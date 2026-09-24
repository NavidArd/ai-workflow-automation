<?php
/**
 * Manages all REST API endpoints for the plugin.
 */
class WP_AI_Workflows_REST_API {

	/**
	 * Site option holding the id of the generated sample cloud workflow.
	 */
	const FIRST_CLOUD_WORKFLOW_OPTION = 'wp_ai_workflows_first_cloud_workflow_id';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_ai_workflows_cleanup', array( $this, 'clear_openrouter_models_cache' ) );
	}

	public function register_rest_routes() {
		// Workflows
		register_rest_route(
			'wp-ai-workflows/v1',
			'/workflows',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_workflows' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/workflows',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_workflow' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/workflows/(?P<id>[\w-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_workflow' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/workflows/(?P<id>[\w-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_workflow' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/workflows/(?P<id>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_single_workflow' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Workflow Execution
		register_rest_route(
			'wp-ai-workflows/v1',
			'/execute-workflow/(?P<id>[\w-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_workflow_endpoint' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/executions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_executions' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/executions/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_execution' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/executions/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'stop_and_delete_execution' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/execution-status/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_execution_status' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Settings
		register_rest_route(
			'wp-ai-workflows/v1',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/settings',
			array(
				'methods'             => 'POST,PUT',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/validate-provider-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_provider_key' ),
				'permission_callback' => array( $this, 'admin_only_permission_check' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/available-ai-models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_available_ai_models' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/cost-statistics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cost_statistics' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/cost-settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cost_settings' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/cost-settings',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_cost_settings' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/sync-costs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_costs' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/cost-sync-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sync_info' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/download-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_debug_log' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/system-requirements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_system_requirements' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/migrate-legacy-workflows',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'migrate_legacy_workflows' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/check-migration-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check_migration_status' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/migrate-outputs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'migrate_outputs' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/cleanup-options',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanup_options' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Forms
		register_rest_route(
			'wp-ai-workflows/v1',
			'/gravity-forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_gravity_forms_data' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/wpforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wpforms_data' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/contactform7',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cf7_data' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/ninjaforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ninja_forms_data' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/elementor-forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_elementor_forms_data' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		//WP core

		register_rest_route(
			'wp-ai-workflows/v1',
			'/wp-core-triggers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wp_core_triggers' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Webhooks
		register_rest_route(
			'wp-ai-workflows/v1',
			'/webhook/(?P<node_id>[\w-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook_trigger' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_webhook_url' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/sample-webhook/(?P<id>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sample_webhook' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/webhook-config/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_webhook_config' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// API Balance Check endpoints

		register_rest_route(
			'wp-ai-workflows/v1',
			'/check-openrouter-balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check_openrouter_balance' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// MCP Client endpoints
		register_rest_route(
			'wp-ai-workflows/v1',
			'/mcp-tools/(?P<server>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mcp_tools' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/mcp-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_mcp_connection' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/mcp-custom-server',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_custom_mcp_server' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/mcp-servers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_mcp_servers' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/mcp-servers/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_mcp_server' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Outputs
		register_rest_route(
			'wp-ai-workflows/v1',
			'/save-output',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_output' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/outputs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_outputs' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/latest-output',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_latest_output' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/shortcode-output',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_shortcode_output' ),
				'permission_callback' => '__return_true',
			)
		);

		// Email
		register_rest_route(
			'wp-ai-workflows/v1',
			'/send-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_email' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/upload-attachment',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_attachment_upload' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/media-library',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_media_library_items' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Output Node Email
		register_rest_route(
			'wp-ai-workflows/v1',
			'/output-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_output_email' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Tables
		register_rest_route(
			'wp-ai-workflows/v1',
			'/tables',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tables' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/export-outputs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_outputs' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/tables',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_table' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/table-structure',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_table_structure' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/delete-table',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_table' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/delete-entry',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_entry' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Vector store endpoints
		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-stores',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_vector_stores' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-stores',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_vector_store' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-stores/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_vector_store' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-stores/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_vector_store' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-store-files/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_vector_store_files' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-store-files',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_vector_store_file' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-store-files/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_vector_store_file' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/vector-stores/(?P<id>[a-zA-Z0-9-_]+)/add-wp-content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_wp_content_to_vector_store' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/openai-vector-stores',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_openai_vector_stores' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/openai-vector-stores/(?P<store_id>[a-zA-Z0-9-_]+)/files',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_openai_vector_store_files' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/openai-vector-stores/(?P<store_id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_openai_vector_store' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Knowledge base (Supabase / pgvector) endpoints.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_knowledge_bases' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_knowledge_base' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases/(?P<id>[a-zA-Z0-9-_]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_knowledge_base' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases/(?P<id>[a-zA-Z0-9-_]+)/ingest-text',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ingest_knowledge_base_text' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases/(?P<id>[a-zA-Z0-9-_]+)/ingest-wp-content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ingest_knowledge_base_wp_content' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-bases/(?P<id>[a-zA-Z0-9-_]+)/ingest-file',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ingest_knowledge_base_file' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-base/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_knowledge_base_status' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/knowledge-base/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_knowledge_base_connection' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/wp-content',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wp_content' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Post
		register_rest_route(
			'wp-ai-workflows/v1',
			'/post-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post_types' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/post-fields/(?P<post_type>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_post_fields' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/execute-post-node',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_post_node' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/authors',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_authors' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/categories/(?P<post_type>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_categories_by_post_type' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Template
		register_rest_route(
			'wp-ai-workflows/v1',
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_templates' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/templates',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_template' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_template' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_template' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_template' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Remote Templates Proxy
		register_rest_route(
			'wp-ai-workflows/v1',
			'/remote-templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_remote_templates' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/remote-template/(?P<slug>.+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_remote_template' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		//generator
		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-workflow',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_workflow' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'prompt'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					// Clarify-step answers: a list of { question, values:[...] }.
					// Sanitized element-by-element in the generator's decisions builder.
					'answers' => array(
						'required' => false,
						'type'     => 'array',
					),
					// Chosen AI model id (may contain '/', '-', ':').
					'model'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Clarify-first PHASE 1: cheap analysis that returns 0–4 clarifying
		// questions (option chips) for the UI to ask before the expensive build.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-workflow/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'analyze_workflow_request' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'prompt' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Firecrawl
		register_rest_route(
			'wp-ai-workflows/v1',
			'/firecrawl/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_firecrawl' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/firecrawl/progress/(?P<job_id>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_firecrawl_progress' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/firecrawl/cache/(?P<url>.*)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_firecrawl_cache' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		//Parser
		register_rest_route(
			'wp-ai-workflows/v1',
			'/upload-document',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_document' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/parse-uploaded-document',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'parse_uploaded_document' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		//RSS
		register_rest_route(
			'wp-ai-workflows/v1',
			'/rss-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_rss_feed' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// API Call test endpoint
		register_rest_route(
			'wp-ai-workflows/v1',
			'/test-api-call',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_test_api_call' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'method' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => function ( $url ) {
							return wp_http_validate_url( $url );
						},
					),
				),
			)
		);

		// License-related routes
		register_rest_route(
			'wp-ai-workflows/v1',
			'/activate-license',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate_license' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/deactivate-license',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'deactivate_license' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/check-license',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'check_license' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		//Google
		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-google-redirect-uri',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_google_redirect_uri' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-auth-callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_google_auth_callback' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/refresh-google-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_google_access_token' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/get-google-auth-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_auth_url' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-integration-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_integration_status' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/reset-google-integration',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_google_integration' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-sheets',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_sheets' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-sheets/(?P<id>[a-zA-Z0-9-_]+)/tabs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_sheet_tabs' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-sheets/(?P<spreadsheet_id>[a-zA-Z0-9-_]+)/tabs/(?P<sheet_id>[a-zA-Z0-9-_]+)/columns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_sheet_columns' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-drive-items',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_drive_items' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-drive-folders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_drive_folders' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-triggers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_triggers' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-triggers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_google_trigger' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-triggers/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_trigger' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-triggers/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_google_trigger' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/google-triggers/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_google_trigger' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Unsplash
		register_rest_route(
			'wp-ai-workflows/v1',
			'/unsplash/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'search_unsplash' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// API key verification
		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-api-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_api_key' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/verify-api-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_api_key' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/human-tasks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_human_tasks' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_workflow_tasks' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/human-tasks/(?P<id>\d+)/(?P<action>approve|reject|revert|modify)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_human_task' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_workflow_tasks' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/human-tasks-count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_human_tasks_count' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_workflow_tasks' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_users' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/roles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_roles' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/task-roles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_task_roles' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/task-roles',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_task_roles' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Chat endpoint
		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat_message' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_history' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-config/(?P<workflow_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_config' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-clear-memory',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clear_chat_memory' ),
				// Public: a visitor may erase their OWN memory. The callback only
				// ever clears the caller's own scope key (their session / cookie
				// visitor id / logged-in user), never an arbitrary key.
				'permission_callback' => '__return_true',
				'args'                => array(
					'scope'      => array(
						'type'    => 'string',
						'default' => 'conversation',
					),
					'session_id' => array(
						'type' => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-actions/(?P<workflow_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_actions' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_chat_events' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-action-result',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_action_result' ),
				'permission_callback' => '__return_true',
			)
		);

		// Submit action data
		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-action-submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_action_submission' ),
				'permission_callback' => '__return_true',
			)
		);

		// Chat Logs
		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_logs' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-messages/(?P<session_id>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_messages' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-statistics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_chat_statistics' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/stream-chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stream_chat_message' ),
				'permission_callback' => '__return_true',
			)
		);

		// Human handoff: inbound webhook per provider.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff/(?P<provider>[a-z]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handoff_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// Widget poll: current conversation mode + any queued human replies.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_handoff_poll' ),
				'permission_callback' => '__return_true',
			)
		);

		// Resolve a handoff (return control to the bot). Operator-only.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handoff_resolve' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Operator inbox: list active handed-off conversations (pending_human + human).
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-inbox',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_handoff_inbox' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Full transcript + mode + context for one handed-off conversation.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-conversation',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_handoff_conversation' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Operator reply — relays to the visitor and takes human control.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handoff_reply' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Approve / reject a paused guarded tool call from the inbox.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/agent-approval/(?P<task_id>\d+)/(?P<decision>approve|reject)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_agent_approval' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_workflow_tasks' );
				},
			)
		);

		// Pending-count for the Inbox nav badge (approvals + waiting handoffs).
		register_rest_route(
			'wp-ai-workflows/v1',
			'/inbox-badge',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_inbox_badge' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_workflow_tasks' );
				},
			)
		);

		// Chat file uploads (opt-in per node). Visitor upload.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat_upload' ),
				'permission_callback' => '__return_true',
			)
		);

		// Token-gated download for a shared chat file.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/chat-file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_chat_file' ),
				'permission_callback' => '__return_true',
			)
		);

		// Operator upload — a human agent shares a file back to the visitor.
		register_rest_route(
			'wp-ai-workflows/v1',
			'/handoff-upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_handoff_upload' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Assistant chat endpoints
		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/session',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_assistant_session' ),
					'permission_callback' => array( $this, 'authorize_request' ),
					'args'                => array(
						'workflow_id'      => array(
							'required' => true,
							'type'     => 'string',
						),
						'workflow_context' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/message',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send_assistant_message' ),
					'permission_callback' => array( $this, 'authorize_request' ),
					'args'                => array(
						'session_id' => array(
							'required' => true,
							'type'     => 'string',
						),
						'content'    => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/context',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_assistant_context' ),
					'permission_callback' => array( $this, 'authorize_request' ),
					'args'                => array(
						'session_id'       => array(
							'required' => true,
							'type'     => 'string',
						),
						'workflow_context' => array(
							'required' => true,
							'type'     => 'object',
						),
						'selected_node'    => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/update-mode',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_mode' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/apply-changes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_workflow_changes' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/get-session/(?P<workflow_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_assistant_session' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/history/(?P<session_id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_assistant_history' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/save-approval',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_approval_metadata' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'session_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'message_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'changes'    => array(
						'required' => true,
						'type'     => 'object',
					),
					'status'     => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/assistant/update-approval-status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_approval_status' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'session_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'message_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'status'     => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/whitelabel-settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_whitelabel_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/whitelabel-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_whitelabel_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/upload-logo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_logo_upload' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/import-whitelabel-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_whitelabel_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/openrouter-models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_openrouter_models' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/openai-models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_openai_models' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/models-catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_models_catalog' ),
				'permission_callback' => array( $this, 'authorize_request' ),
				'args'                => array(
					'refresh' => array(
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/multimedia/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_multimedia_models' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generate_image' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/generate-video',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generate_video' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/upload-file',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_file_upload' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/multimedia/estimate-cost',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'estimate_multimedia_cost' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Dynamic Fal.ai model catalog (registry-driven, cached).
		register_rest_route(
			'wp-ai-workflows/v1',
			'/multimedia/model-catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_multimedia_model_catalog' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// A single model's OpenAPI-derived input field schema (cached).
		register_rest_route(
			'wp-ai-workflows/v1',
			'/multimedia/model-schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_multimedia_model_schema' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		// Generic generation (submit assembled inputs to any Fal model).
		register_rest_route(
			'wp-ai-workflows/v1',
			'/multimedia/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generate_media' ),
				'permission_callback' => array( $this, 'authorize_request' ),
			)
		);

		register_rest_route(
			'wp-ai-workflows/v1',
			'/stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stream_output' ),
				'permission_callback' => '__return_true',
			)
		);

		$this->register_platform_routes();
	}

	/**
	 * Platform (credits/cloud) connection-lifecycle endpoints.
	 */
	private function register_platform_routes() {
		$namespace = 'wp-ai-workflows/v1';
		$perm      = array( $this, 'platform_permission_check' );

		register_rest_route(
			$namespace,
			'/platform/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_login' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/signup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_signup' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/register-site',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_register_site' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/google-complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_google_complete' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_status' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_disconnect' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/rotate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_rotate_key' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/credits',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_credits' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/onboarding-milestone',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_onboarding_milestone' ),
				'permission_callback' => $perm,
			)
		);
		// Per-user new-user onboarding (welcome walkthrough) completion state.
		// GET returns the current user's flag; POST persists it. Distinct from the
		// site-wide `setup_completed` option and from the credit milestones above.
		register_rest_route(
			$namespace,
			'/onboarding',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_onboarding_state' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_onboarding_state' ),
					'permission_callback' => $perm,
				),
			)
		);
		// Sample cloud run: the site-wide id of the generated "My first cloud
		// workflow" (so it is created once, never duplicated) plus the current
		// user's dismissal of the Plans & Credits nudge.
		register_rest_route(
			$namespace,
			'/first-cloud-run',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_first_cloud_run_state' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_first_cloud_run_state' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			$namespace,
			'/platform/credits-history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_credits_history' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/usage-daily',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_usage_daily' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/chat-widget-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'chat_widget_status' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/pdf-templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_pdf_templates' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/pdf-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_pdf_preview' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/execution/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_execution_status' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/execution/(?P<id>\d+)/steps',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_execution_steps' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_checkout' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/subscription',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_subscription' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/portal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_portal' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/redeem-license',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_redeem_license' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/legacy-redeem',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_legacy_redeem_status' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'platform_get_preferences' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'platform_set_preferences' ),
					'permission_callback' => $perm,
				),
			)
		);

		// Apps / Connect an App (Pipedream): browser-facing proxy for the
		// platform's Pipedream Connect surface (/api/v1/mcp/*).
		register_rest_route(
			$namespace,
			'/platform/apps/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_apps_search' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_apps_tools' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_connect' ),
				'permission_callback' => $perm,
			)
		);
		// Connect an API-key/BASIC toolkit with user-entered credentials (no OAuth popup).
		register_rest_route(
			$namespace,
			'/platform/apps/connect-credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_connect_credentials' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/connections',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_apps_connections' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/connections/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_connections_sync' ),
				'permission_callback' => $perm,
			)
		);
		// Disconnect one connected account (switch accounts).
		register_rest_route(
			$namespace,
			'/platform/apps/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_disconnect' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/configure',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_configure' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/reload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_reload' ),
				'permission_callback' => $perm,
			)
		);

		// App-event triggers: an app event can start a workflow.
		register_rest_route(
			$namespace,
			'/platform/apps/event-sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_apps_event_sources' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/deploy-trigger',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_deploy_trigger' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/list-triggers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_apps_list_triggers' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/apps/delete-trigger',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_apps_delete_trigger' ),
				'permission_callback' => $perm,
			)
		);

		// Agency multi-site management: browser-facing proxy for the
		// platform's owner-only /org/sites/* console.
		register_rest_route(
			$namespace,
			'/platform/org-sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'platform_org_sites_list' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/org-sites/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_org_site_update' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/org-sites/cap',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_org_site_cap' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/org-sites/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_org_site_remove' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/org-sites/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_org_site_provision' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			$namespace,
			'/platform/sign-out',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_sign_out' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$namespace,
			'/platform/sign-out-everywhere',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'platform_sign_out_everywhere' ),
				'permission_callback' => $perm,
			)
		);

		// Cloud→WP action callback (Wave 4). PUBLIC route: auth IS the HMAC signature,
		// verified inside the handler BEFORE any parsing (same architecture as the
		// existing public webhook route). NEVER add a capability/nonce check here.
		register_rest_route(
			$namespace,
			'/platform/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WP_AI_Workflows_Platform_Callback', 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback for all /platform/* admin routes.
	 *
	 * @return true|WP_Error
	 */
	public function platform_permission_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permission to manage the platform connection.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * POST /platform/login — proxy platform login, return org list (never the token).
	 */
	public function platform_login( $request ) {
		$params   = $request->get_json_params();
		$email    = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$password = isset( $params['password'] ) ? (string) $params['password'] : '';

		if ( '' === $email || '' === $password ) {
			return new WP_Error( 'invalid_input', 'Email and password are required.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::login( $email, $password );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'       => true,
				'organizations' => $result['organizations'],
			)
		);
	}

	/**
	 * POST /platform/signup — create a platform account (headless) and return orgs.
	 */
	public function platform_signup( $request ) {
		$params    = $request->get_json_params();
		$email     = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$password  = isset( $params['password'] ) ? (string) $params['password'] : '';
		$first     = isset( $params['firstName'] ) ? sanitize_text_field( $params['firstName'] ) : '';
		$last      = isset( $params['lastName'] ) ? sanitize_text_field( $params['lastName'] ) : '';

		if ( '' === $email || strlen( $password ) < 8 ) {
			return new WP_Error( 'invalid_input', 'A valid email and a password of at least 8 characters are required.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::signup( $email, $password, $first, $last );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'       => true,
				'organizations' => $result['organizations'],
			)
		);
	}

	/**
	 * POST /platform/google-complete — adopt a JWT handed back by the "Continue with
	 * Google" OAuth popup and return the org list (never the token).
	 */
	public function platform_google_complete( $request ) {
		$params = $request->get_json_params();
		$token  = isset( $params['token'] ) ? trim( (string) $params['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'invalid_input', 'A sign-in token is required.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::adopt_session( $token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'       => true,
				'organizations' => $result['organizations'],
			)
		);
	}

	/**
	 * POST /platform/register-site — register site with the chosen org, store key.
	 */
	public function platform_register_site( $request ) {
		$params   = $request->get_json_params();
		$org_id   = isset( $params['orgId'] ) ? sanitize_text_field( $params['orgId'] ) : '';
		$org_name = isset( $params['orgName'] ) ? sanitize_text_field( $params['orgName'] ) : '';
		$is_poll  = ! empty( $params['poll'] );

		if ( '' === $org_id ) {
			return new WP_Error( 'invalid_input', 'An organization must be selected.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::register_site( $org_id, $org_name );
		if ( is_wp_error( $result ) ) {
			// An unattended readiness re-attempt answers 200 so a pending email
			// verification does not fill the browser console with failed requests.
			if ( $is_poll && 'platform_email_unverified' === $result->get_error_code() ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'code'    => 'platform_email_unverified',
						'message' => $result->get_error_message(),
					)
				);
			}
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'    => true,
				'connected'  => true,
				'connection' => $result,
			)
		);
	}

	/**
	 * Convert a Platform_Client WP_Error into a REST response that PRESERVES the
	 * platform's machine code (NOT_OWNER / UPGRADE_REQUIRED / SITE_LIMIT_REACHED /
	 * platform_auth_jwt / …) and HTTP status, so the Sites UI can react precisely.
	 * The browser reads `err.response.data.code` uniformly.
	 *
	 * @param WP_Error $error
	 * @return WP_REST_Response
	 */
	private function org_sites_error_response( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$pcode  = is_array( $data ) && ! empty( $data['platform_code'] ) ? (string) $data['platform_code'] : '';
		$code   = '' !== $pcode ? $pcode : $error->get_error_code();

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $error->get_error_message(),
			),
			$status ? $status : 400
		);
	}

	/**
	 * GET /platform/org-sites — list the org's sites + entitlement + per-site usage
	 * and caps (owner-only; enforced by the platform). Non-owner → 403 NOT_OWNER,
	 * non-Business is still 200 with entitlement.isBusiness=false so the UI upsells.
	 */
	public function platform_org_sites_list() {
		$result = WP_AI_Workflows_Platform_Client::get_org_sites();
		if ( is_wp_error( $result ) ) {
			return $this->org_sites_error_response( $result );
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	/**
	 * POST /platform/org-sites/update — rename and/or set a site's display URL.
	 * Body: { siteId, name?, websiteUrl? } (websiteUrl '' clears it). Owner-only.
	 */
	public function platform_org_site_update( $request ) {
		$params  = $request->get_json_params();
		$site_id = isset( $params['siteId'] ) ? sanitize_text_field( $params['siteId'] ) : '';
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}

		// null = leave unchanged; a present key (even '') = set/clear that field.
		$name        = array_key_exists( 'name', $params ) ? sanitize_text_field( (string) $params['name'] ) : null;
		$website_url = array_key_exists( 'websiteUrl', $params ) ? (string) $params['websiteUrl'] : null;

		if ( null === $name && null === $website_url ) {
			return new WP_Error( 'invalid_input', 'Provide a name or a website URL to update.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::update_site( $site_id, $name, $website_url );
		if ( is_wp_error( $result ) ) {
			return $this->org_sites_error_response( $result );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /platform/org-sites/cap — set/clear a per-site credit cap (Business only).
	 * Body: { siteId, cap: number|null, period?: 'monthly'|'total' }. Owner-only.
	 */
	public function platform_org_site_cap( $request ) {
		$params  = $request->get_json_params();
		$site_id = isset( $params['siteId'] ) ? sanitize_text_field( $params['siteId'] ) : '';
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}

		$has_cap = array_key_exists( 'cap', $params ) && null !== $params['cap'] && '' !== $params['cap'];
		$cap     = $has_cap ? (float) $params['cap'] : null;
		$period  = isset( $params['period'] ) && 'total' === $params['period'] ? 'total' : 'monthly';

		$result = WP_AI_Workflows_Platform_Client::set_site_cap( $site_id, $cap, $period );
		if ( is_wp_error( $result ) ) {
			return $this->org_sites_error_response( $result );
		}
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	/**
	 * POST /platform/org-sites/remove — remove / revoke a site. Body: { siteId }.
	 * Owner-only. Destructive: the UI confirms before calling this.
	 */
	public function platform_org_site_remove( $request ) {
		$params  = $request->get_json_params();
		$site_id = isset( $params['siteId'] ) ? sanitize_text_field( $params['siteId'] ) : '';
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}

		$fresh = WP_AI_Workflows_Platform_Client::require_recent_session();
		if ( is_wp_error( $fresh ) ) {
			return $this->org_sites_error_response( $fresh );
		}

		$result = WP_AI_Workflows_Platform_Client::remove_site( $site_id );
		if ( is_wp_error( $result ) ) {
			return $this->org_sites_error_response( $result );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /platform/org-sites/provision — pre-provision a new site and issue its key
	 * ONCE. Body: { siteUrl, siteName? }. Owner-only, Business site-limit enforced.
	 * The returned apiKey is plaintext shown once — the UI surfaces it and discards it.
	 */
	public function platform_org_site_provision( $request ) {
		$params    = $request->get_json_params();
		$site_url  = isset( $params['siteUrl'] ) ? esc_url_raw( trim( (string) $params['siteUrl'] ) ) : '';
		$site_name = isset( $params['siteName'] ) ? sanitize_text_field( (string) $params['siteName'] ) : '';

		if ( '' === $site_url ) {
			return new WP_Error( 'invalid_input', 'A valid site URL is required.', array( 'status' => 400 ) );
		}

		$fresh = WP_AI_Workflows_Platform_Client::require_recent_session();
		if ( is_wp_error( $fresh ) ) {
			return $this->org_sites_error_response( $fresh );
		}

		$result = WP_AI_Workflows_Platform_Client::provision_site( $site_url, $site_name );
		if ( is_wp_error( $result ) ) {
			return $this->org_sites_error_response( $result );
		}
		// Pass the one-time plaintext key through to the owner (shown once, never stored).
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	/**
	 * GET /platform/status — connection metadata (never the key).
	 */
	public function platform_status() {
		// Cloud-callback pre-flight verdict: whether the cloud could reach this
		// site's callback URL, used to block Cloud mode before submit.
		$preflight      = WP_AI_Workflows_Platform_Client::callback_preflight_error();
		$site_reachable = ! is_wp_error( $preflight );

		return rest_ensure_response(
			array(
				'success'    => true,
				'connected'  => WP_AI_Workflows_Platform_Client::is_connected(),
				'connection' => WP_AI_Workflows_Platform_Client::get_connection_meta(),
				'callbackPreflight' => array(
					'siteReachable' => $site_reachable,
					'message'       => $site_reachable ? '' : sanitize_text_field( $preflight->get_error_message() ),
				),
				// Whether the current WP user holds a live management session, and which
				// platform account it belongs to.
				'managementSession' => WP_AI_Workflows_Platform_Client::has_management_session(),
				'managementEmail'   => WP_AI_Workflows_Platform_Client::get_session_email(),
				// Entry point for the "Continue with Google" popup.
				'google'     => array(
					'authUrl' => WP_AI_Workflows_Platform_Client::google_auth_url(),
				),
			)
		);
	}

	/**
	 * POST /platform/disconnect — revoke + purge local state.
	 */
	public function platform_disconnect() {
		$fresh = WP_AI_Workflows_Platform_Client::require_recent_session();
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$result = WP_AI_Workflows_Platform_Client::disconnect();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'connected' => false ) );
	}

	/**
	 * POST /platform/sign-out — end this WP user's management session. The site stays
	 * connected; workflows keep running on the site key.
	 */
	public function platform_sign_out() {
		$result = WP_AI_Workflows_Platform_Client::sign_out();
		return rest_ensure_response( array( 'success' => true, 'signedOut' => ! empty( $result['signedOut'] ) ) );
	}

	/**
	 * POST /platform/sign-out-everywhere — retire every session issued for the
	 * platform account, then clear the local one.
	 */
	public function platform_sign_out_everywhere() {
		$result = WP_AI_Workflows_Platform_Client::sign_out_everywhere();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'signedOut' => true ) );
	}

	/**
	 * POST /platform/rotate-key — rotate; returns new keyPrefix/last4 only.
	 */
	public function platform_rotate_key() {
		$fresh = WP_AI_Workflows_Platform_Client::require_recent_session();
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$result = WP_AI_Workflows_Platform_Client::rotate_key();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'    => true,
				'connection' => $result,
			)
		);
	}

	/**
	 * GET /platform/credits — cached credits status for the meter. Never errors the
	 * page: disconnected -> {state:'disconnected'} (zero platform contact),
	 * network/5xx -> {state:'unavailable'} (R2.6). All numbers cast, strings escaped.
	 */
	public function platform_credits( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'disconnected' ) );
		}

		$force  = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		$status = WP_AI_Workflows_Platform_Client::credits_status( $force );
		if ( is_wp_error( $status ) ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'unavailable' ) );
		}

		return rest_ensure_response(
			array(
				'success'             => true,
				'state'               => 'connected',
				'balance'             => (float) $status['balance'],
				'reserved'            => (float) $status['reserved'],
				'available'           => (float) $status['available'],
				// Lets the UI tell a never-used org apart from a spent one.
				'totalUsed'           => (float) $status['totalUsed'],
				'lowBalance'          => ! empty( $status['lowBalance'] ),
				'lowBalanceThreshold' => (float) $status['lowBalanceThreshold'],
				'currency'            => sanitize_text_field( $status['currency'] ),
			)
		);
	}

	/**
	 * GET /onboarding — return whether the current user has completed (or
	 * skipped) the new-user welcome walkthrough. Per-user (user meta).
	 *
	 * @return WP_REST_Response
	 */
	public function get_onboarding_state() {
		$completed = get_user_meta( get_current_user_id(), 'wpaw_onboarding_completed', true );
		return rest_ensure_response(
			array(
				'success'   => true,
				'completed' => (bool) $completed,
			)
		);
	}

	/**
	 * POST /onboarding — persist the current user's welcome-walkthrough state.
	 * Body: { completed: bool }.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function set_onboarding_state( $request ) {
		$params    = $request->get_json_params();
		$completed = is_array( $params ) && ! empty( $params['completed'] );
		$user_id   = get_current_user_id();

		if ( $completed ) {
			update_user_meta( $user_id, 'wpaw_onboarding_completed', '1' );
		} else {
			delete_user_meta( $user_id, 'wpaw_onboarding_completed' );
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'completed' => $completed,
			)
		);
	}

	/**
	 * GET /first-cloud-run: the sample cloud workflow's id (site-wide) and
	 * whether the current user dismissed the Plans & Credits nudge.
	 *
	 * @return WP_REST_Response
	 */
	public function get_first_cloud_run_state() {
		$workflow_id = (string) get_option( self::FIRST_CLOUD_WORKFLOW_OPTION, '' );

		return rest_ensure_response(
			array(
				'success'    => true,
				'workflowId' => $this->sanitize_workflow_id( $workflow_id ),
				'dismissed'  => (bool) get_user_meta( get_current_user_id(), 'wpaw_first_cloud_run_dismissed', true ),
			)
		);
	}

	/**
	 * POST /first-cloud-run: persist the sample workflow id and/or this user's
	 * dismissal. Body: { workflowId?: string, dismissed?: bool }.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function set_first_cloud_run_state( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		if ( isset( $params['workflowId'] ) ) {
			$workflow_id = $this->sanitize_workflow_id( (string) $params['workflowId'] );
			if ( '' !== $workflow_id ) {
				update_option( self::FIRST_CLOUD_WORKFLOW_OPTION, $workflow_id );
			}
		}

		if ( array_key_exists( 'dismissed', $params ) ) {
			$user_id = get_current_user_id();
			if ( ! empty( $params['dismissed'] ) ) {
				update_user_meta( $user_id, 'wpaw_first_cloud_run_dismissed', '1' );
			} else {
				delete_user_meta( $user_id, 'wpaw_first_cloud_run_dismissed' );
			}
		}

		return $this->get_first_cloud_run_state();
	}

	/**
	 * Reduce a workflow identifier to the character set the workflow routes accept.
	 *
	 * @param string $workflow_id
	 * @return string
	 */
	private function sanitize_workflow_id( $workflow_id ) {
		return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $workflow_id );
	}

	/**
	 * POST /onboarding-milestone — browser-facing proxy that awards a one-time
	 * onboarding-milestone credit bonus. When the site is not connected, returns
	 * a friendly { state:'disconnected' } 200 instead of erroring.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function platform_onboarding_milestone( $request ) {
		$params    = $request->get_json_params();
		$milestone = isset( $params['milestone'] ) ? sanitize_key( $params['milestone'] ) : '';
		$allowed   = array( 'first_workflow', 'three_workflows', 'ten_runs', 'chat_widget' );
		if ( ! in_array( $milestone, $allowed, true ) ) {
			return new WP_Error( 'invalid_milestone', 'Unknown onboarding milestone.', array( 'status' => 400 ) );
		}

		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'disconnected' ) );
		}

		$result = WP_AI_Workflows_Platform_Client::grant_onboarding_milestone( $milestone );
		if ( is_wp_error( $result ) ) {
			// Background-ish grant — never surface a hard error into the onboarding UI.
			return rest_ensure_response( array( 'success' => true, 'state' => 'unavailable' ) );
		}

		return rest_ensure_response(
			array(
				'success'        => true,
				'state'          => 'connected',
				'alreadyGranted' => ! empty( $result['alreadyGranted'] ),
				'milestone'      => sanitize_key( $result['milestone'] ),
				'creditsAwarded' => (int) $result['creditsAwarded'],
				'balance'        => (float) $result['balance'],
			)
		);
	}

	/**
	 * GET /platform/credits-history — browser-facing proxy for the Usage Center's
	 * purchase / top-up / consumption ledger. Never errors the page: disconnected →
	 * { state:'disconnected' }; network/5xx → { state:'unavailable' }.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function platform_credits_history( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'disconnected' ) );
		}

		$limit  = (int) $request->get_param( 'limit' );
		$limit  = $limit > 0 ? $limit : 25;
		$cursor = (string) $request->get_param( 'cursor' );
		$kind   = (string) $request->get_param( 'kind' );

		$data = WP_AI_Workflows_Platform_Client::credits_history( $limit, $cursor, $kind );
		if ( is_wp_error( $data ) ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'unavailable' ) );
		}

		// Sanitize each ledger row for safe rendering (labels are prebuilt server-side).
		$items = array();
		$rows  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$meta      = array();
			$meta_rows = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();
			if ( isset( $meta_rows['executionId'] ) ) {
				$meta['executionId'] = sanitize_text_field( (string) $meta_rows['executionId'] );
			}
			$items[] = array(
				'id'           => isset( $row['id'] ) ? sanitize_text_field( (string) $row['id'] ) : '',
				'at'           => isset( $row['at'] ) ? sanitize_text_field( (string) $row['at'] ) : '',
				'kind'         => isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : 'adjustment',
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'credits'      => isset( $row['credits'] ) ? (float) $row['credits'] : 0.0,
				'balanceAfter' => isset( $row['balanceAfter'] ) ? (float) $row['balanceAfter'] : null,
				'meta'         => $meta,
			);
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'state'      => 'connected',
				'items'      => $items,
				'nextCursor' => isset( $data['nextCursor'] ) && null !== $data['nextCursor'] ? sanitize_text_field( (string) $data['nextCursor'] ) : null,
				'hasMore'    => ! empty( $data['hasMore'] ),
			)
		);
	}

	/**
	 * GET /platform/usage-daily — browser-facing proxy for the Usage Center's daily
	 * consumption chart. Disconnected → { state:'disconnected' };
	 * network/5xx → { state:'unavailable' }.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function platform_usage_daily( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'disconnected' ) );
		}

		$days = (int) $request->get_param( 'days' );
		$days = $days > 0 ? $days : 30;

		$data = WP_AI_Workflows_Platform_Client::usage_daily( $days );
		if ( is_wp_error( $data ) ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'unavailable' ) );
		}

		$days_out = array();
		$rows     = isset( $data['days'] ) && is_array( $data['days'] ) ? $data['days'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$days_out[] = array(
				'date'  => isset( $row['date'] ) ? sanitize_text_field( (string) $row['date'] ) : '',
				'spent' => isset( $row['spent'] ) ? (float) $row['spent'] : 0.0,
				'runs'  => isset( $row['runs'] ) ? (int) $row['runs'] : 0,
			);
		}

		return rest_ensure_response(
			array(
				'success'    => true,
				'state'      => 'connected',
				'days'       => $days_out,
				'totalSpent' => isset( $data['totalSpent'] ) ? (float) $data['totalSpent'] : 0.0,
				'totalRuns'  => isset( $data['totalRuns'] ) ? (int) $data['totalRuns'] : 0,
			)
		);
	}

	/* Apps / Connect an App (Pipedream) — browser-facing proxies onto the
	 * platform's /api/v1/mcp/* surface. */

	/**
	 * Shape a Platform_Client apps result into a REST response. On WP_Error, returns
	 * a { success:false, error } body with the error's HTTP status; otherwise passes
	 * the platform's decoded body through unchanged.
	 *
	 * @param array|WP_Error $result
	 * @return WP_REST_Response
	 */
	private function apps_proxy_response( $result ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502;
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				),
				$status
			);
		}
		return rest_ensure_response( is_array( $result ) ? $result : array( 'success' => false, 'error' => 'Unexpected platform response.' ) );
	}

	/**
	 * GET /platform/apps/search — search the Pipedream app registry.
	 */
	public function platform_apps_search( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$q     = (string) $request->get_param( 'q' );
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? $limit : 60;
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_search( $q, $limit ) );
	}

	/**
	 * GET /platform/apps/tools — list an app's actions + dynamic input schemas.
	 */
	public function platform_apps_tools( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$app = (string) $request->get_param( 'app' );
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_tools( $app ) );
	}

	/**
	 * POST /platform/apps/connect — mint a Pipedream Connect token + hosted URL.
	 */
	public function platform_apps_connect( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params = $request->get_json_params();
		$app    = isset( $params['app'] ) ? (string) $params['app'] : '';
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_connect( $app ) );
	}

	/**
	 * POST /platform/apps/connect-credentials — connect an API-key/BASIC app with the
	 * user's own credentials. Body: { app, fields:{ name: value, ... } }. Field values
	 * are secrets: forwarded to the platform (its vault), never stored or logged here.
	 */
	public function platform_apps_connect_credentials( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params = $request->get_json_params();
		$app    = isset( $params['app'] ) ? (string) $params['app'] : '';
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		return $this->apps_proxy_response(
			WP_AI_Workflows_Platform_Client::apps_connect_credentials( $app, $fields )
		);
	}

	/**
	 * GET /platform/apps/connections — list the org's connected accounts.
	 */
	public function platform_apps_connections() {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_connections() );
	}

	/**
	 * POST /platform/apps/disconnect — remove a connected account (switch accounts).
	 * Body: { accountId }.
	 */
	public function platform_apps_disconnect( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params     = $request->get_json_params();
		$account_id = isset( $params['accountId'] ) ? (string) $params['accountId'] : '';
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_disconnect( $account_id ) );
	}

	/**
	 * POST /platform/apps/connections/sync — sync connected accounts from Pipedream.
	 */
	public function platform_apps_connections_sync() {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_connections_sync() );
	}

	/**
	 * POST /platform/apps/configure — load dynamic options for one action prop.
	 * Body: { app, tool, prop, configuredProps, query }.
	 */
	public function platform_apps_configure( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params           = $request->get_json_params();
		$app              = isset( $params['app'] ) ? (string) $params['app'] : '';
		$tool             = isset( $params['tool'] ) ? (string) $params['tool'] : '';
		$prop             = isset( $params['prop'] ) ? (string) $params['prop'] : '';
		$configured_props = isset( $params['configuredProps'] ) && is_array( $params['configuredProps'] ) ? $params['configuredProps'] : array();
		$query            = isset( $params['query'] ) ? (string) $params['query'] : '';
		return $this->apps_proxy_response(
			WP_AI_Workflows_Platform_Client::apps_configure_prop( $app, $tool, $prop, $configured_props, $query )
		);
	}

	/**
	 * POST /platform/apps/reload — reshape an action's whole prop set (reloadProps).
	 * Body: { app, tool, configuredProps }.
	 */
	public function platform_apps_reload( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params           = $request->get_json_params();
		$app              = isset( $params['app'] ) ? (string) $params['app'] : '';
		$tool             = isset( $params['tool'] ) ? (string) $params['tool'] : '';
		$configured_props = isset( $params['configuredProps'] ) && is_array( $params['configuredProps'] ) ? $params['configuredProps'] : array();
		return $this->apps_proxy_response(
			WP_AI_Workflows_Platform_Client::apps_reload_props( $app, $tool, $configured_props )
		);
	}

	/**
	 * GET /platform/apps/event-sources — list an app's event sources (triggers).
	 * Query: { app }.
	 */
	public function platform_apps_event_sources( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$app = (string) $request->get_param( 'app' );
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_event_sources( $app ) );
	}

	/**
	 * POST /platform/apps/deploy-trigger — deploy a Pipedream trigger bound to a
	 * workflow. Body: { app, eventSourceId, configuredProps, workflowId, authProvisionId }.
	 */
	public function platform_apps_deploy_trigger( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params            = $request->get_json_params();
		$workflow_id       = isset( $params['workflowId'] ) ? (string) $params['workflowId'] : '';
		$app               = isset( $params['app'] ) ? (string) $params['app'] : '';
		$event_source_id   = isset( $params['eventSourceId'] ) ? (string) $params['eventSourceId'] : '';
		$configured_props  = isset( $params['configuredProps'] ) && is_array( $params['configuredProps'] ) ? $params['configuredProps'] : array();
		$auth_provision_id = isset( $params['authProvisionId'] ) ? (string) $params['authProvisionId'] : '';
		return $this->apps_proxy_response(
			WP_AI_Workflows_Platform_Client::apps_deploy_trigger( $workflow_id, $app, $event_source_id, $configured_props, $auth_provision_id )
		);
	}

	/**
	 * GET /platform/apps/list-triggers — list the org's deployed triggers.
	 */
	public function platform_apps_list_triggers() {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_list_triggers() );
	}

	/**
	 * POST /platform/apps/delete-trigger — remove a deployed trigger.
	 * Body: { triggerId }.
	 */
	public function platform_apps_delete_trigger( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => false, 'state' => 'disconnected' ) );
		}
		$params     = $request->get_json_params();
		$trigger_id = isset( $params['triggerId'] ) ? (string) $params['triggerId'] : '';
		return $this->apps_proxy_response( WP_AI_Workflows_Platform_Client::apps_delete_trigger( $trigger_id ) );
	}

	/**
	 * GET /chat-widget-status — local check used by the onboarding banner to
	 * decide whether the "Embed the chat widget" milestone is complete.
	 *
	 * @return WP_REST_Response
	 */
	public function chat_widget_status() {
		return rest_ensure_response(
			array(
				'success'  => true,
				'embedded' => $this->is_chat_widget_embedded(),
			)
		);
	}

	/**
	 * Local detection for the chat-widget onboarding milestone. Cheap and read-only.
	 *
	 * @return bool True when the chat widget is embedded somewhere public.
	 */
	private function is_chat_widget_embedded() {
		global $wpdb;

		// 1) Any PUBLISHED post/page/CPT content carrying the chat shortcode.
		$like  = '%' . $wpdb->esc_like( '[wp_ai_workflow_chat' ) . '%';
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_status = %s AND post_content LIKE %s LIMIT 1",
				$wpdb->posts,
				'publish',
				$like
			)
		);
		if ( ! empty( $found ) ) {
			return true;
		}

		// 2) The bundled sidebar widget, placed with a workflow selected. get_option
		// returns every saved instance keyed by index plus a _multiwidget flag.
		$instances = get_option( 'widget_wp_ai_workflows_chat_widget', array() );
		if ( is_array( $instances ) ) {
			foreach ( $instances as $key => $instance ) {
				if ( '_multiwidget' === $key || ! is_array( $instance ) ) {
					continue;
				}
				if ( ! empty( $instance['workflow_id'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * GET /platform/pdf-templates — proxy so the Generate PDF node's template
	 * picker can list the built-in templates and their expected data fields.
	 */
	public function platform_pdf_templates( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'state'     => 'disconnected',
					'templates' => array(),
				)
			);
		}

		$templates = WP_AI_Workflows_Platform_Client::get_pdf_templates();
		if ( is_wp_error( $templates ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'state'     => 'unavailable',
					'templates' => array(),
				)
			);
		}

		// Sanitize each template descriptor for safe rendering in the picker.
		$clean = array();
		foreach ( (array) $templates as $tpl ) {
			if ( ! is_array( $tpl ) || empty( $tpl['id'] ) ) {
				continue;
			}
			$fields = array();
			if ( isset( $tpl['fields'] ) && is_array( $tpl['fields'] ) ) {
				foreach ( $tpl['fields'] as $field ) {
					if ( ! is_array( $field ) || empty( $field['name'] ) ) {
						continue;
					}
					$fields[] = array(
						'name'        => sanitize_text_field( (string) $field['name'] ),
						'type'        => isset( $field['type'] ) ? sanitize_text_field( (string) $field['type'] ) : 'string',
						'required'    => ! empty( $field['required'] ),
						'description' => isset( $field['description'] ) ? sanitize_text_field( (string) $field['description'] ) : '',
					);
				}
			}
			$clean[] = array(
				'id'          => sanitize_text_field( (string) $tpl['id'] ),
				'name'        => isset( $tpl['name'] ) ? sanitize_text_field( (string) $tpl['name'] ) : (string) $tpl['id'],
				'description' => isset( $tpl['description'] ) ? sanitize_text_field( (string) $tpl['description'] ) : '',
				'category'    => isset( $tpl['category'] ) ? sanitize_text_field( (string) $tpl['category'] ) : '',
				'fields'      => $fields,
			);
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'state'     => 'connected',
				'templates' => $clean,
			)
		);
	}

	/**
	 * POST /platform/pdf-preview — returns a free PNG preview of a PDF template
	 * or custom HTML, so the Generate PDF node can show users the result before
	 * they spend a credit on a real render. Not credit-metered.
	 *
	 * Body: { template:<id|"custom">, data?:{}, html?:<custom>, options?:{landscape,width} }.
	 * Returns { success, state, image:<data:image/png;base64,…>, mimeType, bytes }.
	 */
	public function platform_pdf_preview( $request ) {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'state'   => 'disconnected',
					'image'   => '',
				)
			);
		}

		$params   = $request->get_json_params();
		$params   = is_array( $params ) ? $params : array();
		$template = isset( $params['template'] ) ? sanitize_text_field( (string) $params['template'] ) : '';
		if ( '' === $template ) {
			return new WP_Error( 'pdf_preview_bad_request', 'A template id (or "custom") is required.', array( 'status' => 400 ) );
		}

		$body = array( 'template' => $template );

		if ( 'custom' === $template ) {
			// Pass the RAW custom HTML through (bounded); the render service sanitizes
			// it exactly like /generate does. We only cap the size here.
			$html = isset( $params['html'] ) ? (string) $params['html'] : '';
			if ( strlen( $html ) > 200000 ) {
				$html = substr( $html, 0, 200000 );
			}
			if ( '' === trim( $html ) ) {
				return new WP_Error( 'pdf_preview_bad_request', 'Custom HTML is required to preview a custom template.', array( 'status' => 400 ) );
			}
			$body['html'] = $html;
		} elseif ( isset( $params['data'] ) && is_array( $params['data'] ) ) {
			$body['data'] = self::sanitize_pdf_preview_data( $params['data'], 0 );
		}

		// Optional preview render options (landscape / width) — bounded.
		if ( isset( $params['options'] ) && is_array( $params['options'] ) ) {
			$options = array();
			if ( isset( $params['options']['landscape'] ) ) {
				$options['landscape'] = (bool) $params['options']['landscape'];
			}
			if ( isset( $params['options']['width'] ) ) {
				$width = (int) $params['options']['width'];
				if ( $width > 0 ) {
					$options['width'] = max( 320, min( 2000, $width ) );
				}
			}
			if ( ! empty( $options ) ) {
				$body['options'] = $options;
			}
		}

		$result = WP_AI_Workflows_Platform_Client::preview_pdf( $body );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'state'   => 'unavailable',
					'image'   => '',
					'error'   => $result->get_error_message(),
				)
			);
		}

		// Only surface a value that is actually a PNG data URL — fail closed otherwise.
		$data_url = isset( $result['dataUrl'] ) ? (string) $result['dataUrl'] : '';
		$image    = ( 0 === strpos( $data_url, 'data:image/' ) ) ? $data_url : '';

		return rest_ensure_response(
			array(
				'success'  => true,
				'state'    => 'connected',
				'image'    => $image,
				'mimeType' => isset( $result['mimeType'] ) ? sanitize_text_field( (string) $result['mimeType'] ) : 'image/png',
				'bytes'    => isset( $result['bytes'] ) ? (int) $result['bytes'] : 0,
			)
		);
	}

	/**
	 * Recursively sanitize the caller-supplied preview `data` object before it is
	 * forwarded to the render service. Bounds recursion depth, sanitizes string
	 * keys, and coerces leaf values to safe scalars.
	 *
	 * @param array $data  Arbitrary nested associative/indexed array of scalars.
	 * @param int   $depth Current recursion depth.
	 * @return array Sanitized structure safe to JSON-encode and forward.
	 */
	private static function sanitize_pdf_preview_data( $data, $depth ) {
		if ( $depth > 8 || ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
			if ( is_array( $value ) ) {
				$out[ $clean_key ] = self::sanitize_pdf_preview_data( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $clean_key ] = $value;
			} elseif ( is_string( $value ) ) {
				// Preserve newlines (multi-line addresses etc.); strip markup.
				$out[ $clean_key ] = sanitize_textarea_field( $value );
			}
			// null / objects are dropped.
		}
		return $out;
	}

	/**
	 * GET /platform/execution/{id} — poll proxy for a cloud run. Given a local
	 * execution row id, polls the platform, settles the row, and returns a
	 * sanitized status the builder can drive on.
	 */
	public function platform_execution_status( $request ) {
		$execution_id = (int) $request->get_param( 'id' );
		$status       = WP_AI_Workflows_Workflow::sync_cloud_execution( $execution_id );

		$response = array(
			'success'     => true,
			'status'      => isset( $status['status'] ) ? sanitize_text_field( $status['status'] ) : 'processing',
			'is_complete' => ! empty( $status['is_complete'] ),
		);
		if ( isset( $status['errorMessage'] ) ) {
			$response['errorMessage'] = sanitize_text_field( $status['errorMessage'] );
		}
		if ( array_key_exists( 'outputData', $status ) ) {
			$response['outputData'] = $status['outputData'];
		}
		if ( isset( $status['totalCost'] ) ) {
			$response['totalCost'] = (float) $status['totalCost'];
		}
		if ( ! empty( $status['top_up'] ) ) {
			$response['topUp'] = true;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * GET /platform/execution/{id}/steps — ordered per-node steps (name + input +
	 * output) for a cloud execution, so the details modal can show what happened
	 * at each node. For a local/BYOK run (no platform id) returns cloud:false
	 * with an empty list — the modal renders local per-node data instead.
	 */
	public function platform_execution_steps( $request ) {
		global $wpdb;
		$execution_id     = (int) $request->get_param( 'id' );
		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin custom table, no core API.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT cost_details FROM %i WHERE id = %d", $executions_table, $execution_id )
		);
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Execution not found.', array( 'status' => 404 ) );
		}

		$meta = $row->cost_details ? json_decode( $row->cost_details, true ) : array();
		$pid  = is_array( $meta ) && isset( $meta['platform_execution_id'] ) ? $meta['platform_execution_id'] : null;

		// Not a cloud run — the modal falls back to WP-stored per-node data.
		if ( null === $pid ) {
			return rest_ensure_response(
				array(
					'success'      => true,
					'cloud'        => false,
					'steps'        => array(),
					'totalCredits' => 0,
				)
			);
		}

		$remote = WP_AI_Workflows_Platform_Client::get_execution_steps( $pid );
		if ( is_wp_error( $remote ) ) {
			$data = $remote->get_error_data();
			$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502;
			return new WP_Error(
				$remote->get_error_code(),
				$remote->get_error_message(),
				array( 'status' => $code )
			);
		}

		$steps_in  = isset( $remote['steps'] ) && is_array( $remote['steps'] ) ? $remote['steps'] : array();
		$steps_out = array();
		foreach ( $steps_in as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$steps_out[] = array(
				'nodeId'         => isset( $step['nodeId'] ) ? sanitize_text_field( (string) $step['nodeId'] ) : '',
				'nodeName'       => isset( $step['nodeName'] ) ? sanitize_text_field( (string) $step['nodeName'] ) : '',
				'nodeType'       => isset( $step['nodeType'] ) ? sanitize_text_field( (string) $step['nodeType'] ) : '',
				'status'         => isset( $step['status'] ) ? sanitize_text_field( (string) $step['status'] ) : 'completed',
				// input/output are arbitrary structured blobs rendered read-only in a
				// <pre> as JSON; passed through as-is (not concatenated into HTML).
				'input'          => isset( $step['input'] ) ? $step['input'] : null,
				'output'         => isset( $step['output'] ) ? $step['output'] : null,
				'creditsCharged' => isset( $step['creditsCharged'] ) && null !== $step['creditsCharged']
					? (float) $step['creditsCharged']
					: null,
			);
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'cloud'        => true,
				'status'       => isset( $remote['status'] ) ? sanitize_text_field( (string) $remote['status'] ) : 'completed',
				'totalCredits' => isset( $remote['totalCredits'] ) ? (int) ceil( (float) $remote['totalCredits'] ) : 0,
				'steps'        => $steps_out,
			)
		);
	}

	/**
	 * POST /platform/checkout — create a checkout transaction for the selected
	 * pack/plan and return our hosted checkout page URL.
	 */
	public function platform_checkout( $request ) {
		$params = $request->get_json_params();
		$item   = isset( $params['itemId'] ) ? sanitize_text_field( $params['itemId'] ) : '';
		$cycle  = isset( $params['billingCycle'] ) ? sanitize_text_field( $params['billingCycle'] ) : '';

		if ( '' === $item ) {
			return new WP_Error( 'invalid_input', 'A pack or plan must be selected.', array( 'status' => 400 ) );
		}

		$result = WP_AI_Workflows_Platform_Client::create_checkout( $item, $cycle );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'     => true,
				'checkoutUrl' => esc_url_raw( $result['checkoutUrl'] ),
			)
		);
	}

	/**
	 * GET /platform/subscription — current plan/status summary for the Billing UI.
	 * Best-effort: a platform failure returns {state:'unavailable'} rather than
	 * erroring the page, so the credit packs still render.
	 */
	public function platform_subscription() {
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return rest_ensure_response( array( 'success' => true, 'state' => 'disconnected' ) );
		}
		$result = WP_AI_Workflows_Platform_Client::subscription_status();
		if ( is_wp_error( $result ) ) {
			// No fresh management session → tell the UI to prompt for sign-in (rather
			// than silently showing "free"). Any other failure degrades gracefully.
			$state = 'platform_auth_jwt' === $result->get_error_code() ? 'needs_signin' : 'unavailable';
			return rest_ensure_response( array( 'success' => true, 'state' => $state ) );
		}
		return rest_ensure_response(
			array(
				'success'  => true,
				'state'    => 'connected',
				'plan'     => sanitize_text_field( $result['plan'] ),
				'status'   => sanitize_text_field( $result['status'] ),
				'cycle'    => sanitize_text_field( $result['cycle'] ),
				'renewsAt' => sanitize_text_field( $result['renewsAt'] ),
			)
		);
	}

	/**
	 * POST /platform/portal — mint a one-time Paddle customer-portal session URL
	 * (manage/cancel subscription, payment methods). The browser opens it in a new
	 * tab. JWT-authed; the backend resolves the customer from the account.
	 */
	public function platform_portal() {
		$result = WP_AI_Workflows_Platform_Client::create_portal_session();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'   => true,
				'portalUrl' => esc_url_raw( $result['portalUrl'] ),
			)
		);
	}

	/**
	 * POST /platform/redeem-license — manual legacy v1 (SLM) key redemption
	 * fallback for users whose stored option is missing. Returns the final
	 * normalised outcome (monthly|goodwill|already|invalid|not_redeemable|
	 * pending|error). Rate limited to one submit per 30s per user.
	 */
	public function platform_redeem_license( $request ) {
		$params  = $request->get_json_params();
		$license = isset( $params['licenseKey'] ) ? sanitize_text_field( $params['licenseKey'] ) : '';

		if ( '' === $license ) {
			return new WP_Error( 'invalid_input', 'A license key is required.', array( 'status' => 400 ) );
		}

		$rl_key = 'wpaw_redeem_rl_' . get_current_user_id();
		if ( get_transient( $rl_key ) ) {
			return new WP_Error( 'rate_limited', 'Please wait a moment before submitting again.', array( 'status' => 429 ) );
		}
		set_transient( $rl_key, 1, 30 );

		$result = WP_AI_Workflows_Platform_Client::redeem_license( $license );
		if ( is_wp_error( $result ) ) {
			// Transient/auth/unexpected — surface as an HTTP error; the UI shows a
			// friendly "try again" message.
			return $result;
		}
		return rest_ensure_response(
			array(
				'success'        => true,
				'outcome'        => isset( $result['outcome'] ) ? sanitize_key( $result['outcome'] ) : 'error',
				'mechanism'      => isset( $result['mechanism'] ) ? sanitize_key( $result['mechanism'] ) : '',
				'tier'           => isset( $result['tier'] ) ? sanitize_key( $result['tier'] ) : '',
				'granted'        => isset( $result['granted'] ) ? (int) $result['granted'] : 0,
				'monthlyCredits' => isset( $result['monthlyCredits'] ) ? (int) $result['monthlyCredits'] : 0,
				'endsAt'         => isset( $result['endsAt'] ) ? sanitize_text_field( $result['endsAt'] ) : '',
			)
		);
	}

	/**
	 * GET /platform/legacy-redeem — the automatic legacy-redemption status the
	 * account UI polls after connecting. If the attempt is still queued, runs
	 * the worker inline for an immediate reveal instead of waiting on cron.
	 */
	public function platform_legacy_redeem_status() {
		$record = WP_AI_Workflows_Platform_Client::get_legacy_redeem_result();
		$state  = isset( $record['state'] ) ? (string) $record['state'] : '';

		if ( 'pending_attempt' === $state && WP_AI_Workflows_Platform_Client::is_connected() ) {
			$record = WP_AI_Workflows_Platform_Client::run_legacy_auto_redeem();
			$state  = isset( $record['state'] ) ? (string) $record['state'] : '';
		}

		$out = array(
			'success' => true,
			'state'   => '' !== $state ? $state : 'none',
		);
		if ( 'done' === $state ) {
			$out['mechanism']      = isset( $record['mechanism'] ) ? sanitize_key( $record['mechanism'] ) : '';
			$out['tier']           = isset( $record['tier'] ) ? sanitize_key( $record['tier'] ) : '';
			$out['granted']        = isset( $record['granted'] ) ? (int) $record['granted'] : 0;
			$out['monthlyCredits'] = isset( $record['monthlyCredits'] ) ? (int) $record['monthlyCredits'] : 0;
			$out['endsAt']         = isset( $record['endsAt'] ) ? sanitize_text_field( (string) $record['endsAt'] ) : '';
		} elseif ( 'exhausted' === $state ) {
			$out['outcome'] = isset( $record['outcome'] ) ? sanitize_key( $record['outcome'] ) : '';
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /platform/preferences — return the keyless-routing global preference so the
	 * Account tab can render its toggle (R5.3).
	 */
	public function platform_get_preferences() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		return rest_ensure_response(
			array(
				'success'                      => true,
				'prefer_credits_when_connected' => is_array( $settings ) && ! empty( $settings['prefer_credits_when_connected'] ),
			)
		);
	}

	/**
	 * POST /platform/preferences — persist the global "prefer credits when connected"
	 * toggle inside wp_ai_workflows_settings without clobbering other keys (R5.3).
	 */
	public function platform_set_preferences( $request ) {
		$params   = $request->get_json_params();
		$prefer   = ! empty( $params['prefer_credits_when_connected'] );
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['prefer_credits_when_connected'] = $prefer;
		update_option( 'wp_ai_workflows_settings', $settings );
		return rest_ensure_response(
			array(
				'success'                       => true,
				'prefer_credits_when_connected' => $prefer,
			)
		);
	}



	public function authorize_request( $request ) {

		// Authorization is capability + nonce (admin) or the site's inbound API key.

		if ( strpos( $request->get_route(), '/wp-ai-workflows/v1/firecrawl/' ) === 0 ) {
			$settings = get_option( 'wp_ai_workflows_settings', array() );
			if ( empty( $settings['firecrawl_api_key'] ) ) {
				return new WP_Error( 'firecrawl_api_key_missing', 'Firecrawl API key is required for this operation', array( 'status' => 403 ) );
			}
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$provided_key  = $request->get_header( 'X-Api-Key' );
		$encrypted_key = get_option( 'wp_ai_workflows_encrypted_api_key' );

		if ( $provided_key && wp_check_password( $provided_key, $encrypted_key ) ) {
			return true;
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Authorization failed',
			'error',
			array(
				'route'  => $request->get_route(),
				'method' => $request->get_method(),
			)
		);
		return new WP_Error( 'rest_forbidden', 'Unauthorized access', array( 'status' => 401 ) );
	}

	public function get_workflows( $request ) {
		return WP_AI_Workflows_Workflow::get_workflows( $request );
	}

	public function create_workflow( $request ) {
		return WP_AI_Workflows_Workflow::create_workflow( $request );
	}

	public function update_workflow( $request ) {
		return WP_AI_Workflows_Workflow::update_workflow( $request );
	}

	public function delete_workflow( $request ) {
		return WP_AI_Workflows_Workflow::delete_workflow( $request );
	}

	public function get_single_workflow( $request ) {
		return WP_AI_Workflows_Workflow::get_single_workflow( $request );
	}

	public function execute_workflow_endpoint( $request ) {
		return WP_AI_Workflows_Workflow::execute_workflow_endpoint( $request );
	}

	public function stream_output( $request ) {
		return WP_AI_Workflows_Shortcode::stream_output( $request );
	}

	public function get_execution_status( $request ) {
		return WP_AI_Workflows_Workflow::get_execution_status( $request );
	}

	public function get_executions( $request ) {
		return WP_AI_Workflows_Workflow::get_executions( $request );
	}

	public function get_execution( $request ) {
		return WP_AI_Workflows_Workflow::get_execution( $request );
	}

	public function stop_and_delete_execution( $request ) {
		return WP_AI_Workflows_Workflow::stop_and_delete_execution( $request );
	}

	public function get_gravity_forms_data( $request ) {
		return WP_AI_Workflows_Utilities::get_gravity_forms_data( $request );
	}

	public function get_wpforms_data( $request ) {
		return WP_AI_Workflows_Utilities::get_wpforms_data( $request );
	}

	public function get_cf7_data( $request ) {
		return WP_AI_Workflows_Utilities::get_cf7_data( $request );
	}

	public function get_ninja_forms_data( $request ) {
		return WP_AI_Workflows_Utilities::get_ninja_forms_data( $request );
	}

	public function get_elementor_forms_data( $request ) {
		return WP_AI_Workflows_Utilities::get_elementor_forms_data( $request );
	}

	public function handle_webhook_trigger( $request ) {
		return WP_AI_Workflows_Workflow::handle_webhook_trigger( $request );
	}

	public function generate_webhook_url( $request ) {
		return WP_AI_Workflows_Workflow::generate_webhook_url( $request );
	}

	public function save_output( $request ) {
		return WP_AI_Workflows_Workflow::save_output( $request );
	}

	public function get_outputs( $request ) {
		return WP_AI_Workflows_Workflow::get_outputs( $request );
	}

	public function get_latest_output( $request ) {
		return WP_AI_Workflows_Workflow::get_latest_output( $request );
	}

	public function get_shortcode_output( $request ) {
		return WP_AI_Workflows_Shortcode::get_shortcode_output( $request );
	}

	public function send_email( $request ) {
		return WP_AI_Workflows_Node_Execution::send_email( $request );
	}

	public function get_tables( $request ) {
		return WP_AI_Workflows_Database::get_tables( $request );
	}

	public function export_outputs( $request ) {
		return WP_AI_Workflows_Database::export_outputs( $request );
	}

	public function create_table( $request ) {
		return WP_AI_Workflows_Database::create_table( $request );
	}

	public function get_table_structure( $request ) {
		return WP_AI_Workflows_Database::get_table_structure( $request );
	}

	public function delete_table( $request ) {
		return WP_AI_Workflows_Database::delete_table( $request );
	}

	public function delete_entry( $request ) {
		return WP_AI_Workflows_Database::delete_entry( $request );
	}

	public function get_post_types( $request ) {
		return WP_AI_Workflows_Node_Execution::get_post_types( $request );
	}

	public function get_post_fields( $request ) {
		return WP_AI_Workflows_Node_Execution::get_post_fields( $request );
	}

	public function execute_post_node( $request ) {
		return WP_AI_Workflows_Node_Execution::execute_post_node( $request );
	}

	public function get_templates( $request ) {
		return WP_AI_Workflows_Workflow::get_templates( $request );
	}

	public function create_template( $request ) {
		return WP_AI_Workflows_Workflow::create_template( $request );
	}

	public function get_template( $request ) {
		return WP_AI_Workflows_Workflow::get_template( $request );
	}

	public function update_template( $request ) {
		return WP_AI_Workflows_Workflow::update_template( $request );
	}

	public function delete_template( $request ) {
		return WP_AI_Workflows_Workflow::delete_template( $request );
	}

	public function generate_api_key( $request ) {
		$new_key = WP_AI_Workflows_Utilities::generate_and_encrypt_api_key();
		update_option( 'wp_ai_workflows_api_key', $new_key );
		return new WP_REST_Response( array( 'ai_workflow_api_key' => $this->get_masked_api_key( 'wp_ai_workflows_api_key' ) ), 200 );
	}

	public function verify_api_key( $request ) {
		return WP_AI_Workflows_Utilities::verify_api_key( $request );
	}

	private function get_masked_api_key( $option_name ) {
		$api_key = get_option( $option_name, '' );
		if ( strlen( $api_key ) > 4 ) {
			return str_repeat( '*', strlen( $api_key ) - 4 ) . substr( $api_key, -4 );
		}
		return $api_key;
	}

	public function get_wp_core_triggers() {
		$triggers = array(
			array(
				'value' => 'publish_post',
				'label' => 'Post Published',
			),
			array(
				'value' => 'user_register',
				'label' => 'User Registered',
			),
			array(
				'value' => 'wp_insert_comment',
				'label' => 'Comment Submitted',
			),
			array(
				'value' => 'wp_login',
				'label' => 'User Logged In',
			),
			array(
				'value' => 'transition_post_status',
				'label' => 'Post Status Changed',
			),
		);

		return new WP_REST_Response( $triggers, 200 );
	}

	public function activate_license( $request ) {
		$license_key = $request->get_param( 'license_key' );
		if ( ! $license_key ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'message'    => 'License key is required',
					'error_code' => 'missing_key',
				),
				400
			);
		}

		$license = new WP_AI_Workflows_License();
		$result  = $license->activate_license( $license_key );

		// If result is array (new format with detailed error info)
		if ( is_array( $result ) ) {
			return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
		}

		// Fallback for boolean response (legacy format)
		if ( is_bool( $result ) ) {
			return new WP_REST_Response(
				array(
					'success'    => $result,
					'message'    => $result ? 'License activated successfully' : 'Failed to activate license',
					'error_code' => $result ? null : 'activation_failed',
				),
				$result ? 200 : 400
			);
		}

		// Unexpected response type
		WP_AI_Workflows_Utilities::debug_log(
			'Unexpected activation response type',
			'error',
			array(
				'type' => gettype( $result ),
			)
		);

		return new WP_REST_Response(
			array(
				'success'    => false,
				'message'    => 'An unexpected error occurred',
				'error_code' => 'unexpected_error',
			),
			500
		);
	}

	public function deactivate_license( $request ) {
		$license = new WP_AI_Workflows_License();
		$result  = $license->deactivate_license();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'message'    => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				),
				400
			);
		}

		if ( is_array( $result ) ) {
			return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
		}

		return new WP_REST_Response(
			array(
				'success'    => (bool) $result,
				'message'    => $result ? 'License deactivated successfully' : 'Failed to deactivate license',
				'error_code' => $result ? null : 'deactivation_failed',
			),
			$result ? 200 : 400
		);
	}

	public function check_license( $request ) {
		// Free-first model: this endpoint no longer gates the UI or any feature.
		return new WP_REST_Response(
			array(
				'success'   => true,
				'message'   => 'License check is no longer required (free-first).',
				'is_active' => true,
			),
			200
		);
	}
	public function verify_update_check_request( $request ) {
		$license_key = $request->get_header( 'License-Key' );
		$license     = new WP_AI_Workflows_License();
		return $license->check_license( $license_key );
	}

	public function get_settings( $request ) {
		return WP_AI_Workflows_Utilities::get_settings( $request );
	}

	public function update_settings( $request ) {
		$response = WP_AI_Workflows_Utilities::update_settings( $request );

		$settings = $request->get_json_params();
		if ( isset( $settings['license_key'] ) ) {
			$license = new WP_AI_Workflows_License();
			$license->activate_license( $settings['license_key'] );
		}

		return $response;
	}

	public function admin_only_permission_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', 'You do not have permission to perform this action.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * POST /validate-provider-key — ask the provider whether a key authenticates.
	 * Body: { provider: 'openrouter'|'openai', key }. The key is never stored,
	 * echoed or logged here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response { valid: bool, reason: string }
	 */
	public function validate_provider_key( $request ) {
		$params   = $request->get_json_params();
		$provider = isset( $params['provider'] ) ? sanitize_key( $params['provider'] ) : 'openrouter';
		$key      = isset( $params['key'] ) ? trim( (string) $params['key'] ) : '';

		// Printable ASCII only: a bearer token has no other shape, and this closes
		// header injection through the Authorization value.
		if ( '' === $key || strlen( $key ) < 20 || preg_match( '/[^\x21-\x7E]/', $key ) ) {
			return rest_ensure_response( array( 'valid' => false, 'reason' => 'malformed' ) );
		}

		$endpoints = array(
			'openrouter' => 'https://openrouter.ai/api/v1/key',
			'openai'     => 'https://api.openai.com/v1/models',
		);
		if ( ! isset( $endpoints[ $provider ] ) ) {
			$provider = 'openrouter';
		}

		$response = wp_remote_get(
			$endpoints[ $provider ],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array( 'valid' => false, 'reason' => 'unreachable' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return rest_ensure_response( array( 'valid' => true, 'reason' => 'ok' ) );
		}
		if ( 401 === $status || 403 === $status ) {
			return rest_ensure_response( array( 'valid' => false, 'reason' => 'rejected' ) );
		}

		return rest_ensure_response( array( 'valid' => false, 'reason' => 'unreachable' ) );
	}

	public function get_available_ai_models() {
		$models = array(
			array(
				'value' => 'openai',
				'label' => 'OpenAI',
			),
			array(
				'value' => 'perplexity',
				'label' => 'Perplexity',
			),
			array(
				'value'    => 'anthropic',
				'label'    => 'Anthropic Claude (coming soon)',
				'disabled' => true,
			),
			array(
				'value'    => 'gemini',
				'label'    => 'Google Gemini (coming soon)',
				'disabled' => true,
			),
		);

		return new WP_REST_Response( $models, 200 );
	}

	public function download_debug_log( $request ) {
		return WP_AI_Workflows_Utilities::download_log_file( $request );
	}

	public function get_human_tasks( $request ) {
		try {
			$user_id = get_current_user_id();
			WP_AI_Workflows_Utilities::debug_log( 'Fetching human tasks for user: ' . $user_id, 'debug' );

			$human_tasks = new WP_AI_Workflows_Human_Tasks();
			$tasks       = $human_tasks->get_pending_tasks_for_user( $user_id );

			WP_AI_Workflows_Utilities::debug_log( 'Fetched tasks: ' . print_r( $tasks, true ), 'debug' );

			return new WP_REST_Response( $tasks, 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log( 'Error fetching human tasks: ' . $e->getMessage(), 'error' );
			return new WP_Error( 'fetch_error', 'An error occurred while fetching tasks', array( 'status' => 500 ) );
		}
	}

	public function update_human_task( $request ) {
			$task_id  = $request['id'];
			$action   = $request['action'];
			$user_id  = get_current_user_id();
			$comments = $request->get_param( 'comments' );
			$content  = $request->get_param( 'modified_content' );

			WP_AI_Workflows_Utilities::debug_log(
				'Updating human task',
				'info',
				array(
					'task_id' => $task_id,
					'action'  => $action,
					'user_id' => $user_id,
					'content' => $content,
				)
			);

			$human_tasks = new WP_AI_Workflows_Human_Tasks();
			$task        = $human_tasks->get_task( $task_id );

		if ( ! $task ) {
			WP_AI_Workflows_Utilities::debug_log( 'Task not found', 'error', array( 'task_id' => $task_id ) );
			return new WP_Error( 'task_not_found', 'Task not found', array( 'status' => 404 ) );
		}

			WP_AI_Workflows_Utilities::debug_log( 'Task found', 'info', array( 'task' => $task ) );

		// Agent tool-approval tasks resume a paused chat conversation, not a
		// workflow execution — route them through the shared approval resolver.
		if ( class_exists( 'WP_AI_Workflows_Agent_Approvals' ) && WP_AI_Workflows_Agent_Approvals::is_agent_task( $task ) ) {
			if ( ! in_array( $action, array( 'approve', 'reject', 'revert' ), true ) ) {
				return new WP_Error( 'invalid_action', 'Unsupported action for an approval task', array( 'status' => 400 ) );
			}
			$approved = ( 'approve' === $action );
			$resolved = WP_AI_Workflows_Agent_Approvals::resolve( $task_id, $approved, $user_id );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			return new WP_REST_Response(
				array( 'message' => 'Approval ' . ( $approved ? 'approved' : 'rejected' ) . ' and conversation resumed' ),
				200
			);
		}

		switch ( $action ) {
			case 'approve':
			case 'reject':
			case 'revert':
				$result = $human_tasks->update_task_status( $task_id, $action . 'ed', $user_id, $comments );
				if ( $result ) {
					if ( $action === 'reject' ) {
						WP_AI_Workflows_Workflow::complete_execution( $task['execution_id'], 'rejected' );
					} else {
						WP_AI_Workflows_Workflow::resume_execution( $task['execution_id'], $task['node_id'], $content, $action );
					}
				}
				break;
			case 'modify':
				$result = $human_tasks->update_task_status( $task_id, 'modified', $user_id, $comments, $content );
				if ( $result ) {
					WP_AI_Workflows_Workflow::resume_execution( $task['execution_id'], $task['node_id'], $content, 'modify' );
				}
				break;
			default:
				WP_AI_Workflows_Utilities::debug_log( 'Invalid action', 'error', array( 'action' => $action ) );
				return new WP_Error( 'invalid_action', 'Invalid action', array( 'status' => 400 ) );
		}

		if ( $result === false ) {
			WP_AI_Workflows_Utilities::debug_log( 'Error updating task', 'error', array( 'task_id' => $task_id ) );
			return new WP_Error( 'update_failed', 'Failed to update task', array( 'status' => 500 ) );
		}

			WP_AI_Workflows_Utilities::debug_log(
				'Human task updated and workflow action taken',
				'info',
				array(
					'task_id' => $task_id,
					'action'  => $action,
					'result'  => 'success',
				)
			);

			return new WP_REST_Response( array( 'message' => 'Task updated and workflow action taken' ), 200 );
	}

	public function get_users( $request ) {
			$users            = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
			$users_with_roles = array_map(
				function ( $user ) {
					$user_obj = get_userdata( $user->ID );
					return array(
						'ID'           => $user->ID,
						'display_name' => $user->display_name,
						'user_login'   => $user->user_login,
						'roles'        => $user_obj->roles, // Add roles array
					);
				},
				$users
			);

			return new WP_REST_Response( $users_with_roles, 200 );
	}

	public function get_roles( $request ) {
			global $wp_roles;
			$roles = $wp_roles->get_names();
			return new WP_REST_Response( $roles, 200 );
	}

	public function get_human_tasks_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_human_tasks';

		$user_id    = get_current_user_id();
		$user       = get_userdata( $user_id );
		$user_roles = $user->roles;

		// Use separate queries to avoid complex dynamic SQL
		$user_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE assigned_user = %d AND status = 'pending'",
				$table_name,
				$user_id
			)
		);

		$role_count = 0;
		foreach ( $user_roles as $role ) {
			$role_count += $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE assigned_role = %s AND status = 'pending'",
					$table_name,
					$role
				)
			);
		}

		$count = $user_count + $role_count;

		return new WP_REST_Response( array( 'count' => (int) $count ), 200 );
	}

	public function sample_webhook( $request ) {
		$node_id    = $request['id'];
		$timeout    = 60; // 60 seconds timeout
		$start_time = time();

		while ( time() - $start_time < $timeout ) {
			$webhook_data = get_transient( 'wp_ai_workflows_webhook_sample_' . $node_id );
			if ( $webhook_data ) {
				delete_transient( 'wp_ai_workflows_webhook_sample_' . $node_id );
				$keys = $this->parse_webhook_keys( $webhook_data );
				WP_AI_Workflows_Utilities::debug_log(
					'Webhook sample received',
					'debug',
					array(
						'node_id' => $node_id,
						'keys'    => $keys,
					)
				);
				return new WP_REST_Response( array( 'keys' => $keys ), 200 );
			}
			sleep( 1 );
		}

		WP_AI_Workflows_Utilities::debug_log( 'No webhook sample received within timeout', 'warning', array( 'node_id' => $node_id ) );
		return new WP_REST_Response( array( 'message' => 'No webhook data received within the timeout period' ), 404 );
	}

	private function parse_webhook_keys( $data, $prefix = '' ) {
		$keys = array();
		foreach ( $data as $key => $value ) {
			$full_key = $prefix ? $prefix . '/' . $key : $key;
			if ( is_array( $value ) || is_object( $value ) ) {
				$keys = array_merge( $keys, $this->parse_webhook_keys( $value, $full_key ) );
			} else {
				$keys[] = array(
					'key'  => $full_key,
					'type' => $this->get_value_type( $value ),
				);
			}
		}
		return $keys;
	}

	public function test_webhook_config( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['url'] ) ) {
			return new WP_Error( 'missing_url', 'Webhook URL is required', array( 'status' => 400 ) );
		}

		$webhook_keys = isset( $params['webhookKeys'] ) ? $params['webhookKeys'] : array();

		$test_data = $this->build_webhook_test_data( $webhook_keys );

		$test_data['_meta'] = array(
			'test'      => true,
			'timestamp' => current_time( 'mysql' ),
			'source'    => 'WP AI Workflows Test',
		);

		WP_AI_Workflows_Utilities::debug_log(
			'Testing webhook',
			'debug',
			array(
				'url'        => $params['url'],
				'keys_count' => count( $webhook_keys ),
				'test_data'  => $test_data,
			)
		);

		$response = wp_remote_post(
			$params['url'],
			array(
				'body'        => wp_json_encode( $test_data ),
				'headers'     => array(
					'Content-Type'           => 'application/json',
					'X-WP-AI-Workflows-Test' => 'true',
				),
				'timeout'     => 15,
				'redirection' => 5,
				'blocking'    => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'webhook_test_failed',
				'Failed to connect to webhook: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		return new WP_REST_Response(
			array(
				'success'        => ( $response_code >= 200 && $response_code < 300 ),
				'status'         => $response_code,
				'message'        => ( $response_code >= 200 && $response_code < 300 )
					? 'Webhook test successful! Test data sent with ' . count( $webhook_keys ) . ' configured fields.'
					: 'Webhook returned non-success status: ' . $response_code,
				'response'       => substr( $response_body, 0, 1000 ), // Limit response size
				'test_data_sent' => $test_data, // Include what was sent for debugging
			),
			200
		);
	}

		/**
		 * Build test data structure based on webhook keys configuration
		 */
	private function build_webhook_test_data( $webhook_keys ) {
		$result = array();

		foreach ( $webhook_keys as $webhook_key ) {
			$key_path = $webhook_key['key'];
			$mapping  = isset( $webhook_key['mapping'] ) ? $webhook_key['mapping'] : '';

			$test_value = $this->generate_test_value( $key_path, $mapping );

			// Build nested structure from key path (e.g., "Data/Name/First" becomes nested array)
			$this->set_nested_value( $result, $key_path, $test_value );
		}

		return $result;
	}

		/**
		 * Generate appropriate test data based on field name or mapping
		 */
	private function generate_test_value( $key_path, $mapping ) {
		$key_parts  = explode( '/', $key_path );
		$field_name = strtolower( end( $key_parts ) );

		// If there's a mapping with input tags, indicate what it would contain
		if ( ! empty( $mapping ) ) {
			if ( strpos( $mapping, '[[' ) !== false || strpos( $mapping, '[Input from' ) !== false ) {
				return 'Test data for: ' . strip_tags( $mapping );
			}
			return $mapping; // Static value
		}

		switch ( $field_name ) {
			case 'email':
			case 'user_email':
			case 'mail':
				return 'test@example.com';

			case 'name':
			case 'user_name':
			case 'first':
			case 'firstname':
				return 'John';

			case 'last':
			case 'lastname':
				return 'Doe';

			case 'phone':
			case 'tel':
			case 'telephone':
				return '+1234567890';

			case 'date':
			case 'created':
			case 'timestamp':
				return current_time( 'mysql' );

			case 'id':
			case 'user_id':
				return '12345';

			case 'message':
			case 'content':
			case 'text':
			case 'body':
				return 'This is test content from WP AI Workflows webhook test.';

			case 'url':
			case 'link':
				return 'https://example.com/test';

			case 'status':
				return 'active';

			case 'amount':
			case 'price':
			case 'total':
				return '99.99';

			case 'quantity':
			case 'count':
				return '1';

			default:
				return 'Test value for ' . $field_name;
		}
	}

		/**
		 * Set nested value in array based on path like "Data/Name/First"
		 */
	private function set_nested_value( &$array, $path, $value ) {
		$keys    = explode( '/', $path );
		$current = &$array;

		foreach ( $keys as $key ) {
			if ( is_numeric( $key ) ) {
				if ( ! isset( $current[ $key ] ) ) {
					$current[ $key ] = array();
				}
				$current = &$current[ $key ];
			} else {
				if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
					$current[ $key ] = array();
				}
				$current = &$current[ $key ];
			}
		}

		$current = $value;
	}

	private function get_value_type( $value ) {
		if ( is_numeric( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		return 'string';
	}

	public function execute_firecrawl( $request ) {
		$body      = $request->get_json_params();
		$operation = isset( $body['operation'] ) ? sanitize_key( $body['operation'] ) : '';
		// New shape: { operation, params:{...} }. Fall back to the flat body for the
		// legacy scrape/crawl payload so old callers keep working.
		$params    = ( isset( $body['params'] ) && is_array( $body['params'] ) ) ? $body['params'] : $body;
		$firecrawl = new WP_AI_Workflows_Firecrawl();

		if ( '' === $operation ) {
			return new WP_Error(
				'missing_parameters',
				'Operation is required',
				array( 'status' => 400 )
			);
		}

		try {
			switch ( $operation ) {
				case 'scrape':
					$result = $firecrawl->scrape( $params );
					break;
				case 'crawl':
					$result = $firecrawl->crawl( $params );
					break;
				case 'map':
					$result = $firecrawl->map( $params );
					break;
				case 'search':
					$result = $firecrawl->search( $params );
					break;
				case 'agent':
					$result = $firecrawl->agent( $params );
					break;
				default:
					return new WP_Error(
						'invalid_operation',
						'Invalid operation specified',
						array( 'status' => 400 )
					);
			}

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'firecrawl_error',
					$result->get_error_message(),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response( $result, 200 );

		} catch ( Exception $e ) {
			return new WP_Error(
				'firecrawl_exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get_firecrawl_progress( $request ) {
		$job_id = $request->get_param( 'job_id' );

		if ( empty( $job_id ) ) {
			return new WP_Error(
				'missing_job_id',
				'Job ID is required',
				array( 'status' => 400 )
			);
		}

		try {
			$firecrawl = new WP_AI_Workflows_Firecrawl();
			$result    = $firecrawl->get_crawl_results( $job_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Format the progress response
			$response = array(
				'status'    => $result['status'] ?? 'processing',
				'progress'  => isset( $result['completed'], $result['total'] )
					? round( ( $result['completed'] / max( 1, $result['total'] ) ) * 100 )
					: 0,
				'results'   => isset( $result['pages'] ) ? array_map(
					function ( $page ) {
						return array(
							'url'           => $page['url'],
							'content'       => $page['content'],
							'contentLength' => strlen( $page['content'] ),
							'timestamp'     => date( 'c' ),
						);
					},
					$result['pages']
				) : array(),
				'completed' => $result['completed'] ?? 0,
				'total'     => $result['total'] ?? 0,
			);

			if ( isset( $result['error'] ) ) {
				$response['error'] = $result['error'];
			}

			return new WP_REST_Response( $response, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Progress check error',
				'error',
				array(
					'job_id' => $job_id,
					'error'  => $e->getMessage(),
				)
			);

			return new WP_Error(
				'progress_check_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get_firecrawl_cache( $request ) {
		$url       = $request->get_param( 'url' );
		$operation = $request->get_param( 'operation' ) ?? 'scrape';

		if ( empty( $url ) ) {
			return new WP_Error(
				'missing_url',
				'URL is required',
				array( 'status' => 400 )
			);
		}

		$firecrawl     = new WP_AI_Workflows_Firecrawl();
		$cached_result = $firecrawl->get_cached_result( $url, $operation );

		if ( ! $cached_result ) {
			return new WP_Error(
				'cache_not_found',
				'No cached data found for the specified URL',
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $cached_result, 200 );
	}

	public function upload_document( $request ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$uploadedfile     = $request->get_file_params();
		$upload_overrides = array( 'test_form' => false );
		$movefile         = wp_handle_upload( $uploadedfile['document'], $upload_overrides );

		if ( $movefile && ! isset( $movefile['error'] ) ) {
			$file_path  = $movefile['file'];
			$attachment = array(
				'post_mime_type' => $movefile['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);
			$attach_id  = wp_insert_attachment( $attachment, $file_path );

			return new WP_REST_Response(
				array(
					'success'       => true,
					'attachment_id' => $attach_id,
					'url'           => wp_get_attachment_url( $attach_id ),
				),
				200
			);
		} else {
			return new WP_Error( 'upload_error', $movefile['error'] );
		}
	}

	public function parse_uploaded_document( $request ) {
		$params          = $request->get_json_params();
		$document_url    = $params['document_url'];
		$parser_settings = $params['parser_settings'];

		$file_path = str_replace( site_url( '/' ), ABSPATH, $document_url );
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'The specified file does not exist' );
		}

		$parsed_content = WP_AI_Workflows_Parser::parse_document_with_llamaparse( $document_url, $parser_settings );

		if ( is_wp_error( $parsed_content ) ) {
			return $parsed_content;
		}

		return new WP_REST_Response(
			array(
				'success'        => true,
				'parsed_content' => $parsed_content,
			),
			200
		);
	}

	public function generate_google_redirect_uri() {
		$redirect_uri = WP_AI_Workflows_Utilities::generate_google_redirect_uri();
		return new WP_REST_Response( array( 'redirect_uri' => $redirect_uri ), 200 );
	}

	public function handle_google_auth_callback( $request ) {
		$code = $request->get_param( 'code' );
		if ( ! $code ) {
			return new WP_Error( 'invalid_callback', 'Invalid callback request', array( 'status' => 400 ) );
		}

		$tokens = $this->exchange_code_for_tokens( $code );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		WP_AI_Workflows_Utilities::update_google_tokens( $tokens['access_token'], $tokens['refresh_token'] );
		update_option( 'wp_ai_workflows_google_integrated', true );

		wp_redirect( admin_url( 'admin.php?page=wp-ai-workflows&action=settings&google_auth=success' ) );
		exit;
	}

	private function exchange_code_for_tokens( $code ) {
		$google_settings = WP_AI_Workflows_Utilities::get_google_settings();
		$client_id       = $google_settings['google_client_id'];
		$client_secret   = $google_settings['google_client_secret'];
		$redirect_uri    = $google_settings['google_redirect_uri'];

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'token_exchange_failed', 'Failed to exchange code for tokens', array( 'status' => 500 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['access_token'] ) || ! isset( $body['refresh_token'] ) ) {
			return new WP_Error( 'invalid_token_response', 'Invalid token response from Google', array( 'status' => 500 ) );
		}

		update_option(
			'wp_ai_workflows_google_token_info',
			array(
				'expires_at' => time() + ( $body['expires_in'] ?? 3600 ),
				'token_type' => $body['token_type'] ?? 'Bearer',
				'scope'      => $body['scope'] ?? '',
			)
		);

		return $body;
	}

	public function refresh_google_access_token() {
		$tokens          = WP_AI_Workflows_Utilities::get_google_tokens();
		$google_settings = WP_AI_Workflows_Utilities::get_google_settings();
		$client_id       = $google_settings['google_client_id'];
		$client_secret   = $google_settings['google_client_secret'];

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'token_refresh_failed', 'Failed to refresh access token', array( 'status' => 500 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['access_token'] ) ) {
			return new WP_Error( 'invalid_refresh_response', 'Invalid refresh token response from Google', array( 'status' => 500 ) );
		}

		WP_AI_Workflows_Utilities::update_google_tokens( $body['access_token'], $tokens['refresh_token'] );
		return $body['access_token'];
	}

	public function get_google_auth_url( $request ) {
		$nonce = $request->get_param( '_wpnonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', 'Unauthorized access', array( 'status' => 401 ) );
		}

		$google_settings = WP_AI_Workflows_Utilities::get_google_settings();
		$client_id       = $google_settings['google_client_id'];
		$redirect_uri    = $google_settings['google_redirect_uri'];

		if ( empty( $client_id ) ) {
			return new WP_Error( 'missing_client_id', 'Google Client ID is not set', array( 'status' => 400 ) );
		}

		if ( empty( $redirect_uri ) ) {
			$redirect_uri = WP_AI_Workflows_Utilities::generate_google_redirect_uri();
		}

		$scope = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets';

		$auth_url  = 'https://accounts.google.com/o/oauth2/v2/auth?';
		$auth_url .= 'client_id=' . urlencode( $client_id );
		$auth_url .= '&redirect_uri=' . urlencode( $redirect_uri );
		$auth_url .= '&response_type=code';
		$auth_url .= '&scope=' . urlencode( $scope );
		$auth_url .= '&access_type=offline';
		$auth_url .= '&prompt=consent';

		// Redirect directly rather than return a REST response.
		wp_redirect( $auth_url );
		exit;
	}

	public function get_google_integration_status() {
		$integrated = get_option( 'wp_ai_workflows_google_integrated', false );
		$settings   = WP_AI_Workflows_Utilities::get_google_settings();
		return new WP_REST_Response(
			array(
				'integrated'    => $integrated,
				'client_id'     => $settings['google_client_id'],
				'client_secret' => $settings['google_client_secret'],
			),
			200
		);
	}

	public function reset_google_integration() {
		delete_option( 'wp_ai_workflows_google_integrated' );
		delete_option( 'wp_ai_workflows_google_access_token' );
		delete_option( 'wp_ai_workflows_google_refresh_token' );
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		unset( $settings['google_client_id'] );
		unset( $settings['google_client_secret'] );
		update_option( 'wp_ai_workflows_settings', $settings );
		return new WP_REST_Response( array( 'message' => 'Google integration reset successfully' ), 200 );
	}

	private function check_google_auth_status() {
		$needs_reauth = get_transient( 'wp_ai_workflows_needs_google_reauth' );

		if ( $needs_reauth ) {
			delete_transient( 'wp_ai_workflows_needs_google_reauth' );
			throw new Exception( 'Google authorization has expired. Please re-authenticate in the settings.' );
		}

		if ( ! get_option( 'wp_ai_workflows_google_integrated', false ) ) {
			throw new Exception( 'Google integration is not set up. Please configure Google integration in the settings.' );
		}
	}

	public function get_google_drive_items() {
		try {
			$this->check_google_auth_status();

			WP_AI_Workflows_Utilities::debug_log( 'Fetching Google Drive items', 'debug' );

			$google_service = new WP_AI_Workflows_Google_Service();
			$drive_items    = $google_service->list_drive_items();

			if ( is_wp_error( $drive_items ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error fetching drive items',
					'error',
					array(
						'error' => $drive_items->get_error_message(),
					)
				);
				return $drive_items;
			}

			$formatted_items = array(
				'folders' => array_map(
					function ( $folder ) {
						return array(
							'id'   => $folder['id'],
							'name' => $folder['name'],
						);
					},
					$drive_items['folders'] ?? array()
				),
				'files'   => array_map(
					function ( $file ) {
						return array(
							'id'   => $file['id'],
							'name' => $file['name'],
						);
					},
					$drive_items['files'] ?? array()
				),
			);

			WP_AI_Workflows_Utilities::debug_log(
				'Successfully fetched drive items',
				'debug',
				array(
					'folder_count' => count( $formatted_items['folders'] ),
					'file_count'   => count( $formatted_items['files'] ),
				)
			);

			return new WP_REST_Response( $formatted_items, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in get_google_drive_items',
				'error',
				array(
					'error_message' => $e->getMessage(),
				)
			);

			if ( strpos( $e->getMessage(), 'Google authorization has expired' ) !== false ) {
				return new WP_Error(
					'google_auth_expired',
					$e->getMessage(),
					array(
						'status'          => 401,
						'requires_reauth' => true,
					)
				);
			}

			return new WP_Error( 'google_drive_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_google_sheets() {
		try {
			$this->check_google_auth_status();

			WP_AI_Workflows_Utilities::debug_log( 'Fetching Google Sheets', 'debug' );

			$google_service = new WP_AI_Workflows_Google_Service();
			$sheets         = $google_service->list_spreadsheets();

			if ( is_wp_error( $sheets ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error fetching sheets',
					'error',
					array(
						'error' => $sheets->get_error_message(),
					)
				);
				return $sheets;
			}

			$formatted_sheets = array_map(
				function ( $sheet ) {
					return array(
						'id'   => $sheet['id'],
						'name' => $sheet['name'],
						'tabs' => isset( $sheet['tabs'] ) ? $sheet['tabs'] : array(),
					);
				},
				$sheets
			);

			WP_AI_Workflows_Utilities::debug_log(
				'Successfully fetched sheets',
				'debug',
				array(
					'count' => count( $formatted_sheets ),
				)
			);

			return new WP_REST_Response( $formatted_sheets, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in get_google_sheets',
				'error',
				array(
					'error_message' => $e->getMessage(),
				)
			);

			if ( strpos( $e->getMessage(), 'Google authorization has expired' ) !== false ) {
				return new WP_Error(
					'google_auth_expired',
					$e->getMessage(),
					array(
						'status'          => 401,
						'requires_reauth' => true,
					)
				);
			}

			return new WP_Error( 'google_sheets_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_google_sheet_tabs( $request ) {
		$sheet_id       = $request->get_param( 'id' );
		$google_service = new WP_AI_Workflows_Google_Service();
		$tabs           = $google_service->get_spreadsheet_tabs( $sheet_id );

		if ( is_wp_error( $tabs ) ) {
			return new WP_Error( 'google_sheets_error', $tabs->get_error_message(), array( 'status' => 400 ) );
		}

		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		return new WP_REST_Response( $tabs, 200 );
	}

	public function get_google_sheet_columns( $request ) {
		$spreadsheet_id = $request->get_param( 'spreadsheet_id' );
		$sheet_id       = $request->get_param( 'sheet_id' );

		try {
			$google_service = new WP_AI_Workflows_Google_Service();
			$columns        = $google_service->get_sheet_columns( $spreadsheet_id, $sheet_id );

			return new WP_REST_Response( $columns, 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log( 'Error in get_google_sheet_columns: ' . $e->getMessage(), 'error' );
			return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
		}
	}

	public function get_google_drive_folders() {
		try {
			$this->check_google_auth_status();

			WP_AI_Workflows_Utilities::debug_log( 'Fetching Google Drive folders', 'debug' );

			$google_service = new WP_AI_Workflows_Google_Service();
			$folders        = $google_service->list_drive_folders();

			WP_AI_Workflows_Utilities::debug_log(
				'Successfully fetched drive folders',
				'debug',
				array(
					'count' => count( $folders ),
				)
			);

			return new WP_REST_Response( $folders, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in get_google_drive_folders',
				'error',
				array(
					'error_message' => $e->getMessage(),
				)
			);

			if ( strpos( $e->getMessage(), 'Google authorization has expired' ) !== false ) {
				return new WP_Error(
					'google_auth_expired',
					$e->getMessage(),
					array(
						'status'          => 401,
						'requires_reauth' => true,
					)
				);
			}

			return new WP_Error( 'google_drive_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_google_triggers( $request ) {
		$workflow_id     = $request->get_param( 'workflow_id' ); // Optional filter
		$google_triggers = new WP_AI_Workflows_Google_Triggers();

		if ( $workflow_id ) {
			$triggers = $google_triggers->get_triggers_for_workflow( $workflow_id );
		} else {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_google_triggers';
			$triggers   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC", $table_name ) );
		}

		return new WP_REST_Response( $triggers, 200 );
	}

	public function create_google_trigger( $request ) {
		$params = $request->get_json_params();

		$required_fields = array( 'workflow_id', 'trigger_type', 'item_id', 'polling_frequency' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $params[ $field ] ) ) {
				return new WP_Error(
					'missing_field',
					"Missing required field: $field",
					array( 'status' => 400 )
				);
			}
		}

		$google_triggers = new WP_AI_Workflows_Google_Triggers();
		$trigger_id      = $google_triggers->save_trigger( $params );

		if ( $trigger_id === false ) {
			return new WP_Error(
				'trigger_creation_failed',
				'Failed to create trigger',
				array( 'status' => 500 )
			);
		}

		$trigger = $google_triggers->get_trigger( $trigger_id );
		return new WP_REST_Response( $trigger, 201 );
	}

	public function get_google_trigger( $request ) {
		$trigger_id      = $request['id'];
		$google_triggers = new WP_AI_Workflows_Google_Triggers();
		$trigger         = $google_triggers->get_trigger( $trigger_id );

		if ( ! $trigger ) {
			return new WP_Error(
				'trigger_not_found',
				'Trigger not found',
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $trigger, 200 );
	}

	public function update_google_trigger( $request ) {
		$trigger_id = $request['id'];
		$params     = $request->get_json_params();

		$google_triggers  = new WP_AI_Workflows_Google_Triggers();
		$existing_trigger = $google_triggers->get_trigger( $trigger_id );

		if ( ! $existing_trigger ) {
			return new WP_Error(
				'trigger_not_found',
				'Trigger not found',
				array( 'status' => 404 )
			);
		}

		$params['id'] = $trigger_id;

		$result = $google_triggers->save_trigger( $params );

		if ( $result === false ) {
			return new WP_Error(
				'update_failed',
				'Failed to update trigger',
				array( 'status' => 500 )
			);
		}

		$updated_trigger = $google_triggers->get_trigger( $trigger_id );
		return new WP_REST_Response( $updated_trigger, 200 );
	}

	public function delete_google_trigger( $request ) {
		$trigger_id      = $request['id'];
		$google_triggers = new WP_AI_Workflows_Google_Triggers();

		$existing_trigger = $google_triggers->get_trigger( $trigger_id );
		if ( ! $existing_trigger ) {
			return new WP_Error(
				'trigger_not_found',
				'Trigger not found',
				array( 'status' => 404 )
			);
		}

		$result = $google_triggers->delete_trigger( $trigger_id );

		if ( $result === false ) {
			return new WP_Error(
				'delete_failed',
				'Failed to delete trigger',
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	public function handle_attachment_upload( $request ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded', array( 'status' => 400 ) );
		}

		$upload_overrides = array( 'test_form' => false );
		$movefile         = wp_handle_upload( $files['file'], $upload_overrides );

		if ( $movefile && ! isset( $movefile['error'] ) ) {
			$attachment = array(
				'post_mime_type' => $movefile['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $movefile['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $movefile['file'] );

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
			wp_update_attachment_metadata( $attach_id, $attach_data );

			return new WP_REST_Response(
				array(
					'id'  => $attach_id,
					'url' => wp_get_attachment_url( $attach_id ),
				),
				200
			);
		}

		return new WP_Error( 'upload_error', $movefile['error'], array( 'status' => 500 ) );
	}

	public function get_media_library_items( $request ) {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			'paged'          => $request->get_param( 'page' ) ?: 1,
		);

		$query = new WP_Query( $query_args );
		$items = array_map(
			function ( $post ) {
				return array(
					'id'    => $post->ID,
					'title' => $post->post_title,
					'url'   => wp_get_attachment_url( $post->ID ),
					'type'  => get_post_mime_type( $post->ID ),
				);
			},
			$query->posts
		);

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $query->found_posts,
				'pages' => $query->max_num_pages,
			),
			200
		);
	}

	public function search_unsplash( $request ) {
		$params        = $request->get_json_params();
		$search_term   = sanitize_text_field( $params['searchTerm'] );
		$orientation   = sanitize_text_field( $params['orientation'] );
		$random_result = isset( $params['randomResult'] ) ? (bool) $params['randomResult'] : false;
		$image_size    = isset( $params['imageSize'] ) ? sanitize_text_field( $params['imageSize'] ) : 'regular';

		$api_key = WP_AI_Workflows_Utilities::get_unsplash_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'unsplash_api_key_missing', 'Unsplash API key is not set', array( 'status' => 403 ) );
		}

		$query_params = array(
			'query'    => $search_term,
			'per_page' => $random_result ? 10 : 1,
		);

		if ( $orientation !== 'all' ) {
			$query_params['orientation'] = $orientation;
		}

		$url = add_query_arg( $query_params, 'https://api.unsplash.com/search/photos' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'unsplash_api_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $response_code === 429 ) {
			return new WP_Error( 'rate_limit_exceeded', 'Unsplash API rate limit exceeded', array( 'status' => 429 ) );
		}

		if ( $response_code !== 200 || empty( $body['results'] ) ) {
			return new WP_Error( 'unsplash_api_error', 'Failed to fetch image from Unsplash', array( 'status' => $response_code ) );
		}

		// Get random result if enabled, otherwise get first result
		$result = $random_result ?
			$body['results'][ array_rand( $body['results'] ) ] :
			$body['results'][0];

		// Just return the specific URL and basic info needed for preview
		return new WP_REST_Response(
			array(
				'url'   => $result['urls'][ $image_size ] ?? $result['urls']['regular'],
				'thumb' => $result['urls']['thumb'], // For preview in the node
				'id'    => $result['id'],
			),
			200
		);
	}

	public function generate_workflow( $request ) {
		try {
			$prompt = $request->get_param( 'prompt' );

			if ( empty( $prompt ) ) {
				return new WP_Error(
					'invalid_prompt',
					'Prompt cannot be empty',
					array( 'status' => 400 )
				);
			}

			// Clarify-step constraints (optional). answers is a list of
			// { question, values:[...] }; model is the chosen AI model id. Both are
			// sanitized inside the generator before they shape the build prompt.
			$answers = $request->get_param( 'answers' );
			$model   = $request->get_param( 'model' );

			$generator = new WP_AI_Workflows_Generator();
			$workflow  = $generator->generate_workflow(
				$prompt,
				is_array( $answers ) ? $answers : array(),
				is_string( $model ) ? $model : ''
			);

			return new WP_REST_Response( $workflow, 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'REST API error',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'workflow_generation_failed',
				$e->getMessage(),
				array(
					'status'        => 500,
					'error_details' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					),
				)
			);
		}
	}

	/**
	 * Clarify-first PHASE 1 endpoint: return 0–4 clarifying questions for the
	 * user's request. Deliberately NON-FATAL: if the analysis fails (transport,
	 * bad model output, missing key) we still return 200 with an empty question
	 * list plus a warning, so the UI can fall through to the model pick + build.
	 *
	 * @param WP_REST_Request $request Request with a 'prompt' param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze_workflow_request( $request ) {
		$prompt = $request->get_param( 'prompt' );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'invalid_prompt',
				'Prompt cannot be empty',
				array( 'status' => 400 )
			);
		}

		try {
			$generator = new WP_AI_Workflows_Generator();
			$questions = $generator->analyze_request( $prompt );

			return new WP_REST_Response( array( 'questions' => $questions ), 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Workflow analyze error (non-fatal, UI falls through to build)',
				'warning',
				array( 'error' => $e->getMessage() )
			);

			return new WP_REST_Response(
				array(
					'questions' => array(),
					'warning'   => $e->getMessage(),
				),
				200
			);
		}
	}

	/**
	 * Inbound handoff webhook: a helpdesk provider (Chatwoot / Zendesk Sunshine /
	 * Intercom) or an in-site operator (Native) posts a human message to relay
	 * to the visitor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_handoff_webhook( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 501 );
		}

		$provider_slug = strtolower( preg_replace( '/[^a-z]/', '', (string) $request->get_param( 'provider' ) ) );
		if ( ! in_array( $provider_slug, array( 'native', 'chatwoot', 'zendesk', 'intercom' ), true ) ) {
			return new WP_REST_Response( array( 'error' => 'unknown_provider' ), 404 );
		}

		$manager = new WP_AI_Workflows_Handoff_Manager();
		$store   = $manager->store();
		$body    = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Locate the session by the external conversation ref carried in the
		// payload. Native is an in-site operator handled separately.
		if ( 'native' !== $provider_slug ) {
			$ref = $this->extract_handoff_ref( $provider_slug, $body );
			if ( '' === $ref ) {
				return new WP_REST_Response( array( 'ignored' => true ), 202 );
			}
			$session_id = $store->session_for_external_ref( $provider_slug, $ref );
			if ( ! $session_id ) {
				return new WP_REST_Response( array( 'ignored' => true ), 202 );
			}
			$record = $store->get_record( $session_id );
			$config = $record ? $manager->config_for_workflow( $record['workflow_id'] ) : null;
			if ( ! $config ) {
				return new WP_REST_Response( array( 'ignored' => true ), 202 );
			}
			$provider = $manager->get_provider( $config );
			if ( is_wp_error( $provider ) || ! $provider->verify_webhook( $request ) ) {
				return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
			}
			$injected = $manager->handle_inbound( $provider_slug, $provider, $body );
			return new WP_REST_Response( array( 'injected' => $injected ), 200 );
		}

		// Native: an in-site operator reply. Capability + nonce gate.
		$provider = new WP_AI_Workflows_Handoff_Native( array() );
		if ( ! $provider->verify_webhook( $request ) ) {
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}
		$session_id = sanitize_text_field( isset( $body['session_id'] ) ? $body['session_id'] : '' );
		$message    = isset( $body['message'] ) ? wp_kses_post( $body['message'] ) : '';
		if ( '' === $session_id || '' === trim( $message ) ) {
			return new WP_REST_Response( array( 'error' => 'missing_params' ), 400 );
		}
		$record = $store->get_record( $session_id );
		if ( ! $record || '' === (string) $record['external_ref'] ) {
			return new WP_REST_Response( array( 'error' => 'no_handoff' ), 404 );
		}
		$injected = $manager->handle_inbound(
			'native',
			$provider,
			array(
				'message'      => $message,
				'external_ref' => (string) $record['external_ref'],
			)
		);
		return new WP_REST_Response( array( 'injected' => $injected ), 200 );
	}

	/**
	 * Extract the external conversation reference from an inbound helpdesk webhook
	 * body, so the local session can be located before authentication. This is a
	 * lookup key only — it never grants trust; verify_webhook() authenticates.
	 *
	 * @param string $provider_slug One of chatwoot|zendesk|intercom.
	 * @param array  $body          Decoded webhook body.
	 * @return string External conversation ref, or '' when not derivable.
	 */
	private function extract_handoff_ref( $provider_slug, array $body ) {
		switch ( $provider_slug ) {
			case 'chatwoot':
				$conv = isset( $body['conversation'] ) && is_array( $body['conversation'] ) ? $body['conversation'] : array();
				return isset( $conv['id'] )
					? (string) $conv['id']
					: ( isset( $body['conversation_id'] ) ? (string) $body['conversation_id'] : '' );

			case 'zendesk':
				// Sunshine: first conversation:message event's conversation id.
				$events = isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : array();
				foreach ( $events as $event ) {
					if ( is_array( $event )
						&& isset( $event['type'] ) && 'conversation:message' === $event['type']
						&& isset( $event['payload']['conversation']['id'] ) ) {
						return (string) $event['payload']['conversation']['id'];
					}
				}
				return '';

			case 'intercom':
				return isset( $body['data']['item']['id'] ) ? (string) $body['data']['item']['id'] : '';
		}
		return '';
	}

	/**
	 * Widget poll: current conversation mode + any queued human replies for a
	 * session. Session-scoped; only ever returns the supplied session's own
	 * relayed messages (never an arbitrary session's bot/user history).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_handoff_poll( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return new WP_REST_Response(
				array(
					'mode'     => 'bot',
					'messages' => array(),
				),
				200
			);
		}
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
		}
		$store = new WP_AI_Workflows_Handoff_Store();
		// approval_pending keeps the widget polling through an agent tool-approval
		// pause (the conversation stays in `bot` mode), so the resumed reply
		// streams in by itself once the owner approves.
		$approval_pending = class_exists( 'WP_AI_Workflows_Agent_Approvals' )
			&& WP_AI_Workflows_Agent_Approvals::is_pending_for_session( $session_id );
		return new WP_REST_Response(
			array(
				'mode'             => $store->get_mode( $session_id ),
				'messages'         => $store->drain_inbound( $session_id ),
				'approval_pending' => $approval_pending,
			),
			200
		);
	}

	/**
	 * Resolve a handoff (control returns to the bot). Operator-only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_handoff_resolve( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 501 );
		}
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
		}
		$manager = new WP_AI_Workflows_Handoff_Manager();
		$record  = $manager->store()->get_record( $session_id );
		$config  = $record ? $manager->config_for_workflow( $record['workflow_id'] ) : null;
		if ( ! $config ) {
			// No live config (or already bot) — just force the mode back to bot.
			$manager->store()->end( $session_id );
			return new WP_REST_Response( array( 'mode' => 'bot' ), 200 );
		}
		$result = $manager->end( $session_id, $config );
		return new WP_REST_Response( $result, 200 );
	}

	/* =====================================================================
	 * Operator inbox (Phase 3): the admin surface a human uses to handle
	 * handed-off conversations. List -> open transcript -> reply -> resolve.
	 * All operator-only (authorize_request = nonce + capability).
	 * =================================================================== */

	/**
	 * List active handed-off conversations (pending_human + human) for the
	 * operator inbox, each enriched with a last-activity timestamp, message
	 * count and a snippet of the most recent message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_handoff_inbox( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Store' ) ) {
			return new WP_REST_Response(
				array(
					'conversations' => array(),
					'pending'       => 0,
					'active'        => 0,
				),
				200
			);
		}

		global $wpdb;
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$store   = new WP_AI_Workflows_Handoff_Store();
		$records = $store->list_active();

		$conversations = array();
		$pending       = 0;
		$active        = 0;
		foreach ( $records as $record ) {
			$session_id = isset( $record['session_id'] ) ? (string) $record['session_id'] : '';
			if ( '' === $session_id ) {
				continue;
			}
			$mode = isset( $record['mode'] ) ? (string) $record['mode'] : 'bot';
			if ( 'pending_human' === $mode ) {
				++$pending;
			} elseif ( 'human' === $mode ) {
				++$active;
			}

			// Last message + count for this session (active handoffs are few).
			$last = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT content, role, created_at FROM %i WHERE session_id = %s ORDER BY id DESC LIMIT 1",
					$messages_table,
					$session_id
				),
				ARRAY_A
			);
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE session_id = %s",
					$messages_table,
					$session_id
				)
			);

			$metadata = isset( $record['metadata'] ) && is_array( $record['metadata'] ) ? $record['metadata'] : array();
			$customer = isset( $metadata['customer'] ) && is_array( $metadata['customer'] ) ? $metadata['customer'] : array();
			$visitor  = '';
			if ( isset( $customer['name'] ) && '' !== (string) $customer['name'] ) {
				$visitor = (string) $customer['name'];
			} elseif ( isset( $customer['email'] ) && '' !== (string) $customer['email'] ) {
				$visitor = (string) $customer['email'];
			}

			$snippet   = $last && isset( $last['content'] ) ? (string) $last['content'] : '';
			$last_at   = $last && isset( $last['created_at'] ) ? (string) $last['created_at'] : ( isset( $record['updated_at'] ) ? (string) $record['updated_at'] : '' );

			$conversations[] = array(
				'session_id'    => $session_id,
				'workflow_id'   => isset( $record['workflow_id'] ) ? (string) $record['workflow_id'] : '',
				'provider'      => isset( $record['provider'] ) ? (string) $record['provider'] : '',
				'mode'          => $mode,
				'visitor'       => $visitor,
				'reason'        => isset( $metadata['reason'] ) ? (string) $metadata['reason'] : '',
				'message_count' => $count,
				'last_snippet'  => mb_substr( wp_strip_all_tags( $snippet ), 0, 140 ),
				'last_role'     => $last && isset( $last['role'] ) ? (string) $last['role'] : '',
				'last_activity' => $last_at,
				'started_at'    => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
			);
		}

		// Pending tool approvals from agent conversations — the same underlying
		// human-tasks rows the Tasks page reads (a VIEW, not a forked state). The
		// inbox surfaces them alongside handoffs with inline approve/reject.
		$approvals = class_exists( 'WP_AI_Workflows_Agent_Approvals' )
			? WP_AI_Workflows_Agent_Approvals::list_pending()
			: array();

		return new WP_REST_Response(
			array(
				'conversations'     => $conversations,
				'pending'           => $pending,
				'active'            => $active,
				'approvals'         => $approvals,
				'approvals_pending' => count( $approvals ),
			),
			200
		);
	}

	/**
	 * Approve or reject a paused agent tool call from the Operator Inbox. Approving
	 * replays the LOCKED call and resumes the visitor's conversation live; rejecting
	 * injects a clean decline so the agent apologises. Delegates to the single
	 * resolver shared with the Tasks page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_agent_approval( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Agent_Approvals' ) ) {
			return new WP_Error( 'unavailable', 'Agent approvals are unavailable.', array( 'status' => 501 ) );
		}
		$task_id  = (int) $request['task_id'];
		$approved = ( 'approve' === (string) $request['decision'] );
		$result   = WP_AI_Workflows_Agent_Approvals::resolve( $task_id, $approved, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new WP_Error( 'not_agent_task', 'That task is not an agent approval.', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Pending-count for the Inbox nav badge: waiting handoffs + pending agent tool
	 * approvals. Cheap enough to poll from the admin shell.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_inbox_badge( $request ) {
		$approvals = class_exists( 'WP_AI_Workflows_Agent_Approvals' )
			? WP_AI_Workflows_Agent_Approvals::pending_count()
			: 0;
		$handoffs = 0;
		if ( class_exists( 'WP_AI_Workflows_Handoff_Store' ) ) {
			$store    = new WP_AI_Workflows_Handoff_Store();
			$handoffs = count( $store->list_active( array( WP_AI_Workflows_Handoff_Store::MODE_PENDING_HUMAN ) ) );
		}
		return new WP_REST_Response(
			array(
				'approvals' => (int) $approvals,
				'handoffs'  => (int) $handoffs,
				'total'     => (int) $approvals + (int) $handoffs,
			),
			200
		);
	}

	/**
	 * Full transcript + mode + handoff context for one conversation (operator
	 * inbox detail view). Each message is tagged so the UI can distinguish the
	 * bot from a human relay (metadata.handoff_inbound) and the visitor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handoff_conversation( $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Store' ) ) {
			return new WP_REST_Response( array( 'messages' => array() ), 200 );
		}

		global $wpdb;
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$store  = new WP_AI_Workflows_Handoff_Store();
		$record = $store->get_record( $session_id );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content, created_at, metadata FROM %i WHERE session_id = %s ORDER BY id ASC LIMIT 500",
				$messages_table,
				$session_id
			),
			ARRAY_A
		);

		$messages = array();
		foreach ( (array) $rows as $row ) {
			$role     = isset( $row['role'] ) ? (string) $row['role'] : 'user';
			$meta     = isset( $row['metadata'] ) && $row['metadata'] ? json_decode( (string) $row['metadata'], true ) : array();
			$is_human = is_array( $meta ) && ! empty( $meta['handoff_inbound'] );

			// Speaker: visitor (user) | human (operator relay) | bot (assistant).
			$speaker = 'user' === $role ? 'visitor' : ( $is_human ? 'human' : 'bot' );

			$entry = array(
				'role'       => $role,
				'speaker'    => $speaker,
				'content'    => (string) $row['content'],
				'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			);
			// Surface a shared-file descriptor so the inbox renders the file card.
			if ( is_array( $meta ) && ! empty( $meta['file'] ) && is_array( $meta['file'] ) ) {
				$entry['file'] = $meta['file'];
			}
			$messages[] = $entry;
		}

		$metadata = $record && isset( $record['metadata'] ) && is_array( $record['metadata'] ) ? $record['metadata'] : array();

		$uploads_enabled = false;
		if ( class_exists( 'WP_AI_Workflows_Chat_Uploads' ) && $record ) {
			$upl_cfg         = WP_AI_Workflows_Chat_Uploads::config_for_workflow( (string) $record['workflow_id'] );
			$uploads_enabled = $upl_cfg && ! empty( $upl_cfg['enabled'] );
		}

		return new WP_REST_Response(
			array(
				'session_id'      => $session_id,
				'mode'            => $record ? (string) $record['mode'] : 'bot',
				'provider'        => $record ? (string) $record['provider'] : '',
				'workflow_id'     => $record ? (string) $record['workflow_id'] : '',
				'uploads_enabled' => $uploads_enabled,
				'reason'      => isset( $metadata['reason'] ) ? (string) $metadata['reason'] : '',
				'summary'     => isset( $metadata['summary'] ) ? (string) $metadata['summary'] : '',
				'customer'    => isset( $metadata['customer'] ) && is_array( $metadata['customer'] ) ? $metadata['customer'] : array(),
				'messages'    => $messages,
			),
			200
		);
	}

	/**
	 * Operator reply from the inbox. Relays the message to the visitor, takes
	 * human control, and — for an external provider — best-effort echoes into
	 * the provider thread so its dashboard stays in sync.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handoff_reply( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return new WP_REST_Response( array( 'error' => 'unavailable' ), 501 );
		}
		$body       = $request->get_json_params();
		$body       = is_array( $body ) ? $body : array();
		$session_id = sanitize_text_field( isset( $body['session_id'] ) ? (string) $body['session_id'] : (string) $request->get_param( 'session_id' ) );
		$message    = isset( $body['message'] ) ? wp_kses_post( (string) $body['message'] ) : '';

		if ( '' === $session_id || '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error( 'missing_params', 'Session ID and a non-empty message are required', array( 'status' => 400 ) );
		}

		$manager = new WP_AI_Workflows_Handoff_Manager();
		$store   = $manager->store();

		// Take human control (set_mode creates a row if the operator is jumping
		// into an as-yet-untracked session, and promotes pending_human -> human).
		$store->set_mode( $session_id, WP_AI_Workflows_Handoff_Store::MODE_HUMAN );

		// Deliver to the visitor (universal, provider-agnostic path).
		$queued = $store->queue_inbound( $session_id, $message );

		// Best-effort: keep an external provider's dashboard in sync.
		$record = $store->get_record( $session_id );
		if ( $record && '' !== (string) $record['provider'] && 'native' !== (string) $record['provider'] && '' !== (string) $record['external_ref'] ) {
			$config = $manager->config_for_workflow( (string) $record['workflow_id'] );
			if ( $config ) {
				$provider = $manager->get_provider( $config );
				if ( ! is_wp_error( $provider ) && method_exists( $provider, 'send_to_human' ) ) {
					// Non-fatal: the visitor already received the reply above.
					$provider->send_to_human( (string) $record['external_ref'], $message );
				}
			}
		}

		return new WP_REST_Response(
			array(
				'ok'     => (bool) $queued,
				'mode'   => $store->get_mode( $session_id ),
				'queued' => (bool) $queued,
			),
			200
		);
	}

	/* Chat file uploads (opt-in per node). */

	/**
	 * Visitor file upload. On success the file becomes a file-message in the
	 * transcript and (in bot mode) is made available to the next AI turn.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat_upload( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Chat_Uploads' ) ) {
			return new WP_Error( 'unavailable', 'File uploads are unavailable.', array( 'status' => 501 ) );
		}

		$workflow_id = sanitize_text_field( (string) $request->get_param( 'workflow_id' ) );
		$session_id  = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $workflow_id ) {
			return new WP_Error( 'invalid_request', 'Workflow ID is required', array( 'status' => 400 ) );
		}

		// Fail-closed feature gate: the node must explicitly allow uploads.
		$config = WP_AI_Workflows_Chat_Uploads::config_for_workflow( $workflow_id );
		if ( ! $config || empty( $config['enabled'] ) ) {
			return new WP_Error( 'uploads_disabled', 'File uploads are not enabled for this chat.', array( 'status' => 403 ) );
		}

		// Abuse guard on the public route.
		if ( ! WP_AI_Workflows_Chat_Uploads::check_rate_limit() ) {
			return new WP_Error( 'rate_limited', 'Too many uploads. Please try again later.', array( 'status' => 429 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'missing_file', 'No file was uploaded.', array( 'status' => 400 ) );
		}

		// Canonicalize the session (reuse an existing one, or mint a new one that
		// belongs to this workflow) so the file-message threads correctly.
		$session    = new WP_AI_Workflows_Chat_Session( $workflow_id, '' !== $session_id ? $session_id : null );
		$session_id = $session->get_session_id();

		$stored = WP_AI_Workflows_Chat_Uploads::validate_and_store( $files['file'], $session_id, $config );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$descriptor = array(
			'name'     => $stored['name'],
			'size'     => $stored['size'],
			'type'     => $stored['type'],
			'ext'      => $stored['ext'],
			'url'      => $stored['url'],
			'is_image' => $stored['is_image'],
		);

		// Persist as a visitor file-message (metadata carries the descriptor so
		// history reloads + the operator transcript render the file card).
		$session->add_message( 'user', $stored['name'], array( 'file' => $descriptor ) );

		// Conversation mode drives what happens next.
		$mode = 'bot';
		if ( class_exists( 'WP_AI_Workflows_Handoff_Store' ) ) {
			$store = new WP_AI_Workflows_Handoff_Store();
			$mode  = $store->get_mode( $session_id );
		}

		// Bot turn: make the document usable by the AI on its next reply.
		// Human turn: no AI awareness needed — the operator reads it directly.
		if ( 'human' !== $mode && 'pending_human' !== $mode ) {
			WP_AI_Workflows_Chat_Uploads::prepare_ai_awareness( $stored, $session_id );
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'file'       => $descriptor,
				'session_id' => $session_id,
				'mode'       => $mode,
			),
			200
		);
	}

	/**
	 * Stream a token-gated shared chat file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void Emits the file and exits on success.
	 */
	public function serve_chat_file( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Chat_Uploads' ) ) {
			return new WP_Error( 'unavailable', 'File download is unavailable.', array( 'status' => 404 ) );
		}

		$token      = (string) $request->get_param( 'token' );
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		$resolved = WP_AI_Workflows_Chat_Uploads::resolve( $token, $session_id );
		if ( ! $resolved ) {
			return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
		}

		$meta = $resolved['meta'];
		$path = $resolved['path'];
		$size = (int) ( isset( $meta['size'] ) ? $meta['size'] : filesize( $path ) );
		$mime = isset( $meta['type'] ) ? (string) $meta['type'] : 'application/octet-stream';
		$name = isset( $meta['name'] ) ? sanitize_file_name( (string) $meta['name'] ) : 'download';
		$disp = ! empty( $meta['is_image'] ) ? 'inline' : 'attachment';

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . $disp . '; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Cache-Control: private, max-age=600' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Operator file upload from the inbox. Validates + stores, takes human
	 * control, and relays the file to the visitor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handoff_upload( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Chat_Uploads' ) || ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return new WP_Error( 'unavailable', 'File uploads are unavailable.', array( 'status' => 501 ) );
		}

		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'missing_file', 'No file was uploaded.', array( 'status' => 400 ) );
		}

		$manager = new WP_AI_Workflows_Handoff_Manager();
		$store   = $manager->store();
		$record  = $store->get_record( $session_id );

		// Resolve the node config for validation. Operator uploads honour the
		// same allowlist/cap; if the node has uploads off, refuse (consistent
		// with the visitor gate).
		$workflow_id = $record ? (string) $record['workflow_id'] : '';
		$config      = WP_AI_Workflows_Chat_Uploads::config_for_workflow( $workflow_id );
		if ( ! $config || empty( $config['enabled'] ) ) {
			return new WP_Error( 'uploads_disabled', 'File uploads are not enabled for this chat.', array( 'status' => 403 ) );
		}

		$stored = WP_AI_Workflows_Chat_Uploads::validate_and_store( $files['file'], $session_id, $config );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$descriptor = array(
			'name'     => $stored['name'],
			'size'     => $stored['size'],
			'type'     => $stored['type'],
			'ext'      => $stored['ext'],
			'url'      => $stored['url'],
			'is_image' => $stored['is_image'],
		);

		// Take human control and relay the file to the visitor (durable history
		// copy + live poll queue), mirroring handle_handoff_reply.
		$store->set_mode( $session_id, WP_AI_Workflows_Handoff_Store::MODE_HUMAN );
		$queued = $store->queue_inbound( $session_id, $stored['name'], '', $descriptor );

		return new WP_REST_Response(
			array(
				'ok'     => (bool) $queued,
				'file'   => $descriptor,
				'mode'   => $store->get_mode( $session_id ),
				'queued' => (bool) $queued,
			),
			200
		);
	}

	/**
	 * Clear the caller's own opt-in chat memory (privacy / session reset).
	 * Scoped to the caller: user, conversation, or visitor cookie id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function clear_chat_memory( $request ) {
		if ( ! class_exists( 'WP_AI_Workflows_Chat_Memory' ) ) {
			return new WP_REST_Response( array( 'cleared' => false ), 200 );
		}

		$scope      = sanitize_text_field( (string) $request->get_param( 'scope' ) );
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		$sk = WP_AI_Workflows_Chat_Memory::resolve_scope_key( $scope, $session_id );
		WP_AI_Workflows_Chat_Memory::clear( $sk['key'], $sk['scope'] );

		return new WP_REST_Response(
			array(
				'cleared' => true,
				'scope'   => $sk['scope'],
			),
			200
		);
	}

	public function handle_chat_message( $request ) {
		try {
			// Extract all necessary parameters
			$workflow_id    = $request->get_param( 'workflow_id' );
			$message        = $request->get_param( 'message' );
			$session_id     = $request->get_param( 'session_id' );
			$page_context   = $request->get_param( 'page_context' );
			$is_preview     = $request->get_param( 'preview' ) === true;
			$preview_config = $request->get_param( 'preview_config' );

			// Handle preview mode
			if ( $is_preview && ! empty( $preview_config ) ) {
				$chat_handler = $this->create_preview_chat_handler( $preview_config, $session_id );
			} else {
				$chat_handler = new WP_AI_Workflows_Chat_Handler( $workflow_id, $session_id );
			}

			$is_initial_message = $request->get_param( 'is_initial_message' ) === true;

			if ( empty( $workflow_id ) && ! $is_preview ) {
				return new WP_Error(
					'invalid_request',
					'Workflow ID is required',
					array( 'status' => 400 )
				);
			}

			if ( empty( $message ) ) {
				return new WP_Error(
					'invalid_request',
					'Message is required',
					array( 'status' => 400 )
				);
			}

			// Log page context if provided
			if ( ! empty( $page_context ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Received page context in chat request',
					'debug',
					array(
						'page_title'       => $page_context['page_title'] ?? 'Not provided',
						'page_type'        => $page_context['page_type'] ?? 'Not provided',
						'has_content'      => ! empty( $page_context['content_summary'] ),
						'has_product_info' => ! empty( $page_context['product_info'] ),
					)
				);
			}

			// Log if this is an initial message request
			if ( $is_initial_message ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Processing dynamic initial message request',
					'debug',
					array(
						'workflow_id'      => $workflow_id,
						'has_page_context' => ! empty( $page_context ),
						'is_preview'       => $is_preview,
					)
				);
			}

			// Handle preview mode
			if ( $is_preview ) {
				$preview_config = $request->get_param( 'preview_config' );
				if ( empty( $preview_config ) ) {
					return new WP_Error(
						'invalid_request',
						'Preview configuration is required for preview mode',
						array( 'status' => 400 )
					);
				}

				// Create temporary chat handler for preview
				$chat_handler = $this->create_preview_chat_handler( $preview_config, $session_id );
			} else {
				// Regular chat handler
				$chat_handler = new WP_AI_Workflows_Chat_Handler( $workflow_id, $session_id );
			}

			// Process the message with page context and initial message flag
			$response = $chat_handler->handle_message( $message, false, $page_context, $is_initial_message );

			// Log if citations are included
			if ( isset( $response['citations'] ) && ! empty( $response['citations'] ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Response includes citations',
					'debug',
					array(
						'citation_count' => count( $response['citations'] ),
						'session_id'     => $response['session_id'],
					)
				);
			}

			// Create the response structure
			$api_response = array(
				'type'        => $response['type'],
				'message'     => $response['display_message'],
				'action_data' => $response['type'] === 'action' ? array(
					'action_id'        => $response['action_id'],
					'confidence'       => $response['confidence'] ?? 1.0,
					'extracted_params' => $response['action_data'],
				) : null,
				'session_id'  => $chat_handler->get_session()->get_session_id(),
			);

			// Surface interactive rich-message blocks (concierge product/content
			// cards, quick replies) so they render on the LIVE reply. The chat
			// handler already persists them to message metadata for history
			// rehydration; without this line the non-streaming path dropped them
			// from the HTTP payload and cards only appeared after a page refresh.
			if ( isset( $response['blocks'] ) && is_array( $response['blocks'] ) && ! empty( $response['blocks'] ) ) {
				$api_response['blocks'] = $response['blocks'];
			}

			// Surface conversation mode (bot | pending_human | human) so the
			// widget can switch into human-relay UI and start polling for replies.
			if ( isset( $response['mode'] ) ) {
				$api_response['mode'] = $response['mode'];
			}
			if ( isset( $response['handoff'] ) ) {
				$api_response['handoff'] = $response['handoff'];
			}

			// Add citations to the response if they exist
			if ( isset( $response['citations'] ) && ! empty( $response['citations'] ) ) {
				$api_response['citations'] = $response['citations'];

				WP_AI_Workflows_Utilities::debug_log(
					'Including citations in API response',
					'debug',
					array(
						'citation_count'    => count( $response['citations'] ),
						'api_response_keys' => array_keys( $api_response ),
					)
				);
			}

			// Log successful response
			WP_AI_Workflows_Utilities::debug_log(
				'Chat response generated successfully',
				'debug',
				array(
					'response_type'      => $response['type'],
					'has_page_context'   => ! empty( $page_context ),
					'is_preview'         => $is_preview,
					'is_initial_message' => $is_initial_message,
				)
			);

			return new WP_REST_Response( $api_response, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Chat error',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'chat_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function stream_chat_message( $request ) {
		$workflow_id    = $request->get_param( 'workflow_id' );
		$message        = $request->get_param( 'message' );
		$session_id     = $request->get_param( 'session_id' );
		$page_context   = $request->get_param( 'page_context' );
		$preview        = $request->get_param( 'preview' );
		$preview_config = $request->get_param( 'preview_config' );

		try {
			// Set headers for SSE immediately
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' ); // Important for Nginx

			// Disable output buffering
			if ( ob_get_level() ) {
				ob_end_clean();
			}
			ob_implicit_flush( true );

			// Send an initial message to establish the connection
			echo 'data: ' . json_encode( array( 'content' => '' ) ) . "\n\n";
			flush();

			if ( $preview ) {
				// For preview mode
				if ( empty( $preview_config ) ) {
					echo 'data: ' . json_encode(
						array(
							'error'   => true,
							'message' => 'Preview configuration is required',
						)
					) . "\n\n";
					echo "data: [DONE]\n\n";
					exit;
				}

				// Create a preview workflow ID if not provided
				$preview_workflow_id = $preview_config['workflow_id'] ?? 'preview-' . uniqid();

				// Create handler with preview workflow ID
				$handler = new WP_AI_Workflows_Chat_Handler( $preview_workflow_id, $session_id );

				// Use reflection to set the required properties from preview config
				$reflection = new ReflectionClass( $handler );

				// Set model
				if ( isset( $preview_config['model'] ) ) {
					$property = $reflection->getProperty( 'model' );
					$property->setAccessible( true );
					$property->setValue( $handler, $preview_config['model'] );
				}

				// Set system prompt - handle both 'system_prompt' and 'systemPrompt'
				$system_prompt_value = $preview_config['system_prompt'] ??
									$preview_config['systemPrompt'] ??
									'You are a helpful assistant.';
				$property            = $reflection->getProperty( 'system_prompt' );
				$property->setAccessible( true );
				$property->setValue( $handler, $system_prompt_value );

				// Set model params - handle both 'model_params' and 'modelParams'
				$model_params_value = $preview_config['model_params'] ??
									$preview_config['modelParams'] ??
									array(
										'temperature' => 1.0,
										'max_tokens'  => 4096,
									);
				$property           = $reflection->getProperty( 'model_params' );
				$property->setAccessible( true );
				$property->setValue( $handler, $model_params_value );

				// Set actions if provided
				if ( isset( $preview_config['actions'] ) ) {
					$property = $reflection->getProperty( 'actions' );
					$property->setAccessible( true );
					$property->setValue( $handler, $preview_config['actions'] );
				}

				// Set OpenAI tools - handle both 'openai_tools' and 'openaiTools'
				$openai_tools_value = $preview_config['openai_tools'] ??
									$preview_config['openaiTools'] ??
									null;
				if ( $openai_tools_value !== null ) {
					$property = $reflection->getProperty( 'openai_tools' );
					$property->setAccessible( true );
					$property->setValue( $handler, $openai_tools_value );
				}

				// Log for debugging
				WP_AI_Workflows_Utilities::debug_log(
					'Preview streaming initialized',
					'debug',
					array(
						'model'             => $preview_config['model'] ?? 'not set',
						'session_id'        => $session_id,
						'has_system_prompt' => ! empty( $system_prompt_value ),
					)
				);

				// Handle streaming with the configured handler
				$handler->handle_streaming_message( $message, $page_context );
				exit; // Streaming already handled

			} else {
				// Regular streaming mode
				if ( empty( $workflow_id ) ) {
					echo 'data: ' . json_encode(
						array(
							'error'   => true,
							'message' => 'Workflow ID is required',
						)
					) . "\n\n";
					echo "data: [DONE]\n\n";
					exit;
				}

				$handler = new WP_AI_Workflows_Chat_Handler( $workflow_id, $session_id );

				// Handle streaming message
				$handler->handle_streaming_message( $message, $page_context );
				exit; // Streaming already handled
			}
		} catch ( Exception $e ) {
			// Log the error
			WP_AI_Workflows_Utilities::debug_log(
				'Streaming error',
				'error',
				array(
					'message' => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);

			// Handle errors in SSE format
			echo 'data: ' . json_encode(
				array(
					'error'   => true,
					'message' => $e->getMessage(),
				)
			) . "\n\n";
			echo "data: [DONE]\n\n";
			exit;
		}
	}

	public function set_preview_config( $config ) {
		$this->model         = $config['model'] ?? WP_AI_Workflows_Model_Catalog::get_default_model();
		$this->system_prompt = $config['system_prompt'] ?? '';
		$this->model_params  = $config['model_params'] ?? array(
			'temperature' => 1.0,
			'top_p'       => 1.0,
			'max_tokens'  => 4096,
		);
		$this->actions       = $config['actions'] ?? array();
		$this->openai_tools  = $config['openai_tools'] ?? null;
	}
		/**
		 * Creates a temporary chat handler for preview mode
		 *
		 * @param array $config Preview configuration
		 * @param string|null $session_id Session ID
		 * @return WP_AI_Workflows_Chat_Handler
		 */
	private function create_preview_chat_handler( $config, $session_id = null ) {
		$temp_workflow_id = 'preview-' . uniqid();

		$chat_handler = new WP_AI_Workflows_Chat_Handler( $temp_workflow_id, $session_id );

		// Use reflection to set the required properties
		$reflection = new ReflectionClass( $chat_handler );

		if ( isset( $config['model'] ) ) {
			$property = $reflection->getProperty( 'model' );
			$property->setAccessible( true );
			$property->setValue( $chat_handler, $config['model'] );
		}

		if ( isset( $config['system_prompt'] ) ) {
			$property = $reflection->getProperty( 'system_prompt' );
			$property->setAccessible( true );
			$property->setValue( $chat_handler, $config['system_prompt'] );
		}

		if ( isset( $config['model_params'] ) ) {
			$property = $reflection->getProperty( 'model_params' );
			$property->setAccessible( true );
			$property->setValue( $chat_handler, $config['model_params'] );
		}

		if ( isset( $config['actions'] ) ) {
			$property = $reflection->getProperty( 'actions' );
			$property->setAccessible( true );
			$property->setValue( $chat_handler, $config['actions'] );
		}

		return $chat_handler;
	}


	public function get_chat_history( $request ) {
		try {
			$workflow_id = $request->get_param( 'workflow_id' );
			$session_id  = $request->get_param( 'session_id' );

			if ( empty( $workflow_id ) ) {
				return new WP_Error(
					'invalid_request',
					'Workflow ID is required',
					array( 'status' => 400 )
				);
			}

			$chat_handler = new WP_AI_Workflows_Chat_Handler( $workflow_id, $session_id );
			$history      = $chat_handler->get_session()->get_history();

			return new WP_REST_Response(
				array(
					'success'    => true,
					'history'    => $history,
					'session_id' => $chat_handler->get_session()->get_session_id(),
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'chat_error',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		}
	}

	public function get_chat_config( $request ) {
		try {
			$workflow_id = $request['workflow_id'];
			WP_AI_Workflows_Utilities::debug_log(
				'Getting chat config',
				'debug',
				array(
					'workflow_id' => $workflow_id,
				)
			);

			$base_workflow_id = preg_replace( '/-[\w\d]+$/', '', $workflow_id );

			$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $base_workflow_id );

			if ( ! $workflow ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Workflow not found',
					'error',
					array(
						'workflow_id'      => $workflow_id,
						'base_workflow_id' => $base_workflow_id,
					)
				);
				return new WP_Error( 'not_found', 'Workflow not found', array( 'status' => 404 ) );
			}

			$chat_node = null;
			foreach ( $workflow['nodes'] as $node ) {
				if ( $node['type'] === 'chat' ) {
					$chat_node = $node;
					break;
				}
			}

			if ( ! $chat_node ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Chat node not found',
					'error',
					array(
						'workflow_id' => $workflow_id,
					)
				);
				return new WP_Error( 'no_chat', 'No chat configuration found', array( 'status' => 404 ) );
			}

			if ( ! isset( $chat_node['data']['behavior']['showCitations'] ) ) {
				$chat_node['data']['behavior']['showCitations'] = true;
			}

			$config = array(
				'design'       => $chat_node['data']['design'],
				'behavior'     => $chat_node['data']['behavior'],
				'model'        => $chat_node['data']['model'],
				'modelParams'  => $chat_node['data']['modelParams'],
				'systemPrompt' => $chat_node['data']['systemPrompt'],
				'openaiTools'  => isset( $chat_node['data']['openaiTools'] ) ? $chat_node['data']['openaiTools'] : null,
			);

			WP_AI_Workflows_Utilities::debug_log(
				'Chat config retrieved',
				'debug',
				array(
					'workflow_id' => $workflow_id,
					'config'      => array(
						'model'             => $config['model'],
						'has_system_prompt' => ! empty( $config['systemPrompt'] ),
						'has_openai_tools'  => ! empty( $config['openaiTools'] ),
						'show_citations'    => $config['behavior']['showCitations'],
					),
				)
			);

			return new WP_REST_Response( array( 'config' => $config ), 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error getting chat config',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}


	public function get_chat_action_result( $request ) {
		try {
			$session_id = $request->get_param( 'session_id' );

			global $wpdb;
			$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';

			$result_key    = 'wp_ai_workflows_action_result_' . $session_id;
			$action_result = get_transient( $result_key );

			if ( $action_result ) {
				delete_transient( $result_key );
				delete_transient( 'wp_ai_workflows_pending_execution_' . $session_id );

				return new WP_REST_Response(
					array(
						'success'    => true,
						'has_result' => true,
						'result'     => $action_result,
					),
					200
				);
			}

			$execution_key = 'wp_ai_workflows_pending_execution_' . $session_id;
			$pending_data  = get_transient( $execution_key );

			if ( $pending_data && isset( $pending_data['execution_id'] ) ) {
				$execution = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, status FROM %i 
                        WHERE id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
						$executions_table,
						$pending_data['execution_id']
					)
				);

				if ( $execution ) {
					$is_pending = in_array( $execution->status, array( 'processing', 'paused', null ) );

					return new WP_REST_Response(
						array(
							'success'     => true,
							'has_result'  => false,
							'has_pending' => $is_pending,
							'status'      => $execution->status,
						),
						200
					);
				}
			}

			// Check for any recent execution as fallback
			$recent_execution = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status FROM %i 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                    ORDER BY id DESC LIMIT 1",
					$executions_table
				)
			);

			if ( $recent_execution ) {
				$is_pending = in_array( $recent_execution->status, array( 'processing', 'paused', null ) );
				return new WP_REST_Response(
					array(
						'success'     => true,
						'has_result'  => false,
						'has_pending' => $is_pending,
						'status'      => $recent_execution->status,
					),
					200
				);
			}

			return new WP_REST_Response(
				array(
					'success'     => true,
					'has_result'  => false,
					'has_pending' => false,
				),
				200
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error checking action result',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Internal server error',
				),
				500
			);
		}
	}

	public function handle_chat_events( $request ) {
		$session_id = $request->get_param( 'session_id' );

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Disable buffering for Nginx

		WP_AI_Workflows_Utilities::debug_log(
			'SSE connection established',
			'debug',
			array(
				'session_id'  => $session_id,
				'server_time' => current_time( 'mysql' ),
			)
		);

		echo 'data: ' . json_encode(
			array(
				'type'       => 'connected',
				'session_id' => $session_id,
				'timestamp'  => time(),
			)
		) . "\n\n";
		flush();

		$active_sessions                = get_option( 'wp_ai_workflows_active_sse_sessions', array() );
		$active_sessions[ $session_id ] = time();
		update_option( 'wp_ai_workflows_active_sse_sessions', $active_sessions );

		$timeout        = 30 * 60; // 30 minutes timeout
		$start          = time();
		$check_interval = 1; // Check every 1 second

		while ( time() - $start < $timeout ) {
			$message_key = 'wp_ai_workflows_sse_message_' . $session_id;
			$message     = get_transient( $message_key );

			if ( $message ) {
				WP_AI_Workflows_Utilities::debug_log(
					'SSE sending message',
					'debug',
					array(
						'session_id'   => $session_id,
						'message_type' => $message['type'],
						'timestamp'    => current_time( 'mysql' ),
					)
				);

				echo 'data: ' . json_encode( $message ) . "\n\n";
				flush();

				delete_transient( $message_key );
			}

			if ( connection_aborted() ) {
				WP_AI_Workflows_Utilities::debug_log(
					'SSE connection aborted',
					'debug',
					array(
						'session_id' => $session_id,
					)
				);
				break;
			}

			// Sleep for a short time to avoid CPU overuse
			sleep( $check_interval );
		}

		$active_sessions = get_option( 'wp_ai_workflows_active_sse_sessions', array() );
		unset( $active_sessions[ $session_id ] );
		update_option( 'wp_ai_workflows_active_sse_sessions', $active_sessions );

		WP_AI_Workflows_Utilities::debug_log(
			'SSE connection closed',
			'debug',
			array(
				'session_id' => $session_id,
				'duration'   => time() - $start,
			)
		);

		exit;
	}

	public function check_action_status( $request ) {
		$session_id = $request->get_param( 'session_id' );

		WP_AI_Workflows_Utilities::debug_log(
			'Action status check request',
			'debug',
			array(
				'session_id' => $session_id,
				'endpoint'   => 'check_action_status',
			)
		);

		if ( empty( $session_id ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Missing session ID', 'error' );
			return new WP_Error( 'missing_session_id', 'Session ID is required' );
		}

		$execution_key     = 'wp_ai_workflows_pending_execution_' . $session_id;
		$pending_execution = get_transient( $execution_key );

		WP_AI_Workflows_Utilities::debug_log(
			'Checking pending execution',
			'debug',
			array(
				'execution_key' => $execution_key,
				'has_pending'   => ! empty( $pending_execution ),
			)
		);

		if ( ! $pending_execution ) {
			return new WP_REST_Response(
				array(
					'has_result' => false,
				)
			);
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Found pending execution',
			'debug',
			array(
				'execution_id' => $pending_execution['execution_id'],
				'action_id'    => $pending_execution['action_id'],
			)
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
		$execution  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, output_data FROM %i WHERE id = %d",
				$table_name,
				$pending_execution['execution_id']
			)
		);

		if ( ! $execution ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Execution not found',
				'error',
				array(
					'execution_id' => $pending_execution['execution_id'],
				)
			);
			return new WP_REST_Response(
				array(
					'has_result' => false,
					'status'     => 'not_found',
				)
			);
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Execution status retrieved',
			'debug',
			array(
				'execution_id' => $pending_execution['execution_id'],
				'status'       => $execution->status,
			)
		);

		if ( $execution->status !== 'completed' ) {
			return new WP_REST_Response(
				array(
					'has_result' => false,
					'status'     => $execution->status,
				)
			);
		}

		WP_AI_Workflows_Utilities::debug_log( 'Execution completed, generating result', 'info' );

		$output_data = json_decode( $execution->output_data, true );

		try {
			$chat_handler = new WP_AI_Workflows_Chat_Handler(
				$pending_execution['workflow_id'],
				$session_id
			);

			$result_message = $chat_handler->generate_result_message(
				array(
					'execution_id' => $pending_execution['execution_id'],
					'output_data'  => $output_data,
				)
			);

			WP_AI_Workflows_Utilities::debug_log(
				'Generated result message',
				'debug',
				array(
					'message_length' => strlen( $result_message ),
				)
			);

			$chat_handler->get_session()->add_message( 'assistant', $result_message );

			delete_transient( $execution_key );

			WP_AI_Workflows_Utilities::debug_log(
				'Returning action result',
				'info',
				array(
					'has_result'      => true,
					'message_preview' => substr( $result_message, 0, 50 ) . '...',
				)
			);

			return new WP_REST_Response(
				array(
					'has_result' => true,
					'message'    => $result_message,
					'role'       => 'assistant',
				)
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error generating result message',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return new WP_REST_Response(
				array(
					'has_result' => false,
					'status'     => 'error',
					'error'      => $e->getMessage(),
				)
			);
		}
	}

	public function get_chat_actions( $request ) {
		try {
			$workflow_id = $request['workflow_id'];

			$base_workflow_id = preg_replace( '/-[\w\d]+$/', '', $workflow_id );

			// Get workflow using DBAL
			$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $base_workflow_id );

			if ( ! $workflow ) {
				return new WP_Error( 'not_found', 'Workflow not found', array( 'status' => 404 ) );
			}

			$chat_node = null;
			foreach ( $workflow['nodes'] as $node ) {
				if ( $node['type'] === 'chat' ) {
					$chat_node = $node;
					break;
				}
			}

			if ( ! $chat_node || empty( $chat_node['data']['actions'] ) ) {
				return new WP_REST_Response( array( 'actions' => array() ), 200 );
			}

			return new WP_REST_Response(
				array(
					'actions' => $chat_node['data']['actions'],
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function handle_action_submission( $request ) {
		try {
			$workflow_id = $request->get_param( 'workflow_id' );
			$action_id   = $request->get_param( 'action_id' );
			$action_data = $request->get_param( 'action_data' );

			if ( empty( $workflow_id ) || empty( $action_id ) ) {
				return new WP_Error(
					'invalid_request',
					'Workflow ID and action ID are required',
					array( 'status' => 400 )
				);
			}

			$result = WP_AI_Workflows_Workflow::execute_workflow(
				$workflow_id,
				$action_data,
				null,
				null,
				$action_id
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new WP_REST_Response(
				array(
					'success'      => true,
					'execution_id' => $result['execution_id'] ?? null,
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_chat_logs( $request ) {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$workflows      = WP_AI_Workflows_Workflow_DBAL::get_all_workflows();
		$workflow_names = array();
		foreach ( $workflows as $workflow ) {
			$workflow_names[ $workflow['id'] ] = $workflow['name'];
		}

		$page        = $request->get_param( 'page' ) ?: 1;
		$per_page    = $request->get_param( 'per_page' ) ?: 10;
		$search      = $request->get_param( 'search' );
		$workflow_id = $request->get_param( 'workflow_id' );
		$start_date  = $request->get_param( 'start_date' );
		$end_date    = $request->get_param( 'end_date' );

		$where_clauses = array();
		$where_values  = array();

		if ( $search ) {
			$where_clauses[] = '(s.session_id LIKE %s OR m.content LIKE %s)';
			$where_values[]  = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( $workflow_id ) {
			$where_clauses[] = 's.workflow_id = %s';
			$where_values[]  = $workflow_id;
		}

		if ( $start_date ) {
			$where_clauses[] = 's.created_at >= %s';
			$where_values[]  = $start_date . ' 00:00:00';
		}

		if ( $end_date ) {
			$where_clauses[] = 's.created_at <= %s';
			$where_values[]  = $end_date . ' 23:59:59';
		}

		// Simplified count query without complex WHERE clause concatenation
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT s.session_id) FROM %i s LEFT JOIN %i m ON s.session_id = m.session_id",
				$sessions_table,
				$messages_table
			)
		);

		// Get paginated results - simplified query without complex WHERE clause concatenation
		$offset = ( $page - 1 ) * $per_page;
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
                    s.session_id,
                    s.workflow_id,
                    s.created_at,
                    s.updated_at,
                    COUNT(DISTINCT m.id) as message_count
                FROM %i s
                LEFT JOIN %i m ON s.session_id = m.session_id
                GROUP BY s.session_id, s.workflow_id, s.created_at, s.updated_at
                ORDER BY s.updated_at DESC
                LIMIT %d OFFSET %d",
				$sessions_table,
				$messages_table,
				$per_page,
				$offset
			)
		);

		foreach ( $logs as &$log ) {
			$log->workflow_name = isset( $workflow_names[ $log->workflow_id ] )
				? $workflow_names[ $log->workflow_id ]
				: 'Unknown Workflow';
		}

		WP_AI_Workflows_Utilities::debug_log( 'Chat logs fetched' );

		return new WP_REST_Response(
			array(
				'logs'  => $logs,
				'total' => (int) $total,
			),
			200
		);
	}

	public function get_chat_messages( $request ) {
		global $wpdb;
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
		$session_id     = $request->get_param( 'session_id' );

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE session_id = %s ORDER BY created_at ASC",
				$messages_table,
				$session_id
			)
		);

		return new WP_REST_Response( $messages, 200 );
	}

	public function get_chat_statistics( $request ) {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'wp_ai_workflows_chat_sessions';
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		$total_sessions = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $sessions_table ) );

		$active_today = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT session_id) FROM %i WHERE DATE(updated_at) = %s",
				$sessions_table,
				current_time( 'Y-m-d' )
			)
		);

		$message_stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    COUNT(*) as total_messages,
                    COUNT(*) / COUNT(DISTINCT session_id) as avg_messages_per_chat
                FROM %i",
				$messages_table
			)
		);

		return new WP_REST_Response(
			array(
				'totalSessions'          => (int) $total_sessions,
				'activeToday'            => (int) $active_today,
				'totalMessages'          => (int) $message_stats->total_messages,
				'averageMessagesPerChat' => round( $message_stats->avg_messages_per_chat, 1 ),
			),
			200
		);
	}

	public function preview_rss_feed( $request ) {
		$feed_url = $request->get_param( 'feedUrl' );

		if ( empty( $feed_url ) ) {
			return new WP_Error( 'invalid_url', 'Feed URL is required' );
		}

		include_once ABSPATH . WPINC . '/feed.php';
		$rss = fetch_feed( $feed_url );

		if ( is_wp_error( $rss ) ) {
			return new WP_Error( 'feed_error', $rss->get_error_message() );
		}

		$items = $rss->get_items( 0, 5 ); // Get first 5 items for preview

		$preview_data = array_map(
			function ( $item ) {
				return array(
					'title'      => $item->get_title(),
					'categories' => array_map(
						function ( $cat ) {
							return $cat->get_label();
						},
						$item->get_categories() ?: array()
					),
				);
			},
			$items
		);

		return new WP_REST_Response(
			array(
				'items' => $preview_data,
			),
			200
		);
	}

	public function handle_test_api_call( $request ) {
		try {
			$params = $request->get_params();

			if ( empty( $params['method'] ) || empty( $params['url'] ) ) {
				throw new Exception( 'Method and URL are required' );
			}

			if ( ! wp_http_validate_url( $params['url'] ) ) {
				throw new Exception( 'Invalid URL format' );
			}

			$valid_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' );
			$method        = strtoupper( sanitize_text_field( $params['method'] ) );
			if ( ! in_array( $method, $valid_methods ) ) {
				throw new Exception( 'Invalid HTTP method' );
			}

			// Process headers
			$headers = array();
			if ( ! empty( $params['headers'] ) && is_array( $params['headers'] ) ) {
				foreach ( $params['headers'] as $key => $value ) {
					if ( isset( $value['name'] ) && isset( $value['value'] ) ) {
						$header_name = str_replace( ' ', '-', trim( $value['name'] ) );
						$headers[]   = array(
							'name'  => $header_name,
							'value' => trim( $value['value'] ),
						);
					} else {
						$header_name = str_replace( ' ', '-', trim( $key ) );
						$headers[]   = array(
							'name'  => $header_name,
							'value' => trim( $value ),
						);
					}
				}
			}

			// Process query parameters
			$query_params = array();
			if ( ! empty( $params['queryParams'] ) && is_array( $params['queryParams'] ) ) {
				foreach ( $params['queryParams'] as $param ) {
					if ( ! empty( $param['key'] ) ) {
						$query_params[] = array(
							'key'   => sanitize_text_field( $param['key'] ),
							'value' => sanitize_text_field( $param['value'] ?? '' ),
						);
					}
				}
			}

			// Process body
			$body = null;
			if ( ! empty( $params['body'] ) ) {
				$is_json = false;
				foreach ( $headers as $header ) {
					if ( strtolower( $header['name'] ) === 'content-type' &&
						strpos( strtolower( $header['value'] ), 'application/json' ) !== false ) {
						$is_json = true;
						break;
					}
				}

				if ( $is_json ) {
					if ( is_string( $params['body'] ) ) {
						$decoded = json_decode( $params['body'], true );
						if ( json_last_error() !== JSON_ERROR_NONE ) {
							throw new Exception( 'Invalid JSON in request body: ' . json_last_error_msg() );
						}
						$body = $params['body'];
					} elseif ( is_array( $params['body'] ) ) {
						$body = wp_json_encode( $params['body'] );
						if ( $body === false ) {
							throw new Exception( 'Failed to encode body as JSON' );
						}
					} else {
						throw new Exception( 'Invalid body format for JSON content-type' );
					}
				} else {
					$body = is_array( $params['body'] ) ? wp_json_encode( $params['body'] ) : $params['body'];
				}
			}

			// Add default content type if needed
			$has_content_type = false;
			foreach ( $headers as $header ) {
				if ( strtolower( $header['name'] ) === 'content-type' ) {
					$has_content_type = true;
					break;
				}
			}
			if ( ! $has_content_type && $body !== null ) {
				$headers[] = array(
					'name'  => 'Content-Type',
					'value' => 'application/json',
				);
			}

			// Process authentication
			if ( ! empty( $params['auth'] ) && $params['auth']['type'] !== 'none' ) {
				$test_node_auth = array(
					'type'       => sanitize_text_field( $params['auth']['type'] ),
					'username'   => isset( $params['auth']['username'] ) ? sanitize_text_field( $params['auth']['username'] ) : '',
					'password'   => isset( $params['auth']['password'] ) ? sanitize_text_field( $params['auth']['password'] ) : '',
					'token'      => isset( $params['auth']['token'] ) ? sanitize_text_field( $params['auth']['token'] ) : '',
					'apiKey'     => isset( $params['auth']['apiKey'] ) ? sanitize_text_field( $params['auth']['apiKey'] ) : '',
					'apiKeyName' => isset( $params['auth']['apiKeyName'] ) ? sanitize_text_field( $params['auth']['apiKeyName'] ) : '',
				);

				switch ( $params['auth']['type'] ) {
					case 'basic':
						if ( ! empty( $test_node_auth['username'] ) && ! empty( $test_node_auth['password'] ) ) {
							$headers[]                  = array(
								'name'  => 'Authorization',
								'value' => 'Basic ' . base64_encode( $test_node_auth['username'] . ':' . $test_node_auth['password'] ),
							);
							$test_node_auth['username'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt( $test_node_auth['username'] );
							$test_node_auth['password'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt( $test_node_auth['password'] );
						}
						break;
					case 'bearer':
						if ( ! empty( $test_node_auth['token'] ) ) {
							$headers[]               = array(
								'name'  => 'Authorization',
								'value' => 'Bearer ' . $test_node_auth['token'],
							);
							$test_node_auth['token'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt( $test_node_auth['token'] );
						}
						break;
					case 'apiKey':
						if ( ! empty( $test_node_auth['apiKey'] ) && ! empty( $test_node_auth['apiKeyName'] ) ) {
							$headers[]                = array(
								'name'  => $test_node_auth['apiKeyName'],
								'value' => $test_node_auth['apiKey'],
							);
							$test_node_auth['apiKey'] = 'enc_' . WP_AI_Workflows_Encryption::encrypt( $test_node_auth['apiKey'] );
						}
						break;
				}
			} else {
				$test_node_auth = array( 'type' => 'none' );
			}

			// Build test node
			$test_node = array(
				'id'   => 'test-' . uniqid(),
				'type' => 'apiCall',
				'data' => array(
					'method'         => $method,
					'url'            => esc_url_raw( $params['url'] ),
					'headers'        => $headers,
					'queryParams'    => $query_params,
					'body'           => $body,
					'auth'           => $test_node_auth,
					'responseConfig' => array(
						'timeout'       => isset( $params['responseConfig']['timeout'] )
							? max( 1000, min( 300000, intval( $params['responseConfig']['timeout'] ) ) )
							: 30000,
						'retryCount'    => isset( $params['responseConfig']['retryCount'] )
							? max( 0, min( 5, intval( $params['responseConfig']['retryCount'] ) ) )
							: 0,
						'jsonPath'      => isset( $params['responseConfig']['jsonPath'] )
							? sanitize_text_field( $params['responseConfig']['jsonPath'] )
							: '',
						'cacheResponse' => false,
					),
				),
			);

			// Execute test
			$result = WP_AI_Workflows_Node_Execution::execute_api_call_node(
				$test_node,
				array(),
				'test-' . uniqid()
			);

			// Process result
			if ( isset( $result['type'] ) && $result['type'] === 'error' ) {
				throw new Exception( $result['content'] );
			}

			// Extract data based on pattern if specified
			$extracted_data = null;
			if ( ! empty( $test_node['data']['responseConfig']['jsonPath'] ) &&
				isset( $result['content']['data'] ) ) {

				try {
					$response_data = $result['content']['data'];

					// If response_data is a JSON string, decode it
					if ( is_string( $response_data ) ) {
						$decoded = json_decode( $response_data, true );
						if ( json_last_error() === JSON_ERROR_NONE ) {
							$response_data = $decoded;
						}
					}

					// Function to handle nested value extraction
					$extract_specific_values = function ( $array, $path ) {
						// If path is empty, return empty result
						if ( empty( $path ) ) {
							return array();
						}

						// Handle numeric index access first, before any other processing
						if ( preg_match( '/^(\d+)\.(.+)$/', $path, $matches ) ) {
							$index = intval( $matches[1] );
							$field = $matches[2];

							// Only process if the index exists
							if ( ! isset( $array[ $index ] ) ) {
								return array();
							}

							$item = $array[ $index ];

							// For direct field access, return immediately
							if ( ! str_contains( $field, '.' ) ) {
								return isset( $item[ $field ] ) ? array( $item[ $field ] ) : array();
							}

							// For nested fields, traverse the path
							$current_value = $item;
							$field_parts   = explode( '.', $field );

							foreach ( $field_parts as $part ) {
								if ( ! is_array( $current_value ) || ! isset( $current_value[ $part ] ) ) {
									return array();
								}
								$current_value = $current_value[ $part ];
							}

							return array( $current_value );
						}

						// Function for recursive value extraction (only used for non-numeric paths)
						$extract_values = function ( $array, $key ) use ( &$extract_values ) {
							$results = array();
							foreach ( $array as $k => $v ) {
								if ( $k === $key ) {
									$results[] = $v;
								} elseif ( is_array( $v ) ) {
									$results = array_merge( $results, $extract_values( $v, $key ) );
								}
							}
							return $results;
						};

							// Split path into parts
							$parts = explode( '.', $path );

							// If first part is an array key, get that array first
						if ( isset( $array[ $parts[0] ] ) && is_array( $array[ $parts[0] ] ) ) {
							$array = $array[ $parts[0] ];
							array_shift( $parts );
							$path = implode( '.', $parts );
						}

							// Case 1: Simple field name (e.g., "email")
						if ( ! str_contains( $path, '.' ) ) {
							return $extract_values( $array, $path );
						}

							// Case 2: Field equality condition (e.g., "id=187.email" or 'email="test@test.com".id')
						if ( preg_match( '/^(\w+)=([^\.]+)\.(.+)$/', $path, $matches ) ) {
							$filterField = $matches[1];
							$filterValue = trim( $matches[2], '"\'' );
							$targetField = $matches[3];

							$results = array();
							foreach ( $array as $item ) {
								if ( isset( $item[ $filterField ] ) &&
									(string) $item[ $filterField ] === (string) $filterValue &&
									isset( $item[ $targetField ] ) ) {
									$results[] = $item[ $targetField ];
								}
							}
							return $results;
						}

							// Fallback to simple field extraction
							return $extract_values( $array, $path );
					};

					if ( is_array( $response_data ) ) {
						$extracted_data = $extract_specific_values( $response_data, $test_node['data']['responseConfig']['jsonPath'] );

						if ( is_array( $extracted_data ) && count( $extracted_data ) === 1 ) {
							$extracted_data = reset( $extracted_data );
						}

						WP_AI_Workflows_Utilities::debug_log(
							'Test value extraction completed',
							'debug',
							array(
								'pattern'            => $test_node['data']['responseConfig']['jsonPath'],
								'extracted_count'    => count( $extracted_data ),
								'results'            => $extracted_data,
								'response_structure' => array_keys( $response_data ),
							)
						);
					}
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Test value extraction failed',
						'error',
						array(
							'error' => $e->getMessage(),
							'path'  => $test_node['data']['responseConfig']['jsonPath'],
						)
					);
				}
			}

			// Return response
			return new WP_REST_Response(
				array(
					'success'       => true,
					'status'        => $result['content']['status'] ?? 200,
					'headers'       => $result['content']['headers'] ?? array(),
					'data'          => $result['content']['data'] ?? null,
					'extractedData' => $extracted_data,
					'raw_response'  => $result['content'],
				),
				200
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'API test failed',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => $e->getMessage(),
				),
				400
			);
		}
	}

	private function get_content_type_from_headers( $headers ) {
		foreach ( $headers as $header ) {
			if ( strtolower( $header['name'] ) === 'content-type' ) {
				return $header['value'];
			}
		}
		return null;
	}

	public function get_task_roles() {
		$task_roles = get_option( 'wp_ai_workflows_task_roles', array( 'administrator' ) );
		return new WP_REST_Response( $task_roles, 200 );
	}

	public function update_task_roles( $request ) {
		$roles = $request->get_json_params();

		if ( ! is_array( $roles ) ) {
			return new WP_Error( 'invalid_roles', 'Roles must be an array', array( 'status' => 400 ) );
		}

		$roles = array_map( 'sanitize_text_field', $roles );

		global $wp_roles;

		foreach ( $wp_roles->roles as $role_name => $role ) {
			$role_object = get_role( $role_name );
			if ( $role_object ) {
				$role_object->remove_cap( 'manage_workflow_tasks' );
			}
		}

		foreach ( $roles as $role_name ) {
			$role_object = get_role( $role_name );
			if ( $role_object ) {
				$role_object->add_cap( 'manage_workflow_tasks' );
			}
		}

		update_option( 'wp_ai_workflows_task_roles', $roles );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}




	/**
	 * Get cost statistics for the specified timeframe
	 */
	public static function get_cost_statistics( $request ) {
		try {
			$timeframe  = $request->get_param( 'timeframe' ) ?? '30days';
			$start_date = $request->get_param( 'start_date' );
			$end_date   = $request->get_param( 'end_date' );

			if ( ! $start_date || ! $end_date ) {
				$end_date   = current_time( 'mysql' );
				$start_date = match ( $timeframe ) {
					'7days' => date( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
					'30days' => date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
					'90days' => date( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
					'year' => date( 'Y-m-d H:i:s', strtotime( '-1 year' ) ),
					default => date( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
				};
			}

			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();

			$stats = $cost_manager->get_cost_statistics( null, $start_date, $end_date );

			$overview = array(
				'total_cost'   => array_sum( array_column( $stats, 'total_cost' ) ),
				'total_calls'  => array_sum( array_column( $stats, 'total_executions' ) ),
				'total_tokens' => array_sum( array_column( $stats, 'total_prompt_tokens' ) ) +
								array_sum( array_column( $stats, 'total_completion_tokens' ) ),
			);

			$daily_costs = $cost_manager->get_daily_costs( $start_date, $end_date );

			$pricing = $cost_manager->get_cost_settings();

			return new WP_REST_Response(
				array(
					'overview'    => $overview,
					'model_stats' => $stats,
					'daily_costs' => $daily_costs,
					'pricing'     => $pricing,
					'timeframe'   => array(
						'start' => $start_date,
						'end'   => $end_date,
					),
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'cost_statistics_error',
				'Failed to retrieve cost statistics: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get cost settings
	 */
	public function get_cost_settings( $request ) {
		try {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$settings     = $cost_manager->get_cost_settings();

			return new WP_REST_Response( $settings, 200 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'cost_settings_error',
				'Failed to retrieve cost settings: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update cost settings
	 */
	public function update_cost_settings( $request ) {
		try {
			$costs = $request->get_json_params();

			WP_AI_Workflows_Utilities::debug_log(
				'Received cost update request',
				'debug',
				array(
					'raw_costs' => $costs,
				)
			);

			// Ensure we're working with the correct array format
			if ( ! is_array( $costs ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Invalid data format',
					'error',
					array(
						'received' => $costs,
					)
				);
				return new WP_Error(
					'invalid_request',
					'Invalid cost settings format. Expected array of costs.',
					array( 'status' => 400 )
				);
			}

			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$updated      = 0;

			foreach ( $costs as $cost ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Processing cost entry',
					'debug',
					array(
						'cost_entry' => $cost,
					)
				);

				if ( ! isset( $cost['provider'] ) || ! isset( $cost['model'] ) ||
					! isset( $cost['input_cost'] ) || ! isset( $cost['output_cost'] ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Missing required fields',
						'warning',
						array(
							'cost'    => $cost,
							'missing' => array_diff(
								array( 'provider', 'model', 'input_cost', 'output_cost' ),
								array_keys( $cost )
							),
						)
					);
					continue;
				}

				$result = $cost_manager->update_cost_setting(
					$cost['provider'],
					$cost['model'],
					floatval( $cost['input_cost'] ),
					floatval( $cost['output_cost'] )
				);

				if ( $result ) {
					++$updated;
					WP_AI_Workflows_Utilities::debug_log(
						'Cost setting updated successfully',
						'info',
						array(
							'provider' => $cost['provider'],
							'model'    => $cost['model'],
						)
					);
				}
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Cost settings update complete',
				'info',
				array(
					'updated_count'   => $updated,
					'total_attempted' => count( $costs ),
				)
			);

			if ( $updated === 0 ) {
				return new WP_Error(
					'update_failed',
					'No cost settings were updated. Please check the provided values.',
					array( 'status' => 400 )
				);
			}

			return new WP_REST_Response(
				array(
					'message'       => sprintf( '%d cost settings updated successfully', $updated ),
					'updated_count' => $updated,
				),
				200
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error updating cost settings',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_Error(
				'cost_settings_error',
				'Failed to update cost settings: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}


	public function create_assistant_session( $request ) {
		try {
			$workflow_id      = $request->get_param( 'workflow_id' );
			$workflow_context = $request->get_param( 'workflow_context' );

			WP_AI_Workflows_Utilities::debug_log(
				'Creating assistant session',
				'debug',
				array(
					'workflow_id' => $workflow_id,
					'has_context' => ! empty( $workflow_context ),
				)
			);

			$chat       = new WP_AI_Workflows_Assistant_Chat();
			$session_id = $chat->start_session( $workflow_id );

			if ( $workflow_context ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Updating initial context',
					'debug',
					array(
						'session_id' => $session_id,
					)
				);
				$chat->update_workflow_context( $workflow_context );
			}

			$request->set_param( 'chat_instance', $chat );

			return new WP_REST_Response(
				array(
					'session_id' => $session_id,
					'success'    => true,
				),
				200
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error creating assistant session',
				'error',
				array(
					'error_message' => $e->getMessage(),
					'trace'         => $e->getTraceAsString(),
				)
			);
			return new WP_Error( 'chat_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function send_assistant_message( $request ) {
		try {
			$session_id  = $request->get_param( 'session_id' );
			$content     = $request->get_param( 'content' );
			$mode        = $request->get_param( 'mode' );
			$workflow_id = $this->get_workflow_id_from_session( $session_id );

			$chat = new WP_AI_Workflows_Assistant_Chat( $workflow_id, $session_id );

			// If mode is provided in the request, make sure it's set before sending
			if ( $mode ) {
				$chat->update_mode( $mode );
			}

			$response = $chat->send_message( $content );

			return new WP_REST_Response(
				array(
					'success'   => true,
					'content'   => $response,
					'timestamp' => current_time( 'mysql' ),
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'chat_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function update_assistant_context( $request ) {
		try {
			$session_id       = $request->get_param( 'session_id' );
			$workflow_context = $request->get_param( 'workflow_context' );
			$selected_node    = $request->get_param( 'selected_node' );

			$workflow_id = $this->get_workflow_id_from_session( $session_id );
			$chat        = new WP_AI_Workflows_Assistant_Chat( $workflow_id, $session_id );

			$chat->update_workflow_context( $workflow_context );

			if ( $selected_node !== null ) {
				$chat->update_selected_node( $selected_node );
			}

			return new WP_REST_Response(
				array(
					'success'    => true,
					'session_id' => $session_id,
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'context_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	private function get_workflow_id_from_session( $session_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		$workflow_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT workflow_id FROM %i WHERE session_id = %s",
				$table_name,
				$session_id
			)
		);

		if ( ! $workflow_id ) {
			throw new Exception( 'Invalid session ID' );
		}

		return $workflow_id;
	}

	public function update_mode( $request ) {
		try {
			$params     = $request->get_params();
			$session_id = sanitize_text_field( $params['session_id'] );
			$mode       = sanitize_text_field( $params['mode'] );

			// Get workflow ID first, just like other endpoints do
			$workflow_id = $this->get_workflow_id_from_session( $session_id );
			$assistant   = new WP_AI_Workflows_Assistant_Chat( $workflow_id, $session_id );
			$result      = $assistant->update_mode( $mode );

			return new WP_REST_Response(
				array(
					'success' => true,
					'mode'    => $mode,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $e->getMessage(),
				),
				500
			);
		}
	}

	public function apply_workflow_changes( $request ) {
		try {
			$session_id = $request->get_param( 'session_id' );
			$changes    = $request->get_param( 'changes' );

			if ( ! $session_id || ! $changes ) {
				throw new Exception( 'Missing required parameters' );
			}

			$workflow_id = $this->get_workflow_id_from_session( $session_id );
			$chat        = new WP_AI_Workflows_Assistant_Chat( $workflow_id, $session_id );

			$updated_workflow = $chat->apply_workflow_changes( $changes );

			WP_AI_Workflows_Utilities::debug_log(
				'Applied workflow changes',
				'debug',
				array(
					'workflow_id' => $workflow_id,
					'changes'     => $changes,
				)
			);

			return new WP_REST_Response(
				array(
					'success'  => true,
					'workflow' => $updated_workflow,
				),
				200
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error applying workflow changes',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'workflow_update_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get_assistant_session( $request ) {
		try {
			$workflow_id = $request->get_param( 'workflow_id' );

			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

			$session = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT session_id FROM %i 
                 WHERE workflow_id = %s 
                 ORDER BY updated_at DESC 
                 LIMIT 1",
					$table_name,
					$workflow_id
				)
			);

			if ( $session ) {
				return new WP_REST_Response(
					array(
						'success'        => true,
						'session_exists' => true,
						'session_id'     => $session->session_id,
					),
					200
				);
			} else {
				return new WP_REST_Response(
					array(
						'success'        => true,
						'session_exists' => false,
					),
					200
				);
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'session_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function save_approval_metadata( $request ) {
		try {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

			$params   = $request->get_params();
			$metadata = array(
				'changes'        => $params['changes'],
				'approvalStatus' => $params['status'],
				'messageId'      => $params['message_id'],
			);

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT message_id FROM %i 
                WHERE session_id = %s AND JSON_EXTRACT(metadata, '$.messageId') = %s",
					$table_name,
					$params['session_id'],
					$params['message_id']
				)
			);

			if ( ! $existing ) {
				// Create new message
				$wpdb->insert(
					$table_name,
					array(
						'session_id' => $params['session_id'],
						'role'       => 'assistant',
						'content'    => 'workflow-changes-approval',
						'metadata'   => json_encode( $metadata ),
						'created_at' => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s' )
				);
			}

			return new WP_REST_Response( array( 'success' => true ), 200 );

		} catch ( Exception $e ) {
			return new WP_Error( 'save_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function update_approval_status( $request ) {
		try {
			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';
			$params     = $request->get_params();

			// Update the metadata JSON
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i 
                SET metadata = JSON_SET(metadata, '$.approvalStatus', %s)
                WHERE session_id = %s 
                AND JSON_EXTRACT(metadata, '$.messageId') = %s",
					$table_name,
					$params['status'],
					$params['session_id'],
					$params['message_id']
				)
			);

			if ( $result === false ) {
				throw new Exception( 'Failed to update approval status' );
			}

			return new WP_REST_Response( array( 'success' => true ), 200 );

		} catch ( Exception $e ) {
			return new WP_Error( 'update_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_assistant_history( $request ) {
		try {
			$session_id  = $request->get_param( 'session_id' );
			$workflow_id = $this->get_workflow_id_from_session( $session_id );

			$chat     = new WP_AI_Workflows_Assistant_Chat( $workflow_id, $session_id );
			$messages = $chat->get_chat_history();

			$formatted_messages = array();
			foreach ( $messages as $msg ) {
				$formatted = array(
					'id'      => $msg->message_id,
					'role'    => $msg->role,
					'content' => $msg->content,
				);

				if ( ! empty( $msg->metadata ) ) {
					$formatted['metadata'] = $msg->metadata;
				}

				$formatted_messages[] = $formatted;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
			$mode       = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT mode FROM %i WHERE session_id = %s",
					$table_name,
					$session_id
				)
			);

			return new WP_REST_Response(
				array(
					'success'  => true,
					'messages' => $formatted_messages,
					'mode'     => $mode,
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'history_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	* Handler for system requirements endpoint
	*/
	public function get_system_requirements() {
		try {
			global $wpdb;
			$wp_version  = get_bloginfo( 'version' );
			$php_version = phpversion();
			$db_version  = $wpdb->db_version();
			$is_mariadb  = $wpdb->get_var( $wpdb->prepare( "SELECT VERSION() LIKE %s", '%MariaDB%' ) );
			$db_type     = $is_mariadb ? 'MariaDB' : 'MySQL';

			$max_execution_time = ini_get( 'max_execution_time' );

			$memory_limit = ini_get( 'memory_limit' );
			if ( preg_match( '/^(\d+)(.)$/', $memory_limit, $matches ) ) {
				if ( $matches[2] == 'G' ) {
					$memory_limit = $matches[1] * 1024;
				} elseif ( $matches[2] == 'M' ) {
					$memory_limit = $matches[1];
				} elseif ( $matches[2] == 'K' ) {
					$memory_limit = $matches[1] / 1024;
				}
			}

			$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
			$cron_events   = _get_cron_array();

			$overdue_count       = 0;
			$max_overdue_minutes = 0;

			if ( is_array( $cron_events ) ) {
				$current_time = time();
				foreach ( $cron_events as $timestamp => $hooks ) {
					if ( $timestamp < $current_time ) {
						$overdue_count      += count( $hooks );
						$minutes_overdue     = ( $current_time - $timestamp ) / 60;
						$max_overdue_minutes = max( $max_overdue_minutes, $minutes_overdue );
					}
				}
			}

			$rest_enabled = get_option( 'permalink_structure' ) !== '';
			$rest_url     = get_rest_url();

			$rest_api_test_results = $this->check_rest_availability( $rest_url );
			$rest_accessible       = $rest_api_test_results['test_endpoint_accessible'];
			$specific_issue        = $rest_api_test_results['specific_issue'];
			$status_code           = $rest_api_test_results['status_code'];

			$rest_recommendations = $this->get_rest_api_recommendations( $rest_api_test_results );

			$memory_limit_formatted = $memory_limit . 'MB';
			if ( $memory_limit >= 1024 ) {
				$memory_limit_formatted = round( $memory_limit / 1024, 1 ) . 'GB';
			}

			$items = array(
				array(
					'title'    => 'WordPress Version',
					'value'    => $wp_version,
					'required' => '6.0+',
					'status'   => version_compare( $wp_version, '6.0', '>=' ) ? 'success' : 'error',
					'message'  => version_compare( $wp_version, '6.0', '>=' )
						? 'Your WordPress version is compatible with AI Workflow Automation.'
						: 'AI Workflow Automation requires WordPress 6.0 or higher. Please update your WordPress installation.',
				),
				array(
					'title'    => 'PHP Version',
					'value'    => $php_version,
					'required' => '8.0+',
					'status'   => version_compare( $php_version, '8.0', '>=' ) ? 'success' : 'error',
					'message'  => version_compare( $php_version, '8.0', '>=' )
						? 'Your PHP version is compatible with AI Workflow Automation.'
						: 'AI Workflow Automation requires PHP 8.0 or higher. Please contact your hosting provider to update PHP.',
				),
				array(
					'title'    => $db_type . ' Version',
					'value'    => $wpdb->db_version(),
					'required' => $is_mariadb ? 'MariaDB 10.5+' : 'MySQL 8.0+',
					'status'   => $is_mariadb
						? ( version_compare( $db_version, '10.5', '>=' ) ? 'success' : 'error' )
						: ( version_compare( $db_version, '8.0', '>=' ) ? 'success' : 'error' ),
					'message'  => $is_mariadb
						? ( version_compare( $db_version, '10.5', '>=' )
							? 'Your MariaDB version is compatible with AI Workflow Automation.'
							: 'AI Workflow Automation requires MariaDB 10.5 or higher. Older versions may cause issues with cost management, analytics features, and complex workflows. Please contact your hosting provider to update.' )
						: ( version_compare( $db_version, '8.0', '>=' )
							? 'Your MySQL version is compatible with AI Workflow Automation.'
							: 'AI Workflow Automation requires MySQL 8.0 or higher. Older versions may cause issues with cost management, analytics features, and complex workflows. Please contact your hosting provider to update.' ),
				),
				array(
					'title'    => 'Max Execution Time',
					'value'    => $max_execution_time . ' seconds',
					'required' => '600 seconds (recommended)',
					'status'   => $max_execution_time >= 600 || $max_execution_time == 0 ? 'success' :
								( $max_execution_time >= 300 ? 'warning' : 'warning' ),
					'message'  => $max_execution_time >= 600 || $max_execution_time == 0
						? 'Your max execution time is sufficient for complex workflows.'
						: ( $max_execution_time >= 300
							? 'Your max execution time may be sufficient for most workflows, but complex operations might timeout. Consider increasing it to 600 seconds.'
							: 'Your max execution time is too low for complex workflows. We recommend increasing to at least 600 seconds in your php.ini or contact your hosting provider.' ),
				),
				array(
					'title'    => 'Memory Limit',
					'value'    => $memory_limit_formatted,
					'required' => '512MB',
					'status'   => $memory_limit >= 512 ? 'success' : 'error',
					'message'  => $memory_limit >= 512
						? 'Your memory limit is sufficient for AI Workflow Automation.'
						: 'Memory limit is too low. AI Workflow Automation requires at least 512MB. Please increase your memory_limit in php.ini or contact your hosting provider.',
				),
			);

			$rest_api_status = $rest_enabled ? ( $rest_accessible ? 'success' : 'warning' ) : 'error';
			$rest_api_value  = $rest_enabled ? 'Enabled' : 'Disabled';

			if ( $rest_enabled && ! $rest_accessible ) {
				if ( $specific_issue === 'authentication_required' ) {
					$rest_api_value = 'Enabled (Restricted Access)';
				} elseif ( $specific_issue === 'server_error' ) {
					$rest_api_value = 'Enabled (Server Error)';
				} elseif ( $specific_issue === 'connection_error' ) {
					$rest_api_value = 'Enabled (Connection Issue)';
				} else {
					$rest_api_value = 'Enabled (Issue Detected)';
				}
			}

			$rest_api_message = $rest_enabled
				? ( $rest_accessible
					? 'WordPress REST API is properly configured.'
					: 'WordPress REST API is enabled but not fully accessible. ' . $this->get_specific_rest_issue_message( $specific_issue, $status_code ) )
				: 'WordPress REST API is disabled. AI Workflow Automation requires the REST API. Please enable pretty permalinks in your WordPress settings.';

			foreach ( $items as &$item ) {
				if ( ! isset( $item['details'] ) ) {
					$item['details'] = array(
						'specific_issue'  => null,
						'status_code'     => null,
						'recommendations' => array(),
					);
				}
			}

			$items[] = array(
				'title'    => 'REST API Configuration',
				'value'    => $rest_api_value,
				'required' => 'Enabled',
				'status'   => $rest_api_status,
				'message'  => $rest_api_message,
				'details'  => array(
					'specific_issue'  => $specific_issue,
					'status_code'     => $status_code,
					'recommendations' => $rest_recommendations,
				),
			);

			// Build response
			$response = array(
				'items' => $items,
				'cron'  => array(
					'enabled'        => ! $cron_disabled,
					'overdue'        => $overdue_count,
					'maxOverdueTime' => round( $max_overdue_minutes ),
				),
			);

			// Add server environment info
			$server_software = 'Unknown';
			if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
				$server_software = esc_html( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
			}
			$response['environment'] = array(
				'server'      => $server_software,
				'os'          => PHP_OS,
				'ssl_enabled' => is_ssl(),
			);

			return new WP_REST_Response( $response, 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error retrieving system requirements',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_Error(
				'system_requirements_error',
				'Failed to retrieve system requirements: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Improved REST API testing function for system requirements check
	 * This performs an actual test request to the REST API and provides specific diagnostics
	 */
	private function check_rest_availability( $rest_url ) {
		// Start with basic checks
		$permalink_structure = get_option( 'permalink_structure' );
		$rest_enabled        = ! empty( $permalink_structure );

		// Store detailed diagnostics
		$test_results = array(
			'enabled'                  => $rest_enabled,
			'permalink_structure'      => $permalink_structure,
			'specific_issue'           => null,
			'status_code'              => null,
			'test_endpoint_accessible' => false,
			'plugin_conflicts'         => array(),
		);

		if ( ! $rest_enabled ) {
			$test_results['specific_issue'] = 'permalinks_disabled';
			return $test_results;
		}

		$test_url = trailingslashit( $rest_url ) . 'wp/v2/types';

		$response = wp_remote_get(
			$test_url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'httpversion' => '1.1',
			)
		);

		if ( is_wp_error( $response ) ) {
			$test_results['specific_issue'] = 'connection_error';
			$test_results['error_message']  = $response->get_error_message();
			return $test_results;
		}

		$response_code               = wp_remote_retrieve_response_code( $response );
		$test_results['status_code'] = $response_code;

		if ( $response_code >= 200 && $response_code < 300 ) {
			$test_results['test_endpoint_accessible'] = true;
		} elseif ( $response_code === 401 || $response_code === 403 ) {
			$test_results['specific_issue'] = 'authentication_required';

			$active_plugins   = get_option( 'active_plugins' );
			$security_plugins = array(
				'wordfence/wordfence.php'          => 'Wordfence',
				'better-wp-security/better-wp-security.php' => 'iThemes Security',
				'all-in-one-wp-security-and-firewall/wp-security.php' => 'All In One WP Security',
				'wp-simple-firewall/icwp-wpsf.php' => 'Shield Security',
				'sucuri-scanner/sucuri.php'        => 'Sucuri Security',
			);

			foreach ( $security_plugins as $plugin_file => $plugin_name ) {
				if ( in_array( $plugin_file, $active_plugins ) ) {
					$test_results['plugin_conflicts'][] = $plugin_name;
				}
			}
		} elseif ( $response_code === 404 ) {
			$test_results['specific_issue'] = 'endpoint_not_found';
		} elseif ( $response_code >= 500 ) {
			$test_results['specific_issue'] = 'server_error';
		}

		// Additional .htaccess check
		$htaccess_path                     = ABSPATH . '.htaccess';
		$test_results['htaccess_exists']   = file_exists( $htaccess_path );
		$test_results['htaccess_writable'] = is_writable( $htaccess_path );

		// Check if this site is behind a proxy/CDN
		$test_results['behind_proxy'] = $this->detect_site_behind_proxy();

		return $test_results;
	}

	/**
	 * Detect if the site is behind a proxy/CDN/WAF
	 */
	private function detect_site_behind_proxy() {
		$proxy_headers = array_filter(
			$_SERVER,
			function ( $key ) {
				return strpos( $key, 'HTTP_X_' ) === 0 ||
					strpos( $key, 'HTTP_CF_' ) === 0 ||
					strpos( $key, 'HTTP_CLOUDFRONT_' ) === 0;
			},
			ARRAY_FILTER_USE_KEY
		);

		return ! empty( $proxy_headers );
	}

	/**
	 * Get specific recommendations based on REST API test results
	 */
	private function get_rest_api_recommendations( $test_results ) {
		$recommendations = array();

		if ( ! $test_results['enabled'] ) {
			$recommendations[] = array(
				'title'       => 'Enable WordPress Permalinks',
				'description' => 'Go to Settings → Permalinks and select any option other than "Plain". Post name is recommended.',
				'priority'    => 'high',
			);
			return $recommendations;
		}

		if ( $test_results['specific_issue'] === 'authentication_required' ) {
			if ( ! empty( $test_results['plugin_conflicts'] ) ) {
				foreach ( $test_results['plugin_conflicts'] as $plugin ) {
					$recommendations[] = array(
						'title'       => "Check {$plugin} Settings",
						'description' => "Your {$plugin} plugin may be blocking REST API access. Please check its settings to allow REST API endpoints.",
						'priority'    => 'high',
					);
				}
			} else {
				$recommendations[] = array(
					'title'       => 'Check Security Plugins',
					'description' => 'A security plugin or firewall appears to be blocking REST API access. Check settings in any security plugins.',
					'priority'    => 'high',
				);
			}
		}

		if ( $test_results['specific_issue'] === 'server_error' ) {
			$recommendations[] = array(
				'title'       => 'Server Configuration Issue',
				'description' => 'Your server returned an error when accessing the REST API. Check server error logs and contact your hosting provider.',
				'priority'    => 'high',
			);
		}

		if ( $test_results['specific_issue'] === 'connection_error' ) {
			$recommendations[] = array(
				'title'       => 'Connection Error',
				'description' => 'Unable to connect to the REST API. This could be due to server configuration or a firewall blocking requests.',
				'priority'    => 'high',
			);
		}

		if ( $test_results['htaccess_exists'] && ! $test_results['htaccess_writable'] ) {
			$recommendations[] = array(
				'title'       => 'Check .htaccess Permissions',
				'description' => 'Your .htaccess file exists but is not writable by WordPress, which may affect REST API functionality.',
				'priority'    => 'medium',
			);
		}

		if ( $test_results['behind_proxy'] ) {
			$recommendations[] = array(
				'title'       => 'Proxy/CDN Configuration',
				'description' => 'Your site appears to be behind a proxy, CDN, or firewall. Ensure it\'s configured to allow REST API requests.',
				'priority'    => 'medium',
			);
		}

		return $recommendations;
	}

	/**
	 * Get a user-friendly message for specific REST API issues
	 */
	private function get_specific_rest_issue_message( $issue, $status_code = null ) {
		switch ( $issue ) {
			case 'permalinks_disabled':
				return 'WordPress permalinks are set to "Plain" which disables the REST API.';

			case 'authentication_required':
				return 'Access to the REST API is restricted by a security plugin or firewall (Status: ' . $status_code . ').';

			case 'server_error':
				return 'The server encountered an error when accessing the REST API (Status: ' . $status_code . ').';

			case 'connection_error':
				return 'Unable to connect to the WordPress REST API. This could be due to server configuration issues.';

			case 'endpoint_not_found':
				return 'The REST API endpoint was not found (Status: ' . $status_code . ').';

			default:
				if ( $status_code ) {
					return 'An issue was detected with the REST API (Status: ' . $status_code . ').';
				}
				return 'An unknown issue is preventing full access to the REST API.';
		}
	}

	/**
	* Get all vector stores
	*/
	public function get_vector_stores( $request ) {
		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$stores = $vector_store->get_all_stores();
			return new WP_REST_Response( $stores, 200 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Create a new vector store
	 */
	public function create_vector_store( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params['name'] ) ) {
			return new WP_Error(
				'missing_required_param',
				'The name parameter is required',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->create_store(
				$params['name'],
				$params['description'] ?? ''
			);

			return new WP_REST_Response( $result, 201 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_creation_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update a vector store
	 */
	public function update_vector_store( $request ) {
		$store_id = $request->get_param( 'id' );
		$params   = $request->get_json_params();

		if ( empty( $store_id ) ) {
			return new WP_Error(
				'missing_required_param',
				'The store ID is required',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->update_store(
				$store_id,
				$params['name'] ?? null,
				$params['description'] ?? null
			);

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_update_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete a vector store
	 */
	public function delete_vector_store( $request ) {
		$store_id = $request->get_param( 'id' );

		if ( empty( $store_id ) ) {
			return new WP_Error(
				'missing_required_param',
				'The store ID is required',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->delete_store( $store_id );

			if ( $result ) {
				return new WP_REST_Response( null, 204 );
			} else {
				return new WP_Error(
					'vector_store_deletion_error',
					'Failed to delete vector store',
					array( 'status' => 500 )
				);
			}
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_deletion_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get files in a vector store
	 */
	public function get_vector_store_files( $request ) {
		$store_id = $request->get_param( 'id' );

		if ( empty( $store_id ) ) {
			return new WP_Error(
				'missing_required_param',
				'The store ID is required',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$files = $vector_store->get_store_files( $store_id );
			return new WP_REST_Response( $files, 200 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_files_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Upload a file to a vector store
	 */
	public function upload_vector_store_file( $request ) {
		$store_id = $request->get_param( 'store_id' );
		$files    = $request->get_file_params();

		if ( empty( $store_id ) ) {
			return new WP_Error(
				'missing_required_param',
				'The store_id parameter is required',
				array( 'status' => 400 )
			);
		}

		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'missing_required_param',
				'No file was uploaded',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->upload_file( $store_id, $files['file'] );
			return new WP_REST_Response( $result, 201 );
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_upload_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete a file from a vector store
	 */
	public function delete_vector_store_file( $request ) {
		$file_id = $request->get_param( 'id' );

		if ( empty( $file_id ) ) {
			return new WP_Error(
				'missing_required_param',
				'The file ID is required',
				array( 'status' => 400 )
			);
		}

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->delete_file( $file_id );

			if ( $result ) {
				return new WP_REST_Response( null, 204 );
			} else {
				return new WP_Error(
					'vector_store_file_deletion_error',
					'Failed to delete file',
					array( 'status' => 500 )
				);
			}
		} catch ( Exception $e ) {
			return new WP_Error(
				'vector_store_file_deletion_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get_wp_content( $request ) {
		$type       = $request->get_param( 'post_type' ) ?: $request->get_param( 'type' ) ?: 'post';
		$categories = $request->get_param( 'categories' );
		$after      = $request->get_param( 'after' );
		$before     = $request->get_param( 'before' );
		$authors    = $request->get_param( 'authors' );

		// Validate post type exists
		if ( ! post_type_exists( $type ) ) {
			return new WP_Error(
				'invalid_post_type',
				'Invalid post type',
				array( 'status' => 400 )
			);
		}

		$args = array(
			'post_type'        => $type,
			'post_status'      => 'publish',
			'posts_per_page'   => 100,
			'suppress_filters' => false,
		);

		if ( ! empty( $categories ) ) {
			$args['category__in'] = explode( ',', $categories );
		}

		if ( ! empty( $after ) || ! empty( $before ) ) {
			$args['date_query'] = array();
			if ( ! empty( $after ) ) {
				$args['date_query']['after'] = $after;
			}
			if ( ! empty( $before ) ) {
				$args['date_query']['before'] = $before;
			}
		}

		if ( ! empty( $authors ) ) {
			$args['author__in'] = explode( ',', $authors );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Fetching posts with args',
			'debug',
			array(
				'post_type' => $type,
				'args'      => $args,
			)
		);

		$query = new WP_Query( $args );
		$posts = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				$post    = get_post();

				$post_data = array(
					'id'         => $post_id,
					'title'      => get_the_title(),
					'post_title' => get_the_title(), // Adding for compatibility
					'type'       => get_post_type(),
					'date'       => get_the_date( 'c' ),
					'post_date'  => get_the_date( 'c' ), // Adding for compatibility
					'link'       => get_permalink(),
					'permalink'  => get_permalink(), // Adding for compatibility
					'excerpt'    => get_the_excerpt(),
					'author'     => get_the_author_meta( 'display_name' ),
				);

				$taxonomies = get_object_taxonomies( $type, 'names' );
				foreach ( $taxonomies as $taxonomy ) {
					$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
					if ( ! is_wp_error( $terms ) ) {
						$post_data[ $taxonomy ] = $terms;
					}
				}

				$posts[] = $post_data;
			}
		}

		wp_reset_postdata();

		WP_AI_Workflows_Utilities::debug_log(
			'Posts retrieved',
			'debug',
			array(
				'post_type' => $type,
				'count'     => count( $posts ),
			)
		);

		return new WP_REST_Response( $posts, 200 );
	}

	public function add_wp_content_to_vector_store( $request ) {
		$store_id = $request->get_param( 'id' );
		$post_ids = $request->get_json_params()['post_ids'] ?? array();

		if ( empty( $store_id ) || empty( $post_ids ) ) {
			return new WP_Error(
				'missing_required_param',
				'Store ID and post IDs are required',
				array( 'status' => 400 )
			);
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Adding WordPress content to vector store',
			'info',
			array(
				'store_id'   => $store_id,
				'post_count' => count( $post_ids ),
			)
		);

		$vector_store = new WP_AI_Workflows_Vector_Store();

		try {
			$result = $vector_store->add_wp_content( $store_id, $post_ids );
			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error adding WP content to vector store',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_Error(
				'content_addition_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/* -----------------------------------------------------------------
	 * Knowledge base (Supabase / pgvector) callbacks
	 * --------------------------------------------------------------- */

	/**
	 * List knowledge bases with document counts.
	 */
	public function get_knowledge_bases( $request ) {
		try {
			$kb = new WP_AI_Workflows_Knowledge_Base();
			return new WP_REST_Response( $kb->get_all_kbs(), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create a knowledge base.
	 */
	public function create_knowledge_base( $request ) {
		$params = $request->get_json_params();
		$name   = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'missing_required_param', 'A name is required.', array( 'status' => 400 ) );
		}

		try {
			$kb     = new WP_AI_Workflows_Knowledge_Base();
			$result = $kb->create_kb( $name, isset( $params['description'] ) ? sanitize_textarea_field( $params['description'] ) : '' );
			return new WP_REST_Response( $result, 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_creation_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete a knowledge base (and its Supabase vectors).
	 */
	public function delete_knowledge_base( $request ) {
		$kb_id = sanitize_text_field( $request->get_param( 'id' ) );

		try {
			$kb = new WP_AI_Workflows_Knowledge_Base();
			$kb->delete_kb( $kb_id );
			return new WP_REST_Response( array( 'success' => true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_deletion_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Ingest a block of pasted text into a knowledge base.
	 */
	public function ingest_knowledge_base_text( $request ) {
		$kb_id   = sanitize_text_field( $request->get_param( 'id' ) );
		$params  = $request->get_json_params();
		$content = isset( $params['content'] ) ? (string) $params['content'] : '';
		$title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'missing_required_param', 'Content is required.', array( 'status' => 400 ) );
		}

		$metadata = array( 'source' => 'text' );
		if ( '' !== $title ) {
			$metadata['title'] = $title;
		}

		try {
			$kb     = new WP_AI_Workflows_Knowledge_Base();
			$result = $kb->ingest_text( $kb_id, $content, $metadata );
			return new WP_REST_Response( array( 'success' => true, 'added' => (int) $result['added'] ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_ingest_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Ingest selected WordPress posts into a knowledge base.
	 */
	public function ingest_knowledge_base_wp_content( $request ) {
		$kb_id     = sanitize_text_field( $request->get_param( 'id' ) );
		$params    = $request->get_json_params();
		$post_ids  = isset( $params['post_ids'] ) ? array_map( 'intval', (array) $params['post_ids'] ) : array();
		$post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post';

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'missing_required_param', 'post_ids are required.', array( 'status' => 400 ) );
		}

		try {
			$kb     = new WP_AI_Workflows_Knowledge_Base();
			$result = $kb->ingest_wp_posts( $kb_id, $post_ids, $post_type );
			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_ingest_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Ingest an uploaded document (PDF / Word / text) into a knowledge base.
	 * The stored upload is always deleted after processing.
	 */
	public function ingest_knowledge_base_file( $request ) {
		$kb_id = sanitize_text_field( $request->get_param( 'id' ) );

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'missing_file', 'No file was uploaded.', array( 'status' => 400 ) );
		}
		$file = $files['file'];

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload_error', 'The file failed to upload.', array( 'status' => 400 ) );
		}

		// Size cap: 20 MB.
		$max_bytes = 20 * MB_IN_BYTES;
		if ( (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'file_too_large', 'The file exceeds the 20 MB limit.', array( 'status' => 413 ) );
		}

		// Text-family extensions all map to text/plain: that is what libmagic
		// (finfo) actually reports for .md/.csv, so declaring text/markdown or
		// text/csv here would make wp_check_filetype_and_ext reject them on the
		// real-MIME cross-check.
		$allowed_mimes = array(
			'txt|text|md|markdown|csv' => 'text/plain',
			'pdf'                      => 'application/pdf',
			'doc'                      => 'application/msword',
			'docx'                     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'rtf'                      => 'application/rtf',
		);

		$original_name = sanitize_file_name( (string) $file['name'] );
		$checked       = wp_check_filetype_and_ext( $file['tmp_name'], $original_name, $allowed_mimes );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new WP_Error(
				'invalid_file_type',
				'Unsupported file type. Allowed: PDF, DOC, DOCX, TXT, MD, CSV, RTF.',
				array( 'status' => 415 )
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$overrides = array(
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		);
		$moved     = wp_handle_upload( $file, $overrides );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			$err = ( is_array( $moved ) && isset( $moved['error'] ) ) ? $moved['error'] : 'The file could not be stored.';
			return new WP_Error( 'upload_error', $err, array( 'status' => 400 ) );
		}

		$added  = 0;
		$errors = array();

		try {
			$kb     = new WP_AI_Workflows_Knowledge_Base();
			$result = $kb->ingest_file( $kb_id, $moved['file'], $moved['url'], $original_name );
			$added  = (int) $result['added'];
		} catch ( Exception $e ) {
			$errors[] = $e->getMessage();
			WP_AI_Workflows_Utilities::debug_log(
				'KB file ingest error',
				'error',
				array( 'kb_id' => $kb_id, 'file' => $original_name, 'error' => $e->getMessage() )
			);
		}

		// Always remove the stored upload; the KB keeps only the derived vectors.
		if ( isset( $moved['file'] ) && file_exists( $moved['file'] ) ) {
			wp_delete_file( $moved['file'] );
		}

		// Mirror the ingest-wp-content response shape (added_count + errors[]) so
		// the shared UI error-reporting path surfaces real reasons, not a false
		// "success" toast, when nothing could be ingested.
		return new WP_REST_Response(
			array(
				'success'     => $added > 0,
				'added'       => $added,
				'added_count' => $added,
				'filename'    => $original_name,
				'errors'      => ( $added > 0 || ! empty( $errors ) ) ? $errors : array( 'No content could be ingested from the file.' ),
			),
			200
		);
	}

	/**
	 * Report whether Supabase is configured, plus the one-time setup SQL.
	 */
	public function get_knowledge_base_status( $request ) {
		$cfg = WP_AI_Workflows_Knowledge_Base::get_config();
		return new WP_REST_Response(
			array(
				'configured'      => WP_AI_Workflows_Knowledge_Base::is_configured(),
				'has_url'         => '' !== $cfg['url'],
				'table'           => $cfg['table'],
				'embedding_model' => WP_AI_Workflows_Knowledge_Base::EMBEDDING_MODEL,
				'setup_sql'       => WP_AI_Workflows_Knowledge_Base::get_setup_sql(),
			),
			200
		);
	}

	/**
	 * Test the Supabase connection (RPC reachability + auth).
	 */
	public function test_knowledge_base_connection( $request ) {
		try {
			WP_AI_Workflows_Knowledge_Base::test_connection();
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Connected to Supabase and match_documents is available.' ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'kb_connection_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	public function get_authors() {
		$args = array(
			'who'                 => 'authors',
			'has_published_posts' => true,
			'orderby'             => 'display_name',
			'order'               => 'ASC',
		);

		$authors = get_users( $args );

		$formatted_authors = array_map(
			function ( $author ) {
				return array(
					'id'    => $author->ID,
					'name'  => $author->display_name,
					'label' => $author->display_name, // for Select component compatibility
					'value' => (string) $author->ID,    // for Select component compatibility
				);
			},
			$authors
		);

		return new WP_REST_Response( $formatted_authors, 200 );
	}

	public function get_categories_by_post_type( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				'Invalid post type',
				array( 'status' => 400 )
			);
		}

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$categories = array();

		foreach ( $taxonomies as $taxonomy ) {
			// Skip non-hierarchical taxonomies (like tags); focus on category-like ones.
			if ( ! $taxonomy->hierarchical ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = array(
						'id'             => $term->term_id,
						'name'           => $term->name,
						'label'          => $term->name, // for Select component compatibility
						'value'          => (string) $term->term_id, // for Select component compatibility
						'taxonomy'       => $taxonomy->name,
						'taxonomy_label' => $taxonomy->label,
						'parent'         => $term->parent,
						'slug'           => $term->slug,
					);
				}
			}
		}

		$grouped_categories = array();
		foreach ( $categories as $category ) {
			$taxonomy = $category['taxonomy'];
			if ( ! isset( $grouped_categories[ $taxonomy ] ) ) {
				$grouped_categories[ $taxonomy ] = array(
					'taxonomy' => $taxonomy,
					'label'    => $category['taxonomy_label'],
					'terms'    => array(),
				);
			}
			$grouped_categories[ $taxonomy ]['terms'][] = $category;
		}

		return new WP_REST_Response(
			array(
				'categories' => $categories, // flat list for simple usage
				'grouped'    => array_values( $grouped_categories ), // grouped by taxonomy
			),
			200
		);
	}

	/**
	 * Get all vector stores from OpenAI
	 */
	public function get_openai_vector_stores( $request ) {
		try {
			$vector_store = new WP_AI_Workflows_Vector_Store();
			$stores       = $vector_store->fetch_openai_vector_stores();
			return new WP_REST_Response( $stores, 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array(
					'error' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Get files for a vector store from OpenAI
	 */
	public function get_openai_vector_store_files( $request ) {
		try {
			$store_id     = $request->get_param( 'store_id' );
			$vector_store = new WP_AI_Workflows_Vector_Store();
			$files        = $vector_store->fetch_openai_vector_store_files( $store_id );
			return new WP_REST_Response( $files, 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array(
					'error' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Get a single vector store from OpenAI
	 */
	public function get_openai_vector_store( $request ) {
		try {
			$store_id     = $request->get_param( 'store_id' );
			$vector_store = new WP_AI_Workflows_Vector_Store();
			$store        = $vector_store->fetch_openai_vector_store( $store_id );
			return new WP_REST_Response( $store, 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array(
					'error' => $e->getMessage(),
				),
				500
			);
		}
	}

	/**
	 * Get whitelabel settings for editing (the settings-page read path). Not
	 * how saved branding renders — that is the ungated, PHP-injected
	 * window.wpAiWorkflowsWhitelabel global.
	 */
	public function get_whitelabel_settings() {
		$gate = WP_AI_Workflows_Platform_Client::whitelabel_management_gate();
		if ( is_wp_error( $gate ) ) {
			return $this->org_sites_error_response( $gate );
		}

		$settings = get_option(
			'wp_ai_workflows_whitelabel_settings',
			array(
				'enable_whitelabel'     => false,
				'logo_url'              => '',
				'header_text'           => 'AI Workflow Automation',
				'primary_color'         => '#1890ff',
				'menu_bg_color'         => '#001529',
				'menu_text_color'       => '#ffffff',
				'custom_email_subject'  => 'New Task Awaiting Your Input',
				'custom_email_template' => '',
			)
		);

		return new WP_REST_Response( $settings );
	}

	/**
	 * Update whitelabel settings
	 */
	public function update_whitelabel_settings( $request ) {
		$gate = WP_AI_Workflows_Platform_Client::whitelabel_management_gate();
		if ( is_wp_error( $gate ) ) {
			return $this->org_sites_error_response( $gate );
		}

		$whitelabel = new WP_AI_Workflows_Whitelabel();
		$result     = $whitelabel->update_whitelabel_settings( $request );

		return new WP_REST_Response( $result );
	}

	/**
	 * Handle logo upload
	 */
	public function handle_logo_upload( $request ) {
		$gate = WP_AI_Workflows_Platform_Client::whitelabel_management_gate();
		if ( is_wp_error( $gate ) ) {
			return $this->org_sites_error_response( $gate );
		}

		$whitelabel = new WP_AI_Workflows_Whitelabel();
		$result     = $whitelabel->handle_logo_upload( $request );

		if ( isset( $result['success'] ) && $result['success'] ) {
			return new WP_REST_Response( $result );
		} else {
			return new WP_REST_Response( $result, 400 );
		}
	}

	/**
	 * Import whitelabel settings
	 */
	public function import_whitelabel_settings( $request ) {
		$gate = WP_AI_Workflows_Platform_Client::whitelabel_management_gate();
		if ( is_wp_error( $gate ) ) {
			return $this->org_sites_error_response( $gate );
		}

		$whitelabel = new WP_AI_Workflows_Whitelabel();
		$result     = $whitelabel->import_whitelabel_settings( $request );

		if ( isset( $result['success'] ) && $result['success'] ) {
			return new WP_REST_Response( $result );
		} else {
			return new WP_REST_Response( $result, 400 );
		}
	}


	/**
	 * Get models from OpenRouter API
	 *
	 * @return WP_REST_Response
	 */
	public function get_openrouter_models() {
		// Get models from cache if available
		$cache_key     = 'wp_ai_workflows_openrouter_models';
		$cached_models = get_transient( $cache_key );

		if ( false !== $cached_models ) {
			return rest_ensure_response( $cached_models );
		}

		// Fetch models from OpenRouter API
		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error fetching OpenRouter models',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error( 'openrouter_api_error', 'Failed to fetch models from OpenRouter API: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 ) {
			WP_AI_Workflows_Utilities::debug_log(
				'OpenRouter API returned non-200 status',
				'error',
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
			return new WP_Error( 'openrouter_api_error', 'OpenRouter API returned status: ' . $status_code, array( 'status' => $status_code ) );
		}

		$body        = wp_remote_retrieve_body( $response );
		$models_data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to parse OpenRouter response',
				'error',
				array(
					'error' => json_last_error_msg(),
					'body'  => $body,
				)
			);
			return new WP_Error( 'openrouter_api_error', 'Failed to parse OpenRouter API response: ' . json_last_error_msg(), array( 'status' => 500 ) );
		}

		// Cache the models for 1 hour
		set_transient( $cache_key, $models_data, HOUR_IN_SECONDS );

		// Log successful fetch
		WP_AI_Workflows_Utilities::debug_log(
			'Successfully fetched OpenRouter models',
			'info',
			array(
				'model_count' => count( $models_data['data'] ?? array() ),
			)
		);

		return rest_ensure_response( $models_data );
	}

	/**
	 * Get available models from OpenAI API
	 */
	public function get_openai_models() {
		// Get models from cache if available
		$cache_key     = 'wp_ai_workflows_openai_models';
		$cached_models = get_transient( $cache_key );

		if ( false !== $cached_models ) {
			return rest_ensure_response( $cached_models );
		}

		// Get OpenAI API key
		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( empty( $api_key ) ) {
			// "No key" is a normal state (e.g. OpenRouter-only sites), not a
			// client error. Return HTTP 200 with an empty list and a
			// machine-readable reason so the frontend can silently fall back to
			// its static model list instead of logging a 400. Real upstream
			// failures below still surface as errors.
			return rest_ensure_response(
				array(
					'models' => array(),
					'reason' => 'no_api_key',
				)
			);
		}

		// Fetch models from OpenAI API
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error fetching OpenAI models',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error(
				'openai_api_error',
				'Failed to fetch models from OpenAI API: ' . $response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 ) {
			WP_AI_Workflows_Utilities::debug_log(
				'OpenAI API returned non-200 status',
				'error',
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
			return new WP_Error(
				'openai_api_error',
				'OpenAI API returned status: ' . $status_code,
				array( 'status' => $status_code )
			);
		}

		$body        = wp_remote_retrieve_body( $response );
		$models_data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to parse OpenAI response',
				'error',
				array(
					'error' => json_last_error_msg(),
					'body'  => $body,
				)
			);
			return new WP_Error(
				'openai_api_error',
				'Failed to parse OpenAI API response: ' . json_last_error_msg(),
				array( 'status' => 500 )
			);
		}

		// Cache the models for 1 hour
		set_transient( $cache_key, $models_data, HOUR_IN_SECONDS );

		WP_AI_Workflows_Utilities::debug_log(
			'Successfully fetched OpenAI models',
			'info',
			array(
				'model_count' => count( $models_data['data'] ?? array() ),
			)
		);

		return rest_ensure_response( $models_data );
	}

	/**
	 * Get the merged, dynamic AI model catalog for the builder UI.
	 *
	 * Single source of truth: fetches OpenRouter (public) + OpenAI (when a key is
	 * configured), merges + caches, and falls back to a hard-coded current list
	 * when the remote fetch fails. Keeps user API keys server-side.
	 *
	 * @param WP_REST_Request $request Request object (supports ?refresh=1).
	 * @return WP_REST_Response
	 */
	public function get_models_catalog( $request ) {
		$force   = (bool) $request->get_param( 'refresh' );
		$catalog = WP_AI_Workflows_Model_Catalog::get_catalog( $force );

		return rest_ensure_response(
			array(
				'data'       => $catalog['data'],
				'source'     => isset( $catalog['source'] ) ? $catalog['source'] : 'unknown',
				'fetched_at' => isset( $catalog['fetched_at'] ) ? (int) $catalog['fetched_at'] : 0,
				'default'    => WP_AI_Workflows_Model_Catalog::get_default_model(),
			)
		);
	}

	/**
	 * Function to manually clear the OpenRouter models cache
	 */
	public function clear_openrouter_models_cache() {
		delete_transient( 'wp_ai_workflows_openrouter_models' );
		WP_AI_Workflows_Utilities::debug_log( 'OpenRouter models cache cleared', 'info' );
	}

	public function sync_costs( $request ) {
		try {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$result       = $cost_manager->sync_openrouter_costs();

			WP_AI_Workflows_Utilities::debug_log(
				'Cost sync request processed',
				'info',
				array(
					'success'        => $result['success'],
					'models_added'   => $result['models_added'],
					'models_updated' => $result['models_updated'],
					'errors'         => $result['errors'],
				)
			);

			if ( $result['success'] ) {
				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => sprintf(
							'Models synced successfully. Added %d new models, updated %d existing models.',
							$result['models_added'],
							$result['models_updated']
						),
						'data'    => $result,
					),
					200
				);
			} else {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Failed to sync models: ' . implode( ' ', $result['errors'] ),
						'data'    => $result,
					),
					400
				);
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error syncing costs',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_Error(
				'sync_costs_error',
				'Failed to sync costs: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public function get_sync_info( $request ) {
		try {
			$last_sync_time = get_option( 'wp_ai_workflows_last_cost_sync', 0 );

			$formatted_time = '';
			if ( $last_sync_time > 0 ) {
				$formatted_time = human_time_diff( $last_sync_time, time() ) . ' ago';
			} else {
				$formatted_time = 'Never';
			}

			return new WP_REST_Response(
				array(
					'last_sync_time'      => $formatted_time,
					'last_sync_timestamp' => $last_sync_time,
				),
				200
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error getting sync info',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_Error(
				'sync_info_error',
				'Failed to get sync information: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get available multimedia generation models
	 *
	 * @param WP_REST_Request $request REST Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function get_multimedia_models( $request ) {
		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$models               = $multimedia_generator->get_model_list_for_frontend();

		return new WP_REST_Response( $models, 200 );
	}

	/**
	 * Handle image generation request
	 *
	 * @param WP_REST_Request $request REST Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function handle_generate_image( $request ) {
		$params = $request->get_params();

		$required_fields = array( 'model', 'prompt' );
		foreach ( $required_fields as $field ) {
			if ( empty( $params[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					"Missing required field: $field",
					array( 'status' => 400 )
				);
			}
		}

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$result               = $multimedia_generator->generate_image( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$model        = sanitize_text_field( $params['model'] );
			$num_images   = isset( $params['num_images'] ) ? intval( $params['num_images'] ) : 1;

			$execution_id = isset( $params['execution_id'] ) ? intval( $params['execution_id'] ) : 0;
			$node_id      = isset( $params['node_id'] ) ? sanitize_text_field( $params['node_id'] ) : 'multimedia';

			$cost = $multimedia_generator->estimate_cost( $model, 'image', $num_images );
			if ( $cost !== null ) {
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,
					$node_id,
					$num_images,
					'image'
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Handle video generation request
	 *
	 * @param WP_REST_Request $request REST Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function handle_generate_video( $request ) {
		$params = $request->get_params();

		if ( empty( $params['model'] ) ) {
			return new WP_Error( 'missing_model', 'Model is required', array( 'status' => 400 ) );
		}

		$model                = sanitize_text_field( $params['model'] );
		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();

		$all_models = $multimedia_generator->get_available_models();
		$model_type = '';

		if ( isset( $all_models[ $model ] ) ) {
			if ( isset( $multimedia_generator->get_available_models( 'image_to_video' )[ $model ] ) ) {
				$model_type = 'image_to_video';
				if ( empty( $params['image_url'] ) ) {
					return new WP_Error( 'missing_image_url', 'Image URL is required for this model', array( 'status' => 400 ) );
				}
			} elseif ( isset( $multimedia_generator->get_available_models( 'text_to_video' )[ $model ] ) ) {
				$model_type = 'text_to_video';
				if ( empty( $params['prompt'] ) ) {
					return new WP_Error( 'missing_prompt', 'Prompt is required for this model', array( 'status' => 400 ) );
				}
			}
		} else {
			return new WP_Error( 'unknown_model', 'Unknown model', array( 'status' => 400 ) );
		}

		$result = $multimedia_generator->generate_video( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$video_length = isset( $params['video_length'] ) ? intval( $params['video_length'] ) : 5;

			$execution_id = isset( $params['execution_id'] ) ? intval( $params['execution_id'] ) : 0;
			$node_id      = isset( $params['node_id'] ) ? sanitize_text_field( $params['node_id'] ) : 'multimedia';

			$cost = $multimedia_generator->estimate_cost( $model, $model_type, $video_length );
			if ( $cost !== null ) {
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,
					$node_id,
					1,
					'video'
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Handle file upload for the multimedia generator
	 *
	 * @param WP_REST_Request $request REST Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function handle_file_upload( $request ) {
		$file = $request->get_file_params()['file'] ?? null;
		if ( ! $file ) {
			return new WP_Error( 'missing_file', 'No file was uploaded', array( 'status' => 400 ) );
		}

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$result               = $multimedia_generator->upload_file( $file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'url' => $result['url'],
			),
			200
		);
	}

	/**
	 * Estimate the cost of a multimedia generation request
	 *
	 * @param WP_REST_Request $request REST Request object
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public function estimate_multimedia_cost( $request ) {
		$params = $request->get_params();

		if ( empty( $params['model'] ) ) {
			return new WP_Error( 'missing_model', 'Model is required', array( 'status' => 400 ) );
		}

		$model    = sanitize_text_field( $params['model'] );
		$type     = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : 'image';
		$quantity = isset( $params['quantity'] ) ? intval( $params['quantity'] ) : 1;

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$cost                 = $multimedia_generator->estimate_cost( $model, $type, $quantity );

		if ( $cost === null ) {
			return new WP_Error( 'unknown_model', 'Unknown model or type', array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'cost'           => $cost,
				'formatted_cost' => '$' . number_format( $cost, 2 ),
			),
			200
		);
	}

	/**
	 * Return the dynamic Fal.ai model catalog (registry-driven, cached).
	 *
	 * @param WP_REST_Request $request REST Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_multimedia_model_catalog( $request ) {
		$refresh              = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		$group                = sanitize_key( (string) $request->get_param( 'group' ) );
		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();

		$catalog = $multimedia_generator->get_model_catalog( $refresh );
		if ( is_wp_error( $catalog ) ) {
			return new WP_Error( 'catalog_error', $catalog->get_error_message(), array( 'status' => 502 ) );
		}

		if ( '' !== $group ) {
			$catalog = array_values(
				array_filter(
					$catalog,
					static function ( $m ) use ( $group ) {
						return isset( $m['group'] ) && $m['group'] === $group;
					}
				)
			);
		}

		return new WP_REST_Response( $catalog, 200 );
	}

	/**
	 * Return a single model's OpenAPI-derived input field schema (cached).
	 *
	 * @param WP_REST_Request $request REST Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_multimedia_model_schema( $request ) {
		$model = WP_AI_Workflows_Multimedia_Generator::sanitize_model_id( (string) $request->get_param( 'model' ) );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_model', 'A valid model id is required', array( 'status' => 400 ) );
		}
		$refresh = rest_sanitize_boolean( $request->get_param( 'refresh' ) );

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$schema               = $multimedia_generator->get_model_schema( $model, $refresh );

		if ( is_wp_error( $schema ) ) {
			return new WP_Error( 'schema_error', $schema->get_error_message(), array( 'status' => 502 ) );
		}

		return new WP_REST_Response( $schema, 200 );
	}

	/**
	 * Generic media generation: submit assembled inputs to the selected Fal model.
	 * The inputs are a free-form map validated by the model's own OpenAPI schema
	 * server-side (Fal returns a 4xx on invalid input, surfaced to the caller).
	 *
	 * @param WP_REST_Request $request REST Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate_media( $request ) {
		$model = WP_AI_Workflows_Multimedia_Generator::sanitize_model_id( (string) $request->get_param( 'model' ) );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_model', 'A valid model id is required', array( 'status' => 400 ) );
		}

		$inputs = $request->get_param( 'inputs' );
		if ( ! is_array( $inputs ) ) {
			$inputs = array();
		}
		$inputs = WP_AI_Workflows_Multimedia_Generator::sanitize_generation_inputs( $inputs );

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();
		$result               = $multimedia_generator->submit_generation( $model, $inputs );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'generation_error', $result->get_error_message(), array( 'status' => 502 ) );
		}

		$media = $multimedia_generator->extract_media_outputs( $result );

		return new WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
				'media'   => $media,
			),
			200
		);
	}

	public function get_mcp_tools( $request ) {
		$server_type = $request->get_param( 'server' );
		$config      = $request->get_param( 'config' );

		if ( $config ) {
			$config = json_decode( $config, true );
		}

		// This will be implemented when we create the MCP Client class
		return WP_AI_Workflows_MCP_Client::discover_tools( $server_type, $config );
	}

	public function test_mcp_connection( $request ) {
		$params = $request->get_params();

		// This will be implemented when we create the MCP Client class
		return WP_AI_Workflows_MCP_Client::test_connection( $params );
	}

	public function save_custom_mcp_server( $request ) {
		$params  = $request->get_params();
		$user_id = get_current_user_id();

		try {
			$server_id = WP_AI_Workflows_Database::save_mcp_server(
				$user_id,
				$params['name'],
				$params['description'],
				$params['config'],
				$params['discovered_tools'] ?? null
			);

			if ( $server_id ) {
				return new WP_REST_Response(
					array(
						'success'   => true,
						'server_id' => $server_id,
						'message'   => 'MCP server saved successfully',
					),
					200
				);
			} else {
				return new WP_Error( 'save_failed', 'Failed to save MCP server', array( 'status' => 500 ) );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'save_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public function get_mcp_servers( $request ) {
		$user_id = get_current_user_id();
		$servers = WP_AI_Workflows_Database::get_mcp_servers( $user_id );

		return new WP_REST_Response( $servers, 200 );
	}

	public function delete_mcp_server( $request ) {
		$server_id = $request->get_param( 'id' );
		$user_id   = get_current_user_id();

		$result = WP_AI_Workflows_Database::delete_mcp_server( $server_id, $user_id );

		if ( $result ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'MCP server deleted successfully',
				),
				200
			);
		} else {
			return new WP_Error( 'delete_failed', 'Failed to delete MCP server', array( 'status' => 500 ) );
		}
	}

	/**
	 * Check OpenRouter account balance
	 */
	public function check_openrouter_balance( $request ) {
		try {
			$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
			if ( empty( $api_key ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'OpenRouter API key not configured',
					),
					200
				);
			}

			$response = wp_remote_get(
				'https://openrouter.ai/api/v1/credits',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'Failed to fetch balance: ' . $response->get_error_message() );
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $data['data'] ) ) {
				$total_credits = isset( $data['data']['total_credits'] ) ? $data['data']['total_credits'] : 0;
				$total_usage   = isset( $data['data']['total_usage'] ) ? $data['data']['total_usage'] : 0;
				$remaining     = $total_credits - $total_usage;

				return new WP_REST_Response(
					array(
						'success'       => true,
						'balance'       => $remaining,
						'total_credits' => $total_credits,
						'total_usage'   => $total_usage,
						'currency'      => 'USD',
					)
				);
			}

			throw new Exception( 'Invalid response format' );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'OpenRouter balance check failed',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to check OpenRouter balance',
				),
				200
			);
		}
	}

	public function migrate_legacy_workflows( $request ) {
		$result = WP_AI_Workflows_Utilities::migrate_to_db_table();

		if ( $result ) {
			$workflows = get_option( 'wp_ai_workflows', array() );
			return new WP_REST_Response(
				array(
					'success'  => true,
					'migrated' => count( $workflows ),
					'message'  => 'Migration completed successfully',
				),
				200
			);
		}

		return new WP_Error( 'migration_failed', 'Failed to migrate workflows', array( 'status' => 500 ) );
	}

	public function check_migration_status( $request ) {
		$status = WP_AI_Workflows_Utilities::check_migration_status();
		return new WP_REST_Response( $status, 200 );
	}

	public function migrate_outputs( $request ) {
		$result = WP_AI_Workflows_Utilities::migrate_outputs_to_db_table();

		if ( $result ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'Outputs migrated successfully',
				),
				200
			);
		}

		return new WP_Error( 'migration_failed', 'Failed to migrate outputs', array( 'status' => 500 ) );
	}

	public function cleanup_options( $request ) {
		$result = WP_AI_Workflows_Utilities::cleanup_migrated_options();
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function get_remote_templates( $request ) {
		// Get the actual license key from the database (not masked)
		$license_key = get_option( 'wp_ai_workflows_license_key', '' );

		$response = wp_remote_get(
			'https://wpaiworkflowautomation.com/wp-json/wpai-templates/v1/templates' .
			( $license_key ? '?license_key=' . urlencode( $license_key ) : '' ),
			array(
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', 'Failed to fetch templates from server: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new WP_Error( 'fetch_failed', 'Server returned status: ' . $status_code, array( 'status' => $status_code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_response', 'Invalid JSON response from template server', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $data, 200 );
	}

	public function get_remote_template( $request ) {
		$slug = $request->get_param( 'slug' );

		$license_key = get_option( 'wp_ai_workflows_license_key', '' );

		$url = 'https://wpaiworkflowautomation.com/wp-json/wpai-templates/v1/template/' . $slug .
		( $license_key ? '?license_key=' . urlencode( $license_key ) : '' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', 'Failed to fetch template from server: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code !== 200 ) {
			return new WP_Error( 'fetch_failed', 'Server returned status: ' . $status_code, array( 'status' => $status_code ) );
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_response', 'Invalid JSON response from template server', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $data, 200 );
	}

	public function send_output_email( $request ) {
		try {
			$params = $request->get_json_params();

			if ( empty( $params['to'] ) || empty( $params['subject'] ) || empty( $params['body'] ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Missing required fields: to, subject, or body',
					),
					400
				);
			}

			$to      = sanitize_email( $params['to'] );
			$subject = sanitize_text_field( $params['subject'] );
			$body    = wp_kses_post( $params['body'] );

			if ( ! is_email( $to ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Invalid email address format',
					),
					400
				);
			}

			$headers = array();

			if ( ! empty( $params['useHtml'] ) && $params['useHtml'] === true ) {
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
			} else {
				$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			}

			$from_email = get_option( 'admin_email' );
			$from_name  = get_bloginfo( 'name' );
			$headers[]  = 'From: ' . $from_name . ' <' . $from_email . '>';

			$result = wp_mail( $to, $subject, $body, $headers );

			if ( $result ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Output node test email sent',
					'info',
					array(
						'to'      => $to,
						'subject' => $subject,
					)
				);

				return new WP_REST_Response(
					array(
						'success' => true,
						'message' => 'Test email sent successfully to ' . $to,
					),
					200
				);
			} else {
				WP_AI_Workflows_Utilities::debug_log(
					'Output node test email failed',
					'error',
					array(
						'to'      => $to,
						'subject' => $subject,
					)
				);

				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Failed to send email. Please check your WordPress email configuration (SMTP settings).',
					),
					500
				);
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Output node email error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Error: ' . $e->getMessage(),
				),
				500
			);
		}
	}
}
