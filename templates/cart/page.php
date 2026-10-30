<?php
/**
 * Cart page (works without JavaScript; enhanced by checkout.js).
 *
 * Also returned by the REST API as the "page" fragment.
 *
 * Override: copy to {your-theme}/wahou-commerce/cart/page.php
 *
 * @var \WahouCommerce\Cart\Cart|null                 $cart
 * @var array<string, mixed>                          $totals Optional.
 * @var array{type:string, message:string}|null       $flash  Optional notice.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Products\Product;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$shop_url = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );

if ( empty( $cart ) || $cart->is_empty() ) :
	?>
	<div class="wahou wahou-cart-page" data-wahou-cart-page>
		<?php Template::render( 'checkout/empty', array( 'flash' => $flash ?? null ) ); ?>
	</div>
	<?php
	return;
endif;

$totals   = isset( $totals ) && is_array( $totals ) && ! empty( $totals['lines'] ) ? $totals : $cart->totals();
$currency = (string) $totals['currency'];
$lines    = $cart->lines();
$in_cart  = array_map( static fn( $l ) => (int) $l['product_id'], $lines );

// Cross-sells: products suggested by the items in the cart.
$suggestions = array();
foreach ( $lines as $line ) {
	$product = Product::find( (int) $line['product_id'] );
	foreach ( $product ? $product->cross_sell_ids() : array() as $id ) {
		if ( count( $suggestions ) >= 4 || isset( $suggestions[ $id ] ) || in_array( $id, $in_cart, true ) ) {
			continue;
		}
		$candidate = Product::find( $id );
		if ( $candidate && 'publish' === $candidate->status() && $candidate->in_stock() ) {
			$suggestions[ $id ] = $candidate;
		}
	}
}
?>
<div class="wahou wahou-cart-page" data-wahou-cart-page>
	<?php if ( ! empty( $flash['message'] ) ) : ?>
		<div class="wahou-notice wahou-notice--<?php echo 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success'; ?>" role="status">
			<?php echo esc_html( (string) $flash['message'] ); ?>
		</div>
	<?php endif; ?>
	<div class="wahou-notice wahou-notice--error" role="alert" data-wahou-cp-error hidden></div>

	<div class="wahou-cart-page__layout">
		<form class="wahou-cart-page__items" method="post" data-wahou-cp-form>
			<?php wp_nonce_field( 'wahou_cart', '_wahou_nonce' ); ?>
			<input type="hidden" name="wahou_action" value="update_cart">
			<button type="submit" class="wahou-visually-hidden" tabindex="-1"><?php esc_html_e( 'Update cart', 'wahou-commerce' ); ?></button>

			<h2 class="wahou-cart-page__title">
				<?php
				/* translators: %s: number of items. */
				echo esc_html( sprintf( _n( '%s item', '%s items', $cart->count(), 'wahou-commerce' ), number_format_i18n( $cart->count() ) ) );
				?>
			</h2>

			<ul class="wahou-cart-lines" role="list">
				<?php
				foreach ( $lines as $line ) :
					$money = $totals['lines'][ $line['key'] ] ?? null;
					if ( ! $money ) {
						continue;
					}
					$key      = (string) $line['key'];
					$max      = null === $line['max_qty'] ? 999 : max( 1, (int) $line['max_qty'] );
					$input_id = 'wahou-qty-' . $key;
					$single   = 1 === $max;
					?>
					<li class="wahou-cart-line" data-key="<?php echo esc_attr( $key ); ?>">
						<a class="wahou-cart-line__image" href="<?php echo esc_url( $line['permalink'] ); ?>" tabindex="-1" aria-hidden="true">
							<?php
							if ( $line['image_id'] ) {
								echo wp_get_attachment_image( (int) $line['image_id'], 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '', 'decoding' => 'async' ) );
							} else {
								echo '<span class="wahou-cart-line__placeholder"></span>';
							}
							?>
						</a>
						<div class="wahou-cart-line__info">
							<a class="wahou-cart-line__name" href="<?php echo esc_url( $line['permalink'] ); ?>"><?php echo esc_html( $line['name'] ); ?></a>
							<?php if ( '' !== $line['variant_label'] ) : ?>
								<p class="wahou-cart-line__variant"><?php echo esc_html( $line['variant_label'] ); ?></p>
							<?php endif; ?>
							<p class="wahou-cart-line__unit">
								<?php
								/* translators: %s: unit price. */
								echo esc_html( sprintf( __( '%s each', 'wahou-commerce' ), Money::format( (int) $money['unit'], $currency ) ) );
								?>
								<?php if ( ! empty( $line['meta']['bump'] ) ) : ?>
									<span class="wahou-cart-line__tag"><?php esc_html_e( 'Special offer', 'wahou-commerce' ); ?></span>
								<?php elseif ( 'digital' === $line['kind'] ) : ?>
									<span class="wahou-cart-line__tag wahou-cart-line__tag--muted"><?php esc_html_e( 'Instant download', 'wahou-commerce' ); ?></span>
								<?php endif; ?>
							</p>
						</div>
						<div class="wahou-cart-line__qty">
							<?php if ( $single ) : ?>
								<input type="hidden" name="qty[<?php echo esc_attr( $key ); ?>]" value="1">
								<span class="wahou-cart-line__single"><?php esc_html_e( 'Qty: 1', 'wahou-commerce' ); ?></span>
							<?php else : ?>
								<div class="wahou-qty">
									<button type="button" class="wahou-qty__btn" data-wahou-cp-step="-1" aria-controls="<?php echo esc_attr( $input_id ); ?>" aria-label="<?php esc_attr_e( 'Decrease quantity', 'wahou-commerce' ); ?>">−</button>
									<label class="wahou-visually-hidden" for="<?php echo esc_attr( $input_id ); ?>">
										<?php
										/* translators: %s: product name. */
										echo esc_html( sprintf( __( 'Quantity of %s', 'wahou-commerce' ), $line['name'] ) );
										?>
									</label>
									<input type="number" id="<?php echo esc_attr( $input_id ); ?>" class="wahou-qty__input" name="qty[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $line['qty'] ); ?>" min="0" max="<?php echo esc_attr( (string) $max ); ?>" step="1" inputmode="numeric" data-wahou-cp-qty data-key="<?php echo esc_attr( $key ); ?>">
									<button type="button" class="wahou-qty__btn" data-wahou-cp-step="1" aria-controls="<?php echo esc_attr( $input_id ); ?>" aria-label="<?php esc_attr_e( 'Increase quantity', 'wahou-commerce' ); ?>">+</button>
								</div>
							<?php endif; ?>
							<button type="submit" name="remove" value="<?php echo esc_attr( $key ); ?>" class="wahou-cart-line__remove" data-wahou-cp-remove="<?php echo esc_attr( $key ); ?>">
								<?php esc_html_e( 'Remove', 'wahou-commerce' ); ?>
								<span class="wahou-visually-hidden"><?php echo esc_html( $line['name'] ); ?></span>
							</button>
						</div>
						<div class="wahou-cart-line__total">
							<?php echo esc_html( Money::format( (int) $money['subtotal'], $currency ) ); ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<noscript>
				<p><button type="submit" class="wahou-btn wahou-btn--secondary"><?php esc_html_e( 'Update cart', 'wahou-commerce' ); ?></button></p>
			</noscript>
		</form>

		<aside class="wahou-cart-page__summary" aria-label="<?php esc_attr_e( 'Order summary', 'wahou-commerce' ); ?>">
			<?php
			Template::render(
				'cart/summary',
				array(
					'cart'    => $cart,
					'totals'  => $totals,
					'context' => 'cart',
				)
			);
			?>
			<a class="wahou-btn wahou-btn--primary wahou-btn--block wahou-cart-page__checkout" href="<?php echo esc_url( Pages::url( 'checkout' ) ); ?>">
				<?php esc_html_e( 'Checkout', 'wahou-commerce' ); ?>
				<span aria-hidden="true">·</span>
				<span><?php echo esc_html( Money::format( (int) $totals['total'], $currency ) ); ?></span>
			</a>
			<p class="wahou-cart-page__trust">
				<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
				<?php esc_html_e( 'Secure checkout · Encrypted payment', 'wahou-commerce' ); ?>
			</p>
			<a class="wahou-cart-page__continue" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue shopping', 'wahou-commerce' ); ?></a>
		</aside>
	</div>

	<?php if ( $suggestions ) : ?>
		<section class="wahou-cart-upsell" aria-labelledby="wahou-cart-upsell-title">
			<h2 id="wahou-cart-upsell-title" class="wahou-cart-upsell__title"><?php esc_html_e( 'Complete your order', 'wahou-commerce' ); ?></h2>
			<ul class="wahou-cart-upsell__list" role="list">
				<?php foreach ( $suggestions as $suggestion ) : ?>
					<li class="wahou-cart-upsell__item">
						<a class="wahou-cart-upsell__image" href="<?php echo esc_url( $suggestion->permalink() ); ?>" tabindex="-1" aria-hidden="true">
							<?php
							if ( $suggestion->image_id() ) {
								echo wp_get_attachment_image( $suggestion->image_id(), 'medium', false, array( 'loading' => 'lazy', 'alt' => '', 'decoding' => 'async' ) );
							}
							?>
						</a>
						<a class="wahou-cart-upsell__name" href="<?php echo esc_url( $suggestion->permalink() ); ?>"><?php echo esc_html( $suggestion->name() ); ?></a>
						<span class="wahou-cart-upsell__price"><?php echo esc_html( Money::format( $suggestion->price(), $currency ) ); ?></span>
						<?php if ( 'variable' === $suggestion->type() ) : ?>
							<a class="wahou-btn wahou-btn--secondary wahou-cart-upsell__add" href="<?php echo esc_url( $suggestion->permalink() ); ?>"><?php esc_html_e( 'Choose', 'wahou-commerce' ); ?></a>
						<?php else : ?>
							<a class="wahou-btn wahou-btn--secondary wahou-cart-upsell__add" href="<?php echo esc_url( add_query_arg( 'wahou_add', $suggestion->id(), Pages::url( 'cart' ) ) ); ?>" data-wahou-cp-add="<?php echo esc_attr( (string) $suggestion->id() ); ?>" rel="nofollow">
								<?php esc_html_e( 'Add', 'wahou-commerce' ); ?>
								<span class="wahou-visually-hidden"><?php echo esc_html( $suggestion->name() ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
