<?php
/**
 * Orders screen: list, detail view, actions and accounting CSV export.
 *
 * Every state-changing action goes through admin-post.php with a nonce and a
 * capability check, then redirects back with a flash notice (PRG pattern).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Downloads\Downloads;
use WahouCommerce\Emails\Emails;
use WahouCommerce\Gateways\Gateways;
use WahouCommerce\Install;
use WahouCommerce\Orders\Order;
use WahouCommerce\Orders\Orders;
use WahouCommerce\Settings;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

final class OrdersPage {

	public const SLUG = 'wahou-orders';

	private static ?OrdersListTable $table = null;

	public static function init(): void {
		foreach ( array( 'status', 'mark_paid', 'tracking', 'refund', 'note', 'resend' ) as $action ) {
			add_action( 'admin_post_wahou_order_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_action( 'admin_post_wahou_export_orders', array( self::class, 'export_csv' ) );
		add_filter( 'set_screen_option_wahou_orders_per_page', array( self::class, 'save_per_page' ), 10, 3 );
		add_action( 'wahou_order_status_changed', array( self::class, 'flush_counts' ) );
		add_action( 'wahou_order_created', array( self::class, 'flush_counts' ) );
	}

	/* ------------------------------------------------------------------
	 * Counts & helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Number of orders per status (cached 10 minutes, flushed on changes).
	 *
	 * @return array<string, int>
	 */
	public static function status_counts(): array {
		global $wpdb;
		$cached = get_transient( 'wahou_order_counts' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = array_fill_keys( Order::STATUSES, 0 );
		$table  = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		set_transient( 'wahou_order_counts', $counts, 10 * MINUTE_IN_SECONDS );
		return $counts;
	}

	public static function count( string $status ): int {
		return (int) ( self::status_counts()[ $status ] ?? 0 );
	}

	public static function flush_counts(): void {
		delete_transient( 'wahou_order_counts' );
	}

	/**
	 * @param mixed  $screen_option
	 * @param string $option
	 * @param mixed  $value
	 * @return mixed
	 */
	public static function save_per_page( $screen_option, $option, $value ) {
		return 'wahou_orders_per_page' === $option ? max( 5, min( 200, (int) $value ) ) : $screen_option;
	}

	/**
	 * Months (YYYY-MM) that contain orders, newest first.
	 *
	 * @return string[]
	 */
	public static function months(): array {
		global $wpdb;
		$table = Install::table( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT SUBSTR(created_at, 1, 7) AS ym FROM {$table} ORDER BY ym DESC LIMIT 36" );
		return array_values( array_filter( array_map( 'strval', (array) $rows ), static fn( $m ) => (bool) preg_match( '/^\d{4}-\d{2}$/', $m ) ) );
	}

	/**
	 * GMT boundaries of a local calendar month.
	 *
	 * @return array{0:string, 1:string}
	 */
	public static function month_range( string $ym ): array {
		$tz    = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m', $ym, $tz );
		if ( ! $start ) {
			$start = new \DateTimeImmutable( 'first day of this month 00:00', $tz );
		}
		$end = $start->modify( 'first day of next month' )->modify( '-1 second' );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	public static function status_pill( string $status ): string {
		return sprintf( '<span class="wahou-ap-pill wahou-ap-pill--%1$s">%2$s</span>', esc_attr( $status ), esc_html( Order::label( $status ) ) );
	}

	public static function tax_mode_label( string $mode ): string {
		$labels = array(
			'domestic'       => __( 'Domestic VAT', 'wahou-commerce' ),
			'origin'         => __( 'EU sale – VAT of your country', 'wahou-commerce' ),
			'oss'            => __( 'EU sale – OSS (customer country VAT)', 'wahou-commerce' ),
			'reverse_charge' => __( 'B2B reverse charge (0 %)', 'wahou-commerce' ),
			'export'         => __( 'Outside the EU VAT area (0 %)', 'wahou-commerce' ),
			'exempt'         => __( 'VAT exempt (franchise)', 'wahou-commerce' ),
			'disabled'       => __( 'Tax disabled', 'wahou-commerce' ),
		);
		return $labels[ $mode ] ?? ( '' !== $mode ? $mode : '—' );
	}

	public static function rate_label( float $rate ): string {
		$value = rtrim( rtrim( number_format_i18n( $rate, 3 ), '0' ), '.,' );
		return $value . ' %';
	}

	/**
	 * Carriers with a tracking URL template ({n} = tracking number).
	 *
	 * @return array<string, array{name:string, url:string}>
	 */
	public static function carriers(): array {
		$carriers = array(
			'colissimo'    => array( 'name' => 'Colissimo / La Poste', 'url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={n}' ),
			'chronopost'   => array( 'name' => 'Chronopost', 'url' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={n}' ),
			'mondialrelay' => array( 'name' => 'Mondial Relay', 'url' => 'https://www.mondialrelay.fr/suivi-de-colis/?NumeroExpedition={n}' ),
			'correos'      => array( 'name' => 'Correos', 'url' => 'https://www.correos.es/es/es/herramientas/localizador/envios/detalle?tracking-number={n}' ),
			'seur'         => array( 'name' => 'SEUR', 'url' => 'https://www.seur.com/livetracking/?segOnlineIdentificador={n}' ),
			'mrw'          => array( 'name' => 'MRW', 'url' => 'https://www.mrw.es/seguimiento_envios/MRW_resultados_consultas.asp?modo=nacional&envio={n}' ),
			'ctt'          => array( 'name' => 'CTT', 'url' => 'https://www.ctt.pt/feapl_2/app/open/objectSearch/objectSearch.jspx?objects={n}' ),
			'dhl'          => array( 'name' => 'DHL', 'url' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={n}' ),
			'dpd'          => array( 'name' => 'DPD', 'url' => 'https://tracking.dpd.de/status/en_US/parcel/{n}' ),
			'gls'          => array( 'name' => 'GLS', 'url' => 'https://gls-group.eu/track/{n}' ),
			'ups'          => array( 'name' => 'UPS', 'url' => 'https://www.ups.com/track?tracknum={n}' ),
			'fedex'        => array( 'name' => 'FedEx', 'url' => 'https://www.fedex.com/fedextrack/?trknbr={n}' ),
			'other'        => array( 'name' => __( 'Other carrier', 'wahou-commerce' ), 'url' => '' ),
		);
		/**
		 * Filters the carriers offered in the tracking form.
		 */
		return (array) apply_filters( 'wahou_admin_carriers', $carriers );
	}

	/**
	 * Formatted postal address (escaped HTML).
	 *
	 * @param array<string, string> $address
	 */
	public static function address_html( array $address ): string {
		$lines = array();
		$name  = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
		foreach ( array( $name, $address['company'] ?? '', $address['address_1'] ?? '', $address['address_2'] ?? '', trim( ( $address['postcode'] ?? '' ) . ' ' . ( $address['city'] ?? '' ) ), $address['state'] ?? '' ) as $line ) {
			if ( '' !== trim( (string) $line ) ) {
				$lines[] = esc_html( (string) $line );
			}
		}
		if ( ! empty( $address['country'] ) ) {
			$lines[] = esc_html( Countries::name( (string) $address['country'] ) );
		}
		return $lines ? implode( '<br>', $lines ) : '<span class="wahou-ap-sub">—</span>';
	}

	/**
	 * Link to the payment in the provider dashboard (escaped HTML).
	 */
	public static function transaction_html( Order $order ): string {
		$txn = $order->transaction_id();
		if ( '' === $txn ) {
			return '<span class="wahou-ap-sub">—</span>';
		}
		$test = null;
		foreach ( array( 'test_mode', 'testmode' ) as $key ) {
			if ( null !== $order->get_meta( $key ) ) {
				$test = (bool) $order->get_meta( $key );
			}
		}
		if ( null !== $order->get_meta( 'livemode' ) ) {
			$test = ! $order->get_meta( 'livemode' );
		}
		$url = '';
		switch ( $order->payment_method() ) {
			case 'stripe':
				$mode = (string) $order->get_meta( 'stripe_mode', '' );
				$test = null !== $test ? $test : ( '' !== $mode ? 'test' === $mode : 'test' === Settings::str( 'stripe_mode' ) );
				if ( preg_match( '/^(pi|ch|py)_[A-Za-z0-9]+$/', $txn ) ) {
					$url = 'https://dashboard.stripe.com/' . ( $test ? 'test/' : '' ) . 'payments/' . rawurlencode( $txn );
				}
				break;
			case 'paypal':
				$mode = (string) $order->get_meta( 'paypal_mode', '' );
				$test = null !== $test ? $test : ( '' !== $mode ? 'live' !== $mode : 'sandbox' === Settings::str( 'paypal_mode' ) );
				if ( preg_match( '/^[A-Z0-9]{8,30}$/', $txn ) ) {
					$url = 'https://www.' . ( $test ? 'sandbox.' : '' ) . 'paypal.com/activity/payment/' . rawurlencode( $txn );
				}
				break;
		}
		$code = '<code class="wahou-ap-copy" data-copy="' . esc_attr( $txn ) . '">' . esc_html( $txn ) . '</code>';
		if ( '' === $url ) {
			return $code;
		}
		return $code . ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in dashboard', 'wahou-commerce' ) . ' <span aria-hidden="true">↗</span></a>';
	}

	private static function table(): OrdersListTable {
		if ( null === self::$table ) {
			self::$table = new OrdersListTable();
		}
		return self::$table;
	}

	private static function detail_url( Order $order ): string {
		return $order->admin_url();
	}

	private static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/* ------------------------------------------------------------------
	 * Screen.
	 * ------------------------------------------------------------------ */

	/**
	 * load-{hook}: screen options and bulk actions.
	 */
	public static function load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Orders per page', 'wahou-commerce' ),
				'default' => 20,
				'option'  => 'wahou_orders_per_page',
			)
		);

		$table  = self::table();
		$action = $table->current_action();
		if ( $action && in_array( $action, OrdersListTable::BULK, true ) ) {
			check_admin_referer( 'bulk-orders' );
			if ( ! current_user_can( 'manage_wahou_orders' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wahou-commerce' ), 403 );
			}
			$ids     = isset( $_REQUEST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['order_ids'] ) ) : array();
			$status  = substr( $action, 5 );
			$updated = 0;
			$skipped = 0;
			foreach ( $ids as $id ) {
				$order = Order::find( $id );
				if ( ! $order || $order->status() === $status ) {
					continue;
				}
				if ( in_array( $status, array( 'processing', 'completed' ), true ) && ! $order->is_paid() ) {
					++$skipped;
					continue;
				}
				Orders::set_status( $order, $status, __( 'Bulk edit.', 'wahou-commerce' ) );
				++$updated;
			}
			/* translators: %d: number of orders. */
			Admin::flash( sprintf( _n( '%d order updated.', '%d orders updated.', $updated, 'wahou-commerce' ), $updated ) );
			if ( $skipped ) {
				/* translators: %d: number of orders. */
				Admin::flash( sprintf( _n( '%d unpaid order was skipped: open it and use "Mark as paid" once the payment is received.', '%d unpaid orders were skipped: open them and use "Mark as paid" once the payment is received.', $skipped, 'wahou-commerce' ), $skipped ), 'warning' );
			}
			$back = remove_query_arg( array( 'action', 'action2', 'order_ids', '_wpnonce', '_wp_http_referer', 'filter_action' ), wp_get_referer() ? wp_get_referer() : self::list_url() );
			Admin::back( $back );
		}

		// Clean URLs after a GET filter submission, like core list screens.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			Admin::back( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_wahou_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wahou-commerce' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$id = isset( $_GET['order'] ) && is_numeric( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		if ( $id ) {
			$order = Order::find( $id );
			if ( $order ) {
				self::render_detail( $order );
				return;
			}
			Admin::flash( __( 'Order not found.', 'wahou-commerce' ), 'error' );
		}
		self::render_list();
	}

	private static function render_list(): void {
		$table = self::table();
		$table->prepare_items();
		$filters = OrdersListTable::filters();
		?>
		<div class="wrap wahou-ap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Orders', 'wahou-commerce' ); ?></h1>
			<?php if ( '' !== $filters['search'] ) : ?>
				<span class="subtitle">
					<?php
					/* translators: %s: search terms. */
					printf( esc_html__( 'Search results for: %s', 'wahou-commerce' ), '<strong>' . esc_html( $filters['search'] ) . '</strong>' );
					?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php Admin::print_flash(); ?>
			<?php $table->views(); ?>
			<form method="get" class="wahou-ap-orders-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( '' !== $filters['status'] ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>">
				<?php endif; ?>
				<?php $table->search_box( __( 'Search orders', 'wahou-commerce' ), 'wahou-orders' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Detail view.
	 * ------------------------------------------------------------------ */

	private static function render_detail( Order $order ): void {
		$currency = $order->currency();
		$fmt      = static fn( int $amount ): string => Money::format( $amount, $currency );
		$gateway  = Gateways::get( $order->payment_method() );
		$nonce    = 'wahou_order_' . $order->id();
		$paid_ts  = $order->paid_at() ? (int) strtotime( $order->paid_at() . ' UTC' ) : 0;
		$created  = (int) strtotime( $order->created_at() . ' UTC' );
		$datetime = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$billing  = $order->billing();
		$shipping = $order->shipping_address();
		?>
		<div class="wrap wahou-ap wahou-ap-order">
			<p class="wahou-ap-back"><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'All orders', 'wahou-commerce' ); ?></a></p>
			<div class="wahou-ap-order__head">
				<div>
					<h1 class="wahou-ap-title">
						<?php
						/* translators: %s: order number. */
						echo esc_html( sprintf( __( 'Order #%s', 'wahou-commerce' ), $order->number() ) );
						?>
						<?php echo self::status_pill( $order->status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</h1>
					<p class="wahou-ap-sub">
						<?php
						/* translators: 1: date, 2: payment method. */
						echo esc_html( sprintf( __( 'Placed on %1$s · %2$s', 'wahou-commerce' ), (string) wp_date( $datetime, $created ), $order->payment_method_title() ?: '—' ) );
						?>
					</p>
				</div>
				<div class="wahou-ap-order__actions">
					<?php if ( ! $order->is_paid() && in_array( $order->status(), array( 'pending', 'on-hold', 'failed' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( $nonce ); ?>
							<input type="hidden" name="action" value="wahou_order_mark_paid">
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
							<button type="submit" class="button button-primary" data-wahou-confirm="<?php esc_attr_e( 'Confirm that you received the full payment for this order? Stock, invoice, downloads and confirmation email will be processed.', 'wahou-commerce' ); ?>"><?php esc_html_e( 'Mark as paid', 'wahou-commerce' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( '' !== $order->invoice_number() ) : ?>
						<a class="button" href="<?php echo esc_url( $order->invoice_url() ); ?>" target="_blank" rel="noopener">
							<?php
							/* translators: %s: invoice number. */
							echo esc_html( sprintf( __( 'Invoice %s', 'wahou-commerce' ), $order->invoice_number() ) );
							?>
						</a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( $order->received_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Customer view', 'wahou-commerce' ); ?></a>
				</div>
			</div>
			<hr class="wp-header-end">
			<?php Admin::print_flash(); ?>

			<div class="wahou-ap-order__grid">
				<div class="wahou-ap-order__main">
					<section class="wahou-ap-box">
						<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Items', 'wahou-commerce' ); ?></h2>
						<div class="wahou-ap-table-wrap">
							<table class="wahou-ap-items">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Product', 'wahou-commerce' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Qty', 'wahou-commerce' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Unit price', 'wahou-commerce' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'VAT', 'wahou-commerce' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $order->items() as $item ) : ?>
									<tr>
										<td>
											<?php
											$edit = $item['product_id'] && get_post( (int) $item['product_id'] ) ? get_edit_post_link( (int) $item['product_id'] ) : '';
											echo $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $item['name'] ) . '</strong></a>' : '<strong>' . esc_html( $item['name'] ) . '</strong>';
											if ( ! empty( $item['meta']['variant_label'] ) ) {
												echo '<div class="wahou-ap-sub">' . esc_html( (string) $item['meta']['variant_label'] ) . '</div>';
											}
											$tags = array();
											if ( '' !== $item['sku'] ) {
												/* translators: %s: SKU. */
												$tags[] = esc_html( sprintf( __( 'SKU %s', 'wahou-commerce' ), $item['sku'] ) );
											}
											if ( 'digital' === $item['kind'] ) {
												$tags[] = '<span class="wahou-ap-tag">' . esc_html__( 'Digital', 'wahou-commerce' ) . '</span>';
											}
											if ( 'bump' === $item['item_type'] ) {
												$tags[] = '<span class="wahou-ap-tag wahou-ap-tag--accent">' . esc_html__( 'Order bump', 'wahou-commerce' ) . '</span>';
											}
											if ( ! empty( $item['meta']['offer'] ) ) {
												$tags[] = '<span class="wahou-ap-tag wahou-ap-tag--accent">' . esc_html__( 'Post-purchase offer', 'wahou-commerce' ) . '</span>';
											}
											if ( ! empty( $item['meta']['fbt_parent'] ) ) {
												$tags[] = '<span class="wahou-ap-tag">' . esc_html__( 'Bought together', 'wahou-commerce' ) . '</span>';
											}
											if ( $tags ) {
												echo '<div class="wahou-ap-sub">' . implode( ' ', $tags ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											}
											if ( ! empty( $item['meta']['bundle'] ) && is_array( $item['meta']['bundle'] ) ) {
												echo '<ul class="wahou-ap-bundle">';
												foreach ( $item['meta']['bundle'] as $component ) {
													echo '<li>' . esc_html( (int) $component['qty'] . ' × ' . (string) $component['name'] ) . '</li>';
												}
												echo '</ul>';
											}
											?>
										</td>
										<td class="num"><?php echo esc_html( (string) $item['quantity'] ); ?></td>
										<td class="num"><?php echo esc_html( $fmt( (int) $item['unit_price'] ) ); ?></td>
										<td class="num">
											<?php echo esc_html( self::rate_label( (float) $item['tax_rate'] ) ); ?>
											<div class="wahou-ap-sub"><?php echo esc_html( $fmt( (int) $item['tax'] ) ); ?></div>
										</td>
										<td class="num">
											<strong><?php echo esc_html( $fmt( (int) $item['total'] ) ); ?></strong>
											<?php if ( (int) $item['discount'] > 0 ) : ?>
												<div class="wahou-ap-sub">−<?php echo esc_html( $fmt( (int) $item['discount'] ) ); ?></div>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<dl class="wahou-ap-totals">
							<div><dt><?php esc_html_e( 'Subtotal', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $fmt( $order->subtotal() ) ); ?></dd></div>
							<?php if ( $order->discount_total() > 0 ) : ?>
								<div>
									<dt>
										<?php esc_html_e( 'Discount', 'wahou-commerce' ); ?>
										<?php if ( '' !== $order->coupon_code() ) : ?>
											<code><?php echo esc_html( $order->coupon_code() ); ?></code>
										<?php endif; ?>
									</dt>
									<dd>−<?php echo esc_html( $fmt( $order->discount_total() ) ); ?></dd>
								</div>
							<?php endif; ?>
							<?php
							$ship_line = $order->shipping_line();
							if ( $ship_line || $order->shipping_total() > 0 ) :
								?>
								<div>
									<dt><?php echo esc_html( $ship_line && ! empty( $ship_line['title'] ) ? (string) $ship_line['title'] : __( 'Shipping', 'wahou-commerce' ) ); ?></dt>
									<dd><?php echo esc_html( $order->shipping_total() > 0 ? $fmt( $order->shipping_total() ) : __( 'Free', 'wahou-commerce' ) ); ?></dd>
								</div>
							<?php endif; ?>
							<div class="wahou-ap-totals__grand"><dt><?php esc_html_e( 'Total', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $fmt( $order->total() ) ); ?></dd></div>
							<?php foreach ( $order->tax_lines() as $line ) : ?>
								<?php
								if ( (int) $line['tax'] <= 0 && (float) $line['rate'] <= 0 ) {
									continue;
								}
								?>
								<div class="wahou-ap-totals__tax">
									<dt>
										<?php
										/* translators: 1: VAT rate, 2: taxable base. */
										echo esc_html( sprintf( __( 'incl. VAT %1$s on %2$s', 'wahou-commerce' ), self::rate_label( (float) $line['rate'] ), $fmt( (int) $line['net'] ) ) );
										?>
									</dt>
									<dd><?php echo esc_html( $fmt( (int) $line['tax'] ) ); ?></dd>
								</div>
							<?php endforeach; ?>
							<?php if ( $order->refunded_total() > 0 ) : ?>
								<div class="wahou-ap-totals__refund"><dt><?php esc_html_e( 'Refunded', 'wahou-commerce' ); ?></dt><dd>−<?php echo esc_html( $fmt( $order->refunded_total() ) ); ?></dd></div>
								<div class="wahou-ap-totals__grand"><dt><?php esc_html_e( 'Net payment', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $fmt( $order->total() - $order->refunded_total() ) ); ?></dd></div>
							<?php endif; ?>
						</dl>
						<?php
						$mention = class_exists( '\WahouCommerce\Tax\Tax' ) ? \WahouCommerce\Tax\Tax::invoice_mention( $order->tax_mode(), $order->needs_shipping() ) : '';
						if ( '' !== $mention ) :
							?>
							<p class="wahou-ap-sub wahou-ap-mention"><?php echo esc_html( $mention ); ?></p>
						<?php endif; ?>
					</section>

					<?php self::render_refunds( $order, $nonce, $fmt ); ?>
					<?php self::render_notes( $order, $nonce ); ?>
				</div>

				<aside class="wahou-ap-order__side">
					<section class="wahou-ap-box">
						<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Status', 'wahou-commerce' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wahou-ap-form">
							<?php wp_nonce_field( $nonce ); ?>
							<input type="hidden" name="action" value="wahou_order_status">
							<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
							<label for="wahou-order-status" class="screen-reader-text"><?php esc_html_e( 'Order status', 'wahou-commerce' ); ?></label>
							<select name="status" id="wahou-order-status">
								<?php foreach ( Order::STATUSES as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $order->status(), $status ); ?>><?php echo esc_html( Order::label( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<label for="wahou-status-note"><?php esc_html_e( 'Internal note (optional)', 'wahou-commerce' ); ?></label>
							<textarea name="note" id="wahou-status-note" rows="2"></textarea>
							<button type="submit" class="button"><?php esc_html_e( 'Update status', 'wahou-commerce' ); ?></button>
							<p class="wahou-ap-help"><?php esc_html_e( 'Setting an unpaid order to processing or completed records the payment. To give money back, use the refund form.', 'wahou-commerce' ); ?></p>
						</form>
						<dl class="wahou-ap-meta">
							<div><dt><?php esc_html_e( 'Payment', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $order->payment_method_title() ?: '—' ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Transaction', 'wahou-commerce' ); ?></dt><dd><?php echo self::transaction_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd></div>
							<div><dt><?php esc_html_e( 'Paid on', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $paid_ts ? (string) wp_date( $datetime, $paid_ts ) : '—' ); ?></dd></div>
							<?php if ( $order->completed_at() ) : ?>
								<div><dt><?php esc_html_e( 'Completed on', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( (string) wp_date( $datetime, (int) strtotime( $order->completed_at() . ' UTC' ) ) ); ?></dd></div>
							<?php endif; ?>
						</dl>
					</section>

					<?php self::render_customer( $order, $billing, $shipping ); ?>
					<?php
					if ( $order->needs_shipping() ) {
						self::render_tracking( $order, $nonce );
					}
					?>
					<?php self::render_emails( $order, $nonce, $gateway ); ?>
					<?php self::render_documents( $order ); ?>
					<?php self::render_linked( $order, $fmt ); ?>
					<?php self::render_compliance( $order ); ?>
					<?php self::render_technical( $order ); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * @param callable(int):string $fmt
	 */
	private static function render_refunds( Order $order, string $nonce, callable $fmt ): void {
		$refunds   = (array) $order->get_meta( 'refunds', array() );
		$remaining = $order->total() - $order->refunded_total();
		if ( ! $order->is_paid() && empty( $refunds ) ) {
			return;
		}
		$gateway = Gateways::get( $order->payment_method() );
		$auto    = $gateway && $gateway->supports( 'refunds' );
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Refunds', 'wahou-commerce' ); ?></h2>
			<?php if ( $refunds ) : ?>
				<ul class="wahou-ap-refund-list">
					<?php foreach ( $refunds as $refund ) : ?>
						<li>
							<strong><?php echo esc_html( $fmt( (int) ( $refund['amount'] ?? 0 ) ) ); ?></strong>
							<span class="wahou-ap-sub">
								<?php echo esc_html( ! empty( $refund['date'] ) ? wahou_date( (string) $refund['date'] ) : '' ); ?>
								<?php if ( ! empty( $refund['credit_note'] ) ) : ?>
									·
									<?php
									/* translators: %s: credit note number. */
									echo esc_html( sprintf( __( 'Credit note %s', 'wahou-commerce' ), (string) $refund['credit_note'] ) );
									?>
								<?php endif; ?>
								<?php if ( ! empty( $refund['reference'] ) ) : ?>
									· <code><?php echo esc_html( (string) $refund['reference'] ); ?></code>
								<?php endif; ?>
							</span>
							<?php if ( ! empty( $refund['reason'] ) ) : ?>
								<div><?php echo esc_html( (string) $refund['reason'] ); ?></div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $order->is_paid() && $remaining > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wahou-ap-form wahou-ap-refund" data-wahou-refund>
					<?php wp_nonce_field( $nonce ); ?>
					<input type="hidden" name="action" value="wahou_order_refund">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
					<div class="wahou-ap-row">
						<p>
							<label for="wahou-refund-amount"><?php esc_html_e( 'Amount', 'wahou-commerce' ); ?></label>
							<input type="text" inputmode="decimal" name="amount" id="wahou-refund-amount" value="<?php echo esc_attr( Money::to_input( $remaining, $order->currency() ) ); ?>" data-currency="<?php echo esc_attr( $order->currency() ); ?>" required>
							<span class="wahou-ap-sub">
								<?php
								/* translators: %s: maximum refundable amount. */
								echo esc_html( sprintf( __( 'Up to %s', 'wahou-commerce' ), $fmt( $remaining ) ) );
								?>
							</span>
						</p>
						<p>
							<label for="wahou-refund-reason"><?php esc_html_e( 'Reason (optional)', 'wahou-commerce' ); ?></label>
							<input type="text" name="reason" id="wahou-refund-reason" maxlength="200">
						</p>
					</div>
					<p>
						<label><input type="checkbox" name="restock" value="1" <?php checked( $order->needs_shipping() ); ?>> <?php esc_html_e( 'Put the items back in stock', 'wahou-commerce' ); ?></label>
					</p>
					<p class="wahou-ap-help">
						<?php
						echo esc_html(
							$auto
								/* translators: %s: payment method name. */
								? sprintf( __( 'The money is sent back automatically through %s and a credit note is issued.', 'wahou-commerce' ), $order->payment_method_title() )
								: __( 'This payment method cannot be refunded automatically: send the money yourself, then record the refund here (a credit note is issued).', 'wahou-commerce' )
						);
						?>
					</p>
					<button type="submit" class="button"><?php echo esc_html( $auto ? __( 'Refund', 'wahou-commerce' ) : __( 'Record refund', 'wahou-commerce' ) ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_notes( Order $order, string $nonce ): void {
		$notes = $order->notes();
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Notes & history', 'wahou-commerce' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wahou-ap-form">
				<?php wp_nonce_field( $nonce ); ?>
				<input type="hidden" name="action" value="wahou_order_note">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
				<label for="wahou-note" class="screen-reader-text"><?php esc_html_e( 'Add a note', 'wahou-commerce' ); ?></label>
				<textarea name="note" id="wahou-note" rows="3" placeholder="<?php esc_attr_e( 'Add a note…', 'wahou-commerce' ); ?>" required></textarea>
				<div class="wahou-ap-row wahou-ap-row--center">
					<label><input type="checkbox" name="customer_note" value="1"> <?php esc_html_e( 'Send to the customer by email', 'wahou-commerce' ); ?></label>
					<button type="submit" class="button"><?php esc_html_e( 'Add note', 'wahou-commerce' ); ?></button>
				</div>
			</form>
			<?php if ( $notes ) : ?>
				<ol class="wahou-ap-timeline">
					<?php foreach ( $notes as $note ) : ?>
						<li class="<?php echo ! empty( $note['is_customer'] ) ? 'is-customer' : ''; ?>">
							<div class="wahou-ap-timeline__body"><?php echo wp_kses_post( wpautop( (string) $note['note'] ) ); ?></div>
							<div class="wahou-ap-sub">
								<?php echo esc_html( wahou_date( (string) $note['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
								· <?php echo esc_html( (string) $note['author'] ); ?>
								<?php if ( ! empty( $note['is_customer'] ) ) : ?>
									· <span class="wahou-ap-tag wahou-ap-tag--accent"><?php esc_html_e( 'Sent to customer', 'wahou-commerce' ); ?></span>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<string, string> $billing
	 * @param array<string, string> $shipping
	 */
	private static function render_customer( Order $order, array $billing, array $shipping ): void {
		$others = Order::query(
			array(
				'email' => $order->email(),
				'limit' => 1,
			)
		);
		$phone  = (string) ( $shipping['phone'] ?? $billing['phone'] ?? '' );
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Customer', 'wahou-commerce' ); ?></h2>
			<p>
				<strong><?php echo esc_html( $order->customer_name() ?: $order->email() ); ?></strong><br>
				<a href="mailto:<?php echo esc_attr( $order->email() ); ?>"><?php echo esc_html( $order->email() ); ?></a>
				<?php if ( '' !== $phone ) : ?>
					<br><a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a>
				<?php endif; ?>
			</p>
			<p class="wahou-ap-sub">
				<?php if ( $order->customer_id() && get_userdata( $order->customer_id() ) ) : ?>
					<a href="<?php echo esc_url( get_edit_user_link( $order->customer_id() ) ); ?>"><?php esc_html_e( 'Customer account', 'wahou-commerce' ); ?></a> ·
				<?php else : ?>
					<?php esc_html_e( 'Guest', 'wahou-commerce' ); ?> ·
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( 's', rawurlencode( $order->email() ), self::list_url() ) ); ?>">
					<?php
					/* translators: %d: number of orders. */
					echo esc_html( sprintf( _n( '%d order in total', '%d orders in total', $others['total'], 'wahou-commerce' ), $others['total'] ) );
					?>
				</a>
			</p>
			<div class="wahou-ap-addresses">
				<div>
					<h3><?php esc_html_e( 'Billing', 'wahou-commerce' ); ?></h3>
					<address><?php echo self::address_html( $billing ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></address>
					<?php if ( ! empty( $billing['vat_number'] ) ) : ?>
						<p class="wahou-ap-sub">
							<?php
							/* translators: %s: VAT number. */
							echo esc_html( sprintf( __( 'VAT number: %s', 'wahou-commerce' ), $billing['vat_number'] ) );
							?>
						</p>
					<?php endif; ?>
				</div>
				<?php if ( $order->needs_shipping() && $shipping ) : ?>
					<div>
						<h3><?php esc_html_e( 'Shipping', 'wahou-commerce' ); ?></h3>
						<address><?php echo self::address_html( $shipping ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></address>
					</div>
				<?php endif; ?>
			</div>
			<?php $note = (string) $order->get_meta( 'customer_note', '' ); ?>
			<?php if ( '' !== $note ) : ?>
				<div class="wahou-ap-quote">
					<strong><?php esc_html_e( 'Customer note', 'wahou-commerce' ); ?></strong>
					<p><?php echo esc_html( $note ); ?></p>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_tracking( Order $order, string $nonce ): void {
		$tracking = (array) $order->get_meta( 'tracking', array() );
		$carriers = self::carriers();
		$current  = (string) ( $tracking['carrier_id'] ?? '' );
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Shipping & tracking', 'wahou-commerce' ); ?></h2>
			<?php if ( ! empty( $tracking['number'] ) ) : ?>
				<p class="wahou-ap-tracking">
					<strong><?php echo esc_html( (string) ( $tracking['carrier'] ?? '' ) ); ?></strong>
					<code class="wahou-ap-copy" data-copy="<?php echo esc_attr( (string) $tracking['number'] ); ?>"><?php echo esc_html( (string) $tracking['number'] ); ?></code>
					<?php if ( ! empty( $tracking['url'] ) ) : ?>
						<a href="<?php echo esc_url( (string) $tracking['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track', 'wahou-commerce' ); ?> <span aria-hidden="true">↗</span></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wahou-ap-form" data-wahou-tracking>
				<?php wp_nonce_field( $nonce ); ?>
				<input type="hidden" name="action" value="wahou_order_tracking">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
				<label for="wahou-carrier"><?php esc_html_e( 'Carrier', 'wahou-commerce' ); ?></label>
				<select name="carrier" id="wahou-carrier">
					<?php foreach ( $carriers as $key => $carrier ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $carrier['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="wahou-tracking-number"><?php esc_html_e( 'Tracking number', 'wahou-commerce' ); ?></label>
				<input type="text" name="tracking_number" id="wahou-tracking-number" value="<?php echo esc_attr( (string) ( $tracking['number'] ?? '' ) ); ?>" autocomplete="off">
				<label for="wahou-tracking-url"><?php esc_html_e( 'Tracking link', 'wahou-commerce' ); ?></label>
				<input type="url" name="tracking_url" id="wahou-tracking-url" value="<?php echo esc_attr( (string) ( $tracking['url'] ?? '' ) ); ?>" placeholder="https://">
				<div class="wahou-ap-row">
					<button type="submit" class="button"><?php esc_html_e( 'Save', 'wahou-commerce' ); ?></button>
					<?php if ( 'completed' !== $order->status() && $order->is_paid() ) : ?>
						<button type="submit" name="mark_shipped" value="1" class="button button-primary"><?php esc_html_e( 'Save & mark as shipped', 'wahou-commerce' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="wahou-ap-help"><?php esc_html_e( 'Marking as shipped completes the order and emails the tracking link to the customer.', 'wahou-commerce' ); ?></p>
			</form>
		</section>
		<?php
	}

	private static function render_emails( Order $order, string $nonce, ?\WahouCommerce\Gateways\Gateway $gateway ): void {
		$types = array();
		if ( $order->is_paid() ) {
			$types['order_confirmation'] = __( 'Order confirmation', 'wahou-commerce' );
		}
		if ( 'completed' === $order->status() && $order->needs_shipping() ) {
			$types['order_shipped'] = __( 'Shipping confirmation', 'wahou-commerce' );
		}
		if ( 'bank' === $order->payment_method() && ! $order->is_paid() ) {
			$types['bank_instructions'] = __( 'Bank transfer instructions', 'wahou-commerce' );
		}
		if ( empty( $types ) ) {
			return;
		}
		$available = class_exists( Emails::class ) && method_exists( Emails::class, 'resend' );
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Emails', 'wahou-commerce' ); ?></h2>
			<?php if ( ! $available ) : ?>
				<p class="wahou-ap-sub"><?php esc_html_e( 'The email module is not available.', 'wahou-commerce' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wahou-ap-form wahou-ap-row wahou-ap-row--center">
					<?php wp_nonce_field( $nonce ); ?>
					<input type="hidden" name="action" value="wahou_order_resend">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->id() ); ?>">
					<label for="wahou-email-type" class="screen-reader-text"><?php esc_html_e( 'Email to send', 'wahou-commerce' ); ?></label>
					<select name="email_type" id="wahou-email-type">
						<?php foreach ( $types as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Send again', 'wahou-commerce' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_documents( Order $order ): void {
		$downloads = array();
		if ( class_exists( Downloads::class ) && method_exists( Downloads::class, 'for_order' ) && $order->has_digital() ) {
			$downloads = (array) Downloads::for_order( $order );
		}
		$refunds = array_filter( (array) $order->get_meta( 'refunds', array() ), static fn( $r ) => ! empty( $r['credit_note'] ) );
		if ( '' === $order->invoice_number() && empty( $downloads ) && empty( $refunds ) ) {
			return;
		}
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Documents & downloads', 'wahou-commerce' ); ?></h2>
			<ul class="wahou-ap-list">
				<?php if ( '' !== $order->invoice_number() ) : ?>
					<li>
						<a href="<?php echo esc_url( $order->invoice_url() ); ?>" target="_blank" rel="noopener">
							<?php
							/* translators: %s: invoice number. */
							echo esc_html( sprintf( __( 'Invoice %s', 'wahou-commerce' ), $order->invoice_number() ) );
							?>
						</a>
					</li>
				<?php endif; ?>
				<?php foreach ( $refunds as $refund ) : ?>
					<li>
						<?php
						/* translators: %s: credit note number. */
						echo esc_html( sprintf( __( 'Credit note %s', 'wahou-commerce' ), (string) $refund['credit_note'] ) );
						?>
					</li>
				<?php endforeach; ?>
				<?php foreach ( $downloads as $download ) : ?>
					<li>
						<?php if ( ! empty( $download['url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $download['url'] ); ?>"><?php echo esc_html( (string) ( $download['name'] ?? '' ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) ( $download['name'] ?? '' ) ); ?>
						<?php endif; ?>
						<span class="wahou-ap-sub">
							<?php
							$bits = array();
							if ( isset( $download['remaining'] ) && null !== $download['remaining'] ) {
								/* translators: %d: remaining downloads. */
								$bits[] = sprintf( _n( '%d download left', '%d downloads left', (int) $download['remaining'], 'wahou-commerce' ), (int) $download['remaining'] );
							}
							if ( ! empty( $download['expires'] ) ) {
								/* translators: %s: expiry date. */
								$bits[] = sprintf( __( 'expires %s', 'wahou-commerce' ), (string) $download['expires'] );
							}
							echo esc_html( implode( ' · ', $bits ) );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * @param callable(int):string $fmt
	 */
	private static function render_linked( Order $order, callable $fmt ): void {
		$parent   = $order->parent_id() ? Order::find( $order->parent_id() ) : null;
		$children = Order::query(
			array(
				'parent_id' => $order->id(),
				'limit'     => 20,
				'order'     => 'ASC',
			)
		)['orders'];
		if ( ! $parent && empty( $children ) ) {
			return;
		}
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'Linked orders', 'wahou-commerce' ); ?></h2>
			<ul class="wahou-ap-list">
				<?php if ( $parent ) : ?>
					<li>
						<?php esc_html_e( 'Main order:', 'wahou-commerce' ); ?>
						<a href="<?php echo esc_url( $parent->admin_url() ); ?>">#<?php echo esc_html( $parent->number() ); ?></a>
						<?php echo self::status_pill( $parent->status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</li>
				<?php endif; ?>
				<?php foreach ( $children as $child ) : ?>
					<li>
						<?php esc_html_e( 'Post-purchase offer:', 'wahou-commerce' ); ?>
						<a href="<?php echo esc_url( $child->admin_url() ); ?>">#<?php echo esc_html( $child->number() ); ?></a>
						<?php echo esc_html( $fmt( $child->total() ) ); ?>
						<?php echo self::status_pill( $child->status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_compliance( Order $order ): void {
		$evidence = (array) $order->get_meta( 'tax_evidence', array() );
		$consents = (array) $order->get_meta( 'consents', array() );
		$labels   = array(
			'terms'          => __( 'Terms of sale accepted', 'wahou-commerce' ),
			'digital_waiver' => __( 'Immediate access requested, withdrawal right waived (digital content)', 'wahou-commerce' ),
			'marketing'      => __( 'Marketing emails accepted', 'wahou-commerce' ),
		);
		?>
		<section class="wahou-ap-box">
			<h2 class="wahou-ap-box__title"><?php esc_html_e( 'VAT & consents', 'wahou-commerce' ); ?></h2>
			<dl class="wahou-ap-meta">
				<div><dt><?php esc_html_e( 'VAT treatment', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( self::tax_mode_label( $order->tax_mode() ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Tax country', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( '' !== $order->tax_country() ? Countries::name( $order->tax_country() ) : '—' ); ?></dd></div>
				<?php if ( ! empty( $evidence['ip_country'] ) ) : ?>
					<div><dt><?php esc_html_e( 'IP country', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( Countries::name( (string) $evidence['ip_country'] ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! empty( $evidence['vat_number'] ) ) : ?>
					<div>
						<dt><?php esc_html_e( 'VIES check', 'wahou-commerce' ); ?></dt>
						<dd>
							<?php echo ! empty( $evidence['vat_valid'] ) ? esc_html__( 'Valid', 'wahou-commerce' ) : esc_html__( 'Not validated', 'wahou-commerce' ); ?>
							<?php if ( ! empty( $evidence['vat_name'] ) ) : ?>
								<div class="wahou-ap-sub"><?php echo esc_html( (string) $evidence['vat_name'] ); ?></div>
							<?php endif; ?>
						</dd>
					</div>
				<?php endif; ?>
			</dl>
			<?php if ( $consents ) : ?>
				<ul class="wahou-ap-consents">
					<?php foreach ( $consents as $key => $when ) : ?>
						<li>
							<span aria-hidden="true">✓</span>
							<?php echo esc_html( $labels[ $key ] ?? (string) $key ); ?>
							<span class="wahou-ap-sub"><?php echo esc_html( is_string( $when ) ? wahou_date( $when, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_technical( Order $order ): void {
		$utm = (array) $order->get_meta( 'utm', array() );
		?>
		<section class="wahou-ap-box">
			<details>
				<summary class="wahou-ap-box__title"><?php esc_html_e( 'Technical details', 'wahou-commerce' ); ?></summary>
				<dl class="wahou-ap-meta">
					<div><dt><?php esc_html_e( 'Order key', 'wahou-commerce' ); ?></dt><dd><code class="wahou-ap-copy" data-copy="<?php echo esc_attr( $order->key() ); ?>"><?php echo esc_html( substr( $order->key(), 0, 8 ) . '…' ); ?></code></dd></div>
					<div><dt><?php esc_html_e( 'IP address', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $order->ip_address() ?: '—' ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Language', 'wahou-commerce' ); ?></dt><dd><?php echo esc_html( $order->locale() ?: '—' ); ?></dd></div>
					<?php if ( $order->cart_id() ) : ?>
						<div><dt><?php esc_html_e( 'Cart', 'wahou-commerce' ); ?></dt><dd>#<?php echo esc_html( (string) $order->cart_id() ); ?></dd></div>
					<?php endif; ?>
					<?php foreach ( $utm as $key => $value ) : ?>
						<?php
						if ( ! is_scalar( $value ) || '' === (string) $value ) {
							continue;
						}
						?>
						<div><dt><?php echo esc_html( (string) $key ); ?></dt><dd><?php echo esc_html( (string) $value ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			</details>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Actions (admin-post).
	 * ------------------------------------------------------------------ */

	private static function order_from_post(): Order {
		$id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		Admin::guard( 'wahou_order_' . $id );
		$order = $id ? Order::find( $id ) : null;
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'wahou-commerce' ), 404 );
		}
		return $order;
	}

	private static function current_user_name(): string {
		$user = wp_get_current_user();
		return $user->exists() ? $user->display_name : 'Wahou Commerce';
	}

	public static function handle_status(): void {
		$order  = self::order_from_post();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $status, Order::STATUSES, true ) ) {
			Admin::flash( __( 'Unknown status.', 'wahou-commerce' ), 'error' );
			Admin::back( self::detail_url( $order ) );
		}
		if ( ! $order->is_paid() && in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$order->payment_complete( '' );
			$order->refresh();
			/* translators: %s: user name. */
			$order->add_note( sprintf( __( 'Payment confirmed manually by %s.', 'wahou-commerce' ), self::current_user_name() ) );
		}
		if ( $order->status() !== $status ) {
			Orders::set_status( $order, $status, $note );
		} elseif ( '' !== $note ) {
			$order->add_note( $note );
		}
		Admin::flash( __( 'Order updated.', 'wahou-commerce' ) );
		Admin::back( self::detail_url( $order ) );
	}

	public static function handle_mark_paid(): void {
		$order = self::order_from_post();
		if ( $order->is_paid() ) {
			Admin::flash( __( 'This order is already paid.', 'wahou-commerce' ), 'info' );
			Admin::back( self::detail_url( $order ) );
		}
		$order->payment_complete( '' );
		$order->refresh();
		/* translators: %s: user name. */
		$order->add_note( sprintf( __( 'Payment confirmed manually by %s.', 'wahou-commerce' ), self::current_user_name() ) );
		Admin::flash( $order->is_paid() ? __( 'Payment recorded. The customer has been notified.', 'wahou-commerce' ) : __( 'The payment could not be recorded.', 'wahou-commerce' ), $order->is_paid() ? 'success' : 'error' );
		Admin::back( self::detail_url( $order ) );
	}

	public static function handle_tracking(): void {
		$order    = self::order_from_post();
		$carriers = self::carriers();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in order_from_post().
		$carrier = isset( $_POST['carrier'] ) ? sanitize_key( wp_unslash( $_POST['carrier'] ) ) : 'other';
		$number  = isset( $_POST['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) ) : '';
		$url     = isset( $_POST['tracking_url'] ) ? esc_url_raw( wp_unslash( $_POST['tracking_url'] ) ) : '';
		$shipped = ! empty( $_POST['mark_shipped'] );
		// phpcs:enable
		$carrier = isset( $carriers[ $carrier ] ) ? $carrier : 'other';
		if ( '' === $url && '' !== $number && '' !== $carriers[ $carrier ]['url'] ) {
			$url = str_replace( '{n}', rawurlencode( $number ), $carriers[ $carrier ]['url'] );
		}
		if ( '' !== $number || '' !== $url ) {
			$order->update_meta(
				'tracking',
				array(
					'carrier'    => 'other' === $carrier ? '' : $carriers[ $carrier ]['name'],
					'carrier_id' => $carrier,
					'number'     => $number,
					'url'        => $url,
				)
			);
			/* translators: 1: carrier, 2: tracking number. */
			$order->add_note( trim( sprintf( __( 'Tracking added: %1$s %2$s', 'wahou-commerce' ), 'other' === $carrier ? '' : $carriers[ $carrier ]['name'], $number ) ) );
		} else {
			$order->update_meta( 'tracking', null );
		}
		if ( $shipped && 'completed' !== $order->status() ) {
			Orders::set_status( $order, 'completed', __( 'Shipped.', 'wahou-commerce' ) );
			Admin::flash( __( 'Order marked as shipped. The customer has been notified.', 'wahou-commerce' ) );
		} else {
			Admin::flash( __( 'Tracking saved.', 'wahou-commerce' ) );
		}
		Admin::back( self::detail_url( $order ) );
	}

	public static function handle_refund(): void {
		$order = self::order_from_post();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in order_from_post().
		$amount  = isset( $_POST['amount'] ) ? Money::to_minor( sanitize_text_field( wp_unslash( $_POST['amount'] ) ), $order->currency() ) : 0;
		$reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$restock = ! empty( $_POST['restock'] );
		// phpcs:enable
		if ( $amount <= 0 ) {
			Admin::flash( __( 'Please enter the amount to refund.', 'wahou-commerce' ), 'error' );
			Admin::back( self::detail_url( $order ) );
		}
		$result = Orders::refund( $order, $amount, $reason, $restock );
		if ( is_wp_error( $result ) ) {
			Admin::flash( $result->get_error_message(), 'error' );
		} else {
			/* translators: %s: refunded amount. */
			Admin::flash( sprintf( __( '%s refunded.', 'wahou-commerce' ), Money::format( (int) $result['amount'], $order->currency() ) ) );
		}
		Admin::back( self::detail_url( $order ) );
	}

	public static function handle_note(): void {
		$order    = self::order_from_post();
		$note     = isset( $_POST['note'] ) ? trim( wp_kses_post( wp_unslash( $_POST['note'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$customer = ! empty( $_POST['customer_note'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $note ) {
			Admin::flash( __( 'The note is empty.', 'wahou-commerce' ), 'error' );
			Admin::back( self::detail_url( $order ) );
		}
		$order->add_note( $note, $customer );
		Admin::flash( $customer ? __( 'Note sent to the customer.', 'wahou-commerce' ) : __( 'Note added.', 'wahou-commerce' ) );
		Admin::back( self::detail_url( $order ) );
	}

	public static function handle_resend(): void {
		$order = self::order_from_post();
		$type  = isset( $_POST['email_type'] ) ? sanitize_key( wp_unslash( $_POST['email_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $type, array( 'order_confirmation', 'order_shipped', 'bank_instructions' ), true ) || ! class_exists( Emails::class ) || ! method_exists( Emails::class, 'resend' ) ) {
			Admin::flash( __( 'This email cannot be sent.', 'wahou-commerce' ), 'error' );
			Admin::back( self::detail_url( $order ) );
		}
		$sent = (bool) Emails::resend( $order, $type );
		if ( $sent ) {
			/* translators: %s: customer email address. */
			$order->add_note( sprintf( __( 'Email sent again to %s.', 'wahou-commerce' ), $order->email() ) );
		}
		Admin::flash( $sent ? __( 'Email sent.', 'wahou-commerce' ) : __( 'The email could not be sent. Check your site email configuration.', 'wahou-commerce' ), $sent ? 'success' : 'error' );
		Admin::back( self::detail_url( $order ) );
	}

	/* ------------------------------------------------------------------
	 * Accounting export.
	 * ------------------------------------------------------------------ */

	public static function export_csv(): void {
		Admin::guard( 'wahou_export_orders' );

		$args   = OrdersListTable::query_args( OrdersListTable::filters() );
		$orders = array();
		$offset = 0;
		do {
			$batch  = Order::query(
				array_merge(
					$args,
					array(
						'limit'   => 500,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				)
			);
			$orders = array_merge( $orders, $batch['orders'] );
			$offset += 500;
		} while ( 500 === count( $batch['orders'] ) && $offset < 20000 );

		$rates = array();
		foreach ( $orders as $order ) {
			foreach ( $order->tax_lines() as $line ) {
				$rate = (float) $line['rate'];
				if ( $rate > 0 ) {
					$rates[ number_format( $rate, 3, '.', '' ) ] = $rate;
				}
			}
		}
		krsort( $rates, SORT_NUMERIC );

		$comma = ',' === substr( Money::to_input( 150, 'EUR' ), -3, 1 );
		$sep   = $comma ? ';' : ',';
		$amt   = static function ( int $minor, string $currency ) use ( $comma ): string {
			$decimals = Money::decimals( $currency );
			return number_format( $minor / ( 10 ** $decimals ), $decimals, $comma ? ',' : '.', '' );
		};
		$text  = static function ( string $value ): string {
			$value = str_replace( array( "\r", "\n" ), ' ', $value );
			return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t" ), true ) ) ? "'" . $value : $value;
		};

		$header = array(
			__( 'Order', 'wahou-commerce' ),
			__( 'Invoice', 'wahou-commerce' ),
			__( 'Order date', 'wahou-commerce' ),
			__( 'Payment date', 'wahou-commerce' ),
			__( 'Status', 'wahou-commerce' ),
			__( 'Customer', 'wahou-commerce' ),
			__( 'Company', 'wahou-commerce' ),
			__( 'Email', 'wahou-commerce' ),
			__( 'Billing country', 'wahou-commerce' ),
			__( 'Tax country', 'wahou-commerce' ),
			__( 'VAT treatment', 'wahou-commerce' ),
			__( 'Customer VAT number', 'wahou-commerce' ),
			__( 'Currency', 'wahou-commerce' ),
			__( 'Subtotal', 'wahou-commerce' ),
			__( 'Discount', 'wahou-commerce' ),
			__( 'Shipping', 'wahou-commerce' ),
			__( 'Total excl. VAT', 'wahou-commerce' ),
			__( 'Total VAT', 'wahou-commerce' ),
		);
		foreach ( $rates as $rate ) {
			/* translators: %s: VAT rate. */
			$header[] = sprintf( __( 'Taxable base %s', 'wahou-commerce' ), self::rate_label( $rate ) );
			/* translators: %s: VAT rate. */
			$header[] = sprintf( __( 'VAT %s', 'wahou-commerce' ), self::rate_label( $rate ) );
		}
		$header = array_merge(
			$header,
			array(
				__( 'Total incl. VAT', 'wahou-commerce' ),
				__( 'Refunded', 'wahou-commerce' ),
				__( 'Payment method', 'wahou-commerce' ),
				__( 'Transaction ID', 'wahou-commerce' ),
				__( 'Coupon', 'wahou-commerce' ),
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wahou-orders-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, $header, $sep, '"', '\\' );

		$datetime = 'Y-m-d H:i';
		foreach ( $orders as $order ) {
			$currency = $order->currency();
			$billing  = $order->billing();
			$by_rate  = array();
			$net      = 0;
			foreach ( $order->tax_lines() as $line ) {
				$key = number_format( (float) $line['rate'], 3, '.', '' );
				if ( ! isset( $by_rate[ $key ] ) ) {
					$by_rate[ $key ] = array(
						'net' => 0,
						'tax' => 0,
					);
				}
				$by_rate[ $key ]['net'] += (int) $line['net'];
				$by_rate[ $key ]['tax'] += (int) $line['tax'];
				$net                    += (int) $line['net'];
			}
			if ( empty( $by_rate ) ) {
				$net = $order->total() - $order->tax_total();
			}
			$row = array(
				$text( $order->number() ),
				$text( $order->invoice_number() ),
				(string) get_date_from_gmt( $order->created_at(), $datetime ),
				$order->paid_at() ? (string) get_date_from_gmt( $order->paid_at(), $datetime ) : '',
				Order::label( $order->status() ),
				$text( $order->customer_name() ),
				$text( (string) ( $billing['company'] ?? '' ) ),
				$text( $order->email() ),
				(string) ( $billing['country'] ?? '' ),
				$order->tax_country(),
				self::tax_mode_label( $order->tax_mode() ),
				$text( (string) ( $billing['vat_number'] ?? '' ) ),
				$currency,
				$amt( $order->subtotal(), $currency ),
				$amt( $order->discount_total(), $currency ),
				$amt( $order->shipping_total(), $currency ),
				$amt( $net, $currency ),
				$amt( $order->tax_total(), $currency ),
			);
			foreach ( array_keys( $rates ) as $key ) {
				$row[] = isset( $by_rate[ $key ] ) ? $amt( $by_rate[ $key ]['net'], $currency ) : '';
				$row[] = isset( $by_rate[ $key ] ) ? $amt( $by_rate[ $key ]['tax'], $currency ) : '';
			}
			$row = array_merge(
				$row,
				array(
					$amt( $order->total(), $currency ),
					$amt( $order->refunded_total(), $currency ),
					$text( $order->payment_method_title() ),
					$text( $order->transaction_id() ),
					$text( $order->coupon_code() ),
				)
			);
			fputcsv( $out, $row, $sep, '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Products sold in paid orders since a GMT date (dashboard).
	 *
	 * @return array<int, array{product_id:int, name:string, qty:int, revenue:int}>
	 */
	public static function top_products( string $from_gmt, int $limit = 5 ): array {
		global $wpdb;
		$orders = Install::table( 'orders' );
		$items  = Install::table( 'order_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.product_id, MAX(i.name) AS name, SUM(i.quantity) AS qty, SUM(i.total) AS revenue FROM {$items} i INNER JOIN {$orders} o ON o.id = i.order_id WHERE o.status IN ('processing','completed') AND o.paid_at >= %s GROUP BY i.product_id ORDER BY revenue DESC LIMIT %d", $from_gmt, $limit ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'product_id' => (int) $r['product_id'],
				'name'       => (string) $r['name'],
				'qty'        => (int) $r['qty'],
				'revenue'    => (int) $r['revenue'],
			),
			(array) $rows
		);
	}
}
