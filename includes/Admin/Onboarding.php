<?php
/**
 * Full-screen setup wizard.
 *
 * Seven steps: welcome, taxes, payments, shipping (physical goods only),
 * design, legal & pages, done. Each step is saved over AJAX so the merchant
 * can stop at any time and resume where they left off. The wizard renders its
 * own HTML document (no wp-admin chrome) for focus and speed.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Onboarding {

	public const SLUG   = 'wahou-setup';
	public const OPTION = 'wahou_onboarding';
	public const STEPS  = array( 'welcome', 'tax', 'payments', 'shipping', 'design', 'legal', 'done' );

	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ), 1 );
		add_action( 'admin_init', array( self::class, 'maybe_render' ), 20 );
		add_action( 'wp_ajax_wahou_onboarding_save', array( self::class, 'ajax_save' ) );
		add_action( 'admin_post_wahou_onboarding_skip', array( self::class, 'handle_skip' ) );
		add_action( 'admin_post_wahou_delete_demo', array( self::class, 'handle_delete_demo' ) );
	}

	/* ------------------------------------------------------------------
	 * State.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function update_state( array $values ): void {
		update_option( self::OPTION, array_merge( self::state(), $values ), false );
	}

	/**
	 * Whether the merchant sells physical goods (unknown => true if a physical product exists).
	 */
	public static function sells_physical(): bool {
		$sells = (string) ( self::state()['sells'] ?? '' );
		if ( '' !== $sells ) {
			return 'digital' !== $sells;
		}
		$physical = get_posts(
			array(
				'post_type'      => PostTypes::PRODUCT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wahou_kind',
						'value' => 'physical',
					),
				),
			)
		);
		return ! empty( $physical );
	}

	public static function has_demo(): bool {
		foreach ( (array) ( self::state()['demo_ids'] ?? array() ) as $id ) {
			if ( get_post( (int) $id ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Routing.
	 * ------------------------------------------------------------------ */

	/**
	 * Send the merchant to the wizard right after activation (single activation only).
	 */
	public static function maybe_redirect(): void {
		if ( ! get_transient( '_wahou_activation_redirect' ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		delete_transient( '_wahou_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core activation flag.
		if ( is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_wahou' ) || get_option( 'wahou_onboarding_done' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public static function maybe_render(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing.
		if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wahou-commerce' ), 403 );
		}
		nocache_headers();
		self::render();
		exit;
	}

	/**
	 * Page callback in case the full-screen render did not run.
	 */
	public static function render_fallback(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Setup wizard', 'wahou-commerce' ) . '</h1><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open the setup wizard', 'wahou-commerce' ) . '</a></p></div>';
	}

	public static function handle_skip(): void {
		Admin::guard( 'wahou_onboarding_skip', 'manage_wahou' );
		update_option( 'wahou_onboarding_done', time() );
		Install::create_pages();
		Admin::flash( __( 'Setup skipped. The store pages have been created; finish the checklist below whenever you are ready.', 'wahou-commerce' ), 'info' );
		Admin::back( admin_url( 'admin.php?page=' . Admin::MENU ) );
	}

	public static function handle_delete_demo(): void {
		Admin::guard( 'wahou_delete_demo', 'manage_wahou' );
		$count = self::delete_demo();
		/* translators: %d: number of deleted items. */
		Admin::flash( sprintf( _n( '%d demo item deleted.', '%d demo items deleted.', $count, 'wahou-commerce' ), $count ) );
		Admin::back( admin_url( 'admin.php?page=' . Admin::MENU ) );
	}

	/* ------------------------------------------------------------------
	 * AJAX.
	 * ------------------------------------------------------------------ */

	public static function ajax_save(): void {
		if ( ! check_ajax_referer( 'wahou_onboarding', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'wahou-commerce' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'wahou-commerce' ) ), 403 );
		}
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$data = wp_unslash( $_POST ); // Sanitized field by field below.
		$data = is_array( $data ) ? $data : array();

		switch ( $step ) {
			case 'welcome':
				$result = self::save_welcome( $data );
				break;
			case 'tax':
				$result = self::save_tax( $data );
				break;
			case 'payments':
				$result = self::save_payments( $data );
				break;
			case 'shipping':
				$result = self::save_shipping( $data );
				break;
			case 'design':
				$result = self::save_design( $data );
				break;
			case 'legal':
			case 'finish':
				$result = self::finish( $data );
				break;
			default:
				$result = new \WP_Error( 'wahou_step', __( 'Unknown step.', 'wahou-commerce' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'fields'  => (array) ( $result->get_error_data()['fields'] ?? array() ),
				),
				400
			);
		}

		$index = array_search( 'finish' === $step ? 'legal' : $step, self::STEPS, true );
		if ( false !== $index && 'legal' !== $step && 'finish' !== $step ) {
			self::update_state( array( 'step' => self::STEPS[ min( count( self::STEPS ) - 1, (int) $index + 1 ) ] ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * @param array<string, string> $fields
	 */
	private static function fail( string $message, array $fields = array() ): \WP_Error {
		return new \WP_Error( 'wahou_invalid', $message, array( 'fields' => $fields ) );
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_welcome( array $d ) {
		$sells    = in_array( $d['sells'] ?? '', array( 'physical', 'digital', 'both' ), true ) ? (string) $d['sells'] : 'both';
		$name     = sanitize_text_field( (string) ( $d['store_name'] ?? '' ) );
		$email    = sanitize_email( (string) ( $d['store_email'] ?? '' ) );
		$country  = strtoupper( substr( sanitize_text_field( (string) ( $d['store_country'] ?? '' ) ), 0, 2 ) );
		$currency = strtoupper( substr( sanitize_text_field( (string) ( $d['currency'] ?? '' ) ), 0, 3 ) );

		$errors = array();
		if ( '' === $name ) {
			$errors['store_name'] = __( 'Give your store a name.', 'wahou-commerce' );
		}
		if ( ! is_email( $email ) ) {
			$errors['store_email'] = __( 'Please enter a valid email address.', 'wahou-commerce' );
		}
		if ( ! Countries::exists( $country ) ) {
			$errors['store_country'] = __( 'Please choose your country.', 'wahou-commerce' );
		}
		if ( ! isset( Money::currencies()[ $currency ] ) ) {
			$errors['currency'] = __( 'Please choose a currency.', 'wahou-commerce' );
		}
		if ( $errors ) {
			return self::fail( __( 'Please check the highlighted fields.', 'wahou-commerce' ), $errors );
		}

		$update = array(
			'store_name'    => substr( $name, 0, 120 ),
			'store_email'   => $email,
			'store_country' => $country,
			'currency'      => $currency,
		);
		// Default shipping zones follow the store country until they are customised.
		$saved = get_option( Settings::OPTION, array() );
		if ( ! is_array( $saved ) || ! isset( $saved['shipping_zones'] ) ) {
			$update['shipping_zones'] = Settings::default_shipping_zones( $country );
		}
		Settings::update( $update );
		self::update_state( array( 'sells' => $sells ) );
		return array( 'sells' => $sells );
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_tax( array $d ) {
		$exempt  = 'exempt' === ( $d['vat_mode'] ?? '' );
		$vat     = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $d['store_vat_number'] ?? '' ) ) );
		$mention = sanitize_text_field( (string) ( $d['tax_exempt_mention'] ?? '' ) );
		if ( $exempt && '' === $mention && 'FR' === Settings::store_country() ) {
			$mention = 'TVA non applicable, art. 293 B du CGI';
		}
		Settings::update(
			array(
				'tax_enabled'            => true,
				'tax_exempt'             => $exempt,
				'tax_exempt_mention'     => $mention,
				'store_vat_number'       => substr( $vat, 0, 20 ),
				'prices_include_tax'     => ! empty( $d['prices_include_tax'] ),
				'tax_oss'                => ! $exempt && ! empty( $d['tax_oss'] ),
				'tax_fixed_gross'        => ! empty( $d['tax_fixed_gross'] ),
				'tax_b2b_reverse_charge' => ! $exempt && ! empty( $d['tax_b2b_reverse_charge'] ),
			)
		);
		return array( 'exempt' => $exempt );
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_payments( array $d ) {
		$bank   = ! empty( $d['bank_enabled'] );
		$holder = sanitize_text_field( (string) ( $d['bank_account_holder'] ?? '' ) );
		$iban   = strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $d['bank_iban'] ?? '' ) ) );
		$bic    = strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $d['bank_bic'] ?? '' ) ) );

		$errors = array();
		if ( $bank ) {
			if ( '' === $holder ) {
				$errors['bank_account_holder'] = __( 'Enter the account holder name.', 'wahou-commerce' );
			}
			if ( ! self::valid_iban( $iban ) ) {
				$errors['bank_iban'] = __( 'This IBAN is not valid.', 'wahou-commerce' );
			}
			if ( '' !== $bic && ! preg_match( '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic ) ) {
				$errors['bank_bic'] = __( 'This BIC/SWIFT code is not valid.', 'wahou-commerce' );
			}
		}
		if ( $errors ) {
			return self::fail( __( 'Please check the highlighted fields.', 'wahou-commerce' ), $errors );
		}

		$update = array( 'bank_enabled' => $bank );
		if ( $bank || '' !== $iban ) {
			$update['bank_account_holder'] = substr( $holder, 0, 120 );
			$update['bank_iban']           = $iban;
			$update['bank_bic']            = $bic;
		}
		// Connecting Stripe/PayPal already enabled them; respect an explicit mode choice.
		if ( ! empty( $d['stripe_connected'] ) ) {
			$update['stripe_enabled'] = true;
			$update['stripe_mode']    = 'live' === ( $d['stripe_mode'] ?? '' ) ? 'live' : 'test';
		}
		if ( ! empty( $d['paypal_connected'] ) ) {
			$update['paypal_enabled'] = true;
			$update['paypal_mode']    = 'live' === ( $d['paypal_mode'] ?? '' ) ? 'live' : 'sandbox';
		}
		Settings::update( $update );
		if ( class_exists( Gateways::class ) ) {
			Gateways::reset();
		}
		return array( 'gateways' => array_keys( Gateways::available() ) );
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_shipping( array $d ) {
		$currency = Settings::currency();
		$country  = Settings::store_country();
		$days     = static fn( $value, int $fallback ): int => max( 0, min( 90, '' === (string) $value ? $fallback : (int) $value ) );
		$method   = static function ( string $id, string $type, int $cost, int $min_amount, int $min, int $max ): array {
			return array(
				'id'         => $id,
				'type'       => $type,
				'title'      => '',
				'cost'       => max( 0, $cost ),
				'min_amount' => max( 0, $min_amount ),
				'min_days'   => min( $min, $max ),
				'max_days'   => max( $min, $max ),
				'enabled'    => true,
			);
		};

		$dom_min = $days( $d['domestic_min'] ?? '', 2 );
		$dom_max = $days( $d['domestic_max'] ?? '', 3 );
		$free    = Money::to_minor( (string) ( $d['free_threshold'] ?? '' ), $currency );
		$methods = array( $method( 'flat', 'flat', Money::to_minor( (string) ( $d['domestic_cost'] ?? '' ), $currency ), 0, $dom_min, $dom_max ) );
		if ( $free > 0 ) {
			$methods[] = $method( 'free', 'free', 0, $free, $dom_min, $dom_max );
		}
		$zones = array(
			array(
				'id'        => 'domestic',
				'name'      => Countries::name( $country ),
				'countries' => array( $country ),
				'methods'   => $methods,
			),
		);
		if ( ! empty( $d['eu_enabled'] ) ) {
			$zones[] = array(
				'id'        => 'eu',
				'name'      => __( 'European Union', 'wahou-commerce' ),
				'countries' => array_values( array_diff( Countries::eu(), array( $country ) ) ),
				'methods'   => array( $method( 'flat', 'flat', Money::to_minor( (string) ( $d['eu_cost'] ?? '' ), $currency ), 0, $days( $d['eu_min'] ?? '', 4 ), $days( $d['eu_max'] ?? '', 7 ) ) ),
			);
		}
		if ( ! empty( $d['world_enabled'] ) ) {
			$zones[] = array(
				'id'        => 'world',
				'name'      => __( 'Rest of the world', 'wahou-commerce' ),
				'countries' => array( '*' ),
				'methods'   => array( $method( 'flat', 'flat', Money::to_minor( (string) ( $d['world_cost'] ?? '' ), $currency ), 0, $days( $d['world_min'] ?? '', 7 ), $days( $d['world_max'] ?? '', 14 ) ) ),
			);
		}
		Settings::update( array( 'shipping_zones' => $zones ) );
		return array( 'zones' => count( $zones ) );
	}

	/**
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function save_design( array $d ) {
		$color = sanitize_hex_color( (string) ( $d['brand_color'] ?? '' ) );
		$style = in_array( $d['button_style'] ?? '', array( 'rounded', 'pill', 'square' ), true ) ? (string) $d['button_style'] : 'rounded';
		$radii = array(
			'square'  => 4,
			'rounded' => 12,
			'pill'    => 16,
		);
		Settings::update(
			array(
				'brand_color'  => $color ? $color : '#5b3df5',
				'button_style' => $style,
				'radius'       => $radii[ $style ],
				'cart_mode'    => 'page' === ( $d['cart_mode'] ?? '' ) ? 'page' : 'drawer',
			)
		);
		return array( 'color' => $color );
	}

	/**
	 * Last step: legal identity, pages, demo content.
	 *
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function finish( array $d ) {
		Settings::update(
			array(
				'store_legal_name'  => substr( sanitize_text_field( (string) ( $d['store_legal_name'] ?? '' ) ), 0, 190 ),
				'store_address'     => substr( sanitize_textarea_field( (string) ( $d['store_address'] ?? '' ) ), 0, 500 ),
				'store_legal_extra' => substr( sanitize_text_field( (string) ( $d['store_legal_extra'] ?? '' ) ), 0, 190 ),
			)
		);

		$pages = Install::create_pages();
		$demo  = array();
		if ( ! empty( $d['create_demo'] ) && ! self::has_demo() ) {
			$demo = self::create_demo_products();
		}
		update_option( 'wahou_onboarding_done', time() );
		self::update_state(
			array(
				'step'     => 'done',
				'finished' => time(),
			)
		);

		$gateways = array_map( static fn( $g ) => $g->title(), Gateways::available() );
		$first    = $demo['products'][0] ?? 0;
		return array(
			'shop_url'      => Pages::url( 'shop' ),
			'dashboard_url' => admin_url( 'admin.php?page=' . Admin::MENU ),
			'product_url'   => admin_url( 'post-new.php?post_type=' . PostTypes::PRODUCT ),
			'buy_url'       => $first ? add_query_arg( 'wahou_buy', $first, home_url( '/' ) ) : '',
			'summary'       => array_values(
				array_filter(
					array(
						$gateways
							/* translators: %s: list of payment methods. */
							? sprintf( __( 'Payments: %s', 'wahou-commerce' ), implode( ', ', $gateways ) )
							: __( 'Payments: none yet – connect one from the dashboard before launch', 'wahou-commerce' ),
						/* translators: %d: number of pages. */
						sprintf( _n( '%d store page ready', '%d store pages ready', count( $pages ), 'wahou-commerce' ), count( $pages ) ),
						$demo ? sprintf(
							/* translators: %d: number of demo products. */
							_n( '%d demo product added', '%d demo products added', count( $demo['products'] ), 'wahou-commerce' ),
							count( $demo['products'] )
						) : '',
						Settings::bool( 'tax_exempt' ) ? __( 'VAT: franchise (no VAT charged)', 'wahou-commerce' ) : ( Settings::bool( 'tax_oss' ) ? __( 'VAT: OSS, customer country rates', 'wahou-commerce' ) : __( 'VAT: your country rates', 'wahou-commerce' ) ),
					)
				)
			),
		);
	}

	public static function valid_iban( string $iban ): bool {
		$iban = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $iban ) );
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}
		$moved     = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric   = '';
		$length    = strlen( $moved );
		for ( $i = 0; $i < $length; $i++ ) {
			$char     = $moved[ $i ];
			$numeric .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}
		$remainder = 0;
		foreach ( str_split( $numeric, 7 ) as $chunk ) {
			$remainder = (int) ( (string) $remainder . $chunk ) % 97;
		}
		return 1 === $remainder;
	}

	/* ------------------------------------------------------------------
	 * Demo content.
	 * ------------------------------------------------------------------ */

	/**
	 * Create a small demo catalog: an e-book (digital, real PDF), a T-shirt
	 * with sizes and stock, a mug, a discounted bundle, an order bump and a
	 * welcome coupon. Everything is tagged _wahou_demo for one-click removal.
	 *
	 * @return array{products:int[], ids:int[]}
	 */
	public static function create_demo_products(): array {
		$switched = switch_to_locale( get_locale() );
		$ids      = array();

		$ebooks  = self::demo_term( __( 'E-books', 'wahou-commerce' ) );
		$goodies = self::demo_term( __( 'Goodies', 'wahou-commerce' ) );
		$size    = __( 'Size', 'wahou-commerce' );

		// 1. E-book with a real (generated) PDF delivered through secure links.
		$file  = self::demo_pdf();
		$ebook = self::demo_post(
			__( 'The Wahou guide to selling online (e-book)', 'wahou-commerce' ),
			__( 'The step-by-step method to launch a profitable online store in 30 days: product, price, payment, delivery and marketing.', 'wahou-commerce' ),
			'<!-- wp:paragraph --><p>' . esc_html__( 'A practical guide written for independent creators and small brands. No jargon: every chapter ends with a checklist you can apply the same day.', 'wahou-commerce' ) . '</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'What you will learn', 'wahou-commerce' ) . '</h2><!-- /wp:heading -->'
			. '<!-- wp:list --><ul class="wp-block-list"><li>' . esc_html__( 'Choosing a product people already want to buy', 'wahou-commerce' ) . '</li><li>' . esc_html__( 'Setting a price that protects your margin', 'wahou-commerce' ) . '</li><li>' . esc_html__( 'Turning one-time buyers into loyal customers', 'wahou-commerce' ) . '</li></ul><!-- /wp:list -->',
			array(
				'_wahou_kind'              => 'digital',
				'_wahou_type'              => 'simple',
				'_wahou_price'             => 2400,
				'_wahou_sale_price'        => 1900,
				'_wahou_tax_class'         => 'ebooks',
				'_wahou_sku'               => 'WAHOU-EBOOK',
				'_wahou_sold_individually' => 1,
				'_wahou_files'             => $file ? array( $file ) : array(),
				'_wahou_download_limit'    => 5,
				'_wahou_download_expiry'   => 30,
				'_wahou_badge'             => __( 'Best-seller', 'wahou-commerce' ),
				'_wahou_highlights'        => array(
					__( 'Instant download right after payment', 'wahou-commerce' ),
					__( 'PDF readable on phone, tablet and computer', 'wahou-commerce' ),
					__( 'Free updates for life', 'wahou-commerce' ),
				),
				'_wahou_faq'               => array(
					array(
						'q' => __( 'How do I receive the e-book?', 'wahou-commerce' ),
						'a' => __( 'A download link appears right after payment and is also sent by email.', 'wahou-commerce' ),
					),
					array(
						'q' => __( 'Can I read it on my phone?', 'wahou-commerce' ),
						'a' => __( 'Yes, the PDF works on every device.', 'wahou-commerce' ),
					),
					array(
						'q' => __( 'Can I get a refund?', 'wahou-commerce' ),
						'a' => __( 'Digital content is delivered immediately: once downloaded, the right of withdrawal no longer applies, as required by EU law.', 'wahou-commerce' ),
					),
				),
			),
			array( $ebooks )
		);
		$ids[] = $ebook;

		// 2. Mug (simple physical product with stock).
		$mug   = self::demo_post(
			__( 'Wahou mug', 'wahou-commerce' ),
			__( 'A 350 ml ceramic mug to start every day with a smile. Dishwasher safe.', 'wahou-commerce' ),
			'<!-- wp:paragraph --><p>' . esc_html__( 'Glazed ceramic, 350 ml, microwave and dishwasher safe. Shipped in recycled packaging.', 'wahou-commerce' ) . '</p><!-- /wp:paragraph -->',
			array(
				'_wahou_kind'         => 'physical',
				'_wahou_type'         => 'simple',
				'_wahou_price'        => 1400,
				'_wahou_tax_class'    => 'standard',
				'_wahou_sku'          => 'WAHOU-MUG',
				'_wahou_manage_stock' => 1,
				'_wahou_stock'        => 25,
				'_wahou_weight'       => 0.35,
				'_wahou_highlights'   => array(
					__( 'Dishwasher and microwave safe', 'wahou-commerce' ),
					__( 'Plastic-free packaging', 'wahou-commerce' ),
				),
			),
			array( $goodies )
		);
		$ids[] = $mug;

		// 3. T-shirt with size variants and per-size stock.
		$variants = array();
		foreach ( array( 'S' => 12, 'M' => 18, 'L' => 9, 'XL' => 3 ) as $label => $stock ) {
			$variants[] = array(
				'id'         => 'size-' . strtolower( $label ),
				'attributes' => array( $size => $label ),
				'price'      => null,
				'sale_price' => null,
				'sku'        => 'WAHOU-TEE-' . $label,
				'stock'      => $stock,
				'image_id'   => 0,
			);
		}
		$tee   = self::demo_post(
			__( 'Organic cotton T-shirt', 'wahou-commerce' ),
			__( 'Soft, organic and made to last. Printed in Europe with water-based inks.', 'wahou-commerce' ),
			'<!-- wp:paragraph --><p>' . esc_html__( '100 % organic cotton, 180 g/m². Regular fit: take your usual size.', 'wahou-commerce' ) . '</p><!-- /wp:paragraph -->',
			array(
				'_wahou_kind'       => 'physical',
				'_wahou_type'       => 'variable',
				'_wahou_price'      => 2900,
				'_wahou_tax_class'  => 'standard',
				'_wahou_sku'        => 'WAHOU-TEE',
				'_wahou_weight'     => 0.2,
				'_wahou_attributes' => array(
					array(
						'name'   => $size,
						'values' => array( 'S', 'M', 'L', 'XL' ),
					),
				),
				'_wahou_variants'   => $variants,
				'_wahou_highlights' => array(
					__( '100 % organic cotton', 'wahou-commerce' ),
					__( 'Free exchange of size', 'wahou-commerce' ),
				),
			),
			array( $goodies )
		);
		$ids[] = $tee;

		// 4. Bundle: guide + mug, 15 % off the separate prices.
		$bundle = self::demo_post(
			__( 'Starter pack: guide + mug', 'wahou-commerce' ),
			__( 'Everything you need to get started, 15 % cheaper than buying separately.', 'wahou-commerce' ),
			'<!-- wp:paragraph --><p>' . esc_html__( 'The e-book is available immediately; the mug ships within 48 hours.', 'wahou-commerce' ) . '</p><!-- /wp:paragraph -->',
			array(
				'_wahou_kind'            => 'physical',
				'_wahou_type'            => 'bundle',
				'_wahou_tax_class'       => 'standard',
				'_wahou_sku'             => 'WAHOU-PACK',
				'_wahou_bundle_items'    => array(
					array(
						'product_id' => $ebook,
						'qty'        => 1,
					),
					array(
						'product_id' => $mug,
						'qty'        => 1,
					),
				),
				'_wahou_bundle_pricing'  => 'discount',
				'_wahou_bundle_discount' => 15,
				'_wahou_badge'           => __( 'Save 15 %', 'wahou-commerce' ),
			),
			array( $ebooks, $goodies )
		);
		$ids[] = $bundle;

		// Merchandising links (bought together, cross-sell, upsell).
		update_post_meta( $ebook, '_wahou_fbt', array( $mug ) );
		update_post_meta( $ebook, '_wahou_fbt_discount', 10 );
		update_post_meta( $ebook, '_wahou_upsells', array( $bundle ) );
		update_post_meta( $mug, '_wahou_cross_sells', array( $tee ) );
		update_post_meta( $tee, '_wahou_cross_sells', array( $mug ) );

		// Images (generated, no external download).
		$images = array(
			$ebook  => 'book',
			$mug    => 'mug',
			$tee    => 'tshirt',
			$bundle => 'bundle',
		);
		foreach ( $images as $product_id => $kind ) {
			$attachment = self::demo_image( $kind, get_the_title( $product_id ), $product_id );
			if ( $attachment ) {
				set_post_thumbnail( $product_id, $attachment );
				$ids[] = $attachment;
			}
		}

		// Order bump at checkout.
		$offer = wp_insert_post(
			array(
				'post_type'   => PostTypes::OFFER,
				'post_status' => 'publish',
				'post_title'  => __( 'Demo: mug order bump', 'wahou-commerce' ),
				'meta_input'  => array(
					'_wahou_offer_type'     => 'bump',
					'_wahou_offer_product'  => $mug,
					'_wahou_offer_discount' => 30,
					'_wahou_offer_headline' => __( 'Add the Wahou mug at −30 %', 'wahou-commerce' ),
					'_wahou_offer_text'     => __( 'Your morning coffee deserves it. Ships with your order.', 'wahou-commerce' ),
					'_wahou_offer_triggers' => array(),
					'_wahou_offer_active'   => 1,
					'_wahou_demo'           => 1,
				),
			),
			true
		);
		if ( ! is_wp_error( $offer ) ) {
			$ids[] = (int) $offer;
		}

		// Welcome coupon.
		$coupon = wp_insert_post(
			array(
				'post_type'   => PostTypes::COUPON,
				'post_status' => 'publish',
				'post_title'  => strtoupper( _x( 'WELCOME10', 'demo coupon code', 'wahou-commerce' ) ),
				'meta_input'  => array(
					'_wahou_coupon_type'   => 'percent',
					'_wahou_coupon_amount' => 10,
					'_wahou_demo'          => 1,
				),
			),
			true
		);
		if ( ! is_wp_error( $coupon ) ) {
			$ids[] = (int) $coupon;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		self::update_state(
			array(
				'demo_ids'   => $ids,
				'demo_terms' => array_values( array_filter( array( $ebooks, $goodies ) ) ),
				'demo_file'  => $file ? (string) $file['path'] : '',
			)
		);
		if ( $switched ) {
			restore_previous_locale();
		}
		return array(
			'products' => array( $ebook, $tee, $mug, $bundle ),
			'ids'      => $ids,
		);
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param int[]                $terms
	 */
	private static function demo_post( string $title, string $excerpt, string $content, array $meta, array $terms ): int {
		$meta['_wahou_demo'] = 1;
		$id                  = wp_insert_post(
			array(
				'post_type'    => PostTypes::PRODUCT,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
				'meta_input'   => $meta,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$terms = array_values( array_filter( array_map( 'intval', $terms ) ) );
		if ( $terms ) {
			wp_set_object_terms( (int) $id, $terms, PostTypes::CATEGORY );
		}
		return (int) $id;
	}

	private static function demo_term( string $name ): int {
		$existing = term_exists( $name, PostTypes::CATEGORY );
		if ( is_array( $existing ) ) {
			return (int) $existing['term_id'];
		}
		$term = wp_insert_term( $name, PostTypes::CATEGORY );
		return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	}

	/**
	 * Write a small but valid PDF in the private downloads folder.
	 *
	 * @return array{id:string, name:string, path:string}|null
	 */
	private static function demo_pdf(): ?array {
		Install::create_private_dirs();
		$folder = strtolower( wp_generate_password( 16, false ) );
		$dir    = Install::private_dir() . '/' . $folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$encode = static function ( string $text ): string {
			$text = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ) : $text;
			return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
		};
		$lines = array(
			__( 'Thank you for testing Wahou Commerce!', 'wahou-commerce' ),
			__( 'This file was delivered through a secure download link', 'wahou-commerce' ),
			__( 'that expires and is limited in number of downloads.', 'wahou-commerce' ),
			'',
			__( 'Replace this demo product with your own e-book,', 'wahou-commerce' ),
			__( 'course or template, and start selling.', 'wahou-commerce' ),
		);
		$brand  = self::rgb( Settings::str( 'brand_color' ) );
		$stream = sprintf( "%.3F %.3F %.3F rg 0 742 595 100 re f\n", $brand[0] / 255, $brand[1] / 255, $brand[2] / 255 );
		$stream .= 'BT /F2 26 Tf 1 1 1 rg 56 782 Td (' . $encode( 'Wahou Commerce' ) . ") Tj ET\n";
		$stream .= 'BT /F2 20 Tf 0.1 0.1 0.15 rg 56 680 Td (' . $encode( __( 'The Wahou guide to selling online', 'wahou-commerce' ) ) . ") Tj ET\n";
		$y       = 640;
		foreach ( $lines as $line ) {
			if ( '' !== $line ) {
				$stream .= 'BT /F1 13 Tf 0.25 0.25 0.3 rg 56 ' . $y . ' Td (' . $encode( $line ) . ") Tj ET\n";
			}
			$y -= 22;
		}
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
		);
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $index => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

		$name = 'wahou-guide.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $dir . '/' . $name, $pdf ) ) {
			return null;
		}
		return array(
			'id'   => 'f_' . strtolower( wp_generate_password( 10, false ) ),
			'name' => $name,
			'path' => $folder . '/' . $name,
		);
	}

	/**
	 * @return array{0:int, 1:int, 2:int}
	 */
	private static function rgb( string $hex ): array {
		$hex = ltrim( (string) sanitize_hex_color( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			$hex = '5b3df5';
		}
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Draw a simple product illustration with GD (book, mug, T-shirt, bundle).
	 */
	private static function demo_image( string $kind, string $title, int $parent ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return 0;
		}
		$size = 1000;
		$img  = imagecreatetruecolor( $size, $size );
		if ( ! $img ) {
			return 0;
		}
		if ( function_exists( 'imageantialias' ) ) {
			imageantialias( $img, true );
		}
		list( $br, $bg, $bb ) = self::rgb( Settings::str( 'brand_color' ) );
		$mix                  = static fn( int $c, float $t ): int => (int) round( $c + ( 255 - $c ) * $t );
		$palettes             = array(
			'book'   => array( array( 255, 244, 230 ), array( 255, 226, 204 ) ),
			'mug'    => array( array( 232, 246, 255 ), array( 204, 232, 252 ) ),
			'tshirt' => array( array( 238, 250, 240 ), array( 212, 240, 220 ) ),
			'bundle' => array( array( 246, 240, 255 ), array( 228, 218, 255 ) ),
		);
		list( $c1, $c2 ) = $palettes[ $kind ] ?? $palettes['bundle'];
		for ( $y = 0; $y < $size; $y++ ) {
			$t   = $y / $size;
			$col = imagecolorallocate( $img, (int) ( $c1[0] + ( $c2[0] - $c1[0] ) * $t ), (int) ( $c1[1] + ( $c2[1] - $c1[1] ) * $t ), (int) ( $c1[2] + ( $c2[2] - $c1[2] ) * $t ) );
			imageline( $img, 0, $y, $size, $y, (int) $col );
		}
		$brand  = (int) imagecolorallocate( $img, $br, $bg, $bb );
		$dark   = (int) imagecolorallocate( $img, (int) ( $br * 0.7 ), (int) ( $bg * 0.7 ), (int) ( $bb * 0.7 ) );
		$light  = (int) imagecolorallocate( $img, $mix( $br, 0.75 ), $mix( $bg, 0.75 ), $mix( $bb, 0.75 ) );
		$white  = (int) imagecolorallocate( $img, 255, 255, 255 );
		$shadow = (int) imagecolorallocatealpha( $img, 30, 20, 60, 100 );

		$book = static function ( int $x, int $y, int $w, int $h ) use ( $img, $brand, $dark, $white, $light, $shadow ): void {
			imagefilledellipse( $img, $x + (int) ( $w / 2 ), $y + $h + 18, (int) ( $w * 1.1 ), 40, $shadow );
			imagefilledrectangle( $img, $x + 14, $y + 10, $x + $w + 14, $y + $h + 4, $white );
			imagefilledrectangle( $img, $x, $y, $x + $w, $y + $h, $brand );
			imagefilledrectangle( $img, $x, $y, $x + (int) ( $w * 0.09 ), $y + $h, $dark );
			$line = (int) ( $w * 0.62 );
			imagefilledrectangle( $img, $x + (int) ( $w * 0.2 ), $y + (int) ( $h * 0.22 ), $x + (int) ( $w * 0.2 ) + $line, $y + (int) ( $h * 0.22 ) + 26, $white );
			imagefilledrectangle( $img, $x + (int) ( $w * 0.2 ), $y + (int) ( $h * 0.32 ), $x + (int) ( $w * 0.2 ) + (int) ( $line * 0.7 ), $y + (int) ( $h * 0.32 ) + 18, $light );
			imagefilledellipse( $img, $x + (int) ( $w * 0.55 ), $y + (int) ( $h * 0.66 ), (int) ( $w * 0.34 ), (int) ( $w * 0.34 ), $light );
		};
		$mug = static function ( int $x, int $y, int $w, int $h ) use ( $img, $brand, $dark, $white, $shadow ): void {
			imagefilledellipse( $img, $x + (int) ( $w / 2 ), $y + $h + 14, (int) ( $w * 1.2 ), 36, $shadow );
			for ( $r = (int) ( $h * 0.22 ); $r < (int) ( $h * 0.3 ); $r++ ) {
				imagearc( $img, $x + $w, $y + (int) ( $h * 0.45 ), $r * 2, $r * 2, 270, 90, $dark );
			}
			imagefilledrectangle( $img, $x, $y + 20, $x + $w, $y + $h - 20, $brand );
			imagefilledellipse( $img, $x + 20, $y + $h - 20, 40, 40, $brand );
			imagefilledellipse( $img, $x + $w - 20, $y + $h - 20, 40, 40, $brand );
			imagefilledrectangle( $img, $x + 20, $y + $h - 40, $x + $w - 20, $y + $h, $brand );
			imagefilledellipse( $img, $x + (int) ( $w / 2 ), $y + 20, $w, 46, $dark );
			imagefilledellipse( $img, $x + (int) ( $w / 2 ), $y + (int) ( $h * 0.55 ), (int) ( $w * 0.36 ), (int) ( $w * 0.36 ), $white );
			imagefilledellipse( $img, $x + (int) ( $w / 2 ), $y + (int) ( $h * 0.55 ), (int) ( $w * 0.18 ), (int) ( $w * 0.18 ), $brand );
		};

		switch ( $kind ) {
			case 'book':
				$book( 330, 230, 340, 480 );
				break;
			case 'mug':
				$mug( 330, 330, 300, 360 );
				break;
			case 'tshirt':
				imagefilledellipse( $img, 500, 840, 560, 50, $shadow );
				imagefilledpolygon( $img, array( 380, 200, 300, 230, 170, 360, 250, 450, 310, 410, 310, 800, 690, 800, 690, 410, 750, 450, 830, 360, 700, 230, 620, 200, 590, 240, 500, 265, 410, 240 ), $brand );
				imagefilledpolygon( $img, array( 410, 240, 500, 265, 590, 240, 575, 222, 500, 240, 425, 222 ), $dark );
				imagefilledellipse( $img, 500, 470, 170, 170, $white );
				imagefilledellipse( $img, 500, 470, 90, 90, $brand );
				break;
			default:
				$book( 230, 260, 290, 410 );
				$mug( 540, 420, 230, 280 );
		}

		ob_start();
		imagepng( $img, null, 6 );
		$png = (string) ob_get_clean();
		imagedestroy( $img );

		$upload = wp_upload_bits( 'wahou-demo-' . $kind . '.png', null, $png );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent,
			true
		);
		if ( is_wp_error( $attachment ) || ! $attachment ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( (int) $attachment, wp_generate_attachment_metadata( (int) $attachment, $upload['file'] ) );
		update_post_meta( (int) $attachment, '_wp_attachment_image_alt', $title );
		update_post_meta( (int) $attachment, '_wahou_demo', 1 );
		return (int) $attachment;
	}

	/**
	 * Remove every demo item created by the wizard. Returns the number removed.
	 */
	public static function delete_demo(): int {
		$state = self::state();
		$count = 0;
		foreach ( (array) ( $state['demo_ids'] ?? array() ) as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || ! get_post_meta( (int) $id, '_wahou_demo', true ) ) {
				continue;
			}
			$deleted = 'attachment' === $post->post_type ? wp_delete_attachment( (int) $id, true ) : wp_delete_post( (int) $id, true );
			if ( $deleted ) {
				++$count;
			}
		}
		foreach ( (array) ( $state['demo_terms'] ?? array() ) as $term_id ) {
			$term = get_term( (int) $term_id, PostTypes::CATEGORY );
			if ( $term instanceof \WP_Term && 0 === (int) $term->count ) {
				wp_delete_term( (int) $term_id, PostTypes::CATEGORY );
			}
		}
		$file = (string) ( $state['demo_file'] ?? '' );
		if ( '' !== $file && preg_match( '#^[a-z0-9]+/wahou-guide\.pdf$#', $file ) ) {
			$path = Install::private_dir() . '/' . $file;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
				@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		self::update_state(
			array(
				'demo_ids'   => array(),
				'demo_terms' => array(),
				'demo_file'  => '',
			)
		);
		if ( class_exists( '\WahouCommerce\Funnels\Offers' ) ) {
			\WahouCommerce\Funnels\Offers::flush();
		}
		return $count;
	}

	/* ------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	private static function render(): void {
		$state     = self::state();
		$resume    = (string) ( $state['step'] ?? 'welcome' );
		$resume    = get_option( 'wahou_onboarding_done' ) || ! in_array( $resume, self::STEPS, true ) || 'done' === $resume ? 'welcome' : $resume;
		$sells     = (string) ( $state['sells'] ?? 'both' );
		$country   = Settings::store_country();
		$currency  = Settings::currency();
		$brand     = (string) ( sanitize_hex_color( Settings::str( 'brand_color' ) ) ?: '#5b3df5' );
		$user      = wp_get_current_user();
		$dashboard = admin_url( 'admin.php?page=' . Admin::MENU );
		$labels    = array(
			'welcome'  => __( 'Welcome', 'wahou-commerce' ),
			'tax'      => __( 'Taxes', 'wahou-commerce' ),
			'payments' => __( 'Payments', 'wahou-commerce' ),
			'shipping' => __( 'Shipping', 'wahou-commerce' ),
			'design'   => __( 'Design', 'wahou-commerce' ),
			'legal'    => __( 'Legal & pages', 'wahou-commerce' ),
			'done'     => __( 'Ready!', 'wahou-commerce' ),
		);

		$data = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'wahou_onboarding' ),
			'connectNonce' => wp_create_nonce( 'wahou_gateway_connect' ),
			'steps'        => self::STEPS,
			'start'        => $resume,
			'sells'        => $sells,
			'eu'           => Countries::eu(),
			'currencies'   => self::country_currencies(),
			'dashboardUrl' => $dashboard,
			'i18n'         => array(
				'error'        => __( 'Something went wrong. Please try again.', 'wahou-commerce' ),
				'saving'       => __( 'Saving…', 'wahou-commerce' ),
				'connecting'   => __( 'Connecting…', 'wahou-commerce' ),
				'connected'    => __( 'Connected', 'wahou-commerce' ),
				'notConnected' => __( 'Not connected', 'wahou-commerce' ),
				'unavailable'  => __( 'The connection service is not available. Check that Wahou Commerce is up to date.', 'wahou-commerce' ),
				'missingKeys'  => __( 'Paste both keys first.', 'wahou-commerce' ),
				'stripeKeyMismatch' => __( 'These keys do not match the selected mode (test keys start with pk_test_ / sk_test_, live keys with pk_live_ / sk_live_).', 'wahou-commerce' ),
				/* translators: %1$s: step number, %2$s: total steps. */
				'stepOf'       => __( 'Step %1$s of %2$s', 'wahou-commerce' ),
				'ibanInvalid'  => __( 'This IBAN is not valid.', 'wahou-commerce' ),
				'required'     => __( 'This field is required.', 'wahou-commerce' ),
				'testMode'     => __( 'Test mode', 'wahou-commerce' ),
				'liveMode'     => __( 'Live mode', 'wahou-commerce' ),
			),
		);

		$stripe_mode     = 'live' === Settings::str( 'stripe_mode' ) ? 'live' : 'test';
		$paypal_mode     = 'live' === Settings::str( 'paypal_mode' ) ? 'live' : 'sandbox';
		$stripe_ok       = Settings::bool( 'stripe_enabled' ) && Settings::has_secret( 'stripe_' . $stripe_mode . '_secret' );
		$paypal_ok       = Settings::bool( 'paypal_enabled' ) && Settings::has_secret( 'paypal_' . $paypal_mode . '_secret' );
		$products        = wp_count_posts( PostTypes::PRODUCT );
		$has_products    = isset( $products->publish ) && (int) $products->publish > 0;
		$ship            = self::shipping_defaults();
		$total_steps     = count( self::STEPS );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( '%s – %s', __( 'Setup wizard', 'wahou-commerce' ), 'Wahou Commerce' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( WAHOU_URL . 'assets/css/onboarding.css?ver=' . rawurlencode( WAHOU_VERSION ) ); ?>">
	<style>:root{--ob-brand:<?php echo esc_html( $brand ); ?>;}</style>
</head>
<body class="wahou-ob">
	<a class="wahou-ob-skip-link" href="#wahou-ob-main"><?php esc_html_e( 'Skip to content', 'wahou-commerce' ); ?></a>
	<header class="wahou-ob-header">
		<div class="wahou-ob-brand"><span class="wahou-ob-logo" aria-hidden="true">W</span><span>Wahou Commerce</span></div>
		<a class="wahou-ob-exit" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Exit setup', 'wahou-commerce' ); ?></a>
	</header>

	<div class="wahou-ob-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Setup progress', 'wahou-commerce' ); ?>" aria-valuemin="1" aria-valuemax="<?php echo esc_attr( (string) $total_steps ); ?>" aria-valuenow="1" data-ob-progress><span></span></div>

	<nav class="wahou-ob-stepper" aria-label="<?php esc_attr_e( 'Setup steps', 'wahou-commerce' ); ?>">
		<ol>
			<?php foreach ( self::STEPS as $i => $step ) : ?>
				<li data-stepper="<?php echo esc_attr( $step ); ?>"><button type="button" data-goto="<?php echo esc_attr( $step ); ?>" disabled><span class="wahou-ob-stepper__dot" aria-hidden="true"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span><span class="wahou-ob-stepper__label"><?php echo esc_html( $labels[ $step ] ); ?></span></button></li>
			<?php endforeach; ?>
		</ol>
		<p class="wahou-ob-stepper__compact" data-ob-compact></p>
	</nav>

	<main id="wahou-ob-main" class="wahou-ob-main">

		<?php /* ---------- 1. Welcome ---------- */ ?>
		<section class="wahou-ob-step" data-step="welcome" aria-labelledby="ob-t-welcome">
			<form class="wahou-ob-card" data-save="welcome" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['welcome'] ); ?></p>
					<h1 id="ob-t-welcome" tabindex="-1">
						<?php
						/* translators: %s: user first name. */
						echo esc_html( sprintf( __( 'Welcome %s! Let’s open your store.', 'wahou-commerce' ), $user->first_name ? $user->first_name : $user->display_name ) );
						?>
					</h1>
					<p><?php esc_html_e( 'Five minutes, no code. Every answer can be changed later in the settings.', 'wahou-commerce' ); ?></p>
				</div>

				<fieldset class="wahou-ob-choices wahou-ob-choices--3">
					<legend><?php esc_html_e( 'What do you sell?', 'wahou-commerce' ); ?></legend>
					<?php
					self::choice( 'sells', 'physical', $sells, __( 'Physical products', 'wahou-commerce' ), __( 'Shipped goods with stock', 'wahou-commerce' ), 'box' );
					self::choice( 'sells', 'digital', $sells, __( 'Digital products', 'wahou-commerce' ), __( 'E-books, courses, files', 'wahou-commerce' ), 'download' );
					self::choice( 'sells', 'both', $sells, __( 'Both', 'wahou-commerce' ), __( 'The best of both worlds', 'wahou-commerce' ), 'spark' );
					?>
				</fieldset>

				<div class="wahou-ob-grid">
					<?php
					self::input( 'store_name', __( 'Store name', 'wahou-commerce' ), Settings::store_name(), array( 'required' => true, 'autocomplete' => 'organization' ) );
					self::input( 'store_email', __( 'Contact email', 'wahou-commerce' ), Settings::store_email(), array( 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'help' => __( 'Order notifications and customer replies go here.', 'wahou-commerce' ) ) );
					?>
					<div class="wahou-ob-field wahou-ob-field--half">
						<label for="ob-store_country"><?php esc_html_e( 'Country of your business', 'wahou-commerce' ); ?></label>
						<select id="ob-store_country" name="store_country" required>
							<?php foreach ( Countries::all() as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="wahou-ob-field__error" data-error-for="store_country" hidden></p>
					</div>
					<div class="wahou-ob-field wahou-ob-field--half">
						<label for="ob-currency"><?php esc_html_e( 'Currency', 'wahou-commerce' ); ?></label>
						<select id="ob-currency" name="currency" required>
							<?php foreach ( Money::currencies() as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency, $code ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="wahou-ob-field__error" data-error-for="currency" hidden></p>
					</div>
				</div>

				<?php self::footer( false, __( 'Let’s go', 'wahou-commerce' ), false ); ?>
				<p class="wahou-ob-fineprint">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wahou_onboarding_skip' ), 'wahou_onboarding_skip' ) ); ?>"><?php esc_html_e( 'I’m an expert, skip the wizard', 'wahou-commerce' ); ?></a>
				</p>
			</form>
		</section>

		<?php /* ---------- 2. Taxes ---------- */ ?>
		<section class="wahou-ob-step" data-step="tax" aria-labelledby="ob-t-tax" hidden>
			<form class="wahou-ob-card" data-save="tax" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['tax'] ); ?></p>
					<h1 id="ob-t-tax" tabindex="-1"><?php esc_html_e( 'VAT, handled for you', 'wahou-commerce' ); ?></h1>
					<p><?php esc_html_e( 'Rates for all 27 EU countries are built in, including reduced rates for books and e-books.', 'wahou-commerce' ); ?></p>
				</div>

				<fieldset class="wahou-ob-choices">
					<legend><?php esc_html_e( 'Do you charge VAT?', 'wahou-commerce' ); ?></legend>
					<?php
					$vat_mode = Settings::bool( 'tax_exempt' ) ? 'exempt' : 'registered';
					self::choice( 'vat_mode', 'registered', $vat_mode, __( 'Yes, I charge VAT', 'wahou-commerce' ), __( 'I have a VAT number', 'wahou-commerce' ), 'receipt' );
					self::choice( 'vat_mode', 'exempt', $vat_mode, __( 'No, I am VAT exempt', 'wahou-commerce' ), __( 'Small business franchise', 'wahou-commerce' ), 'shield' );
					?>
				</fieldset>

				<div data-show-when="vat_mode:exempt">
					<?php
					$mention = Settings::str( 'tax_exempt_mention' );
					if ( '' === $mention && 'FR' === $country ) {
						$mention = 'TVA non applicable, art. 293 B du CGI';
					}
					self::input( 'tax_exempt_mention', __( 'Mention printed on your invoices', 'wahou-commerce' ), $mention, array( 'help' => __( 'In France: “TVA non applicable, art. 293 B du CGI”.', 'wahou-commerce' ), 'full' => true ) );
					?>
				</div>

				<div data-show-when="vat_mode:registered">
					<?php self::input( 'store_vat_number', __( 'Your VAT number', 'wahou-commerce' ), Settings::str( 'store_vat_number' ), array( 'placeholder' => 'FR12345678901', 'autocomplete' => 'off', 'help' => __( 'Printed on invoices. Required for B2B reverse charge.', 'wahou-commerce' ), 'full' => true ) ); ?>
					<div class="wahou-ob-toggles">
						<?php
						self::toggle( 'prices_include_tax', Settings::bool( 'prices_include_tax' ), __( 'My prices include VAT', 'wahou-commerce' ), __( 'Recommended for consumers: the price you type is the price they pay.', 'wahou-commerce' ) );
						?>
						<div data-eu-only>
							<?php
							self::toggle( 'tax_oss', Settings::bool( 'tax_oss' ), __( 'I am registered for the EU One-Stop Shop (OSS)', 'wahou-commerce' ), __( 'Turn on when your sales to consumers in other EU countries exceed €10,000 a year (all countries combined) or if you opted in: customers then pay their own country’s VAT. Below the threshold, your country’s VAT applies.', 'wahou-commerce' ) );
							self::toggle( 'tax_fixed_gross', Settings::bool( 'tax_fixed_gross' ), __( 'Same price for every EU customer', 'wahou-commerce' ), __( 'The displayed price never changes at checkout; only the VAT share varies by country.', 'wahou-commerce' ) );
							self::toggle( 'tax_b2b_reverse_charge', Settings::bool( 'tax_b2b_reverse_charge' ), __( 'Let EU businesses buy without VAT', 'wahou-commerce' ), __( 'VAT numbers are checked live with the official VIES service, then the reverse charge applies.', 'wahou-commerce' ) );
							?>
						</div>
					</div>
				</div>
				<p class="wahou-ob-note"><?php esc_html_e( 'Not sure? Keep the recommended options and ask your accountant: you can change them at any time in Settings → Tax.', 'wahou-commerce' ); ?></p>
				<?php self::footer( true, __( 'Continue', 'wahou-commerce' ), false ); ?>
			</form>
		</section>

		<?php /* ---------- 3. Payments ---------- */ ?>
		<section class="wahou-ob-step" data-step="payments" aria-labelledby="ob-t-payments" hidden>
			<form class="wahou-ob-card" data-save="payments" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['payments'] ); ?></p>
					<h1 id="ob-t-payments" tabindex="-1"><?php esc_html_e( 'Get paid in two minutes', 'wahou-commerce' ); ?></h1>
					<p><?php esc_html_e( 'Paste your keys: we check them, create the webhooks and enable Apple Pay and Google Pay automatically.', 'wahou-commerce' ); ?></p>
				</div>

				<?php
				self::gateway_card(
					'stripe',
					'Stripe',
					__( 'Cards, Apple Pay, Google Pay, Link, SEPA and more.', 'wahou-commerce' ),
					$stripe_ok,
					$stripe_mode,
					array( 'test', 'live' ),
					Settings::str( 'stripe_' . $stripe_mode . '_publishable' ),
					array(
						'public'      => __( 'Publishable key', 'wahou-commerce' ),
						'secret'      => __( 'Secret key', 'wahou-commerce' ),
						'public_ph'   => 'pk_test_…',
						'secret_ph'   => 'sk_test_…',
						'keys_url'    => 'https://dashboard.stripe.com/test/apikeys',
						'signup_url'  => 'https://dashboard.stripe.com/register',
						'account'     => Settings::str( 'stripe_account_name' ),
						'hint'        => $stripe_ok ? Settings::secret_hint( 'stripe_' . $stripe_mode . '_secret' ) : '',
					)
				);
				self::gateway_card(
					'paypal',
					'PayPal',
					__( 'PayPal, Pay Later and cards through PayPal.', 'wahou-commerce' ),
					$paypal_ok,
					$paypal_mode,
					array( 'sandbox', 'live' ),
					Settings::str( 'paypal_' . $paypal_mode . '_client_id' ),
					array(
						'public'     => __( 'Client ID', 'wahou-commerce' ),
						'secret'     => __( 'Secret', 'wahou-commerce' ),
						'public_ph'  => 'AXk…',
						'secret_ph'  => 'EH…',
						'keys_url'   => 'https://developer.paypal.com/dashboard/applications',
						'signup_url' => 'https://www.paypal.com/business',
						'account'    => '',
						'hint'       => $paypal_ok ? Settings::secret_hint( 'paypal_' . $paypal_mode . '_secret' ) : '',
					)
				);
				?>

				<div class="wahou-ob-gateway" data-gateway="bank">
					<div class="wahou-ob-gateway__head">
						<span class="wahou-ob-gateway__logo wahou-ob-gateway__logo--bank" aria-hidden="true"><?php echo self::icon( 'bank' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h2><?php esc_html_e( 'Bank transfer', 'wahou-commerce' ); ?></h2>
							<p><?php esc_html_e( 'For B2B and large orders. You confirm reception manually.', 'wahou-commerce' ); ?></p>
						</div>
					</div>
					<?php self::toggle( 'bank_enabled', Settings::bool( 'bank_enabled' ), __( 'Accept bank transfers', 'wahou-commerce' ) ); ?>
					<div class="wahou-ob-grid" data-show-when="bank_enabled:1">
						<?php
						self::input( 'bank_account_holder', __( 'Account holder', 'wahou-commerce' ), Settings::str( 'bank_account_holder' ), array( 'autocomplete' => 'off' ) );
						self::input( 'bank_iban', 'IBAN', Settings::str( 'bank_iban' ), array( 'autocomplete' => 'off', 'placeholder' => 'FR76 …', 'half' => true ) );
						self::input( 'bank_bic', __( 'BIC / SWIFT (optional)', 'wahou-commerce' ), Settings::str( 'bank_bic' ), array( 'autocomplete' => 'off', 'half' => true ) );
						?>
					</div>
				</div>
				<?php self::footer( true, __( 'Continue', 'wahou-commerce' ), true ); ?>
			</form>
		</section>

		<?php /* ---------- 4. Shipping ---------- */ ?>
		<section class="wahou-ob-step" data-step="shipping" aria-labelledby="ob-t-shipping" hidden>
			<form class="wahou-ob-card" data-save="shipping" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['shipping'] ); ?></p>
					<h1 id="ob-t-shipping" tabindex="-1"><?php esc_html_e( 'Simple, clear delivery', 'wahou-commerce' ); ?></h1>
					<p><?php esc_html_e( 'Shoppers see the delivery cost and date before paying: no bad surprise, fewer abandoned carts.', 'wahou-commerce' ); ?></p>
				</div>

				<div class="wahou-ob-zone">
					<h2>
						<?php
						/* translators: %s: country name. */
						echo esc_html( sprintf( __( 'Delivery in %s', 'wahou-commerce' ), Countries::name( $country ) ) );
						?>
					</h2>
					<div class="wahou-ob-grid">
						<?php
						self::input( 'domestic_cost', __( 'Price', 'wahou-commerce' ), $ship['domestic_cost'], array( 'inputmode' => 'decimal', 'suffix' => Money::symbol( $currency ), 'half' => true ) );
						self::input( 'free_threshold', __( 'Free shipping from', 'wahou-commerce' ), $ship['free_threshold'], array( 'inputmode' => 'decimal', 'suffix' => Money::symbol( $currency ), 'half' => true, 'help' => __( 'Leave empty for no free shipping. A progress bar shows shoppers how much is left.', 'wahou-commerce' ) ) );
						self::days( 'domestic', $ship['domestic_min'], $ship['domestic_max'] );
						?>
					</div>
				</div>

				<div class="wahou-ob-zone">
					<?php self::toggle( 'eu_enabled', $ship['eu_enabled'], __( 'Ship to the rest of the European Union', 'wahou-commerce' ) ); ?>
					<div class="wahou-ob-grid" data-show-when="eu_enabled:1">
						<?php
						self::input( 'eu_cost', __( 'Price', 'wahou-commerce' ), $ship['eu_cost'], array( 'inputmode' => 'decimal', 'suffix' => Money::symbol( $currency ), 'half' => true ) );
						self::days( 'eu', $ship['eu_min'], $ship['eu_max'] );
						?>
					</div>
				</div>

				<div class="wahou-ob-zone">
					<?php self::toggle( 'world_enabled', $ship['world_enabled'], __( 'Ship worldwide', 'wahou-commerce' ) ); ?>
					<div class="wahou-ob-grid" data-show-when="world_enabled:1">
						<?php
						self::input( 'world_cost', __( 'Price', 'wahou-commerce' ), $ship['world_cost'], array( 'inputmode' => 'decimal', 'suffix' => Money::symbol( $currency ), 'half' => true ) );
						self::days( 'world', $ship['world_min'], $ship['world_max'] );
						?>
					</div>
				</div>
				<?php self::footer( true, __( 'Continue', 'wahou-commerce' ), true ); ?>
			</form>
		</section>

		<?php /* ---------- 5. Design ---------- */ ?>
		<section class="wahou-ob-step" data-step="design" aria-labelledby="ob-t-design" hidden>
			<form class="wahou-ob-card" data-save="design" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['design'] ); ?></p>
					<h1 id="ob-t-design" tabindex="-1"><?php esc_html_e( 'Make it yours', 'wahou-commerce' ); ?></h1>
					<p><?php esc_html_e( 'Wahou adapts to your theme. Pick your brand color and button style.', 'wahou-commerce' ); ?></p>
				</div>
				<div class="wahou-ob-design">
					<div class="wahou-ob-design__controls">
						<div class="wahou-ob-field">
							<label for="ob-brand_color"><?php esc_html_e( 'Brand color', 'wahou-commerce' ); ?></label>
							<div class="wahou-ob-color">
								<input type="color" id="ob-brand_color" name="brand_color" value="<?php echo esc_attr( $brand ); ?>">
								<div class="wahou-ob-swatches" role="group" aria-label="<?php esc_attr_e( 'Suggested colors', 'wahou-commerce' ); ?>">
									<?php foreach ( array( '#5b3df5', '#2563eb', '#0f766e', '#16a34a', '#ea580c', '#db2777', '#111827' ) as $swatch ) : ?>
										<button type="button" class="wahou-ob-swatch" data-swatch="<?php echo esc_attr( $swatch ); ?>" style="--swatch:<?php echo esc_attr( $swatch ); ?>"><span class="screen-reader-text"><?php echo esc_html( $swatch ); ?></span></button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<fieldset class="wahou-ob-choices wahou-ob-choices--3 wahou-ob-choices--compact">
							<legend><?php esc_html_e( 'Button style', 'wahou-commerce' ); ?></legend>
							<?php
							$style = Settings::str( 'button_style' ) ?: 'rounded';
							self::choice( 'button_style', 'rounded', $style, __( 'Rounded', 'wahou-commerce' ), '', '' );
							self::choice( 'button_style', 'pill', $style, __( 'Pill', 'wahou-commerce' ), '', '' );
							self::choice( 'button_style', 'square', $style, __( 'Square', 'wahou-commerce' ), '', '' );
							?>
						</fieldset>
						<fieldset class="wahou-ob-choices wahou-ob-choices--compact">
							<legend><?php esc_html_e( 'Cart', 'wahou-commerce' ); ?></legend>
							<?php
							$cart_mode = 'page' === Settings::str( 'cart_mode' ) ? 'page' : 'drawer';
							self::choice( 'cart_mode', 'drawer', $cart_mode, __( 'Slide-in cart', 'wahou-commerce' ), __( 'Recommended: shoppers stay on the page', 'wahou-commerce' ), '' );
							self::choice( 'cart_mode', 'page', $cart_mode, __( 'Cart page', 'wahou-commerce' ), __( 'Classic full page cart', 'wahou-commerce' ), '' );
							?>
						</fieldset>
					</div>
					<div class="wahou-ob-preview" data-preview data-style="<?php echo esc_attr( $style ); ?>" aria-hidden="true">
						<div class="wahou-ob-preview__img"><span><?php echo self::icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><em class="wahou-ob-preview__badge">−20 %</em></div>
						<div class="wahou-ob-preview__body">
							<strong><?php esc_html_e( 'Your best product', 'wahou-commerce' ); ?></strong>
							<span class="wahou-ob-preview__stars">★★★★★ <small>(128)</small></span>
							<span class="wahou-ob-preview__price"><s><?php echo esc_html( Money::format( 3900, $currency ) ); ?></s> <b><?php echo esc_html( Money::format( 3100, $currency ) ); ?></b></span>
							<span class="wahou-ob-preview__btn"><?php esc_html_e( 'Add to cart', 'wahou-commerce' ); ?></span>
							<span class="wahou-ob-preview__ship"><?php esc_html_e( 'Free delivery from 50 €', 'wahou-commerce' ); ?></span>
						</div>
					</div>
				</div>
				<?php self::footer( true, __( 'Continue', 'wahou-commerce' ), true ); ?>
			</form>
		</section>

		<?php /* ---------- 6. Legal & pages ---------- */ ?>
		<section class="wahou-ob-step" data-step="legal" aria-labelledby="ob-t-legal" hidden>
			<form class="wahou-ob-card" data-save="legal" novalidate>
				<div class="wahou-ob-intro">
					<p class="wahou-ob-kicker"><?php echo esc_html( $labels['legal'] ); ?></p>
					<h1 id="ob-t-legal" tabindex="-1"><?php esc_html_e( 'Legal details & store pages', 'wahou-commerce' ); ?></h1>
					<p><?php esc_html_e( 'These details appear on invoices and in your terms of sale.', 'wahou-commerce' ); ?></p>
				</div>
				<div class="wahou-ob-grid">
					<?php
					self::input( 'store_legal_name', __( 'Legal name', 'wahou-commerce' ), Settings::str( 'store_legal_name' ), array( 'autocomplete' => 'organization', 'placeholder' => __( 'Company or your full name', 'wahou-commerce' ) ) );
					self::input( 'store_legal_extra', __( 'Registration numbers', 'wahou-commerce' ), Settings::str( 'store_legal_extra' ), array( 'placeholder' => __( 'e.g. SIRET, NIF, company number', 'wahou-commerce' ) ) );
					?>
					<div class="wahou-ob-field">
						<label for="ob-store_address"><?php esc_html_e( 'Business address', 'wahou-commerce' ); ?></label>
						<textarea id="ob-store_address" name="store_address" rows="3" autocomplete="street-address"><?php echo esc_textarea( Settings::str( 'store_address' ) ); ?></textarea>
						<p class="wahou-ob-field__error" data-error-for="store_address" hidden></p>
					</div>
				</div>

				<div class="wahou-ob-pages">
					<h2><?php esc_html_e( 'Pages we create for you', 'wahou-commerce' ); ?></h2>
					<ul>
						<?php foreach ( array( 'shop', 'cart', 'checkout', 'account', 'terms' ) as $key ) : ?>
							<?php $exists = Pages::id( $key, false ) && get_post( Pages::id( $key, false ) ); ?>
							<li class="<?php echo $exists ? 'is-done' : ''; ?>"><span aria-hidden="true"><?php echo $exists ? '✓' : '+'; ?></span> <?php echo esc_html( Pages::labels()[ $key ] ); ?> <?php if ( $exists ) : ?><small><?php esc_html_e( 'already exists', 'wahou-commerce' ); ?></small><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="wahou-ob-note"><?php esc_html_e( 'The terms of sale page is a template with the sections EU law expects: complete it and have it reviewed before launch.', 'wahou-commerce' ); ?></p>
				</div>
				<?php
				if ( ! self::has_demo() ) {
					self::toggle( 'create_demo', ! $has_products, __( 'Add demo products', 'wahou-commerce' ), __( 'An e-book with a real PDF, a T-shirt with sizes, a mug, a bundle, an order bump and a coupon: test everything, then delete them in one click.', 'wahou-commerce' ) );
				}
				?>
				<?php self::footer( true, __( 'Create my store', 'wahou-commerce' ), false ); ?>
			</form>
		</section>

		<?php /* ---------- 7. Done ---------- */ ?>
		<section class="wahou-ob-step wahou-ob-step--done" data-step="done" aria-labelledby="ob-t-done" hidden>
			<div class="wahou-ob-card">
				<div class="wahou-ob-done__icon" aria-hidden="true"><?php echo self::icon( 'spark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<h1 id="ob-t-done" tabindex="-1"><?php esc_html_e( 'Wahou! Your store is ready.', 'wahou-commerce' ); ?></h1>
				<p class="wahou-ob-done__lead"><?php esc_html_e( 'Fast, mobile-first, optimized for Google and AI assistants, and ready to sell.', 'wahou-commerce' ); ?></p>
				<ul class="wahou-ob-summary" data-ob-summary></ul>
				<div class="wahou-ob-done__actions">
					<a class="wahou-ob-btn wahou-ob-btn--primary" data-done-link="product_url" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::PRODUCT ) ); ?>"><?php esc_html_e( 'Add my first product', 'wahou-commerce' ); ?></a>
					<a class="wahou-ob-btn" data-done-link="shop_url" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View my store', 'wahou-commerce' ); ?></a>
					<a class="wahou-ob-btn wahou-ob-btn--ghost" data-done-link="dashboard_url" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Go to the dashboard', 'wahou-commerce' ); ?></a>
				</div>
				<div class="wahou-ob-tip" data-ob-buy hidden>
					<strong><?php esc_html_e( 'Pro tip', 'wahou-commerce' ); ?></strong>
					<p><?php esc_html_e( 'Every product has a direct checkout link to share in emails, social bios, QR codes or inside your books:', 'wahou-commerce' ); ?></p>
					<code data-ob-buy-url></code>
				</div>
			</div>
		</section>
	</main>
	<div class="screen-reader-text" aria-live="polite" data-ob-live></div>
	<canvas class="wahou-ob-confetti" aria-hidden="true" data-ob-confetti></canvas>
	<script>window.wahouOnboarding = <?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
	<script src="<?php echo esc_url( WAHOU_URL . 'assets/js/onboarding.js?ver=' . rawurlencode( WAHOU_VERSION ) ); ?>" defer></script>
