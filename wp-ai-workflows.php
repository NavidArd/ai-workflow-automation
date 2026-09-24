<?php
/*
 * Plugin Name: AI Workflow Automation
 * Plugin URI: https://wpaiworkflowautomation.com
 * Description: A WordPress plugin for building complex AI-powered workflows and AI agents with a visual interface.
 * Version: 2.0.8
 * Requires at least: 6.2.0
 * Requires PHP: 8.0.0
 * Author: Massive Shift
 * Author URI: https://wpaiworkflowautomation.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-workflows
 * Domain Path: /languages
*/

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Firebase JWT class aliases. Declared here at file scope - OUTSIDE the
// compile-safety wrapper further down - because `use` imports may not appear
// inside a conditional block.
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/*
 * Duplicate-copy / legacy-clash guard - must run before any class include.
 *
 * v2.0 replaces two legacy plugins that declare the same WP_AI_Workflows_*
 * classes/functions with no redeclaration guards. If a legacy copy - or a
 * second copy of this plugin - is already loaded this request, requiring our
 * classes would fatal with "Cannot redeclare class/function". WP_AI_WORKFLOWS_LOADED,
 * function_exists( 'wp_ai_workflows_handle_error' ), and class_exists( 'WP_AI_Workflows_Utilities' )
 * detect that before this file declares anything of its own; on a hit we bail
 * out silently and hand off to the legacy guard, which deactivates the stale copy.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-ai-workflows-legacy-guard.php';

if (
	defined( 'WP_AI_WORKFLOWS_LOADED' )
	|| function_exists( 'wp_ai_workflows_handle_error' )
	|| class_exists( 'WP_AI_Workflows_Utilities', false )
) {
	WP_AI_Workflows_Legacy_Guard::init( plugin_basename( __FILE__ ) );
	WP_AI_Workflows_Legacy_Guard::register_duplicate_notice();
	return;
}
define( 'WP_AI_WORKFLOWS_LOADED', __FILE__ );

/*
 * Compile-safety wrapper: PHP early-binds unconditional top-level function
 * declarations at compile time, before the bail-out above can run. A legacy
 * copy declares the same wp_ai_workflows_* functions, so wrapping this whole
 * body in a condition defers binding to runtime and avoids a "Cannot
 * redeclare function" fatal when a legacy copy is already loaded.
 */
if ( ! function_exists( 'wp_ai_workflows_handle_error' ) ) {

// Custom error handler for initialization
function wp_ai_workflows_handle_error( $errno, $errstr, $errfile, $errline ) {
	if ( ! ( error_reporting() & $errno ) ) {
		return false;
	}
	error_log( sprintf( 'WP AI Workflows Error: %s in %s on line %d', $errstr, $errfile, $errline ) );
	return true;
}
set_error_handler( 'wp_ai_workflows_handle_error' );

// Firebase JWT autoloader
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'Firebase\\JWT\\';
		$base_dir = __DIR__ . '/vendor/firebase/php-jwt/src/';

		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);


define( 'WP_AI_WORKFLOWS_PRO_VERSION', '2.0.8' );
define( 'WP_AI_WORKFLOWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_WORKFLOWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_WORKFLOWS_PLUGIN_FILE', __FILE__ );
define( 'WP_AI_WORKFLOWS_DEBUG', false );
define( 'WP_AI_WORKFLOWS_PRO_BASENAME', plugin_basename( __FILE__ ) );

// Wire the legacy guard for the copy that won the request: registers the
// admin_init scan that deactivates any legacy Lite/Pro copy activated later.
WP_AI_Workflows_Legacy_Guard::init( WP_AI_WORKFLOWS_PRO_BASENAME );
define( 'WP_AI_WORKFLOWS_DB_VERSION_OPTION', 'wp_ai_workflows_db_version' );
define( 'WP_AI_WORKFLOWS_DB_CHARSET', 'utf8mb4' );
define( 'WP_AI_WORKFLOWS_DB_COLLATE', 'utf8mb4_unicode_ci' );


