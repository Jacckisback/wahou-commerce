<?php
/**
 * Orders list table (Wahou Commerce > Orders).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Admin;

use WahouCommerce\Orders\Order;
use WahouCommerce\Support\Countries;
use WahouCommerce\Support\Money;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class OrdersListTable extends \WP_List_Table {

	public const BULK = array( 'mark_processing', 'mark_completed', 'mark_cancelled' );

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array{status:string, search:string, month:string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$month  = isset( $_REQUEST['m'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['m'] ) ) : '';
		// phpcs:enable
		return array(
			'status' => in_array( $status, Order::STATUSES, true ) ? $status : '',
			'search' => $search,
			'month'  => preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : '',
		);
	}

	/**
	 * Order::query() arguments for the current filters.
	 *
	 * @param array{status:string, search:string, month:string} $filters
	 * @return array<string, mixed>
	 */
	public static function query_args( array $filters ): array {
		$args = array();
		if ( '' !== $filters['status'] ) {
			$args['status'] = $filters['status'];
		}
		if ( '' !== $filters['search'] ) {
			$args['search'] = $filters['search'];
		}
		if ( '' !== $filters['month'] ) {
			list( $from, $to )  = OrdersPage::month_range( $filters['month'] );
			$args['date_from'] = $from;
			$args['date_to']   = $to;
		}
		return $args;
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'number'   => __( 'Order', 'wahou-commerce' ),
			'date'     => __( 'Date', 'wahou-commerce' ),
			'customer' => __( 'Customer', 'wahou-commerce' ),
			'status'   => __( 'Status', 'wahou-commerce' ),
			'items'    => __( 'Items', 'wahou-commerce' ),
			'total'    => __( 'Total', 'wahou-commerce' ),
			'payment'  => __( 'Payment', 'wahou-commerce' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'number' => array( 'id', true ),
			'date'   => array( 'created_at', true ),
			'total'  => array( 'total', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'mark_processing' => __( 'Change status to processing', 'wahou-commerce' ),
			'mark_completed'  => __( 'Change status to completed', 'wahou-commerce' ),
			'mark_cancelled'  => __( 'Change status to cancelled', 'wahou-commerce' ),
		);
	}

	protected function get_views() {
		$counts  = OrdersPage::status_counts();
		$current = self::filters()['status'];
		$base    = admin_url( 'admin.php?page=' . OrdersPage::SLUG );
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $base ),
			'' === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All', 'wahou-commerce' ),
			esc_html( number_format_i18n( array_sum( $counts ) ) )
		);
		foreach ( Order::STATUSES as $status ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( Order::label( $status ) ),
				esc_html( number_format_i18n( (int) $counts[ $status ] ) )
			);
		}
		return $views;
	}

	public function prepare_items() {
		$per_page = (int) $this->get_items_per_page( 'wahou_orders_per_page', 20 );
		$paged    = $this->get_pagenum();
		$filters  = self::filters();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Sorting parameters.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id';
		$order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable

		$args = array_merge(
			self::query_args( $filters ),
			array(
				'limit'   => $per_page,
				'offset'  => ( $paged - 1 ) * $per_page,
				'orderby' => in_array( $orderby, array( 'id', 'created_at', 'total' ), true ) ? $orderby : 'id',
				'order'   => $order,
			)
		);

		$result      = Order::query( $args );
		$this->items = $result['orders'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'number' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / max( 1, $per_page ) ),
			)
		);
	}

	public function no_items() {
		$filters = self::filters();
		if ( '' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['month'] ) {
			esc_html_e( 'No orders match these filters.', 'wahou-commerce' );
			return;
		}
		echo '<span class="wahou-ap-empty">' . esc_html__( 'No orders yet. Share a direct buy link or your shop page to get your first sale!', 'wahou-commerce' ) . '</span>';
	}

	/**
	 * @param Order $item
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="wahou-order-%1$d">%2$s</label><input type="checkbox" name="order_ids[]" id="wahou-order-%1$d" value="%1$d" />',
			$item->id(),
			/* translators: %s: order number. */
			esc_html( sprintf( __( 'Select order #%s', 'wahou-commerce' ), $item->number() ) )
		);
	}

	/**
	 * @param Order $item
	 */
	protected function column_number( $item ) {
		$url  = $item->admin_url();
		$html = sprintf( '<strong><a class="row-title" href="%1$s">#%2$s</a></strong>', esc_url( $url ), esc_html( $item->number() ) );
		if ( $item->parent_id() ) {
			$parent = Order::find( $item->parent_id() );
			if ( $parent ) {
				$html .= '<div class="wahou-ap-sub">' . sprintf(
					/* translators: %s: parent order number with link. */
					esc_html__( 'Upsell of %s', 'wahou-commerce' ),
					'<a href="' . esc_url( $parent->admin_url() ) . '">#' . esc_html( $parent->number() ) . '</a>'
				) . '</div>';
			}
		}
		$actions = array(
			'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'wahou-commerce' ) . '</a>',
		);
		if ( '' !== $item->invoice_number() ) {
			$actions['invoice'] = '<a href="' . esc_url( $item->invoice_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Invoice', 'wahou-commerce' ) . '</a>';
		}
		return $html . $this->row_actions( $actions );
	}

	/**
	 * @param Order $item
	 */
	protected function column_date( $item ) {
		$timestamp = (int) strtotime( $item->created_at() . ' UTC' );
		$diff      = time() - $timestamp;
		if ( $diff >= 0 && $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human time difference, e.g. "2 hours". */
			$label = sprintf( __( '%s ago', 'wahou-commerce' ), human_time_diff( $timestamp ) );
		} else {
			$label = wp_date( (string) get_option( 'date_format' ), $timestamp );
		}
		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
			esc_html( (string) $label )
		);
	}

	/**
	 * @param Order $item
	 */
	protected function column_customer( $item ) {
		$billing = $item->billing();
		$name    = $item->customer_name();
		$company = (string) ( $billing['company'] ?? '' );
		$country = (string) ( $billing['country'] ?? $item->tax_country() );
		$out     = '<strong>' . esc_html( '' !== $name ? $name : $item->email() ) . '</strong>';
		if ( '' !== $company ) {
			$out .= ' <span class="wahou-ap-sub">· ' . esc_html( $company ) . '</span>';
		}
		$out .= '<div class="wahou-ap-sub">' . esc_html( $item->email() );
		if ( '' !== $country ) {
			$out .= ' · <abbr title="' . esc_attr( Countries::name( $country ) ) . '">' . esc_html( $country ) . '</abbr>';
		}
		return $out . '</div>';
	}

	/**
	 * @param Order $item
	 */
	protected function column_status( $item ) {
		return OrdersPage::status_pill( $item->status() );
	}

	/**
	 * @param Order $item
	 */
	protected function column_items( $item ) {
		$items = $item->items();
		if ( empty( $items ) ) {
			return '—';
		}
		$first = $items[0];
		$label = $first['name'] . ( (int) $first['quantity'] > 1 ? ' × ' . (int) $first['quantity'] : '' );
		$more  = count( $items ) - 1;
		$out   = '<span class="wahou-ap-truncate">' . esc_html( $label ) . '</span>';
		if ( $more > 0 ) {
			/* translators: %d: number of other products in the order. */
			$out .= ' <span class="wahou-ap-sub">' . esc_html( sprintf( _n( '+ %d other', '+ %d others', $more, 'wahou-commerce' ), $more ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * @param Order $item
	 */
	protected function column_total( $item ) {
		$out = '<strong>' . esc_html( Money::format( $item->total(), $item->currency() ) ) . '</strong>';
		if ( $item->refunded_total() > 0 ) {
			/* translators: %s: refunded amount. */
			$out .= '<div class="wahou-ap-sub wahou-ap-refunded">' . esc_html( sprintf( __( '%s refunded', 'wahou-commerce' ), Money::format( $item->refunded_total(), $item->currency() ) ) ) . '</div>';
		}
		return $out;
	}

	/**
	 * @param Order $item
	 */
	protected function column_payment( $item ) {
		$title = $item->payment_method_title();
		return '' !== $title ? esc_html( $title ) : '—';
	}

	/**
	 * @param Order  $item
	 * @param string $column_name
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Month filter and export button.
	 *
	 * @param string $which top|bottom
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::filters();
		$months  = OrdersPage::months();
		echo '<div class="alignleft actions">';
		if ( $months ) {
			echo '<label for="wahou-filter-month" class="screen-reader-text">' . esc_html__( 'Filter by month', 'wahou-commerce' ) . '</label>';
			echo '<select name="m" id="wahou-filter-month"><option value="">' . esc_html__( 'All dates', 'wahou-commerce' ) . '</option>';
			foreach ( $months as $ym ) {
				$label = wp_date( 'F Y', (int) strtotime( $ym . '-15 12:00:00' ) );
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $ym ), selected( $filters['month'], $ym, false ), esc_html( (string) $label ) );
			}
			echo '</select>';
			submit_button( __( 'Filter', 'wahou-commerce' ), '', 'filter_action', false );
		}
		$export = wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => 'wahou_export_orders',
						'status' => $filters['status'],
						's'      => $filters['search'],
						'm'      => $filters['month'],
					)
				),
				admin_url( 'admin-post.php' )
			),
			'wahou_export_orders'
		);
		echo ' <a class="button wahou-ap-export" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV (accounting)', 'wahou-commerce' ) . '</a>';
		echo '</div>';
	}
}
