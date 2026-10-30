<?php
/**
 * EU-27 VAT rates (percent). PROVISIONAL – replaced by the verified table.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Tax;

defined( 'ABSPATH' ) || exit;

final class Rates {

	public const VERIFIED_AT = '';
	public const SOURCE      = '';

	/**
	 * @return array<string, array{standard:float, reduced:float[], super_reduced?:float, books?:float, ebooks?:float}>
	 */
	public static function table(): array {
		return (array) apply_filters( 'wahou_vat_rates_table', self::RATES );
	}

	private const RATES = array(
		'AT' => array( 'standard' => 20, 'reduced' => array( 10, 13 ), 'books' => 10, 'ebooks' => 10 ),
		'BE' => array( 'standard' => 21, 'reduced' => array( 6, 12 ), 'books' => 6, 'ebooks' => 6 ),
		'BG' => array( 'standard' => 20, 'reduced' => array( 9 ), 'books' => 9, 'ebooks' => 9 ),
		'CY' => array( 'standard' => 19, 'reduced' => array( 5, 9 ), 'books' => 5, 'ebooks' => 5 ),
		'CZ' => array( 'standard' => 21, 'reduced' => array( 12 ), 'books' => 0, 'ebooks' => 0 ),
		'DE' => array( 'standard' => 19, 'reduced' => array( 7 ), 'books' => 7, 'ebooks' => 7 ),
		'DK' => array( 'standard' => 25, 'reduced' => array(), 'books' => 25, 'ebooks' => 25 ),
		'EE' => array( 'standard' => 24, 'reduced' => array( 9, 13 ), 'books' => 9, 'ebooks' => 9 ),
		'ES' => array( 'standard' => 21, 'reduced' => array( 10 ), 'super_reduced' => 4, 'books' => 4, 'ebooks' => 4 ),
		'FI' => array( 'standard' => 25.5, 'reduced' => array( 14, 10 ), 'books' => 14, 'ebooks' => 14 ),
		'FR' => array( 'standard' => 20, 'reduced' => array( 10, 5.5 ), 'super_reduced' => 2.1, 'books' => 5.5, 'ebooks' => 5.5 ),
		'GR' => array( 'standard' => 24, 'reduced' => array( 13, 6 ), 'books' => 6, 'ebooks' => 6 ),
		'HR' => array( 'standard' => 25, 'reduced' => array( 13, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'HU' => array( 'standard' => 27, 'reduced' => array( 18, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'IE' => array( 'standard' => 23, 'reduced' => array( 13.5, 9 ), 'super_reduced' => 4.8, 'books' => 0, 'ebooks' => 0 ),
		'IT' => array( 'standard' => 22, 'reduced' => array( 10, 5 ), 'super_reduced' => 4, 'books' => 4, 'ebooks' => 4 ),
		'LT' => array( 'standard' => 21, 'reduced' => array( 9, 5 ), 'books' => 9, 'ebooks' => 9 ),
		'LU' => array( 'standard' => 17, 'reduced' => array( 8 ), 'super_reduced' => 3, 'books' => 3, 'ebooks' => 3 ),
		'LV' => array( 'standard' => 21, 'reduced' => array( 12, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'MT' => array( 'standard' => 18, 'reduced' => array( 7, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'NL' => array( 'standard' => 21, 'reduced' => array( 9 ), 'books' => 9, 'ebooks' => 9 ),
		'PL' => array( 'standard' => 23, 'reduced' => array( 8, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'PT' => array( 'standard' => 23, 'reduced' => array( 13, 6 ), 'books' => 6, 'ebooks' => 6 ),
		'RO' => array( 'standard' => 21, 'reduced' => array( 11 ), 'books' => 11, 'ebooks' => 11 ),
		'SE' => array( 'standard' => 25, 'reduced' => array( 12, 6 ), 'books' => 6, 'ebooks' => 6 ),
		'SI' => array( 'standard' => 22, 'reduced' => array( 9.5, 5 ), 'books' => 5, 'ebooks' => 5 ),
		'SK' => array( 'standard' => 23, 'reduced' => array( 19, 5 ), 'books' => 5, 'ebooks' => 5 ),
	);
}
