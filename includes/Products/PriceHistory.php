<?php
/**
 * Price history for the EU "Omnibus" rule (Directive 98/6/EC art. 6a, as
 * amended by Directive (EU) 2019/2161): any announced price reduction must
 * show the lowest price applied during the 30 days before the reduction.
 *
 * History is stored per product in _wahou_price_history as a list of
 * [timestamp, price, variant_id] entries, recorded whenever the active price
 * changes (on save and daily for scheduled sales). Entries older than 120
 * days are pruned.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Products;

defined( 'ABSPATH' ) || exit;

final class PriceHistory {

	private const KEY  = '_wahou_price_history';
	private const KEEP = 120 * DAY_IN_SECONDS;

	public static function init(): void {
		add_action( 'save_post_' . PostTypes::PRODUCT, array( self::class, 'on_save' ), 99, 1 );
		add_action( 'wahou_cron_daily', array( self::class, 'daily' ) );
	}

	public static function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		Product::flush( $post_id );
		$product = Product::find( $post_id );
		if ( $product ) {
			self::record( $product );
		}
	}

	/**
	 * Record the current active price of the product and its variants when it changed.
	 */
	public static function record( Product $product ): void {
		$history = self::get( $product->id() );
		$changed = false;
		$now     = time();

		$targets = array( '' );
		foreach ( $product->variants() as $variant ) {
			$targets[] = (string) $variant['id'];
		}
		foreach ( $targets as $vid ) {
			$price = $product->price( '' === $vid ? null : $vid );
			$last  = self::last_for( $history, $vid );
			if ( null === $last || (int) $last[1] !== $price ) {
				$history[] = array( $now, $price, $vid );
				$changed   = true;
			}
		}

		$history = array_values( array_filter( $history, static fn( $e ) => (int) $e[0] >= $now - self::KEEP ) );
		// Always keep at least the latest entry of each target even if old.
		foreach ( $targets as $vid ) {
			if ( null === self::last_for( $history, $vid ) ) {
				$history[] = array( $now, $product->price( '' === $vid ? null : $vid ), $vid );
				$changed   = true;
			}
		}
		if ( $changed ) {
			update_post_meta( $product->id(), self::KEY, $history );
		}
		// Sortable "current price" (sale price when on sale, lowest variant price).
		$active = $product->variants() ? $product->price_range()[0] : $product->price();
		if ( (int) get_post_meta( $product->id(), '_wahou_active_price', true ) !== $active || '' === get_post_meta( $product->id(), '_wahou_active_price', true ) ) {
			update_post_meta( $product->id(), '_wahou_active_price', $active );
		}
	}

	/**
	 * @return array<int, array{0:int,1:int,2:string}>
	 */
	public static function get( int $product_id ): array {
		$history = get_post_meta( $product_id, self::KEY, true );
		if ( ! is_array( $history ) ) {
			return array();
		}
		$out = array();
		foreach ( $history as $entry ) {
			if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) ) {
				$out[] = array( (int) $entry[0], (int) $entry[1], (string) ( $entry[2] ?? '' ) );
			}
		}
		usort( $out, static fn( $a, $b ) => $a[0] <=> $b[0] );
		return $out;
	}

	/**
	 * Lowest price over the 30 days preceding the start of the current reduction.
	 * Falls back to the regular price when there is no history.
	 */
	public static function reference_price( Product $product, ?string $variant_id = null ): ?int {
		$vid     = (string) $variant_id;
		$current = $product->price( $variant_id );
		$entries = array_values( array_filter( self::get( $product->id() ), static fn( $e ) => $e[2] === $vid ) );

		// Start of the current price period.
		$start = (int) $product->meta( 'sale_from', 0 );
		if ( ! $start ) {
			$start = time();
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				if ( $entries[ $i ][1] !== $current ) {
					break;
				}
				$start = $entries[ $i ][0];
			}
		}
		$window_start = $start - 30 * DAY_IN_SECONDS;

		$prices = array();
		$before = null;
		foreach ( $entries as $entry ) {
			if ( $entry[0] <= $window_start ) {
				$before = $entry[1];
			} elseif ( $entry[0] < $start ) {
				$prices[] = $entry[1];
			}
		}
		if ( null !== $before ) {
			$prices[] = $before;
		}
		$prices = array_filter( $prices, static fn( $p ) => $p > $current );
		if ( empty( $prices ) ) {
			$regular = $product->regular_price( $variant_id );
			return $regular > $current ? $regular : null;
		}
		return (int) min( $prices );
	}

	/**
	 * Scheduled sales start/stop without a save: record their price changes daily.
	 */
	public static function daily(): void {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::PRODUCT,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_wahou_sale_from',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_wahou_sale_to',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$product = Product::find( (int) $id );
			if ( $product ) {
				self::record( $product );
			}
		}
	}

	/**
	 * @param array<int, array{0:int,1:int,2:string}> $history
	 * @return array{0:int,1:int,2:string}|null
	 */
	private static function last_for( array $history, string $vid ): ?array {
		$last = null;
		foreach ( $history as $entry ) {
			if ( $entry[2] === $vid ) {
				$last = $entry;
			}
		}
		return $last;
	}
}
