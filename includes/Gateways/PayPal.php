<?php
/**
 * PayPal gateway (Orders v2 + JS SDK buttons): PayPal account, Pay Later and
 * the other funding sources PayPal offers to the buyer.
 *
 * Flow: the checkout creates the store order, process_payment() creates the
 * PayPal order, the buyer approves it in the PayPal popup, then the browser
 * calls POST wahou/v1/paypal/capture which captures and verifies the amount.
 * Webhooks keep pending captures, refunds and reversals in sync.
 *
 * Settings: paypal_enabled, paypal_mode (sandbox|live), paypal_{mode}_client_id,
 * paypal_{mode}_webhook_id, paypal_paylater. Secret: paypal_{mode}_secret.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Funnels\Offers;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Rest\RestApi;
use WahouCommerce\Settings;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class PayPal extends Gateway {

	/** Currencies accepted by the PayPal REST API. */
	public const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' );

	/** Currencies PayPal only accepts without decimals. */
	private const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	public const WEBHOOK_EVENTS = array(
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.REFUNDED',
		'PAYMENT.CAPTURE.DENIED',
		'PAYMENT.CAPTURE.REVERSED',
	);

	/** Locales accepted by the PayPal JS SDK. */
	private const LOCALES = array( 'en_US', 'en_GB', 'en_AU', 'en_CA', 'fr_FR', 'fr_CA', 'fr_BE', 'fr_CH', 'es_ES', 'es_MX', 'de_DE', 'de_AT', 'de_CH', 'it_IT', 'nl_NL', 'nl_BE', 'pt_PT', 'pt_BR', 'pl_PL', 'sv_SE', 'da_DK', 'fi_FI', 'no_NO', 'cs_CZ', 'sk_SK', 'hu_HU', 'el_GR', 'ru_RU', 'ja_JP', 'zh_CN', 'zh_TW', 'zh_HK', 'ko_KR' );

	public string $id = 'paypal';

	/** @var string[] */
	protected array $supports = array( 'refunds' );

	/* ------------------------------------------------------------------
	 * Configuration.
	 * ------------------------------------------------------------------ */

	public static function mode(): string {
		return 'live' === Settings::str( 'paypal_mode' ) ? 'live' : 'sandbox';
	}

	private static function normalize_mode( string $mode ): string {
		return 'live' === $mode ? 'live' : 'sandbox';
	}

	public static function client_id( ?string $mode = null ): string {
		return trim( Settings::str( 'paypal_' . self::normalize_mode( $mode ?? self::mode() ) . '_client_id' ) );
	}

	public static function secret( ?string $mode = null ): string {
		return trim( Settings::secret( 'paypal_' . self::normalize_mode( $mode ?? self::mode() ) . '_secret' ) );
	}

	public static function webhook_id( ?string $mode = null ): string {
		return trim( Settings::str( 'paypal_' . self::normalize_mode( $mode ?? self::mode() ) . '_webhook_id' ) );
	}

	public static function api( ?string $mode = null ): PayPalApi {
		$mode = self::normalize_mode( $mode ?? self::mode() );
		return new PayPalApi( $mode, self::client_id( $mode ), self::secret( $mode ) );
	}

	public static function currency_supported( ?string $currency = null ): bool {
		return in_array( strtoupper( $currency ?? Settings::currency() ), self::CURRENCIES, true );
	}

	public function title(): string {
		return 'PayPal';
	}

	public function description(): string {
		return __( 'Pay securely with your PayPal account or the other options PayPal offers you.', 'wahou-commerce' );
	}

	public function is_available(): bool {
		return Settings::bool( 'paypal_enabled' ) && '' !== self::client_id() && '' !== self::secret() && self::currency_supported();
	}

	public function icons(): string {
		return '<span class="wahou-gateway__icons"><svg width="56" height="22" viewBox="0 0 56 22" aria-hidden="true" focusable="false"><rect x=".5" y=".5" width="55" height="21" rx="3.5" fill="#fff" stroke="#d6d9de"/><text x="28" y="15" text-anchor="middle" font-family="Verdana,Arial,sans-serif" font-size="10" font-weight="700" font-style="italic"><tspan fill="#003087">Pay</tspan><tspan fill="#009cde">Pal</tspan></text></svg></span>';
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			RestApi::NS,
			'/paypal/capture',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_capture' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_key'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'paypal_order_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			RestApi::NS,
			'/paypal/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function checkout_config(): array {
		return array(
			'clientId' => self::client_id(),
			'mode'     => self::mode(),
			'currency' => strtoupper( Settings::currency() ),
			'locale'   => self::sdk_locale(),
			'payLater' => Settings::bool( 'paypal_paylater' ),
			'lazy'     => Settings::bool( 'perf_lazy_paypal' ),
			'radius'   => Settings::int( 'radius' ),
			'i18n'     => array(
				'loadFailed'   => __( 'PayPal could not load. Please disable any content blocker or choose another payment method.', 'wahou-commerce' ),
				'notEligible'  => __( 'PayPal is not available for this order. Please choose another payment method.', 'wahou-commerce' ),
				'createFailed' => __( 'PayPal could not start the payment. Please try again.', 'wahou-commerce' ),
				'captureFailed' => __( 'Your PayPal payment could not be completed. Please try again.', 'wahou-commerce' ),
				'genericError' => __( 'Something went wrong with PayPal. Please try again or choose another payment method.', 'wahou-commerce' ),
				'useButton'    => __( 'Please use the PayPal button to complete your payment.', 'wahou-commerce' ),
			),
		);
	}

	public function payment_fields_html(): string {
		$html = '';
		if ( 'sandbox' === self::mode() ) {
			$html .= '<p class="wahou-gateway__test">' . esc_html__( 'Sandbox mode: log in with a PayPal sandbox buyer account.', 'wahou-commerce' ) . '</p>';
		}
		$html .= '<div id="wahou-paypal-buttons" class="wahou-paypal-buttons" data-wahou-paypal-buttons></div>';
		$html .= '<p class="wahou-gateway__desc">' . esc_html( $this->description() ) . '</p>';
		return $html;
	}

	public function enqueue_checkout_assets(): void {
		if ( ! $this->is_available() ) {
			return;
		}
		$deps = ( wp_script_is( 'wahou-checkout', 'registered' ) || wp_script_is( 'wahou-checkout', 'enqueued' ) ) ? array( 'wahou-checkout' ) : array();
		// The PayPal SDK itself is loaded on demand by paypal.js (lazy loading).
		wp_enqueue_script( 'wahou-paypal', WAHOU_URL . 'assets/js/gateways/paypal.js', $deps, WAHOU_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/* ------------------------------------------------------------------
	 * Payment.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function process_payment( Order $order, array $request ) {
		$mode     = self::mode();
		$currency = strtoupper( $order->currency() );
		if ( ! self::currency_supported( $currency ) ) {
			return new \WP_Error( 'wahou_paypal_currency', __( 'PayPal does not support the currency of this store.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		if ( $order->total() <= 0 ) {
			return new \WP_Error( 'wahou_paypal_amount', __( 'Invalid order amount.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}

		$funding  = sanitize_key( (string) ( $request['paypal_funding'] ?? 'paypal' ) );
		$shipping = $order->needs_shipping() ? self::shipping_address( $order ) : null;

		$unit = array(
			'reference_id' => 'wahou-' . $order->id(),
			'custom_id'    => (string) $order->id(),
			/* translators: 1: store name, 2: order number. */
			'description'  => self::truncate( sprintf( __( '%1$s – order #%2$s', 'wahou-commerce' ), Settings::store_name(), $order->number() ), 127 ),
			'amount'       => array(
				'currency_code' => $currency,
				'value'         => self::amount_value( $order->total(), $currency ),
			),
		);
		if ( $shipping ) {
			$unit['shipping'] = $shipping;
		}

		$context = array(
			'brand_name'          => self::truncate( Settings::store_name(), 127 ),
			'shipping_preference' => $shipping ? 'SET_PROVIDED_ADDRESS' : 'NO_SHIPPING',
			'user_action'         => 'PAY_NOW',
			'return_url'          => $order->received_url(),
			'cancel_url'          => Pages::url( 'checkout' ),
		);
		$locale = self::sdk_locale();
		if ( '' !== $locale ) {
			$context['locale'] = str_replace( '_', '-', $locale );
		}

		$payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array( $unit ),
		);
		if ( in_array( $funding, array( 'card', 'venmo' ), true ) ) {
			// Card / Venmo buttons: the SDK chooses the payment source.
			$payload['application_context'] = $context;
		} else {
			$paypal = array(
				'experience_context' => $context + array( 'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED' ),
			);
			if ( is_email( $order->email() ) ) {
				$paypal['email_address'] = $order->email();
			}
			$payload['payment_source'] = array( 'paypal' => $paypal );
		}

		// Same request id for an identical retry, a new one when anything changed.
		$request_id = 'wahou-' . $order->id() . '-' . $order->total() . '-' . substr( md5( (string) wp_json_encode( array( $payload, $mode, $order->key() ) ) ), 0, 12 );
		// Unique per attempt (never reuses a number already captured, even after a database reset).
		$payload['purchase_units'][0]['invoice_id'] = self::truncate( $order->number() . '-' . strtoupper( Crypto::key_string( 6 ) ), 127 );

		$result = self::api( $mode )->create_order( $payload, $request_id );
		if ( is_wp_error( $result ) ) {
			$order->add_note( sprintf( /* translators: %s: error. */ __( 'PayPal order creation failed: %s', 'wahou-commerce' ), $result->get_error_message() ), false, 'PayPal' );
			return new \WP_Error( 'wahou_paypal_create', __( 'PayPal could not start the payment. Please try again or choose another payment method.', 'wahou-commerce' ), array( 'status' => 502 ) );
		}
		$paypal_order_id = (string) ( $result['id'] ?? '' );
		if ( '' === $paypal_order_id ) {
			return new \WP_Error( 'wahou_paypal_create', __( 'PayPal could not start the payment. Please try again or choose another payment method.', 'wahou-commerce' ), array( 'status' => 502 ) );
		}

		$order->update_meta( 'paypal_order_id', $paypal_order_id );
		$order->update_meta( 'paypal_mode', $mode );

		return array(
			'action'          => 'paypal_approve',
			'paypal_order_id' => $paypal_order_id,
		);
	}

	/**
	 * POST wahou/v1/paypal/capture { order_key, paypal_order_id }
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_capture( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'paypal_capture', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wahou_rate_limited', __( 'Too many requests. Please wait a moment.', 'wahou-commerce' ), array( 'status' => 429 ) );
		}
		$order           = Order::find_by_key( (string) $request['order_key'] );
		$paypal_order_id = strtoupper( (string) $request['paypal_order_id'] );
		if ( ! $order || $this->id !== $order->payment_method() || ! preg_match( '/^[A-Z0-9]{8,40}$/', $paypal_order_id ) || ! hash_equals( (string) $order->get_meta( 'paypal_order_id', '' ), $paypal_order_id ) ) {
			return new \WP_Error( 'wahou_order_not_found', __( 'Order not found.', 'wahou-commerce' ), array( 'status' => 404 ) );
		}
		if ( ! $order->is_paid() && 'on-hold' !== $order->status() ) {
			$result = $this->capture( $order, $paypal_order_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$order->refresh();
		}
		return rest_ensure_response(
			array(
				'status'   => $order->status(),
				'paid'     => $order->is_paid(),
				'redirect' => $order->is_paid() ? Offers::after_payment_url( $order ) : $order->received_url(),
			)
		);
	}

	/**
	 * Capture an approved PayPal order and apply the result.
	 *
	 * @return true|\WP_Error
	 */
	private function capture( Order $order, string $paypal_order_id ) {
		$api    = self::api( (string) $order->get_meta( 'paypal_mode', self::mode() ) );
		$result = $api->capture_order( $paypal_order_id, 'wahou-cap-' . $order->id() . '-' . $paypal_order_id );

		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$issue = is_array( $data ) ? (string) ( $data['issue'] ?? '' ) : '';
			if ( 'ORDER_ALREADY_CAPTURED' === $issue ) {
				$result = $api->get_order( $paypal_order_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} elseif ( 'INSTRUMENT_DECLINED' === $issue ) {
				return self::declined();
			} else {
				/* translators: %s: error message. */
				$order->add_note( sprintf( __( 'PayPal capture failed: %s', 'wahou-commerce' ), $result->get_error_message() ), false, 'PayPal' );
				return new \WP_Error( 'wahou_paypal_capture', __( 'Your PayPal payment could not be completed. Please try again.', 'wahou-commerce' ), array( 'status' => 402 ) );
			}
		}

		$capture = self::first_capture( $result );
		if ( ! $capture ) {
			return new \WP_Error( 'wahou_paypal_capture', __( 'Your PayPal payment could not be completed. Please try again.', 'wahou-commerce' ), array( 'status' => 402 ) );
		}
		return $this->apply_capture( $order, $capture );
	}

	/**
	 * @param array<string, mixed> $capture
	 * @return true|\WP_Error
	 */
	private function apply_capture( Order $order, array $capture ) {
		$capture_id = (string) ( $capture['id'] ?? '' );
		$status     = strtoupper( (string) ( $capture['status'] ?? '' ) );
		$value      = (string) ( $capture['amount']['value'] ?? '' );
		$currency   = strtoupper( (string) ( $capture['amount']['currency_code'] ?? '' ) );

		if ( isset( $capture['custom_id'] ) && (string) $capture['custom_id'] !== (string) $order->id() ) {
			Logger::error(
				'paypal',
				'Capture does not belong to this order',
				array(
					'order'   => $order->id(),
					'capture' => $capture_id,
				)
			);
			return new \WP_Error( 'wahou_paypal_capture', __( 'Your PayPal payment could not be completed. Please try again.', 'wahou-commerce' ), array( 'status' => 409 ) );
		}
		if ( $currency !== strtoupper( $order->currency() ) || ! self::amount_matches( $value, $currency, $order->total() ) ) {
			Orders::set_status(
				$order,
				'on-hold',
				sprintf(
					/* translators: 1: amount received, 2: currency, 3: expected amount. */
					__( 'PayPal payment amount mismatch: received %1$s %2$s, expected %3$s. Please check this order.', 'wahou-commerce' ),
					$value,
					$currency,
					Money::format( $order->total(), $order->currency() )
				)
			);
			return new \WP_Error( 'wahou_paypal_amount', __( 'The payment amount does not match your order. We will contact you shortly.', 'wahou-commerce' ), array( 'status' => 409 ) );
		}

		if ( '' !== $capture_id ) {
			$order->update_meta( 'paypal_capture_id', $capture_id );
		}
		if ( 'COMPLETED' === $status ) {
			$order->payment_complete( $capture_id );
			return true;
		}
		if ( 'PENDING' === $status ) {
			$reason = sanitize_text_field( (string) ( $capture['status_details']['reason'] ?? '' ) );
			if ( '' !== $capture_id ) {
				$order->update( array( 'transaction_id' => $capture_id ) );
			}
			if ( 'on-hold' !== $order->status() ) {
				/* translators: %s: PayPal pending reason. */
				Orders::set_status( $order, 'on-hold', sprintf( __( 'PayPal payment pending (%s). The order will be confirmed automatically.', 'wahou-commerce' ), $reason ) );
			}
			return true;
		}

		/* translators: %s: PayPal capture status. */
		$order->add_note( sprintf( __( 'PayPal payment %s.', 'wahou-commerce' ), strtolower( $status ) ), false, 'PayPal' );
		return self::declined();
	}

	private static function declined(): \WP_Error {
		return new \WP_Error( 'wahou_paypal_declined', __( 'Your payment method was declined by PayPal. Please choose another way to pay.', 'wahou-commerce' ), array( 'status' => 402 ) );
	}

	/**
	 * Recover payments when the browser never reached the capture step.
	 */
	public function handle_return( Order $order ): void {
		if ( $this->id !== $order->payment_method() || $order->is_paid() || 'pending' !== $order->status() ) {
			return;
		}
		$paypal_order_id = (string) $order->get_meta( 'paypal_order_id', '' );
		if ( '' === $paypal_order_id ) {
			return;
		}
		$lock = 'wahou_paypal_check_' . $order->id();
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 5 );

		$remote = self::api( (string) $order->get_meta( 'paypal_mode', self::mode() ) )->get_order( $paypal_order_id );
		if ( is_wp_error( $remote ) ) {
			return;
		}
		$status = strtoupper( (string) ( $remote['status'] ?? '' ) );
		if ( 'APPROVED' === $status ) {
			$this->capture( $order, $paypal_order_id );
		} elseif ( 'COMPLETED' === $status ) {
			$capture = self::first_capture( $remote );
			if ( $capture ) {
				$this->apply_capture( $order, $capture );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Webhooks.
	 * ------------------------------------------------------------------ */

	public function rest_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! RateLimiter::hit( 'paypal_webhook', 300, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}
		$raw     = (string) $request->get_body();
		$headers = array(
			'auth_algo'         => (string) $request->get_header( 'paypal-auth-algo' ),
			'cert_url'          => (string) $request->get_header( 'paypal-cert-url' ),
			'transmission_id'   => (string) $request->get_header( 'paypal-transmission-id' ),
			'transmission_sig'  => (string) $request->get_header( 'paypal-transmission-sig' ),
			'transmission_time' => (string) $request->get_header( 'paypal-transmission-time' ),
		);
		$event   = json_decode( $raw, true );
		if ( '' === $raw || ! is_array( $event ) || in_array( '', $headers, true ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		$verified = false;
		foreach ( array( 'live', 'sandbox' ) as $mode ) {
			$webhook_id = self::webhook_id( $mode );
			if ( '' === $webhook_id || '' === self::client_id( $mode ) || '' === self::secret( $mode ) ) {
				continue;
			}
			// Built by hand so that the event is sent back byte for byte.
			$body   = '{"auth_algo":' . wp_json_encode( $headers['auth_algo'] )
				. ',"cert_url":' . wp_json_encode( $headers['cert_url'] )
				. ',"transmission_id":' . wp_json_encode( $headers['transmission_id'] )
				. ',"transmission_sig":' . wp_json_encode( $headers['transmission_sig'] )
				. ',"transmission_time":' . wp_json_encode( $headers['transmission_time'] )
				. ',"webhook_id":' . wp_json_encode( $webhook_id )
				. ',"webhook_event":' . $raw . '}';
			$result = self::api( $mode )->verify_webhook_signature( $body );
			if ( ! is_wp_error( $result ) && 'SUCCESS' === ( $result['verification_status'] ?? '' ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			Logger::error( 'paypal', 'Webhook verification failed', array( 'event' => (string) ( $event['event_type'] ?? '' ) ) );
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		$resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : array();
		Logger::info(
			'paypal',
			'Webhook received',
			array(
				'type' => (string) ( $event['event_type'] ?? '' ),
				'id'   => (string) ( $event['id'] ?? '' ),
			)
		);

		switch ( (string) ( $event['event_type'] ?? '' ) ) {
			case 'PAYMENT.CAPTURE.COMPLETED':
				$order = self::order_from_capture( $resource );
				if ( $order && ! $order->is_paid() ) {
					$this->apply_capture( $order, $resource );
				}
				break;
			case 'PAYMENT.CAPTURE.DENIED':
				$order = self::order_from_capture( $resource );
				if ( $order && ! $order->is_paid() && in_array( $order->status(), array( 'pending', 'on-hold' ), true ) ) {
					Orders::set_status( $order, 'failed', __( 'PayPal denied the payment.', 'wahou-commerce' ) );
				}
				break;
			case 'PAYMENT.CAPTURE.REFUNDED':
				$this->webhook_refunded( $resource );
				break;
			case 'PAYMENT.CAPTURE.REVERSED':
				$order = self::order_from_capture( $resource );
				if ( $order ) {
					$message = __( 'PayPal reversed this payment (chargeback or dispute). Check your PayPal Resolution Center.', 'wahou-commerce' );
					$order->add_note( $message, false, 'PayPal' );
					/* translators: 1: store name, 2: order number. */
					$subject = sprintf( __( '[%1$s] PayPal payment reversed on order #%2$s', 'wahou-commerce' ), Settings::store_name(), $order->number() );
					wp_mail( Settings::store_email(), $subject, $message . "\n\n" . $order->admin_url() );
				}
				break;
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Find the store order of a capture, making sure it belongs to this site.
	 *
	 * @param array<string, mixed> $capture
	 */
	private static function order_from_capture( array $capture ): ?Order {
		$order = null;
		if ( ! empty( $capture['custom_id'] ) && ctype_digit( (string) $capture['custom_id'] ) ) {
			$order = Order::find( (int) $capture['custom_id'] );
		}
		if ( ! $order && ! empty( $capture['id'] ) ) {
			$order = Order::find_by_transaction( (string) $capture['id'] );
		}
		if ( ! $order || 'paypal' !== $order->payment_method() ) {
			return null;
		}
		// Several sites may share one PayPal account: the PayPal order must match.
		$related = (string) ( $capture['supplementary_data']['related_ids']['order_id'] ?? self::linked_id( $capture, 'up', '/checkout/orders/' ) );
		if ( '' !== $related ) {
			return hash_equals( (string) $order->get_meta( 'paypal_order_id', '' ), $related ) ? $order : null;
		}
		return (string) $order->get_meta( 'paypal_capture_id', $order->transaction_id() ) === (string) ( $capture['id'] ?? '' ) ? $order : null;
	}

	/**
	 * Refunds made from the PayPal dashboard are mirrored in the order.
	 *
	 * @param array<string, mixed> $refund
	 */
	private function webhook_refunded( array $refund ): void {
		$capture_id = self::linked_id( $refund, 'up', '/captures/' );
		if ( '' === $capture_id ) {
			return;
		}
		$order = Order::find_by_transaction( $capture_id );
		if ( ! $order || 'paypal' !== $order->payment_method() || get_transient( 'wahou_paypal_refunding_' . $order->id() ) ) {
			return;
		}
		$refund_id = (string) ( $refund['id'] ?? '' );
		foreach ( (array) $order->get_meta( 'refunds', array() ) as $entry ) {
			if ( is_array( $entry ) && '' !== $refund_id && ( $entry['reference'] ?? '' ) === $refund_id ) {
				return;
			}
		}
		$currency = strtoupper( (string) ( $refund['amount']['currency_code'] ?? $order->currency() ) );
		$amount   = Money::to_minor( (string) ( $refund['amount']['value'] ?? '0' ), $currency );
		if ( $amount > 0 ) {
			Orders::record_refund( $order, $amount, __( 'Refunded from the PayPal dashboard.', 'wahou-commerce' ), $refund_id );
		}
	}

	/**
	 * ID at the end of a HATEOAS link (e.g. rel "up" => .../captures/{id}).
	 *
	 * @param array<string, mixed> $resource
	 */
	private static function linked_id( array $resource, string $rel, string $segment ): string {
		foreach ( (array) ( $resource['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) && ( $link['rel'] ?? '' ) === $rel && false !== strpos( (string) ( $link['href'] ?? '' ), $segment ) ) {
				$path = (string) wp_parse_url( (string) $link['href'], PHP_URL_PATH );
				return (string) basename( $path );
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Refunds.
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|\WP_Error PayPal refund ID.
	 */
	public function refund( Order $order, int $amount, string $reason = '' ) {
		$capture_id = (string) $order->get_meta( 'paypal_capture_id', $order->transaction_id() );
		if ( '' === $capture_id ) {
			return new \WP_Error( 'wahou_paypal_refund', __( 'No PayPal payment was found for this order.', 'wahou-commerce' ) );
		}
		$currency = strtoupper( $order->currency() );
		$payload  = array(
			'amount' => array(
				'value'         => self::amount_value( $amount, $currency ),
				'currency_code' => $currency,
			),
		);
		$note     = trim( sanitize_text_field( $reason ) );
		if ( '' !== $note ) {
			$payload['note_to_payer'] = self::truncate( $note, 255 );
		}

		set_transient( 'wahou_paypal_refunding_' . $order->id(), 1, MINUTE_IN_SECONDS );
		$api    = self::api( (string) $order->get_meta( 'paypal_mode', self::mode() ) );
		$result = $api->refund_capture( $capture_id, $payload, 'wahou-ref-' . $order->id() . '-' . $order->refunded_total() . '-' . $amount );
		if ( is_wp_error( $result ) ) {
			delete_transient( 'wahou_paypal_refunding_' . $order->id() );
			return $result;
		}
		if ( in_array( strtoupper( (string) ( $result['status'] ?? '' ) ), array( 'CANCELLED', 'FAILED' ), true ) ) {
			delete_transient( 'wahou_paypal_refunding_' . $order->id() );
			return new \WP_Error( 'wahou_paypal_refund_failed', __( 'PayPal could not process this refund.', 'wahou-commerce' ) );
		}
		return (string) ( $result['id'] ?? '' );
	}

	/* ------------------------------------------------------------------
	 * Connection (settings screen and onboarding).
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function connect( string $mode, string $client_id, string $secret ) {
		$mode      = in_array( $mode, array( 'live', 'production' ), true ) ? 'live' : 'sandbox';
		$client_id = trim( $client_id );
		$secret    = trim( $secret );
		if ( '' === $client_id ) {
			$client_id = self::client_id( $mode );
		}
		if ( '' === $secret ) {
			$secret = self::secret( $mode );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,128}$/', $client_id ) ) {
			return new \WP_Error( 'wahou_paypal_client_id', __( 'This PayPal client ID does not look valid. Copy it from your PayPal developer dashboard (Apps & Credentials).', 'wahou-commerce' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,128}$/', $secret ) ) {
			return new \WP_Error( 'wahou_paypal_secret', __( 'This PayPal secret does not look valid. Copy it from your PayPal developer dashboard (Apps & Credentials).', 'wahou-commerce' ) );
		}

		$api   = new PayPalApi( $mode, $client_id, $secret );
		$token = $api->token( true );
		if ( is_wp_error( $token ) ) {
			return new \WP_Error(
				'wahou_paypal_auth',
				'live' === $mode
					? __( 'PayPal rejected these live credentials. Check that you copied the Live (not Sandbox) client ID and secret.', 'wahou-commerce' )
					: __( 'PayPal rejected these sandbox credentials. Check that you copied the Sandbox client ID and secret.', 'wahou-commerce' )
			);
		}

		Settings::set_secret( 'paypal_' . $mode . '_secret', $secret );
		Settings::update(
			array(
				'paypal_' . $mode . '_client_id' => $client_id,
				'paypal_mode'                    => $mode,
				'paypal_enabled'                 => true,
			)
		);

		$webhook  = self::setup_webhook( $api, $mode );
		$messages = array( __( 'Connected to PayPal.', 'wahou-commerce' ) );
		if ( 'sandbox' === $mode ) {
			$messages[] = __( 'Sandbox mode: no real payment will be collected.', 'wahou-commerce' );
		}
		switch ( $webhook ) {
			case 'created':
				$messages[] = __( 'Webhook created automatically.', 'wahou-commerce' );
				break;
			case 'exists':
				$messages[] = __( 'Existing webhook reused.', 'wahou-commerce' );
				break;
			case 'skipped':
				$messages[] = __( 'Webhook not created: it requires a public HTTPS site. Payments are still confirmed instantly at checkout.', 'wahou-commerce' );
				break;
			default:
				$messages[] = __( 'The webhook could not be created automatically. Add it in your PayPal developer dashboard.', 'wahou-commerce' );
		}
		if ( ! self::currency_supported() ) {
			/* translators: %s: currency code. */
			$messages[] = sprintf( __( 'Warning: PayPal does not support your store currency (%s).', 'wahou-commerce' ), Settings::currency() );
		}

		Gateways::reset();

		return array(
			'gateway'      => 'paypal',
			'mode'         => $mode,
			'account_name' => '',
			'webhook'      => $webhook,
			'message'      => implode( ' ', $messages ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function disconnect( string $mode ): array {
		$mode       = self::normalize_mode( 'test' === $mode ? 'sandbox' : $mode );
		$webhook_id = self::webhook_id( $mode );
		if ( '' !== $webhook_id && '' !== self::client_id( $mode ) && '' !== self::secret( $mode ) ) {
			self::api( $mode )->delete_webhook( $webhook_id );
		}
		self::api( $mode )->forget_token();
		Settings::set_secret( 'paypal_' . $mode . '_secret', '' );
		$update = array(
			'paypal_' . $mode . '_client_id'  => '',
			'paypal_' . $mode . '_webhook_id' => '',
		);
		if ( $mode === self::mode() ) {
			$update['paypal_enabled'] = false;
		}
		Settings::update( $update );
		Gateways::reset();
		return array(
			'gateway' => 'paypal',
			'mode'    => $mode,
			'message' => __( 'PayPal has been disconnected.', 'wahou-commerce' ),
		);
	}

	/**
	 * @return string created|exists|skipped|error
	 */
	private static function setup_webhook( PayPalApi $api, string $mode ): string {
		if ( ! Connect::is_public_site() ) {
			return 'skipped';
		}
		$url  = rest_url( RestApi::NS . '/paypal/webhook' );
		$list = $api->list_webhooks();
		if ( ! is_wp_error( $list ) ) {
			foreach ( (array) ( $list['webhooks'] ?? array() ) as $hook ) {
				if ( is_array( $hook ) && ( $hook['url'] ?? '' ) === $url && ! empty( $hook['id'] ) ) {
					Settings::set( 'paypal_' . $mode . '_webhook_id', (string) $hook['id'] );
					return 'exists';
				}
			}
		}
		$created = $api->create_webhook( $url, self::WEBHOOK_EVENTS );
		if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
			return 'error';
		}
		Settings::set( 'paypal_' . $mode . '_webhook_id', (string) $created['id'] );
		return 'created';
	}

	/**
	 * @return array{connected:bool, mode:string, label:string, issues:string[]}
	 */
	public function status(): array {
		$mode      = self::mode();
		$connected = '' !== self::client_id() && '' !== self::secret();
		$issues    = array();
		if ( $connected ) {
			if ( ! Settings::bool( 'paypal_enabled' ) ) {
				$issues[] = __( 'PayPal is connected but disabled.', 'wahou-commerce' );
			}
			if ( 'sandbox' === $mode ) {
				$issues[] = __( 'Sandbox mode is on: no real payment is collected.', 'wahou-commerce' );
			}
			if ( '' === self::webhook_id() ) {
				$issues[] = __( 'No webhook: pending payments and refunds made in PayPal will not sync automatically.', 'wahou-commerce' );
			}
			if ( ! self::currency_supported() ) {
				/* translators: %s: currency code. */
				$issues[] = sprintf( __( 'PayPal does not support your store currency (%s).', 'wahou-commerce' ), Settings::currency() );
			}
		}
		return array(
			'connected' => $connected,
			'mode'      => $mode,
			'label'     => 'PayPal',
			'issues'    => $issues,
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * PayPal amount string, e.g. 1990 EUR => "19.90", 123456 HUF => "1235".
	 */
	public static function amount_value( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );
		if ( in_array( $currency, self::NO_DECIMALS, true ) ) {
			return (string) (int) round( $minor / ( 10 ** Money::decimals( $currency ) ) );
		}
		return Money::to_decimal( $minor, $currency );
	}

	public static function amount_matches( string $value, string $currency, int $expected_minor ): bool {
		$currency = strtoupper( $currency );
		$decimals = in_array( $currency, self::NO_DECIMALS, true ) ? 0 : Money::decimals( $currency );
		$received = number_format( (float) $value, $decimals, '.', '' );
		return '' !== $value && $received === self::amount_value( $expected_minor, $currency );
	}

	/**
	 * @param array<string, mixed> $order PayPal order.
	 * @return array<string, mixed>|null
	 */
	private static function first_capture( array $order ): ?array {
		$capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
		return is_array( $capture ) ? $capture : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function shipping_address( Order $order ): ?array {
		$address = $order->shipping_address();
		if ( empty( $address['address_1'] ) || empty( $address['country'] ) ) {
			return null;
		}
		$name = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		return array(
			'name'    => array( 'full_name' => self::truncate( '' !== $name ? $name : $order->customer_name(), 300 ) ),
			'address' => array_filter(
				array(
					'address_line_1' => self::truncate( (string) ( $address['address_1'] ?? '' ), 300 ),
					'address_line_2' => self::truncate( (string) ( $address['address_2'] ?? '' ), 300 ),
					'admin_area_2'   => self::truncate( (string) ( $address['city'] ?? '' ), 120 ),
					'admin_area_1'   => self::truncate( (string) ( $address['state'] ?? '' ), 300 ),
					'postal_code'    => self::truncate( (string) ( $address['postcode'] ?? '' ), 60 ),
					'country_code'   => strtoupper( (string) ( $address['country'] ?? '' ) ),
				),
				'strlen'
			),
		);
	}

	/**
	 * JS SDK locale (fr_FR...), empty to let PayPal detect it.
	 */
	public static function sdk_locale(): string {
		$locale = determine_locale();
		if ( in_array( $locale, self::LOCALES, true ) ) {
			return $locale;
		}
		$lang = strtolower( substr( $locale, 0, 2 ) );
		foreach ( self::LOCALES as $candidate ) {
			if ( 0 === strpos( $candidate, $lang . '_' ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private static function truncate( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
