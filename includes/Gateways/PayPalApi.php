<?php
/**
 * Minimal PayPal REST client (Orders v2, captures, webhooks) with an OAuth
 * token cached (encrypted) in a transient and idempotent POST requests
 * through the PayPal-Request-Id header.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class PayPalApi {

	private string $mode;
	private string $client_id;
	private string $secret;

	public function __construct( string $mode, string $client_id, string $secret ) {
		$this->mode      = 'live' === $mode ? 'live' : 'sandbox';
		$this->client_id = trim( $client_id );
		$this->secret    = trim( $secret );
	}

	public function base_url(): string {
		return 'live' === $this->mode ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private function token_key(): string {
		return 'wahou_pp_tok_' . substr( md5( $this->mode . '|' . $this->client_id . '|' . hash( 'sha256', $this->secret ) ), 0, 20 );
	}

	public function forget_token(): void {
		delete_transient( $this->token_key() );
	}

	/**
	 * OAuth2 client-credentials access token.
	 *
	 * @return string|\WP_Error
	 */
	public function token( bool $force = false ) {
		if ( '' === $this->client_id || '' === $this->secret ) {
			return new \WP_Error( 'wahou_paypal_not_configured', __( 'PayPal is not configured.', 'wahou-commerce' ) );
		}
		if ( ! $force ) {
			$cached = get_transient( $this->token_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				$token = Crypto::decrypt( $cached );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}

		$response = wp_remote_post(
			$this->base_url() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);
		if ( is_wp_error( $response ) ) {
			Logger::error( 'paypal', 'Token request failed', array( 'error' => $response->get_error_message() ) );
			return new \WP_Error( 'wahou_paypal_http', __( 'Could not reach PayPal. Please try again in a moment.', 'wahou-commerce' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 400 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			Logger::error(
				'paypal',
				'Token rejected',
				array(
					'status' => $status,
					'mode'   => $this->mode,
					'error'  => is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '',
				)
			);
			return new \WP_Error( 'wahou_paypal_auth', __( 'PayPal rejected these credentials.', 'wahou-commerce' ), array( 'status' => $status ) );
		}
		$token = (string) $body['access_token'];
		$ttl   = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
		set_transient( $this->token_key(), Crypto::encrypt( $token ), $ttl );
		return $token;
	}

	/**
	 * @param array<string, mixed>|string|null $body    Array (JSON encoded) or raw JSON string.
	 * @param array<string, string>            $headers Extra headers.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, $body = null, array $headers = array(), bool $retry = true ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Prefer'        => 'return=representation',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'paypal',
				'HTTP request failed',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return new \WP_Error( 'wahou_paypal_http', __( 'Could not reach PayPal. Please try again in a moment.', 'wahou-commerce' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $status && $retry ) {
			$this->forget_token();
			return $this->request( $method, $path, $body, $headers, false );
		}
		if ( $status >= 400 ) {
			$data    = is_array( $data ) ? $data : array();
			$detail  = is_array( $data['details'][0] ?? null ) ? $data['details'][0] : array();
			$issue   = (string) ( $detail['issue'] ?? $data['name'] ?? 'ERROR' );
			$message = (string) ( $detail['description'] ?? $data['message'] ?? __( 'PayPal returned an error.', 'wahou-commerce' ) );
			Logger::error(
				'paypal',
				'API error',
				array(
					'path'     => $path,
					'status'   => $status,
					'issue'    => $issue,
					'message'  => $message,
					'debug_id' => (string) ( $data['debug_id'] ?? '' ),
				)
			);
			return new \WP_Error(
				'wahou_paypal_' . sanitize_key( strtolower( $issue ) ),
				$message,
				array(
					'status'   => $status,
					'issue'    => $issue,
					'debug_id' => (string) ( $data['debug_id'] ?? '' ),
				)
			);
		}
		return is_array( $data ) ? $data : array();
	}

	/* ------------------------------------------------------------------
	 * Orders and captures.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_order( array $payload, string $request_id ) {
		return $this->request( 'POST', '/v2/checkout/orders', $payload, array( 'PayPal-Request-Id' => substr( $request_id, 0, 108 ) ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_order( string $id ) {
		return $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $id ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function capture_order( string $id, string $request_id ) {
		return $this->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $id ) . '/capture', '{}', array( 'PayPal-Request-Id' => substr( $request_id, 0, 108 ) ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refund_capture( string $capture_id, array $payload, string $request_id ) {
		return $this->request( 'POST', '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund', $payload, array( 'PayPal-Request-Id' => substr( $request_id, 0, 108 ) ) );
	}

	/* ------------------------------------------------------------------
	 * Webhooks.
	 * ------------------------------------------------------------------ */

	/**
	 * The body must embed the raw event JSON exactly as received.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function verify_webhook_signature( string $json ) {
		return $this->request( 'POST', '/v1/notifications/verify-webhook-signature', $json );
	}

	/**
	 * @param string[] $events
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_webhook( string $url, array $events ) {
		return $this->request(
			'POST',
			'/v1/notifications/webhooks',
			array(
				'url'         => $url,
				'event_types' => array_map(
					static fn( $name ) => array( 'name' => $name ),
					array_values( $events )
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', '/v1/notifications/webhooks' );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_webhook( string $id ) {
		return $this->request( 'DELETE', '/v1/notifications/webhooks/' . rawurlencode( $id ) );
	}
}
