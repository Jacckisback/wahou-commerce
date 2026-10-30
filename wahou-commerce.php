<?php
/**
 * Plugin Name:       Wahou Commerce
 * Description:       Frictionless e-commerce for WordPress: mobile-first, lightning fast, built-in SEO & GEO, Stripe and PayPal in two minutes, automatic EU VAT (OSS), bundles, upsells, abandoned cart recovery, physical and digital products.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Thierry Gustin
 * Author URI:        https://gustinai.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wahou-commerce
 * Domain Path:       /languages
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WAHOU_VERSION' ) ) {
	return;
}

define( 'WAHOU_VERSION', '1.0.0' );
define( 'WAHOU_DB_VERSION', '1' );
define( 'WAHOU_FILE', __FILE__ );
define( 'WAHOU_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAHOU_URL', plugin_dir_url( __FILE__ ) );
define( 'WAHOU_BASENAME', plugin_basename( __FILE__ ) );
define( 'WAHOU_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, WAHOU_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			/* translators: 1: required PHP version, 2: current PHP version. */
			echo esc_html( sprintf( __( 'Wahou Commerce requires PHP %1$s or newer. Your server runs PHP %2$s.', 'wahou-commerce' ), WAHOU_MIN_PHP, PHP_VERSION ) );
			echo '</p></div>';
		}
	);
	return;
}

require_once WAHOU_DIR . 'includes/Autoloader.php';
\WahouCommerce\Autoloader::register();
require_once WAHOU_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( \WahouCommerce\Install::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WahouCommerce\Install::class, 'deactivate' ) );

\WahouCommerce\Plugin::boot();
