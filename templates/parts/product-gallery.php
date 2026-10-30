<?php
/**
 * Product gallery: swipeable on mobile (CSS scroll snap), thumbnails on
 * desktop, zoom in a lightbox. The first image is the LCP element: eager
 * loading + high fetch priority; the others load lazily.
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;

defined( 'ABSPATH' ) || exit;

$wahou_images = $product->all_image_ids();
$wahou_count  = count( $wahou_images );
$wahou_name   = $product->name();
?>
<div class="wahou-gallery<?php echo $wahou_count > 1 ? ' wahou-gallery--multi' : ''; ?>" data-wahou-gallery>
	<?php echo Render::badges( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
	<?php if ( ! $wahou_count ) : ?>
		<div class="wahou-gallery__viewport"><?php echo Render::placeholder( 'wahou-gallery__placeholder' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<?php else : ?>
		<div class="wahou-gallery__viewport">
			<ul class="wahou-gallery__track" data-wahou-gallery-track tabindex="0" aria-label="<?php esc_attr_e( 'Product images', 'wahou-commerce' ); ?>">
				<?php foreach ( $wahou_images as $wahou_i => $wahou_image_id ) : ?>
					<?php
					$wahou_full = wp_get_attachment_image_url( $wahou_image_id, 'full' );
					$wahou_attr = array(
						'class' => 'wahou-gallery__img',
						'sizes' => '(min-width: 1200px) 640px, (min-width: 900px) 55vw, 100vw',
					);
					if ( 0 === $wahou_i ) {
						$wahou_attr['loading']       = 'eager';
						$wahou_attr['fetchpriority'] = 'high';
						$wahou_attr['decoding']      = 'auto';
					}
					?>
					<li class="wahou-gallery__slide" data-index="<?php echo (int) $wahou_i; ?>" data-image-id="<?php echo (int) $wahou_image_id; ?>">
						<a class="wahou-gallery__zoom" href="<?php echo esc_url( $wahou_full ? $wahou_full : '#' ); ?>" data-wahou-zoom>
							<?php echo Render::image( (int) $wahou_image_id, 'large', $wahou_attr, $wahou_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="screen-reader-text">
								<?php
								/* translators: 1: image position, 2: number of images. */
								echo esc_html( sprintf( __( 'Enlarge image %1$d of %2$d', 'wahou-commerce' ), $wahou_i + 1, $wahou_count ) );
								?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $wahou_count > 1 ) : ?>
				<button type="button" class="wahou-gallery__nav wahou-gallery__nav--prev" data-wahou-gallery-prev aria-label="<?php esc_attr_e( 'Previous image', 'wahou-commerce' ); ?>"><?php echo Render::icon( 'left', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="wahou-gallery__nav wahou-gallery__nav--next" data-wahou-gallery-next aria-label="<?php esc_attr_e( 'Next image', 'wahou-commerce' ); ?>"><?php echo Render::icon( 'chevron', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<?php endif; ?>
		</div>
		<?php if ( $wahou_count > 1 ) : ?>
			<div class="wahou-gallery__dots" aria-hidden="true">
				<?php foreach ( $wahou_images as $wahou_i => $wahou_image_id ) : ?>
					<span class="wahou-gallery__dot<?php echo 0 === $wahou_i ? ' is-active' : ''; ?>" data-index="<?php echo (int) $wahou_i; ?>"></span>
				<?php endforeach; ?>
			</div>
			<div class="wahou-gallery__thumbs">
				<?php foreach ( $wahou_images as $wahou_i => $wahou_image_id ) : ?>
					<button type="button" class="wahou-gallery__thumb<?php echo 0 === $wahou_i ? ' is-active' : ''; ?>" data-index="<?php echo (int) $wahou_i; ?>" aria-current="<?php echo 0 === $wahou_i ? 'true' : 'false'; ?>">
						<?php echo Render::image( (int) $wahou_image_id, 'thumbnail', array( 'alt' => '', 'sizes' => '80px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="screen-reader-text">
							<?php
							/* translators: 1: image position, 2: number of images. */
							echo esc_html( sprintf( __( 'Show image %1$d of %2$d', 'wahou-commerce' ), $wahou_i + 1, $wahou_count ) );
							?>
						</span>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
