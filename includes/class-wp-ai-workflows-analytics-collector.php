<?php
/**
 * Opt-in, anonymous funnel analytics. Nothing ever leaves the site unless
 * wp_ai_workflows_analytics_opt_in is explicitly true; every send path funnels
 * through send(), which re-checks that gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Analytics_Collector {

	const ENDPOINT_PATH   = '/api/v1/telemetry/plugin';
	const WRITE_KEY       = 'wpawfk-2026-9f3c1a';
	const OPTION_SENT     = 'wp_ai_workflows_analytics_sent';
	const OPTION_SEEN     = 'wp_ai_workflows_analytics_last_seen';
	const SWEEP_TRANSIENT = 'wp_ai_workflows_analytics_sweep';

	private static $instance;

	private static $allowed_properties = array(
		'path',
		'provider',
		'mode',
		'plan',
		'source',
		'skipped',
		'wp_version',
		'php_major',
		'locale',
		'multisite',
		'woocommerce',
		'is_pro',
		'workflows',
		'executions_7d',
		'executions_30d',
		'node_types',
		'days_since_install',
	);

	private $plugin_version;
	private $is_pro;

	public function __construct( $version, $is_pro = false ) {
		$this->plugin_version = (string) $version;
		$this->is_pro         = (bool) $is_pro;
		self::$instance       = $this;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		if ( $this->is_analytics_enabled() ) {
			add_action( 'wp_ai_workflows_daily_analytics', array( $this, 'daily_tick' ) );
			add_action( 'shutdown', array( $this, 'sweep' ), 100 );
			if ( ! wp_next_scheduled( 'wp_ai_workflows_daily_analytics' ) ) {
				wp_schedule_event( time(), 'daily', 'wp_ai_workflows_daily_analytics' );
			}
		} elseif ( wp_next_scheduled( 'wp_ai_workflows_daily_analytics' ) ) {
			wp_clear_scheduled_hook( 'wp_ai_workflows_daily_analytics' );
		}
	}

	public function register_settings() {
		register_setting(
			'wp_ai_workflows_settings',
			'wp_ai_workflows_analytics_opt_in',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	private function is_analytics_enabled() {
		return (bool) get_option( 'wp_ai_workflows_analytics_opt_in', false );
	}

	private function endpoint() {
		$base = defined( 'WPAW_TELEMETRY_URL' ) ? WPAW_TELEMETRY_URL : 'https://api.wpaiworkflowautomation.com';
		return untrailingslashit( $base ) . self::ENDPOINT_PATH;
	}

	/**
	 * Only these keys can ever reach the payload; everything else is dropped.
	 *
	 * @param array $props Raw properties.
	 * @return array Sanitized, allowlisted properties.
	 */
	private static function filter_properties( $props ) {
		if ( ! is_array( $props ) ) {
			return array();
		}

		$out = array();
		foreach ( self::$allowed_properties as $key ) {
			if ( ! array_key_exists( $key, $props ) ) {
				continue;
			}
			$value = $props[ $key ];

			if ( 'node_types' === $key ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$types = array();
				foreach ( $value as $type => $count ) {
					if ( count( $types ) >= 40 ) {
						break;
					}
					$type = sanitize_key( (string) $type );
					if ( '' === $type ) {
						continue;
					}
					$types[ substr( $type, 0, 32 ) ] = (int) $count;
				}
				$out[ $key ] = $types;
				continue;
			}

			if ( is_bool( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean       = preg_replace( '/[^A-Za-z0-9._-]/', '', $value );
				$out[ $key ] = substr( (string) $clean, 0, 64 );
			}
		}
		return $out;
	}

	private function installation_id() {
		$id = get_option( 'wp_ai_workflows_installation_id' );
		if ( ! $id ) {
			$id = wp_generate_uuid4();
			update_option( 'wp_ai_workflows_installation_id', $id );
			update_option( 'wp_ai_workflows_installed_at', current_time( 'mysql', true ) );
		}
		return $id;
	}

	private function build_payload( $event, $props ) {
		$properties = self::filter_properties( $props );
		return array(
			'installation_id' => $this->installation_id(),
			'event'           => (string) $event,
			'timestamp'       => gmdate( 'c' ),
			'plugin_version'  => $this->plugin_version,
			// PHP encodes an empty array as JSON `[]`; the backend requires an object.
			'properties'      => empty( $properties ) ? new stdClass() : $properties,
		);
	}

	private function send( $event, $props = array() ) {
		if ( ! $this->is_analytics_enabled() ) {
			return;
		}
		$payload = $this->build_payload( $event, $props );
		$body    = wp_json_encode( $payload );

		// Shed the only variable-length field rather than lose the event.
		if ( is_string( $body ) && strlen( $body ) > 1024 && ! empty( $payload['properties']['node_types'] ) ) {
			unset( $payload['properties']['node_types'] );
			$body = wp_json_encode( $payload );
		}

		if ( ! is_string( $body ) || strlen( $body ) > 1024 ) {
			return;
		}

		wp_remote_post(
			$this->endpoint(),
			array(
				'body'      => $body,
				'headers'   => array(
					'Content-Type'         => 'application/json',
					'X-WPAW-Telemetry-Key' => self::WRITE_KEY,
				),
				'timeout'   => 5,
				'blocking'  => false,
				'sslverify' => true,
			)
		);
	}

	public static function sent_events() {
		$sent = get_option( self::OPTION_SENT, array() );
		return is_array( $sent ) ? $sent : array();
	}

	private static function mark_sent( $event ) {
		$sent           = self::sent_events();
		$sent[ $event ] = gmdate( 'c' );
		update_option( self::OPTION_SENT, $sent, false );
	}

	/**
	 * Send an event at most once per install. Marks it sent before sending so a
	 * failed request never retries forever and never double-sends.
	 *
	 * @param string $event Event name.
	 * @param array  $props Raw properties, filtered before transmission.
	 * @return void
	 */
	public static function record( $event, $props = array() ) {
		if ( null === self::$instance || ! self::$instance->is_analytics_enabled() ) {
			return;
		}
		$sent = self::sent_events();
		if ( isset( $sent[ $event ] ) ) {
			return;
		}
		self::mark_sent( $event );
		self::$instance->send( $event, $props );
	}

	private function first_configured_provider() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$providers = array(
			'openai_api_key'     => 'openai',
			'openrouter_api_key' => 'openrouter',
			'perplexity_api_key' => 'perplexity',
		);
		foreach ( $providers as $key => $name ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return $name;
			}
		}
		return '';
	}

	private function was_active_within( $days ) {
		$seen = get_option( self::OPTION_SEEN );
		if ( $seen && strtotime( (string) $seen ) >= time() - $days * DAY_IN_SECONDS ) {
			return true;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wp_ai_workflows_executions';
		$row   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE created_at >= %s LIMIT 1',
				$table,
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
		return null !== $row;
	}

	private function node_type_counts() {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM %i LIMIT 200', $table ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $raw ) {
			$decoded = json_decode( (string) $raw, true );
			if ( ! is_array( $decoded ) || empty( $decoded['nodes'] ) || ! is_array( $decoded['nodes'] ) ) {
				continue;
			}
			foreach ( $decoded['nodes'] as $node ) {
				if ( ! is_array( $node ) || empty( $node['type'] ) ) {
					continue;
				}
				$type = sanitize_key( (string) $node['type'] );
				if ( '' === $type ) {
					continue;
				}
				if ( ! isset( $counts[ $type ] ) ) {
					if ( count( $counts ) >= 40 ) {
						continue;
					}
					$counts[ $type ] = 0;
				}
				++$counts[ $type ];
			}
		}
		return $counts;
	}

	/**
	 * Runs on shutdown of an admin/REST request. Sends whichever once-only
	 * milestone events have not fired yet, derived from stored option state.
	 *
	 * @return void
	 */
	public function sweep() {
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! $this->is_analytics_enabled() ) {
			return;
		}

		$sent = self::sent_events();

		if ( ! isset( $sent['installed'] ) ) {
			self::record(
				'installed',
				array(
					'wp_version'  => get_bloginfo( 'version' ),
					'php_major'   => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'locale'      => get_locale(),
					'woocommerce' => class_exists( 'WooCommerce' ),
					'multisite'   => is_multisite(),
					'is_pro'      => $this->is_pro,
				)
			);
			$sent = self::sent_events();
		}

		if ( ! isset( $sent['onboarding_completed'] ) && get_option( 'wp_ai_workflows_setup_completed' ) ) {
			$path = 'none';
			if ( class_exists( 'WP_AI_Workflows_Platform_Client' ) && WP_AI_Workflows_Platform_Client::is_connected() ) {
				$path = 'credits';
			} elseif ( '' !== $this->first_configured_provider() ) {
				$path = 'byok';
			}
			self::record( 'onboarding_completed', array( 'path' => $path ) );
			$sent = self::sent_events();
		}

		if ( ! isset( $sent['provider_connected'] ) ) {
			$provider = $this->first_configured_provider();
			if ( '' !== $provider ) {
				self::record(
					'provider_connected',
					array(
						'provider' => $provider,
						'source'   => 'byok',
					)
				);
			} elseif ( class_exists( 'WP_AI_Workflows_Platform_Client' ) && WP_AI_Workflows_Platform_Client::is_connected() ) {
				self::record(
					'provider_connected',
					array(
						'provider' => 'platform',
						'source'   => 'platform',
					)
				);
			}
			$sent = self::sent_events();
		}

		$need_counts = ! isset( $sent['first_workflow_created'] )
			|| ! isset( $sent['second_workflow_created'] )
			|| ! isset( $sent['first_execution_succeeded'] );

		if ( $need_counts && ! get_transient( self::SWEEP_TRANSIENT ) ) {
			set_transient( self::SWEEP_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

			if ( class_exists( 'WP_AI_Workflows_Workflow_DBAL' ) ) {
				$counts = WP_AI_Workflows_Workflow_DBAL::count_workflows_by_status();
				$total  = is_array( $counts ) && isset( $counts['total'] ) ? (int) $counts['total'] : 0;

				if ( ! isset( $sent['first_workflow_created'] ) && $total >= 1 ) {
					self::record( 'first_workflow_created' );
				}
				if ( ! isset( $sent['second_workflow_created'] ) && $total >= 2 ) {
					self::record( 'second_workflow_created' );
				}
			}

			if ( ! isset( $sent['first_execution_succeeded'] ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'wp_ai_workflows_executions';
				$row   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT cost_details FROM %i WHERE status = 'completed' ORDER BY id ASC LIMIT 1",
						$table
					)
				);
				if ( null !== $row ) {
					$mode = ( is_string( $row ) && false !== strpos( $row, 'cloud_status' ) ) ? 'cloud' : 'local';
					self::record( 'first_execution_succeeded', array( 'mode' => $mode ) );
				}
			}
		}

		$today = gmdate( 'Y-m-d' );
		if ( get_option( self::OPTION_SEEN ) !== $today ) {
			update_option( self::OPTION_SEEN, $today, false );
		}
	}

	/**
	 * Daily cron: retention milestones plus the one repeating event (heartbeat),
	 * sent unconditionally so it bypasses the once-only record() path.
	 *
	 * @return void
	 */
	public function daily_tick() {
		if ( ! $this->is_analytics_enabled() ) {
			return;
		}

		$installed_at = get_option( 'wp_ai_workflows_installed_at' );
		$days_since   = $installed_at ? (int) floor( ( time() - strtotime( (string) $installed_at ) ) / DAY_IN_SECONDS ) : 0;

		if ( $days_since >= 7 && $this->was_active_within( 7 ) ) {
			self::record( 'active_after_7_days', array( 'days_since_install' => $days_since ) );
		}
		if ( $days_since >= 30 && $this->was_active_within( 30 ) ) {
			self::record( 'active_after_30_days', array( 'days_since_install' => $days_since ) );
		}

		$counts    = class_exists( 'WP_AI_Workflows_Workflow_DBAL' ) ? WP_AI_Workflows_Workflow_DBAL::count_workflows_by_status() : array();
		$workflows = is_array( $counts ) && isset( $counts['total'] ) ? (int) $counts['total'] : 0;

		global $wpdb;
		$table          = $wpdb->prefix . 'wp_ai_workflows_executions';
		$executions_7d  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			)
		);
		$executions_30d = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);

		$this->send(
			'heartbeat',
			array(
				'workflows'          => $workflows,
				'executions_7d'      => $executions_7d,
				'executions_30d'     => $executions_30d,
				'node_types'         => $this->node_type_counts(),
				'days_since_install' => $days_since,
			)
		);
	}

	/**
	 * Records a one-time 'upgraded' event when a subscription becomes active on
	 * a paid plan.
	 *
	 * @param string $plan   Plan name from the platform.
	 * @param string $status Subscription status from the platform.
	 * @return void
	 */
	public static function note_plan( $plan, $status ) {
		$plan   = is_string( $plan ) ? $plan : '';
		$status = is_string( $status ) ? $status : '';
		if ( ! in_array( $status, array( 'active', 'trialing' ), true ) ) {
			return;
		}
		if ( '' === $plan || 'free' === $plan || 'none' === $plan ) {
			return;
		}
		self::record( 'upgraded', array( 'plan' => $plan ) );
	}

	public static function uninstall() {
		delete_option( 'wp_ai_workflows_installation_id' );
		delete_option( 'wp_ai_workflows_installed_at' );
		delete_option( 'wp_ai_workflows_last_activation' );
		delete_option( 'wp_ai_workflows_analytics_opt_in' );
		delete_option( 'wp_ai_workflows_analytics_opt_out' );
		delete_option( self::OPTION_SENT );
		delete_option( self::OPTION_SEEN );
		delete_option( 'wp_ai_workflows_analytics_notice_dismissed' );
	}
}
