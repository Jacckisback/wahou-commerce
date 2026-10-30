<?php
/**
 * Shared admin field renderers and the product search endpoint.
 *
 * Renderers return escaped HTML strings so they can be composed. Every form
 * row uses the same markup in settings and editors:
 *
 *   <div class="wahou-field">
 *     <label class="wahou-field__label">…</label>
 *     <div class="wahou-field__control">…</div>
 *     <p class="wahou-field__help">…</p>
 *   </div>
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class Fields {

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'wp_ajax_wahou_search_products', array( self::class, 'ajax_search_products' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_base' ), 5 );
	}

	/* ------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the current admin screen belongs to Wahou Commerce.
	 */
	public static function is_wahou_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'wahou' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 0 === strpos( $page, 'wahou' );
	}

	/**
	 * Base admin styles on every Wahou screen.
	 */
	public static function enqueue_base(): void {
		if ( self::is_wahou_screen() ) {
			wp_enqueue_style( 'wahou-admin', WAHOU_URL . 'assets/css/admin.css', array(), WAHOU_VERSION );
		}
	}

	/**
	 * Script used by the product, coupon and offer editors.
	 */
	public static function enqueue_editor(): void {
		wp_enqueue_style( 'wahou-admin', WAHOU_URL . 'assets/css/admin.css', array(), WAHOU_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'wahou-editors', WAHOU_URL . 'assets/js/product-editor.js', array(), WAHOU_VERSION, array( 'in_footer' => true ) );
		wp_localize_script( 'wahou-editors', 'wahouAdmin', self::script_data() );
	}

	/**
	 * Data shared by admin scripts (window.wahouAdmin).
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		$sample = Money::to_input( 1990 );
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonces'    => array(
				'search'  => wp_create_nonce( 'wahou_search_products' ),
				'upload'  => wp_create_nonce( 'wahou_upload_file' ),
				'connect' => wp_create_nonce( 'wahou_gateway_connect' ),
			),
			'currency'  => array(
				'code'     => Settings::currency(),
				'symbol'   => Money::symbol(),
				'decimal'  => false !== strpos( $sample, ',' ) ? ',' : '.',
				'decimals' => Money::decimals(),
			),
			'eu'        => Countries::eu(),
			'maxUpload' => (int) wp_max_upload_size(),
			'maxUploadHuman' => size_format( wp_max_upload_size() ),
			'i18n'      => array(
				'search'            => __( 'Search a product…', 'wahou-commerce' ),
				'noResults'         => __( 'No product found.', 'wahou-commerce' ),
				'loading'           => __( 'Loading…', 'wahou-commerce' ),
				'remove'            => __( 'Remove', 'wahou-commerce' ),
				'addCountry'        => __( 'Add a country…', 'wahou-commerce' ),
				'addEu'             => __( '+ EU-27', 'wahou-commerce' ),
				'addWorld'          => __( '+ All other countries', 'wahou-commerce' ),
				'world'             => __( 'All other countries', 'wahou-commerce' ),
				'clear'             => __( 'Clear', 'wahou-commerce' ),
				'zoneName'          => __( 'Zone name', 'wahou-commerce' ),
				'newZone'           => __( 'New zone', 'wahou-commerce' ),
				'removeZone'        => __( 'Remove zone', 'wahou-commerce' ),
				'confirmRemoveZone' => __( 'Remove this shipping zone?', 'wahou-commerce' ),
				'countries'         => __( 'Countries', 'wahou-commerce' ),
				'methods'           => __( 'Delivery methods', 'wahou-commerce' ),
				'addMethod'         => __( '+ Add a method', 'wahou-commerce' ),
				'methodType'        => __( 'Type', 'wahou-commerce' ),
				'methodFlat'        => __( 'Flat rate', 'wahou-commerce' ),
				'methodFree'        => __( 'Free shipping', 'wahou-commerce' ),
				'methodPickup'      => __( 'Local pickup', 'wahou-commerce' ),
				'methodTitle'       => __( 'Name shown to customers', 'wahou-commerce' ),
				'optional'          => __( 'Optional', 'wahou-commerce' ),
				'cost'              => __( 'Cost', 'wahou-commerce' ),
				'freeFrom'          => __( 'Free from', 'wahou-commerce' ),
				'freeFromHelp'      => __( 'Order amount needed (0 = always free)', 'wahou-commerce' ),
				'days'              => __( 'Delivery time (days)', 'wahou-commerce' ),
				'daysMin'           => __( 'Min', 'wahou-commerce' ),
				'daysMax'           => __( 'Max', 'wahou-commerce' ),
				'enabled'           => __( 'Enabled', 'wahou-commerce' ),
				'noZones'           => __( 'No shipping zone yet. Physical products cannot be ordered until you add one.', 'wahou-commerce' ),
				'noCountries'       => __( 'No country selected: this zone is never used.', 'wahou-commerce' ),
				'connect'           => __( 'Connect & test', 'wahou-commerce' ),
				'connecting'        => __( 'Connecting…', 'wahou-commerce' ),
				'connected'         => __( 'Connected', 'wahou-commerce' ),
				'connectedTest'     => __( 'Connected · test mode', 'wahou-commerce' ),
				'notConnected'      => __( 'Not connected', 'wahou-commerce' ),
				'disconnect'        => __( 'Disconnect', 'wahou-commerce' ),
				'disconnected'      => __( 'Disconnected.', 'wahou-commerce' ),
				'confirmDisconnect' => __( 'Disconnect this payment method? Customers will no longer be able to pay with it in this mode.', 'wahou-commerce' ),
				'enterKeys'         => __( 'Enter your keys first.', 'wahou-commerce' ),
				'genericError'      => __( 'Something went wrong. Please try again.', 'wahou-commerce' ),
				'account'           => __( 'Account', 'wahou-commerce' ),
				'webhook'           => __( 'Webhook', 'wahou-commerce' ),
				'applePay'          => __( 'Apple Pay / Google Pay domain', 'wahou-commerce' ),
				'unsaved'           => __( 'You have unsaved changes.', 'wahou-commerce' ),
				'copied'            => __( 'Copied!', 'wahou-commerce' ),
				'copy'              => __( 'Copy', 'wahou-commerce' ),
				'moveUp'            => __( 'Move up', 'wahou-commerce' ),
				'moveDown'          => __( 'Move down', 'wahou-commerce' ),
				'chooseImage'       => __( 'Choose an image', 'wahou-commerce' ),
				'useImage'          => __( 'Use this image', 'wahou-commerce' ),
				'chooseImages'      => __( 'Add product images', 'wahou-commerce' ),
				'addToGallery'      => __( 'Add to gallery', 'wahou-commerce' ),
				'removeImage'       => __( 'Remove image', 'wahou-commerce' ),
				'attributeName'     => __( 'Attribute', 'wahou-commerce' ),
				'attributeNamePh'   => __( 'e.g. Size', 'wahou-commerce' ),
				'attributeValues'   => __( 'Values (comma separated)', 'wahou-commerce' ),
				'attributeValuesPh' => __( 'e.g. S, M, L', 'wahou-commerce' ),
				'confirmGenerate'   => __( 'Regenerate the variant list? Existing variants with the same options keep their prices and stock.', 'wahou-commerce' ),
				'noVariants'        => __( 'Add at least one attribute with values, then click "Generate variants".', 'wahou-commerce' ),
				'variant'           => __( 'Variant', 'wahou-commerce' ),
				'price'             => __( 'Price', 'wahou-commerce' ),
				'salePrice'         => __( 'Sale price', 'wahou-commerce' ),
				'sku'               => __( 'SKU', 'wahou-commerce' ),
				'stock'             => __( 'Stock', 'wahou-commerce' ),
				'image'             => __( 'Image', 'wahou-commerce' ),
				'samePrice'         => __( 'Product price', 'wahou-commerce' ),
				'notTracked'        => __( 'Not tracked', 'wahou-commerce' ),
				'variantsCount'     => __( '%d variants', 'wahou-commerce' ),
				'tooManyVariants'   => __( 'Too many combinations (maximum 200). Reduce the number of values.', 'wahou-commerce' ),
				'uploading'         => __( 'Uploading… %d%', 'wahou-commerce' ),
				'uploadFailed'      => __( 'The upload failed.', 'wahou-commerce' ),
				'fileTooBig'        => __( 'This file is larger than the maximum upload size of your server (%s). Host it elsewhere and add its URL instead.', 'wahou-commerce' ),
				'urlPrompt'         => __( 'File URL (https://…)', 'wahou-commerce' ),
				'privateFile'       => __( 'Private file', 'wahou-commerce' ),
				'externalFile'      => __( 'External link', 'wahou-commerce' ),
				'characters'        => __( '%1$d / %2$d characters', 'wahou-commerce' ),
				'enterCode'         => __( 'Coupon code', 'wahou-commerce' ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Low-level helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Build an HTML attribute string. true => boolean attribute, null/false => omitted.
	 *
	 * @param array<string, mixed> $attrs
	 */
	public static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}
			if ( true === $value ) {
				$out .= ' ' . esc_attr( (string) $key );
				continue;
			}
			$out .= sprintf( ' %s="%s"', esc_attr( (string) $key ), esc_attr( is_scalar( $value ) ? (string) $value : '' ) );
		}
		return $out;
	}

	/**
	 * HTML id from a field name: wahou[store_name] => wahou-store-name.
	 */
	public static function id( string $name ): string {
		$id = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', $name ) );
		return trim( $id, '-' );
	}

	/**
	 * Tags allowed in labels and help texts.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function inline_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
			'span'   => array( 'class' => true ),
		);
	}

	public static function kses( string $html ): string {
		return wp_kses( $html, self::inline_html() );
	}

	/* ------------------------------------------------------------------
	 * Layout.
	 * ------------------------------------------------------------------ */

	/**
	 * Form row: label + control + help.
	 *
	 * @param array<string, mixed> $args for, class, attrs.
	 */
	public static function row( string $label, string $control, string $help = '', array $args = array() ): string {
		$attrs = array_merge(
			array( 'class' => trim( 'wahou-field ' . ( $args['class'] ?? '' ) ) ),
			(array) ( $args['attrs'] ?? array() )
		);
		$html = '<div' . self::attrs( $attrs ) . '>';
		if ( '' !== $label ) {
			$for   = (string) ( $args['for'] ?? '' );
			$html .= '' !== $for
				? '<label class="wahou-field__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>'
				: '<span class="wahou-field__label">' . esc_html( $label ) . '</span>';
		}
		$html .= '<div class="wahou-field__control">' . $control . '</div>';
		if ( '' !== $help ) {
			$html .= '<p class="wahou-field__help">' . self::kses( $help ) . '</p>';
		}
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------
	 * Controls.
	 * ------------------------------------------------------------------ */

	/**
	 * Generic <input>. Args: type, id, class, placeholder, prefix, suffix, attrs.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $args
	 */
	public static function input( string $name, $value, array $args = array() ): string {
		$attrs = array_merge(
			array(
				'type'        => $args['type'] ?? 'text',
				'id'          => $args['id'] ?? self::id( $name ),
				'name'        => $name,
				'value'       => is_scalar( $value ) ? (string) $value : '',
				'class'       => trim( 'wahou-input ' . ( $args['class'] ?? '' ) ),
				'placeholder' => isset( $args['placeholder'] ) && '' !== $args['placeholder'] ? (string) $args['placeholder'] : null,
			),
			(array) ( $args['attrs'] ?? array() )
		);
		$html = '<input' . self::attrs( $attrs ) . '>';
		if ( ! empty( $args['prefix'] ) || ! empty( $args['suffix'] ) ) {
			$html = '<span class="wahou-input-group">'
				. ( ! empty( $args['prefix'] ) ? '<span class="wahou-input-group__addon">' . esc_html( (string) $args['prefix'] ) . '</span>' : '' )
				. $html
				. ( ! empty( $args['suffix'] ) ? '<span class="wahou-input-group__addon" data-suffix>' . esc_html( (string) $args['suffix'] ) . '</span>' : '' )
				. '</span>';
		}
		return $html;
	}

	/**
	 * Number input. Args: min, max, step + input args.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $args
	 */
	public static function number( string $name, $value, array $args = array() ): string {
		$args['type']  = 'number';
		$args['attrs'] = array_merge(
			array(
				'min'       => $args['min'] ?? null,
				'max'       => $args['max'] ?? null,
				'step'      => $args['step'] ?? null,
				'inputmode' => isset( $args['step'] ) && false !== strpos( (string) $args['step'], '.' ) ? 'decimal' : 'numeric',
			),
			(array) ( $args['attrs'] ?? array() )
		);
		$args['class'] = trim( 'wahou-input--number ' . ( $args['class'] ?? '' ) );
		return self::input( $name, $value, $args );
	}

	/**
	 * Money input: shows minor units in the merchant's locale ("19,90").
	 *
	 * @param array<string, mixed> $args
	 */
	public static function money( string $name, ?int $minor, array $args = array() ): string {
		$args['suffix']      = $args['suffix'] ?? Money::symbol();
		$args['class']       = trim( 'wahou-input--money ' . ( $args['class'] ?? '' ) );
		$args['placeholder'] = $args['placeholder'] ?? Money::to_input( 0 );
		$args['attrs']       = array_merge(
			array(
				'inputmode'    => 'decimal',
				'autocomplete' => 'off',
				'data-money'   => '1',
			),
			(array) ( $args['attrs'] ?? array() )
		);
		return self::input( $name, null === $minor ? '' : Money::to_input( $minor ), $args );
	}

	/**
	 * @param mixed                                      $value
	 * @param array<string|int, string|array<string|int, string>> $options value => label (array label = optgroup).
	 * @param array<string, mixed>                       $args    id, class, multiple, attrs.
	 */
	public static function select( string $name, $value, array $options, array $args = array() ): string {
		$multiple = ! empty( $args['multiple'] );
		$values   = array_map( 'strval', $multiple ? (array) $value : array( $value ) );
		$attrs    = array_merge(
			array(
				'id'       => $args['id'] ?? self::id( $name ),
				'name'     => $multiple ? $name . '[]' : $name,
				'class'    => trim( 'wahou-input wahou-select ' . ( $args['class'] ?? '' ) ),
				'multiple' => $multiple,
			),
			(array) ( $args['attrs'] ?? array() )
		);
		$html = '<select' . self::attrs( $attrs ) . '>';
		foreach ( $options as $key => $label ) {
			if ( is_array( $label ) ) {
				$html .= '<optgroup label="' . esc_attr( (string) $key ) . '">';
				foreach ( $label as $sub_key => $sub_label ) {
					$html .= self::option( (string) $sub_key, (string) $sub_label, $values );
				}
				$html .= '</optgroup>';
				continue;
			}
			$html .= self::option( (string) $key, (string) $label, $values );
		}
		return $html . '</select>';
	}

	/**
	 * @param string[] $selected
	 */
	private static function option( string $value, string $label, array $selected ): string {
		return '<option value="' . esc_attr( $value ) . '"' . ( in_array( $value, $selected, true ) ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
	}

	/**
	 * Switch-style checkbox. Submits "1" when checked.
	 *
	 * @param array<string, mixed> $args id, attrs, description.
	 */
	public static function toggle( string $name, bool $checked, string $label, array $args = array() ): string {
		$id    = (string) ( $args['id'] ?? self::id( $name ) );
		$attrs = array_merge(
			array(
				'type'    => 'checkbox',
				'id'      => $id,
				'name'    => $name,
				'value'   => '1',
				'checked' => $checked,
			),
			(array) ( $args['attrs'] ?? array() )
		);
		return '<label class="wahou-toggle" for="' . esc_attr( $id ) . '">'
			. '<input' . self::attrs( $attrs ) . '>'
			. '<span class="wahou-toggle__track" aria-hidden="true"></span>'
			. '<span class="wahou-toggle__label">' . self::kses( $label ) . '</span>'
			. '</label>';
	}

	/**
	 * Segmented control (radio group).
	 *
	 * @param mixed                 $value
	 * @param array<string, string> $options
	 * @param array<string, mixed>  $args label (aria), attrs.
	 */
	public static function segmented( string $name, $value, array $options, array $args = array() ): string {
		$attrs = array_merge(
			array(
				'class'      => trim( 'wahou-segmented ' . ( $args['class'] ?? '' ) ),
				'role'       => 'radiogroup',
				'aria-label' => $args['label'] ?? null,
			),
			(array) ( $args['attrs'] ?? array() )
		);
		$html = '<div' . self::attrs( $attrs ) . '>';
		foreach ( $options as $key => $label ) {
			$id    = self::id( $name . '-' . $key );
			$html .= '<input type="radio" class="wahou-segmented__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $key ) . '"' . checked( (string) $key, (string) $value, false ) . '>';
			$html .= '<label class="wahou-segmented__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}
		return $html . '</div>';
	}

	/**
	 * Large radio cards with an icon and a description.
	 *
	 * @param mixed                                                    $value
	 * @param array<string, array{label:string, desc?:string, icon?:string}> $options
	 */
	public static function choices( string $name, $value, array $options, string $aria_label = '' ): string {
		$html = '<div class="wahou-choices" role="radiogroup"' . ( '' !== $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : '' ) . '>';
		foreach ( $options as $key => $option ) {
			$checked = (string) $key === (string) $value;
			$html   .= '<label class="wahou-choice' . ( $checked ? ' is-checked' : '' ) . '">'
				. '<input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $key ) . '"' . checked( $checked, true, false ) . '>'
				. ( ! empty( $option['icon'] ) ? '<span class="wahou-choice__icon dashicons ' . esc_attr( $option['icon'] ) . '" aria-hidden="true"></span>' : '' )
				. '<span class="wahou-choice__text"><span class="wahou-choice__title">' . esc_html( $option['label'] ) . '</span>'
				. ( ! empty( $option['desc'] ) ? '<span class="wahou-choice__desc">' . esc_html( $option['desc'] ) . '</span>' : '' )
				. '</span></label>';
		}
		return $html . '</div>';
	}

	/**
	 * @param array<string, mixed> $args id, rows, placeholder, class, attrs.
	 */
	public static function textarea( string $name, string $value, array $args = array() ): string {
		$attrs = array_merge(
			array(
				'id'          => $args['id'] ?? self::id( $name ),
				'name'        => $name,
				'rows'        => $args['rows'] ?? 3,
				'class'       => trim( 'wahou-input wahou-textarea ' . ( $args['class'] ?? '' ) ),
				'placeholder' => isset( $args['placeholder'] ) && '' !== $args['placeholder'] ? (string) $args['placeholder'] : null,
			),
			(array) ( $args['attrs'] ?? array() )
		);
		return '<textarea' . self::attrs( $attrs ) . '>' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * Color picker with a hex text twin.
	 *
	 * @param array<string, mixed> $args id.
	 */
	public static function color( string $name, string $value, array $args = array() ): string {
		$id    = (string) ( $args['id'] ?? self::id( $name ) );
		$value = sanitize_hex_color( $value ) ? $value : '#5b3df5';
		return '<span class="wahou-color">'
			. '<input type="color" class="wahou-color__swatch" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" data-color-swatch aria-label="' . esc_attr__( 'Pick a color', 'wahou-commerce' ) . '">'
			. '<input type="text" class="wahou-input wahou-color__hex" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" pattern="#[0-9A-Fa-f]{6}" maxlength="7" data-color-hex aria-label="' . esc_attr__( 'Hexadecimal color', 'wahou-commerce' ) . '">'
			. '</span>';
	}

	/**
	 * Image picker storing an attachment ID (wp.media).
	 */
	public static function image( string $name, int $attachment_id ): string {
		$src = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		return '<div class="wahou-image-field" data-wahou-image>'
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $src ? $attachment_id : 0 ) ) . '" data-image-input>'
			. '<span class="wahou-image-field__preview" data-image-preview>' . ( $src ? '<img src="' . esc_url( $src ) . '" alt="">' : '' ) . '</span>'
			. '<button type="button" class="wahou-btn wahou-btn--secondary" data-image-choose>' . esc_html__( 'Choose an image', 'wahou-commerce' ) . '</button> '
			. '<button type="button" class="wahou-btn wahou-btn--ghost" data-image-remove' . ( $src ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'wahou-commerce' ) . '</button>'
			. '</div>';
	}

	/**
	 * Product picker (AJAX search + chips).
	 *
	 * @param int[]                $ids
	 * @param array<string, mixed> $args multiple (bool, default true), exclude (int[]), placeholder, id.
	 */
	public static function product_picker( string $name, array $ids, array $args = array() ): string {
		$multiple = ! isset( $args['multiple'] ) || ! empty( $args['multiple'] );
		$exclude  = array_values( array_filter( array_map( 'intval', (array) ( $args['exclude'] ?? array() ) ) ) );
		$chips    = '';
		foreach ( $ids as $id ) {
			$product = Product::find( (int) $id );
			if ( $product ) {
				$chips .= self::chip( $name, $multiple, $product->id(), self::product_label( $product ) );
				if ( ! $multiple ) {
					break;
				}
			}
		}
		$input_id = (string) ( $args['id'] ?? self::id( $name ) . '-search' );
		$html     = '<div class="wahou-picker" data-wahou-picker data-name="' . esc_attr( $name ) . '" data-multiple="' . ( $multiple ? '1' : '0' ) . '" data-exclude="' . esc_attr( implode( ',', $exclude ) ) . '">';
		// Sentinel so that clearing the picker also clears the saved value.
		$html .= '<input type="hidden" name="' . esc_attr( $multiple ? $name . '[]' : $name ) . '" value="">';
		$html .= '<ul class="wahou-chips" data-chips>' . $chips . '</ul>';
		$html .= '<div class="wahou-picker__search"' . ( ! $multiple && '' !== $chips ? ' hidden' : '' ) . ' data-search>';
		$html .= '<input type="search" id="' . esc_attr( $input_id ) . '" class="wahou-input wahou-picker__input" placeholder="' . esc_attr( (string) ( $args['placeholder'] ?? __( 'Search a product…', 'wahou-commerce' ) ) ) . '" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . esc_attr( $input_id . '-results' ) . '">';
		$html .= '<ul class="wahou-picker__results" id="' . esc_attr( $input_id . '-results' ) . '" role="listbox" hidden></ul>';
		$html .= '</div></div>';
		return $html;
	}

	public static function chip( string $name, bool $multiple, int $id, string $label ): string {
		return sprintf(
			'<li class="wahou-chip" data-id="%1$d"><span class="wahou-chip__label">%2$s</span><button type="button" class="wahou-chip__remove" aria-label="%3$s">&times;</button><input type="hidden" name="%4$s" value="%1$d"></li>',
			$id,
			esc_html( $label ),
			/* translators: %s: item name. */
			esc_attr( sprintf( __( 'Remove %s', 'wahou-commerce' ), $label ) ),
			esc_attr( $multiple ? $name . '[]' : $name )
		);
	}

	/**
	 * Country multi-select (native select, enhanced into chips by JS).
	 *
	 * @param string[]             $selected
	 * @param array<string, mixed> $args id, size.
	 */
	public static function countries( string $name, array $selected, array $args = array() ): string {
		$selected = array_map( 'strtoupper', array_map( 'strval', $selected ) );
		$id       = (string) ( $args['id'] ?? self::id( $name ) );
		$html     = '<div class="wahou-countries" data-wahou-countries>';
		$html    .= '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
		$html    .= '<select multiple id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" class="wahou-input wahou-countries__select" size="' . esc_attr( (string) ( $args['size'] ?? 8 ) ) . '">';
		foreach ( Countries::all() as $code => $label ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . ( in_array( $code, $selected, true ) ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></div>';
		return $html;
	}

	/**
	 * Status pill.
	 */
	public static function pill( string $label, string $variant = 'muted' ): string {
		return '<span class="wahou-pill wahou-pill--' . esc_attr( $variant ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ------------------------------------------------------------------
	 * Products.
	 * ------------------------------------------------------------------ */

	public static function product_label( Product $product ): string {
		$label = $product->name();
		if ( '' === trim( $label ) ) {
			/* translators: %d: product ID. */
			$label = sprintf( __( 'Product #%d', 'wahou-commerce' ), $product->id() );
		}
		$sku = $product->sku();
		if ( '' !== $sku ) {
			$label .= ' · ' . $sku;
		}
		if ( 'publish' !== $product->status() ) {
			$statuses = get_post_statuses();
			$label   .= ' (' . ( $statuses[ $product->status() ] ?? $product->status() ) . ')';
		}
		return $label;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function product_payload( Product $product ): array {
		$variants = array();
		foreach ( $product->variants() as $variant ) {
			$vid        = (string) $variant['id'];
			$variants[] = array(
				'id'    => $vid,
				'label' => $product->variant_label( $vid ),
			);
		}
		$thumb = $product->image_id() ? wp_get_attachment_image_url( $product->image_id(), 'thumbnail' ) : '';
		return array(
			'id'       => $product->id(),
			'text'     => self::product_label( $product ),
			'price'    => Money::format( $product->price() ),
			'thumb'    => $thumb ? (string) $thumb : '',
			'type'     => $product->type(),
			'kind'     => $product->kind(),
			'variants' => $variants,
		);
	}

	/**
	 * AJAX: search products by title, SKU or ID (or fetch ?ids=1,2).
	 */
	public static function ajax_search_products(): void {
		check_ajax_referer( 'wahou_search_products', 'nonce' );
		if ( ! current_user_can( 'edit_wahou_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wahou-commerce' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above.
		$q       = isset( $_REQUEST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) ) : '';
		$ids     = isset( $_REQUEST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) ) ) ) : array();
		$exclude = isset( $_REQUEST['exclude'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['exclude'] ) ) ) ) ) : array();
		// phpcs:enable

		$base  = array(
			'post_type'        => PostTypes::PRODUCT,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		$found = array();
		if ( $ids ) {
			$found = get_posts(
				$base + array(
					'post__in'       => $ids,
					'orderby'        => 'post__in',
					'posts_per_page' => min( 100, count( $ids ) ),
				)
			);
		} elseif ( '' === $q ) {
			$found = get_posts(
				$base + array(
					'posts_per_page' => 20,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
		} else {
			if ( ctype_digit( $q ) ) {
				$found[] = (int) $q;
			}
			$found = array_merge(
				$found,
				get_posts(
					$base + array(
						's'              => $q,
						'posts_per_page' => 20,
					)
				),
				get_posts(
					$base + array(
						'posts_per_page' => 10,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'     => '_wahou_sku',
								'value'   => $q,
								'compare' => 'LIKE',
							),
						),
					)
				)
			);
		}

		$out  = array();
		$seen = array();
		foreach ( $found as $id ) {
			$id = (int) $id;
			if ( isset( $seen[ $id ] ) || in_array( $id, $exclude, true ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = Product::find( $id );
			if ( $product ) {
				$out[] = self::product_payload( $product );
			}
			if ( ! $ids && count( $out ) >= 20 ) {
				break;
			}
		}
		wp_send_json_success( $out );
	}
}
