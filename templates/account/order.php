<?php
/**
 * Customer order detail.
 *
 * Override: copy to {your-theme}/wahou-commerce/account/order.php
 *
 * @var \WahouCommerce\Orders\Order        $order
 * @var array<int, array<string, mixed>>   $downloads
 *
 * @package WahouCommerce
 */

use WahouCommerce\Customers\Account;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$tracking = (array) $order->get_meta( 'tracking', array() );
$gateway  = Gateways::get( $order->payment_method() );
?>
<article class="wahou-account-order">
	<p class="wahou-account-order__back"><a href="<?php echo esc_url( add_query_arg( 'view', 'orders', Account::account_url() ) ); ?>">← <?php esc_html_e( 'All orders', 'wahou-commerce' ); ?></a></p>

	<header class="wahou-account-order__header">
		<h3>
			<?php
			/* translators: %s: order number. */
			echo esc_html( sprintf( __( 'Order #%s', 'wahou-commerce' ), $order->number() ) );
			?>
		</h3>
		<span class="wahou-status wahou-status--<?php echo esc_attr( $order->status() ); ?>"><?php echo esc_html( $order->status_label() ); ?></span>
		<p class="wahou-account-order__date">
			<?php
			/* translators: %s: order date. */
			echo esc_html( sprintf( __( 'Placed on %s', 'wahou-commerce' ), $order->date() ) );
			?>
		</p>
	</header>

	<?php if ( ! empty( $tracking['number'] ) || ! empty( $tracking['url'] ) ) : ?>
		<section class="wahou-thankyou__block wahou-account-order__tracking">
			<h4><?php esc_html_e( 'Tracking', 'wahou-commerce' ); ?></h4>
			<p>
				<?php echo esc_html( trim( (string) ( $tracking['carrier'] ?? '' ) . ' ' . (string) ( $tracking['number'] ?? '' ) ) ); ?>
			</p>
			<?php if ( ! empty( $tracking['url'] ) ) : ?>
				<a class="wahou-btn wahou-btn--secondary" href="<?php echo esc_url( (string) $tracking['url'] ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Track my parcel', 'wahou-commerce' ); ?></a>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $downloads ) : ?>
		<section class="wahou-thankyou__block">
			<h4><?php esc_html_e( 'Downloads', 'wahou-commerce' ); ?></h4>
			<?php Template::render( 'account/downloads-list', array( 'downloads' => $downloads ) ); ?>
		</section>
	<?php endif; ?>

	<?php
	$gateway_html = $gateway && 'on-hold' === $order->status() ? $gateway->thankyou_html( $order ) : '';
	if ( '' !== $gateway_html ) :
		?>
		<section class="wahou-thankyou__block"><?php echo $gateway_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup provided by the gateway class. ?></section>
	<?php endif; ?>

	<?php Template::render( 'checkout/order-details', array( 'order' => $order ) ); ?>

	<?php if ( $order->is_paid() && '' !== $order->invoice_number() ) : ?>
		<p class="wahou-account-order__actions">
			<a class="wahou-btn wahou-btn--secondary" href="<?php echo esc_url( $order->invoice_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download invoice', 'wahou-commerce' ); ?></a>
		</p>
	<?php endif; ?>
</article>
