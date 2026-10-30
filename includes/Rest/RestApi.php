<?php
/**
 * Store REST API (namespace wahou/v1).
 *
 *   GET    /cart                       Cart (+ ?html=drawer,summary for server-rendered fragments)
 *   POST   /cart/items                 { product_id, qty, variant_id?, fbt_parent? }
 *   PATCH  /cart/items/{key}           { qty }
 *   DELETE /cart/items/{key}
 *   POST   /cart/coupon                { code }
 *   DELETE /cart/coupon
 *   POST   /cart/customer              { email?, country?, postcode?, vat_number?, shipping_method?, consent? }
 *   POST   /cart/bump                  { offer_id, add: bool }
 *   POST   /checkout                   Place the order, returns the payment step
 *   GET    /order/{key}                Order status (thank-you page polling)
 *   POST   /offer/{id}/accept          { order_key } one-click post-purchase offer
 *   POST   /offer/{id}/decline         { order_key }
 *   POST   /vat/check                  { vat_number, country }
 *   GET    /products                   Public catalog (agents, headless)
 *
 * Guest requests are authenticated by the httpOnly SameSite=Lax cart cookie,
 * so cross-site requests cannot touch a visitor's cart. Responses are never cached.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Rest;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Checkout\Checkout;
use WahouCommerce\Funnels\Offers;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\RateLimiter;
use WahouCommerce\Support\Template;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	public const NS = 'wahou/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'no_cache' ), 10, 3 );
	}

	public static function routes(): void {
		$public = '__return_true';

		register_rest_route(
			self::NS,
			'/cart',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_cart' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/cart/items',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'add_item' ),
				'permission_callback' => $public,
				'args'                => array(
					'product_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'qty'        => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
						'maximum' => 999,
					),
					'variant_id' => array(
						'type'    => 'string',
						'default' => '',
					),
					'fbt_parent' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/items/(?P<key>[a-f0-9]{32})',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'update_item' ),
					'permission_callback' => $public,
					'args'                => array(
						'qty' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
							'maximum'  => 999,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'remove_item' ),
					'permission_callback' => $public,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/coupon',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'apply_coupon' ),
					'permission_callback' => $public,
					'args'                => array(
						'code' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'remove_coupon' ),
					'permission_callback' => $public,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/customer',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'update_customer' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/cart/bump',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'toggle_bump' ),
				'permission_callback' => $public,
				'args'                => array(
					'offer_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'add'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/checkout',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'checkout' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/order/(?P<key>[A-Za-z0-9]{20,64})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'order_status' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/offer/(?P<id>\d+)/(?P<decision>accept|decline)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'offer_decision' ),
				'permission_callback' => $public,
				'args'                => array(
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/vat/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'vat_check' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'products' ),
				'permission_callback' => static fn() => Settings::bool( 'rest_public_products' ) || current_user_can( 'edit_wahou_products' ),
				'args'                => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'string' ),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Never cache store responses (carts are personal).
	 */
	public static function no_cache( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS . '/' ) && '/' . self::NS . '/products' !== $request->get_route() ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		return $response;
	}

	/* ------------------------------------------------------------------
	 * Cart.
	 * ------------------------------------------------------------------ */

	public static function get_cart( \WP_REST_Request $request ): \WP_REST_Response {
		$cart = Cart::current();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_item( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'cart_add', 120, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}
		$cart = Cart::current( true );
		$meta = array();
		if ( $request['fbt_parent'] ) {
			$meta['fbt_parent'] = (int) $request['fbt_parent'];
		}
		$result = $cart->add( (int) $request['product_id'], (int) $request['qty'], '' !== (string) $request['variant_id'] ? (string) $request['variant_id'] : null, $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cart->save();
		$response = self::cart_response( $cart, self::fragments( $request ) );
		$data              = $response->get_data();
		$data['added_key'] = $result;
		$response->set_data( $data );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_item( \WP_REST_Request $request ) {
		$cart = Cart::current();
		if ( ! $cart ) {
			return self::no_cart();
		}
		$result = $cart->set_qty( (string) $request['key'], (int) $request['qty'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_item( \WP_REST_Request $request ) {
		$cart = Cart::current();
		if ( ! $cart ) {
			return self::no_cart();
		}
		$cart->remove( (string) $request['key'] );
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function apply_coupon( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'coupon', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}
		$cart = Cart::current();
		if ( ! $cart || $cart->is_empty() ) {
			return self::no_cart();
		}
		$result = $cart->apply_coupon( (string) $request['code'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_coupon( \WP_REST_Request $request ) {
		$cart = Cart::current();
		if ( ! $cart ) {
			return self::no_cart();
		}
		$cart->remove_coupon();
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_customer( \WP_REST_Request $request ) {
		$cart = Cart::current();
		if ( ! $cart ) {
			return self::no_cart();
		}
		$params = (array) $request->get_json_params() + (array) $request->get_body_params();
		$cart->set_customer( $params );
		if ( isset( $params['shipping_method'] ) ) {
			$cart->set_shipping_method( (string) $params['shipping_method'] );
		}
		if ( isset( $params['consent'] ) ) {
			$cart->set_consent( (bool) $params['consent'] );
		}
		// Validate a business VAT number on the fly (reverse charge preview).
		if ( array_key_exists( 'vat_number', $params ) ) {
			$vat = (string) $params['vat_number'];
			if ( '' !== $vat ) {
				$check = Tax::validate_vat( $vat, (string) ( $cart->customer()['country'] ?? '' ) );
				$cart->set_customer(
					array(
						'vat_valid' => $check['valid'],
						'vat_name'  => $check['name'] ?? '',
					)
				);
			} else {
				$cart->set_customer( array( 'vat_valid' => false ) );
			}
		}
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function toggle_bump( \WP_REST_Request $request ) {
		$cart = Cart::current();
		if ( ! $cart ) {
			return self::no_cart();
		}
		$result = Offers::toggle_bump( $cart, (int) $request['offer_id'], (bool) $request['add'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cart->save();
		return self::cart_response( $cart, self::fragments( $request ) );
	}

	/* ------------------------------------------------------------------
	 * Checkout & orders.
	 * ------------------------------------------------------------------ */

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function checkout( \WP_REST_Request $request ) {
		$input  = (array) $request->get_json_params() + (array) $request->get_body_params();
		$result = Checkout::process( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_status( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'order_status', 120, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}
		$order = Order::find_by_key( (string) $request['key'] );
		if ( ! $order ) {
			return new \WP_Error( 'wahou_order_not_found', __( 'Order not found.', 'wahou-commerce' ), array( 'status' => 404 ) );
		}
		if ( 'pending' === $order->status() ) {
			$gateway = \WahouCommerce\Gateways\Gateways::get( $order->payment_method() );
			if ( $gateway ) {
				$gateway->handle_return( $order );
				$order->refresh();
			}
		}
		return rest_ensure_response(
			array(
				'status'       => $order->status(),
				'status_label' => $order->status_label(),
				'paid'         => $order->is_paid(),
				'number'       => $order->number(),
				'redirect'     => $order->is_paid() ? Offers::after_payment_url( $order ) : '',
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function offer_decision( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'offer', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return self::rate_limited();
		}
		$order = Order::find_by_key( (string) $request['order_key'] );
		if ( ! $order || ! $order->is_paid() ) {
			return new \WP_Error( 'wahou_order_not_found', __( 'Order not found.', 'wahou-commerce' ), array( 'status' => 404 ) );
		}
		// Offers are only valid for one hour after the purchase.
		if ( strtotime( $order->paid_at() . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
			return rest_ensure_response( array( 'redirect' => $order->received_url() ) );
		}
		if ( 'decline' === $request['decision'] ) {
			return rest_ensure_response( array( 'redirect' => Offers::decline( $order, (int) $request['id'] ) ) );
		}
		$result = Offers::accept( $order, (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function vat_check( \WP_REST_Request $request ) {
		$result = Tax::validate_vat( (string) $request->get_param( 'vat_number' ), (string) $request->get_param( 'country' ) );
		unset( $result['address'] );
		return rest_ensure_response( $result );
	}

	public static function products( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'post_type'      => PostTypes::PRODUCT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $request['per_page'],
			'paged'          => (int) $request['page'],
			's'              => (string) $request['search'],
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		);
		if ( ! empty( $request['category'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => PostTypes::CATEGORY,
					'field'    => 'slug',
					'terms'    => sanitize_title( (string) $request['category'] ),
				),
			);
		}
		$query    = new \WP_Query( $args );
		$products = array();
		foreach ( $query->posts as $post ) {
			$product = Product::find( $post );
			if ( $product ) {
				$products[] = $product->to_array();
			}
		}
		$response = rest_ensure_response( $products );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * @return string[]
	 */
	private static function fragments( \WP_REST_Request $request ): array {
		$html = (string) $request->get_param( 'html' );
		return '' === $html ? array() : array_intersect( array_map( 'trim', explode( ',', $html ) ), array( 'drawer', 'summary', 'page' ) );
	}

	/**
	 * Cart payload shared by every cart endpoint.
	 *
	 * @param string[] $fragments Server-rendered parts: drawer, summary, page.
	 */
	public static function cart_response( ?Cart $cart, array $fragments = array() ): \WP_REST_Response {
		return rest_ensure_response( self::cart_data( $cart, $fragments ) );
	}

	/**
	 * @param string[] $fragments
	 * @return array<string, mixed>
	 */
	public static function cart_data( ?Cart $cart, array $fragments = array() ): array {
		$currency = Settings::currency();
		if ( ! $cart ) {
			$data = array(
				'count'  => 0,
				'items'  => array(),
				'totals' => null,
				'empty'  => true,
				'html'   => array(),
			);
			foreach ( $fragments as $fragment ) {
				$data['html'][ $fragment ] = Template::get( 'cart/' . $fragment, array( 'cart' => null ) );
			}
			return $data;
		}

		$totals = $cart->totals();
		$items  = array();
		foreach ( $cart->lines() as $line ) {
			$money   = $totals['lines'][ $line['key'] ] ?? null;
			$items[] = array(
				'key'           => $line['key'],
				'product_id'    => $line['product_id'],
				'variant_id'    => $line['variant_id'],
				'name'          => $line['name'],
				'variant_label' => $line['variant_label'],
				'qty'           => $line['qty'],
				'max_qty'       => $line['max_qty'],
				'unit_price'    => $money ? $money['unit'] : 0,
				'line_total'    => $money ? $money['total'] : 0,
				'line_total_html' => Money::format( $money ? $money['total'] : 0, $currency ),
				'image'         => $line['image_id'] ? (string) wp_get_attachment_image_url( $line['image_id'], 'thumbnail' ) : '',
				'url'           => $line['permalink'],
				'is_bump'       => ! empty( $line['meta']['bump'] ),
				'kind'          => $line['kind'],
			);
		}

		$bump = Offers::bump_for_cart( $cart );
		$data = array(
			'count'           => $cart->count(),
			'empty'           => $cart->is_empty(),
			'items'           => $items,
			'coupon'          => $cart->coupon(),
			'email'           => $cart->email(),
			'needs_shipping'  => $totals['needs_shipping'],
			'has_digital'     => $cart->has_digital(),
			'totals'          => array(
				'currency'                => $totals['currency'],
				'subtotal'                => $totals['subtotal'],
				'discount'                => $totals['discount'],
				'shipping'                => $totals['shipping'],
				'tax'                     => $totals['tax'],
				'total'                   => $totals['total'],
				'subtotal_html'           => Money::format( $totals['subtotal'], $currency ),
				'discount_html'           => Money::format( $totals['discount'], $currency ),
				'shipping_html'           => Money::format( $totals['shipping'], $currency ),
				'tax_html'                => Money::format( $totals['tax'], $currency ),
				'total_html'              => Money::format( $totals['total'], $currency ),
				'tax_mode'                => $totals['tax_mode'],
				'shipping_options'        => array_map(
					static function ( $o ) use ( $currency ) {
						$o['cost_html'] = Money::format( (int) $o['cost'], $currency );
						return $o;
					},
					$totals['shipping_options']
				),
				'shipping_method'         => $totals['shipping_method'],
				'free_shipping_threshold' => $totals['free_shipping_threshold'],
				'free_shipping_remaining' => $totals['free_shipping_remaining'],
				'coupon'                  => $totals['coupon'],
				'errors'                  => $totals['errors'],
			),
			'bump'            => $bump ? array(
				'id'       => $bump['id'],
				'in_cart'  => '' !== Offers::bump_key( $cart, (int) $bump['id'] ),
			) : null,
			'html'            => array(),
		);
		foreach ( $fragments as $fragment ) {
			$data['html'][ $fragment ] = Template::get(
				'cart/' . $fragment,
				array(
					'cart'   => $cart,
					'totals' => $totals,
				)
			);
		}
		return apply_filters( 'wahou_cart_response', $data, $cart );
	}

	private static function no_cart(): \WP_Error {
		return new \WP_Error( 'wahou_no_cart', __( 'Your cart is empty.', 'wahou-commerce' ), array( 'status' => 404 ) );
	}

	private static function rate_limited(): \WP_Error {
		return new \WP_Error( 'wahou_rate_limited', __( 'Too many requests. Please wait a moment.', 'wahou-commerce' ), array( 'status' => 429 ) );
	}
}
