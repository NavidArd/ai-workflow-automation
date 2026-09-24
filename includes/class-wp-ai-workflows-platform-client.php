<?php
/**
 * WP_AI_Workflows_Platform_Client - single owner of all HTTP communication with
 * the credits/cloud platform. Browser JS never talks to the platform directly;
 * calls go through plugin REST, keeping the site key and user JWT server-side
 * only. Secrets are never logged (see redact()), returned by REST, or
 * serialized into workflow JSON.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Platform_Client {

	/** Option: encrypted `wpaw_` site API key (waf2 format). */
	const OPTION_API_KEY = 'wpaw_platform_api_key';

	/** Option: non-secret connection metadata (safe to echo in UI/REST). */
	const OPTION_CONNECTION = 'wpaw_platform_connection';

	/**
	 * LEGACY: site-wide transient that once held the encrypted account JWT (replaced
	 * by per-WP-user meta USERMETA_JWT). Kept only to delete stale data from older
	 * builds.
	 */
	const TRANSIENT_JWT = 'wpaw_platform_jwt';

	/**
	 * Per-WP-user meta holding the management session (encrypted JWT, its real exp and
	 * iat, and the account email), bound to the WP user who authenticated. Runtime
	 * execution and credits use the persistent site-scoped `wpaw_` key instead.
	 */
	const USERMETA_JWT = 'wpaw_platform_mgmt_jwt';

	/** Transient: cached credits status, TTL 60s. */
	const TRANSIENT_CREDITS = 'wpaw_credits_cache';

	/** Transient: set when the backend has no refresh route, so we stop asking. */
	const TRANSIENT_NO_REFRESH = 'wpaw_mgmt_no_refresh';

	/**
	 * Per-WP-user transient caching a positive white-label management gate decision
	 * briefly, to avoid a round-trip on every settings read. Never caches a denial.
	 */
	const TRANSIENT_WL_GATE = 'wpaw_wl_gate';

	/** Token age at which a still-valid session is slid forward on next use. */
	const MGMT_REFRESH_AFTER = DAY_IN_SECONDS;

	/** Maximum token age accepted for actions that change site access. */
	const MGMT_STEPUP_MAX_AGE = 900;

	/** Lifetime assumed when a token carries no usable exp claim. */
	const MGMT_FALLBACK_TTL = HOUR_IN_SECONDS;

	const CREDITS_TTL = 60;

	/**
	 * The single canonical option the 1.x plugin stored the old Software License
	 * Manager (SLM) key in (see WP_AI_Workflows_License::save_license_data). On the
	 * v2.0 relaunch we auto-redeem this stored key when the site connects, so an
	 * existing customer gets their credits/plan without re-typing anything.
	 */
	const OPTION_LEGACY_KEY = 'wp_ai_workflows_license_key';

	/**
	 * Local record of the automatic legacy-key redemption so we only auto-attempt
	 * ONCE (idempotency across reconnects), plus the celebratory welcome payload the
	 * account UI reveals. Shape:
	 *   { state:'pending_attempt', queuedAt }                 - queued, not yet run
	 *   { state:'done', mechanism, tier, granted,             - a grant happened
	 *     monthlyCredits, endsAt, attemptedAt }
	 *   { state:'exhausted', outcome:'invalid'|'already'|     - a definitive non-grant
	 *     'not_redeemable', attemptedAt }                       (404/409/422; stay quiet)
	 * A DEFINITIVE state (done|exhausted) is never re-attempted. A transient failure
	 * (503/429/401/network) leaves the record at pending_attempt so a later connect or
	 * status poll retries. This option deliberately SURVIVES a disconnect so a
	 * reconnect never re-redeems the same key.
	 */
	const OPTION_LEGACY_REDEEM = 'wpaw_legacy_redeem_result';

	/** One-time "welcome back" admin notice de-dupe flag (set once when shown). */
	const OPTION_LEGACY_NOTICE = 'wpaw_legacy_welcome_notice_shown';

	/** Fire-and-forget cron hook that runs the queued legacy auto-redeem off-request. */
	const CRON_AUTO_REDEEM = 'wpaw_auto_redeem_legacy';

	/** Short mutex so an inline status-poll run and the cron run never double-redeem. */
	const LOCK_AUTO_REDEEM = 'wpaw_auto_redeem_lock';

	/**
	 * Register hooks. Called once from run_wp_ai_workflows(). Kept minimal - the
	 * headless cloud-execution poller (Wave 2) registers its self-rescheduling event
	 * here.
	 */
	public static function init() {
		// Fire-and-forget worker for automatic legacy-license redemption; runs on
		// WP-cron so it never blocks connecting.
		add_action( self::CRON_AUTO_REDEEM, array( __CLASS__, 'run_legacy_auto_redeem' ) );
	}

	/* ---------------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------------- */

	/**
	 * The single accessor for the platform base URL. No other file may hard-code it.
	 *
	 * @return string Base URL with no trailing slash, e.g. https://api.wpaiworkflowautomation.com
	 */
	public static function base_url() {
		$url = defined( 'WPAW_PLATFORM_URL' ) ? WPAW_PLATFORM_URL : 'https://api.wpaiworkflowautomation.com';
		return untrailingslashit( $url );
	}

	/**
	 * The platform "Continue with Google" entry point; opened in a popup, backend
	 * returns the JWT via window.opener.postMessage (see adopt_session()). Public,
	 * non-secret URL.
	 *
	 * @return string Absolute URL of the platform's Google sign-in entry point.
	 */
	public static function google_auth_url() {
		return self::base_url() . '/api/v1/auth/google?remember=1';
	}

	/* ---------------------------------------------------------------------------
	 * Connection state (R1.3, R1.4, R1.8)
	 * ------------------------------------------------------------------------- */

	/**
	 * Is this site connected to a platform account?
	 *
	 * @return bool True iff an encrypted `wpaw_` key AND siteId metadata are present.
	 */
	public static function is_connected() {
		$meta = get_option( self::OPTION_CONNECTION, array() );
		$key  = get_option( self::OPTION_API_KEY, '' );
		return ! empty( $key ) && is_array( $meta ) && ! empty( $meta['siteId'] );
	}

	/**
	 * Non-secret connection metadata for display. NEVER returns the key or JWT.
	 *
	 * @return array{siteId?:string,keyPrefix?:string,keyLast4?:string,scopes?:array,orgId?:string,orgName?:string,connectedAt?:string}
	 */
	public static function get_connection_meta() {
		$meta = get_option( self::OPTION_CONNECTION, array() );
		if ( ! is_array( $meta ) ) {
			return array();
		}
		// Belt-and-braces: never leak a secret even if one were ever stored here.
		unset( $meta['apiKey'], $meta['key'], $meta['token'] );
		return $meta;
	}

	/**
	 * Build the optional bearer header the updater attaches for download
	 * entitlement (R6.3). Returns '' when disconnected or on decrypt failure - the
	 * update check must never depend on this. The key never leaves the server.
	 *
	 * @return string 'Bearer wpaw_…' or '' .
	 */
	public static function get_update_authorization_header() {
		if ( ! self::is_connected() ) {
			return '';
		}
		$key = self::get_key();
		if ( is_wp_error( $key ) || '' === (string) $key ) {
			return '';
		}
		return 'Bearer ' . $key;
	}

	/**
	 * Derive the cloud→WP callback secret. Both sides derive the same secret
	 * independently from the site key, so it's never exchanged over the wire.
	 * Rotating/revoking the site key rotates/kills this secret automatically.
	 *
	 * @return string|WP_Error Hex secret, or a WP_Error when disconnected / undecryptable.
	 */
	public static function get_callback_secret() {
		$key = self::get_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( '' === (string) $key ) {
			return new WP_Error( 'platform_auth', 'This site is not connected to a platform account.', array( 'status' => 401 ) );
		}
		return hash_hmac( 'sha256', 'wpaw-callback-v1', hash( 'sha256', (string) $key ) );
	}

	/**
	 * The keyPrefix of the currently-stored `wpaw_` key (non-secret), used to match
	 * the `X-WPAW-Key-Prefix` header on inbound callbacks. '' when disconnected.
	 *
	 * @return string
	 */
	public static function get_key_prefix() {
		$meta = get_option( self::OPTION_CONNECTION, array() );
		return isset( $meta['keyPrefix'] ) ? (string) $meta['keyPrefix'] : '';
	}

	/**
	 * Whether a host is OBVIOUSLY unreachable from the public cloud: loopback
	 * names, reserved dev TLDs, private/loopback/link-local IP literals, or a
	 * bare hostname with no TLD. Used by the cloud-callback pre-flight so a
	 * workflow with WordPress-action nodes (post/save callbacks) fails at SUBMIT
	 * time with a clear message instead of minutes later with a socket error.
	 *
	 * Conservative on purpose: anything not provably local passes (a public URL
	 * that happens to be firewalled still fails fast cloud-side - the engine now
	 * treats refused/unresolvable callbacks as non-retryable).
	 *
	 * @param string $host Hostname (no scheme/path). IPv6 may be bracketed.
	 * @return bool True when the cloud can obviously never reach this host.
	 */
	public static function is_host_cloud_unreachable( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return true;
		}
		$bare = trim( $host, '[]' ); // IPv6 literals arrive bracketed in URLs.

		if ( 'localhost' === $bare ) {
			return true;
		}

		// Reserved / mDNS / dev-only TLDs that public DNS never resolves.
		foreach ( array( '.localhost', '.local', '.test', '.internal', '.example', '.invalid', '.home.arpa' ) as $suffix ) {
			$len = strlen( $suffix );
			if ( strlen( $bare ) > $len && substr( $bare, -$len ) === $suffix ) {
				return true;
			}
		}

		// IP literals: only publicly routable addresses are reachable. This rejects
		// loopback (127/8, ::1), private (10/8, 172.16/12, 192.168/16, fc00::/7)
		// and link-local/reserved (169.254/16, fe80::/10, 0.0.0.0) ranges.
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		// A hostname with no dot has no TLD ("mysite", "wordpress") - LAN-only.
		if ( false === strpos( $bare, '.' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Cloud-callback pre-flight: if THIS site's URL is obviously unreachable from
	 * the cloud, return the blocking WP_Error a wpAction-carrying submit should
	 * fail with; null when the submit may proceed.
	 *
	 * Escape hatch for tunneled dev setups (ngrok/Cloudflared etc. where the
	 * public callback host differs from home_url()):
	 *   add_filter( 'wp_ai_workflows_skip_callback_preflight', '__return_true' );
	 *
	 * @return WP_Error|null
	 */
	public static function callback_preflight_error() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		if ( ! self::is_host_cloud_unreachable( $host ) ) {
			return null;
		}

		/**
		 * Skip the cloud-callback reachability pre-flight (tunneled dev setups).
		 *
		 * @param bool   $skip Default false.
		 * @param string $host The home_url() host that failed the check.
		 */
		if ( apply_filters( 'wp_ai_workflows_skip_callback_preflight', false, (string) $host ) ) {
			return null;
		}

		$display = (string) $host . ( $port ? ':' . (int) $port : '' );
		return new WP_Error(
			'cloud_callback_unreachable',
			sprintf(
				"Your WordPress site (%s) isn't reachable from the cloud. Workflows with WordPress-action nodes (like Post) need a publicly accessible site URL; run this workflow in Local mode instead.",
				$display
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Purge all local connection state. Called on explicit disconnect and on a
	 * definitive 401 (R7.3). Safe to call when already disconnected.
	 *
	 * @param string $reason Machine reason, for the (redacted) debug log only.
	 */
	public static function mark_disconnected( $reason = 'manual' ) {
		delete_option( self::OPTION_API_KEY );
		delete_option( self::OPTION_CONNECTION );
		// Site key is gone - drop every WP user's management JWT (cross-user purge)
		// plus any legacy transient.
		delete_metadata( 'user', 0, self::USERMETA_JWT, '', true );
		delete_transient( self::TRANSIENT_JWT );
		delete_transient( self::TRANSIENT_CREDITS );
		WP_AI_Workflows_Utilities::debug_log( 'Platform disconnected: ' . sanitize_key( $reason ), 'info' );
	}

	/* ---------------------------------------------------------------------------
	 * Credential storage (encrypted site key + metadata; per-user management JWT) - task 1.2
	 * ------------------------------------------------------------------------- */

	/**
	 * Encrypt and persist the `wpaw_` site key. Refuses to store plaintext: if
	 * encryption fails (no OpenSSL) the WP_Error propagates and the connect flow
	 * fails loudly (R1.8).
	 *
	 * @param string $api_key Plaintext `wpaw_…` key (shown once by the backend).
	 * @return true|WP_Error
	 */
	private static function store_key( $api_key ) {
		$encrypted = WP_AI_Workflows_Encryption::encrypt( (string) $api_key );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		update_option( self::OPTION_API_KEY, $encrypted, false );
		return true;
	}

	/**
	 * Decrypt and return the stored `wpaw_` key for outbound runtime calls.
	 *
	 * @return string|WP_Error
	 */
	private static function get_key() {
		$stored = get_option( self::OPTION_API_KEY, '' );
		if ( empty( $stored ) ) {
			return new WP_Error( 'platform_auth', 'This site is not connected to a platform account.', array( 'status' => 401 ) );
		}
		$plain = WP_AI_Workflows_Encryption::decrypt( $stored );
		if ( false === $plain || '' === $plain ) {
			return new WP_Error( 'platform_auth', 'Stored platform key could not be decrypted; please reconnect.', array( 'status' => 401 ) );
		}
		return $plain;
	}

	/**
	 * Persist non-secret connection metadata.
	 *
	 * @param array $meta {siteId, keyPrefix, keyLast4, scopes, orgId, orgName, connectedAt}.
	 */
	private static function store_connection( array $meta ) {
		// Strip anything secret before persisting to the (readable) metadata option.
		unset( $meta['apiKey'], $meta['key'], $meta['token'] );
		update_option( self::OPTION_CONNECTION, $meta, false );
	}

	/**
	 * Read the unverified claims out of a JWT payload segment. Used only to learn the
	 * token's own lifetime and account email for local bookkeeping; the platform is
	 * what actually validates the token.
	 *
	 * @param string $token Raw JWT.
	 * @return array Decoded claims, or an empty array when unreadable.
	 */
	private static function decode_jwt_claims( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) ) {
			return array();
		}
		$payload = strtr( $parts[1], '-_', '+/' );
		$remain  = strlen( $payload ) % 4;
		if ( $remain > 0 ) {
			$payload .= str_repeat( '=', 4 - $remain );
		}
		$json = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payload segment, not obfuscation.
		if ( false === $json ) {
			return array();
		}
		$claims = json_decode( $json, true );
		return is_array( $claims ) ? $claims : array();
	}

	/**
	 * Encrypt and store the platform JWT as a per-WP-user management session (not a
	 * site-wide transient), bound to the WP user who authenticated. The stored expiry
	 * and issue time come from the token itself, never from a caller-supplied value.
	 * With no current WP user there is nowhere identity-bound to store it, so we
	 * refuse (the caller then re-prompts login).
	 *
	 * @param string $token Raw JWT.
	 * @return void
	 */
	private static function store_jwt( $token ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			WP_AI_Workflows_Utilities::debug_log( 'Refusing to store platform JWT with no current WP user.', 'warning' );
			return;
		}
		$encrypted = WP_AI_Workflows_Encryption::encrypt( (string) $token );
		if ( is_wp_error( $encrypted ) ) {
			// Non-fatal: lifecycle calls will re-prompt login if the JWT is missing.
			WP_AI_Workflows_Utilities::debug_log( 'Failed to encrypt platform JWT for storage.', 'warning' );
			return;
		}

		$claims  = self::decode_jwt_claims( $token );
		$now     = time();
		$issued  = isset( $claims['iat'] ) ? (int) $claims['iat'] : 0;
		$expires = isset( $claims['exp'] ) ? (int) $claims['exp'] : 0;
		if ( $issued <= 0 || $issued > $now ) {
			$issued = $now;
		}
		if ( $expires <= $now ) {
			$expires = $now + self::MGMT_FALLBACK_TTL;
		}

		update_user_meta(
			$user_id,
			self::USERMETA_JWT,
			array(
				'token'   => $encrypted,
				'expires' => $expires,
				'issued'  => $issued,
				'email'   => isset( $claims['email'] ) ? sanitize_email( (string) $claims['email'] ) : '',
			)
		);
		// Belt-and-braces: purge any legacy site-wide JWT transient from older builds.
		delete_transient( self::TRANSIENT_JWT );
	}

	/**
	 * The current WP user's stored session record, or an empty array.
	 *
	 * @return array
	 */
	private static function get_session_record() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$stored = get_user_meta( $user_id, self::USERMETA_JWT, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Seconds since the current session token was issued, or null when there is no
	 * session to measure.
	 *
	 * @return int|null
	 */
	public static function get_session_age() {
		$stored = self::get_session_record();
		if ( empty( $stored['issued'] ) ) {
			return null;
		}
		return max( 0, time() - (int) $stored['issued'] );
	}

	/**
	 * The account email the current session belongs to, for display. Empty when
	 * unknown (a session stored by an older build carries no email).
	 *
	 * @return string
	 */
	public static function get_session_email() {
		$stored = self::get_session_record();
		return isset( $stored['email'] ) ? sanitize_email( (string) $stored['email'] ) : '';
	}

	/**
	 * Decrypt and return the CURRENT WP user's management JWT, or a typed error when
	 * missing/expired so the UI re-prompts login.
	 *
	 * @return string|WP_Error
	 */
	private static function get_jwt() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'platform_auth_jwt', 'Please sign in to manage your account.', array( 'status' => 401 ) );
		}
		$stored = get_user_meta( $user_id, self::USERMETA_JWT, true );
		if ( ! is_array( $stored ) || empty( $stored['token'] ) ) {
			return new WP_Error( 'platform_auth_jwt', 'Your session ended. Sign in again to manage your account.', array( 'status' => 401 ) );
		}
		if ( empty( $stored['expires'] ) || time() >= (int) $stored['expires'] ) {
			delete_user_meta( $user_id, self::USERMETA_JWT );
			return new WP_Error( 'platform_auth_jwt', 'Your session ended. Sign in again to manage your account.', array( 'status' => 401 ) );
		}
		$plain = WP_AI_Workflows_Encryption::decrypt( $stored['token'] );
		if ( false === $plain || '' === $plain ) {
			delete_user_meta( $user_id, self::USERMETA_JWT );
			return new WP_Error( 'platform_auth_jwt', 'Your session ended. Sign in again to manage your account.', array( 'status' => 401 ) );
		}
		return $plain;
	}

	/**
	 * Clear the CURRENT WP user's management session (sign-out of management / on a
	 * 401). Only this user's session is touched; other users' sessions are unrelated.
	 * Also purges any legacy site-wide JWT transient.
	 *
	 * @return void
	 */
	private static function clear_jwt() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::USERMETA_JWT );
			// A management surface may only be managed under a live session - drop this
			// user's cached white-label gate decision along with the session.
			delete_transient( self::TRANSIENT_WL_GATE . '_' . $user_id );
		}
		delete_transient( self::TRANSIENT_JWT );
	}

	/**
	 * Does the CURRENT WP user hold a valid (unexpired) management session? Drives the
	 * UI's "sign in to manage" gate so management surfaces prompt for fresh auth
	 * instead of silently failing. Never contacts the platform.
	 *
	 * @return bool
	 */
	public static function has_management_session() {
		$stored = self::get_session_record();
		return ! empty( $stored['token'] )
			&& ! empty( $stored['expires'] )
			&& time() < (int) $stored['expires'];
	}

	/**
	 * Gate for actions that change which machines can reach the organization:
	 * issuing or revoking a site key, removing a site, disconnecting this site.
	 * Requires a live session that was authenticated recently.
	 *
	 * @return true|WP_Error true when the caller may proceed.
	 */
	public static function require_recent_session() {
		$token = self::get_jwt();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$age = self::get_session_age();
		if ( null !== $age && $age <= self::MGMT_STEPUP_MAX_AGE ) {
			return true;
		}

		return new WP_Error(
			'platform_reauth_required',
			'Please confirm it is you. This action changes site access, so it needs a fresh sign-in.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Slide the session forward once its token passes MGMT_REFRESH_AFTER, so an
	 * account in regular use is never signed out. Called after a successful
	 * JWT-authed call; every failure mode leaves the current token in place.
	 *
	 * @return void
	 */
	private static function maybe_refresh_session() {
		static $running = false;
		if ( $running ) {
			return;
		}

		$stored = self::get_session_record();
		if ( empty( $stored['issued'] ) || empty( $stored['token'] ) ) {
			return;
		}
		if ( ( time() - (int) $stored['issued'] ) < self::MGMT_REFRESH_AFTER ) {
			return;
		}
		if ( get_transient( self::TRANSIENT_NO_REFRESH ) ) {
			return;
		}

		$running = true;
		$result  = self::request(
			'POST',
			'/api/v1/auth/refresh',
			array(
				'auth'    => 'jwt',
				'timeout' => 10,
				'body'    => array(),
				'context' => 'refresh_session',
			)
		);
		$running = false;

		if ( is_wp_error( $result ) ) {
			if ( 'platform_auth_jwt' === $result->get_error_code() ) {
				self::clear_jwt();
				return;
			}
			// Keep using the current token until its own expiry; back off from asking.
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			set_transient( self::TRANSIENT_NO_REFRESH, 1, 404 === $status ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
			return;
		}

		$token = isset( $result['data']['token'] ) ? (string) $result['data']['token'] : '';
		if ( '' !== $token ) {
			self::store_jwt( $token );
		}
	}

	/**
	 * End this WP user's management session on this site.
	 *
	 * @return array {signedOut: true}
	 */
	public static function sign_out() {
		$token = self::get_jwt();
		if ( ! is_wp_error( $token ) ) {
			self::request(
				'POST',
				'/api/v1/auth/sign-out',
				array(
					'auth'    => 'jwt',
					'timeout' => 8,
					'body'    => array(),
					'context' => 'sign_out',
				)
			);
		}
		self::clear_jwt();
		return array( 'signedOut' => true );
	}

	/**
	 * Retire every session issued for the platform account, on this site and any
	 * other, then clear the local one.
	 *
	 * @return array|WP_Error {signedOut: true}
	 */
	public static function sign_out_everywhere() {
		$result = self::request(
			'POST',
			'/api/v1/auth/sign-out-everywhere',
			array(
				'auth'    => 'jwt',
				'timeout' => 12,
				'body'    => array(),
				'context' => 'sign_out_everywhere',
			)
		);

		if ( is_wp_error( $result ) && 'platform_auth_jwt' !== $result->get_error_code() ) {
			return $result;
		}

		self::clear_jwt();
		return array( 'signedOut' => true );
	}

	/**
	 * Access gate for viewing/editing the white-label CONFIG (not for rendering
	 * already-saved branding, which is public). Requires: connected site, a live
	 * management session, org OWNER, and Business tier. Positive decisions are
	 * cached per-WP-user for 60s; the fast path still re-checks the live session.
	 *
	 * @return true|WP_Error true when white-labeling may be managed; otherwise a typed
	 *                       WP_Error carrying the platform machine code in
	 *                       `platform_code` and an HTTP `status`.
	 */
	public static function whitelabel_management_gate() {
		$user_id = get_current_user_id();

		// Fast path: a recent positive decision that is still backed by a live session.
		if ( $user_id > 0 && self::has_management_session() ) {
			$cached = get_transient( self::TRANSIENT_WL_GATE . '_' . $user_id );
			if ( true === $cached ) {
				return true;
			}
		}

		$sites = self::get_org_sites();
		if ( is_wp_error( $sites ) ) {
			// platform_disconnected / platform_auth_jwt / platform_org_sites(NOT_OWNER) / …
			return $sites;
		}

		$entitlement = isset( $sites['entitlement'] ) && is_array( $sites['entitlement'] ) ? $sites['entitlement'] : array();
		if ( empty( $entitlement['isBusiness'] ) ) {
			return new WP_Error(
				'platform_org_sites',
				'White-labeling is a Business-plan feature. Upgrade your plan to unlock it.',
				array(
					'status'        => 403,
					'platform_code' => 'UPGRADE_REQUIRED',
				)
			);
		}

		if ( $user_id > 0 ) {
			set_transient( self::TRANSIENT_WL_GATE . '_' . $user_id, true, 60 );
		}
		return true;
	}

	/* ---------------------------------------------------------------------------
	 * Log redaction
	 * ------------------------------------------------------------------------- */

	/**
	 * Strip secret material (`wpaw_` keys, bearer tokens, JWTs) from any value
	 * before it reaches the debug log. ALL platform-related debug_log calls MUST
	 * pass their message through this.
	 *
	 * @param mixed $text String or array/object (json-encoded first).
	 * @return string Redacted text.
	 */
	public static function redact( $text ) {
		if ( ! is_string( $text ) ) {
			$text = wp_json_encode( $text );
		}
		$text = (string) $text;
		$text = preg_replace( '/wpaw_[A-Za-z0-9]+/', 'wpaw_[REDACTED]', $text );
		$text = preg_replace( '/[Bb]earer\s+[A-Za-z0-9._\-]+/', 'Bearer [REDACTED]', $text );
		// Bare JWTs (header.payload.signature - all start with the base64 of {"alg").
		$text = preg_replace( '/eyJ[A-Za-z0-9._\-]+/', '[JWT_REDACTED]', $text );
		return $text;
	}

	/* ---------------------------------------------------------------------------
	 * HTTP core + error taxonomy - task 1.1
	 * ------------------------------------------------------------------------- */

	/**
	 * Perform a platform HTTP request and map the outcome to decoded data or a
	 * typed WP_Error per the design error taxonomy.
	 *
	 * @param string $method GET|POST|DELETE.
	 * @param string $path   Path beginning with '/', appended to base_url().
	 * @param array  $opts   {
	 *     @type array  $body     Request body (json-encoded).
	 *     @type string $auth     'jwt' | 'wpaw' | 'none' (default 'none').
	 *     @type string $org_id   Value for the x-organization-id header.
	 *     @type int    $timeout  Seconds (default 15).
	 *     @type int    $retries  Retry attempts on network/5xx (default 0).
	 *     @type int    $backoff  Base backoff seconds between retries (default 2).
	 *     @type string $context  Short label for logs.
	 * }
	 * @return array|WP_Error Decoded JSON body on 2xx; WP_Error otherwise.
	 */
	private static function request( $method, $path, array $opts = array() ) {
		$auth    = isset( $opts['auth'] ) ? $opts['auth'] : 'none';
		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 15;
		$retries = isset( $opts['retries'] ) ? (int) $opts['retries'] : 0;
		$backoff = isset( $opts['backoff'] ) ? (int) $opts['backoff'] : 2;
		$context = isset( $opts['context'] ) ? (string) $opts['context'] : $path;

		// A site-key (wpaw) 401 / invalid-key response purges the WHOLE connection
		// (deletes the persistent site-key option) ONLY when the caller opts in. That
		// is reserved for genuine, user-initiated runtime execution calls (proxy AI,
		// cloud execute, PDF render) where a 401 is a definitive "this key is revoked"
		// signal. BACKGROUND reads (credits status, legacy-license auto-redeem, PDF
		// template list / preview, execution polling) must NEVER tear down the
		// connection on a transient or non-critical 401 - doing so turned a mere
		// background blip (e.g. the post-connect auto-redeem hitting a 401) into a full
		// "logged out / no credits" state that returned on every screen navigation.
		$disconnect_on_auth_fail = ! empty( $opts['disconnect_on_auth_fail'] );

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( 'jwt' === $auth ) {
			$token = self::get_jwt();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		} elseif ( 'wpaw' === $auth ) {
			$key = self::get_key();
			if ( is_wp_error( $key ) ) {
				return $key;
			}
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		if ( ! empty( $opts['org_id'] ) ) {
			$headers['x-organization-id'] = (string) $opts['org_id'];
		}

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => $timeout,
			'sslverify' => true,
		);
		if ( array_key_exists( 'body', $opts ) && null !== $opts['body'] ) {
			$args['body'] = wp_json_encode( $opts['body'] );
		}

		$url     = self::base_url() . $path;
		$attempt = 0;

		while ( true ) {
			$response = wp_remote_request( $url, $args );

			// Transport-level failure (DNS/timeout/connection reset).
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $retries ) {
					++$attempt;
					sleep( min( $backoff * $attempt, 10 ) );
					continue;
				}
				WP_AI_Workflows_Utilities::debug_log(
					self::redact( 'Platform request failed [' . $context . ']: ' . $response->get_error_message() ),
					'error'
				);
				return new WP_Error( 'platform_unreachable', 'The platform is currently unreachable. Please try again shortly.', array( 'status' => 503 ) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$body = json_decode( $raw, true );

			// Retry idempotent server errors (not 502 - a real upstream provider signal).
			if ( $code >= 500 && 502 !== $code && $attempt < $retries ) {
				++$attempt;
				sleep( min( $backoff * $attempt, 10 ) );
				continue;
			}

			$mapped = self::map_response( $code, $body, $response, $context, $auth, $disconnect_on_auth_fail );
			if ( 'jwt' === $auth && ! is_wp_error( $mapped ) ) {
				self::maybe_refresh_session();
			}
			return $mapped;
		}
	}

	/**
	 * Map an HTTP status + decoded body to decoded data or a typed WP_Error.
	 *
	 * @param int        $code     HTTP status code.
	 * @param mixed      $body     Decoded JSON body (array) or null.
	 * @param array      $response Raw wp_remote_* response (for headers).
	 * @param string     $context  Log label.
	 * @param string     $auth     Auth mode used ('jwt'|'wpaw'|'none').
	 * @param bool       $disconnect_on_auth_fail Whether a wpaw 401 / invalid-key body
	 *                             should purge the connection. True only for genuine
	 *                             runtime execution calls; false for background reads.
	 * @return array|WP_Error
	 */
	private static function map_response( $code, $body, $response, $context, $auth, $disconnect_on_auth_fail = false ) {
		if ( $code >= 200 && $code < 300 ) {
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'platform_invalid_response', 'The platform returned an unexpected response.', array( 'status' => 502 ) );
			}
			return $body;
		}

		$error_code = is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : '';

		switch ( $code ) {
			case 401:
				// A JWT-authed call failing means the human session expired (re-prompt
				// login); a runtime `wpaw_` call failing means the site is disconnected.
				if ( 'jwt' === $auth ) {
					return new WP_Error( 'platform_auth_jwt', 'Your session ended. Sign in again to manage your account.', array( 'status' => 401 ) );
				}
				// Only a definitive runtime-execution 401 purges the connection; a
				// background read's 401 is surfaced as a transient auth error and the
				// persistent site key is left intact (the meter shows 'unavailable',
				// not 'disconnected', and a real revocation is caught at execute time).
				if ( $disconnect_on_auth_fail ) {
					self::mark_disconnected( 'invalid_api_key' );
					return new WP_Error( 'platform_auth', 'This site is no longer authorized. Please reconnect your account.', array( 'status' => 401 ) );
				}
				return new WP_Error( 'platform_auth', 'The platform could not authorize this request. Please try again shortly.', array( 'status' => 401 ) );

			case 402:
				$balance  = is_array( $body ) && isset( $body['balance'] ) ? (float) $body['balance'] : 0;
				$required = is_array( $body ) && isset( $body['required'] ) ? (float) $body['required'] : 0;
				self::invalidate_credits_cache();
				return new WP_Error(
					'platform_credits',
					'You do not have enough credits for this operation.',
					array(
						'status'   => 402,
						'balance'  => $balance,
						'required' => $required,
					)
				);

			case 403:
				// The "rotate your site key" remedy only makes sense for site-key
				// (wpaw) calls. JWT/management calls have no site key yet, so a 403 there
				// is either an unverified email (the common brand-new-signup case) or a
				// plain permissions issue.
				if ( 'wpaw' === $auth ) {
					return new WP_Error( 'platform_scope', 'Your site key lacks permission for this operation. Try rotating the key.', array( 'status' => 403 ) );
				}
				$reason = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '';
				if ( 'EMAIL_NOT_VERIFIED' === $error_code || false !== stripos( $reason, 'email verification required' ) ) {
					return new WP_Error( 'platform_email_unverified', 'Please verify your email address first. Check your inbox for the confirmation link.', array( 'status' => 403 ) );
				}
				return new WP_Error( 'platform_forbidden', 'Your account does not have permission for this operation.', array( 'status' => 403 ) );

			case 409:
				if ( 'SITE_LIMIT_REACHED' === $error_code ) {
					return self::site_limit_error( $body );
				}
				break;

			case 429:
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				return new WP_Error( 'platform_rate_limited', 'The platform is rate limiting requests. Please retry shortly.', array( 'status' => 429, 'retry_after' => $retry_after ) );

			case 502:
				return new WP_Error( 'platform_provider', 'The upstream AI provider failed. You were not charged.', array( 'status' => 502 ) );
		}

		// Any other non-2xx (400 validation, unexpected 5xx, malformed body).
		$message = is_array( $body ) && ! empty( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'The platform returned an error.';
		if ( 'MISSING_API_KEY' === $error_code || 'INVALID_API_KEY' === $error_code ) {
			// Same rule as the 401 case: only a runtime-execution call may purge the
			// connection on an invalid-key body. Background reads stay connected.
			if ( $disconnect_on_auth_fail ) {
				self::mark_disconnected( 'invalid_api_key' );
				return new WP_Error( 'platform_auth', 'This site is no longer authorized. Please reconnect your account.', array( 'status' => 401 ) );
			}
			return new WP_Error( 'platform_auth', 'The platform could not authorize this request. Please try again shortly.', array( 'status' => 401 ) );
		}
		if ( $code >= 500 ) {
			return new WP_Error( 'platform_unreachable', 'The platform is currently unavailable. Please try again shortly.', array( 'status' => 503 ) );
		}
		WP_AI_Workflows_Utilities::debug_log( self::redact( 'Platform error [' . $context . '] HTTP ' . $code . ': ' . $message ), 'error' );
		return new WP_Error( 'platform_error', $message, array( 'status' => $code ) );
	}

	/**
	 * Build the typed error the connect UI turns into the "move or upgrade" dialog.
	 *
	 * @param mixed $body Decoded response body.
	 * @return WP_Error
	 */
	private static function site_limit_error( $body ) {
		$data  = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		$sites = array();

		if ( isset( $data['sites'] ) && is_array( $data['sites'] ) ) {
			foreach ( $data['sites'] as $site ) {
				if ( ! is_array( $site ) || empty( $site['id'] ) ) {
					continue;
				}
				$sites[] = array(
					'id'         => sanitize_text_field( (string) $site['id'] ),
					'name'       => isset( $site['name'] ) ? sanitize_text_field( (string) $site['name'] ) : '',
					'url'        => isset( $site['url'] ) ? esc_url_raw( (string) $site['url'] ) : '',
					'lastPingAt' => isset( $site['lastPingAt'] ) ? sanitize_text_field( (string) $site['lastPingAt'] ) : '',
				);
			}
		}

		$message = is_array( $body ) && ! empty( $body['error'] )
			? sanitize_text_field( (string) $body['error'] )
			: 'Your plan has no free production site left.';

		return new WP_Error(
			'site_limit_reached',
			$message,
			array(
				'status'     => 409,
				'siteLimit'  => isset( $data['siteLimit'] ) ? (int) $data['siteLimit'] : 1,
				'plan'       => isset( $data['plan'] ) ? sanitize_text_field( (string) $data['plan'] ) : '',
				'sites'      => $sites,
				'upgradeUrl' => isset( $data['upgradeUrl'] ) ? esc_url_raw( (string) $data['upgradeUrl'] ) : '',
			)
		);
	}

	/**
	 * Drop the short-lived credits cache (after any metered call / on 402).
	 */
	public static function invalidate_credits_cache() {
		delete_transient( self::TRANSIENT_CREDITS );
	}

	/**
	 * Write the credits balance through the cache from a proxy `balanceAfter`
	 * (R5.6) - keeps the meter fresh without an extra status call.
	 *
	 * @param float $balance_after
	 * @return void
	 */
	public static function cache_balance_after( $balance_after ) {
		$cached = get_transient( self::TRANSIENT_CREDITS );
		if ( ! is_array( $cached ) ) {
			$cached = array();
		}
		$cached['balance']   = (float) $balance_after;
		$cached['available'] = (float) $balance_after - ( isset( $cached['reserved'] ) ? (float) $cached['reserved'] : 0 );
		set_transient( self::TRANSIENT_CREDITS, $cached, self::CREDITS_TTL );
	}

	/* ---------------------------------------------------------------------------
	 * Credits status (wpaw auth, 60s cache) - task 2.4
	 * ------------------------------------------------------------------------- */

	/**
	 * Fetch the credits balance for the meter. 60s transient cache (R2.7); the
	 * platform is contacted at most once per minute unless $force is true.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error {balance, reserved, available, totalPurchased,
	 *                         totalUsed, lowBalanceThreshold, lowBalance, currency}.
	 */
	public static function credits_status( $force = false ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_CREDITS );
			if ( is_array( $cached ) && isset( $cached['balance'] ) ) {
				return $cached;
			}
		}

		$result = self::request(
			'GET',
			'/api/v1/credits/status',
			array(
				'auth'    => 'wpaw',
				'timeout' => 10,
				'retries' => 1,
				'context' => 'credits_status',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( ! isset( $data['balance'] ) && ! isset( $data['available'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Credits status was malformed.', array( 'status' => 502 ) );
		}

		$status = array(
			'balance'             => isset( $data['balance'] ) ? (float) $data['balance'] : 0.0,
			'reserved'            => isset( $data['reserved'] ) ? (float) $data['reserved'] : 0.0,
			'available'           => isset( $data['available'] ) ? (float) $data['available'] : 0.0,
			'totalPurchased'      => isset( $data['totalPurchased'] ) ? (float) $data['totalPurchased'] : 0.0,
			'totalUsed'           => isset( $data['totalUsed'] ) ? (float) $data['totalUsed'] : 0.0,
			'lowBalanceThreshold' => isset( $data['lowBalanceThreshold'] ) ? (float) $data['lowBalanceThreshold'] : 0.0,
			'lowBalance'          => ! empty( $data['lowBalance'] ),
			'currency'            => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : 'credits',
		);

		set_transient( self::TRANSIENT_CREDITS, $status, self::CREDITS_TTL );
		return $status;
	}

	/**
	 * Grant a one-time onboarding-milestone credit bonus. The site sends only the
	 * milestone id; the backend owns amounts and enforces idempotency (a replay
	 * returns alreadyGranted:true). Site-key authed; never disconnects on a 401.
	 *
	 * @param string $milestone One of 'first_workflow' | 'three_workflows' | 'ten_runs' | 'chat_widget'.
	 * @return array{alreadyGranted:bool,milestone:string,creditsAwarded:int,balance:float}|WP_Error
	 */
	public static function grant_onboarding_milestone( $milestone ) {
		$milestone = sanitize_key( (string) $milestone );
		$allowed   = array( 'first_workflow', 'three_workflows', 'ten_runs', 'chat_widget' );
		if ( ! in_array( $milestone, $allowed, true ) ) {
			return new WP_Error( 'invalid_milestone', 'Unknown onboarding milestone.', array( 'status' => 400 ) );
		}
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}

		$result = self::request(
			'POST',
			'/api/v1/credits/onboarding-milestone',
			array(
				'auth'    => 'wpaw',
				'timeout' => 12,
				'retries' => 0,
				'body'    => array( 'milestone' => $milestone ),
				'context' => 'onboarding_milestone',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data    = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$already = ! empty( $data['alreadyGranted'] );
		$awarded = isset( $data['creditsAwarded'] ) ? (int) $data['creditsAwarded'] : 0;
		$balance = isset( $data['balance'] ) ? (float) $data['balance'] : 0.0;

		// A real (first-time) grant changed the balance - drop the cache so the
		// meter refetches fresh. An idempotent replay left the balance untouched.
		if ( ! $already && $awarded > 0 ) {
			self::invalidate_credits_cache();
		}

		return array(
			'alreadyGranted' => $already,
			'milestone'      => isset( $data['milestone'] ) ? sanitize_key( (string) $data['milestone'] ) : $milestone,
			'creditsAwarded' => $awarded,
			'balance'        => $balance,
		);
	}

	/* ---------------------------------------------------------------------------
	 * Usage Center - credits ledger history + daily consumption (wpaw auth)
	 * ------------------------------------------------------------------------- */

	/**
	 * Fetch a page of the credits ledger for the Usage Center's History list.
	 * Site-key authed background read; never disconnects on a 401. Only
	 * whitelisted params are forwarded, sanitized.
	 *
	 * @param int    $limit  Rows to return (server clamps to 100).
	 * @param string $cursor Opaque pagination cursor from a previous nextCursor.
	 * @param string $kind   Optional ledger-kind filter (purchase|subscription_grant|
	 *                       bonus|usage|refund|adjustment|expiry).
	 * @return array|WP_Error Decoded `data` {items, nextCursor, hasMore} on success.
	 */
	public static function credits_history( $limit = 25, $cursor = '', $kind = '' ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}

		// Whitelist + sanitize: only these three params are ever forwarded.
		$query = array( 'limit' => max( 1, min( 100, (int) $limit ) ) );
		if ( '' !== (string) $cursor ) {
			$query['cursor'] = sanitize_text_field( (string) $cursor );
		}
		$allowed_kinds = array( 'purchase', 'subscription_grant', 'bonus', 'usage', 'refund', 'adjustment', 'expiry' );
		$kind          = sanitize_key( (string) $kind );
		if ( '' !== $kind && in_array( $kind, $allowed_kinds, true ) ) {
			$query['kind'] = $kind;
		}

		$result = self::request(
			'GET',
			'/api/v1/credits/history?' . http_build_query( $query ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 12,
				'retries' => 1,
				'context' => 'credits_history',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * Fetch the zero-filled daily consumption series for the Usage Center's
	 * consumption chart. Site-key authed background read; never disconnects on 401.
	 *
	 * @param int $days Trailing window in days (server caps at 90).
	 * @return array|WP_Error Decoded `data` {days:[{date,spent,runs}], totalSpent, totalRuns}.
	 */
	public static function usage_daily( $days = 30 ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}

		$days  = max( 1, min( 90, (int) $days ) );
		$result = self::request(
			'GET',
			'/api/v1/credits/usage-daily?' . http_build_query( array( 'days' => $days ) ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 12,
				'retries' => 1,
				'context' => 'usage_daily',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/* ---------------------------------------------------------------------------
	 * Apps / Pipedream Connect (wpaw auth) - thin server-side proxies onto the
	 * platform's /api/v1/mcp/* surface for the "Apps / Connect an App" node.
	 * Builder-time only; none are metered or may disconnect on a transient 401.
	 * ------------------------------------------------------------------------- */

	/**
	 * Search the Pipedream app registry (full 2,700+ catalogue).
	 *
	 * @param string $query Free-text app search (empty = featured list).
	 * @param int    $limit Rows to return (server clamps 1..100).
	 * @return array|WP_Error Decoded platform body { success, data:[apps], total }.
	 */
	public static function apps_search( $query = '', $limit = 60 ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$params = array( 'limit' => max( 1, min( 100, (int) $limit ) ) );
		if ( '' !== (string) $query ) {
			$params['q'] = sanitize_text_field( (string) $query );
		}
		return self::request(
			'GET',
			'/api/v1/mcp/apps?' . http_build_query( $params ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 15,
				'retries' => 1,
				'context' => 'apps_search',
			)
		);
	}

	/**
	 * List an app's available actions (tools) with their dynamic input schemas.
	 *
	 * @param string $app_slug Pipedream app key/slug.
	 * @return array|WP_Error Decoded platform body { success, data:[tools] }.
	 */
	public static function apps_tools( $app_slug ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', 'An app must be selected.', array( 'status' => 400 ) );
		}
		return self::request(
			'GET',
			'/api/v1/mcp/apps/' . rawurlencode( $slug ) . '/tools',
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'retries' => 1,
				'context' => 'apps_tools',
			)
		);
	}

	/**
	 * Mint a single-use Pipedream Connect token for the site's org and return the
	 * hosted Connect Link URL (pre-scoped to this app) the browser opens for OAuth.
	 *
	 * @param string $app_slug Pipedream app key/slug to connect.
	 * @return array|WP_Error Decoded platform body { success, authUrl, token, expiresAt }.
	 */
	public static function apps_connect( $app_slug ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', 'An app must be selected.', array( 'status' => 400 ) );
		}
		return self::request(
			'POST',
			'/api/v1/mcp/auth/' . rawurlencode( $slug ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 15,
				'context' => 'apps_connect',
				'body'    => new stdClass(),
			)
		);
	}

	/**
	 * Connect an API-key/BASIC toolkit with the user's own credentials (Composio).
	 * OAuth apps use apps_connect() + the hosted popup; key-based apps have no hosted
	 * redirect, so the browser collects the fields (from apps_connect's `mode:fields`
	 * response) and posts them here. The platform validates the key against the service,
	 * stores it in its auth vault (never in WordPress), and mirrors the connection.
	 *
	 * SECURITY: field VALUES are credentials - passed straight through, never logged.
	 *
	 * @param string $app_slug Toolkit slug to connect.
	 * @param array  $fields   Map of field name => value (as declared by apps_connect).
	 * @return array|WP_Error Decoded platform body { success, accountId, status, appSlug }.
	 */
	public static function apps_connect_credentials( $app_slug, $fields ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', 'An app must be selected.', array( 'status' => 400 ) );
		}
		// Sanitize field KEYS (identifiers) only; VALUES are opaque secrets passed as-is.
		$clean = array();
		if ( is_array( $fields ) ) {
			foreach ( $fields as $key => $value ) {
				$name = self::sanitize_prop_name( (string) $key );
				if ( '' !== $name && is_scalar( $value ) ) {
					$clean[ $name ] = (string) $value;
				}
			}
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'invalid_input', 'Enter the required credentials.', array( 'status' => 400 ) );
		}
		return self::request(
			'POST',
			'/api/v1/mcp/connections/credentials',
			array(
				'auth'    => 'wpaw',
				'timeout' => 25,
				'context' => 'apps_connect_credentials',
				// Object-cast so the map encodes as JSON `{}` not `[]` (see apps_configure_prop).
				'body'    => array(
					'appSlug' => $slug,
					'fields'  => (object) $clean,
				),
			)
		);
	}

	/**
	 * List the org's connected accounts (mcpConnections mirror).
	 *
	 * @return array|WP_Error Decoded platform body { success, data:[connections] }.
	 */
	public static function apps_connections() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		return self::request(
			'GET',
			'/api/v1/mcp/connections',
			array(
				'auth'    => 'wpaw',
				'timeout' => 15,
				'retries' => 1,
				'context' => 'apps_connections',
			)
		);
	}

	/**
	 * Sync connected accounts from Pipedream into the mcpConnections mirror (called
	 * after the user returns from the Connect Link popup).
	 *
	 * @return array|WP_Error Decoded platform body { success, data:[...], synced }.
	 */
	public static function apps_connections_sync() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		return self::request(
			'POST',
			'/api/v1/mcp/connections/sync',
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'context' => 'apps_connections_sync',
				'body'    => new stdClass(),
			)
		);
	}

	/**
	 * Load dynamic options for one action prop against the user's connected account
	 * (Pipedream configureProp). Powers dependent dropdowns and typeahead search.
	 *
	 * @param string $app_slug        App key/slug.
	 * @param string $tool_name       Action name.
	 * @param string $prop_name       Prop whose options to load.
	 * @param array  $configured_props Currently-set prop values (dependency context).
	 * @param string $query           Optional typeahead query.
	 * @return array|WP_Error Decoded platform body { success, options|stringOptions }.
	 */
	public static function apps_configure_prop( $app_slug, $tool_name, $prop_name, $configured_props = array(), $query = '' ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		$tool = self::sanitize_tool_name( $tool_name );
		$prop = self::sanitize_prop_name( $prop_name );
		if ( '' === $slug || '' === $tool || '' === $prop ) {
			return new WP_Error( 'invalid_input', 'App, action and property are required.', array( 'status' => 400 ) );
		}
		// Cast to object so an EMPTY set encodes as JSON `{}` (a PHP empty array would
		// encode as `[]`, which the platform's `configuredProps: {type:object}` schema
		// rejects with a 400/500 - the cause of the initial dropdown-load failure).
		$props = is_array( $configured_props ) ? $configured_props : array();
		$body  = array( 'configuredProps' => (object) $props );
		if ( '' !== (string) $query ) {
			$body['query'] = (string) $query;
		}
		return self::request(
			'POST',
			'/api/v1/mcp/apps/' . rawurlencode( $slug ) . '/tools/' . rawurlencode( $tool ) . '/configure/' . rawurlencode( $prop ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'context' => 'apps_configure_prop',
				'body'    => $body,
			)
		);
	}

	/**
	 * Reload a whole action prop set after a schema-reshaping ("reloadProps") field
	 * changes (Pipedream reloadProps).
	 *
	 * @param string $app_slug         App key/slug.
	 * @param string $tool_name        Action name.
	 * @param array  $configured_props Currently-set prop values.
	 * @return array|WP_Error Decoded platform body { success, configurableProps }.
	 */
	public static function apps_reload_props( $app_slug, $tool_name, $configured_props = array() ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		$tool = self::sanitize_tool_name( $tool_name );
		if ( '' === $slug || '' === $tool ) {
			return new WP_Error( 'invalid_input', 'App and action are required.', array( 'status' => 400 ) );
		}
		$props = is_array( $configured_props ) ? $configured_props : array();
		return self::request(
			'POST',
			'/api/v1/mcp/apps/' . rawurlencode( $slug ) . '/tools/' . rawurlencode( $tool ) . '/reload',
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'context' => 'apps_reload_props',
				// Object-cast so an empty set encodes as `{}` (see apps_configure_prop).
				'body'    => array( 'configuredProps' => (object) $props ),
			)
		);
	}

	/**
	 * RUN a Connect-an-App action - the metered runtime call. Reserves + settles
	 * credit against the org resolved server-side from this site's key. A
	 * definitive 401 disconnects the site; never retried (a retry could double-run
	 * the action).
	 *
	 * @param string $app_slug         Pipedream app key/slug (e.g. "google_docs").
	 * @param string $tool_name        Action name (e.g. "google_docs-create-document").
	 * @param array  $configured_props Fully-resolved prop values for the action.
	 * @return array|WP_Error Decoded platform body { success, data, creditsCharged, balanceAfter }.
	 */
	public static function apps_execute( $app_slug, $tool_name, $configured_props = array() ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		$tool = self::sanitize_tool_name( $tool_name );
		if ( '' === $slug || '' === $tool ) {
			return new WP_Error( 'invalid_input', 'An app and action are required to run.', array( 'status' => 400 ) );
		}
		// Object-cast so an empty prop set encodes as JSON `{}` (see apps_configure_prop).
		$props = is_array( $configured_props ) ? $configured_props : array();
		return self::request(
			'POST',
			'/api/v1/mcp/actions/run',
			array(
				'auth'                    => 'wpaw',
				'timeout'                 => 60,
				'context'                 => 'apps_execute',
				'disconnect_on_auth_fail' => true,
				'body'                    => array(
					'appSlug'    => $slug,
					'toolName'   => $tool,
					'toolConfig' => (object) $props,
				),
			)
		);
	}

	/* ---------------------------------------------------------------------------
	 * App-event TRIGGERS (wpaw auth) - a workflow trigger bound to a Pipedream
	 * event source; the platform deploys it and POSTs a signed inbound webhook
	 * when the event fires.
	 * ------------------------------------------------------------------------- */

	/**
	 * List an app's available event sources (triggers) with their input schemas.
	 *
	 * @param string $app_slug Pipedream app key/slug.
	 * @return array|WP_Error Decoded platform body { success, data:[eventSources] }.
	 */
	public static function apps_event_sources( $app_slug ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$slug = self::sanitize_app_slug( $app_slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', 'An app must be selected.', array( 'status' => 400 ) );
		}
		return self::request(
			'GET',
			'/api/v1/mcp/apps/' . rawurlencode( $slug ) . '/event-sources',
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'retries' => 1,
				'context' => 'apps_event_sources',
			)
		);
	}

	/**
	 * Deploy a Pipedream trigger (event source) bound to a workflow. On the platform
	 * side this mints a signed inbound-webhook URL for the workflow and registers the
	 * trigger with Pipedream so future events start the run.
	 *
	 * @param string $workflow_id       Platform workflow id to bind the trigger to.
	 * @param string $app_slug          App key/slug.
	 * @param string $event_source_id   Event source (trigger component) id.
	 * @param array  $configured_props  Configured trigger props.
	 * @param string $auth_provision_id Optional connected-account provision id.
	 * @return array|WP_Error Decoded platform body { success, deployedTrigger, message }.
	 */
	public static function apps_deploy_trigger( $workflow_id, $app_slug, $event_source_id, $configured_props = array(), $auth_provision_id = '' ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$workflow = self::sanitize_id( $workflow_id );
		$slug     = self::sanitize_app_slug( $app_slug );
		$source   = self::sanitize_tool_name( $event_source_id );
		if ( '' === $workflow || '' === $slug || '' === $source ) {
			return new WP_Error( 'invalid_input', 'A workflow, app and event source are required.', array( 'status' => 400 ) );
		}
		$props = is_array( $configured_props ) ? $configured_props : array();
		$body  = array(
			'appSlug'         => $slug,
			'eventSourceId'   => $source,
			// Object-cast so an empty set encodes as `{}` (see apps_configure_prop).
			'configuredProps' => (object) $props,
		);
		if ( '' !== (string) $auth_provision_id ) {
			$body['authProvisionId'] = sanitize_text_field( (string) $auth_provision_id );
		}
		return self::request(
			'POST',
			'/api/v1/mcp/workflows/' . rawurlencode( $workflow ) . '/deploy-trigger',
			array(
				'auth'    => 'wpaw',
				'timeout' => 30,
				// Never retried: a retry could create a second deployed trigger.
				'context' => 'apps_deploy_trigger',
				'body'    => $body,
			)
		);
	}

	/**
	 * List the org's DEPLOYED triggers (org-scoped by the site key server-side).
	 *
	 * @return array|WP_Error Decoded platform body { success, data:[deployedTriggers] }.
	 */
	public static function apps_list_triggers() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		return self::request(
			'GET',
			'/api/v1/mcp/triggers',
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'retries' => 1,
				'context' => 'apps_list_triggers',
			)
		);
	}

	/**
	 * Remove a deployed trigger. Org-ownership is enforced by the platform (a
	 * cross-org id → 404), so we simply forward the id.
	 *
	 * @param string $trigger_id Deployed Pipedream trigger id (e.g. "dc_xxx").
	 * @return array|WP_Error Decoded platform body { success, message }.
	 */
	public static function apps_delete_trigger( $trigger_id ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$id = self::sanitize_id( $trigger_id );
		if ( '' === $id ) {
			return new WP_Error( 'invalid_input', 'A trigger id is required.', array( 'status' => 400 ) );
		}
		return self::request(
			'DELETE',
			'/api/v1/mcp/triggers/' . rawurlencode( $id ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'context' => 'apps_delete_trigger',
			)
		);
	}

	/**
	 * Disconnect a connected account (so the user can reconnect with a different
	 * account). Org-ownership is enforced by the platform (a cross-org account id →
	 * 404), so we simply forward the id. Deletes the Pipedream connected account and
	 * its stored credentials.
	 *
	 * @param string $account_id Pipedream connected-account id (e.g. "apn_xxx").
	 * @return array|WP_Error Decoded platform body { success, message }.
	 */
	public static function apps_disconnect( $account_id ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}
		$id = self::sanitize_id( $account_id );
		if ( '' === $id ) {
			return new WP_Error( 'invalid_input', 'An account id is required.', array( 'status' => 400 ) );
		}
		return self::request(
			'DELETE',
			'/api/v1/mcp/connections/' . rawurlencode( $id ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 20,
				'context' => 'apps_disconnect',
			)
		);
	}

	/**
	 * Sanitize an opaque id (workflow id / deployed-trigger id): letters, digits,
	 * underscore and hyphen only - covers UUIDs and Pipedream ids like "dc_abc123".
	 */
	private static function sanitize_id( $id ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
	}

	/**
	 * Sanitize a Pipedream app identifier: letters (either case), digits, underscore
	 * and hyphen only. Anything else is stripped.
	 *
	 * This accepts BOTH forms the app picker can hand us:
	 *   - a lowercase nameSlug, e.g. "google_sheets", "slack"
	 *   - an opaque, MIXED-CASE app id, e.g. "app_M0hv7G"
	 *
	 * Case MUST be preserved: Pipedream app ids are case-sensitive, and lowercasing
	 * them ("app_m0hv7g") makes every downstream lookup 404 - which is what made
	 * "+ Add app" hand a dead id to Connect Link and render "App not found."
	 * The character allowlist is unchanged, so this stays safe for path use (and
	 * callers rawurlencode it regardless).
	 */
	private static function sanitize_app_slug( $slug ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $slug );
	}

	/**
	 * Sanitize a Pipedream action name (e.g. "google_sheets-add-single-row"):
	 * letters, digits, underscore, hyphen only.
	 */
	private static function sanitize_tool_name( $name ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $name ) );
	}

	/**
	 * Sanitize a prop identifier. Pipedream prop names are code identifiers, so we
	 * allow letters, digits and underscore (case preserved).
	 */
	private static function sanitize_prop_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
	}

	/* ---------------------------------------------------------------------------
	 * Cloud execution (wpaw auth) - task 2.7
	 * ------------------------------------------------------------------------- */

	/**
	 * Translate a stored workflow and submit it for cloud execution. Never retried
	 * (a retry could create a second execution + reservation). A 402 pre-check maps
	 * to platform_credits; unsupported nodes map to a typed error naming them.
	 *
	 * @param array $workflow   Decoded workflow blob (nodes/edges/...).
	 * @param mixed $input_data Trigger/input data.
	 * @return array|WP_Error {executionId, status, estimatedCredits} on success.
	 */
	public static function execute_workflow( array $workflow, $input_data = null ) {
		$translated = WP_AI_Workflows_Workflow_Translator::translate( $workflow );
		if ( $translated instanceof WP_AI_Workflows_Translation_Error ) {
			return new WP_Error(
				'cloud_unsupported_nodes',
				$translated->get_message(),
				array(
					'status' => 400,
					'nodes'  => $translated->get_nodes(),
				)
			);
		}

		// Pre-flight: a definition with wpAction nodes (post/save_output callbacks)
		// can only succeed if the cloud can reach this site. Block at submit time
		// with a clear message instead of burning credits on a socket error later.
		// Filterable for tunneled dev setups - see callback_preflight_error().
		$has_wp_action = false;
		foreach ( $translated['definition']['nodes'] as $translated_node ) {
			if ( isset( $translated_node['type'] ) && 'wpAction' === $translated_node['type'] ) {
				$has_wp_action = true;
				break;
			}
		}
		if ( $has_wp_action ) {
			$preflight = self::callback_preflight_error();
			if ( $preflight instanceof WP_Error ) {
				return $preflight;
			}
		}

		$result = self::request(
			'POST',
			'/api/v1/execute/workflow',
			array(
				'auth'    => 'wpaw',
				'timeout' => 30,
				'retries' => 0,
				'body'    => array(
					'definition' => $translated['definition'],
					'inputData'  => null === $input_data ? new stdClass() : $input_data,
				),
				'context' => 'execute_workflow',
				// Runtime execution: a 401 here means the site key is genuinely revoked.
				'disconnect_on_auth_fail' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['executionId'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Cloud submission did not return an execution id.', array( 'status' => 502 ) );
		}
		self::invalidate_credits_cache();
		return $data;
	}

	/**
	 * Poll a cloud execution's status.
	 *
	 * @param int|string $platform_execution_id
	 * @return array|WP_Error {executionId, status, outputData, totalCost, errorMessage}.
	 */
	public static function get_execution( $platform_execution_id ) {
		$result = self::request(
			'GET',
			'/api/v1/execute/workflow/' . rawurlencode( (string) $platform_execution_id ),
			array(
				'auth'    => 'wpaw',
				'timeout' => 10,
				'context' => 'get_execution',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * Fetch the ORDERED per-node steps for a cloud execution so the details modal
	 * can render what happened at each node (node name + input + output) instead of
	 * falling back to execution-level metadata. Org-scoped by the site key.
	 *
	 * @param int|string $platform_execution_id
	 * @return array|WP_Error Decoded {executionId, status, totalCredits, steps} on success; WP_Error otherwise.
	 */
	public static function get_execution_steps( $platform_execution_id ) {
		$result = self::request(
			'GET',
			'/api/v1/execute/workflow/' . rawurlencode( (string) $platform_execution_id ) . '/steps',
			array(
				'auth'    => 'wpaw',
				'timeout' => 12,
				'context' => 'get_execution_steps',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/* ---------------------------------------------------------------------------
	 * Metered AI proxy (wpaw auth) - task 3.2
	 * ------------------------------------------------------------------------- */

	/**
	 * Route a single AI completion through the metered platform proxy. Never adds
	 * a provider API key - BYOK stays local-only. No automatic retry, except one
	 * bounded retry on 429 honouring Retry-After. No fallback to BYOK on failure;
	 * failures surface as typed, non-charged WP_Errors.
	 *
	 * @param array $payload {model, input|messages, systemPrompt?, maxTokens?, temperature?}.
	 * @return array|WP_Error Decoded proxy data {content, usage, costUsd, creditsCharged, balanceAfter}.
	 */
	public static function proxy_ai( array $payload ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected to a platform account.', array( 'status' => 400 ) );
		}

		// Defence in depth: strip anything that looks like a provider key even if a
		// caller mis-builds the payload. The proxy runs on the platform's own keys.
		$forbidden = array( 'apiKey', 'api_key', 'openai_api_key', 'openrouter_api_key', 'anthropic_api_key', 'perplexity_api_key', 'key', 'token', 'tokens', 'authorization', 'Authorization' );
		foreach ( $forbidden as $f ) {
			unset( $payload[ $f ] );
		}

		$do_request = static function () use ( $payload ) {
			return self::request(
				'POST',
				'/api/v1/proxy/ai',
				array(
					'auth'    => 'wpaw',
					'timeout' => 130,
					'retries' => 0,
					'body'    => $payload,
					'context' => 'proxy_ai',
					// Runtime execution: a 401 here means the site key is genuinely revoked.
					'disconnect_on_auth_fail' => true,
				)
			);
		};

		$result = $do_request();

		// Single bounded retry on rate-limit (R7.6), honouring Retry-After (cap 10s).
		if ( is_wp_error( $result ) && 'platform_rate_limited' === $result->get_error_code() ) {
			$data        = $result->get_error_data();
			$retry_after = is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 2;
			sleep( min( max( $retry_after, 1 ), 10 ) );
			$result = $do_request();
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		if ( ! isset( $data['content'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'The AI proxy returned an unexpected response.', array( 'status' => 502 ) );
		}

		// Write the fresh balance through the meter cache (R5.6).
		if ( isset( $data['balanceAfter'] ) ) {
			self::cache_balance_after( (float) $data['balanceAfter'] );
		} else {
			self::invalidate_credits_cache();
		}

		return $data;
	}

	/* ---------------------------------------------------------------------------
	 * PDF render service (wpaw auth) - Generate PDF node
	 * ------------------------------------------------------------------------- */

	/**
	 * List the built-in PDF templates (id, name, description, category, fields,
	 * sample) so the Generate PDF node's picker can render each template and its
	 * expected data fields. Site-key authed (scope execute:workflow).
	 *
	 * @return array|WP_Error Decoded `templates` array on success; WP_Error otherwise.
	 */
	public static function get_pdf_templates() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected to a platform account.', array( 'status' => 400 ) );
		}

		$result = self::request(
			'GET',
			'/api/v1/pdf/templates',
			array(
				'auth'    => 'wpaw',
				'timeout' => 15,
				'retries' => 1,
				'context' => 'pdf_templates',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$templates = isset( $result['templates'] ) && is_array( $result['templates'] ) ? $result['templates'] : array();
		return $templates;
	}

	/**
	 * Render a PDF via the cloud render service and return its stored public URL.
	 * Never adds a provider key; metered in credits. A 402 maps to the typed
	 * `platform_credits` WP_Error.
	 *
	 * @param array $body {template:<id|"custom">, data?:{}, html?:<custom>, options?:{}}.
	 * @return array|WP_Error Decoded {fileId, fileUrl, bytes, pages, creditsCharged, balanceAfter} or WP_Error.
	 */
	public static function render_pdf( array $body ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected to a platform account.', array( 'status' => 400 ) );
		}

		// Defence in depth: never let a provider key ride along to the render service.
		$forbidden = array( 'apiKey', 'api_key', 'key', 'token', 'authorization', 'Authorization' );
		foreach ( $forbidden as $f ) {
			unset( $body[ $f ] );
		}

		$result = self::request(
			'POST',
			'/api/v1/pdf/generate',
			array(
				'auth'    => 'wpaw',
				'timeout' => 130,
				'retries' => 0,
				'body'    => $body,
				'context' => 'pdf_generate',
				// Runtime execution: a 401 here means the site key is genuinely revoked.
				'disconnect_on_auth_fail' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The endpoint returns the render result at the top level (not under `data`).
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		if ( empty( $data['fileUrl'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'The PDF render service returned an unexpected response.', array( 'status' => 502 ) );
		}

		if ( isset( $data['balanceAfter'] ) ) {
			self::cache_balance_after( (float) $data['balanceAfter'] );
		} else {
			self::invalidate_credits_cache();
		}

		return $data;
	}

	/**
	 * Render a free preview image (PNG data URL) of a template or custom HTML.
	 * Unlike render_pdf() this is never metered - lets the user preview before
	 * spending a credit. No provider key rides along; nothing is charged.
	 *
	 * @param array $body {template:<id|"custom">, data?:{}, html?:<custom>, options?:{landscape,width}}.
	 * @return array|WP_Error Decoded {mimeType, dataUrl, bytes} or WP_Error.
	 */
	public static function preview_pdf( array $body ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected to a platform account.', array( 'status' => 400 ) );
		}

		// Defence in depth: never let a provider key ride along to the render service.
		$forbidden = array( 'apiKey', 'api_key', 'key', 'token', 'authorization', 'Authorization' );
		foreach ( $forbidden as $f ) {
			unset( $body[ $f ] );
		}

		$result = self::request(
			'POST',
			'/api/v1/pdf/preview',
			array(
				'auth'    => 'wpaw',
				'timeout' => 60,
				'retries' => 0,
				'body'    => $body,
				'context' => 'pdf_preview',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The endpoint returns the preview result at the top level (not under `data`).
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		if ( empty( $data['dataUrl'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'The PDF preview service returned an unexpected response.', array( 'status' => 502 ) );
		}

		// Preview is free - DO NOT touch the credits cache.
		return $data;
	}

	/* ---------------------------------------------------------------------------
	 * Billing - Paddle overlay checkout, config, subscription, portal
	 * See docs/BILLING-BACKEND-CONTRACT.md for the exact backend contract.
	 * ------------------------------------------------------------------------- */

	/**
	 * Ask the backend to create a checkout transaction and return our hosted
	 * checkout page URL, opened in a popup (the plugin never loads Paddle.js or
	 * needs a client token). JWT-authed; an expired JWT returns `platform_auth_jwt`
	 * so the UI re-prompts login.
	 *
	 * @param string $item_id Pack/plan identifier (e.g. topup_10 / topup_25 / topup_100 / pro / business).
	 * @param string $cycle   Optional billing cycle for subscriptions (monthly|yearly).
	 * @return array{checkoutUrl:string,transactionId?:string}|WP_Error
	 */
	public static function create_checkout( $item_id, $cycle = '' ) {
		$body = array(
			'itemId' => (string) $item_id,
			'planId' => (string) $item_id, // Back-compat alias; backend may read either.
		);
		if ( '' !== $cycle ) {
			$body['billingCycle'] = (string) $cycle;
		}

		// Billing endpoint is org-scoped: send the connected org id, mirroring other
		// org-scoped calls. '' when disconnected - the JWT-authed UI already gates this.
		$meta   = self::get_connection_meta();
		$org_id = isset( $meta['orgId'] ) ? (string) $meta['orgId'] : '';

		$result = self::request(
			'POST',
			'/api/v1/billing/checkout',
			array(
				'auth'    => 'jwt',
				'org_id'  => $org_id,
				'timeout' => 15,
				'retries' => 0,
				'body'    => $body,
				'context' => 'create_checkout',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;

		if ( empty( $data['checkoutUrl'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Checkout could not be created. Please try again.', array( 'status' => 502 ) );
		}

		$out = array( 'checkoutUrl' => esc_url_raw( (string) $data['checkoutUrl'] ) );
		if ( ! empty( $data['transactionId'] ) ) {
			$out['transactionId'] = sanitize_text_field( (string) $data['transactionId'] );
		}
		return $out;
	}

	/**
	 * Fetch the connected organization's current subscription summary for display
	 * (plan name, status, cycle). Best-effort - a failure surfaces as a WP_Error the
	 * UI degrades gracefully around (it still shows credit packs). wpaw-authed.
	 *
	 * @return array{plan:string,status:string,cycle:string,renewsAt:string}|WP_Error
	 */
	public static function subscription_status() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected.', array( 'status' => 400 ) );
		}

		// JWT-only, org-scoped read - the wpaw_ site key is not accepted here (would
		// misfire the invalid-key disconnect path). Best-effort: an expired JWT
		// returns platform_auth_jwt without purging the connection.
		$meta   = self::get_connection_meta();
		$org_id = isset( $meta['orgId'] ) ? (string) $meta['orgId'] : '';

		$result = self::request(
			'GET',
			'/api/v1/billing/subscription',
			array(
				'auth'    => 'jwt',
				'org_id'  => $org_id,
				'timeout' => 10,
				'retries' => 1,
				'context' => 'subscription_status',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		return array(
			'plan'     => isset( $data['plan'] ) ? sanitize_text_field( (string) $data['plan'] ) : 'free',
			'status'   => isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : 'none',
			'cycle'    => isset( $data['cycle'] ) ? sanitize_text_field( (string) $data['cycle'] ) : '',
			'renewsAt' => isset( $data['renewsAt'] ) ? sanitize_text_field( (string) $data['renewsAt'] ) : '',
		);
	}

	/**
	 * Mint a Paddle customer-portal session and return its one-time, time-limited
	 * overview URL. JWT-authed; the backend resolves the customer id server-side.
	 *
	 * @return array{portalUrl:string}|WP_Error
	 */
	public static function create_portal_session() {
		$result = self::request(
			'POST',
			'/api/v1/billing/portal',
			array(
				'auth'    => 'jwt',
				'timeout' => 15,
				'retries' => 0,
				'body'    => array(),
				'context' => 'create_portal_session',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		if ( empty( $data['portalUrl'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'The subscription portal is unavailable. Please try again.', array( 'status' => 502 ) );
		}
		return array( 'portalUrl' => esc_url_raw( (string) $data['portalUrl'] ) );
	}

	/* ---------------------------------------------------------------------------
	 * Legacy license redemption (JWT/wpaw auth) - task 5.5
	 * ------------------------------------------------------------------------- */

	/**
	 * Submit a legacy v1 (SLM) license key for redemption and normalise the backend
	 * response into a single shape both the manual UI and the automatic connect-time
	 * flow consume. The key is sent once and never logged.
	 *
	 * Success (200) → the backend returns
	 *   { mechanism:'monthly'|'goodwill', tier:'pro'|'business'|null, granted,
	 *     monthlyCredits, endsAt, balance:{creditBalance,…} }.
	 * Definitive non-grants fold into the same array (never retried): 404 →
	 * 'invalid', 409 → 'already', 422 → 'not_redeemable'. Only transient problems
	 * bubble up as a WP_Error so the caller can retry.
	 *
	 * @param string $license_key
	 * @return array{outcome:string,mechanism:string,tier:string,granted:int,monthlyCredits:int,endsAt:string,balanceAfter:float|null}|WP_Error
	 */
	public static function redeem_license( $license_key ) {
		$license_key = trim( (string) $license_key );
		if ( '' === $license_key ) {
			return new WP_Error( 'invalid_input', 'A license key is required.', array( 'status' => 400 ) );
		}

		// Prefer the site key (wpaw) when connected; fall back to JWT session.
		$auth = self::is_connected() ? 'wpaw' : 'jwt';

		$result = self::request(
			'POST',
			'/api/v1/licenses/redeem',
			array(
				'auth'    => $auth,
				'timeout' => 15,
				'retries' => 0,
				'body'    => array( 'licenseKey' => $license_key ),
				'context' => 'redeem_license',
			)
		);

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			// Definitive redemption verdicts arrive as HTTP errors - fold them into the
			// normalised shape so callers treat them as final (never retried).
			if ( 404 === $status ) {
				return self::normalize_redeem_definitive( 'invalid' );
			}
			if ( 409 === $status ) {
				// Already redeemed elsewhere - just refresh the (already-correct) meter.
				self::invalidate_credits_cache();
				return self::normalize_redeem_definitive( 'already' );
			}
			if ( 422 === $status ) {
				return self::normalize_redeem_definitive( 'not_redeemable' );
			}
			// Transient / auth / unexpected - let the caller decide to retry.
			return $result;
		}

		$data      = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
		$mechanism = isset( $data['mechanism'] ) ? sanitize_key( (string) $data['mechanism'] ) : '';

		// Back-compat with the earlier stub shape { status:'granted'|'pending', creditsGranted }.
		if ( '' === $mechanism && isset( $data['status'] ) ) {
			$legacy_status = sanitize_key( (string) $data['status'] );
			if ( 'granted' === $legacy_status ) {
				$mechanism = 'goodwill';
			} elseif ( 'invalid' === $legacy_status ) {
				return self::normalize_redeem_definitive( 'invalid' );
			} elseif ( 'pending' === $legacy_status ) {
				return self::normalize_redeem_definitive( 'pending' );
			}
		}

		$balance_after = null;
		if ( isset( $data['balance']['creditBalance'] ) ) {
			$balance_after = (float) $data['balance']['creditBalance'];
		} elseif ( isset( $data['balanceAfter'] ) ) {
			$balance_after = (float) $data['balanceAfter'];
		}

		$is_grant = ( 'monthly' === $mechanism || 'goodwill' === $mechanism );
		if ( $is_grant ) {
			// A grant changed the balance - drop the cache so the meter refetches fresh.
			self::invalidate_credits_cache();
		}

		return array(
			'outcome'        => $is_grant ? $mechanism : 'error',
			'mechanism'      => $is_grant ? $mechanism : '',
			'tier'           => isset( $data['tier'] ) ? sanitize_key( (string) $data['tier'] ) : '',
			'granted'        => isset( $data['granted'] ) ? (int) $data['granted'] : ( isset( $data['creditsGranted'] ) ? (int) $data['creditsGranted'] : 0 ),
			'monthlyCredits' => isset( $data['monthlyCredits'] ) ? (int) $data['monthlyCredits'] : 0,
			'endsAt'         => isset( $data['endsAt'] ) ? sanitize_text_field( (string) $data['endsAt'] ) : '',
			'balanceAfter'   => $balance_after,
		);
	}

	/**
	 * Build the normalised redeem array for a definitive non-grant outcome
	 * (invalid / already / not_redeemable / pending). No credits, no schedule.
	 *
	 * @param string $outcome
	 * @return array
	 */
	private static function normalize_redeem_definitive( $outcome ) {
		return array(
			'outcome'        => $outcome,
			'mechanism'      => '',
			'tier'           => '',
			'granted'        => 0,
			'monthlyCredits' => 0,
			'endsAt'         => '',
			'balanceAfter'   => null,
		);
	}

	/* ---------------------------------------------------------------------------
	 * Automatic legacy-key redemption on connect. A site upgrading from 1.x has
	 * its old SLM key in OPTION_LEGACY_KEY; on connect we auto-redeem it so an
	 * existing customer's credits/plan appear without typing anything. Never
	 * blocks the connect flow - queued via cron.
	 * ------------------------------------------------------------------------- */

	/**
	 * The old 1.x SLM license key stored in wp options, or '' when none (fresh install).
	 *
	 * @return string
	 */
	public static function get_legacy_license_key() {
		$key = get_option( self::OPTION_LEGACY_KEY, '' );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * The local auto-redeem record (see OPTION_LEGACY_REDEEM). Always an array.
	 *
	 * @return array
	 */
	public static function get_legacy_redeem_result() {
		$record = get_option( self::OPTION_LEGACY_REDEEM, array() );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Queue the automatic legacy redemption after a successful connect. Fire-and-forget:
	 * marks the attempt pending and schedules an off-request cron run so connecting is
	 * never delayed. No-ops when there is no stored key, or when a DEFINITIVE result is
	 * already recorded (idempotency - a reconnect never re-redeems).
	 *
	 * @return void
	 */
	private static function maybe_queue_legacy_auto_redeem() {
		$key = self::get_legacy_license_key();
		if ( '' === $key ) {
			return; // Fresh install / no legacy key - nothing to redeem.
		}
		$record = self::get_legacy_redeem_result();
		$state  = isset( $record['state'] ) ? (string) $record['state'] : '';
		if ( 'done' === $state || 'exhausted' === $state ) {
			return; // Already settled once - never auto-attempt again.
		}

		update_option(
			self::OPTION_LEGACY_REDEEM,
			array( 'state' => 'pending_attempt', 'queuedAt' => time() ),
			false
		);
		if ( ! wp_next_scheduled( self::CRON_AUTO_REDEEM ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_AUTO_REDEEM );
		}
		WP_AI_Workflows_Utilities::debug_log( 'Legacy auto-redeem queued after connect.', 'info' );
	}

	/**
	 * Perform the queued legacy redemption and record its result. Safe to call from
	 * both the cron event and inline from the status endpoint - a transient lock
	 * plus a settled-state guard mean it runs at most once.
	 *
	 * @return array The (possibly updated) auto-redeem record.
	 */
	public static function run_legacy_auto_redeem() {
		$record = self::get_legacy_redeem_result();
		$state  = isset( $record['state'] ) ? (string) $record['state'] : '';
		if ( 'done' === $state || 'exhausted' === $state ) {
			return $record; // Already settled - nothing to do.
		}
		if ( ! self::is_connected() ) {
			// Disconnected mid-flow - leave pending; a later connect/poll retries.
			WP_AI_Workflows_Utilities::debug_log( 'Legacy auto-redeem skipped: site not connected.', 'info' );
			return $record;
		}
		$key = self::get_legacy_license_key();
		if ( '' === $key ) {
			return $record;
		}

		// Serialise cron vs. inline runners so we never double-redeem.
		if ( get_transient( self::LOCK_AUTO_REDEEM ) ) {
			return $record;
		}
		set_transient( self::LOCK_AUTO_REDEEM, time(), 30 );

		$result = self::redeem_license( $key );

		if ( is_wp_error( $result ) ) {
			// Transient (503/429/401/network) - keep the record pending for a retry.
			WP_AI_Workflows_Utilities::debug_log(
				self::redact( 'Legacy auto-redeem transient failure: ' . $result->get_error_code() ),
				'warning'
			);
			delete_transient( self::LOCK_AUTO_REDEEM );
			return self::get_legacy_redeem_result();
		}

		$outcome = isset( $result['outcome'] ) ? (string) $result['outcome'] : 'error';
		if ( 'monthly' === $outcome || 'goodwill' === $outcome ) {
			$record = array(
				'state'          => 'done',
				'mechanism'      => $outcome,
				'tier'           => isset( $result['tier'] ) ? (string) $result['tier'] : '',
				'granted'        => isset( $result['granted'] ) ? (int) $result['granted'] : 0,
				'monthlyCredits' => isset( $result['monthlyCredits'] ) ? (int) $result['monthlyCredits'] : 0,
				'endsAt'         => isset( $result['endsAt'] ) ? (string) $result['endsAt'] : '',
				'attemptedAt'    => time(),
			);
			WP_AI_Workflows_Utilities::debug_log( 'Legacy auto-redeem granted (' . $outcome . ').', 'info' );
		} else {
			// invalid (404) / already (409) / not_redeemable (422) / pending - stay quiet.
			$record = array(
				'state'       => 'exhausted',
				'outcome'     => $outcome,
				'attemptedAt' => time(),
			);
			WP_AI_Workflows_Utilities::debug_log( 'Legacy auto-redeem settled without a grant (' . $outcome . ').', 'info' );
		}
		update_option( self::OPTION_LEGACY_REDEEM, $record, false );
		delete_transient( self::LOCK_AUTO_REDEEM );
		return $record;
	}

	/* ---------------------------------------------------------------------------
	 * Connection lifecycle (JWT auth) - task 1.3
	 * ------------------------------------------------------------------------- */

	/**
	 * Log in to the platform, store the JWT, and return the caller's organization
	 * list (never the token). Enriches org names via GET /organizations so the UI
	 * can present a friendly picker (R1.2).
	 *
	 * @param string $email
	 * @param string $password
	 * @return array{organizations:array}|WP_Error
	 */
	public static function login( $email, $password ) {
		return self::authenticate(
			'/api/v1/auth/login',
			array(
				'email'    => $email,
				'password' => $password,
				'remember' => true,
			),
			'login'
		);
	}

	/**
	 * Create a platform account from inside the plugin (headless - users never
	 * visit the dashboard) and connect the resulting session. Returns the org
	 * list, ready for register_site().
	 *
	 * @param string $email
	 * @param string $password
	 * @param string $first_name
	 * @param string $last_name
	 * @return array{organizations:array}|WP_Error
	 */
	public static function signup( $email, $password, $first_name = '', $last_name = '' ) {
		$result = self::authenticate(
			'/api/v1/auth/register',
			array(
				'email'     => $email,
				'password'  => $password,
				'firstName' => $first_name,
				'lastName'  => $last_name,
				'remember'  => true,
			),
			'signup'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A brand-new account can come back with zero organizations, which would leave
		// the signup credit grant (fired at org creation) unclaimed. Create a default
		// organization so the account is funded; existing orgs pass through unchanged.
		if ( empty( $result['organizations'] ) ) {
			$created = self::create_organization( self::default_org_name( $email, $first_name ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$result['organizations'] = array( $created );
		}

		return $result;
	}

	/**
	 * Create a default organization for the authenticated user (JWT session). Used
	 * when in-plugin signup returns no organizations, so the backend's one-time
	 * 200-credit signup grant (which fires at org creation) still funds the account.
	 *
	 * Backend: POST /api/v1/organizations (JWT-auth) with body {name, slug?}. The
	 * backend generates a slug from the name when omitted and de-duplicates slug
	 * collisions itself, so a collision never surfaces as an error here.
	 *
	 * @param string $name Human-readable organization name.
	 * @param string $slug Optional slug seed; a safe, pattern-valid slug is derived.
	 * @return array{organizationId:string,role:string,name:string}|WP_Error
	 */
	public static function create_organization( $name, $slug = '' ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			$name = 'My Workspace';
		}
		// Backend caps name at 255 chars.
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 255 );
		} else {
			$name = substr( $name, 0, 255 );
		}

		$body       = array( 'name' => $name );
		$safe_slug  = self::generate_org_slug( '' !== $slug ? $slug : $name );
		if ( '' !== $safe_slug ) {
			$body['slug'] = $safe_slug;
		}

		$result = self::request(
			'POST',
			'/api/v1/organizations',
			array(
				'auth'    => 'jwt',
				'timeout' => 15,
				'body'    => $body,
				'context' => 'create_organization',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Organization creation did not return an id.', array( 'status' => 502 ) );
		}

		return array(
			'organizationId' => sanitize_text_field( (string) $data['id'] ),
			'role'           => 'owner',
			'name'           => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : $name,
		);
	}

	/**
	 * Pick a friendly default name for the auto-created workspace. Priority: the
	 * first name the user gave at signup, else this site's title, else the email
	 * local-part.
	 *
	 * @param string $email
	 * @param string $first_name
	 * @return string
	 */
	private static function default_org_name( $email, $first_name = '' ) {
		$first = trim( (string) $first_name );
		if ( '' !== $first ) {
			$base = $first;
		} else {
			$base = trim( (string) get_bloginfo( 'name' ) );
			if ( '' === $base ) {
				$local = is_string( $email ) ? strstr( $email, '@', true ) : '';
				$base  = '' !== (string) $local ? (string) $local : 'My';
			}
		}
		return $base . ' Workspace';
	}

	/**
	 * Build a slug matching the backend pattern ^[a-z0-9-]+$, with a short random
	 * suffix so the first insert attempt is unlikely to collide. The backend also
	 * de-duplicates slugs, so this is belt-and-braces, never the sole guarantee.
	 *
	 * @param string $seed Text to slugify.
	 * @return string
	 */
	private static function generate_org_slug( $seed ) {
		$slug = sanitize_title( (string) $seed );
		$slug = preg_replace( '/[^a-z0-9-]/', '', (string) $slug );
		$slug = trim( (string) $slug, '-' );
		if ( '' === $slug ) {
			$slug = 'workspace';
		}
		return $slug . '-' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 );
	}

	/**
	 * Shared login/signup flow: POST credentials, store the JWT, and return the
	 * caller's organization list (never the token) with human-readable names.
	 *
	 * @param string $path    Auth endpoint path.
	 * @param array  $body    Request body.
	 * @param string $context Log label.
	 * @return array{organizations:array}|WP_Error
	 */
	private static function authenticate( $path, array $body, $context ) {
		$result = self::request(
			'POST',
			$path,
			array(
				'auth'    => 'none',
				'timeout' => 15,
				'body'    => $body,
				'context' => $context,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$token = isset( $data['token'] ) ? (string) $data['token'] : '';
		if ( '' === $token ) {
			return new WP_Error( 'platform_invalid_response', 'Login did not return a session token.', array( 'status' => 502 ) );
		}
		self::store_jwt( $token );

		$memberships = isset( $data['user']['organizations'] ) && is_array( $data['user']['organizations'] )
			? $data['user']['organizations']
			: array();

		$orgs = array();
		foreach ( $memberships as $m ) {
			if ( empty( $m['organizationId'] ) ) {
				continue;
			}
			$orgs[ $m['organizationId'] ] = array(
				'organizationId' => sanitize_text_field( $m['organizationId'] ),
				'role'           => isset( $m['role'] ) ? sanitize_text_field( $m['role'] ) : '',
				'name'           => sanitize_text_field( $m['organizationId'] ),
			);
		}

		// Enrich with human-readable org names (best-effort; failure is non-fatal).
		$named = self::request( 'GET', '/api/v1/organizations', array( 'auth' => 'jwt', 'timeout' => 10, 'context' => 'organizations' ) );
		if ( ! is_wp_error( $named ) && isset( $named['data'] ) && is_array( $named['data'] ) ) {
			foreach ( $named['data'] as $org ) {
				if ( ! empty( $org['id'] ) && isset( $orgs[ $org['id'] ] ) && ! empty( $org['name'] ) ) {
					$orgs[ $org['id'] ]['name'] = sanitize_text_field( $org['name'] );
				}
			}
		}

		return array( 'organizations' => array_values( $orgs ) );
	}

	/**
	 * Adopt a platform JWT obtained via the "Continue with Google" OAuth popup
	 * (backend hands the token to the browser via postMessage). The Google-sign-in
	 * analogue of login(): stores the JWT and returns the org list. The token is
	 * validated by using it - a failing GET /organizations clears the session so
	 * the UI re-prompts.
	 *
	 * @param string $token Raw platform JWT (header.payload.signature).
	 * @return array{organizations:array}|WP_Error
	 */
	public static function adopt_session( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_input', 'A session token is required.', array( 'status' => 400 ) );
		}
		// Structural guard only, not verification - a JWT is three base64url segments;
		// the platform itself rejects a forged/expired token on the call below.
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token ) ) {
			return new WP_Error( 'invalid_input', 'The sign-in token was malformed. Please try again.', array( 'status' => 400 ) );
		}

		self::store_jwt( $token );

		// Validate the token AND obtain the org list in one call (JWT-authed).
		$named = self::request(
			'GET',
			'/api/v1/organizations',
			array(
				'auth'    => 'jwt',
				'timeout' => 10,
				'context' => 'google_organizations',
			)
		);
		if ( is_wp_error( $named ) ) {
			// A bad/expired token is not a usable session - drop it so the UI re-prompts.
			self::clear_jwt();
			return $named;
		}

		$orgs = array();
		if ( isset( $named['data'] ) && is_array( $named['data'] ) ) {
			foreach ( $named['data'] as $org ) {
				if ( empty( $org['id'] ) ) {
					continue;
				}
				$orgs[] = array(
					'organizationId' => sanitize_text_field( (string) $org['id'] ),
					'role'           => isset( $org['role'] ) ? sanitize_text_field( (string) $org['role'] ) : '',
					'name'           => isset( $org['name'] ) ? sanitize_text_field( (string) $org['name'] ) : sanitize_text_field( (string) $org['id'] ),
				);
			}
		}

		// Mirror the signup path: a brand-new Google user can land here with zero
		// organizations, so create a default org to claim the signup credit grant.
		if ( empty( $orgs ) ) {
			$created = self::create_organization( self::default_org_name( '' ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$orgs = array( $created );
		}

		return array( 'organizations' => $orgs );
	}

	/**
	 * Register this WordPress site with the selected organization and persist the
	 * issued `wpaw_` key encrypted (R1.3, R1.4).
	 *
	 * @param string $org_id          Organization id (sent as x-organization-id).
	 * @param string $org_name        Human-readable org name for display (optional).
	 * @param string $replace_site_id Site to move the account off, freeing its plan slot.
	 * @return array|WP_Error Non-secret connection metadata on success.
	 */
	public static function register_site( $org_id, $org_name = '', $replace_site_id = '' ) {
		$org_id = sanitize_text_field( $org_id );
		if ( '' === $org_id ) {
			return new WP_Error( 'platform_error', 'An organization must be selected before registering this site.', array( 'status' => 400 ) );
		}

		$body = array(
			'siteUrl'  => home_url(),
			'siteName' => get_bloginfo( 'name' ),
		);

		$replace_site_id = sanitize_text_field( (string) $replace_site_id );
		if ( '' !== $replace_site_id ) {
			$body['replaceSiteId'] = $replace_site_id;
		}

		$result = self::request(
			'POST',
			'/api/v1/sites/register',
			array(
				'auth'    => 'jwt',
				'org_id'  => $org_id,
				'timeout' => 15,
				'body'    => $body,
				'context' => 'register_site',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['apiKey'] ) || empty( $data['siteId'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Site registration did not return a key.', array( 'status' => 502 ) );
		}

		$stored = self::store_key( $data['apiKey'] );
		if ( is_wp_error( $stored ) ) {
			// Encryption unavailable - never fall back to plaintext (R1.8).
			return $stored;
		}

		$existing = '' !== $org_name ? array( 'orgName' => sanitize_text_field( $org_name ) ) : array();
		$meta     = self::build_connection_meta( $data, $org_id, $existing );
		self::store_connection( $meta );
		self::invalidate_credits_cache();

		// v2.0 migration: the site is now connected - auto-redeem any stored 1.x SLM key
		// so an existing customer's credits/plan appear without typing anything. Queued
		// fire-and-forget; it never blocks or fails this connect.
		self::maybe_queue_legacy_auto_redeem();

		return $meta;
	}

	/**
	 * Rotate the site key (revokes the old key immediately) and replace the stored
	 * value (R1.6).
	 *
	 * @return array|WP_Error Updated connection metadata.
	 */
	public static function rotate_key() {
		$meta = self::get_connection_meta();
		if ( empty( $meta['siteId'] ) ) {
			return new WP_Error( 'platform_error', 'This site is not connected.', array( 'status' => 400 ) );
		}

		$result = self::request(
			'POST',
			'/api/v1/sites/rotate-key',
			array(
				'auth'    => 'jwt',
				'org_id'  => isset( $meta['orgId'] ) ? $meta['orgId'] : '',
				'timeout' => 15,
				'body'    => array( 'siteId' => $meta['siteId'] ),
				'context' => 'rotate_key',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['apiKey'] ) ) {
			return new WP_Error( 'platform_invalid_response', 'Key rotation did not return a new key.', array( 'status' => 502 ) );
		}

		$stored = self::store_key( $data['apiKey'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$new_meta = self::build_connection_meta( $data, isset( $meta['orgId'] ) ? $meta['orgId'] : '', $meta );
		self::store_connection( $new_meta );

		return $new_meta;
	}

	/**
	 * Disconnect the site: revoke on the platform, then purge local state. Purges
	 * locally even if the remote call fails with a definitive 401 (R1.5, R7.3).
	 *
	 * @return array|WP_Error {disconnected: true} on success.
	 */
	public static function disconnect() {
		$meta = self::get_connection_meta();
		if ( empty( $meta['siteId'] ) ) {
			self::mark_disconnected( 'already_disconnected' );
			return array( 'disconnected' => true );
		}

		$result = self::request(
			'DELETE',
			'/api/v1/sites/' . rawurlencode( $meta['siteId'] ),
			array(
				'auth'    => 'jwt',
				'org_id'  => isset( $meta['orgId'] ) ? $meta['orgId'] : '',
				'timeout' => 15,
				'context' => 'disconnect',
			)
		);

		// A 401 (revoked/expired) is still a definitive "gone" - purge locally.
		if ( is_wp_error( $result ) ) {
			$err = $result->get_error_code();
			if ( 'platform_auth' === $err || 'platform_auth_jwt' === $err ) {
				self::mark_disconnected( 'remote_401' );
				return array( 'disconnected' => true );
			}
			return $result;
		}

		self::mark_disconnected( 'user_disconnect' );
		return array( 'disconnected' => true );
	}

	/* ---------------------------------------------------------------------------
	 * Agency multi-site management (owner-gated, /org/sites/*). Gated on the
	 * platform account role being `owner` of the org, enforced backend-side.
	 * Errors preserve the backend's machine `code` (NOT_OWNER / UPGRADE_REQUIRED /
	 * …) via a dedicated request helper, since the generic taxonomy collapses it.
	 * ------------------------------------------------------------------------- */

	/**
	 * Perform an owner-gated /org/sites/* request (JWT + org header) and return the
	 * decoded body on 2xx or a typed WP_Error carrying the backend `code` in
	 * `platform_code`. A 401 is treated as an expired human session, not a revoked
	 * site key: only the JWT is dropped, the connection stays intact.
	 *
	 * @param string     $method GET|POST|PATCH|PUT|DELETE.
	 * @param string     $path   Path under base_url(), beginning with '/'.
	 * @param array|null $body   Optional request body.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error Decoded JSON body on 2xx; WP_Error otherwise.
	 */
	private static function org_sites_request( $method, $path, $body = null, $timeout = 15 ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'platform_disconnected', 'This site is not connected to a platform account.', array( 'status' => 400 ) );
		}

		$token = self::get_jwt();
		if ( is_wp_error( $token ) ) {
			return $token; // platform_auth_jwt → UI re-prompts login.
		}

		$meta   = self::get_connection_meta();
		$org_id = isset( $meta['orgId'] ) ? (string) $meta['orgId'] : '';
		if ( '' === $org_id ) {
			return new WP_Error( 'platform_error', 'No organization is associated with this connection.', array( 'status' => 400 ) );
		}

		$args = array(
			'method'    => $method,
			'headers'   => array(
				'Accept'            => 'application/json',
				'Content-Type'      => 'application/json',
				'Authorization'     => 'Bearer ' . $token,
				'x-organization-id' => $org_id,
			),
			'timeout'   => (int) $timeout,
			'sslverify' => true,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				self::redact( 'Org-sites request failed [' . $path . ']: ' . $response->get_error_message() ),
				'error'
			);
			return new WP_Error( 'platform_unreachable', 'The platform is currently unreachable. Please try again shortly.', array( 'status' => 503 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'platform_invalid_response', 'The platform returned an unexpected response.', array( 'status' => 502 ) );
			}
			self::maybe_refresh_session();
			return $data;
		}

		if ( 401 === $code ) {
			// Expired human session - drop this user's management JWT so the UI
			// re-prompts login, but do NOT purge the site key/connection (this is not
			// an invalid-key signal).
			self::clear_jwt();
			return new WP_Error( 'platform_auth_jwt', 'Your session ended. Sign in again to manage your account.', array( 'status' => 401 ) );
		}

		// Preserve the backend machine code (e.g. NOT_OWNER) with its case intact.
		$platform_code = '';
		if ( is_array( $data ) && ! empty( $data['code'] ) ) {
			$platform_code = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $data['code'] );
		}
		$message = is_array( $data ) && ! empty( $data['error'] )
			? sanitize_text_field( (string) $data['error'] )
			: 'The platform returned an error.';

		WP_AI_Workflows_Utilities::debug_log(
			self::redact( 'Org-sites error [' . $path . '] HTTP ' . $code . ' code=' . $platform_code ),
			'warning'
		);

		return new WP_Error(
			'platform_org_sites',
			$message,
			array(
				'status'        => $code,
				'platform_code' => $platform_code,
			)
		);
	}

	/**
	 * List the org's sites with entitlement, usage and per-site caps (owner-only).
	 *
	 * @return array|WP_Error {entitlement:{plan,isBusiness,siteLimit}, siteCount, sites:[…]}.
	 */
	public static function get_org_sites() {
		$result = self::org_sites_request( 'GET', '/api/v1/org/sites', null, 15 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
	}

	/**
	 * Rename a site and/or set its display website URL (owner-only). `website_url`
	 * may be an empty string to CLEAR it (sent as null). At least one field is sent.
	 *
	 * @param string      $site_id     The connected_platforms.id.
	 * @param string|null $name        New friendly name, or null to leave unchanged.
	 * @param string|null $website_url New display URL (''=clear), or null to leave unchanged.
	 * @return array|WP_Error {success:true}.
	 */
	public static function update_site( $site_id, $name = null, $website_url = null ) {
		$site_id = sanitize_text_field( (string) $site_id );
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}

		$body = array();
		if ( null !== $name ) {
			$body['name'] = sanitize_text_field( (string) $name );
		}
		if ( null !== $website_url ) {
			$clean = trim( (string) $website_url );
			// Empty string clears the display URL (backend accepts null).
			$body['websiteUrl'] = '' === $clean ? null : esc_url_raw( $clean );
		}
		if ( empty( $body ) ) {
			return new WP_Error( 'invalid_input', 'Nothing to update.', array( 'status' => 400 ) );
		}

		return self::org_sites_request( 'PATCH', '/api/v1/org/sites/' . rawurlencode( $site_id ), $body, 15 );
	}

	/**
	 * Set or clear a per-site credit cap (owner-only, Business tier). A null/≤0 cap
	 * clears it. `period` is monthly (default) | total.
	 *
	 * @param string     $site_id The connected_platforms.id.
	 * @param float|null $cap     Cap amount, or null to clear.
	 * @param string     $period  'monthly' | 'total'.
	 * @return array|WP_Error {success:true, data:{siteId,cap,period}}.
	 */
	public static function set_site_cap( $site_id, $cap, $period = 'monthly' ) {
		$site_id = sanitize_text_field( (string) $site_id );
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}

		$period = ( 'total' === $period ) ? 'total' : 'monthly';
		$body   = array(
			'cap'    => ( null === $cap || '' === $cap ) ? null : (float) $cap,
			'period' => $period,
		);

		return self::org_sites_request( 'PUT', '/api/v1/org/sites/' . rawurlencode( $site_id ) . '/cap', $body, 15 );
	}

	/**
	 * Remove / revoke a site: revokes its keys and marks it inactive (owner-only).
	 *
	 * @param string $site_id The connected_platforms.id.
	 * @return array|WP_Error {success:true}.
	 */
	public static function remove_site( $site_id ) {
		$site_id = sanitize_text_field( (string) $site_id );
		if ( '' === $site_id ) {
			return new WP_Error( 'invalid_input', 'A site id is required.', array( 'status' => 400 ) );
		}
		return self::org_sites_request( 'DELETE', '/api/v1/org/sites/' . rawurlencode( $site_id ), null, 15 );
	}

	/**
	 * Pre-provision / register a new site under the org and issue its key ONCE
	 * (owner-only; enforces the plan site limit). The returned `apiKey` is plaintext
	 * shown exactly once - the caller surfaces it to the owner and never stores it.
	 *
	 * @param string $site_url  The new site's URL (identity + display seed).
	 * @param string $site_name Optional friendly name.
	 * @return array|WP_Error {siteId, keyId, apiKey, keyPrefix, keyLast4, scopes}.
	 */
	public static function provision_site( $site_url, $site_name = '' ) {
		$site_url = esc_url_raw( trim( (string) $site_url ) );
		if ( '' === $site_url ) {
			return new WP_Error( 'invalid_input', 'A site URL is required.', array( 'status' => 400 ) );
		}

		$body = array( 'siteUrl' => $site_url );
		$name = sanitize_text_field( (string) $site_name );
		if ( '' !== $name ) {
			$body['siteName'] = $name;
		}

		$result = self::org_sites_request( 'POST', '/api/v1/org/sites', $body, 15 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
	}

	/**
	 * Assemble non-secret connection metadata from a register/rotate response.
	 *
	 * @param array  $data     Backend `data` payload (siteId, keyPrefix, keyLast4, scopes).
	 * @param string $org_id   Organization id.
	 * @param array  $existing Prior metadata to preserve org name / connectedAt on rotate.
	 * @return array
	 */
	private static function build_connection_meta( array $data, $org_id, array $existing = array() ) {
		return array(
			'siteId'      => isset( $data['siteId'] ) ? sanitize_text_field( $data['siteId'] ) : ( isset( $existing['siteId'] ) ? $existing['siteId'] : '' ),
			'keyPrefix'   => isset( $data['keyPrefix'] ) ? sanitize_text_field( $data['keyPrefix'] ) : '',
			'keyLast4'    => isset( $data['keyLast4'] ) ? sanitize_text_field( $data['keyLast4'] ) : '',
			'scopes'      => isset( $data['scopes'] ) && is_array( $data['scopes'] ) ? array_map( 'sanitize_text_field', $data['scopes'] ) : ( isset( $existing['scopes'] ) ? $existing['scopes'] : array() ),
			'orgId'       => sanitize_text_field( $org_id ),
			'orgName'     => isset( $existing['orgName'] ) && '' !== $existing['orgName'] ? $existing['orgName'] : sanitize_text_field( $org_id ),
			'connectedAt' => isset( $existing['connectedAt'] ) && '' !== $existing['connectedAt'] ? $existing['connectedAt'] : current_time( 'mysql' ),
			// Development sites do not count toward the plan's site limit.
			'isDevelopment' => isset( $data['isDevelopment'] ) ? (bool) $data['isDevelopment'] : ! empty( $existing['isDevelopment'] ),
		);
	}
}
