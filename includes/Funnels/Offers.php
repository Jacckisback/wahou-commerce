<?php
/**
 * Sales funnels: order bumps (checkbox at checkout), post-purchase one-click
 * upsells and their downsells. Offers are wahou_offer posts:
 *
 *   _wahou_offer_type      bump | upsell | downsell
 *   _wahou_offer_product   product ID, _wahou_offer_variant variant ID
 *   _wahou_offer_discount  percent off the product price
 *   _wahou_offer_headline, _wahou_offer_text
 *   _wahou_offer_triggers  product IDs (empty = any cart/order)
 *   _wahou_offer_downsell  offer ID shown when an upsell is declined
 *   _wahou_offer_active    bool; priority = menu_order (lower first)
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Funnels;

use WahouCommerce\Cart\Calculator;
use WahouCommerce\Cart\Cart;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Offers {

	public const TYPES = array( 'bump', 'upsell', 'downsell' );

	/** @var array<int, array<string, mixed>|null> */
	private static array $cache = array();

	public static function init(): void {
		add_action( 'save_post_' . PostTypes::OFFER, array( self::class, 'flush' ) );
	}

	public static function flush(): void {
		self::$cache = array();
		delete_transient( 'wahou_offer_ids' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( array_key_exists( $id, self::$cache ) ) {
			return self::$cache[ $id ];
		}
		$post = get_post( $id );
		if ( ! $post || PostTypes::OFFER !== $post->post_type ) {
			self::$cache[ $id ] = null;
			return null;
		}
		$m    = static fn( string $k, $d = '' ) => ( '' !== ( $v = get_post_meta( $id, '_wahou_offer_' . $k, true ) ) ) ? $v : $d;
		$type = (string) $m( 'type', 'bump' );

		self::$cache[ $id ] = array(
			'id'          => $id,
			'title'       => $post->post_title,
			'type'        => in_array( $type, self::TYPES, true ) ? $type : 'bump',
			'product_id'  => (int) $m( 'product', 0 ),
			'variant_id'  => (string) $m( 'variant', '' ),
			'discount'    => max( 0.0, min( 95.0, (float) $m( 'discount', 0 ) ) ),
			'headline'    => (string) $m( 'headline', '' ),
			'text'        => (string) $m( 'text', '' ),
			'triggers'    => array_values( array_filter( array_map( 'intval', (array) $m( 'triggers', array() ) ) ) ),
			'downsell_id' => (int) $m( 'downsell', 0 ),
			'active'      => 'publish' === $post->post_status && (bool) $m( 'active', true ),
			'priority'    => (int) $post->menu_order,
		);
		return self::$cache[ $id ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( string $type ): array {
		$ids = get_transient( 'wahou_offer_ids' );
		if ( ! is_array( $ids ) ) {
			$ids = get_posts(
				array(
					'post_type'      => PostTypes::OFFER,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
					'no_found_rows'  => true,
				)
			);
			set_transient( 'wahou_offer_ids', $ids, DAY_IN_SECONDS );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$offer = self::get( (int) $id );
			if ( $offer && $offer['active'] && $type === $offer['type'] ) {
				$out[] = $offer;
			}
		}
		return $out;
	}

	/**
	 * Unit price of the offer (same tax basis as product prices).
	 *
	 * @param array<string, mixed> $offer
	 */
	public static function price( array $offer ): int {
		$product = Product::find( (int) $offer['product_id'] );
		if ( ! $product ) {
			return 0;
		}
		$vid   = '' !== $offer['variant_id'] ? $offer['variant_id'] : null;
		$price = $product->price( $vid );
		return (int) round( $price * ( 1 - (float) $offer['discount'] / 100 ) );
	}

	/**
	 * @param array<string, mixed> $offer
	 */
	private static function product_ok( array $offer ): bool {
		$product = Product::find( (int) $offer['product_id'] );
		if ( ! $product || ! $product->is_purchasable() ) {
			return false;
		}
		$vid = '' !== $offer['variant_id'] ? $offer['variant_id'] : null;
		if ( $product->variants() && ( ! $vid || ! $product->variant( $vid ) ) ) {
			return false;
		}
		return $product->in_stock( $vid );
	}

	/**
	 * First matching order bump for a cart (the bump item itself may be in the cart).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function bump_for_cart( Cart $cart ): ?array {
		if ( ! Settings::bool( 'bumps_enabled' ) || $cart->is_empty() ) {
			return null;
		}
		$in_cart = array();
		foreach ( $cart->items() as $item ) {
			if ( empty( $item['meta']['bump'] ) ) {
				$in_cart[] = (int) $item['product_id'];
			}
		}
		foreach ( self::all( 'bump' ) as $offer ) {
			if ( in_array( $offer['product_id'], $in_cart, true ) ) {
				continue;
			}
			if ( $offer['triggers'] && ! array_intersect( $offer['triggers'], $in_cart ) ) {
				continue;
			}
			if ( self::product_ok( $offer ) ) {
				return $offer;
			}
		}
		return null;
	}

	/**
	 * Key of the bump item in the cart, or ''.
	 */
	public static function bump_key( Cart $cart, int $offer_id ): string {
		foreach ( $cart->items() as $key => $item ) {
			if ( (int) ( $item['meta']['bump'] ?? 0 ) === $offer_id ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * Toggle an order bump in the cart.
	 *
	 * @return true|\WP_Error
	 */
	public static function toggle_bump( Cart $cart, int $offer_id, bool $add ) {
		$offer = self::get( $offer_id );
		if ( ! $offer || 'bump' !== $offer['type'] || ! $offer['active'] ) {
			return new \WP_Error( 'wahou_offer_invalid', __( 'This offer is no longer available.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$key = self::bump_key( $cart, $offer_id );
		if ( ! $add ) {
			if ( $key ) {
				$cart->remove( $key );
			}
			return true;
		}
		if ( $key ) {
			return true;
		}
		$result = $cart->add( (int) $offer['product_id'], 1, '' !== $offer['variant_id'] ? $offer['variant_id'] : null, array( 'bump' => $offer_id ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Post-purchase offer to show after $order (or after a declined upsell).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function upsell_for_order( Order $order ): ?array {
		if ( ! Settings::bool( 'upsells_enabled' ) || $order->parent_id() || ! $order->is_paid() ) {
			return null;
		}
		$gateway = Gateways::get( $order->payment_method() );
		if ( ! $gateway || ! $gateway->supports( 'one_click_upsell' ) || ! $order->get_meta( 'one_click_ready' ) ) {
			return null;
		}
		$bought = array_map( static fn( $i ) => (int) $i['product_id'], $order->items() );
		$seen   = (array) $order->get_meta( 'offers_seen', array() );
		foreach ( self::all( 'upsell' ) as $offer ) {
			if ( in_array( $offer['id'], $seen, true ) || in_array( $offer['product_id'], $bought, true ) ) {
				continue;
			}
			if ( $offer['triggers'] && ! array_intersect( $offer['triggers'], $bought ) ) {
				continue;
			}
			if ( self::product_ok( $offer ) ) {
				return $offer;
			}
		}
		return null;
	}

	/**
	 * Where to send the customer after payment of $order.
	 */
	public static function after_payment_url( Order $order ): string {
		$offer = self::upsell_for_order( $order );
		return $offer ? Pages::offer_url( $order, (int) $offer['id'] ) : $order->received_url();
	}

	/**
	 * Accept a post-purchase offer: create a linked order and charge it in one click.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function accept( Order $parent, int $offer_id ) {
		$offer = self::get( $offer_id );
		if ( ! $offer || ! in_array( $offer['type'], array( 'upsell', 'downsell' ), true ) || ! $offer['active'] || ! self::product_ok( $offer ) ) {
			return new \WP_Error( 'wahou_offer_invalid', __( 'This offer is no longer available.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$seen = (array) $parent->get_meta( 'offers_accepted', array() );
		if ( in_array( $offer_id, $seen, true ) ) {
			return array(
				'status'   => 'succeeded',
				'redirect' => $parent->received_url(),
			);
		}
		$gateway = Gateways::get( $parent->payment_method() );
		if ( ! $gateway || ! $gateway->supports( 'one_click_upsell' ) ) {
			return new \WP_Error( 'wahou_one_click_unsupported', __( 'One-click purchase is not available for this payment method.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}

		$child = self::create_child_order( $parent, $offer );
		if ( is_wp_error( $child ) ) {
			return $child;
		}

		$result = $gateway->charge_one_click( $parent, $child );
		if ( is_wp_error( $result ) ) {
			Orders::set_status( $child, 'failed', $result->get_error_message() );
			return $result;
		}

		$seen[] = $offer_id;
		$parent->update_meta( 'offers_accepted', $seen );
		self::mark_seen( $parent, $offer_id );

		if ( 'succeeded' === ( $result['status'] ?? '' ) ) {
			$child->payment_complete( (string) ( $result['transaction_id'] ?? '' ) );
			return array(
				'status'   => 'succeeded',
				'redirect' => $parent->received_url(),
			);
		}
		return array_merge(
			$result,
			array(
				'child_key' => $child->key(),
				'redirect'  => $parent->received_url(),
			)
		);
	}

	/**
	 * Decline: go to the downsell if any, else to the order received page.
	 */
	public static function decline( Order $parent, int $offer_id ): string {
		self::mark_seen( $parent, $offer_id );
		$offer = self::get( $offer_id );
		if ( $offer && 'upsell' === $offer['type'] && $offer['downsell_id'] ) {
			$down = self::get( $offer['downsell_id'] );
			if ( $down && $down['active'] && self::product_ok( $down ) ) {
				return Pages::offer_url( $parent, (int) $down['id'] );
			}
		}
		return $parent->received_url();
	}

	private static function mark_seen( Order $parent, int $offer_id ): void {
		$seen = (array) $parent->get_meta( 'offers_seen', array() );
		if ( ! in_array( $offer_id, $seen, true ) ) {
			$seen[] = $offer_id;
			$parent->update_meta( 'offers_seen', $seen );
		}
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return Order|\WP_Error
	 */
	private static function create_child_order( Order $parent, array $offer ) {
		global $wpdb;
		$product = Product::find( (int) $offer['product_id'] );
		if ( ! $product ) {
			return new \WP_Error( 'wahou_offer_invalid', __( 'This offer is no longer available.', 'wahou-commerce' ) );
		}
		$vid   = '' !== $offer['variant_id'] ? $offer['variant_id'] : null;
		$line  = array(
			'key'                => 'offer' . $offer['id'],
			'product_id'         => $product->id(),
			'variant_id'         => $vid,
			'qty'                => 1,
			'name'               => $product->name(),
			'variant_label'      => $vid ? $product->variant_label( $vid ) : '',
			'unit_price'         => self::price( $offer ),
			'regular_unit_price' => $product->price( $vid ),
			'tax_class'          => $product->tax_class(),
			'needs_shipping'     => false, // Ships with the parent order: no extra shipping fee.
			'kind'               => $product->kind(),
			'type'               => $product->type(),
			'sku'                => $product->sku( $vid ),
			'meta'               => array( 'offer' => (int) $offer['id'] ),
		);
		$evidence = (array) $parent->get_meta( 'tax_evidence', array() );
		$address  = $parent->needs_shipping() ? $parent->shipping_address() : $parent->billing();
		$totals   = Calculator::calculate(
			array( $line ),
			array(
				'country'   => $parent->tax_country() ?: ( $address['country'] ?? '' ),
				'postcode'  => $address['postcode'] ?? '',
				'vat_valid' => ! empty( $evidence['vat_valid'] ),
				'currency'  => $parent->currency(),
			)
		);
		$now      = current_time( 'mysql', true );
		$meta     = array(
			'tax_lines'            => $totals['tax_lines'],
			'rate_country'         => $totals['rate_country'],
			'prices_include_tax'   => (bool) $totals['prices_include_tax'],
			'payment_method_title' => $parent->payment_method_title(),
			'tax_evidence'         => $evidence,
			'offer_id'             => (int) $offer['id'],
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Install::table( 'orders' ),
			array(
				'order_key'      => Crypto::key_string( 32 ),
				'parent_id'      => $parent->id(),
				'status'         => 'pending',
				'customer_id'    => $parent->customer_id(),
				'email'          => $parent->email(),
				'currency'       => $parent->currency(),
				'subtotal'       => (int) $totals['subtotal'],
				'discount_total' => 0,
				'shipping_total' => 0,
				'tax_total'      => (int) $totals['tax'],
				'total'          => (int) $totals['total'],
				'tax_mode'       => (string) $totals['tax_mode'],
				'tax_country'    => (string) $totals['tax_country'],
				'payment_method' => $parent->payment_method(),
				'billing'        => wp_json_encode( $parent->billing() ),
				'shipping'       => wp_json_encode( $parent->shipping_address() ),
				'meta'           => wp_json_encode( $meta ),
				'locale'         => $parent->locale(),
				'ip_address'     => $parent->ip_address(),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'wahou_order_failed', __( 'We could not create your order. Please try again.', 'wahou-commerce' ) );
		}
		$child_id = (int) $wpdb->insert_id;
		Orders::insert_items( $child_id, array( $line ), $totals );
		$child = Order::find( $child_id );
		if ( ! $child ) {
			return new \WP_Error( 'wahou_order_failed', __( 'We could not create your order. Please try again.', 'wahou-commerce' ) );
		}
		/* translators: %s: parent order number. */
		$child->add_note( sprintf( __( 'Post-purchase offer accepted after order #%s.', 'wahou-commerce' ), $parent->number() ), false, 'Wahou Commerce' );
		do_action( 'wahou_order_created', $child );
		return $child;
	}
}
