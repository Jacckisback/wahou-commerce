<?php
/**
 * Unknown order link.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/not-found.php
 *
 * @package WahouCommerce
 */

use WahouCommerce\Customers\Account;

defined( 'ABSPATH' ) || exit;
?>
<div class="wahou wahou-empty">
	<svg class="wahou-empty__icon" aria-hidden="true" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
	<h2 class="wahou-empty__title"><?php esc_html_e( 'We could not find this order', 'wahou-commerce' ); ?></h2>
	<p class="wahou-empty__text"><?php esc_html_e( 'The link may be incomplete. Your orders are always available in your account.', 'wahou-commerce' ); ?></p>
	<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( Account::account_url() ); ?>"><?php esc_html_e( 'Go to my account', 'wahou-commerce' ); ?></a>
</div>
