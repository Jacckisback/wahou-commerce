<?php
/**
 * Transactional and lifecycle emails.
 *
 * Triggers:
 *   wahou_order_paid (20)            Order confirmation (customer) + new order (admin)
 *   wahou_order_status_on-hold       Bank transfer instructions + new order (admin)
 *   wahou_order_completed            "On its way" for orders that ship
 *   wahou_order_refunded             Refund confirmation
 *   wahou_order_customer_note        Note from the shop
 *   wahou_low_stock                  Admin alert (once per product per day)
 *
 * Customer emails are rendered in the language of the order (or cart);
 * admin emails in the store owner's language.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Emails;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Emails {

	public const RESENDABLE = array( 'order_confirmation', 'order_shipped', 'bank_instructions' );

	public static function init(): void {
		add_action( 'wahou_order_paid', array( self::class, 'on_paid' ), 20 );
		add_action( 'wahou_order_status_on-hold', array( self::class, 'on_hold' ), 20, 2 );
		add_action( 'wahou_order_completed', array( self::class, 'on_completed' ), 20 );
		add_action( 'wahou_order_refunded', array( self::class, 'on_refunded' ), 20, 3 );
		add_action( 'wahou_order_customer_note', array( self::class, 'on_customer_note' ), 20, 2 );
		add_action( 'wahou_low_stock', array( self::class, 'on_low_stock' ), 20, 3 );

		// Let the locale switcher use languages shipped with the plugin (built right after plugins_loaded).
		add_filter( 'get_available_languages', array( self::class, 'plugin_languages' ) );
		add_action(
			'after_setup_theme',
			static function () {
				remove_filter( 'get_available_languages', array( self::class, 'plugin_languages' ) );
			},
			0
		);
	}

	/**
	 * Add locales for which Wahou Commerce has translations to the available languages.
	 *
	 * @param string[] $languages
	 * @return string[]
	 */
	public static function plugin_languages( $languages ): array {
		$languages = (array) $languages;
		$files     = array_merge(
			(array) glob( WAHOU_DIR . 'languages/wahou-commerce-*.mo' ),
			(array) glob( WAHOU_DIR . 'languages/wahou-commerce-*.l10n.php' )
		);
		foreach ( array_filter( $files ) as $file ) {
			if ( preg_match( '/wahou-commerce-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z]+)?)\.(?:mo|l10n\.php)$/', basename( $file ), $m ) ) {
				$languages[] = $m[1];
			}
		}
		return array_values( array_unique( $languages ) );
	}

	/* ------------------------------------------------------------------
	 * Hook callbacks.
	 * ------------------------------------------------------------------ */

	public static function on_paid( Order $order ): void {
		if ( Settings::bool( 'email_order_confirmation' ) ) {
			self::send_order_confirmation( $order );
		}
		if ( Settings::bool( 'email_admin_new_order' ) && ! self::was_sent( $order, 'admin_new_order' ) ) {
			self::send_admin_new_order( $order );
		}
	}

	public static function on_hold( Order $order, string $from = '' ): void {
		if ( 'bank' !== $order->payment_method() || $order->is_paid() ) {
			return;
		}
		if ( ! self::was_sent( $order, 'bank_instructions' ) ) {
			self::send_bank_instructions( $order );
		}
		if ( Settings::bool( 'email_admin_new_order' ) && ! self::was_sent( $order, 'admin_new_order' ) ) {
			self::send_admin_new_order( $order );
		}
	}

	public static function on_completed( Order $order ): void {
		if ( Settings::bool( 'email_order_shipped' ) && $order->needs_shipping() && ! self::was_sent( $order, 'order_shipped' ) ) {
			self::send_order_shipped( $order );
		}
	}

	/**
	 * @param array<string, mixed> $refund
	 */
	public static function on_refunded( Order $order, int $amount, array $refund = array() ): void {
		if ( Settings::bool( 'email_order_refunded' ) && $amount > 0 ) {
			self::send_refund( $order, $amount, $refund );
		}
	}

	public static function on_customer_note( Order $order, string $note ): void {
		if ( '' === trim( wp_strip_all_tags( $note ) ) ) {
			return;
		}
		self::to_customer(
			$order,
			'customer-note',
			static fn() => sprintf(
				/* translators: %s: order number. */
				__( 'A note about your order #%s', 'wahou-commerce' ),
				$order->number()
			),
			array(
				'heading' => __( 'A note about your order', 'wahou-commerce' ),
				'note'    => $note,
			)
		);
	}

	public static function on_low_stock( int $product_id, int $stock, ?string $variant_id = null ): void {
		if ( ! Settings::bool( 'email_low_stock' ) ) {
			return;
		}
		$throttle = 'wahou_lowstock_' . md5( $product_id . '|' . (string) $variant_id );
		if ( get_transient( $throttle ) ) {
			return;
		}
		$product = Product::find( $product_id );
		if ( ! $product ) {
			return;
		}
		set_transient( $throttle, 1, DAY_IN_SECONDS );

		self::to_admin(
			'low-stock',
			static fn() => sprintf(
				/* translators: 1: store name, 2: product name, 3: units left. */
				__( '[%1$s] Low stock: %2$s (%3$d left)', 'wahou-commerce' ),
				Settings::store_name(),
				$product->name(),
				$stock
			),
			array(
				'heading'       => __( 'Low stock alert', 'wahou-commerce' ),
				'product'       => $product,
				'stock'         => $stock,
				'variant_label' => $variant_id ? $product->variant_label( $variant_id ) : '',
				'edit_url'      => (string) get_edit_post_link( $product_id, 'raw' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Public API.
	 * ------------------------------------------------------------------ */

	/**
	 * Passwordless login link.
	 */
	public static function magic_link( string $email, string $url, ?string $locale = null ): bool {
		if ( ! is_email( $email ) || '' === $url ) {
			return false;
		}
		return (bool) Mailer::with_locale(
			$locale ? $locale : determine_locale(),
			static function () use ( $email, $url ) {
				return Mailer::send(
					$email,
					sprintf(
						/* translators: %s: store name. */
						__( 'Your login link for %s', 'wahou-commerce' ),
						Settings::store_name()
					),
					'magic-link',
					array(
						'heading'   => __( 'Your login link', 'wahou-commerce' ),
						'preheader' => __( 'One click and you are in. No password needed.', 'wahou-commerce' ),
						'url'       => $url,
						'email'     => $email,
					),
					array( 'reply_to' => Settings::store_email() )
				);
			}
		);
	}

	/**
	 * Resend an order email from the admin.
	 */
	public static function resend( Order $order, string $type ): bool {
		switch ( $type ) {
			case 'order_confirmation':
				return self::send_order_confirmation( $order );
			case 'order_shipped':
				return self::send_order_shipped( $order );
			case 'bank_instructions':
				return self::send_bank_instructions( $order );
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Senders.
	 * ------------------------------------------------------------------ */

	public static function send_order_confirmation( Order $order ): bool {
		$downloads = array();
		if ( class_exists( '\WahouCommerce\Downloads\Downloads' ) && method_exists( '\WahouCommerce\Downloads\Downloads', 'for_order' ) ) {
			$downloads = (array) \WahouCommerce\Downloads\Downloads::for_order( $order );
		}
		$account_url = '';
		if ( class_exists( '\WahouCommerce\Customers\Account' ) && method_exists( '\WahouCommerce\Customers\Account', 'magic_url' ) ) {
			$account_url = (string) \WahouCommerce\Customers\Account::magic_url( $order->email() );
		}

		$sent = self::to_customer(
			$order,
			'order-confirmation',
			static fn() => sprintf(
				/* translators: %s: order number. */
				__( 'Your order #%s is confirmed', 'wahou-commerce' ),
				$order->number()
			),
			array(
				'heading'     => __( 'Thank you for your order!', 'wahou-commerce' ),
				'preheader'   => sprintf(
					/* translators: 1: order number, 2: order total. */
					__( 'Order #%1$s confirmed – %2$s', 'wahou-commerce' ),
					$order->number(),
					Money::format( $order->total(), $order->currency() )
				),
				'downloads'   => $downloads,
				'account_url' => $account_url,
				'invoice_url' => '' !== $order->invoice_number() ? $order->invoice_url() : '',
				'message'     => Settings::str( 'thankyou_message' ),
			)
		);
		self::mark_sent( $order, 'order_confirmation', $sent );
		return $sent;
	}

	public static function send_admin_new_order( Order $order ): bool {
		$sent = self::to_admin(
			'admin-new-order',
			static fn() => sprintf(
				/* translators: 1: store name, 2: order number, 3: order total. */
				__( '[%1$s] New order #%2$s – %3$s', 'wahou-commerce' ),
				Settings::store_name(),
				$order->number(),
				Money::format( $order->total(), $order->currency() )
			),
			array(
				'heading'   => __( 'New order', 'wahou-commerce' ),
				'order'     => $order,
				'admin_url' => $order->admin_url(),
			),
			$order->email()
		);
		self::mark_sent( $order, 'admin_new_order', $sent );
		return $sent;
	}

	public static function send_bank_instructions( Order $order ): bool {
		$gateway      = Gateways::get( $order->payment_method() );
		$instructions = $gateway ? $gateway->thankyou_html( $order ) : '';
		$sent         = self::to_customer(
			$order,
			'bank-instructions',
			static fn() => sprintf(
				/* translators: %s: order number. */
				__( 'Your order #%s – payment instructions', 'wahou-commerce' ),
				$order->number()
			),
			array(
				'heading'      => __( 'Almost done: complete your bank transfer', 'wahou-commerce' ),
				'preheader'    => sprintf(
					/* translators: %s: order total. */
					__( 'Transfer %s to confirm your order.', 'wahou-commerce' ),
					Money::format( $order->total(), $order->currency() )
				),
				'instructions' => $instructions,
				'hold_days'    => max( 1, Settings::int( 'bank_hold_days' ) ),
			)
		);
		self::mark_sent( $order, 'bank_instructions', $sent );
		return $sent;
	}

	public static function send_order_shipped( Order $order ): bool {
		$tracking = $order->get_meta( 'tracking', array() );
		$sent     = self::to_customer(
			$order,
			'order-shipped',
			static fn() => sprintf(
				/* translators: %s: order number. */
				__( 'Your order #%s is on its way', 'wahou-commerce' ),
				$order->number()
			),
			array(
				'heading'   => __( 'Your order has shipped!', 'wahou-commerce' ),
				'preheader' => __( 'Good news: your parcel is on its way.', 'wahou-commerce' ),
				'tracking'  => is_array( $tracking ) ? $tracking : array(),
			)
		);
		self::mark_sent( $order, 'order_shipped', $sent );
		return $sent;
	}

	/**
	 * @param array<string, mixed> $refund
	 */
	public static function send_refund( Order $order, int $amount, array $refund = array() ): bool {
		$full       = $order->refunded_total() >= $order->total();
		$credit     = (string) ( $refund['credit_note'] ?? '' );
		$credit_url = '' !== $credit ? add_query_arg( 'credit', rawurlencode( $credit ), $order->invoice_url() ) : '';
		return self::to_customer(
			$order,
			'order-refunded',
			static fn() => $full
				? sprintf(
					/* translators: %s: order number. */
					__( 'Your order #%s has been refunded', 'wahou-commerce' ),
					$order->number()
				)
				: sprintf(
					/* translators: %s: order number. */
					__( 'A refund has been issued for your order #%s', 'wahou-commerce' ),
					$order->number()
				),
			array(
				'heading'    => $full ? __( 'Your order has been refunded', 'wahou-commerce' ) : __( 'A refund is on its way', 'wahou-commerce' ),
				'amount'     => $amount,
				'full'       => $full,
				'reason'     => (string) ( $refund['reason'] ?? '' ),
				'credit_url' => $credit_url,
			)
		);
	}

	/**
	 * Abandoned cart reminder.
	 *
	 * @param array<string, mixed> $vars cart, lines, totals, step, recover_url, unsubscribe_url, coupon, coupon_percent, coupon_expires, first_name.
	 */
	public static function send_cart_reminder( string $email, string $locale, int $step, array $vars ): bool {
		return (bool) Mailer::with_locale(
			$locale,
			static function () use ( $email, $step, $vars ) {
				$steps   = (array) Settings::get( 'recovery_steps', array() );
				$custom  = trim( (string) ( $steps[ $step ]['subject'] ?? '' ) );
				$coupon  = (string) ( $vars['coupon'] ?? '' );
				$percent = (float) ( $vars['coupon_percent'] ?? 0 );
				$name    = (string) ( $vars['first_name'] ?? '' );

				if ( '' !== $custom ) {
					$subject = strtr(
						$custom,
						array(
							'{first_name}' => $name,
							'{store_name}' => Settings::store_name(),
							'{coupon}'     => $coupon,
							'{discount}'   => self::percent( $percent ),
						)
					);
				} elseif ( '' !== $coupon ) {
					/* translators: %s: discount, e.g. "10%". */
					$subject = sprintf( __( 'Last chance: %s off your cart', 'wahou-commerce' ), self::percent( $percent ) );
				} elseif ( 0 === $step ) {
					$subject = __( 'You left something in your cart', 'wahou-commerce' );
				} else {
					$subject = __( 'Your cart is still waiting for you', 'wahou-commerce' );
				}

				return Mailer::send(
					$email,
					$subject,
					'cart-recovery',
					array_merge(
						$vars,
						array(
							'heading'   => '' !== $coupon
								? __( 'A little gift to finish your order', 'wahou-commerce' )
								: __( 'Your cart is saved', 'wahou-commerce' ),
							'preheader' => __( 'Pick up right where you left off – in one click.', 'wahou-commerce' ),
							'step'      => $step,
						)
					),
					array( 'reply_to' => Settings::store_email() )
				);
			}
		);
	}

	/**
	 * Review request after delivery.
	 *
	 * @param array<int, array{name:string, url:string, image:string}> $products
	 */
	public static function send_review_request( Order $order, array $products, string $unsubscribe_url ): bool {
		if ( empty( $products ) ) {
			return false;
		}
		return self::to_customer(
			$order,
			'review-request',
			static fn() => 1 === count( $products )
				? sprintf(
					/* translators: %s: product name. */
					__( 'What do you think of %s?', 'wahou-commerce' ),
					$products[0]['name']
				)
				: __( 'How do you like your purchase?', 'wahou-commerce' ),
			array(
				'heading'         => __( 'Your opinion matters', 'wahou-commerce' ),
				'preheader'       => __( 'One minute to help other customers choose.', 'wahou-commerce' ),
				'products'        => $products,
				'unsubscribe_url' => $unsubscribe_url,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Send an email to the order's customer, in the order's language.
	 *
	 * @param callable             $subject Returns the subject (called inside the locale switch).
	 * @param array<string, mixed> $vars
	 */
	private static function to_customer( Order $order, string $template, callable $subject, array $vars ): bool {
		if ( ! is_email( $order->email() ) ) {
			return false;
		}
		return (bool) Mailer::with_locale(
			$order->locale(),
			static function () use ( $order, $template, $subject, $vars ) {
				// Labels inside $vars were computed before the switch: recompute them here.
				$vars = array_merge( $vars, self::localized_vars( $template, $order, $vars ) );
				return Mailer::send(
					$order->email(),
					(string) $subject(),
					$template,
					array_merge( array( 'order' => $order ), $vars ),
					array( 'reply_to' => Settings::store_email() )
				);
			}
		);
	}

	/**
	 * Recompute translated strings for templates after the locale switch.
	 *
	 * @param array<string, mixed> $vars
	 * @return array<string, mixed>
	 */
	private static function localized_vars( string $template, Order $order, array $vars ): array {
		$total = Money::format( $order->total(), $order->currency() );
		switch ( $template ) {
			case 'order-confirmation':
				return array(
					'heading'   => __( 'Thank you for your order!', 'wahou-commerce' ),
					'preheader' => sprintf(
						/* translators: 1: order number, 2: order total. */
						__( 'Order #%1$s confirmed – %2$s', 'wahou-commerce' ),
						$order->number(),
						$total
					),
				);
			case 'bank-instructions':
				return array(
					'heading'   => __( 'Almost done: complete your bank transfer', 'wahou-commerce' ),
					/* translators: %s: order total. */
					'preheader' => sprintf( __( 'Transfer %s to confirm your order.', 'wahou-commerce' ), $total ),
				);
			case 'order-shipped':
				return array(
					'heading'   => __( 'Your order has shipped!', 'wahou-commerce' ),
					'preheader' => __( 'Good news: your parcel is on its way.', 'wahou-commerce' ),
				);
			case 'order-refunded':
				return array(
					'heading' => ! empty( $vars['full'] ) ? __( 'Your order has been refunded', 'wahou-commerce' ) : __( 'A refund is on its way', 'wahou-commerce' ),
				);
			case 'customer-note':
				return array( 'heading' => __( 'A note about your order', 'wahou-commerce' ) );
			case 'review-request':
				return array(
					'heading'   => __( 'Your opinion matters', 'wahou-commerce' ),
					'preheader' => __( 'One minute to help other customers choose.', 'wahou-commerce' ),
				);
		}
		return array();
	}

	/**
	 * Send an email to the store owner, in the owner's language.
	 *
	 * @param callable             $subject
	 * @param array<string, mixed> $vars
	 */
	private static function to_admin( string $template, callable $subject, array $vars, string $reply_to = '' ): bool {
		$to     = Settings::store_email();
		$user   = get_user_by( 'email', $to );
		$locale = $user ? get_user_locale( $user ) : get_locale();
		return (bool) Mailer::with_locale(
			$locale,
			static function () use ( $to, $template, $subject, $vars, $reply_to ) {
				if ( isset( $vars['heading'] ) ) {
					$vars['heading'] = 'low-stock' === $template ? __( 'Low stock alert', 'wahou-commerce' ) : __( 'New order', 'wahou-commerce' );
				}
				return Mailer::send( $to, (string) $subject(), $template, $vars, array( 'reply_to' => $reply_to ) );
			}
		);
	}

	public static function was_sent( Order $order, string $type ): bool {
		$sent = (array) $order->get_meta( 'emails_sent', array() );
		return ! empty( $sent[ $type ] );
	}

	private static function mark_sent( Order $order, string $type, bool $sent ): void {
		if ( ! $sent ) {
			return;
		}
		$log          = (array) $order->get_meta( 'emails_sent', array() );
		$log[ $type ] = current_time( 'mysql', true );
		$order->update_meta( 'emails_sent', $log );
	}

	/**
	 * Postal address as escaped HTML lines.
	 *
	 * @param array<string, string> $address
	 */
	public static function address_html( array $address ): string {
		if ( empty( $address ) ) {
			return '';
		}
		$lines = array();
		$name  = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		foreach ( array( $name, $address['company'] ?? '', $address['address_1'] ?? '', $address['address_2'] ?? '' ) as $line ) {
			if ( '' !== trim( (string) $line ) ) {
				$lines[] = esc_html( (string) $line );
			}
		}
		$city = trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) );
		if ( '' !== $city ) {
			$lines[] = esc_html( $city );
		}
		if ( ! empty( $address['country'] ) ) {
			$lines[] = esc_html( Countries::name( (string) $address['country'] ) );
		}
		if ( ! empty( $address['phone'] ) ) {
			$lines[] = esc_html( (string) $address['phone'] );
		}
		if ( ! empty( $address['vat_number'] ) ) {
			/* translators: %s: VAT number. */
			$lines[] = esc_html( sprintf( __( 'VAT number: %s', 'wahou-commerce' ), $address['vat_number'] ) );
		}
		return implode( '<br>', $lines );
	}

	/**
	 * Localized date (month names follow the current, possibly switched, locale).
	 */
	public static function date( string $gmt, string $format = '' ): string {
		$timestamp = '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
		if ( ! $timestamp ) {
			return '';
		}
		return (string) wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), $timestamp );
	}

	public static function percent( float $value ): string {
		$formatted = rtrim( rtrim( number_format_i18n( $value, 2 ), '0' ), '.,' );
		/* translators: %s: number. Percent sign placement varies by language. */
		return sprintf( __( '%s%%', 'wahou-commerce' ), $formatted );
	}
}
