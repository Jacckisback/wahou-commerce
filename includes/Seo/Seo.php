<?php
/**
 * SEO & GEO module bootstrap and shared helpers.
 *
 *  - Schema: JSON-LD (Product/ProductGroup, offers, returns, shipping, reviews,
 *    breadcrumbs, FAQ, item lists, store).
 *  - Meta: description, Open Graph and Twitter tags (steps aside for SEO plugins).
 *  - Geo: llms.txt, llms-full.txt, Markdown product pages, robots rules.
 *  - Feed: Google Merchant Center XML feed.
 *  - Abilities: WordPress Abilities API (6.9+) so AI agents can query the catalog.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** Bump when rewrite rules change. */
	private const RULES_VERSION = '1';

	public static function init(): void {
		Schema::init();
		Meta::init();
		Geo::init();
		Feed::init();
		Abilities::init();

		add_action( 'init', array( self::class, 'rewrites' ), 11 );

		// Invalidate cached catalog documents (feed, llms.txt).
		add_action( 'save_post_' . PostTypes::PRODUCT, array( self::class, 'bump_catalog_version' ) );
		add_action( 'deleted_post', array( self::class, 'maybe_bump_on_delete' ), 10, 2 );
		add_action( 'wahou_stock_changed', array( self::class, 'bump_catalog_version' ) );
		add_action( 'wahou_settings_updated', array( self::class, 'bump_catalog_version' ) );
		add_action( 'edited_' . PostTypes::CATEGORY, array( self::class, 'bump_catalog_version' ) );
		add_action( 'created_' . PostTypes::CATEGORY, array( self::class, 'bump_catalog_version' ) );
		add_action( 'delete_' . PostTypes::CATEGORY, array( self::class, 'bump_catalog_version' ) );
	}

	/**
	 * Register rewrite rules for llms.txt, Markdown pages and the feed, and ask
	 * for a rewrite flush when they changed.
	 */
	public static function rewrites(): void {
		$base = PostTypes::product_base();
		add_rewrite_rule( '^llms\.txt$', 'index.php?wahou_geo=llms', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?wahou_geo=llms-full', 'top' );
		add_rewrite_rule( '^' . $base . '/([^/]+)\.md$', 'index.php?wahou_geo=md&wahou_md=$matches[1]', 'top' );
		add_rewrite_rule( '^wahou-feed/google\.xml$', 'index.php?wahou_feed=google', 'top' );

		$signature = md5( self::RULES_VERSION . '|' . $base );
		if ( get_option( 'wahou_seo_rules' ) !== $signature ) {
			update_option( 'wahou_seo_rules', $signature, true );
			update_option( 'wahou_flush_rewrite', 1, true );
		}
	}

	public static function catalog_version(): string {
		$version = get_option( 'wahou_catalog_version' );
		if ( ! $version ) {
			$version = (string) time();
			update_option( 'wahou_catalog_version', $version, true );
		}
		return (string) $version;
	}

	public static function bump_catalog_version(): void {
		static $bumped = false;
		if ( $bumped ) {
			return;
		}
		$bumped = true;
		update_option( 'wahou_catalog_version', (string) microtime( true ), true );
	}

	/**
	 * @param int           $post_id
	 * @param \WP_Post|null $post
	 */
	public static function maybe_bump_on_delete( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && PostTypes::PRODUCT === $post->post_type ) {
			self::bump_catalog_version();
		}
	}

	/**
	 * Consumer price (tax inclusive in the store country) for an entered price.
	 */
	public static function gross_price( Product $product, int $entered ): int {
		if ( ! Settings::bool( 'tax_enabled' ) || Settings::bool( 'tax_exempt' ) || Settings::bool( 'prices_include_tax' ) ) {
			return $entered;
		}
		$rate = Tax::rate( Settings::store_country(), $product->tax_class() );
		return $entered + Tax::from_net( $entered, $rate );
	}

	/**
	 * Plain text from post content (blocks, shortcodes and tags removed).
	 */
	public static function plain_text( string $html, int $words = 0 ): string {
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $html ) ) );
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ?? '' );
		return $words > 0 ? wp_trim_words( $text, $words, '…' ) : $text;
	}

	/**
	 * Human availability label.
	 */
	public static function availability_label( Product $product, ?string $variant_id = null ): string {
		switch ( $product->stock_status( $variant_id ) ) {
			case 'out_of_stock':
				return __( 'Out of stock', 'wahou-commerce' );
			case 'backorder':
				return __( 'Available on backorder', 'wahou-commerce' );
			case 'low_stock':
				$stock = (int) $product->stock( $variant_id );
				/* translators: %d: units left. */
				return sprintf( _n( 'In stock (only %d left)', 'In stock (only %d left)', $stock, 'wahou-commerce' ), $stock );
		}
		return $product->is_digital() ? __( 'Available – instant download', 'wahou-commerce' ) : __( 'In stock', 'wahou-commerce' );
	}

	/**
	 * Map attribute names to schema.org / Merchant Center properties (color, size...).
	 */
	public static function attribute_property( string $name ): string {
		$key = strtolower( remove_accents( trim( $name ) ) );
		$map = array(
			'color'    => array( 'color', 'colour', 'couleur', 'farbe', 'colore', 'cor', 'kleur' ),
			'size'     => array( 'size', 'taille', 'talla', 'grosse', 'groesse', 'taglia', 'tamanho', 'maat', 'pointure' ),
			'material' => array( 'material', 'matiere', 'materiau', 'materiale', 'materiaal', 'stoff' ),
			'pattern'  => array( 'pattern', 'motif', 'estampado', 'muster', 'motivo', 'padrao' ),
		);
		foreach ( $map as $property => $names ) {
			if ( in_array( $key, $names, true ) ) {
				return $property;
			}
		}
		return '';
	}
}
