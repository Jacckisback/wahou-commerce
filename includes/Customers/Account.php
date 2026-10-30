<?php
/**
 * Customer accounts without passwords.
 *
 *  - Accounts are created automatically after the first paid order
 *    (role wahou_customer) and linked to every order placed with the email.
 *  - Customers log in with a magic link: a random token, stored hashed in
 *    user meta, single use, valid 30 minutes (24 hours when sent with the
 *    order confirmation). Opening the link shows a confirmation button that
 *    performs the login with a POST, so email security scanners that "click"
 *    links cannot consume the token.
 *  - Staff accounts (users who can edit posts) never get magic links.
 *  - The request endpoint always answers the same way (no account enumeration).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Customers;

use WahouCommerce\Checkout\Checkout;
use WahouCommerce\Downloads\Downloads;
use WahouCommerce\Emails\Emails;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Rest\RestApi;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\RateLimiter;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Account {

	private const TOKENS_META = 'wahou_magic_tokens';
	private const LINK_TTL    = 30 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 8 );
		add_action( 'wahou_order_paid', array( self::class, 'on_order_paid' ), 7 );
	}

	/* ------------------------------------------------------------------
	 * REST.
	 * ------------------------------------------------------------------ */

	public static function routes(): void {
		register_rest_route(
			RestApi::NS,
			'/account/magic-link',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'rest_magic_link' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'redirect' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_magic_link( \WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request['email'] );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'wahou_invalid_email', __( 'Please enter a valid email address.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'magic_ip', 5, 15 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wahou_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'wahou-commerce' ), array( 'status' => 429 ) );
		}
		self::request_link( $email, (string) $request['redirect'] );
		$response = rest_ensure_response(
			array(
				'success' => true,
				'message' => self::generic_message(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function generic_message(): string {
		return __( 'If an account exists for this email, a login link is on its way. Check your inbox (and your spam folder).', 'wahou-commerce' );
	}

	/**
	 * Send a magic link when the email belongs to a customer. Silent otherwise.
	 */
	public static function request_link( string $email, string $redirect = '' ): void {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || ! RateLimiter::hit( 'magic_email', 3, HOUR_IN_SECONDS, md5( $email ) ) ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user && Settings::bool( 'auto_create_account' ) && self::has_paid_orders( $email ) ) {
			$user_id = self::create_customer( $email, array() );
			$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
			if ( $user ) {
				self::link_orders( $user->ID, $email );
			}
		}
		if ( ! $user || user_can( $user, 'edit_posts' ) ) {
			return;
		}
		$url = self::magic_url_for_user( (int) $user->ID, self::LINK_TTL, $redirect );
		if ( class_exists( Emails::class ) && method_exists( Emails::class, 'magic_link' ) ) {
			Emails::magic_link( $email, $url, determine_locale() );
			return;
		}
		wp_mail(
			$email,
			/* translators: %s: store name. */
			sprintf( __( 'Your login link for %s', 'wahou-commerce' ), Settings::store_name() ),
			sprintf(
				/* translators: %s: login URL. */
				__( "Hello,\n\nClick this link to log in to your account (valid for 30 minutes, single use):\n%s\n\nIf you did not request it, you can ignore this email.", 'wahou-commerce' ),
				$url
			)
		);
	}

	/**
	 * One-time login URL for emails (order confirmation...). Falls back to the
	 * account page when the email has no customer account.
	 */
	public static function magic_url( string $email, int $ttl = DAY_IN_SECONDS ): string {
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user || user_can( $user, 'edit_posts' ) ) {
			return self::account_url();
		}
		return self::magic_url_for_user( (int) $user->ID, $ttl );
	}

	private static function magic_url_for_user( int $user_id, int $ttl, string $redirect = '' ): string {
		$args = array(
			'wahou_login' => self::create_token( $user_id, $ttl ),
			'u'           => $user_id,
		);
		$redirect = wp_validate_redirect( $redirect, '' );
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), self::account_url() );
	}

	public static function account_url(): string {
		return Pages::id( 'account' ) ? Pages::url( 'account' ) : home_url( '/' );
	}

	/* ------------------------------------------------------------------
	 * Tokens.
	 * ------------------------------------------------------------------ */

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . '|wahou-magic' );
	}

	private static function create_token( int $user_id, int $ttl ): string {
		$token  = Crypto::key_string( 40 );
		$now    = time();
		$tokens = get_user_meta( $user_id, self::TOKENS_META, true );
		$tokens = is_array( $tokens ) ? array_filter( $tokens, static fn( $expires ) => (int) $expires > $now ) : array();

		$tokens[ self::hash( $token ) ] = $now + max( 5 * MINUTE_IN_SECONDS, $ttl );
		if ( count( $tokens ) > 10 ) {
			asort( $tokens );
			$tokens = array_slice( $tokens, -10, null, true );
		}
		update_user_meta( $user_id, self::TOKENS_META, $tokens );
		return $token;
	}

	public static function verify_token( int $user_id, string $token, bool $consume = true ): bool {
		if ( $user_id <= 0 || ! preg_match( '/^[A-Za-z0-9]{40}$/', $token ) ) {
			return false;
		}
		$tokens = get_user_meta( $user_id, self::TOKENS_META, true );
		if ( ! is_array( $tokens ) ) {
			return false;
		}
		$hash  = self::hash( $token );
		$valid = false;
		foreach ( $tokens as $stored => $expires ) {
			if ( hash_equals( (string) $stored, $hash ) && (int) $expires > time() ) {
				$valid = true;
				break;
			}
		}
		if ( $valid && $consume ) {
			unset( $tokens[ $hash ] );
			update_user_meta( $user_id, self::TOKENS_META, $tokens );
		}
		return $valid;
	}

	/* ------------------------------------------------------------------
	 * Customers.
	 * ------------------------------------------------------------------ */

	/**
	 * After payment: link the order to an account (created when needed) and
	 * remember the addresses for the next checkout.
	 */
	public static function on_order_paid( Order $order ): void {
		$email = strtolower( $order->email() );
		if ( ! is_email( $email ) ) {
			return;
		}
		$user_id = $order->customer_id();
		// Only the account holder (logged in at checkout) or a brand new account may
		// have its profile updated: a guest typing someone else's email must not.
		$may_update_profile = $user_id > 0;
		if ( ! $user_id ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = (int) $user->ID;
			} elseif ( Settings::bool( 'auto_create_account' ) ) {
				$user_id            = self::create_customer( $email, $order->billing() );
				$may_update_profile = $user_id > 0;
			}
		}
		if ( ! $user_id ) {
			return;
		}
		if ( $order->customer_id() !== $user_id ) {
			$order->update( array( 'customer_id' => $user_id ) );
		}
		self::link_orders( $user_id, $email );
		if ( ! $may_update_profile ) {
			return;
		}

		$billing = array_filter( $order->billing(), 'is_scalar' );
		if ( $billing ) {
			update_user_meta( $user_id, 'wahou_billing', $billing );
		}
		$shipping = array_filter( $order->shipping_address(), 'is_scalar' );
		if ( $shipping ) {
			update_user_meta( $user_id, 'wahou_shipping', $shipping );
		}
		$user = get_userdata( $user_id );
		if ( $user && '' === (string) $user->first_name && ! empty( $billing['first_name'] ) ) {
			wp_update_user(
				array(
					'ID'         => $user_id,
					'first_name' => (string) $billing['first_name'],
					'last_name'  => (string) ( $billing['last_name'] ?? '' ),
				)
			);
		}
	}

	/**
	 * @param array<string, string> $billing
	 */
	private static function create_customer( string $email, array $billing ): int {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = '' !== $base ? substr( $base, 0, 40 ) : 'customer';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . ( $i < 50 ? $i : wp_rand( 1000, 999999 ) );
		}
		$first = sanitize_text_field( (string) ( $billing['first_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $billing['last_name'] ?? '' ) );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => get_role( 'wahou_customer' ) ? 'wahou_customer' : 'subscriber',
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) ?: $login,
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		/**
		 * Fires after a customer account was created automatically.
		 */
		do_action( 'wahou_customer_created', (int) $id, $email );
		return (int) $id;
	}

	/**
	 * Attach guest orders placed with the email to the account.
	 */
	public static function link_orders( int $user_id, string $email ): void {
		global $wpdb;
		if ( ! $user_id || ! is_email( $email ) ) {
			return;
		}
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET customer_id = %d WHERE email = %s AND customer_id = 0", $user_id, $email ) );
	}

	private static function has_paid_orders( string $email ): bool {
		$result = Order::query(
			array(
				'email' => $email,
				'paid'  => true,
				'limit' => 1,
			)
		);
		return $result['total'] > 0;
	}

	/* ------------------------------------------------------------------
	 * Form handling (template_redirect).
	 * ------------------------------------------------------------------ */

	public static function handle_request(): void {
		// Magic link opened on a page without the account block: send it to the account page.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['wahou_login'] ) && ! is_user_logged_in() && Pages::id( 'account' ) && ! Pages::is( 'account' ) && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$args = array(
				'wahou_login' => sanitize_text_field( wp_unslash( $_GET['wahou_login'] ) ),
				'u'           => absint( $_GET['u'] ?? 0 ),
			);
			wp_safe_redirect( add_query_arg( $args, Pages::url( 'account' ) ) );
			exit;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wahou_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['wahou_action'] ) );
		if ( ! in_array( $action, array( 'magic_link', 'magic_login', 'save_addresses' ), true ) ) {
			return;
		}
		$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ), '' );
		// phpcs:enable

		nocache_headers();
		switch ( $action ) {
			case 'magic_link':
				self::post_magic_link( $redirect );
				break;
			case 'magic_login':
				self::post_magic_login( $redirect );
				break;
			case 'save_addresses':
				self::post_save_addresses();
				break;
		}
	}

	private static function post_magic_link( string $redirect ): void {
		if ( ! self::same_origin() ) {
			self::back( 'session' );
		}
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Same-origin check above.
		if ( ! is_email( $email ) ) {
			self::back( 'invalid_email' );
		}
		if ( ! RateLimiter::hit( 'magic_ip', 5, 15 * MINUTE_IN_SECONDS ) ) {
			self::back( 'rate_limited' );
		}
		self::request_link( $email, $redirect );
		self::back( 'link_sent' );
	}

	private static function post_magic_login( string $redirect ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Token + same-origin protected.
		$token   = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$user_id = absint( $_POST['u'] ?? 0 );
		// phpcs:enable
		if ( ! self::same_origin() ) {
			self::back( 'session' );
		}
		if ( ! RateLimiter::hit( 'magic_login', 10, 15 * MINUTE_IN_SECONDS ) ) {
			self::back( 'rate_limited' );
		}
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			self::back( 'link_invalid' );
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			self::back( 'staff' );
		}
		if ( ! self::verify_token( $user_id, $token, true ) ) {
			self::back( 'link_invalid' );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );
		self::link_orders( $user_id, $user->user_email );

		wp_safe_redirect( '' !== $redirect ? $redirect : self::account_url() );
		exit;
	}

	private static function post_save_addresses(): void {
		if ( ! is_user_logged_in() ) {
			self::back( 'session' );
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wahou_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, 'wahou_account_addresses' ) ) {
			self::back( 'session', 'addresses' );
		}
		$user_id = get_current_user_id();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$raw    = isset( $_POST[ $group ] ) && is_array( $_POST[ $group ] ) ? wp_unslash( $_POST[ $group ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			$fields = array_keys( Checkout::address_fields( $group, true ) );
			if ( 'billing' === $group ) {
				$fields[] = 'company';
				$fields[] = 'vat_number';
				$fields[] = 'phone';
			}
			$clean = array();
			foreach ( array_unique( $fields ) as $field ) {
				$value = is_scalar( $raw[ $field ] ?? null ) ? trim( (string) $raw[ $field ] ) : '';
				if ( 'country' === $field ) {
					$value = strtoupper( substr( $value, 0, 2 ) );
					$value = Countries::exists( $value ) ? $value : '';
				} elseif ( 'vat_number' === $field ) {
					$value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $value ) ?? '' );
				} else {
					$value = sanitize_text_field( $value );
				}
				if ( '' !== $value ) {
					$clean[ $field ] = substr( $value, 0, 190 );
				}
			}
			update_user_meta( $user_id, 'wahou_' . $group, $clean );
		}
		self::back( 'addresses_saved', 'addresses' );
	}

	/**
	 * Redirect back to the account page with a message code.
	 *
	 * @return never
	 */
	private static function back( string $message, string $view = '' ): void {
		$args = array( 'wahou_msg' => $message );
		if ( '' !== $view ) {
			$args['view'] = $view;
		}
		wp_safe_redirect( add_query_arg( $args, self::account_url() ) );
		exit;
	}

	/**
	 * CSRF check that also works for logged-out visitors: the POST must come
	 * from this site (Origin header, or Referer as a fallback).
	 */
	private static function same_origin(): bool {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $origin && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( '' === $origin ) {
			return true; // Privacy settings may strip both headers; the token still protects the login.
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === strtolower( (string) $host );
	}

	/* ------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{type:string, text:string}|null
	 */
	private static function message(): ?array {
		$code     = isset( $_GET['wahou_msg'] ) ? sanitize_key( wp_unslash( $_GET['wahou_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'link_sent'       => array( 'success', self::generic_message() ),
			'link_invalid'    => array( 'error', __( 'This login link is invalid or has expired. Request a new one below.', 'wahou-commerce' ) ),
			'staff'           => array( 'error', __( 'For security reasons, staff accounts must log in with their password.', 'wahou-commerce' ) ),
			'rate_limited'    => array( 'error', __( 'Too many requests. Please wait a few minutes and try again.', 'wahou-commerce' ) ),
			'session'         => array( 'error', __( 'Your session has expired. Please try again.', 'wahou-commerce' ) ),
			'invalid_email'   => array( 'error', __( 'Please enter a valid email address.', 'wahou-commerce' ) ),
			'addresses_saved' => array( 'success', __( 'Your addresses have been saved.', 'wahou-commerce' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return null;
		}
		return array(
			'type' => $messages[ $code ][0],
			'text' => $messages[ $code ][1],
		);
	}

	/**
	 * Account page (block / shortcode).
	 */
	public static function render(): string {
		if ( class_exists( '\WahouCommerce\Frontend\CheckoutPage' ) ) {
			\WahouCommerce\Frontend\CheckoutPage::enqueue_assets( 'account' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$redirect = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ?? '' ) ), '' );
		$message  = self::message();

		if ( ! is_user_logged_in() ) {
			$token   = isset( $_GET['wahou_login'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_login'] ) ) : '';
			$user_id = absint( $_GET['u'] ?? 0 );
			if ( '' !== $token && $user_id ) {
				$user = get_userdata( $user_id );
				return Template::get(
					'account/magic-confirm',
					array(
						'token'    => $token,
						'user_id'  => $user_id,
						'valid'    => $user && ! user_can( $user, 'edit_posts' ) && self::verify_token( $user_id, $token, false ),
						'email'    => $user ? self::mask_email( (string) $user->user_email ) : '',
						'redirect' => $redirect,
						'action'   => self::account_url(),
					)
				);
			}
			return Template::get(
				'account/login',
				array(
					'message'  => $message,
					'redirect' => $redirect,
					'action'   => self::account_url(),
				)
			);
		}

		$user = wp_get_current_user();
		self::link_orders( (int) $user->ID, (string) $user->user_email );
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'orders';
		// phpcs:enable

		switch ( $view ) {
			case 'order':
				$order = Order::find( absint( $_GET['order'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $order && self::owns( $order, $user ) ) {
					$content = Template::get(
						'account/order',
						array(
							'order'     => $order,
							'downloads' => Downloads::for_order( $order ),
						)
					);
				} else {
					$view    = 'orders';
					$content = self::orders_html( $user );
				}
				break;
			case 'downloads':
				$content = Template::get( 'account/downloads', array( 'downloads' => Downloads::for_customer( (string) $user->user_email, (int) $user->ID ) ) );
				break;
			case 'addresses':
				$content = Template::get(
					'account/addresses',
					array(
						'billing'            => (array) get_user_meta( $user->ID, 'wahou_billing', true ),
						'shipping'           => (array) get_user_meta( $user->ID, 'wahou_shipping', true ),
						'countries'          => Countries::all(),
						'shipping_countries' => Countries::selectable( Shipping::countries() ),
						'action'             => self::account_url(),
					)
				);
				break;
			default:
				$view    = 'orders';
				$content = self::orders_html( $user );
		}

		return Template::get(
			'account/dashboard',
			array(
				'user'    => $user,
				'view'    => $view,
				'content' => $content,
				'message' => $message,
				'base'    => self::account_url(),
			)
		);
	}

	private static function orders_html( \WP_User $user ): string {
		$result = Order::query(
			array(
				'customer_id' => (int) $user->ID,
				'limit'       => 50,
			)
		);
		$orders = array_values( array_filter( $result['orders'], static fn( Order $o ) => ! in_array( $o->status(), array( 'pending', 'failed' ), true ) || strtotime( $o->created_at() . ' UTC' ) > time() - DAY_IN_SECONDS ) );
		return Template::get(
			'account/orders',
			array(
				'orders' => $orders,
				'base'   => self::account_url(),
			)
		);
	}

	public static function owns( Order $order, \WP_User $user ): bool {
		return ( $order->customer_id() && $order->customer_id() === (int) $user->ID )
			|| ( '' !== $order->email() && strtolower( $order->email() ) === strtolower( (string) $user->user_email ) );
	}

	public static function order_url( Order $order ): string {
		return add_query_arg(
			array(
				'view'  => 'order',
				'order' => $order->id(),
			),
			self::account_url()
		);
	}

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		$name = $parts[0];
		$keep = max( 1, min( 3, (int) floor( strlen( $name ) / 3 ) ) );
		return substr( $name, 0, $keep ) . str_repeat( '•', max( 2, strlen( $name ) - $keep ) ) . '@' . $parts[1];
	}
}
