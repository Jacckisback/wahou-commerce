<?php
/**
 * Activation, database schema, roles and upgrades.
 *
 * Orders, carts, downloads and invoices use dedicated indexed tables (not
 * posts/postmeta) for speed. Products, coupons and offers are custom post types
 * so they work with the block editor, SEO and multilingual plugins.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce;

use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Install {

	public const PRODUCT_CAPS = array(
		'edit_wahou_products',
		'edit_others_wahou_products',
		'edit_private_wahou_products',
		'edit_published_wahou_products',
		'publish_wahou_products',
		'read_private_wahou_products',
		'delete_wahou_products',
		'delete_private_wahou_products',
		'delete_published_wahou_products',
		'delete_others_wahou_products',
	);

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
		if ( ! get_option( 'wahou_onboarding_done' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			set_transient( '_wahou_activation_redirect', 1, 60 );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wahou_cron_frequent' );
		wp_clear_scheduled_hook( 'wahou_cron_daily' );
		flush_rewrite_rules();
	}

	public static function install(): void {
		self::create_tables();
		self::create_roles();
		self::create_private_dirs();
		self::schedule_events();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, array(), '', true );
		}
		update_option( 'wahou_version', WAHOU_VERSION, true );
		update_option( 'wahou_db_version', WAHOU_DB_VERSION, true );
		update_option( 'wahou_flush_rewrite', 1, true );
	}

	/**
	 * Runs on init: upgrades schema/roles after a plugin update and flushes
	 * rewrite rules when requested.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wahou_version' ) !== WAHOU_VERSION || get_option( 'wahou_db_version' ) !== WAHOU_DB_VERSION ) {
			self::install();
			/**
			 * Fires after Wahou Commerce has been upgraded.
			 */
			do_action( 'wahou_upgraded', WAHOU_VERSION );
		}
		if ( get_option( 'wahou_flush_rewrite' ) ) {
			add_action(
				'init',
				static function () {
					delete_option( 'wahou_flush_rewrite' );
					flush_rewrite_rules( false );
				},
				999
			);
		}
		if ( ! wp_next_scheduled( 'wahou_cron_frequent' ) ) {
			self::schedule_events();
		}
	}

	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'wahou_cron_frequent' ) ) {
			wp_schedule_event( time() + 60, 'wahou_15min', 'wahou_cron_frequent' );
		}
		if ( ! wp_next_scheduled( 'wahou_cron_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:17' ) ?: time() + DAY_IN_SECONDS, 'daily', 'wahou_cron_daily' );
		}
	}

	/**
	 * @return array<string, string> table key => full table name
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			'carts'        => $wpdb->prefix . 'wahou_carts',
			'orders'       => $wpdb->prefix . 'wahou_orders',
			'order_items'  => $wpdb->prefix . 'wahou_order_items',
			'order_notes'  => $wpdb->prefix . 'wahou_order_notes',
			'downloads'    => $wpdb->prefix . 'wahou_downloads',
			'invoices'     => $wpdb->prefix . 'wahou_invoices',
			'credit_notes' => $wpdb->prefix . 'wahou_credit_notes',
		);
	}

	public static function table( string $key ): string {
		return self::tables()[ $key ];
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$t       = self::tables();

		$sql = "CREATE TABLE {$t['carts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(64) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  currency char(3) NOT NULL DEFAULT 'EUR',
  total bigint(20) NOT NULL DEFAULT 0,
  item_count int(11) NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  locale varchar(20) NOT NULL DEFAULT '',
  reminders_sent tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_reminder_at datetime DEFAULT NULL,
  unsubscribed tinyint(1) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  abandoned_at datetime DEFAULT NULL,
  converted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY status_updated (status,updated_at),
  KEY email (email),
  KEY next_reminder_at (next_reminder_at)
) $collate;
CREATE TABLE {$t['orders']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_key varchar(64) NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL DEFAULT '',
  currency char(3) NOT NULL DEFAULT 'EUR',
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount_total bigint(20) NOT NULL DEFAULT 0,
  shipping_total bigint(20) NOT NULL DEFAULT 0,
  tax_total bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  refunded_total bigint(20) NOT NULL DEFAULT 0,
  tax_mode varchar(20) NOT NULL DEFAULT '',
  tax_country char(2) NOT NULL DEFAULT '',
  coupon_code varchar(64) NOT NULL DEFAULT '',
  payment_method varchar(30) NOT NULL DEFAULT '',
  transaction_id varchar(190) NOT NULL DEFAULT '',
  billing longtext NOT NULL,
  shipping longtext NOT NULL,
  meta longtext NOT NULL,
  locale varchar(20) NOT NULL DEFAULT '',
  ip_address varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  cart_id bigint(20) unsigned NOT NULL DEFAULT 0,
  invoice_number varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_key (order_key),
  KEY status_created (status,created_at),
  KEY email (email),
  KEY customer_id (customer_id),
  KEY transaction_id (transaction_id),
  KEY parent_id (parent_id),
  KEY paid_at (paid_at)
) $collate;
CREATE TABLE {$t['order_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variant_id varchar(40) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  sku varchar(100) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'physical',
  item_type varchar(20) NOT NULL DEFAULT 'product',
  quantity int(11) NOT NULL DEFAULT 1,
  unit_price bigint(20) NOT NULL DEFAULT 0,
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount bigint(20) NOT NULL DEFAULT 0,
  tax bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  tax_rate decimal(6,3) NOT NULL DEFAULT 0,
  tax_class varchar(30) NOT NULL DEFAULT 'standard',
  meta longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY product_id (product_id)
) $collate;
CREATE TABLE {$t['order_notes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  note text NOT NULL,
  author varchar(100) NOT NULL DEFAULT '',
  is_customer tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) $collate;
CREATE TABLE {$t['downloads']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(64) NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file_id varchar(40) NOT NULL DEFAULT '',
  file_name varchar(255) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  downloads_remaining int(11) DEFAULT NULL,
  download_count int(11) NOT NULL DEFAULT 0,
  expires_at datetime DEFAULT NULL,
  last_download_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY order_id (order_id),
  KEY email (email)
) $collate;
CREATE TABLE {$t['invoices']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  number varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id)
) $collate;
CREATE TABLE {$t['credit_notes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  refund_ref varchar(64) NOT NULL DEFAULT '',
  amount bigint(20) NOT NULL DEFAULT 0,
  number varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) $collate;";

		dbDelta( $sql );
	}

	public static function create_roles(): void {
		$caps = array_merge( self::PRODUCT_CAPS, array( 'manage_wahou_orders' ) );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $caps as $cap ) {
				$admin->add_cap( $cap );
			}
			$admin->add_cap( 'manage_wahou' );
		}

		$manager_caps = array(
			'read'         => true,
			'upload_files' => true,
			'edit_posts'   => false,
			'unfiltered_html' => false,
		);
		foreach ( $caps as $cap ) {
			$manager_caps[ $cap ] = true;
		}
		if ( ! get_role( 'wahou_manager' ) ) {
			add_role( 'wahou_manager', 'Shop manager', $manager_caps );
		} else {
			$role = get_role( 'wahou_manager' );
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
		if ( ! get_role( 'wahou_customer' ) ) {
			add_role( 'wahou_customer', 'Customer', array( 'read' => true ) );
		}
	}

	public static function remove_roles(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_merge( self::PRODUCT_CAPS, array( 'manage_wahou_orders', 'manage_wahou' ) ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( 'wahou_manager' );
		remove_role( 'wahou_customer' );
	}

	/**
	 * Private folder for downloadable files (served only through signed links).
	 */
	public static function private_dir(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'wahou-private';
	}

	public static function create_private_dirs(): void {
		$dir = self::private_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		Logger::protect_dir( $dir );
		Logger::dir();
	}

	/**
	 * Create the store pages that do not exist yet. Returns key => page ID.
	 *
	 * @return array<string, int>
	 */
	public static function create_pages(): array {
		$switched = switch_to_locale( get_locale() );
		$labels   = Pages::labels();
		$content  = array(
			'shop'     => '<!-- wp:wahou/products {"pagination":true,"filters":true} /-->',
			'cart'     => '<!-- wp:wahou/cart /-->',
			'checkout' => '<!-- wp:wahou/checkout /-->',
			'account'  => '<!-- wp:wahou/account /-->',
			'terms'    => self::terms_skeleton(),
		);
		$created  = array();

		foreach ( $content as $key => $body ) {
			$existing = Settings::int( 'page_' . $key );
			if ( $existing && 'trash' !== get_post_status( $existing ) && get_post( $existing ) ) {
				$created[ $key ] = $existing;
				continue;
			}
			$page_id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => $labels[ $key ],
					'post_name'      => sanitize_title( $labels[ $key ] ),
					'post_content'   => $body,
					'comment_status' => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $page_id ) ) {
				$created[ $key ] = (int) $page_id;
				Settings::set( 'page_' . $key, (int) $page_id );
			}
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		update_option( 'wahou_flush_rewrite', 1, true );
		return $created;
	}

	/**
	 * Skeleton of the terms of sale with the sections EU law expects.
	 * It must be completed and reviewed by the merchant.
	 */
	private static function terms_skeleton(): string {
		$sections = array(
			__( 'Seller identification', 'wahou-commerce' ),
			__( 'Products and services', 'wahou-commerce' ),
			__( 'Prices and taxes', 'wahou-commerce' ),
			__( 'Ordering process', 'wahou-commerce' ),
			__( 'Payment', 'wahou-commerce' ),
			__( 'Delivery', 'wahou-commerce' ),
			__( 'Right of withdrawal (14 days)', 'wahou-commerce' ),
			__( 'Digital content: immediate supply and withdrawal', 'wahou-commerce' ),
			__( 'Legal guarantees of conformity', 'wahou-commerce' ),
			__( 'Customer service and complaints', 'wahou-commerce' ),
			__( 'Consumer mediation', 'wahou-commerce' ),
			__( 'Personal data', 'wahou-commerce' ),
			__( 'Applicable law', 'wahou-commerce' ),
		);
		$out  = '<!-- wp:paragraph --><p><strong>' . esc_html__( 'Template to complete and have reviewed before opening your store.', 'wahou-commerce' ) . '</strong></p><!-- /wp:paragraph -->';
		foreach ( $sections as $i => $title ) {
			$out .= '<!-- wp:heading --><h2 class="wp-block-heading">' . ( $i + 1 ) . '. ' . esc_html( $title ) . '</h2><!-- /wp:heading -->';
			$out .= '<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->';
		}
		return $out;
	}
}
