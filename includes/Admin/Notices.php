<?php
/**
 * Admin notices shown on store screens only (never on the rest of wp-admin).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Support\Request;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_wahou_orders' ) || ! Admin::is_store_screen() ) {
			return;
		}
		$screen       = get_current_screen();
		$is_dashboard = $screen && Admin::hook( 'dashboard' ) === $screen->id;
		$is_settings  = $screen && Admin::hook( 'settings' ) === $screen->id;
		$settings_url = admin_url( 'admin.php?page=wahou-settings&tab=payments' );

		// The dashboard checklist already covers onboarding and payments.
		if ( ! $is_dashboard && current_user_can( 'manage_wahou' ) ) {
			if ( ! get_option( 'wahou_onboarding_done' ) ) {
				self::notice(
					'info',
					__( 'Your store is almost ready: the setup wizard configures taxes, payments, shipping and pages in about 5 minutes.', 'wahou-commerce' ),
					admin_url( 'admin.php?page=' . Onboarding::SLUG ),
					__( 'Launch the setup wizard', 'wahou-commerce' )
				);
			} elseif ( empty( Gateways::available() ) && ! $is_settings ) {
				self::notice(
					'error',
					__( 'No payment method is active: customers cannot pay yet.', 'wahou-commerce' ),
					$settings_url,
					__( 'Connect Stripe or PayPal', 'wahou-commerce' )
				);
			}
		}

		if ( Dashboard::test_mode() && ! empty( Gateways::available() ) ) {
			self::notice(
				'warning',
				__( 'Payments are in test mode: orders work but no real money is collected.', 'wahou-commerce' ),
				current_user_can( 'manage_wahou' ) ? $settings_url : '',
				__( 'Switch to live mode', 'wahou-commerce' )
			);
		}

		if ( ! Request::is_https() ) {
			self::notice(
				'warning',
				__( 'Your site does not use HTTPS. Stripe, PayPal, Apple Pay and Google Pay require it for live payments, and browsers flag forms on non-secure pages.', 'wahou-commerce' ),
				'https://wordpress.org/documentation/article/https-for-wordpress/',
				__( 'How to enable HTTPS', 'wahou-commerce' )
			);
		}
	}

	private static function notice( string $type, string $message, string $url = '', string $cta = '' ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' wahou-ap-notice"><p><strong>Wahou Commerce</strong> – ' . esc_html( $message );
		if ( '' !== $url && '' !== $cta ) {
			$external = 0 !== strpos( $url, admin_url() );
			echo ' <a href="' . esc_url( $url ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $cta ) . '</a>';
		}
		echo '</p></div>';
	}
}
