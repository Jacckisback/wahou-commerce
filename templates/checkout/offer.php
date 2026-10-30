<?php
/**
 * One-click post-purchase offer (upsell / downsell).
 *
 * Honest by design: no fake countdown, a clear price, a visible "No thanks"
 * and an explicit statement of what will be charged.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/offer.php
 *
 * @var \WahouCommerce\Orders\Order          $order
 * @var array<string, mixed>                 $offer
 * @var \WahouCommerce\Products\Product      $product
 * @var int                                  $image_id
 * @var int                                  $price    Amount charged (tax inclusive).
 * @var int                                  $regular  Usual price (tax inclusive).
 * @var int                                  $percent  Saving in percent.
 * @var string                               $currency
 * @var string                               $method   Payment method title.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

$headline = '' !== (string) $offer['headline']
	? (string) $offer['headline']
	/* translators: %s: product name. */
	: sprintf( __( 'Add %s to your order at a special price', 'wahou-commerce' ), $product->name() );
$text     = '' !== trim( (string) $offer['text'] ) ? (string) $offer['text'] : $product->short_description();
$price_label = Money::format( $price, $currency );
?>
<div class="wahou wahou-offer" data-wahou-offer data-order-key="<?php echo esc_attr( $order->key() ); ?>" data-offer-id="<?php echo esc_attr( (string) $offer['id'] ); ?>" data-fallback="<?php echo esc_url( $order->received_url() ); ?>">
	<p class="wahou-offer__confirmed">
		<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
		<?php
		/* translators: %s: order number. */
		echo esc_html( sprintf( __( 'Order #%s confirmed. One last thing…', 'wahou-commerce' ), $order->number() ) );
		?>
	</p>

	<div class="wahou-offer__card">
		<?php if ( $image_id ) : ?>
			<div class="wahou-offer__media">
				<?php echo wp_get_attachment_image( $image_id, 'large', false, array( 'alt' => esc_attr( $product->name() ), 'fetchpriority' => 'high' ) ); ?>
			</div>
		<?php endif; ?>
		<div class="wahou-offer__body">
			<p class="wahou-offer__eyebrow"><?php esc_html_e( 'Exclusive offer, only on this page', 'wahou-commerce' ); ?></p>
			<h2 class="wahou-offer__title"><?php echo esc_html( $headline ); ?></h2>
			<?php if ( '' !== trim( wp_strip_all_tags( $text ) ) ) : ?>
				<div class="wahou-offer__text"><?php echo wp_kses_post( wpautop( $text ) ); ?></div>
			<?php endif; ?>

			<p class="wahou-offer__price">
				<?php if ( $regular > $price ) : ?>
					<del><?php echo esc_html( Money::format( $regular, $currency ) ); ?></del>
				<?php endif; ?>
				<strong><?php echo esc_html( $price_label ); ?></strong>
				<?php if ( $percent > 0 ) : ?>
					<span class="wahou-offer__save">
						<?php
						/* translators: %d: discount percentage. */
						echo esc_html( sprintf( __( 'You save %d%%', 'wahou-commerce' ), $percent ) );
						?>
					</span>
				<?php endif; ?>
			</p>

			<div class="wahou-notice wahou-notice--error" role="alert" data-wahou-offer-error hidden></div>

			<form method="post" class="wahou-offer__actions">
				<?php wp_nonce_field( 'wahou_offer_' . $order->id(), '_wahou_nonce' ); ?>
				<input type="hidden" name="wahou_offer_id" value="<?php echo esc_attr( (string) $offer['id'] ); ?>">
				<button type="submit" name="wahou_offer_decision" value="accept" class="wahou-btn wahou-btn--primary wahou-btn--block wahou-offer__accept" data-wahou-offer-accept>
					<span>
						<?php
						/* translators: %s: offer price. "Buy now" keeps the button unambiguous about the obligation to pay (Directive 2011/83/EU art. 8(2)). */
						echo esc_html( sprintf( __( 'Yes, buy now – %s', 'wahou-commerce' ), $price_label ) );
						?>
					</span>
					<span class="wahou-co-spinner" aria-hidden="true"></span>
				</button>
				<button type="submit" name="wahou_offer_decision" value="decline" class="wahou-btn wahou-btn--link wahou-offer__decline" data-wahou-offer-decline>
					<?php esc_html_e( 'No thanks, continue to my order', 'wahou-commerce' ); ?>
				</button>
			</form>

			<p class="wahou-offer__note">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: payment method, 2: order number, 3: price. */
						__( 'By clicking "Yes", the %1$s used for order #%2$s will be charged %3$s (VAT included) for this extra item. No need to enter your details again.', 'wahou-commerce' ),
						'' !== $method ? $method : __( 'payment method', 'wahou-commerce' ),
						$order->number(),
						$price_label
					)
				);
				?>
			</p>
		</div>
	</div>
</div>
