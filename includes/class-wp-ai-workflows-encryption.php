<?php
/**
 * At-rest encryption for sensitive plugin data (AI provider API keys, OAuth
 * secrets/tokens, HTTP-node credentials).
 *
 * Threat model: protect secrets stored in the WordPress options table so a DB
 * dump alone does not expose them. The encryption key is anchored to the site's
 * SECURE_AUTH_KEY salt (never stored in the DB), or - on a misconfigured host
 * where that salt is absent - to a random key stored in an autoloaded option
 * (best anchor available on such a host; strictly better than a hard-coded
 * literal that would be identical across every install).
 *
 * FORMAT / VERSIONING
 * -------------------
 * New writes use AES-256-GCM (authenticated encryption) and are tagged with a
 * literal "waf2:" prefix, e.g.  waf2:<base64( iv[12] . tag[16] . ciphertext )>.
 * The prefix contains ':' which is NOT in the base64 alphabet, so it can never
 * collide with a legacy value (legacy values are pure base64). That gives the
 * decryptor an unambiguous, self-describing way to dispatch by format - the
 * reason GCM-with-versioning is safe to adopt here rather than staying on
 * CBC+HMAC.
 *
 * BACK-COMPAT
 * -----------
 * decrypt() still understands every historical format so no customer data is
 * orphaned:
 *   1. waf2: ................. modern AES-256-GCM (this class, new writes)
 *   2. AES-256-CBC ........... legacy, keyed on SECURE_AUTH_KEY
 *   3. AES-256-CBC ........... legacy, keyed on the old literal 'fallback-key'
 *   4. plain base64 .......... legacy no-OpenSSL path (no real encryption)
 * When a legacy path succeeds, last_decrypt_used_legacy() returns true so the
 * retrieval layer can transparently re-encrypt with the modern path
 * (self-healing migration).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Encryption {

	/**
	 * Version prefix for the modern authenticated format. Contains ':' so it can
	 * never appear at the start of a legacy (pure-base64) value.
	 */
	const FORMAT_PREFIX = 'waf2:';

	/** Cipher used for new writes. */
	const CIPHER = 'aes-256-gcm';

	/** Autoloaded option that anchors encryption when SECURE_AUTH_KEY is absent. */
	const KEY_OPTION = 'wp_ai_workflows_encryption_key';

	/** Flag option set when OpenSSL is missing so an admin notice can be shown. */
	const UNAVAILABLE_OPTION = 'wp_ai_workflows_encryption_unavailable';

	/**
	 * Whether the most recent decrypt() succeeded via a legacy code path. Read by
	 * the retrieval layer to decide whether to re-encrypt (self-heal).
	 *
	 * @var bool
	 */
	private static $last_decrypt_used_legacy = false;

	/**
	 * Cached raw key material (SECURE_AUTH_KEY or the random anchor).
	 *
	 * @var string|null
	 */
	private static $key_material = null;

	/**
	 * Kept for API compatibility. Surfaces an admin notice if OpenSSL is missing.
	 */
	public static function init() {
		if ( is_admin() && get_option( self::UNAVAILABLE_OPTION ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_openssl_notice' ) );
		}
	}

	/**
	 * Did the last decrypt() call fall back to a legacy format? Used to trigger
	 * opportunistic re-encryption at the storage layer.
	 *
	 * @return bool
	 */
	public static function last_decrypt_used_legacy() {
		return self::$last_decrypt_used_legacy;
	}

	/**
	 * Encrypt a value for at-rest storage using AES-256-GCM (authenticated).
	 *
	 * Never emits plain base64: if OpenSSL is unavailable it fails loudly with a
	 * WP_Error and an admin notice rather than silently storing a reversible
	 * secret. OpenSSL is bundled with PHP 8+, so this path is effectively dead on
	 * supported hosts.
	 *
	 * @param string $data Plaintext.
	 * @return string|WP_Error Encrypted "waf2:..." string, or WP_Error on failure.
	 */
	public static function encrypt( $data ) {
		if ( ! extension_loaded( 'openssl' ) ) {
			update_option( self::UNAVAILABLE_OPTION, true, true );
			WP_AI_Workflows_Utilities::debug_log( 'Encryption unavailable: OpenSSL extension not loaded; refusing to store secret as plain base64.' );
			return new WP_Error(
				'encryption_unavailable',
				'OpenSSL is required to securely store API keys and credentials. Please enable the PHP OpenSSL extension.'
			);
		}

		// Non-string / null input: preserve prior forgiving behaviour on empties.
		$data = (string) $data;

		$key       = self::derive_key( self::get_key_material( true ) );
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv        = openssl_random_pseudo_bytes( $iv_length );
		$tag       = '';

		$ciphertext = openssl_encrypt( $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			WP_AI_Workflows_Utilities::debug_log( 'openssl_encrypt failed for AES-256-GCM.' );
			return new WP_Error( 'encryption_failed', 'Failed to encrypt data.' );
		}

		return self::FORMAT_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value written by any historical version of this class.
	 *
	 * @param string $data Stored value.
	 * @return string|false Plaintext, or false if nothing could decrypt it.
	 */
	public static function decrypt( $data ) {
		self::$last_decrypt_used_legacy = false;

		if ( ! is_string( $data ) || '' === $data ) {
			return false;
		}

		// 1. Modern authenticated format.
		if ( 0 === strpos( $data, self::FORMAT_PREFIX ) ) {
			return self::decrypt_gcm( substr( $data, strlen( self::FORMAT_PREFIX ) ) );
		}

		// Without OpenSSL only the historic plain-base64 path is meaningful.
		if ( ! extension_loaded( 'openssl' ) ) {
			self::$last_decrypt_used_legacy = true;
			return base64_decode( $data );
		}

		// 2 & 3. Legacy AES-256-CBC, trying each historical key candidate.
		$decoded = base64_decode( $data, true );
		if ( false !== $decoded ) {
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			if ( strlen( $decoded ) > $iv_length ) {
				$iv         = substr( $decoded, 0, $iv_length );
				$ciphertext = substr( $decoded, $iv_length );
				foreach ( self::legacy_cbc_key_candidates() as $candidate ) {
					$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $candidate, 0, $iv );
					if ( false !== $plain ) {
						self::$last_decrypt_used_legacy = true;
						return $plain;
					}
				}
			}
		}

		// 4. Plain base64 (legacy no-OpenSSL writes). Only accept a clean
		// round-trip so we don't mistake undecryptable ciphertext for plaintext.
		$strict = base64_decode( $data, true );
		if ( false !== $strict && base64_encode( $strict ) === $data ) {
			self::$last_decrypt_used_legacy = true;
			return $strict;
		}

		return false;
	}

	/**
	 * Decrypt the modern AES-256-GCM payload (already stripped of its prefix).
	 *
	 * @param string $payload base64( iv . tag . ciphertext ).
	 * @return string|false
	 */
	private static function decrypt_gcm( $payload ) {
		if ( ! extension_loaded( 'openssl' ) ) {
			return false;
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw ) {
			return false;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$tag_length = 16;
		if ( strlen( $raw ) <= $iv_length + $tag_length ) {
			return false;
		}

		$iv         = substr( $raw, 0, $iv_length );
		$tag        = substr( $raw, $iv_length, $tag_length );
		$ciphertext = substr( $raw, $iv_length + $tag_length );

		$key = self::derive_key( self::get_key_material( false ) );
		if ( '' === $key ) {
			return false;
		}

		$plain = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? false : $plain;
	}

	/**
	 * Derive a 32-byte key from raw key material for AES-256-GCM.
	 *
	 * @param string $material Raw key material.
	 * @return string 32 raw bytes, or '' if no material available.
	 */
	private static function derive_key( $material ) {
		if ( '' === (string) $material ) {
			return '';
		}
		return hash( 'sha256', $material, true );
	}

	/**
	 * Resolve the raw key material used for modern (GCM) reads/writes.
	 *
	 * Prefers SECURE_AUTH_KEY. On a host where that salt is undefined/empty,
	 * falls back to a random per-site key stored in an autoloaded option. The
	 * anchor is only created when $create is true (i.e. during encryption) so a
	 * plain decrypt never mutates state.
	 *
	 * @param bool $create Create the anchor option if it does not yet exist.
	 * @return string Raw key material ('' only when no anchor exists and !$create).
	 */
	private static function get_key_material( $create ) {
		if ( defined( 'SECURE_AUTH_KEY' ) && '' !== (string) SECURE_AUTH_KEY ) {
			return SECURE_AUTH_KEY;
		}

		if ( null !== self::$key_material ) {
			return self::$key_material;
		}

		$anchor = get_option( self::KEY_OPTION );
		if ( empty( $anchor ) && $create ) {
			$anchor = self::generate_anchor_key();
			// Autoloaded: available on every request without an extra query.
			add_option( self::KEY_OPTION, $anchor, '', 'yes' );
		}

		self::$key_material = is_string( $anchor ) ? $anchor : '';
		return self::$key_material;
	}

	/**
	 * Generate a strong random anchor key (used only when SECURE_AUTH_KEY absent).
	 *
	 * @return string
	 */
	private static function generate_anchor_key() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return base64_encode( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				// Fall through to OpenSSL source below.
			}
		}
		return base64_encode( openssl_random_pseudo_bytes( 32 ) );
	}

	/**
	 * Historical key candidates for legacy AES-256-CBC values, in the order they
	 * were used. openssl_decrypt() was fed the raw string directly, so we must
	 * replay the exact same material.
	 *
	 * @return string[]
	 */
	private static function legacy_cbc_key_candidates() {
		$candidates = array();
		if ( defined( 'SECURE_AUTH_KEY' ) && '' !== (string) SECURE_AUTH_KEY ) {
			$candidates[] = SECURE_AUTH_KEY;
		}
		// The old literal fallback - kept ONLY for decrypting pre-existing data.
		$candidates[] = 'fallback-key';
		// A random anchor could only ever have keyed modern (GCM) data, so it is
		// intentionally not a CBC candidate.
		return $candidates;
	}

	/**
	 * Admin notice shown when OpenSSL is unavailable and a secret could not be
	 * encrypted.
	 */
	public static function render_openssl_notice() {
		echo '<div class="notice notice-error"><p><strong>AI Workflow Automation:</strong> '
			. esc_html__( 'The PHP OpenSSL extension is not available, so API keys and credentials cannot be stored securely. Please enable OpenSSL to save sensitive settings.', 'ai-workflow-automation-lite' )
			. '</p></div>';
	}
}
