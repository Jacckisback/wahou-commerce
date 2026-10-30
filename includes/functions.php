<?php
/**
 * Global helpers for themes and integrations.
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;

if ( ! function_exists( 'wahou_setting' ) ) {
	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	function wahou_setting( string $key, $fallback = null ) {
		return Settings::get( $key, $fallback );
	}
}

if ( ! function_exists( 'wahou_get_product' ) ) {
	/**
	 * @param int|WP_Post|null $product
	 */
	function wahou_get_product( $product = null ): ?Product {
		return Product::find( $product ?? get_post() );
	}
}

if ( ! function_exists( 'wahou_get_order' ) ) {
	/**
	 * @param int|string $id_or_key Order ID or order key.
	 */
	function wahou_get_order( $id_or_key ): ?Order {
		return is_numeric( $id_or_key ) ? Order::find( (int) $id_or_key ) : Order::find_by_key( (string) $id_or_key );
	}
}

if ( ! function_exists( 'wahou_cart' ) ) {
	function wahou_cart( bool $create = false ): ?Cart {
		return Cart::current( $create );
	}
}

if ( ! function_exists( 'wahou_price' ) ) {
	/**
	 * Formatted price from minor units, e.g. wahou_price( 1990 ) => "19,90 €".
	 */
	function wahou_price( int $minor, ?string $currency = null ): string {
		return Money::format( $minor, $currency );
	}
}

if ( ! function_exists( 'wahou_page_url' ) ) {
	/**
	 * @param string                    $page shop|cart|checkout|account|terms|privacy
	 * @param array<string, string|int> $args
	 */
	function wahou_page_url( string $page, array $args = array() ): string {
		return Pages::url( $page, $args );
	}
}

if ( ! function_exists( 'wahou_template' ) ) {
	/**
	 * @param array<string, mixed> $vars
	 */
	function wahou_template( string $name, array $vars = array() ): void {
		Template::render( $name, $vars );
	}
}

if ( ! function_exists( 'wahou_buy_url' ) ) {
	/**
	 * Direct checkout link for a product (emails, social bios, QR codes, e-books).
	 */
	function wahou_buy_url( int $product_id, int $qty = 1, string $coupon = '' ): string {
		$args = array( 'wahou_buy' => $qty > 1 ? $product_id . ':' . $qty : $product_id );
		if ( '' !== $coupon ) {
			$args['wahou_coupon'] = $coupon;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}
}

if ( ! function_exists( 'wahou_date' ) ) {
	/**
	 * Localized date (month names in the current language) from a GMT MySQL datetime.
	 */
	function wahou_date( string $gmt_datetime, string $format = '' ): string {
		$timestamp = '' !== $gmt_datetime ? strtotime( $gmt_datetime . ' UTC' ) : false;
		if ( ! $timestamp ) {
			return '';
		}
		return (string) wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), $timestamp );
	}
}
