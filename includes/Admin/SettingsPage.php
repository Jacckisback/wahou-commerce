<?php
/**
 * Store settings screen (Wahou Commerce › Settings).
 *
 * The screen is driven by a declarative schema (tabs → sections → fields)
 * used both for rendering and for typed sanitization: only the keys of the
 * submitted tab are processed, so unchecked toggles of other tabs are never
 * reset. Complex settings (shipping zones, VAT overrides, reminder sequence,
 * payment gateways) have dedicated renderers and sanitizers.
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
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Request;
use WahouCommerce\Tax\Rates;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const SLUG = 'wahou-settings';

	/** Secret keys editable on the Payments tab, with their expected format. */
	private const SECRETS = array(
		'stripe_test_secret'         => '/^(sk|rk)_test_[A-Za-z0-9_]{10,}$/',
		'stripe_live_secret'         => '/^(sk|rk)_live_[A-Za-z0-9_]{10,}$/',
		'stripe_test_webhook_secret' => '/^whsec_[A-Za-z0-9+\/=_]{10,}$/',
		'stripe_live_webhook_secret' => '/^whsec_[A-Za-z0-9+\/=_]{10,}$/',
		'paypal_sandbox_secret'      => '/^[A-Za-z0-9_\-]{20,}$/',
		'paypal_live_secret'         => '/^[A-Za-z0-9_\-]{20,}$/',
	);

	public static function init(): void {
		Fields::init();
		add_action( 'admin_post_wahou_save_settings', array( self::class, 'save' ) );
		add_action( 'admin_post_wahou_create_pages', array( self::class, 'create_pages' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function url( string $tab = 'general', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param string $hook Admin page hook suffix.
	 */
	public static function enqueue( $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( false === strpos( (string) $hook, self::SLUG ) && self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'wahou-admin', WAHOU_URL . 'assets/css/admin.css', array(), WAHOU_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'wahou-admin-settings', WAHOU_URL . 'assets/js/admin-settings.js', array(), WAHOU_VERSION, array( 'in_footer' => true ) );
		wp_localize_script( 'wahou-admin-settings', 'wahouAdmin', Fields::script_data() );
	}

	/* ------------------------------------------------------------------
	 * Schema.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'general'     => __( 'General', 'wahou-commerce' ),
			'pages'       => __( 'Pages', 'wahou-commerce' ),
			'catalog'     => __( 'Catalog & product page', 'wahou-commerce' ),
			'checkout'    => __( 'Checkout', 'wahou-commerce' ),
			'payments'    => __( 'Payments', 'wahou-commerce' ),
			'tax'         => __( 'Tax', 'wahou-commerce' ),
			'shipping'    => __( 'Shipping', 'wahou-commerce' ),
			'emails'      => __( 'Emails & recovery', 'wahou-commerce' ),
			'design'      => __( 'Design', 'wahou-commerce' ),
			'seo'         => __( 'SEO & GEO', 'wahou-commerce' ),
			'performance' => __( 'Performance', 'wahou-commerce' ),
			'advanced'    => __( 'Advanced', 'wahou-commerce' ),
		);
	}

	private static function toggle_options(): array {
		return array(
			'optional' => __( 'Optional', 'wahou-commerce' ),
			'required' => __( 'Required', 'wahou-commerce' ),
			'hidden'   => __( 'Hidden', 'wahou-commerce' ),
		);
	}

	/**
	 * Sections of a tab. Section keys: id, title, description, fields, render (method
	 * called after the fields), manual (fields rendered by "render"), sanitize (method).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sections( string $tab ): array {
		$store_name = Settings::store_name();
		$slug_help  = static fn( string $base ): string => sprintf(
			/* translators: %s: example URL. */
			__( 'Example: %s', 'wahou-commerce' ),
			'<code>' . esc_html( home_url( '/' . $base . '/…/' ) ) . '</code>'
		);

		switch ( $tab ) {
			case 'general':
				return array(
					array(
						'id'          => 'store',
						'title'       => __( 'Your business', 'wahou-commerce' ),
						'description' => __( 'Printed on invoices and emails, and used in structured data.', 'wahou-commerce' ),
						'fields'      => array(
							'store_name'        => array(
								'type'        => 'text',
								'label'       => __( 'Store name', 'wahou-commerce' ),
								'placeholder' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
							),
							'store_legal_name'  => array(
								'type'  => 'text',
								'label' => __( 'Legal name', 'wahou-commerce' ),
								'help'  => __( 'Company name, or your full name if you are a sole trader.', 'wahou-commerce' ),
							),
							'store_email'       => array(
								'type'        => 'email',
								'label'       => __( 'Store email', 'wahou-commerce' ),
								'placeholder' => (string) get_option( 'admin_email' ),
								'help'        => __( 'Receives new order notifications and customer replies.', 'wahou-commerce' ),
							),
							'store_address'     => array(
								'type'  => 'textarea',
								'label' => __( 'Business address', 'wahou-commerce' ),
								'rows'  => 3,
							),
							'store_country'     => array(
								'type'  => 'country',
								'label' => __( 'Country of establishment', 'wahou-commerce' ),
								'help'  => __( 'Determines your domestic VAT rates.', 'wahou-commerce' ),
							),
							'store_vat_number'  => array(
								'type'        => 'text',
								'label'       => __( 'VAT number', 'wahou-commerce' ),
								'placeholder' => 'FR12345678901',
							),
							'store_legal_extra' => array(
								'type'  => 'textarea',
								'label' => __( 'Other legal mentions', 'wahou-commerce' ),
								'rows'  => 3,
								'help'  => __( 'Registration number (SIRET, NIF/CIF…), trade register, share capital… Printed at the bottom of invoices.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'currency',
						'title'  => __( 'Currency & units', 'wahou-commerce' ),
						'fields' => array(
							'currency'       => array(
								'type'    => 'select',
								'label'   => __( 'Currency', 'wahou-commerce' ),
								'options' => Money::currencies(),
								'help'    => __( 'Prices are displayed in the format of each visitor\'s language (1 234,50 € / €1,234.50).', 'wahou-commerce' ),
							),
							'weight_unit'    => array(
								'type'    => 'segmented',
								'label'   => __( 'Weight unit', 'wahou-commerce' ),
								'options' => array(
									'kg' => 'kg',
									'g'  => 'g',
									'lb' => 'lb',
									'oz' => 'oz',
								),
							),
							'dimension_unit' => array(
								'type'    => 'segmented',
								'label'   => __( 'Dimension unit', 'wahou-commerce' ),
								'options' => array(
									'cm' => 'cm',
									'mm' => 'mm',
									'in' => 'in',
								),
							),
						),
					),
					array(
						'id'          => 'numbering',
						'title'       => __( 'Order & invoice numbering', 'wahou-commerce' ),
						'description' => __( 'Invoice and credit note numbers are sequential and never reused, as EU invoicing rules require.', 'wahou-commerce' ),
						'fields'      => array(
							'order_prefix'       => array(
								'type'        => 'text',
								'label'       => __( 'Order number prefix', 'wahou-commerce' ),
								'placeholder' => __( 'e.g. WC-', 'wahou-commerce' ),
								'maxlength'   => 12,
							),
							'invoice_prefix'     => array(
								'type'      => 'text',
								'label'     => __( 'Invoice prefix', 'wahou-commerce' ),
								'maxlength' => 20,
								'help'      => __( 'Use <code>{YYYY}</code> for the year, e.g. <code>F-{YYYY}-</code> gives F-2026-000124.', 'wahou-commerce' ),
							),
							'credit_note_prefix' => array(
								'type'      => 'text',
								'label'     => __( 'Credit note prefix', 'wahou-commerce' ),
								'maxlength' => 20,
							),
							'invoice_offset'     => array(
								'type'  => 'int',
								'label' => __( 'Invoice number offset', 'wahou-commerce' ),
								'min'   => 0,
								'max'   => 99999999,
								'help'  => __( 'Added to the internal sequence. Coming from another tool whose last invoice was 1000? Enter 1000 and the next one will be 1001.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'          => 'urls',
						'title'       => __( 'Product URLs', 'wahou-commerce' ),
						'description' => __( 'Short, readable URLs in your language rank better. Changing them later breaks existing links.', 'wahou-commerce' ),
						'fields'      => array(
							'product_base'  => array(
								'type'        => 'slug',
								'label'       => __( 'Product base', 'wahou-commerce' ),
								'placeholder' => _x( 'product', 'URL slug', 'wahou-commerce' ),
								'help'        => $slug_help( '' !== Settings::str( 'product_base' ) ? Settings::str( 'product_base' ) : _x( 'product', 'URL slug', 'wahou-commerce' ) ),
							),
							'category_base' => array(
								'type'        => 'slug',
								'label'       => __( 'Category base', 'wahou-commerce' ),
								'placeholder' => _x( 'product-category', 'URL slug', 'wahou-commerce' ),
								'help'        => $slug_help( '' !== Settings::str( 'category_base' ) ? Settings::str( 'category_base' ) : _x( 'product-category', 'URL slug', 'wahou-commerce' ) ),
							),
						),
					),
				);

			case 'pages':
				$fields = array();
				foreach ( Pages::KEYS as $key ) {
					$fields[ 'page_' . $key ] = array(
						'type'  => 'page',
						'label' => Pages::labels()[ $key ],
					);
				}
				return array(
					array(
						'id'          => 'pages',
						'title'       => __( 'Store pages', 'wahou-commerce' ),
						'description' => __( 'Each page contains a Wahou block. Missing pages can be created in one click; you can then edit their text like any page.', 'wahou-commerce' ),
						'fields'      => $fields,
						'manual'      => true,
						'render'      => 'render_pages',
					),
				);

			case 'catalog':
				return array(
					array(
						'id'     => 'catalog',
						'title'  => __( 'Catalog', 'wahou-commerce' ),
						'fields' => array(
							'products_per_page'   => array(
								'type'  => 'int',
								'label' => __( 'Products per page', 'wahou-commerce' ),
								'min'   => 1,
								'max'   => 100,
							),
							'catalog_columns'     => array(
								'type'    => 'segmented',
								'label'   => __( 'Columns on desktop', 'wahou-commerce' ),
								'options' => array(
									'2' => '2',
									'3' => '3',
									'4' => '4',
								),
								'cast'    => 'int',
								'help'    => __( 'Mobile always shows 2 columns for easy thumb scrolling.', 'wahou-commerce' ),
							),
							'show_stock'          => array(
								'type'  => 'toggle',
								'label' => __( 'Show low stock messages ("Only 3 left")', 'wahou-commerce' ),
							),
							'low_stock_threshold' => array(
								'type'  => 'int',
								'label' => __( 'Low stock threshold', 'wahou-commerce' ),
								'min'   => 0,
								'max'   => 10000,
								'help'  => __( 'Also used for low stock email alerts. Each product can override it.', 'wahou-commerce' ),
							),
							'free_shipping_bar'   => array(
								'type'  => 'toggle',
								'label' => __( 'Free shipping progress bar in the cart', 'wahou-commerce' ),
								'help'  => __( '"Only 12,10 € left for free delivery" – one of the most effective ways to raise the average order value.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'product_page',
						'title'  => __( 'Product page', 'wahou-commerce' ),
						'fields' => array(
							'sticky_atc'      => array(
								'type'  => 'toggle',
								'label' => __( 'Sticky "Add to cart" bar on mobile', 'wahou-commerce' ),
							),
							'buy_now_button'  => array(
								'type'  => 'toggle',
								'label' => __( '"Buy now" button that goes straight to checkout', 'wahou-commerce' ),
							),
							'trust_badges'    => array(
								'type'  => 'toggle',
								'label' => __( 'Reassurance badges (secure payment, returns, delivery)', 'wahou-commerce' ),
							),
							'omnibus_display' => array(
								'type'  => 'toggle',
								'label' => __( 'Show the reference price on discounted products', 'wahou-commerce' ),
								'help'  => __( 'In the EU, any announced price reduction must refer to the lowest price applied in the previous 30 days (Omnibus rule, art. 6a of Directive 98/6/EC). Wahou records your price history automatically.', 'wahou-commerce' ),
							),
							'cart_mode'       => array(
								'type'    => 'segmented',
								'label'   => __( 'After "Add to cart"', 'wahou-commerce' ),
								'options' => array(
									'drawer' => __( 'Open the slide-in cart', 'wahou-commerce' ),
									'page'   => __( 'Go to the cart page', 'wahou-commerce' ),
								),
							),
						),
					),
					array(
						'id'     => 'reviews',
						'title'  => __( 'Reviews', 'wahou-commerce' ),
						'fields' => array(
							'reviews_enabled'       => array(
								'type'  => 'toggle',
								'label' => __( 'Enable product reviews', 'wahou-commerce' ),
								'help'  => __( 'Star ratings appear in Google results and reassure buyers.', 'wahou-commerce' ),
							),
							'reviews_verified_only' => array(
								'type'    => 'toggle',
								'label'   => __( 'Only verified buyers can leave a review', 'wahou-commerce' ),
								'show_if' => 'reviews_enabled',
							),
						),
					),
					array(
						'id'     => 'delivery',
						'title'  => __( 'Delivery estimate', 'wahou-commerce' ),
						'fields' => array(
							'delivery_estimate' => array(
								'type'  => 'toggle',
								'label' => __( 'Show an estimated delivery date on product pages', 'wahou-commerce' ),
							),
							'shipping_cutoff'   => array(
								'type'    => 'time',
								'label'   => __( 'Order cut-off time', 'wahou-commerce' ),
								'help'    => __( 'Orders placed before this time (store time zone) ship the same business day.', 'wahou-commerce' ),
								'show_if' => 'delivery_estimate',
							),
							'processing_days'   => array(
								'type'    => 'int',
								'label'   => __( 'Preparation time (business days)', 'wahou-commerce' ),
								'min'     => 0,
								'max'     => 30,
								'show_if' => 'delivery_estimate',
							),
							'business_days'     => array(
								'type'    => 'weekdays',
								'label'   => __( 'Shipping days', 'wahou-commerce' ),
								'show_if' => 'delivery_estimate',
							),
						),
					),
				);

			case 'checkout':
				return array(
					array(
						'id'     => 'accounts',
						'title'  => __( 'Customer accounts', 'wahou-commerce' ),
						'fields' => array(
							'guest_checkout'      => array(
								'type'  => 'toggle',
								'label' => __( 'Allow guest checkout', 'wahou-commerce' ),
								'help'  => __( 'Recommended: forcing account creation is one of the main causes of abandoned checkouts.', 'wahou-commerce' ),
							),
							'auto_create_account' => array(
								'type'  => 'toggle',
								'label' => __( 'Create the customer account automatically after purchase', 'wahou-commerce' ),
								'help'  => __( 'No password to invent: customers log in with a secure link sent by email.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'fields',
						'title'  => __( 'Checkout fields', 'wahou-commerce' ),
						'fields' => array(
							'field_phone'       => array(
								'type'    => 'segmented',
								'label'   => __( 'Phone number', 'wahou-commerce' ),
								'options' => self::toggle_options(),
								'help'    => __( 'Only asked when the order is shipped. Carriers use it for delivery updates.', 'wahou-commerce' ),
							),
							'field_company'     => array(
								'type'    => 'segmented',
								'label'   => __( 'Company name', 'wahou-commerce' ),
								'options' => self::toggle_options(),
							),
							'order_notes'       => array(
								'type'  => 'toggle',
								'label' => __( 'Allow order notes', 'wahou-commerce' ),
							),
							'allowed_countries' => array(
								'type'  => 'countries',
								'label' => __( 'Countries allowed for digital products', 'wahou-commerce' ),
								'help'  => __( 'Leave empty to sell worldwide. Physical products are limited to your shipping zones.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'legal',
						'title'  => __( 'Legal & compliance', 'wahou-commerce' ),
						'fields' => array(
							'terms_checkbox'   => array(
								'type'  => 'toggle',
								'label' => __( 'Require acceptance of the terms and conditions of sale', 'wahou-commerce' ),
								'help'  => __( 'Shown only when a terms page is selected in the Pages tab.', 'wahou-commerce' ),
							),
							'digital_waiver'   => array(
								'type'  => 'toggle',
								'label' => __( 'Ask for consent to immediate access for digital products', 'wahou-commerce' ),
								'help'  => __( 'EU consumers have 14 days to withdraw from a purchase, except for digital content delivered immediately when they expressly agree and acknowledge that they lose this right (art. 16(m) Directive 2011/83/EU). This checkbox collects that consent and stores it with the order.', 'wahou-commerce' ),
							),
							'pay_button_label' => array(
								'type'        => 'text',
								'label'       => __( 'Pay button label', 'wahou-commerce' ),
								/* translators: %s: amount placeholder kept as is. */
								'placeholder' => __( 'Pay %s', 'wahou-commerce' ),
								'maxlength'   => 60,
								'help'        => __( '<code>%s</code> is replaced by the amount. EU law requires a label that makes the obligation to pay obvious ("Pay", "Order with obligation to pay"…).', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'security',
						'title'  => __( 'Fraud protection', 'wahou-commerce' ),
						'fields' => array(
							'honeypot'            => array(
								'type'  => 'toggle',
								'label' => __( 'Invisible anti-bot field', 'wahou-commerce' ),
							),
							'checkout_rate_limit' => array(
								'type'  => 'int',
								'label' => __( 'Maximum checkout attempts per visitor (15 minutes)', 'wahou-commerce' ),
								'min'   => 0,
								'max'   => 200,
								'help'  => __( 'Protects your payment account against card-testing attacks. 0 disables the limit.', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'     => 'funnels',
						'title'  => __( 'Order bumps & upsells', 'wahou-commerce' ),
						'fields' => array(
							'bumps_enabled'   => array(
								'type'  => 'toggle',
								'label' => __( 'Order bumps at checkout', 'wahou-commerce' ),
							),
							'upsells_enabled' => array(
								'type'  => 'toggle',
								'label' => __( 'One-click offers after payment', 'wahou-commerce' ),
								'help'  => sprintf(
									/* translators: %s: link to the offers screen. */
									__( 'Create your offers in %s.', 'wahou-commerce' ),
									'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . PostTypes::OFFER ) ) . '">' . esc_html__( 'Offers & funnels', 'wahou-commerce' ) . '</a>'
								),
							),
						),
					),
					array(
						'id'     => 'thankyou',
						'title'  => __( 'Order confirmation page', 'wahou-commerce' ),
						'fields' => array(
							'thankyou_message' => array(
								'type'  => 'html',
								'label' => __( 'Custom message', 'wahou-commerce' ),
								'rows'  => 4,
								'help'  => __( 'Shown above the order summary. Simple HTML allowed.', 'wahou-commerce' ),
							),
						),
					),
				);

			case 'payments':
				return array(
					array(
						'id'          => 'gateways',
						'title'       => __( 'Payment methods', 'wahou-commerce' ),
						'description' => __( 'Paste your API keys and click "Connect & test": Wahou checks them, creates the webhook and registers your domain for Apple Pay / Google Pay automatically.', 'wahou-commerce' ),
						'manual'      => true,
						'render'      => 'render_payments',
						'fields'      => array(
							'stripe_enabled'              => array( 'type' => 'toggle' ),
							'stripe_mode'                 => array(
								'type'    => 'segmented',
								'options' => array(
									'test' => 'test',
									'live' => 'live',
								),
							),
							'stripe_test_publishable'     => array(
								'type'   => 'publishable',
								'prefix' => 'pk_test_',
								'label'  => __( 'Publishable key (test)', 'wahou-commerce' ),
							),
							'stripe_live_publishable'     => array(
								'type'   => 'publishable',
								'prefix' => 'pk_live_',
								'label'  => __( 'Publishable key (live)', 'wahou-commerce' ),
							),
							'stripe_statement_descriptor' => array( 'type' => 'descriptor' ),
							'stripe_express'              => array( 'type' => 'toggle' ),
							'paypal_enabled'              => array( 'type' => 'toggle' ),
							'paypal_mode'                 => array(
								'type'    => 'segmented',
								'options' => array(
									'sandbox' => 'sandbox',
									'live'    => 'live',
								),
							),
							'paypal_sandbox_client_id'    => array(
								'type'      => 'text',
								'maxlength' => 200,
							),
							'paypal_live_client_id'       => array(
								'type'      => 'text',
								'maxlength' => 200,
							),
							'paypal_sandbox_webhook_id'   => array(
								'type'      => 'text',
								'maxlength' => 60,
							),
							'paypal_live_webhook_id'      => array(
								'type'      => 'text',
								'maxlength' => 60,
							),
							'paypal_paylater'             => array( 'type' => 'toggle' ),
							'bank_enabled'                => array( 'type' => 'toggle' ),
							'bank_account_holder'         => array(
								'type'      => 'text',
								'maxlength' => 120,
							),
							'bank_iban'                   => array(
								'type'  => 'iban',
								'label' => 'IBAN',
							),
							'bank_bic'                    => array(
								'type'  => 'bic',
								'label' => 'BIC / SWIFT',
							),
							'bank_name'                   => array(
								'type'      => 'text',
								'maxlength' => 120,
							),
							'bank_instructions'           => array( 'type' => 'textarea' ),
							'bank_hold_days'              => array(
								'type' => 'int',
								'min'  => 1,
								'max'  => 60,
							),
							'gateway_order'               => array( 'type' => 'gateway_order' ),
						),
					),
				);

			case 'tax':
				return array(
					array(
						'id'     => 'tax_general',
						'title'  => __( 'VAT', 'wahou-commerce' ),
						'fields' => array(
							'tax_enabled'        => array(
								'type'  => 'toggle',
								'label' => __( 'Charge VAT', 'wahou-commerce' ),
							),
							'tax_exempt'         => array(
								'type'    => 'toggle',
								'label'   => __( 'I am exempt from VAT (small business scheme)', 'wahou-commerce' ),
								'help'    => __( 'No VAT is charged and the legal exemption mention is printed on invoices.', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
							'tax_exempt_mention' => array(
								'type'        => 'text',
								'label'       => __( 'Exemption mention on invoices', 'wahou-commerce' ),
								'placeholder' => 'FR' === Settings::store_country() ? 'TVA non applicable, art. 293 B du CGI' : __( 'VAT exempt (small business scheme).', 'wahou-commerce' ),
								'maxlength'   => 200,
								'show_if'     => 'tax_exempt',
							),
							'prices_include_tax' => array(
								'type'    => 'toggle',
								'label'   => __( 'My prices include VAT', 'wahou-commerce' ),
								'help'    => __( 'Recommended for consumer sales: EU consumers must always see final prices.', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
							'tax_fixed_gross'    => array(
								'type'    => 'toggle',
								'label'   => __( 'Same price for every EU customer', 'wahou-commerce' ),
								'help'    => __( 'Your 19,90 € e-book costs 19,90 € everywhere: only the VAT share changes with the customer\'s country. Turn this off to recalculate prices from your price excluding VAT.', 'wahou-commerce' ),
								'show_if' => 'prices_include_tax',
							),
							'tax_shipping'       => array(
								'type'    => 'toggle',
								'label'   => __( 'Charge VAT on shipping costs', 'wahou-commerce' ),
								'help'    => __( 'Shipping follows the VAT rate of the goods shipped (the highest rate when the cart mixes rates).', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
							'tax_price_suffix'   => array(
								'type'    => 'toggle',
								'label'   => __( 'Show "incl. VAT" next to prices', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
						),
					),
					array(
						'id'     => 'tax_eu',
						'title'  => __( 'Selling to other EU countries', 'wahou-commerce' ),
						'fields' => array(
							'tax_oss'                => array(
								'type'    => 'toggle',
								'label'   => __( 'I am registered for the One-Stop Shop (OSS)', 'wahou-commerce' ),
								'help'    => __( 'Below 10 000 € of sales to consumers in other EU countries per calendar year (all countries and digital services combined), you may charge your own country\'s VAT. Above that threshold – or if you opted in – you must charge the VAT of your customer\'s country and declare it through the OSS (art. 59c of Directive 2006/112/EC). Turn this on as soon as you are registered.', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
							'tax_b2b_reverse_charge' => array(
								'type'    => 'toggle',
								'label'   => __( 'No VAT for EU businesses with a valid VAT number (reverse charge)', 'wahou-commerce' ),
								'help'    => __( 'The number is checked live in the European Commission\'s VIES database. Invoices get the legal reverse-charge mention.', 'wahou-commerce' ),
								'show_if' => 'tax_enabled',
							),
						),
					),
					array(
						'id'          => 'tax_rates',
						'title'       => __( 'VAT rates', 'wahou-commerce' ),
						'description' => __( 'Built-in rates for the 27 EU member states. Leave a cell empty to use the default rate; type a value only to override it.', 'wahou-commerce' ),
						'render'      => 'render_rates',
						'sanitize'    => 'sanitize_rates',
					),
				);

			case 'shipping':
				return array(
					array(
						'id'          => 'zones',
						'title'       => __( 'Shipping zones', 'wahou-commerce' ),
						'description' => __( 'Customers see the methods of the first zone that contains their country. Digital products never ask for a delivery address. Costs follow your VAT setting (prices including or excluding VAT).', 'wahou-commerce' ),
						'render'      => 'render_zones',
						'sanitize'    => 'sanitize_zones',
					),
					array(
						'id'     => 'returns',
						'title'  => __( 'Returns', 'wahou-commerce' ),
						'fields' => array(
							'return_days' => array(
								'type'  => 'int',
								'label' => __( 'Return window (days)', 'wahou-commerce' ),
								'min'   => 0,
								'max'   => 365,
								'help'  => __( 'Shown on product pages and in structured data for Google. EU consumers always have at least 14 days to withdraw from a distance purchase.', 'wahou-commerce' ),
							),
							'return_fees' => array(
								'type'    => 'segmented',
								'label'   => __( 'Return shipping costs', 'wahou-commerce' ),
								'options' => array(
									'customer' => __( 'Paid by the customer', 'wahou-commerce' ),
									'free'     => __( 'Free returns', 'wahou-commerce' ),
								),
							),
						),
					),
				);

			case 'emails':
				return array(
					array(
						'id'     => 'sender',
						'title'  => __( 'Sender', 'wahou-commerce' ),
						'fields' => array(
							'email_from_name'    => array(
								'type'        => 'text',
								'label'       => __( 'Sender name', 'wahou-commerce' ),
								'placeholder' => $store_name,
							),
							'email_from_address' => array(
								'type'        => 'email',
								'label'       => __( 'Sender email', 'wahou-commerce' ),
								'placeholder' => Settings::store_email(),
								'help'        => __( 'Use an address on your own domain (with SPF and DKIM configured) to stay out of spam folders.', 'wahou-commerce' ),
							),
							'email_logo'         => array(
								'type'  => 'image',
								'label' => __( 'Logo', 'wahou-commerce' ),
							),
							'email_footer'       => array(
								'type'  => 'textarea',
								'label' => __( 'Footer text', 'wahou-commerce' ),
								'rows'  => 2,
							),
						),
					),
					array(
						'id'     => 'notifications',
						'title'  => __( 'Notifications', 'wahou-commerce' ),
						'fields' => array(
							'email_order_confirmation' => array(
								'type'  => 'toggle',
								'label' => __( 'Order confirmation (customer, with download links)', 'wahou-commerce' ),
							),
							'email_order_shipped'      => array(
								'type'  => 'toggle',
								'label' => __( 'Order shipped (customer, with tracking)', 'wahou-commerce' ),
							),
							'email_order_refunded'     => array(
								'type'  => 'toggle',
								'label' => __( 'Refund (customer)', 'wahou-commerce' ),
							),
							'email_admin_new_order'    => array(
								'type'  => 'toggle',
								'label' => __( 'New order (you)', 'wahou-commerce' ),
							),
							'email_low_stock'          => array(
								'type'  => 'toggle',
								'label' => __( 'Low stock alert (you)', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'          => 'recovery',
						'title'       => __( 'Abandoned cart recovery', 'wahou-commerce' ),
						'description' => __( 'The cart is saved as soon as the shopper types their email at checkout. If they leave without paying, Wahou sends the reminders below with a link that restores their cart.', 'wahou-commerce' ),
						'fields'      => array(
							'recovery_enabled'         => array(
								'type'  => 'toggle',
								'label' => __( 'Send abandoned cart reminders', 'wahou-commerce' ),
							),
							'recovery_abandon_minutes' => array(
								'type'    => 'int',
								'label'   => __( 'Consider a cart abandoned after (minutes)', 'wahou-commerce' ),
								'min'     => 10,
								'max'     => 1440,
								'show_if' => 'recovery_enabled',
							),
							'recovery_consent'         => array(
								'type'    => 'segmented',
								'label'   => __( 'Consent', 'wahou-commerce' ),
								'options' => array(
									'notice'   => __( 'Information notice', 'wahou-commerce' ),
									'checkbox' => __( 'Opt-in checkbox', 'wahou-commerce' ),
								),
								'help'    => __( 'The GDPR requires at least a clear notice and an unsubscribe link in every reminder (always included). An opt-in checkbox is the most cautious choice.', 'wahou-commerce' ),
								'show_if' => 'recovery_enabled',
							),
							'recovery_retention_days'  => array(
								'type'  => 'int',
								'label' => __( 'Delete abandoned carts after (days)', 'wahou-commerce' ),
								'min'   => 7,
								'max'   => 365,
							),
						),
						'render'      => 'render_recovery_steps',
						'sanitize'    => 'sanitize_recovery_steps',
					),
					array(
						'id'     => 'review_requests',
						'title'  => __( 'Review requests', 'wahou-commerce' ),
						'fields' => array(
							'review_requests'   => array(
								'type'  => 'toggle',
								'label' => __( 'Ask customers for a review after their order', 'wahou-commerce' ),
							),
							'review_delay_days' => array(
								'type'    => 'int',
								'label'   => __( 'Days after the order is completed', 'wahou-commerce' ),
								'min'     => 1,
								'max'     => 90,
								'show_if' => 'review_requests',
							),
						),
					),
				);

			case 'design':
				return array(
					array(
						'id'          => 'brand',
						'title'       => __( 'Brand', 'wahou-commerce' ),
						'description' => __( 'Wahou adapts to your theme (fonts, colors). Pick your accent color: text contrast is computed automatically for accessibility.', 'wahou-commerce' ),
						'fields'      => array(
							'brand_color'  => array(
								'type'  => 'color',
								'label' => __( 'Accent color', 'wahou-commerce' ),
							),
							'radius'       => array(
								'type'  => 'range',
								'label' => __( 'Corner radius', 'wahou-commerce' ),
								'min'   => 0,
								'max'   => 24,
							),
							'button_style' => array(
								'type'    => 'segmented',
								'label'   => __( 'Button shape', 'wahou-commerce' ),
								'options' => array(
									'rounded' => __( 'Rounded', 'wahou-commerce' ),
									'pill'    => __( 'Pill', 'wahou-commerce' ),
									'square'  => __( 'Square', 'wahou-commerce' ),
								),
							),
						),
						'render'      => 'render_design_preview',
					),
				);

			case 'seo':
				return array(
					array(
						'id'     => 'seo',
						'title'  => __( 'Search engines', 'wahou-commerce' ),
						'fields' => array(
							'seo_schema'      => array(
								'type'  => 'toggle',
								'label' => __( 'Structured data (Product, Offer, Review, FAQ, Breadcrumb)', 'wahou-commerce' ),
								'help'  => __( 'Enables rich results: price, availability, stars and delivery details in Google.', 'wahou-commerce' ),
							),
							'seo_meta'        => array(
								'type'    => 'segmented',
								'label'   => __( 'Meta tags (title, description, Open Graph)', 'wahou-commerce' ),
								'options' => array(
									'auto' => __( 'Automatic', 'wahou-commerce' ),
									'on'   => __( 'Always', 'wahou-commerce' ),
									'off'  => __( 'Never', 'wahou-commerce' ),
								),
								'help'    => self::seo_meta_help(),
							),
							'seo_breadcrumbs' => array(
								'type'  => 'toggle',
								'label' => __( 'Breadcrumbs on product and category pages', 'wahou-commerce' ),
							),
							'seo_brand'       => array(
								'type'        => 'text',
								'label'       => __( 'Default brand', 'wahou-commerce' ),
								'placeholder' => $store_name,
								'help'        => __( 'Used when a product has no brand of its own (Google Shopping requires one).', 'wahou-commerce' ),
							),
							'seo_feed'        => array(
								'type'  => 'toggle',
								'label' => __( 'Google Merchant Center product feed', 'wahou-commerce' ),
							),
						),
					),
					array(
						'id'          => 'geo',
						'title'       => __( 'Generative engines (GEO)', 'wahou-commerce' ),
						'description' => __( 'Make your catalog easy to read, understand and recommend for AI assistants and answer engines (ChatGPT, Perplexity, Gemini, Copilot…).', 'wahou-commerce' ),
						'fields'      => array(
							'seo_llms_txt'    => array(
								'type'  => 'toggle',
								'label' => __( 'Publish /llms.txt and /llms-full.txt', 'wahou-commerce' ),
								'help'  => __( 'A plain-language summary of your store and catalog for language models.', 'wahou-commerce' ),
							),
							'seo_llms_intro'  => array(
								'type'    => 'textarea',
								'label'   => __( 'Store introduction', 'wahou-commerce' ),
								'rows'    => 3,
								'help'    => __( 'Two or three factual sentences: who you are, what you sell, for whom. It opens your llms.txt file.', 'wahou-commerce' ),
								'show_if' => 'seo_llms_txt',
							),
							'seo_markdown'    => array(
								'type'  => 'toggle',
								'label' => __( 'Markdown version of every product page (.md)', 'wahou-commerce' ),
							),
							'seo_ai_crawlers' => array(
								'type'    => 'segmented',
								'label'   => __( 'AI crawlers', 'wahou-commerce' ),
								'options' => array(
									'allow' => __( 'Allow', 'wahou-commerce' ),
									'block' => __( 'Block', 'wahou-commerce' ),
								),
								'help'    => __( 'Blocking them keeps your content out of AI answers – and your products out of their recommendations.', 'wahou-commerce' ),
							),
							'seo_abilities'   => array(
								'type'  => 'toggle',
								'label' => __( 'Expose the catalog to AI agents (WordPress Abilities API)', 'wahou-commerce' ),
								'help'  => __( 'Read-only abilities (search products, product details, store policies) usable by agents and MCP clients. Requires WordPress 6.9 or later.', 'wahou-commerce' ),
							),
						),
						'render'      => 'render_geo_urls',
					),
				);

			case 'performance':
				return array(
					array(
						'id'          => 'perf',
						'title'       => __( 'Speed', 'wahou-commerce' ),
						'description' => __( 'Wahou only loads its scripts on store pages and keeps catalog pages fully cacheable. These options trim WordPress a little more.', 'wahou-commerce' ),
						'fields'      => array(
							'perf_speculation'    => array(
								'type'    => 'select',
								'label'   => __( 'Instant page loads (speculative loading)', 'wahou-commerce' ),
								'options' => array(
									'off'          => __( 'Off', 'wahou-commerce' ),
									'conservative' => __( 'Conservative – on click', 'wahou-commerce' ),
									'moderate'     => __( 'Moderate – on hover (recommended)', 'wahou-commerce' ),
									'eager'        => __( 'Eager – as soon as possible', 'wahou-commerce' ),
								),
								'help'    => __( 'Pages are prepared in the background when a visitor is about to open them. Cart, checkout and account pages are always excluded.', 'wahou-commerce' ),
							),
							'perf_disable_emojis' => array(
								'type'  => 'toggle',
								'label' => __( 'Remove the WordPress emoji script', 'wahou-commerce' ),
								'help'  => __( 'Modern devices display emojis natively.', 'wahou-commerce' ),
							),
							'perf_disable_embeds' => array(
								'type'  => 'toggle',
								'label' => __( 'Disable WordPress embeds (oEmbed)', 'wahou-commerce' ),
								'help'  => __( 'Keep this off if you embed YouTube videos or posts in your pages.', 'wahou-commerce' ),
							),
							'perf_lazy_paypal'    => array(
								'type'  => 'toggle',
								'label' => __( 'Load PayPal only when the payment section is reached', 'wahou-commerce' ),
							),
							'perf_purge_cache'    => array(
								'type'  => 'toggle',
								'label' => __( 'Refresh the cached product page when its price or stock changes', 'wahou-commerce' ),
								'help'  => __( 'Compatible with LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround Optimizer and others.', 'wahou-commerce' ),
							),
						),
					),
				);

			case 'advanced':
				return array(
					array(
						'id'     => 'integrations',
						'title'  => __( 'Integrations', 'wahou-commerce' ),
						'fields' => array(
							'analytics_datalayer'  => array(
								'type'  => 'toggle',
								'label' => __( 'Send e-commerce events to the dataLayer (GA4, Google Tag Manager)', 'wahou-commerce' ),
								'help'  => __( 'view_item, add_to_cart, begin_checkout and purchase events. Your consent tool and Tag Manager decide what is sent.', 'wahou-commerce' ),
							),
							'rest_public_products' => array(
								'type'  => 'toggle',
								'label' => __( 'Public product API', 'wahou-commerce' ),
								'help'  => '<code>' . esc_html( rest_url( 'wahou/v1/products' ) ) . '</code>',
							),
						),
					),
					array(
						'id'     => 'maintenance',
						'title'  => __( 'Maintenance', 'wahou-commerce' ),
						'fields' => array(
							'debug_log' => array(
								'type'  => 'toggle',
								'label' => __( 'Detailed logs', 'wahou-commerce' ),
								'help'  => __( 'Errors are always logged. Turn this on temporarily to investigate a problem.', 'wahou-commerce' ),
							),
						),
						'render' => 'render_maintenance',
					),
					array(
						'id'     => 'danger',
						'title'  => __( 'Danger zone', 'wahou-commerce' ),
						'class'  => 'wahou-card--danger',
						'fields' => array(
							'delete_data_on_uninstall' => array(
								'type'  => 'toggle',
								'label' => __( 'Delete all store data when the plugin is deleted', 'wahou-commerce' ),
								'help'  => __( 'Products, orders, invoices, carts, settings and private files would be permanently erased. Keep this off unless you are sure.', 'wahou-commerce' ),
							),
						),
					),
				);
		}
		return array();
	}

	private static function seo_meta_help(): string {
		$help = __( 'Automatic: Wahou steps aside when Yoast SEO, Rank Math, SEOPress, All in One SEO or The SEO Framework is active.', 'wahou-commerce' );
		$compat = '\WahouCommerce\Compat\Compat';
		if ( class_exists( $compat ) && method_exists( $compat, 'seo_plugin_active' ) && $compat::seo_plugin_active() ) {
			$help .= ' <strong>' . esc_html__( 'An SEO plugin is active on this site.', 'wahou-commerce' ) . '</strong>';
		}
		return $help;
	}

	/* ------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'wahou-commerce' ), 403 );
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap wahou-admin wahou-settings">';
		echo '<div class="wahou-page-header">';
		echo '<h1 class="wahou-page-title">' . esc_html__( 'Settings', 'wahou-commerce' ) . '</h1>';
		echo '<a class="wahou-btn wahou-btn--ghost" href="' . esc_url( admin_url( 'admin.php?page=wahou-setup' ) ) . '"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> ' . esc_html__( 'Setup wizard', 'wahou-commerce' ) . '</a>';
		echo '</div>';
		echo '<hr class="wp-header-end">';

		self::notices();

		echo '<nav class="wahou-tabs" aria-label="' . esc_attr__( 'Settings sections', 'wahou-commerce' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="wahou-tabs__link%2$s"%3$s>%4$s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' is-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wahou-settings__form" data-wahou-settings-form>';
		wp_nonce_field( 'wahou_save_settings' );
		echo '<input type="hidden" name="action" value="wahou_save_settings">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';

		foreach ( self::sections( $tab ) as $section ) {
			self::render_section( $section );
		}

		echo '<div class="wahou-savebar">';
		echo '<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--large">' . esc_html__( 'Save changes', 'wahou-commerce' ) . '</button>';
		echo '<span class="wahou-savebar__hint" data-unsaved-hint hidden>' . esc_html__( 'You have unsaved changes.', 'wahou-commerce' ) . '</span>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wahou-commerce' ) . '</p></div>';
		}
		if ( isset( $_GET['pages_created'] ) ) {
			$count = absint( $_GET['pages_created'] );
			$text  = $count
				/* translators: %d: number of pages. */
				? sprintf( _n( '%d page created.', '%d pages created.', $count, 'wahou-commerce' ), $count )
				: __( 'All store pages already exist.', 'wahou-commerce' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
		// phpcs:enable
		$key     = 'wahou_settings_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( is_array( $notices ) ) {
			delete_transient( $key );
			foreach ( $notices as $notice ) {
				$type = in_array( $notice[0] ?? '', array( 'error', 'warning', 'success', 'info' ), true ) ? $notice[0] : 'info';
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) ( $notice[1] ?? '' ) ) . '</p></div>';
			}
		}
	}

	/**
	 * @param array<string, mixed> $section
	 */
	private static function render_section( array $section ): void {
		printf(
			'<section class="wahou-card wahou-settings__section %1$s" id="%2$s">',
			esc_attr( (string) ( $section['class'] ?? '' ) ),
			esc_attr( 'wahou-section-' . $section['id'] )
		);
		echo '<header class="wahou-card__header"><h2 class="wahou-card__title">' . esc_html( (string) $section['title'] ) . '</h2>';
		if ( ! empty( $section['description'] ) ) {
			echo '<p class="wahou-card__desc">' . Fields::kses( (string) $section['description'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</header><div class="wahou-card__body">';
		if ( empty( $section['manual'] ) ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $key => $field ) {
				echo self::render_field( (string) $key, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		if ( ! empty( $section['render'] ) && method_exists( self::class, (string) $section['render'] ) ) {
			call_user_func( array( self::class, (string) $section['render'] ) );
		}
		echo '</div></section>';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function render_field( string $key, array $field ): string {
		$name    = 'wahou[' . $key . ']';
		$id      = 'wahou-setting-' . str_replace( '_', '-', $key );
		$value   = Settings::get( $key );
		$label   = (string) ( $field['label'] ?? '' );
		$help    = (string) ( $field['help'] ?? '' );
		$attrs   = array();
		$control = '';

		if ( ! empty( $field['show_if'] ) ) {
			$attrs['data-show-if'] = (string) $field['show_if'];
		}

		switch ( $field['type'] ) {
			case 'toggle':
				$control = Fields::toggle( $name, (bool) $value, $label, array( 'id' => $id ) );
				$label   = '';
				break;
			case 'int':
				$control = Fields::number(
					$name,
					(int) $value,
					array(
						'id'  => $id,
						'min' => $field['min'] ?? null,
						'max' => $field['max'] ?? null,
					)
				);
				break;
			case 'range':
				$control = '<span class="wahou-range"><input type="range" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '" min="' . esc_attr( (string) ( $field['min'] ?? 0 ) ) . '" max="' . esc_attr( (string) ( $field['max'] ?? 100 ) ) . '" data-range><output class="wahou-range__value" for="' . esc_attr( $id ) . '">' . esc_html( (string) (int) $value ) . ' px</output></span>';
				break;
			case 'select':
				$control = Fields::select( $name, (string) $value, (array) $field['options'], array( 'id' => $id ) );
				break;
			case 'segmented':
				$control = Fields::segmented( $name, (string) $value, (array) $field['options'], array( 'label' => $label ) );
				$id      = '';
				break;
			case 'textarea':
			case 'html':
				$control = Fields::textarea(
					$name,
					is_scalar( $value ) ? (string) $value : '',
					array(
						'id'          => $id,
						'rows'        => $field['rows'] ?? 3,
						'placeholder' => $field['placeholder'] ?? '',
					)
				);
				break;
			case 'email':
				$control = Fields::input(
					$name,
					(string) $value,
					array(
						'id'          => $id,
						'type'        => 'email',
						'placeholder' => $field['placeholder'] ?? '',
					)
				);
				break;
			case 'color':
				$control = Fields::color( $name, (string) $value, array( 'id' => $id ) );
				break;
			case 'country':
				$control = Fields::select( $name, (string) $value, Countries::all(), array( 'id' => $id ) );
				break;
			case 'countries':
				$control = Fields::countries( $name, (array) $value, array( 'id' => $id ) );
				break;
			case 'time':
				$control = Fields::input(
					$name,
					(string) $value,
					array(
						'id'   => $id,
						'type' => 'time',
					)
				);
				break;
			case 'weekdays':
				$control = self::weekdays_control( $name, (array) $value );
				$id      = '';
				break;
			case 'image':
				$control = Fields::image( $name, (int) $value );
				$id      = '';
				break;
			case 'slug':
				$control = Fields::input(
					$name,
					(string) $value,
					array(
						'id'          => $id,
						'placeholder' => $field['placeholder'] ?? '',
						'attrs'       => array(
							'pattern'    => '[a-z0-9\-]*',
							'spellcheck' => 'false',
						),
					)
				);
				break;
			default:
				$control = Fields::input(
					$name,
					is_scalar( $value ) ? (string) $value : '',
					array(
						'id'          => $id,
						'placeholder' => $field['placeholder'] ?? '',
						'attrs'       => array( 'maxlength' => $field['maxlength'] ?? null ),
					)
				);
		}

		return Fields::row(
			$label,
			$control,
			$help,
			array(
				'for'   => $id,
				'attrs' => $attrs,
				'class' => 'wahou-field--' . $field['type'],
			)
		);
	}

	/**
	 * Shipping days as checkboxes (0 = Sunday … 6 = Saturday, like PHP date('w') and JS getDay()).
	 *
	 * @param int[] $days
	 */
	private static function weekdays_control( string $name, array $days ): string {
		global $wp_locale;
		$days  = array_map( 'intval', $days );
		$order = array( 1, 2, 3, 4, 5, 6, 0 );
		$html  = '<div class="wahou-weekdays">';
		$html .= '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
		foreach ( $order as $day ) {
			$id    = 'wahou-weekday-' . $day;
			$label = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day ) ) : (string) $day;
			$html .= '<input type="checkbox" class="wahou-weekdays__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $day ) . '"' . checked( in_array( $day, $days, true ), true, false ) . '>';
			$html .= '<label class="wahou-weekdays__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Custom renderers.
	 * ------------------------------------------------------------------ */

	private static function render_pages(): void {
		$labels = Pages::labels();
		$blocks = array(
			'shop'     => array( 'wahou/products', 'wahou_products' ),
			'cart'     => array( 'wahou/cart', 'wahou_cart' ),
			'checkout' => array( 'wahou/checkout', 'wahou_checkout' ),
			'account'  => array( 'wahou/account', 'wahou_account' ),
		);
		$help   = array(
			'shop'     => __( 'Your catalog.', 'wahou-commerce' ),
			'cart'     => __( 'Used when the slide-in cart is turned off and as a fallback without JavaScript.', 'wahou-commerce' ),
			'checkout' => __( 'Also shows the order confirmation and post-purchase offers.', 'wahou-commerce' ),
			'account'  => __( 'Orders, downloads and passwordless login.', 'wahou-commerce' ),
			'terms'    => __( 'Linked from the checkout. A template with the sections required in the EU is created for you: complete it before opening your store.', 'wahou-commerce' ),
			'privacy'  => __( 'Defaults to the WordPress privacy policy page.', 'wahou-commerce' ),
		);

		$missing = 0;
		foreach ( Pages::KEYS as $key ) {
			$id   = Pages::id( $key, false );
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || 'trash' === $post->post_status ) {
				$id   = 0;
				$post = null;
				if ( 'privacy' !== $key ) {
					++$missing;
				}
			}
			$dropdown = wp_dropdown_pages(
				array(
					'name'              => 'wahou[page_' . $key . ']',
					'id'                => 'wahou-setting-page-' . $key,
					'selected'          => $id,
					'show_option_none'  => __( '— Select a page —', 'wahou-commerce' ),
					'option_none_value' => '0',
					'echo'              => 0,
					'class'             => 'wahou-input wahou-select',
					'post_status'       => array( 'publish', 'private', 'draft' ),
				)
			);
			if ( '' === $dropdown ) {
				$dropdown = '<input type="hidden" name="wahou[page_' . esc_attr( $key ) . ']" value="0"><span class="wahou-muted">' . esc_html__( 'No page yet.', 'wahou-commerce' ) . '</span>';
			}
			$extra = '';
			if ( $post ) {
				$extra .= ' <span class="wahou-field__links">';
				$edit   = get_edit_post_link( $post->ID );
				if ( $edit ) {
					$extra .= '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'wahou-commerce' ) . '</a>';
				}
				$extra .= ' · <a href="' . esc_url( (string) get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'wahou-commerce' ) . '</a></span>';
				if ( isset( $blocks[ $key ] ) && ! has_block( $blocks[ $key ][0], $post ) && ! has_shortcode( (string) $post->post_content, $blocks[ $key ][1] ) ) {
					$extra .= ' ' . Fields::pill( __( 'Wahou block missing', 'wahou-commerce' ), 'warning' );
				}
				if ( 'publish' !== $post->post_status ) {
					$extra .= ' ' . Fields::pill( __( 'Not published', 'wahou-commerce' ), 'warning' );
				}
			}
			echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$labels[ $key ],
				$dropdown . $extra,
				$help[ $key ] ?? '',
				array( 'for' => 'wahou-setting-page-' . $key )
			);
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=wahou_create_pages' ), 'wahou_create_pages' );
		echo '<div class="wahou-actions">';
		echo '<a class="wahou-btn ' . ( $missing ? 'wahou-btn--primary' : 'wahou-btn--secondary' ) . '" href="' . esc_url( $url ) . '">' . esc_html__( 'Create missing pages', 'wahou-commerce' ) . '</a>';
		if ( $missing ) {
			/* translators: %d: number of missing pages. */
			echo ' <span class="wahou-muted">' . esc_html( sprintf( _n( '%d page is missing.', '%d pages are missing.', $missing, 'wahou-commerce' ), $missing ) ) . '</span>';
		}
		echo '</div>';
	}

	private static function render_payments(): void {
		echo '<div class="wahou-gateways">';
		self::render_stripe_card();
		self::render_paypal_card();
		self::render_bank_card();
		echo '</div>';
		self::render_gateway_order();
	}

	/**
	 * @param array<string, mixed>|null $status
	 */
	private static function gateway_pill( ?array $status, bool $exists ): string {
		if ( ! $exists ) {
			return '<span class="wahou-pill wahou-pill--muted" data-gateway-pill>' . esc_html__( 'Module unavailable', 'wahou-commerce' ) . '</span>';
		}
		if ( $status && ! empty( $status['connected'] ) ) {
			$test = in_array( (string) ( $status['mode'] ?? '' ), array( 'test', 'sandbox' ), true );
			return '<span class="wahou-pill wahou-pill--' . ( $test ? 'warning' : 'success' ) . '" data-gateway-pill>' . esc_html( $test ? __( 'Connected · test mode', 'wahou-commerce' ) : __( 'Connected', 'wahou-commerce' ) ) . '</span>';
		}
		return '<span class="wahou-pill wahou-pill--muted" data-gateway-pill>' . esc_html__( 'Not connected', 'wahou-commerce' ) . '</span>';
	}

	/**
	 * @param array<string, mixed>|null $status
	 */
	private static function gateway_issues( ?array $status ): string {
		$issues = $status ? array_filter( (array) ( $status['issues'] ?? array() ) ) : array();
		if ( ! $issues ) {
			return '';
		}
		$html = '<ul class="wahou-issues">';
		foreach ( $issues as $issue ) {
			$html .= '<li>' . esc_html( (string) $issue ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Password field for a secret: never prints the secret, only a masked hint.
	 */
	private static function secret_input( string $key, string $label, string $placeholder, string $role ): string {
		$constant = Settings::secret_is_constant( $key );
		$hint     = $constant ? '' : Settings::secret_hint( $key );
		$id       = 'wahou-secret-' . str_replace( '_', '-', $key );
		$control  = Fields::input(
			'wahou_secret[' . $key . ']',
			'',
			array(
				'id'          => $id,
				'type'        => 'password',
				'placeholder' => $constant ? __( 'Defined in wp-config.php', 'wahou-commerce' ) : ( '' !== $hint ? $hint : $placeholder ),
				'attrs'       => array(
					'autocomplete'   => 'new-password',
					'spellcheck'     => 'false',
					'disabled'       => $constant,
					'data-key'       => $role,
					'data-has-value' => ( $constant || '' !== $hint ) ? '1' : '0',
				),
			)
		);
		$help = $constant
			? __( 'This key is defined in wp-config.php and takes precedence.', 'wahou-commerce' )
			: ( '' !== $hint ? __( 'Saved and encrypted. Leave empty to keep it.', 'wahou-commerce' ) : '' );
		return Fields::row( $label, $control, $help, array( 'for' => $id ) );
	}

	private static function render_stripe_card(): void {
		$gateway = Gateways::get( 'stripe' );
		$status  = $gateway ? $gateway->status() : null;
		$mode    = 'live' === Settings::str( 'stripe_mode' ) ? 'live' : 'test';

		echo '<article class="wahou-card wahou-gateway-card" data-gateway="stripe" data-mode="' . esc_attr( $mode ) . '">';
		echo '<header class="wahou-gateway-card__header">';
		echo '<div><h3 class="wahou-gateway-card__title">Stripe</h3><p class="wahou-gateway-card__desc">' . esc_html__( 'Cards, Apple Pay, Google Pay, Link, SEPA Direct Debit, Bancontact, iDEAL… One integration, all local payment methods.', 'wahou-commerce' ) . '</p></div>';
		echo self::gateway_pill( $status, null !== $gateway ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</header>';
		echo '<div class="wahou-gateway-card__body">';
		echo Fields::toggle( 'wahou[stripe_enabled]', Settings::bool( 'stripe_enabled' ), __( 'Accept payments with Stripe', 'wahou-commerce' ), array( 'id' => 'wahou-setting-stripe-enabled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Mode', 'wahou-commerce' ),
			Fields::segmented(
				'wahou[stripe_mode]',
				$mode,
				array(
					'test' => __( 'Test', 'wahou-commerce' ),
					'live' => __( 'Live', 'wahou-commerce' ),
				),
				array(
					'label' => __( 'Mode', 'wahou-commerce' ),
					'attrs' => array( 'data-gateway-mode' => 'stripe' ),
				)
			),
			__( 'Test mode uses Stripe test cards (4242 4242 4242 4242) – no real money moves.', 'wahou-commerce' )
		);

		foreach ( array( 'test', 'live' ) as $m ) {
			echo '<div class="wahou-gateway-keys" data-mode-panel="' . esc_attr( $m ) . '"' . ( $m !== $mode ? ' hidden' : '' ) . '>';
			echo '<p class="wahou-gateway-keys__title">' . esc_html( 'test' === $m ? __( 'Test keys', 'wahou-commerce' ) : __( 'Live keys', 'wahou-commerce' ) ) . '</p>';
			echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Publishable key', 'wahou-commerce' ),
				Fields::input(
					'wahou[stripe_' . $m . '_publishable]',
					Settings::str( 'stripe_' . $m . '_publishable' ),
					array(
						'id'          => 'wahou-setting-stripe-' . $m . '-publishable',
						'placeholder' => 'pk_' . $m . '_…',
						'attrs'       => array(
							'autocomplete' => 'off',
							'spellcheck'   => 'false',
							'data-key'     => 'public',
						),
					)
				),
				'',
				array( 'for' => 'wahou-setting-stripe-' . $m . '-publishable' )
			);
			echo self::secret_input( 'stripe_' . $m . '_secret', __( 'Secret key', 'wahou-commerce' ), 'sk_' . $m . '_…', 'secret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::secret_input( 'stripe_' . $m . '_webhook_secret', __( 'Webhook signing secret (optional)', 'wahou-commerce' ), 'whsec_…', 'webhook' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$webhook_id = Settings::str( 'stripe_' . $m . '_webhook_id' );
			if ( '' !== $webhook_id ) {
				echo '<p class="wahou-muted wahou-gateway-keys__meta">' . esc_html__( 'Webhook', 'wahou-commerce' ) . ' : <code>' . esc_html( $webhook_id ) . '</code></p>';
			}
			echo '</div>';
		}

		self::connect_actions( 'stripe', __( 'Where are my keys? Stripe Dashboard › Developers › API keys.', 'wahou-commerce' ), 'https://dashboard.stripe.com/apikeys' );

		$account = Settings::str( 'stripe_account_name' );
		if ( '' !== $account ) {
			echo '<p class="wahou-gateway-card__account"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Account', 'wahou-commerce' ) . ' : <strong>' . esc_html( $account ) . '</strong></p>';
		}
		echo self::gateway_issues( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="wahou-grid wahou-grid--2">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Statement descriptor', 'wahou-commerce' ),
			Fields::input(
				'wahou[stripe_statement_descriptor]',
				Settings::str( 'stripe_statement_descriptor' ),
				array(
					'id'          => 'wahou-setting-stripe-descriptor',
					'placeholder' => mb_substr( strtoupper( remove_accents( Settings::store_name() ) ), 0, 22 ),
					'attrs'       => array( 'maxlength' => 22 ),
				)
			),
			__( 'Shown on your customers\' bank statements (5 to 22 characters). A recognizable name avoids disputes.', 'wahou-commerce' ),
			array( 'for' => 'wahou-setting-stripe-descriptor' )
		);
		echo '</div>';
		echo Fields::toggle( 'wahou[stripe_express]', Settings::bool( 'stripe_express' ), __( 'Express checkout buttons (Apple Pay, Google Pay, Link) at the top of the checkout', 'wahou-commerce' ), array( 'id' => 'wahou-setting-stripe-express' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></article>';
	}

	private static function render_paypal_card(): void {
		$gateway = Gateways::get( 'paypal' );
		$status  = $gateway ? $gateway->status() : null;
		$mode    = 'live' === Settings::str( 'paypal_mode' ) ? 'live' : 'sandbox';

		echo '<article class="wahou-card wahou-gateway-card" data-gateway="paypal" data-mode="' . esc_attr( $mode ) . '">';
		echo '<header class="wahou-gateway-card__header">';
		echo '<div><h3 class="wahou-gateway-card__title">PayPal</h3><p class="wahou-gateway-card__desc">' . esc_html__( 'PayPal wallet, Pay Later and guest card payments through PayPal.', 'wahou-commerce' ) . '</p></div>';
		echo self::gateway_pill( $status, null !== $gateway ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</header>';
		echo '<div class="wahou-gateway-card__body">';
		echo Fields::toggle( 'wahou[paypal_enabled]', Settings::bool( 'paypal_enabled' ), __( 'Accept payments with PayPal', 'wahou-commerce' ), array( 'id' => 'wahou-setting-paypal-enabled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Mode', 'wahou-commerce' ),
			Fields::segmented(
				'wahou[paypal_mode]',
				$mode,
				array(
					'sandbox' => __( 'Sandbox', 'wahou-commerce' ),
					'live'    => __( 'Live', 'wahou-commerce' ),
				),
				array(
					'label' => __( 'Mode', 'wahou-commerce' ),
					'attrs' => array( 'data-gateway-mode' => 'paypal' ),
				)
			)
		);

		foreach ( array( 'sandbox', 'live' ) as $m ) {
			echo '<div class="wahou-gateway-keys" data-mode-panel="' . esc_attr( $m ) . '"' . ( $m !== $mode ? ' hidden' : '' ) . '>';
			echo '<p class="wahou-gateway-keys__title">' . esc_html( 'sandbox' === $m ? __( 'Sandbox app', 'wahou-commerce' ) : __( 'Live app', 'wahou-commerce' ) ) . '</p>';
			echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Client ID', 'wahou-commerce' ),
				Fields::input(
					'wahou[paypal_' . $m . '_client_id]',
					Settings::str( 'paypal_' . $m . '_client_id' ),
					array(
						'id'    => 'wahou-setting-paypal-' . $m . '-client-id',
						'attrs' => array(
							'autocomplete' => 'off',
							'spellcheck'   => 'false',
							'data-key'     => 'public',
						),
					)
				),
				'',
				array( 'for' => 'wahou-setting-paypal-' . $m . '-client-id' )
			);
			echo self::secret_input( 'paypal_' . $m . '_secret', __( 'Secret', 'wahou-commerce' ), '', 'secret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				__( 'Webhook ID', 'wahou-commerce' ),
				Fields::input(
					'wahou[paypal_' . $m . '_webhook_id]',
					Settings::str( 'paypal_' . $m . '_webhook_id' ),
					array(
						'id'    => 'wahou-setting-paypal-' . $m . '-webhook-id',
						'attrs' => array(
							'spellcheck'   => 'false',
							'data-webhook' => '1',
						),
					)
				),
				__( 'Filled automatically when you connect.', 'wahou-commerce' ),
				array( 'for' => 'wahou-setting-paypal-' . $m . '-webhook-id' )
			);
			echo '</div>';
		}

		self::connect_actions( 'paypal', __( 'Where are my keys? PayPal Developer Dashboard › Apps & Credentials (create an app, then copy its Client ID and Secret).', 'wahou-commerce' ), 'https://developer.paypal.com/dashboard/applications' );
		echo self::gateway_issues( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Fields::toggle( 'wahou[paypal_paylater]', Settings::bool( 'paypal_paylater' ), __( 'Offer "Pay Later" (pay in installments, where PayPal offers it)', 'wahou-commerce' ), array( 'id' => 'wahou-setting-paypal-paylater' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></article>';
	}

	private static function connect_actions( string $gateway, string $help, string $link ): void {
		echo '<div class="wahou-gateway-card__actions">';
		echo '<button type="button" class="wahou-btn wahou-btn--primary" data-gateway-connect="' . esc_attr( $gateway ) . '">' . esc_html__( 'Connect & test', 'wahou-commerce' ) . '</button>';
		echo '<button type="button" class="wahou-btn wahou-btn--ghost" data-gateway-disconnect="' . esc_attr( $gateway ) . '">' . esc_html__( 'Disconnect', 'wahou-commerce' ) . '</button>';
		echo '<a class="wahou-gateway-card__help" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $help ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
		echo '</div>';
		echo '<div class="wahou-gateway-card__result" data-gateway-result role="status" aria-live="polite"></div>';
	}

	private static function render_bank_card(): void {
		$gateway = Gateways::get( 'bank' );
		echo '<article class="wahou-card wahou-gateway-card" data-gateway="bank">';
		echo '<header class="wahou-gateway-card__header">';
		echo '<div><h3 class="wahou-gateway-card__title">' . esc_html__( 'Bank transfer', 'wahou-commerce' ) . '</h3><p class="wahou-gateway-card__desc">' . esc_html__( 'No fees. The order stays on hold until you confirm the transfer was received.', 'wahou-commerce' ) . '</p></div>';
		if ( $gateway ) {
			echo $gateway->is_available() ? Fields::pill( __( 'Active', 'wahou-commerce' ), 'success' ) : Fields::pill( __( 'Inactive', 'wahou-commerce' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</header><div class="wahou-gateway-card__body">';
		echo Fields::toggle( 'wahou[bank_enabled]', Settings::bool( 'bank_enabled' ), __( 'Accept bank transfers', 'wahou-commerce' ), array( 'id' => 'wahou-setting-bank-enabled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div data-show-if="bank_enabled">';
		echo '<div class="wahou-grid wahou-grid--2">';
		$text = static function ( string $key, string $label, string $placeholder = '' ): string {
			$id = 'wahou-setting-' . str_replace( '_', '-', $key );
			return Fields::row(
				$label,
				Fields::input(
					'wahou[' . $key . ']',
					Settings::str( $key ),
					array(
						'id'          => $id,
						'placeholder' => $placeholder,
						'attrs'       => array( 'spellcheck' => 'false' ),
					)
				),
				'',
				array( 'for' => $id )
			);
		};
		echo $text( 'bank_account_holder', __( 'Account holder', 'wahou-commerce' ), Settings::str( 'store_legal_name' ) ?: Settings::store_name() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $text( 'bank_name', __( 'Bank name', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $text( 'bank_iban', 'IBAN', 'FR76 3000 6000 0112 3456 7890 189' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $text( 'bank_bic', 'BIC / SWIFT', 'AGRIFRPP' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Instructions', 'wahou-commerce' ),
			Fields::textarea(
				'wahou[bank_instructions]',
				Settings::str( 'bank_instructions' ),
				array(
					'id'          => 'wahou-setting-bank-instructions',
					'placeholder' => __( 'Please use your order number as the payment reference. Your order ships as soon as the transfer is received.', 'wahou-commerce' ),
				)
			),
			'',
			array( 'for' => 'wahou-setting-bank-instructions' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Cancel unpaid orders after (days)', 'wahou-commerce' ),
			Fields::number(
				'wahou[bank_hold_days]',
				Settings::int( 'bank_hold_days' ),
				array(
					'id'  => 'wahou-setting-bank-hold-days',
					'min' => 1,
					'max' => 60,
				)
			),
			__( 'Reserved stock is released when the order is cancelled.', 'wahou-commerce' ),
			array( 'for' => 'wahou-setting-bank-hold-days' )
		);
		echo '</div></div></article>';
	}

	/**
	 * @return array<string, string> id => label
	 */
	private static function gateway_labels(): array {
		$labels = array(
			'stripe' => 'Stripe',
			'paypal' => 'PayPal',
			'bank'   => __( 'Bank transfer', 'wahou-commerce' ),
		);
		foreach ( Gateways::all() as $id => $gateway ) {
			if ( ! isset( $labels[ $id ] ) ) {
				$labels[ $id ] = $gateway->title();
			}
		}
		return $labels;
	}

	private static function render_gateway_order(): void {
		$labels = self::gateway_labels();
		$order  = array_values( array_intersect( (array) Settings::get( 'gateway_order', array() ), array_keys( $labels ) ) );
		$order  = array_merge( $order, array_diff( array_keys( $labels ), $order ) );

		echo '<div class="wahou-sortable" data-wahou-sortable>';
		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Order at checkout', 'wahou-commerce' ) . '</h3>';
		echo '<ol class="wahou-sortable__list">';
		foreach ( $order as $id ) {
			echo '<li class="wahou-sortable__item" data-id="' . esc_attr( $id ) . '">';
			echo '<span class="wahou-sortable__label">' . esc_html( $labels[ $id ] ) . '</span>';
			echo '<input type="hidden" name="wahou[gateway_order][]" value="' . esc_attr( $id ) . '">';
			echo '<span class="wahou-sortable__actions">';
			echo '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--icon" data-move="up" aria-label="' . esc_attr__( 'Move up', 'wahou-commerce' ) . '"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
			echo '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--icon" data-move="down" aria-label="' . esc_attr__( 'Move down', 'wahou-commerce' ) . '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>';
			echo '</span></li>';
		}
		echo '</ol></div>';
	}

	/**
	 * Default rate of the built-in table (mirrors Tax::rate() without overrides).
	 *
	 * @param array<string, array<string, mixed>> $table
	 */
	private static function default_rate( array $table, string $country, string $class ): float {
		$rates = $table[ $country ] ?? null;
		if ( ! is_array( $rates ) ) {
			return 0.0;
		}
		$standard = (float) $rates['standard'];
		$reduced  = array_map( 'floatval', (array) ( $rates['reduced'] ?? array() ) );
		switch ( $class ) {
			case 'reduced':
				return $reduced ? $reduced[0] : $standard;
			case 'super_reduced':
				return isset( $rates['super_reduced'] ) ? (float) $rates['super_reduced'] : ( $reduced ? (float) min( $reduced ) : $standard );
			case 'books':
				return isset( $rates['books'] ) ? (float) $rates['books'] : $standard;
			case 'ebooks':
				return isset( $rates['ebooks'] ) ? (float) $rates['ebooks'] : ( isset( $rates['books'] ) ? (float) $rates['books'] : $standard );
		}
		return $standard;
	}

	private static function format_rate( float $rate ): string {
		$text = rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' );
		return false !== strpos( Money::to_input( 1990 ), ',' ) ? str_replace( '.', ',', $text ) : $text;
	}

	private static function render_rates(): void {
		$table     = Rates::table();
		$overrides = (array) Settings::get( 'tax_rates_overrides', array() );
		$classes   = array_diff_key( Tax::class_labels(), array( 'zero' => true ) );
		$store     = Settings::store_country();
		$countries = Countries::eu();
		if ( ! in_array( $store, $countries, true ) ) {
			array_unshift( $countries, $store );
		}

		$verified = defined( Rates::class . '::VERIFIED_AT' ) ? (string) constant( Rates::class . '::VERIFIED_AT' ) : '';
		$source   = defined( Rates::class . '::SOURCE' ) ? (string) constant( Rates::class . '::SOURCE' ) : '';
		if ( '' !== $verified ) {
			$date = mysql2date( (string) get_option( 'date_format' ), $verified );
			echo '<p class="wahou-callout wahou-callout--info"><span class="dashicons dashicons-info" aria-hidden="true"></span> ';
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'Rates checked on %s against the European Commission database.', 'wahou-commerce' ), $date ) );
			if ( '' !== $source ) {
				echo ' <a href="' . esc_url( $source ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source', 'wahou-commerce' ) . '</a>';
			}
			echo ' ' . esc_html__( 'Rates change: check them once a year.', 'wahou-commerce' ) . '</p>';
		}

		$count = 0;
		foreach ( $overrides as $classes_overridden ) {
			$count += is_array( $classes_overridden ) ? count( $classes_overridden ) : 0;
		}

		echo '<details class="wahou-details"' . ( $count ? ' open' : '' ) . '>';
		echo '<summary>' . esc_html__( 'Show the rates table', 'wahou-commerce' );
		if ( $count ) {
			/* translators: %d: number of overridden rates. */
			echo ' ' . Fields::pill( sprintf( _n( '%d rate overridden', '%d rates overridden', $count, 'wahou-commerce' ), $count ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</summary>';
		echo '<div class="wahou-table-wrap"><table class="wahou-table wahou-rates"><thead><tr><th scope="col">' . esc_html__( 'Country', 'wahou-commerce' ) . '</th>';
		foreach ( $classes as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $countries as $cc ) {
			echo '<tr' . ( $cc === $store ? ' class="is-highlighted"' : '' ) . '><th scope="row">' . esc_html( Countries::name( $cc ) ) . ' <span class="wahou-muted">' . esc_html( $cc ) . '</span></th>';
			foreach ( $classes as $class => $label ) {
				$default = self::default_rate( $table, $cc, $class );
				$value   = isset( $overrides[ $cc ][ $class ] ) && '' !== $overrides[ $cc ][ $class ] ? self::format_rate( (float) $overrides[ $cc ][ $class ] ) : '';
				printf(
					'<td><span class="wahou-input-group wahou-input-group--rate"><input type="text" inputmode="decimal" class="wahou-input wahou-input--rate%1$s" name="wahou[tax_rates_overrides][%2$s][%3$s]" value="%4$s" placeholder="%5$s" aria-label="%6$s" data-rate-default="%5$s"><span class="wahou-input-group__addon">%%</span></span></td>',
					'' !== $value ? ' is-overridden' : '',
					esc_attr( $cc ),
					esc_attr( $class ),
					esc_attr( $value ),
					esc_attr( self::format_rate( $default ) ),
					esc_attr( Countries::name( $cc ) . ' – ' . $label )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></details>';
	}

	private static function render_zones(): void {
		$zones = array();
		foreach ( Shipping::zones() as $zone ) {
			$methods = array();
			foreach ( $zone['methods'] as $method ) {
				$methods[] = array(
					'id'         => $method['id'],
					'type'       => $method['type'],
					'title'      => $method['title'],
					'cost'       => Money::to_input( (int) $method['cost'] ),
					'min_amount' => (int) $method['min_amount'] ? Money::to_input( (int) $method['min_amount'] ) : '',
					'min_days'   => (int) $method['min_days'],
					'max_days'   => (int) $method['max_days'],
					'enabled'    => (bool) $method['enabled'],
				);
			}
			$zones[] = array(
				'id'        => $zone['id'],
				'name'      => $zone['name'],
				'countries' => $zone['countries'],
				'methods'   => $methods,
			);
		}

		$names = array();
		foreach ( Countries::all() as $code => $label ) {
			$names[] = array( $code, $label );
		}

		echo '<div class="wahou-zones" data-wahou-zones>';
		echo '<input type="hidden" name="wahou_json[shipping_zones]" value="' . esc_attr( (string) wp_json_encode( $zones ) ) . '" data-zones-input>';
		echo '<script type="application/json" data-zones-countries>' . wp_json_encode( $names, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script type="application/json" data-zones-eu>' . wp_json_encode( Countries::eu() ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="wahou-zones__list" data-zones-list></div>';
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-zone-add>' . esc_html__( '+ Add a shipping zone', 'wahou-commerce' ) . '</button>';
		echo '<noscript><p class="wahou-callout wahou-callout--warning">' . esc_html__( 'JavaScript is required to edit shipping zones.', 'wahou-commerce' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $step
	 * @param int|string           $index
	 */
	private static function recovery_row( $index, array $step ): string {
		$base = 'wahou[recovery_steps][' . $index . ']';
		$id   = 'wahou-step-' . $index;
		$html = '<div class="wahou-repeater__row wahou-step" data-row>';
		$html .= '<div class="wahou-step__head"><span class="wahou-step__number" data-step-number></span>';
		$html .= '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--small" data-remove-row>' . esc_html__( 'Remove', 'wahou-commerce' ) . '</button></div>';
		$html .= '<div class="wahou-grid wahou-grid--4">';
		$html .= Fields::row(
			__( 'Send after (hours)', 'wahou-commerce' ),
			Fields::number(
				$base . '[delay_hours]',
				(int) ( $step['delay_hours'] ?? 24 ),
				array(
					'id'  => $id . '-delay',
					'min' => 1,
					'max' => 720,
				)
			),
			'',
			array( 'for' => $id . '-delay' )
		);
		$html .= Fields::row(
			__( 'Discount (%)', 'wahou-commerce' ),
			Fields::number(
				$base . '[coupon]',
				(int) ( $step['coupon'] ?? 0 ),
				array(
					'id'  => $id . '-coupon',
					'min' => 0,
					'max' => 90,
				)
			),
			__( '0 = no coupon', 'wahou-commerce' ),
			array( 'for' => $id . '-coupon' )
		);
		$html .= Fields::row(
			__( 'Coupon valid (days)', 'wahou-commerce' ),
			Fields::number(
				$base . '[coupon_days]',
				(int) ( $step['coupon_days'] ?? 0 ),
				array(
					'id'  => $id . '-days',
					'min' => 0,
					'max' => 60,
				)
			),
			'',
			array( 'for' => $id . '-days' )
		);
		$html .= '</div>';
		$html .= Fields::row(
			__( 'Subject', 'wahou-commerce' ),
			Fields::input(
				$base . '[subject]',
				(string) ( $step['subject'] ?? '' ),
				array(
					'id'          => $id . '-subject',
					'placeholder' => __( 'Default subject, translated into the customer\'s language', 'wahou-commerce' ),
					'attrs'       => array( 'maxlength' => 150 ),
				)
			),
			'',
			array( 'for' => $id . '-subject' )
		);
		return $html . '</div>';
	}

	private static function render_recovery_steps(): void {
		$steps = array_values( (array) Settings::get( 'recovery_steps', array() ) );
		echo '<div class="wahou-repeater wahou-steps" data-wahou-repeater data-max="5" data-show-if="recovery_enabled">';
		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Reminder sequence', 'wahou-commerce' ) . '</h3>';
		echo '<p class="wahou-muted">' . esc_html__( 'Tip: a first reminder within the hour, a second one the next day, and a small discount only in the last one.', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-repeater__rows" data-rows>';
		foreach ( $steps as $i => $step ) {
			echo self::recovery_row( $i, (array) $step ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		echo '<template data-template>' . self::recovery_row( '__i__', array( 'delay_hours' => 48 ) ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-add-row>' . esc_html__( '+ Add a reminder', 'wahou-commerce' ) . '</button>';
		echo '</div>';
	}

	private static function render_design_preview(): void {
		$color  = sanitize_hex_color( Settings::str( 'brand_color' ) ) ?: '#5b3df5';
		$radius = Settings::int( 'radius' );
		$style  = Settings::str( 'button_style' );
		echo '<div class="wahou-design-preview" data-design-preview data-style="' . esc_attr( $style ) . '" style="' . esc_attr( '--p-brand:' . $color . ';--p-radius:' . $radius . 'px' ) . '">';
		echo '<p class="wahou-design-preview__label">' . esc_html__( 'Preview', 'wahou-commerce' ) . '</p>';
		echo '<div class="wahou-design-preview__card">';
		echo '<div class="wahou-design-preview__img" aria-hidden="true"><span class="wahou-design-preview__badge">-20%</span></div>';
		echo '<div class="wahou-design-preview__name">' . esc_html__( 'Your best-selling product', 'wahou-commerce' ) . '</div>';
		echo '<div class="wahou-design-preview__price"><strong>' . esc_html( Money::format( 1990 ) ) . '</strong> <del>' . esc_html( Money::format( 2490 ) ) . '</del></div>';
		echo '<button type="button" class="wahou-design-preview__btn" tabindex="-1">' . esc_html__( 'Add to cart', 'wahou-commerce' ) . '</button>';
		echo '</div></div>';
	}

	private static function render_geo_urls(): void {
		$plain = '' === (string) get_option( 'permalink_structure' );
		$urls  = array(
			'llms.txt'                                       => home_url( '/llms.txt' ),
			'llms-full.txt'                                  => home_url( '/llms-full.txt' ),
			__( 'Google Merchant feed', 'wahou-commerce' )   => home_url( '/wahou-feed/google.xml' ),
			__( 'Product API (JSON)', 'wahou-commerce' )     => rest_url( 'wahou/v1/products' ),
		);
		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Your public URLs', 'wahou-commerce' ) . '</h3>';
		if ( $plain ) {
			echo '<p class="wahou-callout wahou-callout--warning">' . esc_html__( 'Enable pretty permalinks (Settings › Permalinks) for these URLs to work.', 'wahou-commerce' ) . '</p>';
		}
		echo '<ul class="wahou-urls">';
		foreach ( $urls as $label => $url ) {
			echo '<li class="wahou-urls__item"><span class="wahou-urls__label">' . esc_html( (string) $label ) . '</span>';
			echo '<a class="wahou-urls__link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $url ) . '</code></a>';
			echo '<button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--small" data-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'wahou-commerce' ) . '</button></li>';
		}
		echo '</ul>';
		echo '<p class="wahou-muted">' . esc_html__( 'Submit the Google feed in Merchant Center › Products › Feeds (scheduled fetch, daily).', 'wahou-commerce' ) . '</p>';
	}

	private static function render_maintenance(): void {
		self::render_logs();
		self::render_system();
		echo '<div class="wahou-actions"><a class="wahou-btn wahou-btn--secondary" href="' . esc_url( admin_url( 'admin.php?page=wahou-setup' ) ) . '">' . esc_html__( 'Run the setup wizard again', 'wahou-commerce' ) . '</a></div>';
	}

	private static function render_logs(): void {
		$files = Logger::files();
		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Logs', 'wahou-commerce' ) . '</h3>';
		if ( ! $files ) {
			echo '<p class="wahou-muted">' . esc_html__( 'No log entries yet.', 'wahou-commerce' ) . '</p>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';
		$current  = $files[0];
		foreach ( $files as $file ) {
			if ( basename( $file ) === $selected ) {
				$current = $file;
			}
		}
		echo '<div class="wahou-logs__bar"><label for="wahou-log-file">' . esc_html__( 'File', 'wahou-commerce' ) . '</label> ';
		echo '<select id="wahou-log-file" class="wahou-input wahou-select" data-log-select>';
		foreach ( array_slice( $files, 0, 60 ) as $file ) {
			$base = basename( $file );
			// Hide the random suffix that protects file names.
			$label = (string) preg_replace( '/-[a-f0-9]{12}\.log$/', '.log', $base );
			echo '<option value="' . esc_url( self::url( 'advanced', array( 'log' => $base ) ) ) . '"' . selected( $file, $current, false ) . '>' . esc_html( $label . ' – ' . size_format( (int) filesize( $file ) ) ) . '</option>';
		}
		echo '</select></div>';
		echo '<pre class="wahou-logs" tabindex="0">' . esc_html( self::tail( $current, 200 ) ) . '</pre>';
	}

	private static function tail( string $file, int $lines ): string {
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 ) {
			return '';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			return '';
		}
		$read = min( $size, 131072 );
		fseek( $handle, -$read, SEEK_END );
		$data = (string) fread( $handle, $read );
		fclose( $handle );
		// phpcs:enable
		$rows = explode( "\n", rtrim( $data ) );
		if ( $read < $size ) {
			array_shift( $rows );
		}
		return implode( "\n", array_slice( $rows, -$lines ) );
	}

	private static function render_system(): void {
		$cron_next = wp_next_scheduled( 'wahou_cron_frequent' );
		$rows      = array(
			array( 'Wahou Commerce', WAHOU_VERSION, 'ok' ),
			array( 'WordPress', (string) get_bloginfo( 'version' ), 'ok' ),
			array( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.2', '>=' ) ? 'ok' : 'warning' ),
			array( 'HTTPS', Request::is_https() ? __( 'Yes', 'wahou-commerce' ) : __( 'No – required for live payments', 'wahou-commerce' ), Request::is_https() ? 'ok' : 'error' ),
			array( __( 'Pretty permalinks', 'wahou-commerce' ), '' !== (string) get_option( 'permalink_structure' ) ? __( 'Yes', 'wahou-commerce' ) : __( 'No', 'wahou-commerce' ), '' !== (string) get_option( 'permalink_structure' ) ? 'ok' : 'warning' ),
			array(
				__( 'Scheduled tasks', 'wahou-commerce' ),
				( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'WP-Cron disabled: make sure a server cron calls wp-cron.php.', 'wahou-commerce' ) . ' ' : '' )
					/* translators: %s: date and time. */
					. ( $cron_next ? sprintf( __( 'Next run: %s', 'wahou-commerce' ), wp_date( (string) get_option( 'time_format' ), $cron_next ) ) : __( 'Not scheduled', 'wahou-commerce' ) ),
				$cron_next ? 'ok' : 'warning',
			),
			array( __( 'PHP intl extension', 'wahou-commerce' ), class_exists( '\NumberFormatter' ) ? __( 'Yes', 'wahou-commerce' ) : __( 'Missing – prices and country names use a basic format', 'wahou-commerce' ), class_exists( '\NumberFormatter' ) ? 'ok' : 'warning' ),
			array( __( 'Persistent object cache', 'wahou-commerce' ), wp_using_ext_object_cache() ? __( 'Yes', 'wahou-commerce' ) : __( 'No (recommended for large catalogs)', 'wahou-commerce' ), wp_using_ext_object_cache() ? 'ok' : 'info' ),
			array( __( 'Maximum upload size', 'wahou-commerce' ), size_format( wp_max_upload_size() ), 'info' ),
		);
		echo '<h3 class="wahou-subtitle">' . esc_html__( 'System status', 'wahou-commerce' ) . '</h3>';
		echo '<div class="wahou-table-wrap"><table class="wahou-table wahou-system"><tbody>';
		foreach ( $rows as $row ) {
			$icon = array(
				'ok'      => 'dashicons-yes-alt',
				'warning' => 'dashicons-warning',
				'error'   => 'dashicons-dismiss',
				'info'    => 'dashicons-info-outline',
			)[ $row[2] ];
			echo '<tr class="is-' . esc_attr( $row[2] ) . '"><th scope="row">' . esc_html( $row[0] ) . '</th><td><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------
	 * Saving.
	 * ------------------------------------------------------------------ */

	public static function save(): void {
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wahou-commerce' ), 403 );
		}
		check_admin_referer( 'wahou_save_settings' );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'general';
		}
		// Sanitized per field below.
		$raw     = isset( $_POST['wahou'] ) && is_array( $_POST['wahou'] ) ? wp_unslash( $_POST['wahou'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values  = array();
		$notices = array();

		foreach ( self::sections( $tab ) as $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $key => $field ) {
				$values[ $key ] = self::sanitize_field( (string) $key, $field, $raw, $notices );
			}
			if ( ! empty( $section['sanitize'] ) && method_exists( self::class, (string) $section['sanitize'] ) ) {
				$values = array_merge( $values, (array) call_user_func_array( array( self::class, (string) $section['sanitize'] ), array( $raw, &$notices ) ) );
			}
		}

		if ( 'payments' === $tab ) {
			self::save_secrets( $notices );
		}

		$before = array(
			'product_base'  => Settings::str( 'product_base' ),
			'category_base' => Settings::str( 'category_base' ),
		);
		Settings::update( $values );

		if ( in_array( $tab, array( 'general', 'pages', 'seo' ), true ) ) {
			update_option( 'wahou_flush_rewrite', 1, true );
		}
		if ( 'general' === $tab && ( $before['product_base'] !== Settings::str( 'product_base' ) || $before['category_base'] !== Settings::str( 'category_base' ) ) ) {
			$notices[] = array( 'warning', __( 'Your product URLs changed. Set up redirects for links already shared or indexed.', 'wahou-commerce' ) );
		}

		if ( $notices ) {
			set_transient( 'wahou_settings_notices_' . get_current_user_id(), $notices, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url( $tab, array( 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * @param array<string, mixed>              $field
	 * @param array<string, mixed>              $raw
	 * @param array<int, array{0:string,1:string}> $notices
	 * @return mixed
	 */
	private static function sanitize_field( string $key, array $field, array $raw, array &$notices ) {
		$value    = $raw[ $key ] ?? null;
		$defaults = Settings::defaults();
		$default  = $defaults[ $key ] ?? null;
		$label    = (string) ( $field['label'] ?? $key );
		$scalar   = is_scalar( $value ) ? trim( (string) $value ) : '';

		switch ( $field['type'] ) {
			case 'toggle':
				return ! empty( $value );

			case 'int':
			case 'range':
				$int = (int) $scalar;
				if ( isset( $field['min'] ) ) {
					$int = max( (int) $field['min'], $int );
				}
				if ( isset( $field['max'] ) ) {
					$int = min( (int) $field['max'], $int );
				}
				return $int;

			case 'select':
			case 'segmented':
				if ( ! array_key_exists( $scalar, (array) $field['options'] ) ) {
					return Settings::get( $key, $default );
				}
				return 'int' === ( $field['cast'] ?? '' ) ? (int) $scalar : $scalar;

			case 'email':
				if ( '' === $scalar ) {
					return '';
				}
				$email = sanitize_email( $scalar );
				if ( ! is_email( $email ) ) {
					/* translators: %s: field label. */
					$notices[] = array( 'error', sprintf( __( '%s: this email address is not valid. The previous value was kept.', 'wahou-commerce' ), $label ) );
					return Settings::get( $key, $default );
				}
				return $email;

			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );

			case 'html':
				return wp_kses_post( is_scalar( $value ) ? (string) $value : '' );

			case 'color':
				$color = sanitize_hex_color( $scalar );
				return $color ? strtolower( $color ) : $default;

			case 'money':
				return max( 0, Money::to_minor( $scalar ) );

			case 'country':
				$code = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $scalar ) );
				return Countries::exists( $code ) ? $code : Settings::get( $key, $default );

			case 'countries':
				$codes = array();
				foreach ( (array) $value as $code ) {
					$code = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', is_scalar( $code ) ? (string) $code : '' ) );
					if ( '' !== $code && Countries::exists( $code ) ) {
						$codes[] = $code;
					}
				}
				return array_values( array_unique( $codes ) );

			case 'page':
				$id = absint( $scalar );
				return ( $id && 'page' === get_post_type( $id ) ) ? $id : 0;

			case 'slug':
				return sanitize_title( $scalar );

			case 'time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $scalar ) ? $scalar : $default;

			case 'weekdays':
				$days = array();
				foreach ( (array) $value as $day ) {
					if ( is_scalar( $day ) && '' !== (string) $day && ctype_digit( (string) $day ) && (int) $day <= 6 ) {
						$days[] = (int) $day;
					}
				}
				$days = array_values( array_unique( $days ) );
				sort( $days );
				return $days;

			case 'image':
				$id = absint( $scalar );
				return ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;

			case 'gateway_order':
				$known = array_keys( self::gateway_labels() );
				$order = array();
				foreach ( (array) $value as $id ) {
					$id = sanitize_key( is_scalar( $id ) ? (string) $id : '' );
					if ( in_array( $id, $known, true ) && ! in_array( $id, $order, true ) ) {
						$order[] = $id;
					}
				}
				return array_values( array_merge( $order, array_diff( $known, $order ) ) );

			case 'publishable':
				$key_value = (string) preg_replace( '/\s+/', '', $scalar );
				if ( '' !== $key_value && 0 !== strpos( $key_value, (string) $field['prefix'] ) ) {
					/* translators: 1: field label, 2: expected prefix. */
					$notices[] = array( 'error', sprintf( __( '%1$s must start with %2$s. The previous value was kept.', 'wahou-commerce' ), $label, $field['prefix'] ) );
					return Settings::get( $key, $default );
				}
				return sanitize_text_field( $key_value );

			case 'descriptor':
				$descriptor = sanitize_text_field( $scalar );
				$descriptor = (string) preg_replace( '/[<>\\\\\'"*]/', '', remove_accents( $descriptor ) );
				$descriptor = trim( mb_substr( $descriptor, 0, 22 ) );
				if ( '' !== $descriptor && ( mb_strlen( $descriptor ) < 5 || ! preg_match( '/[A-Za-z]/', $descriptor ) ) ) {
					$notices[] = array( 'warning', __( 'The statement descriptor needs 5 to 22 characters, including at least one letter. Stripe will use your account default.', 'wahou-commerce' ) );
					return '';
				}
				return $descriptor;

			case 'iban':
				$iban = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $scalar ) );
				if ( '' !== $iban && ! self::iban_is_valid( $iban ) ) {
					$notices[] = array( 'warning', __( 'The IBAN does not look valid (checksum error). Please double-check it.', 'wahou-commerce' ) );
				}
				return $iban;

			case 'bic':
				$bic = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $scalar ) );
				if ( '' !== $bic && ! preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic ) ) {
					$notices[] = array( 'warning', __( 'The BIC does not look valid (8 or 11 characters). Please double-check it.', 'wahou-commerce' ) );
				}
				return $bic;

			default:
				$text = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				if ( ! empty( $field['maxlength'] ) ) {
					$text = mb_substr( $text, 0, (int) $field['maxlength'] );
				}
				return $text;
		}
	}

	private static function iban_is_valid( string $iban ): bool {
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return false;
		}
		$moved   = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric = '';
		foreach ( str_split( $moved ) as $char ) {
			$numeric .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}
		$remainder = 0;
		foreach ( str_split( $numeric, 7 ) as $chunk ) {
			$remainder = (int) ( $remainder . $chunk ) % 97;
		}
		return 1 === $remainder;
	}

	/**
	 * @param array<int, array{0:string,1:string}> $notices
	 */
	private static function save_secrets( array &$notices ): void {
		$raw = isset( $_POST['wahou_secret'] ) && is_array( $_POST['wahou_secret'] ) ? wp_unslash( $_POST['wahou_secret'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Nonce checked in save().
		foreach ( self::SECRETS as $key => $pattern ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( sanitize_text_field( (string) $raw[ $key ] ) ) : '';
			if ( '' === $value || Settings::secret_is_constant( $key ) ) {
				continue;
			}
			if ( ! preg_match( $pattern, $value ) ) {
				/* translators: %s: name of the key. */
				$notices[] = array( 'error', sprintf( __( 'The value entered for "%s" does not look valid and was not saved.', 'wahou-commerce' ), self::secret_label( $key ) ) );
				continue;
			}
			Settings::set_secret( $key, $value );
			/* translators: %s: name of the key. */
			$notices[] = array( 'success', sprintf( __( '%s saved. Click "Connect & test" to check it.', 'wahou-commerce' ), self::secret_label( $key ) ) );
		}
	}

	private static function secret_label( string $key ): string {
		$labels = array(
			'stripe_test_secret'         => __( 'Stripe secret key (test)', 'wahou-commerce' ),
			'stripe_live_secret'         => __( 'Stripe secret key (live)', 'wahou-commerce' ),
			'stripe_test_webhook_secret' => __( 'Stripe webhook secret (test)', 'wahou-commerce' ),
			'stripe_live_webhook_secret' => __( 'Stripe webhook secret (live)', 'wahou-commerce' ),
			'paypal_sandbox_secret'      => __( 'PayPal secret (sandbox)', 'wahou-commerce' ),
			'paypal_live_secret'         => __( 'PayPal secret (live)', 'wahou-commerce' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * @param array<string, mixed>              $raw
	 * @param array<int, array{0:string,1:string}> $notices
	 * @return array<string, mixed>
	 */
	private static function sanitize_rates( array $raw, array &$notices ): array {
		$table = Rates::table();
		$input = isset( $raw['tax_rates_overrides'] ) && is_array( $raw['tax_rates_overrides'] ) ? $raw['tax_rates_overrides'] : array();
		$out   = array();
		foreach ( $input as $country => $classes ) {
			$country = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $country ) );
			if ( ! Countries::exists( $country ) || ! is_array( $classes ) ) {
				continue;
			}
			foreach ( $classes as $class => $value ) {
				$class = (string) $class;
				if ( 'zero' === $class || ! in_array( $class, Tax::CLASSES, true ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = trim( str_replace( array( ',', '%', ' ' ), array( '.', '', '' ), (string) $value ) );
				if ( '' === $value ) {
					continue;
				}
				if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 100 ) {
					/* translators: 1: country, 2: value. */
					$notices[] = array( 'error', sprintf( __( 'Invalid VAT rate for %1$s: "%2$s". It was ignored.', 'wahou-commerce' ), Countries::name( $country ), $value ) );
					continue;
				}
				$rate = round( (float) $value, 3 );
				if ( abs( $rate - self::default_rate( $table, $country, $class ) ) < 0.0001 ) {
					continue;
				}
				$out[ $country ][ $class ] = $rate;
			}
		}
		return array( 'tax_rates_overrides' => $out );
	}

	/**
	 * @param array<string, mixed>              $raw
	 * @param array<int, array{0:string,1:string}> $notices
	 * @return array<string, mixed>
	 */
	private static function sanitize_zones( array $raw, array &$notices ): array {
		$json = isset( $_POST['wahou_json']['shipping_zones'] ) ? wp_unslash( $_POST['wahou_json']['shipping_zones'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Decoded and sanitized below; nonce checked in save().
		if ( null === $json ) {
			return array();
		}
		$zones = json_decode( (string) $json, true );
		if ( ! is_array( $zones ) ) {
			$notices[] = array( 'error', __( 'The shipping zones could not be read and were not saved.', 'wahou-commerce' ) );
			return array();
		}

		$out      = array();
		$zone_ids = array();
		foreach ( array_slice( $zones, 0, 50 ) as $zone ) {
			if ( ! is_array( $zone ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $zone['id'] ?? '' ) );
			if ( '' === $id || isset( $zone_ids[ $id ] ) ) {
				$id = 'z' . strtolower( wp_generate_password( 6, false ) );
			}
			$zone_ids[ $id ] = true;
			$name            = mb_substr( sanitize_text_field( (string) ( $zone['name'] ?? '' ) ), 0, 80 );

			$countries = array();
			foreach ( (array) ( $zone['countries'] ?? array() ) as $code ) {
				$code = strtoupper( is_scalar( $code ) ? trim( (string) $code ) : '' );
				if ( '*' === $code || ( preg_match( '/^[A-Z]{2}$/', $code ) && Countries::exists( $code ) ) ) {
					$countries[] = $code;
				}
			}
			$countries = array_values( array_unique( $countries ) );

			$methods    = array();
			$method_ids = array();
			foreach ( array_slice( (array) ( $zone['methods'] ?? array() ), 0, 10 ) as $method ) {
				if ( ! is_array( $method ) ) {
					continue;
				}
				$type = in_array( $method['type'] ?? '', array( 'flat', 'free', 'pickup' ), true ) ? (string) $method['type'] : 'flat';
				$mid  = sanitize_key( (string) ( $method['id'] ?? '' ) );
				if ( '' === $mid || isset( $method_ids[ $mid ] ) ) {
					$mid = $type . '_' . strtolower( wp_generate_password( 4, false ) );
				}
				$method_ids[ $mid ] = true;
				$min_days           = max( 0, min( 365, (int) ( $method['min_days'] ?? 0 ) ) );
				$max_days           = max( $min_days, min( 365, (int) ( $method['max_days'] ?? 0 ) ) );
				$methods[]          = array(
					'id'         => $mid,
					'type'       => $type,
					'title'      => mb_substr( sanitize_text_field( (string) ( $method['title'] ?? '' ) ), 0, 80 ),
					'cost'       => 'free' === $type ? 0 : max( 0, Money::to_minor( (string) ( $method['cost'] ?? '' ) ) ),
					'min_amount' => 'free' === $type ? max( 0, Money::to_minor( (string) ( $method['min_amount'] ?? '' ) ) ) : 0,
					'min_days'   => $min_days,
					'max_days'   => $max_days,
					'enabled'    => ! empty( $method['enabled'] ),
				);
			}

			if ( ! $countries ) {
				/* translators: %s: zone name. */
				$notices[] = array( 'warning', sprintf( __( 'The zone "%s" has no country: customers will never see it.', 'wahou-commerce' ), '' !== $name ? $name : $id ) );
			}
			if ( ! $methods ) {
				/* translators: %s: zone name. */
				$notices[] = array( 'warning', sprintf( __( 'The zone "%s" has no delivery method: customers in this zone cannot order physical products.', 'wahou-commerce' ), '' !== $name ? $name : $id ) );
			}
			$out[] = array(
				'id'        => $id,
				'name'      => $name,
				'countries' => $countries,
				'methods'   => $methods,
			);
		}
		return array( 'shipping_zones' => $out );
	}

	/**
	 * @param array<string, mixed>              $raw
	 * @param array<int, array{0:string,1:string}> $notices
	 * @return array<string, mixed>
	 */
	private static function sanitize_recovery_steps( array $raw, array &$notices ): array {
		$input = isset( $raw['recovery_steps'] ) && is_array( $raw['recovery_steps'] ) ? $raw['recovery_steps'] : array();
		$steps = array();
		foreach ( $input as $index => $step ) {
			if ( '__i__' === (string) $index || ! is_array( $step ) ) {
				continue;
			}
			$steps[] = array(
				'delay_hours' => max( 1, min( 720, (int) ( $step['delay_hours'] ?? 1 ) ) ),
				'subject'     => mb_substr( sanitize_text_field( (string) ( $step['subject'] ?? '' ) ), 0, 150 ),
				'coupon'      => max( 0, min( 90, (int) ( $step['coupon'] ?? 0 ) ) ),
				'coupon_days' => max( 0, min( 60, (int) ( $step['coupon_days'] ?? 0 ) ) ),
			);
		}
		usort( $steps, static fn( $a, $b ) => $a['delay_hours'] <=> $b['delay_hours'] );
		if ( count( $steps ) > 5 ) {
			$notices[] = array( 'warning', __( 'Only the first 5 reminders were kept.', 'wahou-commerce' ) );
		}
		foreach ( $steps as $step ) {
			if ( $step['coupon'] > 0 && 0 === $step['coupon_days'] ) {
				$notices[] = array( 'info', __( 'Tip: give reminder coupons a validity period (e.g. 3 days) to create urgency.', 'wahou-commerce' ) );
				break;
			}
		}
		return array( 'recovery_steps' => array_slice( $steps, 0, 5 ) );
	}

	/**
	 * admin-post.php?action=wahou_create_pages
	 */
	public static function create_pages(): void {
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wahou-commerce' ), 403 );
		}
		check_admin_referer( 'wahou_create_pages' );

		$before = array();
		foreach ( Pages::KEYS as $key ) {
			$id             = Settings::int( 'page_' . $key );
			$before[ $key ] = ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) ? $id : 0;
		}
		$created = Install::create_pages();
		$count   = 0;
		foreach ( $created as $key => $id ) {
			if ( (int) $id !== (int) ( $before[ $key ] ?? 0 ) ) {
				++$count;
			}
		}
		wp_safe_redirect( self::url( 'pages', array( 'pages_created' => $count ) ) );
		exit;
	}
}
