<?php
/**
 * Template loader with theme overrides.
 *
 * A theme can override any template by copying it to
 * {theme}/wahou-commerce/{name}.php (child theme first, then parent theme).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Template {

	public static function locate( string $name ): string {
		$name = ltrim( str_replace( array( '..', '\\' ), array( '', '/' ), $name ), '/' );
		$file = $name . '.php';

		$theme_file = locate_template( array( 'wahou-commerce/' . $file ) );
		$path       = $theme_file ? $theme_file : WAHOU_DIR . 'templates/' . $file;

		/**
		 * Filters the resolved path of a template.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template name (without .php).
		 */
		return (string) apply_filters( 'wahou_template_path', $path, $name );
	}

	/**
	 * Output a template. Variables are extracted into the template scope.
	 *
	 * @param array<string, mixed> $vars
	 */
	public static function render( string $name, array $vars = array() ): void {
		$path = self::locate( $name );
		if ( ! is_readable( $path ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				echo '<!-- wahou: missing template ' . esc_html( $name ) . ' -->';
			}
			return;
		}
		/**
		 * Filters the variables passed to a template.
		 */
		$vars = apply_filters( 'wahou_template_vars', $vars, $name );
		( static function ( string $__path, array $__vars ): void {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $__path;
		} )( $path, $vars );
	}

	/**
	 * Return a rendered template as a string.
	 *
	 * @param array<string, mixed> $vars
	 */
	public static function get( string $name, array $vars = array() ): string {
		ob_start();
		self::render( $name, $vars );
		return (string) ob_get_clean();
	}
}
