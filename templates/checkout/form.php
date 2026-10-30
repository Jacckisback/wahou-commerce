<?php
/**
 * One-page checkout.
 *
 * Mobile first: a single column with the order summary collapsed in a bar at
 * the top; on desktop the summary becomes a sticky right column. The form is
 * submitted by assets/js/checkout.js (REST), never as a classic POST.
 *
 * Override: copy to {your-theme}/wahou-commerce/checkout/form.php
 *
 * @var \WahouCommerce\Cart\Cart                         $cart
 * @var array<string, mixed>                             $totals
 * @var array<string, mixed>                             $prefill
 * @var bool                                             $needs_shipping
 * @var bool                                             $has_digital
 * @var array<string, string>                            $countries
 * @var array<string, string>                            $billing_countries
 * @var array<string, \WahouCommerce\Gateways\Gateway>   $gateways
 * @var array<string, mixed>|null                        $bump
 * @var string                                           $pay_label
 * @var string                                           $terms_url
 * @var string                                           $privacy_url
 * @var bool                                             $digital_waiver
 * @var string                                           $recovery_mode   '', 'notice' or 'checkbox'.
 * @var bool                                             $order_notes
 * @var bool                                             $honeypot
 * @var bool                                             $show_vat_field
 * @var string                                           $company_field
 * @var string                                           $login_url
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\CheckoutPage;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Template;

defined( 'ABSPATH' ) || exit;

$currency  = (string) $totals['currency'];
$step      = 0;
$tax_group = $needs_shipping ? 'shipping' : 'billing';
$ship      = (array) $prefill['shipping'];
$bill      = (array) $prefill['billing'];
$business  = ! empty( $prefill['is_business'] );
$sync      = array( 'data-wahou-sync' => '1' );

$shipping_fields = $needs_shipping ? CheckoutPage::ordered_fields( 'shipping', true ) : array();
$billing_fields  = CheckoutPage::ordered_fields( 'billing', true );
unset( $billing_fields['company'] );
$basic_billing   = CheckoutPage::ordered_fields( 'billing', false );
$address_only    = array_diff_key( $billing_fields, $basic_billing );
$gateway_ids     = array_keys( $gateways );
$first_gateway   = $gateway_ids[0] ?? '';
?>
<div class="wahou wahou-checkout" data-wahou-checkout>
	<noscript>
		<div class="wahou-notice wahou-notice--error"><?php esc_html_e( 'Please enable JavaScript in your browser to complete your order securely.', 'wahou-commerce' ); ?></div>
	</noscript>

	<form id="wahou-checkout-form" class="wahou-checkout__form" method="post" action="" novalidate
		data-needs-shipping="<?php echo $needs_shipping ? '1' : '0'; ?>"
		data-has-digital="<?php echo $has_digital ? '1' : '0'; ?>">

		<div class="wahou-checkout__layout">

			<aside class="wahou-checkout__aside" aria-label="<?php esc_attr_e( 'Order summary', 'wahou-commerce' ); ?>">
				<button type="button" class="wahou-checkout__summary-toggle" aria-expanded="false" aria-controls="wahou-checkout-summary" data-wahou-summary-toggle>
					<span class="wahou-checkout__summary-label">
						<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
						<span data-wahou-summary-label><?php esc_html_e( 'Show order summary', 'wahou-commerce' ); ?></span>
						<svg class="wahou-checkout__chevron" aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
					</span>
					<span class="wahou-checkout__summary-total" data-wahou-total><?php echo esc_html( Money::format( (int) $totals['total'], $currency ) ); ?></span>
				</button>
				<div class="wahou-checkout__summary" id="wahou-checkout-summary" data-wahou-summary aria-live="polite">
					<?php
					Template::render(
						'cart/summary',
						array(
							'cart'    => $cart,
							'totals'  => $totals,
							'context' => 'checkout',
						)
					);
					?>
				</div>
			</aside>

			<div class="wahou-checkout__main">

				<section class="wahou-checkout__express" data-wahou-express-section>
					<div id="wahou-express" class="wahou-express"></div>
					<p class="wahou-checkout__divider" data-wahou-express-divider hidden><span><?php esc_html_e( 'or', 'wahou-commerce' ); ?></span></p>
				</section>

				<?php /* ---------------------------------------------------- Contact */ ?>
				<section class="wahou-checkout__section" aria-labelledby="wahou-step-contact">
					<div class="wahou-checkout__heading">
						<h2 class="wahou-checkout__title" id="wahou-step-contact">
							<span class="wahou-checkout__step" aria-hidden="true"><?php echo esc_html( (string) ++$step ); ?></span>
							<?php esc_html_e( 'Contact', 'wahou-commerce' ); ?>
						</h2>
						<?php if ( '' !== $login_url ) : ?>
							<a class="wahou-checkout__login" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'wahou-commerce' ); ?></a>
						<?php endif; ?>
					</div>

					<div class="wahou-checkout__fields">
						<?php
						CheckoutPage::field(
							'',
							'email',
							array(
								'label'        => __( 'Email', 'wahou-commerce' ),
								'required'     => true,
								'type'         => 'email',
								'autocomplete' => 'email',
								'inputmode'    => 'email',
								'placeholder'  => __( 'you@example.com', 'wahou-commerce' ),
							),
							(string) $prefill['email'],
							array(),
							array(
								'data-wahou-sync' => '1',
								'spellcheck'      => 'false',
								'autocapitalize'  => 'off',
							)
						);
						?>
					</div>
					<p class="wahou-checkout__hint"><?php esc_html_e( 'Your confirmation and receipt will be sent to this address.', 'wahou-commerce' ); ?></p>

					<?php if ( 'checkbox' === $recovery_mode ) : ?>
						<label class="wahou-checkbox">
							<input type="checkbox" name="recovery_consent" value="1" data-wahou-recovery-consent<?php checked( true === $cart->consent() ); ?>>
							<span><?php esc_html_e( 'Email me a reminder if I don’t finish my order.', 'wahou-commerce' ); ?></span>
						</label>
					<?php elseif ( 'notice' === $recovery_mode ) : ?>
						<p class="wahou-checkout__fineprint"><?php esc_html_e( 'We save your cart: if you do not complete your order, we may send you a reminder email. You can unsubscribe in one click.', 'wahou-commerce' ); ?></p>
					<?php endif; ?>

					<label class="wahou-checkbox">
						<input type="checkbox" name="marketing" value="1">
						<span><?php esc_html_e( 'Send me news and exclusive offers by email (optional).', 'wahou-commerce' ); ?></span>
					</label>

					<?php if ( ! $needs_shipping ) : ?>
						<div class="wahou-checkout__fields wahou-checkout__fields--details">
							<?php
							// Names first, then the country (needed as VAT evidence for digital sales).
							$details = array_intersect_key( $basic_billing, array_flip( array( 'first_name', 'last_name' ) ) ) + $basic_billing;
							foreach ( $details as $name => $field ) {
								CheckoutPage::field( 'billing', $name, $field, (string) ( $bill[ $name ] ?? '' ), $billing_countries, in_array( $name, array( 'country', 'first_name', 'last_name' ), true ) ? $sync : array() );
							}
							?>
						</div>
					<?php endif; ?>
				</section>

				<?php /* ---------------------------------------------------- Delivery */ ?>
				<?php if ( $needs_shipping ) : ?>
					<section class="wahou-checkout__section" aria-labelledby="wahou-step-delivery">
						<h2 class="wahou-checkout__title" id="wahou-step-delivery">
							<span class="wahou-checkout__step" aria-hidden="true"><?php echo esc_html( (string) ++$step ); ?></span>
							<?php esc_html_e( 'Delivery', 'wahou-commerce' ); ?>
						</h2>
						<div class="wahou-checkout__fields">
							<?php
							foreach ( $shipping_fields as $name => $field ) {
								$extra = in_array( $name, array( 'country', 'postcode', 'city', 'first_name', 'last_name' ), true ) ? $sync : array();
								CheckoutPage::field( 'shipping', $name, $field, (string) ( $ship[ $name ] ?? '' ), $countries, $extra );
							}
							?>
						</div>
						<fieldset class="wahou-checkout__shipping" data-wahou-shipping-methods>
							<legend class="wahou-checkout__subtitle"><?php esc_html_e( 'Delivery method', 'wahou-commerce' ); ?></legend>
							<div class="wahou-options" data-wahou-shipping-options>
								<?php echo CheckoutPage::shipping_options_html( $totals ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper. ?>
							</div>
						</fieldset>
					</section>
				<?php endif; ?>

				<?php /* ---------------------------------------------------- Payment */ ?>
				<section class="wahou-checkout__section wahou-checkout__section--payment" aria-labelledby="wahou-step-payment">
					<h2 class="wahou-checkout__title" id="wahou-step-payment">
						<span class="wahou-checkout__step" aria-hidden="true"><?php echo esc_html( (string) ++$step ); ?></span>
						<?php esc_html_e( 'Payment', 'wahou-commerce' ); ?>
					</h2>

					<?php if ( $needs_shipping ) : ?>
						<label class="wahou-checkbox">
							<input type="checkbox" name="billing_same" value="1" data-wahou-toggle="billing" checked>
							<span><?php esc_html_e( 'Billing address same as delivery address', 'wahou-commerce' ); ?></span>
						</label>
						<div class="wahou-checkout__fields wahou-checkout__billing" data-wahou-billing-fields hidden>
							<?php
							foreach ( $billing_fields as $name => $field ) {
								CheckoutPage::field( 'billing', $name, $field, (string) ( $bill[ $name ] ?? '' ), $billing_countries );
							}
							?>
						</div>
					<?php endif; ?>

					<label class="wahou-checkbox">
						<input type="checkbox" name="is_business" value="1" data-wahou-toggle="business"<?php checked( $business ); ?>>
						<span><?php esc_html_e( 'I am buying for a business (VAT invoice)', 'wahou-commerce' ); ?></span>
					</label>
					<div class="wahou-checkout__fields wahou-checkout__business" data-wahou-business-fields<?php echo $business ? '' : ' hidden'; ?>>
						<?php
						if ( 'hidden' !== $company_field ) {
							CheckoutPage::field(
								'billing',
								'company',
								array(
									'label'        => __( 'Company', 'wahou-commerce' ),
									'business'     => true,
									'autocomplete' => 'billing organization',
								),
								(string) ( $bill['company'] ?? '' )
							);
						}
						if ( $show_vat_field ) {
							CheckoutPage::field(
								'billing',
								'vat_number',
								array(
									'label'        => __( 'EU VAT number', 'wahou-commerce' ),
									'business'     => true,
									'autocomplete' => 'off',
									'placeholder'  => __( 'e.g. FR12345678901', 'wahou-commerce' ),
								),
								(string) ( $bill['vat_number'] ?? '' ),
								array(),
								array(
									'data-wahou-vat' => '1',
									'autocapitalize' => 'characters',
									'spellcheck'     => 'false',
								)
							);
							echo '<p class="wahou-vat-status" data-wahou-vat-status aria-live="polite"></p>';
						}
						if ( ! $needs_shipping ) {
							foreach ( $address_only as $name => $field ) {
								$field['business'] = false;
								CheckoutPage::field( 'billing', $name, $field, (string) ( $bill[ $name ] ?? '' ), $billing_countries, in_array( $name, array( 'postcode', 'city' ), true ) ? $sync : array() );
							}
						}
						?>
					</div>

					<div class="wahou-checkout__payment" data-wahou-payment-section>
						<p class="wahou-checkout__secure">
							<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
							<?php esc_html_e( 'All transactions are secure and encrypted.', 'wahou-commerce' ); ?>
						</p>
						<?php if ( $gateways ) : ?>
							<div class="wahou-gateways<?php echo 1 === count( $gateways ) ? ' wahou-gateways--single' : ''; ?>" role="radiogroup" aria-labelledby="wahou-step-payment" data-wahou-gateways>
								<?php foreach ( $gateways as $id => $gateway ) : ?>
									<?php $selected = $id === $first_gateway; ?>
									<div class="wahou-gateway<?php echo $selected ? ' is-selected' : ''; ?>" data-gateway="<?php echo esc_attr( $id ); ?>">
										<label class="wahou-gateway__header">
											<input type="radio" name="payment_method" value="<?php echo esc_attr( $id ); ?>"<?php checked( $selected ); ?>>
											<span class="wahou-gateway__title"><?php echo esc_html( $gateway->title() ); ?></span>
											<span class="wahou-gateway__icons"><?php echo $gateway->icons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup provided by the gateway class. ?></span>
										</label>
										<div class="wahou-gateway__body" data-gateway-body="<?php echo esc_attr( $id ); ?>"<?php echo $selected ? '' : ' hidden'; ?>>
											<?php echo $gateway->payment_fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup provided by the gateway class. ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="wahou-field__error" data-wahou-gateway-error role="alert" hidden></p>
						<?php else : ?>
							<div class="wahou-notice wahou-notice--warning">
								<?php esc_html_e( 'Online payment is not available yet. Please come back soon or contact us.', 'wahou-commerce' ); ?>
								<?php if ( current_user_can( 'manage_wahou' ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wahou-settings&tab=payments' ) ); ?>"><?php esc_html_e( 'Connect Stripe or PayPal', 'wahou-commerce' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
					<p class="wahou-checkout__free-note" data-wahou-free-note hidden><?php esc_html_e( 'No payment needed for this order.', 'wahou-commerce' ); ?></p>
				</section>

				<?php /* ---------------------------------------------------- Order bump */ ?>
				<?php if ( $bump ) : ?>
					<div class="wahou-bump<?php echo $bump['in_cart'] ? ' is-active' : ''; ?>" data-wahou-bump="<?php echo esc_attr( (string) $bump['id'] ); ?>">
						<label class="wahou-bump__label">
							<input type="checkbox" class="wahou-bump__check" data-wahou-bump-toggle<?php checked( $bump['in_cart'] ); ?>>
							<span class="wahou-bump__cta"><?php esc_html_e( 'Yes, add it to my order!', 'wahou-commerce' ); ?></span>
						</label>
						<div class="wahou-bump__content">
							<?php if ( $bump['image_id'] ) : ?>
								<span class="wahou-bump__image"><?php echo wp_get_attachment_image( (int) $bump['image_id'], 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '' ) ); ?></span>
							<?php endif; ?>
							<div class="wahou-bump__body">
								<p class="wahou-bump__headline"><?php echo esc_html( (string) $bump['headline'] ); ?></p>
								<?php if ( '' !== trim( wp_strip_all_tags( (string) $bump['text'] ) ) ) : ?>
									<div class="wahou-bump__text"><?php echo wp_kses_post( wpautop( (string) $bump['text'] ) ); ?></div>
								<?php endif; ?>
								<p class="wahou-bump__price">
									<?php if ( $bump['regular'] > $bump['price'] ) : ?>
										<del><?php echo esc_html( Money::format( (int) $bump['regular'], (string) $bump['currency'] ) ); ?></del>
									<?php endif; ?>
									<strong><?php echo esc_html( Money::format( (int) $bump['price'], (string) $bump['currency'] ) ); ?></strong>
									<?php if ( $bump['percent'] > 0 ) : ?>
										<span class="wahou-bump__save">
											<?php
											/* translators: %d: discount percentage. */
											echo esc_html( sprintf( __( '-%d%%', 'wahou-commerce' ), (int) $bump['percent'] ) );
											?>
										</span>
									<?php endif; ?>
								</p>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $order_notes ) : ?>
					<details class="wahou-checkout__note">
						<summary><?php esc_html_e( 'Add a note to your order', 'wahou-commerce' ); ?></summary>
						<label class="wahou-visually-hidden" for="wahou-customer-note"><?php esc_html_e( 'Order note', 'wahou-commerce' ); ?></label>
						<textarea id="wahou-customer-note" name="customer_note" class="wahou-input wahou-textarea" rows="3" maxlength="1000" placeholder="<?php esc_attr_e( 'Delivery instructions, gift message…', 'wahou-commerce' ); ?>"></textarea>
					</details>
				<?php endif; ?>

				<?php /* ---------------------------------------------------- Consents & pay */ ?>
				<div class="wahou-checkout__consents" data-wahou-consents>
					<?php if ( '' !== $terms_url ) : ?>
						<div class="wahou-field wahou-field--checkbox">
							<label class="wahou-checkbox">
								<input type="checkbox" id="wahou-terms" name="terms" value="1" required aria-describedby="wahou-terms-error" data-wahou-consent>
								<span>
									<?php
									echo wp_kses(
										sprintf(
											/* translators: 1: opening link tag, 2: closing link tag. */
											__( 'I have read and accept the %1$sterms and conditions of sale%2$s.', 'wahou-commerce' ),
											'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">',
											'</a>'
										),
										array(
											'a' => array(
												'href'   => true,
												'target' => true,
												'rel'    => true,
											),
										)
									);
									?>
								</span>
							</label>
							<p class="wahou-field__error" id="wahou-terms-error" hidden></p>
						</div>
					<?php endif; ?>
					<?php if ( $digital_waiver ) : ?>
						<div class="wahou-field wahou-field--checkbox">
							<label class="wahou-checkbox">
								<input type="checkbox" id="wahou-digital-waiver" name="digital_waiver" value="1" required aria-describedby="wahou-digital-waiver-error" data-wahou-consent>
								<span><?php esc_html_e( 'I expressly request that supply of the digital content start immediately, before the 14-day withdrawal period ends, and I acknowledge that I thereby lose my right of withdrawal.', 'wahou-commerce' ); ?></span>
							</label>
							<p class="wahou-field__error" id="wahou-digital-waiver-error" hidden></p>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $honeypot ) : ?>
					<div class="wahou-hp" aria-hidden="true">
						<label for="wahou-hp"><?php esc_html_e( 'Leave this field empty', 'wahou-commerce' ); ?></label>
						<input type="text" id="wahou-hp" name="wahou_hp" value="" tabindex="-1" autocomplete="off">
					</div>
				<?php endif; ?>

				<div class="wahou-checkout__pay" data-wahou-pay-area>
					<div class="wahou-notice wahou-notice--error wahou-checkout__error" role="alert" data-wahou-checkout-error hidden></div>
					<button type="submit" class="wahou-btn wahou-btn--primary wahou-btn--block wahou-pay-button" data-wahou-pay>
						<span class="wahou-pay-button__label" data-wahou-pay-label><?php echo esc_html( $pay_label ); ?></span>
						<span class="wahou-co-spinner" aria-hidden="true"></span>
					</button>
					<p class="wahou-checkout__trust">
						<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
						<?php esc_html_e( 'Secure payment · SSL encryption · Your card details are never stored on our servers', 'wahou-commerce' ); ?>
					</p>
					<?php if ( '' !== $privacy_url ) : ?>
						<p class="wahou-checkout__fineprint">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: opening link tag, 2: closing link tag. */
									__( 'Your personal data is used to process your order and for the purposes described in our %1$sprivacy policy%2$s.', 'wahou-commerce' ),
									'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">',
									'</a>'
								),
								array(
									'a' => array(
										'href'   => true,
										'target' => true,
										'rel'    => true,
									),
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</form>
</div>
