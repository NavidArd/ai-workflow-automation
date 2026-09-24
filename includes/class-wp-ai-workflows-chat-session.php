<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Chat_Session {
	private $session_id;
	private $workflow_id;
	private $max_history_length;
	private $rate_limit_config;

	public function __construct( $workflow_id, $session_id = null ) {
		$this->workflow_id = $workflow_id;

		if ( $session_id ) {
			global $wpdb;
			$table_name       = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
			$existing_session = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE session_id = %s AND workflow_id = %s",
					$table_name,
					$session_id,
					$workflow_id
				)
			);

			if ( $existing_session ) {
				$this->session_id = $session_id;
				$this->load_session_config();
				return;
			}
		}

		$this->session_id = wp_generate_uuid4();
		$this->initialize_session();
	}

	private function initialize_session() {
		$expiry = time() + ( 12 * 3600 ); // 12 hours

		set_transient(
			'wp_ai_chat_session_' . $this->session_id,
			array(
				'expires'     => $expiry,
				'workflow_id' => $this->workflow_id,
			),
			12 * 3600
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';

		$wpdb->insert(
			$table_name,
			array(
				'session_id'  => $this->session_id,
				'workflow_id' => $this->workflow_id,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		$this->load_session_config();

		WP_AI_Workflows_Utilities::debug_log(
			'New chat session initialized',
			'debug',
			array(
				'session_id'  => $this->session_id,
				'workflow_id' => $this->workflow_id,
			)
		);
	}

	private function load_session_config() {
		$workflow = $this->get_workflow();
		if ( $workflow ) {
			$chat_node = $this->find_chat_node( $workflow['nodes'] );
			if ( $chat_node ) {
				$this->max_history_length = $chat_node['data']['behavior']['maxHistoryLength'] ?? 50;
				$this->rate_limit_config  = $chat_node['data']['behavior']['rateLimit'] ?? null;
			}
		}
	}

	public function update_last_activity() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';

		$wpdb->update(
			$table_name,
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'session_id' => $this->session_id )
		);
	}

	public function can_send_message() {
		if ( ! $this->rate_limit_config || ! $this->rate_limit_config['enabled'] ) {
			return true;
		}

		$recent_messages = $this->get_recent_messages_count();

		$ip       = $this->get_client_ip();
		$ip_key   = 'wp_ai_chat_ip_' . md5( $ip );
		$ip_count = get_transient( $ip_key ) ?: 0;

		set_transient( $ip_key, $ip_count + 1, 3600 ); // 1 hour expiry

		$session_limit_exceeded = $recent_messages >= $this->rate_limit_config['maxMessages'];
		$ip_limit_exceeded = $ip_count > 100; // 100 messages per hour per IP

		return ! ( $session_limit_exceeded || $ip_limit_exceeded );
	}

	private function get_client_ip() {
		$ip_headers = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $ip_headers as $header ) {
			$server_value = filter_input( INPUT_SERVER, $header, FILTER_SANITIZE_SPECIAL_CHARS );
			if ( ! empty( $server_value ) ) {
				$ip = $server_value;
				// If it's a list of IPs, take the first one
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	private function get_recent_messages_count() {
		global $wpdb;
		$table_name  = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
		$time_window = $this->rate_limit_config['timeWindow'];

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i 
            WHERE session_id = %s 
            AND role = 'user'
            AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
				$table_name,
				$this->session_id,
				$time_window
			)
		);
	}

	public function add_message( $role, $content, $metadata = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$data    = array(
			'session_id' => $this->session_id,
			'role'       => $role,
			'content'    => $content,
			'created_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		// Optional structured metadata (e.g. a shared-file descriptor). Stored in
		// the existing metadata column so history/reloads render file messages.
		if ( null !== $metadata ) {
			$data['metadata'] = is_string( $metadata ) ? $metadata : wp_json_encode( $metadata );
			$formats[]        = '%s';
		}

		$wpdb->insert( $table_name, $data, $formats );

		$sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
		$wpdb->update(
			$sessions_table,
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'session_id' => $this->session_id )
		);

		$this->trim_history();
	}

	public function get_history() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i 
            WHERE session_id = %s 
            ORDER BY created_at ASC",
				$table_name,
				$this->session_id
			)
		);

		// Apply LIMIT safely after query
		return array_slice( $results, 0, (int) $this->max_history_length );
	}

	private function trim_history() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE session_id = %s",
				$table_name,
				$this->session_id
			)
		);

		if ( $count > $this->max_history_length ) {
			$to_delete = $count - $this->max_history_length;
			// Get IDs of messages to delete (safe LIMIT approach)
			$ids_to_delete = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i 
                    WHERE session_id = %s 
                    ORDER BY created_at ASC",
					$table_name,
					$this->session_id
				)
			);

			if ( ! empty( $ids_to_delete ) ) {
				$ids_to_delete = array_slice( $ids_to_delete, 0, (int) $to_delete );

				// Use individual DELETE statements to avoid dynamic IN clause
				foreach ( $ids_to_delete as $id ) {
					$wpdb->query(
						$wpdb->prepare(
							"DELETE FROM %i WHERE id = %d",
							$table_name,
							$id
						)
					);
				}
			}
		}
	}

	public function get_session_id() {
		return $this->session_id;
	}

	public function get_workflow_id() {
		return $this->workflow_id;
	}

	private function get_workflow() {
		return WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $this->workflow_id );
	}

	private function find_chat_node( $nodes ) {
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'chat' ) {
				return $node;
			}
		}
		return null;
	}

	public function is_new_session() {
		if ( ! isset( $this->is_new ) ) {
			$history      = $this->get_history();
			$this->is_new = empty( $history );
		}
		return $this->is_new;
	}
}
