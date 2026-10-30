<?php
/**
 * Customer downloads.
 *
 * Override: copy to {your-theme}/wahou-commerce/account/downloads.php
 *
 * @var array<int, array<string, mixed>> $downloads
 *
 * @package WahouCommerce
 */

use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

if ( ! $downloads ) :
	?>
	<div class="wahou-empty wahou-empty--compact">
		<p class="wahou-empty__text"><?php esc_html_e( 'Your digital purchases will appear here.', 'wahou-commerce' ); ?></p>
	</div>
	<?php
	return;
endif;

Template::render( 'account/downloads-list', array( 'downloads' => $downloads ) );
