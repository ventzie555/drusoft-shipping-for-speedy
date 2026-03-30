<?php
/**
 * Plugin Name: Modern Shipping for Speedy
 * Plugin URI:  https://github.com/ventzie555/speedy-modern-shipping
 * Description: A clean, conflict-free Speedy integration for Bulgaria.
 * Version:     1.0.0
 * Author:      DRUSOFT LTD
 * Author URI:  https://drusoft.dev/
 * Text Domain: modern-shipping-for-speedy
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @copyright 2026 DRUSOFT LTD.
 * @license GPL-2.0-or-later
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter.
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
	require_once SPEEDY_MODERN_PATH . 'includes/class-speedy-modern-waybill-generator.php';
	require_once SPEEDY_MODERN_PATH . 'includes/admin/class-speedy-modern-admin-menu.php';
	require_once SPEEDY_MODERN_PATH . 'includes/admin/class-speedy-modern-actions.php';
	require_once SPEEDY_MODERN_PATH . 'includes/admin/class-speedy-modern-order-metabox.php';
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
	// Bundled translations for locales not yet on translate.wordpress.org.
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain( 'modern-shipping-for-speedy', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * Add Speedy Modern to WooCommerce shipping methods.
 *
 * @param array $methods Existing shipping methods.
 * @return array Updated shipping methods.
 */
add_filter( 'woocommerce_shipping_methods', 'speedy_modern_register_method' );
function speedy_modern_register_method( $methods ) {
	$methods['speedy_modern'] = 'WC_Speedy_Modern_Method';
	return $methods;
}

/**
 * Check office/automat availability for a given city.
 *
 * @param int $city_id Speedy city (site) ID.
 * @return array { @type bool $has_office, @type bool $has_automat }
 */
function speedy_modern_check_city_availability( int $city_id ): array {
	$has_office  = false;
	$has_automat = false;

	if ( $city_id <= 0 ) {
		return compact( 'has_office', 'has_automat' );
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT name, office_type FROM {$wpdb->prefix}speedy_offices WHERE city_id = %d",
			$city_id
		)
	);

	foreach ( $rows as $row ) {
		if ( speedy_modern_is_automat( $row->office_type, $row->name ) ) {
			$has_automat = true;
		} else {
			$has_office = true;
		}
		if ( $has_office && $has_automat ) {
			break;
		}
	}

	return compact( 'has_office', 'has_automat' );
}

/**
 * Determine whether an office row represents an automat (APT/APS).
 *
 * @param string $office_type The office_type column value.
 * @param string $name        The office name.
 * @return bool
 */
function speedy_modern_is_automat( string $office_type, string $name ): bool {
	return ( stripos( $office_type, 'APT' ) !== false
		|| stripos( $office_type, 'APS' ) !== false
		|| mb_stripos( $name, 'АВТОМАТ' ) !== false
		|| stripos( $name, 'APS' ) !== false
		|| stripos( $name, 'APT' ) !== false );
}

/**
 * Get the display name for a Speedy city by ID (e.g. "гр. София").
 *
 * @param int $city_id Speedy city (site) ID.
 * @return string City name or empty string if not found.
 */
function speedy_modern_get_city_name( int $city_id ): string {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$name = $wpdb->get_var( $wpdb->prepare(
		"SELECT CONCAT(type, ' ', name) FROM {$wpdb->prefix}speedy_cities WHERE id = %d",
		$city_id
	) );
	return $name ?: '';
}

/**
 * Append the selected Speedy service ID to the shipping package so the
 * package hash changes whenever the user picks a different service.
 * This forces WooCommerce to re-call calculate_shipping() instead of
 * returning a cached rate.
 */
