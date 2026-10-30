<?php
/**
 * Coupons (custom post type wahou_coupon, code = post title).
 *
 * Meta keys: _wahou_coupon_type (percent|fixed|free_shipping),
 * _wahou_coupon_amount (percent as float, or minor units for "fixed"; fixed
 * amounts are tax inclusive like displayed prices), _wahou_coupon_min (minor),
 * _wahou_coupon_expires (unix), _wahou_coupon_limit, _wahou_coupon_used,
 * _wahou_coupon_per_email, _wahou_coupon_products (int[]),
 * _wahou_coupon_email (restricted to one email), _wahou_coupon_auto (bool),
 * _wahou_coupon_used_by (sha256 of lowercase emails).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Coupons;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class Coupons {

	public static function normalize( string $code ): string {
		return strtoupper( trim( preg_replace( '/\s+/', '', $code ) ?? '' ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $code ): ?array {
		global $wpdb;
		$code = self::normalize( $code );
		if ( '' === $code || strlen( $code ) > 64 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s ORDER BY ID DESC LIMIT 1", PostTypes::COUPON, $code ) );
		return $id ? self::get( $id ) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || PostTypes::COUPON !== $post->post_type ) {
			return null;
		}
		$m = static fn( string $k, $d = '' ) => ( '' !== ( $v = get_post_meta( $id, '_wahou_coupon_' . $k, true ) ) ) ? $v : $d;
		$type = (string) $m( 'type', 'percent' );
		return array(
			'id'          => $id,
			'code'        => self::normalize( $post->post_title ),
			'type'        => in_array( $type, array( 'percent', 'fixed', 'free_shipping' ), true ) ? $type : 'percent',
			'amount'      => 'fixed' === $type ? (int) $m( 'amount', 0 ) : (float) $m( 'amount', 0 ),
			'min'         => (int) $m( 'min', 0 ),
			'expires'     => (int) $m( 'expires', 0 ),
			'limit'       => (int) $m( 'limit', 0 ),
			'used'        => (int) $m( 'used', 0 ),
			'per_email'   => (int) $m( 'per_email', 0 ),
			'products'    => array_values( array_filter( array_map( 'intval', (array) $m( 'products', array() ) ) ) ),
			'email'       => strtolower( (string) $m( 'email', '' ) ),
			'auto'        => (bool) $m( 'auto', false ),
			'free_shipping' => 'free_shipping' === $type || (bool) $m( 'free_shipping', false ),
			'status'      => $post->post_status,
		);
	}

	/**
	 * @param array<string, mixed> $coupon
	 * @param array<string, mixed> $ctx    subtotal (gross, minor), email, product_ids (int[])
	 * @return true|\WP_Error
	 */
	public static function validate( array $coupon, array $ctx ) {
		if ( 'publish' !== ( $coupon['status'] ?? '' ) ) {
			return new \WP_Error( 'wahou_coupon_invalid', __( 'This coupon is not valid.', 'wahou-commerce' ) );
		}
		if ( $coupon['expires'] && time() > $coupon['expires'] ) {
			return new \WP_Error( 'wahou_coupon_expired', __( 'This coupon has expired.', 'wahou-commerce' ) );
		}
		if ( $coupon['limit'] && $coupon['used'] >= $coupon['limit'] ) {
			return new \WP_Error( 'wahou_coupon_limit', __( 'This coupon has reached its usage limit.', 'wahou-commerce' ) );
		}
		$email = strtolower( (string) ( $ctx['email'] ?? '' ) );
		if ( '' !== $coupon['email'] && '' !== $email && $coupon['email'] !== $email ) {
			return new \WP_Error( 'wahou_coupon_email', __( 'This coupon is reserved for another email address.', 'wahou-commerce' ) );
		}
		if ( $coupon['per_email'] && '' !== $email ) {
			$used_by = (array) get_post_meta( (int) $coupon['id'], '_wahou_coupon_used_by', true );
			$count   = count( array_keys( $used_by, hash( 'sha256', $email ), true ) );
			if ( $count >= $coupon['per_email'] ) {
				return new \WP_Error( 'wahou_coupon_used', __( 'You have already used this coupon.', 'wahou-commerce' ) );
			}
		}
		if ( $coupon['min'] && (int) ( $ctx['subtotal'] ?? 0 ) < $coupon['min'] ) {
			return new \WP_Error(
				'wahou_coupon_min',
				sprintf(
					/* translators: %s: minimum amount. */
					__( 'This coupon requires a minimum order of %s.', 'wahou-commerce' ),
					\WahouCommerce\Support\Money::format( (int) $coupon['min'] )
				)
			);
		}
		if ( ! empty( $coupon['products'] ) && empty( array_intersect( $coupon['products'], (array) ( $ctx['product_ids'] ?? array() ) ) ) ) {
			return new \WP_Error( 'wahou_coupon_products', __( 'This coupon does not apply to the products in your cart.', 'wahou-commerce' ) );
		}
		return (bool) apply_filters( 'wahou_coupon_is_valid', true, $coupon, $ctx ) ? true : new \WP_Error( 'wahou_coupon_invalid', __( 'This coupon is not valid.', 'wahou-commerce' ) );
	}

	/**
	 * Distribute the coupon discount across eligible lines (gross, minor units).
	 *
	 * @param array<string, mixed>              $coupon
	 * @param array<string, array<string,mixed>> $lines key => [gross:int, product_id:int]
	 * @return array<string, int> key => discount
	 */
	public static function allocate( array $coupon, array $lines ): array {
		$eligible = array();
		foreach ( $lines as $key => $line ) {
			if ( empty( $coupon['products'] ) || in_array( (int) $line['product_id'], $coupon['products'], true ) ) {
				if ( (int) $line['gross'] > 0 ) {
					$eligible[ $key ] = (int) $line['gross'];
				}
			}
		}
		$out = array_fill_keys( array_keys( $lines ), 0 );
		if ( empty( $eligible ) || 'free_shipping' === $coupon['type'] ) {
			return $out;
		}
		$base = array_sum( $eligible );
		if ( 'percent' === $coupon['type'] ) {
			$pct = max( 0.0, min( 100.0, (float) $coupon['amount'] ) );
			foreach ( $eligible as $key => $gross ) {
				$out[ $key ] = (int) round( $gross * $pct / 100 );
			}
			return $out;
		}
		$amount    = min( $base, max( 0, (int) $coupon['amount'] ) );
		$remaining = $amount;
		$keys      = array_keys( $eligible );
		$last      = end( $keys );
		foreach ( $eligible as $key => $gross ) {
			$share       = $key === $last ? $remaining : (int) floor( $amount * $gross / $base );
			$share       = min( $share, $gross );
			$out[ $key ] = $share;
			$remaining  -= $share;
		}
		return $out;
	}

	public static function record_usage( string $code, string $email ): void {
		$coupon = self::find( $code );
		if ( ! $coupon ) {
			return;
		}
		update_post_meta( (int) $coupon['id'], '_wahou_coupon_used', (int) $coupon['used'] + 1 );
		if ( '' !== $email ) {
			$used_by   = (array) get_post_meta( (int) $coupon['id'], '_wahou_coupon_used_by', true );
			$used_by[] = hash( 'sha256', strtolower( $email ) );
			update_post_meta( (int) $coupon['id'], '_wahou_coupon_used_by', array_slice( array_values( array_filter( $used_by ) ), -5000 ) );
		}
	}

	/**
	 * Create a single-use personal coupon (used by abandoned cart emails).
	 */
	public static function create_auto( string $email, float $percent, int $days, string $prefix = 'WAHOU' ): string {
		$code = self::normalize( $prefix . '-' . strtoupper( substr( Crypto::key_string( 10 ), 0, 6 ) ) );
		$id   = wp_insert_post(
			array(
				'post_type'   => PostTypes::COUPON,
				'post_status' => 'publish',
				'post_title'  => $code,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return '';
		}
		update_post_meta( $id, '_wahou_coupon_type', 'percent' );
		update_post_meta( $id, '_wahou_coupon_amount', $percent );
		update_post_meta( $id, '_wahou_coupon_limit', 1 );
		update_post_meta( $id, '_wahou_coupon_per_email', 1 );
		update_post_meta( $id, '_wahou_coupon_email', strtolower( $email ) );
		update_post_meta( $id, '_wahou_coupon_auto', 1 );
		if ( $days > 0 ) {
			update_post_meta( $id, '_wahou_coupon_expires', time() + $days * DAY_IN_SECONDS );
		}
		return $code;
	}

	/**
	 * Delete expired automatic coupons (daily).
	 */
	public static function cleanup(): void {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::COUPON,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wahou_coupon_auto',
						'value' => '1',
					),
					array(
						'key'     => '_wahou_coupon_expires',
						'value'   => time() - 7 * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
