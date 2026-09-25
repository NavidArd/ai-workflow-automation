<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Human_Tasks {
	private $table_name;
	private $db_version = '1.0';

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wp_ai_workflows_human_tasks';
		$this->maybe_update_db_structure();
	}

	private function maybe_update_db_structure() {
		$current_db_version = get_option( 'wp_ai_workflows_human_tasks_db_version' );

		if ( $current_db_version !== $this->db_version ) {
			if ( $this->check_and_add_missing_columns() ) {
				update_option( 'wp_ai_workflows_human_tasks_db_version', $this->db_version );
			}
		}
	}

	public function get_pending_tasks_for_user( $user_id ) {
		global $wpdb;

		$user_roles = get_user_meta( $user_id, $wpdb->prefix . 'capabilities', true );
		$user_roles = $user_roles ? array_keys( $user_roles ) : array();

		$conditions = array();
		$params     = array();

		$conditions[] = 'assigned_user_id = %d';
		$params[]     = $user_id;

		// Use separate queries for each condition to avoid complex dynamic SQL.
		$results = array();

		$user_results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE assigned_user_id = %d AND status = %s",
				$this->table_name,
				$user_id,
				'pending'
			)
		);
		if ( $user_results ) {
			$results = array_merge( $results, $user_results );
		}

		if ( ! empty( $user_roles ) ) {
			foreach ( $user_roles as $role ) {
				$role_results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE assigned_role = %s AND status = %s",
						$this->table_name,
						$role,
						'pending'
					)
				);
				if ( $role_results ) {
					$results = array_merge( $results, $role_results );
				}
			}
		}

		$unique_results = array();
		$seen_ids = array();
		foreach ( $results as $result ) {
			if ( ! in_array( $result->id, $seen_ids ) ) {
				$unique_results[] = $result;
				$seen_ids[] = $result->id;
			}
		}
		$results = $unique_results;

		WP_AI_Workflows_Utilities::debug_log(
			'Fetched pending tasks for user',
			'debug',
			array(
				'user_id'    => $user_id,
				'user_roles' => $user_roles,
				'count'      => count( $results ),
			)
		);

		$processed_results = array_map(
			function ( $task ) {
				if ( ! empty( $task->content ) ) {
					$decoded_content = json_decode( $task->content, true );
					$task->content   = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_content : $task->content;
				}

				if ( ! empty( $task->instructions ) ) {
					$decoded_instructions = json_decode( $task->instructions, true );
					$task->instructions   = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_instructions : $task->instructions;
				}

				return $task;
			},
			$results
		);

		return $processed_results;
	}

	public function update_task_status( $id, $status, $user_id, $comments = '', $content = null ) {
		global $wpdb;

		$data = array(
			'status'          => $status,
			'comments'        => $comments,
			'action_taken_by' => $user_id,
			'action_taken_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( $content !== null ) {
			$data['content'] = is_array( $content ) ? wp_json_encode( $content ) : $content;
		}

		$result = $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $id )
		);

		return $result !== false;
	}


	public function create_task( $data ) {
		global $wpdb;

		WP_AI_Workflows_Utilities::debug_log( 'Creating new human task', 'debug', array( 'initial_data' => $data ) );

		$workflow_id = isset( $data['workflow_id'] ) ? $data['workflow_id'] : ( isset( $data['execution_id'] ) ? $data['execution_id'] : null );

		if ( ! $workflow_id ) {
			WP_AI_Workflows_Utilities::debug_log( 'Failed to create human task: No workflow_id or execution_id provided', 'error' );
			return false;
		}

		// A caller that already knows the real workflow identity (e.g. a Cloud
		// site-step dispatch, which has no local workflow row to look up) passes
		// workflow_name directly. Only fall back to the id lookups below when it
		// didn't, so a resolved id from one caller's id space is never matched
		// against a table that uses a different one.
		if ( ! isset( $data['workflow_name'] ) || '' === $data['workflow_name'] ) {
			$workflows_table = $wpdb->prefix . 'wp_ai_workflows';
			$workflow_name   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM %i WHERE id = %s",
					$workflows_table,
					$workflow_id
				)
			);

			if ( ! $workflow_name ) {
				$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
				$workflow_name    = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT workflow_name FROM %i WHERE id = %d",
						$executions_table,
						$workflow_id
					)
				);
			}

			$data['workflow_name'] = $workflow_name ? $workflow_name : 'Unknown Workflow';
		}

		if ( ! isset( $data['content'] ) || $data['content'] === '' ) {
			$data['content'] = 'No content provided';
		}

		if ( isset( $data['assigned_user'] ) ) {
			$data['assigned_user_id'] = $data['assigned_user'];
			unset( $data['assigned_user'] );
		}

		if ( isset( $data['assigned_role'] ) && ! empty( $data['assigned_role'] ) ) {
			$data['assigned_user_id'] = null;
		}

		$insert_data = array(
			'workflow_id'      => $data['workflow_id'],
			'workflow_name'    => $data['workflow_name'],
			'execution_id'     => $data['execution_id'],
			'node_id'          => $data['node_id'],
			'assigned_user_id' => $data['assigned_user_id'],
			'assigned_role'    => $data['assigned_role'],
			'input_type'       => $data['input_type'],
			'content'          => $data['content'],
			'instructions'     => isset( $data['instructions'] ) ? $data['instructions'] : '',
		);

		// Encode arrays as JSON if needed
		foreach ( array( 'instructions', 'content' ) as $field ) {
			if ( isset( $insert_data[ $field ] ) && is_array( $insert_data[ $field ] ) ) {
				$insert_data[ $field ] = wp_json_encode( $insert_data[ $field ] );
			}
		}

		$result = $wpdb->insert( $this->table_name, $insert_data );

		$new_task_id = $wpdb->insert_id;

		$created_task = $this->get_task( $new_task_id );

		if ( $new_task_id ) {
			$this->send_task_notification( $new_task_id );
		}

		return $new_task_id;
	}

	public function get_task( $id ) {
		global $wpdb;

		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d",
				$this->table_name,
				$id
			),
			ARRAY_A
		);

		if ( $task ) {
			if ( ! is_null( $task['instructions'] ) ) {
				$decoded_instructions = json_decode( $task['instructions'], true );
				$task['instructions'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_instructions : $task['instructions'];
			}

			if ( ! is_null( $task['content'] ) ) {
				$decoded_content = json_decode( $task['content'], true );
				$task['content'] = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_content : $task['content'];
			}
		}

		return $task;
	}

	public function send_task_notification( $task_id ) {
		WP_AI_Workflows_Utilities::debug_log( 'Sending task notification', 'debug', array( 'task_id' => $task_id ) );

		try {
			$task = $this->get_task( $task_id );

			if ( ! $task ) {
				WP_AI_Workflows_Utilities::debug_log( 'Task not found', 'error', array( 'task_id' => $task_id ) );
				return false;
			}

			$recipients = array();

			if ( ! empty( $task['assigned_user_id'] ) ) {
				$user = get_userdata( $task['assigned_user_id'] );
				if ( $user && ! empty( $user->user_email ) ) {
					$recipients[] = $user->user_email;
				}
			}

			if ( ! empty( $task['assigned_role'] ) ) {
				$role_users = get_users( array( 'role' => $task['assigned_role'] ) );
				foreach ( $role_users as $user ) {
					if ( ! empty( $user->user_email ) ) {
						$recipients[] = $user->user_email;
					}
				}
			}

			if ( empty( $recipients ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'No valid recipients found',
					'error',
					array(
						'task_id'          => $task_id,
						'assigned_user_id' => $task['assigned_user_id'],
						'assigned_role'    => $task['assigned_role'],
					)
				);
				return false;
			}

			$subject = 'New Task Awaiting Your Input';
			$message = $this->build_notification_email( $task );

			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . get_bloginfo( 'name' ) . ' <' . get_bloginfo( 'admin_email' ) . '>',
			);

			$success = true;
			foreach ( $recipients as $recipient ) {
				$sent = wp_mail( $recipient, $subject, $message, $headers );
				WP_AI_Workflows_Utilities::debug_log(
					'Task notification email ' . ( $sent ? 'sent' : 'failed' ),
					'info',
					array(
						'task_id'   => $task_id,
						'recipient' => $recipient,
						'success'   => $sent,
					)
				);
				if ( ! $sent ) {
					$success = false;
				}
			}

			return $success;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error sending task notification',
				'error',
				array(
					'task_id' => $task_id,
					'error'   => $e->getMessage(),
				)
			);
			return false;
		}
	}

	private function build_notification_email( $task ) {
		$whitelabel_settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );

		$use_custom_logo = ! empty( $whitelabel_settings['enable_whitelabel'] ) &&
							! empty( $whitelabel_settings['logo_url'] );

		if ( $use_custom_logo ) {
			$logo_url = $whitelabel_settings['logo_url'];
		} else {
			$logo_url = get_site_icon_url( 96 );
			if ( empty( $logo_url ) ) {
				$logo_url = admin_url( 'images/wordpress-logo.png' );
			}
		}

		$site_name = get_bloginfo( 'name' );
		$admin_url = admin_url( 'admin.php?page=wp-ai-workflows-tasks' );
		$footer_logo_url = plugins_url( 'images/AWAIcon.png', WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php' );

			$content_display = 'No content provided';
		if ( isset( $task['content'] ) && ! is_null( $task['content'] ) ) {
			if ( is_string( $task['content'] ) ) {
				$content_display = $task['content'];
			} elseif ( is_array( $task['content'] ) || is_object( $task['content'] ) ) {
				$content_display = print_r( $task['content'], true );
			}
		}

			$instructions = '';
		if ( ! empty( $task['instructions'] ) ) {
			$instructions = $task['instructions'];
			if ( is_string( $instructions ) ) {
				$decoded = json_decode( $instructions, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$instructions = $decoded;
				}
			}
			if ( is_array( $instructions ) || is_object( $instructions ) ) {
				$instructions = print_r( $instructions, true );
			}
		}

			$html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
            </head>
            <body style="margin: 0; padding: 0; background-color: #F7F7F7; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F7F7F7;">
                    <tr>
                        <td align="center" style="padding: 20px 0;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <!-- Header -->
                                <tr>
                                    <td align="center" style="padding: 30px 30px 20px 30px;">
                                        <img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site_name ) . '" width="auto" height="60" style="display: block; max-width: 240px; max-height: 60px; object-fit: contain; margin-bottom: 15px;">
                                        <h1 style="color: #1D1D1F; font-size: 24px; font-weight: 600; margin: 0 0 10px 0;">New Task Awaiting Your Input</h1>
                                        <p style="color: #86868B; font-size: 16px; margin: 0;">Action required in ' . esc_html( $site_name ) . '</p>
                                    </td>
                                </tr>
        
                                <!-- Content -->
                                <tr>
                                    <td style="padding: 0 30px;">
                                        <p style="color: #1D1D1F; font-size: 16px; line-height: 1.6;">You have a new task waiting for your input in the AI Workflow Automation plugin.</p>
                                        
                                        <!-- Task Details Box -->
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #F5F5F7; border-radius: 12px; margin: 20px 0;">
                                            <tr>
                                                <td style="padding: 20px;">
                                                    <!-- Workflow -->
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 15px;">
                                                        <tr>
                                                            <td>
                                                                <p style="color: #86868B; font-size: 14px; margin: 0 0 4px 0;">Workflow</p>
                                                                <p style="color: #1D1D1F; font-size: 16px; margin: 0;">' . esc_html( $task['workflow_name'] ) . '</p>
                                                            </td>
                                                        </tr>
                                                    </table>
        
                                                    <!-- Type -->
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 15px;">
                                                        <tr>
                                                            <td>
                                                                <p style="color: #86868B; font-size: 14px; margin: 0 0 4px 0;">Type</p>
                                                                <p style="color: #1D1D1F; font-size: 16px; margin: 0;">' . ucfirst( $task['input_type'] ) . '</p>
                                                            </td>
                                                        </tr>
                                                    </table>
        
                                                    <!-- Content -->
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 15px;">
                                                        <tr>
                                                            <td>
                                                                <p style="color: #86868B; font-size: 14px; margin: 0 0 4px 0;">Content</p>
                                                                <p style="color: #1D1D1F; font-size: 16px; margin: 0; white-space: pre-wrap;">' . esc_html( $content_display ) . '</p>
                                                            </td>
                                                        </tr>
                                                    </table>';

		if ( ! empty( $task['instructions'] ) ) {
			$instructions = is_string( $task['instructions'] ) ? $task['instructions'] : print_r( $task['instructions'], true );
			$html        .= '
                                                    <!-- Instructions -->
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <tr>
                                                            <td>
                                                                <p style="color: #86868B; font-size: 14px; margin: 0 0 4px 0;">Instructions</p>
                                                                <p style="color: #1D1D1F; font-size: 16px; margin: 0; white-space: pre-wrap;">' . esc_html( $instructions ) . '</p>
                                                            </td>
                                                        </tr>
                                                    </table>';
		}

			$html .= '
                                                </td>
                                            </tr>
                                        </table>
        
                                        <!-- Action Button -->
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 20px 0;">
                                            <tr>
                                                <td align="center">
                                                    <a href="' . esc_url( $admin_url ) . '" style="background-color: #0071E3; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: 500; display: inline-block;">View and Action Task</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
        
                                <!-- Footer -->
                                <tr>
                                    <td align="center" style="padding: 30px;">
                                        <img src="' . esc_url( $footer_logo_url ) . '" alt="' . esc_attr( $site_name ) . '" width="24" height="24" style="display: block; margin-bottom: 10px;">
                                        <p style="color: #86868B; font-size: 13px; margin: 0;">Powered by AI Workflow Automation WordPress Plugin</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

			return $html;
	}

	/**
	 * Check whether the human tasks table exists.
	 *
	 * SHOW TABLES LIKE returns an empty result (no DB error) for a missing table,
	 * so this is a safe pre-flight guard before running SHOW COLUMNS queries.
	 *
	 * @return bool True if the table exists.
	 */
	private function table_exists() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) ) === $this->table_name;
	}

	public function check_and_add_missing_columns() {
		global $wpdb;

		// Bail out gracefully if the table does not exist yet. Running SHOW COLUMNS
		// against a missing table triggers a WordPress DB error on every request and
		// floods debug.log. The table is created on activation/upgrade via
		// WP_AI_Workflows_Database::create_tables().
		if ( ! $this->table_exists() ) {
			return false;
		}

		$expected_columns = array(
			'id'               => 'bigint(20) NOT NULL AUTO_INCREMENT',
			'workflow_id'      => 'varchar(255) NOT NULL',
			'workflow_name'    => 'varchar(255) NOT NULL',
			'execution_id'     => 'bigint(20) NOT NULL',
			'node_id'          => 'varchar(255) NOT NULL',
			'assigned_user_id' => 'bigint(20)',
			'assigned_role'    => 'varchar(255)',
			'input_type'       => "enum('approval','modification') NOT NULL",
			'instructions'     => 'longtext',
			'content'          => 'longtext NOT NULL',
			'status'           => "enum('pending','approved','rejected','reverted','modified') NOT NULL DEFAULT 'pending'",
			'comments'         => 'text',
			'created_at'       => 'datetime DEFAULT CURRENT_TIMESTAMP',
			'updated_at'       => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			'action_taken_by'  => 'bigint(20)',
			'action_taken_at'  => 'datetime',
		);

		$existing_columns      = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $this->table_name ), ARRAY_A );
		$existing_column_names = array_column( $existing_columns, 'Field' );

		$columns_updated = false;

		foreach ( $expected_columns as $column_name => $column_definition ) {
			if ( ! in_array( $column_name, $existing_column_names ) ) {
				// Skip dynamic column addition for security; use dbDelta instead.
				WP_AI_Workflows_Utilities::debug_log(
					'Skipping dynamic column addition for security',
					'warning',
					array(
						'table'      => $this->table_name,
						'column'     => $column_name,
						'definition' => $column_definition,
					)
				);

				$result = true; // Skip for security

				if ( $result === false ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Failed to add column',
						'error',
						array(
							'column' => $column_name,
							'error'  => $wpdb->last_error,
						)
					);
					return false;
				}

				$columns_updated = true;
			}
		}

		if ( $columns_updated ) {
			WP_AI_Workflows_Utilities::debug_log( 'Table structure updated', 'info' );
		}

		return $this->verify_table_structure( false );
	}

	public function verify_table_structure( $log = true ) {
		global $wpdb;

		// Avoid SHOW COLUMNS on a missing table (prevents per-request debug.log spam).
		if ( ! $this->table_exists() ) {
			return false;
		}

		$columns          = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $this->table_name ), ARRAY_A );
		$column_names     = array_column( $columns, 'Field' );
		$expected_columns = array(
			'id',
			'workflow_id',
			'workflow_name',
			'execution_id',
			'node_id',
			'assigned_user_id',
			'assigned_role',
			'input_type',
			'instructions',
			'content',
			'status',
			'comments',
			'created_at',
			'updated_at',
			'action_taken_by',
			'action_taken_at',
		);
		$missing_columns  = array_diff( $expected_columns, $column_names );

		if ( ! empty( $missing_columns ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Missing columns in human tasks table',
				'error',
				array( 'missing_columns' => $missing_columns )
			);
			return false;
		}

		if ( $log ) {
			WP_AI_Workflows_Utilities::debug_log( 'Human tasks table structure verified', 'info' );
		}
		return true;
	}

	private function column_exists( $column_name ) {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW COLUMNS FROM %i LIKE %s",
				$wpdb->prefix . 'wp_ai_workflows_human_tasks',
				$column_name
			)
		);
		return ! empty( $result );
	}

	private function get_execution( $execution_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d",
				$table_name,
				$execution_id
			)
		);
	}

	public function get_task_by_execution_and_node( $execution_id, $node_id ) {
		global $wpdb;

		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE execution_id = %d AND node_id = %s ORDER BY id DESC LIMIT 1",
				$wpdb->prefix . 'wp_ai_workflows_human_tasks',
				$execution_id,
				$node_id
			),
			ARRAY_A
		);

		if ( $task ) {
			$task['instructions'] = json_decode( $task['instructions'], true );
			$task['content']      = json_decode( $task['content'], true );
		}

		return $task;
	}
}
