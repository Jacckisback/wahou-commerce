<?php
/**
 * Bank transfer instructions (order on hold until the transfer arrives).
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var string                      $instructions HTML from the bank transfer gateway.
 * @var int                         $hold_days
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Template;

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
	/* translators: 1: amount, 2: order number. */
	echo esc_html( sprintf( __( 'Thank you for your order! To confirm it, please transfer %1$s and use the reference %2$s.', 'wahou-commerce' ), Money::format( $order->total(), $order->currency() ), $order->number() ) );
	?>
</p>

<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>">
	<?php
	if ( '' !== trim( $instructions ) ) {
		echo wp_kses_post( $instructions );
	} else {
		/* translators: %s: order number. */
		echo esc_html( sprintf( __( 'Payment reference: %s', 'wahou-commerce' ), $order->number() ) );
	}
	?>
</div>

<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>">
	<?php
	/* translators: %d: number of days. */
	echo esc_html( sprintf( _n( 'We ship (or give access to) your order as soon as the transfer is received. Without payment within %d day, the order is cancelled automatically.', 'We ship (or give access to) your order as soon as the transfer is received. Without payment within %d days, the order is cancelled automatically.', $hold_days, 'wahou-commerce' ), $hold_days ) );
	?>
</p>

<?php echo Template::get( 'emails/parts/order-details', array( 'order' => $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the partial. ?>
