<?php
/**
 * Order received page.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/thank-you.php
 *
 * @var \WahouCommerce\Orders\Order                 $order
 * @var \WahouCommerce\Gateways\Gateway|null        $gateway
 * @var array<int, array<string, mixed>>            $downloads
 * @var array{from:int, to:int}|null                $delivery
 * @var array<string, mixed>|null                   $purchase    GA4 purchase payload (first view only).
 * @var string                                      $account_url
 * @var string                                      $shop_url
 * @var string                                      $checkout_url
 * @var string                                      $thankyou_message
 * @var bool                                        $has_account
 *
 * @package WahouCommerce
 */

use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$status     = $order->status();
$first_name = $order->first_name();
$is_paid    = $order->is_paid() && 'refunded' !== $status;
$pending    = 'pending' === $status;
$failed     = in_array( $status, array( 'failed', 'cancelled' ), true );

if ( $failed ) {
	$title = __( 'Your payment was not completed', 'wahou-commerce' );
	$lead  = __( 'No money has been taken. Your cart is still saved: you can try again with the same or another payment method.', 'wahou-commerce' );
} elseif ( $pending ) {
	$title = __( 'We are confirming your payment…', 'wahou-commerce' );
	$lead  = __( 'This usually takes a few seconds. Please keep this page open.', 'wahou-commerce' );
} elseif ( 'on-hold' === $status ) {
	$title = '' !== $first_name
		/* translators: %s: customer first name. */
		? sprintf( __( 'Thank you, %s! Your order is reserved', 'wahou-commerce' ), $first_name )
		: __( 'Thank you! Your order is reserved', 'wahou-commerce' );
	$lead  = __( 'We will process it as soon as we receive your payment. The details are below and in the email we just sent you.', 'wahou-commerce' );
} elseif ( 'refunded' === $status ) {
	$title = __( 'This order has been refunded', 'wahou-commerce' );
	$lead  = '';
} else {
	$title = '' !== $first_name
		/* translators: %s: customer first name. */
		? sprintf( __( 'Thank you, %s!', 'wahou-commerce' ), $first_name )
		: __( 'Thank you for your order!', 'wahou-commerce' );
	$lead  = sprintf(
		/* translators: %s: customer email address. */
		__( 'Your order is confirmed. A confirmation email is on its way to %s.', 'wahou-commerce' ),
		$order->email()
	);
}
$state = $failed ? 'failed' : ( $pending ? 'pending' : ( 'on-hold' === $status ? 'on-hold' : 'paid' ) );
?>
<div class="wahou wahou-thankyou wahou-thankyou--<?php echo esc_attr( $state ); ?>" data-wahou-thankyou data-order-key="<?php echo esc_attr( $order->key() ); ?>"<?php echo $pending ? ' data-wahou-poll' : ''; ?>>
	<header class="wahou-thankyou__hero">
		<div class="wahou-thankyou__icon" aria-hidden="true">
			<?php if ( $pending ) : ?>
				<span class="wahou-co-spinner wahou-co-spinner--large"></span>
			<?php elseif ( $failed ) : ?>
				<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
			<?php else : ?>
				<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
			<?php endif; ?>
		</div>
		<p class="wahou-thankyou__eyebrow">
			<?php
			/* translators: %s: order number. */
			echo esc_html( sprintf( __( 'Order #%s', 'wahou-commerce' ), $order->number() ) );
			?>
		</p>
		<h2 class="wahou-thankyou__title"><?php echo esc_html( $title ); ?></h2>
		<?php if ( '' !== $lead ) : ?>
			<p class="wahou-thankyou__lead"><?php echo esc_html( $lead ); ?></p>
		<?php endif; ?>
		<p class="wahou-thankyou__still" data-wahou-still-processing hidden><?php esc_html_e( 'Your payment is still being confirmed. You will receive an email as soon as it is validated.', 'wahou-commerce' ); ?></p>
		<?php if ( $failed ) : ?>
			<p><a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( $checkout_url ); ?>"><?php esc_html_e( 'Try again', 'wahou-commerce' ); ?></a></p>
		<?php endif; ?>
	</header>

	<?php if ( $is_paid && '' !== trim( $thankyou_message ) ) : ?>
		<div class="wahou-thankyou__message"><?php echo wp_kses_post( wpautop( $thankyou_message ) ); ?></div>
	<?php endif; ?>

	<?php if ( $is_paid && $downloads ) : ?>
		<section class="wahou-thankyou__block wahou-thankyou__downloads" aria-labelledby="wahou-ty-downloads">
			<h3 id="wahou-ty-downloads"><?php esc_html_e( 'Your downloads are ready', 'wahou-commerce' ); ?></h3>
			<?php Template::render( 'account/downloads-list', array( 'downloads' => $downloads ) ); ?>
			<p class="wahou-thankyou__hint"><?php esc_html_e( 'The links are also in your confirmation email and in your account.', 'wahou-commerce' ); ?></p>
		</section>
	<?php endif; ?>

	<?php
	$gateway_html = $gateway ? $gateway->thankyou_html( $order ) : '';
	if ( '' !== $gateway_html && ! $failed ) :
		?>
		<section class="wahou-thankyou__block wahou-thankyou__payment">
			<?php echo $gateway_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup provided by the gateway class. ?>
		</section>
	<?php endif; ?>

	<?php if ( $delivery ) : ?>
		<section class="wahou-thankyou__block wahou-thankyou__delivery" aria-labelledby="wahou-ty-delivery">
			<svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.5"/><circle cx="17" cy="17.5" r="1.5"/></svg>
			<div>
				<h3 id="wahou-ty-delivery"><?php esc_html_e( 'Estimated delivery', 'wahou-commerce' ); ?></h3>
				<p>
					<?php
					/* translators: Date format for delivery estimates, see https://www.php.net/manual/datetime.format.php */
					$format = _x( 'l j F', 'delivery date format', 'wahou-commerce' );
					$from   = wp_date( $format, $delivery['from'] );
					$to     = wp_date( $format, $delivery['to'] );
					echo esc_html(
						$from === $to
							? $from
							/* translators: 1: earliest date, 2: latest date. */
							: sprintf( __( 'Between %1$s and %2$s', 'wahou-commerce' ), $from, $to )
					);
					?>
				</p>
				<p class="wahou-thankyou__hint"><?php esc_html_e( 'You will receive an email with the tracking link as soon as your parcel ships.', 'wahou-commerce' ); ?></p>
			</div>
		</section>
	<?php endif; ?>

	<?php Template::render( 'checkout/order-details', array( 'order' => $order ) ); ?>

	<div class="wahou-thankyou__actions">
		<?php if ( $is_paid && '' !== $order->invoice_number() ) : ?>
			<a class="wahou-btn wahou-btn--secondary" href="<?php echo esc_url( $order->invoice_url() ); ?>" target="_blank" rel="noopener">
				<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>
				<?php esc_html_e( 'Download invoice', 'wahou-commerce' ); ?>
			</a>
		<?php endif; ?>
		<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue shopping', 'wahou-commerce' ); ?></a>
	</div>

	<?php if ( ! $failed ) : ?>
		<section class="wahou-thankyou__block wahou-thankyou__account">
			<?php if ( is_user_logged_in() ) : ?>
				<p><?php esc_html_e( 'Find your orders, invoices and downloads in your account at any time.', 'wahou-commerce' ); ?></p>
				<a class="wahou-btn wahou-btn--secondary" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'My account', 'wahou-commerce' ); ?></a>
			<?php elseif ( $has_account ) : ?>
				<p><?php esc_html_e( 'Your account is ready: no password needed, we send you a secure login link.', 'wahou-commerce' ); ?></p>
				<button type="button" class="wahou-btn wahou-btn--secondary" data-wahou-magic-link data-email="<?php echo esc_attr( $order->email() ); ?>">
					<?php esc_html_e( 'Email me a login link', 'wahou-commerce' ); ?>
				</button>
				<p class="wahou-thankyou__hint" data-wahou-magic-message role="status" hidden></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $purchase ) : ?>
		<script type="application/json" data-wahou-purchase><?php echo wp_json_encode( $purchase, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
	<?php endif; ?>
</div>
