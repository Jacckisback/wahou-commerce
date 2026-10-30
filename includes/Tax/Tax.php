<?php
/**
 * EU VAT engine.
 *
 * Tax modes:
 *  - disabled        Tax collection is off.
 *  - exempt          Merchant under a VAT franchise (e.g. France art. 293 B CGI): no VAT.
 *  - domestic        Customer in the store country: store country rates.
 *  - origin          EU consumer abroad, OSS off (below the EUR 10,000 EU-wide
 *                    threshold, art. 59c Directive 2006/112/EC): store country rates.
 *  - oss             EU consumer abroad, OSS on: customer country rates.
 *  - reverse_charge  EU business with a VIES-valid VAT number in another member
 *                    state: 0 % (art. 196 / art. 138 Directive 2006/112/EC).
 *  - export          Outside the EU VAT area (non-EU, Canary Islands, French
 *                    overseas departments...): 0 %.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Tax;

use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Tax {

	public const CLASSES = array( 'standard', 'reduced', 'super_reduced', 'books', 'ebooks', 'zero' );

	/** Territories taxed as another member state (art. 7 Directive 2006/112/EC). */
	private const VAT_ALIASES = array( 'MC' => 'FR' );

	/**
	 * @return array<string, string> class => label
	 */
	public static function class_labels(): array {
		return array(
			'standard'      => __( 'Standard rate', 'wahou-commerce' ),
			'reduced'       => __( 'Reduced rate', 'wahou-commerce' ),
			'super_reduced' => __( 'Super-reduced rate', 'wahou-commerce' ),
			'books'         => __( 'Books (printed)', 'wahou-commerce' ),
			'ebooks'        => __( 'E-books & digital publications', 'wahou-commerce' ),
			'zero'          => __( 'Zero rate / exempt', 'wahou-commerce' ),
		);
	}

	/**
	 * Rate (percent) for a country and tax class, honoring admin overrides.
	 */
	public static function rate( string $country, string $class = 'standard' ): float {
		$country = strtoupper( $country );
		$class   = in_array( $class, self::CLASSES, true ) ? $class : 'standard';
		if ( 'zero' === $class ) {
			return 0.0;
		}

		$overrides = Settings::get( 'tax_rates_overrides', array() );
		if ( is_array( $overrides ) && isset( $overrides[ $country ][ $class ] ) && '' !== $overrides[ $country ][ $class ] ) {
			return (float) $overrides[ $country ][ $class ];
		}

		$table = Rates::table();
		$rates = $table[ $country ] ?? null;
		if ( null === $rates ) {
			$rate = 0.0;
		} else {
			switch ( $class ) {
				case 'reduced':
					$rate = (float) ( $rates['reduced'][0] ?? $rates['standard'] );
					break;
				case 'super_reduced':
					$rate = isset( $rates['super_reduced'] ) ? (float) $rates['super_reduced'] : (float) ( empty( $rates['reduced'] ) ? $rates['standard'] : min( $rates['reduced'] ) );
					break;
				case 'books':
					$rate = (float) ( $rates['books'] ?? $rates['standard'] );
					break;
				case 'ebooks':
					$rate = (float) ( $rates['ebooks'] ?? $rates['books'] ?? $rates['standard'] );
					break;
				default:
					$rate = (float) $rates['standard'];
			}
		}

		/**
		 * Filters a VAT rate.
		 *
		 * @param float  $rate    Percent.
		 * @param string $country ISO code.
		 * @param string $class   Tax class.
		 */
		return (float) apply_filters( 'wahou_tax_rate', $rate, $country, $class );
	}

	/**
	 * Resolve the tax mode and the country whose rates apply.
	 *
	 * @param array<string, mixed> $customer Keys: country, postcode, vat_valid (bool).
	 * @return array{mode:string, rate_country:string, country:string}
	 */
	public static function resolve( array $customer ): array {
		$store   = Settings::store_country();
		$country = strtoupper( (string) ( $customer['country'] ?? '' ) );
		if ( ! Countries::exists( $country ) ) {
			$country = $store;
		}
		$postcode = (string) ( $customer['postcode'] ?? '' );
		$result   = array(
			'mode'         => 'domestic',
			'rate_country' => $store,
			'country'      => $country,
		);
		// Monaco belongs to the French VAT territory (art. 7 Directive 2006/112/EC).
		$vat_country = self::VAT_ALIASES[ $country ] ?? $country;

		if ( ! Settings::bool( 'tax_enabled' ) ) {
			$result['mode'] = 'disabled';
		} elseif ( Settings::bool( 'tax_exempt' ) ) {
			$result['mode'] = 'exempt';
		} elseif ( Countries::is_outside_eu_vat_area( $country, $postcode ) ) {
			$result['mode'] = 'export';
		} elseif ( ! Countries::is_eu( $store ) ) {
			// Merchant outside the EU: domestic sales taxed, everything else zero-rated.
			$result['mode'] = $vat_country === $store ? 'domestic' : 'export';
		} elseif ( ! Countries::is_eu( $vat_country ) ) {
			$result['mode'] = 'export';
		} elseif ( $vat_country !== $store && ! empty( $customer['vat_valid'] ) && Settings::bool( 'tax_b2b_reverse_charge' ) ) {
			$result['mode'] = 'reverse_charge';
		} elseif ( $vat_country === $store ) {
			$result['mode'] = 'domestic';
		} elseif ( Settings::bool( 'tax_oss' ) ) {
			$result['mode']         = 'oss';
			$result['rate_country'] = $vat_country;
		} else {
			$result['mode'] = 'origin';
		}

		/**
		 * Filters the resolved tax situation of a customer.
		 */
		return apply_filters( 'wahou_tax_resolve', $result, $customer );
	}

	/**
	 * Whether the mode charges VAT at all.
	 */
	public static function mode_is_taxable( string $mode ): bool {
		return in_array( $mode, array( 'domestic', 'origin', 'oss' ), true );
	}

	/**
	 * Tax included in a gross amount: gross * r / (100 + r).
	 */
	public static function from_gross( int $gross, float $rate ): int {
		if ( $rate <= 0 || 0 === $gross ) {
			return 0;
		}
		return (int) round( $gross * $rate / ( 100 + $rate ) );
	}

	/**
	 * Tax to add to a net amount.
	 */
	public static function from_net( int $net, float $rate ): int {
		if ( $rate <= 0 || 0 === $net ) {
			return 0;
		}
		return (int) round( $net * $rate / 100 );
	}

	/**
	 * Label shown after prices ("incl. VAT"), empty when not relevant.
	 */
	public static function price_suffix(): string {
		if ( ! Settings::bool( 'tax_price_suffix' ) || ! Settings::bool( 'tax_enabled' ) ) {
			return '';
		}
		if ( Settings::bool( 'tax_exempt' ) ) {
			return '';
		}
		return Settings::bool( 'prices_include_tax' ) ? __( 'incl. VAT', 'wahou-commerce' ) : __( 'excl. VAT', 'wahou-commerce' );
	}

	/**
	 * Mention printed on invoices for zero-rated or exempt sales.
	 *
	 * Invoicing rules are those of the seller's member state (art. 219a
	 * Directive 2006/112/EC), so statutory wordings follow the store country:
	 *  - FR: "Autoliquidation" (CGI ann. II art. 242 nonies A I-13°), "Exonération de TVA,
	 *    article 262 ter, I du CGI" (I-12°), franchise "TVA non applicable, article 293 B du CGI".
	 *  - ES: «Inversión del sujeto pasivo» and «Entrega intracomunitaria exenta»
	 *    (Real Decreto 1619/2012, art. 6.1 m) and j)).
	 */
	public static function invoice_mention( string $mode, bool $has_goods = false ): string {
		$store = Settings::store_country();
		switch ( $mode ) {
			case 'exempt':
				$custom = Settings::str( 'tax_exempt_mention' );
				if ( '' !== $custom ) {
					return $custom;
				}
				return 'FR' === $store ? 'TVA non applicable, article 293 B du CGI' : __( 'VAT exempt (small business scheme).', 'wahou-commerce' );
			case 'reverse_charge':
				if ( 'FR' === $store ) {
					return $has_goods ? 'Exonération de TVA, article 262 ter, I du CGI' : 'Autoliquidation (article 196 de la directive 2006/112/CE)';
				}
				if ( 'ES' === $store ) {
					return $has_goods ? 'Entrega intracomunitaria exenta (art. 25 Ley 37/1992 – art. 138 Directiva 2006/112/CE)' : 'Inversión del sujeto pasivo (art. 196 Directiva 2006/112/CE)';
				}
				return $has_goods
					? __( 'Exempt intra-Community supply – Article 138 of Directive 2006/112/EC.', 'wahou-commerce' )
					: __( 'Reverse charge – Article 196 of Directive 2006/112/EC.', 'wahou-commerce' );
			case 'export':
				return __( 'VAT not applicable – supply outside the EU VAT area.', 'wahou-commerce' );
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * VIES VAT number validation.
	 * ------------------------------------------------------------------ */

	/**
	 * Normalize "FR 12 345678901" => ['FR', '12345678901'] (Greece uses EL in VIES).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function split_vat_number( string $vat, string $country = '' ): array {
		$vat = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $vat ) ?? '' );
		$cc  = substr( $vat, 0, 2 );
		if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			$number = substr( $vat, 2 );
		} else {
			$cc     = strtoupper( $country );
			$number = $vat;
		}
		if ( 'GR' === $cc ) {
			$cc = 'EL';
		}
		return array( $cc, $number );
	}

	/**
	 * Validate a VAT number with the European Commission VIES REST API.
	 * Results are cached for 24 hours (valid) or 10 minutes (errors).
	 *
	 * @return array{valid:bool, country:string, number:string, name?:string, address?:string, error?:string}
	 */
	public static function validate_vat( string $vat, string $country = '' ): array {
		list( $cc, $number ) = self::split_vat_number( $vat, $country );
		$result              = array(
			'valid'   => false,
			'country' => $cc,
			'number'  => $number,
		);
		if ( strlen( $number ) < 4 || ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			$result['error'] = 'format';
			return $result;
		}
		$cache_key = 'wahou_vies_' . md5( $cc . $number );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! RateLimiter::hit( 'vies', 20, HOUR_IN_SECONDS ) ) {
			$result['error'] = 'rate_limited';
			return $result;
		}

		$response = wp_remote_post(
			'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number',
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'countryCode' => $cc,
						'vatNumber'   => $number,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'tax', 'VIES request failed', array( 'error' => $response->get_error_message() ) );
			$result['error'] = 'unavailable';
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$result['error'] = 'unavailable';
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		if ( isset( $body['actionSucceed'] ) && false === $body['actionSucceed'] ) {
			// Service errors (MS_UNAVAILABLE, TIMEOUT...) are returned with HTTP 200.
			Logger::error( 'tax', 'VIES service error', array( 'errors' => $body['errorWrappers'] ?? array() ) );
			$result['error'] = 'unavailable';
			set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		if ( ! empty( $body['valid'] ) ) {
			$result['valid']   = true;
			$result['name']    = trim( (string) ( $body['name'] ?? '' ) ) === '---' ? '' : trim( (string) ( $body['name'] ?? '' ) );
			$result['address'] = trim( (string) ( $body['address'] ?? '' ) ) === '---' ? '' : trim( (string) ( $body['address'] ?? '' ) );
			set_transient( $cache_key, $result, DAY_IN_SECONDS );
		} else {
			$result['error'] = array_key_exists( 'valid', $body ) ? 'invalid' : 'unavailable';
			set_transient( $cache_key, $result, 'invalid' === $result['error'] ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		}
		return $result;
	}
}
