<?php
/**
 * Plugin bootstrap: loads translations and boots every module.
 *
 * Each module is a class exposing a static init() method that only registers
 * hooks. Modules are booted on plugins_loaded so that multilingual and SEO
 * plugins are available for compatibility checks.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Modules booted on every request.
	 */
	private const MODULES = array(
		// Core.
		Products\PostTypes::class,
		Products\PriceHistory::class,
		Orders\Orders::class,
		Cart\CartSession::class,
		Rest\RestApi::class,
		Funnels\Offers::class,
		// Payments.
		Gateways\Gateways::class,
		// Storefront.
		Frontend\Frontend::class,
		Reviews\Reviews::class,
		Downloads\Downloads::class,
		Customers\Account::class,
		Invoices\Invoices::class,
		// Growth, SEO & GEO.
		Emails\Emails::class,
		Recovery\Recovery::class,
		Seo\Seo::class,
		Performance\Performance::class,
		Compat\Compat::class,
		Privacy\Privacy::class,
	);

	/**
	 * Modules booted only in wp-admin.
	 */
	private const ADMIN_MODULES = array(
		Admin\Admin::class,
		Import\WooImporter::class,
	);

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'plugins_loaded', array( self::class, 'load_modules' ), 5 );
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'init', array( Install::class, 'maybe_upgrade' ), 1 );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
	}

	public static function load_modules(): void {
		foreach ( self::MODULES as $module ) {
			if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
				$module::init();
			}
		}
		if ( is_admin() ) {
			foreach ( self::ADMIN_MODULES as $module ) {
				if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
					$module::init();
				}
			}
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( Cli\Commands::class ) ) {
			Cli\Commands::init();
		}

		/**
		 * Fires once every Wahou Commerce module has registered its hooks.
		 */
		do_action( 'wahou_loaded' );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'wahou-commerce', false, dirname( WAHOU_BASENAME ) . '/languages' );
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['wahou_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Wahou Commerce – every 15 minutes',
		);
		return $schedules;
	}
}
