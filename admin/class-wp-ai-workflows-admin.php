<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-specific functionality of the plugin.
 */
class WP_AI_Workflows_Admin {
	const OPTION_WELCOME_NOTICE = 'wp_ai_workflows_welcome_notice';
	const DISMISS_WELCOME_ACTION = 'wp_ai_workflows_dismiss_welcome';
	const OPTION_ANALYTICS_NOTICE_DISMISSED = 'wp_ai_workflows_analytics_notice_dismissed';
	const ANALYTICS_NOTICE_ACTION = 'wp_ai_workflows_analytics_choice';

	private $license_manager;

	public function init() {
		$this->license_manager = new WP_AI_Workflows_License();
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_wp_ai_workflows_activate_license', array( $this, 'ajax_activate_license' ) );
		add_action( 'wp_ajax_wp_ai_workflows_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
		add_action( 'wp_ajax_wp_ai_workflows_update_menu_count', array( $this, 'ajax_update_menu_count' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_legacy_welcome_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_welcome_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_analytics_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_welcome_notice_dismiss' ) );
		add_action( 'admin_post_wp_ai_workflows_analytics_choice', array( $this, 'handle_analytics_notice_choice' ) );
	}

	/**
	 * First-run notice pointing a freshly activated site at the plugin.
	 *
	 * @return void
	 */
	public function maybe_render_welcome_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( self::OPTION_WELCOME_NOTICE, 0 ) ) {
			return;
		}

		$open_url = add_query_arg(
			array(
				'page'                    => 'wp-ai-workflows',
				self::DISMISS_WELCOME_ACTION => 1,
				'_wpnonce'                => wp_create_nonce( self::DISMISS_WELCOME_ACTION ),
			),
			admin_url( 'admin.php' )
		);

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_WELCOME_ACTION, 1 ),
			self::DISMISS_WELCOME_ACTION
		);