add_filter( 'woocommerce_cart_shipping_packages', 'speedy_modern_vary_package_hash' );
function speedy_modern_vary_package_hash( $packages ) {
	// Extract delivery type and office ID from the current checkout POST data
	// so the package hash changes whenever the user switches delivery type or
	// picks a different office/automat — forcing WC to re-call calculate_shipping.
	$post_data = [];
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called inside WC filter; nonce verified by WooCommerce.
	if ( ! empty( $_POST['post_data'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL-encoded string; individual values sanitized below.
		parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$merged = array_merge( $post_data, $_POST );

	$delivery_type = sanitize_text_field( $merged['speedy_delivery_type'] ?? 'address' );
	$office_id     = absint( $merged['speedy_office_id'] ?? 0 );
	$selected      = WC()->session ? WC()->session->get( 'speedy_modern_selected_service', 0 ) : 0;

	// Determine which address context is active
	$ship_to_different = ! empty( $merged['ship_to_different_address'] );
	$context           = $ship_to_different ? 'shipping' : 'billing';
	$city_id           = absint( $merged[ $context . '_city' ] ?? 0 );

	// Also try the cart calculator city field and our dedicated hidden field
	if ( ! $city_id ) {
		$city_id = absint( $merged['calc_shipping_city'] ?? 0 );
	}
	if ( ! $city_id ) {
		$city_id = absint( $merged['speedy_city_id'] ?? 0 );
	}

	// On the cart page, set session data directly from the form submission
	// so calculate_shipping() can read them without a prior AJAX call.
	if ( WC()->session && $city_id > 0 ) {
		$session_city = absint( WC()->session->get( 'speedy_modern_city_id', 0 ) );
		$session_type = WC()->session->get( 'speedy_modern_delivery_type', 'address' );

		// Update session if something changed
		if ( $city_id !== $session_city || $delivery_type !== $session_type ) {
			WC()->session->set( 'speedy_modern_city_id', $city_id );
			WC()->session->set( 'speedy_modern_delivery_type', $delivery_type );

			// Update customer city
			if ( WC()->customer ) {
				$city_name = speedy_modern_get_city_name( $city_id );
				WC()->customer->set_shipping_city( $city_name ?: $city_id );
				WC()->customer->set_billing_city( $city_name ?: $city_id );
				WC()->customer->save();
			}

			// Save office_id from POST data (if submitted), otherwise clear it for address delivery.
			if ( $office_id > 0 ) {
				WC()->session->set( 'speedy_modern_office_id', $office_id );
			} elseif ( $delivery_type === 'address' ) {
				WC()->session->set( 'speedy_modern_office_id', 0 );
			}
		}

		// Update state from form if present
		$state = sanitize_text_field( $merged['calc_shipping_state'] ?? '' );
		if ( $state ) {
			WC()->session->set( 'speedy_modern_state', $state );
			if ( WC()->customer ) {
				WC()->customer->set_shipping_state( $state );
				WC()->customer->set_billing_state( $state );
				WC()->customer->save();
			}
		}
	}

	// Include the payment method in the hash so switching COD ↔ card
	// forces shipping recalculation (COD changes courierServicePayer).
	// Read directly from POST data (available during checkout AJAX);
	// on the cart page this is empty, which is fine (defaults to COD).
	$payment_method = sanitize_text_field( $merged['payment_method'] ?? '' );

	foreach ( $packages as &$package ) {
		$package['speedy_selected_service'] = $selected;
		$package['speedy_delivery_type']    = $delivery_type;
		$package['speedy_office_id']        = $office_id;
		$package['speedy_city_id']          = $city_id;
		$package['speedy_payment_method']   = $payment_method;
	}

	return $packages;
}

/**
 * Hide the price in the order review when Speedy data is incomplete
 * (e.g. user switched to office but hasn't selected one yet).
 */
add_filter( 'woocommerce_cart_shipping_method_full_label', 'speedy_modern_hide_incomplete_price', 10, 2 );
function speedy_modern_hide_incomplete_price( $label, $method ) {
	if (str_starts_with($method->id, 'speedy_modern')) {
		$meta = $method->get_meta_data();
		if ( ! empty( $meta['missing_address'] ) ) {
			// Return just the method label without the price
			return $method->get_label();
		}
	}
	return $label;
}

/**
 * Enqueue scripts for the checkout page.
 *
 * @return void
 */
add_action( 'wp_enqueue_scripts', 'speedy_modern_enqueue_scripts' );
function speedy_modern_enqueue_scripts(): void {
	if ( is_admin() ) {
		return;
	}

	$current_type    = WC()->session ? WC()->session->get( 'speedy_modern_delivery_type', 'address' ) : 'address';
	$current_city_id = WC()->session ? WC()->session->get( 'speedy_modern_city_id', 0 ) : 0;
	$current_state   = WC()->session ? WC()->session->get( 'speedy_modern_state', '' ) : '';
	$current_office  = WC()->session ? WC()->session->get( 'speedy_modern_office_id', 0 ) : 0;

	if ( WC()->customer ) {
		if ( ! $current_state ) {
			$current_state = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();
		}
		if ( ! $current_city_id ) {
			$cust_city = WC()->customer->get_shipping_city() ?: WC()->customer->get_billing_city();
			if ( is_numeric( $cust_city ) ) {
				$current_city_id = absint( $cust_city );
			}
		}
	}

	$params = array(
		'ajax_url'           => admin_url( 'admin-ajax.php' ),
		'nonce'              => wp_create_nonce( 'speedy_modern_public' ),
		'method_id'          => 'speedy_modern',
		'current_type'       => $current_type,
		'current_city_id'    => $current_city_id,
		'current_state'      => $current_state,
		'current_office_id'  => $current_office,
		'currency_symbol'    => get_woocommerce_currency_symbol(),
		'i18n'            => array(
			'to_address'       => __( 'To Address', 'modern-shipping-for-speedy' ),
			'to_office'        => __( 'To Office', 'modern-shipping-for-speedy' ),
			'to_automat'       => __( 'To Automat', 'modern-shipping-for-speedy' ),
			'select_office'    => __( 'Select Office', 'modern-shipping-for-speedy' ),
			'select_automat'   => __( 'Select Automat', 'modern-shipping-for-speedy' ),
			'select_from_map'  => __( 'Select from Map', 'modern-shipping-for-speedy' ),
			'select_city'      => __( 'Select a city...', 'modern-shipping-for-speedy' ),
			'alert_select_city' => __( 'Please select a city first.', 'modern-shipping-for-speedy' ),
			'no_results'       => __( 'No results', 'modern-shipping-for-speedy' ),
			'select_service'   => __( 'Select Service', 'modern-shipping-for-speedy' ),
		)
	);

	// Shared utilities (transliteration, select2 matcher, state sorting).
	// Only registered here — loaded automatically on cart/checkout via dependency.
	wp_register_script(
		'speedy-modern-common',
		SPEEDY_MODERN_URL . 'assets/js/speedy-common.js',
		array( 'jquery', 'select2' ),
		'1.0.0',
		true
	);

	if ( is_checkout() ) {
		wp_enqueue_script(
			'speedy-modern-checkout',
			SPEEDY_MODERN_URL . 'assets/js/checkout.js',
			array( 'jquery', 'select2', 'speedy-modern-common' ),
			'1.0.0',
			true
		);

		wp_enqueue_style(
			'speedy-modern-checkout',
			SPEEDY_MODERN_URL . 'assets/css/checkout.css',
			array(),
			'1.0.0'
		);

		wp_localize_script( 'speedy-modern-checkout', 'speedy_params', $params );
	}

	if ( is_cart() ) {
		// Pre-compute availability so the JS doesn't need an AJAX call on load
		$availability = speedy_modern_check_city_availability( $current_city_id );
		$params['has_office']  = $availability['has_office'];
		$params['has_automat'] = $availability['has_automat'];

		wp_enqueue_script(
			'speedy-modern-cart',
			SPEEDY_MODERN_URL . 'assets/js/cart.js',
			array( 'jquery', 'select2', 'speedy-modern-common' ),
			'1.0.0',
			true
		);

		wp_enqueue_style(
			'speedy-modern-cart',
			SPEEDY_MODERN_URL . 'assets/css/cart.css',
			array(),
			'1.0.0'
		);

		wp_localize_script( 'speedy-modern-cart', 'speedy_params', $params );
	}
}

/**
 * Add hidden fields to the cart form so delivery_type and city_id get
 * submitted with the standard WC cart update — no separate AJAX needed.
 */
add_action( 'woocommerce_cart_contents', 'speedy_modern_cart_hidden_fields' );
function speedy_modern_cart_hidden_fields(): void {
	$delivery_type = WC()->session ? WC()->session->get( 'speedy_modern_delivery_type', 'address' ) : 'address';
	$city_id       = WC()->session ? absint( WC()->session->get( 'speedy_modern_city_id', 0 ) ) : 0;
	echo '<input type="hidden" name="speedy_delivery_type" id="speedy_cart_delivery_type" value="' . esc_attr( $delivery_type ) . '">';
	echo '<input type="hidden" name="speedy_city_id" id="speedy_cart_city_id" value="' . esc_attr( $city_id ) . '">';
}

/**
 * After each Speedy shipping rate is rendered, output a hidden element with
 * availability data so the JS can read it from the DOM after cart updates.
 */
add_action( 'woocommerce_after_shipping_rate', 'speedy_modern_output_availability_data', 10, 2 );
function speedy_modern_output_availability_data( $method ): void {
	if (!str_starts_with($method->id, 'speedy_modern')) {
		return;
	}

	$city_id = WC()->session ? absint( WC()->session->get( 'speedy_modern_city_id', 0 ) ) : 0;
	if ( $city_id <= 0 ) {
		return;
	}

	$availability = speedy_modern_check_city_availability( $city_id );

	printf(
		'<span id="speedy-availability-data" data-has-office="%s" data-has-automat="%s" style="display:none;"></span>',
		esc_attr( $availability['has_office'] ? '1' : '0' ),
		esc_attr( $availability['has_automat'] ? '1' : '0' )
	);
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
		'nonce'                    => wp_create_nonce( 'speedy_modern_admin' ),
		'i18n_correct_credentials' => __( 'Please correct your credentials and save again.', 'modern-shipping-for-speedy' ),
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$city_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}speedy_cities WHERE id = %d",
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$office = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT name, address FROM {$wpdb->prefix}speedy_offices WHERE id = %d",
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
	check_ajax_referer( 'speedy_modern_admin', 'nonce' );

	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'modern-shipping-for-speedy' ) );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
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
		wp_send_json_error( __( 'No API credentials found.', 'modern-shipping-for-speedy' ) );
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
	check_ajax_referer( 'speedy_modern_admin', 'nonce' );

	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'modern-shipping-for-speedy' ) );
	}

	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
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
		wp_send_json_error( __( 'Class WC_Speedy_Modern_Method not found.', 'modern-shipping-for-speedy' ) );
	}
}

