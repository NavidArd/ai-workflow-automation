<?php
/**
 * Intercom Handoff Provider — create a conversation and assign to a teammate/team.
 *
 * Agent mode Phase 2b. Outbound: ensure an Intercom contact (role=user) for the
 * chat session, open a conversation carrying the transcript + agent summary, then
 * assign it to the configured teammate or team so a human takes over. Subsequent
 * visitor messages are relayed as user replies on the conversation. Inbound: an
 * Intercom `conversation.admin.replied` webhook — the admin's (human) reply — is
 * relayed to the visitor. Resolve (end_handoff) closes the conversation.
 *
 * All Intercom HTTP goes through {@see self::request()} (wp_remote_*), so it is
 * mockable in tests via the WordPress HTTP layer. Auth uses a Bearer access token
 * plus the `Intercom-Version` header.
 *
 * Inbound authenticity: Intercom signs webhooks with the app's client secret. We
 * verify the `X-Hub-Signature` header as `sha1=<hex>` HMAC-SHA1 over the raw body
 * with the client secret. Fail-closed: with no configured secret, or no valid
 * proof, the webhook is rejected.
 *
 * API reference: Intercom REST API — Contacts, Conversations (create / reply /
 * parts assignment + close), base https://api.intercom.io (EU
 * https://api.eu.intercom.io, AU https://api.au.intercom.io).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Intercom implements WP_AI_Workflows_Handoff_Provider {

	/** @var string Intercom API base host (no trailing slash). */
	private $base_url;

	/** @var string Plaintext access token (Bearer). */
	private $access_token;

	/** @var string Intercom-Version header value. */
	private $api_version;

	/** @var string Acting admin id (assigns/closes on behalf of). */
	private $admin_id;

	/** @var string Target teammate/team id to assign the conversation to. */
	private $assignee_id;

	/** @var string 'admin' (a teammate) or 'team'. */
	private $assignee_type;

	/** @var string Plaintext client secret used to verify inbound webhooks. */
	private $client_secret;

	/**
	 * @param array $config Plaintext handoff.intercom config.
	 */
	public function __construct( array $config ) {
		$base = isset( $config['baseUrl'] ) ? trim( (string) $config['baseUrl'] ) : '';
		if ( '' === $base ) {
			$region = isset( $config['region'] ) ? strtolower( (string) $config['region'] ) : 'us';
			if ( 'eu' === $region ) {
				$base = 'https://api.eu.intercom.io';
			} elseif ( 'au' === $region ) {
				$base = 'https://api.au.intercom.io';
			} else {
				$base = 'https://api.intercom.io';
			}
		}
		$this->base_url      = untrailingslashit( $base );
		$this->access_token  = isset( $config['accessToken'] ) ? (string) $config['accessToken'] : '';
		$this->api_version   = isset( $config['apiVersion'] ) && '' !== (string) $config['apiVersion']
			? (string) $config['apiVersion']
			: '2.11';
		$this->admin_id      = isset( $config['adminId'] ) ? (string) $config['adminId'] : '';
		$this->assignee_id   = isset( $config['assigneeId'] ) ? (string) $config['assigneeId'] : '';
		$this->assignee_type = isset( $config['assigneeType'] ) && 'team' === $config['assigneeType'] ? 'team' : 'admin';
		$this->client_secret = isset( $config['clientSecret'] ) ? (string) $config['clientSecret'] : '';
	}

	/* ---------------------------------------------------------------------
	 * Outbound
	 * ------------------------------------------------------------------- */

	/**
	 * Ensure a contact, open a conversation with the transcript, and assign it to
	 * the configured teammate/team. Returns the conversation id as the ref.
	 *
	 * @param array $conversation { session_id, workflow_id }.
	 * @param array $transcript   Ordered [ { role, content } ].
	 * @param array $metadata     { customer, summary, reason, kb_refs }.
	 * @return string|WP_Error Conversation id, or error.
	 */
	public function start_handoff( array $conversation, array $transcript, array $metadata ) {
		if ( '' === $this->base_url || '' === $this->access_token || '' === $this->admin_id ) {
			return new WP_Error( 'handoff_intercom_config', 'Intercom handoff is not fully configured.' );
		}

		$session_id  = isset( $conversation['session_id'] ) ? (string) $conversation['session_id'] : '';
		$external_id = 'wpaiw_' . ( '' !== $session_id ? $session_id : bin2hex( random_bytes( 8 ) ) );

		$customer = isset( $metadata['customer'] ) && is_array( $metadata['customer'] ) ? $metadata['customer'] : array();
		$name     = isset( $customer['name'] ) && $customer['name'] ? (string) $customer['name'] : 'Website Visitor';
		$email    = isset( $customer['email'] ) ? (string) $customer['email'] : '';

		// 1) Create the contact (role=user). Intercom returns the contact id.
		$contact_body = array(
			'role'        => 'user',
			'external_id' => $external_id,
			'name'        => $name,
		);
		if ( '' !== $email ) {
			$contact_body['email'] = $email;
		}
		$contact = $this->request( 'POST', '/contacts', $contact_body );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		$contact_id = isset( $contact['id'] ) ? (string) $contact['id'] : '';
		if ( '' === $contact_id ) {
			return new WP_Error( 'handoff_intercom_no_contact', 'Intercom did not return a contact id.' );
		}

		// 2) Open the conversation from the user, carrying the transcript/context.
		$conv = $this->request(
			'POST',
			'/conversations',
			array(
				'from' => array(
					'type' => 'user',
					'id'   => $contact_id,
				),
				'body' => $this->build_context_note( $transcript, $metadata ),
			)
		);
		if ( is_wp_error( $conv ) ) {
			return $conv;
		}
		$conversation_id = isset( $conv['conversation_id'] ) ? (string) $conv['conversation_id'] : ( isset( $conv['id'] ) ? (string) $conv['id'] : '' );
		if ( '' === $conversation_id ) {
			return new WP_Error( 'handoff_intercom_no_conv', 'Intercom did not return a conversation id.' );
		}

		// 3) Assign the conversation to the human teammate/team.
		if ( '' !== $this->assignee_id ) {
			$assign = $this->request(
				'POST',
				'/conversations/' . rawurlencode( $conversation_id ) . '/parts',
				array(
					'message_type' => 'assignment',
					'type'         => $this->assignee_type,
					'admin_id'     => $this->admin_id,
					'assignee_id'  => $this->assignee_id,
				)
			);
			if ( is_wp_error( $assign ) ) {
				return $assign;
			}
		}

		return $conversation_id;
	}

	/**
	 * Relay a subsequent visitor message to the Intercom conversation as a user
	 * comment.
	 *
	 * @param string $ref     Conversation id.
	 * @param string $message Visitor message.
	 * @return true|WP_Error
	 */
	public function send_to_human( $ref, $message ) {
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return new WP_Error( 'handoff_intercom_no_ref', 'Missing conversation reference.' );
		}
		$res = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $ref ) . '/reply',
			array(
				'message_type' => 'comment',
				'type'         => 'user',
				'body'         => (string) $message,
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Close the Intercom conversation (control returns to the bot).
	 *
	 * @param string $ref Conversation id.
	 * @return true|WP_Error
	 */
	public function end_handoff( $ref ) {
		$ref = (string) $ref;
		if ( '' === $ref || '' === $this->admin_id ) {
			return true;
		}
		$res = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $ref ) . '/parts',
			array(
				'message_type' => 'close',
				'type'         => 'admin',
				'admin_id'     => $this->admin_id,
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/* ---------------------------------------------------------------------
	 * Inbound
	 * ------------------------------------------------------------------- */

	/**
	 * Parse an Intercom `conversation.admin.replied` webhook. Relay the admin's
	 * (human) latest comment. Skip other topics and non-comment parts.
	 *
	 * @param array $payload Decoded webhook body.
	 * @return array<int,array{content:string,role:string,external_ref:string}>
	 */
	public function receive_inbound( array $payload ) {
		$topic = isset( $payload['topic'] ) ? (string) $payload['topic'] : '';
		if ( 'conversation.admin.replied' !== $topic ) {
			return array();
		}

		$item = isset( $payload['data']['item'] ) && is_array( $payload['data']['item'] ) ? $payload['data']['item'] : array();
		$ref  = isset( $item['id'] ) ? (string) $item['id'] : '';
		if ( '' === $ref ) {
			return array();
		}

		$parts = array();
		if ( isset( $item['conversation_parts']['conversation_parts'] ) && is_array( $item['conversation_parts']['conversation_parts'] ) ) {
			$parts = $item['conversation_parts']['conversation_parts'];
		}
		if ( empty( $parts ) ) {
			return array();
		}

		// Take the latest admin comment part.
		$latest = null;
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			$part_type   = isset( $part['part_type'] ) ? (string) $part['part_type'] : '';
			$author_type = isset( $part['author']['type'] ) ? strtolower( (string) $part['author']['type'] ) : '';
			if ( 'comment' === $part_type && 'admin' === $author_type ) {
				$latest = $part;
			}
		}
		if ( null === $latest ) {
			return array();
		}

		$content = isset( $latest['body'] ) ? $this->html_to_text( (string) $latest['body'] ) : '';
		if ( '' === $content ) {
			return array();
		}

		return array(
			array(
				'content'      => $content,
				'role'         => 'assistant',
				'external_ref' => $ref,
				'ext_msg_id'   => isset( $latest['id'] ) ? (string) $latest['id'] : '',
			),
		);
	}

	/**
	 * Verify an inbound Intercom webhook via the X-Hub-Signature HMAC-SHA1 over
	 * the raw body with the app client secret. Fail-closed.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return bool
	 */
	public function verify_webhook( $request ) {
		if ( '' === $this->client_secret ) {
			return false; // Cannot verify without a configured secret.
		}
		if ( ! is_object( $request ) ) {
			return false;
		}

		$raw = method_exists( $request, 'get_body' ) ? (string) $request->get_body() : '';
		$sig = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'X-Hub-Signature' ) : '';
		if ( '' === $sig ) {
			return false;
		}
		$sig      = preg_replace( '/^sha1=/', '', $sig );
		$expected = hash_hmac( 'sha1', $raw, $this->client_secret );
		return hash_equals( $expected, (string) $sig );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Build the conversation body posted to Intercom on handoff.
	 *
	 * @param array $transcript Ordered turns.
	 * @param array $metadata   Context.
	 * @return string
	 */
	private function build_context_note( array $transcript, array $metadata ) {
		$lines = array( 'Handoff from AI assistant' );

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
				$lines[] = 'Customer — ' . implode( ', ', $cust );
			}
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
	 * Reduce an Intercom HTML comment body to plain text for the widget.
	 *
	 * @param string $html HTML body.
	 * @return string
	 */
	private function html_to_text( $html ) {
		$text = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = preg_replace( '#</p>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		return trim( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Perform an Intercom REST request. Returns the decoded body array on success,
	 * or WP_Error on transport / non-2xx.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after the base host.
	 * @param array  $body   Request body (JSON-encoded for non-GET).
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array() ) {
		$url = $this->base_url . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'Authorization'    => 'Bearer ' . $this->access_token,
				'Intercom-Version' => $this->api_version,
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
				'handoff_intercom_http',
				'Intercom API error (' . $code . ').',
				array( 'body' => $raw )
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
