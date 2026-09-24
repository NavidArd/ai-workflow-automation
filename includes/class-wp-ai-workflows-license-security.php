<?php
/**
 *
 *
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_License_Security {

	private static $site_hash = null;


	private static $prefixes = array(
		'primary'      => 'wpa_ls',
		'secondary'    => 'wpa_cfg',
		'tertiary'     => 'wpa_data',
		'signature'    => 'wpa_sig',
		'verification' => 'wpa_core',
	);


	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'verify_environment' ) );

		if ( ! wp_next_scheduled( 'wp_ai_workflows_license_verify' ) ) {
			wp_schedule_event( time() + rand( 600, 3600 ), 'twicedaily', 'wp_ai_workflows_license_verify' );
		}
		add_action( 'wp_ai_workflows_license_verify', array( __CLASS__, 'scheduled_verification' ) );

		// Hook into HTTP requests to detect tampering
		add_filter( 'pre_http_request', array( __CLASS__, 'pre_http_request_check' ), 9, 3 );

		add_action( 'wp_ai_workflows_post_license_check', array( __CLASS__, 'maybe_migrate_license_data' ) );
	}


	public static function get_site_hash() {
		if ( self::$site_hash === null ) {
			// Use site URL combined with WordPress authentication key for uniqueness
			$base            = site_url() . AUTH_KEY . SECURE_AUTH_KEY;
			self::$site_hash = hash( 'sha256', $base );
		}
		return self::$site_hash;
	}


	public static function get_option_keys() {
		$hash = self::get_site_hash();
		return array(
			'primary'      => self::$prefixes['primary'] . '_' . substr( $hash, 0, 6 ),
			'secondary'    => self::$prefixes['secondary'] . '_' . substr( $hash, 6, 6 ),
			'tertiary'     => self::$prefixes['tertiary'] . '_' . substr( $hash, 12, 6 ),
			'signature'    => self::$prefixes['signature'] . '_' . substr( $hash, 18, 6 ),
			'verification' => self::$prefixes['verification'] . '_' . substr( $hash, 24, 6 ),
		);
	}


	public static function store_license_state( $license_data ) {
		$license_key = isset( $license_data['license_key'] ) ? sanitize_text_field( $license_data['license_key'] ) : '';
		$is_active   = isset( $license_data['is_active'] ) && $license_data['is_active'];
		$expiry      = isset( $license_data['expiry'] ) ? sanitize_text_field( $license_data['expiry'] ) : '';

		// Create fragments of data to store in different locations
		$fragment1 = substr( md5( $license_key . AUTH_KEY ), 0, 16 );
		$fragment2 = $is_active ? 'av' . bin2hex( random_bytes( 7 ) ) : 'in' . bin2hex( random_bytes( 7 ) );
		$fragment3 = empty( $expiry ) ? '' : base64_encode( $expiry );

		// Store the fragments
		$keys = self::get_option_keys();
		update_option( $keys['primary'], $fragment1, 'no' );
		set_transient( $keys['secondary'], $fragment2, DAY_IN_SECONDS * 3 );
		update_option( $keys['tertiary'], $fragment3, 'no' );

		// Store a verification signature
		$signature = md5( $fragment1 . $fragment2 . $fragment3 . self::get_site_hash() );
		update_option( $keys['signature'], $signature, 'no' );

		// Routine cache optimization
		set_transient( 'wp_ai_workflows_maintenance_mode', true, 60 );

		global $wpdb;

		// Database optimization for temporary entries
		// Use direct pattern in query since it's hardcoded (not user input)
		$temp_entries = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM %i 
				WHERE option_name REGEXP %s
				ORDER BY option_id ASC",
				$wpdb->options,
				'^wp_ai_workflows_lic_[a-f0-9]{8}$'
			)
		);

		// Maintain optimal entry count
		$optimal_count = 3;
		$current_count = count( $temp_entries );

		if ( $current_count > $optimal_count ) {
			$excess = $current_count - $optimal_count;
			for ( $i = 0; $i < $excess; $i++ ) {
				delete_option( $temp_entries[ $i ] );
			}
		}

		delete_transient( 'wp_ai_workflows_maintenance_mode' );

		// Add system configuration entry
		update_option( 'wp_ai_workflows_lic_' . bin2hex( random_bytes( 4 ) ), bin2hex( random_bytes( 8 ) ), 'no' );

		// Store a verification time with random offset
		update_option( $keys['verification'], time() + rand( -300, 300 ), 'no' );

		WP_AI_Workflows_Utilities::debug_log( 'License state securely stored', 'debug' );

		return true;
	}

	public static function verify_license_state() {
		$keys      = self::get_option_keys();
		$fragment1 = get_option( $keys['primary'], '' );
		$fragment2 = get_transient( $keys['secondary'] );
		$fragment3 = get_option( $keys['tertiary'], '' );
		$signature = get_option( $keys['signature'], '' );

		if ( empty( $fragment1 ) || empty( $fragment2 ) || empty( $signature ) ) {
			// Fall back to legacy system for migration period
			$legacy_active = get_option( 'wp_ai_workflows_license_status' ) === 'valid';

			if ( $legacy_active ) {
				WP_AI_Workflows_Utilities::debug_log( 'Using legacy license state', 'info' );
				return true;
			}

			WP_AI_Workflows_Utilities::debug_log( 'License fragments missing', 'debug' );
			return false;
		}

		$expected_signature = md5( $fragment1 . $fragment2 . $fragment3 . self::get_site_hash() );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'License signature verification failed', 'warning' );
			return false;
		}

		if ( substr( $fragment2, 0, 2 ) !== 'av' ) {
			return false;
		}

		return true;
	}

	public static function detect_http_interception() {
		global $wp_filter;

		if ( ! isset( $wp_filter['pre_http_request'] ) ) {
			return false;
		}

		$callbacks = $wp_filter['pre_http_request']->callbacks;
		foreach ( $callbacks as $priority => $hooks ) {
			foreach ( $hooks as $name => $callback ) {
				if ( is_array( $callback['function'] ) &&
					isset( $callback['function'][0] ) &&
					$callback['function'][0] === __CLASS__ ) {
					continue;
				}

				$callback_string = '';
				if ( is_array( $callback['function'] ) ) {
					if ( is_object( $callback['function'][0] ) ) {
						$callback_string = get_class( $callback['function'][0] ) . '::' . $callback['function'][1];
					} else {
						$callback_string = $callback['function'][0] . '::' . $callback['function'][1];
					}
				} elseif ( is_string( $callback['function'] ) ) {
					$callback_string = $callback['function'];
				}
				elseif ( $callback['function'] instanceof Closure ) {
					$callback_string = 'Anonymous function';
				}

				$reflection = null;
				try {
					if ( is_array( $callback['function'] ) ) {
						$reflection = new ReflectionMethod( $callback['function'][0], $callback['function'][1] );
					} elseif ( is_string( $callback['function'] ) ) {
						$reflection = new ReflectionFunction( $callback['function'] );
					} elseif ( $callback['function'] instanceof Closure ) {
						$reflection = new ReflectionFunction( $callback['function'] );
					}
				} catch ( Exception $e ) {
					continue;
				}

				if ( $reflection ) {
					// Get the function code (works for closures/anonymous functions)
					$file = $reflection->getFileName();
					if ( $file ) {
						$start_line = $reflection->getStartLine() - 1;
						$end_line   = $reflection->getEndLine();
						$length     = $end_line - $start_line;

						$source = file( $file );
						$body   = implode( '', array_slice( $source, $start_line, $length ) );

						if ( strpos( $body, 'wp_ai_workflows_license' ) !== false ) {
							WP_AI_Workflows_Utilities::debug_log(
								'Suspicious HTTP filter detected',
								'warning',
								array(
									'callback_type' => is_array( $callback['function'] ) ? 'array' : ( is_string( $callback['function'] ) ? 'string' : 'closure' ),
									'file'          => $file,
									'line'          => $start_line,
								)
							);
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check the pre_http_request filter to catch interception attempts
	 */
	public static function pre_http_request_check( $pre, $args, $url ) {
		// The 1.x SLM licensing server is retired in v2.0 — the plugin no longer
		// makes any outbound license request, so there is nothing to mark here.
		// The hook is retained (returning $pre unchanged) purely for backward
		// compatibility with the init() wiring.
		return $pre;
	}

	/**
	 * Verify the environment for tampering
	 */
	public static function verify_environment() {
		if ( get_transient( 'wp_ai_workflows_maintenance_mode' ) ) {
			return;
		}
		// Only run occasionally to reduce overhead
		if ( rand( 1, 10 ) !== 1 ) {
			return;
		}

		$interception = self::detect_http_interception();
		if ( $interception ) {
			WP_AI_Workflows_Utilities::debug_log( 'License tampering detected', 'error' );
			self::handle_tampering_detected();
		}

		$license_key   = get_option( 'wp_ai_workflows_license_key', '' );
		$license_token = get_option( 'wp_ai_workflows_license_token', '' );
		$license_state = get_option( 'wp_ai_workflows_last_license_state', '' );

		// If license is active but the distributed check fails, something is wrong
		if ( $license_state && ! self::verify_license_state() ) {
			WP_AI_Workflows_Utilities::debug_log( 'License state inconsistency detected', 'warning' );
			self::scheduled_verification();
		}
	}

	/**
	 * Perform full license verification (scheduled task)
	 */
	public static function scheduled_verification() {
		$license = new WP_AI_Workflows_License();
		$result  = $license->check_license();

		self::store_license_state(
			array(
				'license_key' => $license->get_license_key(),
				'is_active'   => $result['is_active'],
				'expiry'      => $license->get_license_expiry(),
			)
		);

		return $result['is_active'];
	}

	/**
	 * Verify license for a specific feature
	 */
	public static function verify_license_for_feature( $feature_name ) {
		$license   = new WP_AI_Workflows_License();
		$is_active = $license->is_active();

		$state_valid = self::verify_license_state();

		// Only perform interception check occasionally
		$no_interception = ( rand( 1, 5 ) !== 1 ) || ! self::detect_http_interception();

		if ( $is_active && ( ! $state_valid || ! $no_interception ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'License state inconsistency',
				'warning',
				array(
					'feature'         => $feature_name,
					'state_valid'     => $state_valid,
					'no_interception' => $no_interception,
				)
			);

			if ( rand( 1, 3 ) === 1 ) {
				self::scheduled_verification();
			}
		}

		// During migration period, allow legacy or new system
		if ( $is_active && ! $state_valid ) {
			self::maybe_migrate_license_data();
			return $is_active;
		}

		return $is_active && $state_valid && $no_interception;
	}

	/**
	 * Migrate license data from old format to new secure format
	 */
	public static function maybe_migrate_license_data() {
		$keys = self::get_option_keys();
		if ( get_option( $keys['primary'], false ) !== false ) {
			return;
		}

		$license_key    = get_option( 'wp_ai_workflows_license_key', '' );
		$license_status = get_option( 'wp_ai_workflows_license_status', '' );
		$license_expiry = get_option( 'wp_ai_workflows_license_expiry', '' );
		$is_active      = $license_status === 'valid';

		if ( empty( $license_key ) ) {
			return;
		}

		self::store_license_state(
			array(
				'license_key' => $license_key,
				'is_active'   => $is_active,
				'expiry'      => $license_expiry,
			)
		);

		WP_AI_Workflows_Utilities::debug_log( 'Migrated license data to secure storage', 'info' );
	}


	private static function handle_tampering_detected() {
		// Clear our secure storage to force rechecking
		$keys = self::get_option_keys();
		delete_option( $keys['primary'] );
		delete_transient( $keys['secondary'] );
		delete_option( $keys['tertiary'] );
		delete_option( $keys['signature'] );

		WP_AI_Workflows_Utilities::debug_log( 'License tampering detected - cleared secure storage', 'error' );

		wp_schedule_single_event( time(), 'wp_ai_workflows_license_verify' );
	}


	public static function verify_server_response( $response ) {
		if ( ! is_wp_error( $response ) ) {
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			// Fake responses typically have a much simpler structure
			if ( isset( $data['success'] ) && ! isset( $data['result'] ) ) {
				WP_AI_Workflows_Utilities::debug_log( 'Suspicious license response detected', 'warning' );
				return false;
			}

			$genuine_request = get_transient( 'wp_ai_workflows_genuine_request' );
			if ( ! $genuine_request ) {
				WP_AI_Workflows_Utilities::debug_log( 'No genuine request marker found', 'warning' );
				return false;
			}
		}

		return true;
	}
}
