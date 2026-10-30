<?php
/**
 * Countries, EU membership and territories excluded from the EU VAT area.
 *
 * Country names are localized with PHP intl when available, so no country
 * name has to be translated by hand.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Countries {

	/** EU-27 member states (ISO 3166-1 alpha-2; Greece is GR here, EL in VIES). */
	public const EU = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	/**
	 * ISO codes of territories that have their own ISO code but are part of an
	 * EU member state while being outside the EU VAT area (Art. 6 Directive 2006/112/EC).
	 */
	private const NON_VAT_TERRITORY_CODES = array( 'GP', 'MQ', 'RE', 'GF', 'YT', 'MF', 'AX', 'IC', 'EA' );

	/** @var array<string, string> */
	private const NAMES = array(
		'AF' => 'Afghanistan', 'AX' => 'Åland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa', 'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
		'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BQ' => 'Caribbean Netherlands', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
		'CV' => 'Cape Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'KY' => 'Cayman Islands', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island', 'CC' => 'Cocos (Keeling) Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo - Brazzaville', 'CD' => 'Congo - Kinshasa', 'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => 'Côte d’Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CW' => 'Curaçao', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
		'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
		'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam', 'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
		'HT' => 'Haiti', 'HM' => 'Heard & McDonald Islands', 'VA' => 'Vatican City', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel', 'IT' => 'Italy',
		'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'North Korea', 'KR' => 'South Korea', 'XK' => 'Kosovo', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
		'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
		'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
		'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MK' => 'North Macedonia', 'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman',
		'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PN' => 'Pitcairn Islands', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico', 'QA' => 'Qatar',
		'RE' => 'Réunion', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'BL' => 'Saint Barthélemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'MF' => 'Saint Martin', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'São Tomé and Príncipe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SX' => 'Sint Maarten', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia & South Sandwich Islands', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
		'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu',
		'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UM' => 'U.S. Outlying Islands', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'VG' => 'British Virgin Islands', 'VI' => 'U.S. Virgin Islands', 'WF' => 'Wallis and Futuna', 'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
	);

	/** @var array<string, array<string, string>> */
	private static array $localized = array();

	/**
	 * All countries, localized and sorted by name.
	 *
	 * @return array<string, string>
	 */
	public static function all( ?string $locale = null ): array {
		$locale = $locale ?? ( function_exists( 'determine_locale' ) ? determine_locale() : 'en_US' );
		if ( isset( self::$localized[ $locale ] ) ) {
			return self::$localized[ $locale ];
		}
		$out = array();
		foreach ( self::NAMES as $code => $english ) {
			$out[ $code ] = self::localize( $code, $english, $locale );
		}
		if ( class_exists( '\Collator' ) ) {
			$collator = new \Collator( $locale );
			uasort( $out, static fn( $a, $b ) => (int) $collator->compare( $a, $b ) );
		} else {
			asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		}
		/**
		 * Filters the list of countries (code => localized name).
		 */
		self::$localized[ $locale ] = apply_filters( 'wahou_countries', $out, $locale );
		return self::$localized[ $locale ];
	}

	public static function name( string $code, ?string $locale = null ): string {
		$code = strtoupper( $code );
		if ( ! isset( self::NAMES[ $code ] ) ) {
			return $code;
		}
		$locale = $locale ?? ( function_exists( 'determine_locale' ) ? determine_locale() : 'en_US' );
		return self::localize( $code, self::NAMES[ $code ], $locale );
	}

	public static function exists( string $code ): bool {
		return isset( self::NAMES[ strtoupper( $code ) ] );
	}

	/**
	 * @return string[]
	 */
	public static function eu(): array {
		return self::EU;
	}

	public static function is_eu( string $code ): bool {
		return in_array( strtoupper( $code ), self::EU, true );
	}

	/**
	 * Whether an address inside an EU member state is outside the EU VAT area
	 * (Art. 6 of Directive 2006/112/EC). Such sales are treated as exports.
	 */
	public static function is_outside_eu_vat_area( string $country, string $postcode = '' ): bool {
		$country  = strtoupper( $country );
		$postcode = strtoupper( preg_replace( '/\s+/', '', $postcode ) ?? '' );

		if ( in_array( $country, self::NON_VAT_TERRITORY_CODES, true ) ) {
			return true;
		}
		if ( '' === $postcode ) {
			return false;
		}
		switch ( $country ) {
			case 'ES': // Canary Islands (35, 38), Ceuta (51), Melilla (52).
				return (bool) preg_match( '/^(35|38|51|52)\d{3}$/', $postcode );
			case 'FR': // Overseas departments (Guadeloupe, Martinique, Guyane, Réunion, Mayotte) and Saint-Martin.
				return (bool) preg_match( '/^97[1-6]\d{2}$/', $postcode ) || '97150' === $postcode;
			case 'DE': // Büsingen am Hochrhein, Heligoland.
				return in_array( $postcode, array( '78266', '27498' ), true );
			case 'IT': // Livigno, Campione d'Italia, Italian waters of Lake Lugano.
				return in_array( $postcode, array( '23041', '22061' ), true );
			case 'GR': // Mount Athos.
				return in_array( $postcode, array( '63086', '63087' ), true );
			case 'FI': // Åland Islands.
				return (bool) preg_match( '/^22\d{3}$/', $postcode );
		}
		return false;
	}

	/**
	 * Guess the store country from a WordPress locale (fr_FR => FR, es_ES => ES).
	 */
	public static function from_locale( string $locale ): string {
		$map = array(
			'fr' => 'FR',
			'es' => 'ES',
			'de' => 'DE',
			'it' => 'IT',
			'pt' => 'PT',
			'nl' => 'NL',
			'ca' => 'ES',
			'eu' => 'ES',
			'gl' => 'ES',
			'en' => 'GB',
		);
		if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) && isset( self::NAMES[ $m[1] ] ) ) {
			if ( 'en_US' === $locale ) {
				return 'US';
			}
			return $m[1];
		}
		$lang = strtolower( substr( $locale, 0, 2 ) );
		return $map[ $lang ] ?? 'FR';
	}

	/**
	 * Countries a customer may choose at checkout. Physical carts are limited
	 * to shipping zones; digital carts can be sold worldwide.
	 *
	 * @param string[] $restrict Optional list of allowed codes.
	 * @return array<string, string>
	 */
	public static function selectable( array $restrict = array() ): array {
		$all = self::all();
		if ( empty( $restrict ) ) {
			return $all;
		}
		return array_intersect_key( $all, array_flip( array_map( 'strtoupper', $restrict ) ) );
	}

	private static function localize( string $code, string $english, string $locale ): string {
		if ( 0 === strpos( $locale, 'en' ) || ! class_exists( '\Locale' ) ) {
			return $english;
		}
		$name = \Locale::getDisplayRegion( 'und_' . $code, $locale );
		return ( is_string( $name ) && '' !== $name && $name !== $code ) ? $name : $english;
	}
}
