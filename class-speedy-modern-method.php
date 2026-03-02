<?php

/**
 * Speedy Modern Shipping Method Class
 */
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
			$this->method_title       = __( 'Speedy Modern', 'speedy-modern' );
			$this->method_description = __( 'Fresh, conflict-free Speedy delivery for Bulgaria.', 'speedy-modern' );

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
			$this->title = $this->get_option( 'title', __( 'Speedy Delivery', 'speedy-modern' ) );

			// Save settings in admin
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
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
						__( 'Speedy API authentication failed: %s', 'speedy-modern' ),
						$validation->get_error_message()
					);

					$this->add_error(
						$error_message . ' ' . __( 'Credentials have been cleared.', 'speedy-modern' )
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
		private function validate_speedy_credentials( string $username, string $password ) {
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
					?? __( 'Invalid username or password.', 'speedy-modern' );

				return new WP_Error( 'speedy_auth_failed', $api_message );
			}

			// Any non-200 response
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'speedy_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Unexpected API response (HTTP %d).', 'speedy-modern' ),
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
				__( 'The API returned an unexpected response. Please check your credentials.', 'speedy-modern' )
			);
		}

		/**
		 * Validate the sender_city field.
		 *
		 * Since the options are loaded via AJAX, the standard validation (checking against keys) fails.
		 * We simply return the value (sanitized).
		 *
		 * @param string $key
		 * @param string $value
		 * @return string
		 */
		public function validate_sender_city_field( $key, $value ) {
			return sanitize_text_field( $value );
		}

		/**
		 * Define the settings fields
		 */
		public function init_form_fields(): void {

			$this->instance_form_fields = array(
				// --- SECTION: CONNECTION ---
				'section_api' => [
					'title' => __( 'Speedy API Connection', 'speedy-modern' ),
					'type'  => 'title',
				],
				'enabled' => [
					'title'   => __( 'Module Status', 'speedy-modern' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable/Disable', 'speedy-modern' ),
					'default' => 'yes',
				],
				'title' => [
					'title'       => __( 'Method Title', 'speedy-modern' ),
					'type'        => 'text',
					'default'     => __( 'Speedy Delivery', 'speedy-modern' ),
					'desc_tip'    => true,
				],
				'speedy_username' => [
					'title' => __( 'Username', 'speedy-modern' ),
					'type'  => 'text'
				],
				'speedy_password' => [
					'title' => __( 'Password', 'speedy-modern' ),
					'type'  => 'password',
				],
			);

			// Only show advanced settings if API credentials are saved
			if ( $this->get_option('speedy_username') && $this->get_option('speedy_password') ) {
				$this->add_authenticated_fields();
			} else {
				$this->instance_form_fields['info_msg'] = [
					'type'        => 'title',
					'description' => __( 'Please save your credentials to unlock shipping options.', 'speedy-modern' ),
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
			if ( isset( $_POST[ $field_key ] ) ) {
				$posted_city = sanitize_text_field( $_POST[ $field_key ] );
				if ( $posted_city ) {
					$current_city = $posted_city;
				}
			}

			$authenticated = [

				// --- SECTION: SENDER DETAILS ---
				'section_sender' => [
					'title' => __( 'Sender Information', 'speedy-modern' ),
					'type'  => 'title',
				],
				'sender_id' => [
					'title'   => __( 'Sender (Object)', 'speedy-modern' ),
					'type'    => 'select',
					'options' => $this->get_speedy_clients(),
				],
				'sender_name' => [
					'title' => __( 'Contact Person', 'speedy-modern' ),
					'type'  => 'text'
				],
				'sender_email' => [
					'title' => __( 'Email', 'speedy-modern' ),
					'type'  => 'email'
				],
				'sender_phone' => [
					'title' => __( 'Phone Number', 'speedy-modern' ),
					'type'  => 'text'
				],
				'sender_city' => [
					'title'   => __( 'City', 'speedy-modern' ),
					'type'    => 'select',
					'class'   => 'speedy-city-search',
					'options' => [ $current_city => speedy_modern_get_city_name_by_id( $current_city ) ],
					'custom_attributes' => [
						'data-placeholder' => __( 'Search for a city...', 'speedy-modern' ),
					],
				],
				'sender_officeyesno' => [
					'title'   => __( 'Send from Office', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'sender_office' => [
					'title'   => __( 'Shipping from Office', 'speedy-modern' ),
					'type'    => 'select',
					'options' => $this->get_speedy_offices(),
				],
				'sender_time' => [
					'title'       => __( 'Working Day End Time', 'speedy-modern' ),
					'type'        => 'text',
					'placeholder' => '17:30',
					'description' => __( 'Format HH:MM', 'speedy-modern' ),
				],

				// --- SECTION: SHIPMENT SETTINGS ---
				'section_shipment' => [
					'title' => __( 'Shipment Settings', 'speedy-modern' ),
					'type'  => 'title',
				],
				'uslugi' => [
					'title'    => __( 'Active Services', 'speedy-modern' ),
					'type'     => 'multiselect',
					'options'  => $this->get_speedy_services(),
					'default'  => '505',
				],
				'opakovka' => [
					'title'   => __( 'Packaging', 'speedy-modern' ),
					'type'    => 'text',
					'default' => 'BOX'
				],
				'teglo' => [
					'title'   => __( 'Default Weight', 'speedy-modern' ),
					'type'    => 'number',
					'default' => '1'
				],
				'obqvena' => [
					'title'   => __( 'Declared Value', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'chuplivost' => [
					'title'   => __( 'Fragile', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'saturdayoption' => [
					'title'   => __( 'Saturday Delivery', 'speedy-modern' ),
					'type'    => 'select',
					'default' => 'NO',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'special_requirements' => [
					'title'    => __( 'Special Requirements', 'speedy-modern' ),
					'type'     => 'select',
					'options'  => $this->get_speedy_special_requirements(),
				],

				// --- SECTION: PRICING & PAYMENT ---
				'section_pricing' => [
					'title' => __( 'Pricing & Payment', 'speedy-modern' ),
					'type'  => 'title',
				],
				'cenadostavka' => [
					'title'   => __( 'Pricing Method', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'speedycalculator' => __( 'Speedy Calculator', 'speedy-modern' ),
						'fixedprices'      => __( 'Fixed Price', 'speedy-modern' ),
						'freeshipping'     => __( 'Free Shipping', 'speedy-modern' ),
						'fileprices'       => __( 'Custom Prices', 'speedy-modern' ),
						'nadbavka'         => __( 'Calculator + Surcharge', 'speedy-modern' ),
					],
				],
				'suma_nadbavka'          => [
					'title'        => __( 'Surcharge Amount', 'speedy-modern' ),
					'type'         => 'number',
					'custom_class' => 'suma-nadbavka',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fileceni'               => [
					'title'        => __( 'CSV Price File', 'speedy-modern' ),
					'type'         => 'text', // Changed to text for JS handling
					'class'        => 'speedy-file-input-wrapper', // Hook for JS
					'description'  => __( 'Path to CSV file with custom prices', 'speedy-modern' ),
				],
				'free_shipping' => [
					'title'       => __( 'Free Shipping', 'speedy-modern' ),
					'description' => __( 'Sum ABOVE the specified here activates free shipping to office/address. Explanation: If you want users to receive free shipping when reaching X amount - enter it with 0.01 less in the respective field. For example, for free shipping when reaching 100lv - enter 99.99 etc.', 'speedy-modern' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'free_shipping_automat'  => [
					'title'        => __( 'Free Shipping to Automat > Amount', 'speedy-modern' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_office'   => [
					'title'        => __( 'Free Shipping to Office > Amount', 'speedy-modern' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'free_shipping_address'  => [
					'title'        => __( 'Free Shipping to Address > Amount', 'speedy-modern' ),
					'type'         => 'number',
					'custom_class' => 'free-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping' => [
					'title'       => __( 'Fixed Shipping Price', 'speedy-modern' ),
					'description' => __( 'Enable fixed shipping price to office/address', 'speedy-modern' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'fixed_shipping_automat' => [
					'title'        => __( 'Fixed Price to Automat', 'speedy-modern' ),
					'type'         => 'number',
					'custom_class' => 'fixed-shipping-automat',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_office' => [
					'title' => __( 'Fixed Price to Office', 'speedy-modern' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-office',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'fixed_shipping_address' => [
					'title' => __( 'Fixed Price to Address', 'speedy-modern' ),
					'type'  => 'number',
					'custom_class' => 'fixed-shipping-address',
					'custom_attributes' => [ 'step' => '0.01' ],
				],
				'moneytransfer'          => [
					'title'   => __( 'Money Transfer Type', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'        => __( 'Cash on Delivery', 'speedy-modern' ),
						'YES'       => __( 'Postal Money Transfer', 'speedy-modern' ),
						'fiscal'    => __( 'Fiscal Receipt (Items)', 'speedy-modern' ),
						'fiscalone' => __( 'Fiscal Receipt (Groups)', 'speedy-modern' )
					],
				],
				'includeshippingprice'   => [
					'title'   => __( 'Include Shipping Price in COD', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'nachinplashtane'        => [
					'title'    => __( 'COD Payout Method', 'speedy-modern' ),
					'type'     => 'text',
					'default'  => 'по договор',
					'custom_attributes' => [ 'disabled' => 'disabled' ],
				],
				'administrative'         => [
					'title'   => __( 'Administrative Fee', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],

				// --- SECTION: WORKFLOW & OPTIONS ---
				'section_options' => [
					'title' => __( 'Workflow & Options', 'speedy-modern' ),
					'type'  => 'title',
				],
				'generate_waybill' => [
					'title'       => __( 'Automatic Waybill', 'speedy-modern' ),
					'description' => __( 'Automatically create waybill on order completion', 'speedy-modern' ),
					'type'        => 'checkbox',
					'default'     => 'no'
				],
				'printer'                => [
					'title'   => __( 'Label Printer', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'additionalcopy'         => [
					'title'   => __( 'Additional Waybill Copy', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'addressonefield'        => [
					'title'   => __( 'Address in One Field', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'fast_checkout'          => [
					'title'   => __( 'Fast Checkout', 'speedy-modern' ),
					'type'    => 'checkbox',
					'default' => 'no'
				],
				'test_before_pay' => [
					'title'   => __( 'Options Before Payment', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'   => __( 'None', 'speedy-modern' ),
						'OPEN' => __( 'Open', 'speedy-modern' ),
						'TEST' => __( 'Test', 'speedy-modern' ),
					],
				],
				'testplatec'             => [
					'title'   => __( 'Return Shipment Payer (Test/Open)', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'SENDER'    => __( 'Sender', 'speedy-modern' ),
						'RECIPIENT' => __( 'Recipient', 'speedy-modern' ),
					],
				],
				'autoclose'              => [
					'title'   => __( 'Auto Close Options at Automat', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],

				// --- SECTION: RETURNS & VOUCHERS ---
				'section_returns' => [
					'title' => __( 'Returns & Vouchers', 'speedy-modern' ),
					'type'  => 'title',
				],
				'vaucher' => [
					'title'   => __( 'Return Voucher', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'NO'  => __( 'No', 'speedy-modern' ),
						'YES' => __( 'Yes', 'speedy-modern' ),
					],
				],
				'vaucherpayer' => [
					'title'   => __( 'Return Payer', 'speedy-modern' ),
					'type'    => 'select',
					'options' => [
						'SENDER'    => __( 'Sender', 'speedy-modern' ),
						'RECIPIENT' => __( 'Recipient', 'speedy-modern' ),
					],
				],
				'vaucherpayerdays'       => [
					'title'        => __( 'Voucher Validity (Days)', 'speedy-modern' ),
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

			$clients = [ '0' => __( '-- Select Client --', 'speedy-modern' ) ];

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

			// Execute API Call
			$ch = curl_init( 'https://api.speedy.bg/v1/client/contract' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Accept: application/json'
			] );

			$response = curl_exec( $ch );
			$error    = curl_error( $ch );
			curl_close( $ch );

			if ( $error ) {
				error_log( 'Speedy API Error (get_clients): ' . $error );
				return $clients;
			}

			$data = json_decode( $response, true );

			// Process and Format Data
			if ( isset( $data['clients'] ) && is_array( $data['clients'] ) ) {
				foreach ( $data['clients'] as $client ) {
					$client_id   = $client['clientId'] ?? '';
					$client_name = $client['clientName'] ?? '';
					$object_name = $client['objectName'] ?? '';
					$address     = $client['address']['fullAddressString'] ?? '';

					$clients[ $client_id ] = sprintf(
					/* translators: 1: ID, 2: Name, 3: Object, 4: Address */
						__( 'ID: %1$s, %2$s, %3$s, Address: %4$s', 'speedy-modern' ),
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
		 * Fetch available Speedy offices from API and sort them alphabetically
		 *
		 * @return array Associative array of [officeId => "Name - Address"]
		 */
		private function get_speedy_offices(): array {
			$cache_key = 'speedy_offices_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$offices   = get_transient( $cache_key );

			// If cache exists, return it immediately
			if ( false !== $offices ) {
				return $offices;
			}

			$offices = [ '0' => __( '-- Select Office --', 'speedy-modern' ) ];

			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $offices;
			}

			// Prepare API data (Country ID 100 is Bulgaria)
			$body = json_encode( [
				'userName'  => $username,
				'password'  => $password,
				'countryId' => 100,
			] );

			$ch = curl_init( 'https://api.speedy.bg/v1/location/office' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Accept: application/json'
			] );

			$response = curl_exec( $ch );
			$error    = curl_error( $ch );
			curl_close( $ch );

			if ( $error ) {
				error_log( 'Speedy API Error (get_offices): ' . $error );
				return $offices;
			}

			$data = json_decode( $response, true );

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

				// Cache for 24 hours
				set_transient( $cache_key, $offices, DAY_IN_SECONDS );
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

			// Execute API Call
			$ch = curl_init( 'https://api.speedy.bg/v1/services' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Accept: application/json'
			] );

			$response = curl_exec( $ch );
			$error    = curl_error( $ch );
			curl_close( $ch );

			if ( $error ) {
				error_log( 'Speedy API Error (get_services): ' . $error );
				return $services_list;
			}

			$data = json_decode( $response, true );

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
		 * Fetch Special Requirements from Speedy API
		 */
		private function get_speedy_special_requirements(): array {
			$cache_key = 'speedy_requirements_cache_' . md5( $this->get_option( 'speedy_username' ) );
			$requirements = get_transient( $cache_key );

			if ( false !== $requirements ) {
				return $requirements;
			}

			$requirements = [ '0' => __( '-- None --', 'speedy-modern' ) ];
			$username = $this->get_option( 'speedy_username' );
			$password = $this->get_option( 'speedy_password' );

			if ( ! $username || ! $password ) {
				return $requirements;
			}

			$body = json_encode([
				'userName' => $username,
				'password' => $password,
			]);

			$ch = curl_init( 'https://api.speedy.bg/v1/client/contract/info' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
				'Accept: application/json'
			] );

			$response = curl_exec( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );

			if ( $error ) {
				return $requirements;
			}

			$data = json_decode( $response, true );

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
		 * Calculate the shipping rate
		 */
		public function calculate_shipping( $package = array() ): void {
			// Only calculate the rate if enabled in settings
			if ( 'yes' !== $this->get_option( 'enabled' ) ) {
				return;
			}

			// Only calculate if the destination is Bulgaria
			if ( 'BG' !== $package['destination']['country'] ) {
				return;
			}

			// Calculate total weight of the package
			$weight = 0;
			foreach ( $package['contents'] as $item ) {
				$product = $item['data'];
				$weight  += (float) $product->get_weight() * $item['quantity'];
			}
			// Fallback to 1kg if weight is missing or 0
			$weight = $weight > 0 ? $weight : 1.0;

			// Get selected services from settings
			$services = $this->get_option( 'uslugi' );
			if ( empty( $services ) ) {
				return;
			}

			// Ensure services is an array (Multiselect returns array, but safety first)
			if ( ! is_array( $services ) ) {
				$services = array( $services );
			}

			$available_services = $this->get_speedy_services();

			// Loop through each selected service and get a quote
			foreach ( $services as $service_id ) {
				$cost = $this->fetch_speedy_api_rate( $service_id, $weight, $package['destination'] );

				if ( false !== $cost ) {
					// Use the service name from our cache as the label (e.g., "505 - City Courier")
					$label = $available_services[ $service_id ] ?? $this->title;

					$rate = array(
						'id'      => $this->id . '_' . $service_id,
						'label'   => $label,
						'cost'    => $cost,
						'package' => $package,
					);

					$this->add_rate( $rate );
				}
			}
		}

		/**
		 * Fetch the shipping rate from Speedy API v1
		 */
		private function fetch_speedy_api_rate( $service_id, $weight, $destination ) {
			$username  = $this->get_option( 'speedy_username' );
			$password  = $this->get_option( 'speedy_password' );
			$sender_id = $this->get_option( 'sender_id' );

			if ( ! $username || ! $password || ! $sender_id ) {
				return false;
			}

			$payload = array(
				'userName'  => $username,
				'password'  => $password,
				'serviceId' => (int) $service_id,
				'sender'    => array(
					'clientId' => (int) $sender_id,
				),
				'recipient' => array(
					'countryId' => 100, // Bulgaria
					'postCode'  => $destination['postcode'],
					'siteName'  => $destination['city'],
					'address'   => $destination['address_1'] . ' ' . $destination['address_2'],
				),
				'content'   => array(
					'parcelsCount' => 1,
					'totalWeight'  => $weight,
				),
			);

			$ch = curl_init( 'https://api.speedy.bg/v1/calculate' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Accept: application/json'
			) );

			$response = curl_exec( $ch );
			$error    = curl_error( $ch );
			curl_close( $ch );

			if ( $error ) {
				return false;
			}

			$data = json_decode( $response, true );

			// Check if the API returned a valid total amount
			if ( isset( $data['amounts']['total'] ) ) {
				return $data['amounts']['total'];
			}

			return false;
		}
	}
}