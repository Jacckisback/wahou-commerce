<?php
/**
 * Order service: creation from a cart, status workflow, payment completion,
 * stock, invoice/credit note numbering and refunds bookkeeping.
 *
 * Hooks fired:
 *   wahou_order_created( Order $order )
 *   wahou_order_status_changed( Order $order, string $from, string $to )
 *   wahou_order_status_{to}( Order $order, string $from )
 *   wahou_order_paid( Order $order )            priorities: 5 downloads, 7 account, 20 emails
 *   wahou_order_completed( Order $order )
 *   wahou_order_refunded( Order $order, int $amount, array $refund )
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Orders;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Coupons\Coupons;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Request;

defined( 'ABSPATH' ) || exit;

final class Orders {

	public static function init(): void {
		add_action( 'wahou_cron_frequent', array( self::class, 'cancel_stale' ) );
	}

	/**
	 * Create (or refresh the pending) order from a cart. Prices, taxes and
	 * totals are recomputed server side; nothing is trusted from the browser.
	 *
	 * @param array<string, mixed> $data email, billing[], shipping[]|null, payment_method, customer_note,
	 *                                   locale, tax_evidence[], meta[].
	 * @return Order|\WP_Error
	 */
	public static function create_from_cart( Cart $cart, array $data ) {
		global $wpdb;

		$lines  = $cart->lines();
		$totals = $cart->totals();
		if ( empty( $lines ) ) {
			return new \WP_Error( 'wahou_empty_cart', __( 'Your cart is empty.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		foreach ( $lines as $line ) {
			$product = Product::find( (int) $line['product_id'] );
			if ( ! $product || ! $product->in_stock( $line['variant_id'], (int) $line['qty'] ) ) {
				return new \WP_Error(
					'wahou_out_of_stock',
					/* translators: %s: product name. */
					sprintf( __( 'Sorry, "%s" is no longer available in the requested quantity.', 'wahou-commerce' ), $line['name'] ),
					array( 'status' => 409 )
				);
			}
		}
		if ( ! empty( $totals['errors']['shipping'] ) ) {
			return new \WP_Error( 'wahou_shipping', (string) $totals['errors']['shipping'], array( 'status' => 400 ) );
		}

		$gateway = Gateways::get( (string) ( $data['payment_method'] ?? '' ) );
		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();
		$billing = (array) ( $data['billing'] ?? array() );

		$meta = array_merge(
			(array) ( $data['meta'] ?? array() ),
			array(
				'tax_lines'            => $totals['tax_lines'],
				'rate_country'         => $totals['rate_country'],
				'prices_include_tax'   => (bool) $totals['prices_include_tax'],
				'payment_method_title' => $gateway ? $gateway->title() : '',
				'tax_evidence'         => array(
					'billing_country' => (string) ( $billing['country'] ?? '' ),
					'ip_country'      => Request::geo_country(),
					'vat_number'      => (string) ( $billing['vat_number'] ?? '' ),
					'vat_valid'       => ! empty( $cart->customer()['vat_valid'] ),
					'vat_name'        => (string) ( $cart->customer()['vat_name'] ?? '' ),
				),
				'customer_note'        => (string) ( $data['customer_note'] ?? '' ),
				'consents'             => (array) ( $data['consents'] ?? array() ),
			)
		);
		if ( $totals['needs_shipping'] && $totals['shipping_method'] ) {
			$meta['shipping_line'] = array(
				'method_id' => $totals['shipping_method'],
				'title'     => $totals['shipping_title'],
				'total'     => $totals['shipping'],
				'tax'       => $totals['shipping_tax'],
				'rate'      => $totals['shipping_rate'],
			);
		}
		if ( $cart->get( 'utm' ) ) {
			$meta['utm'] = $cart->get( 'utm' );
		}

		$columns = array(
			'status'         => 'pending',
			'customer_id'    => $user_id,
			'email'          => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'currency'       => (string) $totals['currency'],
			'subtotal'       => (int) $totals['subtotal'],
			'discount_total' => (int) $totals['discount'],
			'shipping_total' => (int) $totals['shipping'],
			'tax_total'      => (int) $totals['tax'],
			'total'          => (int) $totals['total'],
			'tax_mode'       => (string) $totals['tax_mode'],
			'tax_country'    => (string) $totals['tax_country'],
			'coupon_code'    => $totals['coupon'] ? (string) $totals['coupon']['code'] : '',
			'payment_method' => $gateway ? $gateway->id : sanitize_key( (string) ( $data['payment_method'] ?? '' ) ),
			'billing'        => wp_json_encode( $billing ),
			'shipping'       => wp_json_encode( $totals['needs_shipping'] ? (array) ( $data['shipping'] ?? $billing ) : array() ),
			'meta'           => wp_json_encode( $meta ),
			'locale'         => (string) ( $data['locale'] ?? determine_locale() ),
			'ip_address'     => Request::ip(),
			'user_agent'     => Request::user_agent(),
			'cart_id'        => $cart->id(),
			'updated_at'     => $now,
		);

		$orders_table = Install::table( 'orders' );
		$existing     = $cart->pending_order_id() ? Order::find( $cart->pending_order_id() ) : null;
		if ( $existing && 'pending' === $existing->status() && $existing->cart_id() === $cart->id() ) {
			// Customer retried: reuse the pending order so we never create duplicates.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $orders_table, $columns, array( 'id' => $existing->id() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Install::table( 'order_items' ), array( 'order_id' => $existing->id() ) );
			$order_id = $existing->id();
		} else {
			$columns['order_key']  = Crypto::key_string( 32 );
			$columns['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = $wpdb->insert( $orders_table, $columns );
			if ( ! $ok ) {
				Logger::error( 'orders', 'Order insert failed', array( 'db_error' => $wpdb->last_error ) );
				return new \WP_Error( 'wahou_order_failed', __( 'We could not create your order. Please try again.', 'wahou-commerce' ), array( 'status' => 500 ) );
			}
			$order_id = (int) $wpdb->insert_id;
		}

		self::insert_items( $order_id, $lines, $totals );

		$cart->set_pending_order_id( $order_id );
		$cart->save();

		$order = Order::find( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'wahou_order_failed', __( 'We could not create your order. Please try again.', 'wahou-commerce' ), array( 'status' => 500 ) );
		}
		if ( ! $existing ) {
			$order->add_note( __( 'Order created.', 'wahou-commerce' ), false, 'Wahou Commerce' );
		}
		do_action( 'wahou_order_created', $order );
		return $order;
	}

	/**
	 * Insert order item rows from priced cart lines and computed totals.
	 *
	 * @param array<int, array<string, mixed>> $lines
	 * @param array<string, mixed>             $totals
	 */
	public static function insert_items( int $order_id, array $lines, array $totals ): void {
		global $wpdb;
		foreach ( $lines as $line ) {
			$money   = $totals['lines'][ $line['key'] ];
			$product = Product::find( (int) $line['product_id'] );
			$item_meta = array(
				'variant_label' => $line['variant_label'],
				'needs_shipping' => $product ? $product->needs_shipping() : (bool) $line['needs_shipping'],
				'has_files'     => $product ? $product->has_files() : false,
			);
			if ( ! empty( $line['meta']['bump'] ) ) {
				$item_meta['bump'] = (int) $line['meta']['bump'];
			}
			if ( ! empty( $line['meta']['fbt_parent'] ) ) {
				$item_meta['fbt_parent'] = (int) $line['meta']['fbt_parent'];
			}
			if ( $product && 'bundle' === $product->type() ) {
				$item_meta['bundle'] = array_map(
					static function ( $component ) {
						$p = Product::find( $component['product_id'] );
						return array(
							'product_id' => $component['product_id'],
							'qty'        => $component['qty'],
							'name'       => $p ? $p->name() : '',
						);
					},
					$product->bundle_items()
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				Install::table( 'order_items' ),
				array(
					'order_id'   => $order_id,
					'product_id' => (int) $line['product_id'],
					'variant_id' => (string) $line['variant_id'],
					'name'       => (string) $line['name'],
					'sku'        => (string) $line['sku'],
					'kind'       => (string) $line['kind'],
					'item_type'  => ! empty( $line['meta']['bump'] ) ? 'bump' : ( 'bundle' === $line['type'] ? 'bundle' : 'product' ),
					'quantity'   => (int) $line['qty'],
					'unit_price' => (int) $money['unit'],
					'subtotal'   => (int) $money['subtotal'],
					'discount'   => (int) $money['discount'],
					'tax'        => (int) $money['tax'],
					'total'      => (int) $money['total'],
					'tax_rate'   => (float) $money['rate'],
					'tax_class'  => (string) $line['tax_class'],
					'meta'       => wp_json_encode( $item_meta ),
				)
			);
		}
	}

	/**
	 * Change the status, add a note and fire hooks.
	 */
	public static function set_status( Order $order, string $status, string $note = '' ): void {
		$from = $order->status();
		if ( ! in_array( $status, Order::STATUSES, true ) || $from === $status ) {
			if ( '' !== $note ) {
				$order->add_note( $note );
			}
			return;
		}
		$columns = array( 'status' => $status );
		if ( 'completed' === $status && ! $order->completed_at() ) {
			$columns['completed_at'] = current_time( 'mysql', true );
		}
		$order->update( $columns );

		/* translators: 1: previous status, 2: new status. */
		$message = sprintf( __( 'Status changed from %1$s to %2$s.', 'wahou-commerce' ), Order::label( $from ), Order::label( $status ) );
		$order->add_note( trim( $message . ' ' . $note ) );

		// Give stock back when an unpaid or paid order is cancelled.
		if ( in_array( $status, array( 'cancelled', 'failed' ), true ) && $order->get_meta( 'stock_reduced' ) ) {
			self::restore_stock( $order );
		}

		do_action( 'wahou_order_status_changed', $order, $from, $status );
		do_action( 'wahou_order_status_' . $status, $order, $from );
		if ( 'completed' === $status ) {
			do_action( 'wahou_order_completed', $order );
		}
	}

	/**
	 * Mark an order as paid. Idempotent: safe to call from the return URL and
	 * from webhooks at the same time.
	 */
	public static function payment_complete( Order $order, string $transaction_id = '' ): void {
		global $wpdb;

		// Atomic transition to avoid double processing (webhook + redirect race).
		$table = Install::table( 'orders' );
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET paid_at = %s, updated_at = %s WHERE id = %d AND paid_at IS NULL AND status IN ('pending','on-hold','failed','cancelled')", $now, $now, $order->id() ) );
		if ( ! $claimed ) {
			return;
		}
		$order->refresh();

		if ( '' !== $transaction_id ) {
			$order->update( array( 'transaction_id' => substr( $transaction_id, 0, 190 ) ) );
		}

		self::reduce_stock( $order );
		self::assign_invoice_number( $order );

		foreach ( $order->items() as $item ) {
			$product = Product::find( (int) $item['product_id'] );
			if ( $product ) {
				update_post_meta( $product->id(), '_wahou_total_sales', $product->total_sales() + (int) $item['quantity'] );
				Product::flush( $product->id() );
			}
		}
		if ( '' !== $order->coupon_code() ) {
			Coupons::record_usage( $order->coupon_code(), $order->email() );
		}
		if ( $order->cart_id() ) {
			Cart::mark_converted( $order->cart_id(), $order->id() );
		}

		// Digital-only / service orders are fulfilled instantly.
		$status = $order->needs_shipping() ? 'processing' : 'completed';
		$status = (string) apply_filters( 'wahou_payment_complete_status', $status, $order );
		self::set_status( $order, $status, __( 'Payment received.', 'wahou-commerce' ) );

		do_action( 'wahou_order_paid', $order );
	}

	public static function reduce_stock( Order $order ): void {
		if ( $order->get_meta( 'stock_reduced' ) ) {
			return;
		}
		foreach ( $order->items() as $item ) {
			$product = Product::find( (int) $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$vid = '' !== $item['variant_id'] ? (string) $item['variant_id'] : null;
			if ( 'bundle' === $product->type() ) {
				foreach ( $product->bundle_items() as $component ) {
					$p = Product::find( $component['product_id'] );
					if ( $p ) {
						$p->adjust_stock( -1 * $component['qty'] * (int) $item['quantity'] );
					}
				}
			}
			$product->adjust_stock( -1 * (int) $item['quantity'], $vid );
		}
		$order->update_meta( 'stock_reduced', true );
	}

	public static function restore_stock( Order $order ): void {
		if ( ! $order->get_meta( 'stock_reduced' ) ) {
			return;
		}
		foreach ( $order->items() as $item ) {
			$product = Product::find( (int) $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$vid = '' !== $item['variant_id'] ? (string) $item['variant_id'] : null;
			if ( 'bundle' === $product->type() ) {
				foreach ( $product->bundle_items() as $component ) {
					$p = Product::find( $component['product_id'] );
					if ( $p ) {
						$p->adjust_stock( $component['qty'] * (int) $item['quantity'] );
					}
				}
			}
			$product->adjust_stock( (int) $item['quantity'], $vid );
		}
		$order->update_meta( 'stock_reduced', null );
		$order->add_note( __( 'Stock restored.', 'wahou-commerce' ) );
	}

	/**
	 * Sequential invoice number, gap-free in normal operation and never reused
	 * (auto-increment of a dedicated table). Format: prefix + zero-padded number.
	 */
	public static function assign_invoice_number( Order $order ): string {
		global $wpdb;
		if ( '' !== $order->invoice_number() ) {
			return $order->invoice_number();
		}
		$table = Install::table( 'invoices' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'order_id'   => $order->id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			// Already numbered by a concurrent request.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$number = (string) $wpdb->get_var( $wpdb->prepare( "SELECT number FROM {$table} WHERE order_id = %d", $order->id() ) );
			if ( '' !== $number ) {
				$order->update( array( 'invoice_number' => $number ) );
			}
			return $number;
		}
		$number = self::format_number( Settings::str( 'invoice_prefix' ), $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'number' => $number ), array( 'id' => $id ) );
		$order->update( array( 'invoice_number' => $number ) );
		return $number;
	}

	public static function format_number( string $prefix, int $sequence ): string {
		$prefix = str_replace( array( '{YYYY}', '{YY}' ), array( gmdate( 'Y' ), gmdate( 'y' ) ), $prefix );
		return $prefix . str_pad( (string) ( $sequence + Settings::int( 'invoice_offset' ) ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Record a refund (after the gateway accepted it) and issue a credit note.
	 *
	 * @return array<string, mixed> The refund entry.
	 */
	public static function record_refund( Order $order, int $amount, string $reason = '', string $reference = '', bool $restock = false ): array {
		global $wpdb;
		$amount = max( 0, min( $amount, $order->total() - $order->refunded_total() ) );

		$credit_number = '';
		if ( '' !== $order->invoice_number() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				Install::table( 'credit_notes' ),
				array(
					'order_id'   => $order->id(),
					'refund_ref' => substr( $reference, 0, 64 ),
					'amount'     => $amount,
					'created_at' => current_time( 'mysql', true ),
				)
			);
			$credit_id     = (int) $wpdb->insert_id;
			$credit_number = self::format_number( Settings::str( 'credit_note_prefix' ), $credit_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Install::table( 'credit_notes' ), array( 'number' => $credit_number ), array( 'id' => $credit_id ) );
		}

		$refund    = array(
			'amount'      => $amount,
			'reason'      => sanitize_text_field( $reason ),
			'reference'   => $reference,
			'credit_note' => $credit_number,
			'date'        => current_time( 'mysql', true ),
			'by'          => get_current_user_id(),
		);
		$refunds   = (array) $order->get_meta( 'refunds', array() );
		$refunds[] = $refund;
		$order->update_meta( 'refunds', $refunds );
		$order->update( array( 'refunded_total' => $order->refunded_total() + $amount ) );

		if ( $restock ) {
			self::restore_stock( $order );
		}

		/* translators: 1: amount, 2: reason. */
		$order->add_note( sprintf( __( 'Refunded %1$s. %2$s', 'wahou-commerce' ), \WahouCommerce\Support\Money::format( $amount, $order->currency() ), $refund['reason'] ) );
		if ( $order->refunded_total() >= $order->total() ) {
			self::set_status( $order, 'refunded' );
		}
		do_action( 'wahou_order_refunded', $order, $amount, $refund );
		return $refund;
	}

	/**
	 * Refund through the payment gateway, then record it.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function refund( Order $order, int $amount, string $reason = '', bool $restock = false ) {
		if ( ! $order->is_paid() ) {
			return new \WP_Error( 'wahou_not_paid', __( 'Only paid orders can be refunded.', 'wahou-commerce' ) );
		}
		$amount = min( $amount, $order->total() - $order->refunded_total() );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'wahou_refund_amount', __( 'Nothing left to refund.', 'wahou-commerce' ) );
		}
		$reference = '';
		$gateway   = Gateways::get( $order->payment_method() );
		if ( $gateway && $gateway->supports( 'refunds' ) ) {
			$result = $gateway->refund( $order, $amount, $reason );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$reference = is_string( $result ) ? $result : '';
		}
		return self::record_refund( $order, $amount, $reason, $reference, $restock );
	}

	/**
	 * Cancel unpaid orders: card/PayPal after 24 h, bank transfers after the hold period.
	 */
	public static function cancel_stale(): void {
		$pending = Order::query(
			array(
				'status'  => 'pending',
				'date_to' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'limit'   => 50,
				'order'   => 'ASC',
			)
		);
		foreach ( $pending['orders'] as $order ) {
			self::set_status( $order, 'cancelled', __( 'Unpaid order cancelled automatically.', 'wahou-commerce' ) );
		}
		$hold_days = max( 1, Settings::int( 'bank_hold_days' ) );
		$on_hold   = Order::query(
			array(
				'status'  => 'on-hold',
				'date_to' => gmdate( 'Y-m-d H:i:s', time() - $hold_days * DAY_IN_SECONDS ),
				'limit'   => 50,
				'order'   => 'ASC',
			)
		);
		foreach ( $on_hold['orders'] as $order ) {
			if ( 'bank' === $order->payment_method() ) {
				self::set_status( $order, 'cancelled', __( 'Bank transfer not received in time.', 'wahou-commerce' ) );
			}
		}
	}

	/**
	 * Revenue stats for dashboards (paid orders, GMT dates).
	 *
	 * @return array{revenue:int, orders:int, aov:int, refunds:int}
	 */
	public static function stats( string $from, string $to ): array {
		global $wpdb;
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS revenue, COALESCE(SUM(refunded_total),0) AS refunds FROM {$table} WHERE status IN ('processing','completed','refunded') AND paid_at >= %s AND paid_at <= %s", $from, $to ), ARRAY_A );
		$n   = (int) ( $row['n'] ?? 0 );
		$rev = (int) ( $row['revenue'] ?? 0 ) - (int) ( $row['refunds'] ?? 0 );
		return array(
			'revenue' => $rev,
			'orders'  => $n,
			'aov'     => $n ? (int) round( $rev / $n ) : 0,
			'refunds' => (int) ( $row['refunds'] ?? 0 ),
		);
	}
}