/**
 * AJAX Handler for file uploads in admin settings.
 */
add_action( 'wp_ajax_speedy_modern_upload_file', 'speedy_modern_upload_file' );
function speedy_modern_upload_file(): void {
	check_ajax_referer( 'speedy_modern_admin', 'nonce' );

	// Check permissions
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'modern-shipping-for-speedy' ) );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is validated by wp_handle_upload / wp_check_filetype.
	if ( ! isset( $_FILES['file'] ) || empty( $_FILES['file']['name'] ) ) {
		wp_send_json_error( __( 'No file uploaded.', 'modern-shipping-for-speedy' ) );
	}

	// Validate file type (CSV only)
	$file_type = wp_check_filetype( sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) );
	if ( 'csv' !== $file_type['ext'] ) {
		wp_send_json_error( __( 'Invalid file type. Please upload a CSV file.', 'modern-shipping-for-speedy' ) );
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	// Redirect uploads to a dedicated directory.
	$upload_filter = static function ( $uploads ) {
		$uploads['subdir'] = '/speedy_shipping';
		$uploads['path']   = $uploads['basedir'] . '/speedy_shipping';
		$uploads['url']    = $uploads['baseurl'] . '/speedy_shipping';
		return $uploads;
	};
	add_filter( 'upload_dir', $upload_filter );

	$overrides = [
		'test_form' => false,
		'mimes'     => [ 'csv' => 'text/csv' ],
	];

	$uploaded = wp_handle_upload( $_FILES['file'], $overrides ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	remove_filter( 'upload_dir', $upload_filter );

	if ( isset( $uploaded['error'] ) ) {
		wp_send_json_error( $uploaded['error'] );
	}

	update_option( 'speedy_fileceni_path', $uploaded['file'] );

	wp_send_json_success( [
		'path' => $uploaded['file'],
		'name' => basename( $uploaded['file'] ),
	] );
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
 * AJAX Handler: Save cart Speedy selections to WC session.
 * Called from cart.js whenever the user changes state, city, delivery type, or office.
 * This ensures the data persists to the checkout page even without clicking "Update Cart".
 */
add_action( 'wp_ajax_speedy_modern_save_cart_selection', 'speedy_modern_save_cart_selection_ajax' );
add_action( 'wp_ajax_nopriv_speedy_modern_save_cart_selection', 'speedy_modern_save_cart_selection_ajax' );

function speedy_modern_save_cart_selection_ajax(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	if ( ! WC()->session ) {
		wp_send_json_error( 'No session' );
	}

	$state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
	$city_id       = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
	$delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_type'] ) ) : 'address';
	$office_id     = isset( $_POST['office_id'] ) ? absint( $_POST['office_id'] ) : 0;

	if ( $state ) {
		WC()->session->set( 'speedy_modern_state', $state );
	}
	if ( $city_id ) {
		WC()->session->set( 'speedy_modern_city_id', $city_id );
	}
	if ( $delivery_type ) {
		WC()->session->set( 'speedy_modern_delivery_type', $delivery_type );
	}
	WC()->session->set( 'speedy_modern_office_id', $office_id );

	// Also update the WC customer so checkout form fields are pre-filled
	if ( WC()->customer ) {
		if ( $state ) {
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_billing_state( $state );
		}
		if ( $city_id ) {
			$city_name = speedy_modern_get_city_name( $city_id );
			WC()->customer->set_shipping_city( $city_name ?: $city_id );
			WC()->customer->set_billing_city( $city_name ?: $city_id );
		}
		WC()->customer->save();
	}

	wp_send_json_success();
}

/**
 * AJAX Handler: Get cities for a specific region.
 * Used by checkout.js
 */
add_action( 'wp_ajax_speedy_get_cities', 'speedy_modern_get_cities_ajax' );
add_action( 'wp_ajax_nopriv_speedy_get_cities', 'speedy_modern_get_cities_ajax' );

function speedy_modern_get_cities_ajax(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$region_code = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
	
	if ( empty( $region_code ) ) {
		wp_send_json_error( __( 'Missing region code', 'modern-shipping-for-speedy' ) );
	}

	global $wpdb;

	// Use helper function for mapping
	$region_map = speedy_modern_get_region_map();
	$region_name = $region_map[ $region_code ] ?? '';

	if ( empty( $region_name ) ) {
		wp_send_json_error( __( 'Unknown region code', 'modern-shipping-for-speedy' ) );
	}

	// Exact match for Sofia regions, LIKE for others
	if ( 'BG-22' === $region_code || 'BG-23' === $region_code ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, post_code, type FROM {$wpdb->prefix}speedy_cities WHERE region = %s ORDER BY CASE WHEN type = 'гр.' THEN 1 ELSE 2 END, name ASC",
				$region_name
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, post_code, type FROM {$wpdb->prefix}speedy_cities WHERE region LIKE %s ORDER BY CASE WHEN type = 'гр.' THEN 1 ELSE 2 END, name ASC",
				'%' . $wpdb->esc_like( $region_name ) . '%'
			)
		);
	}

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
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( __( 'Missing city ID', 'modern-shipping-for-speedy' ) );
	}

	global $wpdb;

	// Fetch all offices/automats for this city
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, address, office_type FROM {$wpdb->prefix}speedy_offices WHERE city_id = %d ORDER BY name ASC",
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

		if ( speedy_modern_is_automat( $row->office_type, $row->name ) ) {
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
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

	if ( ! $city_id ) {
		wp_send_json_error( __( 'Missing city ID', 'modern-shipping-for-speedy' ) );
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$region_name = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT region FROM {$wpdb->prefix}speedy_cities WHERE id = %d",
			$city_id
		)
	);

	if ( ! $region_name ) {
		wp_send_json_error( __( 'City not found', 'modern-shipping-for-speedy' ) );
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
		wp_send_json_error( __( 'Region mapping not found for: ', 'modern-shipping-for-speedy' ) . esc_html( $region_name ) );
	}
}

