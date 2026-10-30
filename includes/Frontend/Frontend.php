<?php
/**
 * Storefront bootstrap: assets, page context, cart drawer and no-JS forms.
 *
 * Performance rules:
 *  - Store assets load only on store pages (products, categories, shop, cart,
 *    checkout, account, pages using Wahou blocks/shortcodes).
 *  - Everywhere else a ~1 KB inline script reads the wahou_cart_count cookie,
 *    shows a floating cart button when the cart is not empty and lazy-loads
 *    the store assets on first click. Pages stay fully cacheable.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Rest\RestApi;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	private static ?bool $store_page = null;
	private static bool $enqueued    = false;
	private static bool $data_added  = false;

	public static function init(): void {
		Templates::init();
		Shortcodes::init();
		Blocks::init();
		if ( class_exists( CheckoutPage::class ) && method_exists( CheckoutPage::class, 'init' ) ) {
			CheckoutPage::init();
		}

		add_action( 'init', array( self::class, 'register_assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue' ), 5 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_footer', array( self::class, 'footer' ), 5 );
		add_action( 'wp_loaded', array( self::class, 'handle_post' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	public static function register_assets(): void {
		$v = WAHOU_VERSION;
		wp_register_style( 'wahou', \WahouCommerce\Support\Assets::url( 'css/wahou.css' ), array(), $v );
		wp_register_style( 'wahou-checkout', \WahouCommerce\Support\Assets::url( 'css/checkout.css' ), array( 'wahou' ), $v );
		wp_add_inline_style( 'wahou', self::css_variables() );

		$footer = array(
			'strategy'  => 'defer',
			'in_footer' => true,
		);
		wp_register_script( 'wahou', \WahouCommerce\Support\Assets::url( 'js/wahou.js' ), array(), $v, $footer );
		wp_register_script( 'wahou-checkout', \WahouCommerce\Support\Assets::url( 'js/checkout.js' ), array( 'wahou' ), $v, $footer );
		wp_register_script(
			'wahou-blocks',
			WAHOU_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-data' ),
			$v,
			true
		);
	}

	public static function maybe_enqueue(): void {
		if ( self::is_store_page() ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue the storefront assets (idempotent; safe to call while rendering).
	 */
	public static function enqueue(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! wp_style_is( 'wahou', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'wahou' );
		wp_enqueue_script( 'wahou' );
		if ( ! self::$data_added ) {
			self::$data_added = true;
			wp_add_inline_script( 'wahou', 'window.wahouData=' . wp_json_encode( self::data() ) . ';', 'before' );
		}
		self::$enqueued = true;
	}

	public static function is_enqueued(): bool {
		return self::$enqueued;
	}

	/**
	 * Whether the current request renders store UI.
	 */
	public static function is_store_page(): bool {
		if ( null !== self::$store_page ) {
			return self::$store_page;
		}
		$store = is_singular( PostTypes::PRODUCT ) || is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) );
		if ( ! $store ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'account' ) as $key ) {
				if ( Pages::is( $key ) ) {
					$store = true;
					break;
				}
			}
		}
		if ( ! $store && is_singular() ) {
			$post  = get_post();
			$store = $post && ( false !== strpos( $post->post_content, '<!-- wp:wahou/' ) || false !== strpos( $post->post_content, '[wahou_' ) );
		}
		/**
		 * Filters whether the current page loads the store assets.
		 */
		self::$store_page = (bool) apply_filters( 'wahou_is_store_page', $store );
		return self::$store_page;
	}

	public static function context(): string {
		if ( is_singular( PostTypes::PRODUCT ) ) {
			return 'product';
		}
		if ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			return 'archive';
		}
		foreach ( array( 'checkout', 'cart', 'account', 'shop' ) as $key ) {
			if ( Pages::is( $key ) ) {
				return $key;
			}
		}
		return 'other';
	}

	/**
	 * BCP 47 locale for Intl (fr_FR => fr-FR, de_DE_formal => de-DE).
	 */
	public static function bcp47(): string {
		$locale = determine_locale();
		if ( preg_match( '/^([a-z]{2,3})(?:_([A-Z]{2}))?/', $locale, $m ) ) {
			return $m[1] . ( isset( $m[2] ) ? '-' . $m[2] : '' );
		}
		return 'en';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		return apply_filters(
			'wahou_frontend_data',
			array(
				'restUrl'     => esc_url_raw( rest_url( RestApi::NS . '/' ) ),
				'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'currency'    => Settings::currency(),
				'locale'      => self::bcp47(),
				'cartMode'    => 'page' === Settings::str( 'cart_mode' ) ? 'page' : 'drawer',
				'cartUrl'     => Pages::url( 'cart' ),
				'checkoutUrl' => Pages::url( 'checkout' ),
				'shopUrl'     => Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' ),
				'analytics'   => Settings::bool( 'analytics_datalayer' ),
				'brandColor'  => self::brand_color(),
				'radius'      => max( 0, Settings::int( 'radius' ) ),
				'context'     => self::context(),
				'floatingCart' => (bool) apply_filters( 'wahou_floating_cart', true ),
				'i18n'        => array(
					'added'        => __( 'Added to cart', 'wahou-commerce' ),
					'viewCart'     => __( 'View cart', 'wahou-commerce' ),
					'checkout'     => __( 'Checkout', 'wahou-commerce' ),
					'chooseOption' => __( 'Please choose an option.', 'wahou-commerce' ),
					'error'        => __( 'Something went wrong. Please try again.', 'wahou-commerce' ),
					'cart'         => __( 'Cart', 'wahou-commerce' ),
					'openCart'     => __( 'Open cart', 'wahou-commerce' ),
					'cartUpdated'  => __( 'Cart updated', 'wahou-commerce' ),
					'loading'      => __( 'Loading…', 'wahou-commerce' ),
					'close'        => __( 'Close', 'wahou-commerce' ),
					'addToCart'    => __( 'Add to cart', 'wahou-commerce' ),
					'adding'       => __( 'Adding…', 'wahou-commerce' ),
					'outOfStock'   => __( 'Out of stock', 'wahou-commerce' ),
					'previous'     => __( 'Previous image', 'wahou-commerce' ),
					'next'         => __( 'Next image', 'wahou-commerce' ),
					/* translators: 1: image position, 2: number of images. */
					'imageOf'      => __( 'Image %1$d of %2$d', 'wahou-commerce' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Design tokens.
	 * ------------------------------------------------------------------ */

	public static function brand_color(): string {
		$color = sanitize_hex_color( Settings::str( 'brand_color' ) );
		return $color ? $color : '#5b3df5';
	}

	/**
	 * Readable text color on top of a background color (WCAG contrast).
	 */
	public static function on_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '#ffffff';
		}
		$channel = static function ( string $part ): float {
			$c = hexdec( $part ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		$lum   = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );
		$white = 1.05 / ( $lum + 0.05 );
		$black = ( $lum + 0.05 ) / 0.05;
		return $white >= $black ? '#ffffff' : '#111111';
	}

	public static function css_variables(): string {
		$brand  = self::brand_color();
		$radius = max( 0, min( 32, Settings::int( 'radius' ) ) );
		$style  = Settings::str( 'button_style' );
		$button = 'pill' === $style ? '999px' : ( 'square' === $style ? '4px' : $radius . 'px' );
		return sprintf(
			':root{--wahou-brand:%1$s;--wahou-on-brand:%2$s;--wahou-radius:%3$dpx;--wahou-btn-radius:%4$s}',
			$brand,
			self::on_color( $brand ),
			$radius,
			$button
		);
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_store_page() ) {
			$classes[] = 'wahou-store';
			$classes[] = 'wahou-context-' . self::context();
		}
		return $classes;
	}

	/* ------------------------------------------------------------------
	 * Footer: drawer container or lazy floating cart.
	 * ------------------------------------------------------------------ */

	public static function footer(): void {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		if ( self::$enqueued ) {
			if ( 'page' !== Settings::str( 'cart_mode' ) && ! in_array( self::context(), array( 'cart', 'checkout' ), true ) ) {
				echo '<div id="wahou-drawer" class="wahou-drawer" hidden><div class="wahou-drawer__overlay" data-wahou-close></div>'
					. '<div class="wahou-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="wahou-drawer-title" tabindex="-1">'
					. '<div class="wahou-drawer__content" data-wahou-drawer-content></div></div></div>';
			}
			echo '<div class="wahou-toasts" aria-live="polite" aria-atomic="true"></div>';
			return;
		}
		if ( ! apply_filters( 'wahou_floating_cart', true ) ) {
			return;
		}
		self::print_lazy_loader();
	}

	/**
	 * Tiny loader for non-store pages (only does something when the cart is not empty).
	 */
	private static function print_lazy_loader(): void {
		$config = array(
			'css'  => add_query_arg( 'ver', WAHOU_VERSION, \WahouCommerce\Support\Assets::url( 'css/wahou.css' ) ),
			'js'   => add_query_arg( 'ver', WAHOU_VERSION, \WahouCommerce\Support\Assets::url( 'js/wahou.js' ) ),
			'vars' => self::css_variables(),
			'data' => self::data(),
		);
		$icon   = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>';
		$js     = '(function(d,w,c){var m=d.cookie.match(/(?:^|;\s*)wahou_cart_count=(\d+)/),n=m?+m[1]:0;if(!n||w.Wahou)return;w.wahouData=w.wahouData||c.data;'
			. 'var s=d.createElement("style");s.textContent=c.vars+".wahou-fab{position:fixed;right:16px;bottom:calc(16px + env(safe-area-inset-bottom));z-index:99990;display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;border:0;background:var(--wahou-brand);color:var(--wahou-on-brand);box-shadow:0 8px 24px rgba(0,0,0,.2);cursor:pointer;text-decoration:none}.wahou-fab__count{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#111;color:#fff;font:600 12px/22px system-ui,sans-serif;text-align:center}";d.head.appendChild(s);'
			. 'var b=d.createElement("a");b.className="wahou-fab";b.href=c.data.cartUrl;b.setAttribute("aria-label",c.data.i18n.openCart+" ("+n+")");b.innerHTML=' . wp_json_encode( $icon ) . '+"<span class=\"wahou-fab__count\" data-wahou-count>"+n+"</span>";'
			. 'var busy=0;b.addEventListener("click",function(e){if(w.Wahou||c.data.cartMode!=="drawer")return;e.preventDefault();if(busy)return;busy=1;'
			. 'var l=d.createElement("link");l.rel="stylesheet";l.href=c.css;d.head.appendChild(l);var j=d.createElement("script");j.src=c.js;j.defer=true;j.onload=function(){b.setAttribute("data-wahou-cart-toggle","");if(w.Wahou)w.Wahou.cart.open();};d.body.appendChild(j);});'
			. 'd.body.appendChild(b);})(document,window,' . wp_json_encode( $config ) . ');';
		echo '<script id="wahou-cart-loader">' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded config.
	}

	/* ------------------------------------------------------------------
	 * No-JS add to cart.
	 * ------------------------------------------------------------------ */

	public static function handle_post(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['wahou_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['wahou_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'add_to_cart' !== $action ) {
			return;
		}
		$referer = wp_get_referer();
		$back    = $referer ? $referer : home_url( '/' );
		if ( ! self::verify_post( 'wahou_add_to_cart' ) ) {
			wp_safe_redirect( add_query_arg( 'wahou_notice', 'error', $back ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above (nonce or same origin).
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
		$variant_id = isset( $_POST['variant_id'] ) ? (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( $_POST['variant_id'] ) ) ) : '';
		$fbt        = isset( $_POST['fbt'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['fbt'] ) ) ) : array();
		$buy_now    = ! empty( $_POST['wahou_buy_now'] );
		$skip_main  = ! empty( $_POST['wahou_fbt_only'] );
		// phpcs:enable

		$cart   = Cart::current( true );
		$result = true;
		if ( $product_id && ! $skip_main ) {
			$result = $cart->add( $product_id, $qty, '' !== $variant_id ? $variant_id : null );
		}
		if ( ! is_wp_error( $result ) ) {
			foreach ( $fbt as $id ) {
				$added = $cart->add( $id, 1, null, $skip_main ? array() : array( 'fbt_parent' => $product_id ) );
				if ( is_wp_error( $added ) ) {
					$result = $added;
				}
			}
		}
		$cart->save();

		if ( is_wp_error( $result ) ) {
			$codes = array(
				'wahou_out_of_stock'    => 'out_of_stock',
				'wahou_choose_variant'  => 'choose_variant',
				'wahou_not_purchasable' => 'not_purchasable',
			);
			$code  = $codes[ $result->get_error_code() ] ?? 'error';
			$url   = $product_id ? (string) get_permalink( $product_id ) : $back;
			wp_safe_redirect( add_query_arg( 'wahou_notice', $code, $url ) );
			exit;
		}
		if ( $buy_now ) {
			$dest = Pages::url( 'checkout' );
		} elseif ( Pages::id( 'cart' ) ) {
			$dest = Pages::url( 'cart' );
		} else {
			$dest = add_query_arg( 'wahou_open_cart', '1', $back );
		}
		wp_safe_redirect( $dest );
		exit;
	}

	/**
	 * CSRF check that still works on cached pages: a valid nonce, or a
	 * same-origin POST (Origin/Referer header of this site).
	 */
	public static function verify_post( string $action ): bool {
		$nonce = isset( $_POST['_wahou_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wahou_nonce'] ) ) : '';
		if ( '' !== $nonce && wp_verify_nonce( $nonce, $action ) ) {
			return true;
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$origin    = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $origin && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		return '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) === $home_host;
	}
}
