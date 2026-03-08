<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Speedy_Orders_List_Table
 *
 * Extends WP_List_Table to display a list of WooCommerce orders that have an associated Speedy waybill.
 * Provides functionality for listing, pagination, and actions like printing waybills, canceling shipments, and requesting couriers.
 */
class Speedy_Orders_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * Sets up the list table properties.
	 */
	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Speedy Order', 'speedy-modern' ),
			'plural'   => __( 'Speedy Orders', 'speedy-modern' ),
			'ajax'     => false,
		] );
	}

	/**
	 * Get a list of columns.
	 *
	 * @return array The list of columns.
	 */
	public function get_columns(): array {
		return [
			'cb'       => '<input type="checkbox" />',
			'order'    => __( 'Order', 'speedy-modern' ),
			'waybill'  => __( 'Waybill', 'speedy-modern' ),
			'customer' => __( 'Customer', 'speedy-modern' ),
			'status'   => __( 'Status', 'speedy-modern' ),
			'date'     => __( 'Date', 'speedy-modern' ),
		];
	}

	/**
	 * Prepare the items for the table to process.
	 *
	 * Fetches all orders that used Speedy shipping, handling pagination and sorting.
	 * Orders without a waybill yet will show a "Generate" button.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$paged    = $this->get_pagenum();
		$per_page = 20;

		$args = [
			'limit'        => $per_page,
			'paged'        => $paged,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_key'     => '_speedy_order_data', // All orders that used Speedy shipping
			'meta_compare' => 'EXISTS',
			'paginate'     => true, // Required to get total count
		];

		// wc_get_orders with paginate=true returns an object with 'orders' and 'total'
		$results = wc_get_orders( $args );

		$this->items = $results->orders;

		$this->set_pagination_args( [
			'total_items' => $results->total,
			'per_page'    => $per_page,
		] );
	}

	/**
	 * Default column renderer.
	 *
	 * @param WC_Order $item        The order object.
	 * @param string   $column_name The name of the column to render.
	 *
	 * @return string The column content.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				return sprintf( '<a href="%s">#%s</a>', $item->get_edit_order_url(), $item->get_order_number() );
			case 'customer':
				return $item->get_formatted_billing_full_name();
			case 'status':
				return wc_get_order_status_name( $item->get_status() );
			case 'date':
				return $item->get_date_created()->date_i18n( 'Y/m/d' );
			default:
				return '';
		}
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param WC_Order $item The order object.
	 *
	 * @return string The checkbox HTML.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="order[]" value="%s" />', $item->get_id() );
	}

	/**
	 * Render the Waybill column.
	 *
	 * Displays the waybill ID (linked to tracking) and action buttons (Print, Cancel, Request Courier).
	 *
	 * @param WC_Order $item The order object.
	 *
	 * @return string The column content.
	 */
	protected function column_waybill( $item ): string {
		$waybill_id = $item->get_meta( '_speedy_waybill_id' );

		if ( ! $waybill_id ) {
			return '<button class="button speedy-generate-waybill" data-order-id="' . $item->get_id() . '">' . __( 'Generate', 'speedy-modern' ) . '</button>';
		}

		$actions = [
			'print'   => sprintf( '<a href="%s" target="_blank">%s</a>', admin_url( 'admin-post.php?action=speedy_print_waybill&order_id=' . $item->get_id() ), __( 'Print', 'speedy-modern' ) ),
			'cancel'  => sprintf( '<a href="#" class="speedy-cancel-shipment" data-order-id="%d">%s</a>', $item->get_id(), __( 'Cancel', 'speedy-modern' ) ),
		];

		$courier_requested = $item->get_meta( '_speedy_courier_requested' );
		if ( 'yes' === $courier_requested ) {
			$actions['courier'] = '<span style="color: green;">' . __( 'Requested', 'speedy-modern' ) . '</span>';
		} else {
			$actions['courier'] = sprintf( '<a href="#" class="speedy-request-courier" data-order-id="%d">%s</a>', $item->get_id(), __( 'Request Courier', 'speedy-modern' ) );
		}

		$track_url    = 'https://www.speedy.bg/track?id=' . $waybill_id;
		$waybill_link = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $track_url ), esc_html( $waybill_id ) );

		return $waybill_link . $this->row_actions( $actions );
	}

	/**
	 * Display the table.
	 *
	 * Overrides the parent display method to include necessary JavaScript for AJAX actions.
	 *
	 * @return void
	 */
	public function display(): void {
		parent::display();
		// JS is now enqueued via admin-menu.php
	}
}