if ( ! defined( 'WPAW_PLATFORM_URL' ) ) {
	define( 'WPAW_PLATFORM_URL', 'https://api.wpaiworkflowautomation.com' );
}

$required_files = array(
	'utilities',
	'database',
	'workflow',
	'workflow-dbal',
	'node-execution',
	'post-fields',
	'node-catalog',
	'workflow-validator',
	'platform-client',
	'ai-router',
	'workflow-translator',
	'platform-callback',
	'rest-api',
	'shortcode',
	'license',
	'license-security',
	'updater',
	'wporg-migration',
	'human-tasks',
	'firecrawl',
	'parser',
	'encryption',
	'google-service',
	'model-catalog',
	'generator',
	'chat-session',
	'chat-uploads',
	'chat-memory',
	'chat-handler',
	'chat-embedder',
	'analytics-collector',
	'cost-management',
	'assistant-chat',
	'vector-store',
	'viewer',
	'whitelabel',
	'multimedia-generator',
	'mcp-client',
	'chat-preview',
	'abilities',
	'knowledge-base',
	'agent-tool',
	'agent-tool-registry',
	'agent-tools',
	'agent-content-tools',
	'agent-orchestrator',
	'agent-approvals',
	'agent-credit-meter',
	'handoff-provider',
	'handoff-store',
	'handoff-native',
	'handoff-chatwoot',
	'handoff-zendesk',
	'handoff-intercom',
	'handoff-manager',
);

foreach ( $required_files as $file ) {
	require_once WP_AI_WORKFLOWS_PLUGIN_DIR . "includes/class-wp-ai-workflows-{$file}.php";
}
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'admin/class-wp-ai-workflows-admin.php';


/**
 * Plugin activation
 */
