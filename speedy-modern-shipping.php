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
function speedy_modern_load_dependencies() {
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
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Pass PHP data to JS (like AJAX URL or carrier IDs)
		wp_localize_script( 'speedy-modern-checkout', 'speedy_params', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'method_id' => 'speedy_modern'
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
	global $wpdb;
	$has_credentials = false;
	$option_like     = 'woocommerce_speedy_modern_%_settings';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 10",
			$option_like
		)
	);

	if ( $rows ) {
		foreach ( $rows as $row ) {
			$settings = maybe_unserialize( $row->option_value );
			if ( is_array( $settings ) && ! empty( $settings['speedy_username'] ) && ! empty( $settings['speedy_password'] ) ) {
				$has_credentials = true;
				break;
			}
		}
	}

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
 * @return string The city name or an empty string if not found.
 */
function speedy_modern_get_city_name_by_id( $city_id ) {
	if ( ! $city_id ) {
		return '';
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'speedy_cities';
	
	$city_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT name FROM {$table_name} WHERE id = %d",
			$city_id
		)
	);

	// Fallback: If name is not found (e.g. sync hasn't run), return the ID so the field isn't blank.
	return $city_name ? $city_name : $city_id;
}

/**
 * AJAX Handler for searching cities via Speedy API.
 * Used by Select2 in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_search_cities', 'speedy_modern_search_cities' );
function speedy_modern_search_cities() {
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
	global $wpdb;
	$username = '';
	$password = '';
	
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
 * AJAX Handler for file uploads in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_upload_file', 'speedy_modern_upload_file' );
function speedy_modern_upload_file() {
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
