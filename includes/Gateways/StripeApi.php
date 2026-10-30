<?php
/**
 * Minimal Stripe REST client (no SDK): form-encoded requests, pinned API
 * version, idempotency keys on POST and readable WP_Error objects.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class StripeApi {

	public const BASE    = 'https://api.stripe.com/v1/';
	public const VERSION = '2024-06-20';

	private string $secret;

	public function __construct( string $secret ) {
		$this->secret = trim( $secret );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, array $params = array(), string $idempotency_key = '' ) {
		if ( '' === $this->secret ) {
			return new \WP_Error( 'wahou_stripe_not_configured', __( 'Stripe is not configured.', 'wahou-commerce' ) );
		}
		$method = strtoupper( $method );
		$url    = self::BASE . ltrim( $path, '/' );
		$args   = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $this->secret,
				'Stripe-Version' => self::VERSION,
				'User-Agent'     => 'WahouCommerce/' . WAHOU_VERSION . ' (WordPress)',
			),
		);

		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( $params ) {
				$url .= '?' . self::encode( $params );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = self::encode( $params );
			if ( '' !== $idempotency_key ) {
				$args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 );
			}
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			Logger::error(
				'stripe',
				'HTTP request failed',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);
			return new \WP_Error( 'wahou_stripe_http', __( 'Could not reach Stripe. Please try again in a moment.', 'wahou-commerce' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			Logger::error(
				'stripe',
				'Invalid response',
				array(
					'path'   => $path,
					'status' => $status,
				)
			);
			return new \WP_Error( 'wahou_stripe_invalid_response', __( 'Stripe returned an unexpected response.', 'wahou-commerce' ), array( 'status' => $status ) );
		}

		if ( $status >= 400 || isset( $body['error'] ) ) {
			$error   = is_array( $body['error'] ?? null ) ? $body['error'] : array();
			$code    = (string) ( $error['code'] ?? $error['type'] ?? 'error' );
			$message = (string) ( $error['message'] ?? __( 'Stripe returned an error.', 'wahou-commerce' ) );
			Logger::error(
				'stripe',
				'API error',
				array(
					'path'         => $path,
					'status'       => $status,
					'type'         => (string) ( $error['type'] ?? '' ),
					'code'         => $code,
					'decline_code' => (string) ( $error['decline_code'] ?? '' ),
					'message'      => $message,
				)
			);
			return new \WP_Error(
				'wahou_stripe_' . sanitize_key( $code ),
				$message,
				array(
					'status' => $status,
					'stripe' => $error,
				)
			);
		}

		return $body;
	}

	/**
	 * Stripe expects "true"/"false" for booleans and bracket notation for arrays.
	 *
	 * @param array<string, mixed> $params
	 */
	public static function encode( array $params ): string {
		return http_build_query( self::normalize( $params ), '', '&', PHP_QUERY_RFC1738 );
	}

	/**
	 * @param array<mixed> $params
	 * @return array<mixed>
	 */
	private static function normalize( array $params ): array {
		foreach ( $params as $key => $value ) {
			if ( is_bool( $value ) ) {
				$params[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$params[ $key ] = self::normalize( $value );
			} elseif ( null === $value ) {
				unset( $params[ $key ] );
			}
		}
		return $params;
	}

	/* ------------------------------------------------------------------
	 * Payment intents.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_payment_intent( array $params, string $idempotency_key = '' ) {
		return $this->request( 'POST', 'payment_intents', $params, $idempotency_key );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_payment_intent( string $id, array $params ) {
		return $this->request( 'POST', 'payment_intents/' . rawurlencode( $id ), $params );
	}

	/**
	 * @param string[] $expand
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retrieve_payment_intent( string $id, array $expand = array() ) {
		return $this->request( 'GET', 'payment_intents/' . rawurlencode( $id ), $expand ? array( 'expand' => $expand ) : array() );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function confirm_payment_intent( string $id, array $params = array(), string $idempotency_key = '' ) {
		return $this->request( 'POST', 'payment_intents/' . rawurlencode( $id ) . '/confirm', $params, $idempotency_key );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function cancel_payment_intent( string $id ) {
		return $this->request( 'POST', 'payment_intents/' . rawurlencode( $id ) . '/cancel' );
	}

	/* ------------------------------------------------------------------
	 * Refunds, charges and customers.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_refund( array $params, string $idempotency_key = '' ) {
		return $this->request( 'POST', 'refunds', $params, $idempotency_key );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retrieve_charge( string $id ) {
		return $this->request( 'GET', 'charges/' . rawurlencode( $id ) );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_customer( array $params, string $idempotency_key = '' ) {
		return $this->request( 'POST', 'customers', $params, $idempotency_key );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retrieve_customer( string $id ) {
		return $this->request( 'GET', 'customers/' . rawurlencode( $id ) );
	}

	/* ------------------------------------------------------------------
	 * Account, webhooks and wallet domains.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retrieve_account() {
		return $this->request( 'GET', 'account' );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retrieve_balance() {
		return $this->request( 'GET', 'balance' );
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_webhook_endpoint( array $params ) {
		return $this->request( 'POST', 'webhook_endpoints', $params );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_webhook_endpoints() {
		return $this->request( 'GET', 'webhook_endpoints', array( 'limit' => 100 ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete_webhook_endpoint( string $id ) {
		return $this->request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $id ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_payment_method_domains( string $domain ) {
		return $this->request( 'GET', 'payment_method_domains', array( 'domain_name' => $domain ) );
	}

	/**
	 * Register a domain for Apple Pay / Google Pay / Link with Payment Element.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_payment_method_domain( string $domain ) {
		return $this->request(
			'POST',
			'payment_method_domains',
			array(
				'domain_name' => $domain,
				'enabled'     => true,
			)
		);
	}
}
