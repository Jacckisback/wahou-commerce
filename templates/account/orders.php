<?php
/**
 * Customer orders list.
 *
 * Override: copy to {your-theme}/wahou-commerce/account/orders.php
 *
 * @var \WahouCommerce\Orders\Order[] $orders
 * @var string                        $base   Account page URL.
 *
 * @package WahouCommerce
 */

use WahouCommerce\Customers\Account;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;

defined( 'ABSPATH' ) || exit;

if ( ! $orders ) :
	$shop_url = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );
	?>
	<div class="wahou-empty wahou-empty--compact">
		<p class="wahou-empty__text"><?php esc_html_e( 'You have not placed any order yet.', 'wahou-commerce' ); ?></p>
		<a class="wahou-btn wahou-btn--primary" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Discover the shop', 'wahou-commerce' ); ?></a>
	</div>
	<?php
	return;
endif;
?>
<ul class="wahou-orders" role="list">
	<?php foreach ( $orders as $order ) : ?>
		<li>
			<a class="wahou-order-row" href="<?php echo esc_url( Account::order_url( $order ) ); ?>">
				<span class="wahou-order-row__main">
					<strong>
						<?php
						/* translators: %s: order number. */
						echo esc_html( sprintf( __( 'Order #%s', 'wahou-commerce' ), $order->number() ) );
						?>
					</strong>
					<span class="wahou-order-row__meta">
						<?php echo esc_html( $order->date() ); ?>
						·
						<?php
						/* translators: %s: number of items. */
						echo esc_html( sprintf( _n( '%s item', '%s items', $order->item_count(), 'wahou-commerce' ), number_format_i18n( $order->item_count() ) ) );
						?>
					</span>
				</span>
				<span class="wahou-order-row__side">
					<span class="wahou-status wahou-status--<?php echo esc_attr( $order->status() ); ?>"><?php echo esc_html( $order->status_label() ); ?></span>
					<span class="wahou-order-row__total"><?php echo esc_html( Money::format( $order->total(), $order->currency() ) ); ?></span>
				</span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
