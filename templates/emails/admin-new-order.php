<?php
/**
 * Store owner notification for a new order.
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var string                      $admin_url
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$customer_note = trim( (string) $order->get_meta( 'customer_note', '' ) );
$utm           = (array) $order->get_meta( 'utm', array() );
?>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
	<?php
	if ( $order->is_paid() ) {
		/* translators: 1: customer name or email, 2: order total. */
		echo esc_html( sprintf( __( '%1$s just paid %2$s. Congratulations!', 'wahou-commerce' ), $order->customer_name() ?: $order->email(), Money::format( $order->total(), $order->currency() ) ) );
	} else {
		/* translators: 1: customer name or email, 2: order total. */
		echo esc_html( sprintf( __( '%1$s placed an order of %2$s, awaiting payment by bank transfer.', 'wahou-commerce' ), $order->customer_name() ?: $order->email(), Money::format( $order->total(), $order->currency() ) ) );
	}
	?>
</p>

<?php echo Mailer::button( $admin_url, __( 'View the order', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button(). ?>

<?php if ( '' !== $customer_note ) : ?>
	<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>">
		<strong><?php esc_html_e( 'Customer note', 'wahou-commerce' ); ?></strong><br>
		<?php echo nl2br( esc_html( $customer_note ) ); ?>
	</div>
<?php endif; ?>

<?php if ( $order->parent_id() ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>">
		<?php
		/* translators: %d: parent order ID. */
		echo esc_html( sprintf( __( 'Post-purchase offer accepted after order #%d.', 'wahou-commerce' ), $order->parent_id() ) );
		?>
	</p>
<?php endif; ?>

<?php echo Template::get( 'emails/parts/order-details', array( 'order' => $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the partial. ?>

<?php if ( ! empty( $utm ) ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>margin-top:16px;">
		<?php
		$parts = array();
		foreach ( $utm as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = sanitize_key( (string) $key ) . ': ' . (string) $value;
			}
		}
		/* translators: %s: marketing source parameters. */
		echo esc_html( sprintf( __( 'Source: %s', 'wahou-commerce' ), implode( ' · ', $parts ) ) );
		?>
	</p>
<?php endif; ?>
