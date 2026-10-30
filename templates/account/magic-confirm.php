<?php
/**
 * Magic link landing: one click (POST) to log in.
 *
 * Opening the link never logs in by itself, so email security scanners that
 * pre-open links cannot consume the single-use token.
 *
 * Override: copy to {your-theme}/wahou-commerce/account/magic-confirm.php
 *
 * @var string $token
 * @var int    $user_id
 * @var bool   $valid
 * @var string $email    Masked email of the account.
 * @var string $redirect
 * @var string $action   Form action URL.
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wahou wahou-login" data-wahou-account>
	<?php if ( $valid ) : ?>
		<svg class="wahou-login__icon" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></svg>
		<h2 class="wahou-login__title"><?php esc_html_e( 'Welcome back!', 'wahou-commerce' ); ?></h2>
		<?php if ( '' !== $email ) : ?>
			<p class="wahou-login__text">
				<?php
				/* translators: %s: masked email address. */
				echo esc_html( sprintf( __( 'Log in to the account %s.', 'wahou-commerce' ), $email ) );
				?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="wahou-login__form">
			<input type="hidden" name="wahou_action" value="magic_login">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="u" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<?php if ( '' !== $redirect ) : ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
			<?php endif; ?>
			<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--block"><?php esc_html_e( 'Log in to my account', 'wahou-commerce' ); ?></button>
		</form>
	<?php else : ?>
		<svg class="wahou-login__icon" aria-hidden="true" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg>
		<h2 class="wahou-login__title"><?php esc_html_e( 'This link has expired', 'wahou-commerce' ); ?></h2>
		<p class="wahou-login__text"><?php esc_html_e( 'Login links are valid for a limited time and can only be used once. Request a new one: it takes a few seconds.', 'wahou-commerce' ); ?></p>
		<a class="wahou-btn wahou-btn--primary wahou-btn--block" href="<?php echo esc_url( $action ); ?>"><?php esc_html_e( 'Get a new login link', 'wahou-commerce' ); ?></a>
	<?php endif; ?>
</div>
