<?php
/**
 * Dynamic blocks (server-rendered, no build step).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Customers\Account;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 25 );
		add_filter( 'block_categories_all', array( self::class, 'category' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'editor_assets' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 * @return array<int, array<string, mixed>>
	 */
	public static function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'wahou',
				'title' => __( 'Wahou Commerce', 'wahou-commerce' ),
				'icon'  => null,
			)
		);
		return $categories;
	}

	/**
	 * Store styles inside the editor iframe so previews look right.
	 */
	public static function editor_assets(): void {
		if ( is_admin() ) {
			wp_enqueue_style( 'wahou' );
		}
	}

	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_add_inline_script( 'wahou-blocks', 'window.wahouBlocks=' . wp_json_encode( self::editor_strings() ) . ';', 'before' );

		$common = array(
			'api_version'           => 3,
			'category'              => 'wahou',
			'editor_script_handles' => array( 'wahou-blocks' ),
		);
		$id_attr = array(
			'type'    => 'number',
			'default' => 0,
		);

		$blocks = array(
			'wahou/products'    => array(
				'title'           => __( 'Products', 'wahou-commerce' ),
				'description'     => __( 'Grid of products with optional sorting, category filters and pagination.', 'wahou-commerce' ),
				'icon'            => 'grid-view',
				'keywords'        => array( 'shop', 'catalog', 'grid' ),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
				'attributes'      => array(
					'ids'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'category'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'tag'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'      => array(
						'type'    => 'number',
						'default' => 12,
					),
					'columns'    => array(
						'type'    => 'number',
						'default' => 3,
					),
					'orderby'    => array(
						'type'    => 'string',
						'default' => 'menu_order',
					),
					'pagination' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'filters'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => array( self::class, 'render_products' ),
			),
			'wahou/product'     => array(
				'title'           => __( 'Product spotlight', 'wahou-commerce' ),
				'description'     => __( 'Highlight one product with its image, price and buy button.', 'wahou-commerce' ),
				'icon'            => 'products',
				'supports'        => array( 'html' => false ),
				'attributes'      => array( 'id' => $id_attr ),
				'render_callback' => array( self::class, 'render_product' ),
			),
			'wahou/add-to-cart' => array(
				'title'           => __( 'Add to cart button', 'wahou-commerce' ),
				'description'     => __( 'Add a product to the cart from any page.', 'wahou-commerce' ),
				'icon'            => 'cart',
				'supports'        => array( 'html' => false ),
				'attributes'      => array(
					'id'        => $id_attr,
					'label'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'showPrice' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( self::class, 'render_add_to_cart' ),
			),
			'wahou/buy-button'  => array(
				'title'           => __( 'Buy now button', 'wahou-commerce' ),
				'description'     => __( 'Direct checkout link: one click from your content to payment.', 'wahou-commerce' ),
				'icon'            => 'money-alt',
				'supports'        => array( 'html' => false ),
				'attributes'      => array(
					'id'    => $id_attr,
					'label' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( self::class, 'render_buy_button' ),
			),
			'wahou/cart-icon'   => array(
				'title'           => __( 'Cart icon', 'wahou-commerce' ),
				'description'     => __( 'Cart button with item count, for your header.', 'wahou-commerce' ),
				'icon'            => 'cart',
				'supports'        => array( 'html' => false ),
				'attributes'      => array(
					'showLabel' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => array( self::class, 'render_cart_icon' ),
			),
			'wahou/cart'        => array(
				'title'           => __( 'Cart', 'wahou-commerce' ),
				'description'     => __( 'Full cart page.', 'wahou-commerce' ),
				'icon'            => 'cart',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => array( self::class, 'render_cart' ),
			),
			'wahou/checkout'    => array(
				'title'           => __( 'Checkout', 'wahou-commerce' ),
				'description'     => __( 'One-page checkout, order confirmation and post-purchase offers.', 'wahou-commerce' ),
				'icon'            => 'yes-alt',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => array( self::class, 'render_checkout' ),
			),
			'wahou/account'     => array(
				'title'           => __( 'Customer account', 'wahou-commerce' ),
				'description'     => __( 'Passwordless login, orders, downloads and addresses.', 'wahou-commerce' ),
				'icon'            => 'admin-users',
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => array( self::class, 'render_account' ),
			),
		);

		foreach ( $blocks as $name => $args ) {
			register_block_type( $name, array_merge( $common, $args ) );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function editor_strings(): array {
		return array(
			'orderby' => Render::orderby_options(),
			'i18n'    => array(
				'settings'      => __( 'Settings', 'wahou-commerce' ),
				'product'       => __( 'Product', 'wahou-commerce' ),
				'chooseProduct' => __( 'Choose a product', 'wahou-commerce' ),
				'ids'           => __( 'Product IDs (optional)', 'wahou-commerce' ),
				'idsHelp'       => __( 'Comma-separated IDs to show specific products in this order.', 'wahou-commerce' ),
				'category'      => __( 'Category', 'wahou-commerce' ),
				'allCategories' => __( 'All categories', 'wahou-commerce' ),
				'limit'         => __( 'Number of products', 'wahou-commerce' ),
				'columns'       => __( 'Columns (desktop)', 'wahou-commerce' ),
				'orderby'       => __( 'Order by', 'wahou-commerce' ),
				'pagination'    => __( 'Show pagination', 'wahou-commerce' ),
				'filters'       => __( 'Show category filters and sorting', 'wahou-commerce' ),
				'label'         => __( 'Button label (optional)', 'wahou-commerce' ),
				'showPrice'     => __( 'Show price in the button', 'wahou-commerce' ),
				'showLabel'     => __( 'Show "Cart" label', 'wahou-commerce' ),
				'noProduct'     => __( 'Select a product in the block settings.', 'wahou-commerce' ),
				'cart'          => __( 'The cart is displayed here.', 'wahou-commerce' ),
				'checkout'      => __( 'The checkout, order confirmation and post-purchase offers are displayed here.', 'wahou-commerce' ),
				'account'       => __( 'The customer account (passwordless login, orders, downloads) is displayed here.', 'wahou-commerce' ),
				'buyHelp'       => __( 'Sends the customer straight to checkout with this product.', 'wahou-commerce' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private static function wrap( string $html, string $class, array $attributes = array() ): string {
		if ( '' === $html ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) && \WP_Block_Supports::$block_to_render
			? get_block_wrapper_attributes( array( 'class' => 'wahou ' . $class ) )
			: 'class="wahou ' . esc_attr( $class ) . '"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_products( array $attributes ): string {
		Frontend::enqueue();
		return self::wrap(
			Render::products_section(
				array(
					'ids'        => (string) ( $attributes['ids'] ?? '' ),
					'category'   => (string) ( $attributes['category'] ?? '' ),
					'tag'        => (string) ( $attributes['tag'] ?? '' ),
					'limit'      => (int) ( $attributes['limit'] ?? 12 ),
					'columns'    => (int) ( $attributes['columns'] ?? 3 ),
					'orderby'    => sanitize_key( (string) ( $attributes['orderby'] ?? '' ) ),
					'pagination' => ! empty( $attributes['pagination'] ),
					'filters'    => ! empty( $attributes['filters'] ),
				)
			),
			'wahou-block-products',
			$attributes
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_product( array $attributes ): string {
		Frontend::enqueue();
		$product = Product::find( (int) ( $attributes['id'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->status() ) {
			return '';
		}
		return self::wrap(
			Template::get(
				'parts/product-card',
				array(
					'product'  => $product,
					'heading'  => 'h3',
					'featured' => true,
				)
			),
			'wahou-spotlight'
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_add_to_cart( array $attributes ): string {
		Frontend::enqueue();
		$product = Product::find( (int) ( $attributes['id'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->status() ) {
			return '';
		}
		return self::wrap( Render::add_to_cart_button( $product, sanitize_text_field( (string) ( $attributes['label'] ?? '' ) ), ! empty( $attributes['showPrice'] ) ), 'wahou-inline-buy' );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_buy_button( array $attributes ): string {
		Frontend::enqueue();
		$product = Product::find( (int) ( $attributes['id'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->status() ) {
			return '';
		}
		return self::wrap( Render::buy_button( $product, sanitize_text_field( (string) ( $attributes['label'] ?? '' ) ) ), 'wahou-inline-buy' );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render_cart_icon( array $attributes ): string {
		Frontend::enqueue();
		return self::wrap( Render::cart_icon( array( 'show_label' => ! empty( $attributes['showLabel'] ) ) ), 'wahou-block-cart-icon' );
	}

	public static function render_cart(): string {
		Frontend::enqueue();
		return Shortcodes::delegate( CheckoutPage::class, 'render_cart' );
	}

	public static function render_checkout(): string {
		Frontend::enqueue();
		return Shortcodes::delegate( CheckoutPage::class, 'render_checkout' );
	}

	public static function render_account(): string {
		Frontend::enqueue();
		return Shortcodes::delegate( Account::class, 'render' );
	}
}
