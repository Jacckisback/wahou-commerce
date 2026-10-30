<?php
/**
 * Order model backed by {prefix}wahou_orders (+ items and notes tables).
 *
 * Amounts are integers in minor units and tax inclusive:
 *   subtotal (items before discount), discount_total, shipping_total,
 *   tax_total (all VAT incl. shipping), total (amount charged).
 * Item rows: unit_price, subtotal, discount, total (gross) + tax and tax_rate.
 *
 * Statuses: pending, on-hold, processing, completed, cancelled, refunded, failed.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Orders;

use WahouCommerce\Install;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Order {

	public const STATUSES      = array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed' );
	public const PAID_STATUSES = array( 'processing', 'completed', 'refunded' );

	/** @var array<string, mixed> */
	private array $row;

	/** @var array<string, mixed> */
	private array $meta;

	/** @var array<int, array<string, mixed>>|null */
	private ?array $items = null;

	/**
	 * @param array<string, mixed> $row
	 */
	private function __construct( array $row ) {
		$this->row  = $row;
		$meta       = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$this->meta = is_array( $meta ) ? $meta : array();
	}

	public static function find( int $id ): ?self {
		return self::find_by( 'id', $id );
	}

	public static function find_by_key( string $key ): ?self {
		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $key ) ) {
			return null;
		}
		return self::find_by( 'order_key', $key );
	}

	public static function find_by_transaction( string $transaction_id ): ?self {
		if ( '' === $transaction_id ) {
			return null;
		}
		return self::find_by( 'transaction_id', $transaction_id );
	}

	/**
	 * @param int|string $value
	 */
	private static function find_by( string $column, $value ): ?self {
		global $wpdb;
		$table = Install::table( 'orders' );
		$fmt   = 'id' === $column ? '%d' : '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = {$fmt} LIMIT 1", $value ), ARRAY_A );
		return $row ? new self( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self( $row );
	}

	/**
	 * Query orders.
	 *
	 * @param array<string, mixed> $args status (string|string[]), email, customer_id, search, parent_id,
	 *                                   date_from, date_to (Y-m-d H:i:s GMT), paid (bool), limit, offset, orderby, order.
	 * @return array{orders: Order[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table  = Install::table( 'orders' );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$statuses = array_values( array_intersect( (array) $args['status'], self::STATUSES ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$params  = array_merge( $params, $statuses );
			}
		}
		if ( ! empty( $args['paid'] ) ) {
			$where[] = "status IN ('processing','completed','refunded')";
		}
		if ( ! empty( $args['email'] ) ) {
			$where[]  = 'email = %s';
			$params[] = (string) $args['email'];
		}
		if ( isset( $args['customer_id'] ) ) {
			$where[]  = 'customer_id = %d';
			$params[] = (int) $args['customer_id'];
		}
		if ( isset( $args['parent_id'] ) ) {
			$where[]  = 'parent_id = %d';
			$params[] = (int) $args['parent_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['date_to'];
		}
		if ( ! empty( $args['search'] ) ) {
			$search  = trim( (string) $args['search'] );
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(email LIKE %s OR billing LIKE %s OR transaction_id = %s OR invoice_number = %s OR id = %d)';
			array_push( $params, $like, $like, $search, $search, (int) preg_replace( '/\D/', '', $search ) );
		}

		$orderby = in_array( $args['orderby'] ?? '', array( 'id', 'created_at', 'total', 'status' ), true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$limit   = max( 1, min( 500, (int) ( $args['limit'] ?? 20 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A );
		// phpcs:enable

		return array(
			'orders' => array_map( array( self::class, 'from_row' ), (array) $rows ),
			'total'  => $total,
		);
	}

	/* ------------------------------------------------------------------
	 * Accessors.
	 * ------------------------------------------------------------------ */

	public function id(): int {
		return (int) $this->row['id'];
	}

	public function number(): string {
		return (string) apply_filters( 'wahou_order_number', Settings::str( 'order_prefix' ) . $this->id(), $this );
	}

	public function key(): string {
		return (string) $this->row['order_key'];
	}

	public function status(): string {
		return (string) $this->row['status'];
	}

	public function status_label(): string {
		return self::label( $this->status() );
	}

	public static function label( string $status ): string {
		$labels = array(
			'pending'    => __( 'Pending payment', 'wahou-commerce' ),
			'on-hold'    => __( 'On hold', 'wahou-commerce' ),
			'processing' => __( 'Processing', 'wahou-commerce' ),
			'completed'  => __( 'Completed', 'wahou-commerce' ),
			'cancelled'  => __( 'Cancelled', 'wahou-commerce' ),
			'refunded'   => __( 'Refunded', 'wahou-commerce' ),
			'failed'     => __( 'Failed', 'wahou-commerce' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public function is_paid(): bool {
		return in_array( $this->status(), self::PAID_STATUSES, true );
	}

	public function parent_id(): int {
		return (int) $this->row['parent_id'];
	}

	public function customer_id(): int {
		return (int) $this->row['customer_id'];
	}

	public function email(): string {
		return (string) $this->row['email'];
	}

	public function currency(): string {
		return (string) $this->row['currency'];
	}

	public function subtotal(): int {
		return (int) $this->row['subtotal'];
	}

	public function discount_total(): int {
		return (int) $this->row['discount_total'];
	}

	public function shipping_total(): int {
		return (int) $this->row['shipping_total'];
	}

	public function tax_total(): int {
		return (int) $this->row['tax_total'];
	}

	public function total(): int {
		return (int) $this->row['total'];
	}

	public function refunded_total(): int {
		return (int) $this->row['refunded_total'];
	}

	public function tax_mode(): string {
		return (string) $this->row['tax_mode'];
	}

	public function tax_country(): string {
		return (string) $this->row['tax_country'];
	}

	public function coupon_code(): string {
		return (string) $this->row['coupon_code'];
	}

	public function payment_method(): string {
		return (string) $this->row['payment_method'];
	}

	public function payment_method_title(): string {
		return (string) $this->get_meta( 'payment_method_title', $this->payment_method() );
	}

	public function transaction_id(): string {
		return (string) $this->row['transaction_id'];
	}

	public function locale(): string {
		return (string) $this->row['locale'];
	}

	public function ip_address(): string {
		return (string) $this->row['ip_address'];
	}

	public function cart_id(): int {
		return (int) $this->row['cart_id'];
	}

	public function invoice_number(): string {
		return (string) $this->row['invoice_number'];
	}

	public function created_at(): string {
		return (string) $this->row['created_at'];
	}

	public function paid_at(): string {
		return (string) ( $this->row['paid_at'] ?? '' );
	}

	public function completed_at(): string {
		return (string) ( $this->row['completed_at'] ?? '' );
	}

	/**
	 * Local date for display.
	 */
	public function date( string $format = '' ): string {
		$format = '' !== $format ? $format : (string) get_option( 'date_format' );
		return (string) get_date_from_gmt( $this->created_at(), $format );
	}

	/**
	 * @return array<string, string>
	 */
	public function billing(): array {
		$data = json_decode( (string) $this->row['billing'], true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return array<string, string>
	 */
	public function shipping_address(): array {
		$data = json_decode( (string) $this->row['shipping'], true );
		return is_array( $data ) ? $data : array();
	}

	public function customer_name(): string {
		$b = $this->billing();
		return trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) );
	}

	public function first_name(): string {
		return (string) ( $this->billing()['first_name'] ?? '' );
	}

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	public function get_meta( string $key, $fallback = null ) {
		return $this->meta[ $key ] ?? $fallback;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all_meta(): array {
		return $this->meta;
	}

	/**
	 * Persist one meta value immediately.
	 *
	 * @param mixed $value
	 */
	public function update_meta( string $key, $value ): void {
		if ( null === $value ) {
			unset( $this->meta[ $key ] );
		} else {
			$this->meta[ $key ] = $value;
		}
		$this->update( array( 'meta' => wp_json_encode( $this->meta ) ) );
	}

	/**
	 * Tax breakdown: [ [rate, net, tax], ... ].
	 *
	 * @return array<int, array{rate:float, net:int, tax:int}>
	 */
	public function tax_lines(): array {
		$lines = $this->get_meta( 'tax_lines', array() );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * @return array<string, mixed>|null method_id, title, total, tax, rate
	 */
	public function shipping_line(): ?array {
		$line = $this->get_meta( 'shipping_line' );
		return is_array( $line ) ? $line : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		global $wpdb;
		if ( null === $this->items ) {
			$table = Install::table( 'order_items' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $this->id() ), ARRAY_A );
			$this->items = array_map(
				static function ( $row ) {
					$meta        = json_decode( (string) $row['meta'], true );
					$row['meta'] = is_array( $meta ) ? $meta : array();
					foreach ( array( 'id', 'order_id', 'product_id', 'quantity', 'unit_price', 'subtotal', 'discount', 'tax', 'total' ) as $int ) {
						$row[ $int ] = (int) $row[ $int ];
					}
					$row['tax_rate'] = (float) $row['tax_rate'];
					return $row;
				},
				(array) $rows
			);
		}
		return $this->items;
	}

	public function needs_shipping(): bool {
		foreach ( $this->items() as $item ) {
			if ( 'physical' === $item['kind'] || ! empty( $item['meta']['needs_shipping'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function has_digital(): bool {
		foreach ( $this->items() as $item ) {
			if ( 'digital' === $item['kind'] || ! empty( $item['meta']['has_files'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function item_count(): int {
		return (int) array_sum( array_column( $this->items(), 'quantity' ) );
	}

	/* ------------------------------------------------------------------
	 * URLs.
	 * ------------------------------------------------------------------ */

	public function received_url(): string {
		return Pages::order_received_url( $this );
	}

	public function invoice_url(): string {
		return Pages::invoice_url( $this );
	}

	public function admin_url(): string {
		return admin_url( 'admin.php?page=wahou-orders&order=' . $this->id() );
	}

	/* ------------------------------------------------------------------
	 * Writes.
	 * ------------------------------------------------------------------ */

	/**
	 * Low-level column update (no hooks). Use Orders::set_status() for status changes.
	 *
	 * @param array<string, mixed> $columns
	 */
	public function update( array $columns ): void {
		global $wpdb;
		$columns['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Install::table( 'orders' ), $columns, array( 'id' => $this->id() ) );
		foreach ( $columns as $k => $v ) {
			$this->row[ $k ] = $v;
		}
	}

	public function set_status( string $status, string $note = '' ): void {
		Orders::set_status( $this, $status, $note );
	}

	public function add_note( string $note, bool $customer_note = false, string $author = '' ): void {
		global $wpdb;
		if ( '' === $author ) {
			$user   = wp_get_current_user();
			$author = $user->exists() ? $user->display_name : 'Wahou Commerce';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Install::table( 'order_notes' ),
			array(
				'order_id'    => $this->id(),
				'note'        => wp_kses_post( $note ),
				'author'      => substr( $author, 0, 100 ),
				'is_customer' => $customer_note ? 1 : 0,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		if ( $customer_note ) {
			do_action( 'wahou_order_customer_note', $this, $note );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function notes(): array {
		global $wpdb;
		$table = Install::table( 'order_notes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC", $this->id() ), ARRAY_A );
	}

	public function payment_complete( string $transaction_id = '' ): void {
		Orders::payment_complete( $this, $transaction_id );
	}

	/**
	 * Re-read the row from the database.
	 */
	public function refresh(): self {
		$fresh = self::find( $this->id() );
		if ( $fresh ) {
			$this->row   = $fresh->row;
			$this->meta  = $fresh->meta;
			$this->items = null;
		}
		return $this;
	}

	/**
	 * Data for REST responses, privacy exports and the admin.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id(),
			'number'         => $this->number(),
			'status'         => $this->status(),
			'status_label'   => $this->status_label(),
			'email'          => $this->email(),
			'currency'       => $this->currency(),
			'subtotal'       => $this->subtotal(),
			'discount_total' => $this->discount_total(),
			'shipping_total' => $this->shipping_total(),
			'tax_total'      => $this->tax_total(),
			'total'          => $this->total(),
			'refunded_total' => $this->refunded_total(),
			'payment_method' => $this->payment_method(),
			'payment_method_title' => $this->payment_method_title(),
			'transaction_id' => $this->transaction_id(),
			'invoice_number' => $this->invoice_number(),
			'billing'        => $this->billing(),
			'shipping'       => $this->shipping_address(),
			'items'          => $this->items(),
			'tax_lines'      => $this->tax_lines(),
			'tax_mode'       => $this->tax_mode(),
			'created_at'     => $this->created_at(),
			'paid_at'        => $this->paid_at(),
		);
	}
}
