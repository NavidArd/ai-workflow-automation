<?php
/**
 * Agent Tools builder - adapts existing plugin capabilities (and a couple of
 * safe built-ins) into governed {@see WP_AI_Workflows_Agent_Tool} instances and
 * loads them into a {@see WP_AI_Workflows_Agent_Tool_Registry}.
 *
 * Sources adapted (Phase 1):
 *   - Built-ins: calculator, get_datetime (dependency-free; make an agent useful
 *     with zero configuration and give the loop deterministic, chainable tools).
 *   - WP chat "actions": each becomes a function tool that dispatches its
 *     workflow exactly as today (side-effecting; returns a dispatch observation).
 *   - Knowledge base (RAG) search: when a KB is configured on the node.
 *   - MCP tools: when an MCP server is configured under data.agent.mcp.
 *   - Provider-native search (OpenAI web/file): registered for governance/audit
 *     only in Phase 1 (executed by the provider, not our loop).
 *
 * Per-tool guardrail overrides come from data.agent.tools[<name>].
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Tools {

	/**
	 * Build a fully-governed registry for a chat/agent node.
	 *
	 * @param string   $session_id        Chat session id.
	 * @param string   $workflow_id       Workflow id.
	 * @param array    $agent_config      data.agent (enabled, maxSteps, tools{}, mcp{}).
	 * @param array    $actions           data.actions (chat "actions").
	 * @param mixed    $openai_tools      data.openaiTools (native search config).
	 * @param mixed    $knowledge_base    data.knowledgeBase config.
	 * @param callable $action_dispatcher fn(string $action_id, array $args):string - dispatches the action workflow, returns an observation.
	 * @return WP_AI_Workflows_Agent_Tool_Registry
	 */
	public static function build_registry( $session_id, $workflow_id, $agent_config, $actions, $openai_tools, $knowledge_base, $action_dispatcher ) {
		$registry  = new WP_AI_Workflows_Agent_Tool_Registry( $session_id, $workflow_id );
		$overrides = ( is_array( $agent_config ) && isset( $agent_config['tools'] ) && is_array( $agent_config['tools'] ) )
			? $agent_config['tools']
			: array();

		// 1) Safe built-ins.
		self::register_builtins( $registry, $overrides );

		// 2) WP chat actions (dispatch adapters).
		if ( is_array( $actions ) ) {
			foreach ( $actions as $action ) {
				self::register_action_tool( $registry, $action, $overrides, $action_dispatcher );
			}
		}

		// 3) Knowledge base search (RAG as a tool).
		self::register_kb_tool( $registry, $knowledge_base, $overrides );

		// 4) MCP tools (only when an MCP server is configured on the agent).
		if ( is_array( $agent_config ) && ! empty( $agent_config['mcp'] ) && is_array( $agent_config['mcp'] ) ) {
			self::register_mcp_tools( $registry, $agent_config['mcp'], $overrides );
		}

		// 5) Provider-native search (governance/audit only in Phase 1).
		self::register_native_tools( $registry, $openai_tools, $overrides );

		// 5b) Opt-in content/commerce "concierge" tools (search_content always;
		//     search_products only when WooCommerce is active). Default OFF, so a
		//     simple chatbot is unaffected until the operator enables them.
		if ( class_exists( 'WP_AI_Workflows_Agent_Content_Tools' ) ) {
			WP_AI_Workflows_Agent_Content_Tools::register( $registry, $overrides );
		}

		// 6) Human handoff (Phase 2a) - only when handoff is enabled on the node.
		//    The agent can call handoff_to_human to pass control to a person.
		if ( is_array( $agent_config )
			&& class_exists( 'WP_AI_Workflows_Handoff_Manager' )
			&& WP_AI_Workflows_Handoff_Manager::is_enabled( $agent_config ) ) {
			self::register_handoff_tool( $registry, $session_id, $workflow_id, $agent_config['handoff'], $overrides );
		}

		return $registry;
	}

	/**
	 * Register the governed `handoff_to_human` tool. When the agent calls it, the
	 * configured provider opens a handoff and the conversation flips to human
	 * control (the bot then stops auto-responding - enforced by the chat handler).
	 *
	 * @param WP_AI_Workflows_Agent_Tool_Registry $registry    Registry.
	 * @param string                               $session_id  Session id.
	 * @param string                               $workflow_id Workflow id.
	 * @param array                                $handoff_raw data.agent.handoff.
	 * @param array                                $overrides   Per-tool overrides.
	 * @return void
	 */
	private static function register_handoff_tool( $registry, $session_id, $workflow_id, $handoff_raw, $overrides ) {
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'handoff_to_human',
				'Hand this conversation off to a human support agent. Call this when the visitor asks to talk to a person, when you cannot resolve their issue, or when the request needs human judgement. Provide a short reason and a summary of the conversation so far.',
				array(
					'type'       => 'object',
					'properties' => array(
						'reason'  => array(
							'type'        => 'string',
							'description' => 'Why a human is needed (one short sentence).',
						),
						'summary' => array(
							'type'        => 'string',
							'description' => 'A brief summary of the conversation and what the visitor needs, for the human agent.',
						),
					),
					'required'   => array( 'reason' ),
				),
				WP_AI_Workflows_Agent_Tool::KIND_BUILTIN,
				self::guardrails_for( $overrides, 'handoff_to_human', true ),
				function ( $args, $ctx ) use ( $session_id, $workflow_id, $handoff_raw ) {
					if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
						return 'Human handoff is not available.';
					}
					$manager = new WP_AI_Workflows_Handoff_Manager();
					$config  = WP_AI_Workflows_Handoff_Manager::normalize_config( $handoff_raw );
					$result  = $manager->start(
						$session_id,
						$workflow_id,
						$config,
						array(
							'reason'  => isset( $args['reason'] ) ? (string) $args['reason'] : '',
							'summary' => isset( $args['summary'] ) ? (string) $args['summary'] : '',
						)
					);
					if ( empty( $result['ok'] ) ) {
						return 'I was unable to connect a human right now'
							. ( isset( $result['error'] ) && $result['error'] ? ' (' . $result['error'] . ')' : '' ) . '.';
					}
					return $result['message'];
				}
			)
		);
	}

	/**
	 * Resolve per-tool guardrails from the node's overrides map, applying safe
	 * defaults. A tool is enabled unless explicitly disabled.
	 *
	 * @param array  $overrides       All tool overrides.
	 * @param string $name            Tool name.
	 * @param bool   $default_enabled Default enabled state.
	 * @return array
	 */
	private static function guardrails_for( $overrides, $name, $default_enabled = true ) {
		$o = isset( $overrides[ $name ] ) && is_array( $overrides[ $name ] ) ? $overrides[ $name ] : array();
		return array(
			'enabled'              => isset( $o['enabled'] ) ? (bool) $o['enabled'] : (bool) $default_enabled,
			'requiresConfirmation' => ! empty( $o['requiresConfirmation'] ),
			'rateLimit'            => isset( $o['rateLimit'] ) ? (int) $o['rateLimit'] : 0,
		);
	}

	private static function register_builtins( $registry, $overrides ) {
		// calculator - structured, eval-free arithmetic.
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'calculator',
				'Perform basic arithmetic on two numbers. Use for any calculation.',
				array(
					'type'       => 'object',
					'properties' => array(
						'a'         => array(
							'type'        => 'number',
							'description' => 'First operand.',
						),
						'b'         => array(
							'type'        => 'number',
							'description' => 'Second operand.',
						),
						'operation' => array(
							'type'        => 'string',
							'enum'        => array( 'add', 'subtract', 'multiply', 'divide' ),
							'description' => 'The arithmetic operation.',
						),
					),
					'required'   => array( 'a', 'b', 'operation' ),
				),
				WP_AI_Workflows_Agent_Tool::KIND_BUILTIN,
				self::guardrails_for( $overrides, 'calculator', true ),
				array( __CLASS__, 'exec_calculator' )
			)
		);

		// get_datetime - current server date/time, structured.
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'get_datetime',
				'Get the current server date and time (ISO string plus year, month, day and unix timestamp).',
				array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				WP_AI_Workflows_Agent_Tool::KIND_BUILTIN,
				self::guardrails_for( $overrides, 'get_datetime', true ),
				array( __CLASS__, 'exec_get_datetime' )
			)
		);
	}

	/**
	 * @param array $args {a,b,operation}
	 * @param array $ctx
	 * @return array
	 * @throws Exception On invalid operation or divide-by-zero.
	 */
	public static function exec_calculator( array $args, array $ctx ) {
		$a  = isset( $args['a'] ) ? (float) $args['a'] : 0;
		$b  = isset( $args['b'] ) ? (float) $args['b'] : 0;
		$op = isset( $args['operation'] ) ? (string) $args['operation'] : '';

		switch ( $op ) {
			case 'add':
				$result = $a + $b;
				break;
			case 'subtract':
				$result = $a - $b;
				break;
			case 'multiply':
				$result = $a * $b;
				break;
			case 'divide':
				if ( 0.0 === $b ) {
					throw new Exception( 'Division by zero.' );
				}
				$result = $a / $b;
				break;
			default:
				throw new Exception( 'Unknown operation: ' . esc_html( $op ) );
		}

		return array(
			'result'    => $result,
			'operation' => $op,
			'a'         => $a,
			'b'         => $b,
		);
	}

	/**
	 * @param array $args
	 * @param array $ctx
	 * @return array
	 */
	public static function exec_get_datetime( array $args, array $ctx ) {
		$now = current_time( 'timestamp' );
		return array(
			'iso'       => gmdate( 'c', $now ),
			'year'      => (int) gmdate( 'Y', $now ),
			'month'     => (int) gmdate( 'n', $now ),
			'day'       => (int) gmdate( 'j', $now ),
			'time'      => gmdate( 'H:i:s', $now ),
			'timestamp' => (int) $now,
		);
	}

	private static function register_action_tool( $registry, $action, $overrides, $action_dispatcher ) {
		if ( empty( $action['id'] ) ) {
			return;
		}
		$name       = 'action_' . $action['id'];
		$properties = array();
		$required   = array();

		if ( ! empty( $action['fields'] ) && is_array( $action['fields'] ) ) {
			foreach ( $action['fields'] as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$def = array(
					'type'        => self::map_field_type( isset( $field['type'] ) ? $field['type'] : 'text' ),
					'description' => isset( $field['description'] ) ? (string) $field['description'] : '',
				);
				if ( ! empty( $field['options'] ) ) {
					$def['enum'] = $field['options'];
				}
				$properties[ $field['name'] ] = $def;
				if ( ! empty( $field['required'] ) ) {
					$required[] = $field['name'];
				}
			}
		}

		$action_id = (string) $action['id'];
		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				$name,
				isset( $action['description'] ) ? (string) $action['description'] : ( 'Run the "' . ( $action['name'] ?? $name ) . '" action.' ),
				array(
					'type'                 => 'object',
					'properties'           => empty( $properties ) ? new stdClass() : $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				WP_AI_Workflows_Agent_Tool::KIND_FUNCTION,
				// Side-effecting actions default to confirmation-required for safety.
				self::guardrails_for( $overrides, $name, true ),
				function ( $args, $ctx ) use ( $action_id, $action_dispatcher ) {
					return call_user_func( $action_dispatcher, $action_id, (array) $args );
				}
			)
		);
	}

	private static function register_kb_tool( $registry, $knowledge_base, $overrides ) {
		if ( ! is_array( $knowledge_base ) || empty( $knowledge_base['enabled'] ) || empty( $knowledge_base['kbId'] ) ) {
			return;
		}
		if ( ! class_exists( 'WP_AI_Workflows_Knowledge_Base' ) ) {
			return;
		}
		$kb_id = (string) $knowledge_base['kbId'];
		$top_k = isset( $knowledge_base['topK'] ) ? (int) $knowledge_base['topK'] : 5;

		$registry->register(
			new WP_AI_Workflows_Agent_Tool(
				'knowledge_base_search',
				'Search the connected knowledge base for passages relevant to a query. Use before answering questions that may be covered by the knowledge base.',
				array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'What to look up in the knowledge base.',
						),
					),
					'required'   => array( 'query' ),
				),
				WP_AI_Workflows_Agent_Tool::KIND_KB,
				self::guardrails_for( $overrides, 'knowledge_base_search', true ),
				function ( $args, $ctx ) use ( $kb_id, $top_k ) {
					$query = isset( $args['query'] ) ? (string) $args['query'] : '';
					if ( '' === trim( $query ) ) {
						return array( 'results' => array() );
					}
					$rows = WP_AI_Workflows_Knowledge_Base::search( $kb_id, $query, $top_k );
					$out  = array();
					foreach ( (array) $rows as $r ) {
						$out[] = array(
							'content'    => isset( $r['content'] ) ? (string) $r['content'] : '',
							'similarity' => isset( $r['similarity'] ) ? (float) $r['similarity'] : 0.0,
						);
					}
					return array( 'results' => $out );
				}
			)
		);
	}

	private static function register_mcp_tools( $registry, $mcp_config, $overrides ) {
		if ( ! class_exists( 'WP_AI_Workflows_MCP_Client' ) ) {
			return;
		}
		$server_tools = isset( $mcp_config['tools'] ) && is_array( $mcp_config['tools'] ) ? $mcp_config['tools'] : array();
		if ( empty( $server_tools ) ) {
			return;
		}
		$server_config = $mcp_config; // Passed to execute_tool (serverType/connectionConfig/customServerConfig).

		foreach ( $server_tools as $mcp_tool ) {
			$tool_name = isset( $mcp_tool['name'] ) ? (string) $mcp_tool['name'] : '';
			if ( '' === $tool_name ) {
				continue;
			}
			$name       = 'mcp_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $tool_name );
			$schema     = isset( $mcp_tool['inputSchema'] ) && is_array( $mcp_tool['inputSchema'] )
				? $mcp_tool['inputSchema']
				: array(
					'type'       => 'object',
					'properties' => new stdClass(),
				);
			$upstream   = $tool_name;
			$registry->register(
				new WP_AI_Workflows_Agent_Tool(
					$name,
					isset( $mcp_tool['description'] ) ? (string) $mcp_tool['description'] : ( 'MCP tool: ' . $tool_name ),
					$schema,
					WP_AI_Workflows_Agent_Tool::KIND_MCP,
					self::guardrails_for( $overrides, $name, true ),
					function ( $args, $ctx ) use ( $server_config, $upstream ) {
						$res = WP_AI_Workflows_MCP_Client::execute_tool( $server_config, $upstream, (array) $args );
						if ( is_array( $res ) && isset( $res['type'] ) && 'error' === $res['type'] ) {
							throw new Exception( esc_html( isset( $res['content'] ) ? (string) $res['content'] : 'MCP error' ) );
						}
						return is_array( $res ) && isset( $res['content'] ) ? $res['content'] : $res;
					}
				)
			);
		}
	}

	private static function register_native_tools( $registry, $openai_tools, $overrides ) {
		if ( ! is_array( $openai_tools ) ) {
			return;
		}
		if ( ! empty( $openai_tools['webSearch']['enabled'] ) ) {
			$registry->register(
				new WP_AI_Workflows_Agent_Tool(
					'web_search',
					'Provider-native web search (executed by the model provider).',
					array(
						'type'       => 'object',
						'properties' => new stdClass(),
					),
					WP_AI_Workflows_Agent_Tool::KIND_NATIVE,
					self::guardrails_for( $overrides, 'web_search', true ),
					'__return_null'
				)
			);
		}
		if ( ! empty( $openai_tools['fileSearch']['enabled'] ) ) {
			$registry->register(
				new WP_AI_Workflows_Agent_Tool(
					'file_search',
					'Provider-native file search / RAG (executed by the model provider).',
					array(
						'type'       => 'object',
						'properties' => new stdClass(),
					),
					WP_AI_Workflows_Agent_Tool::KIND_NATIVE,
					self::guardrails_for( $overrides, 'file_search', true ),
					'__return_null'
				)
			);
		}
	}

	/**
	 * Map a chat action field type to a JSON-schema type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	private static function map_field_type( $type ) {
		$map = array(
			'text'    => 'string',
			'number'  => 'number',
			'email'   => 'string',
			'url'     => 'string',
			'select'  => 'string',
			'boolean' => 'boolean',
			'array'   => 'array',
			'phone'   => 'string',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 'string';
	}
}
