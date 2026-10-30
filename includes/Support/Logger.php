<?php
/**
 * Minimal file logger. Errors are always logged; debug/info only when the
 * "debug_log" setting is on. Log files live in uploads/wahou-logs/ behind a
 * deny-all .htaccess and a random file suffix. Sensitive keys are redacted.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private const REDACT = '/(secret|password|passwd|token|authorization|card|cvc|iban|client_secret|api_key)/i';

	/**
	 * @param array<string, mixed> $context
	 */
	public static function error( string $channel, string $message, array $context = array() ): void {
		self::write( 'ERROR', $channel, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function info( string $channel, string $message, array $context = array() ): void {
		if ( Settings::bool( 'debug_log' ) ) {
			self::write( 'INFO', $channel, $message, $context );
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function debug( string $channel, string $message, array $context = array() ): void {
		if ( Settings::bool( 'debug_log' ) ) {
			self::write( 'DEBUG', $channel, $message, $context );
		}
	}

	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wahou-logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::protect_dir( $dir );
		}
		return $dir;
	}

	/**
	 * Drop deny-all files in a directory (Apache + index fallback).
	 */
	public static function protect_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "# Wahou Commerce – private files\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\nOptions -Indexes\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Absolute path of today's file for a channel.
	 */
	public static function file( string $channel ): string {
		$channel = sanitize_key( $channel );
		$suffix  = substr( hash( 'sha256', $channel . wp_salt( 'auth' ) ), 0, 12 );
		return self::dir() . '/' . $channel . '-' . gmdate( 'Y-m-d' ) . '-' . $suffix . '.log';
	}

	/**
	 * Recent log files, newest first.
	 *
	 * @return string[]
	 */
	public static function files(): array {
		$files = glob( self::dir() . '/*.log' );
		if ( ! $files ) {
			return array();
		}
		usort( $files, static fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
		return $files;
	}

	public static function purge( int $days = 30 ): void {
		foreach ( self::files() as $file ) {
			if ( filemtime( $file ) < time() - $days * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function write( string $level, string $channel, string $message, array $context ): void {
		$line = sprintf( '[%s] %s %s', gmdate( 'c' ), $level, $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( self::file( $channel ), $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::REDACT, $key ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}
}
