<?php
/**
 * Plugin Name: Speedy Modern Shipping
 * Description: A clean, conflict-free Speedy integration for Bulgaria.
 * Version: 1.0.0
 * Author: DRUSOFT LTD
 * Text Domain: speedy-modern
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare HPOS Compatibility for WooCommerce 8.0+
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__
		);
	}
} );

/**
 * Guard Clause: Exit if WooCommerce is not active.
 * This keeps the rest of the code clean and un-indented.
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Define Constants
 * Helpful for paths and URLs throughout the plugin.
 */
define( 'SPEEDY_MODERN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPEEDY_MODERN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Load Dependencies
 */
add_action( 'plugins_loaded', 'speedy_modern_load_dependencies' );
function speedy_modern_load_dependencies(): void {
	require_once SPEEDY_MODERN_PATH . 'class-speedy-modern-method.php';
	require_once SPEEDY_MODERN_PATH . 'includes/class-speedy-modern-syncer.php';
}

/**
 * Activation & Deactivation Hooks
 */
register_activation_hook( __FILE__, 'speedy_modern_activate' );
register_deactivation_hook( __FILE__, 'speedy_modern_deactivate' );

/**
 * Run on plugin activation.
 *
 * Creates tables and schedules sync.
 *
 * @return void
 */
function speedy_modern_activate(): void {
	// Create Database Tables
	require_once SPEEDY_MODERN_PATH . 'includes/class-speedy-modern-activator.php';
	Speedy_Modern_Activator::activate();

	// Schedule Background Data Sync (Action Scheduler)
	// This ensures we don't freeze the admin panel fetching thousands of offices
	$settings = get_option( 'woocommerce_speedy_modern_settings' );
	if ( ! empty( $settings['speedy_username'] ) && ! empty( $settings['speedy_password'] ) ) {
		if ( function_exists( 'as_schedule_single_action' ) && ! as_next_scheduled_action( 'speedy_modern_sync_locations_event' ) ) {
			as_schedule_single_action( time(), 'speedy_modern_sync_locations_event' );
		}
	}
}

/**
 * Run on plugin deactivation.
 *
 * Drops tables and clears scheduled actions.
 *
 * @return void
 */
function speedy_modern_deactivate(): void {
	// Unschedule the sync event so it doesn't run when plugin is disabled
	if ( function_exists( 'as_unschedule_action' ) ) {
		as_unschedule_action( 'speedy_modern_sync_locations_event' );
	}

	// Drop Database Tables
	require_once SPEEDY_MODERN_PATH . 'includes/class-speedy-modern-activator.php';
	Speedy_Modern_Activator::deactivate();
}

/**
 * Load plugin text domain.
 *
 * @return void
 */
add_action( 'plugins_loaded', 'speedy_modern_init' );
function speedy_modern_init(): void {
	load_plugin_textdomain( 'speedy-modern', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * Add Speedy Modern to WooCommerce shipping methods.
 *
 * @param array $methods Existing shipping methods.
 * @return array Updated shipping methods.
 */
add_filter( 'woocommerce_shipping_methods', 'register_speedy_modern_method' );
function register_speedy_modern_method( $methods ) {
	$methods['speedy_modern'] = 'WC_Speedy_Modern_Method';
	return $methods;
}

/**
 * Enqueue scripts for the checkout page.
 *
 * @return void
 */
add_action( 'wp_enqueue_scripts', 'speedy_modern_enqueue_scripts' );
function speedy_modern_enqueue_scripts(): void {
	// Only load on checkout and only if we aren't in the admin
	if ( is_checkout() && ! is_admin() ) {
		wp_enqueue_script(
			'speedy-modern-checkout',
			SPEEDY_MODERN_URL . 'assets/js/checkout.js',
			array( 'jquery', 'select2' ),
			'1.0.3',
			true
		);

		wp_enqueue_style(
			'speedy-modern-checkout',
			SPEEDY_MODERN_URL . 'assets/css/checkout.css',
			array(),
			'1.0.0'
		);

		// Pass PHP data to JS (like AJAX URL or carrier IDs)
		wp_localize_script( 'speedy-modern-checkout', 'speedy_params', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'method_id' => 'speedy_modern',
			'i18n'      => array(
				'to_address' => __( 'To Address', 'speedy-modern' ),
				'to_office'  => __( 'To Office', 'speedy-modern' ),
				'to_automat' => __( 'To Automat', 'speedy-modern' ),
				'select_office' => __( 'Select Office', 'speedy-modern' ),
				'select_automat' => __( 'Select Automat', 'speedy-modern' ),
				'select_from_map' => __( 'Select from Map', 'speedy-modern' ),
				'select_city' => __( 'Select a city...', 'speedy-modern' ),
				'alert_select_city' => __( 'Please select a city first.', 'speedy-modern' ),
			)
		));
	}
}

/**
 * Enqueue admin scripts for the WooCommerce shipping zones page.
 *
 * Loads a script that auto-reopens the settings modal after saving
 * credentials for the first time, so the user sees the unlocked fields.
 *
 * @return void
 */
add_action( 'admin_enqueue_scripts', 'speedy_modern_enqueue_admin_scripts' );
function speedy_modern_enqueue_admin_scripts( $hook ): void {
	// Only load on the WooCommerce shipping settings page
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	if ( 'shipping' !== $tab ) {
		return;
	}

	// Determine if credentials are already saved (for any instance)
	// We check the global option key that WooCommerce uses for instance settings
	$credentials = speedy_modern_get_first_credentials();
	$has_credentials = ! empty( $credentials );

	wp_enqueue_script(
		'speedy-modern-admin-shipping',
		SPEEDY_MODERN_URL . 'assets/js/admin-shipping-zone.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);

	wp_localize_script( 'speedy-modern-admin-shipping', 'speedy_modern_admin', array(
		'has_credentials'          => $has_credentials ? '1' : '0',
		'i18n_correct_credentials' => __( 'Please correct your credentials and save again.', 'speedy-modern' ),
	) );

	// Enqueue the settings script for dynamic field visibility
	wp_enqueue_style( 'speedy-modern-admin-settings', SPEEDY_MODERN_URL . 'assets/css/admin-settings.css', array(), '1.0.0' );
	wp_enqueue_script(
		'speedy-modern-admin-settings',
		SPEEDY_MODERN_URL . 'assets/js/admin-settings.js',
		array( 'jquery', 'select2' ),
		'1.0.0',
		true
	);
}

/**
 * Background Job Listeners
 * This connects the scheduled event to the actual logic.
 */
add_action( 'speedy_modern_sync_locations_event', array( 'Speedy_Modern_Syncer', 'sync' ) );

/**
 * Get city name by its ID from our local database.
 *
 * @param int $city_id The Speedy city ID.
 *
 * @return int|string The city name or an empty string if not found.
 */
function speedy_modern_get_city_name_by_id( int $city_id ): int|string {
	if ( ! $city_id ) {
		return '';
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_cities';
	
	$city_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT name FROM $table_name WHERE id = %d",
			$city_id
		)
	);

	// Fallback: If name is not found (e.g. sync hasn't run), return the ID so the field isn't blank.
	return $city_name ?: $city_id;
}

/**
 * Get office label by its ID from our local database.
 *
 * @param int $office_id The Speedy office ID.
 *
 * @return int|string The office label (Name - Address) or ID if not found.
 */
function speedy_modern_get_office_label_by_id( int $office_id ): int|string {
	if ( ! $office_id ) {
		return '';
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_offices';
	
	$office = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, address FROM $table_name WHERE id = %d",
			$office_id
		)
	);

	if ( $office ) {
		return sprintf( '%s %s - %s', $office_id, $office->name, $office->address );
	}

	return $office_id;
}

/**
 * AJAX Handler for searching cities via Speedy API.
 * Used by Select2 in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_search_cities', 'speedy_modern_search_cities' );
function speedy_modern_search_cities(): void {
	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( 'Permission denied' );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';
	if ( empty( $term ) ) {
		wp_send_json_success( [] );
	}

	// We need credentials to query the API.
	// Since this is a global AJAX handler, we need to find *some* valid credentials.
	// We'll try to get them from the first configured instance.
	$credentials = speedy_modern_get_first_credentials();
	$username = $credentials['username'] ?? '';
	$password = $credentials['password'] ?? '';

	if ( empty( $username ) || empty( $password ) ) {
		wp_send_json_error( 'No API credentials found.' );
	}

	// Call Speedy API
	$body = json_encode( [
		'userName' => $username,
		'password' => $password,
		'language' => 'BG',
		'countryId' => 100, // Bulgaria
		'name'     => $term,
	] );

	$response = wp_remote_post( 'https://api.speedy.bg/v1/location/site', [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => $body,
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$results = [];

	if ( isset( $data['sites'] ) && is_array( $data['sites'] ) ) {
		foreach ( $data['sites'] as $site ) {
			// Format: "Sofia, Stolichna"
			$label = $site['name'];
			if ( ! empty( $site['municipality'] ) ) {
				$label .= ', ' . $site['municipality'];
			}
			
			$results[] = [
				'id'   => $site['id'], 
				'text' => $label
			];
		}
	}

	wp_send_json( [ 'results' => $results ] ); // Select2 v4+ expects results in a 'results' key
}

/**
 * AJAX Handler for searching offices via local DB with API fallback.
 * Used by Select2 in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_search_offices', 'speedy_modern_search_offices' );
function speedy_modern_search_offices(): void {
	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( 'Permission denied' );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';
	if ( empty( $term ) ) {
		wp_send_json_success( [] );
	}

	// Use the static method from the shipping class which handles DB check + API fallback
	if ( class_exists( 'WC_Speedy_Modern_Method' ) ) {
		$offices = WC_Speedy_Modern_Method::get_speedy_offices( null, null, $term );
		
		$results = [];
		if ( ! empty( $offices ) ) {
			foreach ( $offices as $id => $label ) {
				// Skip the default placeholder if present
				if ( $id == 0 ) continue;
				
				$results[] = [
					'id'   => $id,
					'text' => $label
				];
			}
		}
		
		wp_send_json( [ 'results' => $results ] );
	} else {
		wp_send_json_error( 'Class WC_Speedy_Modern_Method not found.' );
	}
}

/**
 * AJAX Handler for file uploads in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_upload_file', 'speedy_modern_upload_file' );
function speedy_modern_upload_file(): void {
	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( 'Permission denied' );
	}

	if ( ! isset( $_FILES['file'] ) || empty( $_FILES['file']['name'] ) ) {
		wp_send_json_error( 'No file uploaded.' );
	}

	$file = $_FILES['file'];

	// Check for upload errors
	if ( $file['error'] !== UPLOAD_ERR_OK ) {
		wp_send_json_error( 'Upload error: ' . $file['error'] );
	}

	// Validate file type (CSV)
	$file_type = wp_check_filetype( $file['name'] );
	if ( 'csv' !== $file_type['ext'] ) {
		wp_send_json_error( 'Invalid file type. Please upload a CSV file.' );
	}

	// Define upload directory
	$upload_dir = wp_upload_dir();
	$target_dir = $upload_dir['basedir'] . '/speedy_shipping/';
	
	// Create directory if it doesn't exist
	if ( ! file_exists( $target_dir ) ) {
		wp_mkdir_p( $target_dir );
	}

	// Use sanitized original filename
	$filename = sanitize_file_name( $file['name'] );
	$target_file = $target_dir . $filename;

	// Move uploaded file
	if ( move_uploaded_file( $file['tmp_name'], $target_file ) ) {
		// Also update the global option if needed for backward compatibility or easy access
		update_option( 'speedy_fileceni_path', $target_file );
		
		wp_send_json_success( [ 
			'path' => $target_file,
			'name' => basename( $target_file )
		] );
	} else {
		wp_send_json_error( 'Failed to move uploaded file.' );
	}
}

/**
 * Helper: Retrieve the first available Speedy API credentials from settings.
 *
 * @return array|null Array with 'username' and 'password' keys, or null if not found.
 */
function speedy_modern_get_first_credentials(): ?array {
	global $wpdb;
	$option_like = 'woocommerce_speedy_modern_%_settings';
	
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_value FROM $wpdb->options WHERE option_name LIKE %s LIMIT 10",
			$option_like
		)
	);

	if ( $rows ) {
		foreach ( $rows as $row ) {
			$settings = maybe_unserialize( $row->option_value );
			if ( is_array( $settings ) && ! empty( $settings['speedy_username'] ) && ! empty( $settings['speedy_password'] ) ) {
				return [
					'username' => $settings['speedy_username'],
					'password' => $settings['speedy_password'],
				];
			}
		}
	}
	
	return null;
}

