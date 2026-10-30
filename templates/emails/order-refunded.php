<?php
/**
 * Refund confirmation.
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var int                         $amount     Refunded amount (minor units).
 * @var bool                        $full       Whole order refunded.
 * @var string                      $reason
 * @var string                      $credit_url Credit note URL ('' when no invoice was issued).
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
	/* translators: 1: refunded amount, 2: order number, 3: payment method. */
	echo esc_html( sprintf( __( 'We have refunded %1$s for your order #%2$s to your original payment method (%3$s).', 'wahou-commerce' ), Money::format( $amount, $order->currency() ), $order->number(), $order->payment_method_title() ) );
	?>
</p>
<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>"><?php esc_html_e( 'Depending on your bank, the money can take a few business days to appear on your account.', 'wahou-commerce' ); ?></p>

<?php if ( '' !== trim( $reason ) ) : ?>
	<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>">
		<strong><?php esc_html_e( 'Reason', 'wahou-commerce' ); ?></strong><br>
		<?php echo esc_html( $reason ); ?>
	</div>
<?php endif; ?>

<?php if ( '' !== $credit_url ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
		<a href="<?php echo esc_url( $credit_url ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>"><?php esc_html_e( 'View your credit note', 'wahou-commerce' ); ?></a>
	</p>
<?php endif; ?>

<?php
echo Template::get( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the partial.
	'emails/parts/order-details',
	array(
		'order'          => $order,
		'show_addresses' => false,
	)
);
?>
