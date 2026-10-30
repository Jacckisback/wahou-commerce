<?php
/**
 * Performance: lighter pages, smarter prefetching and cache hygiene.
 *
 *  - Optional removal of the emoji script/styles and oEmbed discovery.
 *  - Speculative loading (WordPress 6.8+): eagerness/mode from settings, with
 *    cart, checkout and account pages excluded.
 *  - Transactional pages and private links are never cached (DONOTCACHEPAGE +
 *    no-cache headers understood by caching plugins, CDNs and hosts).
 *  - Product pages are purged from page caches when the product or its stock
 *    changes (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest
 *    Cache, SiteGround, Nginx Helper).
 *  - Preconnect to Stripe / PayPal only on the checkout page.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Performance;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Performance {

	private const PRIVATE_ARGS = array( 'wahou_order', 'wahou_offer', 'wahou_invoice', 'wahou_dl', 'wahou_login', 'wahou_recover', 'wahou_unsub', 'wahou_buy', 'wahou_add', 'wahou_coupon' );

	public static function init(): void {
		add_action( 'init', array( self::class, 'cleanup_head' ) );
		add_filter( 'wp_speculation_rules_configuration', array( self::class, 'speculation' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( self::class, 'speculation_exclusions' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'no_cache' ), 0 );
		add_filter( 'wp_resource_hints', array( self::class, 'resource_hints' ), 10, 2 );

		add_action( 'save_post_' . PostTypes::PRODUCT, array( self::class, 'purge_product' ), 100 );
		add_action( 'wahou_stock_changed', array( self::class, 'purge_after_stock_change' ), 20, 1 );
	}

	public static function cleanup_head(): void {
		if ( is_admin() ) {
			return;
		}
		if ( Settings::bool( 'perf_disable_emojis' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
		if ( Settings::bool( 'perf_disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			remove_filter( 'embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js' );
		}
	}

	/**
	 * @param array<string, string>|null $config
	 * @return array<string, string>|null
	 */
	public static function speculation( $config ) {
		if ( null === $config ) {
			return null; // Disabled by WordPress for this request (e.g. logged-in user).
		}
		$setting = Settings::str( 'perf_speculation' );
		switch ( $setting ) {
			case 'off':
				return null;
			case 'prerender':
				return array(
					'mode'      => 'prerender',
					'eagerness' => 'moderate',
				);
			case 'eager':
			case 'moderate':
			case 'conservative':
				return array(
					'mode'      => 'prefetch',
					'eagerness' => $setting,
				);
		}
		return $config;
	}

	/**
	 * @param string[] $paths
	 * @return string[]
	 */
	public static function speculation_exclusions( $paths, $mode = '' ): array {
		$paths     = (array) $paths;
		$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		foreach ( array( 'cart', 'checkout', 'account' ) as $key ) {
			$id = Pages::id( $key );
			if ( ! $id ) {
				continue;
			}
			$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
			if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
			$path = '/' . trim( $path, '/' );
			if ( '/' !== $path ) {
				$paths[] = $path;
				$paths[] = $path . '/*';
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Never cache personal pages.
	 */
	public static function no_cache(): void {
		if ( ! self::is_private() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		do_action( 'litespeed_control_set_nocache', 'wahou-commerce' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'X-Accel-Expires: 0' );
		}
	}

	private static function is_private(): bool {
		foreach ( self::PRIVATE_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		return Pages::is_transactional();
	}

	/**
	 * @param array<int, string|array<string, string>> $urls
	 * @return array<int, string|array<string, string>>
	 */
	public static function resource_hints( $urls, $relation_type ): array {
		$urls = (array) $urls;
		if ( 'preconnect' !== $relation_type || is_admin() || ! Pages::is( 'checkout' ) ) {
			return $urls;
		}
		$stripe = Gateways::get( 'stripe' );
		if ( $stripe && $stripe->is_available() ) {
			$urls[] = array(
				'href' => 'https://js.stripe.com',
			);
			$urls[] = array(
				'href'        => 'https://m.stripe.network',
				'crossorigin' => 'anonymous',
			);
		}
		$paypal = Gateways::get( 'paypal' );
		if ( $paypal && $paypal->is_available() ) {
			$urls[] = array(
				'href' => 'https://www.paypal.com',
			);
		}
		return $urls;
	}

	/* ------------------------------------------------------------------
	 * Cache purge.
	 * ------------------------------------------------------------------ */

	/**
	 * @param int $post_id
	 */
	public static function purge_product( $post_id ): void {
		if ( ! Settings::bool( 'perf_purge_cache' ) || wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}
		self::purge( (int) $post_id );
	}

	/**
	 * Stock changes can happen many times per minute: purge at most once a minute per product.
	 *
	 * @param int $product_id
	 */
	public static function purge_after_stock_change( $product_id ): void {
		$product_id = (int) $product_id;
		if ( ! Settings::bool( 'perf_purge_cache' ) || get_transient( 'wahou_purged_' . $product_id ) ) {
			return;
		}
		set_transient( 'wahou_purged_' . $product_id, 1, MINUTE_IN_SECONDS );
		self::purge( $product_id );
	}

	public static function purge( int $product_id ): void {
		$product = Product::find( $product_id );
		if ( ! $product ) {
			return;
		}
		$urls = array( $product->permalink() );
		$shop = Pages::id( 'shop' );
		if ( $shop ) {
			$urls[] = (string) get_permalink( $shop );
		}
		foreach ( $product->categories() as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$urls[] = $link;
			}
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_post', $product_id );
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url );
		}
		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $product_id );
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}
		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $product_id );
		}
		if ( function_exists( 'w3tc_flush_url' ) ) {
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
		}
		// WP Super Cache.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $product_id );
		}
		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $product_id );
		}
		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			foreach ( $urls as $url ) {
				sg_cachepress_purge_cache( $url );
			}
		}
		// Nginx Helper.
		global $nginx_purger;
		if ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_url' ) ) {
			foreach ( $urls as $url ) {
				$nginx_purger->purge_url( $url );
			}
		}

		/**
		 * Fires after Wahou Commerce asked caches to forget a product's pages.
		 *
		 * @param int      $product_id Product ID.
		 * @param string[] $urls       Purged URLs.
		 */
		do_action( 'wahou_cache_purged', $product_id, $urls );
	}
}
