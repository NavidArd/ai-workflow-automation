<?php
/**
 * Handoff Store - the conversation-mode state machine + inbound relay queue.
 *
 * Tracks a chat session's mode (bot | pending_human | human) in a dedicated,
 * self-healing table (existing chat/session tables are never altered). Inbound
 * human messages are persisted to chat history and queued in a short-lived
 * transient the widget polls.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Handoff_Store {

	const MODE_BOT           = 'bot';
	const MODE_PENDING_HUMAN = 'pending_human';
	const MODE_HUMAN         = 'human';

	/** @var string Fully-qualified handoff-sessions table name. */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wp_ai_workflows_handoff_sessions';
		$this->ensure_table_exists();
	}

	/* ---------------------------------------------------------------------
	 * Self-healing table
	 * ------------------------------------------------------------------- */

	/**
	 * Create the handoff-sessions table if missing. Cheap SHOW TABLES check
	 * cached in a daily transient (mirrors the agent-audit / KB pattern).
	 */
	private function ensure_table_exists() {
		if ( false !== get_transient( 'wp_ai_workflows_handoff_tbl_ok' ) ) {
			return;
		}

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );

		if ( $exists !== $this->table ) {
			$this->create_tables();
		}

		set_transient( 'wp_ai_workflows_handoff_tbl_ok', 1, DAY_IN_SECONDS );
	}

	/**
	 * Create the handoff-sessions table. One row per handed-off chat session.
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = $wpdb->prepare(
			"CREATE TABLE IF NOT EXISTS %i (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id VARCHAR(191) NOT NULL,
				workflow_id VARCHAR(191) DEFAULT '',
				provider VARCHAR(32) DEFAULT '',
				mode VARCHAR(20) NOT NULL DEFAULT 'bot',
				external_ref VARCHAR(191) DEFAULT '',
				metadata LONGTEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY session_id (session_id),
				KEY provider_ref (provider, external_ref),
				KEY mode (mode)
			) " . $charset_collate, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is passed as %i; charset comes from $wpdb->get_charset_collate().
			$this->table
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ---------------------------------------------------------------------
	 * Mode state machine
	 * ------------------------------------------------------------------- */

	/**
	 * Current mode for a session. Absence (any existing session) = `bot`.
	 *
	 * @param string $session_id Session id.
	 * @return string One of MODE_*.
	 */
	public function get_mode( $session_id ) {
		$row = $this->get_record( $session_id );
		return $row ? (string) $row['mode'] : self::MODE_BOT;
	}

	/**
	 * True while a human owns (or is being asked to own) the conversation, i.e.
	 * the bot must stop auto-responding and only relay.
	 *
	 * @param string $session_id Session id.
	 * @return bool
	 */
	public function is_human_controlled( $session_id ) {
		$mode = $this->get_mode( $session_id );
		return self::MODE_HUMAN === $mode || self::MODE_PENDING_HUMAN === $mode;
	}

	/**
	 * Full handoff record for a session.
	 *
	 * @param string $session_id Session id.
	 * @return array<string,mixed>|null
	 */
	public function get_record( $session_id ) {
		global $wpdb;
		if ( '' === (string) $session_id ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE session_id = %s", $this->table, $session_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['metadata'] = $row['metadata'] ? json_decode( $row['metadata'], true ) : array();
		return $row;
	}

	/**
	 * List conversations currently owned by (or awaiting) a human - the source
	 * for the operator inbox. Newest activity first.
	 *
	 * @param array $modes Modes to include (default pending_human + human).
	 * @param int   $limit Max rows.
	 * @return array<int,array<string,mixed>> Handoff records (metadata decoded).
	 */
	public function list_active( array $modes = array( self::MODE_PENDING_HUMAN, self::MODE_HUMAN ), $limit = 100 ) {
		global $wpdb;

		// Sanitize the requested modes against the allow-list (fail-closed).
		$allowed = array();
		foreach ( $modes as $m ) {
			$m = $this->sanitize_mode( $m );
			if ( self::MODE_BOT !== $m ) {
				$allowed[ $m ] = true;
			}
		}
		if ( empty( $allowed ) ) {
			$allowed = array(
				self::MODE_PENDING_HUMAN => true,
				self::MODE_HUMAN         => true,
			);
		}
		$mode_list    = array_keys( $allowed );
		$placeholders = implode( ', ', array_fill( 0, count( $mode_list ), '%s' ) );

		// %i (identifier) + the mode placeholders + the LIMIT, all via prepare().
		$args    = array_merge( array( $this->table ), $mode_list, array( max( 1, (int) $limit ) ) );
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE mode IN ( $placeholders ) ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['metadata'] = ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : array();
			$out[]           = $row;
		}
		return $out;
	}

	/**
	 * Map an external provider reference back to a local session id (inbound
	 * webhook routing).
	 *
	 * @param string $provider     Provider slug.
	 * @param string $external_ref External reference.
	 * @return string|null Session id.
	 */
	public function session_for_external_ref( $provider, $external_ref ) {
		global $wpdb;
		if ( '' === (string) $external_ref ) {
			return null;
		}
		$session_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT session_id FROM %i WHERE provider = %s AND external_ref = %s ORDER BY id DESC LIMIT 1",
				$this->table,
				$provider,
				$external_ref
			)
		);
		return $session_id ? (string) $session_id : null;
	}

	/**
	 * Record a started handoff and flip the conversation into human control.
	 *
	 * @param string $session_id   Session id.
	 * @param string $workflow_id  Workflow id.
	 * @param string $provider     Provider slug.
	 * @param string $external_ref External reference from the provider.
	 * @param array  $metadata     Handoff metadata (customer, summary, ...).
	 * @param string $mode         Initial mode (default pending_human).
	 * @return void
	 */
	public function start( $session_id, $workflow_id, $provider, $external_ref, array $metadata = array(), $mode = self::MODE_PENDING_HUMAN ) {
		global $wpdb;
		$now      = current_time( 'mysql' );
		$existing = $this->get_record( $session_id );

		$data = array(
			'workflow_id'  => (string) $workflow_id,
			'provider'     => (string) $provider,
			'mode'         => $this->sanitize_mode( $mode ),
			'external_ref' => (string) $external_ref,
			'metadata'     => wp_json_encode( $metadata ),
			'updated_at'   => $now,
		);

		if ( $existing ) {
			$wpdb->update( $this->table, $data, array( 'session_id' => (string) $session_id ) );
		} else {
			$data['session_id'] = (string) $session_id;
			$data['created_at'] = $now;
			$wpdb->insert( $this->table, $data );
		}
	}

	/**
	 * Set the mode for a session (creating a `bot` row if none exists).
	 *
	 * @param string $session_id Session id.
	 * @param string $mode       One of MODE_*.
	 * @return void
	 */
	public function set_mode( $session_id, $mode ) {
		global $wpdb;
		$mode = $this->sanitize_mode( $mode );
		if ( $this->get_record( $session_id ) ) {
			$wpdb->update(
				$this->table,
				array(
					'mode'       => $mode,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'session_id' => (string) $session_id )
			);
		} else {
			$wpdb->insert(
				$this->table,
				array(
					'session_id' => (string) $session_id,
					'mode'       => $mode,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Promote pending_human -> human on the first human reply.
	 *
	 * @param string $session_id Session id.
	 * @return void
	 */
	public function mark_human_active( $session_id ) {
		if ( self::MODE_PENDING_HUMAN === $this->get_mode( $session_id ) ) {
			$this->set_mode( $session_id, self::MODE_HUMAN );
		}
	}

	/**
	 * Resolve a handoff: control returns to the bot.
	 *
	 * @param string $session_id Session id.
	 * @return void
	 */
	public function end( $session_id ) {
		$this->set_mode( $session_id, self::MODE_BOT );
	}

	/**
	 * @param string $mode Candidate mode.
	 * @return string Sanitized mode (defaults to bot).
	 */
	private function sanitize_mode( $mode ) {
		$allowed = array( self::MODE_BOT, self::MODE_PENDING_HUMAN, self::MODE_HUMAN );
		return in_array( $mode, $allowed, true ) ? $mode : self::MODE_BOT;
	}

	/* ---------------------------------------------------------------------
	 * Inbound relay queue (human -> visitor)
	 * ------------------------------------------------------------------- */

	/**
	 * Queue an inbound human message for delivery to the visitor. Stored both in
	 * the durable chat-messages table (history) and a short-lived transient the
	 * widget polls. De-duplicated per (session, external message id) so webhook
	 * retries never double-post.
	 *
	 * @param string $session_id Session id.
	 * @param string $content    Message body.
	 * @param string $ext_msg_id Provider message id (for replay-safety). Optional.
	 * @param array  $file       Optional shared-file descriptor (name/size/type/url/ext/is_image).
	 * @param array  $opts       Optional: {
	 *                             blocks:  array  interactive rich-message blocks (agent cards),
	 *                             source:  string '' human relay (default) | 'agent' bot resume,
	 *                             durable: bool   write a chat-history row (default true; pass
	 *                                             false when the caller already persisted it).
	 *                           }
	 * @return bool True if queued, false if a duplicate/empty was skipped.
	 */
	public function queue_inbound( $session_id, $content, $ext_msg_id = '', $file = null, $opts = array() ) {
		$content   = trim( (string) $content );
		$has_file  = is_array( $file ) && ! empty( $file['url'] );
		$opts      = is_array( $opts ) ? $opts : array();
		$blocks    = isset( $opts['blocks'] ) && is_array( $opts['blocks'] ) ? $opts['blocks'] : array();
		$source    = isset( $opts['source'] ) ? (string) $opts['source'] : '';
		$durable   = ! array_key_exists( 'durable', $opts ) || (bool) $opts['durable'];
		$has_blocks = ! empty( $blocks );
		// A file-only or blocks-only relay is valid even with empty text.
		if ( '' === (string) $session_id || ( '' === $content && ! $has_file && ! $has_blocks ) ) {
			return false;
		}

		// Replay/idempotency guard: skip a message we already delivered.
		if ( '' !== (string) $ext_msg_id ) {
			$seen_key = 'wpaiw_handoff_seen_' . md5( $session_id . '|' . $ext_msg_id );
			if ( false !== get_transient( $seen_key ) ) {
				return false;
			}
			set_transient( $seen_key, 1, HOUR_IN_SECONDS );
		}

		// Durable copy in chat history (role assistant = incoming bubble). Human
		// relays are flagged handoff_inbound; agent resumes carry blocks so a
		// reload re-renders the cards. Skipped when $durable is false (caller
		// already persisted the turn).
		global $wpdb;
		if ( $durable ) {
			$messages_table = $wpdb->prefix . 'wp_ai_workflows_chat_messages';
			$meta           = ( 'agent' === $source ) ? array() : array( 'handoff_inbound' => true );
			if ( $has_file ) {
				$meta['file'] = $file;
			}
			if ( $has_blocks ) {
				$meta['blocks'] = $blocks;
			}
			$wpdb->insert(
				$messages_table,
				array(
					'session_id' => (string) $session_id,
					'role'       => 'assistant',
					'content'    => $content,
					'created_at' => current_time( 'mysql' ),
					'metadata'   => wp_json_encode( $meta ),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		}

		// Live delivery queue (widget poll). Append to a bounded transient list.
		$queue_key = 'wpaiw_handoff_inbox_' . md5( (string) $session_id );
		$queue     = get_transient( $queue_key );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$entry = array(
			'content'   => $content,
			'role'      => 'assistant',
			'timestamp' => time(),
		);
		if ( $has_file ) {
			$entry['file'] = $file;
		}
		if ( $has_blocks ) {
			$entry['blocks'] = $blocks;
		}
		// 'agent' => render as a normal bot message (not a live-human relay). The
		// widget uses this to pick the bubble style + resume its awaiting state.
		if ( '' !== $source ) {
			$entry['source'] = $source;
		}
		$queue[] = $entry;
		// Keep the queue bounded (last 50 undelivered) to avoid unbounded growth.
		if ( count( $queue ) > 50 ) {
			$queue = array_slice( $queue, -50 );
		}
		set_transient( $queue_key, $queue, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Drain the inbound queue for the widget poll. Returns pending human messages
	 * and clears them (at-most-once live delivery; the durable copy remains in
	 * chat history).
	 *
	 * @param string $session_id Session id.
	 * @return array<int,array{content:string,role:string,timestamp:int}>
	 */
	public function drain_inbound( $session_id ) {
		$queue_key = 'wpaiw_handoff_inbox_' . md5( (string) $session_id );
		$queue     = get_transient( $queue_key );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return array();
		}
		delete_transient( $queue_key );
		return $queue;
	}
}
