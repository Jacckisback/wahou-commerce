<?php
/**
 * Product page content.
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$variant_id = Render::requested_variant( $product );
$item       = Render::analytics_item( $product, $variant_id );
?>
<div class="wahou wahou-product-page wahou-container" data-wahou-product="<?php echo (int) $product->id(); ?>">
	<?php Template::render( 'parts/breadcrumbs' ); ?>
	<?php echo Render::query_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>

	<?php if ( post_password_required( $product->post() ) ) : ?>
		<div class="wahou-product-locked"><?php echo get_the_password_form( $product->post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<?php else : ?>
		<div class="wahou-product">
			<div class="wahou-product__media">
				<?php Template::render( 'parts/product-gallery', array( 'product' => $product ) ); ?>
			</div>
			<div class="wahou-product__summary">
				<?php
				Template::render(
					'parts/product-summary',
					array(
						'product'    => $product,
						'variant_id' => $variant_id,
					)
				);
				?>
			</div>
		</div>

		<?php Template::render( 'parts/fbt', array( 'product' => $product ) ); ?>
		<?php Template::render( 'parts/product-details', array( 'product' => $product ) ); ?>
		<?php Template::render( 'parts/related', array( 'product' => $product ) ); ?>

		<?php if ( Settings::bool( 'sticky_atc' ) && $product->is_purchasable() ) : ?>
			<?php
			Template::render(
				'parts/sticky-atc',
				array(
					'product'    => $product,
					'variant_id' => $variant_id,
				)
			);
			?>
		<?php endif; ?>

		<script type="application/json" data-wahou-view-item><?php echo wp_json_encode( array( 'currency' => Settings::currency(), 'value' => $item['price'], 'items' => array( $item ) ) ); ?></script>
	<?php endif; ?>
</div>
