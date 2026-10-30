<?php
/**
 * Order summary used by order emails: items, totals, VAT and addresses.
 *
 * @var \WahouCommerce\Orders\Order $order
 * @var bool                        $show_addresses Optional, default true.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Emails;
use WahouCommerce\Emails\Mailer;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Money;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

$currency       = $order->currency();
$show_addresses = isset( $show_addresses ) ? (bool) $show_addresses : true;
$shipping_line  = $order->shipping_line();
$mention        = Tax::invoice_mention( $order->tax_mode(), $order->needs_shipping() );
?>
<h2 style="<?php echo esc_attr( Mailer::style( 'h2' ) ); ?>">
	<?php
	/* translators: 1: order number, 2: order date. */
	echo esc_html( sprintf( __( 'Order #%1$s – %2$s', 'wahou-commerce' ), $order->number(), Emails::date( $order->created_at() ) ) );
	?>
</h2>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>">
	<?php
	foreach ( $order->items() as $item ) :
		$product = Product::find( (int) $item['product_id'] );
		$image   = $product ? $product->image_id( '' !== $item['variant_id'] ? (string) $item['variant_id'] : null ) : 0;
		$image   = $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
		$label   = (string) ( $item['meta']['variant_label'] ?? '' );
		?>
		<tr>
			<?php if ( $image ) : ?>
				<td width="56" style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>width:56px;padding-right:12px;">
					<img src="<?php echo esc_url( $image ); ?>" width="48" height="48" alt="" style="display:block;width:48px;height:48px;border-radius:8px;object-fit:cover;border:0;">
				</td>
			<?php endif; ?>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>" <?php echo $image ? '' : 'colspan="2"'; ?>>
				<strong><?php echo esc_html( (string) $item['name'] ); ?></strong>
				<?php if ( '' !== $label ) : ?>
					<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $item['meta']['bundle'] ) && is_array( $item['meta']['bundle'] ) ) : ?>
					<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>">
						<?php echo esc_html( implode( ', ', array_map( static fn( $c ) => (int) $c['qty'] . ' × ' . (string) $c['name'], $item['meta']['bundle'] ) ) ); ?>
					</span>
				<?php endif; ?>
				<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>">
					<?php
					/* translators: %d: quantity. */
					echo esc_html( sprintf( __( 'Quantity: %d', 'wahou-commerce' ), (int) $item['quantity'] ) );
					?>
				</span>
			</td>
			<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">
				<?php if ( (int) $item['discount'] > 0 ) : ?>
					<s style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( Money::format( (int) $item['subtotal'], $currency ) ); ?></s><br>
				<?php endif; ?>
				<?php echo esc_html( Money::format( (int) $item['total'], $currency ) ); ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>margin-top:8px;">
	<tr>
		<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>"><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></td>
		<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>"><?php echo esc_html( Money::format( $order->subtotal(), $currency ) ); ?></td>
	</tr>
	<?php if ( $order->discount_total() > 0 ) : ?>
		<tr>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>">
				<?php
				echo esc_html(
					'' !== $order->coupon_code()
						/* translators: %s: coupon code. */
						? sprintf( __( 'Discount (%s)', 'wahou-commerce' ), $order->coupon_code() )
						: __( 'Discount', 'wahou-commerce' )
				);
				?>
			</td>
			<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">−<?php echo esc_html( Money::format( $order->discount_total(), $currency ) ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( $shipping_line || $order->shipping_total() > 0 ) : ?>
		<tr>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>">
				<?php esc_html_e( 'Shipping', 'wahou-commerce' ); ?>
				<?php if ( $shipping_line && ! empty( $shipping_line['title'] ) ) : ?>
					<br><span style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>"><?php echo esc_html( (string) $shipping_line['title'] ); ?></span>
				<?php endif; ?>
			</td>
			<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">
				<?php echo $order->shipping_total() > 0 ? esc_html( Money::format( $order->shipping_total(), $currency ) ) : esc_html__( 'Free', 'wahou-commerce' ); ?>
			</td>
		</tr>
	<?php endif; ?>
	<tr>
		<td style="<?php echo esc_attr( Mailer::style( 'total' ) ); ?>"><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></td>
		<td style="<?php echo esc_attr( Mailer::style( 'total_r' ) ); ?>"><?php echo esc_html( Money::format( $order->total(), $currency ) ); ?></td>
	</tr>
	<?php
	foreach ( $order->tax_lines() as $tax_line ) :
		if ( (int) $tax_line['tax'] <= 0 ) {
			continue;
		}
		?>
		<tr>
			<td style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>padding-top:4px;">
				<?php
				/* translators: %s: VAT rate, e.g. "20 %". */
				echo esc_html( sprintf( __( 'Including VAT %s', 'wahou-commerce' ), Emails::percent( (float) $tax_line['rate'] ) ) );
				?>
			</td>
			<td style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>padding-top:4px;text-align:right;white-space:nowrap;"><?php echo esc_html( Money::format( (int) $tax_line['tax'], $currency ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( '' !== $mention ) : ?>
		<tr>
			<td colspan="2" style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>padding-top:6px;"><?php echo esc_html( $mention ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( $order->refunded_total() > 0 ) : ?>
		<tr>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>"><?php esc_html_e( 'Refunded', 'wahou-commerce' ); ?></td>
			<td style="<?php echo esc_attr( Mailer::style( 'td_r' ) ); ?>">−<?php echo esc_html( Money::format( $order->refunded_total(), $currency ) ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( '' !== $order->payment_method_title() ) : ?>
		<tr>
			<td colspan="2" style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>padding-top:10px;">
				<?php
				/* translators: %s: payment method. */
				echo esc_html( sprintf( __( 'Payment method: %s', 'wahou-commerce' ), $order->payment_method_title() ) );
				?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<?php
if ( $show_addresses ) :
	$billing  = Emails::address_html( $order->billing() );
	$shipping = $order->needs_shipping() ? Emails::address_html( $order->shipping_address() ) : '';
	if ( '' !== $billing || '' !== $shipping ) :
		?>
		<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>margin-top:24px;">
			<tr>
				<?php if ( '' !== $shipping ) : ?>
					<td valign="top" style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>width:50%;padding-right:12px;">
						<strong style="color:#111111;"><?php esc_html_e( 'Delivery address', 'wahou-commerce' ); ?></strong><br>
						<?php echo $shipping; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in address_html(). ?>
					</td>
				<?php endif; ?>
				<?php if ( '' !== $billing ) : ?>
					<td valign="top" style="<?php echo esc_attr( Mailer::style( 'muted' ) ); ?>width:50%;">
						<strong style="color:#111111;"><?php esc_html_e( 'Billing details', 'wahou-commerce' ); ?></strong><br>
						<?php echo $billing; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in address_html(). ?>
						<br><?php echo esc_html( $order->email() ); ?>
					</td>
				<?php endif; ?>
			</tr>
		</table>
		<?php
	endif;
endif;