function activate_wp_ai_workflows() {
	ob_start();

	$lock_key = 'wp_ai_workflows_activating';
	if ( get_transient( $lock_key ) ) {
		WP_AI_Workflows_Utilities::debug_log( 'Activation already in progress', 'warning' );
		ob_end_clean();
		return;
	}
	set_transient( $lock_key, true, 30 );

	try {
		WP_AI_Workflows_Utilities::debug_log(
			'Starting plugin activation',
			'info',
			array(
				'php_version'        => PHP_VERSION,
				'wp_version'         => get_bloginfo( 'version' ),
				'plugin_version'     => WP_AI_WORKFLOWS_PRO_VERSION,
				'memory_limit'       => ini_get( 'memory_limit' ),
				'max_execution_time' => ini_get( 'max_execution_time' ),
				'active_plugins'     => get_option( 'active_plugins' ),
				'is_multisite'       => is_multisite(),
				'db_version'         => get_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION ),
			)
		);

		WP_AI_Workflows_Utilities::debug_log(
			'Caching configuration',
			'info',
			array(
				'wp_cache'          => defined( 'WP_CACHE' ) && WP_CACHE,
				'cache_plugins'     => array_filter(
					get_option( 'active_plugins' ),
					function ( $plugin ) {
						return strpos( $plugin, 'cache' ) !== false;
					}
				),
				'permalinks'        => get_option( 'permalink_structure' ),
				'rest_enabled'      => get_option( 'permalink_structure' ) !== '',
				'htaccess_writable' => is_writable( ABSPATH . '.htaccess' ),
				'rest_base'         => rest_get_url_prefix(),
			)
		);

		// Test for potential reverse proxy/CDN issues
		WP_AI_Workflows_Utilities::debug_log(
			'Server configuration',
			'info',
			array(
				'server_software' => 'redacted', // Removed for security
				'is_ssl'          => is_ssl(),
				'proxy_headers'   => array_filter(
					$_SERVER,
					function ( $key ) {
						return strpos( $key, 'HTTP_X_' ) === 0 ||
							strpos( $key, 'HTTP_CF_' ) === 0 ||
							strpos( $key, 'HTTP_CLOUDFRONT_' ) === 0;
					},
					ARRAY_FILTER_USE_KEY
				),
			)
		);

		global $wpdb;
		WP_AI_Workflows_Utilities::debug_log(
			'Database pre-activation state',
			'info',
			array(
				'db_prefix'       => $wpdb->prefix,
				'db_charset'      => $wpdb->charset,
				'db_collate'      => $wpdb->collate,
				'existing_tables' => $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'wp_ai_workflows%' ) ),
				'mysql_version'   => $wpdb->db_version(),
			)
		);

		// Deactivate any active legacy Lite/Pro copy up front so it cannot clash
		// with v2.0 on subsequent loads (mirrors the admin_init scan).
		WP_AI_Workflows_Legacy_Guard::scan_and_deactivate();

		WP_AI_Workflows_Database::create_tables();
		WP_AI_Workflows_Utilities::generate_and_encrypt_api_key();
		wp_ai_workflows_schedule_cleanup_cron();
		delete_option( 'wp_ai_workflows_human_tasks_db_version' );
		update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );
		wp_ai_workflows_setup_files();

		WP_AI_Workflows_Cost_Management::get_instance()->initialize_cost_settings();

		// Usage analytics are OPT-IN and OFF by default: do NOT enable telemetry on
		// activation. Nothing is sent unless the site owner explicitly turns on the
		// `wp_ai_workflows_analytics_opt_in` option in Settings. Clear any stale
		// telemetry cron left behind by a previous (opt-out era) install.
		if ( ! get_option( 'wp_ai_workflows_analytics_opt_in', false ) ) {
			wp_clear_scheduled_hook( 'wp_ai_workflows_daily_analytics' );
		}

		if ( ! wp_next_scheduled( 'wp_ai_workflows_cleanup_chat_data' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_ai_workflows_cleanup_chat_data' );
		}

		if ( ! wp_next_scheduled( 'wp_ai_workflows_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_ai_workflows_daily_maintenance' );
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_workflow_tasks' );
		}

		if ( ! get_option( 'wp_ai_workflows_task_roles' ) ) {
			update_option( 'wp_ai_workflows_task_roles', array( 'administrator' ) );
		}

		add_option( 'wp_ai_workflows_setup_completed', false );

		update_option( WP_AI_Workflows_Admin::OPTION_WELCOME_NOTICE, 1, false );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only, on WordPress's own bulk-activation request.
		$bulk_activation = isset( $_GET['activate-multi'] );
		if ( ! $bulk_activation && ! is_network_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			set_transient( 'wp_ai_workflows_activation_redirect', 1, 60 );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Database post-activation state',
			'info',
			array(
				'tables_after' => $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'wp_ai_workflows%' ) ),
			)
		);

		WP_AI_Workflows_Utilities::debug_log( 'Plugin activation completed successfully', 'info' );

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows activation output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		WP_AI_Workflows_Utilities::debug_log(
			'Activation failed',
			'error',
			array(
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			)
		);
		error_log( 'WP AI Workflows activation error: ' . $e->getMessage() );
		throw $e;
	} finally {
		delete_transient( $lock_key );
	}
}

function wp_ai_workflows_migrate_license_data() {
	if ( get_option( 'wp_ai_workflows_license_migrated', false ) ) {
		return;
	}

	WP_AI_Workflows_License_Security::maybe_migrate_license_data();

	update_option( 'wp_ai_workflows_license_migrated', true );

	WP_AI_Workflows_Utilities::debug_log( 'License data migration attempted during update', 'info' );
}

/**
 * Plugin deactivation
 */
