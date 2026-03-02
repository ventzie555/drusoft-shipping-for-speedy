<?php

/**
 * Handles background synchronization of Speedy locations (Cities and Offices).
 */
class Speedy_Modern_Syncer {

	/**
	 * Main entry point for the background job.
	 */
	public static function sync() {
		// Increase time limit for sync
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		// Get Settings (to access API credentials)
		// Try global settings first
		$settings = get_option( 'woocommerce_speedy_modern_settings' );
		$username = $settings['speedy_username'] ?? '';
		$password = $settings['speedy_password'] ?? '';

		// If not found, try to find ANY instance with credentials
		if ( empty( $username ) || empty( $password ) ) {
			global $wpdb;
			$option_like = 'woocommerce_speedy_modern_%_settings';
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
				wc_get_logger()->error( 'Speedy Sync Failed: Missing credentials.', array( 'source' => 'speedy-modern' ) );
			}
			return;
		}

		$creds = [ 'speedy_username' => $username, 'speedy_password' => $password ];

		// Sync Cities
		self::update_cities( $creds );

		// Sync Offices
		self::update_offices( $creds );
	}

	private static function update_cities( $settings ) {
		global $wpdb;
		
		$username = $settings['speedy_username'];
		$password = $settings['speedy_password'];

		$body = json_encode( [
			'userName' => $username,
			'password' => $password,
		] );

		// Use CSV endpoint for full list
		$ch = curl_init( 'https://api.speedy.bg/v1/location/site/csv/100' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json'
		] );

		$response = curl_exec( $ch );
		$error    = curl_error( $ch );
		curl_close( $ch );

		if ( $error ) {
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Speedy Cities Sync Error: ' . $error, array( 'source' => 'speedy-modern' ) );
			}
			return;
		}

		// Parse CSV
		$lines = explode( "\n", $response );
		if ( empty( $lines ) ) {
			return;
		}

		// Get headers
		$header = str_getcsv( array_shift( $lines ) );

		$table_name = $wpdb->prefix . 'speedy_cities';

		// Truncate table
		$wpdb->query( "TRUNCATE TABLE $table_name" );

		//$arr_excluded_cities = [21539, 21542]; // Exclude problematic cities
		$count = 0;

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$row = str_getcsv( $line );
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$city = array_combine( $header, $row );

			/*if ( in_array( $city['id'], $arr_excluded_cities ) ) {
				continue;
			}*/

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
			wc_get_logger()->info( 'Speedy Cities Sync Completed. Count: ' . $count, array( 'source' => 'speedy-modern' ) );
		}
	}

	private static function update_offices( $settings ) {
		global $wpdb;
		
		$username = $settings['speedy_username'];
		$password = $settings['speedy_password'];

		$body = json_encode( [
			'userName' => $username,
			'password' => $password,
			'countryId' => 100 // Bulgaria
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
			if ( class_exists( 'WC_Logger' ) ) {
				wc_get_logger()->error( 'Speedy Offices Sync Error: ' . $error, array( 'source' => 'speedy-modern' ) );
			}
			return;
		}

		$data = json_decode( $response, true );

		if ( isset( $data['offices'] ) && is_array( $data['offices'] ) ) {
			$table_name = $wpdb->prefix . 'speedy_offices';
			
			// Truncate table
			$wpdb->query( "TRUNCATE TABLE $table_name" );

			foreach ( $data['offices'] as $office ) {
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
				wc_get_logger()->info( 'Speedy Offices Sync Completed. Count: ' . count($data['offices']), array( 'source' => 'speedy-modern' ) );
			}
		}
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
