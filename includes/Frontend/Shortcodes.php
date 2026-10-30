<?php
/**
 * Shortcodes (work in any theme or page builder).
 *
 *   [wahou_products ids="1,2" category="books" tag="" limit="12" columns="3" orderby="popularity" pagination="yes" filters="yes"]
 *   [wahou_product id="12"]                 Product spotlight card
 *   [wahou_add_to_cart id="12" label="" show_price="yes"]
 *   [wahou_buy_button id="12" label="Buy now"] Direct checkout link
 *   [wahou_cart_icon show_label="yes"]
 *   [wahou_cart] [wahou_checkout] [wahou_account]
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Customers\Account;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		$map = array(
			'wahou_products'    => 'products',
			'wahou_product'     => 'product',
			'wahou_add_to_cart' => 'add_to_cart',
			'wahou_buy_button'  => 'buy_button',
			'wahou_cart_icon'   => 'cart_icon',
			'wahou_cart'        => 'cart',
			'wahou_checkout'    => 'checkout',
			'wahou_account'     => 'account',
		);
		foreach ( $map as $tag => $method ) {
			add_shortcode( $tag, array( self::class, $method ) );
		}
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function products( $atts ): string {
		Frontend::enqueue();
		$atts = shortcode_atts(
			array(
				'ids'        => '',
				'category'   => '',
				'tag'        => '',
				'limit'      => '',
				'columns'    => '',
				'orderby'    => '',
				'pagination' => 'no',
				'filters'    => 'no',
				'title'      => '',
			),
			(array) $atts,
			'wahou_products'
		);
		$args = array(
			'ids'        => $atts['ids'],
			'category'   => $atts['category'],
			'tag'        => $atts['tag'],
			'orderby'    => sanitize_key( $atts['orderby'] ),
			'pagination' => self::yes( $atts['pagination'] ),
			'filters'    => self::yes( $atts['filters'] ),
			'title'      => $atts['title'],
		);
		if ( '' !== $atts['limit'] ) {
			$args['limit'] = (int) $atts['limit'];
		}
		if ( '' !== $atts['columns'] ) {
			$args['columns'] = (int) $atts['columns'];
		}
		return Render::products_section( $args );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function product( $atts ): string {
		Frontend::enqueue();
		$atts    = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'wahou_product' );
		$product = Product::find( (int) $atts['id'] );
		if ( ! $product || 'publish' !== $product->status() ) {
			return self::missing();
		}
		return '<div class="wahou wahou-spotlight">' . Template::get(
			'parts/product-card',
			array(
				'product'  => $product,
				'heading'  => 'h3',
				'featured' => true,
			)
		) . '</div>';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function add_to_cart( $atts ): string {
		Frontend::enqueue();
		$atts    = shortcode_atts(
			array(
				'id'         => 0,
				'label'      => '',
				'show_price' => 'no',
			),
			(array) $atts,
			'wahou_add_to_cart'
		);
		$product = Product::find( (int) $atts['id'] );
		if ( ! $product || 'publish' !== $product->status() ) {
			return self::missing();
		}
		return '<div class="wahou wahou-inline-buy">' . Render::add_to_cart_button( $product, sanitize_text_field( $atts['label'] ), self::yes( $atts['show_price'] ) ) . '</div>';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function buy_button( $atts ): string {
		Frontend::enqueue();
		$atts    = shortcode_atts(
			array(
				'id'    => 0,
				'label' => '',
			),
			(array) $atts,
			'wahou_buy_button'
		);
		$product = Product::find( (int) $atts['id'] );
		if ( ! $product || 'publish' !== $product->status() ) {
			return self::missing();
		}
		return '<div class="wahou wahou-inline-buy">' . Render::buy_button( $product, sanitize_text_field( $atts['label'] ) ) . '</div>';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function cart_icon( $atts ): string {
		Frontend::enqueue();
		$atts = shortcode_atts(
			array(
				'show_label' => 'no',
				'label'      => '',
			),
			(array) $atts,
			'wahou_cart_icon'
		);
		return Render::cart_icon(
			array(
				'show_label' => self::yes( $atts['show_label'] ),
				'label'      => sanitize_text_field( $atts['label'] ),
			)
		);
	}

	public static function cart(): string {
		Frontend::enqueue();
		return self::delegate( CheckoutPage::class, 'render_cart' );
	}

	public static function checkout(): string {
		Frontend::enqueue();
		return self::delegate( CheckoutPage::class, 'render_checkout' );
	}

	public static function account(): string {
		Frontend::enqueue();
		return self::delegate( Account::class, 'render' );
	}

	/**
	 * Call a renderer that may echo or return its markup.
	 */
	public static function delegate( string $class, string $method ): string {
		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			return '';
		}
		ob_start();
		$returned = call_user_func( array( $class, $method ) );
		$echoed   = (string) ob_get_clean();
		return $echoed . ( is_string( $returned ) ? $returned : '' );
	}

	/**
	 * @param mixed $value
	 */
	public static function yes( $value ): bool {
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on', 'oui', 'si', 'sí' ), true );
	}

	private static function missing(): string {
		if ( current_user_can( 'edit_wahou_products' ) ) {
			return '<p class="wahou-notice wahou-notice--info">' . esc_html__( 'Wahou Commerce: product not found or not published.', 'wahou-commerce' ) . '</p>';
		}
		return '';
	}
}
