<?php
/**
 * Zendesk Sunshine Conversations Handoff Provider — Switchboard passControl.
 *
 * Outbound: ensures a Sunshine user + conversation, posts the transcript as a
 * business message, then passes control to the configured human switchboard
 * integration. Inbound: business-authored `conversation:message` webhook
 * events are relayed to the visitor; end_handoff passes control back to the
 * bot integration. Webhook verification is fail-closed with no configured
 * secret.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Zendesk implements WP_AI_Workflows_Handoff_Provider {

	/** @var string Sunshine API base host (no trailing slash). */
	private $base_url;

	/** @var string Sunshine app id. */
	private $app_id;

	/** @var string Sunshine API key id (Basic auth username). */
	private $key_id;

	/** @var string Plaintext Sunshine API key secret (Basic auth password). */
	private $key_secret;

	/** @var string Target human switchboard integration id/name for passControl. */
	private $switchboard_integration;

	/** @var string Switchboard integration to return control to on resolve. */
	private $bot_integration;

	/** @var string Plaintext webhook secret used to verify inbound webhooks. */
	private $webhook_secret;

	/**
	 * @param array $config Plaintext handoff.zendesk config.
	 */
	public function __construct( array $config ) {
		$base = isset( $config['baseUrl'] ) ? trim( (string) $config['baseUrl'] ) : '';
		if ( '' === $base ) {
			$region = isset( $config['region'] ) ? strtolower( (string) $config['region'] ) : 'us';
			$base   = ( 'eu' === $region ) ? 'https://api.eu-1.smooch.io' : 'https://api.smooch.io';
		}
		$this->base_url                = untrailingslashit( $base );
		$this->app_id                  = isset( $config['appId'] ) ? (string) $config['appId'] : '';
		$this->key_id                  = isset( $config['keyId'] ) ? (string) $config['keyId'] : '';
		$this->key_secret              = isset( $config['keySecret'] ) ? (string) $config['keySecret'] : '';
		$this->switchboard_integration = isset( $config['switchboardIntegration'] ) ? (string) $config['switchboardIntegration'] : '';
		$this->bot_integration         = isset( $config['botIntegration'] ) && '' !== (string) $config['botIntegration']
			? (string) $config['botIntegration']
			: 'next';
		$this->webhook_secret          = isset( $config['webhookSecret'] ) ? (string) $config['webhookSecret'] : '';
	}

	/**
	 * Ensure a Sunshine user + conversation, post the transcript/context as a
	 * business message, then passControl to the human switchboard integration.
	 * Returns the conversation id as the ref.
	 *
	 * @param array $conversation { session_id, workflow_id }.
	 * @param array $transcript   Ordered [ { role, content } ].
	 * @param array $metadata     { customer, summary, reason, kb_refs }.
	 * @return string|WP_Error Conversation id, or error.
	 */
	public function start_handoff( array $conversation, array $transcript, array $metadata ) {
		if ( '' === $this->base_url || '' === $this->app_id || '' === $this->key_id || '' === $this->key_secret || '' === $this->switchboard_integration ) {
			return new WP_Error( 'handoff_zendesk_config', 'Zendesk Sunshine handoff is not fully configured.' );
		}

		$session_id = isset( $conversation['session_id'] ) ? (string) $conversation['session_id'] : '';
		$external_id = 'wpaiw_' . ( '' !== $session_id ? $session_id : wp_generate_uuid4_safe() );

		$customer = isset( $metadata['customer'] ) && is_array( $metadata['customer'] ) ? $metadata['customer'] : array();
		$name     = isset( $customer['name'] ) && $customer['name'] ? (string) $customer['name'] : 'Website Visitor';
		$email    = isset( $customer['email'] ) ? (string) $customer['email'] : '';

		// 1) Ensure the app user (idempotent by externalId).
		$profile = array( 'givenName' => $name );
		if ( '' !== $email ) {
			$profile['email'] = $email;
		}
		$this->request(
			'POST',
			'/users',
			array(
				'externalId' => $external_id,
				'profile'    => $profile,
			)
		);

		// 2) Create a personal conversation for the user.
		$conv = $this->request(
			'POST',
			'/conversations',
			array(
				'type'         => 'personal',
				'participants' => array(
					array(
						'userExternalId'    => $external_id,
						'subscribeSDKClient' => false,
					),
				),
			)
		);
		if ( is_wp_error( $conv ) ) {
			return $conv;
		}
		$conv_obj        = isset( $conv['conversation'] ) && is_array( $conv['conversation'] ) ? $conv['conversation'] : $conv;
		$conversation_id = isset( $conv_obj['id'] ) ? (string) $conv_obj['id'] : '';
		if ( '' === $conversation_id ) {
			return new WP_Error( 'handoff_zendesk_no_conv', 'Sunshine did not return a conversation id.' );
		}

		// 3) Post the transcript + context as a business message for the agent.
		$note = $this->build_context_note( $transcript, $metadata );
		$this->request(
			'POST',
			'/conversations/' . rawurlencode( $conversation_id ) . '/messages',
			array(
				'author'  => array( 'type' => 'business' ),
				'content' => array(
					'type' => 'text',
					'text' => $note,
				),
			)
		);

		// 4) Pass control to the human switchboard integration; metadata
		// populates the ticket fields via the documented dext.* convention.
		$pass = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $conversation_id ) . '/passControl',
			array(
				'switchboardIntegration' => $this->switchboard_integration,
				'metadata'               => $this->build_pass_metadata( $metadata ),
			)
		);
		if ( is_wp_error( $pass ) ) {
			return $pass;
		}

		return $conversation_id;
	}

	/**
	 * Relay a subsequent visitor message into the Sunshine conversation, authored
	 * as the user so the human sees it and it is never relayed back to us.
	 *
	 * @param string $ref     Conversation id.
	 * @param string $message Visitor message.
	 * @return true|WP_Error
	 */
	public function send_to_human( $ref, $message ) {
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return new WP_Error( 'handoff_zendesk_no_ref', 'Missing conversation reference.' );
		}
		$res = $this->request(
			'POST',
			'/conversations/' . rawurlencode( $ref ) . '/messages',
			array(
				'author'  => array( 'type' => 'user' ),
				'content' => array(
					'type' => 'text',
					'text' => (string) $message,
				),
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Pass control back to the bot/automation switchboard integration.
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
			'/conversations/' . rawurlencode( $ref ) . '/passControl',
			array( 'switchboardIntegration' => $this->bot_integration )
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Parse a Sunshine webhook. Relay only `conversation:message` events authored
	 * by a business (the human agent). Skip user echoes (our own relayed posts and
	 * the visitor's own messages) and non-message events.
	 *
	 * @param array $payload Decoded webhook body.
	 * @return array<int,array{content:string,role:string,external_ref:string}>
	 */
	public function receive_inbound( array $payload ) {
		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		if ( empty( $events ) ) {
			return array();
		}

		$out = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$type = isset( $event['type'] ) ? (string) $event['type'] : '';
			if ( 'conversation:message' !== $type ) {
				continue;
			}
			$data    = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
			$message = isset( $data['message'] ) && is_array( $data['message'] ) ? $data['message'] : array();
			$author  = isset( $message['author'] ) && is_array( $message['author'] ) ? $message['author'] : array();

			// Only a human agent's business-authored message.
			$author_type = isset( $author['type'] ) ? strtolower( (string) $author['type'] ) : '';
			if ( 'business' !== $author_type ) {
				continue;
			}

			$content_obj = isset( $message['content'] ) && is_array( $message['content'] ) ? $message['content'] : array();
			$content     = isset( $content_obj['text'] ) ? trim( (string) $content_obj['text'] ) : '';
			if ( '' === $content ) {
				continue;
			}

			$conv = isset( $data['conversation'] ) && is_array( $data['conversation'] ) ? $data['conversation'] : array();
			$ref  = isset( $conv['id'] ) ? (string) $conv['id'] : '';
			if ( '' === $ref ) {
				continue;
			}

			$out[] = array(
				'content'      => $content,
				'role'         => 'assistant',
				'external_ref' => $ref,
				'ext_msg_id'   => isset( $message['id'] ) ? (string) $message['id'] : '',
			);
		}
		return $out;
	}

	/**
	 * Verify an inbound Sunshine webhook via the configured secret. Fail-closed.
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

		// 1) HMAC-SHA256 (base64) of the raw body in the signature header.
		$sig = '';
		if ( method_exists( $request, 'get_header' ) ) {
			$sig = (string) $request->get_header( 'X-Sunshine-Conversations-Signature' );
		}
		if ( '' !== $sig ) {
			$expected = base64_encode( hash_hmac( 'sha256', $raw, $this->webhook_secret, true ) );
			return hash_equals( $expected, $sig );
		}

		// 2) Shared-key fallback: X-Api-Key equals the configured secret.
		$api_key = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'X-Api-Key' ) : '';
		if ( '' !== $api_key ) {
			return hash_equals( $this->webhook_secret, $api_key );
		}

		return false;
	}

	/**
	 * Build the context message posted to the conversation on handoff.
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
	 * Build the passControl metadata that populates ticket fields (dext.*).
	 *
	 * @param array $metadata Context.
	 * @return array
	 */
	private function build_pass_metadata( array $metadata ) {
		$meta = array();
		if ( ! empty( $metadata['reason'] ) ) {
			$meta['dext.reason'] = (string) $metadata['reason'];
		}
		if ( ! empty( $metadata['summary'] ) ) {
			$meta['dext.summary'] = (string) $metadata['summary'];
		}
		if ( ! empty( $metadata['customer'] ) && is_array( $metadata['customer'] ) ) {
			if ( ! empty( $metadata['customer']['name'] ) ) {
				$meta['dext.customerName'] = (string) $metadata['customer']['name'];
			}
			if ( ! empty( $metadata['customer']['email'] ) ) {
				$meta['dext.customerEmail'] = (string) $metadata['customer']['email'];
			}
		}
		$meta['dext.source'] = 'wp-ai-workflows';
		return $meta;
	}

	/**
	 * Perform a Sunshine Conversations v2 request. Returns the decoded body array
	 * on success, or WP_Error on transport / non-2xx.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after /v2/apps/{app_id}.
	 * @param array  $body   Request body (JSON-encoded for non-GET).
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $body = array() ) {
		$url = $this->base_url . '/v2/apps/' . rawurlencode( $this->app_id ) . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $this->key_id . ':' . $this->key_secret ),
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
				'handoff_zendesk_http',
				'Sunshine Conversations API error (' . $code . ').',
				array( 'body' => $raw )
			);
		}

		return is_array( $data ) ? $data : array();
	}
}

if ( ! function_exists( 'wp_generate_uuid4_safe' ) ) {
	/**
	 * Minimal uuid fallback for the rare case where a session id is absent. Uses
	 * wp_generate_uuid4() when WordPress is present, else a random hex.
	 *
	 * @return string
	 */
	function wp_generate_uuid4_safe() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return bin2hex( random_bytes( 16 ) );
	}
}
