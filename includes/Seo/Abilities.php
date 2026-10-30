<?php
/**
 * WordPress Abilities API (WordPress 6.9+): read-only store abilities that AI
 * agents can discover and call (REST /wp-abilities/v1, MCP adapters...).
 *
 *   wahou-commerce/search-products     Search the catalog.
 *   wahou-commerce/get-product         Full details of one product.
 *   wahou-commerce/get-store-info      Currency, VAT, delivery, returns, payment, contact.
 *   wahou-commerce/get-checkout-link   Direct checkout URL for a product.
 *
 * All abilities are public and read-only: they never expose customer data.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public const CATEGORY = 'wahou-commerce';

	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	public static function register_category(): void {
		if ( ! Settings::bool( 'seo_abilities' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Store catalog', 'wahou-commerce' ),
				'description' => __( 'Read-only access to the products, policies and checkout links of the store.', 'wahou-commerce' ),
			)
		);
	}

	public static function register(): void {
		if ( ! Settings::bool( 'seo_abilities' ) ) {
			return;
		}
		$meta = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'public'       => true,
		);

		wp_register_ability(
			self::CATEGORY . '/search-products',
			array(
				'label'               => __( 'Search products', 'wahou-commerce' ),
				'description'         => __( 'Searches the store catalog by keywords, category, maximum price and availability. Prices include VAT and are given in the store currency.', 'wahou-commerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'query'     => array(
							'type'        => 'string',
							'description' => __( 'Keywords to search for.', 'wahou-commerce' ),
						),
						'category'  => array(
							'type'        => 'string',
							'description' => __( 'Category slug.', 'wahou-commerce' ),
						),
						'max_price' => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => __( 'Maximum price (tax included), in store currency units.', 'wahou-commerce' ),
						),
						'in_stock'  => array(
							'type'        => 'boolean',
							'description' => __( 'Only return products that can be bought now.', 'wahou-commerce' ),
						),
						'kind'      => array(
							'type' => 'string',
							'enum' => array( 'physical', 'digital', 'service' ),
						),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'currency' => array( 'type' => 'string' ),
						'count'    => array( 'type' => 'integer' ),
						'products' => array(
							'type'  => 'array',
							'items' => self::product_summary_schema(),
						),
					),
				),
				'execute_callback'    => array( self::class, 'search_products' ),
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-product',
			array(
				'label'               => __( 'Get product details', 'wahou-commerce' ),
				'description'         => __( 'Returns the full details of a product: price, availability, options, key benefits, description, FAQ, delivery and returns.', 'wahou-commerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'slug' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'name'     => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'markdown' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'get_product' ),
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-store-info',
			array(
				'label'               => __( 'Get store information', 'wahou-commerce' ),
				'description'         => __( 'Returns the store currency, VAT display, delivery zones and delays, return policy, payment methods, contact and legal pages.', 'wahou-commerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'    => 'object',
					'default' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'currency' => array( 'type' => 'string' ),
						'facts'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( self::class, 'get_store_info' ),
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);

		wp_register_ability(
			self::CATEGORY . '/get-checkout-link',
			array(
				'label'               => __( 'Get a checkout link', 'wahou-commerce' ),
				'description'         => __( 'Builds a direct checkout link for a product so the customer can pay in a few taps. The link does not place any order by itself.', 'wahou-commerce' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'product_id' ),
					'properties'           => array(
						'product_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'quantity'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 99,
						),
						'variant_id' => array( 'type' => 'string' ),
						'coupon'     => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'         => array( 'type' => 'string' ),
						'product_url' => array( 'type' => 'string' ),
						'price'       => array( 'type' => 'string' ),
						'currency'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'get_checkout_link' ),
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function product_summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'url'          => array( 'type' => 'string' ),
				'markdown_url' => array( 'type' => 'string' ),
				'price'        => array( 'type' => 'string' ),
				'price_text'   => array( 'type' => 'string' ),
				'in_stock'     => array( 'type' => 'boolean' ),
				'kind'         => array( 'type' => 'string' ),
				'summary'      => array( 'type' => 'string' ),
				'image'        => array( 'type' => 'string' ),
				'rating'       => array( 'type' => 'number' ),
				'reviews'      => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>
	 */
	public static function search_products( $input = null ): array {
		$input = is_array( $input ) ? $input : array();
		$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
		$args  = array(
			'post_type'      => PostTypes::PRODUCT,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => min( 150, $limit * 3 ),
			'no_found_rows'  => true,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		);
		$query = trim( (string) ( $input['query'] ?? '' ) );
		if ( '' !== $query ) {
			$args['s']       = $query;
			$args['orderby'] = 'relevance';
		}
		if ( ! empty( $input['category'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => PostTypes::CATEGORY,
					'field'    => 'slug',
					'terms'    => sanitize_title( (string) $input['category'] ),
				),
			);
		}
		$max      = isset( $input['max_price'] ) ? Money::to_minor( (float) $input['max_price'] ) : null;
		$products = array();
		foreach ( get_posts( $args ) as $post ) {
			$product = Product::find( $post );
			if ( ! $product ) {
				continue;
			}
			if ( ! empty( $input['in_stock'] ) && ! $product->in_stock() ) {
				continue;
			}
			if ( ! empty( $input['kind'] ) && $product->kind() !== $input['kind'] ) {
				continue;
			}
			if ( null !== $max ) {
				list( $min_price ) = $product->price_range();
				if ( Seo::gross_price( $product, $min_price ) > $max ) {
					continue;
				}
			}
			$products[] = self::summary( $product );
			if ( count( $products ) >= $limit ) {
				break;
			}
		}
		return array(
			'currency' => Settings::currency(),
			'count'    => count( $products ),
			'products' => $products,
		);
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_product( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$product = null;
		if ( ! empty( $input['id'] ) ) {
			$product = Product::find( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$post    = get_page_by_path( sanitize_title( (string) $input['slug'] ), OBJECT, PostTypes::PRODUCT );
			$product = $post ? Product::find( $post ) : null;
		}
		if ( ! $product || 'publish' !== $product->status() || post_password_required( $product->post() ) ) {
			return new \WP_Error( 'wahou_product_not_found', __( 'Product not found.', 'wahou-commerce' ) );
		}

		$currency = Settings::currency();
		$variants = array();
		foreach ( $product->variants() as $variant ) {
			$vid        = (string) $variant['id'];
			$variants[] = array(
				'id'         => $vid,
				'label'      => $product->variant_label( $vid ),
				'attributes' => (array) ( $variant['attributes'] ?? array() ),
				'price'      => Money::to_decimal( Seo::gross_price( $product, $product->price( $vid ) ), $currency ),
				'in_stock'   => $product->in_stock( $vid ),
			);
		}

		return array_merge(
			self::summary( $product ),
			array(
				'currency'       => $currency,
				'type'           => $product->type(),
				'brand'          => $product->brand(),
				'sku'            => $product->sku(),
				'gtin'           => $product->gtin(),
				'availability'   => Seo::availability_label( $product ),
				'highlights'     => $product->highlights(),
				'categories'     => array_map( static fn( $t ) => $t->name, $product->categories() ),
				'variants'       => $variants,
				'faq'            => array_map(
					static fn( $row ) => array(
						'question' => wp_strip_all_tags( (string) $row['q'] ),
						'answer'   => wp_strip_all_tags( (string) $row['a'] ),
					),
					$product->faq()
				),
				'checkout_url'   => $product->variants() ? '' : self::buy_url( $product->id(), 1 ),
				'markdown'       => Geo::product_markdown( $product ),
			)
		);
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>
	 */
	public static function get_store_info( $input = null ): array {
		$zones = array();
		foreach ( Shipping::zones() as $zone ) {
			$methods = array();
			foreach ( $zone['methods'] as $method ) {
				if ( ! $method['enabled'] ) {
					continue;
				}
				$methods[] = array(
					'type'       => $method['type'],
					'title'      => '' !== $method['title'] ? $method['title'] : Shipping::default_title( $method['type'] ),
					'cost'       => Money::to_decimal( (int) $method['cost'] ),
					'free_from'  => 'free' === $method['type'] ? Money::to_decimal( (int) $method['min_amount'] ) : null,
					'days'       => $method['max_days'] > 0 ? array( $method['min_days'], $method['max_days'] ) : null,
				);
			}
			$zones[] = array(
				'name'      => $zone['name'],
				'countries' => $zone['countries'],
				'methods'   => $methods,
			);
		}
		$pages = array();
		foreach ( array( 'shop', 'terms', 'privacy', 'account' ) as $key ) {
			$id = Pages::id( $key );
			if ( $id ) {
				$pages[ $key ] = (string) get_permalink( $id );
			}
		}
		return array(
			'name'               => Settings::store_name(),
			'url'                => home_url( '/' ),
			'currency'           => Settings::currency(),
			'country'            => Settings::store_country(),
			'prices_include_tax' => Settings::bool( 'prices_include_tax' ),
			'facts'              => Geo::store_facts(),
			'shipping_zones'     => $zones,
			'returns'            => array(
				'days' => Settings::int( 'return_days' ),
				'fees' => Settings::str( 'return_fees' ),
			),
			'payment_methods'    => array_values( array_map( static fn( $g ) => wp_strip_all_tags( $g->title() ), Gateways::available() ) ),
			'contact_email'      => Settings::store_email(),
			'pages'              => $pages,
			'llms_txt'           => Geo::llms_url(),
		);
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_checkout_link( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$product = Product::find( (int) ( $input['product_id'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->status() ) {
			return new \WP_Error( 'wahou_product_not_found', __( 'Product not found.', 'wahou-commerce' ) );
		}
		$variant = (string) ( $input['variant_id'] ?? '' );
		if ( $product->variants() && ( '' === $variant || ! $product->variant( $variant ) ) ) {
			return new \WP_Error( 'wahou_choose_variant', __( 'This product has options: provide a valid variant_id (see get-product).', 'wahou-commerce' ) );
		}
		if ( ! $product->in_stock( '' !== $variant ? $variant : null ) ) {
			return new \WP_Error( 'wahou_out_of_stock', __( 'Sorry, this product is out of stock.', 'wahou-commerce' ) );
		}
		$qty = max( 1, min( 99, (int) ( $input['quantity'] ?? 1 ) ) );
		$url = self::buy_url( $product->id(), $qty, (string) ( $input['coupon'] ?? '' ) );
		if ( '' !== $variant ) {
			$url = add_query_arg( 'variant', rawurlencode( $variant ), $url );
		}
		return array(
			'url'         => $url,
			'product_url' => $product->permalink(),
			'price'       => Money::to_decimal( Seo::gross_price( $product, $product->price( '' !== $variant ? $variant : null ) ) * $qty ),
			'currency'    => Settings::currency(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function summary( Product $product ): array {
		list( $min ) = $product->price_range();
		return array(
			'id'           => $product->id(),
			'name'         => wp_strip_all_tags( $product->name() ),
			'url'          => $product->permalink(),
			'markdown_url' => Geo::markdown_url( $product ),
			'price'        => Money::to_decimal( Seo::gross_price( $product, $min ) ),
			'price_text'   => Geo::price_text( $product ),
			'in_stock'     => $product->in_stock(),
			'kind'         => $product->kind(),
			'summary'      => $product->summary( 40 ),
			'image'        => $product->image_url(),
			'rating'       => $product->rating_average(),
			'reviews'      => $product->rating_count(),
		);
	}

	private static function buy_url( int $product_id, int $qty = 1, string $coupon = '' ): string {
		if ( function_exists( 'wahou_buy_url' ) ) {
			return wahou_buy_url( $product_id, $qty, $coupon );
		}
		$args = array( 'wahou_buy' => $qty > 1 ? $product_id . ':' . $qty : $product_id );
		if ( '' !== $coupon ) {
			$args['wahou_coupon'] = $coupon;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}
}
