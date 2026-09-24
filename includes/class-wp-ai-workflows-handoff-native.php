<?php
/**
 * Native Handoff Provider — zero external dependency.
 *
 * Agent mode Phase 2a. Hands off to a WordPress admin/agent inside the site: it
 * creates a human task (reusing {@see WP_AI_Workflows_Human_Tasks}) carrying the
 * transcript + context, so an operator can see the conversation and reply from
 * the existing Tasks UI (or the lightweight agent-inbox reply endpoint). The
 * operator's replies are relayed back to the visitor through the standard
 * inbound path.
 *
 * Because "inbound" here is a logged-in operator (not an external service), the
 * webhook is gated by a WordPress capability + nonce rather than an HMAC secret.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Native implements WP_AI_Workflows_Handoff_Provider {

	/** @var array Provider config (unused for Native beyond assignment role). */
	private $config;

	/**
	 * @param array $config data.agent.handoff.native (optional { assignedRole }).
	 */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	/**
	 * Create a human task from the transcript + context. The task id is the
	 * external reference inbound replies map back to.
	 *
	 * @param array $conversation { session_id, workflow_id }.
	 * @param array $transcript   Ordered [ { role, content } ].
	 * @param array $metadata     { customer, summary, reason, kb_refs }.
	 * @return string|WP_Error External reference (task_<id>).
	 */
	public function start_handoff( array $conversation, array $transcript, array $metadata ) {
		if ( ! class_exists( 'WP_AI_Workflows_Human_Tasks' ) ) {
			return new WP_Error( 'handoff_native_unavailable', 'Human tasks module unavailable.' );
		}

		$session_id  = isset( $conversation['session_id'] ) ? (string) $conversation['session_id'] : '';
		$workflow_id = isset( $conversation['workflow_id'] ) ? (string) $conversation['workflow_id'] : '';
		$role        = isset( $this->config['assignedRole'] ) && $this->config['assignedRole']
			? (string) $this->config['assignedRole']
			: 'administrator';

		$reason  = isset( $metadata['reason'] ) ? (string) $metadata['reason'] : '';
		$summary = isset( $metadata['summary'] ) ? (string) $metadata['summary'] : '';

		try {
			$human   = new WP_AI_Workflows_Human_Tasks();
			$task_id = $human->create_task(
				array(
					'workflow_id'      => $workflow_id ? $workflow_id : 'chat-handoff',
					'execution_id'     => 0,
					'node_id'          => 'chat_handoff',
					'assigned_user_id' => null,
					'assigned_role'    => $role,
					'input_type'       => 'handoff',
					'instructions'     => 'A chat visitor was handed off to a human'
						. ( '' !== $reason ? ': ' . $reason : '.' ),
					'content'          => wp_json_encode(
						array(
							'session_id' => $session_id,
							'reason'     => $reason,
							'summary'    => $summary,
							'customer'   => isset( $metadata['customer'] ) ? $metadata['customer'] : array(),
							'kb_refs'    => isset( $metadata['kb_refs'] ) ? $metadata['kb_refs'] : array(),
							'transcript' => $transcript,
						)
					),
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'handoff_native_failed', $e->getMessage() );
		}

		if ( ! $task_id ) {
			return new WP_Error( 'handoff_native_failed', 'Could not create the handoff task.' );
		}

		return 'task_' . (int) $task_id;
	}

	/**
	 * Append a subsequent visitor message to the running native thread so the
	 * operator sees the ongoing conversation. Best-effort (never fatal).
	 *
	 * @param string $ref     External reference.
	 * @param string $message Visitor message.
	 * @return true|WP_Error
	 */
	public function send_to_human( $ref, $message ) {
		$key    = 'wpaiw_native_thread_' . md5( (string) $ref );
		$thread = get_transient( $key );
		if ( ! is_array( $thread ) ) {
			$thread = array();
		}
		$thread[] = array(
			'role'      => 'user',
			'content'   => (string) $message,
			'timestamp' => time(),
		);
		if ( count( $thread ) > 100 ) {
			$thread = array_slice( $thread, -100 );
		}
		set_transient( $key, $thread, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Parse a Native inbound payload (an operator reply, already authenticated by
	 * verify_webhook) into the visitor-facing message.
	 *
	 * @param array $payload { message, external_ref }.
	 * @return array<int,array{content:string,role:string,external_ref:string}>
	 */
	public function receive_inbound( array $payload ) {
		$message = isset( $payload['message'] ) ? trim( (string) $payload['message'] ) : '';
		$ref     = isset( $payload['external_ref'] ) ? (string) $payload['external_ref'] : '';
		if ( '' === $message ) {
			return array();
		}
		return array(
			array(
				'content'      => $message,
				'role'         => 'assistant',
				'external_ref' => $ref,
			),
		);
	}

	/**
	 * Resolve the handoff task (best-effort).
	 *
	 * @param string $ref External reference (task_<id>).
	 * @return true|WP_Error
	 */
	public function end_handoff( $ref ) {
		if ( ! class_exists( 'WP_AI_Workflows_Human_Tasks' ) ) {
			return true;
		}
		$task_id = (int) preg_replace( '/[^0-9]/', '', (string) $ref );
		if ( $task_id <= 0 ) {
			return true;
		}
		try {
			$human   = new WP_AI_Workflows_Human_Tasks();
			$user_id = get_current_user_id();
			$human->update_task_status( $task_id, 'completed', $user_id ? $user_id : 0, 'Handoff resolved.' );
		} catch ( Exception $e ) {
			// Non-fatal: the mode flip back to bot is authoritative.
			WP_AI_Workflows_Utilities::debug_log(
				'Native handoff end_handoff failed',
				'warning',
				array( 'error' => $e->getMessage() )
			);
		}
		return true;
	}

	/**
	 * Native inbound is an in-site operator: require a logged-in user with the
	 * capability to manage workflows/tasks. Fail-closed.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return bool
	 */
	public function verify_webhook( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// Nonce check (operator UI sends the standard WP REST nonce).
		$nonce = '';
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
