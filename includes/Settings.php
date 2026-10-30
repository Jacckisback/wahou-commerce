<?php
/**
 * Settings store.
 *
 * All settings live in one autoloaded option (wahou_settings). API secrets live
 * in a separate, non-autoloaded option (wahou_secrets) and are encrypted at rest
 * with libsodium. Any secret can also be defined as a constant in wp-config.php,
 * e.g. define( 'WAHOU_STRIPE_LIVE_SECRET', 'sk_live_...' ); which always wins.
 *
 * Defaults never contain translated strings: empty text settings mean "use the
 * translated default", which keeps multilingual stores in sync.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce;

use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION  = 'wahou_settings';
	public const SECRETS = 'wahou_secrets';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/** @var array<string, mixed>|null */
	private static ?array $defaults = null;

	/**
	 * Default values for every setting key.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		if ( null !== self::$defaults ) {
			return self::$defaults;
		}

		$locale  = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$country = Countries::from_locale( $locale );
		$is_eu   = Countries::is_eu( $country );

		self::$defaults = array(
			// General.
			'store_name'                => '',
			'store_legal_name'          => '',
			'store_email'               => '',
			'store_address'             => '',
			'store_country'             => $country,
			'store_vat_number'          => '',
			'store_legal_extra'         => '',
			'currency'                  => $is_eu ? 'EUR' : ( 'GB' === $country ? 'GBP' : ( 'US' === $country ? 'USD' : 'EUR' ) ),
			'order_prefix'              => '',
			'invoice_prefix'            => 'F-',
			'credit_note_prefix'        => 'AV-',
			'invoice_offset'            => 0,
			'product_base'              => '',
			'category_base'             => '',
			'weight_unit'               => 'kg',
			'dimension_unit'            => 'cm',

			// Pages (IDs).
			'page_shop'                 => 0,
			'page_cart'                 => 0,
			'page_checkout'             => 0,
			'page_account'              => 0,
			'page_terms'                => 0,
			'page_privacy'              => 0,

			// Catalog & product page.
			'products_per_page'         => 12,
			'catalog_columns'           => 3,
			'show_stock'                => true,
			'low_stock_threshold'       => 5,
			'reviews_enabled'           => true,
			'reviews_verified_only'     => false,
			'omnibus_display'           => true,
			'sticky_atc'                => true,
			'buy_now_button'            => true,
			'cart_mode'                 => 'drawer',
			'free_shipping_bar'         => true,
			'trust_badges'              => true,
			'delivery_estimate'         => true,
			'shipping_cutoff'           => '14:00',
			'processing_days'           => 1,
			'business_days'             => array( 1, 2, 3, 4, 5 ),

			// Design.
			'brand_color'               => '#5b3df5',
			'radius'                    => 12,
			'button_style'              => 'rounded',

			// Checkout.
			'guest_checkout'            => true,
			'auto_create_account'       => true,
			'field_phone'               => 'optional',
			'field_company'             => 'optional',
			'order_notes'               => false,
			'terms_checkbox'            => true,
			'pay_button_label'          => '',
			'digital_waiver'            => true,
			'honeypot'                  => true,
			'checkout_rate_limit'       => 15,
			'thankyou_message'          => '',
			'bumps_enabled'             => true,
			'upsells_enabled'           => true,
			'allowed_countries'         => array(),

			// Payments.
			'gateway_order'             => array( 'stripe', 'paypal', 'bank' ),
			'stripe_enabled'            => false,
			'stripe_mode'               => 'test',
			'stripe_test_publishable'   => '',
			'stripe_live_publishable'   => '',
			'stripe_account_name'       => '',
			'stripe_statement_descriptor' => '',
			'stripe_express'            => true,
			'stripe_test_webhook_id'    => '',
			'stripe_live_webhook_id'    => '',
			'paypal_enabled'            => false,
			'paypal_mode'               => 'sandbox',
			'paypal_sandbox_client_id'  => '',
			'paypal_live_client_id'     => '',
			'paypal_sandbox_webhook_id' => '',
			'paypal_live_webhook_id'    => '',
			'paypal_paylater'           => true,
			'bank_enabled'              => false,
			'bank_account_holder'       => '',
			'bank_iban'                 => '',
			'bank_bic'                  => '',
			'bank_name'                 => '',
			'bank_instructions'         => '',
			'bank_hold_days'            => 7,

			// Tax.
			'tax_enabled'               => true,
			'prices_include_tax'        => true,
			'tax_fixed_gross'           => true,
			'tax_oss'                   => false,
			'tax_b2b_reverse_charge'    => true,
			'tax_exempt'                => false,
			'tax_exempt_mention'        => '',
			'tax_rates_overrides'       => array(),
			'tax_shipping'              => true,
			'tax_price_suffix'          => true,

			// Shipping.
			'shipping_zones'            => self::default_shipping_zones( $country ),
			'return_days'               => 14,
			'return_fees'               => 'customer',

			// Emails.
			'email_from_name'           => '',
			'email_from_address'        => '',
			'email_logo'                => 0,
			'email_footer'              => '',
			'email_admin_new_order'     => true,
			'email_order_confirmation'  => true,
			'email_order_shipped'       => true,
			'email_order_refunded'      => true,
			'email_low_stock'           => true,

			// Abandoned cart recovery.
			'recovery_enabled'          => true,
			'recovery_abandon_minutes'  => 30,
			'recovery_consent'          => 'notice',
			'recovery_steps'            => array(
				array(
					'delay_hours' => 1,
					'subject'     => '',
					'coupon'      => 0,
					'coupon_days' => 0,
				),
				array(
					'delay_hours' => 24,
					'subject'     => '',
					'coupon'      => 0,
					'coupon_days' => 0,
				),
				array(
					'delay_hours' => 72,
					'subject'     => '',
					'coupon'      => 10,
					'coupon_days' => 3,
				),
			),
			'recovery_retention_days'   => 90,
			'review_requests'           => true,
			'review_delay_days'         => 7,

			// SEO & GEO.
			'seo_schema'                => true,
			'seo_meta'                  => 'auto',
			'seo_breadcrumbs'           => true,
			'seo_llms_txt'              => true,
			'seo_llms_intro'            => '',
			'seo_markdown'              => true,
			'seo_feed'                  => true,
			'seo_ai_crawlers'           => 'allow',
			'seo_brand'                 => '',
			'seo_abilities'             => true,

			// Performance.
			'perf_disable_emojis'       => true,
			'perf_disable_embeds'       => false,
			'perf_speculation'          => 'moderate',
			'perf_lazy_paypal'          => true,
			'perf_purge_cache'          => true,

			// Advanced.
			'analytics_datalayer'       => true,
			'rest_public_products'      => true,
			'debug_log'                 => false,
			'delete_data_on_uninstall'  => false,
		);

		return self::$defaults;
	}

	/**
	 * Sensible starter shipping zones for the store country.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function default_shipping_zones( string $country ): array {
		$eu_others = array_values( array_diff( Countries::eu(), array( $country ) ) );
		return array(
			array(
				'id'        => 'domestic',
				'name'      => Countries::name( $country ),
				'countries' => array( $country ),
				'methods'   => array(
					array(
						'id'         => 'flat',
						'type'       => 'flat',
						'title'      => '',
						'cost'       => 490,
						'min_amount' => 0,
						'min_days'   => 2,
						'max_days'   => 3,
					),
					array(
						'id'         => 'free',
						'type'       => 'free',
						'title'      => '',
						'cost'       => 0,
						'min_amount' => 5000,
						'min_days'   => 2,
						'max_days'   => 3,
					),
				),
			),
			array(
				'id'        => 'eu',
				'name'      => 'EU',
				'countries' => $eu_others,
				'methods'   => array(
					array(
						'id'         => 'flat',
						'type'       => 'flat',
						'title'      => '',
						'cost'       => 990,
						'min_amount' => 0,
						'min_days'   => 4,
						'max_days'   => 7,
					),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * @param mixed $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		$value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;

		/**
		 * Filters a single Wahou Commerce setting.
		 *
		 * @param mixed  $value Setting value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'wahou_setting', $value, $key );
	}

	public static function bool( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	public static function int( string $key ): int {
		return (int) self::get( $key, 0 );
	}

	public static function str( string $key ): string {
		$value = self::get( $key, '' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param mixed $value
	 */
	public static function set( string $key, $value ): void {
		self::update( array( $key => $value ) );
	}

	/**
	 * Merge and persist values. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function update( array $values ): void {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$known = self::defaults();
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $known ) ) {
				$saved[ $key ] = $value;
			}
		}
		update_option( self::OPTION, $saved, true );
		self::$cache = null;

		/**
		 * Fires after settings are saved.
		 *
		 * @param array<string, mixed> $values Changed values.
		 */
		do_action( 'wahou_settings_updated', $values );
	}

	public static function flush_cache(): void {
		self::$cache    = null;
		self::$defaults = null;
	}

	/* ------------------------------------------------------------------
	 * Store identity helpers.
	 * ------------------------------------------------------------------ */

	public static function store_name(): string {
		$name = self::str( 'store_name' );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function store_email(): string {
		$email = self::str( 'store_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	public static function store_country(): string {
		$country = strtoupper( self::str( 'store_country' ) );
		return 2 === strlen( $country ) ? $country : 'FR';
	}

	public static function currency(): string {
		$currency = strtoupper( self::str( 'currency' ) );
		return 3 === strlen( $currency ) ? $currency : 'EUR';
	}

	/* ------------------------------------------------------------------
	 * Secrets.
	 * ------------------------------------------------------------------ */

	/**
	 * Read a secret (decrypted). Constant WAHOU_{KEY} overrides the database.
	 */
	public static function secret( string $key ): string {
		$constant = 'WAHOU_' . strtoupper( $key );
		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}
		$secrets = get_option( self::SECRETS, array() );
		if ( ! is_array( $secrets ) || empty( $secrets[ $key ] ) ) {
			return '';
		}
		return Crypto::decrypt( (string) $secrets[ $key ] );
	}

	public static function set_secret( string $key, string $value ): void {
		$secrets = get_option( self::SECRETS, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();
		if ( '' === $value ) {
			unset( $secrets[ $key ] );
		} else {
			$secrets[ $key ] = Crypto::encrypt( $value );
		}
		update_option( self::SECRETS, $secrets, false );
	}

	public static function has_secret( string $key ): bool {
		return '' !== self::secret( $key );
	}

	public static function secret_is_constant( string $key ): bool {
		return defined( 'WAHOU_' . strtoupper( $key ) );
	}

	/**
	 * Masked hint for admin screens, e.g. "sk_live_••••4242".
	 */
	public static function secret_hint( string $key ): string {
		$secret = self::secret( $key );
		if ( '' === $secret ) {
			return '';
		}
		$prefix = '';
		if ( preg_match( '/^([a-z]{2,4}_(?:test|live)_)/', $secret, $m ) ) {
			$prefix = $m[1];
		}
		return $prefix . '••••' . substr( $secret, -4 );
	}
}
