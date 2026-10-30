<?php
/**
 * Product summary: title, rating, price, key benefits and the buy box.
 *
 * @var \WahouCommerce\Products\Product $product
 * @var string|null                     $variant_id
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$wahou_excerpt = $product->short_description();
$wahou_formats = $product->is_digital() ? Render::file_formats( $product ) : array();
?>
<header class="wahou-product__header">
	<?php if ( '' !== $product->badge() ) : ?>
		<span class="wahou-badge wahou-badge--custom"><?php echo esc_html( $product->badge() ); ?></span>
	<?php endif; ?>
	<h1 class="wahou-product__title"><?php echo esc_html( $product->name() ); ?></h1>
	<?php if ( $product->rating_count() > 0 ) : ?>
		<div class="wahou-product__rating">
			<?php echo Render::stars( $product->rating_average(), $product->rating_count(), true, '#wahou-reviews' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
</header>

<div class="wahou-product__price" data-wahou-price-target>
	<?php echo Render::price( $product, $variant_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
</div>

<?php if ( '' !== trim( $wahou_excerpt ) ) : ?>
	<div class="wahou-product__excerpt wahou-prose"><?php echo wp_kses_post( wpautop( $wahou_excerpt ) ); ?></div>
<?php endif; ?>

<?php if ( $product->highlights() ) : ?>
	<ul class="wahou-highlights">
		<?php foreach ( $product->highlights() as $wahou_highlight ) : ?>
			<li><?php echo Render::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $wahou_highlight ); ?></span></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( 'bundle' === $product->type() ) : ?>
	<?php Template::render( 'parts/bundle-items', array( 'product' => $product ) ); ?>
<?php endif; ?>

<?php if ( $wahou_formats ) : ?>
	<p class="wahou-product__formats">
		<?php echo Render::icon( 'download', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<?php
			/* translators: %s: list of file formats, e.g. "PDF, EPUB". */
			echo esc_html( sprintf( __( 'Formats: %s', 'wahou-commerce' ), implode( ', ', $wahou_formats ) ) );
			?>
		</span>
	</p>
<?php endif; ?>

<?php
Template::render(
	'parts/add-to-cart',
	array(
		'product'    => $product,
		'variant_id' => $variant_id,
	)
);
?>

<?php Template::render( 'parts/delivery-estimate', array( 'product' => $product ) ); ?>
<?php Template::render( 'parts/trust-badges', array( 'product' => $product ) ); ?>
