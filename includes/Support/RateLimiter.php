<?php
/**
 * Fixed-window rate limiter backed by transients (object cache friendly).
 * Used against card-testing attacks, coupon brute force and email spam.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Count a hit and tell whether it is allowed.
	 *
	 * @param string      $action  Bucket name, e.g. "checkout".
	 * @param int         $limit   Allowed hits per window.
	 * @param int         $window  Window length in seconds.
	 * @param string|null $subject Identifier (defaults to the client IP).
	 */
	public static function hit( string $action, int $limit, int $window, ?string $subject = null ): bool {
		if ( $limit <= 0 ) {
			return true;
		}
		$subject = $subject ?? Request::ip();
		$key     = 'wahou_rl_' . substr( md5( $action . '|' . $subject ), 0, 20 );
		$bucket  = get_transient( $key );
		$now     = time();

		if ( ! is_array( $bucket ) || $bucket['reset'] <= $now ) {
			$bucket = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}
		++$bucket['count'];
		set_transient( $key, $bucket, max( 1, $bucket['reset'] - $now ) );

		$allowed = $bucket['count'] <= $limit;
		if ( ! $allowed ) {
			/**
			 * Fires when a client exceeds a rate limit.
			 */
			do_action( 'wahou_rate_limited', $action, $subject );
		}
		return $allowed;
	}

	public static function reset( string $action, ?string $subject = null ): void {
		$subject = $subject ?? Request::ip();
		delete_transient( 'wahou_rl_' . substr( md5( $action . '|' . $subject ), 0, 20 ) );
	}
}
