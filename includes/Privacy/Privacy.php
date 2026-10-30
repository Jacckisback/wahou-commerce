<?php
/**
 * GDPR tools: personal data export/erasure (Tools → Export/Erase Personal
 * Data) and a suggested privacy policy section.
 *
 * Erasure deletes carts (and their reminders); orders are kept because
 * invoices must be retained for accounting and tax purposes, but their IP
 * address and browser data are removed.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Privacy;

use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Recovery\Recovery;
use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	private const PER_PAGE = 20;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy_content' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporters( $exporters ): array {
		$exporters                  = (array) $exporters;
		$exporters['wahou-orders'] = array(
			'exporter_friendly_name' => __( 'Store orders', 'wahou-commerce' ),
			'callback'               => array( self::class, 'export_orders' ),
		);
		$exporters['wahou-carts']  = array(
			'exporter_friendly_name' => __( 'Store carts', 'wahou-commerce' ),
			'callback'               => array( self::class, 'export_carts' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_erasers( $erasers ): array {
		$erasers                  = (array) $erasers;
		$erasers['wahou-carts']  = array(
			'eraser_friendly_name' => __( 'Store carts and reminders', 'wahou-commerce' ),
			'callback'             => array( self::class, 'erase_carts' ),
		);
		$erasers['wahou-orders'] = array(
			'eraser_friendly_name' => __( 'Store orders', 'wahou-commerce' ),
			'callback'             => array( self::class, 'erase_orders' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_orders( string $email, int $page = 1 ): array {
		$page   = max( 1, $page );
		$result = Order::query(
			array(
				'email'   => $email,
				'limit'   => self::PER_PAGE,
				'offset'  => ( $page - 1 ) * self::PER_PAGE,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		$data   = array();
		foreach ( $result['orders'] as $order ) {
			$fields = array(
				array(
					'name'  => __( 'Order number', 'wahou-commerce' ),
					'value' => $order->number(),
				),
				array(
					'name'  => __( 'Date', 'wahou-commerce' ),
					'value' => $order->date(),
				),
				array(
					'name'  => __( 'Status', 'wahou-commerce' ),
					'value' => $order->status_label(),
				),
				array(
					'name'  => __( 'Email', 'wahou-commerce' ),
					'value' => $order->email(),
				),
				array(
					'name'  => __( 'Billing details', 'wahou-commerce' ),
					'value' => self::address_text( $order->billing() ),
				),
			);
			if ( $order->needs_shipping() ) {
				$fields[] = array(
					'name'  => __( 'Delivery address', 'wahou-commerce' ),
					'value' => self::address_text( $order->shipping_address() ),
				);
			}
			$fields[] = array(
				'name'  => __( 'Products', 'wahou-commerce' ),
				'value' => implode( ', ', array_map( static fn( $i ) => (int) $i['quantity'] . ' × ' . (string) $i['name'], $order->items() ) ),
			);
			$fields[] = array(
				'name'  => __( 'Total', 'wahou-commerce' ),
				'value' => Money::format( $order->total(), $order->currency() ),
			);
			$fields[] = array(
				'name'  => __( 'Payment method', 'wahou-commerce' ),
				'value' => $order->payment_method_title(),
			);
			if ( '' !== $order->invoice_number() ) {
				$fields[] = array(
					'name'  => __( 'Invoice', 'wahou-commerce' ),
					'value' => $order->invoice_number(),
				);
			}
			if ( '' !== $order->ip_address() ) {
				$fields[] = array(
					'name'  => __( 'IP address', 'wahou-commerce' ),
					'value' => $order->ip_address(),
				);
			}
			$consents = (array) $order->get_meta( 'consents', array() );
			if ( $consents ) {
				$fields[] = array(
					'name'  => __( 'Consents given at checkout', 'wahou-commerce' ),
					'value' => implode( ', ', array_map( static fn( $k, $v ) => $k . ' (' . $v . ' UTC)', array_keys( $consents ), $consents ) ),
				);
			}
			$data[] = array(
				'group_id'          => 'wahou-orders',
				'group_label'       => __( 'Orders', 'wahou-commerce' ),
				'group_description' => __( 'Orders placed in the store.', 'wahou-commerce' ),
				'item_id'           => 'wahou-order-' . $order->id(),
				'data'              => array_values( array_filter( $fields, static fn( $f ) => '' !== (string) $f['value'] ) ),
			);
		}
		return array(
			'data' => $data,
			'done' => $page * self::PER_PAGE >= $result['total'],
		);
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export_carts( string $email, int $page = 1 ): array {
		global $wpdb;
		$page  = max( 1, $page );
		$table = Install::table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );
		$data = array();
		foreach ( (array) $rows as $row ) {
			$cart_data = json_decode( (string) $row['data'], true );
			$cart_data = is_array( $cart_data ) ? $cart_data : array();
			$products  = array();
			foreach ( (array) ( $cart_data['items'] ?? array() ) as $item ) {
				$products[] = (int) ( $item['qty'] ?? 1 ) . ' × ' . wp_strip_all_tags( get_the_title( (int) ( $item['product_id'] ?? 0 ) ) );
			}
			$customer = array_filter( (array) ( $cart_data['customer'] ?? array() ), static fn( $v, $k ) => is_scalar( $v ) && '' !== (string) $v && 'vat_valid' !== $k, ARRAY_FILTER_USE_BOTH );
			$data[]   = array(
				'group_id'    => 'wahou-carts',
				'group_label' => __( 'Carts', 'wahou-commerce' ),
				'item_id'     => 'wahou-cart-' . (int) $row['id'],
				'data'        => array_values(
					array_filter(
						array(
							array(
								'name'  => __( 'Created', 'wahou-commerce' ),
								'value' => get_date_from_gmt( (string) $row['created_at'] ),
							),
							array(
								'name'  => __( 'Last activity', 'wahou-commerce' ),
								'value' => get_date_from_gmt( (string) $row['updated_at'] ),
							),
							array(
								'name'  => __( 'Status', 'wahou-commerce' ),
								'value' => (string) $row['status'],
							),
							array(
								'name'  => __( 'Products', 'wahou-commerce' ),
								'value' => implode( ', ', $products ),
							),
							array(
								'name'  => __( 'Details entered', 'wahou-commerce' ),
								'value' => implode( ', ', array_map( static fn( $k, $v ) => $k . ': ' . $v, array_keys( $customer ), $customer ) ),
							),
							array(
								'name'  => __( 'Reminders sent', 'wahou-commerce' ),
								'value' => (string) (int) $row['reminders_sent'],
							),
						),
						static fn( $f ) => '' !== (string) $f['value']
					)
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( (array) $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_carts( string $email, int $page = 1 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = (int) $wpdb->delete( Install::table( 'carts' ), array( 'email' => $email ) );
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_orders( string $email, int $page = 1 ): array {
		global $wpdb;
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s", $email ) );
		if ( 0 === $count ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$anonymized = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ip_address = '', user_agent = '' WHERE email = %s AND ( ip_address <> '' OR user_agent <> '' )", $email ) );
		return array(
			'items_removed'  => $anonymized > 0,
			'items_retained' => true,
			'messages'       => array(
				sprintf(
					/* translators: %d: number of orders. */
					_n(
						'%d order was kept because invoices must be retained for the period required by accounting and tax law. Its IP address and browser data were removed.',
						'%d orders were kept because invoices must be retained for the period required by accounting and tax law. Their IP address and browser data were removed.',
						$count,
						'wahou-commerce'
					),
					$count
				),
			),
			'done'           => true,
		);
	}

	public static function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$retention = max( 7, Settings::int( 'recovery_retention_days' ) );
		$reminders = class_exists( Recovery::class ) ? count( Recovery::steps() ) : 0;
		$email     = Settings::store_email();

		$html  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your online store. Adapt it to your business and have it reviewed.', 'wahou-commerce' ) . '</p>';
		$html .= '<h2>' . esc_html__( 'Online store', 'wahou-commerce' ) . '</h2>';
		$html .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'wahou-commerce' ) . ' </strong>' . esc_html__( 'When you order from our store, we collect your name, email address, billing and delivery addresses, phone number (optional), company name and VAT number (business customers), the content and amount of your orders, the payment method used, the consents you give at checkout, as well as your IP address and browser information. We never store your card details.', 'wahou-commerce' ) . '</p>';
		$html .= '<p>' . esc_html__( 'We use this data to process and deliver your orders, give you access to your downloads and customer account, issue invoices, meet our accounting and tax obligations (including the evidence of your location required for VAT on digital products) and prevent fraud.', 'wahou-commerce' ) . '</p>';
		if ( Settings::bool( 'recovery_enabled' ) && $reminders > 0 ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: maximum number of reminder emails. */
					_n(
						'If you enter your email address at checkout without completing your order, we may send you %d reminder about your cart. Each email contains an unsubscribe link.',
						'If you enter your email address at checkout without completing your order, we may send you up to %d reminders about your cart. Each email contains an unsubscribe link.',
						$reminders,
						'wahou-commerce'
					),
					$reminders
				)
			) . '</p>';
		}
		$html .= '<p>' . esc_html__( 'Payments are processed by our payment providers (such as Stripe and PayPal), which receive the data needed to process your payment and act under their own privacy policies. Our hosting and email delivery providers process data on our behalf.', 'wahou-commerce' ) . '</p>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				__( 'Orders and invoices are kept for the period required by accounting and tax law. Carts that did not lead to an order are deleted after %d days at the latest.', 'wahou-commerce' ),
				$retention
			)
		) . '</p>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: contact email. */
				__( 'You can access, correct, export or delete your data, or object to its use, by writing to %s. Data we must keep for legal reasons is deleted at the end of the legal retention period.', 'wahou-commerce' ),
				$email
			)
		) . '</p>';

		wp_add_privacy_policy_content( 'Wahou Commerce', wp_kses_post( $html ) );
	}

	/**
	 * @param array<string, string> $address
	 */
	private static function address_text( array $address ): string {
		$parts = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'phone', 'vat_number' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts[] = (string) $address[ $key ];
			}
		}
		if ( ! empty( $address['country'] ) ) {
			$parts[] = Countries::name( (string) $address['country'] );
		}
		return implode( ', ', $parts );
	}
}
