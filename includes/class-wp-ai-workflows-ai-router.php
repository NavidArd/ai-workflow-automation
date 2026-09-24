<?php
/**
 * WP_AI_Workflows_AI_Router - decides, per AI call, whether the request routes
 * through the customer's own provider key (BYOK, local) or through the metered
 * platform credits proxy (keyless).
 *
 * LOCKED precedence (workflow-level source of truth - the per-node `keySource`
 * selector was retired; the workflow's Local/Cloud execution mode is now the
 * single control):
 *   NOT connected                 =>  ALWAYS 'byok' (short-circuits everything).
 *   Workflow mode 'cloud'         =>  'credits' (metered proxy, our keys).
 *   Workflow mode 'local'         =>  'byok'    (bring-your-own keys).
 *   No workflow context (e.g. a   =>  global `prefer_credits_when_connected`
 *   standalone chat run)              setting: 'credits' when on, else 'byok'.
 *
 * This resolver is the ONLY place the route is decided. The 402/502 failure paths
 * NEVER fall back to BYOK (locked Q4) - see WP_AI_Workflows_Node_Execution.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_AI_Router {

	const ROUTE_BYOK    = 'byok';
	const ROUTE_CREDITS = 'credits';

	const MODE_LOCAL = 'local';
	const MODE_CLOUD = 'cloud';

	/** Global setting key stored inside the `wp_ai_workflows_settings` option. */
	const PREF_KEY = 'prefer_credits_when_connected';

	/**
	 * Execution mode of the workflow currently running on this request, set by
	 * WP_AI_Workflows_Workflow::execute_workflow(). Null when no board workflow is
	 * driving the call (e.g. a standalone chat exchange).
	 *
	 * @var string|null
	 */
	private static $execution_mode = null;

	/**
	 * Record the execution mode of the workflow about to run so every AI call it
	 * makes resolves consistently. Anything other than 'cloud' is treated as local.
	 *
	 * @param string|null $mode 'cloud' | 'local'
	 * @return void
	 */
	public static function set_execution_mode( $mode ) {
		if ( null === $mode ) {
			self::$execution_mode = null;
			return;
		}
		self::$execution_mode = ( self::MODE_CLOUD === $mode ) ? self::MODE_CLOUD : self::MODE_LOCAL;
	}

	/**
	 * Clear the recorded execution mode (call after a workflow run settles).
	 *
	 * @return void
	 */
	public static function reset_execution_mode() {
		self::$execution_mode = null;
	}

	/**
	 * The execution mode recorded for the current run, or null when none is set.
	 *
	 * @return string|null
	 */
	public static function get_execution_mode() {
		return self::$execution_mode;
	}

	/**
	 * Resolve the route for a single AI call.
	 *
	 * The `$node_data` argument is accepted for call-site compatibility but is no
	 * longer consulted for a per-node `keySource` (that selector was removed); the
	 * workflow execution mode decides.
	 *
	 * @param array $node_data The node's `data` array (unused for routing now).
	 * @return string self::ROUTE_BYOK | self::ROUTE_CREDITS
	 */
	public static function resolve( $node_data = array() ) {
		// Disconnected sites can never meter - BYOK is the only option (free-first).
		if ( ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return self::ROUTE_BYOK;
		}

		// Workflow execution mode is the single decider.
		if ( self::MODE_CLOUD === self::$execution_mode ) {
			return self::ROUTE_CREDITS;
		}
		if ( self::MODE_LOCAL === self::$execution_mode ) {
			return self::ROUTE_BYOK;
		}

		// No workflow context (e.g. a standalone chat run) - fall back to the
		// global preference so credit-metered chat keeps working.
		return self::prefer_credits() ? self::ROUTE_CREDITS : self::ROUTE_BYOK;
	}

	/**
	 * Whether the global "prefer credits when connected" setting is enabled.
	 *
	 * @return bool
	 */
	public static function prefer_credits() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		return is_array( $settings ) && ! empty( $settings[ self::PREF_KEY ] );
	}

	/**
	 * Convenience predicate for call sites.
	 *
	 * @param array $node_data
	 * @return bool
	 */
	public static function routes_through_credits( $node_data = array() ) {
		return self::ROUTE_CREDITS === self::resolve( $node_data );
	}
}
