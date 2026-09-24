<?php
/**
 * Opt-in chat memory (rolling summary + recent verbatim turns) for the chat
 * node. Scope controls whose memory is recalled: conversation (current
 * session), visitor (anonymous, across sessions), or user (logged-in
 * WordPress user; anonymous visitors fall back to conversation scope). The
 * injected block coexists with the Knowledge Base (RAG) block rather than
 * replacing it. All operations fail open: errors are logged and chat
 * proceeds as if memory were off.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider contract. A provider turns a per-key store into (a) injectable
 * context text and (b) a place to record new turns.
 */
interface WP_AI_Workflows_Chat_Memory_Provider {

	/**
	 * Build the text block to inject into the system prompt for this key.
	 *
	 * @param string                    $key             Scope key (session id, visitor id, or wpuser_<id>).
	 * @param string                    $scope           One of the WP_AI_Workflows_Chat_Memory::SCOPE_* constants.
	 * @param array<int,array{role:string,content:string}> $recent_messages Live session history the handler already has.
	 * @return string Context to inject, or '' when there is nothing to recall.
	 */
	public function build_context( $key, $scope, array $recent_messages );

	/**
	 * Record the latest turn(s) for this key, updating the store / summary.
	 *
	 * @param string                    $key      Scope key.
	 * @param string                    $scope    Scope constant.
	 * @param array<int,array{role:string,content:string}> $messages New messages to remember (e.g. the user + assistant turn).
	 * @return void
	 */
	public function record( $key, $scope, array $messages );
}

/**
 * Simple memory provider: a rolling summary plus the last N verbatim turns.
 */
class WP_AI_Workflows_Chat_Memory_Simple_Provider implements WP_AI_Workflows_Chat_Memory_Provider {

	/** Hard cap on the running summary so the store (and the injected prompt) stay bounded. */
	const SUMMARY_MAX_CHARS = 4000;

	/** Hard cap on a single stored message so a hostile visitor cannot bloat the store. */
	const MESSAGE_MAX_CHARS = 4000;

	/** @var int Number of most-recent turns (user+assistant pairs) kept verbatim. */
	private $recent_turns;

	/** @var bool Whether the rolling summary is maintained. */
	private $summary_enabled;

	/** @var callable|null fn(string $existing_summary, string $overflow_text): string */
	private $summarizer;

