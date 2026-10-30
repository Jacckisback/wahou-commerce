<?php
/**
 * Product reviews: native WordPress comments (type "review") with a required
 * 1–5 star rating, "verified buyer" badge and cached aggregate rating
 * (_wahou_rating_avg / _wahou_rating_count) for cards and schema.org.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Reviews;

use WahouCommerce\Frontend\Render;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class Reviews {

	private static int $pending_rating = 0;

	public static function init(): void {
		add_filter( 'comments_open', array( self::class, 'comments_open' ), 20, 2 );
		add_filter( 'pings_open', array( self::class, 'pings_open' ), 20, 2 );
		add_filter( 'preprocess_comment', array( self::class, 'preprocess' ), 5 );
		add_action( 'comment_post', array( self::class, 'after_post' ), 10, 3 );
		add_action( 'transition_comment_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'edit_comment', array( self::class, 'on_edit' ) );
		add_action( 'deleted_comment', array( self::class, 'on_deleted' ), 10, 2 );
		add_filter( 'get_avatar_comment_types', array( self::class, 'avatar_types' ) );
		add_filter( 'comment_post_redirect', array( self::class, 'redirect' ), 10, 2 );
	}

	public static function enabled(): bool {
		return Settings::bool( 'reviews_enabled' );
	}

	public static function comments_open( bool $open, int $post_id ): bool {
		if ( PostTypes::PRODUCT === get_post_type( $post_id ) ) {
			return self::enabled() && 'publish' === get_post_status( $post_id );
		}
		return $open;
	}

	public static function pings_open( bool $open, int $post_id ): bool {
		return PostTypes::PRODUCT === get_post_type( $post_id ) ? false : $open;
	}

	/**
	 * @param string[] $types
	 * @return string[]
	 */
	public static function avatar_types( array $types ): array {
		$types[] = 'review';
		return array_unique( $types );
	}

	/**
	 * Validate the rating before WordPress stores the comment.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function preprocess( array $data ): array {
		$post_id = (int) ( $data['comment_post_ID'] ?? 0 );
		if ( PostTypes::PRODUCT !== get_post_type( $post_id ) ) {
			return $data;
		}
		// Merchant replies (admin screen or nested replies) are regular comments.
		if ( is_admin() || ! empty( $data['comment_parent'] ) ) {
			return $data;
		}
		$data['comment_type'] = 'review';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core comment form (wp-comments-post.php).
		$rating = isset( $_POST['wahou_rating'] ) ? absint( wp_unslash( $_POST['wahou_rating'] ) ) : 0;
		if ( $rating < 1 || $rating > 5 ) {
			wp_die(
				esc_html__( 'Please choose a rating between 1 and 5 stars.', 'wahou-commerce' ),
				esc_html__( 'Rating required', 'wahou-commerce' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		if ( Settings::bool( 'reviews_verified_only' ) ) {
			$email = self::author_email( $data );
			if ( ! Render::is_verified_buyer( $email, $post_id ) ) {
				wp_die(
					esc_html__( 'Only customers who bought this product can leave a review. Please use the email address of your order.', 'wahou-commerce' ),
					esc_html__( 'Verified buyers only', 'wahou-commerce' ),
					array(
						'response'  => 403,
						'back_link' => true,
					)
				);
			}
		}
		self::$pending_rating = $rating;
		return $data;
	}

	/**
	 * @param int|string           $approved
	 * @param array<string, mixed> $data
	 */
	public static function after_post( int $comment_id, $approved, array $data = array() ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'review' !== $comment->comment_type ) {
			return;
		}
		if ( self::$pending_rating ) {
			update_comment_meta( $comment_id, 'rating', self::$pending_rating );
			self::$pending_rating = 0;
		}
		if ( Render::is_verified_buyer( self::author_email( (array) $comment ), (int) $comment->comment_post_ID ) ) {
			update_comment_meta( $comment_id, 'verified', 1 );
		}
		self::recount( (int) $comment->comment_post_ID );
	}

	/**
	 * @param string      $new_status
	 * @param string      $old_status
	 * @param \WP_Comment $comment
	 */
	public static function on_transition( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof \WP_Comment && PostTypes::PRODUCT === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::recount( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * @param int|string $comment_id
	 */
	public static function on_edit( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( $comment && PostTypes::PRODUCT === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::recount( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * @param int|string       $comment_id
	 * @param \WP_Comment|null $comment
	 */
	public static function on_deleted( $comment_id, $comment = null ): void {
		if ( $comment instanceof \WP_Comment && PostTypes::PRODUCT === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::recount( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Recompute and cache the average rating of a product.
	 */
	public static function recount( int $product_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, AVG(m.meta_value) AS average FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating' WHERE c.comment_post_ID = %d AND c.comment_approved = '1'",
				$product_id
			),
			ARRAY_A
		);
		update_post_meta( $product_id, '_wahou_rating_avg', round( (float) ( $row['average'] ?? 0 ), 2 ) );
		update_post_meta( $product_id, '_wahou_rating_count', (int) ( $row['n'] ?? 0 ) );
		Product::flush( $product_id );
		do_action( 'wahou_rating_updated', $product_id );
	}

	/**
	 * Number of approved reviews per star (5 => n, 4 => n...).
	 *
	 * @return array<int, int>
	 */
	public static function distribution( int $product_id ): array {
		global $wpdb;
		$out = array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS rating, COUNT(*) AS n FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating' WHERE c.comment_post_ID = %d AND c.comment_approved = '1' GROUP BY m.meta_value",
				$product_id
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$rating = (int) $row['rating'];
			if ( isset( $out[ $rating ] ) ) {
				$out[ $rating ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Latest reviews (approved, plus the visitor's own pending review).
	 *
	 * @return \WP_Comment[]
	 */
	public static function latest( int $product_id, int $number = 20 ): array {
		$args = array(
			'post_id'  => $product_id,
			'status'   => 'approve',
			'type__in' => array( 'review', 'comment' ),
			'parent'   => 0,
			'number'   => $number,
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
		);
		$email = function_exists( 'wp_get_unapproved_comment_author_email' ) ? wp_get_unapproved_comment_author_email() : '';
		if ( '' !== $email ) {
			$args['include_unapproved'] = array( $email );
		}
		$comments = get_comments( $args );
		return is_array( $comments ) ? $comments : array();
	}

	/**
	 * First merchant reply to a review, if any.
	 */
	public static function reply( \WP_Comment $review ): ?\WP_Comment {
		$replies = get_comments(
			array(
				'parent' => (int) $review->comment_ID,
				'status' => 'approve',
				'number' => 1,
				'order'  => 'ASC',
			)
		);
		return is_array( $replies ) && $replies ? $replies[0] : null;
	}

	public static function rating( \WP_Comment $comment ): int {
		return max( 0, min( 5, (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true ) ) );
	}

	public static function is_verified( \WP_Comment $comment ): bool {
		return (bool) get_comment_meta( (int) $comment->comment_ID, 'verified', true );
	}

	/**
	 * Review form markup (core comment form with the rating field).
	 */
	public static function form( Product $product ): string {
		if ( ! comments_open( $product->id() ) ) {
			return '';
		}
		$stars = '';
		for ( $i = 5; $i >= 1; $i-- ) {
			$id = 'wahou-rating-' . $product->id() . '-' . $i;
			/* translators: %d: number of stars. */
			$label  = sprintf( _n( '%d star', '%d stars', $i, 'wahou-commerce' ), $i );
			$stars .= '<input type="radio" id="' . esc_attr( $id ) . '" name="wahou_rating" value="' . $i . '"' . ( 5 === $i ? ' required' : '' ) . '>'
				. '<label for="' . esc_attr( $id ) . '" title="' . esc_attr( $label ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span></label>';
		}
		$rating_field = '<fieldset class="wahou-rating-input"><legend>' . esc_html__( 'Your rating', 'wahou-commerce' ) . ' <span class="required" aria-hidden="true">*</span></legend><div class="wahou-rating-input__stars">' . $stars . '</div></fieldset>';
		$text_field   = '<p class="comment-form-comment wahou-field"><label for="comment">' . esc_html__( 'Your review', 'wahou-commerce' ) . ' <span class="required" aria-hidden="true">*</span></label><textarea id="comment" name="comment" class="wahou-input" rows="5" maxlength="65525" required></textarea></p>';

		ob_start();
		comment_form(
			array(
				'title_reply'          => __( 'Write a review', 'wahou-commerce' ),
				/* translators: %s: author of the review being replied to. */
				'title_reply_to'       => __( 'Reply to %s', 'wahou-commerce' ),
				'title_reply_before'   => '<h3 id="reply-title" class="wahou-review-form__title">',
				'title_reply_after'    => '</h3>',
				'label_submit'         => __( 'Publish my review', 'wahou-commerce' ),
				'class_form'           => 'wahou-review-form comment-form',
				'class_submit'         => 'wahou-btn wahou-btn--primary',
				'submit_button'        => '<button name="%1$s" type="submit" id="%2$s" class="%3$s">%4$s</button>',
				'comment_field'        => $rating_field . $text_field,
				'comment_notes_before' => '<p class="wahou-muted wahou-review-form__note">' . esc_html__( 'Your email address will not be published. It lets us show a "verified buyer" badge if you ordered this product.', 'wahou-commerce' ) . '</p>',
			),
			$product->id()
		);
		return (string) ob_get_clean();
	}

	public static function redirect( string $location, \WP_Comment $comment ): string {
		if ( PostTypes::PRODUCT === get_post_type( (int) $comment->comment_post_ID ) && false === strpos( $location, '#' ) ) {
			$location .= '#wahou-reviews';
		}
		return $location;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function author_email( array $data ): string {
		if ( ! empty( $data['user_id'] ) ) {
			$user = get_userdata( (int) $data['user_id'] );
			if ( $user ) {
				return (string) $user->user_email;
			}
		}
		if ( is_user_logged_in() && empty( $data['comment_author_email'] ) ) {
			return (string) wp_get_current_user()->user_email;
		}
		return (string) ( $data['comment_author_email'] ?? '' );
	}
}
