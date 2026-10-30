<?php
/**
 * Customer account layout (navigation + current view).
 *
 * Override: copy to {your-theme}/wahou-commerce/account/dashboard.php
 *
 * @var \WP_User                             $user
 * @var string                               $view     orders|order|downloads|addresses
 * @var string                               $content  Rendered view (already escaped).
 * @var array{type:string, text:string}|null $message
 * @var string                               $base     Account page URL.
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;

$tabs    = array(
	'orders'    => __( 'Orders', 'wahou-commerce' ),
	'downloads' => __( 'Downloads', 'wahou-commerce' ),
	'addresses' => __( 'Addresses', 'wahou-commerce' ),
);
$current = 'order' === $view ? 'orders' : $view;
$name    = '' !== trim( (string) $user->first_name ) ? (string) $user->first_name : (string) $user->display_name;
?>
<div class="wahou wahou-account" data-wahou-account>
	<header class="wahou-account__header">
		<h2 class="wahou-account__title">
			<?php
			/* translators: %s: customer first name. */
			echo esc_html( sprintf( __( 'Hello %s', 'wahou-commerce' ), $name ) );
			?>
		</h2>
		<a class="wahou-account__logout" href="<?php echo esc_url( wp_logout_url( $base ) ); ?>"><?php esc_html_e( 'Log out', 'wahou-commerce' ); ?></a>
	</header>

	<nav class="wahou-account__nav" aria-label="<?php esc_attr_e( 'Account', 'wahou-commerce' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'view', $key, $base ) ); ?>"<?php echo $current === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( $message ) : ?>
		<div class="wahou-notice wahou-notice--<?php echo esc_attr( $message['type'] ); ?>" role="status"><?php echo esc_html( $message['text'] ); ?></div>
	<?php endif; ?>

	<div class="wahou-account__content">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by escaped templates. ?>
	</div>
</div>
