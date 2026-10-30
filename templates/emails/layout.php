<?php
/**
 * Email layout (table based, inline styles, 600px, mobile friendly).
 *
 * Override: {theme}/wahou-commerce/emails/layout.php
 *
 * @var string $content         Body HTML (already escaped by the inner template).
 * @var string $subject
 * @var string $heading
 * @var string $preheader
 * @var string $unsubscribe_url
 * @var string $logo
 * @var string $store_name
 * @var string $store_url
 * @var string $footer          Escaped footer HTML.
 * @var string $brand
 * @var string $on_brand
 * @var string $lang
 * @var string $dir
 *
 * @package WahouCommerce
 */

use WahouCommerce\Emails\Mailer;

defined( 'ABSPATH' ) || exit;

$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $subject ); ?></title>
<style>
	@media only screen and (max-width: 620px) {
		.wahou-email-container { width: 100% !important; }
		.wahou-email-pad { padding-left: 20px !important; padding-right: 20px !important; }
		.wahou-email-hide-mobile { display: none !important; }
	}
	a { color: <?php echo esc_attr( $brand ); ?>; }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-text-size-adjust:100%;">
<?php if ( '' !== $preheader ) : ?>
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f3f4f6;">
	<?php echo esc_html( $preheader ); ?>&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
</div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" class="wahou-email-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
				<tr>
					<td style="height:6px;background-color:<?php echo esc_attr( $brand ); ?>;border-radius:12px 12px 0 0;font-size:0;line-height:0;">&nbsp;</td>
				</tr>
				<tr>
					<td class="wahou-email-pad" style="background-color:#ffffff;padding:28px 36px 8px;">
						<a href="<?php echo esc_url( $store_url ); ?>" style="text-decoration:none;">
							<?php if ( '' !== $logo ) : ?>
								<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" style="display:block;max-width:180px;max-height:64px;height:auto;border:0;">
							<?php else : ?>
								<span style="font-family:<?php echo esc_attr( $font ); ?>;font-size:20px;font-weight:800;color:#111111;letter-spacing:-0.2px;"><?php echo esc_html( $store_name ); ?></span>
							<?php endif; ?>
						</a>
					</td>
				</tr>
				<tr>
					<td class="wahou-email-pad" style="background-color:#ffffff;padding:20px 36px 32px;border-radius:0 0 12px 12px;">
						<?php if ( '' !== $heading ) : ?>
							<h1 style="<?php echo esc_attr( Mailer::style( 'h1' ) ); ?>"><?php echo esc_html( $heading ); ?></h1>
						<?php endif; ?>
						<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the inner template. ?>
					</td>
				</tr>
				<tr>
					<td class="wahou-email-pad" style="padding:20px 36px 8px;font-family:<?php echo esc_attr( $font ); ?>;font-size:12px;line-height:1.6;color:#8a9099;text-align:center;">
						<?php echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_html()/wp_kses_post(). ?>
						<?php if ( '' !== $unsubscribe_url ) : ?>
							<br><a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#8a9099;text-decoration:underline;"><?php esc_html_e( 'Unsubscribe from these emails', 'wahou-commerce' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
