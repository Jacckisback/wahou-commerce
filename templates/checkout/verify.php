<?php
/**
 * Email confirmation required to view an older order page.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/verify.php
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var string                      $error
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wahou wahou-login wahou-verify">
	<svg class="wahou-login__icon" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
	<h2 class="wahou-login__title"><?php esc_html_e( 'Confirm your email', 'wahou-commerce' ); ?></h2>
	<p class="wahou-login__text"><?php esc_html_e( 'To protect your personal data, please enter the email address used for this order.', 'wahou-commerce' ); ?></p>
	<?php if ( '' !== $error ) : ?>
		<div class="wahou-notice wahou-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<form method="post" class="wahou-login__form">
		<?php wp_nonce_field( 'wahou_verify_' . $order->id(), '_wahou_nonce' ); ?>
		<div class="wahou-field">
			<label class="wahou-field__label" for="wahou-verify-email"><?php esc_html_e( 'Email', 'wahou-commerce' ); ?></label>
			<input type="email" id="wahou-verify-email" name="wahou_verify_email" class="wahou-input" required autocomplete="email" inputmode="email" spellcheck="false" autocapitalize="off">
		</div>
		<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--block"><?php esc_html_e( 'View my order', 'wahou-commerce' ); ?></button>
	</form>
</div>