/**
 * Helper: Transliterate Latin to Cyrillic (Bulgarian standard)
 */
/*
 function speedy_modern_transliterate_latin_to_cyrillic( $text ): string {
	$map = [
		'A' => 'А', 'B' => 'Б', 'V' => 'В', 'G' => 'Г', 'D' => 'Д', 'E' => 'Е', 'Z' => 'З', 'I' => 'И', 'J' => 'Й', 'K' => 'К', 'L' => 'Л', 'M' => 'М', 'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р', 'S' => 'С', 'T' => 'Т', 'U' => 'У', 'F' => 'Ф', 'H' => 'Х', 'C' => 'Ц',
		'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'e' => 'е', 'z' => 'з', 'i' => 'и', 'j' => 'й', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф', 'h' => 'х', 'c' => 'ц',
		// Multi-character mappings (order matters!)
		'Sht' => 'Щ', 'sht' => 'щ', 'Sh' => 'Ш', 'sh' => 'ш', 'Ch' => 'Ч', 'ch' => 'ч', 'Yu' => 'Ю', 'yu' => 'ю', 'Ya' => 'Я', 'ya' => 'я', 'Zh' => 'Ж', 'zh' => 'ж', 'Ts' => 'Ц', 'ts' => 'ц',
		'Y' => 'Й', 'y' => 'й', 'X' => 'Х', 'x' => 'х', 'W' => 'В', 'w' => 'в', 'Q' => 'Я', 'q'=> 'я'
	];

	return strtr( $text, $map );
}
*/