</body>
</html>
		<?php
	}

	/**
	 * Current shipping configuration mapped to the wizard fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function shipping_defaults(): array {
		$currency = Settings::currency();
		$find     = static function ( string $zone_id, string $type ): ?array {
			foreach ( Shipping::zones() as $zone ) {
				if ( $zone_id !== $zone['id'] && ! ( 'world' === $zone_id && in_array( '*', $zone['countries'], true ) ) ) {
					continue;
				}
				foreach ( $zone['methods'] as $method ) {
					if ( $type === $method['type'] ) {
						return $method;
					}
				}
			}
			return null;
		};
		$dom   = $find( 'domestic', 'flat' );
		$free  = $find( 'domestic', 'free' );
		$eu    = $find( 'eu', 'flat' );
		$world = $find( 'world', 'flat' );
		return array(
			'domestic_cost'  => Money::to_input( $dom ? (int) $dom['cost'] : 490, $currency ),
			'free_threshold' => $free ? Money::to_input( (int) $free['min_amount'], $currency ) : ( $dom ? '' : Money::to_input( 5000, $currency ) ),
			'domestic_min'   => $dom ? (int) $dom['min_days'] : 2,
			'domestic_max'   => $dom ? (int) $dom['max_days'] : 3,
			'eu_enabled'     => null !== $eu || ! $dom,
			'eu_cost'        => Money::to_input( $eu ? (int) $eu['cost'] : 990, $currency ),
			'eu_min'         => $eu ? (int) $eu['min_days'] : 4,
			'eu_max'         => $eu ? (int) $eu['max_days'] : 7,
			'world_enabled'  => null !== $world,
			'world_cost'     => Money::to_input( $world ? (int) $world['cost'] : 1990, $currency ),
			'world_min'      => $world ? (int) $world['min_days'] : 7,
			'world_max'      => $world ? (int) $world['max_days'] : 14,
		);
	}

	/**
	 * Suggested currency for non-euro countries (wizard convenience).
	 *
	 * @return array<string, string>
	 */
	private static function country_currencies(): array {
		return array(
			'GB' => 'GBP',
			'US' => 'USD',
			'CH' => 'CHF',
			'LI' => 'CHF',
			'CA' => 'CAD',
			'AU' => 'AUD',
			'SE' => 'SEK',
			'NO' => 'NOK',
			'DK' => 'DKK',
			'PL' => 'PLN',
			'CZ' => 'CZK',
			'HU' => 'HUF',
			'RO' => 'RON',
			'MA' => 'MAD',
			'TN' => 'TND',
			'SN' => 'XOF',
			'CI' => 'XOF',
			'BJ' => 'XOF',
			'BF' => 'XOF',
			'ML' => 'XOF',
			'TG' => 'XOF',
			'NE' => 'XOF',
			'MX' => 'MXN',
			'BR' => 'BRL',
			'JP' => 'JPY',
		);
	}

	/* ------------------------------------------------------------------
	 * Field helpers (escaped output).
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $args type, required, autocomplete, inputmode, placeholder, help, half, full, suffix.
	 */
	private static function input( string $name, string $label, string $value, array $args = array() ): void {
		$id    = 'ob-' . $name;
		$class = 'wahou-ob-field' . ( ! empty( $args['half'] ) ? ' wahou-ob-field--half' : '' ) . ( ! empty( $args['full'] ) ? ' wahou-ob-field--full' : '' );
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="wahou-ob-input<?php echo ! empty( $args['suffix'] ) ? ' has-suffix' : ''; ?>">
				<input
					type="<?php echo esc_attr( (string) ( $args['type'] ?? 'text' ) ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php echo ! empty( $args['required'] ) ? 'required aria-required="true"' : ''; ?>
					<?php echo ! empty( $args['autocomplete'] ) ? 'autocomplete="' . esc_attr( (string) $args['autocomplete'] ) . '"' : ''; ?>
					<?php echo ! empty( $args['inputmode'] ) ? 'inputmode="' . esc_attr( (string) $args['inputmode'] ) . '"' : ''; ?>
					<?php echo ! empty( $args['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $args['placeholder'] ) . '"' : ''; ?>
					<?php echo ! empty( $args['help'] ) ? 'aria-describedby="' . esc_attr( $id . '-help' ) . '"' : ''; ?>
				>
				<?php if ( ! empty( $args['suffix'] ) ) : ?>
					<span class="wahou-ob-input__suffix" aria-hidden="true"><?php echo esc_html( (string) $args['suffix'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $args['help'] ) ) : ?>
				<p class="wahou-ob-help" id="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_html( (string) $args['help'] ); ?></p>
			<?php endif; ?>
			<p class="wahou-ob-field__error" data-error-for="<?php echo esc_attr( $name ); ?>" hidden></p>
		</div>
		<?php
	}

	private static function days( string $prefix, int $min, int $max ): void {
		?>
		<div class="wahou-ob-field wahou-ob-field--half">
			<span class="wahou-ob-label" id="<?php echo esc_attr( 'ob-' . $prefix . '-days' ); ?>"><?php esc_html_e( 'Delivery time (business days)', 'wahou-commerce' ); ?></span>
			<div class="wahou-ob-range" role="group" aria-labelledby="<?php echo esc_attr( 'ob-' . $prefix . '-days' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( 'ob-' . $prefix . '_min' ); ?>"><?php esc_html_e( 'Minimum days', 'wahou-commerce' ); ?></label>
				<input type="number" min="0" max="90" inputmode="numeric" id="<?php echo esc_attr( 'ob-' . $prefix . '_min' ); ?>" name="<?php echo esc_attr( $prefix . '_min' ); ?>" value="<?php echo esc_attr( (string) $min ); ?>">
				<span aria-hidden="true">–</span>
				<label class="screen-reader-text" for="<?php echo esc_attr( 'ob-' . $prefix . '_max' ); ?>"><?php esc_html_e( 'Maximum days', 'wahou-commerce' ); ?></label>
				<input type="number" min="0" max="90" inputmode="numeric" id="<?php echo esc_attr( 'ob-' . $prefix . '_max' ); ?>" name="<?php echo esc_attr( $prefix . '_max' ); ?>" value="<?php echo esc_attr( (string) $max ); ?>">
				<span><?php esc_html_e( 'days', 'wahou-commerce' ); ?></span>
			</div>
		</div>
		<?php
	}

	private static function toggle( string $name, bool $checked, string $label, string $help = '' ): void {
		$id = 'ob-' . $name;
		?>
		<div class="wahou-ob-toggle">
			<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?><?php echo '' !== $help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : ''; ?>>
			<label for="<?php echo esc_attr( $id ); ?>">
				<span class="wahou-ob-toggle__ui" aria-hidden="true"></span>
				<span class="wahou-ob-toggle__label"><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( '' !== $help ) : ?>
				<p class="wahou-ob-help" id="<?php echo esc_attr( $id . '-help' ); ?>"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function choice( string $name, string $value, string $current, string $title, string $text, string $icon ): void {
		$id = 'ob-' . $name . '-' . $value;
		?>
		<div class="wahou-ob-choice">
			<input type="radio" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php if ( '' !== $icon ) : ?>
					<span class="wahou-ob-choice__icon" aria-hidden="true"><?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endif; ?>
				<span class="wahou-ob-choice__title"><?php echo esc_html( $title ); ?></span>
				<?php if ( '' !== $text ) : ?>
					<span class="wahou-ob-choice__text"><?php echo esc_html( $text ); ?></span>
				<?php endif; ?>
			</label>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $mode_values
	 * @param array<string, string> $txt
	 */
	private static function gateway_card( string $id, string $name, string $description, bool $connected, string $mode, array $mode_values, string $public, array $txt ): void {
		$labels = array(
			'test'    => __( 'Test', 'wahou-commerce' ),
			'sandbox' => __( 'Sandbox', 'wahou-commerce' ),
			'live'    => __( 'Live', 'wahou-commerce' ),
		);
		?>
		<div class="wahou-ob-gateway<?php echo $connected ? ' is-connected' : ''; ?>" data-gateway="<?php echo esc_attr( $id ); ?>">
			<div class="wahou-ob-gateway__head">
				<span class="wahou-ob-gateway__logo wahou-ob-gateway__logo--<?php echo esc_attr( $id ); ?>" aria-hidden="true"><?php echo esc_html( substr( $name, 0, 1 ) ); ?></span>
				<div>
					<h2><?php echo esc_html( $name ); ?></h2>
					<p><?php echo esc_html( $description ); ?></p>
				</div>
				<span class="wahou-ob-badge" data-gateway-status><?php echo esc_html( $connected ? __( 'Connected', 'wahou-commerce' ) : __( 'Not connected', 'wahou-commerce' ) ); ?></span>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $id . '_connected' ); ?>" value="<?php echo $connected ? '1' : ''; ?>" data-gateway-connected>
			<?php if ( $connected ) : ?>
				<p class="wahou-ob-gateway__info" data-gateway-info>
					<?php
					echo esc_html(
						trim(
							( '' !== $txt['account'] ? $txt['account'] . ' · ' : '' ) . $labels[ $mode ] . ( '' !== $txt['hint'] ? ' · ' . $txt['hint'] : '' )
						)
					);
					?>
				</p>
			<?php else : ?>
				<p class="wahou-ob-gateway__info" data-gateway-info hidden></p>
			<?php endif; ?>
			<details class="wahou-ob-gateway__form"<?php echo $connected ? '' : ' open'; ?>>
				<summary><?php echo esc_html( $connected ? __( 'Change keys', 'wahou-commerce' ) : __( 'Connect', 'wahou-commerce' ) ); ?></summary>
				<fieldset class="wahou-ob-segmented">
					<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'wahou-commerce' ); ?></legend>
					<?php foreach ( $mode_values as $value ) : ?>
						<input type="radio" id="<?php echo esc_attr( 'ob-' . $id . '-mode-' . $value ); ?>" name="<?php echo esc_attr( $id . '_mode' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
						<label for="<?php echo esc_attr( 'ob-' . $id . '-mode-' . $value ); ?>"><?php echo esc_html( $labels[ $value ] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<div class="wahou-ob-grid">
					<div class="wahou-ob-field">
						<label for="<?php echo esc_attr( 'ob-' . $id . '-public' ); ?>"><?php echo esc_html( $txt['public'] ); ?></label>
						<input type="text" id="<?php echo esc_attr( 'ob-' . $id . '-public' ); ?>" data-key="public" value="<?php echo esc_attr( $public ); ?>" placeholder="<?php echo esc_attr( $txt['public_ph'] ); ?>" autocomplete="off" spellcheck="false" autocapitalize="off">
					</div>
					<div class="wahou-ob-field">
						<label for="<?php echo esc_attr( 'ob-' . $id . '-secret' ); ?>"><?php echo esc_html( $txt['secret'] ); ?></label>
						<input type="password" id="<?php echo esc_attr( 'ob-' . $id . '-secret' ); ?>" data-key="secret" value="" placeholder="<?php echo esc_attr( $connected && '' !== $txt['hint'] ? $txt['hint'] : $txt['secret_ph'] ); ?>" autocomplete="new-password" spellcheck="false" autocapitalize="off">
					</div>
				</div>
				<div class="wahou-ob-gateway__actions">
					<button type="button" class="wahou-ob-btn wahou-ob-btn--primary wahou-ob-btn--small" data-connect><?php echo esc_html( sprintf( /* translators: %s: payment provider. */ __( 'Connect %s', 'wahou-commerce' ), $name ) ); ?></button>
					<a href="<?php echo esc_url( $txt['keys_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Where do I find my keys?', 'wahou-commerce' ); ?></a>
					<a href="<?php echo esc_url( $txt['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Create an account', 'wahou-commerce' ); ?></a>
				</div>
				<p class="wahou-ob-gateway__result" role="status" data-gateway-result hidden></p>
			</details>
		</div>
		<?php
	}

	private static function footer( bool $back, string $next, bool $skippable ): void {
		?>
		<div class="wahou-ob-form__error" role="alert" data-form-error hidden></div>
		<div class="wahou-ob-actions">
			<?php if ( $back ) : ?>
				<button type="button" class="wahou-ob-btn wahou-ob-btn--ghost" data-back><?php esc_html_e( 'Back', 'wahou-commerce' ); ?></button>
			<?php else : ?>
				<span></span>
			<?php endif; ?>
			<div class="wahou-ob-actions__right">
				<?php if ( $skippable ) : ?>
					<button type="button" class="wahou-ob-btn wahou-ob-btn--link" data-skip><?php esc_html_e( 'Do this later', 'wahou-commerce' ); ?></button>
				<?php endif; ?>
				<button type="submit" class="wahou-ob-btn wahou-ob-btn--primary"><span data-label><?php echo esc_html( $next ); ?></span></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Small inline icons (decorative).
	 */
	private static function icon( string $name ): string {
		$paths = array(
			'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
			'download' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
			'spark'    => '<path d="M12 2v5M12 17v5M2 12h5M17 12h5M5 5l3 3M16 16l3 3M19 5l-3 3M8 16l-3 3"/>',
			'receipt'  => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2Z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
			'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
			'bank'     => '<path d="M3 10h18L12 4 3 10Zm2 0v8m4-8v8m6-8v8m4-8v8M3 20h18"/>',
		);
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
