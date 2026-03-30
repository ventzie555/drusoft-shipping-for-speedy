<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Speedy_Modern_Waybill_Generator' ) ) {

	class Speedy_Modern_Waybill_Generator {

		/**
		 * The single instance of the class.
		 */
		protected static $_instance = null;

		/**
		 * Main Speedy_Modern_Waybill_Generator Instance.
		 */
		public static function instance(): ?Speedy_Modern_Waybill_Generator {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			// This hook will trigger waybill generation
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		}

		/**
		 * Hook that fires when an order's status changes.
		 *
		 * @param int      $order_id    Order ID.
		 * @param string   $status_from Old status.
		 * @param string   $status_to   New status.
		 * @param WC_Order $order       Order object.
		 */
		public function on_order_status_changed( int $order_id, string $status_from, string $status_to, WC_Order $order ): void {
			// Get the shipping method instance settings
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods ); // Get the first shipping method

			if ( ! $shipping_method || 'speedy_modern' !== $shipping_method->get_method_id() ) {
				return;
			}

			$instance_id = $shipping_method->get_instance_id();
			$settings    = get_option( 'woocommerce_speedy_modern_' . $instance_id . '_settings' );

			// Trigger on 'processing' or 'on-hold' if auto-generation is enabled
			$should_generate = ( 'yes' === ( $settings['generate_waybill'] ?? 'no' ) );
			$is_target_status = in_array( $status_to, [ 'processing', 'on-hold' ], true );

			if ( $should_generate && $is_target_status ) {
				$this->generate_waybill( $order_id );
			}
		}

		/**
		 * Generate the waybill for a given order.
		 *
		 * @param int $order_id The ID of the order.
		 * @return string|WP_Error The waybill ID on success, or a WP_Error on failure.
		 */
		public function generate_waybill( int $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'modern-shipping-for-speedy' ) );
			}

			// Prevent re-generation if a waybill already exists
			if ( $order->get_meta( '_speedy_waybill_id' ) ) {
				return $order->get_meta( '_speedy_waybill_id' );
			}

			// Retrieve the payload saved during checkout
			$payload = $order->get_meta( '_speedy_order_data' );
			if ( empty( $payload ) ) {
				return new WP_Error( 'no_payload', __( 'No Speedy shipping data found for this order.', 'modern-shipping-for-speedy' ) );
			}

			// Get credentials from the specific shipping instance
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods );
			$instance_id      = $shipping_method->get_instance_id();
			$settings         = get_option( 'woocommerce_speedy_modern_' . $instance_id . '_settings' );

			$username = $settings['speedy_username'] ?? '';
			$password = $settings['speedy_password'] ?? '';

			if ( ! $username || ! $password ) {
				return new WP_Error( 'no_credentials', __( 'Speedy credentials are not configured for this shipping method.', 'modern-shipping-for-speedy' ) );
			}

			// --- Finalize the Payload ---
			$payload['userName'] = $username;
			$payload['password'] = $password;

			// Remove internal tracking keys (not part of the API)
			unset( $payload['_selected_service_id'] );

			// Convert calculate payload format to shipment format:
			// The calculate endpoint uses service.serviceIds (array),
			// but the shipment endpoint requires service.serviceId (single int).
			if ( isset( $payload['service']['serviceIds'] ) && is_array( $payload['service']['serviceIds'] ) ) {
				$payload['service']['serviceId'] = $payload['service']['serviceIds'][0];
				unset( $payload['service']['serviceIds'] );
			}

			// Add package type to content (only needed for shipment, not calculate)
			if ( ! isset( $payload['content']['package'] ) ) {
				$payload['content']['package'] = $settings['opakovka'] ?? 'BOX';
			}

			// Add contents description (required by /v1/shipment)
			if ( empty( $payload['content']['contents'] ) ) {
				$items = [];
				foreach ( $order->get_items() as $item ) {
					$items[] = $item->get_name() . ' x' . $item->get_quantity();
				}
				$description = implode( ', ', $items );
				if ( empty( $description ) ) {
					$description = __( 'Order #', 'modern-shipping-for-speedy' ) . $order->get_order_number();
				}
				// Speedy limits this field — truncate to 100 chars
				$payload['content']['contents'] = mb_substr( $description, 0, 100 );
			}

			// Add recipient details from the order
			$payload['recipient']['clientName']       = $order->get_formatted_shipping_full_name();
			$payload['recipient']['phone1']['number'] = $order->get_billing_phone();
			$payload['recipient']['email']            = $order->get_billing_email();

			// Add order reference
			$payload['ref1'] = __( 'Order #', 'modern-shipping-for-speedy' ) . $order->get_order_number();

			// If delivery is to address, use addressNote
			if ( isset( $payload['recipient']['addressLocation'] ) ) {
				$full_address = $order->get_shipping_address_1();
				if ( $order->get_shipping_address_2() ) {
					$full_address .= ', ' . $order->get_shipping_address_2();
				}
				$payload['recipient']['addressLocation']['addressNote'] = $full_address;
			}

			// --- Make the API Call ---

			$response = wp_remote_post( 'https://api.speedy.bg/v1/shipment/', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			] );

			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __( 'Speedy Waybill Error: ', 'modern-shipping-for-speedy' ) . $response->get_error_message() );
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			// --- Handle API Response ---
			if ( isset( $body['error'] ) ) {
				$error_message = $body['error']['message'] ?? __( 'Unknown API error', 'modern-shipping-for-speedy' );
				$order->add_order_note( __( 'Speedy Waybill Error: ', 'modern-shipping-for-speedy' ) . $error_message );
				return new WP_Error( 'api_error', $error_message );
			}

			if ( isset( $body['id'] ) ) {
				$waybill_id = $body['id'];

				// Save the waybill ID and the full response to the order
				$order->update_meta_data( '_speedy_waybill_id', $waybill_id );
				$order->update_meta_data( '_speedy_waybill_response', $body );
				$order->add_order_note( __( 'Speedy Waybill Created: ', 'modern-shipping-for-speedy' ) . $waybill_id );
				$order->save();

				return $waybill_id;
			}

			return new WP_Error( 'unexpected_response', __( 'Unexpected response from Speedy API.', 'modern-shipping-for-speedy' ) );
		}
	}
}

// Initialize the generator
Speedy_Modern_Waybill_Generator::instance();
