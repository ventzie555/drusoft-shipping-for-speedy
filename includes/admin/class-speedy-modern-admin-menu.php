<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Speedy_Modern_Admin_Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	public static function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Speedy Orders', 'modern-shipping-for-speedy' ),
			__( 'Speedy Orders', 'modern-shipping-for-speedy' ),
			'manage_woocommerce',
			'speedy-modern-orders',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_scripts( $hook ): void {
		if ( 'woocommerce_page_speedy-modern-orders' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'speedy-modern-admin-orders',
			SPEEDY_MODERN_URL . 'assets/js/admin-orders.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_localize_script( 'speedy-modern-admin-orders', 'speedy_admin_params', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'speedy_modern_actions' ),
			'i18n'     => [
				'confirm_cancel'  => __( 'Are you sure you want to cancel this shipment?', 'modern-shipping-for-speedy' ),
				'requesting'      => __( 'Requesting...', 'modern-shipping-for-speedy' ),
				'requested'       => __( 'Requested', 'modern-shipping-for-speedy' ),
				'request_courier' => __( 'Request Courier', 'modern-shipping-for-speedy' ),
				'generating'      => __( 'Generating...', 'modern-shipping-for-speedy' ),
				'generate'        => __( 'Generate', 'modern-shipping-for-speedy' ),
			],
		] );
	}

	public static function render_page(): void {
		require_once __DIR__ . '/class-speedy-orders-list-table.php';

		$table = new Speedy_Orders_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Speedy Orders', 'modern-shipping-for-speedy' ) . '</h1>';
		echo '<form method="post">';
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}

Speedy_Modern_Admin_Menu::init();
