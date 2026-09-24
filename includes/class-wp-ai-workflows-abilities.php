<?php
/**
 * Abilities API integration - exposes workflows as WordPress "Abilities", bridged
 * to MCP via the official MCP Adapter plugin when present. Execution is LOCAL
 * (BYOK); feature-detected so it no-ops cleanly without the Abilities API.
 *
 * @package WP_AI_Workflows
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers workflows as core Abilities and (optionally) an MCP server.
 */
class WP_AI_Workflows_Abilities {

	/**
	 * Ability category slug. Must match the core slug regex
	 * `[a-z0-9]+(?:-[a-z0-9]+)*` used by the REST controller.
	 */
	const CATEGORY_SLUG = 'wp-ai-workflows';

	/**
	 * Ability name prefix / namespace.
	 */
	const NAMESPACE_SLUG = 'wp-ai-workflows';

	/**
	 * Settings key (inside the `wp_ai_workflows_settings` option) for the
	 * opt-in toggle. Default OFF - the owner must explicitly expose workflows.
	 */
	const SETTING_KEY = 'expose_to_agents';

	/**
	 * MCP server id used with the MCP Adapter.
	 */
	const MCP_SERVER_ID = 'wp-ai-workflows';

	/**
	 * Maximum number of workflows exposed as abilities (safety cap so a site
	 * with thousands of workflows does not register thousands of REST routes).
	 */
	const MAX_EXPOSED = 100;

	/**
	 * Wire up the WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Register the category before abilities that reference it.
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Bridge to MCP when the official adapter plugin is active.
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );

		// Small admin REST endpoint powering the "AI Agents (MCP)" settings panel.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Whether the core Abilities API is available in this WordPress build.
	 *
	 * @return bool
	 */
	public static function abilities_api_available() {
		return function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether the owner has opted in to exposing workflows to AI agents.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		return ! empty( $settings[ self::SETTING_KEY ] );
	}

	/**
	 * Capability required to invoke a workflow ability. Filterable so a site can
	 * relax/tighten who may drive workflows through the agent surface.
	 *
	 * @return string
	 */
	public static function required_capability() {
		/**
		 * Filters the capability required to run a workflow via the Abilities API.
		 *
		 * @since 1.9.0
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'wp_ai_workflows_abilities_capability', 'manage_options' );
	}

	/**
	 * Permission callback shared by every ability. Abilities are only invokable
	 * by a user who holds the required capability.
	 *
	 * @return bool
	 */
	public static function permission_check() {
		return current_user_can( self::required_capability() );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY_SLUG,
			array(
				'label'       => __( 'AI Workflow Automation', 'wp-ai-workflows' ),
				'description' => __( 'Run AI Workflow Automation workflows as agent-invokable tools.', 'wp-ai-workflows' ),
			)
		);
	}

