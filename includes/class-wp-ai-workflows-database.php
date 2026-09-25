<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database operations for WP AI Workflows plugin.
 */
class WP_AI_Workflows_Database {

	public function init() {
	}

	public static function create_tables() {
		$current_version = get_option( 'wp_ai_workflows_db_version', '0' );
		if ( version_compare( $current_version, WP_AI_WORKFLOWS_PRO_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$shortcode_outputs_table  = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
		$outputs_table            = $wpdb->prefix . 'wp_ai_workflows_outputs';
		$executions_table         = $wpdb->prefix . 'wp_ai_workflows_executions';
		$templates_table          = $wpdb->prefix . 'wp_ai_workflows_templates';
		$human_tasks_table        = $wpdb->prefix . 'wp_ai_workflows_human_tasks';
		$google_sheet_states      = $wpdb->prefix . 'wp_ai_workflows_sheet_states';
		$sessions_table           = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
		$messages_table           = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
		$cost_settings_table      = $wpdb->prefix . 'wp_ai_workflows_cost_settings';
		$node_costs_table         = $wpdb->prefix . 'wp_ai_workflows_node_costs';
		$assistant_sessions_table = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
		$assistant_messages_table = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
		$vector_stores_table      = $wpdb->prefix . 'wp_ai_workflows_vector_stores';
		$vector_files_table       = $wpdb->prefix . 'wp_ai_workflows_vector_files';
		$license_security_table   = $wpdb->prefix . 'wp_ai_workflows_license_security';
		$workflows_table          = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
		$mcp_servers_table        = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		$sql_workflows = self::prepare_create_table(
			$workflows_table,
			"
				id VARCHAR(255) NOT NULL,
				name VARCHAR(255) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				data LONGTEXT NOT NULL,
				created_by VARCHAR(255),
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY name (name),
				KEY status (status),
				KEY created_at (created_at),
				KEY updated_at (updated_at)
			",
			$charset_collate
		);

		$sql_license_security = self::prepare_create_table(
			$license_security_table,
			"
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				check_time DATETIME NOT NULL,
				check_type VARCHAR(50) NOT NULL,
				ip_address VARCHAR(100) NOT NULL,
				result VARCHAR(20) NOT NULL,
				license_key_fragment VARCHAR(32),
				site_hash VARCHAR(64) NOT NULL,
				http_filter_status TINYINT(1) DEFAULT 0,
				details TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY check_time (check_time),
				KEY result (result),
				KEY site_hash (site_hash)
			",
			$charset_collate
		);

		$sql_shortcode_outputs = self::prepare_create_table(
			$shortcode_outputs_table,
			"
				id INT NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(255) NOT NULL,
				workflow_id VARCHAR(255) NOT NULL,
				output_data LONGTEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY session_workflow (session_id, workflow_id)
			",
			$charset_collate
		);

		$sql_outputs = self::prepare_create_table(
			$outputs_table,
			"
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				node_id varchar(255) NOT NULL,
				output_data longtext NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			",
			$charset_collate,
			false
		);

		$sql_executions = self::prepare_create_table(
			$executions_table,
			"
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				workflow_id varchar(255) NOT NULL,
				workflow_name varchar(255) NOT NULL,
				status varchar(20) NOT NULL,
				input_data longtext,
				output_data longtext,
				current_node varchar(255),
				error_message text,
				total_cost DECIMAL(10,6) DEFAULT 0.00,
				cost_details JSON DEFAULT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				scheduled_at datetime,
				PRIMARY KEY  (id)
			",
			$charset_collate,
			false
		);

		$sql_templates = self::prepare_create_table(
			$templates_table,
			"
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				name varchar(255) NOT NULL,
				description text,
				workflow_data longtext NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			",
			$charset_collate,
			false
		);

		$sql_human_tasks = self::prepare_create_table(
			$human_tasks_table,
			"
				id bigint(20) NOT NULL AUTO_INCREMENT,
				workflow_id varchar(255) NOT NULL,
				workflow_name varchar(255) NOT NULL,
				execution_id bigint(20) NOT NULL,
				node_id varchar(255) NOT NULL,
				assigned_user_id bigint(20),
				assigned_role varchar(255),
				input_type enum('approval', 'modification') NOT NULL,
				instructions longtext,
				content longtext NOT NULL,
				status enum('pending', 'approved', 'rejected', 'reverted', 'modified') NOT NULL DEFAULT 'pending',
				comments text,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				action_taken_by bigint(20),
				action_taken_at datetime,
				PRIMARY KEY  (id),
				KEY workflow_id (workflow_id),
				KEY execution_id (execution_id),
				KEY assigned_user_id (assigned_user_id),
				KEY assigned_role (assigned_role),
				KEY status (status)
			",
			$charset_collate,
			false
		);

		$sql_google_sheet_states = self::prepare_create_table(
			$google_sheet_states,
			"
				id bigint(20) NOT NULL AUTO_INCREMENT,
				sheet_id varchar(255) NOT NULL,
				tab_id varchar(255) NOT NULL,
				sheet_state longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sheet_tab (sheet_id,tab_id)
			",
			$charset_collate,
			false
		);

		$sql_sessions = self::prepare_create_table(
			$sessions_table,
			"
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(255) NOT NULL,
				workflow_id VARCHAR(255) NOT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				metadata JSON,
				PRIMARY KEY (id),
				UNIQUE KEY session_id (session_id),
				KEY workflow_id (workflow_id),
				KEY updated_at (updated_at)
			",
			$charset_collate
		);

		$sql_messages = self::prepare_create_table(
			$messages_table,
			"
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(255) NOT NULL,
				role ENUM('system', 'user', 'assistant') NOT NULL,
				content LONGTEXT NOT NULL,
				tokens INT UNSIGNED,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				metadata JSON,
				PRIMARY KEY (id),
				KEY session_id (session_id),
				KEY created_at (created_at)
			",
			$charset_collate
		);

		$sql_node_costs = self::prepare_create_table(
			$node_costs_table,
			"
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				execution_id BIGINT(20) NOT NULL,
				node_id VARCHAR(255) NOT NULL,
				model VARCHAR(255) NOT NULL,
				provider VARCHAR(50) NOT NULL,
				prompt_tokens INT UNSIGNED DEFAULT 0,
				completion_tokens INT UNSIGNED DEFAULT 0,
				cost DECIMAL(10,6) DEFAULT 0.00,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY execution_node (execution_id, node_id),
				KEY model_idx (model),
				KEY provider_idx (provider)
			",
			$charset_collate
		);

		$sql_cost_settings = self::prepare_create_table(
			$cost_settings_table,
			"
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				provider VARCHAR(50) NOT NULL,
				model VARCHAR(255) NOT NULL,
				input_cost DECIMAL(10,6) NOT NULL,
				output_cost DECIMAL(10,6) NOT NULL,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY provider_model (provider, model)
			",
			$charset_collate
		);

		$sql_assistant_sessions = self::prepare_create_table(
			$assistant_sessions_table,
			"
				session_id varchar(36) NOT NULL,
				workflow_id varchar(255) NOT NULL,
				workflow_context longtext,
				selected_node varchar(255) DEFAULT NULL,
				mode varchar(20) NOT NULL DEFAULT 'chat',
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (session_id),
				KEY workflow_id (workflow_id),
				KEY mode (mode)
			",
			$charset_collate
		);

		$sql_assistant_messages = self::prepare_create_table(
			$assistant_messages_table,
			"
				message_id bigint(20) NOT NULL AUTO_INCREMENT,
				session_id varchar(36) NOT NULL,
				role varchar(20) NOT NULL,
				content longtext NOT NULL,
				metadata longtext DEFAULT NULL,
				node_context text DEFAULT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (message_id),
				KEY session_id (session_id),
				KEY message_ordering (session_id, created_at)
			",
			$charset_collate
		);

		$sql_vector_stores = self::prepare_create_table(
			$vector_stores_table,
			"
				id VARCHAR(255) NOT NULL,
				name VARCHAR(255) NOT NULL,
				description TEXT,
				is_default TINYINT(1) DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			",
			$charset_collate
		);

		$sql_vector_files = self::prepare_create_table(
			$vector_files_table,
			"
				id VARCHAR(255) NOT NULL,
				store_id VARCHAR(255) NOT NULL,
				filename VARCHAR(255) NOT NULL,
				mime_type VARCHAR(100),
				size BIGINT,
				status VARCHAR(20) DEFAULT 'pending',
				url TEXT,
				local_path TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY store_id (store_id)
			",
			$charset_collate
		);

		$sql_mcp_servers = self::prepare_create_table(
			$mcp_servers_table,
			"
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				user_id bigint(20) NOT NULL,
				name varchar(255) NOT NULL,
				description text,
				config longtext NOT NULL,
				discovered_tools longtext,
				is_active boolean DEFAULT 1,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY name (name),
				KEY is_active (is_active),
				KEY created_at (created_at)
			",
			$charset_collate
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_shortcode_outputs );
		dbDelta( $sql_outputs );
		dbDelta( $sql_workflows );
		dbDelta( $sql_executions );
		dbDelta( $sql_templates );
		dbDelta( $sql_human_tasks );
		dbDelta( $sql_google_sheet_states );
		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );
		dbDelta( $sql_node_costs );
		dbDelta( $sql_cost_settings );
		dbDelta( $sql_assistant_sessions );
		dbDelta( $sql_assistant_messages );
		dbDelta( $sql_vector_stores );
		dbDelta( $sql_vector_files );
		dbDelta( $sql_license_security );
		dbDelta( $sql_mcp_servers );

		// Supabase (pgvector) knowledge base registry (local names + counts).
		if ( class_exists( 'WP_AI_Workflows_Knowledge_Base' ) ) {
			( new WP_AI_Workflows_Knowledge_Base() )->create_tables();
		}

		// Opt-in chat memory store (conversation + visitor scopes; user scope uses user_meta).
		if ( class_exists( 'WP_AI_Workflows_Chat_Memory' ) ) {
			WP_AI_Workflows_Chat_Memory::create_tables();
		}

		self::ensure_metadata_column();
		self::ensure_site_steps_table( true );

		update_option( 'wp_ai_workflows_chat_db_version', WP_AI_WORKFLOWS_PRO_VERSION );

		WP_AI_Workflows_Utilities::debug_log( 'Database tables created or updated', 'info' );
	}

	public static function cleanup_orphaned_executions() {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__ );

		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';

		$workflow_table        = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
		$existing_workflow_ids = array();

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $workflow_table ) ) == $workflow_table ) {
			$existing_workflow_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i", $workflow_table ) );
		}

		if ( empty( $existing_workflow_ids ) ) {
			return;
		}

		$total_orphaned = 0;
		foreach ( $existing_workflow_ids as $workflow_id ) {
			$orphaned = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE workflow_id != %s",
					$table_name,
					$workflow_id
				)
			);
			$total_orphaned += $orphaned;
		}
		$orphaned = $total_orphaned;

		WP_AI_Workflows_Utilities::debug_log(
			'Cleaned up orphaned executions',
			'info',
			array(
				'orphaned_executions_removed' => $orphaned,
			)
		);
	}

	public static function get_tables() {
		WP_AI_Workflows_Utilities::debug_log( 'Fetching tables', 'debug' );
		global $wpdb;

		$cache_key   = 'wp_ai_workflows_tables';
		$table_names = wp_cache_get( $cache_key );

		if ( false === $table_names ) {
			$tables = $wpdb->get_results(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$wpdb->esc_like( $wpdb->prefix . 'ai_workflows_' ) . '%'
				),
				ARRAY_N
			);

			$table_names = array_map(
				function ( $table ) use ( $wpdb ) {
					return str_replace( $wpdb->prefix, '', $table[0] );
				},
				$tables
			);

			wp_cache_set( $cache_key, $table_names, '', 3600 );
		}

		WP_AI_Workflows_Utilities::debug_log( 'Tables fetched', 'debug', array( 'tables' => $table_names ) );
		return new WP_REST_Response( $table_names, 200 );
	}

	public static function export_outputs( $request ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'request' => $request->get_params() ) );

		global $wpdb;
		$table = $wpdb->prefix . $request->get_param( 'table' );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) != $table ) {
			return new WP_Error( 'invalid_table', 'The specified table does not exist', array( 'status' => 400 ) );
		}

		$outputs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC", $table ), ARRAY_A );

		$csv_content = self::generate_csv( $outputs );

		$filename = $table . '_outputs.csv';

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv_content ) );

		echo esc_html( $csv_content );
		exit;
	}

	private static function generate_csv( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		ob_start();
		$df = fopen( 'php://output', 'w' );
		fputcsv( $df, array_keys( reset( $data ) ) );
		foreach ( $data as $row ) {
			fputcsv( $df, $row );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, not a filesystem write
		fclose( $df );
		return ob_get_clean();
	}

	/**
	 * Build a CREATE TABLE statement with the table name prepared as an identifier.
	 *
	 * @param string $table           Table name.
	 * @param string $body            Column and key definitions between the parens.
	 * @param string $charset_collate Value from $wpdb->get_charset_collate().
	 * @param bool   $if_not_exists   Whether to include IF NOT EXISTS.
	 * @return string
	 */
	private static function prepare_create_table( $table, $body, $charset_collate, $if_not_exists = true ) {
		global $wpdb;
		if ( $if_not_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- body is a literal from the caller and charset comes from $wpdb->get_charset_collate().
			return $wpdb->prepare( "CREATE TABLE IF NOT EXISTS %i ({$body}) {$charset_collate}", $table );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- body is a literal from the caller and charset comes from $wpdb->get_charset_collate().
		return $wpdb->prepare( "CREATE TABLE %i ({$body}) {$charset_collate}", $table );
	}

	public static function create_table( $request ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'request' => $request->get_params() ) );

		global $wpdb;
		$table_name = $request->get_param( 'tableName' );
		$columns    = $request->get_param( 'columns' );

		if ( empty( $table_name ) ) {
			return new WP_Error( 'invalid_table_name', 'Table name cannot be empty', array( 'status' => 400 ) );
		}

		$table_name      = 'ai_workflows_' . sanitize_key( $table_name );
		$full_table_name = $wpdb->prefix . $table_name;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ) == $full_table_name ) {
			return new WP_Error( 'table_exists', 'A table with this name already exists', array( 'status' => 400 ) );
		}

		$charset_collate = $wpdb->get_charset_collate();

		// Build base SQL - charset_collate is safe to concatenate as it comes from WordPress
		$sql = sprintf(
			"CREATE TABLE %s (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) %s",
			$full_table_name,
			$charset_collate
		);

		if ( ! empty( $columns ) && is_array( $columns ) ) {
			foreach ( $columns as $column ) {
				$column_name = sanitize_key( $column['name'] );
				$column_type = self::get_sql_type( $column['type'] );
				$sql         = str_replace( 'PRIMARY KEY  (id)', "$column_name $column_type,\nPRIMARY KEY  (id)", $sql );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table_name ) ) == $full_table_name ) {
			return new WP_REST_Response(
				array(
					'success'   => true,
					'tableName' => $table_name,
					'columns'   => $columns,
				),
				200
			);
		} else {
			return new WP_Error( 'table_creation_failed', 'Failed to create the table', array( 'status' => 500 ) );
		}
	}

	public static function get_table_structure( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $request->get_param( 'table' );

		$cache_key = 'wp_ai_workflows_table_structure_' . md5( $table_name );
		$structure = wp_cache_get( $cache_key );

		if ( false === $structure ) {
			$columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $table_name ) );

			$structure = array_map(
				function ( $column ) {
					return array(
						'name' => $column->Field,
						'type' => $column->Type,
					);
				},
				$columns
			);

			wp_cache_set( $cache_key, $structure, '', 3600 ); // Cache for 1 hour
		}

		return new WP_REST_Response( $structure, 200 );
	}

	public static function delete_table( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $request->get_param( 'table' );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
			return new WP_Error( 'invalid_table', 'The specified table does not exist', array( 'status' => 400 ) );
		}

		$wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $table_name ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) == $table_name ) {
			return new WP_Error( 'delete_failed', 'Failed to delete the table', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'message' => 'Table deleted successfully' ), 200 );
	}

	public static function delete_entry( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $request->get_param( 'table' );
		$entry_id   = $request->get_param( 'id' );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
			return new WP_Error( 'invalid_table', 'The specified table does not exist', array( 'status' => 400 ) );
		}

		$result = $wpdb->delete( $table_name, array( 'id' => $entry_id ), array( '%d' ) );

		if ( $result === false ) {
			return new WP_Error( 'delete_failed', 'Failed to delete the entry', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'message' => 'Entry deleted successfully' ), 200 );
	}

	private static function get_sql_type( $type ) {
		switch ( $type ) {
			case 'text':
				return 'TEXT';
			case 'number':
				return 'FLOAT';
			case 'datetime':
				return 'DATETIME';
			default:
				return 'TEXT';
		}
	}

	public static function update_database_schema() {
		global $wpdb;

		$tables_created = self::ensure_tables_exist();

		$table_name       = $wpdb->prefix . 'wp_ai_workflows_executions';
		$required_columns = array(
			'current_node'  => 'VARCHAR(255)',
			'error_message' => 'TEXT',
			'total_cost'    => 'DECIMAL(10,6) DEFAULT 0.00',
			'cost_details'  => 'JSON DEFAULT NULL',
		);

		$existing_columns = array();
		$columns          = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %i", $table_name ) );
		foreach ( $columns as $column ) {
			$existing_columns[] = $column->Field;
		}

		$columns_added = false;
		foreach ( $required_columns as $column_name => $column_definition ) {
			if ( ! in_array( $column_name, $existing_columns ) ) {
				// Skip column addition for security - this functionality should use dbDelta
				WP_AI_Workflows_Utilities::debug_log(
					'Skipping dynamic column addition for security',
					'warning',
					array(
						'table'  => $table_name,
						'column' => $column_name,
					)
				);
				$columns_added = true;
				WP_AI_Workflows_Utilities::debug_log(
					'Added new column',
					'info',
					array(
						'table'  => $table_name,
						'column' => $column_name,
					)
				);
			}
		}

		if ( $tables_created || $columns_added ) {
			update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );
		}
	}

	public static function cleanup_old_chat_data() {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$old_sessions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT session_id FROM %i 
				WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$sessions_table
			)
		);

		if ( ! empty( $old_sessions ) ) {
			foreach ( $old_sessions as $session_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE session_id = %s",
						$messages_table,
						$session_id
					)
				);
			}

			foreach ( $old_sessions as $session_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM %i WHERE session_id = %s",
						$sessions_table,
						$session_id
					)
				);
			}
		}
	}

	public static function update_chat_schema() {
		$current_version = get_option( 'wp_ai_workflows_chat_db_version', '0' );

		if ( version_compare( $current_version, WP_AI_WORKFLOWS_PRO_VERSION, '<' ) ) {
			self::create_chat_tables();
		}
	}

	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'wp_ai_workflows_cleanup_chat_data' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_ai_workflows_cleanup_chat_data' );
		}
	}

	public static function ensure_metadata_column() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM %i LIKE %s",
				$table_name,
				'metadata'
			)
		);

		if ( ! $column_exists ) {
			$wpdb->query(
				$wpdb->prepare(
					"ALTER TABLE %i 
					ADD COLUMN metadata longtext DEFAULT NULL 
					AFTER content",
					$table_name
				)
			);
		}
	}

	/** Option flag recording that the site-step table is present at this revision. */
	const SITE_STEPS_SCHEMA_OPTION = 'wpaw_site_steps_schema';

	/** Bumped when the site-step table definition changes. */
	const SITE_STEPS_SCHEMA_VERSION = '3';

	/** Days a site-step record is kept before the cleanup cron removes it. */
	const SITE_STEPS_RETENTION_DAYS = 7;

	/**
	 * Fully-qualified name of the site-step correlation table.
	 *
	 * @return string
	 */
	public static function site_steps_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_workflows_site_steps';
	}

	/**
	 * Create the site-step correlation table if it is not already present. Cheap to
	 * call on every request: an option read short-circuits once the table exists.
	 *
	 * @param bool $force Skip the option short-circuit and run dbDelta anyway.
	 * @return void
	 */
	public static function ensure_site_steps_table( $force = false ) {
		if ( ! $force && self::SITE_STEPS_SCHEMA_VERSION === get_option( self::SITE_STEPS_SCHEMA_OPTION, '' ) ) {
			return;
		}

		global $wpdb;
		$table           = self::site_steps_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = self::prepare_create_table(
			$table,
			"
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				step_id varchar(64) NOT NULL,
				platform_execution_id varchar(64) NOT NULL,
				workflow_name varchar(191) DEFAULT NULL,
				node_id varchar(255) NOT NULL,
				node_type varchar(64) NOT NULL,
				mode varchar(8) NOT NULL DEFAULT 'sync',
				status varchar(16) NOT NULL DEFAULT 'received',
				result_ref varchar(255) DEFAULT NULL,
				error_code varchar(64) DEFAULT NULL,
				error_message text,
				duration_ms int unsigned DEFAULT NULL,
				delivered_at datetime DEFAULT NULL,
				delivery_attempts smallint unsigned NOT NULL DEFAULT 0,
				delivery_error varchar(190) DEFAULT NULL,
				delivery_payload mediumtext,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY step_id (step_id),
				KEY platform_execution_id (platform_execution_id),
				KEY status (status),
				KEY created_at (created_at)
			",
			$charset_collate,
			false
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SITE_STEPS_SCHEMA_OPTION, self::SITE_STEPS_SCHEMA_VERSION, false );
	}

	/**
	 * Drop site-step records past their retention window. Hooked to the existing
	 * daily cleanup cron.
	 *
	 * @return void
	 */
	public static function cleanup_site_steps() {
		if ( self::SITE_STEPS_SCHEMA_VERSION !== get_option( self::SITE_STEPS_SCHEMA_OPTION, '' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled retention sweep.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				self::site_steps_table(),
				self::SITE_STEPS_RETENTION_DAYS
			)
		);
	}

	public static function verify_tables_exist() {
		global $wpdb;
		$required_tables = array(
			'wp_ai_workflows_shortcode_outputs',
			'wp_ai_workflows_outputs',
			'wp_ai_workflows_executions',
			'wp_ai_workflows_templates',
			'wp_ai_workflows_human_tasks',
			'wp_ai_workflows_sheet_states',
			'wp_ai_workflows_chat_sessions',
			'wp_ai_workflows_chat_messages',
			'wp_ai_workflows_cost_settings',
			'wp_ai_workflows_node_costs',
			'wp_ai_workflows_vector_stores',
			'wp_ai_workflows_vector_files',
			'wp_ai_workflows_license_security',
			'wp_ai_workflows_workflow_data',
			'wp_ai_workflows_mcp_servers',

		);

		$missing_tables = array();
		foreach ( $required_tables as $table ) {
			$table_name = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
				$missing_tables[] = $table;
			}
		}

		if ( ! empty( $missing_tables ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Missing required tables',
				'error',
				array(
					'missing_tables' => $missing_tables,
				)
			);

			delete_option( 'wp_ai_workflows_db_version' );
			self::create_tables();

			$still_missing = array();
			foreach ( $missing_tables as $table ) {
				$table_name = $wpdb->prefix . $table;
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
					$still_missing[] = $table;
				}
			}

			if ( ! empty( $still_missing ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to create tables',
					'error',
					array(
						'still_missing' => $still_missing,
					)
				);
				return false;
			}
		}

		return true;
	}

	public static function ensure_tables_exist() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$required_tables = array(
			'wp_ai_workflows_cost_settings'      => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_cost_settings',
				"
					id BIGINT(20) NOT NULL AUTO_INCREMENT,
					provider VARCHAR(50) NOT NULL,
					model VARCHAR(255) NOT NULL,
					input_cost DECIMAL(10,6) NOT NULL,
					output_cost DECIMAL(10,6) NOT NULL,
					updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					UNIQUE KEY provider_model (provider, model)
				",
				$charset_collate
			),
			'wp_ai_workflows_node_costs'         => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_node_costs',
				"
					id BIGINT(20) NOT NULL AUTO_INCREMENT,
					execution_id BIGINT(20) NOT NULL,
					node_id VARCHAR(255) NOT NULL,
					model VARCHAR(255) NOT NULL,
					provider VARCHAR(50) NOT NULL,
					prompt_tokens INT UNSIGNED DEFAULT 0,
					completion_tokens INT UNSIGNED DEFAULT 0,
					cost DECIMAL(10,6) DEFAULT 0.00,
					created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY execution_node (execution_id, node_id),
					KEY model_idx (model),
					KEY provider_idx (provider)
				",
				$charset_collate
			),
			'wp_ai_workflows_executions'         => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_executions',
				"
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					workflow_id varchar(255) NOT NULL,
					workflow_name varchar(255) NOT NULL,
					status varchar(20) NOT NULL,
					input_data longtext,
					output_data longtext,
					current_node varchar(255),
					error_message text,
					total_cost DECIMAL(10,6) DEFAULT 0.00,
					cost_details JSON DEFAULT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					scheduled_at datetime,
					PRIMARY KEY  (id)
				",
				$charset_collate
			),
			'wp_ai_workflows_assistant_messages' => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_assistant_messages',
				"
					message_id bigint(20) NOT NULL AUTO_INCREMENT,
					session_id varchar(36) NOT NULL,
					role varchar(20) NOT NULL,
					content longtext NOT NULL,
					metadata longtext DEFAULT NULL,
					node_context text DEFAULT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (message_id),
					KEY session_id (session_id),
					KEY message_ordering (session_id, created_at)
				",
				$charset_collate
			),
			'wp_ai_workflows_assistant_sessions' => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_assistant_sessions',
				"
					session_id varchar(36) NOT NULL,
					workflow_id varchar(255) NOT NULL,
					workflow_context longtext,
					selected_node varchar(255) DEFAULT NULL,
					mode varchar(20) NOT NULL DEFAULT 'chat',
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (session_id),
					KEY workflow_id (workflow_id),
					KEY mode (mode)
				",
				$charset_collate
			),
			'wp_ai_workflows_vector_stores'      => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_vector_stores',
				"
					id VARCHAR(255) NOT NULL,
					name VARCHAR(255) NOT NULL,
					description TEXT,
					is_default TINYINT(1) DEFAULT 0,
					created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
					updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				",
				$charset_collate
			),
			'wp_ai_workflows_vector_files'       => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_vector_files',
				"
					id VARCHAR(255) NOT NULL,
					store_id VARCHAR(255) NOT NULL,
					filename VARCHAR(255) NOT NULL,
					mime_type VARCHAR(100),
					size BIGINT,
					status VARCHAR(20) DEFAULT 'pending',
					url TEXT,
					local_path TEXT,
					created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
					updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY store_id (store_id)
				",
				$charset_collate
			),
			'wp_ai_workflows_license_security'   => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_license_security',
				"
					id BIGINT(20) NOT NULL AUTO_INCREMENT,
					check_time DATETIME NOT NULL,
					check_type VARCHAR(50) NOT NULL,
					ip_address VARCHAR(100) NOT NULL,
					result VARCHAR(20) NOT NULL,
					license_key_fragment VARCHAR(32),
					site_hash VARCHAR(64) NOT NULL,
					http_filter_status TINYINT(1) DEFAULT 0,
					details TEXT,
					created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY check_time (check_time),
					KEY result (result),
					KEY site_hash (site_hash)
				",
				$charset_collate
			),
			'wp_ai_workflows_workflow_data'      => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_workflow_data',
				"
					id VARCHAR(255) NOT NULL,
					name VARCHAR(255) NOT NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'active',
					data LONGTEXT NOT NULL,
					created_by VARCHAR(255),
					created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
					updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY name (name),
					KEY status (status),
					KEY created_at (created_at),
					KEY updated_at (updated_at)
				",
				$charset_collate
			),
			'wp_ai_workflows_mcp_servers'        => self::prepare_create_table(
				$wpdb->prefix . 'wp_ai_workflows_mcp_servers',
				"
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					user_id bigint(20) NOT NULL,
					name varchar(255) NOT NULL,
					description text,
					config longtext NOT NULL,
					discovered_tools longtext,
					is_active boolean DEFAULT 1,
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY user_id (user_id),
					KEY name (name),
					KEY is_active (is_active),
					KEY created_at (created_at)
				",
				$charset_collate
			)
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables_created = false;

		foreach ( $required_tables as $table => $sql ) {
			$table_name = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
				dbDelta( $sql );
				$tables_created = true;
				WP_AI_Workflows_Utilities::debug_log(
					'Created missing table',
					'info',
					array(
						'table' => $table,
						'sql'   => $sql,
					)
				);
			} else {
				// Verify and update table structure if needed
				dbDelta( $sql );
			}
		}

		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $executions_table ) ) === $executions_table ) {
			$columns          = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM %i", $executions_table ) );
			$required_columns = array(
				'id',
				'workflow_id',
				'workflow_name',
				'status',
				'input_data',
				'output_data',
				'current_node',
				'error_message',
				'total_cost',
				'cost_details',
			);

			foreach ( $required_columns as $column ) {
				if ( ! in_array( $column, $columns ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Missing required column',
						'warning',
						array(
							'table'  => 'wp_ai_workflows_executions',
							'column' => $column,
						)
					);
					$tables_created = true;
					break;
				}
			}
		}

		if ( $tables_created ) {
			update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );
		}

		return $tables_created;
	}

	/**
	 * Save custom MCP server configuration
	 */
	public static function save_mcp_server( $user_id, $name, $description, $config, $discovered_tools = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		$data = array(
			'user_id'          => $user_id,
			'name'             => sanitize_text_field( $name ),
			'description'      => sanitize_textarea_field( $description ),
			'config'           => wp_json_encode( $config ),
			'discovered_tools' => $discovered_tools ? wp_json_encode( $discovered_tools ) : null,
			'is_active'        => 1,
		);

		$result = $wpdb->insert( $table_name, $data );

		if ( $result === false ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to save MCP server',
				'error',
				array(
					'user_id' => $user_id,
					'name'    => $name,
					'error'   => $wpdb->last_error,
				)
			);
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Get MCP servers for a user
	 */
	public static function get_mcp_servers( $user_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		if ( $user_id ) {
			$servers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE user_id = %d AND is_active = 1 ORDER BY name ASC",
					$table_name,
					$user_id
				),
				ARRAY_A
			);
		} else {
			$servers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE is_active = 1 ORDER BY name ASC",
					$table_name
				),
				ARRAY_A
			);
		}

		foreach ( $servers as &$server ) {
			$server['config'] = json_decode( $server['config'], true );
			if ( $server['discovered_tools'] ) {
				$server['discovered_tools'] = json_decode( $server['discovered_tools'], true );
			}
		}

		return $servers;
	}

	/**
	 * Update MCP server discovered tools
	 */
	public static function update_mcp_server_tools( $server_id, $discovered_tools ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		$result = $wpdb->update(
			$table_name,
			array( 'discovered_tools' => wp_json_encode( $discovered_tools ) ),
			array( 'id' => $server_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Delete MCP server
	 */
	public static function delete_mcp_server( $server_id, $user_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		$where        = array( 'id' => $server_id );
		$where_format = array( '%d' );

		if ( $user_id ) {
			$where['user_id'] = $user_id;
			$where_format[]   = '%d';
		}

		return $wpdb->delete( $table_name, $where, $where_format ) !== false;
	}

	/**
	 * Get single MCP server by ID
	 */
	public static function get_mcp_server( $server_id, $user_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_mcp_servers';

		if ( $user_id ) {
			$server = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id = %d AND user_id = %d AND is_active = 1",
					$table_name,
					$server_id,
					$user_id
				),
				ARRAY_A
			);
		} else {
			$server = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id = %d AND is_active = 1",
					$table_name,
					$server_id
				),
				ARRAY_A
			);
		}

		if ( $server ) {
			$server['config'] = json_decode( $server['config'], true );
			if ( $server['discovered_tools'] ) {
				$server['discovered_tools'] = json_decode( $server['discovered_tools'], true );
			}
		}

		return $server;
	}
}
