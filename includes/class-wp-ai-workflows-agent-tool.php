<?php
/**
 * Agent Tool - a single governed capability the agent can invoke.
 *
 * A uniform abstraction over every kind of capability the Agent node can use
 * (WP actions, MCP tools, KB search, provider-native search, safe built-ins).
 * Each tool carries its own JSON schema, per-tool guardrails and an executor
 * callable. The {@see WP_AI_Workflows_Agent_Tool_Registry} builds, governs and
 * runs these. Introduced with Agent mode (Phase 1) - additive, opt-in.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Tool {

	/* Tool kinds - where the capability comes from. */
	const KIND_FUNCTION = 'function'; // WP chat "action" (dispatches a workflow).
	const KIND_MCP      = 'mcp';      // External MCP server tool.
	const KIND_KB       = 'kb';       // Knowledge base (RAG) search.
	const KIND_NATIVE   = 'native';   // Provider-native (OpenAI web/file search).
	const KIND_BUILTIN  = 'builtin';  // Safe, dependency-free built-in utility.

	/** @var string Tool name exposed to the model (must be [A-Za-z0-9_-]+). */
	private $name;

	/** @var string Human/model-readable description. */
	private $description;

	/** @var array JSON-schema `parameters` object. */
	private $json_schema;

	/** @var string One of the KIND_* constants. */
	private $kind;

	/** @var array{enabled:bool,requiresConfirmation:bool,rateLimit:int} */
	private $guardrails;

	/** @var callable(array $args, array $ctx):mixed */
	private $executor;

	/**
	 * @param string   $name        Model-facing tool name.
	 * @param string   $description What the tool does.
	 * @param array    $json_schema JSON-schema parameters object.
	 * @param string   $kind        KIND_* constant.
	 * @param array    $guardrails  Per-tool guardrails (see defaults below).
	 * @param callable $executor    Runs the tool: fn(array $args, array $ctx).
	 */
	public function __construct( $name, $description, array $json_schema, $kind, array $guardrails, $executor ) {
		$this->name        = (string) $name;
		$this->description = (string) $description;
		$this->json_schema = $this->normalize_schema( $json_schema );
		$this->kind        = (string) $kind;
		$this->guardrails  = wp_parse_args(
			$guardrails,
			array(
				'enabled'              => true,
				'requiresConfirmation' => false,
				'rateLimit'            => 0, // 0 = unlimited.
			)
		);
		$this->executor = $executor;
	}

	/**
	 * Ensure a valid JSON-schema object so providers never reject the tool.
	 *
	 * @param array $schema Caller-supplied schema.
	 * @return array
	 */
	private function normalize_schema( array $schema ) {
		if ( empty( $schema ) || ! isset( $schema['type'] ) ) {
			return array(
				'type'       => 'object',
				'properties' => new stdClass(),
			);
		}
		if ( 'object' === $schema['type'] && empty( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}
		return $schema;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_description() {
		return $this->description;
	}

	public function get_kind() {
		return $this->kind;
	}

	public function is_enabled() {
		return ! empty( $this->guardrails['enabled'] );
	}

	public function requires_confirmation() {
		return ! empty( $this->guardrails['requiresConfirmation'] );
	}

	/**
	 * @return int Per-session-per-hour call cap (0 = unlimited).
	 */
	public function get_rate_limit() {
		return (int) $this->guardrails['rateLimit'];
	}

	/**
	 * Provider-native tools (OpenAI web/file search) are executed by the model
	 * provider, not by our orchestration loop, so they are excluded from the
	 * Chat-Completions tool schema list and never loop-executed in Phase 1.
	 *
	 * @return bool
	 */
	public function is_provider_native() {
		return self::KIND_NATIVE === $this->kind;
	}

	/**
	 * Provider tool schema (OpenAI/OpenRouter Chat-Completions "function" form).
	 *
	 * @return array
	 */
	public function to_chat_tool() {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->name,
				'description' => $this->description,
				'parameters'  => $this->json_schema,
			),
		);
	}

	/**
	 * Run the tool. Callers (the registry) apply guardrails/audit around this.
	 *
	 * @param array $args Model-provided arguments.
	 * @param array $ctx  Execution context (session_id, workflow_id, …).
	 * @return mixed
	 */
	public function execute( array $args, array $ctx ) {
		return call_user_func( $this->executor, $args, $ctx );
	}
}