function deactivate_wp_ai_workflows() {
	ob_start();
	try {
		set_transient( 'wp_ai_workflows_maintenance_mode', true, 300 );

		wp_clear_scheduled_hook( 'wp_ai_workflows_cleanup' );
		wp_clear_scheduled_hook( 'wp_ai_workflows_cleanup_chat_data' );
		wp_clear_scheduled_hook( 'wp_ai_workflows_check_license' );
		wp_clear_scheduled_hook( 'wp_ai_workflows_daily_maintenance' );
		wp_clear_scheduled_hook( 'wp_ai_workflows_license_verify' );

		global $wpdb;

		// Pattern for temporary license cache entries
		$pattern = '^wp_ai_workflows_lic_[a-f0-9]{8}$';

		$cache_count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} 
                 WHERE option_name REGEXP %s",
				$pattern
			)
		);

		if ( $cache_count > 0 ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Database optimization completed',
				'info',
				array(
					'entries_processed' => $cache_count,
				)
			);
		}

		WP_AI_Workflows_Utilities::debug_log( 'Plugin deactivated successfully' );

		delete_transient( 'wp_ai_workflows_maintenance_mode' );

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows deactivation output: ' . $output );
		}
	} catch ( Exception $e ) {
		delete_transient( 'wp_ai_workflows_maintenance_mode' );
		ob_end_clean();
		error_log( 'WP AI Workflows deactivation error: ' . $e->getMessage() );
	}
}

/**
 * Send a freshly activated site straight to the plugin instead of leaving the
 * user to find it in the sidebar. Fires once, on the next admin page load.
 */
