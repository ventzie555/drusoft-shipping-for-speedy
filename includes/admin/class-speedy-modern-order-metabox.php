<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Speedy shipment meta box to the WooCommerce order edit page.
 *
 * Displays waybill status and provides Generate, Print, Cancel,
 * and Request Courier buttons directly on the order screen.
 */
class Speedy_Modern_Order_Metabox {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * Register the meta box for WooCommerce orders.
	 * Supports both HPOS (woocommerce_page_wc-orders) and legacy (shop_order).
	 */
	public static function add_meta_box(): void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show the meta box if the order uses Speedy shipping
		$order = self::get_current_order();
		if ( ! $order ) {
			return;
		}

		$has_speedy = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'speedy_modern' === $method->get_method_id() ) {
				$has_speedy = true;
				break;
			}
		}

		if ( ! $has_speedy ) {
			return;
		}

		add_meta_box(
			'speedy-modern-shipment',
			__( 'Speedy Shipment', 'modern-shipping-for-speedy' ),
			[ __CLASS__, 'render' ],
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Determine the correct screen ID for the order edit page.
	 *
	 * @return string|null
	 */
	private static function get_order_screen(): ?string {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return null;
		}

		// HPOS
		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			return $screen->id;
		}

		// Legacy
		if ( 'shop_order' === $screen->id ) {
			return 'shop_order';
		}

		return null;
	}

	/**
	 * Get the order object from the current screen.
	 *
	 * @return WC_Order|null
	 */
	private static function get_current_order(): ?WC_Order {
		// HPOS: order ID is in the GET parameter
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; used to display a meta box on the WC order screen.
		if ( isset( $_GET['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( absint( $_GET['id'] ) );
			return $order ?: null;
		}

		// Legacy: order ID is the post ID
		global $post;
		if ( $post && 'shop_order' === $post->post_type ) {
			$order = wc_get_order( $post->ID );
			return $order ?: null;
		}

		return null;
	}

	/**
	 * Render the meta box content.
	 */
	public static function render(): void {
		$order = self::get_current_order();
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'modern-shipping-for-speedy' ) . '</p>';
			return;
		}

		$order_id   = $order->get_id();
		$waybill_id = $order->get_meta( '_speedy_waybill_id' );
		$courier_requested = ( 'yes' === $order->get_meta( '_speedy_courier_requested' ) );

		echo '<div id="speedy-metabox-content">';

		if ( $waybill_id ) {
			// Waybill exists — show info and actions
			$track_url = 'https://www.speedy.bg/track?id=' . urlencode( $waybill_id );
			$print_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=speedy_print_waybill&order_id=' . $order_id ),
				'speedy_print_waybill'
			);

			echo '<p><strong>' . esc_html__( 'Waybill:', 'modern-shipping-for-speedy' ) . '</strong> ';
			echo '<a href="' . esc_url( $track_url ) . '" target="_blank">' . esc_html( $waybill_id ) . '</a></p>';

			echo '<div class="speedy-metabox-actions" style="display: flex; flex-direction: column; gap: 6px;">';

			// Print
			echo '<a href="' . esc_url( $print_url ) . '" target="_blank" class="button" style="text-align:center;">'
			     . esc_html__( 'Print Waybill', 'modern-shipping-for-speedy' ) . '</a>';

			// Request Courier
			if ( $courier_requested ) {
				echo '<span class="button disabled" style="text-align:center; color: green;">'
				     . esc_html__( 'Courier Requested', 'modern-shipping-for-speedy' ) . '</span>';
			} else {
				echo '<button type="button" class="button speedy-order-request-courier" data-order-id="' . esc_attr( $order_id ) . '">'
				     . esc_html__( 'Request Courier', 'modern-shipping-for-speedy' ) . '</button>';
			}

			// Cancel
			echo '<button type="button" class="button speedy-order-cancel" data-order-id="' . esc_attr( $order_id ) . '" style="color: #a00;">'
			     . esc_html__( 'Cancel Shipment', 'modern-shipping-for-speedy' ) . '</button>';

			echo '</div>';
		} else {
			// No waybill — show generate button
			echo '<p>' . esc_html__( 'No waybill generated yet.', 'modern-shipping-for-speedy' ) . '</p>';
			echo '<button type="button" class="button button-primary speedy-order-generate" data-order-id="' . esc_attr( $order_id ) . '">'
			     . esc_html__( 'Generate Waybill', 'modern-shipping-for-speedy' ) . '</button>';
		}

		echo '</div>';
		echo '<div id="speedy-metabox-notice" style="margin-top: 8px;"></div>';
	}

	/**
	 * Enqueue JS on the order edit screen.
	 */
	public static function enqueue_scripts( $hook ): void {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		wp_enqueue_script(
			'speedy-modern-order-metabox',
			SPEEDY_MODERN_URL . 'assets/js/order-metabox.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_localize_script( 'speedy-modern-order-metabox', 'speedy_metabox_params', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'speedy_modern_actions' ),
			'i18n'     => [
				'confirm_cancel'    => __( 'Are you sure you want to cancel this shipment?', 'modern-shipping-for-speedy' ),
				'generating'        => __( 'Generating...', 'modern-shipping-for-speedy' ),
				'requesting'        => __( 'Requesting...', 'modern-shipping-for-speedy' ),
				'courier_requested' => __( 'Courier Requested', 'modern-shipping-for-speedy' ),
				'cancelling'        => __( 'Cancelling...', 'modern-shipping-for-speedy' ),
			],
		] );
	}
}

Speedy_Modern_Order_Metabox::init();

