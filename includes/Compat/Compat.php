<?php
/**
 * Compatibility layer: multilingual plugins (WPML, Polylang) and SEO plugins.
 *
 *  - Store pages resolve to the visitor's language (wahou_translate_page_id).
 *  - Products and their categories/tags are translatable in Polylang; WPML
 *    reads wpml-config.xml at the plugin root.
 *  - Prices, stock and logistics data are shared by all translations of a
 *    product; texts (highlights, FAQ, options, SEO) are copied once and then
 *    translated. Stock changes are propagated to every translation.
 *  - seo_plugin_active() lets the SEO module step aside when Yoast, Rank Math,
 *    SEOPress, AIOSEO, The SEO Framework or Slim SEO handle meta tags.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Compat;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;

defined( 'ABSPATH' ) || exit;

final class Compat {

	/** Product meta shared by every translation (kept in sync). */
	public const SHARED_META = array(
		'_wahou_kind',
		'_wahou_type',
		'_wahou_price',
		'_wahou_sale_price',
		'_wahou_sale_from',
		'_wahou_sale_to',
		'_wahou_sku',
		'_wahou_gtin',
		'_wahou_mpn',
		'_wahou_manage_stock',
		'_wahou_stock',
		'_wahou_backorders',
		'_wahou_low_stock',
		'_wahou_weight',
		'_wahou_length',
		'_wahou_width',
		'_wahou_height',
		'_wahou_tax_class',
		'_wahou_files',
		'_wahou_download_limit',
		'_wahou_download_expiry',
		'_wahou_sold_individually',
		'_wahou_bundle_pricing',
		'_wahou_bundle_discount',
		'_wahou_fbt_discount',
		'_wahou_gallery',
	);

	/** Product meta copied when a translation is created, then translated. */
	public const TRANSLATABLE_META = array(
		'_wahou_brand',
		'_wahou_badge',
		'_wahou_highlights',
		'_wahou_faq',
		'_wahou_attributes',
		'_wahou_variants',
		'_wahou_seo_title',
		'_wahou_seo_description',
		'_wahou_bundle_items',
		'_wahou_upsells',
		'_wahou_cross_sells',
		'_wahou_fbt',
	);

	private static bool $syncing = false;

	public static function init(): void {
		add_filter( 'wahou_translate_page_id', array( self::class, 'translate_page_id' ), 10, 2 );

		// Polylang.
		add_filter( 'pll_get_post_types', array( self::class, 'pll_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( self::class, 'pll_taxonomies' ), 10, 2 );
		add_filter( 'pll_copy_post_metas', array( self::class, 'pll_copy_metas' ), 10, 5 );

		// Both: one stock for every translation.
		add_action( 'wahou_stock_changed', array( self::class, 'sync_stock' ), 5, 4 );
	}

	public static function is_wpml(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public static function is_polylang(): bool {
		return function_exists( 'pll_get_post' );
	}

	public static function is_multilingual(): bool {
		return self::is_wpml() || self::is_polylang();
	}

	/**
	 * Store page in the current language.
	 */
	public static function translate_page_id( int $id, string $key = '' ): int {
		if ( $id <= 0 ) {
			return $id;
		}
		if ( self::is_polylang() ) {
			$translated = pll_get_post( $id );
			return $translated ? (int) $translated : $id;
		}
		if ( self::is_wpml() ) {
			return (int) apply_filters( 'wpml_object_id', $id, 'page', true );
		}
		return $id;
	}

	/**
	 * Products are always translatable with Polylang (hidden from its settings list).
	 *
	 * @param array<string, string> $post_types
	 * @return array<string, string>
	 */
	public static function pll_post_types( $post_types, $is_settings = false ): array {
		$post_types = (array) $post_types;
		if ( $is_settings ) {
			unset( $post_types[ PostTypes::PRODUCT ] );
		} else {
			$post_types[ PostTypes::PRODUCT ] = PostTypes::PRODUCT;
		}
		return $post_types;
	}

	/**
	 * @param array<string, string> $taxonomies
	 * @return array<string, string>
	 */
	public static function pll_taxonomies( $taxonomies, $is_settings = false ): array {
		$taxonomies = (array) $taxonomies;
		foreach ( array( PostTypes::CATEGORY, PostTypes::TAG ) as $taxonomy ) {
			if ( $is_settings ) {
				unset( $taxonomies[ $taxonomy ] );
			} else {
				$taxonomies[ $taxonomy ] = $taxonomy;
			}
		}
		return $taxonomies;
	}

	/**
	 * Meta copied by Polylang: shared keys are synchronized, texts only copied at creation.
	 *
	 * @param string[] $metas
	 * @param bool     $sync True when synchronizing an existing translation.
	 * @param int      $from Source post ID.
	 * @return string[]
	 */
	public static function pll_copy_metas( $metas, $sync = false, $from = 0, $to = 0, $lang = '' ): array {
		$metas = (array) $metas;
		if ( PostTypes::PRODUCT !== get_post_type( (int) $from ) ) {
			return $metas;
		}
		$keys = $sync ? self::SHARED_META : array_merge( self::SHARED_META, self::TRANSLATABLE_META );
		return array_values( array_unique( array_merge( $metas, $keys ) ) );
	}

	/**
	 * Propagate a stock change to the other translations of a product.
	 */
	public static function sync_stock( int $product_id, int $stock, ?string $variant_id = null, int $delta = 0 ): void {
		if ( self::$syncing || ! self::is_multilingual() ) {
			return;
		}
		$ids = self::translations( $product_id );
		if ( ! $ids ) {
			return;
		}
		self::$syncing = true;
		foreach ( $ids as $translation_id ) {
			if ( $variant_id ) {
				$variants = get_post_meta( $translation_id, '_wahou_variants', true );
				if ( is_array( $variants ) ) {
					foreach ( $variants as &$variant ) {
						if ( is_array( $variant ) && (string) ( $variant['id'] ?? '' ) === $variant_id && isset( $variant['stock'] ) && '' !== $variant['stock'] && null !== $variant['stock'] ) {
							$variant['stock'] = $stock;
						}
					}
					unset( $variant );
					update_post_meta( $translation_id, '_wahou_variants', $variants );
				}
			} else {
				update_post_meta( $translation_id, '_wahou_stock', $stock );
			}
			Product::flush( $translation_id );
		}
		self::$syncing = false;
	}

	/**
	 * IDs of the other translations of a post (a product by default).
	 *
	 * @return int[]
	 */
	public static function translations( int $product_id, string $post_type = PostTypes::PRODUCT ): array {
		$ids = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$ids = array_map( 'intval', array_values( (array) pll_get_post_translations( $product_id ) ) );
		} elseif ( self::is_wpml() ) {
			$type = 'post_' . $post_type;
			$trid = apply_filters( 'wpml_element_trid', null, $product_id, $type );
			if ( $trid ) {
				foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $translation ) {
					if ( is_object( $translation ) && ! empty( $translation->element_id ) ) {
						$ids[] = (int) $translation->element_id;
					}
				}
			}
		}
		return array_values( array_diff( array_unique( array_filter( $ids ) ), array( $product_id ) ) );
	}

	/**
	 * Home URL in the language matching a WordPress locale (used in emails).
	 */
	public static function localized_home( string $locale ): string {
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' ) ) {
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$index   = array_search( $locale, $locales, true );
			if ( false !== $index && isset( $slugs[ $index ] ) ) {
				return (string) pll_home_url( $slugs[ $index ] );
			}
		}
		if ( self::is_wpml() ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			foreach ( (array) $languages as $code => $language ) {
				if ( is_array( $language ) && ( $language['default_locale'] ?? '' ) === $locale ) {
					return (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code, true );
				}
			}
		}
		return home_url( '/' );
	}

	/**
	 * Whether a dedicated SEO plugin outputs titles, descriptions and Open Graph tags.
	 */
	public static function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| class_exists( 'RankMath' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' )
			|| function_exists( 'the_seo_framework' )
			|| defined( 'SLIM_SEO_VER' )
			|| class_exists( '\SlimSEO\Plugin' );

		/**
		 * Filters whether an SEO plugin handles meta tags.
		 */
		return (bool) apply_filters( 'wahou_seo_plugin_active', $active );
	}
}
