<?php
/**
 * Agent Tool Registry - governed tool system for Agent mode. Applies per-tool
 * enable, confirmation-required (reusing human-tasks), rate limiting, and a
 * full audit trail; fail-closed (unknown/disabled tools never run, executor
 * errors become a bounded observation, never fatal to the loop).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Tool_Registry {

	/** @var array<string,WP_AI_Workflows_Agent_Tool> name => tool */
	private $tools = array();

	/** @var string */
	private $session_id;

	/** @var string */
	private $workflow_id;

	/** @var string Fully-qualified audit table name. */
	private $table;

	/**
	 * @param string $session_id  Current chat session id (for rate-limit + audit).
	 * @param string $workflow_id Owning workflow id (for audit).
	 */
	public function __construct( $session_id = '', $workflow_id = '' ) {
		global $wpdb;
		$this->session_id  = (string) $session_id;
		$this->workflow_id = (string) $workflow_id;
		$this->table       = $wpdb->prefix . 'wp_ai_workflows_agent_audit';
		$this->ensure_table_exists();
	}

	/**
	 * Create the audit table if missing. SHOW TABLES check is cached in a daily
	 * transient so it only touches the DB once per day per install.
	 */
	private function ensure_table_exists() {
		if ( false !== get_transient( 'wp_ai_workflows_agent_audit_ok' ) ) {
			return;
		}

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );

		if ( $exists !== $this->table ) {
			$this->create_tables();
		}

		set_transient( 'wp_ai_workflows_agent_audit_ok', 1, DAY_IN_SECONDS );
	}

	/**
	 * Create the agent tool audit table (args + result summary + status + time).
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = $wpdb->prepare(
			"CREATE TABLE IF NOT EXISTS %i (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(191) DEFAULT '',
				workflow_id VARCHAR(191) DEFAULT '',
				tool_name VARCHAR(191) NOT NULL,
				tool_kind VARCHAR(32) DEFAULT '',
				args LONGTEXT,
				status VARCHAR(32) DEFAULT '',
				result_summary TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY session_id (session_id),
				KEY workflow_id (workflow_id),
				KEY created_at (created_at)
			) " . $charset_collate, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is passed as %i; charset comes from $wpdb->get_charset_collate().
			$this->table
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param WP_AI_Workflows_Agent_Tool $tool Tool to register (last-wins by name).
	 * @return void
	 */
	public function register( WP_AI_Workflows_Agent_Tool $tool ) {
		$this->tools[ $tool->get_name() ] = $tool;
	}

	/**
	 * @return array<string,WP_AI_Workflows_Agent_Tool>
	 */
	public function all() {
		return $this->tools;
	}

	/**
	 * @param string $name Tool name.
	 * @return WP_AI_Workflows_Agent_Tool|null
	 */
	public function find( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Provider tool schemas for enabled, loop-executable tools only (native
	 * provider tools are governed/audited but not part of the function loop in
	 * Phase 1).
	 *
	 * @return array<int,array>
	 */
	public function get_tool_schemas() {
		$out = array();
		foreach ( $this->tools as $tool ) {
			if ( ! $tool->is_enabled() || $tool->is_provider_native() ) {
				continue;
			}
			$out[] = $tool->to_chat_tool();
		}
		return $out;
	}

	/**
	 * @return bool True when at least one enabled, loop-executable tool exists.
	 */
	public function has_callable_tools() {
		return ! empty( $this->get_tool_schemas() );
	}

	/**
	 * Execute a tool with all guardrails applied. Never throws - always returns
	 * a structured result the loop can turn into an observation.
	 *
	 * Return shape: array{ status: 'ok'|'error'|'unknown_tool'|'disabled'
	 *   |'rate_limited'|'pending_confirmation', tool:string, content:mixed,
	 *   task_id?:int }.
	 *
	 * @param string $name Tool name requested by the model.
	 * @param array  $args Decoded tool arguments.
	 * @param array  $ctx  Execution context (may carry confirmed_tools[]).
	 * @return array
	 */
	public function execute( $name, array $args, array $ctx = array() ) {
		$tool = $this->find( $name );

		// Fail-closed: never run something we do not know about.
		if ( ! $tool ) {
			$this->audit( $name, '', $args, 'unknown_tool', 'Tool not registered' );
			return array(
				'status'  => 'unknown_tool',
				'tool'    => $name,
				'content' => 'Unknown tool: ' . $name,
			);
		}

		if ( ! $tool->is_enabled() ) {
			$this->audit( $name, $tool->get_kind(), $args, 'disabled', 'Tool disabled by guardrail' );
			return array(
				'status'  => 'disabled',
				'tool'    => $name,
				'content' => 'This tool is disabled and cannot be used.',
			);
		}

		// Rate limiting (per session + tool, hourly window).
		$limit = $tool->get_rate_limit();
		if ( $limit > 0 && ! $this->consume_rate_budget( $name, $limit ) ) {
			$this->audit( $name, $tool->get_kind(), $args, 'rate_limited', 'Rate limit reached (' . $limit . '/hr)' );
			return array(
				'status'  => 'rate_limited',
				'tool'    => $name,
				'content' => 'Rate limit reached for this tool; try again later.',
			);
		}

		// Confirmation-required: pause and await a human OK (reuse human-tasks).
		$already_confirmed = ! empty( $ctx['confirmed_tools'][ $name ] );
		if ( $tool->requires_confirmation() && ! $already_confirmed ) {
			$task_id = $this->create_confirmation_task( $tool, $args, $ctx );
			$this->audit( $name, $tool->get_kind(), $args, 'pending_confirmation', 'Awaiting approval (task #' . $task_id . ')' );
			return array(
				'status'  => 'pending_confirmation',
				'tool'    => $name,
				'task_id' => $task_id,
				'content' => 'This action requires human approval before it can run.',
			);
		}

		// Execute - bounded, fail-safe.
		try {
			$result  = $tool->execute( $args, $ctx );
			$summary = $this->summarize( $result );
			$this->audit( $name, $tool->get_kind(), $args, 'ok', $summary );
			return array(
				'status'  => 'ok',
				'tool'    => $name,
				'content' => $result,
			);
		} catch ( Exception $e ) {
			$this->audit( $name, $tool->get_kind(), $args, 'error', $e->getMessage() );
			return array(
				'status'  => 'error',
				'tool'    => $name,
				'content' => 'Tool error: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Consume one unit of the hourly rate budget for a tool. Fail-open on any
	 * cache backend hiccup so a broken object cache never blocks legitimate use.
	 *
	 * @param string $name  Tool name.
	 * @param int    $limit Calls allowed per hour.
	 * @return bool True if allowed (budget consumed), false if exceeded.
	 */
	private function consume_rate_budget( $name, $limit ) {
		$key   = 'wpaiw_agent_rl_' . md5( $this->session_id . '|' . $name );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Create a human approval task for a confirmation-required tool. Best-effort:
	 * on any failure it logs and returns 0 (the loop still pauses on the returned
	 * pending status; the audit row is the authoritative record).
	 *
	 * @param WP_AI_Workflows_Agent_Tool $tool Tool awaiting approval.
	 * @param array                      $args Requested arguments.
	 * @param array                      $ctx  Execution context.
	 * @return int Human task id, or 0.
	 */
	private function create_confirmation_task( $tool, array $args, array $ctx ) {
		if ( ! class_exists( 'WP_AI_Workflows_Human_Tasks' ) ) {
			return 0;
		}
		try {
			$human = new WP_AI_Workflows_Human_Tasks();
			$id    = $human->create_task(
				array(
					'workflow_id'      => $this->workflow_id ? $this->workflow_id : 'agent',
					'execution_id'     => 0,
					'node_id'          => 'agent_confirmation',
					'assigned_user_id' => null,
					'assigned_role'    => 'administrator',
					'input_type'       => 'approval',
					'instructions'     => 'Agent requests approval to run tool "' . $tool->get_name() . '".',
					'content'          => wp_json_encode(
						array(
							'tool'        => $tool->get_name(),
							'kind'        => $tool->get_kind(),
							'arguments'   => $args,
							'session_id'  => $this->session_id,
							// Owning workflow so the resolver can rebuild the chat handler
							// even if the durable task row is read alone.
							'workflow_id' => $this->workflow_id,
							// Human-readable one-liner for the Tasks page + inbox row.
							'label'       => class_exists( 'WP_AI_Workflows_Agent_Approvals' )
								? WP_AI_Workflows_Agent_Approvals::describe( $tool->get_name(), $args )
								: $tool->get_name(),
						)
					),
				)
			);
			return (int) $id;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Agent confirmation task creation failed',
				'warning',
				array( 'error' => $e->getMessage() )
			);
			return 0;
		}
	}

	/**
	 * Record a single tool execution (args + bounded result summary + status +
	 * timestamp). Never throws.
	 *
	 * @param string $name    Tool name.
	 * @param string $kind    Tool kind.
	 * @param mixed  $args    Arguments (json-encoded on store).
	 * @param string $status  Outcome status.
	 * @param string $summary Bounded result/summary text.
	 * @return void
	 */
	private function audit( $name, $kind, $args, $status, $summary ) {
		global $wpdb;
		try {
			$wpdb->insert(
				$this->table,
				array(
					'session_id'     => $this->session_id,
					'workflow_id'    => $this->workflow_id,
					'tool_name'      => (string) $name,
					'tool_kind'      => (string) $kind,
					'args'           => wp_json_encode( $args ),
					'status'         => (string) $status,
					'result_summary' => mb_substr( (string) $summary, 0, 1000 ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Agent audit write failed',
				'warning',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Fetch recent audit rows for a session (for the transcript / step view).
	 *
	 * @param string $session_id Session id.
	 * @param int    $limit      Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_audit_for_session( $session_id, $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool_name, tool_kind, status, result_summary, created_at
					FROM %i WHERE session_id = %s ORDER BY id DESC LIMIT %d",
				$this->table,
				$session_id,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Produce a short, safe summary of a tool result for the audit log.
	 *
	 * @param mixed $result Tool return value.
	 * @return string
	 */
	private function summarize( $result ) {
		if ( is_string( $result ) ) {
			return mb_substr( $result, 0, 500 );
		}
		$encoded = wp_json_encode( $result );
		return mb_substr( is_string( $encoded ) ? $encoded : '[unserializable result]', 0, 500 );
	}
}
