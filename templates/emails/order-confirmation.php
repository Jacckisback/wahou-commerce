<?php
/**
 * Customer order confirmation (sent once the payment is received).
 *
 * Also serves as the confirmation "on a durable medium" required by
 * Directive 2011/83/EU art. 8(7): it restates the customer's consents.
 *
 * @var \WahouCommerce\Orders\Order                     $order
 * @var array<int, array<string, mixed>>                $downloads   name, url, product, remaining, expires.
 * @var string                                          $account_url Passwordless account link ('' when unavailable).
 * @var string                                          $invoice_url
 * @var string                                          $message     Custom thank-you message.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$first_name = $order->first_name();
$consents   = (array) $order->get_meta( 'consents', array() );
$ships      = $order->needs_shipping();
$terms_url  = Pages::id( 'terms' ) ? Pages::url( 'terms' ) : '';
$datetime   = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
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
	if ( $ships && ! empty( $downloads ) ) {
		esc_html_e( 'We have received your payment. Your digital products are ready to download below, and we are preparing the rest of your order: you will receive another email as soon as it ships.', 'wahou-commerce' );
	} elseif ( $ships ) {
		esc_html_e( 'We have received your payment and are preparing your order. You will receive another email as soon as it ships.', 'wahou-commerce' );
	} elseif ( ! empty( $downloads ) ) {
		esc_html_e( 'We have received your payment. Your downloads are ready – enjoy!', 'wahou-commerce' );
	} else {
		esc_html_e( 'We have received your payment. Thank you for your trust!', 'wahou-commerce' );
	}
	?>
</p>

<?php if ( '' !== trim( $message ) ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>"><?php echo wp_kses_post( nl2br( $message ) ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $downloads ) ) : ?>
	<h2 style="<?php echo esc_attr( Mailer::style( 'h2' ) ); ?>"><?php esc_html_e( 'Your downloads', 'wahou-commerce' ); ?></h2>
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>">
		<?php foreach ( $downloads as $download ) : ?>
			<tr>
				<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>">
					<strong><?php echo esc_html( (string) ( $download['name'] ?? '' ) ); ?></strong>
					<?php if ( ! empty( $download['product'] ) && $download['product'] !== ( $download['name'] ?? '' ) ) : ?>
						<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( (string) $download['product'] ); ?></span>
					<?php endif; ?>
					<?php
					$limits = array();
					if ( isset( $download['remaining'] ) && null !== $download['remaining'] ) {
						/* translators: %d: number of downloads left. */
						$limits[] = sprintf( _n( '%d download left', '%d downloads left', (int) $download['remaining'], 'wahou-commerce' ), (int) $download['remaining'] );
					}
					if ( ! empty( $download['expires'] ) ) {
						/* translators: %s: expiry date. */
						$limits[] = sprintf( __( 'available until %s', 'wahou-commerce' ), get_date_from_gmt( (string) $download['expires'], get_option( 'date_format' ) ) );
					}
					if ( $limits ) :
						?>
						<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( implode( ' · ', $limits ) ); ?></span>
					<?php endif; ?>
				</td>
				<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">
					<a href="<?php echo esc_url( (string) ( $download['url'] ?? '' ) ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>font-weight:700;"><?php esc_html_e( 'Download', 'wahou-commerce' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<?php echo Template::get( 'emails/parts/order-details', array( 'order' => $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the partial. ?>

<?php if ( '' !== $account_url || '' !== $invoice_url ) : ?>
	<div style="margin-top:24px;">
		<?php
		if ( '' !== $account_url ) {
			echo Mailer::button( $account_url, __( 'Access my orders', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button().
		}
		?>
		<?php if ( '' !== $invoice_url ) : ?>
			<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>">
				<a href="<?php echo esc_url( $invoice_url ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>"><?php esc_html_e( 'View and print your invoice', 'wahou-commerce' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( ! empty( $consents['terms'] ) || ! empty( $consents['digital_waiver'] ) ) : ?>
	<div style="<?php echo esc_attr( Mailer::style( 'box' ) ); ?>margin-top:24px;font-size:13px;color:#4b5259;">
		<?php if ( ! empty( $consents['terms'] ) ) : ?>
			<?php
			/* translators: %s: date and time of acceptance. */
			echo esc_html( sprintf( __( 'You accepted our terms and conditions of sale on %s.', 'wahou-commerce' ), get_date_from_gmt( (string) $consents['terms'], $datetime ) ) );
			?>
			<?php if ( '' !== $terms_url ) : ?>
				<a href="<?php echo esc_url( $terms_url ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>"><?php esc_html_e( 'Read them again', 'wahou-commerce' ); ?></a>
			<?php endif; ?>
			<br>
		<?php endif; ?>
		<?php if ( ! empty( $consents['digital_waiver'] ) ) : ?>
			<?php esc_html_e( 'You expressly requested that supply of the digital content start immediately and acknowledged that you thereby lose your right of withdrawal.', 'wahou-commerce' ); ?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>margin-top:20px;"><?php esc_html_e( 'A question about your order? Simply reply to this email.', 'wahou-commerce' ); ?></p>
