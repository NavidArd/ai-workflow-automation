<?php
/**
 * WP_AI_Workflows_Platform_Callback — the cloud→WordPress action executor.
 * Receives HMAC-signed callbacks from the platform and dispatches a narrow,
 * named, allow-listed set of WordPress mutations, verified before dispatch:
 * signature, then timestamp window, then nonce replay/idempotency.
 *
 * Auth is the HMAC signature — the REST route is intentionally PUBLIC
 * (`permission_callback => '__return_true'`). Actions run as the system,
 * never with a user's elevated capabilities; `manage_options`-level
 * mutations are deliberately not in the allow-list.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Platform_Callback {

	/** ±window (seconds) the request timestamp must fall within (replay protection). */
	const TIMESTAMP_WINDOW = 300;

	/** Nonce transient TTL (seconds) — doubles as the idempotency cache lifetime. */
	const NONCE_TTL = 600;

	/** Transient key prefix for the per-nonce idempotency record. */
	const NONCE_PREFIX = 'wpaw_cb_nonce_';

	/**
	 * Route handler for POST /wp-ai-workflows/v1/platform/callback.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$raw       = $request->get_body();
		$signature = (string) $request->get_header( 'X-WPAW-Signature' );
		$timestamp = (string) $request->get_header( 'X-WPAW-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-WPAW-Nonce' );

		// ── 1. Signature (constant-time, BEFORE JSON parse) ──────────────────────
		$secret = WP_AI_Workflows_Platform_Client::get_callback_secret();
		if ( is_wp_error( $secret ) || '' === (string) $secret ) {
			// Disconnected / undecryptable key → no derivable secret. Auth failure.
			return self::reject( 401, 'invalid_signature', 'Signature verification failed.', 'no_secret' );
		}
		if ( '' === $signature || '' === $timestamp || '' === $nonce ) {
			return self::reject( 401, 'invalid_signature', 'Signature verification failed.', 'missing_headers' );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $raw, (string) $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::reject( 401, 'invalid_signature', 'Signature verification failed.', 'bad_signature' );
		}

		// ── 2. Timestamp window ──────────────────────────────────────────────────
		$ts = (int) $timestamp;
		if ( abs( time() - $ts ) > self::TIMESTAMP_WINDOW ) {
			return self::reject( 408, 'stale_timestamp', 'Request timestamp is outside the allowed window.', 'stale_timestamp' );
		}

		// ── 3. Nonce replay / idempotency ────────────────────────────────────────
		$nonce_key = self::NONCE_PREFIX . sanitize_key( $nonce );
		$cached    = get_transient( $nonce_key );
		if ( is_array( $cached ) && isset( $cached['sig'] ) ) {
			if ( hash_equals( (string) $cached['sig'], $signature ) ) {
				// Identical replay → return the cached response, DO NOT re-execute.
				return new WP_REST_Response( $cached['body'], (int) $cached['status'] );
			}
			// Same nonce, different signature → reject (potential tampering/reuse).
			return self::reject( 409, 'nonce_reuse', 'Nonce has already been used with a different request.', 'nonce_mismatch' );
		}

		// ── 4. Parse + dispatch (signature already validated over the raw bytes) ──
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return self::finalize( $nonce_key, $signature, 400, false, null, 'invalid_payload', 'Malformed request body.' );
		}

		$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';
		$data   = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();

		$handlers = self::get_action_handlers();
		if ( ! isset( $handlers[ $action ] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Callback rejected: unknown action ' . sanitize_key( $action ), 'warning' );
			return self::finalize( $nonce_key, $signature, 400, false, null, 'unknown_action', 'Unknown callback action.' );
		}

		$result = call_user_func( $handlers[ $action ], $data );

		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$err     = $result->get_error_message();
			$status  = (int) ( $result->get_error_data()['status'] ?? 400 );
			WP_AI_Workflows_Utilities::debug_log( 'Callback action ' . sanitize_key( $action ) . ' failed: ' . sanitize_key( (string) $code ), 'warning' );
			return self::finalize( $nonce_key, $signature, $status, false, null, (string) $code, (string) $err );
		}

		return self::finalize( $nonce_key, $signature, 200, true, $result, '', '' );
	}

	/**
	 * The locked, default action allow-list. Filterable, but ships closed to exactly
	 * the three v2.0 launch actions. Anything not here → 400 unknown_action.
	 *
	 * @return array<string,callable>
	 */
	private static function get_action_handlers() {
		$handlers = array(
			'save_output' => array( __CLASS__, 'action_save_output' ),
			'insert_post' => array( __CLASS__, 'action_insert_post' ),
			'update_post' => array( __CLASS__, 'action_update_post' ),
		);
		// Extensible but default-locked: filters may add actions on a hardened site.
		return apply_filters( 'wp_ai_workflows_callback_actions', $handlers );
	}

	/* ---------------------------------------------------------------------------
	 * Action handlers — each validates its own payload (fail-closed) and runs as
	 * the system. No user-context escalation; no privileged (options/users) writes.
	 * ------------------------------------------------------------------------- */

	/**
	 * save_output — insert a sanitized row into a plugin/custom output table
	 * (mirrors execute_output_node's `save` logic, but narrower: explicit,
	 * pre-resolved values only).
	 *
	 * @param array $payload {table, columns[], content?}
	 * @return array|WP_Error {output_id, table}
	 */
	public static function action_save_output( array $payload ) {
		global $wpdb;

		$table = isset( $payload['table'] ) ? (string) $payload['table'] : 'wp_ai_workflows_outputs';
		// Allow only alphanumerics + underscore, then prefix — never interpolate raw.
		$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		if ( '' === $table ) {
			return new WP_Error( 'invalid_payload', 'A valid table name is required.', array( 'status' => 400 ) );
		}
		$table_name = $wpdb->prefix . $table;

		$insert = array( 'created_at' => current_time( 'mysql' ) );

		if ( isset( $payload['columns'] ) && is_array( $payload['columns'] ) ) {
			foreach ( $payload['columns'] as $col ) {
				if ( ! is_array( $col ) || ! isset( $col['name'] ) ) {
					continue;
				}
				$name = sanitize_key( $col['name'] );
				if ( '' === $name || 'id' === $name || 'created_at' === $name ) {
					continue;
				}
				$value = array_key_exists( 'value', $col ) ? $col['value'] : ( isset( $col['mapping'] ) ? $col['mapping'] : '' );
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$insert[ $name ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		// Convenience: a single resolved `content` maps to the default table's
		// output_data column when no explicit columns were provided.
		if ( isset( $payload['content'] ) && ! isset( $insert['output_data'] ) && ! array_key_exists( 'node_id', $insert ) ) {
			$content              = $payload['content'];
			$insert['output_data'] = is_scalar( $content ) ? (string) $content : wp_json_encode( $content );
			if ( ! isset( $insert['node_id'] ) ) {
				$insert['node_id'] = 'cloud-callback';
			}
		}

		if ( count( $insert ) <= 1 ) {
			return new WP_Error( 'invalid_payload', 'Nothing to save (no columns or content).', array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writing a workflow output row (table name sanitized above).
		$ok = $wpdb->insert( $table_name, $insert );
		if ( false === $ok ) {
			WP_AI_Workflows_Utilities::debug_log( 'Callback save_output DB error: ' . $wpdb->last_error, 'error' );
			return new WP_Error( 'db_error', 'Failed to save output.', array( 'status' => 500 ) );
		}

		return array(
			'output_id' => (int) $wpdb->insert_id,
			'table'     => $table,
		);
	}

	/**
	 * insert_post — create a WordPress post from a sanitized payload.
	 *
	 * @param array $payload {title, content, status, post_type}
	 * @return array|WP_Error {post_id, status, post_type}
	 */
	public static function action_insert_post( array $payload ) {
		$post_data = self::build_post_data( $payload, false );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Callback insert_post error: ' . $post_id->get_error_code(), 'error' );
			return new WP_Error( 'insert_failed', 'Failed to create post.', array( 'status' => 500 ) );
		}

		return array(
			'post_id'   => (int) $post_id,
			'status'    => $post_data['post_status'],
			'post_type' => $post_data['post_type'],
		);
	}

	/**
	 * update_post — update an existing WordPress post (ID must exist + type check).
	 *
	 * @param array $payload {id, title?, content?, status?, post_type?}
	 * @return array|WP_Error {post_id}
	 */
	public static function action_update_post( array $payload ) {
		$post_id = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_payload', 'A valid post id is required for update.', array( 'status' => 400 ) );
		}
		$existing = get_post( $post_id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', 'The target post does not exist.', array( 'status' => 400 ) );
		}

		$update = array( 'ID' => $post_id );
		if ( isset( $payload['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( isset( $payload['content'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $payload['content'] );
		}
		if ( isset( $payload['status'] ) ) {
			$update['post_status'] = self::sanitize_post_status( (string) $payload['status'] );
		}

		if ( count( $update ) <= 1 ) {
			return new WP_Error( 'invalid_payload', 'No updatable fields were provided.', array( 'status' => 400 ) );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Callback update_post error: ' . $result->get_error_code(), 'error' );
			return new WP_Error( 'update_failed', 'Failed to update post.', array( 'status' => 500 ) );
		}

		return array( 'post_id' => (int) $post_id );
	}

	/**
	 * Build a sanitized wp_insert_post arg array from a callback payload.
	 *
	 * @param array $payload
	 * @param bool  $is_update
	 * @return array|WP_Error
	 */
	private static function build_post_data( array $payload, $is_update ) {
		$title   = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '';
		$content = isset( $payload['content'] ) ? wp_kses_post( (string) $payload['content'] ) : '';
		$status  = self::sanitize_post_status( isset( $payload['status'] ) ? (string) $payload['status'] : 'draft' );

		$post_type = isset( $payload['post_type'] ) ? sanitize_key( (string) $payload['post_type'] ) : 'post';
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		if ( '' === $title ) {
			$title = 'Auto-generated post ' . current_time( 'mysql' );
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => $post_type,
		);

		// Attribute to a configured author if the site set one; otherwise leave 0
		// (wp_insert_post applies its own defaults). Never elevate to a user context.
		$author = (int) apply_filters( 'wp_ai_workflows_callback_post_author', 0, $payload );
		if ( $author > 0 ) {
			$post_data['post_author'] = $author;
		}

		return $post_data;
	}

	/**
	 * Constrain a post status to a safe allow-list.
	 *
	 * @param string $status
	 * @return string
	 */
	private static function sanitize_post_status( $status ) {
		$allowed = array( 'draft', 'publish', 'pending', 'private', 'future' );
		$status  = sanitize_key( $status );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/* ---------------------------------------------------------------------------
	 * Response helpers
	 * ------------------------------------------------------------------------- */

	/**
	 * Cache the terminal response under the nonce (idempotency) and return it.
	 *
	 * @param string     $nonce_key
	 * @param string     $signature
	 * @param int        $status
	 * @param bool       $success
	 * @param array|null $result
	 * @param string     $code
	 * @param string     $message
	 * @return WP_REST_Response
	 */
	private static function finalize( $nonce_key, $signature, $status, $success, $result, $code, $message ) {
		if ( $success ) {
			$body = array(
				'success' => true,
				'result'  => $result,
			);
		} else {
			$body = array(
				'success' => false,
				'code'    => $code,
				'error'   => $message,
			);
		}

		set_transient(
			$nonce_key,
			array(
				'sig'    => $signature,
				'status' => (int) $status,
				'body'   => $body,
			),
			self::NONCE_TTL
		);

		return new WP_REST_Response( $body, (int) $status );
	}

	/**
	 * A pre-dispatch rejection (never cached — auth/timestamp/replay failures).
	 *
	 * @param int    $status
	 * @param string $code
	 * @param string $message  Generic, machine-readable — never echoes the payload.
	 * @param string $log_reason Internal reason for the (payload-free) debug log.
	 * @return WP_REST_Response
	 */
	private static function reject( $status, $code, $message, $log_reason ) {
		WP_AI_Workflows_Utilities::debug_log( 'Callback rejected (' . (int) $status . '): ' . sanitize_key( $log_reason ), 'warning' );
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'error'   => $message,
			),
			(int) $status
		);
	}
}
