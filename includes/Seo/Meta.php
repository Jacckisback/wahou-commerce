<?php
/**
 * Meta tags for products and product categories.
 *
 * Mode "seo_meta": auto (only when no SEO plugin is active), on, off.
 * Product price/availability Open Graph tags (used by social catalogs and
 * link previews) are always printed on product pages.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Compat\Compat;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Meta {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 2 );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 20 );
	}

	public static function enabled(): bool {
		$mode = Settings::str( 'seo_meta' );
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'on' === $mode ) {
			return true;
		}
		return ! Compat::seo_plugin_active();
	}

	/**
	 * Custom SEO title of a product.
	 *
	 * @param string $title
	 * @return string
	 */
	public static function title( $title ) {
		if ( ! self::enabled() || ! is_singular( PostTypes::PRODUCT ) ) {
			return $title;
		}
		$custom = trim( (string) get_post_meta( get_queried_object_id(), '_wahou_seo_title', true ) );
		return '' !== $custom ? $custom : $title;
	}

	public static function output(): void {
		if ( is_singular( PostTypes::PRODUCT ) ) {
			$product = Product::find( get_queried_object_id() );
			if ( ! $product ) {
				return;
			}
			if ( self::enabled() ) {
				self::product_tags( $product );
			}
			self::product_commerce_tags( $product );
		} elseif ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) && self::enabled() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				self::term_tags( $term );
			}
		}
	}

	public static function description( Product $product ): string {
		$custom = trim( (string) get_post_meta( $product->id(), '_wahou_seo_description', true ) );
		return '' !== $custom ? $custom : $product->summary( 30 );
	}

	private static function product_tags( Product $product ): void {
		$title       = trim( (string) get_post_meta( $product->id(), '_wahou_seo_title', true ) );
		$title       = '' !== $title ? $title : wp_strip_all_tags( $product->name() );
		$description = self::description( $product );
		$image_id    = $product->image_id();

		$tags = array(
			array( 'name', 'description', $description ),
			array( 'property', 'og:type', 'product' ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $description ),
			array( 'property', 'og:url', $product->permalink() ),
			array( 'property', 'og:site_name', Settings::store_name() ),
			array( 'property', 'og:locale', str_replace( '-', '_', determine_locale() ) ),
			array( 'name', 'twitter:card', $image_id ? 'summary_large_image' : 'summary' ),
			array( 'name', 'twitter:title', $title ),
			array( 'name', 'twitter:description', $description ),
		);
		if ( $image_id ) {
			$image = wp_get_attachment_image_src( $image_id, 'large' );
			if ( $image ) {
				$alt    = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
				$tags[] = array( 'property', 'og:image', $image[0] );
				$tags[] = array( 'property', 'og:image:width', (string) $image[1] );
				$tags[] = array( 'property', 'og:image:height', (string) $image[2] );
				$tags[] = array( 'property', 'og:image:alt', '' !== $alt ? $alt : $title );
				$tags[] = array( 'name', 'twitter:image', $image[0] );
			}
		}
		self::print_tags( $tags );
	}

	/**
	 * Price and availability tags (Open Graph "product" namespace).
	 */
	private static function product_commerce_tags( Product $product ): void {
		$currency = Settings::currency();
		$regular  = Seo::gross_price( $product, $product->regular_price() );
		$current  = Seo::gross_price( $product, $product->price() );
		$status   = $product->stock_status();
		$tags     = array(
			array( 'property', 'product:price:amount', Money::to_decimal( $product->is_on_sale() ? $regular : $current, $currency ) ),
			array( 'property', 'product:price:currency', $currency ),
			array( 'property', 'product:availability', 'out_of_stock' === $status ? 'out of stock' : ( 'backorder' === $status ? 'available for order' : 'in stock' ) ),
			array( 'property', 'product:condition', 'new' ),
			array( 'property', 'product:retailer_item_id', '' !== $product->sku() ? $product->sku() : (string) $product->id() ),
			array( 'property', 'product:brand', $product->brand() ),
		);
		if ( $product->is_on_sale() ) {
			$tags[] = array( 'property', 'product:sale_price:amount', Money::to_decimal( $current, $currency ) );
			$tags[] = array( 'property', 'product:sale_price:currency', $currency );
		}
		self::print_tags( $tags );
	}

	private static function term_tags( \WP_Term $term ): void {
		$description = Seo::plain_text( (string) term_description( $term ), 30 );
		if ( '' === $description ) {
			/* translators: %s: category name. */
			$description = sprintf( __( 'Discover our selection: %s.', 'wahou-commerce' ), $term->name );
		}
		$link = get_term_link( $term );
		$tags = array(
			array( 'name', 'description', $description ),
			array( 'property', 'og:type', 'website' ),
			array( 'property', 'og:title', wp_strip_all_tags( $term->name ) ),
			array( 'property', 'og:description', $description ),
			array( 'property', 'og:url', is_string( $link ) ? $link : home_url( '/' ) ),
			array( 'property', 'og:site_name', Settings::store_name() ),
			array( 'name', 'twitter:card', 'summary_large_image' ),
		);
		global $wp_query;
		foreach ( (array) $wp_query->posts as $post ) {
			$thumb = get_post_thumbnail_id( $post );
			$src   = $thumb ? wp_get_attachment_image_url( $thumb, 'large' ) : '';
			if ( $src ) {
				$tags[] = array( 'property', 'og:image', $src );
				$tags[] = array( 'name', 'twitter:image', $src );
				break;
			}
		}
		self::print_tags( $tags );
	}

	/**
	 * @param array<int, array{0:string, 1:string, 2:string}> $tags
	 */
	private static function print_tags( array $tags ): void {
		foreach ( $tags as $tag ) {
			if ( '' === trim( (string) $tag[2] ) ) {
				continue;
			}
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $tag[0] ), esc_attr( $tag[1] ), esc_attr( $tag[2] ) );
		}
	}
}
