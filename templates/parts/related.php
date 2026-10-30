<?php
/**
 * Upsells ("Recommended for you") and related products.
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;

defined( 'ABSPATH' ) || exit;

$wahou_upsells = Render::products( $product->upsell_ids() );
$wahou_upsells = array_slice( array_values( array_filter( $wahou_upsells, static fn( $p ) => $p->is_purchasable() ) ), 0, 4 );
$wahou_exclude = array_map( static fn( $p ) => $p->id(), $wahou_upsells );
$wahou_related = Render::related( $product, 4, $wahou_exclude );
?>
<?php if ( $wahou_upsells ) : ?>
	<section class="wahou-related wahou-related--upsells" aria-labelledby="wahou-upsells-title">
		<h2 id="wahou-upsells-title" class="wahou-section-title"><?php esc_html_e( 'Recommended for you', 'wahou-commerce' ); ?></h2>
		<?php
		echo Render::grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$wahou_upsells,
			array(
				'columns' => 4,
				'heading' => 'h3',
				'list'    => 'upsells',
			)
		);
		?>
	</section>
<?php endif; ?>
<?php if ( $wahou_related ) : ?>
	<section class="wahou-related" aria-labelledby="wahou-related-title">
		<h2 id="wahou-related-title" class="wahou-section-title"><?php esc_html_e( 'You may also like', 'wahou-commerce' ); ?></h2>
		<?php
		echo Render::grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$wahou_related,
			array(
				'columns' => 4,
				'heading' => 'h3',
				'list'    => 'related',
			)
		);
		?>
	</section>
<?php endif; ?>
