<?php
/**
 * Structured data (schema.org JSON-LD, one @graph per page).
 *
 *  - Product page: Product (or ProductGroup + hasVariant for options) with
 *    tax-inclusive Offer, StrikethroughPrice = Omnibus reference price,
 *    MerchantReturnPolicy, OfferShippingDetails per zone, AggregateRating,
 *    latest reviews, FAQPage.
 *  - Category/tag archives and the shop page: ItemList.
 *  - BreadcrumbList, and an OnlineStore entity on the front page when no SEO
 *    plugin describes the organization.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Compat\Compat;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'output' ), 25 );
	}

	public static function output(): void {
		if ( ! Settings::bool( 'seo_schema' ) || ! apply_filters( 'wahou_schema_enabled', true ) ) {
			return;
		}
		$graph = self::graph();
		if ( empty( $graph ) ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);
		if ( $json ) {
			echo '<script type="application/ld+json" class="wahou-schema">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG.
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function graph(): array {
		$graph = array();

		if ( is_singular( PostTypes::PRODUCT ) ) {
			$product = Product::find( get_queried_object_id() );
			if ( $product ) {
				$graph[] = self::product( $product );
				$faq     = self::faq( $product );
				if ( $faq ) {
					$graph[] = $faq;
				}
			}
		} elseif ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			global $wp_query;
			$term = get_queried_object();
			$link = $term instanceof \WP_Term ? get_term_link( $term ) : '';
			$list = self::item_list( (array) $wp_query->posts, single_term_title( '', false ), is_string( $link ) ? $link : '' );
			if ( $list ) {
				$graph[] = $list;
			}
		} elseif ( Pages::id( 'shop' ) && Pages::is( 'shop' ) ) {
			$ids  = get_posts(
				array(
					'post_type'      => PostTypes::PRODUCT,
					'post_status'    => 'publish',
					'posts_per_page' => max( 1, Settings::int( 'products_per_page' ) ),
					'orderby'        => array(
						'menu_order' => 'ASC',
						'date'       => 'DESC',
					),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$list = self::item_list( $ids, wp_strip_all_tags( get_the_title() ), (string) get_permalink() );
			if ( $list ) {
				$graph[] = $list;
			}
		}

		if ( ! Compat::seo_plugin_active() ) {
			$crumbs = self::breadcrumb_list();
			if ( $crumbs ) {
				$graph[] = $crumbs;
			}
			if ( is_front_page() ) {
				$graph[] = self::store();
			}
		}

		/**
		 * Filters the JSON-LD graph of the current page.
		 */
		return array_values( array_filter( (array) apply_filters( 'wahou_schema_graph', $graph ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function product( Product $product ): array {
		$url    = $product->permalink();
		$images = array_values( array_filter( array_map( static fn( $id ) => wp_get_attachment_image_url( $id, 'large' ), $product->all_image_ids() ) ) );

		$data = array(
			'@type' => 'Product',
			'@id'   => $url . '#product',
			'name'  => wp_strip_all_tags( $product->name() ),
			'url'   => $url,
			'brand' => array(
				'@type' => 'Brand',
				'name'  => $product->brand(),
			),
		);
		$description = $product->summary( 60 );
		if ( '' !== $description ) {
			$data['description'] = $description;
		}
		if ( $images ) {
			$data['image'] = $images;
		}
		if ( '' !== $product->sku() ) {
			$data['sku'] = $product->sku();
		}
		if ( '' !== $product->gtin() ) {
			$data[ self::gtin_property( $product->gtin() ) ] = $product->gtin();
		}
		if ( '' !== $product->mpn() ) {
			$data['mpn'] = $product->mpn();
		}
		$categories = $product->categories();
		if ( $categories ) {
			$data['category'] = wp_strip_all_tags( $categories[0]->name );
		}
		if ( $product->weight() > 0 ) {
			$data['weight'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => $product->weight(),
				'unitCode' => 'KGM',
			);
		}

		$count   = $product->rating_count();
		$average = $product->rating_average();
		if ( $count > 0 && $average > 0 ) {
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $average,
				'reviewCount' => $count,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
			$reviews = self::reviews( $product );
			if ( $reviews ) {
				$data['review'] = $reviews;
			}
		}

		$variants = 'variable' === $product->type() ? $product->variants() : array();
		if ( $variants ) {
			$data['@type']          = 'ProductGroup';
			$data['productGroupID'] = '' !== $product->sku() ? $product->sku() : (string) $product->id();
			$varies                 = array();
			foreach ( $product->attributes() as $attribute ) {
				$property = Seo::attribute_property( (string) ( $attribute['name'] ?? '' ) );
				if ( '' !== $property ) {
					$varies[] = 'https://schema.org/' . $property;
				}
			}
			if ( $varies ) {
				$data['variesBy'] = array_values( array_unique( $varies ) );
			}
			$data['hasVariant'] = array();
			foreach ( $variants as $variant ) {
				$vid   = (string) $variant['id'];
				$label = $product->variant_label( $vid );
				$item  = array(
					'@type'  => 'Product',
					'name'   => wp_strip_all_tags( $product->name() ) . ( '' !== $label ? ' – ' . $label : '' ),
					'url'    => add_query_arg( 'variant', rawurlencode( $vid ), $url ),
					'offers' => self::offer( $product, $vid, add_query_arg( 'variant', rawurlencode( $vid ), $url ) ),
				);
				if ( '' !== $product->sku( $vid ) ) {
					$item['sku'] = $product->sku( $vid );
				}
				$image = $product->image_id( $vid );
				if ( $image ) {
					$src = wp_get_attachment_image_url( $image, 'large' );
					if ( $src ) {
						$item['image'] = $src;
					}
				}
				foreach ( (array) ( $variant['attributes'] ?? array() ) as $name => $value ) {
					$property = Seo::attribute_property( (string) $name );
					if ( '' !== $property ) {
						$item[ $property ] = (string) $value;
					}
				}
				$data['hasVariant'][] = $item;
			}
		} else {
			$data['offers'] = self::offer( $product, null, $url );
		}

		/**
		 * Filters the Product (or ProductGroup) entity.
		 */
		return (array) apply_filters( 'wahou_schema_product', $data, $product );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function offer( Product $product, ?string $variant_id, string $url ): array {
		$currency = Settings::currency();
		$price    = Seo::gross_price( $product, $product->price( $variant_id ) );
		$offer    = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'priceCurrency' => $currency,
			'price'         => Money::to_decimal( $price, $currency ),
			'availability'  => self::availability( $product, $variant_id ),
			'itemCondition' => 'https://schema.org/NewCondition',
			'seller'        => array(
				'@type' => 'Organization',
				'name'  => Settings::store_name(),
				'url'   => home_url( '/' ),
			),
		);
		if ( $product->is_on_sale( $variant_id ) ) {
			$reference = $product->reference_price( $variant_id );
			if ( $reference ) {
				$offer['priceSpecification'] = array(
					array(
						'@type'         => 'UnitPriceSpecification',
						'priceType'     => 'https://schema.org/StrikethroughPrice',
						'price'         => Money::to_decimal( Seo::gross_price( $product, $reference ), $currency ),
						'priceCurrency' => $currency,
					),
				);
			}
			if ( $product->sale_ends_at() ) {
				$offer['priceValidUntil'] = wp_date( 'Y-m-d', $product->sale_ends_at() );
			}
		}
		$policy = self::return_policy( $product );
		if ( $policy ) {
			$offer['hasMerchantReturnPolicy'] = $policy;
		}
		if ( $product->needs_shipping() ) {
			$shipping = self::shipping_details( $product, $price );
			if ( $shipping ) {
				$offer['shippingDetails'] = $shipping;
			}
		}
		return $offer;
	}

	public static function availability( Product $product, ?string $variant_id = null ): string {
		switch ( $product->stock_status( $variant_id ) ) {
			case 'out_of_stock':
				return 'https://schema.org/OutOfStock';
			case 'backorder':
				return 'https://schema.org/BackOrder';
		}
		return 'https://schema.org/InStock';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function return_policy( Product $product ): ?array {
		if ( 'service' === $product->kind() ) {
			return null;
		}
		$countries = self::policy_countries();
		$days      = Settings::int( 'return_days' );
		$no_return = $days <= 0 || ( $product->is_digital() && Settings::bool( 'digital_waiver' ) );
		if ( $no_return ) {
			return array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => $countries,
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			);
		}
		return array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $countries,
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'   => $days,
			'returnMethod'         => 'https://schema.org/ReturnByMail',
			'returnFees'           => 'free' === Settings::str( 'return_fees' ) ? 'https://schema.org/FreeReturn' : 'https://schema.org/ReturnFeesCustomerResponsibility',
		);
	}

	/**
	 * Countries covered by the return policy (store country + shipping zones).
	 *
	 * @return string[]
	 */
	private static function policy_countries(): array {
		$countries = array_merge( array( Settings::store_country() ), Shipping::countries() );
		$countries = array_values( array_unique( array_filter( $countries, static fn( $c ) => 2 === strlen( (string) $c ) ) ) );
		return array_slice( $countries, 0, 40 );
	}

	/**
	 * One OfferShippingDetails per zone (cheapest available rate for this product).
	 *
	 * @return array<int, array<string, mixed>>|array<string, mixed>|null
	 */
	private static function shipping_details( Product $product, int $price_gross ): ?array {
		$currency = Settings::currency();
		$handling = max( 0, Settings::int( 'processing_days' ) );
		$add_tax  = Settings::bool( 'tax_enabled' ) && ! Settings::bool( 'tax_exempt' ) && ! Settings::bool( 'prices_include_tax' ) && Settings::bool( 'tax_shipping' );
		$out      = array();

		foreach ( Shipping::zones() as $zone ) {
			$countries = array_values( array_filter( $zone['countries'], static fn( $c ) => '*' !== $c ) );
			if ( ! $countries ) {
				continue;
			}
			$rate = null;
			$min  = 0;
			$max  = 0;
			foreach ( $zone['methods'] as $method ) {
				if ( ! $method['enabled'] || 'pickup' === $method['type'] ) {
					continue;
				}
				$cost = $method['cost'];
				if ( 'free' === $method['type'] ) {
					if ( $price_gross < $method['min_amount'] ) {
						continue;
					}
					$cost = 0;
				}
				if ( null === $rate || $cost < $rate ) {
					$rate = $cost;
					$min  = $method['min_days'];
					$max  = $method['max_days'];
				}
			}
			if ( null === $rate ) {
				continue;
			}
			if ( $add_tax && $rate > 0 ) {
				$rate += Tax::from_net( $rate, Tax::rate( Settings::store_country(), $product->tax_class() ) );
			}
			$detail = array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => Money::to_decimal( (int) $rate, $currency ),
					'currency' => $currency,
				),
				'shippingDestination' => array_map(
					static fn( $c ) => array(
						'@type'          => 'DefinedRegion',
						'addressCountry' => $c,
					),
					array_slice( $countries, 0, 30 )
				),
			);
			if ( $max > 0 ) {
				$detail['deliveryTime'] = array(
					'@type'        => 'ShippingDeliveryTime',
					'handlingTime' => array(
						'@type'    => 'QuantitativeValue',
						'minValue' => 0,
						'maxValue' => $handling,
						'unitCode' => 'DAY',
					),
					'transitTime'  => array(
						'@type'    => 'QuantitativeValue',
						'minValue' => min( $min, $max ),
						'maxValue' => $max,
						'unitCode' => 'DAY',
					),
				);
			}
			$out[] = $detail;
		}
		if ( ! $out ) {
			return null;
		}
		return 1 === count( $out ) ? $out[0] : $out;
	}

	/**
	 * Latest approved reviews with a rating.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function reviews( Product $product ): array {
		$comments = get_comments(
			array(
				'post_id'    => $product->id(),
				'status'     => 'approve',
				'number'     => 5,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'rating',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'wahou_rating',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$out = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
			if ( ! $rating ) {
				$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'wahou_rating', true );
			}
			if ( $rating < 1 ) {
				continue;
			}
			$out[] = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => '' !== trim( $comment->comment_author ) ? wp_strip_all_tags( $comment->comment_author ) : __( 'Customer', 'wahou-commerce' ),
				),
				'datePublished' => mysql2date( 'Y-m-d', $comment->comment_date_gmt, false ),
				'reviewBody'    => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 80, '…' ),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => min( 5, $rating ),
					'bestRating'  => 5,
					'worstRating' => 1,
				),
			);
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function faq( Product $product ): ?array {
		$faq = $product->faq();
		if ( ! $faq ) {
			return null;
		}
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $product->permalink() . '#faq',
			'mainEntity' => array_map(
				static fn( $row ) => array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( (string) $row['q'] ),
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => wp_kses( (string) $row['a'], array( 'a' => array( 'href' => array() ), 'strong' => array(), 'em' => array(), 'br' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) ),
					),
				),
				$faq
			),
		);
	}

	/**
	 * @param array<int, int|\WP_Post> $posts
	 * @return array<string, mixed>|null
	 */
	private static function item_list( array $posts, string $name, string $url ): ?array {
		$items    = array();
		$position = 1;
		foreach ( $posts as $post ) {
			$product = Product::find( $post );
			if ( ! $product ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'url'      => $product->permalink(),
				'name'     => wp_strip_all_tags( $product->name() ),
			);
		}
		if ( ! $items ) {
			return null;
		}
		return array(
			'@type'           => 'ItemList',
			'@id'             => $url . '#itemlist',
			'name'            => $name,
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function breadcrumb_list(): ?array {
		$items = Breadcrumbs::items();
		if ( count( $items ) < 2 ) {
			return null;
		}
		$list = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function store(): array {
		$store = array(
			'@type'   => 'OnlineStore',
			'@id'     => home_url( '/#organization' ),
			'name'    => Settings::store_name(),
			'url'     => home_url( '/' ),
			'address' => array(
				'@type'          => 'PostalAddress',
				'addressCountry' => Settings::store_country(),
			),
		);
		$legal = Settings::str( 'store_legal_name' );
		if ( '' !== $legal ) {
			$store['legalName'] = $legal;
		}
		$vat = Settings::str( 'store_vat_number' );
		if ( '' !== $vat ) {
			$store['vatID'] = $vat;
		}
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo_id = $logo_id ? $logo_id : (int) get_option( 'site_icon' );
		if ( $logo_id ) {
			$logo = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo ) {
				$store['logo'] = $logo;
			}
		}
		$days = Settings::int( 'return_days' );
		if ( $days > 0 ) {
			$store['hasMerchantReturnPolicy'] = array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => self::policy_countries(),
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => $days,
				'returnMethod'         => 'https://schema.org/ReturnByMail',
				'returnFees'           => 'free' === Settings::str( 'return_fees' ) ? 'https://schema.org/FreeReturn' : 'https://schema.org/ReturnFeesCustomerResponsibility',
			);
		}
		return $store;
	}

	private static function gtin_property( string $gtin ): string {
		$digits = preg_replace( '/\D/', '', $gtin ) ?? '';
		switch ( strlen( $digits ) ) {
			case 8:
				return 'gtin8';
			case 12:
				return 'gtin12';
			case 13:
				return 'gtin13';
			case 14:
				return 'gtin14';
		}
		return 'gtin';
	}
}