		printf(
			'<div class="notice notice-success is-dismissible wpaw-welcome-notice"><p>%1$s <a href="%2$s"><strong>%3$s</strong></a> <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'AI Workflow Automation is ready. Build your first automation, or claim 50 free cloud credits.', 'ai-workflow-automation-lite' ),
			esc_url( $open_url ),
			esc_html__( 'Open AI Workflows', 'ai-workflow-automation-lite' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'ai-workflow-automation-lite' )
		);
	}

	/**
	 * Persist the welcome-notice dismissal.
	 *
	 * @return void
	 */
	public function handle_welcome_notice_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_WELCOME_ACTION ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_WELCOME_ACTION ) ) {
			return;
		}

		update_option( self::OPTION_WELCOME_NOTICE, 0, false );
	}

	/**
	 * One-time, dismissible opt-in notice for anonymous usage analytics. Shown
	 * only to a site that already finished onboarding (new users get the
	 * checkbox in onboarding instead).
	 *
	 * @return void
	 */
	public function maybe_render_analytics_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, array( 'toplevel_page_wp-ai-workflows', 'toplevel_page_wp-ai-workflows-tasks' ), true ) ) {
			return;
		}
		if ( get_option( self::OPTION_ANALYTICS_NOTICE_DISMISSED, false ) ) {
			return;
		}
		if ( get_option( 'wp_ai_workflows_analytics_opt_in', false ) ) {
			return;
		}
		if ( ! get_option( 'wp_ai_workflows_setup_completed' ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ANALYTICS_NOTICE_ACTION ); ?>
				<input type="hidden" name="action" value="wp_ai_workflows_analytics_choice" />
				<p>
					<input type="checkbox" name="analytics_opt_in" value="1" id="wpaw-analytics-optin" />
					<label for="wpaw-analytics-optin">
						<?php
						printf(
							'<strong>%1$s</strong> %2$s %3$s. %4$s',
							esc_html__( 'Share anonymous usage data.', 'ai-workflow-automation-lite' ),
							esc_html__( 'Send anonymous usage events such as "created a workflow" so we can see where people get stuck: never your content, prompts, API keys, email address or site name.', 'ai-workflow-automation-lite' ),
							sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								esc_url( 'https://wpaiworkflowautomation.com/privacy-policy/' ),
								esc_html__( 'Privacy policy', 'ai-workflow-automation-lite' )
							),
							esc_html__( 'You can change this any time in Settings.', 'ai-workflow-automation-lite' )
						);
						?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save choice', 'ai-workflow-automation-lite' ); ?></button>
					<button type="submit" name="dismiss" value="1" class="button-link"><?php esc_html_e( 'Not now', 'ai-workflow-automation-lite' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the analytics opt-in choice from the notice form.
	 *
	 * @return void
	 */
	public function handle_analytics_notice_choice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-workflow-automation-lite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ANALYTICS_NOTICE_ACTION );

		update_option( self::OPTION_ANALYTICS_NOTICE_DISMISSED, 1, false );

		$dismissed = isset( $_POST['dismiss'] ) ? sanitize_text_field( wp_unslash( $_POST['dismiss'] ) ) : '';
		$opted_in  = isset( $_POST['analytics_opt_in'] ) ? sanitize_text_field( wp_unslash( $_POST['analytics_opt_in'] ) ) : '';

		if ( '' === $dismissed && '' !== $opted_in ) {
			update_option( 'wp_ai_workflows_analytics_opt_in', true );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=wp-ai-workflows' ) );
		exit;
	}

	/**
	 * One-time "welcome back" notice for a 1.x customer whose license was
	 * auto-redeemed during the v2.0 migration. Shown once; stays silent on
	 * any non-grant outcome so an automatic attempt never surfaces an error.
	 *
	 * @return void
	 */
	public function maybe_render_legacy_welcome_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( WP_AI_Workflows_Platform_Client::OPTION_LEGACY_NOTICE, '' ) ) {
			return; // Already shown once.
		}
		$record = WP_AI_Workflows_Platform_Client::get_legacy_redeem_result();
		if ( ! is_array( $record ) || ! isset( $record['state'] ) || 'done' !== $record['state'] ) {
			return; // Only a genuine grant gets a celebratory notice.
		}

		$mechanism = isset( $record['mechanism'] ) ? (string) $record['mechanism'] : '';
		if ( 'monthly' === $mechanism ) {
			$monthly    = isset( $record['monthlyCredits'] ) ? (int) $record['monthlyCredits'] : 0;
			$tier       = isset( $record['tier'] ) ? (string) $record['tier'] : '';
			$tier_label = ( 'business' === $tier ) ? 'Business' : 'Pro';
			$ends_raw   = isset( $record['endsAt'] ) ? (string) $record['endsAt'] : '';
			$ends_ts    = '' !== $ends_raw ? strtotime( $ends_raw ) : false;
			$ends_fmt   = $ends_ts ? date_i18n( get_option( 'date_format' ), $ends_ts ) : '';
			$message    = sprintf(
				/* translators: 1: monthly credit amount, 2: plan tier (Pro/Business), 3: optional " until <date>". */
				'Welcome back! Your existing license gives you %1$s credits/month (plus %2$s features)%3$s.',
				number_format_i18n( $monthly ),
				$tier_label,
				'' !== $ends_fmt ? ' until ' . $ends_fmt : ''
			);
		} else {
			$granted = isset( $record['granted'] ) ? (int) $record['granted'] : 0;
			$message = sprintf(
				/* translators: %s: goodwill credit gift amount. */
				'Welcome back. Your license expired, so we\'ve added a %s-credit gift to get you started.',
				number_format_i18n( $granted > 0 ? $granted : 500 )
			);
		}

		printf(
			'<div class="notice notice-success is-dismissible wpaw-legacy-welcome"><p>🎉 %s</p></div>',
			esc_html( $message )
		);

		// One-time: never show this notice again.
		update_option( WP_AI_Workflows_Platform_Client::OPTION_LEGACY_NOTICE, time(), false );
	}

	public function add_admin_menu() {
		$capability = 'manage_options';
		WP_AI_Workflows_Utilities::debug_log(
			'Adding admin menu page',
			'debug',
			array(
				'capability'          => $capability,
				'user_has_capability' => current_user_can( $capability ),
			)
		);

		$icon_url = WP_AI_WORKFLOWS_PLUGIN_URL . 'images/AWAIcon.png';

		if ( current_user_can( 'manage_options' ) ) {
			add_menu_page(
				'AI Workflows',
				'AI Workflows',
				'manage_options',
				'wp-ai-workflows',
				array( $this, 'render_app' ),
				$icon_url,
				30
			);
		}

		if ( current_user_can( 'manage_workflow_tasks' ) ) {
			$pending_tasks_count = $this->get_pending_tasks_count();
			$menu_title          = 'Tasks';
			if ( $pending_tasks_count > 0 ) {
				$menu_title .= " <span class='update-plugins count-{$pending_tasks_count}'><span class='plugin-count'>" .
					number_format_i18n( $pending_tasks_count ) . '</span></span>';
			}

			add_menu_page(
				'My Tasks',
				$menu_title,
				'manage_workflow_tasks',
				'wp-ai-workflows-tasks',
				array( $this, 'render_tasks_page' ),
				'dashicons-clipboard',
				31
			);
		}
	}

	public function render_tasks_page() {
		echo '<div id="wp-ai-workflows-root"></div>';

		echo '<div style="text-align: center; margin-top: 20px; padding: 10px; border-top: 1px solid #ccc;">';
		echo '&copy; ' . esc_html( gmdate( 'Y' ) ) . ' AI Workflow Automation. All rights reserved.';
		echo '</div>';
	}

	public function enqueue_admin_scripts( $hook ) {

		if ( ! in_array( $hook, array( 'toplevel_page_wp-ai-workflows', 'toplevel_page_wp-ai-workflows-tasks' ) ) ) {
			return;
		}

		// Enqueue WordPress media library for custom logo upload in ChatNode
		wp_enqueue_media();

		$js_files  = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/js/main.*.js' );
		$css_files = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/css/main.*.css' );

		if ( ! empty( $js_files ) ) {
			wp_enqueue_script(
				'wp-ai-workflows-app',
				WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/js/' . basename( $js_files[0] ),
				array(),
				WP_AI_WORKFLOWS_PRO_VERSION,
				true
			);

			$settings = $this->get_localized_settings(
				array(
					'page' => $hook === 'toplevel_page_wp-ai-workflows-tasks' ? 'tasks' : 'management',
				)
			);

			wp_localize_script( 'wp-ai-workflows-app', 'wpAiWorkflowsSettings', $settings );

			$whitelabel_settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
			wp_localize_script( 'wp-ai-workflows-app', 'wpAiWorkflowsWhitelabel', $whitelabel_settings );
		}

		if ( ! empty( $css_files ) ) {
			wp_enqueue_style(
				'wp-ai-workflows-app',
				WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/css/' . basename( $css_files[0] ),
				array(),
				WP_AI_WORKFLOWS_PRO_VERSION
			);
		}

		if ( $hook === 'toplevel_page_wp-ai-workflows-tasks' ) {
			wp_enqueue_script(
				'wp-ai-workflows-menu-update',
				WP_AI_WORKFLOWS_PLUGIN_URL . 'assets/js/menu-update.js',
				array( 'jquery' ),
				WP_AI_WORKFLOWS_PRO_VERSION,
				true
			);

			wp_localize_script(
				'wp-ai-workflows-menu-update',
				'wpAiWorkflowsMenu',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_ai_workflows_menu_nonce' ),
				)
			);
		}
	}

	public function render_app() {

		$page   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'wp-ai-workflows';
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'management';

		WP_AI_Workflows_Utilities::debug_log(
			'Attempting to render admin app',
			'debug',
			array(
				'page'       => $page,
				'action'     => $action,
				'user_id'    => get_current_user_id(),
				'user_roles' => wp_get_current_user()->roles,
			)
		);

		if ( $page !== 'wp-ai-workflows' ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Attempted to render admin app on incorrect page',
				'warning',
				array(
					'current_page' => $page,
				)
			);
			return;
		}

		$current_page = $action;
		$workflow_id  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : null;

		$workflow_data = null;
		if ( ( $current_page === 'edit' || $current_page === 'builder' ) && $workflow_id ) {
			try {
				$request = new WP_REST_Request( 'GET', '/wp-ai-workflows/v1/workflows/' . $workflow_id );
				$request->set_param( 'id', $workflow_id );
				$response = rest_do_request( $request );

				if ( $response->is_error() ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error fetching workflow',
						'error',
						array(
							'workflow_id' => $workflow_id,
							'error'       => $response->get_error_message(),
						)
					);
				} else {
					$workflow_data = $response->get_data();
				}
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Exception while fetching workflow',
					'error',
					array(
						'workflow_id' => $workflow_id,
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		$settings = $this->get_localized_settings(
			array(
				'page'          => $current_page,
				'workflowData'  => $workflow_data,
				'licenseStatus' => get_option( 'wp_ai_workflows_license_status', 'inactive' ),
			)
		);

		wp_localize_script( 'wp-ai-workflows-app', 'wpAiWorkflowsSettings', $settings );

		WP_AI_Workflows_Utilities::debug_log(
			'Rendering admin app',
			'debug',
			array(
				'page'              => $current_page,
				'workflow_id'       => $workflow_id,
				'has_workflow_data' => ! is_null( $workflow_data ),
			)
		);

		echo '<div id="wp-ai-workflows-root"></div>';

		echo '<div style="text-align: center; margin-top: 20px; padding: 10px; border-top: 1px solid #ccc;">';
		echo '&copy; ' . esc_html( gmdate( 'Y' ) ) . ' WP AI Workflow Automation. All rights reserved.';
		echo '</div>';
	}

	/**
	 * Build the shared `wpAiWorkflowsSettings` object localized to the admin
	 * app bundle.
	 *
	 * Called from both `enqueue_admin_scripts()` and `render_app()`, which each
	 * call `wp_localize_script()` with the same object name - the later call
	 * silently overwrites the earlier one, so routing both through this method
	 * keeps the base keys consistent between them.
	 *
	 * @param array $extra Page-specific keys to merge over the shared base.
	 * @return array
	 */
	private function get_localized_settings( array $extra = array() ) {
		$base = array(
			'root'            => esc_url_raw( rest_url() ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'adminUrl'        => admin_url(),
			// Used by the sample cloud workflow's prompt so the first run produces
			// something about this site rather than generic filler.
			'siteName'        => sanitize_text_field( get_bloginfo( 'name' ) ),
			'installation_id' => get_option( 'wp_ai_workflows_installation_id', '' ),
			// Whether WooCommerce is active - gates the agent's product-search
			// capability in the chat node UI (hidden when Woo is absent).
			'wooActive'       => class_exists( 'WooCommerce' ),
			// Whether this site is connected to a platform account - drives the
			// chat node's "AI source" default (Credits when connected at creation,
			// else BYOK) and its disconnected-Credits warning.
			'platformConnected' => class_exists( 'WP_AI_Workflows_Platform_Client' )
				&& WP_AI_Workflows_Platform_Client::is_connected(),
			// Gates the frontend's diagnostic logger (frontend/src/utils/debug.js).
			// FALSE by default; set WP_AI_WORKFLOWS_DEBUG in wp-config.php to enable.
			// Kept independent of WP_DEBUG so a debugging dev site doesn't spam the UI.
			'debug'             => defined( 'WP_AI_WORKFLOWS_DEBUG' ) && WP_AI_WORKFLOWS_DEBUG,
			// Per-user new-user onboarding state (welcome walkthrough). Localized
			// so <OnboardingGuide/> can gate the first-run overlay with zero flash
			// (no mount-time round-trip). Written via REST POST /onboarding.
			'onboarding_completed' => (bool) get_user_meta( get_current_user_id(), 'wpaw_onboarding_completed', true ),
			'analytics_opt_in'     => (bool) get_option( 'wp_ai_workflows_analytics_opt_in', false ),
		);

		return array_merge( $base, $extra );
	}

	public function ajax_activate_license() {
		check_ajax_referer( 'wp_ai_workflows_nonce', 'nonce' );
		if ( ! isset( $_POST['license_key'] ) ) {
			wp_send_json_error( 'License key is missing' );
		}
		$license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ) );
		$result      = $this->license_manager->activate_license( $license_key );
		wp_send_json( array( 'success' => $result ) );
	}

	public function ajax_deactivate_license() {
		check_ajax_referer( 'wp_ai_workflows_nonce', 'nonce' );
		$result = $this->license_manager->deactivate_license();
		wp_send_json( array( 'success' => $result ) );
	}

	public function ajax_update_menu_count() {
		check_ajax_referer( 'wp_ai_workflows_menu_nonce', 'nonce' );
		$count = $this->get_pending_tasks_count();
		wp_send_json_success( array( 'count' => $count ) );
	}

	private function get_pending_tasks_count() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_human_tasks';

		$user_id    = get_current_user_id();
		$user       = get_userdata( $user_id );
		$user_roles = $user->roles;

		$placeholders = implode( ', ', array_fill( 0, count( $user_roles ), '%s' ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
            WHERE (assigned_user_id = %d OR (assigned_role IN ($placeholders) AND assigned_role IS NOT NULL))
            AND status = %s",
				array_merge( array( $table_name, $user_id ), $user_roles, array( 'pending' ) )
			)
		);

		return $count;
	}
}
