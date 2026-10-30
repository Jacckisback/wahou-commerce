<?php
/**
 * Review request after delivery.
 *
 * @var \WahouCommerce\Orders\Order                               $order
 * @var array<int, array{name:string, url:string, image:string}> $products
 * @var string                                                    $unsubscribe_url
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;

defined( 'ABSPATH' ) || exit;

$first_name = $order->first_name();
?>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>">
	<?php
	echo esc_html(
		'' !== $first_name
			/* translators: %s: customer first name. */
			? sprintf( __( 'Hi %s,', 'wahou-commerce' ), $first_name )
			: __( 'Hello,', 'wahou-commerce' )
	);
	?>
</p>
<p style="<?php echo esc_attr( Mailer::style( 'p' ) ); ?>"><?php esc_html_e( 'We hope you are enjoying your purchase. Would you take one minute to share your opinion? Your honest review helps other customers choose and helps us improve.', 'wahou-commerce' ); ?></p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="<?php echo esc_attr( Mailer::style( 'table' ) ); ?>">
	<?php foreach ( $products as $product ) : ?>
		<tr>
			<?php if ( ! empty( $product['image'] ) ) : ?>
				<td width="72" style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>width:72px;padding-right:12px;">
					<img src="<?php echo esc_url( $product['image'] ); ?>" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border-radius:10px;object-fit:cover;border:0;">
				</td>
			<?php endif; ?>
			<td style="<?php echo esc_attr( Mailer::style( 'td' ) ); ?>vertical-align:middle;" <?php echo empty( $product['image'] ) ? 'colspan="2"' : ''; ?>>
				<strong><?php echo esc_html( $product['name'] ); ?></strong><br>
				<a href="<?php echo esc_url( $product['url'] ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>font-size:22px;text-decoration:none;letter-spacing:3px;" aria-label="<?php esc_attr_e( 'Write a review', 'wahou-commerce' ); ?>">★★★★★</a><br>
				<a href="<?php echo esc_url( $product['url'] ); ?>" style="<?php echo esc_attr( Mailer::style( 'link' ) ); ?>"><?php esc_html_e( 'Write a review', 'wahou-commerce' ); ?></a>
			</td>
		</tr>
	<?php endforeach; ?>
</table>
