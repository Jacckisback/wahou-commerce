<?php
/**
 * Catalog toolbar: category chips, product count and sort order.
 *
 * @var int      $total       Number of products found.
 * @var string   $orderby     Current sort order.
 * @var int      $current_term Current category term ID (0 = all).
 * @var bool     $show_chips
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

$wahou_chips   = ! empty( $show_chips ) ? Render::category_chips() : array();
$wahou_current = isset( $current_term ) ? (int) $current_term : 0;
$wahou_action  = remove_query_arg( array( 'orderby', 'paged', 'wahou_notice' ), get_pagenum_link( 1, false ) );
$wahou_select  = 'wahou-sort-' . wp_unique_id();
?>
<div class="wahou-toolbar">
	<?php if ( $wahou_chips ) : ?>
		<nav class="wahou-chips" aria-label="<?php esc_attr_e( 'Product categories', 'wahou-commerce' ); ?>">
			<ul>
				<?php if ( Pages::id( 'shop' ) ) : ?>
					<li><a class="wahou-chip-link<?php echo 0 === $wahou_current ? ' is-active' : ''; ?>" href="<?php echo esc_url( Pages::url( 'shop' ) ); ?>"<?php echo 0 === $wahou_current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'wahou-commerce' ); ?></a></li>
				<?php endif; ?>
				<?php foreach ( $wahou_chips as $wahou_term ) : ?>
					<?php
					$wahou_active = $wahou_current === (int) $wahou_term->term_id || ( $wahou_current && in_array( (int) $wahou_term->term_id, get_ancestors( $wahou_current, $wahou_term->taxonomy, 'taxonomy' ), true ) );
					$wahou_link   = get_term_link( $wahou_term );
					if ( is_wp_error( $wahou_link ) ) {
						continue;
					}
					?>
					<li><a class="wahou-chip-link<?php echo $wahou_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $wahou_link ); ?>"<?php echo $wahou_active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $wahou_term->name ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>
	<div class="wahou-toolbar__row">
		<p class="wahou-toolbar__count">
			<?php
			/* translators: %s: number of products. */
			echo esc_html( sprintf( _n( '%s product', '%s products', (int) $total, 'wahou-commerce' ), number_format_i18n( (int) $total ) ) );
			?>
		</p>
		<form class="wahou-sort" method="get" action="<?php echo esc_url( $wahou_action ); ?>">
			<?php
			// Keep other query args (search, plain permalinks...).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $wahou_key => $wahou_value ) {
				$wahou_key = sanitize_key( (string) $wahou_key );
				if ( in_array( $wahou_key, array( 'orderby', 'paged', 'wahou_notice' ), true ) || ! is_scalar( $wahou_value ) ) {
					continue;
				}
				echo '<input type="hidden" name="' . esc_attr( $wahou_key ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( (string) $wahou_value ) ) ) . '">';
			}
			?>
			<label for="<?php echo esc_attr( $wahou_select ); ?>"><?php esc_html_e( 'Sort by', 'wahou-commerce' ); ?></label>
			<select class="wahou-select wahou-select--sm" id="<?php echo esc_attr( $wahou_select ); ?>" name="orderby" data-wahou-autosubmit>
				<?php foreach ( Render::orderby_options() as $wahou_value => $wahou_label ) : ?>
					<option value="<?php echo esc_attr( $wahou_value ); ?>"<?php selected( $orderby, $wahou_value ); ?>><?php echo esc_html( $wahou_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="wahou-btn wahou-btn--secondary wahou-btn--sm"><?php esc_html_e( 'Apply', 'wahou-commerce' ); ?></button></noscript>
		</form>
	</div>
</div>
