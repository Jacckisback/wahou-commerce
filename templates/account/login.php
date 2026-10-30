<?php
/**
 * Passwordless login form (magic link).
 *
 * Override: copy to {your-theme}/wahou-commerce/account/login.php
 *
 * @var array{type:string, text:string}|null $message
 * @var string                               $redirect
 * @var string                               $action   Form action URL.
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;

$password_url = wp_login_url( '' !== $redirect ? $redirect : $action );
?>
<div class="wahou wahou-login" data-wahou-account>
	<svg class="wahou-login__icon" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
	<h2 class="wahou-login__title"><?php esc_html_e( 'Access your account', 'wahou-commerce' ); ?></h2>
	<p class="wahou-login__text"><?php esc_html_e( 'No password to remember: enter your email and we send you a secure login link.', 'wahou-commerce' ); ?></p>

	<div class="wahou-notice wahou-notice--<?php echo esc_attr( $message['type'] ?? 'success' ); ?>" role="status" data-wahou-magic-message<?php echo $message ? '' : ' hidden'; ?>>
		<?php echo $message ? esc_html( $message['text'] ) : ''; ?>
	</div>

	<form method="post" action="<?php echo esc_url( $action ); ?>" class="wahou-login__form" data-wahou-magic-form>
		<input type="hidden" name="wahou_action" value="magic_link">
		<?php if ( '' !== $redirect ) : ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
		<?php endif; ?>
		<div class="wahou-field">
			<label class="wahou-field__label" for="wahou-login-email"><?php esc_html_e( 'Email', 'wahou-commerce' ); ?></label>
			<input type="email" id="wahou-login-email" name="email" class="wahou-input" required autocomplete="email" inputmode="email" spellcheck="false" autocapitalize="off" placeholder="<?php esc_attr_e( 'you@example.com', 'wahou-commerce' ); ?>">
		</div>
		<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--block">
			<span><?php esc_html_e( 'Send me a login link', 'wahou-commerce' ); ?></span>
			<span class="wahou-co-spinner" aria-hidden="true"></span>
		</button>
	</form>

	<p class="wahou-login__alt">
		<a href="<?php echo esc_url( $password_url ); ?>"><?php esc_html_e( 'Log in with a password', 'wahou-commerce' ); ?></a>
	</p>
</div>
