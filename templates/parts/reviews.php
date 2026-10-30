<?php
/**
 * Reviews: rating summary, list and form.
 *
 * @var \WahouCommerce\Products\Product $product
 *
 * @package WahouCommerce
 */

use WahouCommerce\Frontend\Render;
use WahouCommerce\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

$wahou_count   = $product->rating_count();
$wahou_reviews = Reviews::latest( $product->id() );
?>
<div class="wahou-reviews">
	<?php if ( $wahou_count > 0 ) : ?>
		<?php $wahou_distribution = Reviews::distribution( $product->id() ); ?>
		<div class="wahou-reviews__summary">
			<div class="wahou-reviews__score">
				<span class="wahou-reviews__average"><?php echo esc_html( number_format_i18n( $product->rating_average(), 1 ) ); ?></span>
				<?php echo Render::stars( $product->rating_average(), $wahou_count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<ul class="wahou-reviews__bars">
				<?php foreach ( $wahou_distribution as $wahou_stars => $wahou_n ) : ?>
					<?php $wahou_pct = $wahou_count ? round( $wahou_n * 100 / $wahou_count ) : 0; ?>
					<li>
						<span class="wahou-reviews__bar-label">
							<?php
							/* translators: %d: number of stars. */
							echo esc_html( sprintf( _n( '%d star', '%d stars', $wahou_stars, 'wahou-commerce' ), $wahou_stars ) );
							?>
						</span>
						<span class="wahou-reviews__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) $wahou_pct ); ?>%"></span></span>
						<span class="wahou-reviews__bar-count"><?php echo esc_html( number_format_i18n( $wahou_n ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php else : ?>
		<p class="wahou-reviews__empty"><?php esc_html_e( 'No reviews yet. Be the first to share your opinion!', 'wahou-commerce' ); ?></p>
	<?php endif; ?>

	<?php if ( $wahou_reviews ) : ?>
		<ol class="wahou-reviews__list">
			<?php foreach ( $wahou_reviews as $wahou_review ) : ?>
				<?php
				$wahou_rating = Reviews::rating( $wahou_review );
				$wahou_reply  = Reviews::reply( $wahou_review );
				?>
				<li class="wahou-review" id="comment-<?php echo (int) $wahou_review->comment_ID; ?>">
					<div class="wahou-review__head">
						<strong class="wahou-review__author"><?php echo esc_html( get_comment_author( $wahou_review ) ); ?></strong>
						<?php if ( Reviews::is_verified( $wahou_review ) ) : ?>
							<span class="wahou-badge wahou-badge--success"><?php echo Render::icon( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Verified buyer', 'wahou-commerce' ); ?></span>
						<?php endif; ?>
						<time class="wahou-review__date wahou-muted" datetime="<?php echo esc_attr( get_comment_date( 'c', $wahou_review ) ); ?>"><?php echo esc_html( get_comment_date( '', $wahou_review ) ); ?></time>
					</div>
					<?php if ( $wahou_rating ) : ?>
						<?php echo Render::stars( (float) $wahou_rating, 0, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php if ( '0' === (string) $wahou_review->comment_approved ) : ?>
						<p class="wahou-notice wahou-notice--info"><?php esc_html_e( 'Thank you! Your review is awaiting moderation.', 'wahou-commerce' ); ?></p>
					<?php endif; ?>
					<div class="wahou-review__text wahou-prose"><?php comment_text( $wahou_review ); ?></div>
					<?php if ( $wahou_reply ) : ?>
						<div class="wahou-review__reply">
							<strong><?php esc_html_e( 'Reply from the shop', 'wahou-commerce' ); ?></strong>
							<div class="wahou-prose"><?php comment_text( $wahou_reply ); ?></div>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php echo Reviews::form( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core comment form. ?>
</div>
