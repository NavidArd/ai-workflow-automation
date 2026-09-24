<?php
/**
 * Chat Uploads — secure, opt-in file sharing inside a chat conversation.
 *
 * Files are validated by real bytes (not client-declared type), size-capped,
 * stored outside any web-executable path, and served only via a token-gated
 * REST route ({@see WP_AI_Workflows_REST_API::serve_chat_file}). Mirrors the
 * hardened Knowledge Base ingest pattern ({@see WP_AI_Workflows_Knowledge_Base::ingest_file}).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_AGENT_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Chat_Uploads {

	/** Hard ceiling on any single upload, regardless of the node's cap. */
	const MAX_BYTES = 20971520; // 20 MB.

	/** Default per-node size cap (MB) when the node does not specify one. */
	const DEFAULT_MAX_MB = 10;

	/** Per-IP upload budget per hour (abuse guard on the public route). */
	const RATE_PER_HOUR = 40;

	/** Longest AI-awareness text snippet extracted from a shared document. */
	const EXTRACT_CHARS = 4000;

	/**
	 * Server allowlist: extension group => canonical MIME. This is the outer
	 * bound — the node's own allowedTypes can only ever narrow it. Text-family
	 * extensions map to text/plain because that is what finfo actually reports
	 * for .md/.csv, so the wp_check_filetype_and_ext real-byte cross-check
	 * accepts them (the extension is still constrained to this list).
	 *
	 * @return array<string,string>
	 */
	public static function server_allowlist() {
		return array(
			'txt|text|md|markdown|csv' => 'text/plain',
			'pdf'                      => 'application/pdf',
			'doc'                      => 'application/msword',
			'docx'                     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'rtf'                      => 'application/rtf',
			'png'                      => 'image/png',
			'jpg|jpeg'                 => 'image/jpeg',
			'gif'                      => 'image/gif',
			'webp'                     => 'image/webp',
		);
	}

	/** @return array<int,string> Image extensions (rendered as thumbnails). */
	public static function image_exts() {
		return array( 'png', 'jpg', 'jpeg', 'gif', 'webp' );
	}

	/**
	 * Resolve the fileUploads config for a workflow's chat node.
	 *
	 * @param string $workflow_id Workflow id (may carry a node suffix).
	 * @return array{enabled:bool,maxSizeMb:int,allowedTypes:array<int,string>}|null
	 */
	public static function config_for_workflow( $workflow_id ) {
		$workflow_id = (string) $workflow_id;
		if ( '' === $workflow_id || ! class_exists( 'WP_AI_Workflows_Workflow_DBAL' ) ) {
			return null;
		}

		$base     = preg_replace( '/-[\w\d]+$/', '', $workflow_id );
		$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $base );
		if ( ! $workflow || empty( $workflow['nodes'] ) || ! is_array( $workflow['nodes'] ) ) {
			// Retry with the raw id in case there was no node suffix to strip.
			$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow_id );
		}
		if ( ! $workflow || empty( $workflow['nodes'] ) || ! is_array( $workflow['nodes'] ) ) {
			return null;
		}

		foreach ( $workflow['nodes'] as $node ) {
			if ( isset( $node['type'] ) && 'chat' === $node['type'] ) {
				$fu = isset( $node['data']['behavior']['fileUploads'] ) ? $node['data']['behavior']['fileUploads'] : null;
				return self::normalize_config( $fu );
			}
		}
		return null;
	}

	/**
	 * Normalize a raw fileUploads config to a safe, fully-populated shape.
	 *
	 * @param mixed $raw Raw config (may be null / partial / untrusted).
	 * @return array{enabled:bool,maxSizeMb:int,allowedTypes:array<int,string>}
	 */
	public static function normalize_config( $raw ) {
		$raw          = is_array( $raw ) ? $raw : array();
		$enabled      = ! empty( $raw['enabled'] );
		$max_mb       = isset( $raw['maxSizeMb'] ) ? (int) $raw['maxSizeMb'] : self::DEFAULT_MAX_MB;
		$max_mb       = max( 1, min( $max_mb, (int) floor( self::MAX_BYTES / MB_IN_BYTES ) ) );
		$allowed_raw  = isset( $raw['allowedTypes'] ) && is_array( $raw['allowedTypes'] ) ? $raw['allowedTypes'] : array();
		$allowed      = array();
		foreach ( $allowed_raw as $t ) {
			$t = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $t ) );
			if ( '' !== $t ) {
				$allowed[] = ( 'jpeg' === $t ) ? 'jpg' : $t;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );
		if ( empty( $allowed ) ) {
			$allowed = array( 'pdf', 'doc', 'docx', 'txt', 'csv', 'png', 'jpg' );
		}
		return array(
			'enabled'      => $enabled,
			'maxSizeMb'    => $max_mb,
			'allowedTypes' => $allowed,
		);
	}

	/**
	 * Build the effective wp_check_filetype allowlist for this node: the server
	 * allowlist intersected with the node's own allowedTypes (fail-closed — an
	 * unknown/dangerous extension can never be added, only removed).
	 *
	 * @param array $config Normalized config.
	 * @return array<string,string> extension-group => MIME.
	 */
	public static function effective_allowlist( array $config ) {
		$allowed = array();
		foreach ( $config['allowedTypes'] as $t ) {
			$allowed[ $t ] = true;
			if ( 'jpg' === $t ) {
				$allowed['jpeg'] = true;
			}
		}

		$out = array();
		foreach ( self::server_allowlist() as $group => $mime ) {
			$exts = explode( '|', $group );
			$keep = array();
			foreach ( $exts as $ext ) {
				if ( isset( $allowed[ $ext ] ) ) {
					$keep[] = $ext;
				}
			}
			if ( ! empty( $keep ) ) {
				$out[ implode( '|', $keep ) ] = $mime;
			}
		}
		return $out;
	}

	// Storage location (deny-all, token-served).

	/**
	 * Absolute path to the private chat-uploads directory, creating it and its
	 * deny-all guards on first use.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'upload_dir', 'The uploads directory is not writable.' );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'wpaiw-chat-uploads';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::write_guards( $dir );
		return $dir;
	}

	/**
	 * Drop deny-all guards so the store is never directly web-servable. Files
	 * are reachable ONLY through the token-gated REST route. Covers Apache
	 * (.htaccess), IIS (web.config) and directory listing (index.html).
	 *
	 * @param string $dir Directory to guard.
	 * @return void
	 */
	private static function write_guards( $dir ) {
		$ht = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			$rules  = "# Chat uploads: no direct web access — served only via the token-gated REST route.\n";
			$rules .= "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			@file_put_contents( $ht, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
		$wc = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $wc ) ) {
			$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n<system.webServer>\n";
			$xml .= "<authorization>\n<deny users=\"*\" />\n</authorization>\n";
			$xml .= "</system.webServer>\n</configuration>\n";
			@file_put_contents( $wc, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
		$idx = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Absolute path to a stored file for a token (sharded by the first 2 chars).
	 *
	 * @param string $token Alphanumeric token.
	 * @param string $ext   File extension.
	 * @return string|WP_Error
	 */
	private static function stored_file_path( $token, $ext ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$shard = trailingslashit( $base ) . substr( $token, 0, 2 );
		if ( ! is_dir( $shard ) ) {
			wp_mkdir_p( $shard );
		}
		return trailingslashit( $shard ) . $token . '.' . $ext;
	}

	/**
	 * Sidecar metadata path for a token.
	 *
	 * @param string $token Alphanumeric token.
	 * @return string|WP_Error
	 */
	private static function sidecar_path( $token ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$shard = trailingslashit( $base ) . substr( $token, 0, 2 );
		if ( ! is_dir( $shard ) ) {
			wp_mkdir_p( $shard );
		}
		return trailingslashit( $shard ) . $token . '.json';
	}

	// Validate + store.

	/**
	 * Validate a single uploaded file against the node's effective allowlist and
	 * size cap, then move it into the private store and record its sidecar.
	 *
	 * @param array  $file       A single $_FILES entry (tmp_name, name, size, error).
	 * @param string $session_id Owning chat session id.
	 * @param array  $config     Normalized fileUploads config.
	 * @return array{token:string,name:string,size:int,type:string,ext:string,url:string,is_image:bool}|WP_Error
	 */
	public static function validate_and_store( $file, $session_id, array $config ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'missing_file', 'No file was uploaded.', array( 'status' => 400 ) );
		}
		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload_error', 'The file failed to upload.', array( 'status' => 400 ) );
		}

		$max_bytes = min( (int) $config['maxSizeMb'] * MB_IN_BYTES, self::MAX_BYTES );
		if ( (int) $file['size'] > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'The file exceeds the %d MB limit.', (int) $config['maxSizeMb'] ),
				array( 'status' => 413 )
			);
		}

		$allowed_mimes = self::effective_allowlist( $config );
		if ( empty( $allowed_mimes ) ) {
			return new WP_Error( 'no_types', 'No file types are permitted for this chat.', array( 'status' => 415 ) );
		}

		$original_name = sanitize_file_name( (string) $file['name'] );
		$checked       = wp_check_filetype_and_ext( $file['tmp_name'], $original_name, $allowed_mimes );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new WP_Error(
				'invalid_file_type',
				'That file type is not allowed here.',
				array( 'status' => 415 )
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$overrides = array(
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		);
		$moved = wp_handle_upload( $file, $overrides );
		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			$err = ( is_array( $moved ) && isset( $moved['error'] ) ) ? $moved['error'] : 'The file could not be stored.';
			return new WP_Error( 'upload_error', $err, array( 'status' => 400 ) );
		}

		$ext   = strtolower( (string) $checked['ext'] );
		$token = wp_generate_password( 40, false, false ); // 40 chars, alphanumeric.

		$dest = self::stored_file_path( $token, $ext );
		if ( is_wp_error( $dest ) ) {
			wp_delete_file( $moved['file'] );
			return $dest;
		}

		// Relocate out of the public uploads root into the deny-all store.
		if ( ! @rename( $moved['file'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! @copy( $moved['file'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				wp_delete_file( $moved['file'] );
				return new WP_Error( 'store_failed', 'The file could not be stored.', array( 'status' => 500 ) );
			}
			wp_delete_file( $moved['file'] );
		}
		@chmod( $dest, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$is_image = in_array( $ext, self::image_exts(), true );
		$meta     = array(
			'session_id' => (string) $session_id,
			'name'       => $original_name,
			'ext'        => $ext,
			'type'       => (string) $checked['type'],
			'size'       => (int) $file['size'],
			'is_image'   => $is_image,
			'created'    => current_time( 'mysql' ),
		);

		$sidecar = self::sidecar_path( $token );
		if ( is_wp_error( $sidecar ) ) {
			wp_delete_file( $dest );
			return $sidecar;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		@file_put_contents( $sidecar, wp_json_encode( $meta ) );

		$url = add_query_arg(
			array(
				'token'      => $token,
				'session_id' => rawurlencode( (string) $session_id ),
			),
			rest_url( 'wp-ai-workflows/v1/chat-file' )
		);

		return array(
			'token'    => $token,
			'name'     => $original_name,
			'size'     => (int) $file['size'],
			'type'     => (string) $checked['type'],
			'ext'      => $ext,
			'url'      => $url,
			'is_image' => $is_image,
		);
	}

	/**
	 * Resolve a token to its sidecar + on-disk path, enforcing the session bind.
	 *
	 * @param string $token      Token.
	 * @param string $session_id Session the caller claims to own.
	 * @return array{meta:array,path:string}|null
	 */
	public static function resolve( $token, $session_id ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token || strlen( $token ) < 16 ) {
			return null;
		}
		$sidecar = self::sidecar_path( $token );
		if ( is_wp_error( $sidecar ) || ! file_exists( $sidecar ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$meta = json_decode( (string) file_get_contents( $sidecar ), true );
		if ( ! is_array( $meta ) || empty( $meta['ext'] ) ) {
			return null;
		}
		// Session bind: the token alone is unguessable, but we still require the
		// caller to present the owning session id (defence in depth).
		if ( (string) $session_id !== (string) ( $meta['session_id'] ?? '' ) ) {
			return null;
		}
		$path = self::stored_file_path( $token, $meta['ext'] );
		if ( is_wp_error( $path ) || ! file_exists( $path ) ) {
			return null;
		}
		return array(
			'meta' => $meta,
			'path' => $path,
		);
	}

	// AI awareness (light, fail-open).

	/**
	 * Best-effort text extraction from a stored document for AI awareness, and
	 * stash it as a per-session "pending upload" the chat handler injects on the
	 * next AI turn. Plain-text files are read directly; rich docs go through the
	 * same LlamaParse path the KB uses (via the token URL). Images and any
	 * failure fall back to a filename note. Never throws.
	 *
	 * @param array  $stored     Return value of validate_and_store().
	 * @param string $session_id Session id.
	 * @return void
	 */
	public static function prepare_ai_awareness( array $stored, $session_id ) {
		$ext     = isset( $stored['ext'] ) ? (string) $stored['ext'] : '';
		$name    = isset( $stored['name'] ) ? (string) $stored['name'] : 'file';
		$is_img  = ! empty( $stored['is_image'] );
		$url     = isset( $stored['url'] ) ? (string) $stored['url'] : '';
		$extract = '';

		try {
			if ( $is_img ) {
				$extract = ''; // No OCR; the note alone tells the model an image was shared.
			} elseif ( in_array( $ext, array( 'txt', 'text', 'md', 'markdown', 'csv' ), true ) ) {
				$resolved = self::resolve( $stored['token'], $session_id );
				if ( $resolved ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions
					$raw     = (string) file_get_contents( $resolved['path'] );
					$extract = mb_substr( $raw, 0, self::EXTRACT_CHARS );
				}
			} elseif ( class_exists( 'WP_AI_Workflows_Parser' )
				&& '' !== (string) WP_AI_Workflows_Utilities::get_llamaparse_api_key() ) {
				$parsed = WP_AI_Workflows_Parser::parse_document_with_llamaparse(
					$url,
					array(
						'language'            => 'en',
						'parsingInstructions' => '',
						'skipDiagonalText'    => false,
						'doNotUnrollColumns'  => false,
						'targetPages'         => '',
					)
				);
				if ( ! is_wp_error( $parsed ) ) {
					$extract = mb_substr( (string) $parsed, 0, self::EXTRACT_CHARS );
				}
			}
		} catch ( Exception $e ) {
			$extract = ''; // Fail-open: an upload must never break the chat.
		}

		set_transient(
			self::pending_key( $session_id ),
			array(
				'name'     => $name,
				'ext'      => $ext,
				'is_image' => $is_img,
				'url'      => $url,
				'extract'  => $extract,
			),
			30 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Consume the pending upload note for a session (delivered once to the AI).
	 *
	 * @param string $session_id Session id.
	 * @return array{name:string,ext:string,is_image:bool,url:string,extract:string}|null
	 */
	public static function consume_pending( $session_id ) {
		$key     = self::pending_key( $session_id );
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) ) {
			return null;
		}
		delete_transient( $key );
		return $pending;
	}

	/**
	 * @param string $session_id Session id.
	 * @return string Transient key.
	 */
	private static function pending_key( $session_id ) {
		return 'wpaiw_chat_upl_pending_' . md5( (string) $session_id );
	}

	// Rate limiting (public visitor route).

	/**
	 * Enforce a per-IP hourly upload budget. Returns true when the caller is
	 * within budget (and records the attempt), false when over.
	 *
	 * @return bool
	 */
	public static function check_rate_limit() {
		$ip    = self::client_ip();
		$key   = 'wpaiw_chat_upl_ip_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_HOUR ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Best-effort client IP for rate limiting.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$remote = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		if ( $remote ) {
			return (string) $remote;
		}
		$fwd = filter_input( INPUT_SERVER, 'HTTP_X_FORWARDED_FOR', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( $fwd ) {
			$parts = explode( ',', (string) $fwd );
			$first = filter_var( trim( $parts[0] ), FILTER_VALIDATE_IP );
			if ( $first ) {
				return (string) $first;
			}
		}
		return '0.0.0.0';
	}
}
