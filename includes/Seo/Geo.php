<?php
/**
 * GEO (Generative Engine Optimization): make the catalog easy to read and cite
 * for AI assistants and answer engines.
 *
 *  - /llms.txt        Store summary + product index (llmstxt.org format).
 *  - /llms-full.txt   Full catalog in Markdown.
 *  - /{product-base}/{slug}.md (or ?wahou_format=md)  Markdown version of a product,
 *    advertised with <link rel="alternate" type="text/markdown">.
 *  - robots.txt: transactional pages and private links are disallowed; AI
 *    crawlers can be blocked entirely or only for model training.
 *  - noindex on cart/checkout/account pages and private order URLs.
 *
 * Every document is server-rendered from the same data as the product page,
 * so what assistants read is what customers see.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Seo;

use WahouCommerce\Compat\Compat;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class Geo {

	/** Crawlers used to train AI models. */
	private const AI_TRAINING = array( 'GPTBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'ClaudeBot', 'anthropic-ai', 'Bytespider', 'Meta-ExternalAgent', 'cohere-training-data-crawler', 'Diffbot', 'omgili' );

	/** Crawlers and fetchers used by AI search and assistants. */
	private const AI_ANSWER = array( 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User', 'DuckAssistBot', 'MistralAI-User', 'Amazonbot', 'YouBot' );

	/** Query arguments of private, per-customer URLs. */
	private const PRIVATE_ARGS = array( 'wahou_order', 'wahou_offer', 'wahou_invoice', 'wahou_dl', 'wahou_login', 'wahou_recover', 'wahou_unsub', 'wahou_buy', 'wahou_add', 'wahou_coupon' );

	private const LIST_LIMIT = 200;
	private const FULL_LIMIT = 400;

	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'serve' ), 1 );
		add_action( 'wp_head', array( self::class, 'alternate_link' ), 3 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
		add_filter( 'wp_robots', array( self::class, 'wp_robots' ) );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		return array_merge( (array) $vars, array( 'wahou_geo', 'wahou_md', 'wahou_format', 'wahou_feed' ) );
	}

	/* ------------------------------------------------------------------
	 * Routing.
	 * ------------------------------------------------------------------ */

	public static function serve(): void {
		$geo = (string) get_query_var( 'wahou_geo' );
		if ( '' === $geo && 'md' === get_query_var( 'wahou_format' ) && is_singular( PostTypes::PRODUCT ) ) {
			$geo = 'md-current';
		}
		if ( '' === $geo ) {
			return;
		}

		switch ( $geo ) {
			case 'llms':
			case 'llms-full':
				if ( ! Settings::bool( 'seo_llms_txt' ) ) {
					self::not_found();
				}
				self::send( self::llms( 'llms-full' === $geo ), 'text/plain', home_url( '/' ) );
				break;
			case 'md':
			case 'md-current':
				if ( ! Settings::bool( 'seo_markdown' ) ) {
					self::not_found();
				}
				if ( 'md' === $geo ) {
					$slug = (string) get_query_var( 'wahou_md' );
					$post = get_page_by_path( $slug, OBJECT, PostTypes::PRODUCT );
					if ( ! $post ) {
						$post = get_page_by_path( sanitize_title( $slug ), OBJECT, PostTypes::PRODUCT );
					}
				} else {
					$post = get_queried_object();
				}
				$product = ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password ) ? Product::find( $post ) : null;
				if ( ! $product ) {
					self::not_found();
				}
				self::send( self::product_markdown( $product ), 'text/markdown', $product->permalink() );
				break;
		}
	}

	private static function send( string $body, string $type, string $canonical = '' ): void {
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( '' !== $canonical ) {
			header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text / Markdown document.
		exit;
	}

	private static function not_found(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	public static function alternate_link(): void {
		if ( ! Settings::bool( 'seo_markdown' ) || ! is_singular( PostTypes::PRODUCT ) ) {
			return;
		}
		$product = Product::find( get_queried_object_id() );
		if ( ! $product || 'publish' !== $product->status() ) {
			return;
		}
		printf(
			'<link rel="alternate" type="text/markdown" href="%1$s" title="%2$s" />' . "\n",
			esc_url( self::markdown_url( $product ) ),
			esc_attr( wp_strip_all_tags( $product->name() ) . ' (Markdown)' )
		);
	}

	public static function markdown_url( Product $product ): string {
		$permalink = $product->permalink();
		if ( get_option( 'permalink_structure' ) && ! Compat::is_multilingual() ) {
			$expected = home_url( '/' . PostTypes::product_base() . '/' . $product->slug() );
			if ( untrailingslashit( $permalink ) === untrailingslashit( $expected ) ) {
				return untrailingslashit( $permalink ) . '.md';
			}
		}
		return add_query_arg( 'wahou_format', 'md', $permalink );
	}

	public static function llms_url( bool $full = false ): string {
		$file = $full ? 'llms-full.txt' : 'llms.txt';
		return get_option( 'permalink_structure' ) ? home_url( '/' . $file ) : add_query_arg( 'wahou_geo', $full ? 'llms-full' : 'llms', home_url( '/' ) );
	}

	/* ------------------------------------------------------------------
	 * llms.txt.
	 * ------------------------------------------------------------------ */

	public static function llms( bool $full = false ): string {
		$key    = 'wahou_llms_' . md5( ( $full ? 'full' : 'index' ) . '|' . determine_locale() . '|' . Seo::catalog_version() . '|' . home_url() );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$lines   = array();
		$lines[] = '# ' . Settings::store_name();
		$lines[] = '';
		$lines[] = '> ' . self::one_line( self::intro() );
		$lines[] = '';
		foreach ( self::store_facts() as $fact ) {
			$lines[] = '- ' . $fact;
		}
		$lines[] = '';

		$products = self::products( $full ? self::FULL_LIMIT : self::LIST_LIMIT );
		if ( $full ) {
			foreach ( $products as $product ) {
				$lines[] = '---';
				$lines[] = '';
				$lines[] = self::product_markdown( $product, 2 );
				$lines[] = '';
			}
		} else {
			if ( $products ) {
				$lines[] = '## ' . __( 'Products', 'wahou-commerce' );
				$lines[] = '';
				foreach ( $products as $product ) {
					$summary = $product->summary( 25 );
					$lines[] = sprintf(
						'- [%1$s](%2$s): %3$s%4$s',
						self::escape_md( wp_strip_all_tags( $product->name() ) ),
						self::markdown_url( $product ),
						'' !== $summary ? self::one_line( $summary ) . ' – ' : '',
						self::price_text( $product )
					);
				}
				$lines[] = '';
			}

			$terms = get_terms(
				array(
					'taxonomy'   => PostTypes::CATEGORY,
					'hide_empty' => true,
					'number'     => 100,
				)
			);
			if ( is_array( $terms ) && $terms ) {
				$lines[] = '## ' . __( 'Categories', 'wahou-commerce' );
				$lines[] = '';
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_string( $link ) ) {
						continue;
					}
					$description = Seo::plain_text( (string) $term->description, 20 );
					$lines[]     = sprintf( '- [%1$s](%2$s)%3$s', self::escape_md( $term->name ), $link, '' !== $description ? ': ' . self::one_line( $description ) : '' );
				}
				$lines[] = '';
			}
		}

		$policies = array();
		foreach ( array( 'shop', 'terms', 'privacy' ) as $page ) {
			$id = Pages::id( $page );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$policies[] = sprintf( '- [%1$s](%2$s)', self::escape_md( wp_strip_all_tags( get_the_title( $id ) ) ), get_permalink( $id ) );
			}
		}
		if ( $policies ) {
			$lines[] = '## ' . __( 'Store pages and policies', 'wahou-commerce' );
			$lines[] = '';
			$lines   = array_merge( $lines, $policies );
			$lines[] = '';
		}

		if ( ! $full ) {
			$lines[] = '## Optional';
			$lines[] = '';
			$lines[] = sprintf( '- [%1$s](%2$s)', __( 'Full catalog in Markdown', 'wahou-commerce' ), self::llms_url( true ) );
			if ( Settings::bool( 'seo_feed' ) ) {
				$lines[] = sprintf( '- [%1$s](%2$s)', __( 'Product feed (Google Merchant Center format)', 'wahou-commerce' ), Feed::url() );
			}
			if ( Settings::bool( 'rest_public_products' ) ) {
				$lines[] = sprintf( '- [%1$s](%2$s)', __( 'Product API (JSON)', 'wahou-commerce' ), rest_url( 'wahou/v1/products' ) );
			}
			$lines[] = '';
		}

		$body = trim( implode( "\n", $lines ) ) . "\n";
		/**
		 * Filters the llms.txt / llms-full.txt document.
		 */
		$body = (string) apply_filters( 'wahou_llms_txt', $body, $full );
		set_transient( $key, $body, 12 * HOUR_IN_SECONDS );
		return $body;
	}

	private static function intro(): string {
		$intro = trim( Settings::str( 'seo_llms_intro' ) );
		if ( '' === $intro ) {
			$intro = trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		}
		if ( '' === $intro ) {
			/* translators: %s: store name. */
			$intro = sprintf( __( 'Official online store of %s.', 'wahou-commerce' ), Settings::store_name() );
		}
		return $intro;
	}

	/**
	 * Key facts about the store (currency, VAT, delivery, returns, payment, contact).
	 *
	 * @return string[]
	 */
	public static function store_facts(): array {
		$facts    = array();
		$currency = Settings::currency();
		/* translators: %s: currency code. */
		$facts[] = sprintf( __( 'Currency: %s', 'wahou-commerce' ), $currency );

		if ( Settings::bool( 'tax_enabled' ) && ! Settings::bool( 'tax_exempt' ) ) {
			$facts[] = Settings::bool( 'prices_include_tax' )
				? __( 'Prices include VAT for consumers in the European Union.', 'wahou-commerce' )
				: __( 'Prices exclude VAT; VAT is added at checkout when applicable.', 'wahou-commerce' );
		} elseif ( Settings::bool( 'tax_exempt' ) ) {
			$facts[] = Tax::invoice_mention( 'exempt' );
		}

		foreach ( self::delivery_lines() as $line ) {
			$facts[] = $line;
		}
		if ( self::has_kind( 'digital' ) ) {
			$facts[] = __( 'Digital products are delivered immediately after payment (download links by email and in the customer account).', 'wahou-commerce' );
		}

		$days = Settings::int( 'return_days' );
		if ( $days > 0 && self::has_kind( 'physical' ) ) {
			$facts[] = sprintf(
				/* translators: 1: number of days, 2: who pays return shipping. */
				__( 'Returns: %1$d days after delivery for physical products (%2$s).', 'wahou-commerce' ),
				$days,
				'free' === Settings::str( 'return_fees' ) ? __( 'free returns', 'wahou-commerce' ) : __( 'return shipping paid by the customer', 'wahou-commerce' )
			);
		}

		$methods = array();
		foreach ( Gateways::available() as $gateway ) {
			$methods[] = wp_strip_all_tags( $gateway->title() );
		}
		if ( $methods ) {
			/* translators: %s: list of payment methods. */
			$facts[] = sprintf( __( 'Payment methods: %s', 'wahou-commerce' ), implode( ', ', $methods ) );
		}

		$email = Settings::store_email();
		if ( is_email( $email ) ) {
			/* translators: %s: email address. */
			$facts[] = sprintf( __( 'Customer service: %s', 'wahou-commerce' ), $email );
		}
		$legal = Settings::str( 'store_legal_name' );
		/* translators: 1: company name, 2: country. */
		$facts[] = sprintf( __( 'Seller: %1$s (%2$s)', 'wahou-commerce' ), '' !== $legal ? $legal : Settings::store_name(), Countries::name( Settings::store_country() ) );
		return $facts;
	}

	/**
	 * One line per shipping zone: destination, delay, price and free threshold.
	 *
	 * @return string[]
	 */
	private static function delivery_lines(): array {
		$lines    = array();
		$currency = Settings::currency();
		foreach ( Shipping::zones() as $zone ) {
			$countries = array_values( array_filter( $zone['countries'], static fn( $c ) => '*' !== $c ) );
			$label     = in_array( '*', $zone['countries'], true ) ? __( 'rest of the world', 'wahou-commerce' ) : self::zone_label( $zone, $countries );
			$cheapest  = null;
			$free      = null;
			$min       = 0;
			$max       = 0;
			foreach ( $zone['methods'] as $method ) {
				if ( ! $method['enabled'] || 'pickup' === $method['type'] ) {
					continue;
				}
				if ( 'free' === $method['type'] ) {
					$free = $method['min_amount'];
				} elseif ( null === $cheapest || $method['cost'] < $cheapest ) {
					$cheapest = $method['cost'];
				}
				if ( $method['max_days'] > 0 && ( 0 === $max || $method['max_days'] < $max ) ) {
					$min = $method['min_days'];
					$max = $method['max_days'];
				}
			}
			if ( null === $cheapest && null === $free ) {
				continue;
			}
			$parts = array();
			if ( $max > 0 ) {
				/* translators: 1: minimum days, 2: maximum days. */
				$parts[] = sprintf( __( '%1$d–%2$d business days', 'wahou-commerce' ), min( $min, $max ), $max );
			}
			if ( null !== $cheapest ) {
				$parts[] = 0 === $cheapest ? __( 'free', 'wahou-commerce' ) : Money::format( $cheapest, $currency );
			}
			if ( null !== $free ) {
				$parts[] = 0 === (int) $free
					? __( 'free delivery', 'wahou-commerce' )
					/* translators: %s: amount. */
					: sprintf( __( 'free from %s', 'wahou-commerce' ), Money::format( (int) $free, $currency ) );
			}
			/* translators: 1: destination, 2: details. */
			$lines[] = sprintf( __( 'Delivery to %1$s: %2$s', 'wahou-commerce' ), $label, implode( ', ', $parts ) );
		}
		return $lines;
	}

	/**
	 * @param array<string, mixed> $zone
	 * @param string[]             $countries
	 */
	private static function zone_label( array $zone, array $countries ): string {
		if ( count( $countries ) <= 4 ) {
			return implode( ', ', array_map( array( Countries::class, 'name' ), $countries ) );
		}
		if ( 'EU' === $zone['name'] || 'eu' === $zone['id'] ) {
			return __( 'the European Union', 'wahou-commerce' );
		}
		return '' !== $zone['name'] ? $zone['name'] : implode( ', ', array_map( array( Countries::class, 'name' ), array_slice( $countries, 0, 4 ) ) ) . '…';
	}

	private static function has_kind( string $kind ): bool {
		static $cache = array();
		if ( ! isset( $cache[ $kind ] ) ) {
			$ids            = get_posts(
				array(
					'post_type'      => PostTypes::PRODUCT,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => 'physical' === $kind // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						? array(
							'relation' => 'OR',
							array(
								'key'   => '_wahou_kind',
								'value' => 'physical',
							),
							array(
								'key'     => '_wahou_kind',
								'compare' => 'NOT EXISTS',
							),
						)
						: array(
							array(
								'key'   => '_wahou_kind',
								'value' => $kind,
							),
						),
				)
			);
			$cache[ $kind ] = ! empty( $ids );
		}
		return $cache[ $kind ];
	}

	/**
	 * @return Product[]
	 */
	private static function products( int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => PostTypes::PRODUCT,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $limit,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				),
				'no_found_rows'  => true,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$product = Product::find( $post );
			if ( $product ) {
				$out[] = $product;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Product Markdown.
	 * ------------------------------------------------------------------ */

	public static function product_markdown( Product $product, int $level = 1 ): string {
		$h     = str_repeat( '#', max( 1, min( 4, $level ) ) );
		$h2    = $h . '#';
		$lines = array();

		$lines[] = $h . ' ' . self::escape_md( wp_strip_all_tags( $product->name() ) );
		$lines[] = '';
		$summary = $product->summary( 60 );
		if ( '' !== $summary ) {
			$lines[] = '> ' . self::one_line( $summary );
			$lines[] = '';
		}

		$lines[] = '- **' . __( 'Price', 'wahou-commerce' ) . ':** ' . self::price_text( $product, true );
		$lines[] = '- **' . __( 'Availability', 'wahou-commerce' ) . ':** ' . Seo::availability_label( $product );
		$lines[] = '- **' . __( 'Product type', 'wahou-commerce' ) . ':** ' . self::kind_label( $product );
		if ( '' !== $product->brand() ) {
			$lines[] = '- **' . __( 'Brand', 'wahou-commerce' ) . ':** ' . $product->brand();
		}
		if ( '' !== $product->sku() ) {
			$lines[] = '- **' . __( 'SKU', 'wahou-commerce' ) . ':** ' . $product->sku();
		}
		if ( '' !== $product->gtin() ) {
			$lines[] = '- **GTIN:** ' . $product->gtin();
		}
		if ( $product->rating_count() > 0 ) {
			$lines[] = '- **' . __( 'Rating', 'wahou-commerce' ) . ':** ' . sprintf(
				/* translators: 1: average rating, 2: number of reviews. */
				_n( '%1$s/5 (%2$d review)', '%1$s/5 (%2$d reviews)', $product->rating_count(), 'wahou-commerce' ),
				number_format_i18n( $product->rating_average(), 1 ),
				$product->rating_count()
			);
		}
		$categories = array_map( static fn( $t ) => wp_strip_all_tags( $t->name ), $product->categories() );
		if ( $categories ) {
			$lines[] = '- **' . __( 'Categories', 'wahou-commerce' ) . ':** ' . implode( ', ', $categories );
		}
		$lines[] = '- **' . __( 'Product page', 'wahou-commerce' ) . ':** ' . $product->permalink();
		if ( $product->in_stock() && ! $product->variants() ) {
			$buy     = function_exists( 'wahou_buy_url' ) ? wahou_buy_url( $product->id() ) : add_query_arg( 'wahou_buy', $product->id(), home_url( '/' ) );
			$lines[] = '- **' . __( 'Direct checkout link', 'wahou-commerce' ) . ':** ' . $buy;
		}
		$lines[] = '';

		$highlights = $product->highlights();
		if ( $highlights ) {
			$lines[] = $h2 . ' ' . __( 'Key benefits', 'wahou-commerce' );
			$lines[] = '';
			foreach ( $highlights as $highlight ) {
				$lines[] = '- ' . self::one_line( wp_strip_all_tags( $highlight ) );
			}
			$lines[] = '';
		}

		$description = self::html_to_markdown( $product->description() );
		if ( '' !== $description ) {
			$lines[] = $h2 . ' ' . __( 'Description', 'wahou-commerce' );
			$lines[] = '';
			$lines[] = $description;
			$lines[] = '';
		}

		$variants = $product->variants();
		if ( $variants ) {
			$lines[] = $h2 . ' ' . __( 'Options', 'wahou-commerce' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Option', 'wahou-commerce' ) . ' | ' . __( 'Price', 'wahou-commerce' ) . ' | ' . __( 'Availability', 'wahou-commerce' ) . ' |';
			$lines[] = '|---|---|---|';
			foreach ( $variants as $variant ) {
				$vid     = (string) $variant['id'];
				$lines[] = sprintf(
					'| %1$s | %2$s | %3$s |',
					str_replace( '|', '/', $product->variant_label( $vid ) ),
					Money::format( Seo::gross_price( $product, $product->price( $vid ) ) ),
					Seo::availability_label( $product, $vid )
				);
			}
			$lines[] = '';
		}

		$bundle = 'bundle' === $product->type() ? $product->bundle_items() : array();
		if ( $bundle ) {
			$lines[] = $h2 . ' ' . __( 'Included in this bundle', 'wahou-commerce' );
			$lines[] = '';
			foreach ( $bundle as $component ) {
				$item = Product::find( $component['product_id'] );
				if ( $item ) {
					$lines[] = sprintf( '- %1$d × [%2$s](%3$s)', $component['qty'], self::escape_md( wp_strip_all_tags( $item->name() ) ), $item->permalink() );
				}
			}
			$lines[] = '';
		}

		$specs = self::specs( $product );
		if ( $specs ) {
			$lines[] = $h2 . ' ' . __( 'Specifications', 'wahou-commerce' );
			$lines[] = '';
			foreach ( $specs as $label => $value ) {
				$lines[] = '- **' . $label . ':** ' . $value;
			}
			$lines[] = '';
		}

		$faq = $product->faq();
		if ( $faq ) {
			$lines[] = $h2 . ' ' . __( 'Frequently asked questions', 'wahou-commerce' );
			$lines[] = '';
			foreach ( $faq as $row ) {
				$lines[] = $h2 . '# ' . self::one_line( wp_strip_all_tags( (string) $row['q'] ) );
				$lines[] = '';
				$lines[] = self::html_to_markdown( (string) $row['a'] );
				$lines[] = '';
			}
		}

		$lines[] = $h2 . ' ' . __( 'Delivery and returns', 'wahou-commerce' );
		$lines[] = '';
		if ( $product->needs_shipping() ) {
			foreach ( self::delivery_lines() as $line ) {
				$lines[] = '- ' . $line;
			}
			$days = Settings::int( 'return_days' );
			if ( $days > 0 ) {
				/* translators: %d: number of days. */
				$lines[] = '- ' . sprintf( __( 'You can return this product within %d days of delivery.', 'wahou-commerce' ), $days );
			}
		} elseif ( $product->is_digital() ) {
			$lines[] = '- ' . __( 'Instant delivery: download links are available right after payment, by email and in the customer account.', 'wahou-commerce' );
			if ( Settings::bool( 'digital_waiver' ) ) {
				$lines[] = '- ' . __( 'At checkout, the customer requests immediate access to the digital content and acknowledges losing the 14-day right of withdrawal once delivery has started.', 'wahou-commerce' );
			}
		} else {
			$lines[] = '- ' . __( 'No shipping required.', 'wahou-commerce' );
		}
		$lines[] = '';

		$markdown = trim( implode( "\n", $lines ) ) . "\n";
		/**
		 * Filters the Markdown version of a product.
		 */
		return (string) apply_filters( 'wahou_product_markdown', $markdown, $product );
	}

	public static function price_text( Product $product, bool $detailed = false ): string {
		$suffix   = Tax::price_suffix();
		$variants = $product->variants();
		if ( $variants ) {
			list( $min, $max ) = $product->price_range();
			$text              = $min === $max
				? Money::format( Seo::gross_price( $product, $min ) )
				/* translators: %s: lowest price. */
				: sprintf( __( 'from %s', 'wahou-commerce' ), Money::format( Seo::gross_price( $product, $min ) ) );
			return trim( $text . ' ' . $suffix );
		}
		$text = trim( Money::format( Seo::gross_price( $product, $product->price() ) ) . ' ' . $suffix );
		if ( $detailed && $product->is_on_sale() ) {
			$reference = $product->reference_price();
			if ( $reference ) {
				$text .= ' ' . sprintf(
					/* translators: %s: reference price. */
					__( '(instead of ~~%s~~, the lowest price of the 30 days before the discount)', 'wahou-commerce' ),
					Money::format( Seo::gross_price( $product, $reference ) )
				);
			}
			if ( $product->sale_ends_at() ) {
				/* translators: %s: date. */
				$text .= ' ' . sprintf( __( '– offer valid until %s', 'wahou-commerce' ), wp_date( get_option( 'date_format' ), $product->sale_ends_at() ) );
			}
		}
		return $text;
	}

	private static function kind_label( Product $product ): string {
		if ( 'bundle' === $product->type() ) {
			return __( 'Bundle', 'wahou-commerce' );
		}
		switch ( $product->kind() ) {
			case 'digital':
				return __( 'Digital product (instant download)', 'wahou-commerce' );
			case 'service':
				return __( 'Service', 'wahou-commerce' );
		}
		return __( 'Physical product (shipped)', 'wahou-commerce' );
	}

	/**
	 * @return array<string, string>
	 */
	private static function specs( Product $product ): array {
		$specs = array();
		foreach ( $product->attributes() as $attribute ) {
			$name   = trim( (string) ( $attribute['name'] ?? '' ) );
			$values = array_filter( array_map( 'strval', (array) ( $attribute['values'] ?? array() ) ) );
			if ( '' !== $name && $values ) {
				$specs[ self::escape_md( $name ) ] = implode( ', ', $values );
			}
		}
		if ( $product->weight() > 0 ) {
			$specs[ __( 'Weight', 'wahou-commerce' ) ] = number_format_i18n( $product->weight(), 3 ) . ' kg';
		}
		$d = $product->dimensions();
		if ( $d['length'] > 0 || $d['width'] > 0 || $d['height'] > 0 ) {
			$specs[ __( 'Dimensions', 'wahou-commerce' ) ] = number_format_i18n( $d['length'], 1 ) . ' × ' . number_format_i18n( $d['width'], 1 ) . ' × ' . number_format_i18n( $d['height'], 1 ) . ' cm';
		}
		if ( $product->is_digital() ) {
			$formats = array();
			foreach ( $product->files() as $file ) {
				$ext = strtoupper( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
				if ( '' !== $ext ) {
					$formats[] = $ext;
				}
			}
			if ( $formats ) {
				$specs[ __( 'File formats', 'wahou-commerce' ) ] = implode( ', ', array_unique( $formats ) );
			}
		}
		return $specs;
	}

	/**
	 * Lightweight HTML to Markdown conversion for product content.
	 */
	public static function html_to_markdown( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$html = strip_shortcodes( do_blocks( $html ) );
		$html = preg_replace( '#<(script|style|noscript|iframe|svg|form|button|template)[^>]*>.*?</\1>#si', '', $html ) ?? $html;
		$html = preg_replace( '#<!--.*?-->#s', '', $html ) ?? $html;

		$html = preg_replace_callback(
			'#<h([1-6])[^>]*>(.*?)</h\1>#si',
			static fn( $m ) => "\n\n" . str_repeat( '#', min( 6, (int) $m[1] + 1 ) ) . ' ' . self::one_line( wp_strip_all_tags( $m[2] ) ) . "\n\n",
			$html
		) ?? $html;
		$html = preg_replace_callback(
			'#<img\s[^>]*>#i',
			static function ( $m ) {
				$src = preg_match( '#src=(["\'])(.*?)\1#i', $m[0], $s ) ? $s[2] : '';
				$alt = preg_match( '#alt=(["\'])(.*?)\1#i', $m[0], $a ) ? $a[2] : '';
				return '' !== $src ? '![' . self::escape_md( html_entity_decode( $alt, ENT_QUOTES, 'UTF-8' ) ) . '](' . $src . ')' : '';
			},
			$html
		) ?? $html;
		$html = preg_replace_callback(
			'#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#si',
			static function ( $m ) {
				$text = trim( wp_strip_all_tags( $m[3] ) );
				return '' === $text ? '' : '[' . $text . '](' . $m[2] . ')';
			},
			$html
		) ?? $html;
		$html = preg_replace( '#<(strong|b)(?:\s[^>]*)?>(.*?)</\1>#si', '**$2**', $html ) ?? $html;
		$html = preg_replace( '#<(em|i)(?:\s[^>]*)?>(.*?)</\1>#si', '_$2_', $html ) ?? $html;
		$html = preg_replace( '#<li[^>]*>#i', "\n- ", $html ) ?? $html;
		$html = preg_replace( '#<blockquote[^>]*>#i', "\n> ", $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		$html = preg_replace( '#</t[dh]>#i', ' | ', $html ) ?? $html;
		$html = preg_replace( '#</(p|div|ul|ol|blockquote|table|tr|figure|section|h[1-6])>#i', "\n\n", $html ) ?? $html;

		$text  = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines = array_map( 'rtrim', explode( "\n", str_replace( "\r", '', $text ) ) );
		$lines = array_map( static fn( $l ) => preg_replace( '/^[ \t]+(?=[^-#>|])/', '', $l ) ?? $l, $lines );
		$text  = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) ?? '';
		return trim( $text );
	}

	private static function one_line( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}

	private static function escape_md( string $text ): string {
		return str_replace( array( '[', ']' ), array( '(', ')' ), $text );
	}

	/* ------------------------------------------------------------------
	 * Robots.
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $output
	 * @param bool   $public
	 * @return string
	 */
	public static function robots_txt( $output, $public = true ) {
		$output = (string) $output;
		if ( ! $public ) {
			return $output;
		}
		$rules = array();
		foreach ( array( 'cart', 'checkout', 'account' ) as $key ) {
			foreach ( self::page_paths( $key ) as $path ) {
				$rules[] = 'Disallow: ' . $path;
			}
		}
		foreach ( self::PRIVATE_ARGS as $arg ) {
			$rules[] = 'Disallow: /*' . $arg . '=';
		}
		$block = '# Wahou Commerce' . "\n" . implode( "\n", array_unique( $rules ) ) . "\n";

		$pos = strpos( $output, "User-agent: *\n" );
		if ( false !== $pos ) {
			$end    = strpos( $output, "\n\n", $pos );
			$insert = false === $end ? strlen( $output ) : $end + 1;
			$output = substr( $output, 0, $insert ) . $block . substr( $output, $insert );
		} else {
			$output = "User-agent: *\n" . $block . "\n" . $output;
		}

		$mode = Settings::str( 'seo_ai_crawlers' );
		$bots = array();
		if ( 'block' === $mode ) {
			$bots = array_merge( self::AI_TRAINING, self::AI_ANSWER );
		} elseif ( 'block_training' === $mode ) {
			$bots = self::AI_TRAINING;
		}
		if ( $bots ) {
			$output = rtrim( $output ) . "\n\n# Wahou Commerce – AI crawlers\n";
			foreach ( $bots as $bot ) {
				$output .= 'User-agent: ' . $bot . "\n";
			}
			$output .= "Disallow: /\n";
		}
		return $output;
	}

	/**
	 * Paths of a store page and its translations.
	 *
	 * @return string[]
	 */
	private static function page_paths( string $key ): array {
		$id = Pages::id( $key, false );
		if ( ! $id ) {
			return array();
		}
		$ids = array( $id );
		if ( Compat::is_multilingual() ) {
			$ids = array_merge( $ids, Compat::translations( $id, 'page' ) );
		}
		$paths = array();
		foreach ( array_unique( $ids ) as $page_id ) {
			$path = wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
				$paths[] = trailingslashit( $path );
			}
		}
		return $paths;
	}

	/**
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public static function wp_robots( $robots ): array {
		$robots = (array) $robots;
		if ( self::is_private_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function is_private_request(): bool {
		foreach ( self::PRIVATE_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		return Pages::is_transactional();
	}
}
