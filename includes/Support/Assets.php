<?php
/**
 * Asset URLs: minified files are served unless SCRIPT_DEBUG is on.
 *
 * Minified copies (*.min.js / *.min.css) are generated at release time from
 * the readable sources kept next to them.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Support;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Public URL of an asset, e.g. Assets::url( 'js/wahou.js' ).
	 */
	public static function url( string $path ): string {
		$path = ltrim( $path, '/' );
		if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
			$min = preg_replace( '/\.(js|css)$/', '.min.$1', $path );
			if ( $min && is_readable( WAHOU_DIR . 'assets/' . $min ) ) {
				$path = $min;
			}
		}
		return WAHOU_URL . 'assets/' . $path;
	}
}
