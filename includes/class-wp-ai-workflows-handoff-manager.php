<?php
/**
 * Handoff Manager — orchestrates human handoff for a chat/agent conversation.
 *
 * Central seam between the chat handler / agent tool / REST webhooks and the
 * pluggable {@see WP_AI_Workflows_Handoff_Provider} + the
 * {@see WP_AI_Workflows_Handoff_Store} conversation-mode state machine.
 * Provider errors never leak to the visitor and never fatally break the chat turn.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Manager {

	const PROVIDER_NATIVE   = 'native';
	const PROVIDER_CHATWOOT = 'chatwoot';
	const PROVIDER_ZENDESK  = 'zendesk';
	const PROVIDER_INTERCOM = 'intercom';

	/** @var WP_AI_Workflows_Handoff_Store */
	private $store;

	public function __construct() {
		$this->store = new WP_AI_Workflows_Handoff_Store();
	}

	/**
	 * @return WP_AI_Workflows_Handoff_Store
	 */
	public function store() {
		return $this->store;
	}

	/* ---------------------------------------------------------------------
	 * Config + provider factory
	 * ------------------------------------------------------------------- */

	/**
	 * Whether handoff is enabled in a node's agent config.
	 *
	 * @param array|null $agent_config data.agent.
	 * @return bool
	 */
	public static function is_enabled( $agent_config ) {
		return is_array( $agent_config )
			&& isset( $agent_config['handoff'] )
			&& is_array( $agent_config['handoff'] )
			&& ! empty( $agent_config['handoff']['enabled'] );
	}

	/**
	 * Resolve a node's handoff config with provider credentials decrypted. Loads
	 * from the workflow's chat node when only the id is known.
	 *
	 * @param array|null $handoff_raw Raw handoff config (data.agent.handoff).
	 * @return array Normalized config { enabled, provider, trigger, keywords[],
	 *               handoffMessage, resolvedMessage, chatwoot{...}, native{...} }.
	 */
	public static function normalize_config( $handoff_raw ) {
		$raw = is_array( $handoff_raw ) ? $handoff_raw : array();

		$provider = isset( $raw['provider'] ) ? (string) $raw['provider'] : self::PROVIDER_NATIVE;
		$trigger  = isset( $raw['trigger'] ) ? (string) $raw['trigger'] : 'both';

		$keywords_raw = isset( $raw['keywords'] ) ? $raw['keywords'] : 'talk to a human, speak to a person, human agent, live agent, real person, customer support';
		if ( is_array( $keywords_raw ) ) {
			$keywords = $keywords_raw;
		} else {
			$keywords = array_filter( array_map( 'trim', explode( ',', (string) $keywords_raw ) ) );
		}

		$chatwoot = isset( $raw['chatwoot'] ) && is_array( $raw['chatwoot'] ) ? $raw['chatwoot'] : array();
		if ( isset( $chatwoot['apiAccessToken'] ) ) {
			$chatwoot['apiAccessToken'] = self::maybe_decrypt( $chatwoot['apiAccessToken'] );
		}
		if ( isset( $chatwoot['webhookSecret'] ) ) {
			$chatwoot['webhookSecret'] = self::maybe_decrypt( $chatwoot['webhookSecret'] );
		}

		// Zendesk Sunshine Conversations (Phase 2b): keySecret + webhookSecret.
		$zendesk = isset( $raw['zendesk'] ) && is_array( $raw['zendesk'] ) ? $raw['zendesk'] : array();
		if ( isset( $zendesk['keySecret'] ) ) {
			$zendesk['keySecret'] = self::maybe_decrypt( $zendesk['keySecret'] );
		}
		if ( isset( $zendesk['webhookSecret'] ) ) {
			$zendesk['webhookSecret'] = self::maybe_decrypt( $zendesk['webhookSecret'] );
		}

		// Intercom (Phase 2b): accessToken + clientSecret.
		$intercom = isset( $raw['intercom'] ) && is_array( $raw['intercom'] ) ? $raw['intercom'] : array();
		if ( isset( $intercom['accessToken'] ) ) {
			$intercom['accessToken'] = self::maybe_decrypt( $intercom['accessToken'] );
		}
		if ( isset( $intercom['clientSecret'] ) ) {
			$intercom['clientSecret'] = self::maybe_decrypt( $intercom['clientSecret'] );
		}

		return array(
			'enabled'         => ! empty( $raw['enabled'] ),
			'provider'        => $provider,
			'trigger'         => $trigger,
			// Defaults ON so existing nodes get it for free.
			'intent'          => ! array_key_exists( 'intent', $raw ) ? true : ! empty( $raw['intent'] ),
			'keywords'        => array_values( $keywords ),
			'handoffMessage'  => isset( $raw['handoffMessage'] ) && $raw['handoffMessage']
				? (string) $raw['handoffMessage']
				: "Connecting you with a member of our team. They'll be with you shortly.",
			'resolvedMessage' => isset( $raw['resolvedMessage'] ) && $raw['resolvedMessage']
				? (string) $raw['resolvedMessage']
				: "You're back with the AI assistant. How else can I help?",
			'chatwoot'        => $chatwoot,
			'zendesk'         => $zendesk,
			'intercom'        => $intercom,
			'native'          => isset( $raw['native'] ) && is_array( $raw['native'] ) ? $raw['native'] : array(),
		);
	}

	/**
	 * Load and normalize the handoff config for a workflow's chat node.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return array|null Normalized config, or null if none / disabled.
	 */
	public function config_for_workflow( $workflow_id ) {
		if ( ! class_exists( 'WP_AI_Workflows_Workflow_DBAL' ) ) {
			return null;
		}
		$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow_id );
		if ( ! $workflow || empty( $workflow['nodes'] ) ) {
			return null;
		}
		foreach ( $workflow['nodes'] as $node ) {
			if ( isset( $node['type'] ) && 'chat' === $node['type'] ) {
				$agent = isset( $node['data']['agent'] ) ? $node['data']['agent'] : null;
				if ( self::is_enabled( $agent ) ) {
					return self::normalize_config( $agent['handoff'] );
				}
				return null;
			}
		}
		return null;
	}

	/**
	 * Build a provider instance from a normalized config.
	 *
	 * @param array $config Normalized config.
	 * @return WP_AI_Workflows_Handoff_Provider|WP_Error
	 */
	public function get_provider( array $config ) {
		$provider = isset( $config['provider'] ) ? $config['provider'] : self::PROVIDER_NATIVE;

		switch ( $provider ) {
			case self::PROVIDER_CHATWOOT:
				return new WP_AI_Workflows_Handoff_Chatwoot( isset( $config['chatwoot'] ) ? $config['chatwoot'] : array() );
			case self::PROVIDER_ZENDESK:
				return new WP_AI_Workflows_Handoff_Zendesk( isset( $config['zendesk'] ) ? $config['zendesk'] : array() );
			case self::PROVIDER_INTERCOM:
				return new WP_AI_Workflows_Handoff_Intercom( isset( $config['intercom'] ) ? $config['intercom'] : array() );
			case self::PROVIDER_NATIVE:
				return new WP_AI_Workflows_Handoff_Native( isset( $config['native'] ) ? $config['native'] : array() );
			default:
				// An unknown/not-yet-built provider never dead-ends — fall back to
				// Native so a handoff still reaches a human.
				return new WP_AI_Workflows_Handoff_Native( array() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Trigger detection
	 * ------------------------------------------------------------------- */

	/**
	 * Whether a visitor message expresses explicit intent to reach a human,
	 * given the node's keyword trigger settings.
	 *
	 * @param string $message Visitor message.
	 * @param array  $config  Normalized config.
	 * @return bool
	 */
	public function message_requests_human( $message, array $config ) {
		$trigger = isset( $config['trigger'] ) ? $config['trigger'] : 'both';
		if ( 'agent' === $trigger ) {
			return false; // Agent-decides only: no keyword trigger.
		}
		$haystack = strtolower( (string) $message );
		foreach ( (array) $config['keywords'] as $kw ) {
			$kw = strtolower( trim( (string) $kw ) );
			if ( '' !== $kw && false !== strpos( $haystack, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether semantic intent detection is enabled for this config. Complements
	 * (not replaces) the keyword fast-path; defaults ON when handoff is on.
	 *
	 * @param array $config Normalized config.
	 * @return bool
	 */
	public function intent_enabled( array $config ) {
		$trigger = isset( $config['trigger'] ) ? $config['trigger'] : 'both';
		if ( 'agent' === $trigger ) {
			return false; // Agent-decides only: the agent owns the decision.
		}
		return ! isset( $config['intent'] ) || ! empty( $config['intent'] );
	}

	/**
	 * Cheap lexical pre-gate for intent classification — a high-recall net that
	 * keeps the paid model classification off the majority of ordinary turns.
	 *
	 * @param string $message Visitor message.
	 * @return bool
	 */
	public static function intent_pregate( $message ) {
		return (bool) preg_match(
			'/\b(humans?|persons?|people|someone|somebody|anybody|agents?|represent\w*|reps?|operators?|managers?|advisor|consultant|staff|assistant\s+person|real\s+(?:person|human|people|agent)|live\s+(?:agent|person|chat|human|support)|talk(?:ing)?\s+to|speak(?:ing)?\s+(?:to|with)|transfer|escalat|customer\s+support|support\s+(?:team|agent|rep)|contact\s+(?:a|the)?\s*(?:human|person|agent|team))/i',
			(string) $message
		);
	}

	/**
	 * Detect an intent to reach a human semantically — catches natural phrasings
	 * that the keyword list misses. The model call is injected as $classifier so
	 * the transport stays in the chat handler and this stays unit-testable.
	 * Fail-open: any classifier error is treated as "no intent".
	 *
	 * @param string        $message    Visitor message.
	 * @param array         $config     Normalized config.
	 * @param callable|null $classifier fn(string $message):bool — true when the
	 *                                  message asks for a human.
	 * @return bool
	 */
	public function message_requests_human_by_intent( $message, array $config, $classifier ) {
		if ( ! $this->intent_enabled( $config ) ) {
			return false;
		}
		$msg = trim( (string) $message );
		if ( '' === $msg || ! self::intent_pregate( $msg ) ) {
			return false;
		}
		if ( ! is_callable( $classifier ) ) {
			return false;
		}
		try {
			return (bool) call_user_func( $classifier, $msg );
		} catch ( \Throwable $e ) {
			// Fail-open — never let a classifier fault break the chat turn.
			return false;
		}
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Start a handoff for a session. Assembles the transcript + metadata, calls
	 * the provider, and flips the conversation into pending_human on success.
	 *
	 * @param string $session_id  Session id.
	 * @param string $workflow_id Workflow id.
	 * @param array  $config      Normalized config.
	 * @param array  $context     { reason, summary, customer, kb_refs }.
	 * @return array{ok:bool,message:string,mode:string,external_ref?:string,error?:string}
	 */
	public function start( $session_id, $workflow_id, array $config, array $context = array() ) {
		// Idempotency: already handed off -> do not open a second conversation.
		if ( $this->store->is_human_controlled( $session_id ) ) {
			return array(
				'ok'      => true,
				'message' => (string) $config['handoffMessage'],
				'mode'    => $this->store->get_mode( $session_id ),
			);
		}

		$provider = $this->get_provider( $config );
		if ( is_wp_error( $provider ) ) {
			return array(
				'ok'      => false,
				'message' => '',
				'mode'    => WP_AI_Workflows_Handoff_Store::MODE_BOT,
				'error'   => $provider->get_error_message(),
			);
		}

		$transcript = $this->build_transcript( $session_id );
		$metadata   = array(
			'reason'   => isset( $context['reason'] ) ? (string) $context['reason'] : '',
			'summary'  => isset( $context['summary'] ) ? (string) $context['summary'] : '',
			'customer' => isset( $context['customer'] ) && is_array( $context['customer'] ) ? $context['customer'] : array(),
			'kb_refs'  => isset( $context['kb_refs'] ) && is_array( $context['kb_refs'] ) ? $context['kb_refs'] : array(),
		);

		$ref = $provider->start_handoff(
			array(
				'session_id'  => $session_id,
				'workflow_id' => $workflow_id,
			),
			$transcript,
			$metadata
		);

		if ( is_wp_error( $ref ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Handoff start failed',
				'error',
				array(
					'provider' => $config['provider'],
					'error'    => $ref->get_error_message(),
				)
			);
			return array(
				'ok'      => false,
				'message' => '',
				'mode'    => WP_AI_Workflows_Handoff_Store::MODE_BOT,
				'error'   => $ref->get_error_message(),
			);
		}

		$this->store->start(
			$session_id,
			$workflow_id,
			$config['provider'],
			(string) $ref,
			$metadata,
			WP_AI_Workflows_Handoff_Store::MODE_PENDING_HUMAN
		);

		return array(
			'ok'           => true,
			'message'      => (string) $config['handoffMessage'],
			'mode'         => WP_AI_Workflows_Handoff_Store::MODE_PENDING_HUMAN,
			'external_ref' => (string) $ref,
		);
	}

	/**
	 * Relay a subsequent visitor message to the human side (while handed off).
	 *
	 * @param string $session_id Session id.
	 * @param array  $config     Normalized config.
	 * @param string $message    Visitor message.
	 * @return void
	 */
	public function relay_visitor_message( $session_id, array $config, $message ) {
		$record = $this->store->get_record( $session_id );
		if ( ! $record || '' === (string) $record['external_ref'] ) {
			return;
		}
		$provider = $this->get_provider( $config );
		if ( is_wp_error( $provider ) ) {
			return;
		}
		$res = $provider->send_to_human( (string) $record['external_ref'], (string) $message );
		if ( is_wp_error( $res ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Handoff relay to human failed',
				'warning',
				array( 'error' => $res->get_error_message() )
			);
		}
	}

	/**
	 * Handle a verified inbound webhook: map the external ref to a session and
	 * inject the human's message(s) so the visitor sees them.
	 *
	 * @param string                              $provider_slug Provider slug.
	 * @param WP_AI_Workflows_Handoff_Provider    $provider      Provider instance.
	 * @param array                               $payload       Decoded webhook body.
	 * @return int Number of messages injected.
	 */
	public function handle_inbound( $provider_slug, $provider, array $payload ) {
		$messages = $provider->receive_inbound( $payload );
		if ( empty( $messages ) ) {
			return 0;
		}

		$injected = 0;
		foreach ( $messages as $m ) {
			$content = isset( $m['content'] ) ? (string) $m['content'] : '';
			$ref     = isset( $m['external_ref'] ) ? (string) $m['external_ref'] : '';
			$msg_id  = isset( $m['ext_msg_id'] ) ? (string) $m['ext_msg_id'] : '';
			if ( '' === $content || '' === $ref ) {
				continue;
			}
			$session_id = $this->store->session_for_external_ref( $provider_slug, $ref );
			if ( ! $session_id ) {
				continue;
			}
			// A human replied -> promote pending_human to human.
			$this->store->mark_human_active( $session_id );
			if ( $this->store->queue_inbound( $session_id, $content, $msg_id ) ) {
				++$injected;
			}
		}
		return $injected;
	}

	/**
	 * Resolve a handoff: tell the provider and return control to the bot.
	 *
	 * @param string $session_id Session id.
	 * @param array  $config     Normalized config.
	 * @return array{ok:bool,message:string,mode:string}
	 */
	public function end( $session_id, array $config ) {
		$record = $this->store->get_record( $session_id );
		if ( $record && '' !== (string) $record['external_ref'] ) {
			$provider = $this->get_provider( $config );
			if ( ! is_wp_error( $provider ) ) {
				$provider->end_handoff( (string) $record['external_ref'] );
			}
		}
		$this->store->end( $session_id );

		return array(
			'ok'      => true,
			'message' => (string) $config['resolvedMessage'],
			'mode'    => WP_AI_Workflows_Handoff_Store::MODE_BOT,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Build an ordered transcript from the session's chat history.
	 *
	 * @param string $session_id Session id.
	 * @param int    $limit      Max turns.
	 * @return array<int,array{role:string,content:string}>
	 */
	public function build_transcript( $session_id, $limit = 50 ) {
		global $wpdb;
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM %i WHERE session_id = %s ORDER BY id ASC LIMIT %d",
				$messages_table,
				$session_id,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'role'    => isset( $r['role'] ) ? (string) $r['role'] : 'user',
				'content' => isset( $r['content'] ) ? (string) $r['content'] : '',
			);
		}
		return $out;
	}

	/**
	 * Decrypt a stored credential if it carries the enc_ prefix; otherwise return
	 * it unchanged (plaintext values entered before save-encryption still work).
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function maybe_decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'enc_' ) && class_exists( 'WP_AI_Workflows_Encryption' ) ) {
			$plain = WP_AI_Workflows_Encryption::decrypt( substr( $value, 4 ) );
			return false === $plain ? '' : (string) $plain;
		}
		return $value;
	}
}
