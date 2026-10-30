<?php
/**
 * PSR-4 style autoloader: WahouCommerce\Foo\Bar => includes/Foo/Bar.php
 *
 * @package WahouCommerce
 */

namespace WahouCommerce;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WAHOU_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