/**
 * Validate Checkout Fields
 * Ensures an office is selected if the user chose "To Office" or "To Automat".
 */
add_action( 'woocommerce_checkout_process', 'speedy_modern_validate_checkout' );

/**
 * AJAX Handler: Search streets by name within a city.
 * Calls the Speedy /v1/location/street endpoint.
 * Strips common Bulgarian street prefixes (ул., улица, бул., булевард, etc.)
 */
add_action( 'wp_ajax_speedy_modern_search_streets', 'speedy_modern_search_streets_ajax' );
add_action( 'wp_ajax_nopriv_speedy_modern_search_streets', 'speedy_modern_search_streets_ajax' );

function speedy_modern_search_streets_ajax(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$site_id = isset( $_POST['siteId'] ) ? absint( $_POST['siteId'] ) : 0;
	$query   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

	if ( ! $site_id || mb_strlen( $query ) < 2 ) {
		wp_send_json( [] );
	}

	// Strip common Bulgarian street type prefixes so the API gets
	// just the actual street name for matching.
	$prefixes = [
		// Cyrillic
		'улица',  'ул\.',  'ул ',
		'булевард', 'бул\.',  'бул ',
		'площад', 'пл\.',  'пл ',
		'жк',     'ж\.к\.',
		// Latin transliterations
		'ulitsa', 'ulica', 'ul\.',  'ul ',
		'bulevard', 'boulevard', 'bul\.',  'bul ',
		'ploshtad', 'pl\.',  'pl ',
	];
	$pattern = '/^(' . implode( '|', $prefixes ) . ')\s*/iu';
	$clean_query = preg_replace( $pattern, '', $query );

	// If everything was stripped, use original
	if ( empty( trim( $clean_query ) ) ) {
		$clean_query = $query;
	}

	$credentials = speedy_modern_get_first_credentials();
	if ( ! $credentials ) {
		wp_send_json( [] );
	}

	$payload = [
		'userName' => $credentials['username'],
		'password' => $credentials['password'],
		'siteId'   => $site_id,
		'name'     => trim( $clean_query ),
	];

	$response = wp_remote_post( 'https://api.speedy.bg/v1/location/street', [
		'headers' => [ 'Content-Type' => 'application/json' ],
		'body'    => wp_json_encode( $payload ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		wp_send_json( [] );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	$results = [];
	if ( ! empty( $body['streets'] ) && is_array( $body['streets'] ) ) {
		foreach ( $body['streets'] as $street ) {
			$label = $street['type'] ?? '';
			if ( ! empty( $label ) ) {
				$label .= ' ';
			}
			$label .= $street['name'] ?? '';

			$results[] = [
				'id'   => $street['id'] ?? 0,
				'name' => $street['name'] ?? '',
				'type' => $street['type'] ?? '',
				'label' => trim( $label ),
			];
		}
	}

	wp_send_json( $results );
}
function speedy_modern_validate_checkout(): void {
	// Check if Speedy Modern is the selected shipping method
	$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
	$chosen_shipping = $chosen_methods[0] ?? '';

	if ( ! str_contains( $chosen_shipping, 'speedy_modern' ) ) {
		return;
	}

	// Check delivery type
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in woocommerce_checkout_process.
	$delivery_type = isset( $_POST['speedy_delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['speedy_delivery_type'] ) ) : 'address';

	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce in woocommerce_checkout_process.
		$office_id = isset( $_POST['speedy_office_id'] ) ? sanitize_text_field( wp_unslash( $_POST['speedy_office_id'] ) ) : '';

		if ( empty( $office_id ) ) {
			$error_msg = ( 'office' === $delivery_type ) 
				? __( 'Please select a Speedy office.', 'modern-shipping-for-speedy' ) 
				: __( 'Please select a Speedy automat.', 'modern-shipping-for-speedy' );
			
			wc_add_notice( $error_msg, 'error' );
		}
	}
}

/**
 * AJAX Handler: Select a Speedy service.
 * Updates the session with the chosen service and returns the new cost.
 */
add_action( 'wp_ajax_speedy_modern_select_service', 'speedy_modern_select_service_ajax' );
add_action( 'wp_ajax_nopriv_speedy_modern_select_service', 'speedy_modern_select_service_ajax' );

function speedy_modern_select_service_ajax(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;

	if ( ! $service_id || ! WC()->session ) {
		wp_send_json_error( __( 'Invalid service ID', 'modern-shipping-for-speedy' ) );
	}

	$service_options = WC()->session->get( 'speedy_modern_service_options', [] );

	if ( ! isset( $service_options[ $service_id ] ) ) {
		wp_send_json_error( __( 'Service not available', 'modern-shipping-for-speedy' ) );
	}

	$selected = $service_options[ $service_id ];

	// Update session
	WC()->session->set( 'speedy_modern_selected_service', $service_id );
	WC()->session->set( 'speedy_modern_shipping_cost', $selected['cost'] );

	// Update the shipping data payload for waybill
	$payload = WC()->session->get( 'speedy_modern_shipping_data_' . $service_id );
	if ( $payload ) {
		WC()->session->set( 'speedy_modern_shipping_data', $payload );
	}

	// Invalidate WC shipping rate cache so the next update_checkout
	// actually re-calls calculate_shipping with the new selection.
	$packages = WC()->cart ? WC()->cart->get_shipping_packages() : [];
	foreach ( $packages as $key => $package ) {
		WC()->session->set( 'shipping_for_package_' . $key, false );
	}

	wp_send_json_success( [
		'service_id' => $service_id,
		'cost'       => $selected['cost'],
		'name'       => $selected['name'],
	] );
}

/**
 * AJAX Handler: Get available Speedy service options from session.
 */
add_action( 'wp_ajax_speedy_modern_get_services', 'speedy_modern_get_services_ajax' );
add_action( 'wp_ajax_nopriv_speedy_modern_get_services', 'speedy_modern_get_services_ajax' );

function speedy_modern_get_services_ajax(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	if ( ! WC()->session ) {
		wp_send_json_error( __( 'No session', 'modern-shipping-for-speedy' ) );
	}

	$service_options = WC()->session->get( 'speedy_modern_service_options', [] );
	$selected        = WC()->session->get( 'speedy_modern_selected_service', 0 );

	wp_send_json_success( [
		'services' => array_values( $service_options ),
		'selected' => (int) $selected,
	] );
}

/**
 * Save Order Meta
 * Saves the selected office ID and delivery type to the order.
 */
add_action( 'woocommerce_checkout_update_order_meta', 'speedy_modern_save_order_meta' );
function speedy_modern_save_order_meta( $order_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
	if ( ! empty( $_POST['speedy_delivery_type'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $order_id, '_speedy_delivery_type', sanitize_text_field( wp_unslash( $_POST['speedy_delivery_type'] ) ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['speedy_office_id'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $order_id, '_speedy_office_id', sanitize_text_field( wp_unslash( $_POST['speedy_office_id'] ) ) );
	}
}

/**
 * AJAX Handler: Update cart selection for Speedy.
 */
add_action( 'wp_ajax_speedy_modern_update_cart_selection', 'speedy_modern_update_cart_selection' );
add_action( 'wp_ajax_nopriv_speedy_modern_update_cart_selection', 'speedy_modern_update_cart_selection' );

function speedy_modern_update_cart_selection(): void {
	check_ajax_referer( 'speedy_modern_public', 'nonce' );

	$delivery_type = isset( $_POST['delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_type'] ) ) : 'address';
	$city_id       = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
	$state         = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';

	if ( ! WC()->session ) {
		wp_send_json_error();
	}

	WC()->session->set( 'speedy_modern_delivery_type', $delivery_type );
	
	if ( ! empty( $state ) ) {
		WC()->session->set( 'speedy_modern_state', $state );
		if ( WC()->customer ) {
			WC()->customer->set_shipping_state( $state );
			WC()->customer->set_billing_state( $state );
		}
	}

	if ( $city_id > 0 ) {
		WC()->session->set( 'speedy_modern_city_id', $city_id );

		if ( WC()->customer ) {
			$city_name = speedy_modern_get_city_name( $city_id );
			WC()->customer->set_shipping_city( $city_name ?: $city_id );
			WC()->customer->set_billing_city( $city_name ?: $city_id );
		}
	} else {
		$city_id = absint( WC()->session->get( 'speedy_modern_city_id', 0 ) );
	}

	if ( WC()->customer ) {
		WC()->customer->save();
	}
	
	if ( 'office' === $delivery_type || 'automat' === $delivery_type ) {
		$office_id = WC_Speedy_Modern_Method::get_first_available_office( $city_id, $delivery_type );
		WC()->session->set( 'speedy_modern_office_id', $office_id );
	} else {
		WC()->session->set( 'speedy_modern_office_id', 0 );
	}

	// Check office/automat availability for this city so the JS can
	// update the radio buttons without a separate AJAX call.
	$availability = speedy_modern_check_city_availability( $city_id );

	// Clear shipping cache
	$packages = WC()->cart ? WC()->cart->get_shipping_packages() : [];
	foreach ( $packages as $key => $package ) {
		WC()->session->set( 'shipping_for_package_' . $key, false );
	}

	wp_send_json_success([
		'current_type' => $delivery_type,
		'has_office'   => $availability['has_office'],
		'has_automat'  => $availability['has_automat'],
	]);
}
