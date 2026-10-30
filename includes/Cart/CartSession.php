<?php
/**
 * Cart lifecycle hooks: login attachment, marketing links and cleanup.
 *
 * Marketing links (shareable anywhere – emails, social bios, QR codes, books):
 *   ?wahou_buy=123            Direct checkout with product 123 (cart replaced).
 *   ?wahou_buy=123:2&variant=v1  Quantity and variant.
 *   ?wahou_add=123            Add to cart and open it.
 *   ?wahou_coupon=CODE        Pre-apply a coupon (kept until checkout).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Cart;

use WahouCommerce\Install;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class CartSession {

	public static function init(): void {
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( Cart::class, 'forget_cookies' ) );
		add_action( 'template_redirect', array( self::class, 'handle_links' ), 5 );
		add_action( 'wahou_cron_daily', array( self::class, 'cleanup' ) );
	}

	/**
	 * Attach the anonymous cart to the account that just logged in.
	 */
	public static function on_login( string $login, \WP_User $user ): void {
		global $wpdb;
		$token = isset( $_COOKIE[ Cart::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Cart::COOKIE ] ) ) : '';
		if ( ! $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Install::table( 'carts' ),
			array(
				'user_id' => $user->ID,
				'email'   => $user->user_email,
			),
			array(
				'token'   => $token,
				'user_id' => 0,
			)
		);
	}

	public static function handle_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public idempotent marketing links.
		$coupon = isset( $_GET['wahou_coupon'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_coupon'] ) ) : '';
		$buy    = isset( $_GET['wahou_buy'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_buy'] ) ) : '';
		$add    = isset( $_GET['wahou_add'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_add'] ) ) : '';
		$vid    = isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '';
		// phpcs:enable

		if ( '' === $coupon && '' === $buy && '' === $add ) {
			return;
		}
		if ( self::is_bot() ) {
			return;
		}

		$cart = Cart::current( true );
		if ( ! $cart ) {
			return;
		}
		$redirect = remove_query_arg( array( 'wahou_coupon', 'wahou_buy', 'wahou_add', 'variant' ) );

		if ( '' !== $buy || '' !== $add ) {
			list( $id, $qty ) = array_pad( array_map( 'absint', explode( ':', '' !== $buy ? $buy : $add ) ), 2, 1 );
			if ( '' !== $buy ) {
				$cart->clear();
			}
			$result = $cart->add( $id, max( 1, $qty ), $vid ?: null );
			if ( is_wp_error( $result ) ) {
				$product_url = get_permalink( $id );
				$redirect    = $product_url ? $product_url : $redirect;
			} else {
				$redirect = '' !== $buy ? Pages::url( 'checkout' ) : add_query_arg( 'wahou_open_cart', '1', $redirect );
			}
		}
		if ( '' !== $coupon ) {
			// Stored even on an empty cart: it applies as soon as products are added.
			$cart->set( 'coupon', \WahouCommerce\Coupons\Coupons::normalize( $coupon ) );
		}
		$cart->save();

		nocache_headers();
		wp_safe_redirect( $redirect, 302, 'Wahou Commerce' );
		exit;
	}

	/**
	 * Delete stale carts: empty or anonymous carts after 7 days, others after
	 * the retention period (GDPR data minimisation).
	 */
	public static function cleanup(): void {
		global $wpdb;
		$table     = Install::table( 'carts' );
		$retention = max( 7, Settings::int( 'recovery_retention_days' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = '' AND status IN ('active','abandoned') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_count = 0 AND status = 'active' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview|fetch|curl|wget|python|headless/', $ua );
	}
}
