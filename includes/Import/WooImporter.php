<?php
/**
 * One-click migration from WooCommerce (Wahou Commerce → Import).
 *
 * Reads WooCommerce data straight from the database, so it also works after
 * WooCommerce has been deactivated. Imports simple and variable products
 * (prices, sale schedule, SKU/GTIN, stock, weight and dimensions converted to
 * kg/cm, images and gallery, categories with their hierarchy, tags, digital
 * files copied to the protected folder, approved reviews, upsells and
 * cross-sells). Grouped and external products are skipped. Running it again
 * never duplicates a product (tracked with _wahou_imported_from).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Import;

use WahouCommerce\Install;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Products\Product;
use WahouCommerce\Support\Crypto;
use WahouCommerce\Support\Logger;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class WooImporter {

	private const BATCH    = 10;
	private const STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/** @var array<string, int> */
	private static array $term_map = array();

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'wp_ajax_wahou_woo_import', array( self::class, 'ajax' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'wahou',
			__( 'Import from WooCommerce', 'wahou-commerce' ),
			__( 'Import', 'wahou-commerce' ),
			'manage_wahou',
			'wahou-import',
			array( self::class, 'render' )
		);
	}

	/* ------------------------------------------------------------------
	 * Screen.
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wahou-commerce' ) );
		}
		$total    = self::count_source();
		$imported = self::count_imported();
		?>
		<div class="wrap wahou-import">
			<h1><?php esc_html_e( 'Import from WooCommerce', 'wahou-commerce' ); ?></h1>
			<?php if ( ! $total ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No WooCommerce product was found in this site\'s database.', 'wahou-commerce' ); ?></p></div>
			<?php else : ?>
				<p style="max-width:720px;font-size:14px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of WooCommerce products, 2: number already imported. */
							__( '%1$d WooCommerce products found, %2$d already imported. The import copies prices and sale schedules, SKU and GTIN, stock, weight and dimensions, images and gallery, categories and tags, digital files, approved reviews, upsells and cross-sells. WooCommerce data is never modified, and products already imported are skipped.', 'wahou-commerce' ),
							$total,
							$imported
						)
					);
					?>
				</p>
				<p><label><input type="checkbox" id="wahou-import-draft"> <?php esc_html_e( 'Import as drafts (review before publishing)', 'wahou-commerce' ); ?></label></p>
				<p>
					<button type="button" class="button button-primary button-hero" id="wahou-import-start" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wahou_woo_import' ) ); ?>">
						<?php
						/* translators: %d: number of products. */
						echo esc_html( sprintf( __( 'Import %d products', 'wahou-commerce' ), $total ) );
						?>
					</button>
				</p>
				<div id="wahou-import-progress" hidden style="max-width:720px;">
					<div style="height:12px;background:#e6e8eb;border-radius:99px;overflow:hidden;"><div id="wahou-import-bar" style="height:100%;width:0;background:#5b3df5;transition:width .3s;"></div></div>
					<p id="wahou-import-status" aria-live="polite"></p>
					<ul id="wahou-import-log" style="max-height:280px;overflow:auto;font-family:monospace;font-size:12px;"></ul>
				</div>
				<script>
				( function () {
					var button = document.getElementById( 'wahou-import-start' );
					if ( ! button ) { return; }
					var labels = <?php echo wp_json_encode( array( 'running' => __( 'Importing… %1$d / %2$d', 'wahou-commerce' ), 'done' => __( 'Done! %1$d imported, %2$d skipped, %3$d errors.', 'wahou-commerce' ), 'error' => __( 'The import stopped because of an error. You can run it again: imported products are skipped.', 'wahou-commerce' ) ) ); ?>;
					var fmt = function ( s, a ) { return s.replace( /%(\d)\$d/g, function ( m, i ) { return a[ i - 1 ]; } ); };
					button.addEventListener( 'click', function () {
						button.disabled = true;
						document.getElementById( 'wahou-import-progress' ).hidden = false;
						var totals = { imported: 0, skipped: 0, errors: 0 };
						var step = function ( offset ) {
							var body = new FormData();
							body.append( 'action', 'wahou_woo_import' );
							body.append( '_ajax_nonce', button.dataset.nonce );
							body.append( 'offset', offset );
							body.append( 'draft', document.getElementById( 'wahou-import-draft' ).checked ? '1' : '' );
							fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
								.then( function ( r ) { return r.json(); } )
								.then( function ( res ) {
									if ( ! res || ! res.success ) { throw new Error( 'failed' ); }
									var d = res.data;
									totals.imported += d.imported; totals.skipped += d.skipped; totals.errors += d.errors;
									var log = document.getElementById( 'wahou-import-log' );
									d.log.forEach( function ( line ) { var li = document.createElement( 'li' ); li.textContent = line; log.appendChild( li ); } );
									log.scrollTop = log.scrollHeight;
									document.getElementById( 'wahou-import-bar' ).style.width = Math.min( 100, Math.round( d.next_offset / Math.max( 1, d.total ) * 100 ) ) + '%';
									if ( d.done ) {
										document.getElementById( 'wahou-import-status' ).textContent = fmt( labels.done, [ totals.imported, totals.skipped, totals.errors ] );
										document.getElementById( 'wahou-import-bar' ).style.width = '100%';
									} else {
										document.getElementById( 'wahou-import-status' ).textContent = fmt( labels.running, [ d.next_offset, d.total ] );
										step( d.next_offset );
									}
								} )
								.catch( function () {
									document.getElementById( 'wahou-import-status' ).textContent = labels.error;
									button.disabled = false;
								} );
						};
						step( 0 );
					} );
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * AJAX batch.
	 * ------------------------------------------------------------------ */

	public static function ajax(): void {
		check_ajax_referer( 'wahou_woo_import' );
		if ( ! current_user_can( 'manage_wahou' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'wahou-commerce' ) ), 403 );
		}
		global $wpdb;
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$draft  = ! empty( $_POST['draft'] );

		$in = "'" . implode( "','", self::STATUSES ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ({$in}) ORDER BY ID ASC LIMIT %d OFFSET %d", self::BATCH, $offset ) );

		$counts = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);
		$log    = array();
		foreach ( $ids as $woo_id ) {
			try {
				$result = self::import_one( (int) $woo_id, $draft );
			} catch ( \Throwable $e ) {
				Logger::error( 'import', 'WooCommerce import failed', array( 'product' => (int) $woo_id, 'error' => $e->getMessage() ) );
				$result = array(
					'status'  => 'errors',
					'message' => $e->getMessage(),
				);
			}
			++$counts[ $result['status'] ];
			$log[] = '#' . (int) $woo_id . ' – ' . $result['message'];
		}

		$done = count( $ids ) < self::BATCH;
		if ( $done ) {
			self::remap_relations();
			update_option( 'wahou_flush_rewrite', 1, true );
		}
		wp_send_json_success(
			array_merge(
				$counts,
				array(
					'next_offset' => $offset + count( $ids ),
					'total'       => self::count_source(),
					'done'        => $done,
					'log'         => $log,
				)
			)
		);
	}

	/**
	 * @return array{status:string, message:string}
	 */
	public static function import_one( int $woo_id, bool $draft = false ): array {
		$existing = self::find_imported( $woo_id );
		if ( $existing ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'already imported', 'wahou-commerce' ),
			);
		}
		$post = get_post( $woo_id );
		if ( ! $post ) {
			return array(
				'status'  => 'errors',
				'message' => __( 'not found', 'wahou-commerce' ),
			);
		}
		$type = self::woo_type( $woo_id );
		if ( in_array( $type, array( 'grouped', 'external' ), true ) ) {
			return array(
				'status'  => 'skipped',
				/* translators: 1: product name, 2: WooCommerce product type (grouped, external). */
				'message' => sprintf( __( '%1$s – %2$s products are not supported', 'wahou-commerce' ), $post->post_title, $type ),
			);
		}

		$meta = get_post_meta( $woo_id );
		$m    = static fn( string $key ): string => isset( $meta[ $key ][0] ) ? (string) $meta[ $key ][0] : '';

		$kind    = 'yes' === $m( '_downloadable' ) ? 'digital' : ( 'yes' === $m( '_virtual' ) ? 'service' : 'physical' );
		$regular = '' !== $m( '_regular_price' ) ? $m( '_regular_price' ) : $m( '_price' );

		$input = array(
			'_wahou_kind'              => $kind,
			'_wahou_type'              => 'variable' === $type ? 'variable' : 'simple',
			'_wahou_price'             => Money::to_minor( $regular ),
			'_wahou_sold_individually' => 'yes' === $m( '_sold_individually' ) ? 1 : 0,
			'_wahou_tax_class'         => self::tax_class( $m( '_tax_status' ), $m( '_tax_class' ) ),
			'_wahou_imported_from'     => $woo_id,
		);
		if ( '' !== $m( '_sale_price' ) ) {
			$input['_wahou_sale_price'] = Money::to_minor( $m( '_sale_price' ) );
		}
		if ( '' !== $m( '_sale_price_dates_from' ) ) {
			$input['_wahou_sale_from'] = (int) $m( '_sale_price_dates_from' );
		}
		if ( '' !== $m( '_sale_price_dates_to' ) ) {
			$input['_wahou_sale_to'] = (int) $m( '_sale_price_dates_to' );
		}
		foreach ( array( '_sku' => '_wahou_sku', '_global_unique_id' => '_wahou_gtin' ) as $from => $to ) {
			if ( '' !== $m( $from ) ) {
				$input[ $to ] = sanitize_text_field( $m( $from ) );
			}
		}
		if ( 'yes' === $m( '_manage_stock' ) ) {
			$input['_wahou_manage_stock'] = 1;
			$input['_wahou_stock']        = (int) $m( '_stock' );
		}
		if ( in_array( $m( '_backorders' ), array( 'yes', 'notify' ), true ) ) {
			$input['_wahou_backorders'] = 1;
		}
		if ( '' !== $m( '_low_stock_amount' ) ) {
			$input['_wahou_low_stock'] = (int) $m( '_low_stock_amount' );
		}
		if ( 'physical' === $kind ) {
			$weight = self::to_kg( $m( '_weight' ) );
			if ( $weight > 0 ) {
				$input['_wahou_weight'] = $weight;
			}
			foreach ( array( '_length' => '_wahou_length', '_width' => '_wahou_width', '_height' => '_wahou_height' ) as $from => $to ) {
				$cm = self::to_cm( $m( $from ) );
				if ( $cm > 0 ) {
					$input[ $to ] = $cm;
				}
			}
		}
		$gallery = array_values( array_filter( array_map( 'intval', explode( ',', $m( '_product_image_gallery' ) ) ) ) );
		if ( $gallery ) {
			$input['_wahou_gallery'] = $gallery;
		}
		if ( 'digital' === $kind ) {
			$files = maybe_unserialize( $m( '_downloadable_files' ) );
			$out   = array();
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				if ( is_array( $file ) && ! empty( $file['file'] ) ) {
					$out[] = self::import_file( (string) $file['file'], (string) ( $file['name'] ?? '' ) );
				}
			}
			if ( $out ) {
				$input['_wahou_files'] = $out;
			}
			if ( (int) $m( '_download_limit' ) > 0 ) {
				$input['_wahou_download_limit'] = (int) $m( '_download_limit' );
			}
			if ( (int) $m( '_download_expiry' ) > 0 ) {
				$input['_wahou_download_expiry'] = (int) $m( '_download_expiry' );
			}
		}
		foreach ( array( '_upsell_ids' => '_wahou_import_upsells', '_crosssell_ids' => '_wahou_import_crosssells' ) as $from => $to ) {
			$ids = maybe_unserialize( $m( $from ) );
			if ( is_array( $ids ) && $ids ) {
				$input[ $to ] = array_values( array_map( 'intval', $ids ) );
			}
		}
		if ( '' !== $m( '_wc_average_rating' ) ) {
			$input['_wahou_rating_avg']   = (float) $m( '_wc_average_rating' );
			$input['_wahou_rating_count'] = (int) $m( '_wc_review_count' );
		}
		if ( '' !== $m( 'total_sales' ) ) {
			$input['_wahou_total_sales'] = (int) $m( 'total_sales' );
		}
		if ( 'variable' === $type ) {
			list( $attributes, $variants ) = self::variations( $woo_id, $m( '_product_attributes' ) );
			if ( $attributes ) {
				$input['_wahou_attributes'] = $attributes;
			}
			if ( $variants ) {
				$input['_wahou_variants'] = $variants;
			}
		} else {
			$attributes = self::attributes( $woo_id, $m( '_product_attributes' ), false );
			if ( $attributes ) {
				$input['_wahou_attributes'] = $attributes;
			}
		}

		$status = $draft ? 'draft' : ( in_array( $post->post_status, self::STATUSES, true ) ? $post->post_status : 'draft' );
		$new_id = wp_insert_post(
			array(
				'post_type'      => PostTypes::PRODUCT,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $status,
				'post_name'      => $post->post_name,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'post_author'    => (int) $post->post_author,
				'menu_order'     => (int) $post->menu_order,
				'comment_status' => $post->comment_status,
				'meta_input'     => $input,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return array(
				'status'  => 'errors',
				'message' => $new_id->get_error_message(),
			);
		}
		$thumbnail = (int) $m( '_thumbnail_id' );
		if ( $thumbnail ) {
			set_post_thumbnail( $new_id, $thumbnail );
		}
		$categories = self::map_terms( $woo_id, 'product_cat', PostTypes::CATEGORY );
		if ( $categories ) {
			wp_set_object_terms( $new_id, $categories, PostTypes::CATEGORY );
		}
		$tags = self::map_terms( $woo_id, 'product_tag', PostTypes::TAG );
		if ( $tags ) {
			wp_set_object_terms( $new_id, $tags, PostTypes::TAG );
		}
		$reviews = self::copy_reviews( $woo_id, (int) $new_id );

		return array(
			'status'  => 'imported',
			'message' => sprintf(
				/* translators: 1: product name, 2: number of reviews. */
				_n( '%1$s imported (%2$d review)', '%1$s imported (%2$d reviews)', $reviews, 'wahou-commerce' ),
				$post->post_title,
				$reviews
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	public static function count_source(): int {
		global $wpdb;
		$in = "'" . implode( "','", self::STATUSES ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ({$in})" );
	}

	public static function count_imported(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_wahou_imported_from'" );
	}

	private static function find_imported( int $woo_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wahou_imported_from' AND pm.meta_value = %s AND p.post_type = %s LIMIT 1", (string) $woo_id, PostTypes::PRODUCT ) );
	}

	private static function woo_type( int $woo_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$slug = $wpdb->get_var( $wpdb->prepare( "SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = 'product_type' AND tr.object_id = %d LIMIT 1", $woo_id ) );
		return $slug ? (string) $slug : 'simple';
	}

	private static function tax_class( string $status, string $class ): string {
		if ( 'none' === $status ) {
			return 'zero';
		}
		switch ( $class ) {
			case 'reduced-rate':
				return 'reduced';
			case 'zero-rate':
				return 'zero';
		}
		return 'standard';
	}

	private static function to_kg( string $value ): float {
		$value = (float) str_replace( ',', '.', $value );
		if ( $value <= 0 ) {
			return 0.0;
		}
		switch ( get_option( 'woocommerce_weight_unit', 'kg' ) ) {
			case 'g':
				return round( $value / 1000, 4 );
			case 'lbs':
				return round( $value * 0.45359237, 4 );
			case 'oz':
				return round( $value * 0.028349523, 4 );
		}
		return $value;
	}

	private static function to_cm( string $value ): float {
		$value = (float) str_replace( ',', '.', $value );
		if ( $value <= 0 ) {
			return 0.0;
		}
		switch ( get_option( 'woocommerce_dimension_unit', 'cm' ) ) {
			case 'm':
				return round( $value * 100, 2 );
			case 'mm':
				return round( $value / 10, 2 );
			case 'in':
				return round( $value * 2.54, 2 );
			case 'yd':
				return round( $value * 91.44, 2 );
		}
		return $value;
	}

	/**
	 * Copy a downloadable file into the protected folder when it lives on this
	 * server, otherwise keep its URL.
	 *
	 * @return array<string, string>
	 */
	private static function import_file( string $url, string $name ): array {
		$id      = 'f_' . strtolower( Crypto::key_string( 12 ) );
		$uploads = wp_upload_dir( null, false );
		$source  = '';
		if ( 0 === strpos( $url, (string) $uploads['baseurl'] ) ) {
			$relative = rawurldecode( (string) strtok( ltrim( substr( $url, strlen( (string) $uploads['baseurl'] ) ), '/' ), '?' ) );
			$source   = (string) $uploads['basedir'] . '/' . $relative;
		} elseif ( ! preg_match( '#^[a-z]+://#i', $url ) && '' !== $url ) {
			$source = $url;
		}
		$real = '' !== $source ? realpath( $source ) : false;
		$root = realpath( ABSPATH );
		$base = realpath( (string) $uploads['basedir'] );
		$safe = $real && is_file( $real ) && ( ( $base && 0 === strpos( $real, $base ) ) || ( $root && 0 === strpos( $real, $root ) ) );
		if ( $safe && filesize( $real ) <= GB_IN_BYTES ) {
			$folder = strtolower( Crypto::key_string( 16 ) );
			$dir    = Install::private_dir() . '/' . $folder;
			wp_mkdir_p( $dir );
			$filename = sanitize_file_name( basename( $real ) );
			if ( copy( $real, $dir . '/' . $filename ) ) {
				return array(
					'id'   => $id,
					'name' => '' !== $name ? $name : $filename,
					'path' => $folder . '/' . $filename,
				);
			}
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return array(
			'id'   => $id,
			'name' => '' !== $name ? $name : basename( $path ),
			'url'  => esc_url_raw( $url ),
		);
	}

	/**
	 * @return int[] Target term IDs.
	 */
	private static function map_terms( int $woo_id, string $from, string $to ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id = %d", $from, $woo_id ) );
		$out      = array();
		foreach ( $term_ids as $term_id ) {
			$mapped = self::ensure_term( (int) $term_id, $from, $to );
			if ( $mapped ) {
				$out[] = $mapped;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function ensure_term( int $source_id, string $from, string $to, int $depth = 0 ): int {
		global $wpdb;
		$key = $to . ':' . $source_id;
		if ( isset( self::$term_map[ $key ] ) ) {
			return self::$term_map[ $key ];
		}
		$existing = get_terms(
			array(
				'taxonomy'   => $to,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'meta_key'   => '_wahou_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( is_array( $existing ) && $existing ) {
			self::$term_map[ $key ] = (int) $existing[0];
			return self::$term_map[ $key ];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.name, t.slug, tt.parent, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s", $source_id, $from ) );
		if ( ! $row ) {
			return 0;
		}
		$parent = ( (int) $row->parent && $depth < 10 ) ? self::ensure_term( (int) $row->parent, $from, $to, $depth + 1 ) : 0;
		$found  = term_exists( (string) $row->slug, $to, $parent ? $parent : null );
		if ( is_array( $found ) ) {
			$term_id = (int) $found['term_id'];
		} else {
			$created = wp_insert_term(
				(string) $row->name,
				$to,
				array(
					'slug'        => (string) $row->slug,
					'parent'      => $parent,
					'description' => (string) $row->description,
				)
			);
			if ( is_wp_error( $created ) ) {
				$term_id = (int) $created->get_error_data( 'term_exists' );
			} else {
				$term_id = (int) $created['term_id'];
			}
		}
		if ( $term_id ) {
			update_term_meta( $term_id, '_wahou_imported_from', (string) $source_id );
			$thumbnail = (int) get_term_meta( $source_id, 'thumbnail_id', true );
			if ( $thumbnail ) {
				update_term_meta( $term_id, 'thumbnail_id', $thumbnail );
			}
		}
		self::$term_map[ $key ] = $term_id;
		return $term_id;
	}

	/**
	 * Non-variation attributes become specification rows.
	 *
	 * @return array<int, array{name:string, values:string[]}>
	 */
	private static function attributes( int $woo_id, string $raw, bool $only_variations ): array {
		$attributes = maybe_unserialize( $raw );
		$out        = array();
		foreach ( is_array( $attributes ) ? $attributes : array() as $attribute ) {
			if ( ! is_array( $attribute ) || ( $only_variations && empty( $attribute['is_variation'] ) ) ) {
				continue;
			}
			$values = self::attribute_values( $woo_id, $attribute );
			if ( $values ) {
				$out[] = array(
					'name'   => self::attribute_label( $attribute ),
					'values' => $values,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $attribute
	 * @return string[]
	 */
	private static function attribute_values( int $woo_id, array $attribute ): array {
		global $wpdb;
		if ( ! empty( $attribute['is_taxonomy'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s AND tr.object_id = %d ORDER BY t.name ASC", (string) $attribute['name'], $woo_id ) );
			return array_values( array_map( 'strval', $names ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $attribute['value'] ?? '' ) ) ) ) );
	}

	/**
	 * @param array<string, mixed> $attribute
	 */
	private static function attribute_label( array $attribute ): string {
		global $wpdb;
		$name = (string) ( $attribute['name'] ?? '' );
		if ( ! empty( $attribute['is_taxonomy'] ) && 0 === strpos( $name, 'pa_' ) ) {
			$table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$label = $wpdb->get_var( $wpdb->prepare( "SELECT attribute_label FROM {$table} WHERE attribute_name = %s", substr( $name, 3 ) ) );
				if ( $label ) {
					return (string) $label;
				}
			}
			return ucfirst( str_replace( array( '-', '_' ), ' ', substr( $name, 3 ) ) );
		}
		return $name;
	}

	/**
	 * Variations of a variable product.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
	 */
	private static function variations( int $woo_id, string $raw ): array {
		global $wpdb;
		$attributes = maybe_unserialize( $raw );
		$attributes = is_array( $attributes ) ? $attributes : array();
		$out_attrs  = self::attributes( $woo_id, $raw, false );
		$labels     = array();
		foreach ( $attributes as $attribute ) {
			if ( is_array( $attribute ) && ! empty( $attribute['name'] ) ) {
				$labels[ 'attribute_' . sanitize_title( (string) $attribute['name'] ) ] = array(
					'label'    => self::attribute_label( $attribute ),
					'taxonomy' => ! empty( $attribute['is_taxonomy'] ) ? (string) $attribute['name'] : '',
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish','private') ORDER BY menu_order ASC, ID ASC", $woo_id ) );
		$variants = array();
		foreach ( $children as $child_id ) {
			$vm    = get_post_meta( (int) $child_id );
			$v     = static fn( string $key ): string => isset( $vm[ $key ][0] ) ? (string) $vm[ $key ][0] : '';
			$attrs = array();
			foreach ( $vm as $key => $values ) {
				if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
					continue;
				}
				$info  = $labels[ $key ] ?? null;
				$value = (string) ( $values[0] ?? '' );
				if ( $info && '' !== $info['taxonomy'] && '' !== $value ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$term_name = $wpdb->get_var( $wpdb->prepare( "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s", $value, $info['taxonomy'] ) );
					$value     = $term_name ? (string) $term_name : $value;
				}
				$attrs[ $info ? $info['label'] : ucfirst( substr( (string) $key, 10 ) ) ] = '' !== $value ? $value : __( 'Any', 'wahou-commerce' );
			}
			$variant = array(
				'id'         => 'v' . (int) $child_id,
				'attributes' => $attrs,
				'price'      => '' !== $v( '_regular_price' ) ? Money::to_minor( $v( '_regular_price' ) ) : null,
				'sale_price' => '' !== $v( '_sale_price' ) ? Money::to_minor( $v( '_sale_price' ) ) : null,
				'sku'        => $v( '_sku' ),
				'stock'      => 'yes' === $v( '_manage_stock' ) ? (int) $v( '_stock' ) : null,
				'image_id'   => (int) $v( '_thumbnail_id' ),
			);
			$variants[] = $variant;
		}
		return array( $out_attrs, $variants );
	}

	private static function copy_reviews( int $woo_id, int $new_id ): int {
		$comments = get_comments(
			array(
				'post_id'  => $woo_id,
				'status'   => 'approve',
				'type__in' => array( 'review', 'comment' ),
				'number'   => 500,
			)
		);
		$count    = 0;
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment || (int) $comment->comment_parent ) {
				continue;
			}
			$new = wp_insert_comment(
				array(
					'comment_post_ID'      => $new_id,
					'comment_author'       => $comment->comment_author,
					'comment_author_email' => $comment->comment_author_email,
					'comment_author_url'   => $comment->comment_author_url,
					'comment_content'      => $comment->comment_content,
					'comment_type'         => 'review',
					'comment_parent'       => 0,
					'user_id'              => (int) $comment->user_id,
					'comment_date'         => $comment->comment_date,
					'comment_date_gmt'     => $comment->comment_date_gmt,
					'comment_approved'     => 1,
				)
			);
			if ( $new ) {
				$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
				if ( $rating ) {
					add_comment_meta( (int) $new, 'rating', $rating, true );
				}
				if ( get_comment_meta( (int) $comment->comment_ID, 'verified', true ) ) {
					add_comment_meta( (int) $new, 'verified', 1, true );
				}
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Translate WooCommerce upsell/cross-sell IDs into Wahou product IDs.
	 */
	private static function remap_relations(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wahou_imported_from'", ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['meta_value'] ] = (int) $row['post_id'];
		}
		foreach ( array( '_wahou_import_upsells' => '_wahou_upsells', '_wahou_import_crosssells' => '_wahou_cross_sells' ) as $temp => $target ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pending = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $temp ), ARRAY_A );
			foreach ( (array) $pending as $row ) {
				$ids    = maybe_unserialize( $row['meta_value'] );
				$mapped = array();
				foreach ( is_array( $ids ) ? $ids : array() as $woo_id ) {
					if ( isset( $map[ (int) $woo_id ] ) ) {
						$mapped[] = $map[ (int) $woo_id ];
					}
				}
				if ( $mapped ) {
					update_post_meta( (int) $row['post_id'], $target, array_values( array_unique( $mapped ) ) );
				}
				delete_post_meta( (int) $row['post_id'], $temp );
				Product::flush( (int) $row['post_id'] );
			}
		}
	}
}