/**
 * Helper: Get Region Map (WC Code => Speedy Name)
 */
function speedy_modern_get_region_map(): array {
	return [
		'BG-01' => 'Благоевград',
		'BG-02' => 'Бургас',
		'BG-03' => 'Варна',
		'BG-04' => 'Велико Търново',
		'BG-05' => 'Видин',
		'BG-06' => 'Враца',
		'BG-07' => 'Габрово',
		'BG-08' => 'Добрич',
		'BG-09' => 'Кърджали',
		'BG-10' => 'Кюстендил',
		'BG-11' => 'Ловеч',
		'BG-12' => 'Монтана',
		'BG-13' => 'Пазарджик',
		'BG-14' => 'Перник',
		'BG-15' => 'Плевен',
		'BG-16' => 'Пловдив',
		'BG-17' => 'Разград',
		'BG-18' => 'Русе',
		'BG-19' => 'Силистра',
		'BG-20' => 'Сливен',
		'BG-21' => 'Смолян',
		'BG-22' => 'София (столица)', // Sofia City
		'BG-23' => 'София',           // Sofia Province
		'BG-24' => 'Стара Загора',
		'BG-25' => 'Търговище',
		'BG-26' => 'Хасково',
		'BG-27' => 'Шумен',
		'BG-28' => 'Ямбол',
	];
}

