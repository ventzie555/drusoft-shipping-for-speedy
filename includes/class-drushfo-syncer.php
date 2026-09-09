<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles background synchronization of Speedy locations (Cities and Offices).
 */
class Drushfo_Syncer {

	/**
	 * Main entry point for the background job.
	 */
	public static function sync() {
		// Increase time limit for sync
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- bulk sync of thousands of rows needs extended time.
		}

		// Get Settings (to access API credentials)
		// Try global settings first
		$settings = get_option( 'woocommerce_drushfo_speedy_settings' );
		$username = $settings['speedy_username'] ?? '';
		$password = $settings['speedy_password'] ?? '';

		// If not found, try to find ANY instance with credentials
		if ( empty( $username ) || empty( $password ) ) {
			global $wpdb;
			$option_like = 'woocommerce_drushfo_speedy_%_settings';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$option_like
				)
			);

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$inst_settings = maybe_unserialize( $row->option_value );
					if ( is_array( $inst_settings ) && ! empty( $inst_settings['speedy_username'] ) && ! empty( $inst_settings['speedy_password'] ) ) {
						$username = $inst_settings['speedy_username'];
						$password = $inst_settings['speedy_password'];
						break;
					}
				}
			}
		}
		
		if ( empty( $username ) || empty( $password ) ) {
			// Log error: Credentials missing
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( __( 'Speedy Sync Failed: Missing credentials.', 'drusoft-shipping-for-speedy' ), array( 'source' => 'drusoft-shipping-for-speedy' ) );
			}
			return;
		}

		$creds = [ 'speedy_username' => $username, 'speedy_password' => $password ];

		$cities  = self::update_cities( $creds );
		$offices = self::update_offices( $creds );

		// The moment both tables were refreshed from the API. An option write
		// works regardless of who owns the uploads directory — the WC log does
		// not: wc_get_logger() swallows a failed file write, so a missing log
		// line proves nothing about whether this ran. This is what the settings
		// screen shows, and what to trust when the log looks dead.
		if ( $cities && $offices ) {
			update_option( 'drushfo_last_sync', time(), false );
		}
	}

	private static function update_cities( $settings ): bool {
		global $wpdb;
		
		$username = $settings['speedy_username'];
		$password = $settings['speedy_password'];

		// Use CSV endpoint for full list
		$response = wp_remote_post( 'https://api.speedy.bg/v1/location/site/csv/100', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'userName' => $username,
				'password' => $password,
			] ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( __( 'Speedy Cities Sync Error: ', 'drusoft-shipping-for-speedy' ) . $response->get_error_message(), array( 'source' => 'drusoft-shipping-for-speedy' ) );
			}
			return false;
		}

		$response = wp_remote_retrieve_body( $response );

		// Parse CSV
		$lines = explode( "\n", $response );
		if ( empty( $lines ) ) {
			return false;
		}

		// Get headers
		$header = str_getcsv( array_shift( $lines ), ',', '"', '' );

		$table_name = $wpdb->prefix . 'drushfo_cities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}drushfo_cities" );

		$count = 0;

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$row = str_getcsv( $line, ',', '"', '' );
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$city = array_combine( $header, $row );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table_name,
				array(
					'id'        => $city['id'],
					'name'      => self::mb_ucfirst( $city['name'] ?? '' ),
					'post_code' => $city['postCode'] ?? '',
					'region'    => self::mb_ucfirst( $city['region'] ?? '' ),
					'type'      => $city['type'] ?? ''
				)
			);
			$count++;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			wc_get_logger()->info( __( 'Speedy Cities Sync Completed. Count: ', 'drusoft-shipping-for-speedy' ) . $count, array( 'source' => 'drusoft-shipping-for-speedy' ) );
		}
		return $count > 0;
	}

	private static function update_offices( $settings ): bool {
		global $wpdb;
		
		$username = $settings['speedy_username'];
		$password = $settings['speedy_password'];

		$response = wp_remote_post( 'https://api.speedy.bg/v1/location/office', [
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode( [
				'userName'  => $username,
				'password'  => $password,
				'countryId' => 100, // Bulgaria
			] ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( __( 'Speedy Offices Sync Error: ', 'drusoft-shipping-for-speedy' ) . $response->get_error_message(), array( 'source' => 'drusoft-shipping-for-speedy' ) );
			}
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['offices'] ) && is_array( $data['offices'] ) ) {
			$table_name = $wpdb->prefix . 'drushfo_offices';
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}drushfo_offices" );

			foreach ( $data['offices'] as $office ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$table_name,
					array(
						'id'              => $office['id'],
						'name'            => $office['name'],
						'city'            => self::mb_ucfirst( $office['address']['siteName'] ?? '' ),
						'address'         => $office['address']['fullAddressString'] ?? '',
						'latitude'        => $office['address']['x'] ?? '',
						'longitude'       => $office['address']['y'] ?? '',
						'city_id'         => $office['siteId'] ?? 0,
						'office_type'     => $office['type'] ?? '',
						'post_code'       => $office['address']['postCode'] ?? '',
						'address_details' => maybe_serialize( $office['address'] ?? [] ),
						'office_details'  => '', // Not provided in basic office response usually
						'phone'           => '', // Not always available in this endpoint
						'email'           => ''  // Not always available in this endpoint
					)
				);
			}

			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->info( __( 'Speedy Offices Sync Completed. Count: ', 'drusoft-shipping-for-speedy' ) . count($data['offices']), array( 'source' => 'drusoft-shipping-for-speedy' ) );
			}
			return count( $data['offices'] ) > 0;
		}
		return false;
	}

	/**
	 * Helper to capitalize first letter of a multi-byte string
	 */
	private static function mb_ucfirst($string, $encoding = 'UTF-8') {
		$string = mb_strtolower( $string, $encoding );
		$strlen = mb_strlen($string, $encoding);
		if ($strlen <= 0) {
			return $string;
		}
		$firstChar = mb_substr($string, 0, 1, $encoding);
		$then = mb_substr($string, 1, $strlen - 1, $encoding);
		return mb_strtoupper($firstChar, $encoding) . $then;
	}
}
