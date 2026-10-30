<?php
/**
 * Shipping zones and methods.
 *
 * Zones are stored in the "shipping_zones" setting:
 *   [ { id, name, countries: ['FR'] | ['*'], methods: [ { id, type: flat|free|pickup,
 *       title, cost (minor units, same tax basis as product prices),
 *       min_amount (free shipping threshold), min_days, max_days } ] } ]
 * The first zone matching the customer country wins; '*' matches any country.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Shipping;

use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

final class Shipping {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function zones(): array {
		$zones = Settings::get( 'shipping_zones', array() );
		if ( ! is_array( $zones ) ) {
			return array();
		}
		$out = array();
		foreach ( $zones as $zone ) {
			if ( ! is_array( $zone ) || empty( $zone['id'] ) ) {
				continue;
			}
			$methods = array();
			foreach ( (array) ( $zone['methods'] ?? array() ) as $method ) {
				if ( ! is_array( $method ) || empty( $method['id'] ) ) {
					continue;
				}
				$methods[] = array(
					'id'         => sanitize_key( (string) $method['id'] ),
					'type'       => in_array( $method['type'] ?? 'flat', array( 'flat', 'free', 'pickup' ), true ) ? $method['type'] : 'flat',
					'title'      => (string) ( $method['title'] ?? '' ),
					'cost'       => max( 0, (int) ( $method['cost'] ?? 0 ) ),
					'min_amount' => max( 0, (int) ( $method['min_amount'] ?? 0 ) ),
					'min_days'   => max( 0, (int) ( $method['min_days'] ?? 0 ) ),
					'max_days'   => max( 0, (int) ( $method['max_days'] ?? 0 ) ),
					'enabled'    => ! isset( $method['enabled'] ) || ! empty( $method['enabled'] ),
				);
			}
			$out[] = array(
				'id'        => sanitize_key( (string) $zone['id'] ),
				'name'      => (string) ( $zone['name'] ?? '' ),
				'countries' => array_values( array_map( 'strtoupper', (array) ( $zone['countries'] ?? array() ) ) ),
				'methods'   => $methods,
			);
		}
		return apply_filters( 'wahou_shipping_zones', $out );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function zone_for( string $country ): ?array {
		$country = strtoupper( $country );
		$wild    = null;
		foreach ( self::zones() as $zone ) {
			if ( in_array( $country, $zone['countries'], true ) ) {
				return $zone;
			}
			if ( null === $wild && in_array( '*', $zone['countries'], true ) ) {
				$wild = $zone;
			}
		}
		return $wild;
	}

	/**
	 * Country codes the store ships to (empty array = worldwide).
	 *
	 * @return string[]
	 */
	public static function countries(): array {
		$codes = array();
		foreach ( self::zones() as $zone ) {
			if ( in_array( '*', $zone['countries'], true ) ) {
				return array();
			}
			$codes = array_merge( $codes, $zone['countries'] );
		}
		return array_values( array_unique( $codes ) );
	}

	public static function default_title( string $type ): string {
		switch ( $type ) {
			case 'free':
				return __( 'Free delivery', 'wahou-commerce' );
			case 'pickup':
				return __( 'Local pickup', 'wahou-commerce' );
		}
		return __( 'Standard delivery', 'wahou-commerce' );
	}

	/**
	 * Available options for a destination.
	 *
	 * @param int  $subtotal_gross Cart total after discounts (display basis).
	 * @param bool $free_coupon    A coupon grants free shipping.
	 * @return array<int, array<string, mixed>>
	 */
	public static function options( string $country, int $subtotal_gross, bool $free_coupon = false ): array {
		$zone = self::zone_for( $country );
		if ( ! $zone ) {
			return array();
		}
		$options    = array();
		$free_found = false;
		foreach ( $zone['methods'] as $method ) {
			if ( ! $method['enabled'] ) {
				continue;
			}
			$available = true;
			$cost      = $method['cost'];
			if ( 'free' === $method['type'] ) {
				$cost      = 0;
				$available = $free_coupon || $subtotal_gross >= $method['min_amount'];
				if ( $available ) {
					$free_found = true;
				}
			} elseif ( 'pickup' === $method['type'] ) {
				$cost = $method['cost'];
			}
			if ( ! $available ) {
				continue;
			}
			$options[] = array(
				'id'       => $zone['id'] . ':' . $method['id'],
				'type'     => $method['type'],
				'title'    => '' !== $method['title'] ? $method['title'] : self::default_title( $method['type'] ),
				'cost'     => $cost,
				'min_days' => $method['min_days'],
				'max_days' => $method['max_days'],
			);
		}
		if ( $free_found || $free_coupon ) {
			// When free delivery is unlocked, paid flat rates are pointless: hide them.
			$options = array_values( array_filter( $options, static fn( $o ) => 'flat' !== $o['type'] ) );
			if ( $free_coupon && empty( array_filter( $options, static fn( $o ) => 'free' === $o['type'] ) ) ) {
				$flat = array_values( array_filter( $zone['methods'], static fn( $m ) => 'flat' === $m['type'] && $m['enabled'] ) );
				array_unshift(
					$options,
					array(
						'id'       => $zone['id'] . ':coupon_free',
						'type'     => 'free',
						'title'    => self::default_title( 'free' ),
						'cost'     => 0,
						'min_days' => $flat[0]['min_days'] ?? 0,
						'max_days' => $flat[0]['max_days'] ?? 0,
					)
				);
			}
		}
		usort( $options, static fn( $a, $b ) => $a['cost'] <=> $b['cost'] );

		/**
		 * Filters the shipping options offered for a destination.
		 */
		return apply_filters( 'wahou_shipping_options', $options, $country, $subtotal_gross );
	}

	/**
	 * Free shipping threshold for a destination (null when none).
	 */
	public static function free_threshold( string $country ): ?int {
		$zone = self::zone_for( $country );
		if ( ! $zone ) {
			return null;
		}
		foreach ( $zone['methods'] as $method ) {
			if ( $method['enabled'] && 'free' === $method['type'] && $method['min_amount'] > 0 ) {
				return (int) $method['min_amount'];
			}
		}
		return null;
	}

	/**
	 * Delivery time range of the cheapest paid or free method for a country,
	 * used for product page estimates and schema.org shippingDetails.
	 *
	 * @return array{min:int, max:int}|null
	 */
	public static function transit_days( string $country ): ?array {
		$zone = self::zone_for( $country );
		if ( ! $zone ) {
			return null;
		}
		foreach ( $zone['methods'] as $method ) {
			if ( $method['enabled'] && 'pickup' !== $method['type'] && $method['max_days'] > 0 ) {
				return array(
					'min' => $method['min_days'],
					'max' => $method['max_days'],
				);
			}
		}
		return null;
	}

	/**
	 * Cheapest paid rate for a country (schema.org shippingRate).
	 */
	public static function base_rate( string $country ): ?int {
		$zone = self::zone_for( $country );
		if ( ! $zone ) {
			return null;
		}
		$rates = array();
		foreach ( $zone['methods'] as $method ) {
			if ( $method['enabled'] && 'flat' === $method['type'] ) {
				$rates[] = $method['cost'];
			}
		}
		return $rates ? (int) min( $rates ) : null;
	}

	public static function country_label( string $code ): string {
		return Countries::name( $code );
	}
}
