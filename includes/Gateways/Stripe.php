<?php
/**
 * Stripe gateway: Payment Element (cards, Apple Pay, Google Pay, Link and
 * every method enabled in the Stripe dashboard) with the deferred intent flow,
 * Express Checkout Element, webhooks, refunds and one-click post-purchase offers.
 *
 * Settings: stripe_enabled, stripe_mode (test|live), stripe_{mode}_publishable,
 * stripe_account_name, stripe_statement_descriptor, stripe_express,
 * stripe_{mode}_webhook_id. Secrets: stripe_{mode}_secret, stripe_{mode}_webhook_secret
 * (or the WAHOU_STRIPE_{MODE}_SECRET / WAHOU_STRIPE_{MODE}_WEBHOOK_SECRET constants).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Funnels\Offers;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Rest\RestApi;
use WahouCommerce\Settings;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Stripe extends Gateway {

	public const WEBHOOK_EVENTS = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'payment_intent.processing',
		'charge.refunded',
		'charge.dispute.created',
	);

	private const SIGNATURE_TOLERANCE = 300;

	public string $id = 'stripe';

	/** @var string[] */
	protected array $supports = array( 'refunds', 'one_click_upsell', 'express' );

	/* ------------------------------------------------------------------
	 * Configuration.
	 * ------------------------------------------------------------------ */

	public static function mode(): string {
		return 'live' === Settings::str( 'stripe_mode' ) ? 'live' : 'test';
	}

	private static function normalize_mode( string $mode ): string {
		return 'live' === $mode ? 'live' : 'test';
	}

	public static function publishable_key( ?string $mode = null ): string {
		return trim( Settings::str( 'stripe_' . self::normalize_mode( $mode ?? self::mode() ) . '_publishable' ) );
	}

	public static function secret_key( ?string $mode = null ): string {
		return trim( Settings::secret( 'stripe_' . self::normalize_mode( $mode ?? self::mode() ) . '_secret' ) );
	}

	public static function webhook_secret( ?string $mode = null ): string {
		return trim( Settings::secret( 'stripe_' . self::normalize_mode( $mode ?? self::mode() ) . '_webhook_secret' ) );
	}

	public static function api( ?string $mode = null ): StripeApi {
		return new StripeApi( self::secret_key( $mode ) );
	}

	/**
	 * Saving the card is only requested when a one-click upsell can follow.
	 */
	public static function one_click_enabled(): bool {
		return Settings::bool( 'upsells_enabled' ) && class_exists( Offers::class ) && ! empty( Offers::all( 'upsell' ) );
	}

	public function title(): string {
		return __( 'Card, Apple Pay, Google Pay', 'wahou-commerce' );
	}

	public function description(): string {
		return __( 'Secure payment processed by Stripe. Your card details never touch our servers.', 'wahou-commerce' );
	}

	public function is_available(): bool {
		return Settings::bool( 'stripe_enabled' ) && '' !== self::publishable_key() && '' !== self::secret_key();
	}

	public function icons(): string {
		$visa = '<svg width="34" height="22" viewBox="0 0 34 22" aria-hidden="true" focusable="false"><rect x=".5" y=".5" width="33" height="21" rx="3.5" fill="#fff" stroke="#d6d9de"/><text x="17" y="15" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="9" font-weight="700" font-style="italic" fill="#1a1f71">VISA</text></svg>';
		$mc   = '<svg width="34" height="22" viewBox="0 0 34 22" aria-hidden="true" focusable="false"><rect x=".5" y=".5" width="33" height="21" rx="3.5" fill="#fff" stroke="#d6d9de"/><circle cx="14" cy="11" r="6" fill="#eb001b"/><circle cx="20" cy="11" r="6" fill="#f79e1b" fill-opacity=".9"/></svg>';
		$amex = '<svg width="34" height="22" viewBox="0 0 34 22" aria-hidden="true" focusable="false"><rect width="34" height="22" rx="4" fill="#1f72cd"/><text x="17" y="14.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="7.5" font-weight="700" fill="#fff">AMEX</text></svg>';
		$ap   = '<svg width="40" height="22" viewBox="0 0 40 22" aria-hidden="true" focusable="false"><rect width="40" height="22" rx="4" fill="#000"/><text x="20" y="14.5" text-anchor="middle" font-family="-apple-system,Helvetica,Arial,sans-serif" font-size="8" font-weight="600" fill="#fff">Apple Pay</text></svg>';
		$gp   = '<svg width="40" height="22" viewBox="0 0 40 22" aria-hidden="true" focusable="false"><rect x=".5" y=".5" width="39" height="21" rx="3.5" fill="#fff" stroke="#d6d9de"/><text x="20" y="14.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="600" fill="#3c4043"><tspan fill="#4285f4">G</tspan> Pay</text></svg>';
		return '<span class="wahou-gateway__icons">' . $visa . $mc . $amex . $ap . $gp . '</span>';
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			RestApi::NS,
			'/stripe/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function checkout_config(): array {
		$cart   = Cart::current();
		$totals = $cart ? $cart->totals() : null;
		return array(
			'publishableKey'   => self::publishable_key(),
			'mode'             => self::mode(),
			'currency'         => strtolower( Settings::currency() ),
			'amount'           => $totals ? (int) $totals['total'] : 0,
			'locale'           => self::stripe_locale( determine_locale() ),
			'express'          => Settings::bool( 'stripe_express' ),
			'needsShipping'    => $cart ? $cart->needs_shipping() : false,
			'phoneRequired'    => 'required' === Settings::str( 'field_phone' ),
			'setupFutureUsage' => self::one_click_enabled() ? 'off_session' : '',
			'storeName'        => Settings::store_name(),
			'country'          => Settings::store_country(),
			'appearance'       => self::appearance(),
			'i18n'             => self::js_strings(),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function js_strings(): array {
		return array(
			'loadFailed'    => __( 'The secure payment form could not load. Please disable any content blocker or choose another payment method.', 'wahou-commerce' ),
			'notReady'      => __( 'The payment form is still loading. Please try again in a moment.', 'wahou-commerce' ),
			'paymentFailed' => __( 'Your payment could not be completed. Please try again or use another payment method.', 'wahou-commerce' ),
			'authFailed'    => __( 'The authentication of your payment failed. Please try again.', 'wahou-commerce' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function appearance(): array {
		$brand  = sanitize_hex_color( Settings::str( 'brand_color' ) );
		$radius = max( 0, min( 16, Settings::int( 'radius' ) ) );
		return array(
			'theme'     => 'stripe',
			'variables' => array(
				'colorPrimary' => $brand ? $brand : '#5b3df5',
				'borderRadius' => $radius . 'px',
				// 16px inputs prevent the automatic zoom of iOS Safari.
				'fontSizeBase' => '16px',
				'spacingUnit'  => '4px',
			),
			'rules'     => array(
				'.Input' => array( 'padding' => '12px 14px' ),
			),
		);
	}

	public function payment_fields_html(): string {
		$html = '';
		if ( 'test' === self::mode() ) {
			$html .= '<p class="wahou-gateway__test">' . esc_html__( 'Test mode: use the card 4242 4242 4242 4242 with any future date and any CVC.', 'wahou-commerce' ) . '</p>';
		}
		$html .= '<div id="wahou-stripe-element" class="wahou-stripe-element" data-wahou-stripe-element></div>';
		if ( self::one_click_enabled() ) {
			$html .= '<p class="wahou-gateway__note">' . esc_html__( 'Your payment details are stored securely by Stripe so that you can add an exclusive offer to your order in one click.', 'wahou-commerce' ) . '</p>';
		}
		return $html;
	}

	public function enqueue_checkout_assets(): void {
		if ( ! $this->is_available() ) {
			return;
		}
		// Stripe.js must always be loaded from js.stripe.com (PCI requirement).
		wp_register_script( 'wahou-stripe-js', 'https://js.stripe.com/v3/', array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps = array( 'wahou-stripe-js' );
		if ( wp_script_is( 'wahou-checkout', 'registered' ) || wp_script_is( 'wahou-checkout', 'enqueued' ) ) {
			$deps[] = 'wahou-checkout';
		}
		wp_enqueue_script( 'wahou-stripe', \WahouCommerce\Support\Assets::url( 'js/gateways/stripe.js' ), $deps, WAHOU_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'wahou-stripe',
			'window.wahouStripeConfig = ' . wp_json_encode(
				array(
					'publishableKey' => self::publishable_key(),
					'locale'         => self::stripe_locale( determine_locale() ),
					'i18n'           => self::js_strings(),
				)
			) . ';',
			'before'
		);
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
		$api      = self::api( $mode );
		$amount   = $order->total();
		$currency = strtolower( $order->currency() );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'wahou_stripe_amount', __( 'Invalid order amount.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}

		// Must match the Elements configuration used in the browser.
		$sfu = array_key_exists( 'stripe_sfu', $request ) ? 'off_session' === $request['stripe_sfu'] : self::one_click_enabled();

		$params = array(
			'amount'      => $amount,
			'currency'    => $currency,
			'description' => self::description_for( $order ),
			'metadata'    => self::metadata( $order ),
		);
		$suffix = self::descriptor_suffix();
		if ( '' !== $suffix ) {
			$params['statement_descriptor_suffix'] = $suffix;
		}
		/**
		 * Whether Stripe should also email its own receipt (the store already sends a confirmation).
		 */
		if ( apply_filters( 'wahou_stripe_send_receipt', false, $order ) ) {
			$params['receipt_email'] = $order->email();
		}
		$shipping = self::shipping_details( $order );
		if ( $shipping ) {
			$params['shipping'] = $shipping;
		}

		$customer = '';
		if ( $sfu ) {
			$customer = self::customer_for( $order, $mode );
			if ( is_wp_error( $customer ) ) {
				return self::public_error( $customer );
			}
		}

		// Reuse the order's intent when the customer retries.
		$existing_id = (string) $order->get_meta( 'stripe_payment_intent', '' );
		if ( '' !== $existing_id && $mode === $order->get_meta( 'stripe_mode' ) ) {
			$existing = $api->retrieve_payment_intent( $existing_id );
			if ( ! is_wp_error( $existing ) ) {
				if ( 'succeeded' === ( $existing['status'] ?? '' ) ) {
					$this->apply_intent( $order, $existing );
					$order->refresh();
					return array(
						'action'   => 'redirect',
						'redirect' => $order->is_paid() ? Offers::after_payment_url( $order ) : $order->received_url(),
					);
				}
				if ( in_array( $existing['status'] ?? '', array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
					$update                       = $params;
					$update['setup_future_usage'] = $sfu ? 'off_session' : '';
					if ( '' !== $customer ) {
						$update['customer'] = $customer;
					}
					$updated = $api->update_payment_intent( $existing_id, $update );
					if ( ! is_wp_error( $updated ) ) {
						return $this->confirm_step( $order, $updated );
					}
				}
			}
		}

		$params['automatic_payment_methods'] = array( 'enabled' => true );
		if ( $sfu ) {
			$params['setup_future_usage'] = 'off_session';
			$params['customer']           = $customer;
		}
		$idempotency = 'wahou_pi_' . $order->id() . '_' . substr( md5( (string) wp_json_encode( array( $order->key(), $amount, $currency, $sfu, $customer, $existing_id, $mode ) ) ), 0, 16 );
		$intent      = $api->create_payment_intent( $params, $idempotency );

		// A customer deleted in Stripe: create a fresh one and retry once.
		if ( is_wp_error( $intent ) && $sfu && false !== stripos( $intent->get_error_message(), 'customer' ) ) {
			self::forget_customer( $order, $mode );
			$customer = self::customer_for( $order, $mode );
			if ( ! is_wp_error( $customer ) ) {
				$params['customer'] = $customer;
				$intent             = $api->create_payment_intent( $params, $idempotency . '_r' );
			}
		}
		if ( is_wp_error( $intent ) ) {
			return self::public_error( $intent );
		}

		$order->update_meta( 'stripe_payment_intent', (string) $intent['id'] );
		$order->update_meta( 'stripe_mode', $mode );
		$order->update( array( 'transaction_id' => (string) $intent['id'] ) );

		return $this->confirm_step( $order, $intent );
	}

	/**
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>
	 */
	private function confirm_step( Order $order, array $intent ): array {
		return array(
			'action'        => 'stripe_confirm',
			'client_secret' => (string) ( $intent['client_secret'] ?? '' ),
			'return_url'    => $order->received_url(),
			'intent'        => (string) ( $intent['id'] ?? '' ),
		);
	}

	/**
	 * Verify the payment when the customer comes back from Stripe (or while the
	 * order received page polls the order status).
	 */
	public function handle_return( Order $order ): void {
		if ( $this->id !== $order->payment_method() || $order->is_paid() || ! in_array( $order->status(), array( 'pending', 'on-hold', 'failed' ), true ) ) {
			return;
		}
		$intent_id = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified against Stripe below.
		if ( isset( $_GET['payment_intent'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '/^pi_[A-Za-z0-9]+$/', $candidate ) ) {
				$intent_id = $candidate;
			}
		}
		if ( '' === $intent_id ) {
			$intent_id = (string) $order->get_meta( 'stripe_payment_intent', '' );
		}
		if ( '' === $intent_id ) {
			return;
		}
		// Throttle polling: at most one Stripe call every 3 seconds per order.
		$lock = 'wahou_stripe_check_' . $order->id();
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 3 );

		$mode   = (string) $order->get_meta( 'stripe_mode', self::mode() );
		$intent = self::api( $mode )->retrieve_payment_intent( $intent_id );
		if ( ! is_wp_error( $intent ) ) {
			$this->apply_intent( $order, $intent );
		}
	}

	/**
	 * Apply a PaymentIntent state to an order (return URL and webhooks).
	 *
	 * @param array<string, mixed> $intent
	 */
	public function apply_intent( Order $order, array $intent ): void {
		$meta = is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : array();
		if ( (string) ( $meta['order_id'] ?? '' ) !== (string) $order->id() || ! hash_equals( $order->key(), (string) ( $meta['order_key'] ?? '' ) ) ) {
			Logger::error(
				'stripe',
				'PaymentIntent does not belong to this order',
				array(
					'order'  => $order->id(),
					'intent' => (string) ( $intent['id'] ?? '' ),
				)
			);
			return;
		}

		$intent_id = (string) ( $intent['id'] ?? '' );
		switch ( (string) ( $intent['status'] ?? '' ) ) {
			case 'succeeded':
				if ( $order->is_paid() ) {
					if ( $intent_id !== (string) $order->get_meta( 'stripe_payment_intent', '' ) ) {
						$order->add_note( sprintf( /* translators: %s: Stripe payment ID. */ __( 'Warning: a second Stripe payment (%s) succeeded for this already paid order. Refund it from your Stripe dashboard.', 'wahou-commerce' ), $intent_id ), false, 'Stripe' );
						Logger::error( 'stripe', 'Duplicate payment', array( 'order' => $order->id() ) );
					}
					return;
				}
				$received = (int) ( $intent['amount_received'] ?? $intent['amount'] ?? 0 );
				$currency = strtoupper( (string) ( $intent['currency'] ?? '' ) );
				if ( $received !== $order->total() || strtolower( $currency ) !== strtolower( $order->currency() ) ) {
					Orders::set_status(
						$order,
						'on-hold',
						sprintf(
							/* translators: 1: amount received, 2: expected amount. */
							__( 'Stripe payment amount mismatch: received %1$s, expected %2$s. Please check this order.', 'wahou-commerce' ),
							Money::format( $received, $currency ),
							Money::format( $order->total(), $order->currency() )
						)
					);
					return;
				}
				$order->update_meta( 'stripe_payment_intent', $intent_id );
				if ( 'off_session' === ( $intent['setup_future_usage'] ?? '' ) && ! empty( $intent['customer'] ) && ! empty( $intent['payment_method'] ) ) {
					$order->update_meta( 'stripe_customer', self::object_id( $intent['customer'] ) );
					$order->update_meta( 'stripe_payment_method', self::object_id( $intent['payment_method'] ) );
					$order->update_meta( 'one_click_ready', true );
				}
				$charge = self::object_id( $intent['latest_charge'] ?? '' );
				$order->payment_complete( '' !== $charge ? $charge : $intent_id );
				return;

			case 'processing':
				if ( 'on-hold' !== $order->status() ) {
					Orders::set_status( $order, 'on-hold', __( 'Stripe payment is processing. The order will be confirmed automatically.', 'wahou-commerce' ) );
				}
				return;

			case 'requires_payment_method':
				$error   = is_array( $intent['last_payment_error'] ?? null ) ? $intent['last_payment_error'] : array();
				$message = sanitize_text_field( (string) ( $error['message'] ?? '' ) );
				if ( '' !== $message && $order->get_meta( 'stripe_last_error' ) !== $message ) {
					$order->update_meta( 'stripe_last_error', $message );
					/* translators: %s: error message. */
					$order->add_note( sprintf( __( 'Stripe payment failed: %s', 'wahou-commerce' ), $message ), false, 'Stripe' );
				}
				// A delayed payment (e.g. SEPA debit) that finally failed.
				if ( 'on-hold' === $order->status() ) {
					Orders::set_status( $order, 'failed' );
				}
				return;

			case 'canceled':
				if ( $intent_id === (string) $order->get_meta( 'stripe_payment_intent', '' ) && in_array( $order->status(), array( 'pending', 'on-hold' ), true ) ) {
					Orders::set_status( $order, 'cancelled', __( 'Stripe payment was cancelled.', 'wahou-commerce' ) );
				}
				return;
		}
	}

	/* ------------------------------------------------------------------
	 * Webhooks.
	 * ------------------------------------------------------------------ */

	public function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = (string) $request->get_body();
		$header  = (string) $request->get_header( 'stripe-signature' );
		if ( '' === $payload || '' === $header ) {
			return new \WP_REST_Response( array( 'error' => 'missing_signature' ), 400 );
		}

		$verified_mode = '';
		foreach ( array( 'live', 'test' ) as $mode ) {
			$secret = self::webhook_secret( $mode );
			if ( '' !== $secret && self::verify_signature( $payload, $header, $secret ) ) {
				$verified_mode = $mode;
				break;
			}
		}
		if ( '' === $verified_mode ) {
			Logger::error( 'stripe', 'Webhook signature verification failed' );
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}
		if ( isset( $event['livemode'] ) && (bool) $event['livemode'] !== ( 'live' === $verified_mode ) ) {
			return new \WP_REST_Response( array( 'received' => true ), 200 );
		}

		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		Logger::info(
			'stripe',
			'Webhook received',
			array(
				'type' => (string) $event['type'],
				'id'   => (string) ( $event['id'] ?? '' ),
			)
		);

		switch ( (string) $event['type'] ) {
			case 'payment_intent.succeeded':
			case 'payment_intent.processing':
			case 'payment_intent.payment_failed':
				$order = self::order_from_metadata( $object );
				if ( $order ) {
					$this->apply_intent( $order, $object );
				}
				break;
			case 'charge.refunded':
				$this->webhook_refund( $object );
				break;
			case 'charge.dispute.created':
				$this->webhook_dispute( $object, $verified_mode );
				break;
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Verify a Stripe-Signature header (t=timestamp, v1=HMAC-SHA256 of "t.payload").
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = self::SIGNATURE_TOLERANCE ): bool {
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $object PaymentIntent or Charge.
	 */
	private static function order_from_metadata( array $object ): ?Order {
		$meta = is_array( $object['metadata'] ?? null ) ? $object['metadata'] : array();
		$id   = (int) ( $meta['order_id'] ?? 0 );
		if ( ! $id ) {
			return null;
		}
		$order = Order::find( $id );
		// The key check ignores events of other sites sharing the same Stripe account.
		if ( ! $order || ! hash_equals( $order->key(), (string) ( $meta['order_key'] ?? '' ) ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * @param array<string, mixed> $charge
	 */
	private static function order_from_charge( array $charge ): ?Order {
		$order = self::order_from_metadata( $charge );
		if ( ! $order && ! empty( $charge['id'] ) ) {
			$order = Order::find_by_transaction( (string) $charge['id'] );
		}
		if ( ! $order && ! empty( $charge['payment_intent'] ) ) {
			$order = Order::find_by_transaction( self::object_id( $charge['payment_intent'] ) );
		}
		return $order && 'stripe' === $order->payment_method() ? $order : null;
	}

	/**
	 * Refunds made from the Stripe dashboard are mirrored in the order (with a credit note).
	 *
	 * @param array<string, mixed> $charge
	 */
	private function webhook_refund( array $charge ): void {
		$order = self::order_from_charge( $charge );
		if ( ! $order || get_transient( 'wahou_stripe_refunding_' . $order->id() ) ) {
			return; // Refunds issued from the store are already recorded.
		}
		$diff = (int) ( $charge['amount_refunded'] ?? 0 ) - $order->refunded_total();
		if ( $diff > 0 ) {
			Orders::record_refund( $order, $diff, __( 'Refunded from the Stripe dashboard.', 'wahou-commerce' ), (string) ( $charge['id'] ?? '' ) );
		}
	}

	/**
	 * @param array<string, mixed> $dispute
	 */
	private function webhook_dispute( array $dispute, string $mode ): void {
		$order = null;
		if ( ! empty( $dispute['charge'] ) ) {
			$order = Order::find_by_transaction( self::object_id( $dispute['charge'] ) );
		}
		if ( ! $order && ! empty( $dispute['payment_intent'] ) ) {
			$intent_id = self::object_id( $dispute['payment_intent'] );
			$order     = Order::find_by_transaction( $intent_id );
			if ( ! $order ) {
				$intent = self::api( $mode )->retrieve_payment_intent( $intent_id );
				if ( ! is_wp_error( $intent ) ) {
					$order = self::order_from_metadata( $intent );
				}
			}
		}

		$amount   = Money::format( (int) ( $dispute['amount'] ?? 0 ), strtoupper( (string) ( $dispute['currency'] ?? Settings::currency() ) ) );
		$reason   = sanitize_text_field( str_replace( '_', ' ', (string) ( $dispute['reason'] ?? '' ) ) );
		$due      = ! empty( $dispute['evidence_details']['due_by'] ) ? wp_date( (string) get_option( 'date_format' ), (int) $dispute['evidence_details']['due_by'] ) : '';
		/* translators: 1: amount, 2: reason, 3: deadline. */
		$message = sprintf( __( 'Payment dispute opened by the customer\'s bank: %1$s (reason: %2$s). Respond from your Stripe dashboard before %3$s.', 'wahou-commerce' ), $amount, $reason, $due );

		if ( $order ) {
			$order->add_note( $message, false, 'Stripe' );
		}
		$subject = sprintf(
			/* translators: 1: store name, 2: order number. */
			__( '[%1$s] Payment dispute on order #%2$s', 'wahou-commerce' ),
			Settings::store_name(),
			$order ? $order->number() : '?'
		);
		$body = $message . "\n\n" . ( $order ? $order->admin_url() : 'https://dashboard.stripe.com/disputes' );
		wp_mail( Settings::store_email(), $subject, $body );
	}

	/* ------------------------------------------------------------------
	 * Refunds and one-click offers.
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|\WP_Error Stripe refund ID.
	 */
	public function refund( Order $order, int $amount, string $reason = '' ) {
		$mode   = (string) $order->get_meta( 'stripe_mode', self::mode() );
		$params = array(
			'amount'   => $amount,
			'reason'   => 'requested_by_customer',
			'metadata' => array(
				'order_id'  => (string) $order->id(),
				'order_key' => $order->key(),
				'note'      => substr( sanitize_text_field( $reason ), 0, 450 ),
			),
		);
		$intent = (string) $order->get_meta( 'stripe_payment_intent', '' );
		$txn    = $order->transaction_id();
		if ( 0 === strpos( $intent, 'pi_' ) ) {
			$params['payment_intent'] = $intent;
		} elseif ( preg_match( '/^(ch|py)_/', $txn ) ) {
			$params['charge'] = $txn;
		} else {
			return new \WP_Error( 'wahou_stripe_refund', __( 'No Stripe payment was found for this order.', 'wahou-commerce' ) );
		}

		set_transient( 'wahou_stripe_refunding_' . $order->id(), 1, MINUTE_IN_SECONDS );
		$refund = self::api( $mode )->create_refund( $params, 'wahou_refund_' . $order->id() . '_' . $order->refunded_total() . '_' . $amount );
		if ( is_wp_error( $refund ) ) {
			delete_transient( 'wahou_stripe_refunding_' . $order->id() );
			return $refund;
		}
		if ( 'failed' === ( $refund['status'] ?? '' ) ) {
			delete_transient( 'wahou_stripe_refunding_' . $order->id() );
			return new \WP_Error( 'wahou_stripe_refund_failed', __( 'Stripe could not process this refund.', 'wahou-commerce' ) );
		}
		return (string) $refund['id'];
	}

	/**
	 * Charge a post-purchase offer with the card saved during checkout.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function charge_one_click( Order $parent, Order $child ) {
		$customer = (string) $parent->get_meta( 'stripe_customer', '' );
		$method   = (string) $parent->get_meta( 'stripe_payment_method', '' );
		if ( ! $parent->get_meta( 'one_click_ready' ) || '' === $customer || '' === $method ) {
			return new \WP_Error( 'wahou_one_click_unsupported', __( 'One-click purchase is not available for this order.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$mode = (string) $parent->get_meta( 'stripe_mode', self::mode() );
		$api  = self::api( $mode );

		$params = array(
			'amount'                    => $child->total(),
			'currency'                  => strtolower( $child->currency() ),
			'customer'                  => $customer,
			'payment_method'            => $method,
			'off_session'               => true,
			'confirm'                   => true,
			'automatic_payment_methods' => array(
				'enabled'         => true,
				'allow_redirects' => 'never',
			),
			'description'               => self::description_for( $child ),
			'metadata'                  => self::metadata( $child ) + array( 'parent_order_id' => (string) $parent->id() ),
		);
		$suffix = self::descriptor_suffix();
		if ( '' !== $suffix ) {
			$params['statement_descriptor_suffix'] = $suffix;
		}
		$shipping = self::shipping_details( $parent );
		if ( $shipping ) {
			$params['shipping'] = $shipping;
		}

		$child->update_meta( 'stripe_mode', $mode );
		$intent = $api->create_payment_intent( $params, 'wahou_upsell_' . $child->id() );

		if ( ! is_wp_error( $intent ) ) {
			$child->update_meta( 'stripe_payment_intent', (string) $intent['id'] );
			$child->update( array( 'transaction_id' => (string) $intent['id'] ) );
			return self::one_click_result( $intent );
		}

		// Some banks require 3-D Secure even off session: the customer is on
		// the offer page, so confirm again on session and let Stripe.js authenticate.
		$data   = $intent->get_error_data();
		$error  = is_array( $data['stripe'] ?? null ) ? $data['stripe'] : array();
		$failed = is_array( $error['payment_intent'] ?? null ) ? $error['payment_intent'] : array();
		if ( 'authentication_required' === ( $error['code'] ?? '' ) && ! empty( $failed['id'] ) ) {
			$child->update_meta( 'stripe_payment_intent', (string) $failed['id'] );
			$child->update( array( 'transaction_id' => (string) $failed['id'] ) );
			$retry = $api->confirm_payment_intent(
				(string) $failed['id'],
				array(
					'payment_method' => $method,
					'use_stripe_sdk' => true,
				),
				'wahou_upsell_auth_' . $child->id()
			);
			if ( ! is_wp_error( $retry ) ) {
				return self::one_click_result( $retry );
			}
			$intent = $retry;
		}
		return self::public_error( $intent );
	}

	/**
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function one_click_result( array $intent ) {
		switch ( (string) ( $intent['status'] ?? '' ) ) {
			case 'succeeded':
				$charge = self::object_id( $intent['latest_charge'] ?? '' );
				return array(
					'status'         => 'succeeded',
					'transaction_id' => '' !== $charge ? $charge : (string) $intent['id'],
				);
			case 'requires_action':
				return array(
					'status'        => 'requires_action',
					'client_secret' => (string) $intent['client_secret'],
				);
			case 'processing':
				return array( 'status' => 'processing' );
		}
		return new \WP_Error( 'wahou_stripe_declined', __( 'Your payment could not be completed. Please try again or use another payment method.', 'wahou-commerce' ), array( 'status' => 402 ) );
	}

	/* ------------------------------------------------------------------
	 * Connection (settings screen and onboarding).
	 * ------------------------------------------------------------------ */

	/**
	 * Validate the keys, store them, create the webhook and register the
	 * Apple Pay / Google Pay domain.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function connect( string $mode, string $publishable, string $secret ) {
		$mode        = 'live' === $mode ? 'live' : 'test';
		$publishable = trim( $publishable );
		$secret      = trim( $secret );
		if ( '' === $secret ) {
			$secret = self::secret_key( $mode );
		}
		if ( '' === $publishable ) {
			$publishable = self::publishable_key( $mode );
		}

		if ( ! preg_match( '/^pk_' . $mode . '_[A-Za-z0-9]{8,}$/', $publishable ) ) {
			return new \WP_Error(
				'wahou_stripe_publishable_key',
				'live' === $mode ? __( 'The publishable key must start with pk_live_.', 'wahou-commerce' ) : __( 'The publishable key must start with pk_test_.', 'wahou-commerce' )
			);
		}
		if ( ! preg_match( '/^(sk|rk)_' . $mode . '_[A-Za-z0-9]{8,}$/', $secret ) ) {
			return new \WP_Error(
				'wahou_stripe_secret_key',
				'live' === $mode ? __( 'The secret key must start with sk_live_ (or rk_live_ for a restricted key).', 'wahou-commerce' ) : __( 'The secret key must start with sk_test_ (or rk_test_ for a restricted key).', 'wahou-commerce' )
			);
		}

		$api     = new StripeApi( $secret );
		$account = $api->retrieve_account();
		$name    = '';
		if ( is_wp_error( $account ) ) {
			// Restricted keys may not read the account: validate with the balance instead.
			$balance = $api->retrieve_balance();
			if ( is_wp_error( $balance ) ) {
				/* translators: %s: error returned by Stripe. */
				return new \WP_Error( 'wahou_stripe_auth', sprintf( __( 'Stripe rejected this secret key: %s', 'wahou-commerce' ), $balance->get_error_message() ) );
			}
		} else {
			$name = self::first_non_empty(
				array(
					$account['settings']['dashboard']['display_name'] ?? '',
					$account['business_profile']['name'] ?? '',
					$account['email'] ?? '',
					$account['id'] ?? '',
				)
			);
		}

		Settings::set_secret( 'stripe_' . $mode . '_secret', $secret );
		Settings::update(
			array(
				'stripe_' . $mode . '_publishable' => $publishable,
				'stripe_mode'                      => $mode,
				'stripe_enabled'                   => true,
				'stripe_account_name'              => sanitize_text_field( $name ),
			)
		);

		$webhook = self::setup_webhook( $api, $mode );
		$wallets = self::register_domain( $api );

		/* translators: %s: Stripe account name. */
		$messages = array( '' !== $name ? sprintf( __( 'Connected to the Stripe account “%s”.', 'wahou-commerce' ), $name ) : __( 'Connected to Stripe.', 'wahou-commerce' ) );
		if ( 'test' === $mode ) {
			$messages[] = __( 'Test mode: no real payment will be collected.', 'wahou-commerce' );
		}
		switch ( $webhook ) {
			case 'created':
				$messages[] = __( 'Webhook created automatically.', 'wahou-commerce' );
				break;
			case 'skipped':
				$messages[] = __( 'Webhook not created: it requires a public HTTPS site. Payments are still confirmed when customers return to your store.', 'wahou-commerce' );
				break;
			case 'constant':
				$messages[] = __( 'Webhook secret defined in wp-config.php: left unchanged.', 'wahou-commerce' );
				break;
			default:
				$messages[] = __( 'The webhook could not be created automatically. Add it in your Stripe dashboard.', 'wahou-commerce' );
		}
		if ( 'registered' === $wallets ) {
			$messages[] = __( 'Apple Pay and Google Pay are ready on this domain.', 'wahou-commerce' );
		} elseif ( 'error' === $wallets ) {
			$messages[] = __( 'The domain could not be registered for Apple Pay. Add it under Settings > Payment method domains in Stripe.', 'wahou-commerce' );
		}

		Gateways::reset();

		return array(
			'gateway'      => 'stripe',
			'mode'         => $mode,
			'account_name' => $name,
			'webhook'      => $webhook,
			'apple_pay'    => $wallets,
			'message'      => implode( ' ', $messages ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function disconnect( string $mode ): array {
		$mode       = 'live' === $mode ? 'live' : 'test';
		$webhook_id = Settings::str( 'stripe_' . $mode . '_webhook_id' );
		$secret     = self::secret_key( $mode );
		if ( '' !== $webhook_id && '' !== $secret ) {
			( new StripeApi( $secret ) )->delete_webhook_endpoint( $webhook_id );
		}
		Settings::set_secret( 'stripe_' . $mode . '_secret', '' );
		Settings::set_secret( 'stripe_' . $mode . '_webhook_secret', '' );
		$update = array(
			'stripe_' . $mode . '_publishable' => '',
			'stripe_' . $mode . '_webhook_id'  => '',
		);
		if ( $mode === self::mode() ) {
			$update['stripe_enabled']      = false;
			$update['stripe_account_name'] = '';
		}
		Settings::update( $update );
		Gateways::reset();
		return array(
			'gateway' => 'stripe',
			'mode'    => $mode,
			'message' => __( 'Stripe has been disconnected.', 'wahou-commerce' ),
		);
	}

	/**
	 * @return string created|skipped|constant|error
	 */
	private static function setup_webhook( StripeApi $api, string $mode ): string {
		if ( Settings::secret_is_constant( 'stripe_' . $mode . '_webhook_secret' ) ) {
			return 'constant';
		}
		if ( ! Connect::is_public_site() ) {
			return 'skipped';
		}
		$url  = rest_url( RestApi::NS . '/stripe/webhook' );
		$list = $api->list_webhook_endpoints();
		if ( ! is_wp_error( $list ) ) {
			foreach ( (array) ( $list['data'] ?? array() ) as $endpoint ) {
				if ( is_array( $endpoint ) && ( $endpoint['url'] ?? '' ) === $url && ! empty( $endpoint['id'] ) ) {
					// Only creation returns the signing secret: replace our previous endpoint.
					$api->delete_webhook_endpoint( (string) $endpoint['id'] );
				}
			}
		}
		$created = $api->create_webhook_endpoint(
			array(
				'url'            => $url,
				'enabled_events' => self::WEBHOOK_EVENTS,
				'api_version'    => StripeApi::VERSION,
				'description'    => 'Wahou Commerce',
				'metadata'       => array(
					'source' => 'wahou-commerce',
					'site'   => home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $created ) || empty( $created['secret'] ) || empty( $created['id'] ) ) {
			return 'error';
		}
		Settings::set_secret( 'stripe_' . $mode . '_webhook_secret', (string) $created['secret'] );
		Settings::set( 'stripe_' . $mode . '_webhook_id', (string) $created['id'] );
		return 'created';
	}

	/**
	 * @return string registered|skipped|error
	 */
	private static function register_domain( StripeApi $api ): string {
		if ( ! Connect::is_public_site() ) {
			return 'skipped';
		}
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$existing = $api->list_payment_method_domains( $host );
		if ( ! is_wp_error( $existing ) ) {
			foreach ( (array) ( $existing['data'] ?? array() ) as $domain ) {
				if ( is_array( $domain ) && ( $domain['domain_name'] ?? '' ) === $host && ! empty( $domain['enabled'] ) ) {
					return 'registered';
				}
			}
		}
		$created = $api->create_payment_method_domain( $host );
		if ( is_wp_error( $created ) ) {
			return false !== stripos( $created->get_error_message(), 'already' ) ? 'registered' : 'error';
		}
		return 'registered';
	}

	/**
	 * @return array{connected:bool, mode:string, label:string, issues:string[]}
	 */
	public function status(): array {
		$mode      = self::mode();
		$connected = '' !== self::publishable_key() && '' !== self::secret_key();
		$issues    = array();
		if ( $connected ) {
			if ( ! Settings::bool( 'stripe_enabled' ) ) {
				$issues[] = __( 'Stripe is connected but disabled.', 'wahou-commerce' );
			}
			if ( 'test' === $mode ) {
				$issues[] = __( 'Test mode is on: no real payment is collected.', 'wahou-commerce' );
			}
			if ( '' === self::webhook_secret() ) {
				$issues[] = __( 'No webhook: delayed payments and refunds made in Stripe will not sync automatically.', 'wahou-commerce' );
			}
			if ( 'live' === $mode && 0 !== strpos( home_url(), 'https://' ) ) {
				$issues[] = __( 'Live payments require HTTPS.', 'wahou-commerce' );
			}
		}
		$name = Settings::str( 'stripe_account_name' );
		return array(
			'connected' => $connected,
			'mode'      => $mode,
			'label'     => '' !== $name ? $name : 'Stripe',
			'issues'    => $issues,
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * @return string|\WP_Error
	 */
	private static function customer_for( Order $order, string $mode ) {
		$meta_key = '_wahou_stripe_customer_' . $mode;
		$user_id  = $order->customer_id();
		if ( $user_id ) {
			$saved = (string) get_user_meta( $user_id, $meta_key, true );
			if ( '' !== $saved ) {
				return $saved;
			}
		}
		$saved = (string) $order->get_meta( 'stripe_customer', '' );
		if ( '' !== $saved && $mode === $order->get_meta( 'stripe_mode', $mode ) ) {
			return $saved;
		}
		$customer = self::api( $mode )->create_customer(
			array(
				'email'    => $order->email(),
				'name'     => $order->customer_name(),
				'metadata' => array(
					'wp_user_id' => (string) $user_id,
					'source'     => 'wahou-commerce',
				),
			),
			'wahou_cus_' . $order->id() . '_' . $mode . '_' . substr( md5( $order->email() ), 0, 8 )
		);
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$id = (string) $customer['id'];
		if ( $user_id ) {
			update_user_meta( $user_id, $meta_key, $id );
		}
		$order->update_meta( 'stripe_customer', $id );
		return $id;
	}

	private static function forget_customer( Order $order, string $mode ): void {
		if ( $order->customer_id() ) {
			delete_user_meta( $order->customer_id(), '_wahou_stripe_customer_' . $mode );
		}
		$order->update_meta( 'stripe_customer', null );
	}

	/**
	 * @return array<string, string>
	 */
	private static function metadata( Order $order ): array {
		return array(
			'order_id'  => (string) $order->id(),
			'order_key' => $order->key(),
			'site'      => substr( home_url( '/' ), 0, 490 ),
			'source'    => 'wahou-commerce',
		);
	}

	private static function description_for( Order $order ): string {
		/* translators: 1: store name, 2: order number. */
		return substr( sprintf( __( '%1$s – order #%2$s', 'wahou-commerce' ), Settings::store_name(), $order->number() ), 0, 1000 );
	}

	/**
	 * Suffix appended to the account statement descriptor (card payments only).
	 */
	private static function descriptor_suffix(): string {
		$raw = remove_accents( Settings::str( 'stripe_statement_descriptor' ) );
		$raw = strtoupper( trim( (string) preg_replace( '/[^A-Za-z0-9 .\-]/', '', $raw ) ) );
		return preg_match( '/[A-Z]/', $raw ) ? substr( $raw, 0, 12 ) : '';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function shipping_details( Order $order ): ?array {
		if ( ! $order->needs_shipping() ) {
			return null;
		}
		$address = $order->shipping_address();
		if ( empty( $address['address_1'] ) || empty( $address['country'] ) ) {
			return null;
		}
		$name = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		$data = array(
			'name'    => '' !== $name ? $name : ( '' !== $order->customer_name() ? $order->customer_name() : $order->email() ),
			'address' => array_filter(
				array(
					'line1'       => (string) ( $address['address_1'] ?? '' ),
					'line2'       => (string) ( $address['address_2'] ?? '' ),
					'postal_code' => (string) ( $address['postcode'] ?? '' ),
					'city'        => (string) ( $address['city'] ?? '' ),
					'state'       => (string) ( $address['state'] ?? '' ),
					'country'     => (string) ( $address['country'] ?? '' ),
				),
				'strlen'
			),
		);
		if ( ! empty( $address['phone'] ) ) {
			$data['phone'] = (string) $address['phone'];
		}
		return $data;
	}

	/**
	 * Stripe Elements locale from a WordPress locale.
	 */
	public static function stripe_locale( string $locale ): string {
		$exact = array(
			'fr_CA' => 'fr-CA',
			'pt_BR' => 'pt-BR',
			'en_GB' => 'en-GB',
			'zh_TW' => 'zh-TW',
			'zh_HK' => 'zh-HK',
			'es_ES' => 'es',
			'nb_NO' => 'nb',
			'nn_NO' => 'nb',
		);
		if ( isset( $exact[ $locale ] ) ) {
			return $exact[ $locale ];
		}
		$lang = strtolower( substr( $locale, 0, 2 ) );
		if ( 'es' === $lang ) {
			return 'es-419';
		}
		$supported = array( 'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'et', 'fi', 'fr', 'he', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh' );
		return in_array( $lang, $supported, true ) ? $lang : 'auto';
	}

	/**
	 * @param mixed $value String ID or expanded object.
	 */
	private static function object_id( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['id'] ?? '' );
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @param array<int, mixed> $values
	 */
	private static function first_non_empty( array $values ): string {
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Customer-facing error: card errors are shown as-is, technical errors are generic.
	 */
	private static function public_error( \WP_Error $error ): \WP_Error {
		$data   = $error->get_error_data();
		$stripe = is_array( $data ) && is_array( $data['stripe'] ?? null ) ? $data['stripe'] : array();
		if ( 'card_error' === ( $stripe['type'] ?? '' ) && ! empty( $stripe['message'] ) ) {
			return new \WP_Error( 'wahou_stripe_card_error', (string) $stripe['message'], array( 'status' => 402 ) );
		}
		return new \WP_Error( 'wahou_stripe_error', __( 'Your payment could not be initialized. Please try again or use another payment method.', 'wahou-commerce' ), array( 'status' => 502 ) );
	}
}
