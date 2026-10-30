<?php
/**
 * Order summary: items, coupon, totals and VAT note.
 *
 * Used in the checkout sidebar, on the cart page and as the "summary" REST
 * fragment (wahou/v1/cart?html=summary) that checkout.js swaps after each update.
 *
 * Override: copy to {your-theme}/wahou-commerce/cart/summary.php
 *
 * @var \WahouCommerce\Cart\Cart|null $cart
 * @var array<string, mixed>          $totals  Optional (computed when missing).
 * @var string                        $context Optional: "checkout" (default) or "cart".
 *
 * @package WahouCommerce
 */

use WahouCommerce\Settings;
use WahouCommerce\Support\Money;
use WahouCommerce\Tax\Tax;

defined( 'ABSPATH' ) || exit;

$context = isset( $context ) && 'cart' === $context ? 'cart' : 'checkout';

if ( empty( $cart ) || $cart->is_empty() ) {
	echo '<p class="wahou-summary__empty">' . esc_html__( 'Your cart is empty.', 'wahou-commerce' ) . '</p>';
	return;
}

$totals   = isset( $totals ) && is_array( $totals ) && ! empty( $totals['lines'] ) ? $totals : $cart->totals();
$currency = (string) $totals['currency'];
$coupon   = $totals['coupon'] ?? null;
$errors   = (array) ( $totals['errors'] ?? array() );
$pending  = ! $coupon && '' !== $cart->coupon() ? $cart->coupon() : '';
$uid      = 'cart' === $context ? 'cart' : 'checkout';
?>
<div class="wahou-summary wahou-summary--<?php echo esc_attr( $context ); ?>">
	<?php if ( 'checkout' === $context ) : ?>
		<ul class="wahou-summary__items" role="list">
			<?php
			foreach ( $cart->lines() as $line ) :
				$money = $totals['lines'][ $line['key'] ] ?? null;
				if ( ! $money ) {
					continue;
				}
				$special = (int) $line['unit_price'] < (int) $line['regular_unit_price'] && (int) $line['unit_price'] > 0;
				?>
				<li class="wahou-summary__item">
					<span class="wahou-summary__thumb">
						<?php
						if ( $line['image_id'] ) {
							echo wp_get_attachment_image( (int) $line['image_id'], 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '', 'decoding' => 'async' ) );
						}
						?>
						<span class="wahou-summary__qty">
							<span class="wahou-visually-hidden"><?php esc_html_e( 'Quantity:', 'wahou-commerce' ); ?></span>
							<?php echo esc_html( number_format_i18n( (int) $line['qty'] ) ); ?>
						</span>
					</span>
					<span class="wahou-summary__name">
						<?php echo esc_html( $line['name'] ); ?>
						<?php if ( '' !== $line['variant_label'] ) : ?>
							<small class="wahou-summary__variant"><?php echo esc_html( $line['variant_label'] ); ?></small>
						<?php endif; ?>
						<?php if ( ! empty( $line['meta']['bump'] ) ) : ?>
							<small class="wahou-summary__tag"><?php esc_html_e( 'Special offer', 'wahou-commerce' ); ?></small>
						<?php elseif ( 'digital' === $line['kind'] ) : ?>
							<small class="wahou-summary__tag wahou-summary__tag--muted"><?php esc_html_e( 'Instant download', 'wahou-commerce' ); ?></small>
						<?php endif; ?>
					</span>
					<span class="wahou-summary__price">
						<?php if ( $special ) : ?>
							<del><?php echo esc_html( Money::format( (int) round( $money['subtotal'] * (int) $line['regular_unit_price'] / max( 1, (int) $line['unit_price'] ) ), $currency ) ); ?></del>
						<?php endif; ?>
						<?php echo esc_html( Money::format( (int) $money['subtotal'], $currency ) ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<div class="wahou-coupon" data-wahou-coupon>
		<?php if ( $coupon ) : ?>
			<div class="wahou-coupon__applied">
				<span class="wahou-coupon__chip">
					<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
					<?php echo esc_html( (string) $coupon['code'] ); ?>
				</span>
				<?php if ( 'cart' === $context ) : ?>
					<form method="post" class="wahou-coupon__remove-form" data-wahou-cp-coupon-remove>
						<?php wp_nonce_field( 'wahou_cart', '_wahou_nonce' ); ?>
						<input type="hidden" name="wahou_action" value="remove_coupon">
						<button type="submit" class="wahou-coupon__remove"><?php esc_html_e( 'Remove', 'wahou-commerce' ); ?></button>
					</form>
				<?php else : ?>
					<button type="button" class="wahou-coupon__remove" data-wahou-coupon-remove>
						<?php esc_html_e( 'Remove', 'wahou-commerce' ); ?>
						<span class="wahou-visually-hidden"><?php echo esc_html( (string) $coupon['code'] ); ?></span>
					</button>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<details class="wahou-coupon__details"<?php echo ( '' !== $pending || ! empty( $errors['coupon'] ) ) ? ' open' : ''; ?>>
				<summary class="wahou-coupon__toggle"><?php esc_html_e( 'Have a coupon code?', 'wahou-commerce' ); ?></summary>
				<?php if ( 'cart' === $context ) : ?>
					<form method="post" class="wahou-coupon__form" data-wahou-cp-coupon>
						<?php wp_nonce_field( 'wahou_cart', '_wahou_nonce' ); ?>
						<input type="hidden" name="wahou_action" value="apply_coupon">
						<label class="wahou-visually-hidden" for="wahou-coupon-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Coupon code', 'wahou-commerce' ); ?></label>
						<input type="text" id="wahou-coupon-<?php echo esc_attr( $uid ); ?>" name="coupon_code" class="wahou-input wahou-coupon__input" value="<?php echo esc_attr( $pending ); ?>" placeholder="<?php esc_attr_e( 'Coupon code', 'wahou-commerce' ); ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" data-wahou-coupon-input>
						<button type="submit" class="wahou-btn wahou-btn--secondary wahou-coupon__apply"><?php esc_html_e( 'Apply', 'wahou-commerce' ); ?></button>
					</form>
				<?php else : ?>
					<div class="wahou-coupon__form">
						<label class="wahou-visually-hidden" for="wahou-coupon-<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Coupon code', 'wahou-commerce' ); ?></label>
						<input type="text" id="wahou-coupon-<?php echo esc_attr( $uid ); ?>" class="wahou-input wahou-coupon__input" value="<?php echo esc_attr( $pending ); ?>" placeholder="<?php esc_attr_e( 'Coupon code', 'wahou-commerce' ); ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="go" data-wahou-coupon-input>
						<button type="button" class="wahou-btn wahou-btn--secondary wahou-coupon__apply" data-wahou-coupon-apply><?php esc_html_e( 'Apply', 'wahou-commerce' ); ?></button>
					</div>
				<?php endif; ?>
				<p class="wahou-coupon__error" role="alert" data-wahou-coupon-error<?php echo empty( $errors['coupon'] ) ? ' hidden' : ''; ?>>
					<?php
					if ( ! empty( $errors['coupon'] ) ) {
						echo esc_html( '' !== $pending ? $pending . ' – ' . $errors['coupon'] : $errors['coupon'] );
					}
					?>
				</p>
			</details>
		<?php endif; ?>
	</div>

	<dl class="wahou-summary__totals">
		<div class="wahou-summary__row">
			<dt><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></dt>
			<dd><?php echo esc_html( Money::format( (int) $totals['subtotal'], $currency ) ); ?></dd>
		</div>
		<?php if ( (int) $totals['discount'] > 0 ) : ?>
			<div class="wahou-summary__row wahou-summary__row--discount">
				<dt>
					<?php esc_html_e( 'Discount', 'wahou-commerce' ); ?>
					<?php if ( $coupon ) : ?>
						<small>(<?php echo esc_html( (string) $coupon['code'] ); ?>)</small>
					<?php endif; ?>
				</dt>
				<dd>−<?php echo esc_html( Money::format( (int) $totals['discount'], $currency ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $totals['needs_shipping'] ) ) : ?>
			<div class="wahou-summary__row">
				<dt>
					<?php esc_html_e( 'Shipping', 'wahou-commerce' ); ?>
					<?php if ( '' !== (string) $totals['shipping_title'] ) : ?>
						<small><?php echo esc_html( (string) $totals['shipping_title'] ); ?></small>
					<?php endif; ?>
				</dt>
				<dd>
					<?php
					if ( empty( $totals['shipping_method'] ) ) {
						echo '—';
					} elseif ( (int) $totals['shipping'] > 0 ) {
						echo esc_html( Money::format( (int) $totals['shipping'], $currency ) );
					} else {
						echo '<strong class="wahou-summary__free">' . esc_html__( 'Free', 'wahou-commerce' ) . '</strong>';
					}
					?>
				</dd>
			</div>
		<?php endif; ?>
		<div class="wahou-summary__row wahou-summary__total">
			<dt><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></dt>
			<dd>
				<span class="wahou-summary__currency"><?php echo esc_html( $currency ); ?></span>
				<span data-wahou-summary-total><?php echo esc_html( Money::format( (int) $totals['total'], $currency ) ); ?></span>
			</dd>
		</div>
	</dl>

	<?php
	$mode = (string) $totals['tax_mode'];
	$note = '';
	if ( Tax::mode_is_taxable( $mode ) && (int) $totals['tax'] > 0 ) {
		/* translators: %s: VAT amount. */
		$note = sprintf( __( 'Including %s VAT', 'wahou-commerce' ), Money::format( (int) $totals['tax'], $currency ) );
	} elseif ( 'reverse_charge' === $mode ) {
		$note = __( 'VAT reverse charged (EU business customer).', 'wahou-commerce' );
	} elseif ( 'export' === $mode ) {
		$note = __( 'No VAT applied: delivery outside the EU VAT area.', 'wahou-commerce' );
	} elseif ( 'exempt' === $mode ) {
		$note = Tax::invoice_mention( 'exempt' );
	}
	if ( '' !== $note ) :
		?>
		<p class="wahou-summary__tax"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>

	<?php if ( 'cart' === $context && ! empty( $totals['needs_shipping'] ) && Settings::bool( 'free_shipping_bar' ) && null !== $totals['free_shipping_remaining'] && (int) $totals['free_shipping_threshold'] > 0 ) : ?>
		<?php
		$threshold = (int) $totals['free_shipping_threshold'];
		$remaining = (int) $totals['free_shipping_remaining'];
		$progress  = max( 0, min( 100, (int) round( ( $threshold - $remaining ) * 100 / $threshold ) ) );
		?>
		<div class="wahou-free-ship<?php echo 0 === $remaining ? ' is-unlocked' : ''; ?>">
			<p class="wahou-free-ship__text">
				<?php
				if ( $remaining > 0 ) {
					/* translators: %s: amount missing for free delivery. */
					echo esc_html( sprintf( __( 'Add %s more to get free delivery.', 'wahou-commerce' ), Money::format( $remaining, $currency ) ) );
				} else {
					esc_html_e( 'You have unlocked free delivery!', 'wahou-commerce' );
				}
				?>
			</p>
			<div class="wahou-free-ship__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>" aria-label="<?php esc_attr_e( 'Progress towards free delivery', 'wahou-commerce' ); ?>">
				<span style="width:<?php echo esc_attr( (string) $progress ); ?>%"></span>
			</div>
		</div>
	<?php endif; ?>
</div>