function wp_ai_workflows_activation_redirect() {
	if ( ! get_transient( 'wp_ai_workflows_activation_redirect' ) ) {
		return;
	}

	delete_transient( 'wp_ai_workflows_activation_redirect' );

	if ( wp_doing_ajax() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only, on WordPress's own bulk-activation request.
	if ( isset( $_GET['activate-multi'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-workflows' ) );
	exit;
}
add_action( 'admin_init', 'wp_ai_workflows_activation_redirect' );

register_activation_hook( __FILE__, 'activate_wp_ai_workflows' );
register_activation_hook( __FILE__, 'wp_ai_workflows_migrate_license_data' );
register_deactivation_hook( __FILE__, 'deactivate_wp_ai_workflows' );

/**
 * Increase execution time for plugin operations
 */
function wp_ai_workflows_increase_execution_time() {
	$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );
	if ( $action && strpos( $action, 'wp-ai-workflows' ) !== false ) {
		ini_set( 'max_execution_time', 300 );
		set_time_limit( 300 );
	}
}
add_action( 'init', 'wp_ai_workflows_increase_execution_time' );

/**
 * Main plugin initialization
 */
function run_wp_ai_workflows() {
	ob_start();
	try {
		$license = new WP_AI_Workflows_License();

		WP_AI_Workflows_License_Security::init();

		$components = array(
			new WP_AI_Workflows_REST_API(),
			new WP_AI_Workflows_Admin(),
			new WP_AI_Workflows_Database(),
			new WP_AI_Workflows_Analytics_Collector( WP_AI_WORKFLOWS_PRO_VERSION, true ),
		);

		foreach ( $components as $component ) {
			$component->init();
		}

		WP_AI_Workflows_Encryption::init();
		WP_AI_Workflows_Platform_Client::init();

		// Phase 3 (R6.1/R6.5): free-first. The plugin ALWAYS initializes full
		// functionality - the SLM license no longer gates the plugin, and no
		// license phone-home runs on load (wp.org Guideline 6). Premium/Cloud
		// features gate on platform connection state, not a license. The license
		// classes remain as a dormant fallback but are not consulted here.
		WP_AI_Workflows_Updater::get_instance();
		WP_AI_Workflows_WPOrg_Migration::get_instance();
		initialize_full_functionality( $license );

		$is_connected = WP_AI_Workflows_Platform_Client::is_connected();
		add_filter(
			'wp_ai_workflows_frontend_settings',
			function ( $settings ) use ( $is_connected ) {
				// Kept key name for frontend back-compat; now reflects connection state.
				$settings['license_active']       = $is_connected;
				$settings['platform_connected']   = $is_connected;
				return $settings;
			}
		);

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows initialization output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		error_log( 'WP AI Workflows initialization error: ' . $e->getMessage() );
		add_action(
			'admin_notices',
			function () use ( $e ) {
				echo '<div class="error"><p>' . esc_html( 'WP AI Workflows encountered an error: ' . $e->getMessage() ) . '</p></div>';
			}
		);
	}
}

/**
 * Initialize full plugin functionality
 */
function initialize_full_functionality( $license ) {
	ob_start();
	try {
		$components = array(
			new WP_AI_Workflows_Workflow(),
			new WP_AI_Workflows_Shortcode(),
			new WP_AI_Workflows_Node_Execution(),
			new WP_AI_Workflows_Utilities(),
			new WP_AI_Workflows_Chat_Embedder(),
			new WP_AI_Workflows_Assistant_Chat(),
			new WP_AI_Workflows_Viewer(),
			new WP_AI_Workflows_Whitelabel(),
			new WP_AI_Workflows_Multimedia_Generator(),
			new WP_AI_Workflows_MCP_Client(),
			new WP_AI_Workflows_Chat_Preview(),
			new WP_AI_Workflows_Abilities(),

		);

		foreach ( $components as $component ) {
			$component->init();
		}

		// Construct the handler so its version-gated schema self-heal
		// (maybe_update_db_structure) runs at most once per plugin version.
		// The previous unconditional check_and_add_missing_columns() call ran a
		// SHOW COLUMNS query on every request, flooding debug.log.
		new WP_AI_Workflows_Human_Tasks();

		new WP_AI_Workflows_Firecrawl();
		new WP_AI_Workflows_Parser();
		new WP_AI_Workflows_Generator();
		new WP_AI_Workflows_Vector_Store();

		// Phase 3 (R6.5): no license nag - the plugin is free. $license is retained
		// in the signature only for backward compatibility with the dormant classes.
		unset( $license );

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows full initialization output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		error_log( 'WP AI Workflows full initialization error: ' . $e->getMessage() );
		throw $e;
	}
}

/**
 * Schedule daily maintenance cron job
 */
function wp_ai_workflows_schedule_daily_maintenance() {
	if ( ! wp_next_scheduled( 'wp_ai_workflows_daily_maintenance' ) ) {
		wp_schedule_event( time(), 'daily', 'wp_ai_workflows_daily_maintenance' );
	}
}

add_action( 'wp', 'wp_ai_workflows_schedule_daily_maintenance' );

/**
 * Schedule cleanup cron job
 */
function wp_ai_workflows_schedule_cleanup_cron() {
	if ( ! wp_next_scheduled( 'wp_ai_workflows_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'wp_ai_workflows_cleanup' );
	}
}

/**
 * Schedule license check cron job
 */
function wp_ai_workflows_schedule_license_check() {
	if ( ! wp_next_scheduled( 'wp_ai_workflows_check_license' ) ) {
		wp_schedule_event( time(), 'daily', 'wp_ai_workflows_check_license' );
	}
}

add_action( 'wp', 'wp_ai_workflows_schedule_cleanup_cron' );
add_action( 'wp', 'wp_ai_workflows_schedule_license_check' );
add_action( 'wp_ai_workflows_send_delayed_email', array( 'WP_AI_Workflows_Node_Execution', 'send_email' ), 10, 2 );
add_action(
	'init',
	function () {
		WP_AI_Workflows_Chat_Embedder::get_instance()->init();
	}
);
add_action( 'wp_ai_workflows_cleanup_chat_data', array( 'WP_AI_Workflows_Database', 'cleanup_old_chat_data' ) );
add_action( 'wp_ai_workflows_cleanup_assistant_chat', array( 'WP_AI_Workflows_Assistant_Setup', 'cleanup_old_data' ) );
add_action( 'wp_ai_workflows_daily_maintenance', array( 'WP_AI_Workflows_Assistant_Chat', 'cleanup_old_data' ) );
add_action(
	'wp_ai_workflows_daily_maintenance',
	function () {
		set_transient( 'wp_ai_workflows_maintenance_mode', true, 300 );

		global $wpdb;

		$optimization_threshold = 10;
		$pattern                = '^wp_ai_workflows_lic_[a-f0-9]{8}$';

		$transient_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} 
                 WHERE option_name REGEXP %s",
				$pattern
			)
		);

		if ( $transient_count > $optimization_threshold ) {
			// Keep recent entries, remove old ones
			$limit_count = max( 0, intval( $transient_count ) - 5 );
			$entries = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name REGEXP %s
                     ORDER BY option_id ASC
                     LIMIT %d",
					$pattern,
					$limit_count
				)
			);

			foreach ( $entries as $entry ) {
				delete_option( $entry );
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Database optimization completed',
				'info',
				array(
					'optimized_entries' => count( $entries ),
				)
			);
		}

		delete_transient( 'wp_ai_workflows_maintenance_mode' );
	}
);