	/**
	 * Register the abilities (gated behind the opt-in toggle).
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::is_enabled() ) {
			return;
		}

		// Static discovery ability: lists the currently exposed workflows.
		wp_register_ability(
			self::NAMESPACE_SLUG . '/list-workflows',
			array(
				'label'               => __( 'List AI workflows', 'wp-ai-workflows' ),
				'description'         => __( 'Lists the active AI Workflow Automation workflows that are exposed to AI agents, including each one\'s runnable ability name and trigger type.', 'wp-ai-workflows' ),
				'category'            => self::CATEGORY_SLUG,
				'output_schema'       => array(
					'type'        => 'array',
					'description' => __( 'The list of exposed workflows.', 'wp-ai-workflows' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'string' ),
							'name'         => array( 'type' => 'string' ),
							'ability'      => array( 'type' => 'string' ),
							'trigger_type' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_workflows' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		// One run-ability per exposable workflow.
		foreach ( self::get_exposable_workflows() as $workflow ) {
			$ability_name = self::ability_name_for( $workflow['id'] );
			$workflow_id  = $workflow['id'];

			wp_register_ability(
				$ability_name,
				array(
					/* translators: %s: workflow name. */
					'label'               => sprintf( __( 'Run workflow: %s', 'wp-ai-workflows' ), $workflow['name'] ),
					/* translators: %s: workflow name. */
					'description'         => sprintf( __( 'Executes the "%s" AI workflow on this WordPress site and returns its output. Runs locally using the site\'s own AI provider keys.', 'wp-ai-workflows' ), $workflow['name'] ),
					'category'            => self::CATEGORY_SLUG,
					'input_schema'        => self::build_input_schema( $workflow ),
					'output_schema'       => array(
						'type'        => 'object',
						'description' => __( 'The workflow execution result.', 'wp-ai-workflows' ),
						'properties'  => array(
							'execution_id' => array( 'type' => array( 'integer', 'string' ) ),
							'status'       => array( 'type' => 'string' ),
							'result'       => array( 'type' => 'string' ),
							'outputs'      => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => static function ( $input = null ) use ( $workflow_id ) {
						return self::execute_run_workflow( $workflow_id, $input );
					},
					'permission_callback' => array( __CLASS__, 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
					),
				)
			);
		}
	}

	/**
	 * Build the JSON-Schema input contract for a workflow's trigger.
	 *
	 * @param array $workflow Decoded workflow array.
	 * @return array|null Input schema, or null when the trigger takes no input.
	 */
	public static function build_input_schema( $workflow ) {
		$trigger = self::find_trigger( $workflow );
		if ( ! $trigger ) {
			return null;
		}

		$trigger_type = isset( $trigger['data']['triggerType'] ) && '' !== $trigger['data']['triggerType']
			? $trigger['data']['triggerType']
			: 'manual';

		if ( 'webhook' === $trigger_type ) {
			$properties = array();
			$keys       = isset( $trigger['data']['webhookKeys'] ) && is_array( $trigger['data']['webhookKeys'] )
				? $trigger['data']['webhookKeys']
				: array();

			foreach ( $keys as $entry ) {
				if ( empty( $entry['key'] ) ) {
					continue;
				}
				$prop_key                = sanitize_key( $entry['key'] );
				$properties[ $prop_key ] = array(
					'type'        => 'string',
					'description' => sprintf(
						/* translators: %s: webhook field key. */
						__( 'Value for the "%s" webhook field.', 'wp-ai-workflows' ),
						$entry['key']
					),
				);
			}

			if ( empty( $properties ) ) {
				// Free-form payload when no keys are declared.
				return array(
					'type'        => 'object',
					'description' => __( 'Webhook payload passed to the workflow trigger.', 'wp-ai-workflows' ),
				);
			}

			return array(
				'type'        => 'object',
				'description' => __( 'Webhook payload passed to the workflow trigger.', 'wp-ai-workflows' ),
				'properties'  => $properties,
			);
		}

		// Manual (and default): a single optional text input that overrides the
		// workflow's stored trigger content for this run.
		return array(
			'type'        => 'object',
			'description' => __( 'Input passed to the manual workflow trigger.', 'wp-ai-workflows' ),
			'properties'  => array(
				'input' => array(
					'type'        => 'string',
					'description' => __( 'Text input for the workflow. When provided it overrides the workflow\'s stored trigger content for this run.', 'wp-ai-workflows' ),
				),
			),
		);
	}

	/**
	 * execute_callback for `wp-ai-workflows/list-workflows`.
	 *
	 * @param mixed $input Ignored (no input schema).
	 * @return array
	 */
	public static function execute_list_workflows( $input = null ) {
		unset( $input );

		$list = array();
		foreach ( self::get_exposable_workflows() as $workflow ) {
			$trigger      = self::find_trigger( $workflow );
			$trigger_type = ( $trigger && ! empty( $trigger['data']['triggerType'] ) )
				? $trigger['data']['triggerType']
				: 'manual';

			$list[] = array(
				'id'           => (string) $workflow['id'],
				'name'         => (string) $workflow['name'],
				'ability'      => self::ability_name_for( $workflow['id'] ),
				'trigger_type' => (string) $trigger_type,
			);
		}

		return $list;
	}

	/**
	 * execute_callback for a `wp-ai-workflows/run-{id}` ability.
	 *
	 * Runs the workflow locally and returns its output. Never throws - returns a
	 * WP_Error on failure per the Abilities API contract.
	 *
	 * @param string $workflow_id Workflow id captured at registration time.
	 * @param mixed  $input       Validated input matching the ability's schema.
	 * @return array|WP_Error
	 */
	public static function execute_run_workflow( $workflow_id, $input = null ) {
		if ( ! class_exists( 'WP_AI_Workflows_Workflow' ) ) {
			return new WP_Error( 'wpaw_unavailable', __( 'Workflow engine is not available.', 'wp-ai-workflows' ) );
		}

		$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow_id );
		if ( ! $workflow || 'active' !== ( $workflow['status'] ?? '' ) ) {
			return new WP_Error( 'wpaw_workflow_not_found', __( 'Workflow not found or inactive.', 'wp-ai-workflows' ), array( 'status' => 404 ) );
		}

		$trigger      = self::find_trigger( $workflow );
		$trigger_type = ( $trigger && ! empty( $trigger['data']['triggerType'] ) )
			? $trigger['data']['triggerType']
			: 'manual';

		$input        = is_array( $input ) ? $input : array();
		$initial_data = null;
		$manual_hook  = null;

		if ( 'webhook' === $trigger_type ) {
			// The webhook trigger reads $initial_webhook_data['output'].
			$initial_data = array( 'output' => $input );
		} else {
			// Manual: inject the caller's text via a scoped filter so we do not
			// mutate stored workflow data or affect other executions.
			if ( isset( $input['input'] ) && is_string( $input['input'] ) ) {
				$injected    = $input['input'];
				$manual_hook = static function ( $output ) use ( $injected ) {
					return $injected;
				};
				add_filter( 'wp_ai_workflows_manual_trigger_output', $manual_hook );
			}
		}

		$result = WP_AI_Workflows_Workflow::execute_workflow( $workflow_id, $initial_data );

		if ( $manual_hook ) {
			remove_filter( 'wp_ai_workflows_manual_trigger_output', $manual_hook );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cloud/async execution returns a processing handle (no node_data). The
		// agent surface is local-first; surface the handle rather than blocking.
		if ( ! isset( $result['node_data'] ) || ! is_array( $result['node_data'] ) ) {
			return array(
				'execution_id' => isset( $result['execution_id'] ) ? $result['execution_id'] : 0,
				'status'       => isset( $result['status'] ) ? (string) $result['status'] : 'processing',
				'result'       => '',
				'outputs'      => array(),
			);
		}

		return array(
			'execution_id' => isset( $result['execution_id'] ) ? $result['execution_id'] : 0,
			'status'       => 'completed',
			'result'       => self::extract_result_text( $workflow, $result['node_data'] ),
			'outputs'      => self::extract_outputs( $result['node_data'] ),
		);
	}

	/**
	 * Reduce the raw node_data map to a flat { node_id: content } object.
	 *
	 * @param array $node_data Map of node id => [ 'type' => ..., 'content' => ... ].
	 * @return array
	 */
	private static function extract_outputs( $node_data ) {
		$outputs = array();
		foreach ( $node_data as $node_id => $entry ) {
			$content = is_array( $entry ) && array_key_exists( 'content', $entry ) ? $entry['content'] : $entry;
			if ( is_scalar( $content ) ) {
				$outputs[ (string) $node_id ] = (string) $content;
			} else {
				$outputs[ (string) $node_id ] = wp_json_encode( $content );
			}
		}
		return $outputs;
	}

	/**
	 * Best-effort single-string result: concatenate the content of output nodes,
	 * falling back to every node's content.
	 *
	 * @param array $workflow  Decoded workflow.
	 * @param array $node_data Execution node_data map.
	 * @return string
	 */
	private static function extract_result_text( $workflow, $node_data ) {
		$output_node_ids = array();
		if ( isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ) {
			foreach ( $workflow['nodes'] as $node ) {
				if ( isset( $node['type'] ) && 'output' === $node['type'] && isset( $node['id'] ) ) {
					$output_node_ids[] = $node['id'];
				}
			}
		}

		$parts = array();
		$ids   = ! empty( $output_node_ids ) ? $output_node_ids : array_keys( $node_data );
		foreach ( $ids as $node_id ) {
			if ( ! isset( $node_data[ $node_id ] ) ) {
				continue;
			}
			$entry   = $node_data[ $node_id ];
			$content = is_array( $entry ) && array_key_exists( 'content', $entry ) ? $entry['content'] : $entry;
			if ( is_scalar( $content ) && '' !== (string) $content ) {
				$parts[] = (string) $content;
			} elseif ( ! is_scalar( $content ) ) {
				$parts[] = wp_json_encode( $content );
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The active workflows that qualify for agent exposure: manual or webhook
	 * trigger, capped at MAX_EXPOSED.
	 *
	 * @return array List of decoded workflow arrays.
	 */
	public static function get_exposable_workflows() {
		if ( ! class_exists( 'WP_AI_Workflows_Workflow_DBAL' ) ) {
			return array();
		}

		$workflows = WP_AI_Workflows_Workflow_DBAL::get_workflows_by_status( 'active', self::MAX_EXPOSED );
		if ( ! is_array( $workflows ) ) {
			return array();
		}

		$exposable = array();
		foreach ( $workflows as $workflow ) {
			if ( empty( $workflow['id'] ) ) {
				continue;
			}
			$trigger = self::find_trigger( $workflow );
			if ( ! $trigger ) {
				continue;
			}
			$trigger_type = ! empty( $trigger['data']['triggerType'] ) ? $trigger['data']['triggerType'] : 'manual';
			if ( ! in_array( $trigger_type, array( 'manual', 'webhook' ), true ) ) {
				continue;
			}
			$exposable[] = $workflow;
		}

		return $exposable;
	}

	/**
	 * Locate the trigger node inside a workflow.
	 *
	 * @param array $workflow Decoded workflow.
	 * @return array|null
	 */
	private static function find_trigger( $workflow ) {
		if ( empty( $workflow['nodes'] ) || ! is_array( $workflow['nodes'] ) ) {
			return null;
		}
		foreach ( $workflow['nodes'] as $node ) {
			if ( isset( $node['type'] ) && 'trigger' === $node['type'] ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Deterministic, schema-valid ability name for a workflow id.
	 *
	 * @param string $workflow_id Raw workflow id.
	 * @return string
	 */
	public static function ability_name_for( $workflow_id ) {
		$slug = strtolower( (string) $workflow_id );
		$slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
		$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
		if ( '' === $slug ) {
			$slug = substr( md5( (string) $workflow_id ), 0, 12 );
		}
		return self::NAMESPACE_SLUG . '/run-' . $slug;
	}

	/**
	 * Register the MCP server with the official adapter, exposing our abilities
	 * as MCP tools. Only runs when the adapter fires `mcp_adapter_init` AND the
	 * owner has opted in.
	 *
	 * @param object $adapter The MCP adapter instance passed by the hook.
	 * @return void
	 */
	public function register_mcp_server( $adapter ) {
		if ( ! self::is_enabled() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$transport_class     = 'WP\\MCP\\Transport\\HttpTransport';
		$error_handler_class = 'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability_class = 'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';

		if ( ! class_exists( $transport_class ) ) {
			return;
		}

		$tools = array( self::NAMESPACE_SLUG . '/list-workflows' );
		foreach ( self::get_exposable_workflows() as $workflow ) {
			$tools[] = self::ability_name_for( $workflow['id'] );
		}

		$adapter->create_server(
			self::MCP_SERVER_ID,
			self::NAMESPACE_SLUG,
			'mcp',
			__( 'AI Workflow Automation', 'wp-ai-workflows' ),
			__( 'Run AI Workflow Automation workflows as MCP tools.', 'wp-ai-workflows' ),
			WP_AI_WORKFLOWS_PRO_VERSION,
			array( $transport_class ),
			class_exists( $error_handler_class ) ? $error_handler_class : null,
			class_exists( $observability_class ) ? $observability_class : null,
			$tools
		);
	}

	/**
	 * The MCP server endpoint URL, when the adapter is active. The adapter
	 * registers a REST route at `{namespace}/{route}` - here `wp-ai-workflows/mcp`.
	 *
	 * @return string|null
	 */
	public static function mcp_endpoint_url() {
		if ( ! self::mcp_adapter_active() ) {
			return null;
		}
		return esc_url_raw( rest_url( self::NAMESPACE_SLUG . '/mcp' ) );
	}

	/**
	 * Whether the official MCP Adapter plugin is active.
	 *
	 * @return bool
	 */
	public static function mcp_adapter_active() {
		return class_exists( 'WP\\MCP\\Core\\McpAdapter' ) || did_action( 'mcp_adapter_init' ) > 0;
	}

	/**
	 * Register the admin REST route that powers the settings panel.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'wp-ai-workflows/v1',
			'/agents/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_agents_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * REST: status payload for the "AI Agents (MCP)" settings panel.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_agents_status() {
		$workflows = array();
		if ( self::is_enabled() ) {
			$workflows = self::execute_list_workflows();
		}

		return new WP_REST_Response(
			array(
				'enabled'               => self::is_enabled(),
				'abilities_api'         => self::abilities_api_available(),
				'mcp_adapter_active'    => self::mcp_adapter_active(),
				'abilities_rest_url'    => esc_url_raw( rest_url( 'wp-abilities/v1/abilities' ) ),
				'mcp_endpoint_url'      => self::mcp_endpoint_url(),
				'exposed_workflows'     => $workflows,
				'exposed_count'         => count( $workflows ),
			),
			200
		);
	}
}
