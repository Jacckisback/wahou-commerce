<?php
/**
 * Abandoned carts screen: recovery stats, carts with an email address and
 * their reminder status. The reminders themselves are sent by the Recovery module.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Recovery\Recovery;
use WahouCommerce\Settings;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class CartsPage {

	public const SLUG = 'wahou-carts';

	private const PER_PAGE = 20;

	public static function init(): void {
		add_action( 'admin_post_wahou_cart_delete', array( self::class, 'handle_delete' ) );
	}

	/**
	 * Recovery statistics between two GMT dates. Uses the Recovery module when
	 * available, otherwise computes them from the carts table.
	 *
	 * @return array{abandoned:int, recovered:int, recovered_revenue:int, rate:float}
	 */
	public static function stats( string $from_gmt, string $to_gmt ): array {
		if ( class_exists( Recovery::class ) && method_exists( Recovery::class, 'stats' ) ) {
			$stats = (array) Recovery::stats( $from_gmt, $to_gmt );
			return array(
				'abandoned'         => (int) ( $stats['abandoned'] ?? 0 ),
				'recovered'         => (int) ( $stats['recovered'] ?? 0 ),
				'recovered_revenue' => (int) ( $stats['recovered_revenue'] ?? 0 ),
				'rate'              => (float) ( $stats['rate'] ?? 0 ),
			);
		}
		global $wpdb;
		$carts  = Install::table( 'carts' );
		$orders = Install::table( 'orders' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$abandoned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE status IN ('abandoned','recovered') AND email <> '' AND created_at BETWEEN %s AND %s", $from_gmt, $to_gmt ) );
		$recovered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$carts} WHERE status = 'recovered' AND converted_at BETWEEN %s AND %s", $from_gmt, $to_gmt ) );
		$revenue   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(o.total - o.refunded_total), 0) FROM {$carts} c INNER JOIN {$orders} o ON o.id = c.order_id WHERE c.status = 'recovered' AND c.converted_at BETWEEN %s AND %s AND o.status IN ('processing','completed','refunded')", $from_gmt, $to_gmt ) );
		// phpcs:enable
		return array(
			'abandoned'         => $abandoned,
			'recovered'         => $recovered,
			'recovered_revenue' => $revenue,
			'rate'              => $abandoned > 0 ? round( $recovered / $abandoned * 100, 1 ) : 0.0,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array(
			'abandoned' => __( 'Abandoned', 'wahou-commerce' ),
			'recovered' => __( 'Recovered', 'wahou-commerce' ),
			'active'    => __( 'In progress', 'wahou-commerce' ),
			'all'       => __( 'All with an email', 'wahou-commerce' ),
		);
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'active'    => __( 'In progress', 'wahou-commerce' ),
			'abandoned' => __( 'Abandoned', 'wahou-commerce' ),
			'recovered' => __( 'Recovered', 'wahou-commerce' ),
			'converted' => __( 'Ordered', 'wahou-commerce' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * @return array{0:string, 1:array<int, mixed>}
	 */
	private static function where( string $tab, string $search ): array {
		global $wpdb;
		$where  = array( "email <> ''" );
		$params = array();
		switch ( $tab ) {
			case 'abandoned':
				$where[] = "status = 'abandoned'";
				break;
			case 'recovered':
				$where[] = "status = 'recovered'";
				break;
			case 'active':
				$where[] = "status = 'active' AND item_count > 0";
				break;
			default:
				$where[] = "status IN ('active','abandoned','recovered','converted')";
		}
		if ( '' !== $search ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private static function count_for( string $tab ): int {
		global $wpdb;
		$table              = Install::table( 'carts' );
		list( $where, $params ) = self::where( $tab, '' );
		unset( $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	public static function render(): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_wahou_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wahou-commerce' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'abandoned';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'abandoned';
		}

		$table                  = Install::table( 'carts' );
		list( $where, $params ) = self::where( $tab, $search );
		$offset                 = ( $paged - 1 ) * self::PER_PAGE;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (int) $offset;
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A ) );
		// phpcs:enable

		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$stats = self::stats( $now->modify( '-30 days' )->format( 'Y-m-d H:i:s' ), $now->format( 'Y-m-d H:i:s' ) );
		$steps = count( (array) Settings::get( 'recovery_steps', array() ) );
		$base  = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap wahou-ap wahou-ap-carts">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Abandoned carts', 'wahou-commerce' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=wahou-settings&tab=emails' ) ); ?>"><?php esc_html_e( 'Reminder emails settings', 'wahou-commerce' ); ?></a>
			<hr class="wp-header-end">
			<?php Admin::print_flash(); ?>

			<?php if ( ! Settings::bool( 'recovery_enabled' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Cart recovery emails are turned off: abandoned carts are listed but no reminder is sent.', 'wahou-commerce' ); ?></p></div>
			<?php endif; ?>

			<div class="wahou-ap-kpis wahou-ap-kpis--4" aria-label="<?php esc_attr_e( 'Last 30 days', 'wahou-commerce' ); ?>">
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Abandoned carts', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( number_format_i18n( $stats['abandoned'] ) ); ?></strong>
					<span class="wahou-ap-sub"><?php esc_html_e( 'last 30 days', 'wahou-commerce' ); ?></span>
				</div>
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Recovered carts', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( number_format_i18n( $stats['recovered'] ) ); ?></strong>
					<span class="wahou-ap-sub"><?php esc_html_e( 'last 30 days', 'wahou-commerce' ); ?></span>
				</div>
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Recovery rate', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( number_format_i18n( $stats['rate'], 1 ) . ' %' ); ?></strong>
					<span class="wahou-ap-sub"><?php esc_html_e( 'last 30 days', 'wahou-commerce' ); ?></span>
				</div>
				<div class="wahou-ap-kpi wahou-ap-kpi--accent">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Recovered revenue', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( Money::format( $stats['recovered_revenue'] ) ); ?></strong>
					<span class="wahou-ap-sub"><?php esc_html_e( 'last 30 days', 'wahou-commerce' ); ?></span>
				</div>
			</div>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $tabs as $key => $label ) {
					$links[] = sprintf(
						'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
						esc_url( add_query_arg( 'tab', $key, $base ) ),
						$key === $tab ? ' class="current" aria-current="page"' : '',
						esc_html( $label ),
						esc_html( number_format_i18n( self::count_for( $key ) ) )
					);
				}
				echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>
			<form method="get" class="search-form wahou-ap-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="wahou-cart-search"><?php esc_html_e( 'Search by email', 'wahou-commerce' ); ?></label>
					<input type="search" id="wahou-cart-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email…', 'wahou-commerce' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'wahou-commerce' ); ?></button>
				</p>
			</form>

			<div class="wahou-ap-table-wrap">
				<table class="wp-list-table widefat fixed striped wahou-ap-carts-table">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Customer', 'wahou-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Products', 'wahou-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wahou-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reminders', 'wahou-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last activity', 'wahou-commerce' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wahou-commerce' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr class="no-items"><td colspan="7"><?php echo esc_html( 'abandoned' === $tab ? __( 'No abandoned cart right now. Carts are flagged after a period of inactivity once the shopper has typed an email at checkout.', 'wahou-commerce' ) : __( 'No carts found.', 'wahou-commerce' ) ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$data     = json_decode( (string) $row['data'], true );
						$data     = is_array( $data ) ? $data : array();
						$customer = is_array( $data['customer'] ?? null ) ? $data['customer'] : array();
						$name     = trim( (string) ( $customer['first_name'] ?? '' ) . ' ' . (string) ( $customer['last_name'] ?? '' ) );
						$names    = array();
						foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
							$title = get_the_title( (int) ( $item['product_id'] ?? 0 ) );
							if ( '' !== $title ) {
								$names[] = $title . ( (int) ( $item['qty'] ?? 1 ) > 1 ? ' × ' . (int) $item['qty'] : '' );
							}
						}
						$updated = (int) strtotime( (string) $row['updated_at'] . ' UTC' );
						$order   = (int) $row['order_id'] ? Order::find( (int) $row['order_id'] ) : null;
						?>
						<tr>
							<td class="column-primary">
								<strong><a href="mailto:<?php echo esc_attr( (string) $row['email'] ); ?>"><?php echo esc_html( (string) $row['email'] ); ?></a></strong>
								<?php if ( '' !== $name ) : ?>
									<div class="wahou-ap-sub"><?php echo esc_html( $name ); ?></div>
								<?php endif; ?>
								<?php if ( (int) $row['unsubscribed'] ) : ?>
									<span class="wahou-ap-tag"><?php esc_html_e( 'Unsubscribed', 'wahou-commerce' ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="wahou-ap-truncate"><?php echo esc_html( $names ? implode( ', ', $names ) : '—' ); ?></span></td>
							<td><?php echo esc_html( Money::format( (int) $row['total'], (string) $row['currency'] ) ); ?></td>
							<td><span class="wahou-ap-pill wahou-ap-pill--cart-<?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( self::status_label( (string) $row['status'] ) ); ?></span></td>
							<td><?php echo esc_html( (int) $row['reminders_sent'] . ( $steps ? ' / ' . $steps : '' ) ); ?></td>
							<td>
								<time datetime="<?php echo esc_attr( gmdate( 'c', $updated ) ); ?>" title="<?php echo esc_attr( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated ) ); ?>">
									<?php
									/* translators: %s: human time difference. */
									echo esc_html( sprintf( __( '%s ago', 'wahou-commerce' ), human_time_diff( $updated ) ) );
									?>
								</time>
								<?php if ( $order ) : ?>
									<div><a href="<?php echo esc_url( $order->admin_url() ); ?>">
										<?php
										/* translators: %s: order number. */
										echo esc_html( sprintf( __( 'Order #%s', 'wahou-commerce' ), $order->number() ) );
										?>
									</a></div>
								<?php endif; ?>
							</td>
							<td class="wahou-ap-actions-cell">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'wahou_cart_delete_' . (int) $row['id'] ); ?>
									<input type="hidden" name="action" value="wahou_cart_delete">
									<input type="hidden" name="cart_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
									<button type="submit" class="button-link button-link-delete" data-wahou-confirm-delete><?php esc_html_e( 'Delete', 'wahou-commerce' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
						)
					)
				);
				echo '</div></div>';
			}
			?>

			<p class="wahou-ap-gdpr">
				<?php
				printf(
					/* translators: %d: retention in days. */
					esc_html__( 'Privacy: carts with an email address are deleted automatically after %d days. Every reminder email contains a one-click unsubscribe link. Mention cart reminders in your privacy policy.', 'wahou-commerce' ),
					(int) max( 7, Settings::int( 'recovery_retention_days' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	public static function handle_delete(): void {
		global $wpdb;
		$id = isset( $_POST['cart_id'] ) ? absint( $_POST['cart_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
		Admin::guard( 'wahou_cart_delete_' . $id );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'abandoned'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Install::table( 'carts' ), array( 'id' => $id ) );
			Admin::flash( __( 'Cart deleted.', 'wahou-commerce' ) );
		}
		Admin::back( add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=' . self::SLUG ) ) );
	}
}