	/**
	 * @param int           $recent_turns    Turns to keep verbatim (>=1).
	 * @param bool           $summary_enabled Maintain a rolling summary of older turns.
	 * @param callable|null $summarizer      Summariser callback; null disables summarisation (recent turns still work).
	 */
	public function __construct( $recent_turns = 6, $summary_enabled = true, $summarizer = null ) {
		$this->recent_turns    = max( 1, (int) $recent_turns );
		$this->summary_enabled = (bool) $summary_enabled;
		$this->summarizer      = is_callable( $summarizer ) ? $summarizer : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function build_context( $key, $scope, array $recent_messages ) {
		$store = WP_AI_Workflows_Chat_Memory::load( $key, $scope );

		$summary = isset( $store['summary'] ) ? trim( (string) $store['summary'] ) : '';
		$recent  = isset( $store['recent'] ) && is_array( $store['recent'] ) ? $store['recent'] : array();

		$parts = array();

		if ( '' !== $summary ) {
			$parts[] = "Summary of the earlier conversation with this person:\n" . $summary;
		}

		// The live session history already carries the recent turns for a single
		// conversation, so for conversation scope we inject only the compressed
		// summary. For cross-session scopes the retained turns come from PRIOR
		// sessions the live history does not contain, so include them.
		if ( WP_AI_Workflows_Chat_Memory::SCOPE_CONVERSATION !== $scope && ! empty( $recent ) ) {
			$lines = array();
			foreach ( $recent as $msg ) {
				$role = ( isset( $msg['role'] ) && 'assistant' === $msg['role'] ) ? 'Assistant' : 'User';
				$text = isset( $msg['content'] ) ? trim( (string) $msg['content'] ) : '';
				if ( '' !== $text ) {
					$lines[] = '- ' . $role . ': ' . $text;
				}
			}
			if ( ! empty( $lines ) ) {
				$parts[] = "Recent things from previous sessions:\n" . implode( "\n", $lines );
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		$block  = "### CONVERSATION MEMORY ###\n";
		$block .= "Use the following remembered context to stay consistent with what this person told you before. ";
		$block .= "Do not repeat it back verbatim unless asked.\n\n";
		$block .= implode( "\n\n", $parts );
		$block .= "\n### END CONVERSATION MEMORY ###";

		return $block;
	}

	/**
	 * {@inheritDoc}
	 */
	public function record( $key, $scope, array $messages ) {
		$clean = array();
		foreach ( $messages as $msg ) {
			if ( ! isset( $msg['role'], $msg['content'] ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $msg['content'] );
			if ( '' === trim( $content ) ) {
				continue;
			}
			if ( strlen( $content ) > self::MESSAGE_MAX_CHARS ) {
				$content = substr( $content, 0, self::MESSAGE_MAX_CHARS );
			}
			$role    = ( 'assistant' === $msg['role'] ) ? 'assistant' : 'user';
			$clean[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		if ( empty( $clean ) ) {
			return;
		}

		$store   = WP_AI_Workflows_Chat_Memory::load( $key, $scope );
		$summary = isset( $store['summary'] ) ? (string) $store['summary'] : '';
		$recent  = isset( $store['recent'] ) && is_array( $store['recent'] ) ? $store['recent'] : array();

		$recent = array_merge( $recent, $clean );

		// A "turn" is a message; keep the last (recent_turns * 2) messages verbatim
		// (a user + assistant exchange counts as ~2 messages).
		$keep = $this->recent_turns * 2;

		if ( count( $recent ) > $keep ) {
			$overflow = array_slice( $recent, 0, count( $recent ) - $keep );
			$recent   = array_slice( $recent, count( $recent ) - $keep );

			if ( $this->summary_enabled && $this->summarizer && ! empty( $overflow ) ) {
				$overflow_text = '';
				foreach ( $overflow as $msg ) {
					$role           = ( 'assistant' === $msg['role'] ) ? 'Assistant' : 'User';
					$overflow_text .= $role . ': ' . $msg['content'] . "\n";
				}
				try {
					$new_summary = call_user_func( $this->summarizer, $summary, $overflow_text );
					if ( is_string( $new_summary ) && '' !== trim( $new_summary ) ) {
						$summary = trim( $new_summary );
					}
				} catch ( Exception $e ) {
					// Fail open: keep the previous summary, drop the overflow.
					WP_AI_Workflows_Utilities::debug_log(
						'Chat memory summarisation failed (continuing)',
						'warning',
						array( 'error' => $e->getMessage() )
					);
				}
			}
		}

		if ( strlen( $summary ) > self::SUMMARY_MAX_CHARS ) {
			$summary = substr( $summary, 0, self::SUMMARY_MAX_CHARS );
		}

		WP_AI_Workflows_Chat_Memory::persist(
			$key,
			$scope,
			array(
				'summary' => $summary,
				'recent'  => $recent,
			)
		);
	}
}

/**
 * Orchestrator: scope-key resolution, storage backends, the default summariser,
 * and the provider factory (swappable for a future semantic provider).
 */
class WP_AI_Workflows_Chat_Memory {

	const SCOPE_CONVERSATION = 'conversation';
	const SCOPE_VISITOR      = 'visitor';
	const SCOPE_USER         = 'user';

	/** First-party visitor cookie (12 months); anonymous, opaque id only. */
	const VISITOR_COOKIE = 'wpaw_chat_visitor';

	/** user_meta key holding a logged-in user's memory blob. */
	const USER_META_KEY = 'wpaw_chat_memory';

	/**
	 * Resolve the effective scope + storage key for the current request.
	 *
	 * Falls back to conversation scope when a cross-session scope cannot be
	 * satisfied (e.g. "user" scope for an anonymous visitor).
	 *
	 * @param string $requested_scope Scope requested on the node.
	 * @param string $session_id      Current chat session id.
	 * @return array{scope:string,key:string}
	 */
	public static function resolve_scope_key( $requested_scope, $session_id ) {
		$session_id = sanitize_text_field( (string) $session_id );

		switch ( $requested_scope ) {
			case self::SCOPE_USER:
				$user_id = get_current_user_id();
				if ( $user_id > 0 ) {
					return array(
						'scope' => self::SCOPE_USER,
						'key'   => 'wpuser_' . (int) $user_id,
					);
				}
				// Anonymous → fall back to conversation scope.
				return array(
					'scope' => self::SCOPE_CONVERSATION,
					'key'   => $session_id,
				);

			case self::SCOPE_VISITOR:
				$visitor = self::resolve_visitor_id();
				if ( '' !== $visitor ) {
					return array(
						'scope' => self::SCOPE_VISITOR,
						'key'   => $visitor,
					);
				}
				return array(
					'scope' => self::SCOPE_CONVERSATION,
					'key'   => $session_id,
				);

			case self::SCOPE_CONVERSATION:
			default:
				return array(
					'scope' => self::SCOPE_CONVERSATION,
					'key'   => $session_id,
				);
		}
	}

	/**
	 * Stable, anonymous first-party visitor id. Prefers an explicit id (a widget
	 * may pass one), then the first-party cookie, else mints + sets one. Returns
	 * '' only when no id is available and cookies cannot be set (headers sent).
	 *
	 * @param string|null $explicit Optional client-supplied visitor id.
	 * @return string
	 */
	public static function resolve_visitor_id( $explicit = null ) {
		if ( is_string( $explicit ) && '' !== trim( $explicit ) ) {
			return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $explicit ), 0, 64 );
		}

		if ( isset( $_COOKIE[ self::VISITOR_COOKIE ] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ self::VISITOR_COOKIE ] ) );
			$cookie = preg_replace( '/[^A-Za-z0-9_\-]/', '', $cookie );
			if ( '' !== $cookie ) {
				return substr( $cookie, 0, 64 );
			}
		}

		$new_id = 'v_' . wp_generate_uuid4();
		if ( ! headers_sent() ) {
			setcookie(
				self::VISITOR_COOKIE,
				$new_id,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::VISITOR_COOKIE ] = $new_id;
			return $new_id;
		}

		return '';
	}

	/**
	 * Build the provider for a chat node's memory config. Filterable so a future
	 * semantic/long-term provider can replace the simple one without touching the
	 * chat handler.
	 *
	 * @param array $config Node `data.memory` config.
	 * @return WP_AI_Workflows_Chat_Memory_Provider
	 */
	public static function get_provider( array $config ) {
		$recent_turns    = isset( $config['recentTurns'] ) ? (int) $config['recentTurns'] : 6;
		$summary_enabled = isset( $config['summaryEnabled'] ) ? (bool) $config['summaryEnabled'] : true;

		$provider = new WP_AI_Workflows_Chat_Memory_Simple_Provider(
			$recent_turns,
			$summary_enabled,
			array( __CLASS__, 'summarize' )
		);

		/**
		 * Swap in a different memory provider (e.g. a Supabase-pgvector semantic
		 * provider) implementing WP_AI_Workflows_Chat_Memory_Provider.
		 *
		 * @param WP_AI_Workflows_Chat_Memory_Provider $provider Default simple provider.
		 * @param array                                 $config   Node memory config.
		 */
		return apply_filters( 'wp_ai_workflows_chat_memory_provider', $provider, $config );
	}

	/**
	 * Default summariser: fold overflow turns into the running summary with one
	 * cheap AI call. Prefers an OpenAI key (gpt-4o-mini); falls back to the
	 * platform credits proxy when the site is keyless-connected. Returns the
	 * previous summary unchanged on any failure (fail open).
	 *
	 * @param string $existing_summary Current running summary.
	 * @param string $overflow_text    New older turns to fold in.
	 * @return string
	 */
	public static function summarize( $existing_summary, $overflow_text ) {
		$existing_summary = (string) $existing_summary;
		$overflow_text    = trim( (string) $overflow_text );
		if ( '' === $overflow_text ) {
			return $existing_summary;
		}

		$system = 'You maintain a compact running memory of a chat between an assistant and a user. '
			. 'Merge the previous summary with the new messages into a single concise summary (max ~150 words). '
			. 'Preserve durable facts the user shared (names, preferences, goals, decisions). '
			. 'Write plain text, no preamble.';

		$user = "Previous summary:\n" . ( '' === $existing_summary ? '(none)' : $existing_summary )
			. "\n\nNew messages:\n" . $overflow_text
			. "\n\nUpdated summary:";

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		// Prefer a cheap direct OpenAI call.
		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( ! empty( $api_key ) ) {
			$summary = self::summarize_via_openai( $api_key, $messages );
			if ( null !== $summary ) {
				return $summary;
			}
		}

		// Keyless sites: route through the platform credits proxy when connected.
		if ( class_exists( 'WP_AI_Workflows_Platform_Client' )
			&& method_exists( 'WP_AI_Workflows_Platform_Client', 'is_connected' )
			&& WP_AI_Workflows_Platform_Client::is_connected() ) {
			$response = WP_AI_Workflows_Platform_Client::proxy_ai(
				array(
					'model'       => 'gpt-4o-mini',
					'messages'    => $messages,
					'maxTokens'   => 300,
					'temperature' => 0.2,
				)
			);
			if ( ! is_wp_error( $response ) && isset( $response['content'] ) ) {
				$content = trim( (string) $response['content'] );
				if ( '' !== $content ) {
					return $content;
				}
			}
		}

		// Fail open.
		return $existing_summary;
	}

	/**
	 * One OpenAI Chat Completions summary call. Returns null on any failure.
	 *
	 * @param string $api_key  OpenAI API key.
	 * @param array  $messages Chat messages.
	 * @return string|null
	 */
	private static function summarize_via_openai( $api_key, array $messages ) {
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => 'gpt-4o-mini',
						'messages'    => $messages,
						'max_tokens'  => 300,
						'temperature' => 0.2,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			return null;
		}

		return trim( (string) $body['choices'][0]['message']['content'] );
	}

	/* ---------------------------------------------------------------------
	 * Storage. conversation + visitor scopes live in a custom table; user
	 * scope lives in user_meta (per the plugin's data model).
	 * ------------------------------------------------------------------- */

	/**
	 * @return string Fully-qualified memory table name.
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_ai_workflows_chat_memory';
	}

	/**
	 * Create the memory table (idempotent). Called from the plugin's schema
	 * routine alongside the other tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		// $charset_collate comes from $wpdb->get_charset_collate() (a trusted
		// server value, not user input); the table name is bound via %i. This
		// mirrors the existing WP_AI_Workflows_Knowledge_Base::create_tables().
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"CREATE TABLE IF NOT EXISTS %i (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				mem_key VARCHAR(191) NOT NULL,
				scope VARCHAR(32) NOT NULL,
				summary LONGTEXT,
				recent LONGTEXT,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY mem_key_scope (mem_key, scope)
			) " . $charset_collate,
			$table
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Load the stored memory blob for a key. Never throws.
	 *
	 * @param string $key   Scope key.
	 * @param string $scope Scope constant.
	 * @return array{summary:string,recent:array}
	 */
	public static function load( $key, $scope ) {
		$empty = array(
			'summary' => '',
			'recent'  => array(),
		);

		try {
			if ( self::SCOPE_USER === $scope ) {
				$user_id = self::user_id_from_key( $key );
				if ( $user_id <= 0 ) {
					return $empty;
				}
				$blob = get_user_meta( $user_id, self::USER_META_KEY, true );
				return self::normalize_blob( $blob );
			}

			global $wpdb;
			$table = self::table();
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT summary, recent FROM %i WHERE mem_key = %s AND scope = %s",
					$table,
					(string) $key,
					(string) $scope
				),
				ARRAY_A
			);

			if ( ! $row ) {
				return $empty;
			}

			return array(
				'summary' => isset( $row['summary'] ) ? (string) $row['summary'] : '',
				'recent'  => isset( $row['recent'] ) ? self::decode_recent( $row['recent'] ) : array(),
			);
		} catch ( Exception $e ) {
			return $empty;
		}
	}

	/**
	 * Persist the memory blob for a key. Never throws.
	 *
	 * @param string $key   Scope key.
	 * @param string $scope Scope constant.
	 * @param array  $data  {summary:string, recent:array}
	 * @return void
	 */
	public static function persist( $key, $scope, array $data ) {
		$summary = isset( $data['summary'] ) ? (string) $data['summary'] : '';
		$recent  = isset( $data['recent'] ) && is_array( $data['recent'] ) ? $data['recent'] : array();

		try {
			if ( self::SCOPE_USER === $scope ) {
				$user_id = self::user_id_from_key( $key );
				if ( $user_id <= 0 ) {
					return;
				}
				update_user_meta(
					$user_id,
					self::USER_META_KEY,
					array(
						'summary' => $summary,
						'recent'  => $recent,
					)
				);
				return;
			}

			self::maybe_create_table();

			global $wpdb;
			$table  = self::table();
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE mem_key = %s AND scope = %s",
					$table,
					(string) $key,
					(string) $scope
				)
			);

			if ( $exists ) {
				$wpdb->update(
					$table,
					array(
						'summary'    => $summary,
						'recent'     => wp_json_encode( $recent ),
						'updated_at' => current_time( 'mysql' ),
					),
					array(
						'mem_key' => (string) $key,
						'scope'   => (string) $scope,
					),
					array( '%s', '%s', '%s' ),
					array( '%s', '%s' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'mem_key'    => (string) $key,
						'scope'      => (string) $scope,
						'summary'    => $summary,
						'recent'     => wp_json_encode( $recent ),
						'updated_at' => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s' )
				);
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Chat memory persist failed (continuing)',
				'warning',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Clear the stored memory for a key (privacy / session reset). Never throws.
	 *
	 * @param string $key   Scope key.
	 * @param string $scope Scope constant.
	 * @return void
	 */
	public static function clear( $key, $scope ) {
		try {
			if ( self::SCOPE_USER === $scope ) {
				$user_id = self::user_id_from_key( $key );
				if ( $user_id > 0 ) {
					delete_user_meta( $user_id, self::USER_META_KEY );
				}
				return;
			}

			global $wpdb;
			$wpdb->delete(
				self::table(),
				array(
					'mem_key' => (string) $key,
					'scope'   => (string) $scope,
				),
				array( '%s', '%s' )
			);
		} catch ( Exception $e ) {
			// Best-effort.
			WP_AI_Workflows_Utilities::debug_log(
				'Chat memory clear failed (continuing)',
				'warning',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Lazily create the table on first write so upgrades that predate this
	 * feature still work without a manual reactivation.
	 *
	 * @return void
	 */
	private static function maybe_create_table() {
		if ( get_option( 'wpaw_chat_memory_table_ready' ) ) {
			return;
		}
		self::create_tables();
		update_option( 'wpaw_chat_memory_table_ready', 1, false );
	}

	/**
	 * @param string $key Scope key of the form "wpuser_<id>".
	 * @return int
	 */
	private static function user_id_from_key( $key ) {
		if ( 0 === strpos( (string) $key, 'wpuser_' ) ) {
			return (int) substr( (string) $key, 7 );
		}
		return 0;
	}

	/**
	 * @param mixed $blob Raw user_meta value.
	 * @return array{summary:string,recent:array}
	 */
	private static function normalize_blob( $blob ) {
		if ( ! is_array( $blob ) ) {
			return array(
				'summary' => '',
				'recent'  => array(),
			);
		}
		return array(
			'summary' => isset( $blob['summary'] ) ? (string) $blob['summary'] : '',
			'recent'  => isset( $blob['recent'] ) && is_array( $blob['recent'] ) ? $blob['recent'] : array(),
		);
	}

	/**
	 * @param string $raw JSON string.
	 * @return array
	 */
	private static function decode_recent( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
