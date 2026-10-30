<?php
/**
 * Storefront rendering helpers shared by templates, blocks and shortcodes.
 *
 * Every helper returns escaped HTML. Prices are displayed as entered by the
 * merchant (tax inclusive in B2C stores) followed by the tax suffix.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Install;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Render {

	/**
	 * Inline SVG icons (decorative, hidden from assistive technologies).
	 */
	public static function icon( string $name, int $size = 20 ): string {
		$paths = array(
			'cart'     => '<path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
			'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
			'plus'     => '<path d="M12 5v14M5 12h14"/>',
			'minus'    => '<path d="M5 12h14"/>',
			'check'    => '<path d="M5 12.5l4.2 4.2L19 7"/>',
			'truck'    => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17.5" cy="17.5" r="1.6"/>',
			'lock'     => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
			'return'   => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.6"/><path d="M4 4v4.6h4.6"/>',
			'download' => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M5 19h14"/>',
			'chat'     => '<path d="M5 5h14v10H9l-4 4z"/>',
			'trash'    => '<path d="M4 7h16M9 7V5h6v2M7 7l1 12h8l1-12"/>',
			'chevron'  => '<path d="M9 6l6 6-6 6"/>',
			'left'     => '<path d="M15 6l-6 6 6 6"/>',
			'zoom'     => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5M11 8v6M8 11h6"/>',
			'tag'      => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
			'clock'    => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
			'bolt'     => '<path d="M13 3L5 13h6l-1 8 8-10h-6z"/>',
			'gift'     => '<rect x="4" y="9" width="16" height="11" rx="1"/><path d="M12 9v11M4 13h16M12 9c-2-4-6-4-6-1s6 1 6 1zm0 0c2-4 6-4 6-1s-6 1-6 1z"/>',
			'image'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/>',
		);
		$path = $paths[ $name ] ?? $paths['check'];
		return '<svg class="wahou-icon wahou-icon--' . esc_attr( $name ) . '" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/* ------------------------------------------------------------------
	 * Prices.
	 * ------------------------------------------------------------------ */

	public static function discount_percent( int $reference, int $price ): int {
		if ( $reference <= 0 || $price >= $reference ) {
			return 0;
		}
		return (int) round( ( $reference - $price ) * 100 / $reference );
	}

	private static function suffix_html(): string {
		$suffix = Tax::price_suffix();
		return '' !== $suffix ? ' <span class="wahou-price__suffix">' . esc_html( $suffix ) . '</span>' : '';
	}

	/**
	 * Full price block of the product page (sale, Omnibus reference, tax suffix).
	 */
	public static function price( Product $product, ?string $variant_id = null ): string {
		$currency = Settings::currency();

		if ( null === $variant_id && 'variable' === $product->type() ) {
			list( $min, $max ) = $product->price_range();
			$amount = '<span class="wahou-price__amount">' . esc_html( Money::format( $min, $currency ) ) . '</span>';
			$html   = '<div class="wahou-price-block" data-amount="' . (int) $min . '">';
			$html  .= '<span class="wahou-price wahou-price--current">';
			/* translators: %s: lowest price. */
			$html .= $min !== $max ? sprintf( esc_html__( 'From %s', 'wahou-commerce' ), $amount ) : $amount;
			$html .= '</span>' . self::suffix_html() . '</div>';
			return $html;
		}

		$price     = $product->price( $variant_id );
		$reference = $product->reference_price( $variant_id );
		$percent   = null !== $reference ? self::discount_percent( $reference, $price ) : 0;

		$html  = '<div class="wahou-price-block" data-amount="' . (int) $price . '">';
		$html .= '<span class="wahou-price wahou-price--current' . ( $percent ? ' wahou-price--sale' : '' ) . '"><span class="wahou-price__amount">' . esc_html( Money::format( $price, $currency ) ) . '</span></span>';
		if ( $percent ) {
			$html .= ' <del class="wahou-price__reference"><span class="screen-reader-text">' . esc_html__( 'Reference price:', 'wahou-commerce' ) . ' </span>' . esc_html( Money::format( (int) $reference, $currency ) ) . '</del>';
			/* translators: %d: discount percentage. */
			$html .= ' <span class="wahou-badge wahou-badge--sale">' . esc_html( sprintf( __( '-%d%%', 'wahou-commerce' ), $percent ) ) . '</span>';
		}
		$html .= self::suffix_html();
		if ( $percent && Settings::bool( 'omnibus_display' ) ) {
			$html .= '<p class="wahou-price__omnibus">' . esc_html(
				sprintf(
					/* translators: %s: lowest price over the 30 days before the price reduction. */
					__( 'Lowest price in the 30 days before the discount: %s', 'wahou-commerce' ),
					Money::format( (int) $reference, $currency )
				)
			) . '</p>';
		}
		$ends = $product->sale_ends_at();
		if ( $percent && $ends > time() ) {
			$html .= '<p class="wahou-price__ends">' . self::icon( 'clock', 16 ) . ' ' . esc_html(
				sprintf(
					/* translators: %s: date the sale ends. */
					__( 'Offer ends on %s', 'wahou-commerce' ),
					wp_date( (string) get_option( 'date_format' ), $ends )
				)
			) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Compact price for product cards.
	 */
	public static function card_price( Product $product ): string {
		$currency = Settings::currency();
		if ( 'variable' === $product->type() ) {
			list( $min, $max ) = $product->price_range();
			$amount = '<span class="wahou-price__amount">' . esc_html( Money::format( $min, $currency ) ) . '</span>';
			/* translators: %s: lowest price. */
			return '<span class="wahou-price">' . ( $min !== $max ? sprintf( esc_html__( 'From %s', 'wahou-commerce' ), $amount ) : $amount ) . '</span>';
		}
		$price     = $product->price();
		$reference = $product->reference_price();
		$html      = '<span class="wahou-price' . ( $reference ? ' wahou-price--sale' : '' ) . '"><span class="wahou-price__amount">' . esc_html( Money::format( $price, $currency ) ) . '</span></span>';
		if ( $reference && $reference > $price ) {
			$html .= ' <del class="wahou-price__reference"><span class="screen-reader-text">' . esc_html__( 'Reference price:', 'wahou-commerce' ) . ' </span>' . esc_html( Money::format( $reference, $currency ) ) . '</del>';
		}
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Ratings, badges, stock.
	 * ------------------------------------------------------------------ */

	public static function stars( float $average, int $count = 0, bool $show_count = true, string $link = '' ): string {
		$percent = max( 0, min( 100, $average / 5 * 100 ) );
		/* translators: %s: average rating, e.g. 4.5. */
		$label = sprintf( __( 'Rated %s out of 5', 'wahou-commerce' ), number_format_i18n( $average, 1 ) );
		$html  = '<span class="wahou-stars" role="img" aria-label="' . esc_attr( $label ) . '"><span class="wahou-stars__fill" style="width:' . esc_attr( (string) round( $percent, 1 ) ) . '%"></span></span>';
		if ( $show_count && $count > 0 ) {
			/* translators: %s: number of reviews. */
			$text  = sprintf( _n( '%s review', '%s reviews', $count, 'wahou-commerce' ), number_format_i18n( $count ) );
			$html .= '' !== $link
				? ' <a class="wahou-rating__count" href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>'
				: ' <span class="wahou-rating__count">' . esc_html( $text ) . '</span>';
		}
		return '<span class="wahou-rating">' . $html . '</span>';
	}

	public static function badges( Product $product ): string {
		$badges = array();
		$custom = $product->badge();
		if ( '' !== $custom ) {
			$badges[] = '<span class="wahou-badge wahou-badge--custom">' . esc_html( $custom ) . '</span>';
		}
		if ( ! $product->in_stock() ) {
			$badges[] = '<span class="wahou-badge wahou-badge--muted">' . esc_html__( 'Sold out', 'wahou-commerce' ) . '</span>';
		} elseif ( 'variable' !== $product->type() ) {
			$reference = $product->reference_price();
			$percent   = null !== $reference ? self::discount_percent( $reference, $product->price() ) : 0;
			if ( $percent ) {
				/* translators: %d: discount percentage. */
				$badges[] = '<span class="wahou-badge wahou-badge--sale">' . esc_html( sprintf( __( '-%d%%', 'wahou-commerce' ), $percent ) ) . '</span>';
			}
		} else {
			foreach ( $product->variants() as $variant ) {
				if ( $product->is_on_sale( (string) $variant['id'] ) ) {
					$badges[] = '<span class="wahou-badge wahou-badge--sale">' . esc_html__( 'Sale', 'wahou-commerce' ) . '</span>';
					break;
				}
			}
		}
		return $badges ? '<span class="wahou-badges">' . implode( '', $badges ) . '</span>' : '';
	}

	/**
	 * Stock line (real stock only – no artificial urgency).
	 */
	public static function stock( Product $product, ?string $variant_id = null ): string {
		$status = $product->stock_status( $variant_id );
		switch ( $status ) {
			case 'out_of_stock':
				$text = __( 'Out of stock', 'wahou-commerce' );
				break;
			case 'backorder':
				$text = __( 'Available on backorder', 'wahou-commerce' );
				break;
			case 'low_stock':
				$stock = (int) $product->stock( $variant_id );
				/* translators: %d: units left. */
				$text = Settings::bool( 'show_stock' ) ? sprintf( _n( 'Only %d left in stock', 'Only %d left in stock', $stock, 'wahou-commerce' ), $stock ) : __( 'In stock', 'wahou-commerce' );
				break;
			default:
				$text = $product->is_digital() ? __( 'Available for instant download', 'wahou-commerce' ) : __( 'In stock', 'wahou-commerce' );
		}
		return '<p class="wahou-stock wahou-stock--' . esc_attr( str_replace( '_', '-', $status ) ) . '"><span class="wahou-stock__dot" aria-hidden="true"></span>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Quantity stepper.
	 */
	public static function quantity( string $name, int $value = 1, int $min = 1, ?int $max = null, string $id = '', string $label = '' ): string {
		$id    = '' !== $id ? $id : 'wahou-qty-' . wp_unique_id();
		$label = '' !== $label ? $label : __( 'Quantity', 'wahou-commerce' );
		$html  = '<div class="wahou-qty" data-wahou-qty>';
		$html .= '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<button type="button" class="wahou-qty__btn" data-wahou-qty-step="-1" aria-label="' . esc_attr__( 'Decrease quantity', 'wahou-commerce' ) . '">' . self::icon( 'minus', 18 ) . '</button>';
		$html .= '<input class="wahou-qty__input" type="number" inputmode="numeric" pattern="[0-9]*" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . (int) $value . '" min="' . (int) $min . '"' . ( null !== $max ? ' max="' . (int) $max . '"' : '' ) . ' step="1">';
		$html .= '<button type="button" class="wahou-qty__btn" data-wahou-qty-step="1" aria-label="' . esc_attr__( 'Increase quantity', 'wahou-commerce' ) . '">' . self::icon( 'plus', 18 ) . '</button>';
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Images.
	 * ------------------------------------------------------------------ */

	public static function placeholder( string $class = '' ): string {
		return '<span class="wahou-placeholder ' . esc_attr( $class ) . '" role="img" aria-label="' . esc_attr__( 'No image', 'wahou-commerce' ) . '">' . self::icon( 'image', 40 ) . '</span>';
	}

	/**
	 * Attachment image with sensible loading attributes and alt fallback.
	 *
	 * @param array<string, string|bool> $attr
	 */
	public static function image( int $attachment_id, string $size, array $attr = array(), string $alt_fallback = '' ): string {
		if ( ! $attachment_id ) {
			return self::placeholder();
		}
		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === trim( $alt ) && '' !== $alt_fallback ) {
			$attr['alt'] = $alt_fallback;
		}
		$attr = array_merge(
			array(
				'loading'  => 'lazy',
				'decoding' => 'async',
			),
			$attr
		);
		$html = wp_get_attachment_image( $attachment_id, $size, false, $attr );
		return $html ? $html : self::placeholder();
	}

	/* ------------------------------------------------------------------
	 * Catalog queries.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, string>
	 */
	public static function orderby_options(): array {
		return array(
			'menu_order' => __( 'Featured', 'wahou-commerce' ),
			'popularity' => __( 'Best sellers', 'wahou-commerce' ),
			'rating'     => __( 'Top rated', 'wahou-commerce' ),
			'date'       => __( 'Newest', 'wahou-commerce' ),
			'price'      => __( 'Price: low to high', 'wahou-commerce' ),
			'price-desc' => __( 'Price: high to low', 'wahou-commerce' ),
		);
	}

	public static function current_orderby( string $fallback = 'menu_order' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		return array_key_exists( $value, self::orderby_options() ) ? $value : $fallback;
	}

	public static function current_page(): int {
		return max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	}

	/**
	 * WP_Query arguments for a sort option.
	 *
	 * @return array<string, mixed>
	 */
	public static function orderby_args( string $orderby ): array {
		$meta_sort = static function ( string $key, string $dir ): array {
			return array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation'   => 'OR',
					'wahou_sort' => array(
						'key'     => $key,
						'type'    => 'NUMERIC',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'    => array(
					'wahou_sort' => $dir,
					'date'       => 'DESC',
				),
			);
		};
		switch ( $orderby ) {
			case 'date':
				return array(
					'orderby' => 'date',
					'order'   => 'DESC',
				);
			case 'title':
				return array(
					'orderby' => 'title',
					'order'   => 'ASC',
				);
			case 'price':
				return $meta_sort( '_wahou_price', 'ASC' );
			case 'price-desc':
				return $meta_sort( '_wahou_price', 'DESC' );
			case 'popularity':
				return $meta_sort( '_wahou_total_sales', 'DESC' );
			case 'rating':
				return $meta_sort( '_wahou_rating_avg', 'DESC' );
		}
		return array(
			'orderby' => array(
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
		);
	}

	/**
	 * Product query for grids (blocks, shortcodes, related products).
	 *
	 * @param array<string, mixed> $args ids, category, tag, exclude, limit, orderby, paged, count.
	 */
	public static function query( array $args ): \WP_Query {
		$limit = max( 1, min( 100, (int) ( $args['limit'] ?? 12 ) ) );
		$query = array(
			'post_type'           => PostTypes::PRODUCT,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'paged'               => max( 1, (int) ( $args['paged'] ?? 1 ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => empty( $args['count'] ),
		);

		$ids = array_filter( array_map( 'absint', is_array( $args['ids'] ?? null ) ? $args['ids'] : explode( ',', (string) ( $args['ids'] ?? '' ) ) ) );
		if ( $ids ) {
			$query['post__in'] = $ids;
		}
		if ( ! empty( $args['exclude'] ) ) {
			$query['post__not_in'] = array_map( 'absint', (array) $args['exclude'] );
		}

		$tax_query = array();
		foreach ( array(
			'category' => PostTypes::CATEGORY,
			'tag'      => PostTypes::TAG,
		) as $key => $taxonomy ) {
			$terms = array_filter( array_map( 'sanitize_title', explode( ',', (string) ( $args[ $key ] ?? '' ) ) ) );
			if ( $terms ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $terms,
				);
			}
		}
		if ( ! empty( $args['term_ids'] ) ) {
			$tax_query[] = array(
				'taxonomy' => PostTypes::CATEGORY,
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', (array) $args['term_ids'] ),
			);
		}
		if ( $tax_query ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$orderby = (string) ( $args['orderby'] ?? '' );
		if ( $ids && ( '' === $orderby || 'post__in' === $orderby ) ) {
			$query['orderby'] = 'post__in';
		} else {
			$query = array_merge( $query, self::orderby_args( '' !== $orderby ? $orderby : 'menu_order' ) );
		}

		/**
		 * Filters product grid query arguments.
		 */
		return new \WP_Query( apply_filters( 'wahou_products_query_args', $query, $args ) );
	}

	/**
	 * Products from post objects or IDs (skips anything that is not a product).
	 *
	 * @param array<int, int|\WP_Post> $posts
	 * @return Product[]
	 */
	public static function products( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$product = Product::find( $post );
			if ( $product && 'publish' === $product->status() ) {
				$out[] = $product;
			}
		}
		return $out;
	}

	/**
	 * Products section used by the products block and shortcode.
	 *
	 * @param array<string, mixed> $atts
	 */
	public static function products_section( array $atts ): string {
		$atts = wp_parse_args(
			$atts,
			array(
				'ids'        => '',
				'category'   => '',
				'tag'        => '',
				'limit'      => Settings::int( 'products_per_page' ) ?: 12,
				'columns'    => Settings::int( 'catalog_columns' ) ?: 3,
				'orderby'    => '',
				'pagination' => false,
				'filters'    => false,
				'title'      => '',
				'class'      => '',
			)
		);
		$pagination = filter_var( $atts['pagination'], FILTER_VALIDATE_BOOLEAN );
		$filters    = filter_var( $atts['filters'], FILTER_VALIDATE_BOOLEAN );
		$orderby    = $filters ? self::current_orderby( (string) ( $atts['orderby'] ?: 'menu_order' ) ) : (string) $atts['orderby'];
		$paged      = $pagination ? self::current_page() : 1;

		$query = self::query(
			array(
				'ids'      => $atts['ids'],
				'category' => $atts['category'],
				'tag'      => $atts['tag'],
				'limit'    => (int) $atts['limit'],
				'orderby'  => $orderby,
				'paged'    => $paged,
				'count'    => $pagination || $filters,
			)
		);

		return Template::get(
			'parts/products-section',
			array(
				'query'      => $query,
				'products'   => self::products( $query->posts ),
				'columns'    => max( 1, min( 6, (int) $atts['columns'] ) ),
				'pagination' => $pagination,
				'filters'    => $filters,
				'orderby'    => '' !== $orderby ? $orderby : 'menu_order',
				'paged'      => $paged,
				'title'      => (string) $atts['title'],
				'class'      => (string) $atts['class'],
			)
		);
	}

	/**
	 * Grid of product cards.
	 *
	 * @param Product[]            $products
	 * @param array<string, mixed> $args columns, heading (h2|h3), list (analytics list name).
	 */
	public static function grid( array $products, array $args = array() ): string {
		return Template::get(
			'parts/product-grid',
			array(
				'products' => $products,
				'columns'  => max( 1, min( 6, (int) ( $args['columns'] ?? ( Settings::int( 'catalog_columns' ) ?: 3 ) ) ) ),
				'heading'  => in_array( $args['heading'] ?? 'h3', array( 'h2', 'h3', 'h4' ), true ) ? $args['heading'] : 'h3',
				'list'     => (string) ( $args['list'] ?? '' ),
			)
		);
	}

	/**
	 * Numbered pagination (crawlable links).
	 */
	public static function pagination( int $total_pages, int $current, bool $main_query = false ): string {
		if ( $total_pages < 2 ) {
			return '';
		}
		$args = array(
			'type'      => 'array',
			'current'   => max( 1, $current ),
			'total'     => $total_pages,
			'mid_size'  => 1,
			'prev_text' => self::icon( 'left', 18 ) . '<span class="screen-reader-text">' . esc_html__( 'Previous page', 'wahou-commerce' ) . '</span>',
			'next_text' => self::icon( 'chevron', 18 ) . '<span class="screen-reader-text">' . esc_html__( 'Next page', 'wahou-commerce' ) . '</span>',
		);
		if ( ! $main_query ) {
			$big            = 999999999;
			$args['base']   = str_replace( (string) $big, '%#%', esc_url( get_pagenum_link( $big ) ) );
			$args['format'] = '';
		}
		$links = paginate_links( $args );
		if ( empty( $links ) || ! is_array( $links ) ) {
			return '';
		}
		$html = '<nav class="wahou-pagination" aria-label="' . esc_attr__( 'Products pagination', 'wahou-commerce' ) . '"><ul>';
		foreach ( $links as $link ) {
			$html .= '<li>' . $link . '</li>';
		}
		return $html . '</ul></nav>';
	}

	/**
	 * Category chips (top-level categories).
	 *
	 * @return \WP_Term[]
	 */
	public static function category_chips(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => true,
				'parent'     => 0,
				'number'     => 24,
				'orderby'    => 'name',
			)
		);
		return is_array( $terms ) ? $terms : array();
	}

	/* ------------------------------------------------------------------
	 * Product page helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Variant requested in the URL (?variant=), if valid.
	 */
	public static function requested_variant( Product $product ): ?string {
		$variants = $product->variants();
		if ( ! $variants ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '';
		if ( '' !== $requested && $product->variant( $requested ) ) {
			return $requested;
		}
		return 1 === count( $variants ) ? (string) $variants[0]['id'] : null;
	}

	/**
	 * Breadcrumb trail for the current request.
	 *
	 * @return array<int, array{name:string, url:string}>
	 */
	public static function breadcrumbs(): array {
		if ( class_exists( '\WahouCommerce\Seo\Breadcrumbs' ) && method_exists( '\WahouCommerce\Seo\Breadcrumbs', 'items' ) ) {
			$items = \WahouCommerce\Seo\Breadcrumbs::items();
			if ( is_array( $items ) && $items ) {
				return $items;
			}
		}
		$items = array(
			array(
				'name' => __( 'Home', 'wahou-commerce' ),
				'url'  => home_url( '/' ),
			),
		);
		$shop = Pages::id( 'shop' );
		if ( $shop ) {
			$items[] = array(
				'name' => get_the_title( $shop ),
				'url'  => (string) get_permalink( $shop ),
			);
		}
		$term = null;
		if ( is_singular( PostTypes::PRODUCT ) ) {
			$terms = get_the_terms( get_queried_object_id(), PostTypes::CATEGORY );
			$term  = is_array( $terms ) && $terms ? $terms[0] : null;
		} elseif ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			$term = get_queried_object();
		}
		if ( $term instanceof \WP_Term ) {
			$chain = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
			foreach ( $chain as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );
				if ( $ancestor instanceof \WP_Term ) {
					$items[] = array(
						'name' => $ancestor->name,
						'url'  => (string) get_term_link( $ancestor ),
					);
				}
			}
			$items[] = array(
				'name' => $term->name,
				'url'  => (string) get_term_link( $term ),
			);
		}
		if ( is_singular( PostTypes::PRODUCT ) ) {
			$items[] = array(
				'name' => get_the_title( get_queried_object_id() ),
				'url'  => (string) get_permalink( get_queried_object_id() ),
			);
		}
		return $items;
	}

	/**
	 * Related products: same category, best sellers first.
	 *
	 * @return Product[]
	 */
	public static function related( Product $product, int $limit = 4, array $exclude = array() ): array {
		$term_ids = array_map( static fn( $t ) => (int) $t->term_id, $product->categories() );
		if ( ! $term_ids ) {
			return array();
		}
		$query = self::query(
			array(
				'term_ids' => $term_ids,
				'exclude'  => array_merge( array( $product->id() ), $exclude ),
				'limit'    => $limit,
				'orderby'  => 'popularity',
			)
		);
		return self::products( $query->posts );
	}

	/**
	 * Purchasable, in-stock simple products for "frequently bought together".
	 *
	 * @return Product[]
	 */
	public static function fbt_items( Product $product, int $limit = 3 ): array {
		$out = array();
		foreach ( $product->fbt_ids() as $id ) {
			$item = Product::find( $id );
			if ( $item && 'publish' === $item->status() && $item->is_purchasable() && 'variable' !== $item->type() && $item->in_stock() ) {
				$out[] = $item;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Cross-sell suggestions for the cart drawer (not already in the cart).
	 *
	 * @return Product[]
	 */
	public static function cross_sells( ?Cart $cart, int $limit = 3 ): array {
		if ( ! $cart || $cart->is_empty() ) {
			return array();
		}
		$in_cart = array_map( static fn( $i ) => (int) $i['product_id'], $cart->items() );
		$out     = array();
		foreach ( $in_cart as $product_id ) {
			$product = Product::find( $product_id );
			if ( ! $product ) {
				continue;
			}
			foreach ( $product->cross_sell_ids() as $id ) {
				if ( in_array( $id, $in_cart, true ) || isset( $out[ $id ] ) ) {
					continue;
				}
				$item = Product::find( $id );
				if ( $item && 'publish' === $item->status() && $item->is_purchasable() && 'variable' !== $item->type() && $item->in_stock() ) {
					$out[ $id ] = $item;
				}
				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * File formats of a digital product, e.g. [ 'PDF', 'EPUB' ].
	 *
	 * @return string[]
	 */
	public static function file_formats( Product $product ): array {
		$formats = array();
		foreach ( $product->files() as $file ) {
			$name = (string) ( $file['name'] ?? ( $file['path'] ?? ( $file['url'] ?? '' ) ) );
			$ext  = strtoupper( (string) pathinfo( wp_parse_url( $name, PHP_URL_PATH ) ?: $name, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && strlen( $ext ) <= 5 ) {
				$formats[ $ext ] = $ext;
			}
		}
		return array_values( $formats );
	}

	/**
	 * Delivery estimate: static text (crawlers, no JS) + data for live dates.
	 *
	 * @return array{text:string, data:array<string, mixed>}|null
	 */
	public static function delivery( Product $product ): ?array {
		if ( ! Settings::bool( 'delivery_estimate' ) ) {
			return null;
		}
		if ( ! $product->needs_shipping() ) {
			return array(
				'text' => __( 'Instant delivery by email right after payment.', 'wahou-commerce' ),
				'data' => array(),
			);
		}
		$transit = Shipping::transit_days( Settings::store_country() );
		if ( ! $transit ) {
			return null;
		}
		$processing = max( 1, Settings::int( 'processing_days' ) );
		if ( $transit['min'] === $transit['max'] ) {
			/* translators: %d: number of business days. */
			$delivery = sprintf( _n( 'delivered in %d business day', 'delivered in %d business days', $transit['max'], 'wahou-commerce' ), $transit['max'] );
		} else {
			/* translators: 1: minimum business days, 2: maximum business days. */
			$delivery = sprintf( __( 'delivered in %1$d–%2$d business days', 'wahou-commerce' ), $transit['min'], $transit['max'] );
		}
		/* translators: 1: number of business days before dispatch, 2: delivery delay text. */
		$text = sprintf( _n( 'Dispatched within %1$d business day, %2$s.', 'Dispatched within %1$d business days, %2$s.', $processing, 'wahou-commerce' ), $processing, $delivery );

		$cutoff = preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', Settings::str( 'shipping_cutoff' ) ) ? Settings::str( 'shipping_cutoff' ) : '14:00';
		$days   = array_values( array_unique( array_filter( array_map( 'intval', (array) Settings::get( 'business_days', array( 1, 2, 3, 4, 5 ) ) ), static fn( $d ) => $d >= 0 && $d <= 7 ) ) );

		return array(
			'text' => $text,
			'data' => array(
				'tz'         => wp_timezone_string(),
				'cutoff'     => $cutoff,
				'processing' => $processing,
				'days'       => $days ? $days : array( 1, 2, 3, 4, 5 ),
				'min'        => (int) $transit['min'],
				'max'        => (int) $transit['max'],
				'i18n'       => array(
					/* translators: %s: remaining time, e.g. "2 h 15 min". */
					'within'   => __( 'Order within %s for dispatch today', 'wahou-commerce' ),
					'tomorrow' => __( 'Dispatched tomorrow', 'wahou-commerce' ),
					/* translators: %s: dispatch date. */
					'on'       => __( 'Dispatched on %s', 'wahou-commerce' ),
					/* translators: %s: delivery date or date range. */
					'delivery' => __( 'Estimated delivery: %s', 'wahou-commerce' ),
					/* translators: 1: hours, 2: minutes. */
					'hm'       => __( '%1$d h %2$d min', 'wahou-commerce' ),
					/* translators: %d: minutes. */
					'm'        => __( '%d min', 'wahou-commerce' ),
				),
			),
		);
	}

	/**
	 * GA4 item payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function analytics_item( Product $product, ?string $variant_id = null, int $quantity = 1 ): array {
		$categories = $product->categories();
		$item       = array(
			'item_id'   => '' !== $product->sku( $variant_id ) ? $product->sku( $variant_id ) : (string) $product->id(),
			'item_name' => $product->name(),
			'price'     => (float) Money::to_decimal( $product->price( $variant_id ) ),
			'quantity'  => $quantity,
		);
		if ( '' !== $product->brand() ) {
			$item['item_brand'] = $product->brand();
		}
		if ( $categories ) {
			$item['item_category'] = $categories[0]->name;
		}
		if ( $variant_id ) {
			$item['item_variant'] = $product->variant_label( $variant_id );
		}
		return $item;
	}

	/**
	 * Data consumed by the variant picker.
	 *
	 * @return array<string, mixed>
	 */
	public static function variants_data( Product $product ): array {
		$variants = array();
		foreach ( $product->variants() as $variant ) {
			$vid      = (string) $variant['id'];
			$image_id = ! empty( $variant['image_id'] ) ? (int) $variant['image_id'] : 0;
			$image    = null;
			if ( $image_id ) {
				$src   = wp_get_attachment_image_src( $image_id, 'large' );
				$full  = wp_get_attachment_image_src( $image_id, 'full' );
				$image = $src ? array(
					'id'     => $image_id,
					'src'    => $src[0],
					'srcset' => (string) wp_get_attachment_image_srcset( $image_id, 'large' ),
					'full'   => $full ? $full[0] : $src[0],
				) : null;
			}
			$max        = $product->max_qty( $vid );
			$variants[] = array(
				'id'         => $vid,
				'attributes' => is_array( $variant['attributes'] ?? null ) ? array_map( 'strval', $variant['attributes'] ) : array(),
				'price'      => $product->price( $vid ),
				'price_html' => self::price( $product, $vid ),
				'in_stock'   => $product->in_stock( $vid ),
				'stock_html' => self::stock( $product, $vid ),
				'sku'        => $product->sku( $vid ),
				'max'        => $max,
				'image'      => $image,
				'item'       => self::analytics_item( $product, $vid ),
			);
		}
		return array(
			'attributes' => $product->attributes(),
			'variants'   => $variants,
			'price_html' => self::price( $product ),
		);
	}

	/* ------------------------------------------------------------------
	 * Small standalone components (blocks & shortcodes).
	 * ------------------------------------------------------------------ */

	public static function cart_icon( array $args = array() ): string {
		$label      = (string) ( $args['label'] ?? '' );
		$show_label = ! empty( $args['show_label'] );
		$cart       = Cart::current();
		$count      = $cart ? $cart->count() : 0;
		$html       = '<a class="wahou-cart-icon" href="' . esc_url( Pages::url( 'cart' ) ) . '" data-wahou-cart-toggle data-wahou-cart-icon aria-label="' . esc_attr__( 'Open cart', 'wahou-commerce' ) . '">';
		$html      .= self::icon( 'cart', 24 );
		if ( $show_label ) {
			$html .= '<span class="wahou-cart-icon__label">' . esc_html( '' !== $label ? $label : __( 'Cart', 'wahou-commerce' ) ) . '</span>';
		}
		$html .= '<span class="wahou-cart-icon__count" data-wahou-count' . ( $count ? '' : ' hidden' ) . '>' . (int) $count . '</span>';
		return $html . '</a>';
	}

	/**
	 * Add to cart button for blocks/shortcodes (simple products), or a link
	 * to the product page when options must be chosen.
	 */
	public static function add_to_cart_button( Product $product, string $label = '', bool $show_price = false ): string {
		if ( ! $product->is_purchasable() ) {
			return '';
		}
		$price = $show_price ? ' <span class="wahou-btn__price">' . esc_html( Money::format( 'variable' === $product->type() ? $product->price_range()[0] : $product->price() ) ) . '</span>' : '';
		if ( 'variable' === $product->type() ) {
			return '<a class="wahou-btn wahou-btn--primary" href="' . esc_url( $product->permalink() ) . '">' . esc_html( '' !== $label ? $label : __( 'Choose options', 'wahou-commerce' ) ) . $price . '</a>';
		}
		if ( ! $product->in_stock() ) {
			return '<button type="button" class="wahou-btn wahou-btn--primary" disabled>' . esc_html__( 'Out of stock', 'wahou-commerce' ) . '</button>';
		}
		$html  = '<form class="wahou-atc-mini" method="post" action="' . esc_url( Pages::url( 'cart' ) ) . '" data-wahou-atc>';
		$html .= self::form_fields( $product );
		$html .= '<input type="hidden" name="qty" value="1">';
		$html .= '<button type="submit" class="wahou-btn wahou-btn--primary" data-wahou-atc-button>' . esc_html( '' !== $label ? $label : __( 'Add to cart', 'wahou-commerce' ) ) . $price . '</button>';
		return $html . '</form>';
	}

	/**
	 * Direct checkout link (works without JavaScript, shareable anywhere).
	 */
	public static function buy_button( Product $product, string $label = '' ): string {
		if ( ! $product->is_purchasable() ) {
			return '';
		}
		if ( 'variable' === $product->type() ) {
			return self::add_to_cart_button( $product, $label );
		}
		$price = Money::format( $product->price() );
		$text  = '' !== $label ? $label : __( 'Buy now', 'wahou-commerce' );
		return '<a class="wahou-btn wahou-btn--primary wahou-btn--buy" rel="nofollow" href="' . esc_url( wahou_buy_url( $product->id() ) ) . '">' . esc_html( $text ) . ' <span class="wahou-btn__price">' . esc_html( $price ) . '</span></a>';
	}

	/**
	 * Hidden fields shared by add-to-cart forms (no-JS fallback included).
	 */
	public static function form_fields( Product $product, string $variant_id = '' ): string {
		return '<input type="hidden" name="wahou_action" value="add_to_cart">'
			. '<input type="hidden" name="product_id" value="' . (int) $product->id() . '">'
			. '<input type="hidden" name="variant_id" value="' . esc_attr( $variant_id ) . '" data-wahou-variant-input>'
			. wp_nonce_field( 'wahou_add_to_cart', '_wahou_nonce', false, false );
	}

	/**
	 * Notice from a no-JS redirect (?wahou_notice=code).
	 */
	public static function query_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['wahou_notice'] ) ? sanitize_key( wp_unslash( $_GET['wahou_notice'] ) ) : '';
		$messages = array(
			'out_of_stock'    => __( 'Sorry, this product is out of stock.', 'wahou-commerce' ),
			'choose_variant'  => __( 'Please choose an option.', 'wahou-commerce' ),
			'not_purchasable' => __( 'This product cannot be purchased.', 'wahou-commerce' ),
			'error'           => __( 'Something went wrong. Please try again.', 'wahou-commerce' ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}
		return '<div class="wahou-notice wahou-notice--error" role="alert">' . esc_html( $messages[ $code ] ) . '</div>';
	}

	/**
	 * Is the email a verified buyer of the product (paid order)?
	 */
	public static function is_verified_buyer( string $email, int $product_id ): bool {
		global $wpdb;
		static $cache = array();
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! $product_id ) {
			return false;
		}
		$key = $email . '|' . $product_id;
		if ( ! isset( $cache[ $key ] ) ) {
			$orders = Install::table( 'orders' );
			$items  = Install::table( 'order_items' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders} o INNER JOIN {$items} i ON i.order_id = o.id WHERE o.email = %s AND o.status IN ('processing','completed') AND i.product_id = %d", $email, $product_id ) ) > 0;
		}
		return $cache[ $key ];
	}
}
