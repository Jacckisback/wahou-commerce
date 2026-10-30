<?php
/**
 * Product model (read side + stock writes).
 *
 * Prices are integers in minor units, entered including or excluding tax
 * according to the "prices_include_tax" setting.
 *
 * Meta keys (all prefixed with _wahou_):
 *   kind (physical|digital|service), type (simple|variable|bundle),
 *   price, sale_price, sale_from, sale_to (unix), sku, gtin, mpn, brand,
 *   manage_stock, stock, backorders, low_stock, weight (kg), length, width, height (cm),
 *   tax_class, attributes, variants, bundle_items, bundle_discount,
 *   files, download_limit, download_expiry, upsells, cross_sells, fbt, fbt_discount,
 *   faq, highlights, gallery, badge, sold_individually, seo_title, seo_description,
 *   price_history, rating_avg, rating_count, total_sales.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Products;

use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Product {

	public const META_PREFIX = '_wahou_';

	public const KINDS = array( 'physical', 'digital', 'service' );
	public const TYPES = array( 'simple', 'variable', 'bundle' );

	private \WP_Post $post;

	/** @var array<string, mixed> */
	private array $meta = array();

	/** @var array<int, Product> */
	private static array $cache = array();

	private function __construct( \WP_Post $post ) {
		$this->post = $post;
		$raw        = get_post_meta( $post->ID );
		foreach ( (array) $raw as $key => $values ) {
			if ( 0 === strpos( (string) $key, self::META_PREFIX ) ) {
				$this->meta[ substr( (string) $key, strlen( self::META_PREFIX ) ) ] = maybe_unserialize( $values[0] ?? '' );
			}
		}
	}

	/**
	 * @param int|\WP_Post|null $product
	 */
	public static function find( $product ): ?self {
		$post = $product instanceof \WP_Post ? $product : get_post( (int) $product );
		if ( ! $post || PostTypes::PRODUCT !== $post->post_type ) {
			return null;
		}
		if ( ! isset( self::$cache[ $post->ID ] ) ) {
			self::$cache[ $post->ID ] = new self( $post );
		}
		return self::$cache[ $post->ID ];
	}

	public static function flush( int $id ): void {
		unset( self::$cache[ $id ] );
		clean_post_cache( $id );
	}

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	public function meta( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->meta ) && '' !== $this->meta[ $key ] ? $this->meta[ $key ] : $fallback;
	}

	/* ------------------------------------------------------------------
	 * Identity.
	 * ------------------------------------------------------------------ */

	public function id(): int {
		return (int) $this->post->ID;
	}

	public function post(): \WP_Post {
		return $this->post;
	}

	public function name(): string {
		return get_the_title( $this->post );
	}

	public function slug(): string {
		return $this->post->post_name;
	}

	public function permalink(): string {
		return (string) get_permalink( $this->post );
	}

	public function status(): string {
		return $this->post->post_status;
	}

	public function is_purchasable(): bool {
		$purchasable = 'publish' === $this->status() || current_user_can( 'edit_post', $this->id() );
		return (bool) apply_filters( 'wahou_product_is_purchasable', $purchasable, $this );
	}

	public function kind(): string {
		$kind = (string) $this->meta( 'kind', 'physical' );
		return in_array( $kind, self::KINDS, true ) ? $kind : 'physical';
	}

	public function type(): string {
		$type = (string) $this->meta( 'type', 'simple' );
		if ( 'simple' === $type && ! empty( $this->variants() ) ) {
			$type = 'variable';
		}
		return in_array( $type, self::TYPES, true ) ? $type : 'simple';
	}

	public function is_digital(): bool {
		return 'digital' === $this->kind();
	}

	public function needs_shipping(): bool {
		if ( 'bundle' === $this->type() ) {
			foreach ( $this->bundle_items() as $item ) {
				$component = self::find( $item['product_id'] );
				if ( $component && $component->needs_shipping() ) {
					return true;
				}
			}
			return 'physical' === $this->kind();
		}
		return 'physical' === $this->kind();
	}

	public function has_files(): bool {
		return ! empty( $this->files() );
	}

	public function sku( ?string $variant_id = null ): string {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && ! empty( $variant['sku'] ) ) {
			return (string) $variant['sku'];
		}
		return (string) $this->meta( 'sku', '' );
	}

	public function gtin(): string {
		return (string) $this->meta( 'gtin', '' );
	}

	public function mpn(): string {
		return (string) $this->meta( 'mpn', '' );
	}

	public function brand(): string {
		$brand = (string) $this->meta( 'brand', '' );
		return '' !== $brand ? $brand : ( Settings::str( 'seo_brand' ) ?: Settings::store_name() );
	}

	public function badge(): string {
		return (string) $this->meta( 'badge', '' );
	}

	public function short_description(): string {
		return has_excerpt( $this->post ) ? (string) $this->post->post_excerpt : '';
	}

	public function description(): string {
		return (string) $this->post->post_content;
	}

	/**
	 * Plain text summary for feeds, llms.txt and meta descriptions.
	 */
	public function summary( int $words = 40 ): string {
		$text = $this->short_description();
		if ( '' === $text ) {
			$text = $this->description();
		}
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $text ) ) );
		return wp_trim_words( trim( preg_replace( '/\s+/', ' ', $text ) ?? '' ), $words, '…' );
	}

	/* ------------------------------------------------------------------
	 * Pricing.
	 * ------------------------------------------------------------------ */

	public function regular_price( ?string $variant_id = null ): int {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && isset( $variant['price'] ) && '' !== $variant['price'] && null !== $variant['price'] ) {
			return (int) $variant['price'];
		}
		if ( 'bundle' === $this->type() && 'discount' === $this->bundle_pricing() ) {
			return $this->bundle_components_total();
		}
		return (int) $this->meta( 'price', 0 );
	}

	public function sale_price( ?string $variant_id = null ): ?int {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && isset( $variant['sale_price'] ) && '' !== $variant['sale_price'] && null !== $variant['sale_price'] ) {
			return (int) $variant['sale_price'];
		}
		if ( 'bundle' === $this->type() && 'discount' === $this->bundle_pricing() ) {
			$discount = (float) $this->meta( 'bundle_discount', 0 );
			return $discount > 0 ? (int) round( $this->bundle_components_total() * ( 1 - $discount / 100 ) ) : null;
		}
		$sale = $this->meta( 'sale_price', '' );
		return '' === $sale || null === $sale ? null : (int) $sale;
	}

	public function is_on_sale( ?string $variant_id = null ): bool {
		$sale = $this->sale_price( $variant_id );
		if ( null === $sale || $sale >= $this->regular_price( $variant_id ) ) {
			return false;
		}
		$now  = time();
		$from = (int) $this->meta( 'sale_from', 0 );
		$to   = (int) $this->meta( 'sale_to', 0 );
		if ( $from && $now < $from ) {
			return false;
		}
		if ( $to && $now > $to ) {
			return false;
		}
		return true;
	}

	/**
	 * Active unit price (minor units, as entered by the merchant).
	 */
	public function price( ?string $variant_id = null ): int {
		$price = $this->is_on_sale( $variant_id ) ? (int) $this->sale_price( $variant_id ) : $this->regular_price( $variant_id );
		/**
		 * Filters the active price of a product.
		 */
		return (int) apply_filters( 'wahou_product_price', $price, $this, $variant_id );
	}

	/**
	 * Lowest and highest active price across variants.
	 *
	 * @return array{0:int,1:int}
	 */
	public function price_range(): array {
		$variants = $this->variants();
		if ( empty( $variants ) ) {
			$p = $this->price();
			return array( $p, $p );
		}
		$prices = array_map( fn( $v ) => $this->price( (string) $v['id'] ), $variants );
		return array( (int) min( $prices ), (int) max( $prices ) );
	}

	public function sale_ends_at(): int {
		return (int) $this->meta( 'sale_to', 0 );
	}

	/**
	 * Omnibus (EU Directive 2019/2161): lowest price applied during the 30 days
	 * before the current price reduction. Null when not on sale.
	 */
	public function reference_price( ?string $variant_id = null ): ?int {
		if ( ! $this->is_on_sale( $variant_id ) ) {
			return null;
		}
		return PriceHistory::reference_price( $this, $variant_id );
	}

	public function tax_class(): string {
		$class = (string) $this->meta( 'tax_class', 'standard' );
		return '' !== $class ? $class : 'standard';
	}

	/* ------------------------------------------------------------------
	 * Variants & attributes.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, array{name:string, values:string[]}>
	 */
	public function attributes(): array {
		$attributes = $this->meta( 'attributes', array() );
		return is_array( $attributes ) ? array_values( $attributes ) : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function variants(): array {
		$variants = $this->meta( 'variants', array() );
		return is_array( $variants ) ? array_values( array_filter( $variants, static fn( $v ) => is_array( $v ) && ! empty( $v['id'] ) ) ) : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function variant( string $variant_id ): ?array {
		foreach ( $this->variants() as $variant ) {
			if ( (string) $variant['id'] === $variant_id ) {
				return $variant;
			}
		}
		return null;
	}

	public function variant_label( string $variant_id ): string {
		$variant = $this->variant( $variant_id );
		if ( ! $variant || empty( $variant['attributes'] ) || ! is_array( $variant['attributes'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $variant['attributes'] as $name => $value ) {
			$parts[] = $name . ': ' . $value;
		}
		return implode( ', ', $parts );
	}

	/* ------------------------------------------------------------------
	 * Stock.
	 * ------------------------------------------------------------------ */

	public function manages_stock( ?string $variant_id = null ): bool {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && isset( $variant['stock'] ) && '' !== $variant['stock'] && null !== $variant['stock'] ) {
			return true;
		}
		return (bool) $this->meta( 'manage_stock', false );
	}

	public function stock( ?string $variant_id = null ): ?int {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && isset( $variant['stock'] ) && '' !== $variant['stock'] && null !== $variant['stock'] ) {
			return (int) $variant['stock'];
		}
		if ( ! $this->meta( 'manage_stock', false ) ) {
			return null;
		}
		return (int) $this->meta( 'stock', 0 );
	}

	public function backorders(): bool {
		return (bool) $this->meta( 'backorders', false );
	}

	public function low_stock_threshold(): int {
		$own = $this->meta( 'low_stock', '' );
		return '' === $own || null === $own ? Settings::int( 'low_stock_threshold' ) : (int) $own;
	}

	public function in_stock( ?string $variant_id = null, int $qty = 1 ): bool {
		if ( 'bundle' === $this->type() ) {
			foreach ( $this->bundle_items() as $item ) {
				$component = self::find( $item['product_id'] );
				if ( ! $component || ! $component->in_stock( null, $qty * max( 1, (int) $item['qty'] ) ) ) {
					return false;
				}
			}
		}
		if ( ! $this->manages_stock( $variant_id ) || $this->backorders() ) {
			return true;
		}
		return (int) $this->stock( $variant_id ) >= $qty;
	}

	/**
	 * "in_stock" | "low_stock" | "out_of_stock" | "backorder"
	 */
	public function stock_status( ?string $variant_id = null ): string {
		if ( ! $this->in_stock( $variant_id ) ) {
			return 'out_of_stock';
		}
		if ( ! $this->manages_stock( $variant_id ) ) {
			return 'in_stock';
		}
		$stock = (int) $this->stock( $variant_id );
		if ( $stock <= 0 && $this->backorders() ) {
			return 'backorder';
		}
		return $stock <= $this->low_stock_threshold() ? 'low_stock' : 'in_stock';
	}

	public function sold_individually(): bool {
		return (bool) $this->meta( 'sold_individually', $this->is_digital() );
	}

	/**
	 * Maximum quantity a customer can put in the cart (null = unlimited).
	 */
	public function max_qty( ?string $variant_id = null ): ?int {
		if ( $this->sold_individually() ) {
			return 1;
		}
		if ( $this->manages_stock( $variant_id ) && ! $this->backorders() ) {
			return max( 0, (int) $this->stock( $variant_id ) );
		}
		return null;
	}

	/**
	 * Atomically adjust stock by $delta (negative to reduce). Returns the new stock.
	 */
	public function adjust_stock( int $delta, ?string $variant_id = null ): ?int {
		global $wpdb;

		if ( ! $this->manages_stock( $variant_id ) || 0 === $delta ) {
			return $this->stock( $variant_id );
		}

		$new     = null;
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && isset( $variant['stock'] ) && '' !== $variant['stock'] && null !== $variant['stock'] ) {
			$variants = $this->variants();
			foreach ( $variants as &$v ) {
				if ( (string) $v['id'] === $variant_id ) {
					$v['stock'] = (int) $v['stock'] + $delta;
					$new        = (int) $v['stock'];
				}
			}
			unset( $v );
			update_post_meta( $this->id(), self::META_PREFIX . 'variants', $variants );
		} else {
			$key = self::META_PREFIX . 'stock';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = %s", $delta, $this->id(), $key ) );
			if ( ! $updated ) {
				update_post_meta( $this->id(), $key, (int) $this->stock() + $delta );
			}
			wp_cache_delete( $this->id(), 'post_meta' );
			$new = (int) get_post_meta( $this->id(), $key, true );
		}

		$id = $this->id();
		self::flush( $id );
		$fresh = self::find( $id );

		/**
		 * Fires after a product stock changed.
		 *
		 * @param int         $product_id Product ID.
		 * @param int         $stock      New stock.
		 * @param string|null $variant_id Variant ID.
		 * @param int         $delta      Change applied.
		 */
		do_action( 'wahou_stock_changed', $id, $new ?? 0, $variant_id, $delta );

		if ( $fresh && $delta < 0 && ( $new ?? 0 ) <= $fresh->low_stock_threshold() ) {
			do_action( 'wahou_low_stock', $id, $new ?? 0, $variant_id );
		}
		return $new ?? null;
	}

	/* ------------------------------------------------------------------
	 * Bundles.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, array{product_id:int, qty:int}>
	 */
	public function bundle_items(): array {
		$items = $this->meta( 'bundle_items', array() );
		if ( ! is_array( $items ) ) {
			return array();
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['product_id'] ) && (int) $item['product_id'] !== $this->id() ) {
				$out[] = array(
					'product_id' => (int) $item['product_id'],
					'qty'        => max( 1, (int) ( $item['qty'] ?? 1 ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * "fixed" (bundle price field) or "discount" (% off the sum of components).
	 */
	public function bundle_pricing(): string {
		return 'discount' === $this->meta( 'bundle_pricing', 'fixed' ) ? 'discount' : 'fixed';
	}

	public function bundle_components_total(): int {
		$total = 0;
		foreach ( $this->bundle_items() as $item ) {
			$component = self::find( $item['product_id'] );
			if ( $component && 'bundle' !== $component->type() ) {
				$total += $component->price() * $item['qty'];
			}
		}
		return $total;
	}

	/* ------------------------------------------------------------------
	 * Digital delivery.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, array{id:string, name:string, path?:string, url?:string}>
	 */
	public function files(): array {
		$files = $this->meta( 'files', array() );
		return is_array( $files ) ? array_values( array_filter( $files, static fn( $f ) => is_array( $f ) && ! empty( $f['id'] ) ) ) : array();
	}

	public function download_limit(): int {
		return max( 0, (int) $this->meta( 'download_limit', 0 ) );
	}

	public function download_expiry_days(): int {
		return max( 0, (int) $this->meta( 'download_expiry', 0 ) );
	}

	/* ------------------------------------------------------------------
	 * Merchandising.
	 * ------------------------------------------------------------------ */

	/**
	 * @return int[]
	 */
	private function ids( string $key ): array {
		$ids = $this->meta( $key, array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 && $id !== $this->id() ) ) : array();
	}

	/** @return int[] */
	public function upsell_ids(): array {
		return $this->ids( 'upsells' );
	}

	/** @return int[] */
	public function cross_sell_ids(): array {
		return $this->ids( 'cross_sells' );
	}

	/** @return int[] Frequently bought together. */
	public function fbt_ids(): array {
		return $this->ids( 'fbt' );
	}

	public function fbt_discount(): float {
		return max( 0.0, min( 90.0, (float) $this->meta( 'fbt_discount', 0 ) ) );
	}

	/**
	 * @return array<int, array{q:string, a:string}>
	 */
	public function faq(): array {
		$faq = $this->meta( 'faq', array() );
		if ( ! is_array( $faq ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$faq,
				static fn( $row ) => is_array( $row ) && '' !== trim( (string) ( $row['q'] ?? '' ) ) && '' !== trim( (string) ( $row['a'] ?? '' ) )
			)
		);
	}

	/**
	 * @return string[] Short key benefits shown next to the buy button.
	 */
	public function highlights(): array {
		$items = $this->meta( 'highlights', array() );
		return is_array( $items ) ? array_values( array_filter( array_map( 'strval', $items ), static fn( $s ) => '' !== trim( $s ) ) ) : array();
	}

	public function image_id( ?string $variant_id = null ): int {
		$variant = $variant_id ? $this->variant( $variant_id ) : null;
		if ( $variant && ! empty( $variant['image_id'] ) ) {
			return (int) $variant['image_id'];
		}
		return (int) get_post_thumbnail_id( $this->post );
	}

	/**
	 * @return int[]
	 */
	public function gallery_ids(): array {
		$ids = $this->meta( 'gallery', array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * Main image followed by the gallery, without duplicates.
	 *
	 * @return int[]
	 */
	public function all_image_ids(): array {
		return array_values( array_unique( array_filter( array_merge( array( $this->image_id() ), $this->gallery_ids() ) ) ) );
	}

	public function image_url( string $size = 'large' ): string {
		$id = $this->image_id();
		if ( ! $id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $id, $size );
		return $src ? (string) $src : '';
	}

	/**
	 * Weight in the store's weight unit (Settings → weight_unit).
	 */
	public function weight(): float {
		return (float) $this->meta( 'weight', 0 );
	}

	/**
	 * Weight converted to kilograms (feeds, schema.org).
	 */
	public function weight_kg(): float {
		$factors = array(
			'kg' => 1.0,
			'g'  => 0.001,
			'lb' => 0.45359237,
			'oz' => 0.028349523125,
		);
		return $this->weight() * ( $factors[ Settings::str( 'weight_unit' ) ] ?? 1.0 );
	}

	/**
	 * @return array{length:float, width:float, height:float}
	 */
	public function dimensions(): array {
		return array(
			'length' => (float) $this->meta( 'length', 0 ),
			'width'  => (float) $this->meta( 'width', 0 ),
			'height' => (float) $this->meta( 'height', 0 ),
		);
	}

	public function rating_average(): float {
		return round( (float) $this->meta( 'rating_avg', 0 ), 1 );
	}

	public function rating_count(): int {
		return (int) $this->meta( 'rating_count', 0 );
	}

	public function total_sales(): int {
		return (int) $this->meta( 'total_sales', 0 );
	}

	/**
	 * @return \WP_Term[]
	 */
	public function categories(): array {
		$terms = get_the_terms( $this->post, PostTypes::CATEGORY );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Data for REST, abilities and JS.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$currency = Settings::currency();
		$variants = array();
		foreach ( $this->variants() as $v ) {
			$vid        = (string) $v['id'];
			$variants[] = array(
				'id'         => $vid,
				'attributes' => is_array( $v['attributes'] ?? null ) ? $v['attributes'] : array(),
				'price'      => $this->price( $vid ),
				'regular_price' => $this->regular_price( $vid ),
				'on_sale'    => $this->is_on_sale( $vid ),
				'in_stock'   => $this->in_stock( $vid ),
				'sku'        => $this->sku( $vid ),
				'image'      => $this->image_id( $vid ) ? (string) wp_get_attachment_image_url( $this->image_id( $vid ), 'large' ) : '',
			);
		}
		return array(
			'id'            => $this->id(),
			'name'          => $this->name(),
			'url'           => $this->permalink(),
			'kind'          => $this->kind(),
			'type'          => $this->type(),
			'sku'           => $this->sku(),
			'gtin'          => $this->gtin(),
			'brand'         => $this->brand(),
			'currency'      => $currency,
			'price'         => $this->price(),
			'regular_price' => $this->regular_price(),
			'on_sale'       => $this->is_on_sale(),
			'reference_price' => $this->reference_price(),
			'price_html'    => \WahouCommerce\Support\Money::format( $this->price(), $currency ),
			'prices_include_tax' => Settings::bool( 'prices_include_tax' ),
			'in_stock'      => $this->in_stock(),
			'stock_status'  => $this->stock_status(),
			'summary'       => $this->summary(),
			'highlights'    => $this->highlights(),
			'image'         => $this->image_url(),
			'rating'        => array(
				'average' => $this->rating_average(),
				'count'   => $this->rating_count(),
			),
			'categories'    => array_map( static fn( $t ) => $t->name, $this->categories() ),
			'attributes'    => $this->attributes(),
			'variants'      => $variants,
			'needs_shipping' => $this->needs_shipping(),
		);
	}
}
