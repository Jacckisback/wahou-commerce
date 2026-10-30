<?php
/**
 * Saved addresses (prefilled at checkout).
 *
 * Override: copy to {your-theme}/wahou-commerce/account/addresses.php
 *
 * @var array<string, string> $billing
 * @var array<string, string> $shipping
 * @var array<string, string> $countries
 * @var array<string, string> $shipping_countries
 * @var string                $action
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\CheckoutPage;

defined( 'ABSPATH' ) || exit;

$groups = array(
	'shipping' => array(
		'title'     => __( 'Delivery address', 'wahou-commerce' ),
		'fields'    => CheckoutPage::ordered_fields( 'shipping', true ),
		'values'    => $shipping,
		'countries' => $shipping_countries,
	),
	'billing'  => array(
		'title'     => __( 'Billing details', 'wahou-commerce' ),
		'fields'    => CheckoutPage::ordered_fields( 'billing', true ) + array(
			'vat_number' => array(
				'label'        => __( 'EU VAT number', 'wahou-commerce' ),
				'business'     => true,
				'autocomplete' => 'off',
			),
		),
		'values'    => $billing,
		'countries' => $countries,
	),
);
?>
<form method="post" action="<?php echo esc_url( add_query_arg( 'view', 'addresses', $action ) ); ?>" class="wahou-addresses">
	<?php wp_nonce_field( 'wahou_account_addresses', '_wahou_nonce' ); ?>
	<input type="hidden" name="wahou_action" value="save_addresses">
	<div class="wahou-addresses__grid">
		<?php foreach ( $groups as $group => $config ) : ?>
			<fieldset class="wahou-addresses__group">
				<legend class="wahou-checkout__subtitle"><?php echo esc_html( $config['title'] ); ?></legend>
				<div class="wahou-checkout__fields">
					<?php
					foreach ( $config['fields'] as $name => $field ) {
						$field['required'] = false;
						$field['business'] = true; // Everything is optional here.
						CheckoutPage::field( $group, $name, $field, (string) ( $config['values'][ $name ] ?? '' ), $config['countries'] ? $config['countries'] : $countries );
					}
					?>
				</div>
			</fieldset>
		<?php endforeach; ?>
	</div>
	<button type="submit" class="wahou-btn wahou-btn--primary"><?php esc_html_e( 'Save addresses', 'wahou-commerce' ); ?></button>
</form>
