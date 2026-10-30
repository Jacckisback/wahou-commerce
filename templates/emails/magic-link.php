<?php
/**
 * Passwordless login link.
 *
 * @var string $url
 * @var string $email
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;

defined( 'ABSPATH' ) || exit;
?>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>"><?php esc_html_e( 'Click the button below to access your account: your orders, invoices and downloads are waiting for you.', 'wahou-commerce' ); ?></p>

<?php echo Mailer::button( $url, __( 'Log in', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button(). ?>

<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>"><?php esc_html_e( 'For your security, this link works only once and expires shortly. If you did not request it, simply ignore this email: nobody can log in without access to your mailbox.', 'wahou-commerce' ); ?></p>
<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>word-break:break-all;">
	<?php esc_html_e( 'Button not working? Copy this link into your browser:', 'wahou-commerce' ); ?><br>
	<a href="<?php echo esc_url( $url ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>"><?php echo esc_html( $url ); ?></a>
</p>
