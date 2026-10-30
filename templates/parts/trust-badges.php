<?php
/**
 * Reassurance badges (only factual promises derived from the settings).
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

if ( ! Settings::bool( 'trust_badges' ) ) {
	return;
}

$wahou_badges = array(
	array(
		'icon'  => 'lock',
		'title' => __( 'Secure payment', 'wahou-commerce' ),
		'text'  => __( 'Encrypted checkout, your card details never touch our servers.', 'wahou-commerce' ),
	),
);

if ( $product->needs_shipping() ) {
	$wahou_threshold = Shipping::free_threshold( Settings::store_country() );
	$wahou_badges[]  = array(
		'icon'  => 'truck',
		'title' => null !== $wahou_threshold
			/* translators: %s: free shipping threshold. */
			? sprintf( __( 'Free delivery from %s', 'wahou-commerce' ), Money::format( $wahou_threshold ) )
			: __( 'Tracked delivery', 'wahou-commerce' ),
		'text'  => __( 'Shipping costs are shown before payment.', 'wahou-commerce' ),
	);
	$wahou_days = max( 0, Settings::int( 'return_days' ) );
	if ( $wahou_days > 0 ) {
		$wahou_badges[] = array(
			'icon'  => 'return',
			/* translators: %d: number of days to return a product. */
			'title' => sprintf( _n( '%d-day return', '%d-day returns', $wahou_days, 'wahou-commerce' ), $wahou_days ),
			'text'  => 'free' === Settings::str( 'return_fees' ) ? __( 'Free returns.', 'wahou-commerce' ) : __( 'Easy returns from your account.', 'wahou-commerce' ),
		);
	}
} else {
	$wahou_badges[] = array(
		'icon'  => 'download',
		'title' => __( 'Instant access', 'wahou-commerce' ),
		'text'  => __( 'Download links by email and in your account.', 'wahou-commerce' ),
	);
	$wahou_badges[] = array(
		'icon'  => 'chat',
		'title' => __( 'Responsive support', 'wahou-commerce' ),
		'text'  => __( 'A question? Reply to your order email.', 'wahou-commerce' ),
	);
}

/**
 * Filters the reassurance badges of the product page.
 */
$wahou_badges = apply_filters( 'wahou_trust_badges', $wahou_badges, $product );
if ( ! $wahou_badges ) {
	return;
}
?>
<ul class="wahou-trust">
	<?php foreach ( $wahou_badges as $wahou_badge ) : ?>
		<li class="wahou-trust__item">
			<?php echo Render::icon( (string) $wahou_badge['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span>
				<strong><?php echo esc_html( (string) $wahou_badge['title'] ); ?></strong>
				<span class="wahou-muted"><?php echo esc_html( (string) $wahou_badge['text'] ); ?></span>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
