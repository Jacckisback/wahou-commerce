<?php
/**
 * Product editor: "Product data" meta box (works in the block editor and the
 * classic editor), gallery meta box, private file uploads, list table columns
 * and the "Duplicate" row action.
 *
 * All prices are typed in the merchant's format ("19,90") and stored as
 * integers in minor units. Complex structures (attributes and variants) travel
 * as JSON in a hidden field kept up to date by assets/js/product-editor.js,
 * because the block editor serializes meta boxes without a submit event.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Install;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class ProductEditor {

	private const NONCE = 'wahou_save_product';

	public static function init(): void {
		Fields::init();
		add_action( 'add_meta_boxes_' . PostTypes::PRODUCT, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . PostTypes::PRODUCT, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_ajax_wahou_upload_file', array( self::class, 'ajax_upload' ) );
		add_filter( 'manage_' . PostTypes::PRODUCT . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . PostTypes::PRODUCT . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostTypes::PRODUCT . '_sortable_columns', array( self::class, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( self::class, 'sort_query' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_wahou_duplicate_product', array( self::class, 'duplicate' ) );
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && PostTypes::PRODUCT === $screen->post_type && 'post' === $screen->base ) {
			Fields::enqueue_editor();
		}
	}

	/* ------------------------------------------------------------------
	 * Meta boxes.
	 * ------------------------------------------------------------------ */

	public static function meta_boxes(): void {
		add_meta_box( 'wahou-product-data', __( 'Product data', 'wahou-commerce' ), array( self::class, 'render' ), PostTypes::PRODUCT, 'normal', 'high' );
		add_meta_box( 'wahou-product-gallery', __( 'Product gallery', 'wahou-commerce' ), array( self::class, 'render_gallery' ), PostTypes::PRODUCT, 'side', 'low' );
	}

	private static function seo_plugin_active(): bool {
		$compat = '\WahouCommerce\Compat\Compat';
		return class_exists( $compat ) && method_exists( $compat, 'seo_plugin_active' ) && (bool) $compat::seo_plugin_active();
	}

	public static function render( \WP_Post $post ): void {
		$product = Product::find( $post );
		$kind    = $product ? $product->kind() : 'physical';
		$type    = $product ? $product->type() : 'simple';

		wp_nonce_field( self::NONCE, 'wahou_product_nonce' );

		$tabs = array(
			'general'   => array( __( 'General', 'wahou-commerce' ), 'dashicons-admin-generic', '' ),
			'inventory' => array( __( 'Inventory', 'wahou-commerce' ), 'dashicons-archive', '' ),
			'shipping'  => array( __( 'Shipping', 'wahou-commerce' ), 'dashicons-car', 'data-show-kind="physical"' ),
			'variants'  => array( __( 'Variants', 'wahou-commerce' ), 'dashicons-screenoptions', 'data-show-type="variable"' ),
			'bundle'    => array( __( 'Bundle', 'wahou-commerce' ), 'dashicons-networking', 'data-show-type="bundle"' ),
			'downloads' => array( __( 'Downloads', 'wahou-commerce' ), 'dashicons-download', '' ),
			'marketing' => array( __( 'Upsells & cross-sells', 'wahou-commerce' ), 'dashicons-chart-line', '' ),
			'faq'       => array( __( 'FAQ', 'wahou-commerce' ), 'dashicons-editor-help', '' ),
		);
		if ( ! self::seo_plugin_active() ) {
			$tabs['seo'] = array( __( 'SEO', 'wahou-commerce' ), 'dashicons-search', '' );
		}

		echo '<div class="wahou-pd" data-wahou-pd data-kind="' . esc_attr( $kind ) . '" data-type="' . esc_attr( $type ) . '">';

		// Head: product kind + type.
		echo '<div class="wahou-pd__head">';
		echo Fields::choices( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'wahou_p[kind]',
			$kind,
			array(
				'physical' => array(
					'label' => __( 'Physical', 'wahou-commerce' ),
					'desc'  => __( 'Shipped to the customer', 'wahou-commerce' ),
					'icon'  => 'dashicons-products',
				),
				'digital'  => array(
					'label' => __( 'Digital', 'wahou-commerce' ),
					'desc'  => __( 'Instant download (e-book, course, file…)', 'wahou-commerce' ),
					'icon'  => 'dashicons-download',
				),
				'service'  => array(
					'label' => __( 'Service', 'wahou-commerce' ),
					'desc'  => __( 'Nothing to ship (coaching, booking…)', 'wahou-commerce' ),
					'icon'  => 'dashicons-groups',
				),
			),
			__( 'Product kind', 'wahou-commerce' )
		);
		echo '<div class="wahou-pd__type">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Product type', 'wahou-commerce' ),
			Fields::select(
				'wahou_p[type]',
				$type,
				array(
					'simple'   => __( 'Simple product', 'wahou-commerce' ),
					'variable' => __( 'With options (size, color…)', 'wahou-commerce' ),
					'bundle'   => __( 'Bundle of products', 'wahou-commerce' ),
				),
				array( 'id' => 'wahou-p-type' )
			),
			'',
			array( 'for' => 'wahou-p-type' )
		);
		echo '</div></div>';

		echo '<div class="wahou-pd__body">';
		echo '<div class="wahou-vtabs" role="tablist" aria-label="' . esc_attr__( 'Product data', 'wahou-commerce' ) . '">';
		$first = true;
		foreach ( $tabs as $key => $tab ) {
			printf(
				'<button type="button" role="tab" class="wahou-vtabs__tab%1$s" id="wahou-tab-%2$s" aria-controls="wahou-panel-%2$s" aria-selected="%3$s" data-tab="%2$s" %4$s><span class="dashicons %5$s" aria-hidden="true"></span><span class="wahou-vtabs__text">%6$s</span></button>',
				$first ? ' is-active' : '',
				esc_attr( $key ),
				$first ? 'true' : 'false',
				$tab[2], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
				esc_attr( $tab[1] ),
				esc_html( $tab[0] )
			);
			$first = false;
		}
		echo '</div><div class="wahou-vtabs__panels">';

		self::panel_open( 'general', $tabs, true );
		self::panel_general( $product );
		self::panel_close();

		self::panel_open( 'inventory', $tabs );
		self::panel_inventory( $product, $kind );
		self::panel_close();

		self::panel_open( 'shipping', $tabs );
		self::panel_shipping( $product );
		self::panel_close();

		self::panel_open( 'variants', $tabs );
		self::panel_variants( $product );
		self::panel_close();

		self::panel_open( 'bundle', $tabs );
		self::panel_bundle( $product, $post );
		self::panel_close();

		self::panel_open( 'downloads', $tabs );
		self::panel_downloads( $product, $post );
		self::panel_close();

		self::panel_open( 'marketing', $tabs );
		self::panel_marketing( $product, $post );
		self::panel_close();

		self::panel_open( 'faq', $tabs );
		self::panel_faq( $product );
		self::panel_close();

		if ( isset( $tabs['seo'] ) ) {
			self::panel_open( 'seo', $tabs );
			self::panel_seo( $product, $post );
			self::panel_close();
		}

		echo '</div></div></div>';
	}

	/**
	 * @param array<string, array{0:string,1:string,2:string}> $tabs
	 */
	private static function panel_open( string $key, array $tabs, bool $active = false ): void {
		printf(
			'<section class="wahou-vtabs__panel%1$s" id="wahou-panel-%2$s" role="tabpanel" aria-labelledby="wahou-tab-%2$s" data-panel="%2$s" %3$s><h3 class="wahou-vtabs__heading">%4$s</h3>',
			$active ? ' is-active' : '',
			esc_attr( $key ),
			$tabs[ $key ][2], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
			esc_html( $tabs[ $key ][0] )
		);
	}

	private static function panel_close(): void {
		echo '</section>';
	}

	/**
	 * @return mixed
	 */
	private static function meta( ?Product $product, string $key, $fallback = '' ) {
		return $product ? $product->meta( $key, $fallback ) : $fallback;
	}

	private static function panel_general( ?Product $product ): void {
		$incl  = Settings::bool( 'prices_include_tax' ) || ! Settings::bool( 'tax_enabled' );
		$raw   = self::meta( $product, 'price', '' );
		$price = '' === $raw || null === $raw ? null : (int) $raw;
		$sale  = self::meta( $product, 'sale_price', '' );
		$from  = (int) self::meta( $product, 'sale_from', 0 );
		$to    = (int) self::meta( $product, 'sale_to', 0 );

		echo '<p class="wahou-callout wahou-callout--info" data-show-type="bundle">' . esc_html__( 'Bundle: the price below is the bundle price. You can also compute it from the products it contains in the Bundle tab.', 'wahou-commerce' ) . '</p>';

		echo '<div class="wahou-grid wahou-grid--2">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$incl ? __( 'Price (incl. VAT)', 'wahou-commerce' ) : __( 'Price (excl. VAT)', 'wahou-commerce' ),
			Fields::money( 'wahou_p[price]', $price, array( 'id' => 'wahou-p-price' ) ),
			'',
			array( 'for' => 'wahou-p-price' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Sale price', 'wahou-commerce' ),
			Fields::money(
				'wahou_p[sale_price]',
				'' === $sale || null === $sale ? null : (int) $sale,
				array(
					'id'          => 'wahou-p-sale-price',
					'placeholder' => __( 'Optional', 'wahou-commerce' ),
				)
			),
			'',
			array( 'for' => 'wahou-p-sale-price' )
		);
		echo '</div>';

		echo '<details class="wahou-details wahou-details--inline"' . ( $from || $to ? ' open' : '' ) . '><summary>' . esc_html__( 'Schedule the sale', 'wahou-commerce' ) . '</summary>';
		echo '<div class="wahou-grid wahou-grid--2">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Starts on', 'wahou-commerce' ),
			Fields::input(
				'wahou_p[sale_from]',
				$from ? wp_date( 'Y-m-d', $from ) : '',
				array(
					'id'   => 'wahou-p-sale-from',
					'type' => 'date',
				)
			),
			'',
			array( 'for' => 'wahou-p-sale-from' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Ends on (included)', 'wahou-commerce' ),
			Fields::input(
				'wahou_p[sale_to]',
				$to ? wp_date( 'Y-m-d', $to ) : '',
				array(
					'id'   => 'wahou-p-sale-to',
					'type' => 'date',
				)
			),
			'',
			array( 'for' => 'wahou-p-sale-to' )
		);
		echo '</div></details>';

		if ( $product && $product->is_on_sale() ) {
			$reference = $product->reference_price();
			if ( null !== $reference ) {
				echo '<p class="wahou-callout wahou-callout--info"><span class="dashicons dashicons-info" aria-hidden="true"></span> ';
				/* translators: %s: price. */
				echo esc_html( sprintf( __( 'Reference price shown to customers (lowest price of the 30 days before this discount): %s.', 'wahou-commerce' ), Money::format( $reference ) ) );
				echo '</p>';
			}
		}

		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'VAT class', 'wahou-commerce' ),
			Fields::select( 'wahou_p[tax_class]', $product ? $product->tax_class() : 'standard', Tax::class_labels(), array( 'id' => 'wahou-p-tax-class' ) ),
			__( 'Books and e-books benefit from reduced VAT rates in most EU countries (5,5 % in France, 4 % in Spain, 7 % in Germany…).', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-tax-class' )
		);

		$gtin       = (string) self::meta( $product, 'gtin', '' );
		$gtin_help  = __( 'EAN, UPC or ISBN-13. Strongly recommended for Google Shopping.', 'wahou-commerce' );
		if ( '' !== $gtin && ! in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
			$gtin_help = '<strong>' . esc_html__( 'This GTIN does not have a standard length (8, 12, 13 or 14 digits).', 'wahou-commerce' ) . '</strong>';
		}
		echo '<div class="wahou-grid wahou-grid--2">';
		echo self::text_row( 'sku', __( 'SKU (reference)', 'wahou-commerce' ), (string) self::meta( $product, 'sku', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::text_row( 'gtin', __( 'GTIN / EAN / ISBN', 'wahou-commerce' ), $gtin, $gtin_help, array( 'inputmode' => 'numeric' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::text_row( 'mpn', __( 'Manufacturer part number (MPN)', 'wahou-commerce' ), (string) self::meta( $product, 'mpn', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::text_row( 'brand', __( 'Brand', 'wahou-commerce' ), (string) self::meta( $product, 'brand', '' ), '', array(), Settings::str( 'seo_brand' ) ?: Settings::store_name() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo self::text_row( 'badge', __( 'Badge', 'wahou-commerce' ), (string) self::meta( $product, 'badge', '' ), __( 'Short label shown on the product image, e.g. "Best-seller" or "New".', 'wahou-commerce' ), array( 'maxlength' => 30 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string, mixed> $attrs
	 */
	private static function text_row( string $key, string $label, string $value, string $help = '', array $attrs = array(), string $placeholder = '' ): string {
		$id = 'wahou-p-' . str_replace( '_', '-', $key );
		return Fields::row(
			$label,
			Fields::input(
				'wahou_p[' . $key . ']',
				$value,
				array(
					'id'          => $id,
					'placeholder' => $placeholder,
					'attrs'       => $attrs,
				)
			),
			$help,
			array( 'for' => $id )
		);
	}

	private static function panel_inventory( ?Product $product, string $kind ): void {
		$manage     = (bool) self::meta( $product, 'manage_stock', false );
		$stock      = (int) self::meta( $product, 'stock', 0 );
		$low        = self::meta( $product, 'low_stock', '' );
		$backorders = (bool) self::meta( $product, 'backorders', false );
		$single     = $product ? $product->sold_individually() : 'digital' === $kind;

		echo Fields::toggle( 'wahou_p[manage_stock]', $manage, __( 'Track stock quantity', 'wahou-commerce' ), array( 'id' => 'wahou-p-manage-stock' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="wahou-indent" data-show-if="wahou_p[manage_stock]">';
		echo '<div class="wahou-grid wahou-grid--2">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Quantity in stock', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[stock]',
				$stock,
				array(
					'id'   => 'wahou-p-stock',
					'step' => 1,
				)
			) . '<input type="hidden" name="wahou_p[stock_original]" value="' . esc_attr( (string) $stock ) . '">',
			__( 'Updated automatically with every order and refund.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-stock' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Low stock threshold', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[low_stock]',
				'' === $low || null === $low ? '' : (int) $low,
				array(
					'id'          => 'wahou-p-low-stock',
					'min'         => 0,
					/* translators: %d: store-wide threshold. */
					'placeholder' => sprintf( __( 'Store default (%d)', 'wahou-commerce' ), Settings::int( 'low_stock_threshold' ) ),
				)
			),
			'',
			array( 'for' => 'wahou-p-low-stock' )
		);
		echo '</div>';
		echo Fields::toggle( 'wahou_p[backorders]', $backorders, __( 'Allow orders when out of stock (backorders)', 'wahou-commerce' ), array( 'id' => 'wahou-p-backorders' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo Fields::toggle( 'wahou_p[sold_individually]', $single, __( 'Limit to one per order', 'wahou-commerce' ), array( 'id' => 'wahou-p-sold-individually' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="wahou-field__help">' . esc_html__( 'Recommended for digital products, licences and courses.', 'wahou-commerce' ) . '</p>';
		echo '<p class="wahou-callout wahou-callout--info" data-show-type="variable">' . esc_html__( 'Products with options can also track stock per variant (Variants tab).', 'wahou-commerce' ) . '</p>';

		if ( $product ) {
			$labels = array(
				'in_stock'     => array( __( 'In stock', 'wahou-commerce' ), 'success' ),
				'low_stock'    => array( __( 'Low stock', 'wahou-commerce' ), 'warning' ),
				'out_of_stock' => array( __( 'Out of stock', 'wahou-commerce' ), 'danger' ),
				'backorder'    => array( __( 'On backorder', 'wahou-commerce' ), 'info' ),
			);
			$status = $labels[ $product->stock_status() ] ?? $labels['in_stock'];
			echo '<p class="wahou-pd__status">' . esc_html__( 'Current status:', 'wahou-commerce' ) . ' ' . Fields::pill( $status[0], $status[1] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	private static function panel_shipping( ?Product $product ): void {
		$weight_unit = Settings::str( 'weight_unit' ) ?: 'kg';
		$dim_unit    = Settings::str( 'dimension_unit' ) ?: 'cm';
		$value       = static function ( $v ): string {
			return ( '' === $v || null === $v || 0.0 === (float) $v ) ? '' : rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' );
		};
		echo '<div class="wahou-grid wahou-grid--4">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Weight', 'wahou-commerce' ),
			Fields::input(
				'wahou_p[weight]',
				$value( self::meta( $product, 'weight', '' ) ),
				array(
					'id'     => 'wahou-p-weight',
					'suffix' => $weight_unit,
					'attrs'  => array( 'inputmode' => 'decimal' ),
				)
			),
			'',
			array( 'for' => 'wahou-p-weight' )
		);
		foreach ( array(
			'length' => __( 'Length', 'wahou-commerce' ),
			'width'  => __( 'Width', 'wahou-commerce' ),
			'height' => __( 'Height', 'wahou-commerce' ),
		) as $key => $label ) {
			echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$label,
				Fields::input(
					'wahou_p[' . $key . ']',
					$value( self::meta( $product, $key, '' ) ),
					array(
						'id'     => 'wahou-p-' . $key,
						'suffix' => $dim_unit,
						'attrs'  => array( 'inputmode' => 'decimal' ),
					)
				),
				'',
				array( 'for' => 'wahou-p-' . $key )
			);
		}
		echo '</div>';
		echo '<p class="wahou-field__help">' . esc_html__( 'Used in your Google product feed and by carriers. Shipping costs are set per zone in Settings › Shipping.', 'wahou-commerce' ) . '</p>';
	}

	private static function panel_variants( ?Product $product ): void {
		$attributes = $product ? $product->attributes() : array();
		$variants   = array();
		foreach ( $product ? $product->variants() : array() as $variant ) {
			$image_id   = (int) ( $variant['image_id'] ?? 0 );
			$variants[] = array(
				'id'         => (string) $variant['id'],
				'attributes' => is_array( $variant['attributes'] ?? null ) ? $variant['attributes'] : array(),
				'price'      => self::money_input( $variant['price'] ?? null ),
				'sale_price' => self::money_input( $variant['sale_price'] ?? null ),
				'sku'        => (string) ( $variant['sku'] ?? '' ),
				'stock'      => isset( $variant['stock'] ) && '' !== $variant['stock'] && null !== $variant['stock'] ? (string) (int) $variant['stock'] : '',
				'image_id'   => $image_id,
				'image'      => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '',
			);
		}
		$data = array(
			'attributes' => $attributes,
			'variants'   => $variants,
		);

		echo '<div class="wahou-variants" data-wahou-variants>';
		echo '<input type="hidden" name="wahou_p[variations]" value="' . esc_attr( (string) wp_json_encode( $data ) ) . '" data-variations-input>';
		echo '<p class="wahou-field__help">' . esc_html__( '1. Add your options (e.g. Size: S, M, L). 2. Click "Generate variants". 3. Set a specific price, stock or image only where needed – empty fields use the product values.', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-variants__attributes" data-attributes></div>';
		echo '<div class="wahou-actions">';
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-add-attribute>' . esc_html__( '+ Add an option', 'wahou-commerce' ) . '</button>';
		echo '<button type="button" class="wahou-btn wahou-btn--primary" data-generate-variants>' . esc_html__( 'Generate variants', 'wahou-commerce' ) . '</button>';
		echo '</div>';
		echo '<div class="wahou-variants__bulk" data-variants-bulk hidden>';
		echo '<label for="wahou-variants-bulk-price">' . esc_html__( 'Same price for all variants', 'wahou-commerce' ) . '</label>';
		echo Fields::money( '', null, array( 'id' => 'wahou-variants-bulk-price', 'attrs' => array( 'name' => null, 'data-bulk-price' => '1' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="wahou-btn wahou-btn--ghost" data-bulk-apply>' . esc_html__( 'Apply', 'wahou-commerce' ) . '</button>';
		echo '</div>';
		echo '<div class="wahou-variants__list" data-variants></div>';
		echo '<noscript><p class="wahou-callout wahou-callout--warning">' . esc_html__( 'JavaScript is required to edit variants.', 'wahou-commerce' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * @param mixed $minor
	 */
	private static function money_input( $minor ): string {
		return ( '' === $minor || null === $minor ) ? '' : Money::to_input( (int) $minor );
	}

	private static function panel_bundle( ?Product $product, \WP_Post $post ): void {
		$items    = $product ? $product->bundle_items() : array();
		$pricing  = (string) self::meta( $product, 'bundle_pricing', 'fixed' );
		$discount = self::meta( $product, 'bundle_discount', '' );

		echo '<div class="wahou-repeater wahou-bundle" data-wahou-repeater>';
		echo '<p class="wahou-field__help">' . esc_html__( 'Sell several products together. Stock is taken from each product of the bundle.', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-repeater__rows" data-rows>';
		foreach ( $items as $i => $item ) {
			echo self::bundle_row( (string) $i, (int) $item['product_id'], (int) $item['qty'], $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		echo '<template data-template>' . self::bundle_row( '__i__', 0, 1, $post->ID ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-add-row>' . esc_html__( '+ Add a product', 'wahou-commerce' ) . '</button>';
		echo '</div>';

		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Bundle price', 'wahou-commerce' ),
			Fields::segmented(
				'wahou_p[bundle_pricing]',
				'discount' === $pricing ? 'discount' : 'fixed',
				array(
					'fixed'    => __( 'Fixed price (General tab)', 'wahou-commerce' ),
					'discount' => __( 'Sum of the products minus a discount', 'wahou-commerce' ),
				),
				array( 'label' => __( 'Bundle price', 'wahou-commerce' ) )
			)
		);
		echo '<div data-show-if="wahou_p[bundle_pricing]=discount">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Bundle discount', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[bundle_discount]',
				'' === $discount || null === $discount ? '' : (float) $discount,
				array(
					'id'     => 'wahou-p-bundle-discount',
					'min'    => 0,
					'max'    => 90,
					'step'   => '0.1',
					'suffix' => '%',
				)
			),
			$product && $product->bundle_items()
				/* translators: %s: total price of the bundled products. */
				? sprintf( __( 'Products bought separately: %s.', 'wahou-commerce' ), Money::format( $product->bundle_components_total() ) )
				: '',
			array( 'for' => 'wahou-p-bundle-discount' )
		);
		echo '</div>';
	}

	private static function bundle_row( string $index, int $product_id, int $qty, int $exclude ): string {
		return '<div class="wahou-repeater__row wahou-bundle__row" data-row>'
			. '<div class="wahou-bundle__product">' . Fields::product_picker(
				'wahou_p[bundle][' . $index . '][product_id]',
				$product_id ? array( $product_id ) : array(),
				array(
					'multiple' => false,
					'exclude'  => array( $exclude ),
				)
			) . '</div>'
			. '<div class="wahou-bundle__qty">' . Fields::number(
				'wahou_p[bundle][' . $index . '][qty]',
				max( 1, $qty ),
				array(
					'id'    => 'wahou-p-bundle-qty-' . $index,
					'min'   => 1,
					'max'   => 99,
					'attrs' => array( 'aria-label' => __( 'Quantity', 'wahou-commerce' ) ),
				)
			) . '</div>'
			. '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--icon" data-remove-row aria-label="' . esc_attr__( 'Remove', 'wahou-commerce' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>'
			. '</div>';
	}

	private static function panel_downloads( ?Product $product, \WP_Post $post ): void {
		$files = $product ? $product->files() : array();
		$base  = Install::private_dir();

		echo '<p class="wahou-callout wahou-callout--info" data-show-kind="physical">' . esc_html__( 'Files added to a physical product are delivered as a bonus (e.g. the PDF version of a printed book).', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-files" data-wahou-files data-post="' . esc_attr( (string) $post->ID ) . '">';
		echo '<div class="wahou-repeater" data-wahou-repeater>';
		echo '<div class="wahou-files__list wahou-repeater__rows" data-rows>';
		foreach ( $files as $i => $file ) {
			$size = '';
			if ( ! empty( $file['path'] ) ) {
				$abs  = $base . '/' . ltrim( (string) $file['path'], '/' );
				$size = is_file( $abs ) ? size_format( (int) filesize( $abs ) ) : __( 'File missing!', 'wahou-commerce' );
			}
			echo self::file_row( (string) $i, $file, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		echo '<template data-template="file">' . self::file_row( '__i__', array( 'id' => '', 'name' => '', 'path' => '__path__' ), '__size__' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<template data-template="url">' . self::file_row( '__i__', array( 'id' => '', 'name' => '', 'url' => '' ), '' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div class="wahou-actions">';
		echo '<label class="wahou-btn wahou-btn--primary wahou-files__upload"><span class="dashicons dashicons-upload" aria-hidden="true"></span> ' . esc_html__( 'Upload a file', 'wahou-commerce' ) . '<input type="file" class="wahou-sr-only" data-file-input></label>';
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-add-url>' . esc_html__( 'Add a file URL', 'wahou-commerce' ) . '</button>';
		echo '</div>';
		echo '<div class="wahou-progress" data-upload-progress hidden><span class="wahou-progress__bar" style="width:0"></span><span class="wahou-progress__label"></span></div>';
		echo '<p class="wahou-field__help">';
		/* translators: %s: maximum upload size. */
		echo esc_html( sprintf( __( 'Files are stored in a private folder and delivered through personal, expiring links – never through a public URL. Maximum upload size on your server: %s. For heavier files (videos), add the URL of your file host instead.', 'wahou-commerce' ), size_format( wp_max_upload_size() ) ) );
		echo '</p>';
		echo '</div>';

		echo '<div class="wahou-grid wahou-grid--2">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Download limit', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[download_limit]',
				(int) self::meta( $product, 'download_limit', 0 ),
				array(
					'id'  => 'wahou-p-download-limit',
					'min' => 0,
				)
			),
			__( 'Per file and per order. 0 = unlimited.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-download-limit' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Links expire after (days)', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[download_expiry]',
				(int) self::meta( $product, 'download_expiry', 0 ),
				array(
					'id'  => 'wahou-p-download-expiry',
					'min' => 0,
				)
			),
			__( '0 = never. Customers can always get new links from their account.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-download-expiry' )
		);
		echo '</div>';
	}

	/**
	 * @param array<string, string> $file
	 */
	private static function file_row( string $index, array $file, string $size ): string {
		$base   = 'wahou_p[files][' . $index . ']';
		$is_url = array_key_exists( 'url', $file );
		$html   = '<div class="wahou-repeater__row wahou-file" data-row>';
		$html  .= '<span class="wahou-file__icon dashicons ' . ( $is_url ? 'dashicons-admin-links' : 'dashicons-lock' ) . '" aria-hidden="true"></span>';
		$html  .= '<input type="hidden" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( (string) ( $file['id'] ?? '' ) ) . '" data-file-id>';
		$html  .= '<div class="wahou-file__main">';
		$html  .= '<input type="text" class="wahou-input" name="' . esc_attr( $base . '[name]' ) . '" value="' . esc_attr( (string) ( $file['name'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Name shown to the customer', 'wahou-commerce' ) . '" aria-label="' . esc_attr__( 'File name', 'wahou-commerce' ) . '" data-file-name>';
		if ( $is_url ) {
			$html .= '<input type="url" class="wahou-input" name="' . esc_attr( $base . '[url]' ) . '" value="' . esc_attr( (string) $file['url'] ) . '" placeholder="https://" aria-label="' . esc_attr__( 'File URL', 'wahou-commerce' ) . '" data-file-url>';
		} else {
			$html .= '<input type="hidden" name="' . esc_attr( $base . '[path]' ) . '" value="' . esc_attr( (string) ( $file['path'] ?? '' ) ) . '" data-file-path>';
			$html .= '<span class="wahou-file__meta"><span class="wahou-pill wahou-pill--muted">' . esc_html__( 'Private file', 'wahou-commerce' ) . '</span> <code data-file-label>' . esc_html( basename( (string) ( $file['path'] ?? '' ) ) ) . '</code> <span class="wahou-muted" data-file-size>' . esc_html( $size ) . '</span></span>';
		}
		$html .= '</div>';
		$html .= '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--icon" data-remove-row aria-label="' . esc_attr__( 'Remove', 'wahou-commerce' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
		return $html . '</div>';
	}

	private static function panel_marketing( ?Product $product, \WP_Post $post ): void {
		$exclude = array( $post->ID );
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Upsells', 'wahou-commerce' ),
			Fields::product_picker( 'wahou_p[upsells]', $product ? $product->upsell_ids() : array(), array( 'exclude' => $exclude ) ),
			__( 'Premium alternatives shown on this product page ("You may also like").', 'wahou-commerce' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Cross-sells', 'wahou-commerce' ),
			Fields::product_picker( 'wahou_p[cross_sells]', $product ? $product->cross_sell_ids() : array(), array( 'exclude' => $exclude ) ),
			__( 'Complementary products suggested in the cart ("Complete your order").', 'wahou-commerce' )
		);
		echo '<div class="wahou-grid wahou-grid--fbt">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Frequently bought together', 'wahou-commerce' ),
			Fields::product_picker( 'wahou_p[fbt]', $product ? $product->fbt_ids() : array(), array( 'exclude' => $exclude ) ),
			__( 'Offered as a one-click pack on the product page.', 'wahou-commerce' )
		);
		$fbt_discount = self::meta( $product, 'fbt_discount', '' );
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Pack discount', 'wahou-commerce' ),
			Fields::number(
				'wahou_p[fbt_discount]',
				'' === $fbt_discount || null === $fbt_discount ? '' : (float) $fbt_discount,
				array(
					'id'     => 'wahou-p-fbt-discount',
					'min'    => 0,
					'max'    => 90,
					'step'   => '0.1',
					'suffix' => '%',
				)
			),
			__( 'On the added products.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-fbt-discount' )
		);
		echo '</div>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Key benefits', 'wahou-commerce' ),
			Fields::textarea(
				'wahou_p[highlights]',
				implode( "\n", $product ? $product->highlights() : array() ),
				array(
					'id'          => 'wahou-p-highlights',
					'rows'        => 4,
					'placeholder' => __( "One benefit per line, e.g.\n350 ready-to-use prompts\nUpdated for 2026\nLifetime updates", 'wahou-commerce' ),
				)
			),
			__( 'Shown as a check list next to the buy button and reused in your llms.txt and product feed: write concrete facts.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-highlights' )
		);
	}

	private static function panel_faq( ?Product $product ): void {
		$faq = $product ? $product->faq() : array();
		echo '<p class="wahou-field__help">' . esc_html__( 'Answer the questions buyers ask before purchasing. Shown on the product page and marked up for search and answer engines.', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-repeater wahou-faq" data-wahou-repeater data-max="30">';
		echo '<div class="wahou-repeater__rows" data-rows>';
		foreach ( $faq as $i => $row ) {
			echo self::faq_row( (string) $i, (string) $row['q'], (string) $row['a'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		echo '<template data-template>' . self::faq_row( '__i__', '', '' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-add-row>' . esc_html__( '+ Add a question', 'wahou-commerce' ) . '</button>';
		echo '</div>';
	}

	private static function faq_row( string $index, string $question, string $answer ): string {
		$base = 'wahou_p[faq][' . $index . ']';
		return '<div class="wahou-repeater__row wahou-faq__row" data-row>'
			. '<div class="wahou-faq__fields">'
			. Fields::input(
				$base . '[q]',
				$question,
				array(
					'id'          => 'wahou-p-faq-q-' . $index,
					'placeholder' => __( 'Question, e.g. "Is it suitable for beginners?"', 'wahou-commerce' ),
					'attrs'       => array( 'aria-label' => __( 'Question', 'wahou-commerce' ) ),
				)
			)
			. Fields::textarea(
				$base . '[a]',
				$answer,
				array(
					'id'          => 'wahou-p-faq-a-' . $index,
					'rows'        => 2,
					'placeholder' => __( 'Answer', 'wahou-commerce' ),
					'attrs'       => array( 'aria-label' => __( 'Answer', 'wahou-commerce' ) ),
				)
			)
			. '</div>'
			. '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--icon" data-remove-row aria-label="' . esc_attr__( 'Remove', 'wahou-commerce' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>'
			. '</div>';
	}

	private static function panel_seo( ?Product $product, \WP_Post $post ): void {
		$title = (string) self::meta( $product, 'seo_title', '' );
		$desc  = (string) self::meta( $product, 'seo_description', '' );
		$url   = $product ? $product->permalink() : home_url( '/' );

		echo '<div class="wahou-seo" data-wahou-seo>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'SEO title', 'wahou-commerce' ),
			Fields::input(
				'wahou_p[seo_title]',
				$title,
				array(
					'id'          => 'wahou-p-seo-title',
					'placeholder' => $post->post_title,
					'attrs'       => array(
						'data-count'  => '60',
						'maxlength'   => 120,
						'data-seo'    => 'title',
					),
				)
			) . '<span class="wahou-counter" data-counter-for="wahou-p-seo-title"></span>',
			'',
			array( 'for' => 'wahou-p-seo-title' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Meta description', 'wahou-commerce' ),
			Fields::textarea(
				'wahou_p[seo_description]',
				$desc,
				array(
					'id'          => 'wahou-p-seo-description',
					'rows'        => 3,
					'placeholder' => $product ? $product->summary( 25 ) : '',
					'attrs'       => array(
						'data-count' => '155',
						'maxlength'  => 320,
						'data-seo'   => 'description',
					),
				)
			) . '<span class="wahou-counter" data-counter-for="wahou-p-seo-description"></span>',
			__( 'Leave empty to use the short description automatically.', 'wahou-commerce' ),
			array( 'for' => 'wahou-p-seo-description' )
		);
		echo '<div class="wahou-snippet" aria-label="' . esc_attr__( 'Google preview', 'wahou-commerce' ) . '">';
		echo '<span class="wahou-snippet__url">' . esc_html( (string) preg_replace( '#^https?://#', '', $url ) ) . '</span>';
		echo '<span class="wahou-snippet__title" data-snippet-title data-fallback="' . esc_attr( $post->post_title ) . '">' . esc_html( '' !== $title ? $title : $post->post_title ) . '</span>';
		echo '<span class="wahou-snippet__desc" data-snippet-desc data-fallback="' . esc_attr( $product ? $product->summary( 25 ) : '' ) . '">' . esc_html( '' !== $desc ? $desc : ( $product ? $product->summary( 25 ) : '' ) ) . '</span>';
		echo '</div></div>';
	}

	public static function render_gallery( \WP_Post $post ): void {
		$product = Product::find( $post );
		$ids     = $product ? $product->gallery_ids() : array();
		echo '<div class="wahou-gallery" data-wahou-gallery>';
		echo '<input type="hidden" name="wahou_gallery" value="' . esc_attr( implode( ',', $ids ) ) . '" data-gallery-input>';
		echo '<ul class="wahou-gallery__list" data-gallery-list>';
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $src ) {
				continue;
			}
			printf(
				'<li class="wahou-gallery__item" data-id="%1$d" draggable="true"><img src="%2$s" alt=""><button type="button" class="wahou-gallery__remove" aria-label="%3$s">&times;</button></li>',
				(int) $id,
				esc_url( $src ),
				esc_attr__( 'Remove image', 'wahou-commerce' )
			);
		}
		echo '</ul>';
		echo '<button type="button" class="wahou-btn wahou-btn--secondary wahou-btn--block" data-gallery-add>' . esc_html__( 'Add images', 'wahou-commerce' ) . '</button>';
		echo '<p class="wahou-field__help">' . esc_html__( 'Drag to reorder. The main image is set in the "Main product image" box.', 'wahou-commerce' ) . '</p>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * Saving.
	 * ------------------------------------------------------------------ */

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['wahou_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wahou_product_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( PostTypes::PRODUCT !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$in = isset( $_POST['wahou_p'] ) && is_array( $_POST['wahou_p'] ) ? wp_unslash( $_POST['wahou_p'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.
		$str = static fn( string $key ): string => isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';

		// Kind & type.
		$kind = in_array( $str( 'kind' ), Product::KINDS, true ) ? $str( 'kind' ) : 'physical';
		$type = in_array( $str( 'type' ), Product::TYPES, true ) ? $str( 'type' ) : 'simple';
		self::set( $post_id, 'kind', $kind );
		self::set( $post_id, 'type', $type );

		// Prices.
		self::set( $post_id, 'price', max( 0, Money::to_minor( $str( 'price' ) ) ) );
		self::set( $post_id, 'sale_price', '' === $str( 'sale_price' ) ? null : max( 0, Money::to_minor( $str( 'sale_price' ) ) ) );
		$from = self::date_to_timestamp( $str( 'sale_from' ), false );
		$to   = self::date_to_timestamp( $str( 'sale_to' ), true );
		if ( $from && $to && $to < $from ) {
			$to = 0;
		}
		self::set( $post_id, 'sale_from', $from ?: null );
		self::set( $post_id, 'sale_to', $to ?: null );
		self::set( $post_id, 'tax_class', in_array( $str( 'tax_class' ), Tax::CLASSES, true ) ? $str( 'tax_class' ) : 'standard' );

		// Identifiers.
		self::set( $post_id, 'sku', mb_substr( sanitize_text_field( $str( 'sku' ) ), 0, 100 ) );
		self::set( $post_id, 'gtin', substr( (string) preg_replace( '/\D/', '', $str( 'gtin' ) ), 0, 14 ) );
		self::set( $post_id, 'mpn', mb_substr( sanitize_text_field( $str( 'mpn' ) ), 0, 70 ) );
		self::set( $post_id, 'brand', mb_substr( sanitize_text_field( $str( 'brand' ) ), 0, 70 ) );
		self::set( $post_id, 'badge', mb_substr( sanitize_text_field( $str( 'badge' ) ), 0, 30 ) );

		// Inventory. The stock is only written when the merchant changed it, so
		// that orders placed while the editor was open are not overwritten.
		self::set_bool( $post_id, 'manage_stock', ! empty( $in['manage_stock'] ) );
		if ( '' !== $str( 'stock' ) ) {
			$new      = (int) $str( 'stock' );
			$original = '' !== $str( 'stock_original' ) ? (int) $str( 'stock_original' ) : null;
			$stored   = get_post_meta( $post_id, '_wahou_stock', true );
			if ( '' === $stored || null === $original || $new !== $original ) {
				update_post_meta( $post_id, '_wahou_stock', $new );
			}
		}
		self::set( $post_id, 'low_stock', '' === $str( 'low_stock' ) ? null : max( 0, (int) $str( 'low_stock' ) ) );
		self::set_bool( $post_id, 'backorders', ! empty( $in['backorders'] ) );
		self::set_bool( $post_id, 'sold_individually', ! empty( $in['sold_individually'] ) );

		// Shipping.
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $key ) {
			$value = str_replace( ',', '.', $str( $key ) );
			self::set( $post_id, $key, ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) ? null : round( (float) $value, 3 ) );
		}

		// Variants.
		if ( isset( $in['variations'] ) && is_string( $in['variations'] ) ) {
			list( $attributes, $variants ) = self::sanitize_variations( $in['variations'] );
			self::set( $post_id, 'attributes', $attributes ?: null );
			self::set( $post_id, 'variants', $variants ?: null );
		}

		// Bundle.
		$bundle = array();
		foreach ( (array) ( $in['bundle'] ?? array() ) as $index => $row ) {
			if ( '__i__' === (string) $index || ! is_array( $row ) ) {
				continue;
			}
			$pid = absint( $row['product_id'] ?? 0 );
			if ( $pid && $pid !== $post_id && PostTypes::PRODUCT === get_post_type( $pid ) ) {
				$bundle[] = array(
					'product_id' => $pid,
					'qty'        => max( 1, min( 99, (int) ( $row['qty'] ?? 1 ) ) ),
				);
			}
		}
		self::set( $post_id, 'bundle_items', $bundle ?: null );
		self::set( $post_id, 'bundle_pricing', 'discount' === $str( 'bundle_pricing' ) ? 'discount' : 'fixed' );
		self::set( $post_id, 'bundle_discount', '' === $str( 'bundle_discount' ) ? null : max( 0.0, min( 90.0, (float) str_replace( ',', '.', $str( 'bundle_discount' ) ) ) ) );

		// Downloads.
		$files = self::sanitize_files( (array) ( $in['files'] ?? array() ) );
		self::set( $post_id, 'files', $files ?: null );
		self::set( $post_id, 'download_limit', max( 0, (int) $str( 'download_limit' ) ) ?: null );
		self::set( $post_id, 'download_expiry', max( 0, (int) $str( 'download_expiry' ) ) ?: null );

		// Merchandising.
		foreach ( array( 'upsells', 'cross_sells', 'fbt' ) as $key ) {
			self::set( $post_id, $key, self::product_ids( $in[ $key ] ?? array(), $post_id ) ?: null );
		}
		self::set( $post_id, 'fbt_discount', '' === $str( 'fbt_discount' ) ? null : max( 0.0, min( 90.0, (float) str_replace( ',', '.', $str( 'fbt_discount' ) ) ) ) );

		$highlights = array();
		foreach ( preg_split( '/\r\n|\r|\n/', isset( $in['highlights'] ) && is_string( $in['highlights'] ) ? $in['highlights'] : '' ) ?: array() as $line ) {
			$line = mb_substr( sanitize_text_field( $line ), 0, 160 );
			if ( '' !== $line ) {
				$highlights[] = $line;
			}
		}
		self::set( $post_id, 'highlights', array_slice( $highlights, 0, 10 ) ?: null );

		// FAQ.
		$faq = array();
		foreach ( (array) ( $in['faq'] ?? array() ) as $index => $row ) {
			if ( '__i__' === (string) $index || ! is_array( $row ) ) {
				continue;
			}
			$question = mb_substr( sanitize_text_field( (string) ( $row['q'] ?? '' ) ), 0, 250 );
			$answer   = trim( wp_kses( (string) ( $row['a'] ?? '' ), array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'br' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array() ) ) );
			if ( '' !== $question && '' !== $answer ) {
				$faq[] = array(
					'q' => $question,
					'a' => mb_substr( $answer, 0, 3000 ),
				);
			}
		}
		self::set( $post_id, 'faq', array_slice( $faq, 0, 30 ) ?: null );

		// SEO (only when the fields were displayed).
		if ( array_key_exists( 'seo_title', $in ) ) {
			self::set( $post_id, 'seo_title', mb_substr( sanitize_text_field( $str( 'seo_title' ) ), 0, 120 ) );
			self::set( $post_id, 'seo_description', mb_substr( sanitize_textarea_field( isset( $in['seo_description'] ) && is_string( $in['seo_description'] ) ? $in['seo_description'] : '' ), 0, 320 ) );
		}

		// Gallery (side meta box).
		if ( isset( $_POST['wahou_gallery'] ) ) {
			$gallery = array();
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['wahou_gallery'] ) ) ) as $id ) {
				$id = absint( $id );
				if ( $id && wp_attachment_is_image( $id ) && ! in_array( $id, $gallery, true ) ) {
					$gallery[] = $id;
				}
			}
			self::set( $post_id, 'gallery', array_slice( $gallery, 0, 30 ) ?: null );
		}

		Product::flush( $post_id );

		/**
		 * Fires after the product data meta box was saved.
		 *
		 * @param int                  $post_id Product ID.
		 * @param array<string, mixed> $in      Unslashed raw input.
		 */
		do_action( 'wahou_product_editor_saved', $post_id, $in );
	}

	/**
	 * Store a meta value (null or '' deletes it). Values are slashed because the
	 * metadata API unslashes them.
	 *
	 * @param mixed $value
	 */
	private static function set( int $post_id, string $key, $value ): void {
		if ( null === $value || '' === $value || array() === $value ) {
			delete_post_meta( $post_id, '_wahou_' . $key );
			return;
		}
		update_post_meta( $post_id, '_wahou_' . $key, wp_slash( $value ) );
	}

	private static function set_bool( int $post_id, string $key, bool $value ): void {
		update_post_meta( $post_id, '_wahou_' . $key, $value ? '1' : '0' );
	}

	private static function date_to_timestamp( string $date, bool $end_of_day ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		try {
			$datetime = new \DateTimeImmutable( $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		} catch ( \Exception $e ) {
			return 0;
		}
		return $datetime->getTimestamp();
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function product_ids( $raw, int $exclude ): array {
		$ids = array();
		foreach ( (array) $raw as $id ) {
			$id = absint( is_scalar( $id ) ? $id : 0 );
			if ( $id && $id !== $exclude && ! in_array( $id, $ids, true ) && PostTypes::PRODUCT === get_post_type( $id ) ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, 30 );
	}

	/**
	 * @return array{0: array<int, array{name:string, values:string[]}>, 1: array<int, array<string, mixed>>}
	 */
	private static function sanitize_variations( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array( array(), array() );
		}

		$attributes = array();
		$names      = array();
		foreach ( array_slice( (array) ( $data['attributes'] ?? array() ), 0, 5 ) as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}
			$name = mb_substr( sanitize_text_field( (string) ( $attribute['name'] ?? '' ) ), 0, 60 );
			if ( '' === $name || isset( $names[ $name ] ) ) {
				continue;
			}
			$values = array();
			foreach ( (array) ( $attribute['values'] ?? array() ) as $value ) {
				$value = mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 60 );
				if ( '' !== $value && ! in_array( $value, $values, true ) ) {
					$values[] = $value;
				}
			}
			if ( ! $values ) {
				continue;
			}
			$values         = array_slice( $values, 0, 50 );
			$names[ $name ] = $values;
			$attributes[]   = array(
				'name'   => $name,
				'values' => $values,
			);
		}

		$variants = array();
		$ids      = array();
		foreach ( array_slice( (array) ( $data['variants'] ?? array() ), 0, 200 ) as $variant ) {
			if ( ! is_array( $variant ) || ! $attributes ) {
				continue;
			}
			$combination = array();
			foreach ( $names as $name => $values ) {
				$value = (string) ( $variant['attributes'][ $name ] ?? '' );
				if ( ! in_array( $value, $values, true ) ) {
					continue 2;
				}
				$combination[ $name ] = $value;
			}
			$key = wp_json_encode( $combination );
			if ( isset( $ids[ 'combo:' . $key ] ) ) {
				continue;
			}
			$id = (string) ( $variant['id'] ?? '' );
			if ( ! preg_match( '/^v[a-z0-9]{4,16}$/', $id ) || isset( $ids[ $id ] ) ) {
				$id = 'v' . strtolower( wp_generate_password( 8, false ) );
			}
			$ids[ $id ]             = true;
			$ids[ 'combo:' . $key ] = true;

			$price = trim( (string) ( $variant['price'] ?? '' ) );
			$sale  = trim( (string) ( $variant['sale_price'] ?? '' ) );
			$stock = trim( (string) ( $variant['stock'] ?? '' ) );
			$image = absint( $variant['image_id'] ?? 0 );

			$variants[] = array(
				'id'         => $id,
				'attributes' => $combination,
				'price'      => '' === $price ? null : max( 0, Money::to_minor( $price ) ),
				'sale_price' => '' === $sale ? null : max( 0, Money::to_minor( $sale ) ),
				'sku'        => mb_substr( sanitize_text_field( (string) ( $variant['sku'] ?? '' ) ), 0, 100 ),
				'stock'      => '' === $stock || ! is_numeric( $stock ) ? null : (int) $stock,
				'image_id'   => $image && wp_attachment_is_image( $image ) ? $image : 0,
			);
		}
		return array( $attributes, $variants );
	}

	/**
	 * @param array<int|string, mixed> $rows
	 * @return array<int, array<string, string>>
	 */
	private static function sanitize_files( array $rows ): array {
		$base = realpath( Install::private_dir() );
		$out  = array();
		foreach ( $rows as $index => $row ) {
			if ( '__i__' === (string) $index || ! is_array( $row ) ) {
				continue;
			}
			$id   = (string) ( $row['id'] ?? '' );
			$id   = preg_match( '/^f_[a-z0-9]{6,24}$/', $id ) ? $id : 'f_' . strtolower( wp_generate_password( 10, false ) );
			$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 150 );

			if ( ! empty( $row['path'] ) && '__path__' !== $row['path'] ) {
				$relative = ltrim( str_replace( '\\', '/', (string) $row['path'] ), '/' );
				if ( ! $base || false !== strpos( $relative, '..' ) ) {
					continue;
				}
				$absolute = realpath( $base . DIRECTORY_SEPARATOR . $relative );
				if ( ! $absolute || 0 !== strpos( $absolute, $base . DIRECTORY_SEPARATOR ) || ! is_file( $absolute ) ) {
					continue;
				}
				$out[] = array(
					'id'   => $id,
					'name' => '' !== $name ? $name : basename( $absolute ),
					'path' => $relative,
				);
			} elseif ( ! empty( $row['url'] ) ) {
				$url = esc_url_raw( trim( (string) $row['url'] ), array( 'http', 'https' ) );
				if ( '' === $url ) {
					continue;
				}
				$out[] = array(
					'id'   => $id,
					'name' => '' !== $name ? $name : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
					'url'  => $url,
				);
			}
		}
		return array_slice( $out, 0, 50 );
	}

	/* ------------------------------------------------------------------
	 * Private uploads.
	 * ------------------------------------------------------------------ */

	/**
	 * Extensions accepted for downloadable files (never executable types).
	 *
	 * @return array<string, string> extension regex => mime type
	 */
	public static function allowed_mimes(): array {
		return (array) apply_filters(
			'wahou_download_mimes',
			array(
				'pdf'       => 'application/pdf',
				'epub'      => 'application/epub+zip',
				'mobi'      => 'application/x-mobipocket-ebook',
				'azw|azw3'  => 'application/vnd.amazon.ebook',
				'zip'       => 'application/zip',
				'rar'       => 'application/vnd.rar',
				'7z'        => 'application/x-7z-compressed',
				'mp3'       => 'audio/mpeg',
				'm4a|m4b'   => 'audio/mp4',
				'wav'       => 'audio/wav',
				'flac'      => 'audio/flac',
				'ogg|oga'   => 'audio/ogg',
				'mp4|m4v'   => 'video/mp4',
				'mov'       => 'video/quicktime',
				'webm'      => 'video/webm',
				'jpg|jpeg'  => 'image/jpeg',
				'png'       => 'image/png',
				'gif'       => 'image/gif',
				'webp'      => 'image/webp',
				'txt'       => 'text/plain',
				'csv'       => 'text/csv',
				'rtf'       => 'application/rtf',
				'doc'       => 'application/msword',
				'docx'      => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'xls'       => 'application/vnd.ms-excel',
				'xlsx'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'ppt'       => 'application/vnd.ms-powerpoint',
				'pptx'      => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'odt'       => 'application/vnd.oasis.opendocument.text',
				'ods'       => 'application/vnd.oasis.opendocument.spreadsheet',
				'odp'       => 'application/vnd.oasis.opendocument.presentation',
				'key'       => 'application/vnd.apple.keynote',
				'pages'     => 'application/vnd.apple.pages',
				'numbers'   => 'application/vnd.apple.numbers',
				'psd'       => 'image/vnd.adobe.photoshop',
				'ai|eps'    => 'application/postscript',
				'ttf'       => 'font/ttf',
				'otf'       => 'font/otf',
				'woff'      => 'font/woff',
				'woff2'     => 'font/woff2',
				'json'      => 'application/json',
				'ics'       => 'text/calendar',
			)
		);
	}

	private static function extension_allowed( string $extension ): bool {
		if ( '' === $extension || in_array( $extension, array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'html', 'htm', 'svg', 'js', 'exe', 'sh', 'cgi', 'pl', 'py', 'htaccess' ), true ) ) {
			return false;
		}
		foreach ( array_keys( self::allowed_mimes() ) as $pattern ) {
			if ( in_array( $extension, explode( '|', (string) $pattern ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * AJAX: upload a downloadable file into the private folder.
	 */
	public static function ajax_upload(): void {
		check_ajax_referer( 'wahou_upload_file', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_wahou_products' );
		if ( ! $allowed || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wahou-commerce' ) ), 403 );
		}
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) || empty( $_FILES['file']['name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'wahou-commerce' ) ), 400 );
		}
		$file      = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated by wp_handle_upload() and the extension allow-list.
		$original  = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		$extension = strtolower( (string) pathinfo( $original, PATHINFO_EXTENSION ) );
		if ( ! self::extension_allowed( $extension ) ) {
			/* translators: %s: file extension. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Files of type .%s are not allowed.', 'wahou-commerce' ), $extension ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		Install::create_private_dirs();
		$folder = strtolower( wp_generate_password( 16, false ) );
		$filter = static function ( array $dirs ) use ( $folder ): array {
			$base            = Install::private_dir();
			$dirs['path']    = $base . '/' . $folder;
			$dirs['url']     = '';
			$dirs['subdir']  = '/' . $folder;
			$dirs['basedir'] = $base;
			$dirs['baseurl'] = '';
			$dirs['error']   = false;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter, 999 );
		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => false,
				'mimes'     => self::allowed_mimes(),
			)
		);
		remove_filter( 'upload_dir', $filter, 999 );

		if ( ! is_array( $result ) || isset( $result['error'] ) || empty( $result['file'] ) ) {
			wp_send_json_error( array( 'message' => is_array( $result ) && isset( $result['error'] ) ? (string) $result['error'] : __( 'The upload failed.', 'wahou-commerce' ) ), 400 );
		}

		$absolute = (string) $result['file'];
		$dir      = dirname( $absolute );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( Install::private_dir() ) ) ), '/' );

		wp_send_json_success(
			array(
				'id'   => 'f_' . strtolower( wp_generate_password( 10, false ) ),
				'name' => (string) preg_replace( '/\.[^.]+$/', '', $original ),
				'path' => $relative,
				'file' => basename( $absolute ),
				'size' => size_format( (int) filesize( $absolute ) ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * List table.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$out['wahou_thumb'] = '<span class="screen-reader-text">' . esc_html__( 'Image', 'wahou-commerce' ) . '</span>';
			}
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['wahou_price'] = __( 'Price', 'wahou-commerce' );
				$out['wahou_stock'] = __( 'Stock', 'wahou-commerce' );
				$out['wahou_kind']  = __( 'Type', 'wahou-commerce' );
				$out['wahou_sales'] = __( 'Sales', 'wahou-commerce' );
			}
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		$product = Product::find( $post_id );
		if ( ! $product ) {
			return;
		}
		switch ( $column ) {
			case 'wahou_thumb':
				$image = $product->image_id() ? wp_get_attachment_image(
					$product->image_id(),
					array( 48, 48 ),
					false,
					array(
						'class'   => 'wahou-thumb',
						'loading' => 'lazy',
					)
				) : '';
				echo $image ? $image : '<span class="wahou-thumb wahou-thumb--empty dashicons dashicons-format-image" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'wahou_price':
				echo wp_kses_post( self::price_html( $product ) );
				break;

			case 'wahou_stock':
				$labels = array(
					'in_stock'     => array( __( 'In stock', 'wahou-commerce' ), 'success' ),
					'low_stock'    => array( __( 'Low stock', 'wahou-commerce' ), 'warning' ),
					'out_of_stock' => array( __( 'Out of stock', 'wahou-commerce' ), 'danger' ),
					'backorder'    => array( __( 'Backorder', 'wahou-commerce' ), 'info' ),
				);
				$status = $labels[ $product->stock_status() ] ?? $labels['in_stock'];
				echo Fields::pill( $status[0], $status[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( $product->manages_stock() && null !== $product->stock() ) {
					echo ' <span class="wahou-muted">(' . esc_html( number_format_i18n( (int) $product->stock() ) ) . ')</span>';
				}
				break;

			case 'wahou_kind':
				$kinds = array(
					'physical' => __( 'Physical', 'wahou-commerce' ),
					'digital'  => __( 'Digital', 'wahou-commerce' ),
					'service'  => __( 'Service', 'wahou-commerce' ),
				);
				$types = array(
					'simple'   => '',
					'variable' => __( 'options', 'wahou-commerce' ),
					'bundle'   => __( 'bundle', 'wahou-commerce' ),
				);
				echo esc_html( $kinds[ $product->kind() ] ?? $product->kind() );
				if ( '' !== ( $types[ $product->type() ] ?? '' ) ) {
					echo ' <span class="wahou-muted">· ' . esc_html( $types[ $product->type() ] ) . '</span>';
				}
				break;

			case 'wahou_sales':
				echo esc_html( number_format_i18n( $product->total_sales() ) );
				break;
		}
	}

	private static function price_html( Product $product ): string {
		if ( $product->variants() ) {
			list( $min, $max ) = $product->price_range();
			return $min === $max ? esc_html( Money::format( $min ) ) : esc_html( Money::format( $min ) . ' – ' . Money::format( $max ) );
		}
		if ( $product->is_on_sale() ) {
			return '<del>' . esc_html( Money::format( $product->regular_price() ) ) . '</del> <ins>' . esc_html( Money::format( $product->price() ) ) . '</ins>';
		}
		return esc_html( Money::format( $product->price() ) );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function sortable_columns( array $columns ): array {
		$columns['wahou_price'] = 'wahou_price';
		$columns['wahou_sales'] = 'wahou_sales';
		return $columns;
	}

	public static function sort_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::PRODUCT !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( 'wahou_price' === $orderby || 'wahou_sales' === $orderby ) {
			$query->set( 'meta_key', 'wahou_price' === $orderby ? '_wahou_price' : '_wahou_total_sales' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( PostTypes::PRODUCT !== $post->post_type || ! current_user_can( 'edit_wahou_products' ) ) {
			return $actions;
		}
		$url                        = wp_nonce_url( admin_url( 'admin-post.php?action=wahou_duplicate_product&post=' . $post->ID ), 'wahou_duplicate_' . $post->ID );
		$actions['wahou_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'wahou-commerce' ) . '</a>';
		return $actions;
	}

	/**
	 * admin-post.php?action=wahou_duplicate_product&post=ID
	 */
	public static function duplicate(): void {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'wahou_duplicate_' . $id );
		$post = get_post( $id );
		if ( ! $post || PostTypes::PRODUCT !== $post->post_type || ! current_user_can( 'edit_wahou_products' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wahou-commerce' ), 403 );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => PostTypes::PRODUCT,
					'post_status'    => 'draft',
					/* translators: %s: product name. */
					'post_title'     => sprintf( __( '%s (copy)', 'wahou-commerce' ), $post->post_title ),
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'menu_order'     => $post->menu_order,
					'comment_status' => $post->comment_status,
					'post_author'    => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		foreach ( array( PostTypes::CATEGORY, PostTypes::TAG ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}
		$thumbnail = get_post_thumbnail_id( $id );
		if ( $thumbnail ) {
			set_post_thumbnail( $new_id, $thumbnail );
		}
		$skip = array( '_wahou_total_sales', '_wahou_price_history', '_wahou_rating_avg', '_wahou_rating_count', '_wahou_sku', '_wahou_gtin' );
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, '_wahou_' ) || in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		Product::flush( (int) $new_id );

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
		exit;
	}
}
