<?php
/**
 * Secure delivery of digital files.
 *
 * Access is granted per order and per file when the order is paid (signed,
 * random token per file), with optional download limits and expiry taken from
 * the product. Files live in uploads/wahou-private/ (deny-all) and are only
 * streamed through /?wahou_dl=TOKEN after checking the order, the expiry and
 * the remaining downloads. Paths are resolved with realpath() and must stay
 * inside the private folder (no traversal). External files are redirected.
 *
 * Optional web server offloading (wp-config.php):
 *   define( 'WAHOU_X_ACCEL_PREFIX', '/wahou-protected/' ); // nginx internal location
 *   define( 'WAHOU_XSENDFILE', true );                      // Apache mod_xsendfile
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Downloads;

use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Downloads {

	/** Order statuses giving access to downloads. */
	private const ACCESS_STATUSES = array( 'processing', 'completed' );

	public static function init(): void {
		add_action( 'wahou_order_paid', array( self::class, 'grant' ), 5 );
		add_action( 'wahou_order_status_refunded', array( self::class, 'revoke' ) );
		add_action( 'wahou_order_status_cancelled', array( self::class, 'revoke' ) );
		add_action( 'init', array( self::class, 'maybe_serve' ), 99 );
	}

	/* ------------------------------------------------------------------
	 * Permissions.
	 * ------------------------------------------------------------------ */

	/**
	 * Create download permissions for every file of the order's products
	 * (bundle components included). Idempotent. Returns the number created.
	 */
	public static function grant( Order $order ): int {
		global $wpdb;
		$table   = Install::table( 'downloads' );
		$now     = current_time( 'mysql', true );
		$created = 0;

		foreach ( $order->items() as $item ) {
			foreach ( self::item_products( $item ) as $product ) {
				foreach ( $product->files() as $file ) {
					$file_id = substr( (string) $file['id'], 0, 40 );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND item_id = %d AND product_id = %d AND file_id = %s LIMIT 1", $order->id(), (int) $item['id'], $product->id(), $file_id ) );
					if ( $exists ) {
						continue;
					}
					$limit = $product->download_limit();
					$days  = $product->download_expiry_days();
					$name  = trim( (string) ( $file['name'] ?? '' ) );
					if ( '' === $name ) {
						$name = wp_basename( (string) ( $file['path'] ?? $file['url'] ?? $product->name() ) );
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$ok = $wpdb->insert(
						$table,
						array(
							'token'               => Crypto::token( 24 ),
							'order_id'            => $order->id(),
							'item_id'             => (int) $item['id'],
							'product_id'          => $product->id(),
							'file_id'             => $file_id,
							'file_name'           => substr( $name, 0, 255 ),
							'email'               => $order->email(),
							'downloads_remaining' => $limit > 0 ? $limit : null,
							'expires_at'          => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null,
							'created_at'          => $now,
						)
					);
					if ( $ok ) {
						++$created;
					}
				}
			}
		}

		if ( $created ) {
			/**
			 * Fires after download permissions were granted for an order.
			 */
			do_action( 'wahou_downloads_granted', $order, $created );
		}
		return $created;
	}

	/**
	 * Products whose files are delivered for an order item (bundle components included).
	 *
	 * @param array<string, mixed> $item
	 * @return Product[]
	 */
	private static function item_products( array $item ): array {
		$products = array();
		$product  = Product::find( (int) $item['product_id'] );
		if ( $product ) {
			$products[ $product->id() ] = $product;
		}
		$components = isset( $item['meta']['bundle'] ) && is_array( $item['meta']['bundle'] ) ? $item['meta']['bundle'] : array();
		if ( ! $components && $product && 'bundle' === $product->type() ) {
			$components = $product->bundle_items();
		}
		foreach ( $components as $component ) {
			$p = Product::find( (int) ( $component['product_id'] ?? 0 ) );
			if ( $p ) {
				$products[ $p->id() ] = $p;
			}
		}
		return array_values( $products );
	}

	/**
	 * Remove access (full refund or cancellation). Rows are kept for the audit trail.
	 */
	public static function revoke( Order $order ): void {
		global $wpdb;
		$table = Install::table( 'downloads' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET downloads_remaining = 0, expires_at = %s WHERE order_id = %d", current_time( 'mysql', true ), $order->id() ) );
	}

	public static function url( string $token ): string {
		return add_query_arg( 'wahou_dl', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Downloads of an order, for the thank-you page, emails and the account.
	 *
	 * @return array<int, array<string, mixed>> name, url, product, product_id, remaining (?int),
	 *         expires (?string, GMT Y-m-d H:i:s), expires_label, available (bool), count.
	 */
	public static function for_order( Order $order ): array {
		global $wpdb;
		if ( ! in_array( $order->status(), self::ACCESS_STATUSES, true ) ) {
			return array();
		}
		$table = Install::table( 'downloads' );
		$sql   = "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $order->id() ), ARRAY_A );
		if ( ! $rows && $order->has_digital() && self::grant( $order ) ) {
			// Orders paid before the files were attached: grant lazily.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $order->id() ), ARRAY_A );
		}
		return array_map( array( self::class, 'format_row' ), $rows );
	}

	/**
	 * Every download of a customer (account page).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_customer( string $email, int $user_id = 0 ): array {
		global $wpdb;
		if ( '' === $email && ! $user_id ) {
			return array();
		}
		$downloads = Install::table( 'downloads' );
		$orders    = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.*, o.id AS o_id FROM {$downloads} d INNER JOIN {$orders} o ON o.id = d.order_id WHERE ( d.email = %s OR ( o.customer_id > 0 AND o.customer_id = %d ) ) AND o.status IN ('processing','completed') ORDER BY d.id DESC LIMIT 200", $email, $user_id ), ARRAY_A );
		return array_map( array( self::class, 'format_row' ), $rows );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function format_row( array $row ): array {
		$product   = Product::find( (int) $row['product_id'] );
		$remaining = null === $row['downloads_remaining'] ? null : (int) $row['downloads_remaining'];
		$expires   = empty( $row['expires_at'] ) ? null : (string) $row['expires_at'];
		$expired   = $expires && strtotime( $expires . ' UTC' ) < time();
		return array(
			'id'            => (int) $row['id'],
			'order_id'      => (int) $row['order_id'],
			'name'          => (string) $row['file_name'],
			'url'           => self::url( (string) $row['token'] ),
			'product'       => $product ? $product->name() : '',
			'product_id'    => (int) $row['product_id'],
			'remaining'     => $remaining,
			'expires'       => $expires,
			'expires_label' => $expires ? (string) get_date_from_gmt( $expires, (string) get_option( 'date_format' ) ) : '',
			'available'     => ! $expired && ( null === $remaining || $remaining > 0 ),
			'count'         => (int) $row['download_count'],
		);
	}

	/* ------------------------------------------------------------------
	 * Delivery.
	 * ------------------------------------------------------------------ */

	public static function maybe_serve(): void {
		if ( empty( $_GET['wahou_dl'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		global $wpdb;

		$token = sanitize_text_field( wp_unslash( $_GET['wahou_dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $token ) ) {
			self::fail( __( 'This download link is not valid.', 'wahou-commerce' ), 404 );
		}
		if ( ! RateLimiter::hit( 'download', 30, 10 * MINUTE_IN_SECONDS, $token ) || ! RateLimiter::hit( 'download_ip', 120, 10 * MINUTE_IN_SECONDS ) ) {
			self::fail( __( 'Too many downloads in a short time. Please try again in a few minutes.', 'wahou-commerce' ), 429 );
		}

		$table = Install::table( 'downloads' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ), ARRAY_A );
		if ( ! $row ) {
			self::fail( __( 'This download link is not valid.', 'wahou-commerce' ), 404 );
		}

		$order = Order::find( (int) $row['order_id'] );
		if ( ! $order || ! in_array( $order->status(), self::ACCESS_STATUSES, true ) ) {
			self::fail( __( 'This download is not available for this order.', 'wahou-commerce' ), 403 );
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			self::fail( __( 'This download link has expired. Contact us if you need a new one.', 'wahou-commerce' ), 410 );
		}
		if ( null !== $row['downloads_remaining'] && (int) $row['downloads_remaining'] <= 0 ) {
			self::fail( __( 'The download limit for this file has been reached. Contact us if you need help.', 'wahou-commerce' ), 410 );
		}

		$file    = null;
		$product = Product::find( (int) $row['product_id'] );
		foreach ( $product ? $product->files() : array() as $candidate ) {
			if ( (string) $candidate['id'] === (string) $row['file_id'] ) {
				$file = $candidate;
				break;
			}
		}
		if ( ! $file ) {
			self::fail( __( 'This file is no longer available. Please contact us.', 'wahou-commerce' ), 404 );
		}

		$source = self::resolve_source( $file );
		if ( ! $source ) {
			Logger::error( 'downloads', 'File missing on disk', array( 'download_id' => (int) $row['id'] ) );
			self::fail( __( 'This file is temporarily unavailable. Please contact us.', 'wahou-commerce' ), 404 );
		}

		// Count atomically (two parallel requests cannot exceed the limit).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET download_count = download_count + 1, downloads_remaining = CASE WHEN downloads_remaining IS NULL THEN NULL ELSE downloads_remaining - 1 END, last_download_at = %s WHERE id = %d AND ( downloads_remaining IS NULL OR downloads_remaining > 0 )", current_time( 'mysql', true ), (int) $row['id'] ) );
		if ( ! $counted ) {
			self::fail( __( 'The download limit for this file has been reached. Contact us if you need help.', 'wahou-commerce' ), 410 );
		}

		/**
		 * Fires right before a file is delivered.
		 *
		 * @param array<string, mixed> $row   Download row.
		 * @param Order                $order Order.
		 */
		do_action( 'wahou_download', $row, $order );

		if ( 'url' === $source['type'] ) {
			nocache_headers();
			wp_redirect( $source['url'], 302, 'Wahou Commerce' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External file chosen by the merchant.
			exit;
		}
		self::stream( $source, (string) $row['file_name'] );
	}

	/**
	 * @param array<string, mixed> $file
	 * @return array{type:string, path?:string, relative?:string, url?:string}|null
	 */
	private static function resolve_source( array $file ): ?array {
		if ( ! empty( $file['path'] ) ) {
			$base = realpath( Install::private_dir() );
			if ( ! $base ) {
				return null;
			}
			$relative = ltrim( str_replace( '\\', '/', (string) $file['path'] ), '/' );
			$full     = realpath( $base . DIRECTORY_SEPARATOR . $relative );
			if ( ! $full || 0 !== strpos( $full, $base . DIRECTORY_SEPARATOR ) || ! is_file( $full ) || ! is_readable( $full ) ) {
				return null;
			}
			return array(
				'type'     => 'path',
				'path'     => $full,
				'relative' => ltrim( str_replace( '\\', '/', substr( $full, strlen( $base ) ) ), '/' ),
			);
		}
		if ( ! empty( $file['url'] ) ) {
			$url = esc_url_raw( (string) $file['url'], array( 'https', 'http' ) );
			return $url && wp_http_validate_url( $url ) ? array(
				'type' => 'url',
				'url'  => $url,
			) : null;
		}
		if ( ! empty( $file['attachment_id'] ) ) {
			$path = (string) get_attached_file( (int) $file['attachment_id'] );
			return '' !== $path && is_file( $path ) && is_readable( $path ) ? array(
				'type' => 'attachment',
				'path' => $path,
			) : null;
		}
		return null;
	}

	/**
	 * @param array{type:string, path?:string, relative?:string} $source
	 */
	private static function stream( array $source, string $name ): void {
		$path = (string) $source['path'];
		$name = '' !== trim( $name ) ? wp_basename( $name ) : wp_basename( $path );
		$type = wp_check_filetype( $path );
		$mime = ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream';
		$ascii = preg_replace( '/[^A-Za-z0-9._ -]/', '_', remove_accents( $name ) );

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );

		if ( 'path' === $source['type'] && defined( 'WAHOU_X_ACCEL_PREFIX' ) && WAHOU_X_ACCEL_PREFIX ) {
			$relative = implode( '/', array_map( 'rawurlencode', explode( '/', (string) $source['relative'] ) ) );
			header( 'X-Accel-Redirect: ' . trailingslashit( (string) WAHOU_X_ACCEL_PREFIX ) . $relative );
			exit;
		}
		if ( defined( 'WAHOU_XSENDFILE' ) && WAHOU_XSENDFILE ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}

		$size = filesize( $path );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			exit;
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread
			flush();
			if ( connection_aborted() ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Friendly error page.
	 *
	 * @return never
	 */
	private static function fail( string $message, int $status ): void {
		$account = Pages::id( 'account' ) ? Pages::url( 'account' ) : home_url( '/' );
		$html    = '<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( $account ) . '">' . esc_html__( 'Go to my account', 'wahou-commerce' ) . '</a></p>';
		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html__( 'Download unavailable', 'wahou-commerce' ),
			array(
				'response'  => $status,
				'back_link' => false,
			)
		);
		exit;
	}
}
