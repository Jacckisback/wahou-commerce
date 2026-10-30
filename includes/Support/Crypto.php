<?php
/**
 * Encryption, signing and random tokens.
 *
 * Secrets are encrypted with XSalsa20-Poly1305 (sodium secretbox; WordPress
 * ships sodium_compat so it is always available). The key is derived from
 * WAHOU_ENCRYPTION_KEY when defined, otherwise from the site salts.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const PREFIX = 'wv1:';

	private static function key(): string {
		$material = defined( 'WAHOU_ENCRYPTION_KEY' ) ? (string) WAHOU_ENCRYPTION_KEY : wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material . '|wahou-commerce', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Returns '' when the value cannot be decrypted (e.g. salts were rotated).
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}
		if ( 0 !== strpos( $encoded, self::PREFIX ) ) {
			return $encoded; // Legacy plain value.
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}

	/**
	 * URL-safe random token.
	 */
	public static function token( int $bytes = 24 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Alphanumeric random key (safe in URLs and REST route regexes).
	 */
	public static function key_string( int $length = 32 ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	public static function sign( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) . '|wahou' );
	}

	public static function verify( string $data, string $signature ): bool {
		return '' !== $signature && hash_equals( self::sign( $data ), $signature );
	}

	/**
	 * Short signature for public links (recovery, unsubscribe...).
	 */
	public static function short_sign( string $data ): string {
		return substr( self::sign( $data ), 0, 20 );
	}

	public static function verify_short( string $data, string $signature ): bool {
		return '' !== $signature && hash_equals( self::short_sign( $data ), $signature );
	}
}
