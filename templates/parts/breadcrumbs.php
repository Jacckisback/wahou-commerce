<?php
/**
 * Breadcrumb trail (visible counterpart of the BreadcrumbList schema).
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! Settings::bool( 'seo_breadcrumbs' ) ) {
	return;
}
$wahou_crumbs = Render::breadcrumbs();
if ( count( $wahou_crumbs ) < 2 ) {
	return;
}
$wahou_last = count( $wahou_crumbs ) - 1;
?>
<nav class="wahou-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'wahou-commerce' ); ?>">
	<ol>
		<?php foreach ( $wahou_crumbs as $wahou_i => $wahou_crumb ) : ?>
			<li>
				<?php if ( $wahou_i === $wahou_last ) : ?>
					<span aria-current="page"><?php echo esc_html( $wahou_crumb['name'] ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( $wahou_crumb['url'] ); ?>"><?php echo esc_html( $wahou_crumb['name'] ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
