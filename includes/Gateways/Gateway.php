<?php
/**
 * Base class for payment gateways.
 *
 * Server contract
 *  - process_payment() is called by the checkout after the order was created.
 *    It returns an array describing the next client-side step:
 *      [ 'action' => 'stripe_confirm', 'client_secret' => '...', 'return_url' => '...' ]
 *      [ 'action' => 'paypal_approve', 'paypal_order_id' => '...' ]
 *      [ 'action' => 'redirect', 'redirect' => 'https://...' ]
 *  - handle_return() runs when the customer lands on the order received page
 *    (verify the payment with the provider and call $order->payment_complete()).
 *  - refund() returns the provider refund reference (string) or a WP_Error.
 *
 * Client contract (assets/js/checkout.js): each gateway script calls
 *   window.WahouCheckout.registerGateway( id, {
 *     ownsSubmit: bool,            // true when the gateway renders its own pay button (PayPal)
 *     mount( ctx ),                // ctx = { container, config, totals, api }
 *     onTotalsChange( totals ),
 *     async validate(),            // throw Error( message ) to block
 *     async pay( payment, api ),   // payment = process_payment() result
 *     expressMount( ctx ),         // optional express buttons (#wahou-express)
 *     unmount()
 *   } );
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Orders\Order;

defined( 'ABSPATH' ) || exit;

abstract class Gateway {

	/** Unique gateway ID (stripe, paypal, bank). */
	public string $id = '';

	/**
	 * Features: refunds, one_click_upsell, express.
	 *
	 * @var string[]
	 */
	protected array $supports = array();

	abstract public function title(): string;

	public function description(): string {
		return '';
	}

	/**
	 * Enabled in settings and fully configured.
	 */
	abstract public function is_available(): bool;

	public function supports( string $feature ): bool {
		return in_array( $feature, $this->supports, true );
	}

	/**
	 * Inline SVG/IMG markup of accepted payment brands.
	 */
	public function icons(): string {
		return '';
	}

	/**
	 * Hooks registration (REST routes, webhooks...). Called once at boot.
	 */
	public function register(): void {
	}

	/**
	 * Enqueue the gateway script on the checkout page (depends on wahou-checkout).
	 */
	public function enqueue_checkout_assets(): void {
	}

	/**
	 * Public configuration passed to the gateway script (never secrets).
	 *
	 * @return array<string, mixed>
	 */
	public function checkout_config(): array {
		return array();
	}

	/**
	 * Markup inside the gateway panel at checkout.
	 */
	public function payment_fields_html(): string {
		$description = $this->description();
		return '' !== $description ? '<p class="wahou-gateway__desc">' . esc_html( $description ) . '</p>' : '';
	}

	/**
	 * @param array<string, mixed> $request Sanitized checkout request.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract public function process_payment( Order $order, array $request );

	public function handle_return( Order $order ): void {
	}

	/**
	 * Extra HTML for the order received page and emails (e.g. bank transfer details).
	 */
	public function thankyou_html( Order $order ): string {
		return '';
	}

	/**
	 * @return string|\WP_Error Provider refund reference.
	 */
	public function refund( Order $order, int $amount, string $reason = '' ) {
		return new \WP_Error( 'wahou_refund_unsupported', __( 'This payment method does not support automatic refunds.', 'wahou-commerce' ) );
	}

	/**
	 * Charge a post-purchase offer without asking for payment details again.
	 *
	 * @return array<string, mixed>|\WP_Error [ 'status' => 'succeeded' ] or
	 *         [ 'status' => 'requires_action', 'client_secret' => '...' ]
	 */
	public function charge_one_click( Order $parent, Order $child ) {
		return new \WP_Error( 'wahou_one_click_unsupported', __( 'One-click purchase is not available for this payment method.', 'wahou-commerce' ) );
	}

	/**
	 * Settings summary for admin screens: connected / test mode / issues.
	 *
	 * @return array{connected:bool, mode:string, label:string, issues:string[]}
	 */
	public function status(): array {
		return array(
			'connected' => $this->is_available(),
			'mode'      => 'live',
			'label'     => $this->title(),
			'issues'    => array(),
		);
	}
}
