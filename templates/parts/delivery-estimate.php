<?php
/**
 * Delivery estimate. The static sentence is what crawlers and no-JS visitors
 * read; the script replaces it with real dates (cut-off time, business days).
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;

defined( 'ABSPATH' ) || exit;

$wahou_delivery = Render::delivery( $product );
if ( ! $wahou_delivery ) {
	return;
}
$wahou_icon = $product->needs_shipping() ? 'truck' : 'bolt';
?>
<div class="wahou-delivery"<?php echo $wahou_delivery['data'] ? ' data-wahou-delivery="' . esc_attr( wp_json_encode( $wahou_delivery['data'] ) ) . '"' : ''; ?>>
	<?php echo Render::icon( $wahou_icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<div class="wahou-delivery__text">
		<p class="wahou-delivery__main" data-wahou-delivery-main><?php echo esc_html( $wahou_delivery['text'] ); ?></p>
		<p class="wahou-delivery__sub" data-wahou-delivery-sub hidden></p>
	</div>
</div>
