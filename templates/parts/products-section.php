<?php
/**
 * Products block / [wahou_products] shortcode output.
 *
 * @var \WP_Query                         $query
 * @var \WahouCommerce\Products\Product[] $products
 * @var int                               $columns
 * @var bool                              $pagination
 * @var bool                              $filters
 * @var string                            $orderby
 * @var int                               $paged
 * @var string                            $title
 * @var string                            $class
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;
?>
<div class="wahou wahou-products<?php echo '' !== $class ? ' ' . esc_attr( $class ) : ''; ?>">
	<?php if ( '' !== $title ) : ?>
		<h2 class="wahou-section-title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<?php if ( $filters ) : ?>
		<?php
		Template::render(
			'parts/catalog-toolbar',
			array(
				'total'        => (int) $query->found_posts,
				'orderby'      => $orderby,
				'current_term' => 0,
				'show_chips'   => true,
			)
		);
		?>
	<?php endif; ?>

	<?php if ( $products ) : ?>
		<?php
		echo Render::grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$products,
			array(
				'columns' => $columns,
				'heading' => '' !== $title ? 'h3' : 'h2',
				'list'    => 'products',
			)
		);
		if ( $pagination ) {
			echo Render::pagination( (int) $query->max_num_pages, $paged ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	<?php else : ?>
		<div class="wahou-empty">
			<?php echo Render::icon( 'tag', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'No products found.', 'wahou-commerce' ); ?></p>
			<?php if ( current_user_can( 'edit_wahou_products' ) ) : ?>
				<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wahou_product' ) ); ?>"><?php esc_html_e( 'Add your first product', 'wahou-commerce' ); ?></a>
			<?php elseif ( Pages::id( 'shop' ) && ! Pages::is( 'shop' ) ) : ?>
				<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( Pages::url( 'shop' ) ); ?>"><?php esc_html_e( 'Browse all products', 'wahou-commerce' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
