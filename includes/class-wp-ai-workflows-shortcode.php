<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages shortcode functionality for WP AI Workflows plugin.
 */
class WP_AI_Workflows_Shortcode {

	public function init() {
		add_shortcode( 'wp_ai_workflows_output', array( $this, 'output_shortcode' ) );
	}

	public function output_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'               => '',
				'loading'          => 'previous', // previous|spinner|skeleton|custom
				'loading_text'     => 'Processing your submission...',
				'session_type'     => 'auto', // auto|public|private|dedicated
				'session_id'       => '', // Allow manual session ID
				'refresh_interval' => 5000,
				'show_timestamp'   => 'false',
				'format'           => 'auto', // auto|text|html|json|markdown
				'theme'            => 'default', // default|dark|minimal
				'error_display'    => 'inline', // inline|hidden|modal
				'cache'            => 'true',
				'cache_duration'   => 3600,
				'enable_sse'       => 'true', // Enable Server-Sent Events
				'clear_on_refresh' => 'false', // Clear old results on page refresh
				'stale_after'      => 20, // Seconds after which data is considered stale
				'stop_on_success'  => 'true', // Stop polling after receiving new data
			),
			$atts,
			'wp_ai_workflows_output'
		);

		$workflow_id = $atts['id'];

		$session_id = $this->get_session_id( $atts );

		// Force refresh of assets by adding version timestamp
		$version = WP_AI_WORKFLOWS_PRO_VERSION . '.' . time();

		wp_enqueue_style(
			'wp-ai-workflows-shortcode',
			plugin_dir_url( __DIR__ ) . 'assets/css/shortcode-output.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'wp-ai-workflows-shortcode',
			plugin_dir_url( __DIR__ ) . 'assets/js/shortcode-output.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'wp-ai-workflows-shortcode',
			'wpAiWorkflowsShortcode',
			array(
				'workflowId' => $workflow_id,
				'sessionId'  => $session_id,
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_ai_workflows_shortcode_nonce' ),
				'apiRoot'    => esc_url_raw( rest_url() ),
				'config'     => array(
					'loading'         => $atts['loading'],
					'loadingText'     => $atts['loading_text'],
					'refreshInterval' => intval( $atts['refresh_interval'] ),
					'showTimestamp'   => $atts['show_timestamp'] === 'true',
					'format'          => $atts['format'],
					'theme'           => $atts['theme'],
					'errorDisplay'    => $atts['error_display'],
					'cache'           => $atts['cache'] === 'true',
					'cacheDuration'   => intval( $atts['cache_duration'] ),
					'enableSSE'       => $atts['enable_sse'] === 'true',
					'sessionType'     => $atts['session_type'],
					'clearOnRefresh'  => $atts['clear_on_refresh'] === 'true',
					'staleAfter'      => intval( $atts['stale_after'] ) * 1000, // Convert to milliseconds
					'stopOnSuccess'   => $atts['stop_on_success'] === 'true',
				),
			)
		);

		$theme_class = 'wp-ai-workflows-theme-' . esc_attr( $atts['theme'] );

		return sprintf(
			'<div id="wp-ai-workflows-output-%s" data-workflow-id="%s" class="wp-ai-workflows-container %s" data-session-type="%s"></div>',
			esc_attr( $workflow_id ),
			esc_attr( $workflow_id ),
			$theme_class,
			esc_attr( $atts['session_type'] )
		);
	}

	/**
	 * Enhanced session management based on session type
	 */
	private function get_session_id( $atts ) {
		switch ( $atts['session_type'] ) {
			case 'public':
				// All users share this session - just use 'public'
				return 'public';

			case 'private':
				// Logged in users get their own session, guests get cookie-based
				if ( is_user_logged_in() ) {
					return 'user_' . get_current_user_id() . '_' . $atts['id'];
				}
				return $this->get_cookie_session_id();

			case 'dedicated':
				// Each page load gets a unique session
				return wp_generate_uuid4();

			case 'manual':
				// Use provided session ID or fall back to cookie
				return ! empty( $atts['session_id'] ) ?
					sanitize_text_field( $atts['session_id'] ) :
					$this->get_cookie_session_id();

			default: // 'auto'
				return $this->get_cookie_session_id();
		}
	}

	/**
	 * Get or create cookie-based session ID
	 */
	private function get_cookie_session_id() {
		if ( ! isset( $_COOKIE['wp_ai_workflows_session_id'] ) ) {
			$session_id = wp_generate_uuid4();
			setcookie( 'wp_ai_workflows_session_id', $session_id, time() + ( 86400 * 30 ), '/', '', is_ssl(), true );
			return $session_id;
		}
		return sanitize_text_field( wp_unslash( $_COOKIE['wp_ai_workflows_session_id'] ) );
	}

	/**
	 * Get shortcode output via REST API
	 */
	public static function get_shortcode_output( $request ) {
		global $wpdb;
		$workflow_id = $request->get_param( 'workflow_id' );
		$session_id  = $request->get_param( 'session_id' );

		if ( empty( $workflow_id ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Invalid shortcode request',
				'error',
				array(
					'reason' => 'Missing workflow ID',
				)
			);
			return new WP_REST_Response( array( 'error' => 'Missing workflow ID' ), 400 );
		}

		$table_name = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';

		$output = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i 
            WHERE workflow_id = %s AND session_id = %s 
            ORDER BY created_at DESC 
            LIMIT 1",
				$table_name,
				$workflow_id,
				$session_id
			)
		);

		if ( $output ) {
			$created_timestamp = strtotime( $output->created_at );
			$updated_timestamp = strtotime( $output->updated_at );

			if ( $created_timestamp === false ) {
				$created_timestamp = time();
			}
			if ( $updated_timestamp === false ) {
				$updated_timestamp = $created_timestamp;
			}

			return new WP_REST_Response(
				array(
					'output'               => $output->output_data,
					'created_at'           => $output->created_at,
					'updated_at'           => $output->updated_at,
					'created_at_timestamp' => $created_timestamp,
					'updated_at_timestamp' => $updated_timestamp,
					'status'               => 'success',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'output' => null,
				'status' => 'no_data',
			),
			200
		);
	}

	/**
	 * Stream output updates via Server-Sent Events
	 * Uses timestamp-based change detection
	 */
	public static function stream_output( $request ) {
		$workflow_id = $request->get_param( 'workflow_id' );
		$session_id  = $request->get_param( 'session_id' );

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' ); // Disable Nginx buffering

		echo 'data: ' . json_encode( array( 'status' => 'connected' ) ) . "\n\n";
		ob_flush();
		flush();

		$last_timestamp = 0;
		$counter        = 0;

		while ( $counter < 300 ) { // 5 minutes max
			global $wpdb;
			$table_name = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';

			$output = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i 
                WHERE workflow_id = %s AND session_id = %s 
                ORDER BY created_at DESC 
                LIMIT 1",
					$table_name,
					$workflow_id,
					$session_id
				)
			);

			if ( $output ) {
				$current_timestamp = strtotime( $output->created_at );

				if ( $current_timestamp > $last_timestamp ) {
					$updated_timestamp = strtotime( $output->updated_at );

					echo 'data: ' . json_encode(
						array(
							'output'               => $output->output_data,
							'created_at'           => $output->created_at,
							'updated_at'           => $output->updated_at,
							'created_at_timestamp' => $current_timestamp,
							'updated_at_timestamp' => $updated_timestamp,
							'status'               => 'update',
						)
					) . "\n\n";
					ob_flush();
					flush();
					$last_timestamp = $current_timestamp;
				}
			}

			sleep( 1 );
			++$counter;
		}

		exit;
	}
}
