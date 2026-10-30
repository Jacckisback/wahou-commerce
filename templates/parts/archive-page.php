<?php
/**
 * Category / tag archive content.
 *
 * @var \WP_Query     $query
 * @var \WP_Term|null $term
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$wahou_term     = $term instanceof \WP_Term ? $term : null;
$wahou_products = Render::products( $query->posts );
$wahou_paged    = max( 1, (int) $query->get( 'paged' ) );
?>
<div class="wahou wahou-archive wahou-container">
	<?php Template::render( 'parts/breadcrumbs' ); ?>
	<header class="wahou-archive__header">
		<h1 class="wahou-archive__title"><?php echo esc_html( $wahou_term ? $wahou_term->name : __( 'Products', 'wahou-commerce' ) ); ?></h1>
		<?php if ( $wahou_term && '' !== trim( (string) $wahou_term->description ) && 1 === $wahou_paged ) : ?>
			<div class="wahou-archive__description wahou-prose"><?php echo wp_kses_post( wpautop( $wahou_term->description ) ); ?></div>
		<?php endif; ?>
	</header>

	<?php
	Template::render(
		'parts/catalog-toolbar',
		array(
			'total'        => (int) $query->found_posts,
			'orderby'      => Render::current_orderby(),
			'current_term' => $wahou_term ? (int) $wahou_term->term_id : 0,
			'show_chips'   => true,
		)
	);
	?>

	<?php if ( $wahou_products ) : ?>
		<?php
		echo Render::grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$wahou_products,
			array(
				'columns' => Settings::int( 'catalog_columns' ) ?: 3,
				'heading' => 'h2',
				'list'    => $wahou_term ? $wahou_term->slug : 'archive',
			)
		);
		echo Render::pagination( (int) $query->max_num_pages, $wahou_paged, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php else : ?>
		<div class="wahou-empty">
			<?php echo Render::icon( 'tag', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'No products here yet.', 'wahou-commerce' ); ?></p>
			<?php if ( Pages::id( 'shop' ) ) : ?>
				<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( Pages::url( 'shop' ) ); ?>"><?php esc_html_e( 'Browse all products', 'wahou-commerce' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
