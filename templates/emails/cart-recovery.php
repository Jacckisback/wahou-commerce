<?php
/**
 * Abandoned cart reminder.
 *
 * @var array<int, array<string, mixed>> $lines          Priced cart lines.
 * @var array<string, mixed>             $totals         Cart totals.
 * @var string                           $recover_url    One-click link restoring the cart.
 * @var string                           $unsubscribe_url
 * @var string                           $coupon         Personal coupon code ('' when none).
 * @var float                            $coupon_percent
 * @var int                              $coupon_expires Unix time (0 = no expiry).
 * @var string                           $first_name
 * @var int                              $step           0-based reminder index.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Emails;
use WahouCommerce\Emails\Mailer;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

$currency = (string) ( $totals['currency'] ?? '' );
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
	if ( '' !== $coupon ) {
		/* translators: %s: discount, e.g. "10%". */
		echo esc_html( sprintf( __( 'Your selection is still saved, and we would love to see you again: here is %s off to complete your order.', 'wahou-commerce' ), Emails::percent( $coupon_percent ) ) );
	} elseif ( 0 === $step ) {
		esc_html_e( 'It looks like you did not finish your order. No worries: we saved your cart so you can pick up right where you left off.', 'wahou-commerce' );
	} else {
		esc_html_e( 'Your cart is still waiting for you. Complete your order in one click – your details are already filled in.', 'wahou-commerce' );
	}
	?>
</p>

<?php if ( '' !== $coupon ) : ?>
	<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>text-align:center;">
		<span style="<?php echo esc_attr( Mailer::style( 'code' ) ); ?>"><?php echo esc_html( $coupon ); ?></span>
	</p>
	<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>text-align:center;">
		<?php
		if ( $coupon_expires > 0 ) {
			/* translators: %s: expiry date. */
			echo esc_html( sprintf( __( 'Already applied via the button below · valid until %s', 'wahou-commerce' ), wp_date( get_option( 'date_format' ), $coupon_expires ) ) );
		} else {
			esc_html_e( 'Already applied via the button below', 'wahou-commerce' );
		}
		?>
	</p>
<?php endif; ?>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>margin:8px 0 8px;">
	<?php
	foreach ( $lines as $line ) :
		$money = $totals['lines'][ $line['key'] ] ?? null;
		$image = ! empty( $line['image_id'] ) ? wp_get_attachment_image_url( (int) $line['image_id'], 'thumbnail' ) : '';
		?>
		<tr>
			<?php if ( $image ) : ?>
				<td width="72" style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>width:72px;padding-right:12px;">
					<a href="<?php echo esc_url( $recover_url ); ?>"><img src="<?php echo esc_url( $image ); ?>" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border-radius:10px;object-fit:cover;border:0;"></a>
				</td>
			<?php endif; ?>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>" <?php echo $image ? '' : 'colspan="2"'; ?>>
				<strong><?php echo esc_html( (string) $line['name'] ); ?></strong>
				<?php if ( ! empty( $line['variant_label'] ) ) : ?>
					<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( (string) $line['variant_label'] ); ?></span>
				<?php endif; ?>
				<?php if ( (int) $line['qty'] > 1 ) : ?>
					<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>">
						<?php
						/* translators: %d: quantity. */
						echo esc_html( sprintf( __( 'Quantity: %d', 'wahou-commerce' ), (int) $line['qty'] ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">
				<?php echo esc_html( Money::format( (int) ( $money['subtotal'] ?? 0 ), $currency ) ); ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<td colspan="2" style="<?php echo esc_attr( Mailer::style( 'total' ) ); ?>"><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></td>
		<td style="<?php echo esc_attr( Mailer::style( 'total_r' ) ); ?>"><?php echo esc_html( Money::format( (int) ( $totals['subtotal'] ?? 0 ) - (int) ( $totals['discount'] ?? 0 ), $currency ) ); ?></td>
	</tr>
</table>

<?php echo Mailer::button( $recover_url, __( 'Complete my order', 'wahou-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in button(). ?>

<p style="<?php echo esc_attr( Mailer::style( 'small' ) ); ?>"><?php esc_html_e( 'A question or a hesitation? Reply to this email, we answer personally.', 'wahou-commerce' ); ?></p>
