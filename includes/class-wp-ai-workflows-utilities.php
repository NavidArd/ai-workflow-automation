<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



class WP_AI_Workflows_Utilities {

	public function init() {
	}

	const LOG_LEVEL_DEBUG   = 0;
	const LOG_LEVEL_INFO    = 1;
	const LOG_LEVEL_WARNING = 2;
	const LOG_LEVEL_ERROR   = 3;

	/**
	 * Floor for a reasoning model's output-token budget. gpt-5, o1, o3, and o4
	 * spend part of `max_completion_tokens` on hidden reasoning tokens before writing
	 * the visible answer; a small user-configured cap can be consumed entirely
	 * by reasoning, leaving an empty `content` (finish_reason: "length"). This
	 * mirrors CLOUD_MIN_REASONING_TOKENS in the platform's ai-proxy.service.ts.
	 */
	const MIN_REASONING_COMPLETION_TOKENS = 2000;

	/**
	 * Safe, widely-available fallback model used when a BYOK provider rejects
	 * the requested model outright (model_not_found / invalid_model / an
	 * unsupported-parameter 400 that is really "this model can't be called the
	 * way we called it"). Mirrors CLOUD_FALLBACK_AI_MODEL on the platform.
	 */
	const FALLBACK_AI_MODEL = 'gpt-4o-mini';

	private static $log_level = self::LOG_LEVEL_INFO;

	public static function set_log_level( $level ) {
		self::$log_level = $level;
	}

	public static function debug_log( $message, $type = 'info', $context = array() ) {
		if ( ! WP_AI_WORKFLOWS_DEBUG ) {
			return;
		}

		$log_file       = WP_CONTENT_DIR . '/wp-ai-workflows-debug.log';
		$timestamp      = current_time( 'mysql' );
		$context_string = ! empty( $context ) ? wp_json_encode( $context ) : '';
		$log_entry      = "[{$timestamp}] [{$type}] {$message} {$context_string}\n";

		self::write_log( $log_file, $log_entry );
	}

	private static function write_log( $log_file, $log_entry ) {
		$max_size = 5 * 1024 * 1024;

		if ( file_exists( $log_file ) && filesize( $log_file ) > $max_size ) {
			$fs = self::get_wp_filesystem();
			if ( $fs ) {
				$old_content = $fs->get_contents( $log_file );
				$new_content = substr( $old_content, strlen( $old_content ) / 2 ) . $log_entry;
				$fs->put_contents( $log_file, $new_content, FS_CHMOD_FILE );
			} else {
				error_log( $log_entry, 3, $log_file );
			}
		} else {
			error_log( $log_entry, 3, $log_file );
		}
	}

	private static function get_wp_filesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	private static function get_log_level_from_type( $type ) {
		switch ( $type ) {
			case 'debug':
				return self::LOG_LEVEL_DEBUG;
			case 'info':
				return self::LOG_LEVEL_INFO;
			case 'warning':
				return self::LOG_LEVEL_WARNING;
			case 'error':
				return self::LOG_LEVEL_ERROR;
			default:
				return self::LOG_LEVEL_INFO;
		}
	}


	public static function debug_function( $function_name, $params = array(), $result = null ) {
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 );
		$caller    = isset( $backtrace[1]['function'] ) ? $backtrace[1]['function'] : 'Unknown';

		$context = array(
			'function'          => $function_name,
			'params'            => $params,
			'result'            => $result,
			'caller'            => $caller,
			'memory_usage'      => memory_get_usage( true ),
			'peak_memory_usage' => memory_get_peak_usage( true ),
		);