/**
 * Sites that predate the setup wizard still carry `setup_completed = 0`, so an
 * update greeted them with a first-run wizard they had no business seeing. Mark
 * it done once for any install that shows prior use (a stored API key or an
 * existing workflow). Runs at most once per site.
 */
function wp_ai_workflows_backfill_setup_completed() {
	if ( get_option( 'wp_ai_workflows_setup_backfilled' ) ) {
		return;
	}

	update_option( 'wp_ai_workflows_setup_backfilled', 1 );

	if ( get_option( 'wp_ai_workflows_setup_completed' ) ) {
		return;
	}

	$settings = get_option( 'wp_ai_workflows_settings', array() );
	$api_keys = array(
		'openai_api_key',
		'perplexity_api_key',
		'openrouter_api_key',
		'fal_api_key',
		'firecrawl_api_key',
		'llamaparse_api_key',
		'unsplash_api_key',
	);

	$configured = false;
	if ( is_array( $settings ) ) {
		foreach ( $api_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$configured = true;
				break;
			}
		}
	}

	if ( ! $configured ) {
		$workflows  = get_option( 'wp_ai_workflows', array() );
		$configured = is_array( $workflows ) && ! empty( $workflows );
	}

	if ( $configured ) {
		update_option( 'wp_ai_workflows_setup_completed', 1 );
	}
}

/**
 * Plugin update check
 */
function wp_ai_workflows_update_check() {
	ob_start();
	try {
		$current_version    = get_option( 'wp_ai_workflows_pro_version', '0' );
		$current_db_version = get_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, '0' );

		if ( version_compare( $current_version, WP_AI_WORKFLOWS_PRO_VERSION, '<' ) ) {
			wp_ai_workflows_migrate_license_data();
			if ( $current_version === '0' ) {
				activate_wp_ai_workflows();
			}
			update_option( 'wp_ai_workflows_pro_version', WP_AI_WORKFLOWS_PRO_VERSION );
		}

		WP_AI_Workflows_Database::update_database_schema();

		wp_ai_workflows_backfill_setup_completed();

		$cost_settings = WP_AI_Workflows_Cost_Management::get_instance()->get_cost_settings();
		if ( empty( $cost_settings ) ) {
			WP_AI_Workflows_Cost_Management::get_instance()->initialize_cost_settings();
		}

		if ( version_compare( $current_db_version, WP_AI_WORKFLOWS_PRO_VERSION, '<' ) ) {
			update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );
			WP_AI_Workflows_Utilities::debug_log(
				'Database updated',
				'info',
				array(
					'from_version' => $current_db_version,
					'to_version'   => WP_AI_WORKFLOWS_PRO_VERSION,
				)
			);
		}
	} catch ( Exception $e ) {
		WP_AI_Workflows_Utilities::debug_log(
			'Update check failed',
			'error',
			array(
				'error' => $e->getMessage(),
			)
		);
		error_log( 'WP AI Workflows update check error: ' . $e->getMessage() );
	}
}
add_action( 'plugins_loaded', 'wp_ai_workflows_update_check' );

/**
 * Check license status
 */
function wp_ai_workflows_check_license() {
	ob_start();
	try {
		$license = new WP_AI_Workflows_License();
		$license->check_license();

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows license check output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		error_log( 'WP AI Workflows license check error: ' . $e->getMessage() );
	}
}
add_action( 'wp_ai_workflows_check_license', 'wp_ai_workflows_check_license' );

