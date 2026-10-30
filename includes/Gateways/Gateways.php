<?php
/**
 * Payment gateway registry.
 *
 * Third-party gateways can be added with the wahou_gateways filter:
 *   add_filter( 'wahou_gateways', fn( $g ) => $g + [ 'mollie' => new My_Mollie_Gateway() ] );
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Gateways {

	/** @var array<string, Gateway>|null */
	private static ?array $gateways = null;

	public static function init(): void {
		if ( is_admin() && class_exists( Connect::class ) ) {
			Connect::init();
		}
		add_action(
			'init',
			static function () {
				foreach ( self::all() as $gateway ) {
					$gateway->register();
				}
			},
			20
		);
	}

	/**
	 * @return array<string, Gateway>
	 */
	public static function all(): array {
		if ( null === self::$gateways ) {
			$gateways = array();
			foreach ( array( Stripe::class, PayPal::class, BankTransfer::class ) as $class ) {
				if ( class_exists( $class ) ) {
					$gateway                    = new $class();
					$gateways[ $gateway->id ] = $gateway;
				}
			}
			/**
			 * Filters registered gateways (id => Gateway instance).
			 */
			$gateways = apply_filters( 'wahou_gateways', $gateways );

			$order  = (array) Settings::get( 'gateway_order', array() );
			$sorted = array();
			foreach ( $order as $id ) {
				if ( isset( $gateways[ $id ] ) ) {
					$sorted[ $id ] = $gateways[ $id ];
				}
			}
			self::$gateways = $sorted + $gateways;
		}
		return self::$gateways;
	}

	/**
	 * Gateways usable at checkout, in display order.
	 *
	 * @return array<string, Gateway>
	 */
	public static function available(): array {
		return array_filter( self::all(), static fn( Gateway $g ) => $g->is_available() );
	}

	public static function get( string $id ): ?Gateway {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function reset(): void {
		self::$gateways = null;
	}
}
