<?php
/**
 * Offer editor (wahou_offer): order bumps, one-click upsells and downsells.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Funnels\Offers;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class OfferEditor {

	private const NONCE = 'wahou_save_offer';

	public static function init(): void {
		Fields::init();
		add_action( 'add_meta_boxes_' . PostTypes::OFFER, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . PostTypes::OFFER, array( self::class, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'manage_' . PostTypes::OFFER . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . PostTypes::OFFER . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && PostTypes::OFFER === $screen->post_type && 'post' === $screen->base ) {
			Fields::enqueue_editor();
		}
	}

	public static function title_placeholder( string $placeholder, \WP_Post $post ): string {
		return PostTypes::OFFER === $post->post_type ? __( 'Internal name, e.g. "Audiobook bump"', 'wahou-commerce' ) : $placeholder;
	}

	public static function meta_boxes(): void {
		add_meta_box( 'wahou-offer-data', __( 'Offer settings', 'wahou-commerce' ), array( self::class, 'render' ), PostTypes::OFFER, 'normal', 'high' );
	}

	/**
	 * @return mixed
	 */
	private static function meta( int $post_id, string $key, $fallback = '' ) {
		$value = get_post_meta( $post_id, '_wahou_offer_' . $key, true );
		return '' === $value ? $fallback : $value;
	}

	/**
	 * @return array<string, array{label:string, desc:string, icon:string}>
	 */
	private static function types(): array {
		return array(
			'bump'     => array(
				'label' => __( 'Order bump', 'wahou-commerce' ),
				'desc'  => __( 'A checkbox at checkout: "Add X for only Y". Converts best with a small, related product.', 'wahou-commerce' ),
				'icon'  => 'dashicons-plus-alt',
			),
			'upsell'   => array(
				'label' => __( 'One-click upsell', 'wahou-commerce' ),
				'desc'  => __( 'Shown right after payment: one click adds it to the order, without entering card details again.', 'wahou-commerce' ),
				'icon'  => 'dashicons-chart-line',
			),
			'downsell' => array(
				'label' => __( 'Downsell', 'wahou-commerce' ),
				'desc'  => __( 'A cheaper alternative shown when the customer declines an upsell.', 'wahou-commerce' ),
				'icon'  => 'dashicons-arrow-down-alt',
			),
		);
	}

	public static function render( \WP_Post $post ): void {
		$id         = $post->ID;
		$type       = (string) self::meta( $id, 'type', 'bump' );
		$type       = isset( self::types()[ $type ] ) ? $type : 'bump';
		$product_id = (int) self::meta( $id, 'product', 0 );
		$variant_id = (string) self::meta( $id, 'variant', '' );
		$discount   = self::meta( $id, 'discount', '' );
		$active     = '0' !== (string) get_post_meta( $id, '_wahou_offer_active', true );
		$triggers   = array_values( array_filter( array_map( 'intval', (array) self::meta( $id, 'triggers', array() ) ) ) );
		$downsell   = (int) self::meta( $id, 'downsell', 0 );
		$product    = $product_id ? Product::find( $product_id ) : null;

		wp_nonce_field( self::NONCE, 'wahou_offer_nonce' );
		echo '<div class="wahou-editor wahou-offer" data-wahou-offer>';

		echo Fields::toggle( 'wahou_o[active]', $active, __( 'Offer active', 'wahou-commerce' ), array( 'id' => 'wahou-o-active' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Where is it shown?', 'wahou-commerce' ) . '</h3>';
		echo Fields::choices( 'wahou_o[type]', $type, self::types(), __( 'Offer type', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="wahou-callout wahou-callout--info" data-show-if="wahou_o[type]!=bump">' . esc_html__( 'One-click offers work with card payments through Stripe (the card is saved securely by Stripe during checkout). Customers paying another way go straight to their order confirmation.', 'wahou-commerce' ) . '</p>';

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'What is offered?', 'wahou-commerce' ) . '</h3>';
		echo '<div class="wahou-grid wahou-grid--offer">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Product', 'wahou-commerce' ),
			Fields::product_picker(
				'wahou_o[product]',
				$product_id ? array( $product_id ) : array(),
				array(
					'multiple' => false,
					'id'       => 'wahou-o-product-search',
				)
			)
		);
		$variants = array( '' => __( 'Choose an option', 'wahou-commerce' ) );
		if ( $product ) {
			foreach ( $product->variants() as $variant ) {
				$variants[ (string) $variant['id'] ] = $product->variant_label( (string) $variant['id'] );
			}
		}
		echo '<div data-offer-variant' . ( count( $variants ) > 1 ? '' : ' hidden' ) . '>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Option', 'wahou-commerce' ),
			Fields::select( 'wahou_o[variant]', $variant_id, $variants, array( 'id' => 'wahou-o-variant' ) ),
			__( 'Products with options need a specific variant.', 'wahou-commerce' ),
			array( 'for' => 'wahou-o-variant' )
		);
		echo '</div>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Discount', 'wahou-commerce' ),
			Fields::number(
				'wahou_o[discount]',
				'' === $discount ? '' : (float) $discount,
				array(
					'id'     => 'wahou-o-discount',
					'min'    => 0,
					'max'    => 95,
					'step'   => '0.1',
					'suffix' => '%',
				)
			),
			__( 'Exclusive price compared with the product page.', 'wahou-commerce' ),
			array( 'for' => 'wahou-o-discount' )
		);
		echo '</div>';

		if ( $product ) {
			$regular = $product->price( '' !== $variant_id ? $variant_id : null );
			$price   = (int) round( $regular * ( 1 - (float) $discount / 100 ) );
			echo '<p class="wahou-callout"><span class="dashicons dashicons-tag" aria-hidden="true"></span> ';
			/* translators: 1: offer price, 2: regular price. */
			echo esc_html( sprintf( __( 'Customers pay %1$s instead of %2$s.', 'wahou-commerce' ), Money::format( $price ), Money::format( $regular ) ) );
			echo '</p>';
		}

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Message', 'wahou-commerce' ) . '</h3>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Headline', 'wahou-commerce' ),
			Fields::input(
				'wahou_o[headline]',
				(string) self::meta( $id, 'headline', '' ),
				array(
					'id'          => 'wahou-o-headline',
					'placeholder' => __( 'e.g. Add the audiobook for only 9 €', 'wahou-commerce' ),
					'attrs'       => array( 'maxlength' => 150 ),
				)
			),
			__( 'Talk about the benefit, not the product.', 'wahou-commerce' ),
			array( 'for' => 'wahou-o-headline' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Description', 'wahou-commerce' ),
			Fields::textarea(
				'wahou_o[text]',
				(string) self::meta( $id, 'text', '' ),
				array(
					'id'          => 'wahou-o-text',
					'rows'        => 3,
					'placeholder' => __( 'Two short sentences: why now, why this price.', 'wahou-commerce' ),
				)
			),
			'',
			array( 'for' => 'wahou-o-text' )
		);

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'When is it shown?', 'wahou-commerce' ) . '</h3>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Only when the cart or order contains', 'wahou-commerce' ),
			Fields::product_picker( 'wahou_o[triggers]', $triggers, array( 'id' => 'wahou-o-triggers-search' ) ),
			__( 'Leave empty to show it to everyone. The first matching offer is used (lowest "Order" value first).', 'wahou-commerce' )
		);

		$downsells = get_posts(
			array(
				'post_type'      => PostTypes::OFFER,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'post__not_in'   => array( $id ),
				'meta_key'       => '_wahou_offer_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'downsell', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$options = array( '0' => __( 'None – go to the order confirmation', 'wahou-commerce' ) );
		foreach ( $downsells as $offer ) {
			$options[ (string) $offer->ID ] = '' !== $offer->post_title ? $offer->post_title : '#' . $offer->ID;
		}
		echo '<div data-show-if="wahou_o[type]=upsell">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'If the customer says no, show', 'wahou-commerce' ),
			Fields::select( 'wahou_o[downsell]', (string) $downsell, $options, array( 'id' => 'wahou-o-downsell' ) ),
			count( $options ) > 1 ? '' : __( 'Create an offer of type "Downsell" first.', 'wahou-commerce' ),
			array( 'for' => 'wahou-o-downsell' )
		);
		echo '</div>';
		echo '</div>';
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['wahou_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wahou_offer_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in  = isset( $_POST['wahou_o'] ) && is_array( $_POST['wahou_o'] ) ? wp_unslash( $_POST['wahou_o'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$str = static fn( string $key ): string => isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';

		$type = in_array( $str( 'type' ), Offers::TYPES, true ) ? $str( 'type' ) : 'bump';
		self::set( $post_id, 'type', $type );

		$product_id = absint( $str( 'product' ) );
		$product    = $product_id ? Product::find( $product_id ) : null;
		self::set( $post_id, 'product', $product ? $product->id() : null );

		$variant = sanitize_text_field( $str( 'variant' ) );
		self::set( $post_id, 'variant', $product && '' !== $variant && $product->variant( $variant ) ? $variant : null );

		$discount = '' === $str( 'discount' ) ? null : max( 0.0, min( 95.0, (float) str_replace( ',', '.', $str( 'discount' ) ) ) );
		self::set( $post_id, 'discount', $discount );
		self::set( $post_id, 'headline', mb_substr( sanitize_text_field( $str( 'headline' ) ), 0, 150 ) );
		self::set( $post_id, 'text', mb_substr( wp_kses( isset( $in['text'] ) && is_string( $in['text'] ) ? $in['text'] : '', array( 'strong' => array(), 'em' => array(), 'br' => array() ) ), 0, 1000 ) );

		$triggers = array();
		foreach ( (array) ( $in['triggers'] ?? array() ) as $pid ) {
			$pid = absint( is_scalar( $pid ) ? $pid : 0 );
			if ( $pid && PostTypes::PRODUCT === get_post_type( $pid ) && ! in_array( $pid, $triggers, true ) ) {
				$triggers[] = $pid;
			}
		}
		self::set( $post_id, 'triggers', $triggers ?: null );

		$downsell = absint( $str( 'downsell' ) );
		$valid    = 'upsell' === $type && $downsell && $downsell !== $post_id && PostTypes::OFFER === get_post_type( $downsell );
		self::set( $post_id, 'downsell', $valid ? $downsell : null );

		update_post_meta( $post_id, '_wahou_offer_active', ! empty( $in['active'] ) ? '1' : '0' );

		Offers::flush();
	}

	/**
	 * @param mixed $value
	 */
	private static function set( int $post_id, string $key, $value ): void {
		if ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, '_wahou_offer_' . $key );
			return;
		}
		update_post_meta( $post_id, '_wahou_offer_' . $key, wp_slash( $value ) );
	}

	/* ------------------------------------------------------------------
	 * List table.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['wahou_offer_type']     = __( 'Type', 'wahou-commerce' );
				$out['wahou_offer_product']  = __( 'Product', 'wahou-commerce' );
				$out['wahou_offer_discount'] = __( 'Discount', 'wahou-commerce' );
				$out['wahou_offer_active']   = __( 'Status', 'wahou-commerce' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		$offer = Offers::get( $post_id );
		if ( ! $offer ) {
			return;
		}
		switch ( $column ) {
			case 'wahou_offer_type':
				$types   = self::types();
				$variant = array(
					'bump'     => 'brand',
					'upsell'   => 'success',
					'downsell' => 'info',
				);
				echo Fields::pill( $types[ $offer['type'] ]['label'] ?? $offer['type'], $variant[ $offer['type'] ] ?? 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'wahou_offer_product':
				$product = $offer['product_id'] ? Product::find( (int) $offer['product_id'] ) : null;
				if ( $product ) {
					$link = get_edit_post_link( $product->id() );
					echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $product->name() ) . '</a>' : esc_html( $product->name() );
					if ( '' !== $offer['variant_id'] ) {
						echo ' <span class="wahou-muted">' . esc_html( $product->variant_label( (string) $offer['variant_id'] ) ) . '</span>';
					}
				} else {
					echo Fields::pill( __( 'No product', 'wahou-commerce' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				break;

			case 'wahou_offer_discount':
				echo $offer['discount'] > 0 ? esc_html( '-' . rtrim( rtrim( number_format_i18n( (float) $offer['discount'], 1 ), '0' ), '.,' ) . ' %' ) : '<span class="wahou-muted">—</span>';
				break;

			case 'wahou_offer_active':
				echo $offer['active'] ? Fields::pill( __( 'Active', 'wahou-commerce' ), 'success' ) : Fields::pill( __( 'Paused', 'wahou-commerce' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
		}
	}
}
