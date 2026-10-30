<?php
/**
 * Bundle contents and savings.
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

$wahou_items = $product->bundle_items();
if ( ! $wahou_items ) {
	return;
}
$wahou_total = 0;
?>
<div class="wahou-bundle">
	<p class="wahou-bundle__title"><?php esc_html_e( 'This bundle includes:', 'wahou-commerce' ); ?></p>
	<ul class="wahou-bundle__list">
		<?php foreach ( $wahou_items as $wahou_item ) : ?>
			<?php
			$wahou_component = Product::find( $wahou_item['product_id'] );
			if ( ! $wahou_component ) {
				continue;
			}
			$wahou_total += $wahou_component->price() * $wahou_item['qty'];
			?>
			<li class="wahou-bundle__item">
				<span class="wahou-bundle__thumb"><?php echo Render::image( $wahou_component->image_id(), 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="wahou-bundle__name">
					<?php if ( $wahou_item['qty'] > 1 ) : ?>
						<span class="wahou-bundle__qty"><?php echo esc_html( $wahou_item['qty'] . '×' ); ?></span>
					<?php endif; ?>
					<?php if ( 'publish' === $wahou_component->status() ) : ?>
						<a href="<?php echo esc_url( $wahou_component->permalink() ); ?>"><?php echo esc_html( $wahou_component->name() ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $wahou_component->name() ); ?>
					<?php endif; ?>
				</span>
				<span class="wahou-bundle__price"><?php echo esc_html( Money::format( $wahou_component->price() * $wahou_item['qty'] ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $wahou_total > $product->price() ) : ?>
		<p class="wahou-bundle__saving">
			<?php echo Render::icon( 'gift', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span>
				<?php
				/* translators: 1: amount saved, 2: price of the items bought separately. */
				echo esc_html( sprintf( __( 'You save %1$s compared to buying separately (%2$s).', 'wahou-commerce' ), Money::format( $wahou_total - $product->price() ), Money::format( $wahou_total ) ) );
				?>
			</span>
		</p>
	<?php endif; ?>
</div>
