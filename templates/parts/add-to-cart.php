<?php
/**
 * Buy box: variant picker, stock, quantity, add to cart and buy now.
 * Works without JavaScript (posts to the cart page).
 *
 * @var \WahouCommerce\Products\Product $product
 * @var string|null                     $variant_id
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

if ( ! $product->is_purchasable() ) {
	return;
}

$wahou_variable = 'variable' === $product->type();
$wahou_selected = $wahou_variable && $variant_id ? $product->variant( $variant_id ) : null;
$wahou_in_stock = $wahou_variable ? ( $wahou_selected ? $product->in_stock( $variant_id ) : true ) : $product->in_stock();
$wahou_max      = $product->max_qty( $wahou_selected ? $variant_id : null );
$wahou_action   = Pages::id( 'cart' ) ? Pages::url( 'cart' ) : $product->permalink();
?>
<form class="wahou-atc" method="post" action="<?php echo esc_url( $wahou_action ); ?>" data-wahou-atc data-product-id="<?php echo (int) $product->id(); ?>"<?php echo $wahou_variable ? ' data-wahou-variants' : ''; ?>>
	<?php echo Render::form_fields( $product, $wahou_selected ? (string) $variant_id : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>

	<?php if ( $wahou_variable ) : ?>
		<div class="wahou-variants">
			<?php foreach ( $product->attributes() as $wahou_index => $wahou_attribute ) : ?>
				<?php
				$wahou_name   = (string) ( $wahou_attribute['name'] ?? '' );
				$wahou_values = array_values( array_filter( array_map( 'strval', (array) ( $wahou_attribute['values'] ?? array() ) ), static fn( $v ) => '' !== $v ) );
				if ( '' === $wahou_name || ! $wahou_values ) {
					continue;
				}
				$wahou_current = $wahou_selected && isset( $wahou_selected['attributes'][ $wahou_name ] ) ? (string) $wahou_selected['attributes'][ $wahou_name ] : '';
				?>
				<fieldset class="wahou-variant" data-wahou-attr="<?php echo esc_attr( $wahou_name ); ?>">
					<legend class="wahou-variant__legend">
						<?php echo esc_html( $wahou_name ); ?><span class="wahou-variant__value" data-wahou-attr-value<?php echo '' === $wahou_current ? ' hidden' : ''; ?>><?php echo esc_html( $wahou_current ); ?></span>
					</legend>
					<div class="wahou-variant__options">
						<?php foreach ( $wahou_values as $wahou_v => $wahou_value ) : ?>
							<?php $wahou_input_id = 'wahou-attr-' . $product->id() . '-' . $wahou_index . '-' . $wahou_v; ?>
							<label class="wahou-chip" for="<?php echo esc_attr( $wahou_input_id ); ?>">
								<input type="radio" id="<?php echo esc_attr( $wahou_input_id ); ?>" name="<?php echo esc_attr( 'wahou_attr_' . $wahou_index ); ?>" value="<?php echo esc_attr( $wahou_value ); ?>"<?php checked( $wahou_current, $wahou_value ); ?>>
								<span><?php echo esc_html( $wahou_value ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>
			<script type="application/json" data-wahou-variants-json><?php echo wp_json_encode( Render::variants_data( $product ) ); ?></script>
		</div>
		<noscript>
			<label class="wahou-field">
				<span class="wahou-field__label"><?php esc_html_e( 'Option', 'wahou-commerce' ); ?></span>
				<select class="wahou-select" name="variant_id">
					<?php foreach ( $product->variants() as $wahou_variant ) : ?>
						<option value="<?php echo esc_attr( (string) $wahou_variant['id'] ); ?>"<?php disabled( ! $product->in_stock( (string) $wahou_variant['id'] ) ); ?>><?php echo esc_html( $product->variant_label( (string) $wahou_variant['id'] ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</noscript>
	<?php endif; ?>

	<div class="wahou-atc__stock" data-wahou-stock>
		<?php
		if ( ! $wahou_variable || $wahou_selected ) {
			echo Render::stock( $product, $wahou_selected ? $variant_id : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper.
		}
		?>
	</div>

	<p class="wahou-atc__error" data-wahou-atc-error role="alert" hidden></p>

	<div class="wahou-atc__row">
		<?php if ( ! $product->sold_individually() ) : ?>
			<?php echo Render::quantity( 'qty', 1, 1, $wahou_max, 'wahou-qty-' . $product->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php else : ?>
			<input type="hidden" name="qty" value="1">
		<?php endif; ?>
		<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--lg wahou-atc__add" data-wahou-atc-button data-label="<?php esc_attr_e( 'Add to cart', 'wahou-commerce' ); ?>"<?php disabled( ! $wahou_in_stock ); ?>>
			<?php echo Render::icon( 'cart', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span data-wahou-atc-label><?php echo $wahou_in_stock ? esc_html__( 'Add to cart', 'wahou-commerce' ) : esc_html__( 'Out of stock', 'wahou-commerce' ); ?></span>
		</button>
	</div>

	<?php if ( Settings::bool( 'buy_now_button' ) ) : ?>
		<button type="submit" name="wahou_buy_now" value="1" class="wahou-btn wahou-btn--dark wahou-btn--lg wahou-btn--block wahou-atc__buy" data-wahou-buy-now<?php disabled( ! $wahou_in_stock ); ?>>
			<?php echo Render::icon( 'bolt', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'Buy now', 'wahou-commerce' ); ?></span>
		</button>
	<?php endif; ?>

	<?php if ( $wahou_variable || '' !== $product->sku() ) : ?>
		<p class="wahou-atc__sku"<?php echo '' === $product->sku( $wahou_selected ? $variant_id : null ) ? ' hidden' : ''; ?>>
			<?php esc_html_e( 'SKU:', 'wahou-commerce' ); ?> <span data-wahou-sku><?php echo esc_html( $product->sku( $wahou_selected ? $variant_id : null ) ); ?></span>
		</p>
	<?php endif; ?>
</form>
