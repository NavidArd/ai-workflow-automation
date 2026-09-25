<?php
/**
 * Legacy-version migration guard.
 *
 * Detects active copies of the old Lite/Pro plugins (which redeclare the same
 * classes with no guards) and silently deactivates them before they can cause
 * a "Cannot redeclare class" fatal. Never matches this plugin's own folder.
 *
 * @package WP_AI_Workflows
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * File-level redeclaration guard.
 *
 * `require_once` dedupes by resolved path, but two copies of this plugin live in
 * two different folders, so the same file resolves to two distinct paths and
 * would be executed twice in one request. Bail if the class already exists so a
 * bailing second copy cannot redeclare it.
 */
if ( class_exists( 'WP_AI_Workflows_Legacy_Guard', false ) ) {
	return;
}

/**
 * Detects and deactivates legacy copies of this plugin.
 */
class WP_AI_Workflows_Legacy_Guard {

	/**
	 * Exact folder slug of the old Lite plugin (also the new wp.org slug - see
	 * the self-exclusion in is_legacy_basename()).
	 */
	const LITE_SLUG = 'ai-workflow-automation-lite';

	/**
	 * Folder-name prefix of the old Pro plugin. Real-world installs carry a
	 * version suffix, e.g. `wp-ai-workflow-automation-pro-1.8.4`.
	 */
	const PRO_SLUG_PREFIX = 'wp-ai-workflow-automation-pro';

	/**
	 * Transient holding the list of just-deactivated legacy basenames so the
	 * notice renders once per deactivation event rather than forever.
	 */
	const NOTICE_TRANSIENT = 'wp_ai_workflows_legacy_deactivated';

	/**
	 * This plugin's own basename, e.g. `wp-ai-workflow-automation-pro/wp-ai-workflows.php`.
	 *
	 * @var string
	 */
	private static $self_basename = '';

	/**
	 * Guards against registering hooks more than once per copy.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Wire up the guard. Idempotent.
	 *
	 * @param string $self_basename plugin_basename( __FILE__ ) of the main plugin file.
	 */
	public static function init( $self_basename ) {
		if ( '' === self::$self_basename && is_string( $self_basename ) ) {
			self::$self_basename = $self_basename;
		}

		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Covers a legacy plugin activated AFTER this one, or an activation hook
		// that never ran (e.g. this copy bailed out during activation).
		add_action( 'admin_init', array( __CLASS__, 'scan_and_deactivate' ) );

		// One-shot notice after a legacy copy is deactivated.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_deactivation_notice' ) );
	}

	/**
	 * Register the "another copy is already active, so this one did not load"
	 * notice. Called only from the bootstrap's bail-out path.
	 */
	public static function register_duplicate_notice() {
		add_action( 'admin_notices', array( __CLASS__, 'render_duplicate_notice' ) );
	}

	/**
	 * The folder segment of this plugin's own basename.
	 *
	 * @return string e.g. `wp-ai-workflow-automation-pro`, or '' if unknown.
	 */
	private static function self_folder() {
		$base = self::$self_basename;
		if ( '' === $base ) {
			return '';
		}
		$pos = strpos( $base, '/' );
		return ( false === $pos ) ? $base : substr( $base, 0, $pos );
	}

	/**
	 * Does a plugin basename's FOLDER identify a legacy copy - and is it not us?
	 *
	 * @param string $basename e.g. `some-plugin/some-plugin.php`.
	 * @return bool
	 */
	public static function is_legacy_basename( $basename ) {
		if ( ! is_string( $basename ) || '' === $basename ) {
			return false;
		}

		// Single-file plugins (no folder) cannot be one of our folder-based
		// legacy plugins.
		$pos = strpos( $basename, '/' );
		if ( false === $pos ) {
			return false;
		}
		$folder = substr( $basename, 0, $pos );

		// SELF-EXCLUSION: never flag this plugin's own copy, whether it lives in
		// the `ai-workflow-automation-lite` or a `wp-ai-workflow-automation-pro*` folder.
		$self_folder = self::self_folder();
		if ( $basename === self::$self_basename ) {
			return false;
		}
		if ( '' !== $self_folder && $folder === $self_folder ) {
			return false;
		}

		// Old Lite: exact folder match.
		if ( self::LITE_SLUG === $folder ) {
			return true;
		}

		// Old Pro: folder starts with the Pro prefix (any version suffix).
		if ( 0 === strpos( $folder, self::PRO_SLUG_PREFIX ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Collect matching legacy basenames from the site (and, on multisite, the
	 * network) active-plugin lists.
	 *
	 * @return string[] Unique list of legacy plugin basenames currently active.
	 */
	private static function find_active_legacy_plugins() {
		$matches = array();

		$active = get_option( 'active_plugins', array() );
		if ( is_array( $active ) ) {
			foreach ( $active as $basename ) {
				if ( self::is_legacy_basename( $basename ) ) {
					$matches[] = $basename;
				}
			}
		}

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				// Network list is keyed by basename.
				foreach ( array_keys( $network ) as $basename ) {
					if ( self::is_legacy_basename( $basename ) ) {
						$matches[] = $basename;
					}
				}
			}
		}

		return array_values( array_unique( $matches ) );
	}

	/**
	 * Scan for active legacy copies and silently deactivate them.
	 *
	 * Runs on the activation hook and on admin_init.
	 */
	public static function scan_and_deactivate() {
		$legacy = self::find_active_legacy_plugins();
		if ( empty( $legacy ) ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// $silent = true -> do NOT fire the legacy plugins' deactivation hooks,
		// so their (unknown) cleanup routines never run.
		if ( is_multisite() ) {
			// Clear both network-wide and per-site activation records.
			deactivate_plugins( $legacy, true, true );
			deactivate_plugins( $legacy, true, false );
		} else {
			deactivate_plugins( $legacy, true );
		}

		set_transient( self::NOTICE_TRANSIENT, $legacy, HOUR_IN_SECONDS );
	}

	/**
	 * Render the post-deactivation notice at most once per event.
	 */
	public static function maybe_render_deactivation_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$deactivated = get_transient( self::NOTICE_TRANSIENT );
		if ( empty( $deactivated ) ) {
			return;
		}

		// Consume the flag so the notice shows once per deactivation event.
		delete_transient( self::NOTICE_TRANSIENT );

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html__(
			'AI Workflow Automation: an older version (Lite or Pro) was deactivated automatically. Your workflows and settings are intact. You can safely remove the old plugin folder from wp-content/plugins via FTP; avoid its Delete button on the Plugins screen.',
			'ai-workflow-automation-lite'
		);
		echo '</p></div>';
	}

	/**
	 * Render the "another copy is already active" notice used when this copy
	 * bails out of loading to avoid a redeclare fatal.
	 */
	public static function render_duplicate_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html__(
			'AI Workflow Automation: another copy of this plugin (an older Lite or Pro version) is already active, so this copy did not load. The older copy is being deactivated automatically. Your workflows and settings are intact.',
			'ai-workflow-automation-lite'
		);
		echo '</p></div>';
	}
}
