<?php
/**
 * Printable invoices and credit notes (A4 HTML, "Save as PDF" from the browser).
 *
 *   /?wahou_invoice=ORDER_KEY               Invoice of a paid order.
 *   /?wahou_invoice=ORDER_KEY&credit=NUMBER Credit note issued for a refund.
 *
 * The order key (32 random characters) is the access token; shop managers can
 * open any document. Documents are rendered in the order's language.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Invoices;

use WahouCommerce\Emails\Mailer;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\RateLimiter;
use WahouCommerce\Support\Template;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Invoices {

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 2 );
	}

	public static function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The order key is the access token.
		if ( ! isset( $_GET['wahou_invoice'] ) ) {
			return;
		}
		$key    = sanitize_text_field( wp_unslash( $_GET['wahou_invoice'] ) );
		$credit = isset( $_GET['credit'] ) ? sanitize_text_field( wp_unslash( $_GET['credit'] ) ) : '';
		// phpcs:enable

		$manager = current_user_can( 'manage_wahou_orders' );
		if ( ! $manager && ! RateLimiter::hit( 'invoice', 60, 10 * MINUTE_IN_SECONDS ) ) {
			self::deny( 429 );
		}
		$order = Order::find_by_key( $key );
		if ( ! $order || ( ! $manager && ( ! $order->is_paid() || '' === $order->invoice_number() ) ) ) {
			self::deny( 404 );
		}
		$document = self::document( $order, $credit );
		if ( ! $document ) {
			self::deny( 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );

		$html = Mailer::with_locale(
			$order->locale(),
			static fn() => Template::get( 'invoice', self::vars( $order, $document ) )
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		exit;
	}

	private static function deny( int $status ): void {
		nocache_headers();
		wp_die(
			esc_html__( 'This document is not available.', 'wahou-commerce' ),
			esc_html__( 'Document not available', 'wahou-commerce' ),
			array( 'response' => $status )
		);
	}

	/**
	 * Describe the requested document.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function document( Order $order, string $credit = '' ): ?array {
		if ( '' !== $credit ) {
			foreach ( (array) $order->get_meta( 'refunds', array() ) as $refund ) {
				if ( is_array( $refund ) && ! empty( $refund['credit_note'] ) && (string) $refund['credit_note'] === $credit ) {
					return array(
						'type'   => 'credit_note',
						'number' => $credit,
						'date'   => (string) ( $refund['date'] ?? current_time( 'mysql', true ) ),
						'amount' => (int) ( $refund['amount'] ?? 0 ),
						'reason' => (string) ( $refund['reason'] ?? '' ),
					);
				}
			}
			return null;
		}
		if ( '' === $order->invoice_number() ) {
			return array(
				'type'   => 'proforma',
				'number' => '',
				'date'   => $order->created_at(),
			);
		}
		return array(
			'type'   => 'invoice',
			'number' => $order->invoice_number(),
			'date'   => self::invoice_date( $order ),
		);
	}

	public static function invoice_date( Order $order ): string {
		global $wpdb;
		$table = Install::table( 'invoices' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$date = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE order_id = %d", $order->id() ) );
		if ( '' === $date ) {
			$date = '' !== $order->paid_at() ? $order->paid_at() : $order->created_at();
		}
		return $date;
	}

	/**
	 * Template variables (all amounts in minor units).
	 *
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	public static function vars( Order $order, array $document ): array {
		$lines = array();
		foreach ( $order->items() as $item ) {
			$rate         = (float) $item['tax_rate'];
			$qty          = max( 1, (int) $item['quantity'] );
			$subtotal_net = (int) $item['subtotal'] - Tax::from_gross( (int) $item['subtotal'], $rate );
			$net          = (int) $item['total'] - (int) $item['tax'];
			$lines[]      = array(
				'name'     => (string) $item['name'],
				'detail'   => (string) ( $item['meta']['variant_label'] ?? '' ),
				'sku'      => (string) $item['sku'],
				'qty'      => $qty,
				'unit_net' => (int) round( $subtotal_net / $qty ),
				'discount' => max( 0, $subtotal_net - $net ),
				'rate'     => $rate,
				'net'      => $net,
				'tax'      => (int) $item['tax'],
				'gross'    => (int) $item['total'],
			);
		}
		$shipping = null;
		$line     = $order->shipping_line();
		if ( $line && (int) ( $line['total'] ?? 0 ) > 0 ) {
			$shipping = array(
				'name'  => '' !== (string) ( $line['title'] ?? '' ) ? (string) $line['title'] : __( 'Shipping', 'wahou-commerce' ),
				'rate'  => (float) ( $line['rate'] ?? 0 ),
				'net'   => (int) $line['total'] - (int) ( $line['tax'] ?? 0 ),
				'tax'   => (int) ( $line['tax'] ?? 0 ),
				'gross' => (int) $line['total'],
			);
		}

		$tax_lines = array();
		foreach ( $order->tax_lines() as $tax_line ) {
			$tax_lines[] = array(
				'rate' => (float) $tax_line['rate'],
				'net'  => (int) $tax_line['net'],
				'tax'  => (int) $tax_line['tax'],
			);
		}
		$totals = array(
			'net'   => array_sum( array_column( $tax_lines, 'net' ) ),
			'tax'   => $order->tax_total(),
			'gross' => $order->total(),
		);
		if ( ! $tax_lines ) {
			$totals['net'] = $order->total() - $order->tax_total();
		}

		// Credit note: refund split across VAT rates in proportion to the order.
		if ( 'credit_note' === $document['type'] ) {
			$amount    = (int) $document['amount'];
			$tax_lines = self::split_refund( $amount, $tax_lines, $order->total() );
			$totals    = array(
				'net'   => -1 * array_sum( array_column( $tax_lines, 'net' ) ),
				'tax'   => -1 * array_sum( array_column( $tax_lines, 'tax' ) ),
				'gross' => -1 * $amount,
			);
			foreach ( $tax_lines as &$tax_line ) {
				$tax_line['net'] = -1 * $tax_line['net'];
				$tax_line['tax'] = -1 * $tax_line['tax'];
			}
			unset( $tax_line );
		}

		$billing = $order->billing();
		$logo_id = Settings::int( 'email_logo' );
		$logo_id = $logo_id ? $logo_id : (int) get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		$refunds = array();
		foreach ( (array) $order->get_meta( 'refunds', array() ) as $refund ) {
			if ( is_array( $refund ) ) {
				$refunds[] = array(
					'amount'      => (int) ( $refund['amount'] ?? 0 ),
					'date'        => (string) ( $refund['date'] ?? '' ),
					'credit_note' => (string) ( $refund['credit_note'] ?? '' ),
					'url'         => ! empty( $refund['credit_note'] ) ? add_query_arg( 'credit', rawurlencode( (string) $refund['credit_note'] ), $order->invoice_url() ) : '',
				);
			}
		}

		$legal = Settings::str( 'store_legal_name' );
		return array(
			'order'     => $order,
			'document'  => $document,
			'currency'  => $order->currency(),
			'logo'      => $logo ? (string) $logo : '',
			'seller'    => array(
				'name'    => '' !== $legal ? $legal : Settings::store_name(),
				'store'   => Settings::store_name(),
				'address' => trim( Settings::str( 'store_address' ) ),
				'country' => Countries::name( Settings::store_country() ),
				'vat'     => Settings::str( 'store_vat_number' ),
				'extra'   => trim( Settings::str( 'store_legal_extra' ) ),
				'email'   => Settings::store_email(),
			),
			'buyer'     => array(
				'name'    => trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ),
				'company' => (string) ( $billing['company'] ?? '' ),
				'lines'   => array_values(
					array_filter(
						array(
							(string) ( $billing['address_1'] ?? '' ),
							(string) ( $billing['address_2'] ?? '' ),
							trim( ( $billing['postcode'] ?? '' ) . ' ' . ( $billing['city'] ?? '' ) ),
							! empty( $billing['country'] ) ? Countries::name( (string) $billing['country'] ) : '',
						)
					)
				),
				'vat'     => (string) ( $billing['vat_number'] ?? '' ),
				'email'   => $order->email(),
			),
			'lines'     => $lines,
			'shipping'  => $shipping,
			'tax_lines' => $tax_lines,
			'totals'    => $totals,
			'mention'   => Tax::invoice_mention( $order->tax_mode(), $order->needs_shipping() ),
			'refunds'   => 'invoice' === $document['type'] ? $refunds : array(),
			'paid'      => $order->is_paid(),
		);
	}

	/**
	 * @param array<int, array{rate:float, net:int, tax:int}> $tax_lines
	 * @return array<int, array{rate:float, net:int, tax:int}>
	 */
	private static function split_refund( int $amount, array $tax_lines, int $order_total ): array {
		if ( ! $tax_lines || $order_total <= 0 ) {
			return array(
				array(
					'rate' => 0.0,
					'net'  => $amount,
					'tax'  => 0,
				),
			);
		}
		$out       = array();
		$remaining = $amount;
		$last      = count( $tax_lines ) - 1;
		foreach ( array_values( $tax_lines ) as $i => $line ) {
			$gross     = $i === $last ? $remaining : (int) round( $amount * ( $line['net'] + $line['tax'] ) / $order_total );
			$gross     = min( $gross, $remaining );
			$tax       = Tax::from_gross( $gross, (float) $line['rate'] );
			$out[]     = array(
				'rate' => (float) $line['rate'],
				'net'  => $gross - $tax,
				'tax'  => $tax,
			);
			$remaining -= $gross;
		}
		return $out;
	}
}
