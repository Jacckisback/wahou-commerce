<?php
/**
 * Store dashboard: setup checklist, KPIs with period comparison, revenue
 * chart (server-rendered SVG, no chart library), recent orders, best sellers
 * and things that need attention.
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Products\PostTypes;
use WahouCommerce\Settings;
use WahouCommerce\Shipping\Shipping;
use WahouCommerce\Support\Money;
use WahouCommerce\Support\Pages;
use WahouCommerce\Support\Request;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	public static function init(): void {
		add_action( 'admin_post_wahou_dashboard_create_pages', array( self::class, 'handle_create_pages' ) );
	}

	/* ------------------------------------------------------------------
	 * Periods.
	 * ------------------------------------------------------------------ */

	/**
	 * Current and previous windows (GMT) for a period key.
	 *
	 * @return array{label:string, from:string, to:string, prev_from:string, prev_to:string}
	 */
	public static function period( string $key ): array {
		$tz    = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$now   = new \DateTimeImmutable( 'now', $tz );
		$today = $now->setTime( 0, 0 );
		$days  = 'today' === $key ? 1 : ( '7d' === $key ? 7 : 30 );
		$start = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$fmt   = static fn( \DateTimeImmutable $d ): string => $d->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$labels = array(
			'today' => __( 'Today', 'wahou-commerce' ),
			'7d'    => __( 'Last 7 days', 'wahou-commerce' ),
			'30d'   => __( 'Last 30 days', 'wahou-commerce' ),
		);
		return array(
			'label'     => $labels[ $key ] ?? $labels['30d'],
			'from'      => $fmt( $start ),
			'to'        => $fmt( $now ),
			'prev_from' => $fmt( $start->modify( '-' . $days . ' days' ) ),
			'prev_to'   => $fmt( $now->modify( '-' . $days . ' days' ) ),
		);
	}

	private static function change( int $current, int $previous ): ?float {
		if ( $previous <= 0 ) {
			return $current > 0 ? null : 0.0;
		}
		return round( ( $current - $previous ) / $previous * 100, 1 );
	}

	private static function change_html( ?float $change, string $versus ): string {
		if ( null === $change ) {
			return '<span class="wahou-ap-delta wahou-ap-delta--up">' . esc_html__( 'New', 'wahou-commerce' ) . '</span>';
		}
		$class = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'flat' );
		$arrow = $change > 0 ? '↑' : ( $change < 0 ? '↓' : '→' );
		return sprintf(
			'<span class="wahou-ap-delta wahou-ap-delta--%1$s"><span aria-hidden="true">%2$s</span> %3$s</span> <span class="wahou-ap-sub">%4$s</span>',
			esc_attr( $class ),
			esc_html( $arrow ),
			esc_html( number_format_i18n( abs( $change ), 1 ) . ' %' ),
			esc_html( $versus )
		);
	}

	/* ------------------------------------------------------------------
	 * Checklist.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<int, array{key:string, done:bool, title:string, text:string, url:string, cta:string, warning?:bool}>
	 */
	public static function checklist(): array {
		$items    = array();
		$settings = admin_url( 'admin.php?page=wahou-settings' );

		$items[] = array(
			'key'   => 'identity',
			'done'  => '' !== trim( Settings::str( 'store_address' ) ) && '' !== trim( Settings::str( 'store_legal_name' ) . Settings::str( 'store_name' ) ),
			'title' => __( 'Complete your legal details', 'wahou-commerce' ),
			'text'  => __( 'Legal name, address and VAT number appear on invoices and in your terms of sale.', 'wahou-commerce' ),
			'url'   => add_query_arg( 'tab', 'general', $settings ),
			'cta'   => __( 'Add details', 'wahou-commerce' ),
		);

		$available = Gateways::available();
		$items[]   = array(
			'key'   => 'payments',
			'done'  => ! empty( $available ),
			'title' => __( 'Connect a payment method', 'wahou-commerce' ),
			'text'  => __( 'Stripe (cards, Apple Pay, Google Pay), PayPal or bank transfer: two minutes, no code.', 'wahou-commerce' ),
			'url'   => add_query_arg( 'tab', 'payments', $settings ),
			'cta'   => __( 'Connect', 'wahou-commerce' ),
		);

		if ( ! empty( $available ) && self::test_mode() ) {
			$items[] = array(
				'key'     => 'live',
				'done'    => false,
				'warning' => true,
				'title'   => __( 'Switch payments to live mode', 'wahou-commerce' ),
				'text'    => __( 'Test mode is on: orders work but no real money is collected.', 'wahou-commerce' ),
				'url'     => add_query_arg( 'tab', 'payments', $settings ),
				'cta'     => __( 'Go live', 'wahou-commerce' ),
			);
		}

		$missing = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'account' ) as $key ) {
			$id = Pages::id( $key, false );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				$missing[] = $key;
			}
		}
		$items[] = array(
			'key'   => 'pages',
			'done'  => empty( $missing ),
			'title' => __( 'Create the store pages', 'wahou-commerce' ),
			'text'  => __( 'Shop, cart, checkout and customer account, created in one click.', 'wahou-commerce' ),
			'url'   => wp_nonce_url( admin_url( 'admin-post.php?action=wahou_dashboard_create_pages' ), 'wahou_dashboard_create_pages' ),
			'cta'   => __( 'Create pages', 'wahou-commerce' ),
		);

		$products = wp_count_posts( PostTypes::PRODUCT );
		$items[]  = array(
			'key'   => 'product',
			'done'  => isset( $products->publish ) && (int) $products->publish > 0,
			'title' => __( 'Add your first product', 'wahou-commerce' ),
			'text'  => __( 'Physical, digital or bundle: photos, price, stock and SEO in a single screen.', 'wahou-commerce' ),
			'url'   => admin_url( 'post-new.php?post_type=' . PostTypes::PRODUCT ),
			'cta'   => __( 'Add a product', 'wahou-commerce' ),
		);

		if ( Onboarding::sells_physical() ) {
			$has_shipping = false;
			foreach ( Shipping::zones() as $zone ) {
				foreach ( $zone['methods'] as $method ) {
					if ( ! empty( $method['enabled'] ) ) {
						$has_shipping = true;
					}
				}
			}
			$items[] = array(
				'key'   => 'shipping',
				'done'  => $has_shipping,
				'title' => __( 'Set your shipping rates', 'wahou-commerce' ),
				'text'  => __( 'Zones, prices, free shipping threshold and delivery times.', 'wahou-commerce' ),
				'url'   => add_query_arg( 'tab', 'shipping', $settings ),
				'cta'   => __( 'Set rates', 'wahou-commerce' ),
			);
		}

		$terms   = Pages::id( 'terms', false );
		$content = $terms ? (string) get_post_field( 'post_content', $terms ) : '';
		$items[] = array(
			'key'   => 'terms',
			'done'  => $terms && '' !== $content && false === strpos( $content, '<p>…</p>' ),
			'title' => __( 'Complete your terms of sale', 'wahou-commerce' ),
			'text'  => __( 'A template with the sections EU law expects is ready: fill it in and have it reviewed.', 'wahou-commerce' ),
			'url'   => $terms ? (string) get_edit_post_link( $terms, 'raw' ) : add_query_arg( 'tab', 'pages', $settings ),
			'cta'   => __( 'Edit terms', 'wahou-commerce' ),
		);

		$items[] = array(
			'key'   => 'https',
			'done'  => Request::is_https(),
			'title' => __( 'Secure your site with HTTPS', 'wahou-commerce' ),
			'text'  => __( 'Required by Stripe, PayPal, Apple Pay and Google Pay for live payments.', 'wahou-commerce' ),
			'url'   => 'https://wordpress.org/documentation/article/https-for-wordpress/',
			'cta'   => __( 'Learn how', 'wahou-commerce' ),
		);

		/**
		 * Filters the dashboard setup checklist.
		 */
		return (array) apply_filters( 'wahou_dashboard_checklist', $items );
	}

	public static function test_mode(): bool {
		$stripe = Settings::bool( 'stripe_enabled' ) && 'live' !== Settings::str( 'stripe_mode' );
		$paypal = Settings::bool( 'paypal_enabled' ) && 'live' !== Settings::str( 'paypal_mode' );
		return $stripe || $paypal;
	}

	public static function handle_create_pages(): void {
		Admin::guard( 'wahou_dashboard_create_pages', 'manage_wahou' );
		$pages = Install::create_pages();
		/* translators: %d: number of pages. */
		Admin::flash( sprintf( _n( '%d store page is ready.', '%d store pages are ready.', count( $pages ), 'wahou-commerce' ), count( $pages ) ) );
		Admin::back( admin_url( 'admin.php?page=' . Admin::MENU ) );
	}

	/* ------------------------------------------------------------------
	 * Data.
	 * ------------------------------------------------------------------ */

	/**
	 * Daily net revenue for the last $days local days.
	 *
	 * @return array<string, array{revenue:int, orders:int}> Y-m-d (local) => data
	 */
	public static function daily_revenue( int $days = 30 ): array {
		global $wpdb;
		$tz    = wp_timezone();
		$start = ( new \DateTimeImmutable( 'today', $tz ) )->modify( '-' . ( $days - 1 ) . ' days' );
		$out   = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$out[ $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ) ] = array(
				'revenue' => 0,
				'orders'  => 0,
			);
		}
		$from  = $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT paid_at, total, refunded_total FROM {$table} WHERE status IN ('processing','completed','refunded') AND paid_at >= %s ORDER BY paid_at ASC LIMIT 50000", $from ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$day = ( new \DateTimeImmutable( (string) $row['paid_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( isset( $out[ $day ] ) ) {
				$out[ $day ]['revenue'] += (int) $row['total'] - (int) $row['refunded_total'];
				++$out[ $day ]['orders'];
			}
		}
		return $out;
	}

	private static function carts_created( string $from, string $to ): int {
		global $wpdb;
		$table = Install::table( 'carts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s AND (item_count > 0 OR order_id > 0)", $from, $to ) );
	}

	/**
	 * Published products whose stock is at or below the low stock threshold.
	 *
	 * @return int[]
	 */
	private static function low_stock_ids(): array {
		return get_posts(
			array(
				'post_type'      => PostTypes::PRODUCT,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wahou_manage_stock',
						'value' => '1',
					),
					array(
						'key'     => '_wahou_stock',
						'value'   => Settings::int( 'low_stock_threshold' ),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	private static function short_money( int $minor ): string {
		$decimals = Money::decimals();
		$units    = $minor / ( 10 ** $decimals );
		if ( $units >= 10000 ) {
			return number_format_i18n( $units / 1000, 1 ) . ' k' . Money::symbol();
		}
		return number_format_i18n( $units ) . ' ' . Money::symbol();
	}

	/* ------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( 'manage_wahou_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wahou-commerce' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only period switch.
		$key    = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '30d';
		$key    = in_array( $key, array( 'today', '7d', '30d' ), true ) ? $key : '30d';
		$period = self::period( $key );
		$cur    = Orders::stats( $period['from'], $period['to'] );
		$prev   = Orders::stats( $period['prev_from'], $period['prev_to'] );
		$carts  = CartsPage::stats( $period['from'], $period['to'] );
		$created = self::carts_created( $period['from'], $period['to'] );
		$versus = 'today' === $key ? __( 'vs yesterday', 'wahou-commerce' ) : __( 'vs previous period', 'wahou-commerce' );
		$base   = admin_url( 'admin.php?page=' . Admin::MENU );

		$checklist = current_user_can( 'manage_wahou' ) ? self::checklist() : array();
		$todo      = array_filter( $checklist, static fn( $i ) => ! $i['done'] );
		$done      = count( $checklist ) - count( $todo );
		$user      = wp_get_current_user();
		$shop_url  = Pages::id( 'shop' ) ? Pages::url( 'shop' ) : home_url( '/' );
		?>
		<div class="wrap wahou-ap wahou-ap-dashboard">
			<div class="wahou-ap-hero">
				<div>
					<h1 class="wahou-ap-title">
						<?php
						/* translators: %s: user first name or display name. */
						echo esc_html( sprintf( __( 'Hello %s', 'wahou-commerce' ), $user->first_name ? $user->first_name : $user->display_name ) );
						?>
					</h1>
					<p class="wahou-ap-sub"><?php echo esc_html( Settings::store_name() ); ?> · <a href="<?php echo esc_url( $shop_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View store', 'wahou-commerce' ); ?> <span aria-hidden="true">↗</span></a></p>
				</div>
				<div class="wahou-ap-quick">
					<?php if ( current_user_can( 'edit_wahou_products' ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::PRODUCT ) ); ?>"><?php esc_html_e( 'Add product', 'wahou-commerce' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::COUPON ) ); ?>"><?php esc_html_e( 'Create coupon', 'wahou-commerce' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::OFFER ) ); ?>"><?php esc_html_e( 'Create upsell', 'wahou-commerce' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . OrdersPage::SLUG ) ); ?>"><?php esc_html_e( 'Orders', 'wahou-commerce' ); ?></a>
				</div>
			</div>
			<hr class="wp-header-end">
			<?php Admin::print_flash(); ?>

			<?php if ( Onboarding::has_demo() && current_user_can( 'manage_wahou' ) ) : ?>
				<div class="wahou-ap-banner">
					<p><strong><?php esc_html_e( 'Demo content is installed.', 'wahou-commerce' ); ?></strong> <?php esc_html_e( 'Test a full order in test mode, then remove the demo products in one click.', 'wahou-commerce' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wahou_delete_demo' ); ?>
						<input type="hidden" name="action" value="wahou_delete_demo">
						<button type="submit" class="button" data-wahou-confirm="<?php esc_attr_e( 'Delete the demo products, coupon and offer?', 'wahou-commerce' ); ?>"><?php esc_html_e( 'Delete demo content', 'wahou-commerce' ); ?></button>
					</form>
				</div>
			<?php endif; ?>

			<?php if ( $todo ) : ?>
				<section class="wahou-ap-card wahou-ap-checklist" aria-labelledby="wahou-checklist-title">
					<div class="wahou-ap-card__head">
						<h2 id="wahou-checklist-title"><?php esc_html_e( 'Launch checklist', 'wahou-commerce' ); ?></h2>
						<span class="wahou-ap-sub">
							<?php
							/* translators: 1: completed steps, 2: total steps. */
							echo esc_html( sprintf( __( '%1$d of %2$d done', 'wahou-commerce' ), $done, count( $checklist ) ) );
							?>
						</span>
					</div>
					<div class="wahou-ap-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) count( $checklist ) ); ?>" aria-valuenow="<?php echo esc_attr( (string) $done ); ?>" aria-label="<?php esc_attr_e( 'Launch checklist progress', 'wahou-commerce' ); ?>">
						<span style="width:<?php echo esc_attr( (string) round( $done / max( 1, count( $checklist ) ) * 100 ) ); ?>%"></span>
					</div>
					<ul class="wahou-ap-steps">
						<?php foreach ( $checklist as $item ) : ?>
							<li class="<?php echo esc_attr( $item['done'] ? 'is-done' : ( ! empty( $item['warning'] ) ? 'is-warning' : '' ) ); ?>">
								<span class="wahou-ap-steps__icon" aria-hidden="true"><?php echo $item['done'] ? '✓' : ( ! empty( $item['warning'] ) ? '!' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div class="wahou-ap-steps__text">
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( ! $item['done'] ) : ?>
										<span class="wahou-ap-sub"><?php echo esc_html( $item['text'] ); ?></span>
									<?php else : ?>
										<span class="screen-reader-text"><?php esc_html_e( 'Done', 'wahou-commerce' ); ?></span>
									<?php endif; ?>
								</div>
								<?php if ( ! $item['done'] ) : ?>
									<a class="button" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo 0 === strpos( $item['url'], 'http' ) && false === strpos( $item['url'], admin_url() ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $item['cta'] ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! get_option( 'wahou_onboarding_done' ) ) : ?>
						<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Onboarding::SLUG ) ); ?>"><?php esc_html_e( 'Launch the setup wizard (5 minutes)', 'wahou-commerce' ); ?></a></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<nav class="wahou-ap-periods" aria-label="<?php esc_attr_e( 'Period', 'wahou-commerce' ); ?>">
				<?php foreach ( array( 'today', '7d', '30d' ) as $p ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'period', $p, $base ) ); ?>" class="<?php echo esc_attr( $p === $key ? 'is-active' : '' ); ?>"<?php echo $p === $key ? ' aria-current="true"' : ''; ?>><?php echo esc_html( self::period( $p )['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="wahou-ap-kpis">
				<div class="wahou-ap-kpi wahou-ap-kpi--accent">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Revenue', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( Money::format( $cur['revenue'] ) ); ?></strong>
					<span class="wahou-ap-kpi__delta"><?php echo self::change_html( self::change( $cur['revenue'], $prev['revenue'] ), $versus ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</div>
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Orders', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( number_format_i18n( $cur['orders'] ) ); ?></strong>
					<span class="wahou-ap-kpi__delta"><?php echo self::change_html( self::change( $cur['orders'], $prev['orders'] ), $versus ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</div>
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Average order', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( Money::format( $cur['aov'] ) ); ?></strong>
					<span class="wahou-ap-kpi__delta"><?php echo self::change_html( self::change( $cur['aov'], $prev['aov'] ), $versus ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</div>
				<div class="wahou-ap-kpi">
					<span class="wahou-ap-kpi__label"><?php esc_html_e( 'Recovered carts', 'wahou-commerce' ); ?></span>
					<strong class="wahou-ap-kpi__value"><?php echo esc_html( Money::format( $carts['recovered_revenue'] ) ); ?></strong>
					<span class="wahou-ap-kpi__delta wahou-ap-sub">
						<?php
						/* translators: 1: recovered carts, 2: abandoned carts, 3: recovery rate. */
						echo esc_html( sprintf( __( '%1$d of %2$d carts · %3$s', 'wahou-commerce' ), $carts['recovered'], $carts['abandoned'], number_format_i18n( $carts['rate'], 1 ) . ' %' ) );
						?>
					</span>
				</div>
			</div>
			<p class="wahou-ap-sub wahou-ap-kpi-note">
				<?php
				if ( $created > 0 ) {
					/* translators: 1: carts created, 2: cart to order conversion rate. */
					echo esc_html( sprintf( _n( '%1$d cart created · %2$s turned into orders.', '%1$d carts created · %2$s turned into orders.', $created, 'wahou-commerce' ), $created, number_format_i18n( min( 100, $cur['orders'] / $created * 100 ), 1 ) . ' %' ) );
				}
				if ( $cur['refunds'] > 0 ) {
					/* translators: %s: refunded amount. */
					echo ' ' . esc_html( sprintf( __( 'Revenue is net of %s refunded.', 'wahou-commerce' ), Money::format( $cur['refunds'] ) ) );
				}
				?>
			</p>

			<section class="wahou-ap-card">
				<div class="wahou-ap-card__head">
					<h2><?php esc_html_e( 'Revenue – last 30 days', 'wahou-commerce' ); ?></h2>
				</div>
				<?php self::render_chart( self::daily_revenue( 30 ) ); ?>
			</section>

			<div class="wahou-ap-columns">
				<section class="wahou-ap-card">
					<div class="wahou-ap-card__head">
						<h2><?php esc_html_e( 'Recent orders', 'wahou-commerce' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . OrdersPage::SLUG ) ); ?>"><?php esc_html_e( 'All orders', 'wahou-commerce' ); ?></a>
					</div>
					<?php self::render_recent_orders(); ?>
				</section>
				<div class="wahou-ap-stack">
					<?php self::render_attention(); ?>
					<section class="wahou-ap-card">
						<div class="wahou-ap-card__head">
							<h2><?php esc_html_e( 'Best sellers', 'wahou-commerce' ); ?></h2>
							<span class="wahou-ap-sub"><?php esc_html_e( '30 days', 'wahou-commerce' ); ?></span>
						</div>
						<?php self::render_top_products(); ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, array{revenue:int, orders:int}> $days
	 */
	private static function render_chart( array $days ): void {
		$width   = 720;
		$height  = 220;
		$left    = 56;
		$bottom  = 26;
		$top     = 12;
		$plot_w  = $width - $left - 8;
		$plot_h  = $height - $bottom - $top;
		$max     = (int) max( array_merge( array( 1 ), array_map( static fn( $d ) => (int) $d['revenue'], array_values( $days ) ) ) );
		$total   = array_sum( array_column( $days, 'revenue' ) );
		$count   = count( $days );
		$slot    = $plot_w / max( 1, $count );
		$bar_w   = max( 2, $slot * 0.68 );
		$today   = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		// Nice upper bound for the axis.
		$decimals = Money::decimals();
		$unit     = 10 ** $decimals;
		$raw      = $max / $unit;
		$mag      = 10 ** floor( log10( max( 1, $raw ) ) );
		$nice     = ceil( $raw / $mag ) * $mag;
		$axis_max = (int) max( $unit, $nice * $unit );

		$summary = sprintf(
			/* translators: 1: total revenue, 2: number of days. */
			__( 'Total revenue %1$s over %2$d days.', 'wahou-commerce' ),
			Money::format( $total ),
			$count
		);
		?>
		<figure class="wahou-ap-chart">
			<svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" role="img" aria-labelledby="wahou-chart-t wahou-chart-d" preserveAspectRatio="xMidYMid meet">
				<title id="wahou-chart-t"><?php esc_html_e( 'Daily revenue', 'wahou-commerce' ); ?></title>
				<desc id="wahou-chart-d"><?php echo esc_html( $summary ); ?></desc>
				<?php foreach ( array( 0, 0.5, 1 ) as $ratio ) : ?>
					<?php $y = $top + $plot_h - $plot_h * $ratio; ?>
					<line x1="<?php echo (int) $left; ?>" x2="<?php echo (int) ( $width - 8 ); ?>" y1="<?php echo esc_attr( (string) round( $y, 1 ) ); ?>" y2="<?php echo esc_attr( (string) round( $y, 1 ) ); ?>" class="wahou-ap-chart__grid"/>
					<text x="<?php echo (int) ( $left - 8 ); ?>" y="<?php echo esc_attr( (string) round( $y + 4, 1 ) ); ?>" text-anchor="end" class="wahou-ap-chart__label"><?php echo esc_html( self::short_money( (int) round( $axis_max * $ratio ) ) ); ?></text>
				<?php endforeach; ?>
				<?php
				$i = 0;
				foreach ( $days as $day => $data ) :
					$h     = $data['revenue'] > 0 ? max( 2, $plot_h * $data['revenue'] / $axis_max ) : 0;
					$x     = $left + $i * $slot + ( $slot - $bar_w ) / 2;
					$y     = $top + $plot_h - $h;
					$stamp = (int) strtotime( $day . ' 12:00:00' );
					$label = sprintf(
						/* translators: 1: date, 2: revenue, 3: number of orders. */
						_n( '%1$s: %2$s (%3$d order)', '%1$s: %2$s (%3$d orders)', $data['orders'], 'wahou-commerce' ),
						wp_date( (string) get_option( 'date_format' ), $stamp ),
						Money::format( $data['revenue'] ),
						$data['orders']
					);
					?>
					<g class="wahou-ap-chart__bar<?php echo $day === $today ? ' is-today' : ''; ?>">
						<rect x="<?php echo esc_attr( (string) round( $x, 1 ) ); ?>" y="<?php echo esc_attr( (string) round( $top, 1 ) ); ?>" width="<?php echo esc_attr( (string) round( $bar_w, 1 ) ); ?>" height="<?php echo esc_attr( (string) round( $plot_h, 1 ) ); ?>" class="wahou-ap-chart__hit"><title><?php echo esc_html( $label ); ?></title></rect>
						<?php if ( $h > 0 ) : ?>
							<rect x="<?php echo esc_attr( (string) round( $x, 1 ) ); ?>" y="<?php echo esc_attr( (string) round( $y, 1 ) ); ?>" width="<?php echo esc_attr( (string) round( $bar_w, 1 ) ); ?>" height="<?php echo esc_attr( (string) round( $h, 1 ) ); ?>" rx="3" class="wahou-ap-chart__fill"><title><?php echo esc_html( $label ); ?></title></rect>
						<?php endif; ?>
					</g>
					<?php if ( 0 === $i % 5 || $i === $count - 1 ) : ?>
						<text x="<?php echo esc_attr( (string) round( $x + $bar_w / 2, 1 ) ); ?>" y="<?php echo (int) ( $height - 8 ); ?>" text-anchor="middle" class="wahou-ap-chart__label"><?php echo esc_html( (string) wp_date( 'd/m', $stamp ) ); ?></text>
					<?php endif; ?>
					<?php
					++$i;
				endforeach;
				?>
			</svg>
			<figcaption class="wahou-ap-sub"><?php echo esc_html( $summary ); ?></figcaption>
		</figure>
		<?php
	}

	private static function render_recent_orders(): void {
		$recent = Order::query( array( 'limit' => 8 ) )['orders'];
		if ( empty( $recent ) ) {
			echo '<p class="wahou-ap-empty">' . esc_html__( 'Your first orders will appear here.', 'wahou-commerce' ) . '</p>';
			return;
		}
		echo '<ul class="wahou-ap-orders">';
		foreach ( $recent as $order ) {
			$ts = (int) strtotime( $order->created_at() . ' UTC' );
			printf(
				'<li><a href="%1$s"><strong>#%2$s</strong> <span class="wahou-ap-truncate">%3$s</span></a> %4$s <span class="wahou-ap-orders__total">%5$s</span> <span class="wahou-ap-sub">%6$s</span></li>',
				esc_url( $order->admin_url() ),
				esc_html( $order->number() ),
				esc_html( $order->customer_name() ?: $order->email() ),
				OrdersPage::status_pill( $order->status() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( Money::format( $order->total(), $order->currency() ) ),
				/* translators: %s: human time difference. */
				esc_html( sprintf( __( '%s ago', 'wahou-commerce' ), human_time_diff( $ts ) ) )
			);
		}
		echo '</ul>';
	}

	private static function render_top_products(): void {
		$from = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' );
		$top  = OrdersPage::top_products( $from, 5 );
		if ( empty( $top ) ) {
			echo '<p class="wahou-ap-empty">' . esc_html__( 'No sales in the last 30 days yet.', 'wahou-commerce' ) . '</p>';
			return;
		}
		$max = (int) max( array_merge( array( 1 ), array_column( $top, 'revenue' ) ) );
		echo '<ol class="wahou-ap-top">';
		foreach ( $top as $row ) {
			$link = $row['product_id'] && get_post( $row['product_id'] ) ? get_edit_post_link( $row['product_id'] ) : '';
			$name = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $row['name'] ) . '</a>' : esc_html( $row['name'] );
			printf(
				'<li><div class="wahou-ap-top__row"><span class="wahou-ap-truncate">%1$s</span><strong>%2$s</strong></div><div class="wahou-ap-top__bar"><span style="width:%3$s%%"></span></div><span class="wahou-ap-sub">%4$s</span></li>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( Money::format( $row['revenue'] ) ),
				esc_attr( (string) round( $row['revenue'] / $max * 100 ) ),
				/* translators: %d: units sold. */
				esc_html( sprintf( _n( '%d sold', '%d sold', $row['qty'], 'wahou-commerce' ), $row['qty'] ) )
			);
		}
		echo '</ol>';
	}

	private static function render_attention(): void {
		$to_ship = OrdersPage::count( 'processing' );
		$on_hold = OrdersPage::count( 'on-hold' );
		$low     = current_user_can( 'edit_wahou_products' ) ? self::low_stock_ids() : array();
		$orders  = admin_url( 'admin.php?page=' . OrdersPage::SLUG );
		?>
		<section class="wahou-ap-card">
			<div class="wahou-ap-card__head"><h2><?php esc_html_e( 'To do', 'wahou-commerce' ); ?></h2></div>
			<?php if ( ! $to_ship && ! $on_hold && ! $low ) : ?>
				<p class="wahou-ap-empty"><?php esc_html_e( 'All clear. Nothing waiting for you.', 'wahou-commerce' ); ?></p>
			<?php else : ?>
				<ul class="wahou-ap-todo">
					<?php if ( $to_ship ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( 'status', 'processing', $orders ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $to_ship ) ); ?></strong> <?php echo esc_html( _n( 'order to fulfil', 'orders to fulfil', $to_ship, 'wahou-commerce' ) ); ?></a></li>
					<?php endif; ?>
					<?php if ( $on_hold ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( 'status', 'on-hold', $orders ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $on_hold ) ); ?></strong> <?php echo esc_html( _n( 'order awaiting payment confirmation', 'orders awaiting payment confirmation', $on_hold, 'wahou-commerce' ) ); ?></a></li>
					<?php endif; ?>
					<?php if ( $low ) : ?>
						<li>
							<strong><?php echo esc_html( number_format_i18n( count( $low ) ) ); ?></strong>
							<?php echo esc_html( _n( 'product low in stock:', 'products low in stock:', count( $low ), 'wahou-commerce' ) ); ?>
							<?php
							$links = array();
							foreach ( array_slice( $low, 0, 5 ) as $id ) {
								$links[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $id ) ) . '">' . esc_html( get_the_title( (int) $id ) ) . '</a>';
							}
							echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}
}
