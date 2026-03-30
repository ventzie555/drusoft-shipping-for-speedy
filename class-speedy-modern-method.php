<?php
/**
 * Speedy Modern Shipping Method Class
 *
 * @copyright 2026 DRUSOFT LTD.
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Speedy_Modern_Method' ) ) {

	class WC_Speedy_Modern_Method extends WC_Shipping_Method {

		/**
		 * Constructor for the shipping class
		 */
		public function __construct( $instance_id = 0 ) {
			// Fixes the "Missing parent constructor call" warning
			parent::__construct( $instance_id );

			$this->id                 = 'speedy_modern';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Speedy Modern', 'modern-shipping-for-speedy' );
			$this->method_description = __( 'Fresh, conflict-free Speedy delivery for Bulgaria.', 'modern-shipping-for-speedy' );

			$this->supports = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init();
		}

		/**
		 * Initialize settings and hooks
		 */
		public function init(): void {
			// Load the settings API
			$this->init_instance_settings();
			$this->init_form_fields();
			$this->init_settings();

			// Define user-set variables
			$this->title = $this->get_option( 'title', __( 'Speedy Delivery', 'modern-shipping-for-speedy' ) );

			// Save settings in admin
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

			// Save shipping data to order
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_shipping_data_to_order' ), 10, 2 );
		}

		/**
		 * Save Speedy session data to the order before it is created.
		 *
		 * @param WC_Order $order The order object being created.
         */
		public function save_shipping_data_to_order(WC_Order $order): void {
			// 1. Check if our shipping method is chosen
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
			$is_speedy      = false;

			if ( ! empty( $chosen_methods ) ) {
				foreach ( $chosen_methods as $method_id ) {
					if ( str_starts_with( $method_id, $this->id ) ) {
						$is_speedy = true;
						break;
					}
				}
			}

			if ( ! $is_speedy ) {
				return;
			}

			// 2. Get the selected service from session (set by our service selector)
			$chosen_service_id = WC()->session ? (int) WC()->session->get( 'speedy_modern_selected_service', 0 ) : 0;

			// 3. Try to get the service-specific session data first, fallback to general
			$session_data = null;
			if ( $chosen_service_id && WC()->session ) {
				$session_data = WC()->session->get( 'speedy_modern_shipping_data_' . $chosen_service_id );
			}
			if ( empty( $session_data ) ) {
				$session_data = WC()->session ? WC()->session->get( 'speedy_modern_shipping_data' ) : null;
			}

			if ( ! empty( $session_data ) ) {
				// Ensure the payload uses only the selected service
				if ( $chosen_service_id ) {
					$session_data['service']['serviceIds'] = [ $chosen_service_id ];
					$session_data['_selected_service_id']  = $chosen_service_id;
				}

				// 4. Save to order meta
				$order->add_meta_data( '_speedy_order_data', $session_data );

				if ( isset( $session_data['recipient']['pickupOfficeId'] ) ) {
					$order->add_meta_data( '_speedy_office_id', $session_data['recipient']['pickupOfficeId'] );
				}
			}
		}

		/**
		 * Processes and saves shipping method options in the admin area.
		 *
		 * Validates credentials against the Speedy API BEFORE saving.
		 * If invalid, only the basic fields are kept and the credentials are cleared.
		 */
		public function process_admin_options(): bool {

			// Get the posted data before saving
			$post_data = $this->get_post_data();
			$file_field_key = $this->get_field_key( 'fileceni' );

			// Check if a file path was submitted via the hidden input (AJAX upload)
			if ( ! empty( $post_data[ $file_field_key ] ) ) {
				// Sanitize the path
				$post_data[ $file_field_key ] = sanitize_text_field( $post_data[ $file_field_key ] );
			} 
			// Preserve existing value if nothing new is provided
			else {
				$existing_path = $this->get_option( 'fileceni' );
				if ( $existing_path ) {
					$post_data[ $file_field_key ] = $existing_path;
				}
			}
			
			// Update the post data with the resolved file path
			$this->set_post_data( $post_data );


			// Extract the credentials that the user just submitted
			$field_key_user = $this->get_field_key( 'speedy_username' );
			$field_key_pass = $this->get_field_key( 'speedy_password' );

			$new_username = isset( $post_data[ $field_key_user ] ) ? sanitize_text_field( $post_data[ $field_key_user ] ) : '';
			$new_password = isset( $post_data[ $field_key_pass ] ) ? sanitize_text_field( $post_data[ $field_key_pass ] ) : '';

			// Validate credentials BEFORE saving anything
			if ( $new_username && $new_password ) {
				$validation = $this->validate_speedy_credentials( $new_username, $new_password );

				if ( is_wp_error( $validation ) ) {
					$error_message = sprintf(
						/* translators: %s: error message from the Speedy API */
						__( 'Speedy API authentication failed: %s', 'modern-shipping-for-speedy' ),
						$validation->get_error_message()
					);

					$this->add_error(
						$error_message . ' ' . __( 'Credentials have been cleared.', 'modern-shipping-for-speedy' )
					);

					// Blank out the credentials in the post data so they save as empty
					$post_data[ $field_key_user ] = '';
					$post_data[ $field_key_pass ] = '';
					$this->set_post_data( $post_data );

					// Strip authenticated fields so their values don't get saved
					$basic_keys = [ 'section_api', 'enabled', 'title', 'speedy_username', 'speedy_password', 'info_msg' ];
					$this->instance_form_fields = array_intersect_key(
						$this->instance_form_fields,
						array_flip( $basic_keys )
					);

					// Now let parent save only the basic (credential) fields
					$saved = parent::process_admin_options();

					// The parent's init_instance_settings() loaded ALL old values from the DB.
					// Even though we stripped instance_form_fields, stale authenticated
					// field values remain in instance_settings and were written back.
					// Clean them out now by keeping only the basic keys.
					$option_key      = $this->get_instance_option_key();
					$saved_settings  = get_option( $option_key, [] );
					$clean_settings  = array_intersect_key( $saved_settings, array_flip( $basic_keys ) );
					update_option( $option_key, $clean_settings, 'yes' );

					$this->clear_speedy_cache();

					return $saved;
				}
			}

			// Credentials are valid (or empty) — save everything normally
			$saved = parent::process_admin_options();
			$this->clear_speedy_cache();

			// Trigger background sync if credentials are present
			if ( $saved && $this->get_option('speedy_username') && $this->get_option('speedy_password') ) {
				if ( function_exists( 'as_schedule_single_action' ) ) {
					// Cancel any pending sync to avoid duplicates
					if ( function_exists( 'as_unschedule_action' ) ) {
						as_unschedule_action( 'speedy_modern_sync_locations_event' );
					}
					// Schedule new sync immediately
					as_schedule_single_action( time(), 'speedy_modern_sync_locations_event' );
				}
			}

			return $saved;
		}

		/**
		 * Validate Speedy API credentials by making a lightweight contract call.
		 *
		 * @param string $username Speedy username.
		 * @param string $password Speedy password.
		 *
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_speedy_credentials( string $username, string $password ): bool|WP_Error {
			$body = wp_json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$response = wp_remote_post( 'https://api.speedy.bg/v1/client/contract', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			] );

			// Network / cURL error
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'speedy_connection_error',
					$response->get_error_message()
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// Speedy returns 401 for bad credentials
			if ( 401 === $code || ( isset( $data['error'] ) ) ) {
				$api_message = $data['error']['message']
					?? $data['error']
					?? __( 'Invalid username or password.', 'modern-shipping-for-speedy' );

				return new WP_Error( 'speedy_auth_failed', $api_message );
			}

			// Any non-200 response
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'speedy_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Unexpected API response (HTTP %d).', 'modern-shipping-for-speedy' ),
						$code
					)
				);
			}

			// If we got clients back, credentials are valid
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				return true;
			}

			return new WP_Error(
				'speedy_unexpected_response',
				__( 'The API returned an unexpected response. Please check your credentials.', 'modern-shipping-for-speedy' )
			);
		}

        /**
         * Validate the sender_city field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_city_field( string $_key, string $value ): string {
			return sanitize_text_field( $value );
		}

        /**
         * Validate the sender_office field.
         *
         * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
         * We simply return the value (sanitized).
         *
         * @param string $_key
         * @param string $value
         * @return string
         */
		public function validate_sender_office_field( string $_key, string $value ): string {
			return sanitize_text_field( $value );
		}

		/**
		 * Define the settings fields
		 */
		public function init_form_fields(): void {

			$this->instance_form_fields = array(
				// --- SECTION: CONNECTION ---
				'section_api' => [
					'title' => __( 'Speedy API Connection', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'enabled' => [
					'title'   => __( 'Module Status', 'modern-shipping-for-speedy' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable/Disable', 'modern-shipping-for-speedy' ),
					'default' => 'yes',
				],
				'title' => [
					'title'       => __( 'Method Title', 'modern-shipping-for-speedy' ),
					'type'        => 'text',
					'default'     => __( 'Speedy Delivery', 'modern-shipping-for-speedy' ),
					'desc_tip'    => true,
				],
				'speedy_username' => [
					'title' => __( 'Username', 'modern-shipping-for-speedy' ),
					'type'  => 'text'
				],
				'speedy_password' => [
					'title' => __( 'Password', 'modern-shipping-for-speedy' ),
					'type'  => 'password',
				],
			);

			// Only show advanced settings if API credentials are saved
			if ( $this->get_option('speedy_username') && $this->get_option('speedy_password') ) {
				$this->add_authenticated_fields();
			} else {
				$this->instance_form_fields['info_msg'] = [
					'type'        => 'title',
					'description' => __( 'Please save your credentials to unlock shipping options.', 'modern-shipping-for-speedy' ),
				];
			}
		}

		/**
		 * Fields that require a valid API connection.
		 * These are merged into the main form_fields array.
		 */
		private function add_authenticated_fields(): void {
			
			$current_city = $this->get_instance_option( 'sender_city' );
			
			// Workaround: If a new city is posted, add it to options so validation passes
			// even if validate_sender_city_field is somehow bypassed or fails.
			$field_key = $this->get_field_key( 'sender_city' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_city = sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) );
				if ( $posted_city ) {
					$current_city = $posted_city;
				}
			}

			$current_office = $this->get_instance_option( 'sender_office' );
			$field_key_office = $this->get_field_key( 'sender_office' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in process_admin_options.
			if ( isset( $_POST[ $field_key_office ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$posted_office = sanitize_text_field( wp_unslash( $_POST[ $field_key_office ] ) );
				if ( $posted_office ) {
					$current_office = $posted_office;
				}
			}

			$authenticated = [

				// --- SECTION: SENDER DETAILS ---
				'section_sender' => [
					'title' => __( 'Sender Information', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'sender_id' => [
					'title'   => __( 'Sender (Object)', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'options' => $this->get_speedy_clients(),
				],
				'sender_name' => [
					'title' => __( 'Contact Person', 'modern-shipping-for-speedy' ),
					'type'  => 'text'
				],
				'sender_email' => [
					'title' => __( 'Email', 'modern-shipping-for-speedy' ),
					'type'  => 'email'
				],
				'sender_phone' => [
					'title' => __( 'Phone Number', 'modern-shipping-for-speedy' ),
					'type'  => 'text'
				],
				'sender_city' => [
					'title'   => __( 'City', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'class'   => 'speedy-city-search',
					'options' => [ $current_city => speedy_modern_get_city_name_by_id( $current_city ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for a city...', 'modern-shipping-for-speedy' ),
					],
				],
				'sender_officeyesno' => [
					'title'   => __( 'Send from Office', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'sender_office' => [
					'title'   => __( 'Shipping from Office', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'class'   => 'speedy-office-search',
					'options' => [ $current_office => speedy_modern_get_office_label_by_id( $current_office ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for an office...', 'modern-shipping-for-speedy' ),
					],
				],
				'sender_time' => [
					'title'       => __( 'Working Day End Time', 'modern-shipping-for-speedy' ),
					'type'        => 'text',
					'placeholder' => '17:30',
					'description' => __( 'Format HH:MM', 'modern-shipping-for-speedy' ),
				],

				// --- SECTION: SHIPMENT SETTINGS ---
				'section_shipment' => [
					'title' => __( 'Shipment Settings', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'uslugi' => [
					'title'    => __( 'Active Services', 'modern-shipping-for-speedy' ),
					'type'     => 'multiselect',
					'options'  => $this->get_speedy_services(),
					'default'  => '505',
				],
				'opakovka' => [
					'title'   => __( 'Packaging', 'modern-shipping-for-speedy' ),
					'type'    => 'text',
					'default' => 'BOX'
				],
				'teglo' => [
					'title'   => __( 'Default Weight', 'modern-shipping-for-speedy' ),
					'type'    => 'number',
					'default' => '1'
				],
				'obqvena' => [
					'title'   => __( 'Declared Value', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'chuplivost' => [
					'title'   => __( 'Fragile', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'saturdayoption' => [
					'title'   => __( 'Saturday Delivery', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'special_requirements' => [
					'title'    => __( 'Special Requirements', 'modern-shipping-for-speedy' ),
					'type'     => 'select',
					'default'  => '0',
					'options'  => $this->get_speedy_special_requirements(),
				],

				// --- SECTION: PRICING & PAYMENT ---
				'section_pricing' => [
					'title' => __( 'Pricing & Payment', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'cenadostavka' => [
					'title'   => __( 'Pricing Method', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'speedycalculator',
					'options' => [
						'speedycalculator' => __( 'Speedy Calculator', 'modern-shipping-for-speedy' ),
						'fixedprices'      => __( 'Fixed Price', 'modern-shipping-for-speedy' ),
						'freeshipping'     => __( 'Free Shipping', 'modern-shipping-for-speedy' ),
						'fileprices'       => __( 'Custom Prices', 'modern-shipping-for-speedy' ),
						'nadbavka'         => __( 'Calculator + Surcharge', 'modern-shipping-for-speedy' ),
					],
				],
				'suma_nadbavka'          => [
					'title'        => __( 'Surcharge Amount', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
					'custom_class' => 'suma-nadbavka',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fileceni'               => [
					'title'        => __( 'CSV Price File', 'modern-shipping-for-speedy' ),
					'type'         => 'text', // Changed to text for JS handling
					'class'        => 'speedy-file-input-wrapper', // Hook for JS
					'description'  => __( 'Path to CSV file with custom prices', 'modern-shipping-for-speedy' ),
				],
				'free_shipping' => [
					'title'       => __( 'Free Shipping', 'modern-shipping-for-speedy' ),
					'description' => __( 'Sum ABOVE the specified here activates free shipping to office/address. Explanation: If you want users to receive free shipping when reaching X amount - enter it with 0.01 less in the respective field. For example, for free shipping when reaching 100lv - enter 99.99 etc.', 'modern-shipping-for-speedy' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'free_shipping_automat'  => [
					'title'        => __( 'Free Shipping to Automat > Amount', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_office'   => [
					'title'        => __( 'Free Shipping to Office > Amount', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_address'  => [
					'title'        => __( 'Free Shipping to Address > Amount', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping' => [
					'title'       => __( 'Fixed Shipping Price', 'modern-shipping-for-speedy' ),
					'description' => __( 'Enable fixed shipping price to office/address', 'modern-shipping-for-speedy' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'fixed_shipping_automat' => [
					'title'        => __( 'Fixed Price to Automat', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
					'custom_class' => 'fixed-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_office' => [
					'title' => __( 'Fixed Price to Office', 'modern-shipping-for-speedy' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_address' => [
					'title' => __( 'Fixed Price to Address', 'modern-shipping-for-speedy' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'moneytransfer'          => [
					'title'   => __( 'Money Transfer Type', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'        => __( 'Cash on Delivery', 'modern-shipping-for-speedy' ),
						'YES'       => __( 'Postal Money Transfer', 'modern-shipping-for-speedy' ),
						'fiscal'    => __( 'Fiscal Receipt (Items)', 'modern-shipping-for-speedy' ),
						'fiscalone' => __( 'Fiscal Receipt (Groups)', 'modern-shipping-for-speedy' )
					],
				],
				'includeshippingprice'   => [
					'title'   => __( 'Include Shipping Price in COD', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'administrative'         => [
					'title'   => __( 'Administrative Fee', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],

				// --- SECTION: WORKFLOW & OPTIONS ---
				'section_options' => [
					'title' => __( 'Workflow & Options', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'generate_waybill' => [
					'title'       => __( 'Automatic Waybill', 'modern-shipping-for-speedy' ),
					'description' => __( 'Automatically create waybill on order completion', 'modern-shipping-for-speedy' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'printer'                => [
					'title'   => __( 'Label Printer', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'additionalcopy'         => [
					'title'   => __( 'Additional Waybill Copy', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'test_before_pay' => [
					'title'   => __( 'Options Before Payment', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'   => __( 'None', 'modern-shipping-for-speedy' ),
						'OPEN' => __( 'Open', 'modern-shipping-for-speedy' ),
						'TEST' => __( 'Test', 'modern-shipping-for-speedy' ),
					],
				],
				'testplatec'             => [
					'title'   => __( 'Return Shipment Payer (Test/Open)', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'SENDER',
					'options' => [
						'SENDER'    => __( 'Sender', 'modern-shipping-for-speedy' ),
						'RECIPIENT' => __( 'Recipient', 'modern-shipping-for-speedy' ),
					],
				],
				'autoclose'              => [
					'title'   => __( 'Auto Close Options at Automat', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],

				// --- SECTION: RETURNS & VOUCHERS ---
				'section_returns' => [
					'title' => __( 'Returns & Vouchers', 'modern-shipping-for-speedy' ),
					'type'  => 'title',
				],
				'vaucher' => [
					'title'   => __( 'Return Voucher', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'modern-shipping-for-speedy' ),
						'YES' => __( 'Yes', 'modern-shipping-for-speedy' ),
					],
				],
				'vaucherpayer' => [
					'title'   => __( 'Return Payer', 'modern-shipping-for-speedy' ),
					'type'    => 'select',
					'default' => 'SENDER',
					'options' => [
						'SENDER'    => __( 'Sender', 'modern-shipping-for-speedy' ),
						'RECIPIENT' => __( 'Recipient', 'modern-shipping-for-speedy' ),
					],
				],
				'vaucherpayerdays'       => [
					'title'        => __( 'Voucher Validity (Days)', 'modern-shipping-for-speedy' ),
					'type'         => 'number',
				],
			];

			$this->instance_form_fields = array_merge( $this->instance_form_fields, $authenticated );
		}

		/**
		 * Fetch available clients/contracts from Speedy API
		 *
		 * @return array Associative array of [clientId => Client Details]
		 */
		private function get_speedy_clients(): array {
			$cache_key = 'speedy_clients_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$clients   = get_transient( $cache_key );

			// If cache exists, return it immediately
			if ( false !== $clients ) {
				return $clients;
			}

			$clients = [ '0' => __( '-- Select Client --', 'modern-shipping-for-speedy' ) ];

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $clients;
			}

			// Prepare API data
			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/client/contract', $body );

			if ( null === $data ) {
				return $clients;
			}

			// Process and Format Data
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				foreach ( $data['clients'] as $client ) {
					$client_id   = $client['clientId'] ?? '';
					$client_name = $client['clientName'] ?? '';
					$object_name = $client['objectName'] ?? '';
					$address     = $client['address']['fullAddressString'] ?? '';

					$clients[ $client_id ] = sprintf(
					/* translators: 1: ID, 2: Name, 3: Object, 4: Address */
						__( 'ID: %1$s, %2$s, %3$s, Address: %4$s', 'modern-shipping-for-speedy' ),
						$client_id,
						$client_name,
						$object_name,
						$address
					);
				}

				// Cache the results for 24 hours to prevent repeated API hits
				set_transient( $cache_key, $clients, DAY_IN_SECONDS );
			}

			return $clients;
		}

		/**
		 * Get the first available office or automat ID for a specific city.
		 *
		 * @param int    $city_id
		 * @param string $type 'office' or 'automat'
		 * @return int Office ID or 0 if not found.
		 */
		public static function get_first_available_office( int $city_id, string $type ): int {
			global $wpdb;

			$like_automat = '%' . $wpdb->esc_like( 'АВТОМАТ' ) . '%';
			$like_aps     = '%' . $wpdb->esc_like( 'APS' ) . '%';
			$like_apt     = '%' . $wpdb->esc_like( 'APT' ) . '%';

			// Speedy types: APT/APS is automat, others are office.
			if ( 'automat' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}speedy_offices WHERE city_id = %d AND (office_type IN ('APT', 'APS') OR name LIKE %s OR name LIKE %s OR name LIKE %s) LIMIT 1",
						$city_id,
						$like_automat,
						$like_aps,
						$like_apt
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}speedy_offices WHERE city_id = %d AND (office_type NOT IN ('APT', 'APS') AND name NOT LIKE %s AND name NOT LIKE %s AND name NOT LIKE %s) LIMIT 1",
					$city_id,
					$like_automat,
					$like_aps,
					$like_apt
				)
			);
		}

		/**
		 * Fetch available Speedy offices from API and sort them alphabetically
		 *
		 * @param string|null $username
		 * @param string|null $password
		 * @param string|null $term
		 * @return array Associative array of [officeId => "Name - Address"]
		 */
		public static function get_speedy_offices( ?string $username = null, ?string $password = null, ?string $term = null ): array {
			$offices = [ '0' => __( '-- Select Office --', 'modern-shipping-for-speedy' ) ];

			// Try to fetch from local DB first
			global $wpdb;

			// Check if table exists and has data
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'speedy_offices' )
			);

			if ( $table_exists === $wpdb->prefix . 'speedy_offices' ) {
				if ( $term ) {
					$like_term = '%' . $wpdb->esc_like( $term ) . '%';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$db_offices = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, name, address FROM {$wpdb->prefix}speedy_offices WHERE name LIKE %s OR address LIKE %s ORDER BY name ASC LIMIT 50",
							$like_term,
							$like_term
						)
					);
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$db_offices = $wpdb->get_results(
						"SELECT id, name, address FROM {$wpdb->prefix}speedy_offices ORDER BY name ASC LIMIT 50"
					);
				}

				if ( ! empty( $db_offices ) ) {
					foreach ( $db_offices as $office ) {
						$offices[ $office->id ] = sprintf( '%s %s - %s', $office->id, $office->name, $office->address );
					}
					return $offices;
				}
			}

			// Fallback to API if DB is empty or no results found

			// If credentials are not provided, try to find them
			if ( ! $username || ! $password ) {
				$option_like = 'woocommerce_speedy_modern_%_settings';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
						$option_like
					)
				);

				if ( $rows ) {
					$settings = maybe_unserialize( $rows[0]->option_value );
					if ( is_array( $settings ) ) {
						$username = $settings['speedy_username'] ?? '';
						$password = $settings['speedy_password'] ?? '';
					}
				}
			}

			if ( ! $username || ! $password ) {
				return $offices;
			}

			// Prepare API data (Country ID 100 is Bulgaria)
			$body_data = [
				'userName'  => $username,
				'password'  => $password,
				'countryId' => 100,
			];

			if ( $term ) {
				$body_data['name'] = $term;
			}

			$body = json_encode( $body_data );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/location/office', $body );

			if ( null === $data ) {
				return $offices;
			}

			if ( isset( $data['offices'] ) && is_array( $data['offices'] ) ) {
				$temp_offices = [];

				foreach ( $data['offices'] as $office ) {
					$id      = $office['id'];
					$name    = $office['name'] ?? '';
					$address = $office['address']['fullAddressString'] ?? '';

					// We store a sort_key to handle Bulgarian (Cyrillic) sorting correctly
					$temp_offices[ $id ] = [
						'sort_key' => mb_strtoupper( $name, 'UTF-8' ),
						'label'    => sprintf( '%s %s - %s', $id, $name, $address )
					];
				}

				// Sort alphabetically by the name (sort_key)
				uasort( $temp_offices, function ( $a, $b ) {
					return strcmp( $a['sort_key'], $b['sort_key'] );
				} );

				// Flatten the array back to [ id => label ] for the WooCommerce select field
				foreach ( $temp_offices as $id => $office_data ) {
					$offices[ $id ] = $office_data['label'];
				}
			}

			return $offices;
		}

		/**
		 * Fetch available Speedy services from API
		 *
		 * @return array Associative array of [serviceId => "ID - Service Name"]
		 */
		private function get_speedy_services(): array {
			$cache_key = 'speedy_services_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$services_list = get_transient( $cache_key );

			// Return cached data if available
			if ( false !== $services_list ) {
				return $services_list;
			}

			$services_list = [];

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $services_list;
			}

			// Prepare API data
			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/services', $body );

			if ( null === $data ) {
				return $services_list;
			}

			// Process and Format Data
			if ( isset( $data['services'] ) && is_array( $data['services'] ) ) {
				foreach ( $data['services'] as $service ) {
					if ( isset( $service['id'] ) && isset( $service['name'] ) ) {
						// Format as "505 - CITY COURIER"
						$services_list[ $service['id'] ] = sprintf( '%s - %s', $service['id'], $service['name'] );
					}
				}

				// Cache for 24 hours
				set_transient( $cache_key, $services_list, DAY_IN_SECONDS );
			}

			return $services_list;
		}

		/**
		 * Get a simple service ID → name map for rate labels.
		 *
		 * Reuses the cached service list from get_speedy_services() and strips
		 * the "505 - " prefix to return just the name portion.
		 *
		 * @return array<int, string> e.g. [505 => 'CITY COURIER', 515 => 'ECONOMY']
		 */
		private function get_speedy_service_names(): array {
			$services = $this->get_speedy_services();
			$names = [];
			foreach ( $services as $id => $label ) {
				// The label is formatted as "505 - CITY COURIER"
				$parts = explode( ' - ', $label, 2 );
				$names[ (int) $id ] = $parts[1] ?? $label;
			}
			return $names;
		}

		/**
		 * Fetch Special Requirements from Speedy API
		 */
		private function get_speedy_special_requirements(): array {
			$cache_key = 'speedy_requirements_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$requirements = get_transient( $cache_key );

			if ( false !== $requirements ) {
				return $requirements;
			}

			$requirements = [ '0' => __( '-- None --', 'modern-shipping-for-speedy' ) ];
			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $requirements;
			}

			$body = json_encode( [
				'userName' => $username,
				'password' => $password,
			] );

			$data = self::speedy_curl_post( 'https://api.speedy.bg/v1/client/contract/info', $body );

			if ( null === $data ) {
				return $requirements;
			}

			if ( isset( $data['specialDeliveryRequirements']['requirements'] ) && is_array( $data['specialDeliveryRequirements']['requirements'] ) ) {
				foreach ( $data['specialDeliveryRequirements']['requirements'] as $req ) {
					// Use ID if available, otherwise use text as key (not ideal but fallback)
					$id = $req['id'] ?? $req['text'];
					$text = $req['text'] ?? $id;
					
					if ( $id && $text ) {
						$requirements[ $id ] = $text;
					}
				}
				set_transient( $cache_key, $requirements, DAY_IN_SECONDS );
			}

			return $requirements;
		}

		/**
		 * Clears all cached API data for this specific user
		 */
		private function clear_speedy_cache(): void {
			$user_hash = md5( $this->get_option( 'speedy_username' ) );

			delete_transient( 'speedy_clients_cache_' . $user_hash );
			delete_transient( 'speedy_offices_cache_' . $user_hash );
			delete_transient( 'speedy_services_cache_' . $user_hash );
			delete_transient( 'speedy_requirements_cache_' . $user_hash );
		}

		/**
		 * Calculate the shipping rate.
		 *
		 * Replicates the pricing logic from the legacy woocommerce-speedy-shipping
		 * plugin: ALWAYS calls the Speedy /v1/calculate API (for session storage
		 * and shipment validation), then overrides the displayed cost according
		 * to the configured pricing method (free, fixed, file, calculator, surcharge).
		 */
		public function calculate_shipping( $package = array() ): void {
			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				// No credentials – still show the method with zero cost
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 1. Parse checkout data ---
			$checkout       = $this->parse_checkout_post_data();
			$delivery_type  = $checkout['delivery_type']; // 'address', 'office', 'automat'
			$office_id      = $checkout['office_id'];
			$city_id        = $checkout['city_id'];
			$payment_method = $checkout['payment_method'];

			// Persist selection to session (but NOT the auto-picked office below)
			if ( WC()->session ) {
				WC()->session->set( 'speedy_modern_city_id', $city_id );
				WC()->session->set( 'speedy_modern_delivery_type', $delivery_type );
				if ( $office_id > 0 ) {
					WC()->session->set( 'speedy_modern_office_id', $office_id );
				}
			}

			// For office/automat delivery without a specific office chosen,
			// pick the first available one just for the price calculation.
			// Only on the cart page — on checkout the user selects an office.
			if ( is_cart() && in_array( $delivery_type, [ 'office', 'automat' ], true ) && empty( $office_id ) ) {
				$office_id = self::get_first_available_office( $city_id, $delivery_type );
			}

			// Final validation: if we still have no city ID for address delivery, try one last session lookup
			if ( 'address' === $delivery_type && empty( $city_id ) && WC()->session ) {
				$city_id = absint( WC()->session->get( 'speedy_modern_city_id', 0 ) );
			}

			// For address delivery, we need a city. 
			if ( 'address' === $delivery_type && empty( $city_id ) ) {
				// Clear stale session data
				if ( WC()->session ) {
					WC()->session->set( 'speedy_modern_service_options', [] );
					WC()->session->set( 'speedy_modern_shipping_cost', 0 );
				}
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// For office/automat delivery on checkout, we need the user to select an office.
			if ( ! is_cart() && in_array( $delivery_type, [ 'office', 'automat' ], true ) && empty( $office_id ) ) {
				if ( WC()->session ) {
					WC()->session->set( 'speedy_modern_service_options', [] );
					WC()->session->set( 'speedy_modern_shipping_cost', 0 );
				}
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 2. Determine weight ---
			$order_weight = $this->resolve_weight( $package );

			// --- 3. Determine order total & subtotal ---
			// Note: during calculate_shipping(), WC_Cart_Totals has NOT yet
			// called calculate_totals(), so get_totals()['total'] is still 0
			// on the initial cart load.  Use get_subtotal() (items only) which
			// is always available because item subtotals are calculated first.
			$subtotal    = 0.0;
			$order_total = 0.0;
			if ( WC()->cart ) {
				$subtotal    = (float) WC()->cart->get_subtotal();
				$order_total = $subtotal;
			}

			$is_cod          = ( 'cod' === $payment_method );
			$cenadostavka    = $this->get_option( 'cenadostavka', 'speedycalculator' );

			// --- 4. Determine pricing overrides BEFORE building the API payload ---
			$is_free_shipping = false;
			$override_cost    = null; // null = use API price; float = use this value

			// 4a. Free shipping threshold (checkbox + per-type amount)
			if ( 'yes' === $this->get_option( 'free_shipping' ) ) {
				$threshold = $this->get_free_shipping_threshold( $delivery_type );
				if ( $threshold > 0 && $order_total > $threshold ) {
					$is_free_shipping = true;
					$override_cost    = 0.0;
				}
			}

			// 4b. Always-free pricing mode
			if ( 'freeshipping' === $cenadostavka ) {
				$is_free_shipping = true;
				$override_cost    = 0.0;
			}

			// 4c. Fixed shipping price
			$fixed_price = 0.0;
			$is_fixed    = false;
			if ( ! $is_free_shipping && ( 'fixedprices' === $cenadostavka || 'yes' === $this->get_option( 'fixed_shipping' ) ) ) {
				$fixed_price = $this->get_fixed_price( $delivery_type );
				if ( $fixed_price > 0 ) {
					$is_fixed      = true;
					$override_cost = $fixed_price;
				}
			}

			// 4d. CSV file prices
			$file_cost = false;
			$is_file   = false;
			if ( ! $is_free_shipping && ! $is_fixed && 'fileprices' === $cenadostavka ) {
				$file_cost = $this->get_csv_file_price( $delivery_type, $order_weight, $subtotal );
				if ( false !== $file_cost ) {
					$is_file       = true;
					$override_cost = $file_cost;
				}
			}

			// --- 5. Build the payload (always needed for session / waybill data) ---
			$payload = $this->build_api_calculate_payload(
				$delivery_type,
				$office_id,
				$city_id,
				$order_weight,
				$order_total,
				$subtotal,
				$is_cod,
				$payment_method,
				$is_free_shipping,
				$is_fixed ? $fixed_price : null,
				$is_file ? $file_cost : null
			);

			if ( empty( $payload ) ) {
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => 0,
					'meta_data' => [ 'missing_address' => true ],
				] );
				return;
			}

			// --- 6. Determine final cost ---
			// When we already know the price (free / fixed / file), skip the API
			// call entirely — it adds latency and its errors are irrelevant.
			// The API is only needed for 'speedycalculator' and 'nadbavka' modes.
			if ( null !== $override_cost ) {
				$final_cost = $override_cost;

				// Store cost in session
				if ( WC()->session ) {
					WC()->session->set( 'speedy_modern_shipping_cost', $final_cost );
					WC()->session->set( 'speedy_modern_shipping_data', $payload );
					// Clear service options — no service selector for override pricing
					WC()->session->set( 'speedy_modern_service_options', [] );
				}

				// Append "free shipping" hint to the label
				$label = $this->title;
				if ( $is_free_shipping ) {
					$label .= ' (' . __( 'Free shipping', 'modern-shipping-for-speedy' ) . ')';
				}

				$this->add_rate( [
					'id'    => $this->get_rate_id(),
					'label' => $label,
					'cost'  => number_format( $final_cost, 2, '.', '' ),
				] );
			} else {
				// ── Quick-path: if the session already has priced service options
				// (e.g. the user just switched the service dropdown) and the
				// underlying checkout inputs haven't changed, reuse them.
				$cached_service_options = WC()->session ? WC()->session->get( 'speedy_modern_service_options', [] ) : [];
				$selected_service       = WC()->session ? (int) WC()->session->get( 'speedy_modern_selected_service', 0 ) : 0;

				if ( ! empty( $cached_service_options ) && $selected_service && isset( $cached_service_options[ $selected_service ] ) ) {
					// Verify the cache is still relevant: same delivery type + city/office
					$cached_payload = WC()->session->get( 'speedy_modern_shipping_data' );
					$cache_hit      = false;

					if ( $cached_payload ) {
						// Determine the delivery type of the cached payload
						$cached_is_office  = ! empty( $cached_payload['recipient']['pickupOfficeId'] );
						$cached_is_address = ! empty( $cached_payload['recipient']['addressLocation']['siteId'] );

						// The delivery type must match between cached and current request
						if ( 'address' === $delivery_type && $cached_is_address ) {
							$cache_hit = ( (int) $cached_payload['recipient']['addressLocation']['siteId'] === $city_id );
						} elseif ( in_array( $delivery_type, [ 'office', 'automat' ], true ) && $cached_is_office ) {
							$cache_hit = ( (int) $cached_payload['recipient']['pickupOfficeId'] === $office_id );
						}
						// If delivery type doesn't match (e.g. cached=address, current=office),
						// $cache_hit stays false → forces a fresh API call.

						// The payment method affects courierServicePayer (SENDER vs RECIPIENT),
						// which changes the API price. Invalidate cache on payment method change.
						if ( $cache_hit ) {
							$cached_payer  = $cached_payload['payment']['courierServicePayer'] ?? '';
							$current_payer = $payload['payment']['courierServicePayer'] ?? '';
							if ( $cached_payer !== $current_payer ) {
								$cache_hit = false;
							}
						}
					}

					if ( $cache_hit ) {
						$active_cost = $cached_service_options[ $selected_service ]['cost'];

						$this->add_rate( [
							'id'        => $this->get_rate_id(),
							'label'     => $this->title,
							'cost'      => number_format( (float) $active_cost, 2, '.', '' ),
							'meta_data' => [ 'speedy_service_id' => $selected_service ],
						] );
						return;
					}
				}

				// We need the Speedy API price — call the calculator.
				$payload['userName'] = $username;
				$payload['password'] = $password;

				$response = $this->call_speedy_calculate_api( $payload );

				if ( is_wp_error( $response ) ) {
					$fallback = WC()->session ? WC()->session->get( 'speedy_modern_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Check for top-level API error
				if ( ! empty( $response['error'] ) ) {
					$fallback = WC()->session ? WC()->session->get( 'speedy_modern_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Collect all successful calculations
				$successful_calcs = [];
				if ( ! empty( $response['calculations'] ) ) {
					foreach ( $response['calculations'] as $calc ) {
						if ( isset( $calc['price']['total'] ) && isset( $calc['serviceId'] ) ) {
							$successful_calcs[] = $calc;
						}
					}
				}

				if ( empty( $successful_calcs ) ) {
					$fallback = WC()->session ? WC()->session->get( 'speedy_modern_shipping_cost', 0 ) : 0;
					$this->add_rate( [
						'id'    => $this->get_rate_id(),
						'label' => $this->title,
						'cost'  => number_format( (float) $fallback, 2, '.', '' ),
					] );
					return;
				}

				// Build a service ID → name map from the settings
				$service_names = $this->get_speedy_service_names();

				// Remove credentials before storing in session
				$session_payload = $payload;
				unset( $session_payload['userName'], $session_payload['password'] );

				// ── Build service options for the frontend ──
				// Determine which service to select by default.
				// If the user already picked one (stored in session), keep it.
				$selected_service = WC()->session ? (int) WC()->session->get( 'speedy_modern_selected_service', 0 ) : 0;

				$service_options = [];
				$first_service_id = null;
				$default_cost     = null;

				foreach ( $successful_calcs as $calc ) {
					$service_id = (int) $calc['serviceId'];
					$api_total  = (float) $calc['price']['total'];

					$final_cost = $api_total;
					if ( 'nadbavka' === $cenadostavka ) {
						$final_cost += (float) $this->get_option( 'suma_nadbavka', 0 );
					}

					$service_name = $service_names[ $service_id ]
						?? ( __( 'Service', 'modern-shipping-for-speedy' ) . ' ' . $service_id );

					$service_options[ $service_id ] = [
						'id'   => $service_id,
						'name' => $service_name,
						'cost' => round( $final_cost, 2 ),
					];

					if ( null === $first_service_id ) {
						$first_service_id = $service_id;
						$default_cost     = $final_cost;
					}

					// Store a per-service payload in session for waybill generation
					$svc_payload = $session_payload;
					$svc_payload['service']['serviceIds'] = [ $service_id ];
					$svc_payload['_selected_service_id']  = $service_id;

					if ( WC()->session ) {
						WC()->session->set( 'speedy_modern_shipping_data_' . $service_id, $svc_payload );
					}
				}

				// If the previously-selected service is still available, keep it
				if ( $selected_service && isset( $service_options[ $selected_service ] ) ) {
					$active_service_id = $selected_service;
					$active_cost       = $service_options[ $selected_service ]['cost'];
				} else {
					$active_service_id = $first_service_id;
					$active_cost       = $default_cost;
				}

				// Store in session so JS can render a service selector
				if ( WC()->session ) {
					WC()->session->set( 'speedy_modern_service_options', $service_options );
					WC()->session->set( 'speedy_modern_shipping_cost', $active_cost );
					WC()->session->set( 'speedy_modern_selected_service', $active_service_id );

					$active_payload = $session_payload;
					$active_payload['service']['serviceIds'] = [ $active_service_id ];
					$active_payload['_selected_service_id']  = $active_service_id;
					WC()->session->set( 'speedy_modern_shipping_data', $active_payload );
				}

				// Always add a SINGLE rate – service selection is handled in our custom UI
				$this->add_rate( [
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => number_format( $active_cost, 2, '.', '' ),
					'meta_data' => [ 'speedy_service_id' => $active_service_id ],
				] );
			}
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Parse checkout POST data
		 * ─────────────────────────────────────────────────── */

		/**
		 * Extract Speedy-specific fields from the checkout AJAX request.
		 *
		 * WooCommerce sends checkout form data as a URL-encoded string in
		 * $_POST['post_data'] during AJAX shipping updates. During final
		 * order placement, fields are at the top level of $_POST.
		 *
		 * @return array {
		 *     @type string $delivery_type  'address', 'office', or 'automat'
		 *     @type int    $office_id      Speedy office/automat ID (0 if none)
		 *     @type int    $city_id        Speedy city (site) ID (0 if none)
		 *     @type string $payment_method WC payment method slug
		 * }
		 */
		private function parse_checkout_post_data(): array {
			$data = [];

			// During AJAX updates, form data comes as URL-encoded string
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- post_data is a URL-encoded string; individual values are sanitized below.
			if ( ! empty( $_POST['post_data'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				parse_str( wp_unslash( $_POST['post_data'] ), $data );
			}

			// During final checkout, or if post_data is missing, check top-level $_POST
			// phpcs:ignore WordPress.Security.NonceVerification
			$merged = array_merge( $data, $_POST );

			// post_data is present on checkout AJAX (update_order_review), absent on cart form submit.
			// phpcs:ignore WordPress.Security.NonceVerification
			$has_post_data = ! empty( $_POST['post_data'] );

			// Determine which address context to use (billing or shipping)
			$ship_to_different = ! empty( $merged['ship_to_different_address'] );
			$context = $ship_to_different ? 'shipping' : 'billing';

			// Delivery Type
			$delivery_type = sanitize_text_field( $merged['speedy_delivery_type'] ?? '' );
			if ( empty( $delivery_type ) && WC()->session ) {
				$delivery_type = WC()->session->get( 'speedy_modern_delivery_type', 'address' );
			}
			if ( empty( $delivery_type ) ) {
				$delivery_type = 'address';
			}

			// Office ID
			$office_id = absint( $merged['speedy_office_id'] ?? 0 );
			// On the cart page, fall back to session (set by get_first_available_office).
			// On checkout, do NOT fall back — the user must select an office explicitly.
			if ( $office_id === 0 && WC()->session && ! $has_post_data ) {
				$office_id = absint( WC()->session->get( 'speedy_modern_office_id', 0 ) );
			}

			// City ID: the checkout.js replaces the city input with a <select> whose
			// name is "{context}_city" and value is the Speedy siteId.
			$city_id = 0;

			// 1. Try checkout fields (Speedy IDs are numbers)
			if ( ! empty( $merged[ $context . '_city' ] ) && is_numeric( $merged[ $context . '_city' ] ) ) {
				$city_id = absint( $merged[ $context . '_city' ] );
			} elseif ( ! empty( $merged['billing_city'] ) && is_numeric( $merged['billing_city'] ) ) {
				$city_id = absint( $merged['billing_city'] );
			}
			// 2. Try cart calculator fields
			elseif ( ! empty( $merged['calc_shipping_city'] ) && is_numeric( $merged['calc_shipping_city'] ) ) {
				$city_id = absint( $merged['calc_shipping_city'] );
			}
			// 3. Try customer session as last resort
			elseif ( WC()->session ) {
				$city_id = absint( WC()->session->get( 'speedy_modern_city_id', 0 ) );
				if ( ! $city_id && WC()->customer ) {
					$session_city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
					if ( is_numeric( $session_city ) ) {
						$city_id = absint( $session_city );
					}
				}
			}

			return [
				'delivery_type'  => $delivery_type,
				'office_id'      => $office_id,
				'city_id'        => $city_id,
				'payment_method' => sanitize_text_field( $merged['payment_method'] ?? '' ),
			];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve package weight
		 * ─────────────────────────────────────────────────── */

		/**
		 * Determine the shipment weight.
		 *
		 * If the admin set a fixed "teglo" (weight) setting, use it.
		 * Otherwise compute from the cart contents, falling back to 1 kg.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return float Weight in kg.
		 */
		private function resolve_weight( array $package ): float {
			$fixed_weight = $this->get_option( 'teglo' );

			// If teglo is non-empty AND not zero, use it as a fixed override
			if ( '' !== $fixed_weight && '0' !== $fixed_weight && (float) $fixed_weight > 0 ) {
				return (float) $fixed_weight;
			}

			// Calculate from cart/package contents
			$weight = 0.0;
			if ( ! empty( $package['contents'] ) ) {
				foreach ( $package['contents'] as $item ) {
					$product = $item['data'];
					$weight += (float) $product->get_weight() * $item['quantity'];
				}
			}

			// Fallback: use WC cart weight (covers edge cases)
			if ( $weight <= 0 && WC()->cart ) {
				$weight = WC()->cart->get_cart_contents_weight();
			}

			// Final fallback: 1 kg
			return $weight > 0 ? $weight : 1.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Free shipping threshold for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the free shipping threshold for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Threshold amount (0 = disabled).
		 */
		private function get_free_shipping_threshold( string $delivery_type ): float {
			$map = [
				'office'  => 'free_shipping_office',
				'automat' => 'free_shipping_automat',
				'address' => 'free_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Fixed price for delivery type
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the fixed shipping price for a specific delivery type.
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @return float Fixed price (0 = not set).
		 */
		private function get_fixed_price( string $delivery_type ): float {
			$map = [
				'office'  => 'fixed_shipping_office',
				'automat' => 'fixed_shipping_automat',
				'address' => 'fixed_shipping_address',
			];

			$key = $map[ $delivery_type ] ?? '';
			return $key ? (float) $this->get_option( $key, 0 ) : 0.0;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: CSV file-based pricing
		 * ─────────────────────────────────────────────────── */

		/**
		 * Look up shipping cost from a user-uploaded CSV price file.
		 *
		 * CSV format (header + data rows):
		 *   service_id, take_from_office, weight, order_total, price
		 *
		 * take_from_office: 0 = address, 1 = office, 2 = automat
		 *
		 * @param string $delivery_type 'address', 'office', or 'automat'
		 * @param float  $weight        Shipment weight.
		 * @param float  $subtotal      Cart subtotal.
		 * @return float|false Price from CSV, or false if no match found.
		 */
		private function get_csv_file_price( string $delivery_type, float $weight, float $subtotal ): float|false {
			// Try instance option first, then legacy global option
			$file_path = $this->get_option( 'fileceni' );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				$file_path = get_option( 'speedy_fileceni_path' );
			}

			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				return false;
			}

			// Map delivery type to CSV column value
			$type_map = [
				'address' => 0,
				'office'  => 1,
				'automat' => 2,
			];
			$take_from_office = $type_map[ $delivery_type ] ?? 0;

			// Use WP_Filesystem to read the file.
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() || ! $wp_filesystem ) {
				return false;
			}

			$csv_content = $wp_filesystem->get_contents( $file_path );
			if ( false === $csv_content || empty( $csv_content ) ) {
				return false;
			}

			$lines = explode( "\n", $csv_content );
			if ( empty( $lines ) ) {
				return false;
			}

			// Skip header row
			array_shift( $lines );

			$best_fit_price       = null;
			$best_fit_order_total = null;

			foreach ( $lines as $line ) {
				if ( empty( trim( $line ) ) ) {
					continue;
				}

				$row = str_getcsv( $line, ',', '"', '' );
				if ( count( $row ) < 5 ) {
					continue;
				}

				list( $_csv_service_id, $csv_take_from_office, $csv_weight, $csv_order_total, $csv_price ) = $row;

				if (
					(int) $csv_take_from_office === $take_from_office &&
					$weight <= (float) $csv_weight &&
					$subtotal <= (float) $csv_order_total
				) {
					// Pick the row with the smallest csv_order_total that still covers this order
					if ( null === $best_fit_order_total || (float) $csv_order_total < $best_fit_order_total ) {
						$best_fit_order_total = (float) $csv_order_total;
						$best_fit_price       = (float) $csv_price;
					}
				}
			}

			return $best_fit_price;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build full Speedy /v1/calculate payload
		 * ─────────────────────────────────────────────────── */

		/**
		 * Assemble the Speedy API calculate request body.
		 *
		 * The old plugin always sends the full payload to the API even when
		 * the final cost will be overridden (free / fixed / file). When a
		 * pricing override is active, the courier payer is forced to SENDER
		 * and the COD amount is adjusted to subtotal + shipping cost.
		 *
		 * @param string     $delivery_type    'address', 'office', or 'automat'
		 * @param int        $office_id        Speedy office/automat ID.
		 * @param int        $city_id          Speedy city (site) ID.
		 * @param float      $order_weight     Shipment weight in kg.
		 * @param float      $order_total      Order total minus shipping.
		 * @param float      $subtotal         Cart subtotal.
		 * @param bool       $is_cod           Whether payment is Cash on Delivery.
		 * @param string     $_payment_method  WC payment method slug.
		 * @param bool       $is_free          Whether free shipping is active.
		 * @param float|null $fixed_price      Fixed shipping cost (null = not active).
		 * @param float|null $file_price       CSV file shipping cost (null = not active).
		 * @return array The API request payload (without credentials).
		 */
		private function build_api_calculate_payload(
			string $delivery_type,
			int $office_id,
			int $city_id,
			float $order_weight,
			float $order_total,
			float $subtotal,
			bool $is_cod,
			string $_payment_method,
			bool $is_free = false,
			?float $fixed_price = null,
			?float $file_price = null
		): array {

			$has_price_override = $is_free || null !== $fixed_price || null !== $file_price;

			// ── Sender ──
			// The Speedy /v1/calculate API requires sender data to determine
			// pricing based on the sender's contract and location. This matches
			// the old plugin behavior which always includes full sender details.
			$sender = [];

			$sender_id = (int) $this->get_option( 'sender_id' );
			if ( $sender_id > 0 ) {
				$sender['clientId'] = $sender_id;
			}

			$sender_phone = $this->get_option( 'sender_phone' );
			if ( ! empty( $sender_phone ) ) {
				$sender['phone1'] = [ 'number' => $sender_phone ];
			}

			$sender_name = $this->get_option( 'sender_name' );
			if ( ! empty( $sender_name ) ) {
				$sender['contactName'] = $sender_name;
			}

			$sender_email = $this->get_option( 'sender_email' );
			if ( ! empty( $sender_email ) ) {
				$sender['email'] = $sender_email;
			}

			if ( 'YES' === $this->get_option( 'sender_officeyesno' ) ) {
				$drop_off = (int) $this->get_option( 'sender_office' );
				if ( $drop_off > 0 ) {
					$sender['dropoffOfficeId'] = $drop_off;
				}
			}

			// If no sender data was set, send empty object so JSON encodes as {}
			if ( empty( $sender ) ) {
				$sender = new stdClass();
			}

			// ── Recipient ──
			$recipient = [
				'privatePerson' => true,
			];

			if ( in_array( $delivery_type, [ 'office', 'automat' ], true ) ) {
				$recipient['pickupOfficeId'] = $office_id;
			} else {
				$recipient['addressLocation'] = [
					'siteId' => $city_id,
				];
			}

			// ── Service ──
			$service_ids = $this->get_option( 'uslugi', [] );
			if ( ! is_array( $service_ids ) ) {
				$service_ids = array_map( 'intval', explode( ',', $service_ids ) );
			} else {
				$service_ids = array_map( 'intval', $service_ids );
			}
			// Remove zeroes and ensure we have at least one service
			$service_ids = array_values( array_filter( $service_ids ) );
			if ( empty( $service_ids ) ) {
				$service_ids = [ 505 ]; // Default: Standard courier
			}

			$service = [
				'autoAdjustPickupDate' => true,
				'serviceIds'           => $service_ids,
			];

			if ( 'YES' === $this->get_option( 'saturdayoption' ) ) {
				$service['saturdayDelivery'] = true;
			}

			// ── Content ──
			$content = [
				'parcelsCount' => 1,
				'totalWeight'  => $order_weight,
			];

			// ── Payment ──
			$include_shipping_in_cod = ( 'YES' === $this->get_option( 'includeshippingprice' ) );

			// Old plugin logic: payer is RECIPIENT only when ALL of:
			//   1. COD payment
			//   2. includeshippingprice is not YES
			//   3. cenadostavka is 'speedycalculator' or 'nadbavka' (pure API pricing)
			// For all other pricing modes (fileprices, fixedprices, freeshipping)
			// or when includeshippingprice is YES, use SENDER — matching old plugin.
			$cenadostavka_mode = $this->get_option( 'cenadostavka', 'speedycalculator' );
			$api_pricing_modes = [ 'speedycalculator', 'nadbavka' ];


			if ( $is_cod && ! $include_shipping_in_cod && in_array( $cenadostavka_mode, $api_pricing_modes, true ) && ! $has_price_override ) {
				$payment = [ 'courierServicePayer' => 'RECIPIENT' ];
			} else {
				$payment = [ 'courierServicePayer' => 'SENDER' ];
			}


			if ( 'YES' === $this->get_option( 'administrative' ) ) {
				$payment['administrativeFee'] = true;
			}

			// ── Additional Services ──

			if ( $is_cod ) {
				$money_transfer  = $this->get_option( 'moneytransfer', 'NO' );
				$processing_type = ( 'YES' === $money_transfer ) ? 'POSTAL_MONEY_TRANSFER' : 'CASH';

				// COD amount: use the items subtotal as the base.
				// During calculate_shipping(), WC()->cart->get_totals()['total']
				// is still 0 because WC_Cart_Totals hasn't called calculate_totals()
				// yet (shipping is calculated first). The subtotal (items only) is the
				// always available and is the correct base for COD.
				$cod_amount = $subtotal;

				// For fixed/file pricing with COD, the old plugin sets cod.amount
				// to subtotal + shipping cost so the courier collects the right total.
				if ( null !== $fixed_price ) {
					$cod_amount = $subtotal + $fixed_price;
				} elseif ( null !== $file_price ) {
					$cod_amount = $subtotal + $file_price;
				} elseif ( 'nadbavka' === $this->get_option( 'cenadostavka' ) ) {
					// Surcharge mode: add surcharge to COD amount
					$cod_amount += (float) $this->get_option( 'suma_nadbavka', 0 );
				}

				$service['additionalServices']['cod'] = [
					'amount'                  => $cod_amount,
					'processingType'          => $processing_type,
					'ignoreIfNotApplicable'   => true,
				];

				if ( $include_shipping_in_cod ) {
					$service['additionalServices']['cod']['includeShippingPrice'] = true;
				}

				// Declared Value (old plugin only adds this inside COD branch)
				if ( 'YES' === $this->get_option( 'obqvena' ) ) {
					$service['additionalServices']['declaredValue'] = [
						'amount'                => $order_total,
						'fragile'               => ( 'YES' === $this->get_option( 'chuplivost' ) ),
						'ignoreIfNotApplicable' => true,
					];
				}

				// Return Voucher (old plugin only adds inside COD branch)
				if ( 'YES' === $this->get_option( 'vaucher' ) ) {
					$voucher = [
						'serviceId'             => 505,
						'payer'                 => $this->get_option( 'vaucherpayer', 'SENDER' ),
						'ignoreIfNotApplicable' => true,
					];

					$validity = $this->get_option( 'vaucherpayerdays' );
					if ( ! empty( $validity ) ) {
						$voucher['validityPeriod'] = (int) $validity;
					}

					$service['additionalServices']['returns']['returnVoucher'] = $voucher;
				}

				// Special Delivery Requirements (old plugin only adds inside COD branch)
				$special_req = $this->get_option( 'special_requirements' );
				if ( ! empty( $special_req ) && '0' !== $special_req ) {
					$service['additionalServices']['specialDeliveryId'] = $special_req;
				}
			}
			// Non-COD: don't include COD in additional services at all
			// (the official Speedy API example omits COD entirely when not applicable)

			// OBPD (Test Before Pay / Open Before Pay)
			// Old plugin adds this regardless of COD status
			$obpd_option = $this->get_option( 'test_before_pay', 'NO' );
			$autoclose   = $this->get_option( 'autoclose', 'NO' );

			if (
				in_array( $obpd_option, [ 'OPEN', 'TEST' ], true ) &&
				( 'automat' !== $delivery_type || 'NO' === $autoclose )
			) {
				$service['additionalServices']['obpd'] = [
					'option'                  => $obpd_option,
					'returnShipmentServiceId' => 505,
					'returnShipmentPayer'     => ( 'OPEN' === $obpd_option )
						? ( $this->get_option( 'testplatec' ) ?: 'SENDER' )
						: 'SENDER',
					'ignoreIfNotApplicable'   => true,
				];
			}

			// ── Fiscal Receipt Items (for fiscal / fiscalone modes) ──
			$money_transfer_mode = $this->get_option( 'moneytransfer', 'NO' );
			if ( $is_cod && in_array( $money_transfer_mode, [ 'fiscal', 'fiscalone' ], true ) && WC()->cart ) {
				$cenadostavka_mode = $this->get_option( 'cenadostavka', 'speedycalculator' );
				$fiscal_items      = $this->build_fiscal_receipt_items( $money_transfer_mode, $cenadostavka_mode, $service['additionalServices']['cod']['amount'] ?? 0 );
				if ( ! empty( $fiscal_items ) ) {
					$service['additionalServices']['cod']['fiscalReceiptItems'] = $fiscal_items;
				}
			}

			return [
				'sender'    => $sender,
				'recipient' => $recipient,
				'service'   => $service,
				'content'   => $content,
				'payment'   => $payment,
			];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Build fiscal receipt items
		 * ─────────────────────────────────────────────────── */

		/**
		 * Generate fiscal receipt line items for the Speedy COD fiscal receipt.
		 *
		 * When fixedprices or fileprices is active and cod_amount exceeds the
		 * product total, a separate "Доставка" (Delivery) line is appended
		 * to cover the shipping portion – matching the old plugin behavior.
		 *
		 * @param string $mode            'fiscal' (per-item) or 'fiscalone' (per VAT group).
		 * @param string $cenadostavka    Pricing mode setting.
		 * @param float  $cod_amount      Total COD amount from the payload.
		 * @return array Array of fiscal receipt items.
		 */
		private function build_fiscal_receipt_items( string $mode, string $cenadostavka = '', float $cod_amount = 0.0 ): array {
			$fiscal_items            = [];
			$products_total_with_vat = 0.0;

			if ( 'fiscal' === $mode ) {
				// Per-product line items
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product     = $cart_item['data'];
					$qty         = $cart_item['quantity'];
					$name        = $product->get_name() . ' (x' . $qty . ')';
					$description = mb_substr( $name, 0, 50 );

					$vat_info = $this->resolve_vat_info( $product );

					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$line_with_vat = round( $price_incl_vat * $qty, 2 );
					$line_ex_vat   = round( $price_excl_vat * $qty, 2 );

					$products_total_with_vat += $line_with_vat;

					$fiscal_items[] = [
						'description'   => $description,
						'vatGroup'      => $vat_info['group'],
						'amount'        => $line_ex_vat,
						'amountWithVat' => $line_with_vat,
					];
				}
			} elseif ( 'fiscalone' === $mode ) {
				// Grouped by VAT class
				$groups = [
					'А' => [ 'ex' => 0, 'in' => 0 ], // 0%
					'Г' => [ 'ex' => 0, 'in' => 0 ], // 9%
					'Б' => [ 'ex' => 0, 'in' => 0 ], // 20%
				];

				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product = $cart_item['data'];
					$qty     = $cart_item['quantity'];

					$vat_info       = $this->resolve_vat_info( $product );
					$price_incl_vat = (float) $product->get_price();
					$price_excl_vat = $vat_info['rate'] > 0
						? $price_incl_vat / ( 1 + $vat_info['rate'] )
						: $price_incl_vat;

					$groups[ $vat_info['group'] ]['ex'] += $price_excl_vat * $qty;
					$groups[ $vat_info['group'] ]['in'] += $price_incl_vat * $qty;
				}

				foreach ( $groups as $group => $sum ) {
					if ( $sum['in'] <= 0 ) {
						continue;
					}

					$products_total_with_vat += $sum['in'];

					$fiscal_items[] = [
						/* translators: %s: VAT group identifier (e.g. А, Б, Г) */
						'description'   => sprintf( __( 'Products from order (group %s)', 'modern-shipping-for-speedy' ), $group ),
						'vatGroup'      => $group,
						'amount'        => round( $sum['ex'], 2 ),
						'amountWithVat' => round( $sum['in'], 2 ),
					];
				}
			}

			// Append a "Доставка" (Delivery) line when fixed/file pricing is used
			// and the COD amount exceeds the product total.
			if (
				in_array( $cenadostavka, [ 'fixedprices', 'fileprices' ], true ) &&
				$cod_amount > 0
			) {
				$shipping_amount_with_vat = max( 0, $cod_amount - $products_total_with_vat );

				if ( $shipping_amount_with_vat > 0 ) {
					$shipping_vat_rate      = 0.20;
					$shipping_amount_ex_vat = $shipping_amount_with_vat / ( 1 + $shipping_vat_rate );

					$fiscal_items[] = [
						'description'   => __( 'Delivery', 'modern-shipping-for-speedy' ),
						'vatGroup'      => 'Б',
						'amount'        => round( $shipping_amount_ex_vat, 2 ),
						'amountWithVat' => round( $shipping_amount_with_vat, 2 ),
					];
				}
			}

			return $fiscal_items;
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Resolve VAT info for a product
		 * ─────────────────────────────────────────────────── */

		/**
		 * Get the Bulgarian VAT group and rate for a WC product.
		 *
		 * @param WC_Product $product
		 * @return array { @type string $group, @type float $rate }
		 */
		private function resolve_vat_info(WC_Product $product ): array {
			$tax_class = $product->get_tax_class();

			if ( 'zero-rate' === $tax_class ) {
				return [ 'group' => 'А', 'rate' => 0.00 ];
			}

			if ( 'reduced-rate' === $tax_class ) {
				return [ 'group' => 'Г', 'rate' => 0.09 ];
			}

			// Standard rate (default)
			return [ 'group' => 'Б', 'rate' => 0.20 ];
		}

		/* ───────────────────────────────────────────────────
		 *  HELPER: Call Speedy /v1/calculate API
		 * ─────────────────────────────────────────────────── */

		/**
		 * Send a calculate request to the Speedy API.
		 *
		 * Uses wp_remote_post() instead of raw cURL for WordPress best practices.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function call_speedy_calculate_api( array $payload ): array|WP_Error {

			$body = $this->do_speedy_calculate_request( $payload );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			// If the combined call succeeded with at least one calculation, return it.
			$has_success = false;
			if ( ! empty( $body['calculations'] ) ) {
				foreach ( $body['calculations'] as $calc ) {
					if ( isset( $calc['price']['total'] ) ) {
						$has_success = true;
						break;
					}
				}
			}

			if ( $has_success ) {
				return $body;
			}

			// Combined call failed (top-level error or all calculations failed).
			// If multiple serviceIds were sent, retry each one individually.
			$service_ids = $payload['service']['serviceIds'] ?? [];
			if ( count( $service_ids ) <= 1 ) {
				return $body; // Single service – nothing more to try.
			}


			$merged_calculations = [];
			foreach ( $service_ids as $sid ) {
				$single_payload = $payload;
				$single_payload['service']['serviceIds'] = [ $sid ];

				$single_body = $this->do_speedy_calculate_request( $single_payload );

				if ( is_wp_error( $single_body ) ) {
					continue;
				}

				if ( ! empty( $single_body['calculations'] ) ) {
					foreach ( $single_body['calculations'] as $calc ) {
						$merged_calculations[] = $calc;
					}
				}
			}

			if ( ! empty( $merged_calculations ) ) {
				return [ 'calculations' => $merged_calculations ];
			}

			// All individual calls failed too – return the original combined response.
			return $body;
		}

		/**
		 * Execute a JSON POST request to a Speedy API endpoint.
		 *
		 * @param string $url  Full API endpoint URL.
		 * @param string $body JSON-encoded request body.
		 * @return array|null  Decoded response or null on error.
		 */
		private static function speedy_curl_post( string $url, string $body ): ?array {
			$response = wp_remote_post( $url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => $body,
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$response_body = wp_remote_retrieve_body( $response );
			if ( empty( $response_body ) ) {
				return null;
			}

			return json_decode( $response_body, true );
		}

		/**
		 * Execute a single Speedy /v1/calculate HTTP request.
		 *
		 * @param array $payload Full request body including credentials.
		 * @return array|WP_Error Decoded API response or WP_Error.
		 */
		private function do_speedy_calculate_request( array $payload ): array|WP_Error {
			$response = wp_remote_post( 'https://api.speedy.bg/v1/calculate', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code < 200 || $code >= 300 ) {
				$api_msg = $body['error']['message'] ?? "HTTP $code";
				return new WP_Error( 'speedy_api_error', $api_msg );
			}

			return $body;
		}
	}
}
