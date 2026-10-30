<?php
/**
 * List of downloadable files (thank-you page and account).
 *
 * Override: copy to {your-theme}/wahou-commerce/account/downloads-list.php
 *
 * @var array<int, array<string, mixed>> $downloads From Downloads::for_order() / for_customer().
 *
 * @package WahouCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<ul class="wahou-downloads" role="list">
	<?php foreach ( $downloads as $download ) : ?>
		<?php
		$limits = array();
		if ( null !== $download['remaining'] ) {
			/* translators: %d: remaining downloads. */
			$limits[] = sprintf( _n( '%d download left', '%d downloads left', (int) $download['remaining'], 'wahou-commerce' ), (int) $download['remaining'] );
		}
		if ( '' !== (string) $download['expires_label'] ) {
			/* translators: %s: expiry date. */
			$limits[] = sprintf( __( 'available until %s', 'wahou-commerce' ), $download['expires_label'] );
		}
		?>
		<li class="wahou-download<?php echo $download['available'] ? '' : ' is-unavailable'; ?>">
			<span class="wahou-download__icon" aria-hidden="true">
				<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
			</span>
			<span class="wahou-download__info">
				<strong class="wahou-download__name"><?php echo esc_html( (string) $download['name'] ); ?></strong>
				<?php if ( '' !== (string) $download['product'] && $download['product'] !== $download['name'] ) : ?>
					<span class="wahou-download__product"><?php echo esc_html( (string) $download['product'] ); ?></span>
				<?php endif; ?>
				<?php if ( $limits ) : ?>
					<span class="wahou-download__limits"><?php echo esc_html( implode( ' · ', $limits ) ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( $download['available'] ) : ?>
				<a class="wahou-btn wahou-btn--primary wahou-download__button" href="<?php echo esc_url( (string) $download['url'] ); ?>" rel="nofollow">
					<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12m0 0-5-5m5 5 5-5M5 20h14"/></svg>
					<?php esc_html_e( 'Download', 'wahou-commerce' ); ?>
					<span class="wahou-visually-hidden"><?php echo esc_html( (string) $download['name'] ); ?></span>
				</a>
			<?php else : ?>
				<span class="wahou-download__expired"><?php esc_html_e( 'No longer available', 'wahou-commerce' ); ?></span>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
