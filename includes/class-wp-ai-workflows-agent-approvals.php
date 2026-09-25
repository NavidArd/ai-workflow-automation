<?php
/**
 * Agent Approvals - the tool-approval surface + live-resume coordinator.
 *
 * Lists pending approvals for the Operator Inbox (a view over the human-tasks
 * rows, never forked state) and resolves one by replaying the LOCKED tool call
 * (args captured at pause time - no TOCTOU) through the orchestrator, delivering
 * the continuation live and auditing the outcome.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Approvals {

	/** Node id every agent tool-approval task carries (shared with the registry). */
	const NODE_ID = 'agent_confirmation';

	/**
	 * @param mixed $task A human task (array from get_task()).
	 * @return bool True when the task is an agent tool-approval task.
	 */
	public static function is_agent_task( $task ) {
		return is_array( $task ) && isset( $task['node_id'] ) && self::NODE_ID === (string) $task['node_id'];
	}

	/**
	 * Count pending tool approvals (for the nav badge + inbox stats).
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_ai_workflows_human_tasks';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE node_id = %s AND status = %s",
				$table,
				self::NODE_ID,
				'pending'
			)
		);
	}

	/**
	 * Pending approvals enriched for the Operator Inbox: the conversation it
	 * belongs to, WHAT the agent wants to do (tool + human-readable args), and a
	 * snippet of the visitor's last message for context.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_pending( $limit = 100 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_ai_workflows_human_tasks';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, content, workflow_id, workflow_name, created_at FROM %i WHERE node_id = %s AND status = %s ORDER BY id DESC LIMIT %d",
				$table,
				self::NODE_ID,
				'pending',
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
		$out            = array();
		foreach ( (array) $rows as $r ) {
			$content    = json_decode( (string) $r['content'], true );
			$content    = is_array( $content ) ? $content : array();
			$session_id = isset( $content['session_id'] ) ? (string) $content['session_id'] : '';
			$tool       = isset( $content['tool'] ) ? (string) $content['tool'] : '';
			$args       = isset( $content['arguments'] ) && is_array( $content['arguments'] ) ? $content['arguments'] : array();
			$label      = isset( $content['label'] ) && '' !== (string) $content['label']
				? (string) $content['label']
				: self::describe( $tool, $args );

			// The visitor's most recent message - context for the reviewer.
			$last     = '';
			$visitor  = '';
			if ( '' !== $session_id ) {
				$last_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT content FROM %i WHERE session_id = %s AND role = %s ORDER BY id DESC LIMIT 1",
						$messages_table,
						$session_id,
						'user'
					),
					ARRAY_A
				);
				$last = $last_row && isset( $last_row['content'] ) ? (string) $last_row['content'] : '';
			}
			$visitor = '' !== $session_id ? ( 'Visitor ' . substr( $session_id, 0, 6 ) ) : 'Visitor';

			$out[] = array(
				'id'           => (int) $r['id'],
				'type'         => 'approval',
				'session_id'   => $session_id,
				'workflow_id'  => isset( $r['workflow_id'] ) ? (string) $r['workflow_id'] : '',
				'workflow_name' => isset( $r['workflow_name'] ) ? (string) $r['workflow_name'] : '',
				'tool'         => $tool,
				'tool_label'   => self::humanize_tool( $tool ),
				'args'         => $args,
				'summary'      => $label,
				'visitor'      => $visitor,
				'last_snippet' => mb_substr( wp_strip_all_tags( $last ), 0, 140 ),
				'created_at'   => isset( $r['created_at'] ) ? (string) $r['created_at'] : '',
			);
		}
		return $out;
	}

	/**
	 * True while a session has a pending tool approval (drives the widget's
	 * keep-polling-through-the-pause flag). Cheap transient lookup.
	 *
	 * @param string $session_id Session id.
	 * @return bool
	 */
	public static function is_pending_for_session( $session_id ) {
		if ( '' === (string) $session_id ) {
			return false;
		}
		return false !== get_transient( 'wpaiw_agent_pending_' . md5( (string) $session_id ) );
	}

	/**
	 * Persist the arg-locked resume state for a pause + flag the session so the
	 * widget keeps polling. Stored keyed by task id (durable copy of the exact
	 * conversation + the one locked call).
	 *
	 * @param int    $task_id     Human task id.
	 * @param array  $pending     Orchestrator pending state (messages+tool+args).
	 * @param string $session_id  Chat session id.
	 * @param string $workflow_id Owning workflow id.
	 * @return void
	 */
	public static function remember_pending( $task_id, array $pending, $session_id, $workflow_id ) {
		$task_id = (int) $task_id;
		if ( $task_id <= 0 ) {
			return;
		}
		$pending['session_id']  = (string) $session_id;
		$pending['workflow_id'] = (string) $workflow_id;
		set_transient( 'wpaiw_agent_resume_' . $task_id, $pending, DAY_IN_SECONDS );
		if ( '' !== (string) $session_id ) {
			set_transient( 'wpaiw_agent_pending_' . md5( (string) $session_id ), $task_id, DAY_IN_SECONDS );
		}
	}

	/**
	 * Approve or reject a pending tool approval and resume the conversation.
	 *
	 * @param int  $task_id  Human task id.
	 * @param bool $approved True to run the tool (locked args), false to decline.
	 * @param int  $user_id  Acting operator (for the task action + audit).
	 * @return array|WP_Error|false Result array on success; WP_Error on a handled
	 *                              failure; false when the task is not an agent task.
	 */
	public static function resolve( $task_id, $approved, $user_id = 0 ) {
		$task_id = (int) $task_id;
		if ( ! class_exists( 'WP_AI_Workflows_Human_Tasks' ) ) {
			return new WP_Error( 'unavailable', 'Approvals are unavailable.', array( 'status' => 501 ) );
		}

		$human = new WP_AI_Workflows_Human_Tasks();
		$task  = $human->get_task( $task_id );
		if ( ! self::is_agent_task( $task ) ) {
			return false;
		}
		if ( isset( $task['status'] ) && 'pending' !== (string) $task['status'] ) {
			return new WP_Error( 'already_resolved', 'This approval was already handled.', array( 'status' => 409 ) );
		}

		$content     = is_array( $task['content'] ) ? $task['content'] : json_decode( (string) $task['content'], true );
		$content     = is_array( $content ) ? $content : array();
		$session_id  = isset( $content['session_id'] ) ? (string) $content['session_id'] : '';
		$workflow_id = isset( $content['workflow_id'] ) && '' !== (string) $content['workflow_id']
			? (string) $content['workflow_id']
			: ( isset( $task['workflow_id'] ) ? (string) $task['workflow_id'] : '' );
		$tool        = isset( $content['tool'] ) ? (string) $content['tool'] : '';
		$args        = isset( $content['arguments'] ) && is_array( $content['arguments'] ) ? $content['arguments'] : array();

		// Flip the durable task status first (shared with the Tasks page - one
		// state, two views). Reject/revert both map to a decline.
		$human->update_task_status( $task_id, $approved ? 'approved' : 'rejected', (int) $user_id );

		// Load the exact pause state (locked context + call). Fallback rebuilds
		// from the session history + the stored args (still arg-locked) so a task
		// created before this mechanism existed still resumes.
		$pending = get_transient( 'wpaiw_agent_resume_' . $task_id );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		if ( empty( $pending['tool'] ) ) {
			$pending['tool'] = $tool;
		}
		if ( ! isset( $pending['args'] ) || ! is_array( $pending['args'] ) ) {
			$pending['args'] = $args;
		}
		if ( empty( $pending['call_id'] ) ) {
			$pending['call_id'] = 'call_resume_' . $task_id;
		}

		// Drop the durable resume copy now ($pending is already loaded into memory).
		// The SESSION pending flag stays SET until the resumed reply is queued below:
		// clearing it early lets the widget's poll see approval_pending:false before
		// anything is deliverable, so the reply lands in an empty queue (race fix).
		delete_transient( 'wpaiw_agent_resume_' . $task_id );

		$final  = '';
		$blocks = array();
		if ( '' !== $workflow_id && '' !== $session_id && class_exists( 'WP_AI_Workflows_Chat_Handler' ) ) {
			try {
				$handler = new WP_AI_Workflows_Chat_Handler( $workflow_id, $session_id );
				if ( ! isset( $pending['messages'] ) || ! is_array( $pending['messages'] ) ) {
					$pending = $handler->rebuild_pending_for_resume( $pending );
				}
				$res    = $handler->resume_agent_approval( $pending, (bool) $approved );
				$final  = isset( $res['final'] ) ? (string) $res['final'] : '';
				$blocks = isset( $res['blocks'] ) && is_array( $res['blocks'] ) ? $res['blocks'] : array();
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Agent approval resume failed',
					'error',
					array(
						'task_id' => $task_id,
						'error'   => $e->getMessage(),
					)
				);
			}
		}

		// Deliver the continuation live over the existing handoff poll transport.
		// The durable copy was already written by resume_agent_approval(), so this
		// is a live-only (transient) delivery to avoid a duplicate history row.
		if ( '' !== $session_id && '' !== trim( wp_strip_all_tags( (string) $final ) ) && class_exists( 'WP_AI_Workflows_Handoff_Store' ) ) {
			$store = new WP_AI_Workflows_Handoff_Store();
			$store->queue_inbound(
				$session_id,
				(string) $final,
				'agent_resume_' . $task_id,
				null,
				array(
					'durable' => false,
					'source'  => 'agent',
					'blocks'  => $blocks,
				)
			);
		}

		// Clear the pause flag LAST - only after the reply is queued (see above).
		if ( '' !== $session_id ) {
			delete_transient( 'wpaiw_agent_pending_' . md5( $session_id ) );
		}

		self::audit_decision( $session_id, $workflow_id, $tool, $args, $approved ? 'approved' : 'rejected' );

		return array(
			'ok'         => true,
			'decision'   => $approved ? 'approved' : 'rejected',
			'session_id' => $session_id,
			'final'      => $final,
			'blocks'     => $blocks,
		);
	}

	/**
	 * A human-readable one-liner for a tool call, e.g. `Search products: "beanies"`.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Tool arguments.
	 * @return string
	 */
	public static function describe( $tool, $args ) {
		$label = self::humanize_tool( (string) $tool );
		$value = self::primary_arg_value( is_array( $args ) ? $args : array() );
		if ( '' !== $value ) {
			return $label . ': "' . $value . '"';
		}
		return $label;
	}

	/**
	 * Turn a tool name into a friendly verb phrase.
	 *
	 * @param string $tool Tool name.
	 * @return string
	 */
	public static function humanize_tool( $tool ) {
		$tool = (string) $tool;
		$map  = array(
			'search_products'       => 'Search products',
			'search_content'        => 'Search content',
			'knowledge_base_search' => 'Search the knowledge base',
			'handoff_to_human'      => 'Hand off to a human',
			'calculator'            => 'Calculate',
			'get_datetime'          => 'Get the date & time',
			'web_search'            => 'Search the web',
			'file_search'           => 'Search files',
		);
		if ( isset( $map[ $tool ] ) ) {
			return $map[ $tool ];
		}
		if ( 0 === strpos( $tool, 'action_' ) ) {
			return 'Run action';
		}
		if ( 0 === strpos( $tool, 'mcp_' ) ) {
			$rest = substr( $tool, 4 );
			return 'Run ' . ucwords( str_replace( array( '_', '-' ), ' ', $rest ) );
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $tool ) );
	}

	/**
	 * Pick the most meaningful scalar argument for the one-liner summary.
	 *
	 * @param array $args Arguments.
	 * @return string Bounded, tag-stripped value ('' when none).
	 */
	private static function primary_arg_value( array $args ) {
		foreach ( array( 'query', 'q', 'search', 'term', 'text', 'reason', 'prompt', 'message', 'name' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				return mb_substr( wp_strip_all_tags( (string) $args[ $key ] ), 0, 80 );
			}
		}
		foreach ( $args as $v ) {
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				return mb_substr( wp_strip_all_tags( (string) $v ), 0, 80 );
			}
		}
		return '';
	}

	/**
	 * Write an audit row for an approval decision (mirrors the tool-execution
	 * audit table schema). Best-effort - never throws.
	 *
	 * @param string $session_id  Session id.
	 * @param string $workflow_id Workflow id.
	 * @param string $tool        Tool name.
	 * @param array  $args        Locked args.
	 * @param string $status      'approved' | 'rejected'.
	 * @return void
	 */
	private static function audit_decision( $session_id, $workflow_id, $tool, $args, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_ai_workflows_agent_audit';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		try {
			$wpdb->insert(
				$table,
				array(
					'session_id'     => (string) $session_id,
					'workflow_id'    => (string) $workflow_id,
					'tool_name'      => (string) $tool,
					'tool_kind'      => 'approval',
					'args'           => wp_json_encode( $args ),
					'status'         => (string) $status,
					'result_summary' => 'approved' === $status
						? 'Operator approved the tool call; agent resumed.'
						: 'Operator declined the tool call; agent notified.',
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log( 'Agent approval audit failed', 'warning', array( 'error' => $e->getMessage() ) );
		}
	}
}