		self::debug_log( "Function execution: {$function_name}", 'debug', $context );
	}

	public static function download_log_file( $request ) {
		self::debug_function( __FUNCTION__ );

		// Built fresh on every request rather than reading the persistent debug
		// log, which is only written when WP_AI_WORKFLOWS_DEBUG is enabled.
		$report = self::build_diagnostic_report();

		// Delivered as a plain-text attachment and emitted verbatim; running it
		// through wp_kses_post() would corrupt it and desync Content-Length.
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-ai-workflows-diagnostics-' . gmdate( 'Ymd-His' ) . '.log"' );
		header( 'Content-Length: ' . strlen( $report ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text diagnostic report served as a download attachment (Content-Type: text/plain); HTML-escaping would corrupt it. All secrets are redacted in build_diagnostic_report().
		echo $report;
		exit;
	}

	/**
	 * Build a human-readable, secret-free diagnostic report for support.
	 *
	 * Everything here is either non-sensitive environment metadata or a boolean
	 * "configured / not configured" flag. API keys, secrets and tokens are NEVER
	 * included - only whether a given provider key is present.
	 *
	 * @return string The assembled report, newline-terminated.
	 */
	private static function build_diagnostic_report() {
		global $wpdb;

		$lines   = array();
		$lines[] = '=== AI Workflow Automation - Diagnostic Report ===';
		$lines[] = 'Generated: ' . current_time( 'mysql' ) . ' (site time)';
		$lines[] = '';

		$lines[] = '--- Environment ---';
		$lines[] = 'Site URL:        ' . home_url();
		$lines[] = 'WordPress:       ' . get_bloginfo( 'version' );
		$lines[] = 'PHP:             ' . PHP_VERSION;
		$lines[] = 'MySQL:           ' . $wpdb->db_version();
		$lines[] = 'Multisite:       ' . ( is_multisite() ? 'yes' : 'no' );
		$lines[] = 'Active theme:    ' . wp_get_theme()->get( 'Name' );
		$lines[] = 'WP memory limit: ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'n/a' );
		$lines[] = 'PHP mem limit:   ' . ini_get( 'memory_limit' );
		$lines[] = 'Max exec time:   ' . ini_get( 'max_execution_time' );
		$lines[] = 'Permalinks:      ' . ( get_option( 'permalink_structure' ) ? 'pretty' : 'plain' );
		$lines[] = '';

		$settings = get_option( 'wp_ai_workflows_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$lines[] = '--- Plugin ---';
		$lines[] = 'Version:         ' . ( defined( 'WP_AI_WORKFLOWS_PRO_VERSION' ) ? WP_AI_WORKFLOWS_PRO_VERSION : 'unknown' );
		$lines[] = 'DB version:      ' . get_option( WP_AI_WORKFLOWS_DB_VERSION_OPTION, 'n/a' );
		$lines[] = 'Debug logging:   ' . ( defined( 'WP_AI_WORKFLOWS_DEBUG' ) && WP_AI_WORKFLOWS_DEBUG ? 'on' : 'off' );
		if ( class_exists( 'WP_AI_Workflows_Platform_Client' ) ) {
			$lines[] = 'Platform:        ' . ( WP_AI_Workflows_Platform_Client::is_connected() ? 'connected' : 'not connected' );
		}
		$lines[] = 'Google Drive:    ' . ( ! empty( $settings['google_client_id'] ) ? 'configured' : 'not configured' );
		$lines[] = 'Analytics:       ' . ( get_option( 'wp_ai_workflows_analytics_opt_in' ) ? 'enabled (opted in)' : 'disabled (default)' );
		$lines[] = '';

		$providers = array(
			'openai_api_key'     => 'OpenAI',
			'perplexity_api_key' => 'Perplexity',
			'openrouter_api_key' => 'OpenRouter',
			'fal_api_key'        => 'Fal.ai',
			'firecrawl_api_key'  => 'Firecrawl',
			'llamaparse_api_key' => 'LlamaParse',
			'unsplash_api_key'   => 'Unsplash',
		);
		$lines[] = '--- Configured providers ---';
		foreach ( $providers as $key => $label ) {
			$lines[] = str_pad( $label . ':', 17 ) . ( ! empty( $settings[ $key ] ) ? 'configured' : 'not configured' );
		}
		$lines[] = '';

		$lines[] = '--- Active plugins ---';
		$active = get_option( 'active_plugins', array() );
		if ( ! empty( $active ) && is_array( $active ) ) {
			foreach ( $active as $plugin ) {
				$lines[] = '  ' . $plugin;
			}
		} else {
			$lines[] = '  (none)';
		}
		$lines[] = '';

		$log_file = WP_CONTENT_DIR . '/wp-ai-workflows-debug.log';
		$lines[]  = '--- Debug log (recent) ---';
		if ( file_exists( $log_file ) ) {
			$fs       = self::get_wp_filesystem();
			$contents = $fs ? $fs->get_contents( $log_file ) : '';
			if ( is_string( $contents ) && '' !== $contents ) {
				$log_lines = explode( "\n", $contents );
				$tail      = array_slice( $log_lines, -200 );
				$lines[]   = trim( implode( "\n", $tail ) );
			} else {
				$lines[] = '(log file is empty)';
			}
		} else {
			$lines[] = '(no debug log written: enable WP_AI_WORKFLOWS_DEBUG to capture detailed runtime logs)';
		}
		$lines[] = '';
		$lines[] = '=== End of report ===';

		return implode( "\n", $lines ) . "\n";
	}

	public static function generate_and_encrypt_api_key() {
		self::debug_function( __FUNCTION__ );

		$api_key       = wp_generate_password( 32, false );
		$encrypted_key = wp_hash_password( $api_key );
		update_option( 'wp_ai_workflows_encrypted_api_key', $encrypted_key );
		return $api_key; // Return unencrypted for initial use
	}

	public static function get_api_key() {
		self::debug_function( __FUNCTION__ );

		$encrypted_key = get_option( 'wp_ai_workflows_encrypted_api_key' );
		return $encrypted_key ? '********' . substr( $encrypted_key, -4 ) : '';
	}

	public static function generate_api_key( $request ) {
		self::debug_function( __FUNCTION__ );

		$new_key = self::generate_and_encrypt_api_key();
		return new WP_REST_Response( array( 'api_key' => self::get_api_key() ), 200 );
	}

	public static function get_settings( $request ) {
		self::debug_function( __FUNCTION__ );

		try {
			$settings = get_option( 'wp_ai_workflows_settings', array() );
			$license  = new WP_AI_Workflows_License();

			$api_keys = array(
				'ai'       => array(
					'openai_api_key',
					'perplexity_api_key',
					'openrouter_api_key',
					'fal_api_key',
				),
				'services' => array(
					'firecrawl_api_key',
					'llamaparse_api_key',
					'unsplash_api_key',
				),
			);

			$response_settings = array();

			foreach ( $api_keys as $group ) {
				foreach ( $group as $key ) {
					$response_settings[ $key ] = isset( $settings[ $key ] ) ?
						self::mask_api_key( $settings[ $key ] ) : '';
				}
			}

			$google_settings = self::get_google_settings();

			$task_roles = get_option( 'wp_ai_workflows_task_roles', array( 'administrator' ) );

			$license_status = $license->is_active() ? 'active' : 'inactive';
			$license_key    = $license->get_license_key();

			if ( $license_status === 'active' && ! empty( $license_key ) ) {
				if ( strlen( $license_key ) > 8 ) {
					$first_part  = substr( $license_key, 0, 4 );
					$last_part   = substr( $license_key, -4 );
					$masked_part = str_repeat( '•', min( strlen( $license_key ) - 8, 20 ) ); // Limit dots for readability
					$license_key = $first_part . $masked_part . $last_part;
				}
			}

			$response_settings = array_merge(
				$response_settings,
				array(
					'license_key'          => $license_key, // Now contains masked key if license is active
					'license_status'       => $license_status,
					'license_expiry'       => $license->get_license_expiry(),
					'ai_workflow_api_key'  => get_option( 'wp_ai_workflows_api_key', '' ),
					'google_client_id'     => $google_settings['google_client_id'],
					'google_client_secret' => $google_settings['google_client_secret'],
					'google_redirect_uri'  => $google_settings['google_redirect_uri'],
					'selected_models'      => isset( $settings['selected_models'] ) ? $settings['selected_models'] : array(),
					'expose_to_agents'     => ! empty( $settings['expose_to_agents'] ),
					'analytics_opt_in'     => (bool) get_option( 'wp_ai_workflows_analytics_opt_in', false ),
					'task_roles'           => $task_roles,
					'setup_completed'      => (bool) get_option( 'wp_ai_workflows_setup_completed', 0 ),
				)
			);

			self::debug_log(
				'Settings retrieved successfully',
				'debug',
				array(
					'selected_models'    => $response_settings['selected_models'],
					'license_status'     => $response_settings['license_status'],
					'license_key_masked' => ! empty( $license_key ) && $license_status === 'active',
				)
			);

			return new WP_REST_Response( $response_settings, 200 );
		} catch ( Exception $e ) {
			self::debug_log(
				'Error retrieving settings',
				'error',
				array(
					'error_message' => $e->getMessage(),
				)
			);
			return new WP_Error( 'settings_retrieval_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	private static function mask_api_key( $key ) {
		if ( strlen( $key ) > 4 ) {
			return str_repeat( '*', strlen( $key ) - 4 ) . substr( $key, -4 );
		}
		return $key;
	}

	private static function encrypt_sensitive_data( $data ) {

		return WP_AI_Workflows_Encryption::encrypt( $data );
	}

	private static function decrypt_sensitive_data( $data ) {

		return WP_AI_Workflows_Encryption::decrypt( $data );
	}

	/**
	 * Read a sensitive field from the shared settings option, decrypting it and
	 * transparently re-encrypting (self-healing) any value stored in a legacy
	 * format so it moves to the modern authenticated scheme on first read.
	 *
	 * Write-loop safe: healing only fires when the decrypt used a legacy path;
	 * once rewritten in the modern format subsequent reads no longer heal.
	 *
	 * @param string $field Settings field key (e.g. 'openai_api_key').
	 * @return string Decrypted value, or '' when absent/undecryptable.
	 */
	private static function get_healed_setting_key( $field ) {
		$settings = get_option( 'wp_ai_workflows_settings', array() );

		if ( empty( $settings[ $field ] ) ) {
			return '';
		}

		$decrypted = WP_AI_Workflows_Encryption::decrypt( $settings[ $field ] );

		if ( false === $decrypted ) {
			return '';
		}

		if ( '' !== $decrypted && WP_AI_Workflows_Encryption::last_decrypt_used_legacy() ) {
			$reencrypted = WP_AI_Workflows_Encryption::encrypt( $decrypted );
			if ( is_string( $reencrypted ) && '' !== $reencrypted ) {
				$settings[ $field ] = $reencrypted;
				update_option( 'wp_ai_workflows_settings', $settings );
			}
		}

		return $decrypted;
	}

	public static function update_settings( $request ) {
		self::debug_function( __FUNCTION__, array( 'request' => $request->get_params() ) );

		$settings         = $request->get_json_params();
		$current_settings = get_option( 'wp_ai_workflows_settings', array() );

		$fields_to_update = array(
			'openai_api_key',
			'perplexity_api_key',
			'openrouter_api_key',
			'firecrawl_api_key',
			'llamaparse_api_key',
			'selected_models',
			'google_client_id',
			'google_client_secret',
			'google_redirect_uri',
			'unsplash_api_key',
			'fal_api_key',
			'supabase_url',
			'supabase_key',
			'supabase_table',
		);

		$sensitive_fields = array(
			'openai_api_key',
			'perplexity_api_key',
			'openrouter_api_key',
			'firecrawl_api_key',
			'llamaparse_api_key',
			'google_client_id',
			'google_client_secret',
			'unsplash_api_key',
			'fal_api_key',
			'supabase_key',
		);

		foreach ( $fields_to_update as $field ) {
			if ( isset( $settings[ $field ] ) ) {
				if ( in_array( $field, $sensitive_fields ) ) {
					// For sensitive fields, only update if the new value is different from the masked version
					$masked_current = self::mask_api_key( $current_settings[ $field ] ?? '' );
					if ( $settings[ $field ] !== $masked_current ) {
						$current_settings[ $field ] = self::encrypt_sensitive_data( $settings[ $field ] );
					}
				} else {
					$current_settings[ $field ] = $settings[ $field ];
				}
			}
		}

		// Phase 4: opt-in to exposing workflows to AI agents via the Abilities API.
		if ( isset( $settings['expose_to_agents'] ) ) {
			$current_settings['expose_to_agents'] = (bool) $settings['expose_to_agents'];
		}

		// Usage analytics are opt-in (default off). Persist the explicit choice.
		if ( isset( $settings['analytics_opt_in'] ) ) {
			update_option( 'wp_ai_workflows_analytics_opt_in', (bool) $settings['analytics_opt_in'] );
		}

		if ( isset( $settings['setup_completed'] ) ) {
			update_option( 'wp_ai_workflows_setup_completed', $settings['setup_completed'] === '1' ? 1 : 0 );
		}

		if ( isset( $settings['task_roles'] ) ) {
			$task_roles = array_map( 'sanitize_text_field', $settings['task_roles'] );

			// Ensure administrator is always included
			if ( ! in_array( 'administrator', $task_roles ) ) {
				$task_roles[] = 'administrator';
			}

			global $wp_roles;
			foreach ( $wp_roles->roles as $role_name => $role ) {
				$role_object = get_role( $role_name );
				if ( $role_object ) {
					if ( in_array( $role_name, $task_roles ) ) {
						$role_object->add_cap( 'manage_workflow_tasks' );
					} else {
						$role_object->remove_cap( 'manage_workflow_tasks' );
					}
				}
			}

			update_option( 'wp_ai_workflows_task_roles', $task_roles );
			self::debug_log( 'Task roles updated', 'debug', array( 'task_roles' => $task_roles ) );
		}

		update_option( 'wp_ai_workflows_settings', $current_settings );

		$masked_settings = $current_settings;
		foreach ( $sensitive_fields as $field ) {
			if ( isset( $masked_settings[ $field ] ) ) {
				$masked_settings[ $field ] = self::mask_api_key( $masked_settings[ $field ] );
			}
		}

		$masked_settings['analytics_opt_in'] = (bool) get_option( 'wp_ai_workflows_analytics_opt_in', false );
		return new WP_REST_Response( $masked_settings, 200 );
	}

	public static function get_fal_ai_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'fal_api_key' );
	}

	public static function get_firecrawl_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'firecrawl_api_key' );
	}

	public static function get_llamaparse_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'llamaparse_api_key' );
	}

	public static function get_openrouter_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'openrouter_api_key' );
	}

	/**
	 * Standard OpenRouter attribution headers, used on every OpenRouter
	 * request so the app listing resolves to this plugin.
	 *
	 * @return array{HTTP-Referer: string, X-Title: string}
	 */
	public static function openrouter_headers() {
		return array(
			'HTTP-Referer' => 'https://wpaiworkflowautomation.com',
			'X-Title'      => 'AI Workflow Automation',
		);
	}

	public static function get_unsplash_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'unsplash_api_key' );
	}

	/**
	 * Supabase service/API key for the pgvector knowledge base (decrypted).
	 *
	 * @return string
	 */
	public static function get_supabase_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'supabase_key' );
	}

	public static function get_gravity_forms_data( $request ) {
		self::debug_function( __FUNCTION__ );

		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'gravity_forms_not_active', 'Gravity Forms is not active', array( 'status' => 404 ) );
		}

		$forms           = GFAPI::get_forms();
		$formatted_forms = array();

		foreach ( $forms as $form ) {
			$formatted_fields = array();
			foreach ( $form['fields'] as $field ) {
				$formatted_fields[] = array(
					'id'    => $field->id,
					'label' => $field->label,
					'type'  => $field->type,
				);
			}

			$formatted_forms[] = array(
				'id'     => $form['id'],
				'title'  => $form['title'],
				'fields' => $formatted_fields,
			);
		}

		return new WP_REST_Response( $formatted_forms, 200 );
	}

	public static function get_wpforms_data( $request ) {
		self::debug_function( __FUNCTION__ );

		if ( ! class_exists( 'WPForms' ) ) {
			self::debug_log( 'WPForms not active', 'error' );
			return new WP_Error( 'wpforms_not_active', 'WPForms is not active', array( 'status' => 404 ) );
		}

		try {
			if ( ! function_exists( 'wpforms' ) || ! wpforms()->form ) {
				self::debug_log( 'WPForms forms object not accessible', 'error' );
				return new WP_Error( 'wpforms_not_initialized', 'WPForms not properly initialized', array( 'status' => 500 ) );
			}

			$forms = wpforms()->form->get();
			if ( empty( $forms ) ) {
				self::debug_log( 'No WPForms found', 'debug' );
				return new WP_REST_Response( array(), 200 ); // Return empty array instead of error
			}

			$formatted_forms = array();
			foreach ( $forms as $form ) {
				try {
					$form_data = json_decode( $form->post_content, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						self::debug_log(
							'Error decoding form content',
							'error',
							array(
								'form_id' => $form->ID,
								'error'   => json_last_error_msg(),
							)
						);
						continue; // Skip this form but continue processing others
					}

					$fields = array();
					if ( ! empty( $form_data['fields'] ) ) {
						foreach ( $form_data['fields'] as $field ) {
							$fields[] = array(
								'id'    => $field['id'],
								'label' => isset( $field['label'] ) ? $field['label'] : 'Unnamed Field',
								'type'  => isset( $field['type'] ) ? $field['type'] : 'text',
							);
						}
					}

					$formatted_forms[] = array(
						'id'     => $form->ID,
						'title'  => $form->post_title,
						'fields' => $fields,
					);

				} catch ( Exception $e ) {
					self::debug_log(
						'Error processing form',
						'error',
						array(
							'form_id' => $form->ID,
							'error'   => $e->getMessage(),
						)
					);
					continue; // Skip this form but continue processing others
				}
			}

			self::debug_log(
				'Successfully retrieved WPForms data',
				'debug',
				array(
					'forms_count' => count( $formatted_forms ),
				)
			);

			return new WP_REST_Response( $formatted_forms, 200 );

		} catch ( Exception $e ) {
			self::debug_log(
				'Error getting WPForms data',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_Error(
				'wpforms_error',
				'Error retrieving WPForms data: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	public static function get_cf7_data( $request ) {
		self::debug_function( __FUNCTION__ );

		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			self::debug_log( 'Contact Form 7 not active', 'error' );
			return new WP_Error( 'cf7_not_active', 'Contact Form 7 is not active', array( 'status' => 404 ) );
		}

		try {
			$args = array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			);

			$forms           = get_posts( $args );
			$formatted_forms = array();

			foreach ( $forms as $form ) {
				$cf7_form = wpcf7_contact_form( $form->ID );
				if ( ! $cf7_form ) {
					continue;
				}

				$form_id = 'contact-form-' . $form->ID;

				$form_tags = $cf7_form->scan_form_tags();
				$fields    = array();

				foreach ( $form_tags as $tag ) {
					if ( ! empty( $tag['name'] ) && ! in_array( $tag['type'], array( 'submit', 'reset' ) ) ) {
						$fields[] = array(
							'id'    => $tag['name'],
							'label' => $tag['name'],
							'type'  => $tag['type'],
						);
					}
				}

				$formatted_forms[] = array(
					'id'     => $form_id, // Use the CF7 format ID
					'title'  => $form->post_title,
					'fields' => $fields,
				);
			}

			self::debug_log(
				'Successfully retrieved CF7 data',
				'debug',
				array(
					'forms_count' => count( $formatted_forms ),
					'forms'       => $formatted_forms,
				)
			);

			return new WP_REST_Response( $formatted_forms, 200 );

		} catch ( Exception $e ) {
			self::debug_log(
				'Error getting CF7 data',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_Error( 'cf7_error', 'Error retrieving Contact Form 7 data: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	public static function get_elementor_forms_data( $request ) {
		self::debug_function( __FUNCTION__ );

		if ( ! did_action( 'elementor/loaded' ) ) {
			self::debug_log( 'Elementor not active', 'error' );
			return new WP_Error( 'elementor_not_active', 'Elementor is not active', array( 'status' => 404 ) );
		}

		try {
			global $wpdb;
			$forms = array();

			$posts_with_elementor = $wpdb->get_results(
				"SELECT post_id, meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_elementor_data'"
			);

			foreach ( $posts_with_elementor as $post_meta ) {
				$elementor_data = json_decode( $post_meta->meta_value, true );

				if ( ! is_array( $elementor_data ) ) {
					continue;
				}

				self::find_elementor_form_widgets( $elementor_data, $forms, $post_meta->post_id );
			}

			self::debug_log(
				'Successfully retrieved Elementor Forms data',
				'debug',
				array(
					'forms_count' => count( $forms ),
				)
			);

			return new WP_REST_Response( array_values( $forms ), 200 );

		} catch ( Exception $e ) {
			self::debug_log(
				'Error getting Elementor Forms data',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_Error(
				'elementor_forms_error',
				'Error retrieving Elementor Forms data: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Recursively find form widgets in Elementor data structure
	 */
	private static function find_elementor_form_widgets( $elements, &$forms, $post_id ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) && $element['widgetType'] === 'form' ) {
				$form_id = isset( $element['id'] ) ? $element['id'] : uniqid( 'form_' );
				$form_name = isset( $element['settings']['form_name'] ) ? $element['settings']['form_name'] : 'Untitled Form';

				$fields = array();
				if ( isset( $element['settings']['form_fields'] ) && is_array( $element['settings']['form_fields'] ) ) {
					foreach ( $element['settings']['form_fields'] as $field ) {
						if ( isset( $field['field_type'] ) && $field['field_type'] !== 'step' ) {
							$fields[] = array(
								'id'    => isset( $field['custom_id'] ) ? $field['custom_id'] : $field['_id'],
								'label' => isset( $field['field_label'] ) ? $field['field_label'] : '',
								'type'  => isset( $field['field_type'] ) ? $field['field_type'] : 'text',
							);
						}
					}
				}

				$forms[ $form_id ] = array(
					'id'     => $form_id,
					'title'  => $form_name,
					'fields' => $fields,
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::find_elementor_form_widgets( $element['elements'], $forms, $post_id );
			}
		}
	}

	public static function get_ninja_forms_data( $request ) {
		self::debug_function( __FUNCTION__ );

		if ( ! class_exists( 'Ninja_Forms' ) ) {
			self::debug_log( 'Ninja Forms not active', 'error' );
			return new WP_Error( 'ninja_forms_not_active', 'Ninja Forms is not active', array( 'status' => 404 ) );
		}

		try {
			$forms           = Ninja_Forms()->form()->get_forms();
			$formatted_forms = array();

			foreach ( $forms as $form ) {
				$form_id     = $form->get_id();
				$form_fields = Ninja_Forms()->form( $form_id )->get_fields();
				$fields      = array();

				foreach ( $form_fields as $field ) {
					$field_settings = $field->get_settings();

					if ( ! empty( $field_settings['label'] ) && ! in_array( $field_settings['type'], array( 'submit', 'hr', 'html' ) ) ) {
						$fields[] = array(
							'id'    => $field_settings['key'],
							'label' => $field_settings['label'],
							'type'  => $field_settings['type'],
						);
					}
				}

				$formatted_forms[] = array(
					'id'     => 'ninja-form-' . $form_id,
					'title'  => $form->get_setting( 'title' ),
					'fields' => $fields,
				);

				self::debug_log(
					'Processed Ninja Form',
					'debug',
					array(
						'form_id'     => $form_id,
						'field_count' => count( $fields ),
						'fields'      => $fields,
					)
				);
			}

			self::debug_log(
				'Successfully retrieved Ninja Forms data',
				'debug',
				array(
					'forms_count' => count( $formatted_forms ),
				)
			);

			return new WP_REST_Response( $formatted_forms, 200 );

		} catch ( Exception $e ) {
			self::debug_log(
				'Error getting Ninja Forms data',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return new WP_Error(
				'ninja_forms_error',
				'Error retrieving Ninja Forms data: ' . $e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}



	/**
	 * Is this an OpenAI reasoning-family model (gpt-5*, o1/o3/o4*)? These
	 * models reject the legacy `max_tokens` field (they require
	 * `max_completion_tokens`) and reject a non-default `temperature` /
	 * `top_p` / `frequency_penalty` / `presence_penalty` outright (HTTP 400).
	 * Detection strips an `openai/` prefix so it works for both the bare id
	 * (native OpenAI routing) and the OpenRouter-style id.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public static function is_reasoning_model( $model ) {
		$clean = preg_replace( '#^openai/#', '', (string) $model );
		return (bool) preg_match( '/^(gpt-5|o1|o3|o4)/i', (string) $clean );
	}

	/**
	 * Build a provider `response_format` object (OpenAI / OpenRouter JSON-Schema
	 * structured output) from an AI Model node's `outputSchema` field list.
	 *
	 * Each entry is `{ name, type, description }`. Every field is marked required
	 * and `additionalProperties` is disabled so the model returns exactly the
	 * defined keys. `strict` mode is used whenever every field is a scalar or a
	 * simple string array; a free-form `object` field relaxes strict mode (its
	 * inner shape is not describable through the simple list UI) while still
	 * requesting JSON-Schema output.
	 *
	 * @param array $output_schema Array of { name, type, description } definitions.
	 * @return array|null response_format payload, or null when nothing usable.
	 */
	public static function build_structured_response_format( $output_schema ) {
		if ( ! is_array( $output_schema ) || empty( $output_schema ) ) {
			return null;
		}

		$properties = array();
		$required   = array();
		$strict     = true;

		foreach ( $output_schema as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$type = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : 'string';

			switch ( $type ) {
				case 'number':
					$prop = array( 'type' => 'number' );
					break;
				case 'boolean':
					$prop = array( 'type' => 'boolean' );
					break;
				case 'array':
					$prop = array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					);
					break;
				case 'object':
					// Free-form object: inner shape unknown, so relax strict mode.
					$prop   = array(
						'type'                 => 'object',
						'additionalProperties' => true,
					);
					$strict = false;
					break;
				case 'string':
				default:
					$prop = array( 'type' => 'string' );
					break;
			}

			if ( ! empty( $field['description'] ) ) {
				$prop['description'] = (string) $field['description'];
			}

			$properties[ $name ] = $prop;
			$required[]          = $name;
		}

		if ( empty( $properties ) ) {
			return null;
		}

		return array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'structured_output',
				'strict' => $strict,
				'schema' => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Does this OpenAI error response mean "the requested model/params are
	 * unusable" (as opposed to a transient upstream failure)? True for a 404
	 * (model_not_found) or a 400 whose code/message clearly points at the
	 * model or an unsupported parameter. Deliberately excludes other errors
	 * (e.g. missing key, rate limit, 5xx) so we only downgrade when the
	 * requested model itself is the problem.
	 *
	 * @param int    $status_code   HTTP status code.
	 * @param string $error_code    `error.code` from the OpenAI error body.
	 * @param string $error_type    `error.type` from the OpenAI error body.
	 * @param string $error_message `error.message` from the OpenAI error body.
	 * @return bool
	 */
	public static function is_model_unavailable_error( $status_code, $error_code, $error_type, $error_message ) {
		if ( 404 === (int) $status_code ) {
			return true;
		}
		if ( 400 !== (int) $status_code ) {
			return false;
		}
		$known = array( 'model_not_found', 'unsupported_parameter', 'unsupported_value', 'invalid_model' );
		if ( in_array( (string) $error_code, $known, true ) || in_array( (string) $error_type, $known, true ) ) {
			return true;
		}
		$msg = strtolower( (string) $error_message );
		return (bool) preg_match( '/model|max_tokens|temperature|unsupported|does not exist|do not have access/', $msg );
	}

	/**
	 * Which request-body parameter (if any) does this OpenAI 400 say is
	 * unsupported by the requested model? Lets an unrecognized model family
	 * be handled by stripping the offending field and retrying, without a
	 * plugin update. Trusts `error.param` when present, else parses it out
	 * of `error.message`. Returns null for non-per-parameter errors.
	 *
	 * @param int   $status_code HTTP status code.
	 * @param array $error       Decoded `error` object from the response body.
	 * @return string|null
	 */
	public static function unsupported_param_from_openai_error( $status_code, $error ) {
		if ( 400 !== (int) $status_code || ! is_array( $error ) ) {
			return null;
		}

		$code    = isset( $error['code'] ) ? (string) $error['code'] : '';
		$param   = isset( $error['param'] ) ? (string) $error['param'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';

		if ( '' !== $param && ( 'unsupported_parameter' === $code || false !== stripos( $message, 'unsupported' ) ) ) {
			return $param;
		}

		if ( preg_match( "/[Uu]nsupported parameter: '([^']+)'/", $message, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( "/'([^']+)' is not supported (?:with|for) this model/", $message, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Strip (or rename) a single rejected parameter from an OpenAI request
	 * body in place, per `unsupported_param_from_openai_error()`. `max_tokens`
	 * is renamed to `max_completion_tokens` (with the reasoning-model floor)
	 * rather than dropped, since every model accepts an output-token cap
	 * under one field name or the other.
	 *
	 * @param array  $body  Request body, modified in place.
	 * @param string $param Parameter name reported as unsupported.
	 * @return bool True if the body was changed (safe to retry), false if the
	 *              param wasn't present (nothing to strip - don't loop again).
	 */
	public static function strip_unsupported_openai_param( array &$body, $param ) {
		if ( 'max_tokens' === $param && isset( $body['max_tokens'] ) ) {
			$body['max_completion_tokens'] = max( (int) $body['max_tokens'], self::MIN_REASONING_COMPLETION_TOKENS );
			unset( $body['max_tokens'] );
			return true;
		}

		if ( array_key_exists( $param, $body ) ) {
			unset( $body[ $param ] );
			return true;
		}

		return false;
	}

	/**
	 * Build the chat-completion body for `call_openai_api()`, shaping
	 * sampling/output params to the model family so a valid model never 400s.
	 *
	 * @param string $model     Model id (already stripped of any `openai/` prefix by the caller).
	 * @param array  $messages  Chat messages payload.
	 * @param array  $parameters Node `settings` (temperature/top_p/frequency_penalty/presence_penalty/max_tokens).
	 * @return array
	 */
	public static function build_openai_chat_body( $model, $messages, $parameters ) {
		$reasoning = self::is_reasoning_model( $model );

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		// Output-token cap. Reasoning models use `max_completion_tokens`
		// instead of the legacy `max_tokens`, and need a floor so their hidden
		// reasoning tokens don't consume the entire budget and return empty
		// content (finish_reason: "length").
		if ( isset( $parameters['max_tokens'] ) && is_numeric( $parameters['max_tokens'] ) ) {
			$requested = (int) $parameters['max_tokens'];
			if ( $reasoning ) {
				$body['max_completion_tokens'] = max( $requested, self::MIN_REASONING_COMPLETION_TOKENS );
			} else {
				$body['max_tokens'] = $requested;
			}
		}

		// Reasoning models only accept the DEFAULT sampling params - sending
		// a custom temperature/top_p/penalty is a hard 400. Omit them
		// entirely for those models; non-reasoning models keep prior behavior.
		if ( ! $reasoning ) {
			$body['temperature']       = isset( $parameters['temperature'] ) ? floatval( $parameters['temperature'] ) : 1.0;
			$body['top_p']             = isset( $parameters['top_p'] ) ? floatval( $parameters['top_p'] ) : 1.0;
			$body['frequency_penalty'] = isset( $parameters['frequency_penalty'] ) ? floatval( $parameters['frequency_penalty'] ) : 0.0;
			$body['presence_penalty']  = isset( $parameters['presence_penalty'] ) ? floatval( $parameters['presence_penalty'] ) : 0.0;
		}

		// Structured output (JSON-Schema). Model-family-agnostic - valid for both
		// reasoning and non-reasoning OpenAI chat models - so it sits outside the
		// sampling-param block above.
		if ( isset( $parameters['response_format'] ) && is_array( $parameters['response_format'] ) ) {
			$body['response_format'] = $parameters['response_format'];
		}

		return $body;
	}

	/**
	 * Call the native OpenAI chat-completions API with the user's BYOK key.
	 *
	 * Shapes request params per model family (see `build_openai_chat_body()`).
	 * If the provider rejects the requested model outright, retries ONCE with
	 * `FALLBACK_AI_MODEL` instead of hard-failing.
	 *
	 * @param string $prompt
	 * @param string $model
	 * @param array  $imageUrls
	 * @param array  $parameters
	 * @param bool   $is_fallback_retry Internal - true on the one-shot retry, prevents recursion.
	 * @return array|WP_Error
	 */
	public static function call_openai_api( $prompt, $model, $imageUrls = array(), $parameters = array(), $is_fallback_retry = false ) {
		self::debug_function(
			__FUNCTION__,
			array(
				'prompt'     => $prompt,
				'model'      => $model,
				'imageUrls'  => $imageUrls,
				'parameters' => $parameters,
			)
		);

		$api_key = self::get_openai_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'openai_api_key_missing', 'OpenAI API key is not set' );
		}

		$url     = 'https://api.openai.com/v1/chat/completions';
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(),
			),
		);

		if ( ! empty( $prompt ) ) {
			$messages[0]['content'][] = array(
				'type' => 'text',
				'text' => $prompt,
			);
		}

		foreach ( $imageUrls as $imageUrl ) {
			if ( ! empty( $imageUrl ) ) {
				$messages[0]['content'][] = array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => $imageUrl ),
				);
			}
		}

		$body = self::build_openai_chat_body( $model, $messages, $parameters );

		// Bounded error-adaptive retry: strip a rejected param and retry once
		// per param, so this can never loop beyond the offending fields.
		$stripped_params = array();

		do {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
					'timeout' => 600,
				)
			);

			if ( is_wp_error( $response ) ) {
				self::debug_log( 'OpenAI API call failed', 'error', array( 'error' => $response->get_error_message() ) );
				return new WP_Error( 'openai_api_wp_error', 'WP_Error in API call: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( 200 === $response_code ) {
				break;
			}

			$error       = ( isset( $data['error'] ) && is_array( $data['error'] ) ) ? $data['error'] : array();
			$bad_param   = self::unsupported_param_from_openai_error( $response_code, $error );
			$retry_count = count( $stripped_params );

			if ( $bad_param && ! isset( $stripped_params[ $bad_param ] ) && $retry_count < 5
				&& self::strip_unsupported_openai_param( $body, $bad_param )
			) {
				$stripped_params[ $bad_param ] = true;
				self::debug_log(
					'OpenAI rejected a request parameter, stripping and retrying once',
					'warning',
					array(
						'model' => $model,
						'param' => $bad_param,
					)
				);
				continue;
			}

			break;
		} while ( true );

		if ( $response_code !== 200 ) {
			$error_code    = isset( $data['error']['code'] ) ? $data['error']['code'] : '';
			$error_type    = isset( $data['error']['type'] ) ? $data['error']['type'] : '';
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error';

			if ( ! $is_fallback_retry
				&& self::FALLBACK_AI_MODEL !== $model
				&& self::is_model_unavailable_error( $response_code, $error_code, $error_type, $error_message )
			) {
				self::debug_log(
					'OpenAI model unavailable/invalid for this BYOK key, downgrading to fallback model',
					'warning',
					array(
						'requested_model' => $model,
						'fallback_model'  => self::FALLBACK_AI_MODEL,
						'http_code'       => $response_code,
						'error'           => $error_message,
					)
				);
				return self::call_openai_api( $prompt, self::FALLBACK_AI_MODEL, $imageUrls, $parameters, true );
			}

			self::debug_log(
				'OpenAI API error',
				'error',
				array(
					'http_code' => $response_code,
					'error'     => $error_message,
				)
			);
			return new WP_Error( 'openai_api_error', "OpenAI API error (HTTP $response_code): $error_message" );
		}

		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			self::debug_log(
				'OpenAI API call successful',
				'info',
				array(
					'model'       => $model,
					'image_count' => count( $imageUrls ),
					'has_usage'   => isset( $data['usage'] ),
					'usage'       => $data['usage'] ?? null,
				)
			);

			return array(
				'choices' => $data['choices'],
				'usage'   => $data['usage'] ?? null,
				'model'   => $data['model'] ?? $model,
			);
		} else {
			self::debug_log( 'Unexpected OpenAI API response', 'error', array( 'response' => $data ) );
			return new WP_Error( 'openai_api_unexpected_response', 'Unexpected OpenAI API response structure' );
		}
	}

	public static function call_openai_with_tools( $prompt, $model, $imageUrls, $tools, $parameters, $system_message = null ) {
		self::debug_log(
			'Calling OpenAI with tools',
			'debug',
			array(
				'model'       => $model,
				'tools_count' => count( $tools ),
				'tools'       => array_map(
					function ( $tool ) {
						return $tool['type']; },
					$tools
				),
			)
		);

		$api_key = self::get_openai_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is not set' );
		}

		$url     = 'https://api.openai.com/v1/responses'; // Correct endpoint
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		$input = '';
		if ( ! empty( $prompt ) ) {
			$input = $prompt;
		}

		if ( ! empty( $imageUrls ) ) {
			$input = array(
				array(
					'type' => 'text',
					'text' => $prompt,
				),
			);
			foreach ( $imageUrls as $imageUrl ) {
				if ( ! empty( $imageUrl ) ) {
					$input[] = array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => $imageUrl ),
					);
				}
			}
		}

		$clean_model = strpos( $model, 'openai/' ) === 0 ? substr( $model, 7 ) : $model;

		// Reasoning models reject a non-default temperature/top_p outright
		// (HTTP 400) - omit them entirely for those models, same rule
		// `build_openai_chat_body()` applies to the Chat Completions endpoint.
		$is_reasoning_model = self::is_reasoning_model( $clean_model );

		$body = array(
			'model'               => $clean_model,
			'input'               => $input,
			'tools'               => $tools,
			'tool_choice'         => 'auto',
			'parallel_tool_calls' => true,
		);

		if ( ! $is_reasoning_model ) {
			$body['temperature'] = isset( $parameters['temperature'] ) ? floatval( $parameters['temperature'] ) : 1.0;
			if ( isset( $parameters['top_p'] ) ) {
				$body['top_p'] = floatval( $parameters['top_p'] );
			}
		}

		if ( ! empty( $system_message ) ) {
			$body['instructions'] = $system_message;
		}

		self::debug_log(
			'OpenAI Responses API request',
			'debug',
			array(
				'url'   => $url,
				'model' => $body['model'],
				'tools' => $tools,
			)
		);

		// Bounded error-adaptive retry: strip a rejected param and retry once
		// per param, so this can never loop beyond the offending fields.
		$stripped_params = array();

		do {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
					'timeout' => 600,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'Error calling OpenAI API: ' . esc_html( $response->get_error_message() ) );
			}

			$status_code   = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( 200 === $status_code ) {
				break;
			}

			$error       = ( isset( $data['error'] ) && is_array( $data['error'] ) ) ? $data['error'] : array();
			$bad_param   = self::unsupported_param_from_openai_error( $status_code, $error );
			$retry_count = count( $stripped_params );

			if ( $bad_param && ! isset( $stripped_params[ $bad_param ] ) && $retry_count < 5
				&& self::strip_unsupported_openai_param( $body, $bad_param )
			) {
				$stripped_params[ $bad_param ] = true;
				self::debug_log(
					'OpenAI rejected a request parameter, stripping and retrying once',
					'warning',
					array(
						'model' => $clean_model,
						'param' => $bad_param,
					)
				);
				continue;
			}

			break;
		} while ( true );

		self::debug_log(
			'OpenAI Responses API response',
			'debug',
			array(
				'status_code' => $status_code,
				'response_id' => $data['id'] ?? 'none',
				'status'      => $data['status'] ?? 'unknown',
			)
		);

		if ( $status_code !== 200 ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error';
			throw new Exception( 'OpenAI API error: ' . esc_html( $error_message ) );
		}

		if ( $data['status'] !== 'completed' ) {
			throw new Exception( 'Response not completed. Status: ' . esc_html( $data['status'] ) );
		}

		$response_text  = '';
		$citations      = array();
		$search_results = array();

		if ( isset( $data['output'] ) ) {
			foreach ( $data['output'] as $output ) {
				if ( $output['type'] === 'message' && $output['role'] === 'assistant' ) {
					foreach ( $output['content'] as $content ) {
						if ( $content['type'] === 'output_text' ) {
							$response_text = $content['text'];
							if ( isset( $content['annotations'] ) ) {
								foreach ( $content['annotations'] as $annotation ) {
									$citations[] = $annotation;
								}
							}
						}
					}
				} elseif ( $output['type'] === 'file_search_call' || $output['type'] === 'web_search_call' ) {
					$search_results[] = $output;
				}
			}
		}

		return array(
			'text'           => $response_text,
			'citations'      => $citations,
			'search_results' => $search_results,
			'usage'          => $data['usage'] ?? null,
		);
	}

	public static function get_openai_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'openai_api_key' );
	}

	public static function call_openrouter_api( $prompt, $model, $imageUrls = array(), $parameters = array() ) {
		self::debug_function(
			__FUNCTION__,
			array(
				'prompt'     => $prompt,
				'model'      => $model,
				'imageUrls'  => $imageUrls,
				'parameters' => $parameters,
			)
		);

		$api_key = self::get_openrouter_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'openrouter_api_key_missing', 'OpenRouter API key is not set' );
		}

		$url     = 'https://openrouter.ai/api/v1/chat/completions';
		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			self::openrouter_headers()
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(),
			),
		);

		if ( ! empty( $prompt ) ) {
			$messages[0]['content'][] = array(
				'type' => 'text',
				'text' => $prompt,
			);
		}

		foreach ( $imageUrls as $imageUrl ) {
			if ( ! empty( $imageUrl ) ) {
				$messages[0]['content'][] = array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => $imageUrl ),
				);
			}
		}

		$body = array(
			'model'              => $model,
			'messages'           => $messages,
			'temperature'        => isset( $parameters['temperature'] ) ? floatval( $parameters['temperature'] ) : 1.0,
			'top_p'              => isset( $parameters['top_p'] ) ? floatval( $parameters['top_p'] ) : 1.0,
			'top_k'              => isset( $parameters['top_k'] ) ? intval( $parameters['top_k'] ) : null,
			'frequency_penalty'  => isset( $parameters['frequency_penalty'] ) ? floatval( $parameters['frequency_penalty'] ) : 0.0,
			'presence_penalty'   => isset( $parameters['presence_penalty'] ) ? floatval( $parameters['presence_penalty'] ) : 0.0,
			'repetition_penalty' => isset( $parameters['repetition_penalty'] ) ? floatval( $parameters['repetition_penalty'] ) : 1.0,
		);

		// Structured output (JSON-Schema). OpenRouter forwards this verbatim to
		// providers that support structured outputs; others fall back to the
		// JSON instruction appended to the prompt.
		if ( isset( $parameters['response_format'] ) && is_array( $parameters['response_format'] ) ) {
			$body['response_format'] = $parameters['response_format'];
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 600,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'OpenRouter API call failed', 'error', array( 'error' => $response->get_error_message() ) );
			return new WP_Error( 'openrouter_api_wp_error', 'WP_Error in API call: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		if ( $response_code !== 200 ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error';
			self::debug_log(
				'OpenRouter API error',
				'error',
				array(
					'http_code' => $response_code,
					'error'     => $error_message,
				)
			);
			return new WP_Error( 'openrouter_api_error', "OpenRouter API error (HTTP $response_code): $error_message" );
		}

		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			self::debug_log(
				'OpenRouter API call successful',
				'info',
				array(
					'model'       => $model,
					'image_count' => count( $imageUrls ),
					'has_usage'   => isset( $data['usage'] ),
					'usage'       => $data['usage'] ?? null,
				)
			);

			return array(
				'choices' => $data['choices'],
				'usage'   => $data['usage'] ?? null,
				'model'   => $data['model'] ?? $model,
			);
		} else {
			self::debug_log( 'Unexpected OpenRouter API response', 'error', array( 'response' => $data ) );
			return new WP_Error( 'openrouter_api_unexpected_response', 'Unexpected OpenRouter API response structure' );
		}
	}

	public static function call_perplexity_api( $prompt, $model, $temperature, $additional_params = array() ) {
		$temperature = floatval( $temperature );
		if ( $temperature < 0 || $temperature >= 2 ) {
			self::debug_log(
				'Invalid temperature value',
				'error',
				array(
					'temperature' => $temperature,
				)
			);
			return new WP_Error(
				'perplexity_api_invalid_parameter',
				"Temperature must be between 0 and 1.999. Got: $temperature"
			);
		}

		self::debug_function(
			__FUNCTION__,
			array(
				'prompt'            => $prompt,
				'model'             => $model,
				'temperature'       => $temperature,
				'additional_params' => $additional_params,
			)
		);

		$api_key = self::get_perplexity_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'perplexity_api_key_missing', 'Perplexity API key is not set' );
		}

		$model_mapping = array(
			'llama-3.1-sonar-small-128k-online' => 'sonar',
			'llama-3.1-sonar-large-128k-online' => 'sonar-pro',
			'llama-3.1-sonar-huge-128k-online'  => 'sonar-pro',
			'llama-3.1-sonar-small-128k-chat'   => 'sonar',
			'llama-3.1-sonar-large-128k-chat'   => 'sonar-pro',
		);

		$model = isset( $model_mapping[ $model ] ) ? $model_mapping[ $model ] : $model;

		$url     = 'https://api.perplexity.ai/chat/completions';
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		$system_prompt = self::get_system_prompt( $model );

		$body = array(
			'model'                    => $model,
			'messages'                 => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature'              => $temperature,
			'return_images'            => false,
			'return_related_questions' => false,
			'stream'                   => false,
		);

		if ( isset( $additional_params['top_p'] ) ) {
			$body['top_p'] = floatval( $additional_params['top_p'] );
		}

		if ( ! empty( $additional_params['searchDomainFilters'] ) ) {
			$body['search_domain_filter'] = array_values( $additional_params['searchDomainFilters'] );
		}

		if ( ! empty( $additional_params['search_recency_filter'] ) &&
			$additional_params['search_recency_filter'] !== 'any' ) {
			$body['search_recency_filter'] = $additional_params['search_recency_filter'];
		}

		// Handle mutually exclusive penalties
		if ( isset( $additional_params['frequency_penalty'] ) && $additional_params['frequency_penalty'] > 0 ) {
			$body['frequency_penalty'] = floatval( $additional_params['frequency_penalty'] );
		} elseif ( isset( $additional_params['presence_penalty'] ) && $additional_params['presence_penalty'] > 0 ) {
			$body['presence_penalty'] = floatval( $additional_params['presence_penalty'] );
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 600,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log(
				'Perplexity API call failed',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error(
				'perplexity_api_wp_error',
				'API call failed: ' . $response->get_error_message()
			);
		}

		return self::process_api_response( $response, $model, $additional_params );
	}

	private static function get_system_prompt( $model ) {
		$system_prompt = <<<'EOT'
        You are an expert research assistant focused on providing accurate, comprehensive, and well-sourced information. Adapt your response style based on the query type while maintaining these core principles:
    
        1. GENERAL GUIDELINES
        - Provide detailed, factual information with precise citations
        - Maintain an unbiased, professional tone
        - Write in the same language as the query
        - Use appropriate formatting (markdown for lists, tables, quotes)
        - Avoid subjective qualifiers or hedging language
        - Clearly distinguish between facts and analysis
        - Always include source URLs in citations when available
        - Prioritize recent and authoritative sources
    
        2. OUTPUT FORMATTING
        - Structure responses logically with clear sections
        - Use markdown for formatting when appropriate
        - Include proper citations with URLs
        - Keep paragraphs concise and focused
        EOT;

		if ( $model === 'sonar-pro' ) {
			$system_prompt .= <<<'EOT'
    
            3. ENHANCED SEARCH REQUIREMENTS
            - Perform multiple searches for comprehensive coverage
            - Prioritize high-authority sources
            - Include detailed citation metadata
            - Cross-reference information across sources
            - Verify information from multiple sources
            EOT;
		}

		if ( $model === 'sonar-reasoning' ) {
			$system_prompt .= <<<'EOT'
    
            3. REASONING REQUIREMENTS
            - Break down complex problems into clear steps
            - Show explicit chain-of-thought reasoning
            - Validate conclusions with multiple sources
            - Explain the logic behind each step
            - Consider alternative viewpoints
            EOT;
		}

		return $system_prompt;
	}


	private static function process_api_response( $response, $model, $additional_params = array() ) {
		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			self::debug_log(
				'Empty response from Perplexity API',
				'error',
				array(
					'http_code' => $response_code,
				)
			);
			return new WP_Error(
				'perplexity_api_empty_response',
				'Empty response received from API'
			);
		}

		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			self::debug_log(
				'Invalid JSON response',
				'error',
				array(
					'json_error' => json_last_error_msg(),
					'response'   => $body,
				)
			);
			return new WP_Error(
				'perplexity_api_invalid_json',
				'Invalid JSON response: ' . json_last_error_msg()
			);
		}

		if ( $response_code !== 200 ) {
			$error_message = isset( $data['error']['message'] ) ?
				$data['error']['message'] : 'Unknown error';
			self::debug_log(
				'Perplexity API error',
				'error',
				array(
					'http_code' => $response_code,
					'error'     => $error_message,
				)
			);
			return new WP_Error(
				'perplexity_api_error',
				"API error (HTTP $response_code): $error_message"
			);
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			self::debug_log(
				'Unexpected API response structure',
				'error',
				array(
					'response' => $data,
				)
			);
			return new WP_Error(
				'perplexity_api_unexpected_response',
				'Unexpected API response structure'
			);
		}

		$response_data = array(
			'choices'   => $data['choices'],
			'usage'     => $data['usage'] ?? null,
			'model'     => $data['model'] ?? $model,
			'citations' => $data['citations'] ?? array(),
		);

		if ( isset( $data['search_context'] ) ) {
			$response_data['search_context'] = $data['search_context'];
		}

		self::debug_log(
			'Perplexity API call successful',
			'info',
			array(
				'model'              => $model,
				'has_citations'      => isset( $response_data['citations'] ),
				'citations_count'    => isset( $response_data['citations'] ) ? count( $response_data['citations'] ) : 0,
				'has_search_context' => isset( $response_data['search_context'] ),
			)
		);

		return $response_data;
	}

	private static function get_perplexity_api_key() {
		self::debug_function( __FUNCTION__ );

		return self::get_healed_setting_key( 'perplexity_api_key' );
	}

	public static function verify_api_key( $request ) {
		$provided_key  = $request->get_param( 'api_key' );
		$encrypted_key = get_option( 'wp_ai_workflows_encrypted_api_key' );

		if ( wp_check_password( $provided_key, $encrypted_key ) ) {
			return new WP_REST_Response( array( 'valid' => true ), 200 );
		} else {
			return new WP_REST_Response( array( 'valid' => false ), 403 );
		}
	}

	public static function get_google_settings() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );
		return array(
			'google_client_id'     => isset( $settings['google_client_id'] ) ? self::decrypt_sensitive_data( $settings['google_client_id'] ) : '',
			'google_client_secret' => isset( $settings['google_client_secret'] ) ? self::decrypt_sensitive_data( $settings['google_client_secret'] ) : '',
			'google_redirect_uri'  => get_option( 'wp_ai_workflows_google_redirect_uri', '' ),
		);
	}

	public static function update_google_settings( $client_id, $client_secret ) {
		$settings                         = get_option( 'wp_ai_workflows_settings', array() );
		$settings['google_client_id']     = WP_AI_Workflows_Encryption::encrypt( $client_id );
		$settings['google_client_secret'] = WP_AI_Workflows_Encryption::encrypt( $client_secret );
		update_option( 'wp_ai_workflows_settings', $settings );
	}

	public static function get_google_tokens() {
		$access_token  = get_option( 'wp_ai_workflows_google_access_token' );
		$refresh_token = get_option( 'wp_ai_workflows_google_refresh_token' );
		return array(
			'access_token'  => $access_token ? WP_AI_Workflows_Encryption::decrypt( $access_token ) : null,
			'refresh_token' => $refresh_token ? WP_AI_Workflows_Encryption::decrypt( $refresh_token ) : null,
		);
	}

	public static function generate_google_redirect_uri() {
		$redirect_uri = home_url( '/wp-json/wp-ai-workflows/v1/google-auth-callback' );
		update_option( 'wp_ai_workflows_google_redirect_uri', $redirect_uri );
		return $redirect_uri;
	}

	public static function update_google_tokens( $access_token, $refresh_token ) {
		update_option( 'wp_ai_workflows_google_access_token', WP_AI_Workflows_Encryption::encrypt( $access_token ) );
		update_option( 'wp_ai_workflows_google_refresh_token', WP_AI_Workflows_Encryption::encrypt( $refresh_token ) );
	}

	public static function calculate_delay_time( $delay_value, $delay_unit ) {
		self::debug_function(
			__FUNCTION__,
			array(
				'delay_value' => $delay_value,
				'delay_unit'  => $delay_unit,
			)
		);

		$now = time();

		switch ( $delay_unit ) {
			case 'minutes':
				$delay_time = $now + ( $delay_value * MINUTE_IN_SECONDS );
				break;
			case 'hours':
				$delay_time = $now + ( $delay_value * HOUR_IN_SECONDS );
				break;
			case 'days':
				$delay_time = $now + ( $delay_value * DAY_IN_SECONDS );
				break;
			default:
				self::debug_log( 'Invalid delay unit', 'error', array( 'unit' => $delay_unit ) );
				return false;
		}

		self::debug_log(
			'Calculated delay time',
			'debug',
			array(
				'delay_value' => $delay_value,
				'delay_unit'  => $delay_unit,
				'delay_time'  => gmdate( 'Y-m-d H:i:s', $delay_time ),
			)
		);

		return $delay_time;
	}

	public static function update_execution_status( $execution_id, $status, $message = '', $node_id = '' ) {
		if ( empty( $execution_id ) ) {
			return;
		}

		// A site step has no local execution row of its own.
		if ( class_exists( 'WP_AI_Workflows_Site_Step' ) && WP_AI_Workflows_Site_Step::is_running_step() ) {
			return;
		}

		self::debug_function(
			__FUNCTION__,
			array(
				'execution_id' => $execution_id,
				'status'       => $status,
				'message'      => $message,
				'node_id'      => $node_id,
			)
		);

		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';

		$current_output = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT output_data FROM %i WHERE id = %d",
				$wpdb->prefix . 'wp_ai_workflows_executions',
				$execution_id
			)
		);

		if ( $current_output === null || $current_output === '' ) {
			self::debug_log( 'Empty or null output encountered', 'warning', array( 'execution_id' => $execution_id ) );
			$current_output = array();
		} else {
			$decoded_output = json_decode( $current_output, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				self::debug_log(
					'JSON decoding error',
					'error',
					array(
						'execution_id' => $execution_id,
						'json_error'   => json_last_error_msg(),
						'raw_output'   => $current_output,
					)
				);
			}
			$current_output = ( is_array( $decoded_output ) ) ? $decoded_output : array();
		}

		$current_output[] = array(
			'status'    => $status,
			'message'   => $message,
			'node_id'   => $node_id,
			'timestamp' => current_time( 'mysql' ),
		);

		$wpdb->update(
			$table_name,
			array(
				'status'      => $status,
				'updated_at'  => current_time( 'mysql' ),
				'output_data' => wp_json_encode( $current_output ),
			),
			array( 'id' => $execution_id )
		);

		self::debug_log(
			'Execution status updated',
			'debug',
			array(
				'execution_id' => $execution_id,
				'status'       => $status,
				'message'      => $message,
				'node_id'      => $node_id,
			)
		);
	}

	public static function migrate_to_db_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		if ( get_option( 'wp_ai_workflows_migrated_to_table', false ) ) {
			self::debug_log( 'Workflow migration already completed', 'info' );
			return true;
		}

		WP_AI_Workflows_Database::ensure_tables_exist();

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
			self::debug_log( 'Failed to create workflow table', 'error' );
			return false;
		}

		$workflows = get_option( 'wp_ai_workflows', array() );

		if ( empty( $workflows ) ) {
			update_option( 'wp_ai_workflows_migrated_to_table', true );
			self::debug_log( 'No workflows to migrate', 'info' );
			return true;
		}

		self::debug_log(
			'Starting workflow migration',
			'info',
			array(
				'workflow_count' => count( $workflows ),
			)
		);

		$wpdb->query( 'START TRANSACTION' );

		try {
			$success        = true;
			$migrated_count = 0;
			$error_count    = 0;

			foreach ( $workflows as $workflow ) {
				if ( empty( $workflow['id'] ) || empty( $workflow['name'] ) ) {
					self::debug_log(
						'Skipping invalid workflow',
						'warning',
						array(
							'workflow' => isset( $workflow['id'] ) ? $workflow['id'] : 'unknown',
						)
					);
					continue;
				}

				$workflow['status']    = isset( $workflow['status'] ) ? $workflow['status'] : 'active';
				$workflow['createdBy'] = isset( $workflow['createdBy'] ) ? $workflow['createdBy'] : 'system';
				$workflow['createdAt'] = isset( $workflow['createdAt'] ) ? $workflow['createdAt'] : current_time( 'mysql' );
				$workflow['updatedAt'] = isset( $workflow['updatedAt'] ) ? $workflow['updatedAt'] : $workflow['createdAt'];

				// Prevents duplicates when this migration runs more than once.
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE id = %s",
						$table_name,
						$workflow['id']
					)
				);

				if ( $existing > 0 ) {
					self::debug_log(
						'Workflow already exists in table, updating',
						'debug',
						array(
							'workflow_id' => $workflow['id'],
						)
					);

					$result = $wpdb->update(
						$table_name,
						array(
							'name'       => $workflow['name'],
							'status'     => $workflow['status'],
							'data'       => wp_json_encode( $workflow ),
							'created_by' => $workflow['createdBy'],
							'created_at' => $workflow['createdAt'],
							'updated_at' => $workflow['updatedAt'],
						),
						array( 'id' => $workflow['id'] )
					);
				} else {
					$result = $wpdb->insert(
						$table_name,
						array(
							'id'         => $workflow['id'],
							'name'       => $workflow['name'],
							'status'     => $workflow['status'],
							'data'       => wp_json_encode( $workflow ),
							'created_by' => $workflow['createdBy'],
							'created_at' => $workflow['createdAt'],
							'updated_at' => $workflow['updatedAt'],
						)
					);
				}

				if ( $result === false ) {
					++$error_count;
					self::debug_log(
						'Failed to migrate workflow',
						'error',
						array(
							'workflow_id' => $workflow['id'],
							'error'       => $wpdb->last_error,
						)
					);
				} else {
					++$migrated_count;
				}
			}

			if ( $error_count === 0 ) {
				$wpdb->query( 'COMMIT' );
				update_option( 'wp_ai_workflows_migrated_to_table', true );

				$result = $wpdb->query( "ALTER TABLE " . esc_sql( $table_name ) . " ADD FULLTEXT INDEX workflow_fulltext (name, data(1000000))" );
				if ( $result === false ) {
					self::debug_log(
						'Failed to add fulltext index',
						'warning',
						array(
							'error' => $wpdb->last_error,
						)
					);
				}

				self::debug_log(
					'Workflow migration completed',
					'info',
					array(
						'migrated_count'  => $migrated_count,
						'total_workflows' => count( $workflows ),
					)
				);

				return true;
			} else {
				$wpdb->query( 'ROLLBACK' );
				self::debug_log(
					'Migration failed with errors, rolling back',
					'error',
					array(
						'error_count' => $error_count,
					)
				);
				return false;
			}
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::debug_log(
				'Migration exception',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			return false;
		}
	}


	/**
	 * Migrate outputs from options to database table
	 */
	public static function migrate_outputs_to_db_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_outputs';

		$outputs = get_option( 'wp_ai_workflows_outputs', array() );

		if ( empty( $outputs ) ) {
			self::debug_log( 'No outputs to migrate', 'info' );
			return true;
		}

		self::debug_log(
			'Starting outputs migration',
			'info',
			array(
				'output_count' => count( $outputs ),
			)
		);

		$wpdb->query( 'START TRANSACTION' );

		try {
			$migrated_count   = 0;
			$skipped_count    = 0;
			$already_migrated = 0;

			foreach ( $outputs as $output_id => $output ) {
				if ( ! isset( $output['data'] ) ) {
					self::debug_log(
						'Skipping output without data',
						'warning',
						array(
							'output_id' => $output_id,
						)
					);
					++$skipped_count;
					continue;
				}

				$timestamp = isset( $output['timestamp'] ) && ! empty( $output['timestamp'] )
							? $output['timestamp']
							: current_time( 'mysql' );

				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE node_id = %s AND created_at = %s LIMIT 1",
						$table_name,
						$output_id,
						$timestamp
					)
				);

				if ( $exists ) {
					self::debug_log(
						'Output already migrated',
						'debug',
						array(
							'output_id'   => $output_id,
							'existing_id' => $exists,
							'timestamp'   => $timestamp,
						)
					);
					++$already_migrated;
					continue;
				}

				$other_versions = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE node_id = %s AND created_at != %s",
						$table_name,
						$output_id,
						$timestamp
					)
				);

				if ( $other_versions > 0 ) {
					self::debug_log(
						'Found other versions of this output',
						'info',
						array(
							'output_id'            => $output_id,
							'current_timestamp'    => $timestamp,
							'other_versions_count' => $other_versions,
						)
					);
				}

				$result = $wpdb->insert(
					$table_name,
					array(
						'node_id'     => $output_id,
						'output_data' => is_string( $output['data'] ) ? $output['data'] : wp_json_encode( $output['data'] ),
						'created_at'  => $timestamp,
					),
					array( '%s', '%s', '%s' )
				);

				if ( $result !== false ) {
					++$migrated_count;
					self::debug_log(
						'Migrated output',
						'debug',
						array(
							'output_id' => $output_id,
							'insert_id' => $wpdb->insert_id,
							'timestamp' => $timestamp,
						)
					);
				} else {
					self::debug_log(
						'Failed to migrate output',
						'error',
						array(
							'output_id' => $output_id,
							'error'     => $wpdb->last_error,
						)
					);
				}
			}

			$wpdb->query( 'COMMIT' );

			self::debug_log(
				'Outputs migration completed',
				'info',
				array(
					'total_outputs'    => count( $outputs ),
					'migrated_count'   => $migrated_count,
					'already_migrated' => $already_migrated,
					'skipped_count'    => $skipped_count,
				)
			);

			return true;
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::debug_log(
				'Outputs migration failed',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			return false;
		}
	}

	/**
	 * Check if migration is needed - Updated to check actual migration status
	 */
	public static function check_migration_status() {
		global $wpdb;

		$status = array(
			'workflows_to_migrate'    => 0,
			'outputs_to_migrate'      => 0,
			'can_cleanup'             => false,
			'workflows_option_exists' => false,
			'outputs_option_exists'   => false,
		);

		$workflows_option = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM %i WHERE option_name = %s",
				$wpdb->options,
				'wp_ai_workflows'
			)
		);

		if ( $workflows_option !== null ) {
			$status['workflows_option_exists'] = true;
			$workflows                         = maybe_unserialize( $workflows_option );

			if ( is_array( $workflows ) ) {
				$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
				$missing    = 0;

				foreach ( $workflows as $workflow ) {
					if ( isset( $workflow['id'] ) ) {
						$exists = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM %i WHERE id = %s",
								$table_name,
								$workflow['id']
							)
						);
						if ( ! $exists ) {
							++$missing;
						}
					}
				}
				$status['workflows_to_migrate'] = $missing;
			}
		}

		$outputs_option = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM %i WHERE option_name = %s",
				$wpdb->options,
				'wp_ai_workflows_outputs'
			)
		);

		if ( $outputs_option !== null ) {
			$status['outputs_option_exists'] = true;
			$outputs                         = maybe_unserialize( $outputs_option );

			if ( is_array( $outputs ) && ! empty( $outputs ) ) {
				$outputs_table = $wpdb->prefix . 'wp_ai_workflows_outputs';
				$not_migrated  = 0;

				foreach ( $outputs as $output_id => $output ) {
					$timestamp = isset( $output['timestamp'] ) && ! empty( $output['timestamp'] )
								? $output['timestamp']
								: '';

					if ( $timestamp ) {
						$exists = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM %i WHERE node_id = %s AND created_at = %s",
								$outputs_table,
								$output_id,
								$timestamp
							)
						);
					} else {
						$exists = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM %i WHERE node_id = %s",
								$outputs_table,
								$output_id
							)
						);
					}

					if ( ! $exists ) {
						++$not_migrated;
					}
				}

				$status['outputs_to_migrate'] = $not_migrated;

				self::debug_log(
					'Output migration status',
					'debug',
					array(
						'total_in_options' => count( $outputs ),
						'not_migrated'     => $not_migrated,
					)
				);
			}
		}

		// Can cleanup if options exist but nothing left to migrate
		$status['can_cleanup'] = (
			( $status['workflows_option_exists'] || $status['outputs_option_exists'] ) &&
			$status['workflows_to_migrate'] == 0 &&
			$status['outputs_to_migrate'] == 0
		);

		self::debug_log( 'Migration status check complete', 'debug', $status );

		return $status;
	}

	/**
	 * Clean up options after successful migration
	 */
	public static function cleanup_migrated_options() {
		global $wpdb;
		$status = self::check_migration_status();

		if ( ! $status['workflows_option_exists'] && ! $status['outputs_option_exists'] ) {
			return array(
				'success' => true,
				'message' => 'Options have already been cleaned up',
				'cleaned' => array(),
			);
		}

		if ( ! $status['can_cleanup'] ) {
			return array(
				'success' => false,
				'message' => 'Cannot cleanup - migration not complete. Workflows: ' . $status['workflows_to_migrate'] . ', Outputs: ' . $status['outputs_to_migrate'],
			);
		}

		$cleaned = array();

		if ( $status['workflows_option_exists'] && $status['workflows_to_migrate'] == 0 ) {
			delete_option( 'wp_ai_workflows' );
			$cleaned[] = 'workflows';
		}

		if ( $status['outputs_option_exists'] && $status['outputs_to_migrate'] == 0 ) {
			delete_option( 'wp_ai_workflows_outputs' );
			$cleaned[] = 'outputs';
		}

		self::debug_log(
			'Options cleanup completed',
			'info',
			array(
				'cleaned' => $cleaned,
			)
		);

		return array(
			'success' => true,
			'message' => empty( $cleaned ) ? 'Nothing to clean up' : 'Cleanup completed successfully',
			'cleaned' => $cleaned,
		);
	}
}
