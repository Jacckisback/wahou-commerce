<?php
/**
 * Order details: items, totals, VAT breakdown and addresses.
 * Shared by the thank-you page and the customer account.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/order-details.php
 *
 * @var \WahouCommerce\Orders\Order $order
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\CheckoutPage;
use WahouCommerce\Support\Money;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

$currency = $order->currency();
$shipping = $order->shipping_line();
$refunds  = (array) $order->get_meta( 'refunds', array() );
$has_goods = $order->needs_shipping();
$mention  = Tax::invoice_mention( $order->tax_mode(), $has_goods );
?>
<section class="wahou-order-details" aria-labelledby="wahou-order-details-<?php echo esc_attr( (string) $order->id() ); ?>">
	<h3 class="wahou-order-details__title" id="wahou-order-details-<?php echo esc_attr( (string) $order->id() ); ?>"><?php esc_html_e( 'Order summary', 'wahou-commerce' ); ?></h3>

	<ul class="wahou-order-items" role="list">
		<?php foreach ( $order->items() as $item ) : ?>
			<li class="wahou-order-item">
				<span class="wahou-order-item__name">
					<?php echo esc_html( $item['name'] ); ?>
					<span class="wahou-order-item__qty">× <?php echo esc_html( number_format_i18n( (int) $item['quantity'] ) ); ?></span>
					<?php if ( ! empty( $item['meta']['variant_label'] ) ) : ?>
						<small><?php echo esc_html( (string) $item['meta']['variant_label'] ); ?></small>
					<?php endif; ?>
					<?php if ( ! empty( $item['meta']['bundle'] ) && is_array( $item['meta']['bundle'] ) ) : ?>
						<small>
							<?php
							echo esc_html(
								implode(
									', ',
									array_map(
										static fn( $c ) => ( (int) ( $c['qty'] ?? 1 ) > 1 ? (int) $c['qty'] . ' × ' : '' ) . (string) ( $c['name'] ?? '' ),
										$item['meta']['bundle']
									)
								)
							);
							?>
						</small>
					<?php endif; ?>
				</span>
				<span class="wahou-order-item__total"><?php echo esc_html( Money::format( (int) $item['subtotal'], $currency ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<dl class="wahou-summary__totals">
		<div class="wahou-summary__row">
			<dt><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></dt>
			<dd><?php echo esc_html( Money::format( $order->subtotal(), $currency ) ); ?></dd>
		</div>
		<?php if ( $order->discount_total() > 0 ) : ?>
			<div class="wahou-summary__row wahou-summary__row--discount">
				<dt>
					<?php esc_html_e( 'Discount', 'wahou-commerce' ); ?>
					<?php if ( '' !== $order->coupon_code() ) : ?>
						<small>(<?php echo esc_html( $order->coupon_code() ); ?>)</small>
					<?php endif; ?>
				</dt>
				<dd>−<?php echo esc_html( Money::format( $order->discount_total(), $currency ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( $shipping ) : ?>
			<div class="wahou-summary__row">
				<dt>
					<?php esc_html_e( 'Shipping', 'wahou-commerce' ); ?>
					<?php if ( ! empty( $shipping['title'] ) ) : ?>
						<small><?php echo esc_html( (string) $shipping['title'] ); ?></small>
					<?php endif; ?>
				</dt>
				<dd><?php echo $order->shipping_total() > 0 ? esc_html( Money::format( $order->shipping_total(), $currency ) ) : esc_html__( 'Free', 'wahou-commerce' ); ?></dd>
			</div>
		<?php endif; ?>
		<?php foreach ( $order->tax_lines() as $tax_line ) : ?>
			<?php
			if ( (int) ( $tax_line['tax'] ?? 0 ) <= 0 ) {
				continue;
			}
			?>
			<div class="wahou-summary__row wahou-summary__row--tax">
				<dt>
					<?php
					/* translators: %s: VAT rate, e.g. "20". */
					echo esc_html( sprintf( __( 'Incl. VAT %s%%', 'wahou-commerce' ), number_format_i18n( (float) $tax_line['rate'], fmod( (float) $tax_line['rate'], 1.0 ) > 0 ? 1 : 0 ) ) );
					?>
				</dt>
				<dd><?php echo esc_html( Money::format( (int) $tax_line['tax'], $currency ) ); ?></dd>
			</div>
		<?php endforeach; ?>
		<div class="wahou-summary__row wahou-summary__total">
			<dt><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></dt>
			<dd><?php echo esc_html( Money::format( $order->total(), $currency ) ); ?></dd>
		</div>
		<?php foreach ( $refunds as $refund ) : ?>
			<div class="wahou-summary__row wahou-summary__row--refund">
				<dt><?php esc_html_e( 'Refunded', 'wahou-commerce' ); ?></dt>
				<dd>−<?php echo esc_html( Money::format( (int) ( $refund['amount'] ?? 0 ), $currency ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>

	<?php if ( '' !== $mention ) : ?>
		<p class="wahou-summary__tax"><?php echo esc_html( $mention ); ?></p>
	<?php endif; ?>

	<div class="wahou-order-details__addresses">
		<?php if ( $order->needs_shipping() && $order->shipping_address() ) : ?>
			<div class="wahou-order-details__address">
				<h4><?php esc_html_e( 'Delivery address', 'wahou-commerce' ); ?></h4>
				<address><?php echo CheckoutPage::format_address( $order->shipping_address() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper. ?></address>
			</div>
		<?php endif; ?>
		<?php if ( $order->billing() ) : ?>
			<div class="wahou-order-details__address">
				<h4><?php esc_html_e( 'Billing details', 'wahou-commerce' ); ?></h4>
				<address>
					<?php echo CheckoutPage::format_address( $order->billing() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper. ?>
					<br><?php echo esc_html( $order->email() ); ?>
				</address>
			</div>
		<?php endif; ?>
		<div class="wahou-order-details__address">
			<h4><?php esc_html_e( 'Payment method', 'wahou-commerce' ); ?></h4>
			<p><?php echo esc_html( '' !== $order->payment_method_title() ? $order->payment_method_title() : '—' ); ?></p>
		</div>
	</div>
</section>
