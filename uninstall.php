<?php
/**
 * Uninstall Wahou Commerce.
 *
 * Data is removed ONLY when "Delete all data when the plugin is deleted" is
 * enabled in Settings → Advanced. Otherwise products, orders, invoices and
 * settings are kept so that the store can be reinstalled without loss.
 *
 * Runs without the plugin being loaded: no plugin class is used here.
 *
 * @package WahouCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every Wahou Commerce data of the current site.
 */
function wahou_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'wahou_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// Scheduled events.
	foreach ( array( 'wahou_cron_frequent', 'wahou_cron_daily', 'wahou_review_request' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	// Custom tables.
	foreach ( array( 'carts', 'orders', 'order_items', 'order_notes', 'downloads', 'invoices', 'credit_notes' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'wahou_' . $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// Products, coupons, offers, their meta and reviews.
	$types = array( 'wahou_product', 'wahou_coupon', 'wahou_offer' );
	$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in})" ) );
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		$list        = implode( ',', $chunk );
		$comment_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$list})" ) );
		if ( $comment_ids ) {
			$comments = implode( ',', $comment_ids );
			$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$comments})" );
			$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$comments})" );
		}
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$list})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$list})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$list}) OR ( post_type = 'revision' AND post_parent IN ({$list}) )" );
	}

	// Product categories and tags.
	foreach ( array( 'wahou_product_cat', 'wahou_product_tag' ) as $taxonomy ) {
		$terms = (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
		foreach ( $terms as $term ) {
			$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ) );
			$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $term->term_taxonomy_id ) );
			$shared = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $term->term_id ) );
			if ( ! $shared ) {
				$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $term->term_id ) );
				$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term->term_id ) );
			}
		}
	}

	// Options, transients and user meta.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wahou\\_%' OR option_name LIKE '\\_transient\\_wahou\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wahou\\_%' OR option_name LIKE '\\_site\\_transient\\_wahou\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_wahou\\_%' OR option_name = '_wahou_activation_redirect'" );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_wahou\\_%' OR meta_key LIKE 'wahou\\_%'" );
	// phpcs:enable

	// Roles and capabilities (customers keep an account as subscribers).
	$caps = array(
		'edit_wahou_products',
		'edit_others_wahou_products',
		'edit_private_wahou_products',
		'edit_published_wahou_products',
		'publish_wahou_products',
		'read_private_wahou_products',
		'delete_wahou_products',
		'delete_private_wahou_products',
		'delete_published_wahou_products',
		'delete_others_wahou_products',
		'manage_wahou_orders',
		'manage_wahou',
	);
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
	foreach ( array( 'wahou_customer', 'wahou_manager' ) as $role_name ) {
		foreach ( get_users(
			array(
				'role'   => $role_name,
				'fields' => 'ID',
			)
		) as $user_id ) {
			$user = new WP_User( (int) $user_id );
			$user->set_role( 'subscriber' );
		}
		remove_role( $role_name );
	}

	// Private files (digital products) and logs.
	$uploads = wp_upload_dir( null, false );
	foreach ( array( 'wahou-private', 'wahou-logs' ) as $folder ) {
		wahou_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . $folder );
	}

	wp_cache_flush();
}

/**
 * Recursively delete a folder inside the uploads directory.
 */
function wahou_uninstall_rmdir( string $dir ): void {
	$uploads = wp_upload_dir( null, false );
	$base    = realpath( $uploads['basedir'] );
	$real    = realpath( $dir );
	if ( ! $base || ! $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_dir( $real ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $wahou_site_id ) {
		switch_to_blog( (int) $wahou_site_id );
		wahou_uninstall_site();
		restore_current_blog();
	}
} else {
	wahou_uninstall_site();
}
