<?php
/**
 * Totals engine. Pure function of cart lines + customer context, used for
 * both carts and orders so the amount charged always equals what was shown.
 *
 * Conventions:
 *  - All amounts are integers in minor units, rounded per line.
 *  - "Display" amounts are tax inclusive (EU B2C rule: consumers see final prices).
 *  - With prices entered tax inclusive and "fixed gross" pricing, every EU
 *    consumer pays the same price; only the VAT share changes per country.
 *  - Zero-rated customers (exports, reverse charge) pay the net price: the
 *    store country VAT is removed from tax-inclusive prices.
 *  - Shipping is taxed at the highest rate among shipped lines (the main supply).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Cart;

use WahouCommerce\Coupons\Coupons;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	/**
	 * @param array<int, array<string, mixed>> $lines   Lines from Cart::lines().
	 * @param array<string, mixed>             $context country, postcode, vat_valid, email, coupon (code), shipping_method, currency.
	 * @return array<string, mixed>
	 */
	public static function calculate( array $lines, array $context ): array {
		$currency      = (string) ( $context['currency'] ?? Settings::currency() );
		$incl          = Settings::bool( 'prices_include_tax' );
		$fixed_gross   = Settings::bool( 'tax_fixed_gross' );
		$resolved      = Tax::resolve( $context );
		$mode          = $resolved['mode'];
		$store_country = Settings::store_country();
		$taxable       = Tax::mode_is_taxable( $mode );
		$remove_base   = $incl && in_array( $mode, array( 'export', 'reverse_charge' ), true );

		$out = array(
			'currency'                => $currency,
			'lines'                   => array(),
			'subtotal'                => 0,
			'discount'                => 0,
			'shipping'                => 0,
			'shipping_net'            => 0,
			'shipping_tax'            => 0,
			'shipping_rate'           => 0.0,
			'tax'                     => 0,
			'total'                   => 0,
			'tax_lines'               => array(),
			'tax_mode'                => $mode,
			'tax_country'             => $resolved['country'],
			'rate_country'            => $resolved['rate_country'],
			'prices_include_tax'      => $incl,
			'needs_shipping'          => false,
			'shipping_options'        => array(),
			'shipping_method'         => null,
			'shipping_title'          => '',
			'free_shipping_threshold' => null,
			'free_shipping_remaining' => null,
			'coupon'                  => null,
			'errors'                  => array(),
		);

		// 1. Lines before discount.
		$gross_by_key = array();
		foreach ( $lines as $line ) {
			$key       = (string) $line['key'];
			$class     = (string) ( $line['tax_class'] ?? 'standard' );
			$entered   = (int) $line['unit_price'] * max( 1, (int) $line['qty'] );
			$base_rate = Tax::rate( $store_country, $class );
			$rate      = $taxable ? Tax::rate( $resolved['rate_country'], $class ) : 0.0;

			if ( in_array( $mode, array( 'disabled', 'exempt' ), true ) ) {
				$gross = $entered;
				$rate  = 0.0;
			} elseif ( $remove_base ) {
				$gross = $entered - Tax::from_gross( $entered, $base_rate );
			} elseif ( $incl && $fixed_gross ) {
				$gross = $entered;
			} elseif ( $incl ) {
				$net   = $entered - Tax::from_gross( $entered, $base_rate );
				$gross = $net + Tax::from_net( $net, $rate );
			} else {
				$gross = $entered + Tax::from_net( $entered, $rate );
			}

			$out['lines'][ $key ] = array(
				'rate'     => $rate,
				'subtotal' => $gross,
				'unit'     => (int) round( $gross / max( 1, (int) $line['qty'] ) ),
				'discount' => 0,
				'total'    => $gross,
				'tax'      => 0,
				'net'      => $gross,
				'needs_shipping' => ! empty( $line['needs_shipping'] ),
			);
			$gross_by_key[ $key ] = array(
				'gross'      => $gross,
				'product_id' => (int) $line['product_id'],
			);
			$out['subtotal'] += $gross;
			if ( ! empty( $line['needs_shipping'] ) ) {
				$out['needs_shipping'] = true;
			}
		}

		// 2. Coupon.
		$coupon      = null;
		$free_coupon = false;
		if ( ! empty( $context['coupon'] ) ) {
			$coupon = Coupons::find( (string) $context['coupon'] );
			if ( ! $coupon ) {
				$out['errors']['coupon'] = __( 'This coupon does not exist.', 'wahou-commerce' );
			} else {
				$valid = Coupons::validate(
					$coupon,
					array(
						'subtotal'    => $out['subtotal'],
						'email'       => (string) ( $context['email'] ?? '' ),
						'product_ids' => array_map( static fn( $l ) => (int) $l['product_id'], $lines ),
					)
				);
				if ( is_wp_error( $valid ) ) {
					$out['errors']['coupon'] = $valid->get_error_message();
					$coupon                  = null;
				}
			}
		}
		if ( $coupon ) {
			$allocation  = Coupons::allocate( $coupon, $gross_by_key );
			$free_coupon = ! empty( $coupon['free_shipping'] );
			foreach ( $allocation as $key => $discount ) {
				$out['lines'][ $key ]['discount'] = $discount;
			}
			$out['coupon'] = array(
				'code'          => $coupon['code'],
				'type'          => $coupon['type'],
				'amount'        => array_sum( $allocation ),
				'free_shipping' => $free_coupon,
			);
		}

		// 3. Tax per line after discount.
		$shipping_rate      = 0.0;
		$shipping_base_rate = 0.0;
		foreach ( $lines as $line ) {
			$key   = (string) $line['key'];
			$row   = &$out['lines'][ $key ];
			$total = max( 0, $row['subtotal'] - $row['discount'] );
			$tax   = Tax::from_gross( $total, (float) $row['rate'] );

			$row['total'] = $total;
			$row['tax']   = $tax;
			$row['net']   = $total - $tax;

			$out['discount'] += $row['discount'];
			if ( $row['needs_shipping'] ) {
				$shipping_rate      = max( $shipping_rate, (float) $row['rate'] );
				$shipping_base_rate = max( $shipping_base_rate, Tax::rate( $store_country, (string) ( $line['tax_class'] ?? 'standard' ) ) );
			}
			self::add_tax_line( $out['tax_lines'], (float) $row['rate'], $row['net'], $tax );
			unset( $row );
		}

		$items_total = $out['subtotal'] - $out['discount'];

		// 4. Shipping.
		if ( $out['needs_shipping'] ) {
			$country                  = $resolved['country'];
			$options                  = Shipping::options( $country, $items_total, $free_coupon );
			$out['shipping_options']  = $options;
			$threshold                = Shipping::free_threshold( $country );
			$out['free_shipping_threshold'] = $threshold;
			if ( null !== $threshold && ! $free_coupon ) {
				$out['free_shipping_remaining'] = max( 0, $threshold - $items_total );
			}

			$chosen = null;
			foreach ( $options as $option ) {
				if ( ! empty( $context['shipping_method'] ) && $option['id'] === $context['shipping_method'] ) {
					$chosen = $option;
					break;
				}
			}
			if ( ! $chosen && $options ) {
				$chosen = $options[0];
			}
			if ( $chosen ) {
				$entered = (int) $chosen['cost'];
				$rate    = Settings::bool( 'tax_shipping' ) ? $shipping_rate : 0.0;
				$base    = Settings::bool( 'tax_shipping' ) ? $shipping_base_rate : 0.0;

				if ( in_array( $mode, array( 'disabled', 'exempt' ), true ) ) {
					$gross = $entered;
					$rate  = 0.0;
				} elseif ( $remove_base ) {
					$gross = $entered - Tax::from_gross( $entered, $base );
				} elseif ( $incl && $fixed_gross ) {
					$gross = $entered;
				} elseif ( $incl ) {
					$net   = $entered - Tax::from_gross( $entered, $base );
					$gross = $net + Tax::from_net( $net, $rate );
				} else {
					$gross = $entered + Tax::from_net( $entered, $rate );
				}
				$tax = Tax::from_gross( $gross, $rate );

				$out['shipping_method'] = $chosen['id'];
				$out['shipping_title']  = $chosen['title'];
				$out['shipping']        = $gross;
				$out['shipping_tax']    = $tax;
				$out['shipping_net']    = $gross - $tax;
				$out['shipping_rate']   = $rate;
				self::add_tax_line( $out['tax_lines'], $rate, $gross - $tax, $tax );
			} else {
				$out['errors']['shipping'] = __( 'We do not deliver to this country yet.', 'wahou-commerce' );
			}
		}

		// 5. Grand total.
		$out['tax']   = array_sum( array_column( $out['tax_lines'], 'tax' ) );
		$out['total'] = $items_total + $out['shipping'];
		ksort( $out['tax_lines'] );
		$out['tax_lines'] = array_values( $out['tax_lines'] );

		/**
		 * Filters computed totals.
		 *
		 * @param array<string, mixed> $out
		 * @param array<int, array<string, mixed>> $lines
		 * @param array<string, mixed> $context
		 */
		return apply_filters( 'wahou_calculated_totals', $out, $lines, $context );
	}

	/**
	 * @param array<string, array{rate:float, net:int, tax:int}> $tax_lines
	 */
	private static function add_tax_line( array &$tax_lines, float $rate, int $net, int $tax ): void {
		$k = number_format( $rate, 3, '.', '' );
		if ( ! isset( $tax_lines[ $k ] ) ) {
			$tax_lines[ $k ] = array(
				'rate' => $rate,
				'net'  => 0,
				'tax'  => 0,
			);
		}
		$tax_lines[ $k ]['net'] += $net;
		$tax_lines[ $k ]['tax'] += $tax;
	}
}
