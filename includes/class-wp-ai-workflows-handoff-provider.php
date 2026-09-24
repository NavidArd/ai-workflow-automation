<?php
/**
 * Handoff Provider — the pluggable seam for passing a conversation to a human.
 *
 * Agent mode Phase 2a. Mirrors the memory/KB provider seam: a conversation can
 * be handed off to a human through any implementation of this interface. The
 * conversation gains a mode (bot | pending_human | human — see
 * {@see WP_AI_Workflows_Handoff_Store}); while a human owns it the agent/bot
 * stops auto-responding and only relays messages both ways.
 *
 * Contract (every method is best-effort / fail-safe; implementations MUST NOT
 * leak provider errors to the visitor — they return WP_Error or throw, and the
 * manager degrades gracefully):
 *
 *   start_handoff( $conversation, $transcript, $metadata ) : string|WP_Error
 *       Open/associate the external conversation, post the transcript + context,
 *       put a human in control. Returns an opaque external reference (the key we
 *       map inbound webhooks back to the local session with).
 *   send_to_human( $ref, $message ) : true|WP_Error
 *       Relay a subsequent visitor message to the human side.
 *   receive_inbound( $payload ) : array
 *       Parse a provider inbound webhook body into zero or more messages to show
 *       the visitor. Each item: [ 'content' => string, 'role' => 'assistant',
 *       'external_ref' => string ]. Non-relayable events (bot echoes, private
 *       notes, non-message events) yield an empty array.
 *   end_handoff( $ref ) : true|WP_Error
 *       Resolve/close the external conversation (control returns to the bot).
 *   verify_webhook( $request ) : bool
 *       Authenticate an inbound webhook (shared secret / HMAC / capability).
 *       Fail-closed: unverifiable requests return false and are rejected.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

interface WP_AI_Workflows_Handoff_Provider {

	/**
	 * @param array $conversation { session_id, workflow_id }.
	 * @param array $transcript   Ordered [ { role, content } ] turns.
	 * @param array $metadata     { customer, summary, kb_refs, reason, ... }.
	 * @return string|WP_Error External reference on success.
	 */
	public function start_handoff( array $conversation, array $transcript, array $metadata );

	/**
	 * @param string $ref     External reference from start_handoff().
	 * @param string $message Visitor message to relay to the human.
	 * @return true|WP_Error
	 */
	public function send_to_human( $ref, $message );

	/**
	 * @param array $payload Decoded inbound webhook body.
	 * @return array<int,array{content:string,role:string,external_ref:string}>
	 */
	public function receive_inbound( array $payload );

	/**
	 * @param string $ref External reference from start_handoff().
	 * @return true|WP_Error
	 */
	public function end_handoff( $ref );

	/**
	 * @param WP_REST_Request $request Inbound webhook request.
	 * @return bool True when authentic (fail-closed).
	 */
	public function verify_webhook( $request );
}
