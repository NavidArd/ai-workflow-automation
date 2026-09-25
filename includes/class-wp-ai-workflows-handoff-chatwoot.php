<?php
/**
 * Chatwoot Handoff Provider - Agent-Bot style handoff to a Chatwoot inbox.
 *
 * Outbound: creates a contact + conversation, posts the transcript as a
 * private note, and opens the conversation for a human (optionally assigning
 * an agent/team). Inbound: a human agent's outgoing, non-private
 * `message_created` webhook reply is relayed to the visitor. Chatwoot does
 * not sign webhooks natively, so verification uses a shared secret (HMAC
 * signature header or token param) and is fail-closed with no configured
 * secret.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Chatwoot implements WP_AI_Workflows_Handoff_Provider {

	/** @var string */
	private $base_url;

	/** @var string */
	private $account_id;

	/** @var string */
	private $inbox_id;

	/** @var string Plaintext api_access_token (decrypted by the manager). */
	private $token;

	/** @var string Plaintext shared webhook secret (decrypted by the manager). */
	private $webhook_secret;

	/** @var int|null Optional agent to assign. */
	private $assignee_id;

	/** @var int|null Optional team to assign. */
	private $team_id;

	/**
	 * @param array $config Plaintext handoff.chatwoot config.
	 */
	public function __construct( array $config ) {
		$this->base_url       = isset( $config['baseUrl'] ) ? untrailingslashit( trim( (string) $config['baseUrl'] ) ) : '';
		$this->account_id     = isset( $config['accountId'] ) ? (string) $config['accountId'] : '';
		$this->inbox_id       = isset( $config['inboxId'] ) ? (string) $config['inboxId'] : '';
		$this->token          = isset( $config['apiAccessToken'] ) ? (string) $config['apiAccessToken'] : '';
		$this->webhook_secret = isset( $config['webhookSecret'] ) ? (string) $config['webhookSecret'] : '';
		$this->assignee_id    = isset( $config['assigneeId'] ) && '' !== $config['assigneeId'] ? (int) $config['assigneeId'] : null;
		$this->team_id        = isset( $config['teamId'] ) && '' !== $config['teamId'] ? (int) $config['teamId'] : null;
	}

	/**
	 * Create a Chatwoot conversation, post the transcript/context as a private
	 * note, and open it for a human. Returns the conversation id as the ref.
	 *
	 * @param array $conversation { session_id, workflow_id }.
	 * @param array $transcript   Ordered [ { role, content } ].
	 * @param array $metadata     { customer, summary, reason, kb_refs }.
	 * @return string|WP_Error Conversation id, or error.
	 */
	public function start_handoff( array $conversation, array $transcript, array $metadata ) {
		if ( '' === $this->base_url || '' === $this->account_id || '' === $this->inbox_id || '' === $this->token ) {
			return new WP_Error( 'handoff_chatwoot_config', 'Chatwoot handoff is not fully configured.' );
		}

		$customer = isset( $metadata['customer'] ) && is_array( $metadata['customer'] ) ? $metadata['customer'] : array();
		$name     = isset( $customer['name'] ) && $customer['name'] ? (string) $customer['name'] : 'Website Visitor';
		$email    = isset( $customer['email'] ) ? (string) $customer['email'] : '';

		// 1) Create a contact (yields a source_id for the inbox).
		$contact_body = array(
			'inbox_id' => (int) $this->inbox_id,
			'name'     => $name,
		);
		if ( '' !== $email ) {
			$contact_body['email'] = $email;
		}
		$contact = $this->request( 'POST', '/contacts', $contact_body );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$payload     = isset( $contact['payload'] ) && is_array( $contact['payload'] ) ? $contact['payload'] : $contact;
		$contact_obj = isset( $payload['contact'] ) ? $payload['contact'] : $payload;
		$contact_id  = isset( $contact_obj['id'] ) ? (int) $contact_obj['id'] : 0;
		$source_id   = $this->extract_source_id( $contact_obj );

		// 2) Create the conversation, opened for a human (+ optional assignment).
		$conv_body = array(
			'inbox_id' => (int) $this->inbox_id,
			'status'   => 'open',
		);
		if ( '' !== $source_id ) {
			$conv_body['source_id'] = $source_id;
		}
		if ( $contact_id > 0 ) {
			$conv_body['contact_id'] = $contact_id;
		}
		if ( null !== $this->assignee_id ) {
			$conv_body['assignee_id'] = $this->assignee_id;
		}
		if ( null !== $this->team_id ) {
			$conv_body['team_id'] = $this->team_id;
		}

		$conv = $this->request( 'POST', '/conversations', $conv_body );
		if ( is_wp_error( $conv ) ) {
			return $conv;
		}
		$conversation_id = isset( $conv['id'] ) ? (string) $conv['id'] : '';
		if ( '' === $conversation_id ) {
			return new WP_Error( 'handoff_chatwoot_no_conv', 'Chatwoot did not return a conversation id.' );
		}

		// 3) Post the transcript + context as a PRIVATE note for the agent.
		$note = $this->build_context_note( $transcript, $metadata );
		$this->request(
			'POST',
			'/conversations/' . rawurlencode( $conversation_id ) . '/messages',
			array(
				'content'      => $note,
				'message_type' => 'outgoing',
				'private'      => true,
			)
		);

		return $conversation_id;
	}

	/**
	 * Relay a subsequent visitor message to the Chatwoot conversation (incoming).
	 *
	 * @param string $ref     Conversation id.
	 * @param string $message Visitor message.
	 * @return true|WP_Error
	 */
	public function send_to_human( $ref, $message ) {
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return new WP_Error( 'handoff_chatwoot_no_ref', 'Missing conversation reference.' );
		}
		$res = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $ref ) . '/messages',
			array(
				'content'      => (string) $message,
				'message_type' => 'incoming',
				'private'      => false,
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Resolve the Chatwoot conversation.
	 *
	 * @param string $ref Conversation id.
	 * @return true|WP_Error
	 */
	public function end_handoff( $ref ) {
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return true;
		}
		$res = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $ref ) . '/toggle_status',
			array( 'status' => 'resolved' )
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Parse a Chatwoot `message_created` webhook. Relay only a human AGENT's
	 * outgoing, non-private message (skip bot echoes, private notes, incoming
	 * visitor echoes, and non-message events).
	 *
	 * @param array $payload Decoded webhook body.
	 * @return array<int,array{content:string,role:string,external_ref:string}>
	 */
	public function receive_inbound( array $payload ) {
		$event = isset( $payload['event'] ) ? (string) $payload['event'] : '';
		if ( 'message_created' !== $event ) {
			return array();
		}

		// message_type may be a string ('outgoing') or legacy int (1 = outgoing).
		$type = isset( $payload['message_type'] ) ? $payload['message_type'] : '';
		$is_outgoing = ( 'outgoing' === $type ) || ( 1 === $type ) || ( '1' === (string) $type );
		if ( ! $is_outgoing ) {
			return array();
		}

		// Skip private notes (internal agent context, not for the visitor).
		if ( ! empty( $payload['private'] ) ) {
			return array();
		}

		// Only relay a human agent's message. Human agents have sender type
		// 'user'; agent bots have 'agent_bot' - never echo our own bot posts.
		$sender      = isset( $payload['sender'] ) && is_array( $payload['sender'] ) ? $payload['sender'] : array();
		$sender_type = isset( $sender['type'] ) ? strtolower( (string) $sender['type'] ) : '';
		if ( '' !== $sender_type && 'user' !== $sender_type && 'agent' !== $sender_type ) {
			return array();
		}

		$content = isset( $payload['content'] ) ? trim( (string) $payload['content'] ) : '';
		if ( '' === $content ) {
			return array();
		}

		$conv = isset( $payload['conversation'] ) && is_array( $payload['conversation'] ) ? $payload['conversation'] : array();
		$ref  = isset( $conv['id'] ) ? (string) $conv['id'] : ( isset( $payload['conversation_id'] ) ? (string) $payload['conversation_id'] : '' );

		$msg_id = isset( $payload['id'] ) ? (string) $payload['id'] : '';

		return array(
			array(
				'content'       => $content,
				'role'          => 'assistant',
				'external_ref'  => $ref,
				'ext_msg_id'    => $msg_id,
			),
		);
	}

	/**
	 * Verify an inbound Chatwoot webhook via the configured shared secret.
	 * Fail-closed.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return bool
	 */
	public function verify_webhook( $request ) {
		if ( '' === $this->webhook_secret ) {
			return false; // Cannot verify without a configured secret.
		}
		if ( ! is_object( $request ) ) {
			return false;
		}

		$raw = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';

		// 1) HMAC signature header over the raw body.
		$sig = '';
		if ( method_exists( $request, 'get_header' ) ) {
			$sig = (string) $request->get_header( 'X-Chatwoot-Signature' );
			if ( '' === $sig ) {
				$sig = (string) $request->get_header( 'X-Hub-Signature-256' );
			}
		}
		if ( '' !== $sig ) {
			$sig      = preg_replace( '/^sha256=/', '', $sig );
			$expected = hash_hmac( 'sha256', $raw, $this->webhook_secret );
			return hash_equals( $expected, $sig );
		}

		// 2) Shared token parameter fallback.
		$token = method_exists( $request, 'get_param' ) ? (string) $request->get_param( 'token' ) : '';
		if ( '' !== $token ) {
			return hash_equals( $this->webhook_secret, $token );
		}

		return false;
	}

	/**
	 * Build the private context note posted to the agent on handoff.
	 *
	 * @param array $transcript Ordered turns.
	 * @param array $metadata   Context.
	 * @return string
	 */
	private function build_context_note( array $transcript, array $metadata ) {
		$lines = array( '🤖 Handoff from AI assistant' );

		if ( ! empty( $metadata['reason'] ) ) {
			$lines[] = 'Reason: ' . (string) $metadata['reason'];
		}
		if ( ! empty( $metadata['summary'] ) ) {
			$lines[] = 'Summary: ' . (string) $metadata['summary'];
		}
		if ( ! empty( $metadata['customer'] ) && is_array( $metadata['customer'] ) ) {
			$cust = array();
			foreach ( $metadata['customer'] as $k => $v ) {
				if ( is_scalar( $v ) && '' !== (string) $v ) {
					$cust[] = $k . ': ' . $v;
				}
			}
			if ( $cust ) {
				$lines[] = 'Customer: ' . implode( ', ', $cust );
			}
		}
		if ( ! empty( $metadata['kb_refs'] ) && is_array( $metadata['kb_refs'] ) ) {
			$lines[] = 'Knowledge base references: ' . count( $metadata['kb_refs'] );
		}

		$lines[] = '';
		$lines[] = '--- Transcript ---';
		foreach ( $transcript as $turn ) {
			$role    = isset( $turn['role'] ) ? (string) $turn['role'] : 'user';
			$content = isset( $turn['content'] ) ? (string) $turn['content'] : '';
			$who     = ( 'assistant' === $role ) ? 'Assistant' : ( ( 'system' === $role ) ? 'System' : 'Visitor' );
			$lines[] = $who . ': ' . $content;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Extract the inbox source_id from a Chatwoot contact object (shape varies
	 * across versions).
	 *
	 * @param array $contact_obj Contact payload.
	 * @return string
	 */
	private function extract_source_id( $contact_obj ) {
		if ( ! is_array( $contact_obj ) ) {
			return '';
		}
		if ( isset( $contact_obj['contact_inboxes'][0]['source_id'] ) ) {
			return (string) $contact_obj['contact_inboxes'][0]['source_id'];
		}
		if ( isset( $contact_obj['contact_inbox']['source_id'] ) ) {
			return (string) $contact_obj['contact_inbox']['source_id'];
		}
		if ( isset( $contact_obj['source_id'] ) ) {
			return (string) $contact_obj['source_id'];
		}
		return '';
	}

	/**
	 * Perform a Chatwoot Application API request. Returns the decoded body array
	 * on success, or WP_Error on transport / non-2xx.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after /api/v1/accounts/{account_id}.
	 * @param array  $body   Request body (JSON-encoded for non-GET).
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array() ) {
		$url = $this->base_url . '/api/v1/accounts/' . rawurlencode( $this->account_id ) . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'api_access_token' => $this->token,
			),
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = ( 'GET' === strtoupper( $method ) )
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'handoff_chatwoot_http',
				'Chatwoot API error (' . $code . ').',
				array( 'body' => $raw )
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
