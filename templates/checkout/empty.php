<?php
/**
 * Empty cart / empty checkout state.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/empty.php
 *
 * @var array{type:string, message:string}|null $flash Optional notice.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

$shop_url = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );
?>
<div class="wahou wahou-empty">
	<?php if ( ! empty( $flash['message'] ) ) : ?>
		<div class="wahou-notice wahou-notice--<?php echo 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success'; ?>" role="status">
			<?php echo esc_html( (string) $flash['message'] ); ?>
		</div>
	<?php endif; ?>
	<svg class="wahou-empty__icon" aria-hidden="true" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
	<h2 class="wahou-empty__title"><?php esc_html_e( 'Your cart is empty', 'wahou-commerce' ); ?></h2>
	<p class="wahou-empty__text"><?php esc_html_e( 'Have a look at our products: your next favourite is waiting.', 'wahou-commerce' ); ?></p>
	<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue shopping', 'wahou-commerce' ); ?></a>
</div>
