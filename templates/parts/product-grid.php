<?php
/**
 * Responsive product grid (2 columns on phones, up to $columns on desktop).
 *
 * @var \WahouCommerce\Products\Product[] $products
 * @var int                               $columns
 * @var string                            $heading Card title tag (h2|h3|h4).
 * @var string                            $list    Analytics list name.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

if ( empty( $products ) ) {
	return;
}
?>
<ul class="wahou-grid wahou-grid--c<?php echo (int) $columns; ?>" style="--wahou-cols:<?php echo (int) $columns; ?>"<?php echo '' !== $list ? ' data-wahou-list="' . esc_attr( $list ) . '"' : ''; ?>>
	<?php foreach ( $products as $wahou_position => $wahou_product ) : ?>
		<li class="wahou-grid__item">
			<?php
			Template::render(
				'parts/product-card',
				array(
					'product'  => $wahou_product,
					'heading'  => $heading,
					'position' => $wahou_position,
				)
			);
			?>
		</li>
	<?php endforeach; ?>
</ul>
