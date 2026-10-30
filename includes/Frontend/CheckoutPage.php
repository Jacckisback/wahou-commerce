<?php
/**
 * Cart page, one-page checkout, order received page and post-purchase offers.
 *
 * Checkout flow (all prices recomputed server side):
 *   1. The form (templates/checkout/form.php) is enhanced by assets/js/checkout.js.
 *      Each address/shipping change posts to wahou/v1/cart/customer and the
 *      summary is re-rendered from the server (templates/cart/summary.php).
 *   2. On submit, checkout.js posts to wahou/v1/checkout, then hands the payment
 *      step to the selected gateway script (Stripe, PayPal...) or follows a redirect.
 *   3. The gateway returns to ?wahou_order=KEY: the payment is verified with the
 *      provider (Gateway::handle_return), then the customer sees a one-click
 *      post-purchase offer (if any) or the thank-you page.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Cart\Calculator;
use WahouCommerce\Cart\Cart;
use WahouCommerce\Checkout\Checkout;
use WahouCommerce\Customers\Account;
use WahouCommerce\Downloads\Downloads;
use WahouCommerce\Funnels\Offers;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\Product;
use WahouCommerce\Rest\RestApi;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\RateLimiter;
use WahouCommerce\Support\Request;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class CheckoutPage {

	/** Post-purchase offers are shown during this window after payment. */
	private const OFFER_WINDOW = HOUR_IN_SECONDS;

	private static ?string $context = null;
	private static ?Order $order    = null;
	private static bool $data_added = false;

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'handle_cart_actions' ), 7 );
		add_action( 'template_redirect', array( self::class, 'template_redirect' ), 9 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_filter( 'the_title', array( self::class, 'page_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( self::class, 'document_title' ) );
	}

	/* ------------------------------------------------------------------
	 * Request context.
	 * ------------------------------------------------------------------ */

	/**
	 * checkout | thankyou | offer | cart | account | '' (memoized per request).
	 */
	public static function context(): string {
		if ( null !== self::$context ) {
			return self::$context;
		}
		$context = '';
		if ( ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$post = is_singular() ? get_post() : null;
			$has  = static function ( string $block, string $shortcode ) use ( $post ): bool {
				return $post instanceof \WP_Post && ( has_block( $block, $post ) || has_shortcode( $post->post_content, $shortcode ) );
			};
			if ( Pages::is( 'checkout' ) || $has( 'wahou/checkout', 'wahou_checkout' ) ) {
				$context = 'checkout';
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				if ( ! empty( $_GET['wahou_order'] ) ) {
					$context = ! empty( $_GET['wahou_offer'] ) ? 'offer' : 'thankyou';
				}
				// phpcs:enable
			} elseif ( Pages::is( 'cart' ) || $has( 'wahou/cart', 'wahou_cart' ) ) {
				$context = 'cart';
			} elseif ( Pages::is( 'account' ) || $has( 'wahou/account', 'wahou_account' ) ) {
				$context = 'account';
			}
		}
		if ( did_action( 'template_redirect' ) || did_action( 'wp_enqueue_scripts' ) ) {
			self::$context = $context;
		}
		return $context;
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function body_class( array $classes ): array {
		$context = self::context();
		if ( '' !== $context ) {
			$classes[] = 'wahou-page-' . $context;
		}
		return $classes;
	}

	/**
	 * "Order received" instead of "Checkout" above the confirmation and offer pages.
	 *
	 * @param mixed $title
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function page_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! in_array( self::context(), array( 'thankyou', 'offer' ), true ) || (int) $post_id !== (int) get_queried_object_id() ) {
			return $title;
		}
		return __( 'Order received', 'wahou-commerce' );
	}

	/**
	 * @param array<string, string> $parts
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( in_array( self::context(), array( 'thankyou', 'offer' ), true ) ) {
			$parts['title'] = __( 'Order received', 'wahou-commerce' );
		}
		return $parts;
	}

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard flag read by caching plugins.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/* ------------------------------------------------------------------
	 * template_redirect.
	 * ------------------------------------------------------------------ */

	public static function template_redirect(): void {
		$context = self::context();
		if ( '' === $context ) {
			return;
		}
		self::no_cache();

		if ( 'checkout' === $context ) {
			self::prepare_cart();
			return;
		}
		if ( ! in_array( $context, array( 'thankyou', 'offer' ), true ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order = Order::find_by_key( sanitize_text_field( wp_unslash( $_GET['wahou_order'] ?? '' ) ) );
		$offer = absint( $_GET['wahou_offer'] ?? 0 );
		// phpcs:enable
		if ( ! $order ) {
			return;
		}
		if ( 'pending' === $order->status() ) {
			$gateway = Gateways::get( $order->payment_method() );
			if ( $gateway ) {
				$gateway->handle_return( $order );
				$order->refresh();
			}
		}
		self::$order = $order;

		self::handle_verification( $order );
		if ( ! self::can_view_order( $order ) ) {
			return;
		}
		self::handle_offer_post( $order );

		if ( 'offer' === $context ) {
			if ( ! self::offer_is_valid( $order, $offer ) ) {
				wp_safe_redirect( $order->received_url() );
				exit;
			}
			return;
		}

		if ( $order->is_paid() ) {
			// The purchase is done: the header badge must not show the old cart.
			$cart_cookie = isset( $_COOKIE[ Cart::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Cart::COOKIE ] ) ) : '';
			$order_cart  = $order->cart_id() ? Cart::find( $order->cart_id() ) : null;
			if ( '' !== $cart_cookie && $order_cart && hash_equals( $order_cart->token(), $cart_cookie ) ) {
				Cart::forget_cookies();
			}

			// First visit after payment: propose the one-click upsell once.
			if ( self::within_offer_window( $order ) && empty( $order->get_meta( 'offers_seen', array() ) ) ) {
				$upsell = Offers::upsell_for_order( $order );
				if ( $upsell ) {
					wp_safe_redirect( Pages::offer_url( $order, (int) $upsell['id'] ) );
					exit;
				}
			}
		}
	}

	/**
	 * Make sure the cart has a country (and the logged-in customer's saved
	 * details) so the first totals rendered match the prefilled form.
	 */
	private static function prepare_cart(): void {
		$cart = Cart::current();
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		$customer = $cart->customer();
		$prefill  = self::prefill( $cart );
		$group    = $cart->needs_shipping() ? 'shipping' : 'billing';
		$changes  = array();
		foreach ( array( 'country', 'postcode', 'city', 'first_name', 'last_name' ) as $field ) {
			if ( empty( $customer[ $field ] ) && ! empty( $prefill[ $group ][ $field ] ) ) {
				$changes[ $field ] = $prefill[ $group ][ $field ];
			}
		}
		if ( empty( $customer['email'] ) && '' !== $prefill['email'] ) {
			$changes['email'] = $prefill['email'];
		}
		if ( $changes ) {
			$cart->set_customer( $changes );
			$cart->save();
		}
	}

	/* ------------------------------------------------------------------
	 * No-JS cart actions (cart page forms).
	 * ------------------------------------------------------------------ */

	public static function handle_cart_actions(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wahou_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['wahou_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $action, array( 'update_cart', 'remove_item', 'apply_coupon', 'remove_coupon' ), true ) ) {
			return;
		}
		$referer = wp_get_referer();
		$back    = $referer ? $referer : Pages::url( 'cart' );
		$cart    = Cart::current();
		if ( ! $cart ) {
			wp_safe_redirect( $back );
			exit;
		}
		if ( ! self::verify_post( 'wahou_cart' ) ) {
			self::flash( $cart, 'error', __( 'Your session has expired. Please try again.', 'wahou-commerce' ) );
			wp_safe_redirect( $back );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		switch ( $action ) {
			case 'update_cart':
				$quantities = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? wp_unslash( $_POST['qty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys checked, values cast.
				foreach ( $quantities as $key => $qty ) {
					if ( preg_match( '/^[a-f0-9]{32}$/', (string) $key ) ) {
						$cart->set_qty( (string) $key, absint( $qty ) );
					}
				}
				$remove = isset( $_POST['remove'] ) ? sanitize_key( wp_unslash( $_POST['remove'] ) ) : '';
				if ( preg_match( '/^[a-f0-9]{32}$/', $remove ) ) {
					$cart->remove( $remove );
					self::flash( $cart, 'success', __( 'Item removed.', 'wahou-commerce' ) );
				} else {
					self::flash( $cart, 'success', __( 'Cart updated.', 'wahou-commerce' ) );
				}
				break;
			case 'remove_item':
				$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
				if ( preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
					$cart->remove( $key );
					self::flash( $cart, 'success', __( 'Item removed.', 'wahou-commerce' ) );
				}
				break;
			case 'apply_coupon':
				if ( ! RateLimiter::hit( 'coupon', 20, 10 * MINUTE_IN_SECONDS ) ) {
					self::flash( $cart, 'error', __( 'Too many requests. Please wait a moment.', 'wahou-commerce' ) );
					break;
				}
				$result = $cart->apply_coupon( sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) ) );
				if ( is_wp_error( $result ) ) {
					self::flash( $cart, 'error', $result->get_error_message() );
				} else {
					self::flash( $cart, 'success', __( 'Coupon applied.', 'wahou-commerce' ) );
				}
				break;
			case 'remove_coupon':
				$cart->remove_coupon();
				self::flash( $cart, 'success', __( 'Coupon removed.', 'wahou-commerce' ) );
				break;
		}
		// phpcs:enable
		$cart->save();
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Nonce, or same-origin POST (pages served from a cache may hold stale nonces).
	 */
	private static function verify_post( string $action ): bool {
		if ( class_exists( Frontend::class ) && method_exists( Frontend::class, 'verify_post' ) ) {
			return Frontend::verify_post( $action );
		}
		$nonce = isset( $_POST['_wahou_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wahou_nonce'] ) ) : '';
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	private static function flash( Cart $cart, string $type, string $message ): void {
		set_transient(
			'wahou_flash_' . md5( $cart->token() ),
			array(
				'type'    => $type,
				'message' => $message,
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * @return array{type:string, message:string}|null
	 */
	private static function pull_flash( ?Cart $cart ): ?array {
		if ( ! $cart ) {
			return null;
		}
		$key   = 'wahou_flash_' . md5( $cart->token() );
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return null;
		}
		delete_transient( $key );
		return $flash;
	}

	/* ------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	public static function enqueue(): void {
		$context = self::context();
		if ( '' !== $context ) {
			self::enqueue_assets( $context );
		}
	}

	/**
	 * Enqueue the checkout assets for a context (idempotent, safe during rendering).
	 */
	public static function enqueue_assets( string $context ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( class_exists( Frontend::class ) && method_exists( Frontend::class, 'enqueue' ) ) {
			Frontend::enqueue();
		}
		if ( ! wp_style_is( 'wahou-checkout', 'registered' ) ) {
			wp_register_style( 'wahou-checkout', WAHOU_URL . 'assets/css/checkout.css', wp_style_is( 'wahou', 'registered' ) ? array( 'wahou' ) : array(), WAHOU_VERSION );
		}
		if ( ! wp_script_is( 'wahou-checkout', 'registered' ) ) {
			wp_register_script(
				'wahou-checkout',
				WAHOU_URL . 'assets/js/checkout.js',
				wp_script_is( 'wahou', 'registered' ) ? array( 'wahou' ) : array(),
				WAHOU_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		wp_enqueue_style( 'wahou-checkout' );
		wp_enqueue_script( 'wahou-checkout' );

		if ( self::$data_added ) {
			return;
		}
		self::$data_added = true;

		$gateways = array();
		if ( 'checkout' === $context ) {
			foreach ( Gateways::available() as $id => $gateway ) {
				$gateway->enqueue_checkout_assets();
				$gateways[ $id ] = $gateway->checkout_config();
			}
		} elseif ( 'offer' === $context ) {
			$order   = self::$order ?? self::requested_order();
			$gateway = $order ? Gateways::get( $order->payment_method() ) : null;
			if ( $gateway ) {
				$gateway->enqueue_checkout_assets();
				$gateways[ $gateway->id ] = $gateway->checkout_config();
			}
		}

		wp_add_inline_script(
			'wahou-checkout',
			'window.wahouCheckoutData = ' . wp_json_encode( self::client_data( $context, $gateways ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	private static function requested_order(): ?Order {
		$key = isset( $_GET['wahou_order'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '' !== $key ? Order::find_by_key( $key ) : null;
	}

	/**
	 * @param array<string, array<string, mixed>> $gateways
	 * @return array<string, mixed>
	 */
	private static function client_data( string $context, array $gateways ): array {
		$cart      = in_array( $context, array( 'checkout', 'cart' ), true ) ? Cart::current() : null;
		$cart_data = $cart && ! $cart->is_empty() ? RestApi::cart_data( $cart ) : null;
		$currency  = Settings::currency();

		$data = array(
			'restUrl'       => esc_url_raw( rest_url( RestApi::NS . '/' ) ),
			'nonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'context'       => $context,
			'currency'      => $currency,
			'locale'        => str_replace( '_', '-', preg_replace( '/^([a-z]{2,3}_[A-Z]{2}).*$/', '$1', determine_locale() ) ?? 'en' ),
			'gateways'      => (object) $gateways,
			'totals'        => $cart_data['totals'] ?? null,
			'needsShipping' => (bool) ( $cart_data['needs_shipping'] ?? false ),
			'hasDigital'    => (bool) ( $cart_data['has_digital'] ?? false ),
			'cartUrl'       => Pages::url( 'cart' ),
			'checkoutUrl'   => Pages::url( 'checkout' ),
			'shopUrl'       => Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' ),
			'accountUrl'    => Account::account_url(),
			'payLabel'      => self::pay_label_template(),
			'freeLabel'     => __( 'Complete my order', 'wahou-commerce' ),
			'storeCountry'  => Settings::store_country(),
			'analytics'     => Settings::bool( 'analytics_datalayer' ),
			'i18n'          => array(
				'required'          => __( 'This field is required.', 'wahou-commerce' ),
				'invalidEmail'      => __( 'Please enter a valid email address.', 'wahou-commerce' ),
				'checkFields'       => __( 'Please check the highlighted fields.', 'wahou-commerce' ),
				'genericError'      => __( 'Something went wrong. Please try again.', 'wahou-commerce' ),
				'networkError'      => __( 'Connection problem. Check your internet connection and try again.', 'wahou-commerce' ),
				'free'              => __( 'Free', 'wahou-commerce' ),
				/* translators: %s: number of business days (1). */
				'dayOne'            => _n( '%s business day', '%s business days', 1, 'wahou-commerce' ),
				/* translators: %s: number of business days. */
				'daysMany'          => _n( '%s business day', '%s business days', 5, 'wahou-commerce' ),
				/* translators: 1: minimum days, 2: maximum days. */
				'daysRange'         => __( '%1$s–%2$s business days', 'wahou-commerce' ),
				'noShipping'        => __( 'We do not deliver to this country yet.', 'wahou-commerce' ),
				'vatChecking'       => __( 'Checking the VAT number…', 'wahou-commerce' ),
				'vatValid'          => __( 'Valid VAT number.', 'wahou-commerce' ),
				'vatValidReverse'   => __( 'Valid VAT number: VAT is reverse charged.', 'wahou-commerce' ),
				'vatInvalid'        => __( 'This VAT number is not valid in VIES.', 'wahou-commerce' ),
				'vatUnavailable'    => __( 'The EU VAT check is unavailable right now. You can continue: VAT will be charged.', 'wahou-commerce' ),
				'couponApplied'     => __( 'Coupon applied.', 'wahou-commerce' ),
				'couponRemoved'     => __( 'Coupon removed.', 'wahou-commerce' ),
				'enterCoupon'       => __( 'Please enter a coupon code.', 'wahou-commerce' ),
				'chooseGateway'     => __( 'Please choose a payment method.', 'wahou-commerce' ),
				'useGatewayButton'  => __( 'Use the payment button of the selected method to complete your order.', 'wahou-commerce' ),
				'consentsRequired'  => __( 'Please tick the required boxes before paying.', 'wahou-commerce' ),
				'gatewayUnavailable' => __( 'This payment method is not available. Please choose another one.', 'wahou-commerce' ),
				'stillProcessing'   => __( 'Your payment is still being confirmed. You will receive an email as soon as it is validated.', 'wahou-commerce' ),
				'offerError'        => __( 'The payment could not be completed. You have not been charged for this offer.', 'wahou-commerce' ),
				'showSummary'       => __( 'Show order summary', 'wahou-commerce' ),
				'hideSummary'       => __( 'Hide order summary', 'wahou-commerce' ),
				'updated'           => __( 'Cart updated.', 'wahou-commerce' ),
				'removed'           => __( 'Item removed.', 'wahou-commerce' ),
			),
		);

		if ( 'checkout' === $context && $cart && $cart_data && $data['analytics'] ) {
			$data['beginCheckout'] = array(
				'currency' => $currency,
				'value'    => (float) Money::to_decimal( (int) $cart_data['totals']['total'], $currency ),
				'coupon'   => $cart->coupon(),
				'items'    => self::analytics_items( $cart ),
			);
		}

		/**
		 * Filters the data passed to checkout.js.
		 */
		return apply_filters( 'wahou_checkout_client_data', $data, $context );
	}

	/* ------------------------------------------------------------------
	 * Renderers (blocks & shortcodes).
	 * ------------------------------------------------------------------ */

	public static function render_cart(): string {
		self::enqueue_assets( 'cart' );
		$cart = Cart::current();
		return Template::get(
			'cart/page',
			array(
				'cart'   => $cart,
				'totals' => $cart && ! $cart->is_empty() ? $cart->totals() : array(),
				'flash'  => self::pull_flash( $cart ),
			)
		);
	}

	public static function render_checkout(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['wahou_order'] ) ? sanitize_text_field( wp_unslash( $_GET['wahou_order'] ) ) : '';
		$offer_id = absint( $_GET['wahou_offer'] ?? 0 );
		// phpcs:enable

		if ( '' !== $key ) {
			self::enqueue_assets( $offer_id ? 'offer' : 'thankyou' );
			$order = self::$order && self::$order->key() === $key ? self::$order : Order::find_by_key( $key );
			if ( ! $order ) {
				return Template::get( 'checkout/not-found' );
			}
			if ( ! self::can_view_order( $order ) ) {
				return Template::get(
					'checkout/verify',
					array(
						'order' => $order,
						'error' => self::verification_error(),
					)
				);
			}
			if ( $offer_id && self::offer_is_valid( $order, $offer_id ) ) {
				$vars = self::offer_vars( $order, $offer_id );
				if ( $vars ) {
					return Template::get( 'checkout/offer', $vars );
				}
			}
			return Template::get( 'checkout/thank-you', self::thankyou_vars( $order ) );
		}

		self::enqueue_assets( 'checkout' );
		if ( ! is_user_logged_in() && ! Settings::bool( 'guest_checkout' ) && ! Settings::bool( 'auto_create_account' ) ) {
			return Account::render();
		}
		$cart = Cart::current();
		if ( ! $cart || $cart->is_empty() ) {
			return Template::get( 'checkout/empty' );
		}
		return Template::get( 'checkout/form', self::form_vars( $cart ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function form_vars( Cart $cart ): array {
		$totals         = $cart->totals();
		$needs_shipping = (bool) $totals['needs_shipping'];
		$bump           = Offers::bump_for_cart( $cart );
		$terms_id       = Pages::id( 'terms' );
		$privacy_id     = Pages::id( 'privacy' );

		return array(
			'cart'              => $cart,
			'totals'            => $totals,
			'prefill'           => self::prefill( $cart ),
			'needs_shipping'    => $needs_shipping,
			'has_digital'       => $cart->has_digital(),
			'countries'         => Checkout::countries( $cart ),
			'billing_countries' => $needs_shipping ? Countries::all() : Checkout::countries( $cart ),
			'gateways'          => Gateways::available(),
			'bump'              => $bump ? self::bump_vars( $cart, $bump ) : null,
			'pay_label'         => self::pay_label( (int) $totals['total'], (string) $totals['currency'] ),
			'terms_url'         => $terms_id && Settings::bool( 'terms_checkbox' ) ? (string) get_permalink( $terms_id ) : '',
			'privacy_url'       => $privacy_id ? (string) get_permalink( $privacy_id ) : '',
			'digital_waiver'    => Settings::bool( 'digital_waiver' ) && $cart->has_digital(),
			'recovery_mode'     => Settings::bool( 'recovery_enabled' ) ? Settings::str( 'recovery_consent' ) : '',
			'order_notes'       => Settings::bool( 'order_notes' ),
			'honeypot'          => Settings::bool( 'honeypot' ),
			'show_vat_field'    => Settings::bool( 'tax_enabled' ) && Countries::is_eu( Settings::store_country() ),
			'company_field'     => Settings::str( 'field_company' ),
			'login_url'         => ! is_user_logged_in() && Pages::id( 'account' ) ? add_query_arg( 'redirect_to', rawurlencode( Pages::url( 'checkout' ) ), Account::account_url() ) : '',
		);
	}

	/**
	 * Values to prefill: what the shopper already typed in this cart wins over
	 * the saved account details, which win over the last order.
	 *
	 * @return array{email:string, shipping:array<string,string>, billing:array<string,string>, is_business:bool}
	 */
	public static function prefill( Cart $cart ): array {
		$customer = $cart->customer();
		$shipping = array();
		$billing  = array();
		$email    = '';

		if ( is_user_logged_in() ) {
			$user     = wp_get_current_user();
			$email    = (string) $user->user_email;
			$shipping = array_filter( (array) get_user_meta( $user->ID, 'wahou_shipping', true ), 'is_scalar' );
			$billing  = array_filter( (array) get_user_meta( $user->ID, 'wahou_billing', true ), 'is_scalar' );
			if ( ! $shipping && ! $billing ) {
				$last = Order::query(
					array(
						'customer_id' => (int) $user->ID,
						'paid'        => true,
						'limit'       => 1,
					)
				)['orders'][0] ?? null;
				if ( $last ) {
					$billing  = $last->billing();
					$shipping = $last->shipping_address();
				}
			}
			if ( empty( $billing['first_name'] ) && '' !== (string) $user->first_name ) {
				$billing['first_name'] = (string) $user->first_name;
				$billing['last_name']  = (string) $user->last_name;
			}
			if ( ! $shipping ) {
				$shipping = $billing;
				unset( $shipping['vat_number'], $shipping['company'] );
			}
		}

		if ( ! empty( $customer['email'] ) ) {
			$email = (string) $customer['email'];
		} elseif ( '' === $email && '' !== $cart->email() ) {
			$email = $cart->email();
		}

		$tax_group = $cart->needs_shipping() ? 'shipping' : 'billing';
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( ! empty( $customer[ $field ] ) ) {
				$shipping[ $field ] = (string) $customer[ $field ];
				$billing[ $field ]  = (string) $customer[ $field ];
			}
		}
		foreach ( array( 'country', 'postcode', 'city' ) as $field ) {
			if ( ! empty( $customer[ $field ] ) ) {
				if ( 'shipping' === $tax_group ) {
					$shipping[ $field ] = (string) $customer[ $field ];
				} else {
					$billing[ $field ] = (string) $customer[ $field ];
				}
			}
		}
		foreach ( array( 'company', 'vat_number' ) as $field ) {
			if ( ! empty( $customer[ $field ] ) ) {
				$billing[ $field ] = (string) $customer[ $field ];
			}
		}

		$allowed = Checkout::countries( $cart );
		$default = '';
		foreach ( array( Request::geo_country(), Settings::store_country() ) as $candidate ) {
			if ( '' !== $candidate && isset( $allowed[ $candidate ] ) ) {
				$default = $candidate;
				break;
			}
		}
		if ( '' === $default ) {
			$default = (string) array_key_first( $allowed );
		}
		if ( 'shipping' === $tax_group ) {
			if ( empty( $shipping['country'] ) || ! isset( $allowed[ $shipping['country'] ] ) ) {
				$shipping['country'] = $default;
			}
			if ( empty( $billing['country'] ) ) {
				$billing['country'] = $shipping['country'];
			}
		} elseif ( empty( $billing['country'] ) || ! isset( $allowed[ $billing['country'] ] ) ) {
			$billing['country'] = $default;
		}

		return array(
			'email'       => $email,
			'shipping'    => array_map( 'strval', $shipping ),
			'billing'     => array_map( 'strval', $billing ),
			'is_business' => ! empty( $billing['vat_number'] ) || ! empty( $billing['company'] ),
		);
	}

	/* ------------------------------------------------------------------
	 * Form helpers (used by templates).
	 * ------------------------------------------------------------------ */

	/**
	 * Print one form field.
	 *
	 * @param array<string, mixed>  $field     Definition from Checkout::address_fields().
	 * @param array<string, string> $countries Options for country selects.
	 * @param array<string, string> $extra     Extra attributes for the control.
	 */
	public static function field( string $group, string $name, array $field, string $value = '', array $countries = array(), array $extra = array() ): void {
		$id        = 'wahou-' . ( '' !== $group ? $group . '-' : '' ) . str_replace( '_', '-', $name );
		$required  = ! empty( $field['required'] ) && empty( $field['business'] );
		$type      = (string) ( $field['type'] ?? 'text' );
		$classes   = array( 'wahou-field', 'wahou-field--' . sanitize_html_class( $name ) );
		if ( ! empty( $field['half'] ) ) {
			$classes[] = 'wahou-field--half';
		}
		$attrs = array_merge(
			array(
				'id'               => $id,
				'name'             => '' !== $group ? $group . '[' . $name . ']' : $name,
				'autocomplete'     => (string) ( $field['autocomplete'] ?? 'on' ),
				'aria-describedby' => $id . '-error',
			),
			$extra
		);
		if ( $required ) {
			$attrs['required']      = 'required';
			$attrs['aria-required'] = 'true';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<label class="wahou-field__label" for="' . esc_attr( $id ) . '">' . esc_html( (string) $field['label'] );
		if ( ! $required && empty( $field['business'] ) && false === strpos( (string) $field['label'], '(' ) ) {
			echo ' <span class="wahou-field__optional">' . esc_html__( '(optional)', 'wahou-commerce' ) . '</span>';
		}
		echo '</label>';

		if ( 'country' === $type ) {
			echo '<select class="wahou-select"' . self::attrs( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in attrs().
			foreach ( $countries as $code => $label ) {
				echo '<option value="' . esc_attr( (string) $code ) . '"' . selected( $value, (string) $code, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea class="wahou-input wahou-textarea" rows="3"' . self::attrs( $attrs ) . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in attrs().
		} else {
			$attrs['type']  = $type;
			$attrs['value'] = $value;
			foreach ( array( 'inputmode', 'placeholder', 'maxlength', 'pattern' ) as $optional ) {
				if ( ! empty( $field[ $optional ] ) ) {
					$attrs[ $optional ] = (string) $field[ $optional ];
				}
			}
			echo '<input class="wahou-input"' . self::attrs( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in attrs().
		}
		echo '<p class="wahou-field__error" id="' . esc_attr( $id . '-error' ) . '" hidden></p>';
		echo '</div>';
	}

	/**
	 * @param array<string, string> $attrs
	 */
	private static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$out .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * Address fields in checkout order (country first, then name and address).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function ordered_fields( string $group, bool $full ): array {
		$fields  = Checkout::address_fields( $group, $full );
		$ordered = array();
		foreach ( array( 'country', 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'phone' ) as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$ordered[ $name ] = $fields[ $name ];
				unset( $fields[ $name ] );
			}
		}
		return $ordered + $fields;
	}

	public static function days_label( int $min, int $max ): string {
		if ( $max <= 0 ) {
			return '';
		}
		if ( $min <= 0 || $min >= $max ) {
			/* translators: %s: number of business days. */
			return sprintf( _n( '%s business day', '%s business days', $max, 'wahou-commerce' ), number_format_i18n( $max ) );
		}
		/* translators: 1: minimum days, 2: maximum days. */
		return sprintf( __( '%1$s–%2$s business days', 'wahou-commerce' ), number_format_i18n( $min ), number_format_i18n( $max ) );
	}

	/**
	 * Shipping method radios (also re-rendered client-side after updates).
	 *
	 * @param array<string, mixed> $totals
	 */
	public static function shipping_options_html( array $totals ): string {
		$options = (array) ( $totals['shipping_options'] ?? array() );
		if ( ! $options ) {
			$message = (string) ( $totals['errors']['shipping'] ?? __( 'Enter your address to see the delivery options.', 'wahou-commerce' ) );
			return '<p class="wahou-notice wahou-notice--warning wahou-checkout__no-shipping">' . esc_html( $message ) . '</p>';
		}
		$currency = (string) $totals['currency'];
		$html     = '';
		foreach ( $options as $option ) {
			$checked = $option['id'] === ( $totals['shipping_method'] ?? '' );
			$days    = self::days_label( (int) $option['min_days'], (int) $option['max_days'] );
			$html   .= sprintf(
				'<label class="wahou-option%1$s"><input type="radio" name="shipping_method" value="%2$s"%3$s><span class="wahou-option__main"><span class="wahou-option__title">%4$s</span>%5$s</span><span class="wahou-option__price">%6$s</span></label>',
				$checked ? ' is-selected' : '',
				esc_attr( (string) $option['id'] ),
				$checked ? ' checked' : '',
				esc_html( (string) $option['title'] ),
				'' !== $days ? '<span class="wahou-option__meta">' . esc_html( $days ) . '</span>' : '',
				esc_html( (int) $option['cost'] > 0 ? Money::format( (int) $option['cost'], $currency ) : __( 'Free', 'wahou-commerce' ) )
			);
		}
		return $html;
	}

	public static function pay_label_template(): string {
		$label = trim( Settings::str( 'pay_button_label' ) );
		if ( '' === $label ) {
			/* translators: %s: order total, e.g. "49,00 €". "Pay now" is an unambiguous wording for an order with an obligation to pay (EU Directive 2011/83 art. 8(2)). */
			return __( 'Pay now – %s', 'wahou-commerce' );
		}
		return false === strpos( $label, '%s' ) ? $label . ' – %s' : $label;
	}

	public static function pay_label( int $amount, string $currency ): string {
		if ( $amount <= 0 ) {
			return __( 'Complete my order', 'wahou-commerce' );
		}
		return str_replace( '%s', Money::format( $amount, $currency ), self::pay_label_template() );
	}

	/**
	 * Tax inclusive price of a product line for the cart's customer.
	 *
	 * @param array<string, mixed> $context Calculator context.
	 */
	private static function display_price( Product $product, ?string $variant_id, int $unit_price, array $context ): int {
		$line   = array(
			'key'            => 'preview',
			'product_id'     => $product->id(),
			'variant_id'     => $variant_id,
			'qty'            => 1,
			'unit_price'     => $unit_price,
			'tax_class'      => $product->tax_class(),
			'needs_shipping' => false,
			'kind'           => $product->kind(),
			'type'           => $product->type(),
		);
		$totals = Calculator::calculate( array( $line ), $context );
		return (int) ( $totals['lines']['preview']['total'] ?? $unit_price );
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return array<string, mixed>|null
	 */
	private static function bump_vars( Cart $cart, array $offer ): ?array {
		$product = Product::find( (int) $offer['product_id'] );
		if ( ! $product ) {
			return null;
		}
		$vid      = '' !== $offer['variant_id'] ? (string) $offer['variant_id'] : null;
		$customer = $cart->customer();
		$context  = array(
			'country'   => $customer['country'] ?? '',
			'postcode'  => $customer['postcode'] ?? '',
			'vat_valid' => ! empty( $customer['vat_valid'] ),
			'currency'  => Settings::currency(),
		);
		$price    = self::display_price( $product, $vid, Offers::price( $offer ), $context );
		$regular  = self::display_price( $product, $vid, $product->price( $vid ), $context );
		return array(
			'id'       => (int) $offer['id'],
			'headline' => '' !== $offer['headline'] ? $offer['headline'] : sprintf(
				/* translators: %s: product name. */
				__( 'Special offer: add %s to your order', 'wahou-commerce' ),
				$product->name()
			),
			'text'     => '' !== $offer['text'] ? $offer['text'] : $product->summary( 25 ),
			'name'     => $product->name(),
			'image_id' => $product->image_id( $vid ),
			'price'    => $price,
			'regular'  => $regular,
			'percent'  => $regular > $price && $regular > 0 ? (int) round( ( $regular - $price ) * 100 / $regular ) : 0,
			'in_cart'  => '' !== Offers::bump_key( $cart, (int) $offer['id'] ),
			'currency' => Settings::currency(),
		);
	}

	/* ------------------------------------------------------------------
	 * Order received page.
	 * ------------------------------------------------------------------ */

	/**
	 * Who may see an order page: staff, the customer's account, the browser
	 * that placed it, anyone holding the link during the first 2 hours, or
	 * someone who confirmed the order email.
	 */
	public static function can_view_order( Order $order ): bool {
		if ( current_user_can( 'manage_wahou_orders' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id && $order->customer_id() === $user_id ) {
			return true;
		}
		$created = strtotime( $order->created_at() . ' UTC' );
		if ( $created && $created > time() - 2 * HOUR_IN_SECONDS ) {
			return true;
		}
		$token = isset( $_COOKIE[ Cart::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Cart::COOKIE ] ) ) : '';
		if ( '' !== $token && $order->cart_id() ) {
			$cart = Cart::find( $order->cart_id() );
			if ( $cart && hash_equals( $cart->token(), $token ) ) {
				return true;
			}
		}
		$cookie = isset( $_COOKIE[ 'wahou_ov_' . $order->id() ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ 'wahou_ov_' . $order->id() ] ) ) : '';
		return '' !== $cookie && Crypto::verify_short( 'ov|' . $order->key(), $cookie );
	}

	private static ?string $verification_error = null;

	private static function verification_error(): string {
		return (string) self::$verification_error;
	}

	/**
	 * Email confirmation form for old order links.
	 */
	private static function handle_verification( Order $order ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['wahou_verify_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! self::verify_post( 'wahou_verify_' . $order->id() ) ) {
			self::$verification_error = __( 'Your session has expired. Please try again.', 'wahou-commerce' );
			return;
		}
		if ( ! RateLimiter::hit( 'order_verify', 10, 15 * MINUTE_IN_SECONDS ) ) {
			self::$verification_error = __( 'Too many attempts. Please wait a few minutes and try again.', 'wahou-commerce' );
			return;
		}
		$email = strtolower( trim( sanitize_email( wp_unslash( $_POST['wahou_verify_email'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $email || ! hash_equals( strtolower( $order->email() ), $email ) ) {
			self::$verification_error = __( 'This email address does not match the order.', 'wahou-commerce' );
			return;
		}
		setcookie(
			'wahou_ov_' . $order->id(),
			Crypto::short_sign( 'ov|' . $order->key() ),
			array(
				'expires'  => time() + DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		wp_safe_redirect( $order->received_url() );
		exit;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function thankyou_vars( Order $order ): array {
		$gateway = Gateways::get( $order->payment_method() );
		return array(
			'order'            => $order,
			'gateway'          => $gateway,
			'downloads'        => Downloads::for_order( $order ),
			'delivery'         => $order->needs_shipping() && $order->is_paid() ? self::delivery_window( $order ) : null,
			'purchase'         => self::purchase_data( $order ),
			'account_url'      => Account::account_url(),
			'shop_url'         => Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' ),
			'checkout_url'     => Pages::url( 'checkout' ),
			'thankyou_message' => Settings::str( 'thankyou_message' ),
			'has_account'      => is_user_logged_in() || Settings::bool( 'auto_create_account' ) || false !== get_user_by( 'email', $order->email() ),
		);
	}

	/**
	 * Estimated delivery dates from the chosen shipping method.
	 *
	 * @return array{from:int, to:int}|null Timestamps.
	 */
	public static function delivery_window( Order $order ): ?array {
		$line = $order->shipping_line();
		if ( ! $line || empty( $line['method_id'] ) ) {
			return null;
		}
		list( $zone_id, $method_id ) = array_pad( explode( ':', (string) $line['method_id'], 2 ), 2, '' );
		$days                        = null;
		foreach ( Shipping::zones() as $zone ) {
			if ( $zone['id'] !== $zone_id ) {
				continue;
			}
			foreach ( $zone['methods'] as $method ) {
				if ( $method['id'] === $method_id && $method['max_days'] > 0 ) {
					$days = array(
						'min' => (int) $method['min_days'],
						'max' => (int) $method['max_days'],
					);
				}
			}
		}
		if ( null === $days ) {
			$address = $order->shipping_address();
			$days    = Shipping::transit_days( (string) ( $address['country'] ?? $order->tax_country() ) );
		}
		if ( ! $days || $days['max'] <= 0 ) {
			return null;
		}
		$start      = $order->paid_at() ? (int) strtotime( $order->paid_at() . ' UTC' ) : time();
		$processing = max( 0, Settings::int( 'processing_days' ) );
		return array(
			'from' => self::add_business_days( $start, $processing + max( 0, $days['min'] ) ),
			'to'   => self::add_business_days( $start, $processing + $days['max'] ),
		);
	}

	private static function add_business_days( int $timestamp, int $days ): int {
		$business = array_values( array_filter( array_map( 'intval', (array) Settings::get( 'business_days', array( 1, 2, 3, 4, 5 ) ) ) ) );
		if ( ! $business ) {
			$business = array( 1, 2, 3, 4, 5 );
		}
		$date  = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
		$added = 0;
		for ( $guard = 0; $added < $days && $guard < 90; $guard++ ) {
			$date = $date->modify( '+1 day' );
			if ( in_array( (int) $date->format( 'N' ), $business, true ) ) {
				++$added;
			}
		}
		return $date->getTimestamp();
	}

	/**
	 * Formatted postal address (escaped HTML).
	 *
	 * @param array<string, string> $address
	 */
	public static function format_address( array $address ): string {
		$lines   = array();
		$name    = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		$lines[] = $name;
		$lines[] = $address['company'] ?? '';
		$lines[] = $address['address_1'] ?? '';
		$lines[] = $address['address_2'] ?? '';
		$lines[] = trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) );
		$lines[] = ! empty( $address['country'] ) ? Countries::name( (string) $address['country'] ) : '';
		$lines[] = $address['phone'] ?? '';
		if ( ! empty( $address['vat_number'] ) ) {
			/* translators: %s: VAT number. */
			$lines[] = sprintf( __( 'VAT: %s', 'wahou-commerce' ), $address['vat_number'] );
		}
		$lines = array_filter( array_map( 'trim', array_map( 'strval', $lines ) ), 'strlen' );
		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	/**
	 * GA4 "purchase" payload, returned once per order.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function purchase_data( Order $order ): ?array {
		if ( ! Settings::bool( 'analytics_datalayer' ) || ! $order->is_paid() || $order->get_meta( 'tracked' ) ) {
			return null;
		}
		$order->update_meta( 'tracked', 1 );
		$currency = $order->currency();
		$items    = array();
		foreach ( $order->items() as $item ) {
			$items[] = array(
				'item_id'      => '' !== $item['sku'] ? $item['sku'] : (string) $item['product_id'],
				'item_name'    => $item['name'],
				'item_variant' => (string) ( $item['meta']['variant_label'] ?? '' ),
				'price'        => (float) Money::to_decimal( (int) $item['unit_price'], $currency ),
				'quantity'     => (int) $item['quantity'],
			);
		}
		return array(
			'transaction_id' => $order->number(),
			'value'          => (float) Money::to_decimal( $order->total(), $currency ),
			'tax'            => (float) Money::to_decimal( $order->tax_total(), $currency ),
			'shipping'       => (float) Money::to_decimal( $order->shipping_total(), $currency ),
			'currency'       => $currency,
			'coupon'         => $order->coupon_code(),
			'items'          => $items,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function analytics_items( Cart $cart ): array {
		$totals   = $cart->totals();
		$currency = (string) $totals['currency'];
		$items    = array();
		foreach ( $cart->lines() as $line ) {
			$money = $totals['lines'][ $line['key'] ] ?? null;
			if ( ! $money ) {
				continue;
			}
			$items[] = array(
				'item_id'      => '' !== $line['sku'] ? $line['sku'] : (string) $line['product_id'],
				'item_name'    => $line['name'],
				'item_variant' => $line['variant_label'],
				'price'        => (float) Money::to_decimal( (int) $money['unit'], $currency ),
				'quantity'     => (int) $line['qty'],
			);
		}
		return $items;
	}

	/* ------------------------------------------------------------------
	 * Post-purchase offers.
	 * ------------------------------------------------------------------ */

	private static function within_offer_window( Order $order ): bool {
		$paid = $order->paid_at() ? strtotime( $order->paid_at() . ' UTC' ) : 0;
		return $paid && $paid >= time() - self::OFFER_WINDOW;
	}

	private static function offer_is_valid( Order $order, int $offer_id ): bool {
		if ( ! $offer_id || ! $order->is_paid() || $order->parent_id() || ! self::within_offer_window( $order ) ) {
			return false;
		}
		if ( in_array( $offer_id, array_map( 'intval', (array) $order->get_meta( 'offers_seen', array() ) ), true ) ) {
			return false;
		}
		$offer = Offers::get( $offer_id );
		if ( ! $offer || ! $offer['active'] || ! in_array( $offer['type'], array( 'upsell', 'downsell' ), true ) ) {
			return false;
		}
		$gateway = Gateways::get( $order->payment_method() );
		if ( ! $gateway || ! $gateway->supports( 'one_click_upsell' ) || ! $order->get_meta( 'one_click_ready' ) ) {
			return false;
		}
		$product = Product::find( (int) $offer['product_id'] );
		$vid     = '' !== $offer['variant_id'] ? (string) $offer['variant_id'] : null;
		return $product && $product->is_purchasable() && $product->in_stock( $vid );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function offer_vars( Order $order, int $offer_id ): ?array {
		$offer   = Offers::get( $offer_id );
		$product = $offer ? Product::find( (int) $offer['product_id'] ) : null;
		if ( ! $offer || ! $product ) {
			return null;
		}
		$vid      = '' !== $offer['variant_id'] ? (string) $offer['variant_id'] : null;
		$evidence = (array) $order->get_meta( 'tax_evidence', array() );
		$address  = $order->needs_shipping() ? $order->shipping_address() : $order->billing();
		$context  = array(
			'country'   => '' !== $order->tax_country() ? $order->tax_country() : (string) ( $address['country'] ?? '' ),
			'postcode'  => (string) ( $address['postcode'] ?? '' ),
			'vat_valid' => ! empty( $evidence['vat_valid'] ),
			'currency'  => $order->currency(),
		);
		$price    = self::display_price( $product, $vid, Offers::price( $offer ), $context );
		$regular  = self::display_price( $product, $vid, $product->price( $vid ), $context );
		$gateway  = Gateways::get( $order->payment_method() );
		return array(
			'order'    => $order,
			'offer'    => $offer,
			'product'  => $product,
			'image_id' => $product->image_id( $vid ),
			'price'    => $price,
			'regular'  => $regular,
			'percent'  => $regular > $price && $regular > 0 ? (int) round( ( $regular - $price ) * 100 / $regular ) : 0,
			'currency' => $order->currency(),
			'method'   => $gateway ? $gateway->title() : $order->payment_method_title(),
		);
	}

	/**
	 * No-JS fallback for the offer buttons.
	 */
	private static function handle_offer_post( Order $order ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['wahou_offer_decision'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$offer_id = absint( $_POST['wahou_offer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! self::verify_post( 'wahou_offer_' . $order->id() ) || ! self::offer_is_valid( $order, $offer_id ) ) {
			wp_safe_redirect( $order->received_url() );
			exit;
		}
		$decision = sanitize_key( wp_unslash( $_POST['wahou_offer_decision'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'accept' === $decision ) {
			$result = Offers::accept( $order, $offer_id );
			$url    = ! is_wp_error( $result ) && ! empty( $result['redirect'] ) ? (string) $result['redirect'] : $order->received_url();
		} else {
			$url = Offers::decline( $order, $offer_id );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
