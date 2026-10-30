<?php
/**
 * Request helpers (client IP, geolocation header, user agent).
 *
 * Proxy headers are only trusted when WAHOU_TRUST_PROXY is true in
 * wp-config.php (e.g. behind Cloudflare or a load balancer), otherwise a
 * visitor could spoof their IP to bypass rate limits.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Request {

	public static function ip(): string {
		$candidates = array();
		if ( defined( 'WAHOU_TRUST_PROXY' ) && WAHOU_TRUST_PROXY ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$candidates[] = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				}
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		foreach ( $candidates as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return (string) apply_filters( 'wahou_client_ip', $ip );
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Visitor country from a CDN header when available (VAT evidence for digital sales).
	 */
	public static function geo_country(): string {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$code = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 0, 2 ) );
				if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code && 'T1' !== $code ) {
					return (string) apply_filters( 'wahou_geo_country', $code );
				}
			}
		}
		return (string) apply_filters( 'wahou_geo_country', '' );
	}

	public static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	}

	public static function is_https(): bool {
		return is_ssl() || 0 === strpos( home_url(), 'https://' );
	}
}