/**
 * AJAX Handler: Get cities for a specific region.
 * Used by checkout.js
 */
add_action( 'wp_ajax_speedy_get_cities', 'speedy_modern_get_cities_ajax' );
add_action( 'wp_ajax_nopriv_speedy_get_cities', 'speedy_modern_get_cities_ajax' );

function speedy_modern_get_cities_ajax(): void {
	$region_code = isset( $_POST['region'] ) ? sanitize_text_field( $_POST['region'] ) : '';
	
	if ( empty( $region_code ) ) {
		wp_send_json_error( 'Missing region code' );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_cities';
	$args = [];

	// Use helper function for mapping
	$region_map = speedy_modern_get_region_map();
	$region_name = $region_map[ $region_code ] ?? '';

	if ( empty( $region_name ) ) {
		wp_send_json_error( 'Unknown region code' );
	}

	// Exact match for Sofia regions, LIKE for others
	if ( 'BG-22' === $region_code || 'BG-23' === $region_code ) {
		$query = "SELECT id, name, post_code, type FROM $table_name WHERE region = %s";
		$args[] = $region_name;
	} else {
		$query = "SELECT id, name, post_code, type FROM $table_name WHERE region LIKE %s";
		$args[] = '%' . $wpdb->esc_like( $region_name ) . '%';
	}

	// Add ordering
	$query .= " ORDER BY CASE WHEN type = 'гр.' THEN 1 ELSE 2 END, name ASC";
	
	$cities = $wpdb->get_results( $wpdb->prepare( $query, $args ) );

	$data = [];
	foreach ( $cities as $city ) {
		$data[] = [
			'id'       => $city->id,
			'name'     => $city->type . ' ' . $city->name, // Prepend type
			'postcode' => $city->post_code
		];
	}

	wp_send_json_success( $data );
}

/**
 * AJAX Handler: Check availability of offices/automats in a city.
 * Used by checkout.js
 */
add_action( 'wp_ajax_speedy_check_availability', 'speedy_modern_check_availability_ajax' );
add_action( 'wp_ajax_nopriv_speedy_check_availability', 'speedy_modern_check_availability_ajax' );

function speedy_modern_check_availability_ajax(): void {
	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( 'Missing city ID' );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_offices';

	// Fetch all offices/automats for this city
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, address, office_type FROM $table_name WHERE city_id = %d ORDER BY name ASC",
			$city_id
		)
	);

	$offices = [];
	$automats = [];

	foreach ( $results as $row ) {
		$item = [
			'id'    => $row->id,
			'label' => sprintf( '%s %s - %s', $row->id, $row->name, $row->address )
		];

		// Speedy types: 
		// APT = Automat (APS)
		// OFFICE = Standard Office
		// We need to check the exact type codes Speedy uses. 
		// Usually 'APT' or 'APS' is automat.
		
		if ( stripos( $row->office_type, 'APT' ) !== false || stripos( $row->office_type, 'APS' ) !== false ) {
			$automats[] = $item;
		} else {
			$offices[] = $item;
		}
	}

	wp_send_json_success( [
		'has_office'  => ! empty( $offices ),
		'has_automat' => ! empty( $automats ),
		'offices'     => $offices,
		'automats'    => $automats
	] );
}

