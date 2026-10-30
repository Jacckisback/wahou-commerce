<?php
/**
 * Admin AJAX endpoints used by the settings screen and the setup wizard to
 * connect / disconnect Stripe and PayPal in one click.
 *
 *   action=wahou_gateway_connect     { _ajax_nonce, gateway, mode, public_key, secret }
 *   action=wahou_gateway_disconnect  { _ajax_nonce, gateway, mode }
 *
 * Nonce action: wahou_gateway_connect. Capability: manage_wahou.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

defined( 'ABSPATH' ) || exit;

final class Connect {

	public static function init(): void {
		add_action( 'wp_ajax_wahou_gateway_connect', array( self::class, 'ajax_connect' ) );
		add_action( 'wp_ajax_wahou_gateway_disconnect', array( self::class, 'ajax_disconnect' ) );
	}

	public static function ajax_connect(): void {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$public  = isset( $_POST['public_key'] ) ? self::clean_key( wp_unslash( $_POST['public_key'] ) ) : '';
		$secret  = isset( $_POST['secret'] ) ? self::clean_key( wp_unslash( $_POST['secret'] ) ) : '';
		// phpcs:enable

		switch ( $gateway ) {
			case 'stripe':
				$result = Stripe::connect( 'live' === $mode ? 'live' : 'test', $public, $secret );
				break;
			case 'paypal':
				$result = PayPal::connect( 'live' === $mode ? 'live' : 'sandbox', $public, $secret );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown payment method.', 'wahou-commerce' ) ), 400 );
				return;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}
		wp_send_json_success( $result );
	}

	public static function ajax_disconnect(): void {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		// phpcs:enable

		switch ( $gateway ) {
			case 'stripe':
				wp_send_json_success( Stripe::disconnect( 'live' === $mode ? 'live' : 'test' ) );
				break;
			case 'paypal':
				wp_send_json_success( PayPal::disconnect( 'live' === $mode ? 'live' : 'sandbox' ) );
				break;
		}
		wp_send_json_error( array( 'message' => __( 'Unknown payment method.', 'wahou-commerce' ) ), 400 );
	}

	private static function guard(): void {
		if ( ! check_ajax_referer( 'wahou_gateway_connect', false, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'wahou-commerce' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage payment settings.', 'wahou-commerce' ) ), 403 );
		}
	}

	/**
	 * API keys only contain letters, digits, "_" and "-" (removes pasted spaces and quotes).
	 *
	 * @param mixed $value
	 */
	private static function clean_key( $value ): string {
		return is_string( $value ) ? (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ) : '';
	}

	/**
	 * Webhooks and wallet domains need a public HTTPS address.
	 */
	public static function is_public_site(): bool {
		$home   = home_url( '/' );
		$scheme = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return false;
		}
		if ( preg_match( '/\.(local|test|localhost|invalid|example|lan|internal|home\.arpa)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return (bool) apply_filters( 'wahou_is_public_site', true, $host );
	}
}
