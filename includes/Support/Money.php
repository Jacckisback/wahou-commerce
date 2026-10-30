<?php
/**
 * Money helpers. Every amount is an integer in minor units (cents) to avoid
 * floating point errors; formatting follows the visitor's locale, so a
 * multilingual store shows "1 234,50 €" in French and "€1,234.50" in English.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Money {

	/** Currencies without minor units (Stripe "zero-decimal"). */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	private const SYMBOLS = array(
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
		'CHF' => 'CHF',
		'CAD' => '$',
		'AUD' => '$',
		'JPY' => '¥',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'zł',
		'CZK' => 'Kč',
		'HUF' => 'Ft',
		'RON' => 'lei',
		'BGN' => 'лв',
		'MAD' => 'DH',
		'MXN' => '$',
		'BRL' => 'R$',
		'TND' => 'DT',
		'XOF' => 'CFA',
		'ISK' => 'kr',
	);

	/** @var array<string, \NumberFormatter> */
	private static array $formatters = array();

	/**
	 * @return array<string, string> code => label
	 */
	public static function currencies(): array {
		return array(
			'EUR' => 'Euro (€)',
			'USD' => 'US Dollar ($)',
			'GBP' => 'Pound sterling (£)',
			'CHF' => 'Swiss franc (CHF)',
			'CAD' => 'Canadian dollar ($)',
			'AUD' => 'Australian dollar ($)',
			'SEK' => 'Swedish krona (kr)',
			'NOK' => 'Norwegian krone (kr)',
			'DKK' => 'Danish krone (kr)',
			'PLN' => 'Polish złoty (zł)',
			'CZK' => 'Czech koruna (Kč)',
			'HUF' => 'Hungarian forint (Ft)',
			'RON' => 'Romanian leu (lei)',
			'BGN' => 'Bulgarian lev (лв)',
			'MAD' => 'Moroccan dirham (DH)',
			'TND' => 'Tunisian dinar (DT)',
			'XOF' => 'West African CFA franc (CFA)',
			'MXN' => 'Mexican peso ($)',
			'BRL' => 'Brazilian real (R$)',
			'JPY' => 'Japanese yen (¥)',
		);
	}

	public static function currency(): string {
		return Settings::currency();
	}

	public static function decimals( ?string $currency = null ): int {
		$currency = strtoupper( $currency ?? self::currency() );
		if ( 'TND' === $currency ) {
			return 3;
		}
		return in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	public static function symbol( ?string $currency = null ): string {
		$currency = strtoupper( $currency ?? self::currency() );
		return self::SYMBOLS[ $currency ] ?? $currency;
	}

	/**
	 * Parse a human amount ("19,90", "1 234.50", "1.234,50", 12) into minor units.
	 *
	 * @param string|int|float|null $amount
	 */
	public static function to_minor( $amount, ?string $currency = null ): int {
		if ( null === $amount || '' === $amount ) {
			return 0;
		}
		$decimals = self::decimals( $currency );
		if ( is_int( $amount ) ) {
			return $amount * (int) ( 10 ** $decimals );
		}
		if ( is_float( $amount ) ) {
			return (int) round( $amount * ( 10 ** $decimals ) );
		}
		$str = preg_replace( '/[^\d,.\-]/u', '', (string) $amount );
		if ( null === $str || '' === $str || '-' === $str ) {
			return 0;
		}
		$last_comma = strrpos( $str, ',' );
		$last_dot   = strrpos( $str, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			// The right-most separator is the decimal separator.
			if ( $last_comma > $last_dot ) {
				$str = str_replace( '.', '', $str );
				$str = str_replace( ',', '.', $str );
			} else {
				$str = str_replace( ',', '', $str );
			}
		} elseif ( false !== $last_comma ) {
			// "19,90" => decimal; "1,234" with 3 trailing digits and no other hint => thousands.
			$tail = strlen( $str ) - $last_comma - 1;
			$str  = ( 3 === $tail && substr_count( $str, ',' ) > 1 ) ? str_replace( ',', '', $str ) : str_replace( ',', '.', $str );
		} elseif ( false !== $last_dot && substr_count( $str, '.' ) > 1 ) {
			$str = str_replace( '.', '', $str );
		}
		return (int) round( (float) $str * ( 10 ** $decimals ) );
	}

	/**
	 * Machine decimal string, e.g. 1990 => "19.90" (PayPal, feeds, schema.org).
	 */
	public static function to_decimal( int $minor, ?string $currency = null ): string {
		$decimals = self::decimals( $currency );
		return number_format( $minor / ( 10 ** $decimals ), $decimals, '.', '' );
	}

	/**
	 * Value for an admin input field, e.g. 1990 => "19,90" in French.
	 */
	public static function to_input( ?int $minor, ?string $currency = null ): string {
		if ( null === $minor ) {
			return '';
		}
		$decimals = self::decimals( $currency );
		$sep      = self::uses_decimal_comma( determine_locale() ) ? ',' : '.';
		return number_format( $minor / ( 10 ** $decimals ), $decimals, $sep, '' );
	}

	public static function format( int $minor, ?string $currency = null, ?string $locale = null ): string {
		$currency = strtoupper( $currency ?? self::currency() );
		$locale   = $locale ?? ( function_exists( 'determine_locale' ) ? determine_locale() : 'en_US' );
		$decimals = self::decimals( $currency );
		$value    = $minor / ( 10 ** $decimals );

		if ( class_exists( '\NumberFormatter' ) ) {
			$key = $locale . '|' . $currency;
			if ( ! isset( self::$formatters[ $key ] ) ) {
				$formatter = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
				$formatter->setAttribute( \NumberFormatter::FRACTION_DIGITS, $decimals );
				self::$formatters[ $key ] = $formatter;
			}
			$out = self::$formatters[ $key ]->formatCurrency( $value, $currency );
			if ( false !== $out ) {
				/**
				 * Filters a formatted price string.
				 */
				return apply_filters( 'wahou_format_price', $out, $minor, $currency, $locale );
			}
		}

		$comma  = self::uses_decimal_comma( $locale );
		$number = number_format( abs( $value ), $decimals, $comma ? ',' : '.', $comma ? "\u{202F}" : ',' );
		$symbol = self::symbol( $currency );
		$sign   = $value < 0 ? '-' : '';
		$out    = self::symbol_after( $locale ) ? $sign . $number . "\u{00A0}" . $symbol : $sign . $symbol . $number;
		return apply_filters( 'wahou_format_price', $out, $minor, $currency, $locale );
	}

	/**
	 * Price wrapped in markup with a machine readable value.
	 */
	public static function html( int $minor, ?string $currency = null, string $class = 'wahou-price' ): string {
		return sprintf(
			'<span class="%1$s" data-amount="%2$d">%3$s</span>',
			esc_attr( $class ),
			$minor,
			esc_html( self::format( $minor, $currency ) )
		);
	}

	private static function uses_decimal_comma( string $locale ): bool {
		$lang = strtolower( substr( $locale, 0, 2 ) );
		return ! in_array( $lang, array( 'en', 'ja', 'zh', 'ko', 'th', 'he' ), true ) || 'en_ZA' === $locale;
	}

	private static function symbol_after( string $locale ): bool {
		$lang = strtolower( substr( $locale, 0, 2 ) );
		return ! in_array( $lang, array( 'en', 'nl', 'ja', 'zh', 'ko', 'he' ), true );
	}
}
