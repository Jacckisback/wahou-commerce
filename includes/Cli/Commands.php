<?php
/**
 * WP-CLI commands: wp wahou status | cron | pages
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Cli;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function init(): void {
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wahou', StoreCommand::class );
		}
	}
}

/**
 * Manage Wahou Commerce from the command line.
 */
final class StoreCommand {

	/**
	 * Show the store configuration and health.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wahou status
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function status( $args, $assoc_args ): void {
		global $wpdb;
		$rows = array(
			array(
				'key'   => 'Version',
				'value' => WAHOU_VERSION . ' (db ' . (string) get_option( 'wahou_db_version' ) . ')',
			),
			array(
				'key'   => 'Store',
				'value' => Settings::store_name() . ' – ' . Settings::store_country() . ' – ' . Settings::currency(),
			),
			array(
				'key'   => 'Tax',
				'value' => Settings::bool( 'tax_enabled' ) ? ( Settings::bool( 'tax_exempt' ) ? 'exempt' : ( Settings::bool( 'tax_oss' ) ? 'OSS (destination rates)' : 'origin rates' ) ) . ( Settings::bool( 'prices_include_tax' ) ? ', prices incl. VAT' : ', prices excl. VAT' ) : 'disabled',
			),
		);
		foreach ( Gateways::all() as $gateway ) {
			$status = $gateway->status();
			$rows[] = array(
				'key'   => 'Gateway ' . $gateway->id,
				'value' => ( $gateway->is_available() ? 'available' : 'off' ) . ' (' . $status['mode'] . ')' . ( $status['issues'] ? ' – ' . implode( '; ', $status['issues'] ) : '' ),
			);
		}
		foreach ( Pages::KEYS as $key ) {
			$id     = Pages::id( $key, false );
			$rows[] = array(
				'key'   => 'Page ' . $key,
				'value' => $id ? $id . ' ' . (string) get_permalink( $id ) : '–',
			);
		}
		$counts = wp_count_posts( PostTypes::PRODUCT );
		$rows[] = array(
			'key'   => 'Products',
			'value' => (string) (int) ( $counts->publish ?? 0 ) . ' published',
		);
		$orders = Install::table( 'orders' );
		$carts  = Install::table( 'carts' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$orders} GROUP BY status", ARRAY_A ) as $row ) {
			$rows[] = array(
				'key'   => 'Orders ' . $row['status'],
				'value' => (string) $row['n'],
			);
		}
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$carts} GROUP BY status", ARRAY_A ) as $row ) {
			$rows[] = array(
				'key'   => 'Carts ' . $row['status'],
				'value' => (string) $row['n'],
			);
		}
		// phpcs:enable
		$next   = wp_next_scheduled( 'wahou_cron_frequent' );
		$rows[] = array(
			'key'   => 'Next cron',
			'value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled',
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Run the scheduled tasks now (cart recovery, cleanups).
	 *
	 * ## OPTIONS
	 *
	 * [--daily]
	 * : Also run the daily tasks.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wahou cron
	 *     wp wahou cron --daily
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function cron( $args, $assoc_args ): void {
		do_action( 'wahou_cron_frequent' );
		\WP_CLI::log( 'wahou_cron_frequent done.' );
		if ( ! empty( $assoc_args['daily'] ) ) {
			do_action( 'wahou_cron_daily' );
			\WP_CLI::log( 'wahou_cron_daily done.' );
		}
		\WP_CLI::success( 'Scheduled tasks executed.' );
	}

	/**
	 * Create the missing store pages (shop, cart, checkout, account, terms).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wahou pages
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function pages( $args, $assoc_args ): void {
		$pages = Install::create_pages();
		$rows  = array();
		foreach ( $pages as $key => $id ) {
			$rows[] = array(
				'page' => $key,
				'id'   => $id,
				'url'  => (string) get_permalink( $id ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'page', 'id', 'url' ) );
		\WP_CLI::success( 'Store pages are ready.' );
	}
}
