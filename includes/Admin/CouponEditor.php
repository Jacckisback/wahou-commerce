<?php
/**
 * Coupon editor (wahou_coupon): the post title is the code, normalized to
 * uppercase ASCII on save. Settings live in one meta box; the list table shows
 * type, amount, usage and expiry at a glance.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Coupons\Coupons;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class CouponEditor {

	private const NONCE = 'wahou_save_coupon';

	public static function init(): void {
		Fields::init();
		add_action( 'add_meta_boxes_' . PostTypes::COUPON, array( self::class, 'meta_boxes' ) );
		add_action( 'save_post_' . PostTypes::COUPON, array( self::class, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'normalize_code' ), 10, 2 );
		add_filter( 'enter_title_here', array( self::class, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( self::class, 'after_title' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'manage_' . PostTypes::COUPON . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . PostTypes::COUPON . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && PostTypes::COUPON === $screen->post_type && 'post' === $screen->base ) {
			Fields::enqueue_editor();
		}
	}

	public static function title_placeholder( string $placeholder, \WP_Post $post ): string {
		return PostTypes::COUPON === $post->post_type ? __( 'Coupon code, e.g. WELCOME10', 'wahou-commerce' ) : $placeholder;
	}

	/**
	 * Code helpers under the title: generator and duplicate warning.
	 */
	public static function after_title( \WP_Post $post ): void {
		if ( PostTypes::COUPON !== $post->post_type ) {
			return;
		}
		echo '<div class="wahou-coupon-code-bar">';
		echo '<button type="button" class="wahou-btn wahou-btn--secondary" data-generate-code>' . esc_html__( 'Generate a code', 'wahou-commerce' ) . '</button>';
		echo '<span class="wahou-muted">' . esc_html__( 'Letters, digits, dashes and underscores. Codes are not case sensitive for customers.', 'wahou-commerce' ) . '</span>';
		echo '</div>';

		$code = Coupons::normalize( $post->post_title );
		if ( '' !== $code && 'auto-draft' !== $post->post_status ) {
			$others = get_posts(
				array(
					'post_type'      => PostTypes::COUPON,
					'post_status'    => 'publish',
					'title'          => $code,
					'post__not_in'   => array( $post->ID ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( $others ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Another published coupon uses this code. Only the most recent one applies: change one of the codes.', 'wahou-commerce' ) . '</p></div>';
			}
		}
	}

	/**
	 * Normalize the code (post title) to uppercase ASCII without spaces.
	 *
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function normalize_code( array $data, array $postarr ): array {
		if ( PostTypes::COUPON !== ( $data['post_type'] ?? '' ) || in_array( $data['post_status'] ?? '', array( 'auto-draft', 'trash' ), true ) ) {
			return $data;
		}
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', remove_accents( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) ) ) );
		if ( '' === $code ) {
			$code = 'PROMO-' . strtoupper( wp_generate_password( 6, false ) );
		}
		$data['post_title'] = wp_slash( mb_substr( $code, 0, 64 ) );
		return $data;
	}

	public static function meta_boxes(): void {
		add_meta_box( 'wahou-coupon-data', __( 'Coupon settings', 'wahou-commerce' ), array( self::class, 'render' ), PostTypes::COUPON, 'normal', 'high' );
	}

	/**
	 * @return mixed
	 */
	private static function meta( int $post_id, string $key, $fallback = '' ) {
		$value = get_post_meta( $post_id, '_wahou_coupon_' . $key, true );
		return '' === $value ? $fallback : $value;
	}

	public static function render( \WP_Post $post ): void {
		$id      = $post->ID;
		$type    = (string) self::meta( $id, 'type', 'percent' );
		$type    = in_array( $type, array( 'percent', 'fixed', 'free_shipping' ), true ) ? $type : 'percent';
		$amount  = self::meta( $id, 'amount', '' );
		$expires = (int) self::meta( $id, 'expires', 0 );
		$used    = (int) self::meta( $id, 'used', 0 );

		wp_nonce_field( self::NONCE, 'wahou_coupon_nonce' );
		echo '<div class="wahou-editor wahou-coupon" data-wahou-coupon data-symbol="' . esc_attr( Money::symbol() ) . '">';

		echo Fields::choices( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'wahou_c[type]',
			$type,
			array(
				'percent'       => array(
					'label' => __( 'Percentage', 'wahou-commerce' ),
					'desc'  => __( 'e.g. -10 % on the cart', 'wahou-commerce' ),
					'icon'  => 'dashicons-tag',
				),
				'fixed'         => array(
					'label' => __( 'Fixed amount', 'wahou-commerce' ),
					'desc'  => __( 'e.g. -5 € on the cart', 'wahou-commerce' ),
					'icon'  => 'dashicons-money-alt',
				),
				'free_shipping' => array(
					'label' => __( 'Free shipping', 'wahou-commerce' ),
					'desc'  => __( 'Delivery offered', 'wahou-commerce' ),
					'icon'  => 'dashicons-car',
				),
			),
			__( 'Discount type', 'wahou-commerce' )
		);

		echo '<div class="wahou-grid wahou-grid--2">';
		$amount_value = '';
		if ( '' !== $amount ) {
			$amount_value = 'fixed' === $type ? Money::to_input( (int) $amount ) : rtrim( rtrim( number_format( (float) $amount, 2, '.', '' ), '0' ), '.' );
		}
		echo '<div data-show-if="wahou_c[type]!=free_shipping">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Amount', 'wahou-commerce' ),
			Fields::input(
				'wahou_c[amount]',
				$amount_value,
				array(
					'id'     => 'wahou-c-amount',
					'suffix' => 'fixed' === $type ? Money::symbol() : '%',
					'attrs'  => array(
						'inputmode'      => 'decimal',
						'data-amount'    => '1',
						'autocomplete'   => 'off',
					),
				)
			),
			__( 'Fixed amounts include VAT, like the prices your customers see.', 'wahou-commerce' ),
			array( 'for' => 'wahou-c-amount' )
		);
		echo '</div>';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Minimum order', 'wahou-commerce' ),
			Fields::money(
				'wahou_c[min]',
				(int) self::meta( $id, 'min', 0 ) ?: null,
				array(
					'id'          => 'wahou-c-min',
					'placeholder' => __( 'None', 'wahou-commerce' ),
				)
			),
			'',
			array( 'for' => 'wahou-c-min' )
		);
		echo '</div>';

		echo '<div data-show-if="wahou_c[type]!=free_shipping">';
		echo Fields::toggle( 'wahou_c[free_shipping]', (bool) self::meta( $id, 'free_shipping', false ), __( 'Also offer free shipping', 'wahou-commerce' ), array( 'id' => 'wahou-c-free-shipping' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Limits', 'wahou-commerce' ) . '</h3>';
		echo '<div class="wahou-grid wahou-grid--3">';
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Expires on (included)', 'wahou-commerce' ),
			Fields::input(
				'wahou_c[expires]',
				$expires ? wp_date( 'Y-m-d', $expires ) : '',
				array(
					'id'   => 'wahou-c-expires',
					'type' => 'date',
				)
			),
			$expires && $expires < time() ? '<strong>' . esc_html__( 'This coupon has expired.', 'wahou-commerce' ) . '</strong>' : '',
			array( 'for' => 'wahou-c-expires' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Total uses allowed', 'wahou-commerce' ),
			Fields::number(
				'wahou_c[limit]',
				(int) self::meta( $id, 'limit', 0 ) ?: '',
				array(
					'id'          => 'wahou-c-limit',
					'min'         => 0,
					'placeholder' => __( 'Unlimited', 'wahou-commerce' ),
				)
			),
			/* translators: %d: number of uses so far. */
			sprintf( _n( 'Used %d time so far.', 'Used %d times so far.', $used, 'wahou-commerce' ), $used ),
			array( 'for' => 'wahou-c-limit' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Uses per customer', 'wahou-commerce' ),
			Fields::number(
				'wahou_c[per_email]',
				(int) self::meta( $id, 'per_email', 0 ) ?: '',
				array(
					'id'          => 'wahou-c-per-email',
					'min'         => 0,
					'placeholder' => __( 'Unlimited', 'wahou-commerce' ),
				)
			),
			__( 'Counted per email address.', 'wahou-commerce' ),
			array( 'for' => 'wahou-c-per-email' )
		);
		echo '</div>';

		echo '<h3 class="wahou-subtitle">' . esc_html__( 'Restrictions', 'wahou-commerce' ) . '</h3>';
		$products = array_values( array_filter( array_map( 'intval', (array) self::meta( $id, 'products', array() ) ) ) );
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Only for these products', 'wahou-commerce' ),
			Fields::product_picker( 'wahou_c[products]', $products ),
			__( 'Leave empty to apply to the whole cart. Otherwise the discount only applies to these products.', 'wahou-commerce' )
		);
		echo Fields::row( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'Reserved for one email address', 'wahou-commerce' ),
			Fields::input(
				'wahou_c[email]',
				(string) self::meta( $id, 'email', '' ),
				array(
					'id'          => 'wahou-c-email',
					'type'        => 'email',
					'placeholder' => __( 'Anyone', 'wahou-commerce' ),
				)
			),
			'',
			array( 'for' => 'wahou-c-email' )
		);

		if ( self::meta( $id, 'auto', false ) ) {
			echo '<p class="wahou-callout wahou-callout--info">' . esc_html__( 'Personal coupon generated automatically by an abandoned cart reminder. It is deleted a week after it expires.', 'wahou-commerce' ) . '</p>';
		}

		$code = Coupons::normalize( $post->post_title );
		if ( '' !== $code && 'publish' === $post->post_status && function_exists( 'wahou_buy_url' ) ) {
			$link = add_query_arg( 'wahou_coupon', rawurlencode( $code ), home_url( '/' ) );
			echo '<h3 class="wahou-subtitle">' . esc_html__( 'Share it', 'wahou-commerce' ) . '</h3>';
			echo '<p class="wahou-muted">' . esc_html__( 'This link applies the coupon automatically – perfect for emails, social posts or a QR code in your books:', 'wahou-commerce' ) . '</p>';
			echo '<p class="wahou-share"><code>' . esc_html( $link ) . '</code> <button type="button" class="wahou-btn wahou-btn--ghost wahou-btn--small" data-copy="' . esc_attr( $link ) . '">' . esc_html__( 'Copy', 'wahou-commerce' ) . '</button></p>';
		}
		echo '</div>';
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['wahou_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wahou_coupon_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in  = isset( $_POST['wahou_c'] ) && is_array( $_POST['wahou_c'] ) ? wp_unslash( $_POST['wahou_c'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$str = static fn( string $key ): string => isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';

		$type = in_array( $str( 'type' ), array( 'percent', 'fixed', 'free_shipping' ), true ) ? $str( 'type' ) : 'percent';
		self::set( $post_id, 'type', $type );

		if ( 'fixed' === $type ) {
			$amount = max( 0, Money::to_minor( $str( 'amount' ) ) );
		} elseif ( 'percent' === $type ) {
			$amount = max( 0.0, min( 100.0, (float) str_replace( array( ',', '%', ' ' ), array( '.', '', '' ), $str( 'amount' ) ) ) );
		} else {
			$amount = 0;
		}
		self::set( $post_id, 'amount', $amount );
		self::set( $post_id, 'free_shipping', 'free_shipping' !== $type && ! empty( $in['free_shipping'] ) ? '1' : null );
		self::set( $post_id, 'min', max( 0, Money::to_minor( $str( 'min' ) ) ) ?: null );

		$expires = 0;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $str( 'expires' ) ) ) {
			try {
				$expires = ( new \DateTimeImmutable( $str( 'expires' ) . ' 23:59:59', wp_timezone() ) )->getTimestamp();
			} catch ( \Exception $e ) {
				$expires = 0;
			}
		}
		self::set( $post_id, 'expires', $expires ?: null );
		self::set( $post_id, 'limit', max( 0, (int) $str( 'limit' ) ) ?: null );
		self::set( $post_id, 'per_email', max( 0, (int) $str( 'per_email' ) ) ?: null );

		$products = array();
		foreach ( (array) ( $in['products'] ?? array() ) as $pid ) {
			$pid = absint( is_scalar( $pid ) ? $pid : 0 );
			if ( $pid && PostTypes::PRODUCT === get_post_type( $pid ) && ! in_array( $pid, $products, true ) ) {
				$products[] = $pid;
			}
		}
		self::set( $post_id, 'products', $products ?: null );

		$email = strtolower( sanitize_email( $str( 'email' ) ) );
		self::set( $post_id, 'email', is_email( $email ) ? $email : null );
	}

	/**
	 * @param mixed $value
	 */
	private static function set( int $post_id, string $key, $value ): void {
		if ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, '_wahou_coupon_' . $key );
			return;
		}
		update_post_meta( $post_id, '_wahou_coupon_' . $key, wp_slash( $value ) );
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
			if ( 'title' === $key ) {
				$label = __( 'Code', 'wahou-commerce' );
			}
			if ( 'date' === $key ) {
				$out['wahou_type']   = __( 'Discount', 'wahou-commerce' );
				$out['wahou_usage']  = __( 'Usage', 'wahou-commerce' );
				$out['wahou_expiry'] = __( 'Expires', 'wahou-commerce' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		$coupon = Coupons::get( $post_id );
		if ( ! $coupon ) {
			return;
		}
		switch ( $column ) {
			case 'wahou_type':
				if ( 'free_shipping' === $coupon['type'] ) {
					echo esc_html__( 'Free shipping', 'wahou-commerce' );
				} elseif ( 'fixed' === $coupon['type'] ) {
					echo esc_html( '-' . Money::format( (int) $coupon['amount'] ) );
				} else {
					echo esc_html( '-' . rtrim( rtrim( number_format_i18n( (float) $coupon['amount'], 2 ), '0' ), '.,' ) . ' %' );
				}
				if ( 'free_shipping' !== $coupon['type'] && $coupon['free_shipping'] ) {
					echo ' <span class="wahou-muted">+ ' . esc_html__( 'free shipping', 'wahou-commerce' ) . '</span>';
				}
				if ( $coupon['auto'] ) {
					echo ' ' . Fields::pill( __( 'Reminder', 'wahou-commerce' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				break;

			case 'wahou_usage':
				echo esc_html( number_format_i18n( (int) $coupon['used'] ) );
				if ( $coupon['limit'] ) {
					echo ' / ' . esc_html( number_format_i18n( (int) $coupon['limit'] ) );
				}
				break;

			case 'wahou_expiry':
				if ( ! $coupon['expires'] ) {
					echo '<span class="wahou-muted">' . esc_html__( 'Never', 'wahou-commerce' ) . '</span>';
				} elseif ( $coupon['expires'] < time() ) {
					echo Fields::pill( __( 'Expired', 'wahou-commerce' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) $coupon['expires'] ) );
				}
				break;
		}
	}

	/**
	 * Coupons have no public page: never show a "View" row action.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( PostTypes::COUPON === $post->post_type ) {
			unset( $actions['view'] );
		}
		return $actions;
	}
}
