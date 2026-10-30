<?php
/**
 * Cart persisted in {prefix}wahou_carts and identified by a random token kept
 * in an httpOnly, SameSite=Lax cookie. No PHP sessions, so every catalog page
 * stays fully cacheable; the item count is exposed in a separate readable
 * cookie (wahou_cart_count) for the header badge.
 *
 * Item structure: key => [ key, product_id, variant_id, qty, added_at, meta ]
 * Special meta: bump (offer ID), fbt_parent (product ID).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Cart;

use WahouCommerce\Funnels\Offers;
use WahouCommerce\Install;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class Cart {

	public const COOKIE       = 'wahou_cart';
	public const COUNT_COOKIE = 'wahou_cart_count';
	public const MAX_ITEMS    = 100;

	private static ?Cart $current = null;
	private static bool $current_loaded = false;

	private int $id = 0;
	private string $token = '';
	private int $user_id = 0;
	private string $email = '';
	private string $status = 'active';
	private int $reminders_sent = 0;
	private int $order_id = 0;
	private string $created_at = '';

	/** @var array<string, mixed> */
	private array $data = array(
		'items'           => array(),
		'coupon'          => '',
		'customer'        => array(),
		'shipping_method' => '',
		'consent'         => null,
		'pending_order'   => 0,
	);

	/** @var array<string, mixed>|null */
	private ?array $totals = null;

	/* ------------------------------------------------------------------
	 * Loading.
	 * ------------------------------------------------------------------ */

	/**
	 * Cart of the current visitor. Returns null when there is none and $create is false.
	 */
	public static function current( bool $create = false ): ?self {
		if ( ! self::$current_loaded ) {
			self::$current_loaded = true;
			$token                = self::cookie_token();
			if ( $token ) {
				self::$current = self::from_token( $token );
				if ( self::$current && in_array( self::$current->status, array( 'converted', 'recovered' ), true ) ) {
					self::$current = null;
				}
			}
			if ( ! self::$current && is_user_logged_in() ) {
				self::$current = self::latest_for_user( get_current_user_id() );
				if ( self::$current ) {
					self::$current->set_cookies();
				}
			}
		}
		if ( ! self::$current && $create ) {
			self::$current = self::create();
		}
		return self::$current;
	}

	/**
	 * Replace the visitor's cart (used by recovery links and "buy now" links).
	 */
	public static function set_current( ?self $cart ): void {
		self::$current        = $cart;
		self::$current_loaded = true;
		if ( $cart ) {
			$cart->set_cookies();
		}
	}

	public static function reset_current(): void {
		self::$current        = null;
		self::$current_loaded = false;
	}

	public static function from_token( string $token ): ?self {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $token ) ) {
			return null;
		}
		$table = Install::table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ), ARRAY_A );
		return $row ? self::from_row( $row ) : null;
	}

	public static function find( int $id ): ?self {
		global $wpdb;
		$table = Install::table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ? self::from_row( $row ) : null;
	}

	private static function latest_for_user( int $user_id ): ?self {
		global $wpdb;
		$table = Install::table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('active','abandoned') AND item_count > 0 ORDER BY updated_at DESC LIMIT 1", $user_id ), ARRAY_A );
		return $row ? self::from_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$cart                 = new self();
		$cart->id             = (int) $row['id'];
		$cart->token          = (string) $row['token'];
		$cart->user_id        = (int) $row['user_id'];
		$cart->email          = (string) $row['email'];
		$cart->status         = (string) $row['status'];
		$cart->reminders_sent = (int) $row['reminders_sent'];
		$cart->order_id       = (int) $row['order_id'];
		$cart->created_at     = (string) $row['created_at'];
		$data                 = json_decode( (string) $row['data'], true );
		if ( is_array( $data ) ) {
			$cart->data = array_merge( $cart->data, $data );
		}
		return $cart;
	}

	private static function create(): self {
		global $wpdb;
		$cart          = new self();
		$cart->token   = Crypto::token( 30 );
		$cart->user_id = get_current_user_id();
		if ( $cart->user_id ) {
			$user        = wp_get_current_user();
			$cart->email = (string) $user->user_email;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Install::table( 'carts' ),
			array(
				'token'      => $cart->token,
				'user_id'    => $cart->user_id,
				'email'      => $cart->email,
				'status'     => 'active',
				'currency'   => Settings::currency(),
				'data'       => wp_json_encode( $cart->data ),
				'locale'     => determine_locale(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$cart->id         = (int) $wpdb->insert_id;
		$cart->created_at = $now;
		$cart->set_cookies();
		return $cart;
	}

	private static function cookie_token(): string {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $token ) ? $token : '';
	}

	/* ------------------------------------------------------------------
	 * Accessors.
	 * ------------------------------------------------------------------ */

	public function id(): int {
		return $this->id;
	}

	public function token(): string {
		return $this->token;
	}

	public function status(): string {
		return $this->status;
	}

	public function email(): string {
		return $this->email;
	}

	public function user_id(): int {
		return $this->user_id;
	}

	public function reminders_sent(): int {
		return $this->reminders_sent;
	}

	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function items(): array {
		return is_array( $this->data['items'] ) ? $this->data['items'] : array();
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->data[ $key ] ?? null;
	}

	/**
	 * Store arbitrary data on the cart (e.g. UTM parameters, recovery flags).
	 *
	 * @param mixed $value
	 */
	public function set( string $key, $value ): void {
		if ( 'items' === $key ) {
			return;
		}
		$this->data[ $key ] = $value;
		$this->totals       = null;
	}

	public function count(): int {
		return (int) array_sum( array_map( static fn( $i ) => (int) $i['qty'], $this->items() ) );
	}

	public function is_empty(): bool {
		return 0 === count( $this->items() );
	}

	public function coupon(): string {
		return (string) $this->data['coupon'];
	}

	/**
	 * @return array<string, string>
	 */
	public function customer(): array {
		return is_array( $this->data['customer'] ) ? $this->data['customer'] : array();
	}

	public function shipping_method(): string {
		return (string) $this->data['shipping_method'];
	}

	public function pending_order_id(): int {
		return (int) ( $this->data['pending_order'] ?? 0 );
	}

	public function set_pending_order_id( int $order_id ): void {
		$this->data['pending_order'] = $order_id;
	}

	public function consent(): ?bool {
		return null === $this->data['consent'] ? null : (bool) $this->data['consent'];
	}

	/* ------------------------------------------------------------------
	 * Mutations.
	 * ------------------------------------------------------------------ */

	/**
	 * Add a product. Returns the item key or an error.
	 *
	 * @param array<string, mixed> $meta
	 * @return string|\WP_Error
	 */
	public function add( int $product_id, int $qty = 1, ?string $variant_id = null, array $meta = array() ) {
		$product = Product::find( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return new \WP_Error( 'wahou_not_purchasable', __( 'This product cannot be purchased.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$variants = $product->variants();
		if ( $variants ) {
			if ( ! $variant_id || ! $product->variant( $variant_id ) ) {
				return new \WP_Error( 'wahou_choose_variant', __( 'Please choose an option.', 'wahou-commerce' ), array( 'status' => 400 ) );
			}
		} else {
			$variant_id = null;
		}
		$qty  = max( 1, min( 999, $qty ) );
		$meta = self::sanitize_meta( $meta );
		$key  = md5( $product_id . '|' . (string) $variant_id . '|' . wp_json_encode( $meta ) );

		$items    = $this->items();
		$existing = isset( $items[ $key ] ) ? (int) $items[ $key ]['qty'] : 0;
		$wanted   = $existing + $qty;
		$max      = $product->max_qty( $variant_id );

		if ( null !== $max && $max <= 0 ) {
			return new \WP_Error( 'wahou_out_of_stock', __( 'Sorry, this product is out of stock.', 'wahou-commerce' ), array( 'status' => 409 ) );
		}
		if ( null !== $max && $wanted > $max ) {
			if ( $product->sold_individually() && $existing > 0 ) {
				return $key; // Already in cart: nothing to do.
			}
			$wanted = $max;
		}
		if ( ! isset( $items[ $key ] ) && count( $items ) >= self::MAX_ITEMS ) {
			return new \WP_Error( 'wahou_cart_full', __( 'Your cart is full.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}

		$items[ $key ]       = array(
			'key'        => $key,
			'product_id' => $product_id,
			'variant_id' => $variant_id,
			'qty'        => $wanted,
			'added_at'   => $items[ $key ]['added_at'] ?? time(),
			'meta'       => $meta,
		);
		$this->data['items'] = $items;
		$this->totals        = null;

		/**
		 * Fires after a product was added to a cart.
		 */
		do_action( 'wahou_cart_item_added', $this, $key, $product_id, $qty, $variant_id );
		return $key;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function set_qty( string $key, int $qty ) {
		$items = $this->items();
		if ( ! isset( $items[ $key ] ) ) {
			return new \WP_Error( 'wahou_item_missing', __( 'This item is no longer in your cart.', 'wahou-commerce' ), array( 'status' => 404 ) );
		}
		if ( $qty <= 0 ) {
			$this->remove( $key );
			return true;
		}
		$product = Product::find( (int) $items[ $key ]['product_id'] );
		$max     = $product ? $product->max_qty( $items[ $key ]['variant_id'] ?? null ) : 0;
		if ( null !== $max && $qty > $max ) {
			$qty = max( 1, $max );
		}
		$items[ $key ]['qty'] = min( 999, $qty );
		$this->data['items']  = $items;
		$this->totals         = null;
		return true;
	}

	public function remove( string $key ): void {
		$items = $this->items();
		unset( $items[ $key ] );
		// Removing a parent removes its "frequently bought together" discount links.
		foreach ( $items as $k => $item ) {
			if ( ! empty( $item['meta']['fbt_parent'] ) && ! $this->has_product( (int) $item['meta']['fbt_parent'], $items ) ) {
				unset( $items[ $k ]['meta']['fbt_parent'] );
			}
		}
		$this->data['items'] = $items;
		$this->totals        = null;
		do_action( 'wahou_cart_item_removed', $this, $key );
	}

	public function clear(): void {
		$this->data['items']  = array();
		$this->data['coupon'] = '';
		$this->totals         = null;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function apply_coupon( string $code ) {
		$code = \WahouCommerce\Coupons\Coupons::normalize( $code );
		if ( '' === $code ) {
			return new \WP_Error( 'wahou_coupon_empty', __( 'Please enter a coupon code.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$previous             = $this->data['coupon'];
		$this->data['coupon'] = $code;
		$this->totals         = null;
		$totals               = $this->totals();
		if ( ! empty( $totals['errors']['coupon'] ) ) {
			$this->data['coupon'] = $previous;
			$this->totals         = null;
			return new \WP_Error( 'wahou_coupon_invalid', $totals['errors']['coupon'], array( 'status' => 400 ) );
		}
		return true;
	}

	public function remove_coupon(): void {
		$this->data['coupon'] = '';
		$this->totals         = null;
	}

	/**
	 * Merge partial customer details (captured while the shopper types).
	 *
	 * @param array<string, mixed> $details
	 */
	public function set_customer( array $details ): void {
		$allowed  = array( 'email', 'first_name', 'last_name', 'company', 'country', 'postcode', 'city', 'state', 'phone', 'vat_number' );
		$customer = $this->customer();
		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $details ) ) {
				continue;
			}
			$value = is_scalar( $details[ $field ] ) ? trim( (string) $details[ $field ] ) : '';
			switch ( $field ) {
				case 'email':
					$value = sanitize_email( $value );
					break;
				case 'country':
					$value = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $value ) ?? '', 0, 2 ) );
					break;
				case 'vat_number':
					$value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $value ) ?? '' );
					if ( ( $customer['vat_number'] ?? '' ) !== $value ) {
						unset( $customer['vat_valid'], $customer['vat_name'] );
					}
					break;
				default:
					$value = sanitize_text_field( $value );
			}
			$customer[ $field ] = substr( $value, 0, 190 );
		}
		if ( array_key_exists( 'vat_valid', $details ) ) {
			$customer['vat_valid'] = $details['vat_valid'] ? '1' : '';
			$customer['vat_name']  = sanitize_text_field( (string) ( $details['vat_name'] ?? '' ) );
		}
		$this->data['customer'] = $customer;

		$email = $customer['email'] ?? '';
		if ( $email && is_email( $email ) && $email !== $this->email ) {
			$this->email = $email;
			do_action( 'wahou_cart_email_captured', $this );
		}
		$this->totals = null;
	}

	public function set_shipping_method( string $method ): void {
		$this->data['shipping_method'] = sanitize_text_field( $method );
		$this->totals                  = null;
	}

	public function set_consent( bool $consent ): void {
		$this->data['consent'] = $consent;
	}

	/* ------------------------------------------------------------------
	 * Lines & totals.
	 * ------------------------------------------------------------------ */

	/**
	 * Priced lines, rebuilt from the catalog on every call (server authoritative).
	 * Items whose product disappeared are dropped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function lines(): array {
		$lines = array();
		$items = $this->items();
		foreach ( $items as $key => $item ) {
			$product = Product::find( (int) $item['product_id'] );
			$vid     = isset( $item['variant_id'] ) && '' !== $item['variant_id'] ? (string) $item['variant_id'] : null;
			if ( ! $product || ! $product->is_purchasable() || ( $vid && ! $product->variant( $vid ) ) ) {
				continue;
			}
			$regular = $product->price( $vid );
			$price   = $regular;
			$meta    = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();

			if ( ! empty( $meta['bump'] ) ) {
				$offer = Offers::get( (int) $meta['bump'] );
				if ( $offer && $offer['product_id'] === $product->id() && $offer['discount'] > 0 ) {
					$price = (int) round( $regular * ( 1 - $offer['discount'] / 100 ) );
				}
			}
			if ( ! empty( $meta['fbt_parent'] ) && $this->has_product( (int) $meta['fbt_parent'], $items ) ) {
				$parent = Product::find( (int) $meta['fbt_parent'] );
				if ( $parent && in_array( $product->id(), $parent->fbt_ids(), true ) && $parent->fbt_discount() > 0 ) {
					$price = (int) round( $regular * ( 1 - $parent->fbt_discount() / 100 ) );
				}
			}

			$line = array(
				'key'                => (string) $key,
				'product_id'         => $product->id(),
				'variant_id'         => $vid,
				'qty'                => max( 1, (int) $item['qty'] ),
				'name'               => $product->name(),
				'variant_label'      => $vid ? $product->variant_label( $vid ) : '',
				'unit_price'         => $price,
				'regular_unit_price' => $regular,
				'tax_class'          => $product->tax_class(),
				'needs_shipping'     => $product->needs_shipping(),
				'kind'               => $product->kind(),
				'type'               => $product->type(),
				'sku'                => $product->sku( $vid ),
				'image_id'           => $product->image_id( $vid ),
				'permalink'          => $product->permalink(),
				'max_qty'            => $product->max_qty( $vid ),
				'meta'               => $meta,
			);
			/**
			 * Filters a priced cart line.
			 */
			$lines[] = apply_filters( 'wahou_cart_line', $line, $product, $this );
		}
		return $lines;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function totals(): array {
		if ( null === $this->totals ) {
			$customer     = $this->customer();
			$this->totals = Calculator::calculate(
				$this->lines(),
				array(
					'country'         => $customer['country'] ?? '',
					'postcode'        => $customer['postcode'] ?? '',
					'vat_valid'       => ! empty( $customer['vat_valid'] ),
					'email'           => $customer['email'] ?? $this->email,
					'coupon'          => $this->coupon(),
					'shipping_method' => $this->shipping_method(),
					'currency'        => Settings::currency(),
				)
			);
		}
		return $this->totals;
	}

	public function needs_shipping(): bool {
		foreach ( $this->lines() as $line ) {
			if ( $line['needs_shipping'] ) {
				return true;
			}
		}
		return false;
	}

	public function has_digital(): bool {
		foreach ( $this->lines() as $line ) {
			if ( 'digital' === $line['kind'] ) {
				return true;
			}
			if ( 'bundle' === $line['type'] ) {
				$bundle = Product::find( (int) $line['product_id'] );
				foreach ( $bundle ? $bundle->bundle_items() : array() as $component ) {
					$p = Product::find( $component['product_id'] );
					if ( $p && $p->is_digital() ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @param array<string, array<string, mixed>> $items
	 */
	private function has_product( int $product_id, array $items ): bool {
		foreach ( $items as $item ) {
			if ( (int) $item['product_id'] === $product_id && empty( $item['meta']['fbt_parent'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private static function sanitize_meta( array $meta ): array {
		$clean = array();
		if ( ! empty( $meta['bump'] ) ) {
			$clean['bump'] = absint( $meta['bump'] );
		}
		if ( ! empty( $meta['fbt_parent'] ) ) {
			$clean['fbt_parent'] = absint( $meta['fbt_parent'] );
		}
		return apply_filters( 'wahou_cart_item_meta', $clean, $meta );
	}

	/* ------------------------------------------------------------------
	 * Persistence.
	 * ------------------------------------------------------------------ */

	public function save(): void {
		global $wpdb;
		if ( ! $this->id ) {
			return;
		}
		$this->data['items'] = array_filter( $this->items(), static fn( $i ) => (int) $i['qty'] > 0 );
		$totals              = $this->totals();
		if ( 'abandoned' === $this->status && ! $this->is_empty() ) {
			$this->status = 'active';
		}
		if ( ! $this->user_id && is_user_logged_in() ) {
			$this->user_id = get_current_user_id();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Install::table( 'carts' ),
			array(
				'user_id'    => $this->user_id,
				'email'      => $this->email,
				'status'     => $this->status,
				'currency'   => (string) $totals['currency'],
				'total'      => (int) $totals['total'],
				'item_count' => $this->count(),
				'data'       => wp_json_encode( $this->data ),
				'locale'     => determine_locale(),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $this->id )
		);
		$this->set_count_cookie();

		/**
		 * Fires after a cart was saved.
		 */
		do_action( 'wahou_cart_updated', $this );
	}

	/**
	 * Mark as converted (or recovered when a reminder had been sent).
	 */
	public static function mark_converted( int $cart_id, int $order_id ): void {
		global $wpdb;
		$cart = self::find( $cart_id );
		if ( ! $cart ) {
			return;
		}
		$status = $cart->reminders_sent > 0 || $cart->get( 'recovery_click' ) ? 'recovered' : 'converted';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Install::table( 'carts' ),
			array(
				'status'       => $status,
				'order_id'     => $order_id,
				'converted_at' => current_time( 'mysql', true ),
				'next_reminder_at' => null,
			),
			array( 'id' => $cart_id )
		);
		if ( self::$current && self::$current->id === $cart_id ) {
			self::$current = null;
		}
		do_action( 'wahou_cart_converted', $cart_id, $order_id, $status );
	}

	public function set_cookies(): void {
		if ( headers_sent() || '' === $this->token ) {
			return;
		}
		$secure = is_ssl();
		setcookie(
			self::COOKIE,
			$this->token,
			array(
				'expires'  => time() + 30 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $this->token;
		$this->set_count_cookie();
	}

	private function set_count_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COUNT_COOKIE,
			(string) $this->count(),
			array(
				'expires'  => time() + 30 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	public static function forget_cookies(): void {
		if ( headers_sent() ) {
			return;
		}
		foreach ( array( self::COOKIE, self::COUNT_COOKIE ) as $name ) {
			setcookie( $name, '', time() - YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '' );
			unset( $_COOKIE[ $name ] );
		}
		self::$current        = null;
		self::$current_loaded = true;
	}
}
