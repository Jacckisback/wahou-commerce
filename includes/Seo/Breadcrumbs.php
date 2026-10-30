<?php
/**
 * Breadcrumb trail for the current request (product, category/tag, shop page).
 * Used for the BreadcrumbList schema and by templates for the visible trail.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Breadcrumbs {

	/**
	 * @return array<int, array{name:string, url:string}>
	 */
	public static function items(): array {
		$items   = array();
		$home    = array(
			'name' => __( 'Home', 'wahou-commerce' ),
			'url'  => home_url( '/' ),
		);
		$shop_id = Pages::id( 'shop' );
		$shop    = $shop_id ? array(
			'name' => wp_strip_all_tags( get_the_title( $shop_id ) ),
			'url'  => (string) get_permalink( $shop_id ),
		) : null;

		if ( is_singular( PostTypes::PRODUCT ) ) {
			$product = Product::find( get_queried_object_id() );
			if ( $product ) {
				$items[] = $home;
				if ( $shop ) {
					$items[] = $shop;
				}
				$term = self::primary_category( $product );
				if ( $term ) {
					$items = array_merge( $items, self::term_chain( $term ) );
				}
				$items[] = array(
					'name' => wp_strip_all_tags( $product->name() ),
					'url'  => $product->permalink(),
				);
			}
		} elseif ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$items[] = $home;
				if ( $shop ) {
					$items[] = $shop;
				}
				$items = array_merge( $items, self::term_chain( $term ) );
			}
		} elseif ( $shop && Pages::is( 'shop' ) ) {
			$items = array( $home, $shop );
		}

		/**
		 * Filters the breadcrumb trail ([ name, url ] items).
		 */
		return (array) apply_filters( 'wahou_breadcrumbs', $items );
	}

	/**
	 * Accessible breadcrumb markup.
	 */
	public static function html( string $class = 'wahou-breadcrumbs' ): string {
		$items = self::items();
		$count = count( $items );
		if ( $count < 2 ) {
			return '';
		}
		$out = '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'wahou-commerce' ) . '"><ol>';
		foreach ( $items as $i => $item ) {
			$out .= $i === $count - 1
				? '<li><span aria-current="page">' . esc_html( $item['name'] ) . '</span></li>'
				: '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a></li>';
		}
		return $out . '</ol></nav>';
	}

	/**
	 * Primary category: the SEO plugin's choice when set, else the deepest one.
	 */
	public static function primary_category( Product $product ): ?\WP_Term {
		foreach ( array( '_yoast_wpseo_primary_' . PostTypes::CATEGORY, 'rank_math_primary_' . PostTypes::CATEGORY ) as $key ) {
			$primary = (int) get_post_meta( $product->id(), $key, true );
			if ( $primary ) {
				$term = get_term( $primary, PostTypes::CATEGORY );
				if ( $term instanceof \WP_Term ) {
					return $term;
				}
			}
		}
		$best  = null;
		$depth = -1;
		foreach ( $product->categories() as $term ) {
			$d = count( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) );
			if ( $d > $depth ) {
				$best  = $term;
				$depth = $d;
			}
		}
		return $best;
	}

	/**
	 * Ancestors then the term itself.
	 *
	 * @return array<int, array{name:string, url:string}>
	 */
	private static function term_chain( \WP_Term $term ): array {
		$chain = array();
		$ids   = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		$ids[] = $term->term_id;
		foreach ( $ids as $id ) {
			$t    = get_term( (int) $id, $term->taxonomy );
			$link = $t instanceof \WP_Term ? get_term_link( $t ) : '';
			if ( $t instanceof \WP_Term && is_string( $link ) ) {
				$chain[] = array(
					'name' => wp_strip_all_tags( $t->name ),
					'url'  => $link,
				);
			}
		}
		return $chain;
	}
}
