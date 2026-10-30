<?php
/**
 * Product and catalog page templates.
 *
 * Works with classic themes (get_header()/get_footer()) and block themes
 * (header/footer template parts rendered before wp_head(), exactly like the
 * core template canvas, so their styles land in <head>). A theme can override
 * the whole page with wahou-commerce/single-product.php, or only the content
 * with wahou-commerce/parts/product-page.php. A block theme that provides its
 * own single-wahou_product / taxonomy-wahou_product_cat template keeps it.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Frontend;

use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

final class Templates {

	public static function init(): void {
		add_filter( 'template_include', array( self::class, 'template_include' ), 50 );
		add_action( 'pre_get_posts', array( self::class, 'main_query' ) );
	}

	public static function template_include( string $template ): string {
		if ( is_singular( PostTypes::PRODUCT ) ) {
			return self::theme_has_own( $template ) ? $template : Template::locate( 'single-product' );
		}
		if ( is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			return self::theme_has_own( $template ) ? $template : Template::locate( 'archive-product' );
		}
		return $template;
	}

	/**
	 * Did the theme ship a dedicated template for our post type / taxonomies?
	 */
	private static function theme_has_own( string $template ): bool {
		global $_wp_current_template_id;
		$pattern = '#(^|//|/)(single-' . PostTypes::PRODUCT . '|taxonomy-' . PostTypes::CATEGORY . '|taxonomy-' . PostTypes::TAG . ')([.-]|$)#';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return is_string( $_wp_current_template_id ) && (bool) preg_match( $pattern, $_wp_current_template_id );
		}
		return (bool) preg_match( $pattern, basename( $template ) );
	}

	/**
	 * Catalog archives: page size and sort order.
	 */
	public static function main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( array( PostTypes::CATEGORY, PostTypes::TAG ) ) ) {
			return;
		}
		$query->set( 'posts_per_page', max( 1, Settings::int( 'products_per_page' ) ?: 12 ) );
		$query->set( 'post_type', PostTypes::PRODUCT );
		foreach ( Render::orderby_args( Render::current_orderby() ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * Output a full page around a content template.
	 *
	 * @param array<string, mixed> $vars
	 */
	public static function frame( string $content_template, array $vars = array() ): void {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			self::block_theme_frame( $content_template, $vars );
			return;
		}
		get_header();
		echo '<main id="wahou-main" class="wahou-main site-main">';
		echo self::main( $content_template, $vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output.
		echo '</main>';
		get_footer();
	}

	/**
	 * @param array<string, mixed> $vars
	 */
	private static function block_theme_frame( string $content_template, array $vars ): void {
		// Render everything first so blocks can enqueue their assets in <head>.
		$main   = self::main( $content_template, $vars );
		$header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		$footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<?php if ( ! has_action( 'wp_head', '_block_template_viewport_meta_tag' ) ) : ?>
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#wahou-main"><?php esc_html_e( 'Skip to content', 'wahou-commerce' ); ?></a>
<div class="wp-site-blocks">
		<?php
		echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks.
		echo '<main id="wahou-main" class="wahou-main wp-block-group">' . $main . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output.
		echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks.
		?>
</div>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Render the content template inside the loop.
	 *
	 * @param array<string, mixed> $vars
	 */
	private static function main( string $content_template, array $vars ): string {
		ob_start();
		if ( is_singular( PostTypes::PRODUCT ) ) {
			while ( have_posts() ) {
				the_post();
				$product = Product::find( get_the_ID() );
				if ( $product ) {
					Template::render( $content_template, array_merge( $vars, array( 'product' => $product ) ) );
				}
			}
			rewind_posts();
		} else {
			global $wp_query;
			Template::render(
				$content_template,
				array_merge(
					$vars,
					array(
						'query' => $wp_query,
						'term'  => get_queried_object(),
					)
				)
			);
		}
		return (string) ob_get_clean();
	}
}
