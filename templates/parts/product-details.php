<?php
/**
 * Description, specifications, FAQ and reviews as native accordions.
 * All content is in the HTML (crawlers and AI assistants read it without JS).
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Reviews\Reviews;
use WahouCommerce\Settings;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$wahou_content = apply_filters( 'the_content', $product->description() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

// Specifications.
$wahou_specs = array();
foreach ( $product->attributes() as $wahou_attribute ) {
	$wahou_values = array_filter( array_map( 'strval', (array) ( $wahou_attribute['values'] ?? array() ) ) );
	if ( '' !== (string) ( $wahou_attribute['name'] ?? '' ) && $wahou_values ) {
		$wahou_specs[ (string) $wahou_attribute['name'] ] = implode( ', ', $wahou_values );
	}
}
if ( '' !== $product->brand() && '' !== (string) $product->meta( 'brand', '' ) ) {
	$wahou_specs[ __( 'Brand', 'wahou-commerce' ) ] = $product->brand();
}
if ( '' !== $product->sku() ) {
	$wahou_specs[ __( 'SKU', 'wahou-commerce' ) ] = $product->sku();
}
if ( '' !== $product->gtin() ) {
	$wahou_specs[ __( 'GTIN / EAN / ISBN', 'wahou-commerce' ) ] = $product->gtin();
}
if ( '' !== $product->mpn() ) {
	$wahou_specs[ __( 'Manufacturer part number', 'wahou-commerce' ) ] = $product->mpn();
}
if ( $product->is_digital() ) {
	$wahou_formats = Render::file_formats( $product );
	if ( $wahou_formats ) {
		$wahou_specs[ __( 'Format', 'wahou-commerce' ) ] = implode( ', ', $wahou_formats );
	}
	$wahou_specs[ __( 'Delivery', 'wahou-commerce' ) ] = __( 'Instant download after payment', 'wahou-commerce' );
}
if ( $product->weight() > 0 ) {
	$wahou_specs[ __( 'Weight', 'wahou-commerce' ) ] = number_format_i18n( $product->weight(), 2 ) . ' ' . Settings::str( 'weight_unit' );
}
$wahou_dims = array_filter( $product->dimensions() );
if ( 3 === count( $wahou_dims ) ) {
	$wahou_specs[ __( 'Dimensions', 'wahou-commerce' ) ] = implode( ' × ', array_map( static fn( $d ) => number_format_i18n( $d, 1 ), $wahou_dims ) ) . ' ' . Settings::str( 'dimension_unit' );
}
$wahou_categories = $product->categories();
if ( $wahou_categories ) {
	$wahou_specs[ __( 'Category', 'wahou-commerce' ) ] = implode( ', ', wp_list_pluck( $wahou_categories, 'name' ) );
}
$wahou_specs = apply_filters( 'wahou_product_specs', $wahou_specs, $product );

$wahou_faq     = $product->faq();
$wahou_reviews = Reviews::enabled();
?>
<div class="wahou-details">
	<?php if ( '' !== trim( wp_strip_all_tags( (string) $wahou_content ) ) || false !== strpos( (string) $wahou_content, '<img' ) ) : ?>
		<details class="wahou-accordion" id="wahou-description" open>
			<summary class="wahou-accordion__summary"><h2><?php esc_html_e( 'Description', 'wahou-commerce' ); ?></h2><?php echo Render::icon( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
			<div class="wahou-accordion__body wahou-prose"><?php echo $wahou_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered post content. ?></div>
		</details>
	<?php endif; ?>

	<?php if ( $wahou_specs ) : ?>
		<details class="wahou-accordion" id="wahou-specs">
			<summary class="wahou-accordion__summary"><h2><?php esc_html_e( 'Specifications', 'wahou-commerce' ); ?></h2><?php echo Render::icon( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
			<div class="wahou-accordion__body">
				<dl class="wahou-specs">
					<?php foreach ( $wahou_specs as $wahou_label => $wahou_value ) : ?>
						<div class="wahou-specs__row">
							<dt><?php echo esc_html( (string) $wahou_label ); ?></dt>
							<dd><?php echo esc_html( (string) $wahou_value ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</div>
		</details>
	<?php endif; ?>

	<?php if ( $wahou_faq ) : ?>
		<details class="wahou-accordion" id="wahou-faq">
			<summary class="wahou-accordion__summary"><h2><?php esc_html_e( 'Questions & answers', 'wahou-commerce' ); ?></h2><?php echo Render::icon( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
			<div class="wahou-accordion__body">
				<div class="wahou-faq">
					<?php foreach ( $wahou_faq as $wahou_row ) : ?>
						<div class="wahou-faq__item">
							<h3 class="wahou-faq__q"><?php echo esc_html( (string) $wahou_row['q'] ); ?></h3>
							<div class="wahou-faq__a wahou-prose"><?php echo wp_kses_post( wpautop( (string) $wahou_row['a'] ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</details>
	<?php endif; ?>

	<?php if ( $wahou_reviews ) : ?>
		<details class="wahou-accordion" id="wahou-reviews">
			<summary class="wahou-accordion__summary">
				<h2>
					<?php esc_html_e( 'Customer reviews', 'wahou-commerce' ); ?>
					<?php if ( $product->rating_count() > 0 ) : ?>
						<span class="wahou-accordion__count">(<?php echo esc_html( number_format_i18n( $product->rating_count() ) ); ?>)</span>
					<?php endif; ?>
				</h2>
				<?php echo Render::icon( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</summary>
			<div class="wahou-accordion__body">
				<?php Template::render( 'parts/reviews', array( 'product' => $product ) ); ?>
			</div>
		</details>
	<?php endif; ?>
</div>
