<?php
/**
 * Sticky add-to-cart bar (mobile), shown when the main button scrolls away.
 *
 * @var \WahouCommerce\Products\Product $product
 * @var string|null                     $variant_id
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

$wahou_variable = 'variable' === $product->type();
$wahou_price    = $wahou_variable && ! $variant_id ? $product->price_range()[0] : $product->price( $variant_id );
$wahou_in_stock = $product->in_stock( $variant_id );
?>
<div class="wahou-sticky" data-wahou-sticky hidden>
	<div class="wahou-sticky__inner">
		<span class="wahou-sticky__thumb"><?php echo Render::image( $product->image_id(), 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="wahou-sticky__info">
			<span class="wahou-sticky__name"><?php echo esc_html( $product->name() ); ?></span>
			<span class="wahou-sticky__price" data-wahou-sticky-price>
				<?php
				if ( $wahou_variable && ! $variant_id && $product->price_range()[0] !== $product->price_range()[1] ) {
					/* translators: %s: lowest price. */
					echo esc_html( sprintf( __( 'From %s', 'wahou-commerce' ), Money::format( $wahou_price ) ) );
				} else {
					echo esc_html( Money::format( $wahou_price ) );
				}
				?>
			</span>
		</span>
		<button type="button" class="wahou-btn wahou-btn--primary" data-wahou-sticky-add<?php disabled( ! $wahou_in_stock && ! $wahou_variable ); ?>>
			<?php echo Render::icon( 'cart', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php echo $wahou_variable && ! $variant_id ? esc_html__( 'Choose', 'wahou-commerce' ) : esc_html__( 'Add to cart', 'wahou-commerce' ); ?></span>
		</button>
	</div>
</div>
