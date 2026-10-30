<?php
/**
 * Note from the shop about an order.
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var string                      $note
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;

defined( 'ABSPATH' ) || exit;

$first_name = $order->first_name();
?>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
	<?php
	echo esc_html(
		'' !== $first_name
			/* translators: %s: customer first name. */
			? sprintf( __( 'Hi %s,', 'wahou-commerce' ), $first_name )
			: __( 'Hello,', 'wahou-commerce' )
	);
	?>
</p>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'We have added a note to your order #%s:', 'wahou-commerce' ), $order->number() ) );
	?>
</p>
<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>"><?php echo wp_kses_post( wpautop( $note ) ); ?></div>
<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>"><?php esc_html_e( 'You can reply directly to this email.', 'wahou-commerce' ); ?></p>
