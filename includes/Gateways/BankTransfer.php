<?php
/**
 * Bank transfer (SEPA credit transfer): the order is placed "on hold", the
 * items are reserved and the customer receives the bank details with the
 * order number as payment reference. Unpaid orders are cancelled after
 * "bank_hold_days" (see Orders::cancel_stale()).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Gateways;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class BankTransfer extends Gateway {

	public string $id = 'bank';

	/** @var string[] */
	protected array $supports = array();

	public function title(): string {
		return __( 'Bank transfer', 'wahou-commerce' );
	}

	public function description(): string {
		return __( 'Pay by bank transfer. Your order is processed as soon as we receive the payment.', 'wahou-commerce' );
	}

	public static function iban(): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', Settings::str( 'bank_iban' ) ) );
	}

	public static function bic(): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', Settings::str( 'bank_bic' ) ) );
	}

	/**
	 * "FR7630006000011234567890189" => "FR76 3000 6000 0112 3456 7890 189".
	 */
	public static function format_iban( string $iban ): string {
		return trim( chunk_split( $iban, 4, ' ' ) );
	}

	public function is_available(): bool {
		return Settings::bool( 'bank_enabled' ) && '' !== self::iban();
	}

	public function icons(): string {
		return '<span class="wahou-gateway__icons"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2 2 7v2h20V7L12 2zm-7 9v7h2v-7H5zm4 0v7h2v-7H9zm4 0v7h2v-7h-2zm4 0v7h2v-7h-2zM2 20v2h20v-2H2z"/></svg></span>';
	}

	public function payment_fields_html(): string {
		$days = max( 1, Settings::int( 'bank_hold_days' ) );
		return '<p class="wahou-gateway__desc">' . esc_html( $this->description() ) . '</p>'
			. '<p class="wahou-gateway__note">' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Our bank details are shown after you place the order. Items are reserved for %d day.', 'Our bank details are shown after you place the order. Items are reserved for %d days.', $days, 'wahou-commerce' ),
					$days
				)
			) . '</p>';
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function process_payment( Order $order, array $request ) {
		// Reserve the items while the transfer is on its way (released if the order is cancelled).
		Orders::reduce_stock( $order );
		Orders::set_status( $order, 'on-hold', __( 'Awaiting bank transfer.', 'wahou-commerce' ) );

		// The order is placed: empty the cart and stop abandoned cart reminders.
		if ( $order->cart_id() ) {
			Cart::mark_converted( $order->cart_id(), $order->id() );
		}

		return array(
			'action'   => 'redirect',
			'redirect' => $order->received_url(),
		);
	}

	/**
	 * Bank details for the order received page and emails.
	 */
	public function thankyou_html( Order $order ): string {
		if ( ! in_array( $order->status(), array( 'on-hold', 'pending' ), true ) ) {
			return '';
		}
		$rows = array(
			__( 'Amount', 'wahou-commerce' )         => Money::format( $order->total(), $order->currency() ),
			__( 'Account holder', 'wahou-commerce' ) => Settings::str( 'bank_account_holder' ),
			__( 'IBAN', 'wahou-commerce' )           => self::format_iban( self::iban() ),
			__( 'BIC / SWIFT', 'wahou-commerce' )    => self::bic(),
			__( 'Bank', 'wahou-commerce' )           => Settings::str( 'bank_name' ),
			__( 'Payment reference', 'wahou-commerce' ) => $order->number(),
		);

		$html  = '<section class="wahou-bank-details" aria-labelledby="wahou-bank-details-title">';
		$html .= '<h2 id="wahou-bank-details-title" class="wahou-bank-details__title">' . esc_html__( 'Our bank details', 'wahou-commerce' ) . '</h2>';
		$html .= '<dl class="wahou-bank-details__list">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<div class="wahou-bank-details__row"><dt>' . esc_html( $label ) . '</dt><dd><strong>' . esc_html( (string) $value ) . '</strong></dd></div>';
		}
		$html .= '</dl>';

		$html .= '<p class="wahou-bank-details__ref">' . esc_html(
			sprintf(
				/* translators: %s: order number. */
				__( 'Please use %s as the payment reference so that we can match your transfer.', 'wahou-commerce' ),
				$order->number()
			)
		) . '</p>';

		$instructions = trim( Settings::str( 'bank_instructions' ) );
		if ( '' !== $instructions ) {
			$html .= '<div class="wahou-bank-details__instructions">' . wp_kses_post( wpautop( $instructions ) ) . '</div>';
		}

		$days  = max( 1, Settings::int( 'bank_hold_days' ) );
		$html .= '<p class="wahou-bank-details__deadline">' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				_n( 'Without payment within %d day, the order is cancelled automatically.', 'Without payment within %d days, the order is cancelled automatically.', $days, 'wahou-commerce' ),
				$days
			)
		) . '</p>';
		$html .= '</section>';

		return $html;
	}

	/**
	 * @return array{connected:bool, mode:string, label:string, issues:string[]}
	 */
	public function status(): array {
		$issues = array();
		if ( Settings::bool( 'bank_enabled' ) && '' === self::iban() ) {
			$issues[] = __( 'Add your IBAN to offer bank transfers.', 'wahou-commerce' );
		}
		if ( '' !== self::iban() && '' === trim( Settings::str( 'bank_account_holder' ) ) ) {
			$issues[] = __( 'Add the account holder name.', 'wahou-commerce' );
		}
		return array(
			'connected' => '' !== self::iban(),
			'mode'      => 'live',
			'label'     => $this->title(),
			'issues'    => $issues,
		);
	}
}
