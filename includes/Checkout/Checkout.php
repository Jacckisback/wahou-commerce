<?php
/**
 * Checkout: field definitions, validation and order placement.
 *
 * Friction rules:
 *  - Email first (enables cart recovery), autocomplete attributes everywhere.
 *  - Digital/service-only carts never ask for a postal address: email, name
 *    and country (needed as VAT evidence) are enough.
 *  - Billing = shipping by default; company/VAT fields only for businesses.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Checkout;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\RateLimiter;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Checkout {

	/**
	 * Address field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function address_fields( string $group, bool $full ): array {
		$phone   = Settings::str( 'field_phone' );
		$company = Settings::str( 'field_company' );
		$prefix  = 'shipping' === $group ? 'shipping' : 'billing';

		$fields = array(
			'first_name' => array(
				'label'        => __( 'First name', 'wahou-commerce' ),
				'required'     => true,
				'autocomplete' => $prefix . ' given-name',
				'half'         => true,
			),
			'last_name'  => array(
				'label'        => __( 'Last name', 'wahou-commerce' ),
				'required'     => true,
				'autocomplete' => $prefix . ' family-name',
				'half'         => true,
			),
		);
		if ( $full ) {
			if ( 'hidden' !== $company && 'billing' === $group ) {
				$fields['company'] = array(
					'label'        => __( 'Company', 'wahou-commerce' ),
					'required'     => 'required' === $company,
					'autocomplete' => $prefix . ' organization',
					'business'     => true,
				);
			}
			$fields['address_1'] = array(
				'label'        => __( 'Address', 'wahou-commerce' ),
				'required'     => true,
				'autocomplete' => $prefix . ' address-line1',
				'placeholder'  => __( 'Street and number', 'wahou-commerce' ),
			);
			$fields['address_2'] = array(
				'label'        => __( 'Apartment, suite, etc. (optional)', 'wahou-commerce' ),
				'required'     => false,
				'autocomplete' => $prefix . ' address-line2',
			);
			$fields['postcode']  = array(
				'label'        => __( 'Postcode', 'wahou-commerce' ),
				'required'     => true,
				'autocomplete' => $prefix . ' postal-code',
				'inputmode'    => 'text',
				'half'         => true,
			);
			$fields['city']      = array(
				'label'        => __( 'City', 'wahou-commerce' ),
				'required'     => true,
				'autocomplete' => $prefix . ' address-level2',
				'half'         => true,
			);
		}
		$fields['country'] = array(
			'label'        => __( 'Country', 'wahou-commerce' ),
			'required'     => true,
			'type'         => 'country',
			'autocomplete' => $prefix . ' country',
		);
		if ( $full && 'hidden' !== $phone && 'shipping' === $group ) {
			$fields['phone'] = array(
				'label'        => 'required' === $phone ? __( 'Phone (for the carrier)', 'wahou-commerce' ) : __( 'Phone (optional, for the carrier)', 'wahou-commerce' ),
				'required'     => 'required' === $phone,
				'type'         => 'tel',
				'autocomplete' => $prefix . ' tel',
				'inputmode'    => 'tel',
			);
		}
		/**
		 * Filters checkout address fields.
		 *
		 * @param array  $fields Field definitions.
		 * @param string $group  billing|shipping.
		 * @param bool   $full   Full postal address requested.
		 */
		return apply_filters( 'wahou_checkout_address_fields', $fields, $group, $full );
	}

	/**
	 * Countries selectable for a cart.
	 *
	 * @return array<string, string>
	 */
	public static function countries( Cart $cart ): array {
		if ( $cart->needs_shipping() ) {
			return Countries::selectable( Shipping::countries() );
		}
		$allowed = (array) Settings::get( 'allowed_countries', array() );
		return Countries::selectable( $allowed );
	}

	/**
	 * Validate the checkout request and place the order.
	 *
	 * @param array<string, mixed> $input Raw request params.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process( array $input ) {
		$cart = Cart::current();
		if ( ! $cart || $cart->is_empty() ) {
			return new \WP_Error( 'wahou_empty_cart', __( 'Your cart is empty.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}

		// Anti-bot & card-testing protections.
		if ( Settings::bool( 'honeypot' ) && ! empty( $input['wahou_hp'] ) ) {
			Logger::info( 'checkout', 'Honeypot triggered' );
			return new \WP_Error( 'wahou_spam', __( 'Your request could not be processed.', 'wahou-commerce' ), array( 'status' => 400 ) );
		}
		$limit = Settings::int( 'checkout_rate_limit' );
		if ( $limit > 0 && ! RateLimiter::hit( 'checkout', $limit, 15 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'wahou_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'wahou-commerce' ), array( 'status' => 429 ) );
		}
		if ( ! is_user_logged_in() && ! Settings::bool( 'guest_checkout' ) && ! Settings::bool( 'auto_create_account' ) ) {
			return new \WP_Error( 'wahou_login_required', __( 'Please log in to place your order.', 'wahou-commerce' ), array( 'status' => 401 ) );
		}

		$errors         = array();
		$needs_shipping = $cart->needs_shipping();
		$countries      = self::countries( $cart );

		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'wahou-commerce' );
		}

		$shipping = array();
		if ( $needs_shipping ) {
			$shipping = self::read_address( (array) ( $input['shipping'] ?? array() ), self::address_fields( 'shipping', true ), 'shipping', $errors, $countries );
		}
		$separate_billing = ! $needs_shipping || ! empty( $input['billing_different'] );
		if ( $separate_billing ) {
			$full    = $needs_shipping || ! empty( $input['is_business'] );
			$billing = self::read_address( (array) ( $input['billing'] ?? array() ), self::address_fields( 'billing', $full ), 'billing', $errors, $needs_shipping ? Countries::all() : $countries );
		} else {
			$billing = $shipping;
			unset( $billing['phone'] );
			$raw_billing = (array) ( $input['billing'] ?? array() );
			if ( ! empty( $raw_billing['company'] ) ) {
				$billing['company'] = sanitize_text_field( (string) $raw_billing['company'] );
			}
			if ( ! empty( $shipping['phone'] ) ) {
				$billing['phone'] = $shipping['phone'];
			}
		}

		// Business customers: VAT number (validated with VIES for reverse charge).
		$vat_number = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $input['billing']['vat_number'] ?? '' ) ) ?? '' );
		$vat_valid  = false;
		if ( '' !== $vat_number && ! empty( $input['is_business'] ) ) {
			$billing['vat_number'] = $vat_number;
			$check                 = Tax::validate_vat( $vat_number, (string) ( $billing['country'] ?? '' ) );
			$vat_valid             = $check['valid'];
			if ( ! $vat_valid && 'invalid' === ( $check['error'] ?? '' ) ) {
				$errors['billing.vat_number'] = __( 'This VAT number is not valid in VIES. Remove it or correct it to continue.', 'wahou-commerce' );
			}
			if ( $vat_valid ) {
				$vat_country = 'EL' === $check['country'] ? 'GR' : $check['country'];
				if ( $vat_country !== ( $billing['country'] ?? '' ) ) {
					$vat_valid                    = false;
					$errors['billing.vat_number'] = __( 'The VAT number does not match the billing country.', 'wahou-commerce' );
				}
			}
		}

		// Legal consents.
		$consents = array();
		if ( Settings::bool( 'terms_checkbox' ) && Pages::id( 'terms' ) ) {
			if ( empty( $input['terms'] ) ) {
				$errors['terms'] = __( 'Please accept the terms and conditions of sale.', 'wahou-commerce' );
			}
			$consents['terms'] = current_time( 'mysql', true );
		}
		if ( Settings::bool( 'digital_waiver' ) && $cart->has_digital() ) {
			if ( empty( $input['digital_waiver'] ) ) {
				$errors['digital_waiver'] = __( 'Please confirm that you want immediate access to the digital content.', 'wahou-commerce' );
			}
			$consents['digital_waiver'] = current_time( 'mysql', true );
		}
		if ( ! empty( $input['marketing'] ) ) {
			$consents['marketing'] = current_time( 'mysql', true );
		}

		$gateway_id = sanitize_key( (string) ( $input['payment_method'] ?? '' ) );
		$gateway    = Gateways::get( $gateway_id );

		// Refresh cart context with the final details so totals match the order.
		$tax_address = $needs_shipping ? $shipping : $billing;
		$cart->set_customer(
			array(
				'email'      => $email,
				'first_name' => $billing['first_name'] ?? '',
				'last_name'  => $billing['last_name'] ?? '',
				'country'    => $tax_address['country'] ?? '',
				'postcode'   => $tax_address['postcode'] ?? '',
				'city'       => $tax_address['city'] ?? '',
				'vat_number' => $vat_number,
				'vat_valid'  => $vat_valid,
			)
		);
		if ( ! empty( $input['shipping_method'] ) ) {
			$cart->set_shipping_method( sanitize_text_field( (string) $input['shipping_method'] ) );
		}

		$totals = $cart->totals();
		if ( ! empty( $totals['errors']['shipping'] ) ) {
			$errors['shipping.country'] = (string) $totals['errors']['shipping'];
		}
		// Free orders (100 % coupon, lead magnets) need no payment method.
		$is_free = 0 === (int) $totals['total'] && empty( $totals['errors']['coupon'] );
		if ( $is_free ) {
			$gateway_id = 'free';
		} elseif ( ! $gateway || ! $gateway->is_available() ) {
			$errors['payment_method'] = __( 'Please choose a payment method.', 'wahou-commerce' );
		}
		if ( ! empty( $totals['errors']['coupon'] ) ) {
			$cart->remove_coupon();
		}

		/**
		 * Filters checkout validation errors (field => message).
		 */
		$errors = apply_filters( 'wahou_checkout_errors', $errors, $input, $cart );
		if ( $errors ) {
			$cart->save();
			return new \WP_Error(
				'wahou_checkout_invalid',
				__( 'Please check the highlighted fields.', 'wahou-commerce' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}

		$order = Orders::create_from_cart(
			$cart,
			array(
				'email'          => $email,
				'billing'        => $billing,
				'shipping'       => $needs_shipping ? $shipping : null,
				'payment_method' => $gateway_id,
				'customer_note'  => Settings::bool( 'order_notes' ) ? sanitize_textarea_field( (string) ( $input['customer_note'] ?? '' ) ) : '',
				'consents'       => $consents,
				'locale'         => determine_locale(),
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( 0 === $order->total() || ! $gateway ) {
			// Free orders (100 % coupon, free lead magnets) skip payment.
			$order->payment_complete( 'free' );
			return array(
				'order_id'  => $order->id(),
				'order_key' => $order->key(),
				'payment'   => array(
					'action'   => 'redirect',
					'redirect' => $order->received_url(),
				),
			);
		}

		$payment = $gateway->process_payment( $order, $input );
		if ( is_wp_error( $payment ) ) {
			$order->add_note( $payment->get_error_message() );
			return $payment;
		}
		return array(
			'order_id'  => $order->id(),
			'order_key' => $order->key(),
			'payment'   => $payment,
		);
	}

	/**
	 * @param array<string, mixed>                 $raw
	 * @param array<string, array<string, mixed>>  $fields
	 * @param array<string, string>                $errors
	 * @param array<string, string>                $countries
	 * @return array<string, string>
	 */
	private static function read_address( array $raw, array $fields, string $group, array &$errors, array $countries ): array {
		$out = array();
		foreach ( $fields as $name => $field ) {
			$value = is_scalar( $raw[ $name ] ?? null ) ? trim( (string) $raw[ $name ] ) : '';
			$value = 'country' === $name ? strtoupper( substr( $value, 0, 2 ) ) : sanitize_text_field( $value );
			if ( '' === $value && ! empty( $field['required'] ) && empty( $field['business'] ) ) {
				/* translators: %s: field label. */
				$errors[ $group . '.' . $name ] = sprintf( __( '%s is required.', 'wahou-commerce' ), $field['label'] );
				continue;
			}
			if ( 'country' === $name && '' !== $value && ! isset( $countries[ $value ] ) ) {
				$errors[ $group . '.' . $name ] = __( 'We do not deliver to this country yet.', 'wahou-commerce' );
			}
			if ( 'phone' === $name && '' !== $value && ! preg_match( '/^[+0-9 ().-]{6,25}$/', $value ) ) {
				$errors[ $group . '.' . $name ] = __( 'Please enter a valid phone number.', 'wahou-commerce' );
			}
			$out[ $name ] = substr( $value, 0, 190 );
		}
		return $out;
	}
}
