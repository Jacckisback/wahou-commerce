<?php
/**
 * Google Merchant Center product feed (RSS 2.0 + g: namespace).
 *
 * URL: /wahou-feed/google.xml (or ?wahou_feed=google without pretty permalinks).
 * Prices include the store country VAT, as Merchant Center expects for EU
 * countries. Variants are exported as separate items grouped by item_group_id.
 * The document is cached and rebuilt when the catalog changes.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Feed {

	private const MAX_PRODUCTS = 5000;
	private const CHUNK        = 200;

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'serve' ), 1 );
	}

	public static function url(): string {
		return get_option( 'permalink_structure' ) ? home_url( '/wahou-feed/google.xml' ) : add_query_arg( 'wahou_feed', 'google', home_url( '/' ) );
	}

	public static function serve(): void {
		if ( 'google' !== get_query_var( 'wahou_feed' ) ) {
			return;
		}
		if ( ! Settings::bool( 'seo_feed' ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		$key = 'wahou_feed_' . md5( determine_locale() . '|' . Seo::catalog_version() . '|' . home_url() );
		$xml = get_transient( $key );
		if ( ! is_string( $xml ) || '' === $xml ) {
			$xml = self::build();
			set_transient( $key, $xml, 6 * HOUR_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=1800' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_xml().
		exit;
	}

	public static function build(): string {
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
		$out .= self::tag( 'title', Settings::store_name() );
		$out .= self::tag( 'link', home_url( '/' ) );
		$out .= self::tag( 'description', wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) ?: Settings::store_name() );

		$page  = 1;
		$count = 0;
		do {
			$ids = get_posts(
				array(
					'post_type'      => PostTypes::PRODUCT,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => self::CHUNK,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$product = Product::find( (int) $id );
				if ( ! $product ) {
					continue;
				}
				$out .= self::items( $product );
				++$count;
			}
			++$page;
		} while ( count( $ids ) === self::CHUNK && $count < self::MAX_PRODUCTS );

		$out .= "</channel>\n</rss>\n";
		return $out;
	}

	private static function items( Product $product ): string {
		$variants = 'variable' === $product->type() ? $product->variants() : array();
		if ( ! $variants ) {
			return self::item( $product, null );
		}
		$out = '';
		foreach ( $variants as $variant ) {
			$out .= self::item( $product, (string) $variant['id'], $variant );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $variant
	 */
	private static function item( Product $product, ?string $variant_id, array $variant = array() ): string {
		$currency = Settings::currency();
		$id       = $product->sku( $variant_id );
		if ( '' === $id ) {
			$id = 'wahou-' . $product->id() . ( $variant_id ? '-' . $variant_id : '' );
		}
		$title = wp_strip_all_tags( $product->name() );
		if ( $variant_id ) {
			$label = $product->variant_label( $variant_id );
			$title = '' !== $label ? $title . ' – ' . $label : $title;
		}
		$description = Seo::plain_text( $product->description() );
		if ( '' === $description ) {
			$description = $product->summary( 120 );
		}
		$link = $variant_id ? add_query_arg( 'variant', rawurlencode( $variant_id ), $product->permalink() ) : $product->permalink();

		$out  = "<item>\n";
		$out .= self::tag( 'g:id', $id );
		$out .= self::tag( 'g:title', mb_substr( $title, 0, 150 ) );
		$out .= self::tag( 'g:description', mb_substr( '' !== $description ? $description : $title, 0, 5000 ) );
		$out .= self::tag( 'g:link', $link );

		$image = $product->image_id( $variant_id );
		if ( $image ) {
			$src = wp_get_attachment_image_url( $image, 'full' );
			if ( $src ) {
				$out .= self::tag( 'g:image_link', $src );
			}
		}
		$extra = 0;
		foreach ( $product->gallery_ids() as $gallery_id ) {
			if ( $gallery_id === $image || $extra >= 10 ) {
				continue;
			}
			$src = wp_get_attachment_image_url( $gallery_id, 'full' );
			if ( $src ) {
				$out .= self::tag( 'g:additional_image_link', $src );
				++$extra;
			}
		}

		switch ( $product->stock_status( $variant_id ) ) {
			case 'out_of_stock':
				$availability = 'out_of_stock';
				break;
			case 'backorder':
				$availability = 'backorder';
				break;
			default:
				$availability = 'in_stock';
		}
		$out .= self::tag( 'g:availability', $availability );

		$regular = Seo::gross_price( $product, $product->regular_price( $variant_id ) );
		$out    .= self::tag( 'g:price', Money::to_decimal( $regular, $currency ) . ' ' . $currency );
		if ( $product->is_on_sale( $variant_id ) ) {
			$sale = Seo::gross_price( $product, (int) $product->sale_price( $variant_id ) );
			$out .= self::tag( 'g:sale_price', Money::to_decimal( $sale, $currency ) . ' ' . $currency );
			$from = (int) $product->meta( 'sale_from', 0 );
			$to   = $product->sale_ends_at();
			if ( $from && $to ) {
				$out .= self::tag( 'g:sale_price_effective_date', gmdate( 'c', $from ) . '/' . gmdate( 'c', $to ) );
			}
		}

		$out .= self::tag( 'g:brand', $product->brand() );
		$has_identifier = false;
		if ( '' !== $product->gtin() && ! $variant_id ) {
			$out           .= self::tag( 'g:gtin', preg_replace( '/\D/', '', $product->gtin() ) ?? '' );
			$has_identifier = true;
		}
		if ( '' !== $product->mpn() ) {
			$out           .= self::tag( 'g:mpn', $product->mpn() . ( $variant_id ? '-' . $variant_id : '' ) );
			$has_identifier = true;
		}
		if ( ! $has_identifier ) {
			$out .= self::tag( 'g:identifier_exists', 'no' );
		}
		$out .= self::tag( 'g:condition', 'new' );

		$type = self::product_type( $product );
		if ( '' !== $type ) {
			$out .= self::tag( 'g:product_type', $type );
		}
		if ( $variant_id ) {
			$out .= self::tag( 'g:item_group_id', '' !== $product->sku() ? $product->sku() : 'wahou-' . $product->id() );
			foreach ( (array) ( $variant['attributes'] ?? array() ) as $name => $value ) {
				$property = Seo::attribute_property( (string) $name );
				if ( '' !== $property ) {
					$out .= self::tag( 'g:' . $property, (string) $value );
				}
			}
		}
		if ( $product->needs_shipping() && $product->weight() > 0 ) {
			$out .= self::tag( 'g:shipping_weight', rtrim( rtrim( number_format( $product->weight(), 3, '.', '' ), '0' ), '.' ) . ' kg' );
		}
		if ( 'bundle' === $product->type() ) {
			$out .= self::tag( 'g:is_bundle', 'yes' );
		}
		$out .= "</item>\n";

		/**
		 * Filters one feed item (XML string).
		 */
		return (string) apply_filters( 'wahou_feed_item', $out, $product, $variant_id );
	}

	private static function product_type( Product $product ): string {
		$term = Breadcrumbs::primary_category( $product );
		if ( ! $term ) {
			return '';
		}
		$names = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, PostTypes::CATEGORY );
			if ( $ancestor instanceof \WP_Term ) {
				$names[] = $ancestor->name;
			}
		}
		$names[] = $term->name;
		return implode( ' > ', array_map( 'wp_strip_all_tags', $names ) );
	}

	private static function tag( string $name, string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}
		return '<' . $name . '>' . esc_xml( $value ) . '</' . $name . ">\n";
	}
}
