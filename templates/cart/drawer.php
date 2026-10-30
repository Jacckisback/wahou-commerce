<?php
/**
 * Cart drawer content (server-rendered, returned by the REST API with ?html=drawer).
 *
 * @var \WahouCommerce\Cart\Cart|null $cart
 * @var array<string, mixed>|null     $totals
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

$wahou_totals   = isset( $totals ) && is_array( $totals ) ? $totals : ( $cart ? $cart->totals() : null );
$wahou_lines    = $cart ? $cart->lines() : array();
$wahou_count    = $cart ? $cart->count() : 0;
$wahou_currency = $wahou_totals ? (string) $wahou_totals['currency'] : Settings::currency();
$wahou_shop     = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );
?>
<div class="wahou-drawer__header">
	<h2 id="wahou-drawer-title" class="wahou-drawer__title">
		<?php esc_html_e( 'Your cart', 'wahou-commerce' ); ?>
		<?php if ( $wahou_count ) : ?>
			<span class="wahou-drawer__count">(<?php echo esc_html( number_format_i18n( $wahou_count ) ); ?>)</span>
		<?php endif; ?>
	</h2>
	<button type="button" class="wahou-icon-btn" data-wahou-close data-wahou-focus="close" aria-label="<?php esc_attr_e( 'Close cart', 'wahou-commerce' ); ?>"><?php echo Render::icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
</div>

<?php if ( ! $wahou_lines || ! $wahou_totals ) : ?>
	<div class="wahou-drawer__body wahou-drawer__body--empty">
		<div class="wahou-empty">
			<?php echo Render::icon( 'cart', 44 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p><?php esc_html_e( 'Your cart is empty.', 'wahou-commerce' ); ?></p>
			<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( $wahou_shop ); ?>"><?php esc_html_e( 'Discover the shop', 'wahou-commerce' ); ?></a>
		</div>
	</div>
	<?php
	return;
endif;

$wahou_items_total = (int) $wahou_totals['subtotal'] - (int) $wahou_totals['discount'];
$wahou_threshold   = $wahou_totals['free_shipping_threshold'];
$wahou_remaining   = $wahou_totals['free_shipping_remaining'];
$wahou_cross_sells = Render::cross_sells( $cart );
?>
<div class="wahou-drawer__body">
	<?php if ( Settings::bool( 'free_shipping_bar' ) && $wahou_totals['needs_shipping'] && null !== $wahou_threshold && $wahou_threshold > 0 ) : ?>
		<?php $wahou_progress = null === $wahou_remaining ? 100 : (int) floor( max( 0, min( 100, ( $wahou_threshold - $wahou_remaining ) * 100 / $wahou_threshold ) ) ); ?>
		<div class="wahou-freeship<?php echo 100 <= $wahou_progress ? ' is-unlocked' : ''; ?>">
			<p class="wahou-freeship__text">
				<?php echo Render::icon( 'truck', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span>
					<?php
					if ( $wahou_progress >= 100 ) {
						esc_html_e( 'Free delivery unlocked!', 'wahou-commerce' );
					} else {
						printf(
							/* translators: %s: amount left to reach free shipping. */
							esc_html__( 'Only %s more for free delivery', 'wahou-commerce' ),
							'<strong>' . esc_html( Money::format( (int) $wahou_remaining, $wahou_currency ) ) . '</strong>'
						);
					}
					?>
				</span>
			</p>
			<div class="wahou-freeship__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $wahou_progress; ?>" aria-label="<?php esc_attr_e( 'Progress towards free delivery', 'wahou-commerce' ); ?>"><span style="width:<?php echo (int) $wahou_progress; ?>%"></span></div>
		</div>
	<?php endif; ?>

	<ul class="wahou-lines">
		<?php foreach ( $wahou_lines as $wahou_line ) : ?>
			<?php
			$wahou_key     = (string) $wahou_line['key'];
			$wahou_money   = $wahou_totals['lines'][ $wahou_key ] ?? null;
			$wahou_total   = $wahou_money ? (int) $wahou_money['subtotal'] : 0;
			$wahou_regular = $wahou_line['unit_price'] > 0 && $wahou_line['regular_unit_price'] > $wahou_line['unit_price']
				? (int) round( $wahou_total * $wahou_line['regular_unit_price'] / $wahou_line['unit_price'] )
				: 0;
			$wahou_is_bump = ! empty( $wahou_line['meta']['bump'] );
			$wahou_max     = null === $wahou_line['max_qty'] ? 999 : (int) $wahou_line['max_qty'];
			?>
			<li class="wahou-line" data-key="<?php echo esc_attr( $wahou_key ); ?>">
				<a class="wahou-line__thumb" href="<?php echo esc_url( $wahou_line['permalink'] ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo Render::image( (int) $wahou_line['image_id'], 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
				<div class="wahou-line__info">
					<a class="wahou-line__name" href="<?php echo esc_url( $wahou_line['permalink'] ); ?>"><?php echo esc_html( $wahou_line['name'] ); ?></a>
					<?php if ( '' !== $wahou_line['variant_label'] ) : ?>
						<span class="wahou-line__meta"><?php echo esc_html( $wahou_line['variant_label'] ); ?></span>
					<?php endif; ?>
					<?php if ( $wahou_is_bump ) : ?>
						<span class="wahou-badge wahou-badge--sale"><?php esc_html_e( 'Special offer', 'wahou-commerce' ); ?></span>
					<?php elseif ( ! empty( $wahou_line['meta']['fbt_parent'] ) && $wahou_regular ) : ?>
						<span class="wahou-badge wahou-badge--sale"><?php esc_html_e( 'Bundle discount', 'wahou-commerce' ); ?></span>
					<?php endif; ?>
					<div class="wahou-line__controls">
						<?php if ( $wahou_is_bump || 1 === $wahou_max ) : ?>
							<span class="wahou-line__qty-fixed">
								<?php
								/* translators: %d: quantity. */
								echo esc_html( sprintf( __( 'Qty: %d', 'wahou-commerce' ), (int) $wahou_line['qty'] ) );
								?>
							</span>
						<?php else : ?>
							<div class="wahou-qty wahou-qty--sm" data-wahou-line-qty>
								<button type="button" class="wahou-qty__btn" data-wahou-line-step="-1" data-wahou-focus="<?php echo esc_attr( 'dec-' . $wahou_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Decrease quantity of %s', 'wahou-commerce' ), $wahou_line['name'] ) ); ?>"><?php echo Render::icon( 'minus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
								<input class="wahou-qty__input" type="number" inputmode="numeric" min="0" max="<?php echo (int) $wahou_max; ?>" value="<?php echo (int) $wahou_line['qty']; ?>" data-wahou-line-input data-wahou-focus="<?php echo esc_attr( 'qty-' . $wahou_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Quantity of %s', 'wahou-commerce' ), $wahou_line['name'] ) ); ?>">
								<button type="button" class="wahou-qty__btn" data-wahou-line-step="1" data-wahou-focus="<?php echo esc_attr( 'inc-' . $wahou_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Increase quantity of %s', 'wahou-commerce' ), $wahou_line['name'] ) ); ?>"<?php disabled( $wahou_line['qty'] >= $wahou_max ); ?>><?php echo Render::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
							</div>
						<?php endif; ?>
						<button type="button" class="wahou-line__remove" data-wahou-remove data-wahou-focus="<?php echo esc_attr( 'rm-' . $wahou_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Remove %s from cart', 'wahou-commerce' ), $wahou_line['name'] ) ); ?>"><?php echo Render::icon( 'trash', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
				</div>
				<div class="wahou-line__price">
					<span><?php echo esc_html( Money::format( $wahou_total, $wahou_currency ) ); ?></span>
					<?php if ( $wahou_regular > $wahou_total ) : ?>
						<del><?php echo esc_html( Money::format( $wahou_regular, $wahou_currency ) ); ?></del>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $wahou_cross_sells ) : ?>
		<section class="wahou-xsell" aria-labelledby="wahou-xsell-title">
			<h3 id="wahou-xsell-title" class="wahou-xsell__title"><?php esc_html_e( 'Complete your order', 'wahou-commerce' ); ?></h3>
			<ul class="wahou-xsell__list">
				<?php foreach ( $wahou_cross_sells as $wahou_product ) : ?>
					<li class="wahou-xsell__item">
						<a class="wahou-xsell__thumb" href="<?php echo esc_url( $wahou_product->permalink() ); ?>" tabindex="-1" aria-hidden="true"><?php echo Render::image( $wahou_product->image_id(), 'thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<span class="wahou-xsell__info">
							<a href="<?php echo esc_url( $wahou_product->permalink() ); ?>"><?php echo esc_html( $wahou_product->name() ); ?></a>
							<?php echo Render::card_price( $wahou_product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<button type="button" class="wahou-btn wahou-btn--secondary wahou-btn--sm" data-wahou-add="<?php echo (int) $wahou_product->id(); ?>" data-wahou-focus="<?php echo esc_attr( 'xs-' . $wahou_product->id() ); ?>">
							<?php echo Render::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php esc_html_e( 'Add', 'wahou-commerce' ); ?></span>
							<span class="screen-reader-text"><?php echo esc_html( $wahou_product->name() ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>

<div class="wahou-drawer__footer">
	<?php if ( ! empty( $wahou_totals['coupon'] ) ) : ?>
		<div class="wahou-coupon-chip">
			<?php echo Render::icon( 'tag', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><strong><?php echo esc_html( (string) $wahou_totals['coupon']['code'] ); ?></strong>
			<?php if ( (int) $wahou_totals['coupon']['amount'] > 0 ) : ?>
				−<?php echo esc_html( Money::format( (int) $wahou_totals['coupon']['amount'], $wahou_currency ) ); ?>
			<?php elseif ( ! empty( $wahou_totals['coupon']['free_shipping'] ) ) : ?>
				· <?php esc_html_e( 'Free delivery', 'wahou-commerce' ); ?>
			<?php endif; ?></span>
			<button type="button" class="wahou-icon-btn wahou-icon-btn--sm" data-wahou-coupon-remove data-wahou-focus="coupon-remove" aria-label="<?php esc_attr_e( 'Remove coupon', 'wahou-commerce' ); ?>"><?php echo Render::icon( 'close', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</div>
	<?php else : ?>
		<details class="wahou-coupon"<?php echo ! empty( $wahou_totals['errors']['coupon'] ) ? ' open' : ''; ?>>
			<summary data-wahou-focus="coupon-toggle"><?php esc_html_e( 'Have a discount code?', 'wahou-commerce' ); ?></summary>
			<form class="wahou-coupon__form" data-wahou-coupon-form>
				<label class="screen-reader-text" for="wahou-drawer-coupon"><?php esc_html_e( 'Discount code', 'wahou-commerce' ); ?></label>
				<input class="wahou-input" id="wahou-drawer-coupon" name="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php esc_attr_e( 'Discount code', 'wahou-commerce' ); ?>" data-wahou-focus="coupon-input" required>
				<button type="submit" class="wahou-btn wahou-btn--secondary" data-wahou-focus="coupon-apply"><?php esc_html_e( 'Apply', 'wahou-commerce' ); ?></button>
			</form>
			<p class="wahou-field-error" data-wahou-coupon-error role="alert"<?php echo empty( $wahou_totals['errors']['coupon'] ) ? ' hidden' : ''; ?>><?php echo esc_html( (string) ( $wahou_totals['errors']['coupon'] ?? '' ) ); ?></p>
		</details>
	<?php endif; ?>

	<dl class="wahou-drawer__totals">
		<?php if ( (int) $wahou_totals['discount'] > 0 ) : ?>
			<div><dt><?php esc_html_e( 'Discount', 'wahou-commerce' ); ?></dt><dd>−<?php echo esc_html( Money::format( (int) $wahou_totals['discount'], $wahou_currency ) ); ?></dd></div>
		<?php endif; ?>
		<div class="wahou-drawer__subtotal"><dt><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( Money::format( $wahou_items_total, $wahou_currency ) ); ?></dd></div>
	</dl>
	<p class="wahou-drawer__note wahou-muted">
		<?php
		$wahou_notes = array();
		if ( Settings::bool( 'tax_enabled' ) && ! Settings::bool( 'tax_exempt' ) ) {
			$wahou_notes[] = Settings::bool( 'prices_include_tax' ) ? __( 'Taxes included.', 'wahou-commerce' ) : __( 'Taxes calculated at checkout.', 'wahou-commerce' );
		}
		$wahou_notes[] = $wahou_totals['needs_shipping'] ? __( 'Shipping calculated at checkout.', 'wahou-commerce' ) : __( 'Instant delivery by email.', 'wahou-commerce' );
		echo esc_html( implode( ' ', $wahou_notes ) );
		?>
	</p>
	<a class="wahou-btn wahou-btn--primary wahou-btn--lg wahou-btn--block" href="<?php echo esc_url( Pages::url( 'checkout' ) ); ?>" data-wahou-checkout-link>
		<?php echo Render::icon( 'lock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php esc_html_e( 'Checkout', 'wahou-commerce' ); ?></span>
	</a>
	<button type="button" class="wahou-link-btn" data-wahou-close><?php esc_html_e( 'Continue shopping', 'wahou-commerce' ); ?></button>
</div>
