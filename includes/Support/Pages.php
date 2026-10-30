<?php
/**
 * Store pages (shop, cart, checkout, account, terms, privacy).
 *
 * Page IDs are resolved through the wahou_translate_page_id filter so that
 * WPML / Polylang return the page in the visitor's language.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

use WahouCommerce\Orders\Order;
use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Pages {

	public const KEYS = array( 'shop', 'cart', 'checkout', 'account', 'terms', 'privacy' );

	public static function id( string $key, bool $translate = true ): int {
		$id = Settings::int( 'page_' . $key );
		if ( ! $id && 'privacy' === $key ) {
			$id = (int) get_option( 'wp_page_for_privacy_policy' );
		}
		if ( $id && $translate ) {
			/**
			 * Filters a store page ID, e.g. to return its translation.
			 *
			 * @param int    $id  Page ID in the default language.
			 * @param string $key Page key.
			 */
			$id = (int) apply_filters( 'wahou_translate_page_id', $id, $key );
		}
		return $id;
	}

	/**
	 * @param array<string, string|int> $args Query args to add.
	 */
	public static function url( string $key, array $args = array() ): string {
		$id  = self::id( $key );
		$url = $id ? get_permalink( $id ) : '';
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url ) : $url;
	}

	public static function is( string $key ): bool {
		$id = self::id( $key );
		if ( ! $id ) {
			return false;
		}
		if ( is_page( $id ) ) {
			return true;
		}
		$default = self::id( $key, false );
		return $default && is_page( $default );
	}

	/**
	 * Whether the current request renders one of the store's transactional pages.
	 */
	public static function is_transactional(): bool {
		return self::is( 'cart' ) || self::is( 'checkout' ) || self::is( 'account' );
	}

	public static function order_received_url( Order $order ): string {
		return self::url( 'checkout', array( 'wahou_order' => $order->key() ) );
	}

	public static function offer_url( Order $order, int $offer_id ): string {
		return self::url(
			'checkout',
			array(
				'wahou_order' => $order->key(),
				'wahou_offer' => $offer_id,
			)
		);
	}

	public static function invoice_url( Order $order ): string {
		return add_query_arg( 'wahou_invoice', $order->key(), home_url( '/' ) );
	}

	/**
	 * Labels used for page creation and admin screens.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'shop'     => __( 'Shop', 'wahou-commerce' ),
			'cart'     => __( 'Cart', 'wahou-commerce' ),
			'checkout' => __( 'Checkout', 'wahou-commerce' ),
			'account'  => __( 'My account', 'wahou-commerce' ),
			'terms'    => __( 'Terms and conditions of sale', 'wahou-commerce' ),
			'privacy'  => __( 'Privacy policy', 'wahou-commerce' ),
		);
	}
}
