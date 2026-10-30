<?php
/**
 * Product card.
 *
 * @var \WahouCommerce\Products\Product $product
 * @var string                          $heading  Title tag (default h3).
 * @var int                             $position Position in the list (0 = first, eager image).
 * @var bool                            $featured Spotlight layout with excerpt.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

$wahou_heading  = isset( $heading ) && in_array( $heading, array( 'h2', 'h3', 'h4' ), true ) ? $heading : 'h3';
$wahou_featured = ! empty( $featured );
$wahou_position = isset( $position ) ? (int) $position : 99;
$wahou_url      = $product->permalink();
$wahou_type     = $product->type();
$wahou_quick    = in_array( $wahou_type, array( 'simple', 'bundle' ), true ) && $product->in_stock() && $product->is_purchasable();
$wahou_img_attr = array(
	'class' => 'wahou-card__img',
	'sizes' => $wahou_featured ? '(min-width: 900px) 40vw, 100vw' : '(min-width: 1200px) 300px, (min-width: 900px) 25vw, 50vw',
);
if ( $wahou_position < 2 && $wahou_featured ) {
	$wahou_img_attr['loading'] = 'eager';
}
?>
<article class="wahou-card<?php echo $wahou_featured ? ' wahou-card--featured' : ''; ?><?php echo $product->in_stock() ? '' : ' is-out-of-stock'; ?>" data-product-id="<?php echo (int) $product->id(); ?>">
	<a class="wahou-card__media" href="<?php echo esc_url( $wahou_url ); ?>" tabindex="-1" aria-hidden="true">
		<?php echo Render::image( $product->image_id(), $wahou_featured ? 'large' : 'medium_large', $wahou_img_attr, $product->name() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo Render::badges( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</a>
	<div class="wahou-card__body">
		<<?php echo esc_attr( $wahou_heading ); ?> class="wahou-card__title"><a href="<?php echo esc_url( $wahou_url ); ?>"><?php echo esc_html( $product->name() ); ?></a></<?php echo esc_attr( $wahou_heading ); ?>>
		<?php if ( $product->rating_count() > 0 ) : ?>
			<div class="wahou-card__rating"><?php echo Render::stars( $product->rating_average(), $product->rating_count() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
		<?php if ( $wahou_featured && '' !== $product->summary( 30 ) ) : ?>
			<p class="wahou-card__excerpt"><?php echo esc_html( $product->summary( 30 ) ); ?></p>
		<?php endif; ?>
		<div class="wahou-card__price"><?php echo Render::card_price( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	</div>
	<div class="wahou-card__actions">
		<?php if ( $wahou_quick ) : ?>
			<form class="wahou-card__form" method="post" action="<?php echo esc_url( Pages::id( 'cart' ) ? Pages::url( 'cart' ) : $wahou_url ); ?>" data-wahou-atc data-product-id="<?php echo (int) $product->id(); ?>">
				<?php echo Render::form_fields( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="qty" value="1">
				<button type="submit" class="wahou-btn wahou-btn--secondary wahou-btn--block" data-wahou-atc-button>
					<?php echo Render::icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span data-wahou-atc-label><?php esc_html_e( 'Add to cart', 'wahou-commerce' ); ?></span>
					<span class="screen-reader-text"><?php echo esc_html( ': ' . $product->name() ); ?></span>
				</button>
			</form>
		<?php elseif ( 'variable' === $wahou_type && $product->in_stock() ) : ?>
			<a class="wahou-btn wahou-btn--ghost wahou-btn--block" href="<?php echo esc_url( $wahou_url ); ?>">
				<?php esc_html_e( 'Choose options', 'wahou-commerce' ); ?><span class="screen-reader-text"><?php echo esc_html( ': ' . $product->name() ); ?></span>
			</a>
		<?php else : ?>
			<span class="wahou-card__oos"><?php esc_html_e( 'Out of stock', 'wahou-commerce' ); ?></span>
		<?php endif; ?>
	</div>
</article>
