<?php
/**
 * "Frequently bought together": the add-ons get the product's FBT discount
 * as long as this product is in the cart (enforced server side).
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

if ( ! $product->is_purchasable() || ! $product->in_stock() ) {
	return;
}
$wahou_items = Render::fbt_items( $product );
if ( ! $wahou_items ) {
	return;
}
$wahou_discount = $product->fbt_discount();
$wahou_variable = 'variable' === $product->type();
$wahou_main     = $wahou_variable ? $product->price_range()[0] : $product->price();
$wahou_total    = $wahou_main;
$wahou_regular  = $wahou_main;
?>
<section class="wahou-fbt" data-wahou-fbt data-parent="<?php echo (int) $product->id(); ?>" data-main-price="<?php echo (int) $wahou_main; ?>" aria-labelledby="wahou-fbt-title">
	<h2 id="wahou-fbt-title" class="wahou-section-title"><?php esc_html_e( 'Frequently bought together', 'wahou-commerce' ); ?></h2>
	<?php if ( $wahou_discount > 0 ) : ?>
		<p class="wahou-fbt__promo">
			<?php echo Render::icon( 'tag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php
			/* translators: %s: discount percentage. */
			echo esc_html( sprintf( __( '%s%% off the add-ons when bought together.', 'wahou-commerce' ), number_format_i18n( $wahou_discount, floor( $wahou_discount ) == $wahou_discount ? 0 : 1 ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			?>
		</p>
	<?php endif; ?>
	<form class="wahou-fbt__form" method="post" action="<?php echo esc_url( Pages::id( 'cart' ) ? Pages::url( 'cart' ) : $product->permalink() ); ?>">
		<?php echo Render::form_fields( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<input type="hidden" name="qty" value="1">
		<?php if ( $wahou_variable ) : ?>
			<input type="hidden" name="wahou_fbt_only" value="1" data-wahou-fbt-only>
		<?php endif; ?>
		<ul class="wahou-fbt__list">
			<li class="wahou-fbt__item is-current">
				<span class="wahou-fbt__check"><?php echo Render::icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="wahou-fbt__thumb"><?php echo Render::image( $product->image_id(), 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="wahou-fbt__name"><strong><?php esc_html_e( 'This item:', 'wahou-commerce' ); ?></strong> <?php echo esc_html( $product->name() ); ?></span>
				<span class="wahou-fbt__price" data-wahou-fbt-main><?php echo esc_html( Money::format( $wahou_main ) ); ?></span>
			</li>
			<?php foreach ( $wahou_items as $wahou_item ) : ?>
				<?php
				$wahou_regular_price = $wahou_item->price();
				$wahou_price         = $wahou_discount > 0 ? (int) round( $wahou_regular_price * ( 1 - $wahou_discount / 100 ) ) : $wahou_regular_price;
				$wahou_total        += $wahou_price;
				$wahou_regular      += $wahou_regular_price;
				$wahou_input_id      = 'wahou-fbt-' . $product->id() . '-' . $wahou_item->id();
				?>
				<li class="wahou-fbt__item">
					<input class="wahou-fbt__input" type="checkbox" id="<?php echo esc_attr( $wahou_input_id ); ?>" name="fbt[]" value="<?php echo (int) $wahou_item->id(); ?>" checked data-wahou-fbt-item data-price="<?php echo (int) $wahou_price; ?>" data-regular="<?php echo (int) $wahou_regular_price; ?>">
					<label class="wahou-fbt__label" for="<?php echo esc_attr( $wahou_input_id ); ?>">
						<span class="wahou-fbt__thumb"><?php echo Render::image( $wahou_item->image_id(), 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="wahou-fbt__name"><?php echo esc_html( $wahou_item->name() ); ?></span>
						<span class="wahou-fbt__price">
							<?php echo esc_html( Money::format( $wahou_price ) ); ?>
							<?php if ( $wahou_price < $wahou_regular_price ) : ?>
								<del><?php echo esc_html( Money::format( $wahou_regular_price ) ); ?></del>
							<?php endif; ?>
						</span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="wahou-fbt__footer">
			<p class="wahou-fbt__total">
				<span><?php esc_html_e( 'Total:', 'wahou-commerce' ); ?></span>
				<strong data-wahou-fbt-total><?php echo esc_html( Money::format( $wahou_total ) ); ?></strong>
				<del data-wahou-fbt-regular<?php echo $wahou_regular > $wahou_total ? '' : ' hidden'; ?>><?php echo esc_html( Money::format( $wahou_regular ) ); ?></del>
			</p>
			<button type="submit" class="wahou-btn wahou-btn--secondary" data-wahou-fbt-add data-label-all="<?php esc_attr_e( 'Add the selection to cart', 'wahou-commerce' ); ?>">
				<?php echo Render::icon( 'plus', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Add the selection to cart', 'wahou-commerce' ); ?></span>
			</button>
		</div>
	</form>
</section>