/**
 * Plugin activation/update handler
 */
function wp_ai_workflows_activate_or_update() {
	ob_start();
	try {
		WP_AI_Workflows_Database::create_tables();
		WP_AI_Workflows_Database::update_database_schema();
		update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );

		$human_tasks = new WP_AI_Workflows_Human_Tasks();
		$human_tasks->check_and_add_missing_columns();

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows activate/update output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		error_log( 'WP AI Workflows activate/update error: ' . $e->getMessage() );
		throw $e;
	}
}

register_activation_hook( __FILE__, 'wp_ai_workflows_activate_or_update' );

add_action(
	'plugins_loaded',
	function () {
		ob_start();
		try {
			$current_version    = get_option( 'wp_ai_workflows_version' );
			$current_db_version = get_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION );

			if ( $current_version !== WP_AI_WORKFLOWS_PRO_VERSION ||
			$current_db_version !== WP_AI_WORKFLOWS_PRO_VERSION ) {

				delete_option( 'wp_ai_workflows_human_tasks_db_version' );
				wp_ai_workflows_activate_or_update();

				update_option( 'wp_ai_workflows_version', WP_AI_WORKFLOWS_PRO_VERSION );
				update_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, WP_AI_WORKFLOWS_PRO_VERSION );

				WP_AI_Workflows_Utilities::debug_log(
					'Plugin and database updated',
					'info',
					array(
						'old_version'    => $current_version,
						'old_db_version' => $current_db_version,
						'new_version'    => WP_AI_WORKFLOWS_PRO_VERSION,
					)
				);
			}

			$output = ob_get_clean();
			if ( ! empty( $output ) ) {
				error_log( 'WP AI Workflows version check output: ' . $output );
			}
		} catch ( Exception $e ) {
			ob_end_clean();
			error_log( 'WP AI Workflows version check error: ' . $e->getMessage() );
		}
	}
);

/**
 * Initialize charset settings
 */
function wp_ai_workflows_init_charset() {
	add_filter(
		'rest_pre_serve_request',
		function ( $served, $result ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			return $served;
		},
		10,
		2
	);
}
add_action( 'init', 'wp_ai_workflows_init_charset' );

/**
 * Set up plugin files and directories
 */
function wp_ai_workflows_setup_files() {
	ob_start();
	try {
		$directories = array(
			'includes/prompts',
			'includes/templates',
		);

		foreach ( $directories as $dir ) {
			$path = WP_AI_WORKFLOWS_PLUGIN_DIR . $dir;
			if ( ! file_exists( $path ) ) {
				if ( ! mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
					throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $path ) );
				}
			}
		}

		$template_file      = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/system_prompt.xml';
		$prompt_file        = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/system_prompt.xml';
		$assistant_template = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/assistant_system_prompt.xml';
		$assistant_prompt   = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/assistant_system_prompt.xml';

		if ( ! file_exists( $template_file ) ) {
			$default_template = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/data/system_prompt.xml';
			if ( ! copy( $default_template, $template_file ) ) {
				throw new \RuntimeException( 'Failed to copy system prompt template' );
			}
		}

		if ( ! file_exists( $assistant_prompt ) && file_exists( $assistant_template ) ) {
			copy( $assistant_template, $assistant_prompt );
		}

		if ( ! file_exists( $prompt_file ) ) {
			if ( ! copy( $template_file, $prompt_file ) ) {
				throw new \RuntimeException( 'Failed to copy system prompt file' );
			}
		}

		$output = ob_get_clean();
		if ( ! empty( $output ) ) {
			error_log( 'WP AI Workflows file setup output: ' . $output );
		}
	} catch ( Exception $e ) {
		ob_end_clean();
		error_log( 'WP AI Workflows file setup error: ' . $e->getMessage() );
		throw $e;
	}
}

run_wp_ai_workflows();

restore_error_handler();

} // end COMPILE-SAFETY WRAPPER: if ( ! function_exists( 'wp_ai_workflows_handle_error' ) )