/**
 * AJAX Handler: Get region code by city ID.
 * Used when selecting an office from the map in a different city.
 */
add_action( 'wp_ajax_speedy_get_region_by_city', 'speedy_modern_get_region_by_city_ajax' );
add_action( 'wp_ajax_nopriv_speedy_get_region_by_city', 'speedy_modern_get_region_by_city_ajax' );

function speedy_modern_get_region_by_city_ajax(): void {
	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( 'Missing city ID' );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_cities';

	$region_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT region FROM $table_name WHERE id = %d",
			$city_id
		)
	);

	if ( ! $region_name ) {
		wp_send_json_error( 'City not found' );
	}

	// Use helper function and flip it for reverse mapping
	$region_map = speedy_modern_get_region_map();
	$reverse_map = array_flip( $region_map );

	// Handle fuzzy matching if exact match fails (e.g. "Област София" vs "София")
	$region_code = $reverse_map[ $region_name ] ?? '';

	if ( ! $region_code ) {
		// Try to find partial match
		foreach ( $reverse_map as $name => $code ) {
			if ( mb_stripos( $region_name, $name ) !== false ) {
				$region_code = $code;
				break;
			}
		}
	}

	if ( $region_code ) {
		wp_send_json_success( [ 'region' => $region_code ] );
	} else {
		wp_send_json_error( 'Region mapping not found for: ' . $region_name );
	}
}

/**
 * Validate Checkout Fields
 * Ensures an office is selected if the user chose "To Office" or "To Automat".
 */
add_action( 'woocommerce_checkout_process', 'speedy_modern_validate_checkout' );
function speedy_modern_validate_checkout(): void {
	// Check if Speedy Modern is the selected shipping method
	$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
	$chosen_shipping = $chosen_methods[0] ?? '';

	if ( ! str_contains( $chosen_shipping, 'speedy_modern' ) ) {
		return;
	}

	// Check delivery type
	$delivery_type = isset( $_POST['speedy_delivery_type'] ) ? sanitize_text_field( $_POST['speedy_delivery_type'] ) : 'address';

	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		$office_id = isset( $_POST['speedy_office_id'] ) ? sanitize_text_field( $_POST['speedy_office_id'] ) : '';

		if ( empty( $office_id ) ) {
			$error_msg = ( 'office' === $delivery_type ) 
				? __( 'Please select a Speedy office.', 'speedy-modern' ) 
				: __( 'Please select a Speedy automat.', 'speedy-modern' );
			
			wc_add_notice( $error_msg, 'error' );
		}
	}
}

/**
 * Save Order Meta
 * Saves the selected office ID and delivery type to the order.
 */
add_action( 'woocommerce_checkout_update_order_meta', 'speedy_modern_save_order_meta' );
function speedy_modern_save_order_meta( $order_id ): void {
	if ( ! empty( $_POST['speedy_delivery_type'] ) ) {
		update_post_meta( $order_id, '_speedy_delivery_type', sanitize_text_field( $_POST['speedy_delivery_type'] ) );
	}

	if ( ! empty( $_POST['speedy_office_id'] ) ) {
		update_post_meta( $order_id, '_speedy_office_id', sanitize_text_field( $_POST['speedy_office_id'] ) );
	}
}
