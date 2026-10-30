<?php
/**
 * Admin shell: menu, submenu order, assets, plugin links, page states and
 * flash notices shared by the admin screens.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const MENU = 'wahou';

	private const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2 4h2.6l1.9 8.2L8.7 4h2.6l2.2 8.2L15.4 4H18l-3.3 12h-2.5L10 8.1 7.8 16H5.3z"/><path fill="black" d="M17.5 0l.5 1.3 1.3.5-1.3.5-.5 1.3-.5-1.3-1.3-.5 1.3-.5z"/></svg>';

	/** @var array<string, string> Screen key => hook suffix. */
	private static array $hooks = array();

	public static function init(): void {
		foreach ( array( SettingsPage::class, ProductEditor::class, CouponEditor::class, OfferEditor::class ) as $module ) {
			if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
				$module::init();
			}
		}
		Dashboard::init();
		OrdersPage::init();
		CartsPage::init();
		Onboarding::init();
		Notices::init();

		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_menu', array( self::class, 'late_menu' ), 11 );
		add_action( 'admin_menu', array( self::class, 'reorder' ), 999 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ), 10, 2 );
		add_filter( 'plugin_action_links_' . WAHOU_BASENAME, array( self::class, 'action_links' ) );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/* ------------------------------------------------------------------
	 * Menu.
	 * ------------------------------------------------------------------ */

	public static function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( self::ICON ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		add_menu_page( 'Wahou Commerce', 'Wahou Commerce', 'manage_wahou_orders', self::MENU, array( Dashboard::class, 'render' ), $icon, 55.6 );
		self::$hooks['dashboard'] = (string) add_submenu_page( self::MENU, __( 'Store dashboard', 'wahou-commerce' ), __( 'Dashboard', 'wahou-commerce' ), 'manage_wahou_orders', self::MENU, array( Dashboard::class, 'render' ) );

		$title = __( 'Orders', 'wahou-commerce' );
		$count = OrdersPage::count( 'processing' );
		if ( $count > 0 ) {
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
				$count,
				/* translators: %d: number of orders waiting to be shipped. */
				esc_html( sprintf( _n( '%d order to fulfil', '%d orders to fulfil', $count, 'wahou-commerce' ), $count ) )
			);
		}
		self::$hooks['orders'] = (string) add_submenu_page( self::MENU, __( 'Orders', 'wahou-commerce' ), $title, 'manage_wahou_orders', OrdersPage::SLUG, array( OrdersPage::class, 'render' ) );
		add_action( 'load-' . self::$hooks['orders'], array( OrdersPage::class, 'load' ) );
	}

	/**
	 * Runs after WordPress added the custom post type lists (priority 10).
	 */
	public static function late_menu(): void {
		add_submenu_page( self::MENU, __( 'Add product', 'wahou-commerce' ), __( 'Add product', 'wahou-commerce' ), 'edit_wahou_products', 'post-new.php?post_type=' . PostTypes::PRODUCT );
		add_submenu_page( self::MENU, __( 'Product categories', 'wahou-commerce' ), __( 'Categories', 'wahou-commerce' ), 'edit_wahou_products', self::categories_slug() );

		self::$hooks['carts'] = (string) add_submenu_page( self::MENU, __( 'Abandoned carts', 'wahou-commerce' ), __( 'Abandoned carts', 'wahou-commerce' ), 'manage_wahou_orders', CartsPage::SLUG, array( CartsPage::class, 'render' ) );

		$settings_cb               = class_exists( SettingsPage::class ) && method_exists( SettingsPage::class, 'render' ) ? array( SettingsPage::class, 'render' ) : array( self::class, 'settings_unavailable' );
		self::$hooks['settings'] = (string) add_submenu_page( self::MENU, __( 'Store settings', 'wahou-commerce' ), __( 'Settings', 'wahou-commerce' ), 'manage_wahou', 'wahou-settings', $settings_cb );

		// Hidden page: reachable by URL only, rendered full screen on admin_init.
		self::$hooks['setup'] = (string) add_submenu_page( '', __( 'Setup wizard', 'wahou-commerce' ), '', 'manage_wahou', Onboarding::SLUG, array( Onboarding::class, 'render_fallback' ) );
	}

	public static function categories_slug(): string {
		return 'edit-tags.php?taxonomy=' . PostTypes::CATEGORY . '&amp;post_type=' . PostTypes::PRODUCT;
	}

	/**
	 * Put the submenu in a logical order; unknown items go before Settings.
	 */
	public static function reorder(): void {
		global $submenu;
		if ( empty( $submenu[ self::MENU ] ) || ! is_array( $submenu[ self::MENU ] ) ) {
			return;
		}
		$wanted = array(
			self::MENU,
			OrdersPage::SLUG,
			'edit.php?post_type=' . PostTypes::PRODUCT,
			'post-new.php?post_type=' . PostTypes::PRODUCT,
			self::categories_slug(),
			'edit.php?post_type=' . PostTypes::COUPON,
			'edit.php?post_type=' . PostTypes::OFFER,
			CartsPage::SLUG,
		);
		$items    = $submenu[ self::MENU ];
		$sorted   = array();
		$settings = array();
		foreach ( $wanted as $slug ) {
			foreach ( $items as $key => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					$sorted[] = $item;
					unset( $items[ $key ] );
				}
			}
		}
		foreach ( $items as $key => $item ) {
			if ( isset( $item[2] ) && 'wahou-settings' === $item[2] ) {
				$settings[] = $item;
				unset( $items[ $key ] );
			}
		}
		$submenu[ self::MENU ] = array_merge( $sorted, array_values( $items ), $settings ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Keep the Wahou menu open on product taxonomy screens.
	 *
	 * @param string|null $parent_file
	 */
	public static function parent_file( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->taxonomy, array( PostTypes::CATEGORY, PostTypes::TAG ), true ) ) {
			return self::MENU;
		}
		return $parent_file;
	}

	/**
	 * @param string|null $submenu_file
	 * @param string|null $parent_file
	 */
	public static function submenu_file( $submenu_file, $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $submenu_file;
		}
		if ( PostTypes::CATEGORY === $screen->taxonomy ) {
			return self::categories_slug();
		}
		if ( PostTypes::TAG === $screen->taxonomy ) {
			return 'edit.php?post_type=' . PostTypes::PRODUCT;
		}
		return $submenu_file;
	}

	public static function settings_unavailable(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Store settings', 'wahou-commerce' ) . '</h1><p>' . esc_html__( 'The settings screen is not available.', 'wahou-commerce' ) . '</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Screens & assets.
	 * ------------------------------------------------------------------ */

	public static function hook( string $key ): string {
		return self::$hooks[ $key ] ?? '';
	}

	/**
	 * Whether the current screen belongs to the store (Wahou pages, products,
	 * coupons, offers and product taxonomies).
	 */
	public static function is_store_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		if ( false !== strpos( (string) $screen->id, 'wahou' ) ) {
			return true;
		}
		return in_array( $screen->post_type, array( PostTypes::PRODUCT, PostTypes::COUPON, PostTypes::OFFER ), true )
			|| in_array( $screen->taxonomy, array( PostTypes::CATEGORY, PostTypes::TAG ), true );
	}

	/**
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function assets( $hook_suffix ): void {
		$mine = array_filter( array( self::hook( 'dashboard' ), self::hook( 'orders' ), self::hook( 'carts' ) ) );
		if ( ! in_array( $hook_suffix, $mine, true ) ) {
			return;
		}
		wp_enqueue_style( 'wahou-admin-pages', WAHOU_URL . 'assets/css/admin-pages.css', array(), WAHOU_VERSION );
		wp_enqueue_script( 'wahou-admin-orders', WAHOU_URL . 'assets/js/admin-orders.js', array(), WAHOU_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_localize_script(
			'wahou-admin-orders',
			'wahouAdminOrders',
			array(
				'carriers' => OrdersPage::carriers(),
				'i18n'     => array(
					/* translators: %s: refund amount. */
					'confirmRefund' => __( 'Refund %s to the customer? This cannot be undone.', 'wahou-commerce' ),
					'confirmDelete' => __( 'Delete this cart permanently?', 'wahou-commerce' ),
					'confirmBulk'   => __( 'Apply this action to the selected orders?', 'wahou-commerce' ),
					'copied'        => __( 'Copied', 'wahou-commerce' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Plugin list & pages list.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		$extra = array(
			'wahou-settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=wahou-settings' ) ) . '">' . esc_html__( 'Settings', 'wahou-commerce' ) . '</a>',
			'wahou-setup'    => '<a href="' . esc_url( admin_url( 'admin.php?page=' . Onboarding::SLUG ) ) . '">' . esc_html__( 'Setup wizard', 'wahou-commerce' ) . '</a>',
		);
		return array_merge( $extra, $links );
	}

	/**
	 * Label the store pages in Pages > All pages.
	 *
	 * @param array<string, string> $states
	 * @return array<string, string>
	 */
	public static function post_states( array $states, \WP_Post $post ): array {
		if ( 'page' !== $post->post_type ) {
			return $states;
		}
		$labels = Pages::labels();
		foreach ( array( 'shop', 'cart', 'checkout', 'account', 'terms' ) as $key ) {
			if ( (int) $post->ID === Pages::id( $key, false ) ) {
				/* translators: %s: store page role, e.g. "Checkout". */
				$states[ 'wahou_' . $key ] = sprintf( __( 'Store – %s', 'wahou-commerce' ), $labels[ $key ] );
			}
		}
		return $states;
	}

	/* ------------------------------------------------------------------
	 * Flash notices (one request lifetime, per user).
	 * ------------------------------------------------------------------ */

	public static function flash( string $message, string $type = 'success' ): void {
		$key   = 'wahou_flash_' . get_current_user_id();
		$queue = get_transient( $key );
		$queue = is_array( $queue ) ? $queue : array();
		$queue[] = array(
			'message' => $message,
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
		);
		set_transient( $key, $queue, 5 * MINUTE_IN_SECONDS );
	}

	public static function print_flash(): void {
		$key   = 'wahou_flash_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $queue as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				wp_kses( $notice['message'], array( 'a' => array( 'href' => true ), 'strong' => array() ) )
			);
		}
	}

	/**
	 * Redirect back to a screen after an admin-post action.
	 */
	public static function back( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Common guard for admin-post handlers.
	 */
	public static function guard( string $nonce_action, string $capability = 'manage_wahou_orders' ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wahou-commerce' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}
}
