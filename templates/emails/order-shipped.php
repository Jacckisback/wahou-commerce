<?php
/**
 * "Your order is on its way" email.
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var array<string, string>       $tracking carrier, number, url.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Emails;
use WahouCommerce\Emails\Mailer;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$first_name = $order->first_name();
$carrier    = trim( (string) ( $tracking['carrier'] ?? '' ) );
$number     = trim( (string) ( $tracking['number'] ?? '' ) );
$url        = trim( (string) ( $tracking['url'] ?? '' ) );
$address    = Emails::address_html( $order->shipping_address() );
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
	echo esc_html( sprintf( __( 'Your order #%s has left our hands and is on its way to you.', 'wahou-commerce' ), $order->number() ) );
	?>
</p>

<?php if ( '' !== $carrier || '' !== $number ) : ?>
	<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>">
		<?php if ( '' !== $carrier ) : ?>
			<?php
			/* translators: %s: carrier name. */
			echo esc_html( sprintf( __( 'Carrier: %s', 'wahou-commerce' ), $carrier ) );
			?>
			<br>
		<?php endif; ?>
		<?php if ( '' !== $number ) : ?>
			<?php
			/* translators: %s: tracking number. */
			echo esc_html( sprintf( __( 'Tracking number: %s', 'wahou-commerce' ), $number ) );
			?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php
if ( '' !== $url ) {
	echo Mailer::button( $url, __( 'Track my parcel', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button().
}
?>

<?php if ( '' !== $address ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
		<strong><?php esc_html_e( 'Delivery address', 'wahou-commerce' ); ?></strong><br>
		<?php echo $address; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in address_html(). ?>
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
