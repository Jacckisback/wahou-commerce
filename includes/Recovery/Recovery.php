<?php
/**
 * Abandoned cart recovery and review requests.
 *
 * Flow (every 15 minutes, wahou_cron_frequent):
 *  1. Carts with an email, items and no activity for "recovery_abandon_minutes"
 *     become "abandoned" (respecting the consent mode and opt-outs).
 *  2. Reminders from "recovery_steps" are sent when due (delays are counted
 *     from the last cart activity), optionally with a personal single-use coupon.
 *  3. The recovery link restores the cart in one click and applies the coupon;
 *     a paid order from that cart is counted as "recovered".
 *
 * Links: ?wahou_recover=ID.SIG(&c=CODE), ?wahou_unsub=ID.SIG (cart reminders)
 * or ?wahou_unsub=rID.SIG (review requests). Unsubscribing requires a POST
 * (one-click RFC 8058 or the confirmation button) so link scanners cannot
 * unsubscribe people by prefetching emails.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Recovery;

use WahouCommerce\Cart\Cart;
use WahouCommerce\Compat\Compat;
use WahouCommerce\Coupons\Coupons;
use WahouCommerce\Emails\Emails;
use WahouCommerce\Emails\Mailer;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

final class Recovery {

	private const BATCH            = 25;
	private const MAX_STEPS        = 5;
	private const SUPPRESS_CARTS   = 'wahou_recovery_suppressed';
	private const SUPPRESS_REVIEWS = 'wahou_review_suppressed';
	private const SUPPRESS_MAX     = 20000;

	public static function init(): void {
		add_action( 'wahou_cron_frequent', array( self::class, 'process' ) );
		add_action( 'template_redirect', array( self::class, 'handle_links' ), 4 );
		add_action( 'wahou_order_completed', array( self::class, 'schedule_review_request' ) );
		add_action( 'wahou_review_request', array( self::class, 'send_review_request' ) );
	}

	/* ------------------------------------------------------------------
	 * Cron.
	 * ------------------------------------------------------------------ */

	public static function process(): void {
		if ( ! Settings::bool( 'recovery_enabled' ) ) {
			return;
		}
		$steps = self::steps();
		self::mark_abandoned( $steps );
		if ( $steps ) {
			self::send_due( $steps );
		}
	}

	/**
	 * Normalized reminder steps, sorted by delay.
	 *
	 * @return array<int, array{delay_hours:float, subject:string, coupon:float, coupon_days:int}>
	 */
	public static function steps(): array {
		$out = array();
		foreach ( (array) Settings::get( 'recovery_steps', array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$delay = (float) ( $step['delay_hours'] ?? 0 );
			if ( $delay <= 0 ) {
				continue;
			}
			$out[] = array(
				'delay_hours' => $delay,
				'subject'     => (string) ( $step['subject'] ?? '' ),
				'coupon'      => max( 0.0, min( 90.0, (float) ( $step['coupon'] ?? 0 ) ) ),
				'coupon_days' => max( 0, (int) ( $step['coupon_days'] ?? 0 ) ),
			);
		}
		usort( $out, static fn( $a, $b ) => $a['delay_hours'] <=> $b['delay_hours'] );
		return array_slice( $out, 0, self::MAX_STEPS );
	}

	/**
	 * @param array<int, array<string, mixed>> $steps
	 */
	private static function mark_abandoned( array $steps ): void {
		global $wpdb;
		$table   = Install::table( 'carts' );
		$minutes = max( 5, Settings::int( 'recovery_abandon_minutes' ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND email <> '' AND item_count > 0 AND unsubscribed = 0 AND updated_at < %s ORDER BY updated_at ASC LIMIT 100", $cutoff ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$cart     = Cart::from_row( $row );
			$index    = (int) $row['reminders_sent'];
			$eligible = self::consent_ok( $cart ) && ! self::is_suppressed( (string) $row['email'], self::SUPPRESS_CARTS );
			$next     = null;
			if ( $eligible && isset( $steps[ $index ] ) ) {
				$due  = strtotime( $row['updated_at'] . ' UTC' ) + (int) round( $steps[ $index ]['delay_hours'] * HOUR_IN_SECONDS );
				$next = gmdate( 'Y-m-d H:i:s', max( time(), $due ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'status'           => 'abandoned',
					'abandoned_at'     => ! empty( $row['abandoned_at'] ) ? $row['abandoned_at'] : $now,
					'next_reminder_at' => $next,
				),
				array(
					'id'     => (int) $row['id'],
					'status' => 'active',
				)
			);
			do_action( 'wahou_cart_abandoned', $cart );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $steps
	 */
	private static function send_due( array $steps ): void {
		global $wpdb;
		$table = Install::table( 'carts' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'abandoned' AND unsubscribed = 0 AND next_reminder_at IS NOT NULL AND next_reminder_at <= %s AND reminders_sent < %d ORDER BY next_reminder_at ASC LIMIT %d", $now, count( $steps ), self::BATCH ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			// Claim the row so overlapping cron runs never send twice.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_reminder_at = NULL WHERE id = %d AND next_reminder_at = %s", (int) $row['id'], (string) $row['next_reminder_at'] ) );
			if ( ! $claimed ) {
				continue;
			}
			self::send_reminder( $row, $steps );
		}
	}

	/**
	 * @param array<string, mixed>              $row
	 * @param array<int, array<string, mixed>> $steps
	 */
	private static function send_reminder( array $row, array $steps ): void {
		global $wpdb;
		$table = Install::table( 'carts' );
		$cart  = Cart::from_row( $row );
		$email = (string) $row['email'];
		$index = (int) $row['reminders_sent'];
		$step  = $steps[ $index ] ?? null;

		if ( ! $step || ! is_email( $email ) || ! self::consent_ok( $cart ) || self::is_suppressed( $email, self::SUPPRESS_CARTS ) ) {
			return;
		}
		if ( self::has_paid_since( $email, (string) $row['created_at'] ) ) {
			return; // Bought anyway (another cart or device): stop reminding.
		}
		$lines = $cart->lines();
		if ( empty( $lines ) ) {
			return;
		}

		$locale  = '' !== (string) $row['locale'] ? (string) $row['locale'] : get_locale();
		$coupon  = '';
		$expires = 0;
		if ( $step['coupon'] > 0 ) {
			$existing = $cart->get( 'recovery_coupon' );
			if ( is_array( $existing ) && ! empty( $existing['code'] ) && ( empty( $existing['expires'] ) || (int) $existing['expires'] > time() ) && Coupons::find( (string) $existing['code'] ) ) {
				$coupon  = (string) $existing['code'];
				$expires = (int) ( $existing['expires'] ?? 0 );
			} else {
				$coupon  = Coupons::create_auto( $email, (float) $step['coupon'], (int) $step['coupon_days'], self::coupon_prefix() );
				$expires = $step['coupon_days'] > 0 ? time() + (int) $step['coupon_days'] * DAY_IN_SECONDS : 0;
				if ( '' !== $coupon ) {
					self::store_cart_data(
						(int) $row['id'],
						(string) $row['data'],
						'recovery_coupon',
						array(
							'code'    => $coupon,
							'percent' => (float) $step['coupon'],
							'expires' => $expires,
						)
					);
				}
			}
		}

		$customer = $cart->customer();
		$sent     = Emails::send_cart_reminder(
			$email,
			$locale,
			$index,
			array(
				'cart'            => $cart,
				'lines'           => $lines,
				'totals'          => $cart->totals(),
				'recover_url'     => self::recover_url( (int) $row['id'], $coupon, $locale ),
				'unsubscribe_url' => self::unsubscribe_url( 'c', (int) $row['id'], $locale ),
				'coupon'          => $coupon,
				'coupon_percent'  => '' !== $coupon ? (float) $step['coupon'] : 0.0,
				'coupon_expires'  => $expires,
				'first_name'      => (string) ( $customer['first_name'] ?? '' ),
			)
		);

		if ( $sent ) {
			$count = $index + 1;
			$next  = null;
			if ( isset( $steps[ $count ] ) ) {
				$due  = strtotime( $row['updated_at'] . ' UTC' ) + (int) round( $steps[ $count ]['delay_hours'] * HOUR_IN_SECONDS );
				$next = gmdate( 'Y-m-d H:i:s', max( time() + 30 * MINUTE_IN_SECONDS, $due ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'reminders_sent'   => $count,
					'next_reminder_at' => $next,
				),
				array( 'id' => (int) $row['id'] )
			);
			Logger::info( 'recovery', 'Reminder sent', array( 'cart' => (int) $row['id'], 'step' => $count ) );
		} else {
			// Mail failure: retry in an hour.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'next_reminder_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ), array( 'id' => (int) $row['id'] ) );
		}
	}

	/**
	 * Write one key in a cart's JSON data without touching its activity date.
	 *
	 * @param mixed $value
	 */
	private static function store_cart_data( int $cart_id, string $json, string $key, $value ): void {
		global $wpdb;
		$data         = json_decode( $json, true );
		$data         = is_array( $data ) ? $data : array();
		$data[ $key ] = $value;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Install::table( 'carts' ), array( 'data' => wp_json_encode( $data ) ), array( 'id' => $cart_id ) );
	}

	private static function consent_ok( Cart $cart ): bool {
		$consent = $cart->consent();
		if ( 'checkbox' === Settings::str( 'recovery_consent' ) ) {
			return true === $consent;
		}
		return false !== $consent; // Information notice + opt-out.
	}

	private static function has_paid_since( string $email, string $since ): bool {
		global $wpdb;
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND paid_at IS NOT NULL AND paid_at >= %s", $email, $since ) ) > 0;
	}

	private static function coupon_prefix(): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', remove_accents( Settings::store_name() ) ) ?? '' );
		$prefix = substr( $prefix, 0, 8 );
		return '' !== $prefix ? $prefix : 'WAHOU';
	}

	/* ------------------------------------------------------------------
	 * Links.
	 * ------------------------------------------------------------------ */

	public static function recover_url( int $cart_id, string $coupon = '', string $locale = '' ): string {
		$args = array( 'wahou_recover' => $cart_id . '.' . Crypto::short_sign( 'recover|' . $cart_id ) );
		if ( '' !== $coupon ) {
			$args['c'] = $coupon;
		}
		return add_query_arg( $args, self::home( $locale ) );
	}

	/**
	 * @param string $type "c" (cart reminders) or "r" (review requests).
	 */
	public static function unsubscribe_url( string $type, int $id, string $locale = '' ): string {
		$value = ( 'r' === $type ? 'r' : '' ) . $id . '.' . Crypto::short_sign( 'unsub|' . $type . '|' . $id );
		return add_query_arg( 'wahou_unsub', $value, self::home( $locale ) );
	}

	private static function home( string $locale ): string {
		if ( '' !== $locale && class_exists( Compat::class ) && method_exists( Compat::class, 'localized_home' ) ) {
			return Compat::localized_home( $locale );
		}
		return home_url( '/' );
	}

	public static function handle_links(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Signed links.
		if ( isset( $_GET['wahou_recover'] ) ) {
			self::recover(
				sanitize_text_field( wp_unslash( $_GET['wahou_recover'] ) ),
				isset( $_GET['c'] ) ? sanitize_text_field( wp_unslash( $_GET['c'] ) ) : ''
			);
		}
		if ( isset( $_GET['wahou_unsub'] ) ) {
			self::unsubscribe( sanitize_text_field( wp_unslash( $_GET['wahou_unsub'] ) ) );
		}
		// phpcs:enable
	}

	private static function recover( string $param, string $code ): void {
		$parts   = explode( '.', $param, 2 );
		$cart_id = absint( $parts[0] );
		$sig     = (string) ( $parts[1] ?? '' );
		$fallback = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );

		nocache_headers();
		if ( ! $cart_id || ! Crypto::verify_short( 'recover|' . $cart_id, $sig ) ) {
			wp_safe_redirect( $fallback );
			exit;
		}
		$cart = Cart::find( $cart_id );
		if ( ! $cart || in_array( $cart->status(), array( 'converted', 'recovered' ), true ) || $cart->is_empty() ) {
			wp_safe_redirect( $fallback );
			exit;
		}
		if ( ! self::is_bot() ) {
			Cart::set_current( $cart );
			$cart->set( 'recovery_click', time() );
			if ( '' !== $code ) {
				$cart->apply_coupon( $code ); // Errors are ignored: the cart still opens.
			}
			$cart->save();
			do_action( 'wahou_cart_recovery_click', $cart );
		}
		wp_safe_redirect( Pages::url( 'checkout' ) );
		exit;
	}

	private static function unsubscribe( string $param ): void {
		if ( ! preg_match( '/^(r?)(\d+)\.([a-f0-9]{20})$/', $param, $m ) ) {
			self::render_page( __( 'Invalid link', 'wahou-commerce' ), __( 'This unsubscribe link is not valid.', 'wahou-commerce' ), 400 );
		}
		$type = 'r' === $m[1] ? 'r' : 'c';
		$id   = (int) $m[2];
		if ( ! Crypto::verify_short( 'unsub|' . $type . '|' . $id, $m[3] ) ) {
			self::render_page( __( 'Invalid link', 'wahou-commerce' ), __( 'This unsubscribe link is not valid.', 'wahou-commerce' ), 400 );
		}

		$email  = '';
		$locale = '';
		if ( 'c' === $type ) {
			$cart  = Cart::find( $id );
			$email = $cart ? $cart->email() : '';
		} else {
			$order  = Order::find( $id );
			$email  = $order ? $order->email() : '';
			$locale = $order ? $order->locale() : '';
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		Mailer::with_locale(
			$locale ? $locale : determine_locale(),
			static function () use ( $method, $type, $id, $email ) {
				if ( 'POST' !== $method ) {
					$form = '<form method="post"><button type="submit" style="font:inherit;font-weight:700;padding:12px 22px;border:0;border-radius:10px;cursor:pointer;background:' . esc_attr( Mailer::brand_color() ) . ';color:' . esc_attr( Mailer::on_brand_color() ) . ';">' . esc_html__( 'Confirm', 'wahou-commerce' ) . '</button></form>';
					self::render_page(
						__( 'Unsubscribe', 'wahou-commerce' ),
						'c' === $type ? __( 'Stop receiving reminders about your cart?', 'wahou-commerce' ) : __( 'Stop receiving review requests?', 'wahou-commerce' ),
						200,
						$form
					);
				}
				global $wpdb;
				if ( 'c' === $type ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update(
						Install::table( 'carts' ),
						array(
							'unsubscribed'     => 1,
							'next_reminder_at' => null,
						),
						array( 'id' => $id )
					);
					self::suppress( $email, self::SUPPRESS_CARTS );
				} else {
					self::suppress( $email, self::SUPPRESS_REVIEWS );
				}
				self::render_page( __( 'You are unsubscribed', 'wahou-commerce' ), __( 'You will not receive these emails anymore. Order confirmations are not affected.', 'wahou-commerce' ) );
			}
		);
	}

	/**
	 * Minimal standalone page (theme independent) and exit.
	 */
	private static function render_page( string $title, string $message, int $status = 200, string $extra_html = '' ): void {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$brand = Mailer::brand_color();
		echo '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' – ' . Settings::store_name() ) . '</title></head>';
		echo '<body style="margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1f2328;">';
		echo '<main style="max-width:480px;margin:12vh auto;padding:32px 28px;background:#fff;border-radius:16px;border-top:6px solid ' . esc_attr( $brand ) . ';box-shadow:0 10px 30px rgba(0,0,0,.06);text-align:center;">';
		echo '<p style="margin:0 0 8px;font-weight:800;">' . esc_html( Settings::store_name() ) . '</p>';
		echo '<h1 style="font-size:22px;margin:0 0 12px;">' . esc_html( $title ) . '</h1>';
		echo '<p style="margin:0 0 20px;line-height:1.5;">' . esc_html( $message ) . '</p>';
		echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping above.
		echo '<p style="margin:20px 0 0;"><a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $brand ) . ';">' . esc_html__( 'Back to the shop', 'wahou-commerce' ) . '</a></p>';
		echo '</main></body></html>';
		exit;
	}

	/* ------------------------------------------------------------------
	 * Suppression lists (hashed emails, GDPR friendly).
	 * ------------------------------------------------------------------ */

	private static function suppress( string $email, string $list ): void {
		if ( ! is_email( $email ) ) {
			return;
		}
		$hashes = get_option( $list, array() );
		$hashes = is_array( $hashes ) ? $hashes : array();
		$hash   = hash( 'sha256', strtolower( trim( $email ) ) );
		if ( ! in_array( $hash, $hashes, true ) ) {
			$hashes[] = $hash;
			update_option( $list, array_slice( $hashes, -self::SUPPRESS_MAX ), false );
		}
	}

	public static function is_suppressed( string $email, string $list = self::SUPPRESS_CARTS ): bool {
		$hashes = get_option( $list, array() );
		return is_array( $hashes ) && in_array( hash( 'sha256', strtolower( trim( $email ) ) ), $hashes, true );
	}

	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|preview|scanner|safelinks|proofpoint|mimecast|barracuda|headless|python|curl|wget/', $ua );
	}

	/* ------------------------------------------------------------------
	 * Review requests.
	 * ------------------------------------------------------------------ */

	public static function schedule_review_request( Order $order ): void {
		if ( ! Settings::bool( 'review_requests' ) || ! Settings::bool( 'reviews_enabled' ) || $order->get_meta( 'review_request_scheduled' ) ) {
			return;
		}
		$days = max( 1, Settings::int( 'review_delay_days' ) );
		wp_schedule_single_event( time() + $days * DAY_IN_SECONDS, 'wahou_review_request', array( $order->id() ) );
		$order->update_meta( 'review_request_scheduled', current_time( 'mysql', true ) );
	}

	/**
	 * @param int $order_id
	 */
	public static function send_review_request( $order_id ): void {
		$order = Order::find( (int) $order_id );
		if ( ! $order || 'completed' !== $order->status() || $order->get_meta( 'review_request_sent' ) ) {
			return;
		}
		if ( ! Settings::bool( 'review_requests' ) || ! Settings::bool( 'reviews_enabled' ) || self::is_suppressed( $order->email(), self::SUPPRESS_REVIEWS ) ) {
			return;
		}
		$products = array();
		$seen     = array();
		foreach ( $order->items() as $item ) {
			$id = (int) $item['product_id'];
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = Product::find( $id );
			if ( ! $product || 'publish' !== $product->status() || ! comments_open( $id ) ) {
				continue;
			}
			$already = (int) get_comments(
				array(
					'post_id'      => $id,
					'author_email' => $order->email(),
					'count'        => true,
					'status'       => 'all',
				)
			);
			if ( $already > 0 ) {
				continue;
			}
			$products[] = array(
				'name'  => $product->name(),
				'url'   => $product->permalink() . '#wahou-reviews',
				'image' => $product->image_url( 'thumbnail' ),
			);
		}
		if ( ! $products ) {
			return;
		}
		$sent = Emails::send_review_request( $order, array_slice( $products, 0, 6 ), self::unsubscribe_url( 'r', $order->id(), $order->locale() ) );
		if ( $sent ) {
			$order->update_meta( 'review_request_sent', current_time( 'mysql', true ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Stats.
	 * ------------------------------------------------------------------ */

	/**
	 * Recovery stats between two GMT dates (Y-m-d H:i:s).
	 *
	 * @return array{abandoned:int, recovered:int, recovered_revenue:int, rate:float, reminders_sent:int, abandoned_value:int}
	 */
	public static function stats( string $from_gmt, string $to_gmt ): array {
		global $wpdb;
		$carts  = Install::table( 'carts' );
		$orders = Install::table( 'orders' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$abandoned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE abandoned_at IS NOT NULL AND abandoned_at >= %s AND abandoned_at <= %s", $from_gmt, $to_gmt ) );
		$reminders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(reminders_sent),0) FROM {$carts} WHERE abandoned_at IS NOT NULL AND abandoned_at >= %s AND abandoned_at <= %s", $from_gmt, $to_gmt ) );
		$value     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$carts} WHERE status = 'abandoned' AND abandoned_at >= %s AND abandoned_at <= %s", $from_gmt, $to_gmt ) );
		$recovered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE status = 'recovered' AND converted_at >= %s AND converted_at <= %s", $from_gmt, $to_gmt ) );
		$revenue   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(o.total - o.refunded_total),0) FROM {$carts} c INNER JOIN {$orders} o ON o.id = c.order_id WHERE c.status = 'recovered' AND c.converted_at >= %s AND c.converted_at <= %s AND o.status IN ('processing','completed','refunded')", $from_gmt, $to_gmt ) );
		// phpcs:enable

		return array(
			'abandoned'         => $abandoned,
			'recovered'         => $recovered,
			'recovered_revenue' => $revenue,
			'rate'              => $abandoned > 0 ? round( $recovered / $abandoned * 100, 1 ) : 0.0,
			'reminders_sent'    => $reminders,
			'abandoned_value'   => $value,
		);
	}
}
