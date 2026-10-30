<?php
/**
 * Low stock alert for the store owner.
 *
 * @var \WahouCommerce\Products\Product $product
 * @var int                             $stock
 * @var string                          $variant_label
 * @var string                          $edit_url
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;

defined( 'ABSPATH' ) || exit;
?>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
	<?php
	/* translators: 1: product name, 2: units left. */
	echo esc_html( sprintf( _n( '"%1$s" has only %2$d unit left in stock.', '"%1$s" has only %2$d units left in stock.', max( 0, $stock ), 'wahou-commerce' ), $product->name(), $stock ) );
	?>
</p>
<?php if ( '' !== $variant_label ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( $variant_label ); ?></p>
<?php endif; ?>
<?php if ( '' !== $product->sku() ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>">
		<?php
		/* translators: %s: SKU. */
		echo esc_html( sprintf( __( 'SKU: %s', 'wahou-commerce' ), $product->sku() ) );
		?>
	</p>
<?php endif; ?>
<?php
if ( '' !== $edit_url ) {
	echo Mailer::button( $edit_url, __( 'Restock this product', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button().
}
