<?php
/**
 * One-click switch from the self-distributed (pro-channel) build to the
 * WordPress.org build. Registers only under a non-wp.org folder slug; offers a
 * guarded, downgrade-safe switch that installs and activates the wp.org copy.
 * The legacy guard in the newly activated copy deactivates this old copy.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_WPOrg_Migration {

	const LITE_SLUG      = 'ai-workflow-automation-lite';
	const LITE_BASENAME  = 'ai-workflow-automation-lite/wp-ai-workflows.php';
	const INFO_TRANSIENT = 'wp_ai_workflows_wporg_info';
	const DISMISS_META   = 'wp_ai_workflows_wporg_dismissed_major';
	const SWITCH_ACTION  = 'wp_ai_workflows_wporg_switch';
	const DISMISS_ACTION = 'wp_ai_workflows_wporg_dismiss';
	const MSG_PREFIX     = 'wp_ai_workflows_wporg_msg_';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Inverse of the updater guard: the wp.org build must never offer to
		// migrate to itself.
		if ( self::LITE_SLUG === strtok( WP_AI_WORKFLOWS_PRO_BASENAME, '/' ) ) {
			return;
		}

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_notices', array( $this, 'maybe_render_offer_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_result_notice' ) );
		add_action( 'admin_post_' . self::SWITCH_ACTION, array( $this, 'handle_switch' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	public function maybe_render_offer_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->is_target_screen() ) {
			return;
		}
		if ( ! $this->should_offer() ) {
			return;
		}

		$switch_url  = admin_url( 'admin-post.php' );
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::DISMISS_ACTION
		);
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'AI Workflow Automation is now on WordPress.org', 'wp-ai-workflows' ); ?></strong></p>
			<p><?php esc_html_e( 'Switch once to get automatic updates from WordPress.org. Your workflows and settings are preserved.', 'wp-ai-workflows' ); ?></p>
			<p>
				<form method="post" action="<?php echo esc_url( $switch_url ); ?>" style="display:inline-block;margin-right:6px;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SWITCH_ACTION ); ?>" />
					<?php wp_nonce_field( self::SWITCH_ACTION ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Switch now', 'wp-ai-workflows' ); ?></button>
				</form>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'wp-ai-workflows' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function maybe_render_result_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Read-only status flag from our own post-switch redirect; drives which
		// static message renders and changes no state.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wp_ai_wporg_switch'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['wp_ai_wporg_switch'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $status || 'already' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Switched. Updates now come from WordPress.org. You can delete the old copy from the Plugins screen.', 'wp-ai-workflows' );
			echo '</p></div>';
			return;
		}

		if ( 'error' === $status ) {
			$message = get_transient( self::MSG_PREFIX . get_current_user_id() );
			delete_transient( self::MSG_PREFIX . get_current_user_id() );

			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'The switch to WordPress.org could not be completed. Your current plugin is unchanged.', 'wp-ai-workflows' );
			if ( is_string( $message ) && '' !== $message ) {
				echo ' ' . esc_html( $message );
			}
			echo '</p></div>';
		}
	}

	public function handle_switch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-ai-workflows' ), 403 );
		}
		check_admin_referer( self::SWITCH_ACTION );

		$this->ensure_plugin_functions();

		if ( $this->lite_is_installed() ) {
			if ( is_plugin_active( self::LITE_BASENAME ) ) {
				$this->redirect_with_status( 'already' );
			}
			$activated = activate_plugin( self::LITE_BASENAME );
			if ( is_wp_error( $activated ) ) {
				$this->redirect_with_status( 'error', $activated->get_error_message() );
			}
			$this->redirect_with_status( 'success' );
		}

		$info     = $this->get_remote_info();
		$download = ( $info && ! empty( $info->download_link ) ) ? $info->download_link : '';
		if ( '' === $download ) {
			$this->redirect_with_status( 'error', __( 'The WordPress.org download URL could not be determined.', 'wp-ai-workflows' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Without direct filesystem access an install needs FTP/SSH credentials
		// we cannot collect here; hand off to the core install screen instead of
		// half-completing the switch.
		if ( 'direct' !== get_filesystem_method() ) {
			wp_safe_redirect( $this->core_install_url() );
			exit;
		}

		$result = $this->install_and_activate( $download );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( 'error', $result->get_error_message() );
		}

		$this->redirect_with_status( 'success' );
	}

	public function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-ai-workflows' ), 403 );
		}
		check_admin_referer( self::DISMISS_ACTION );

		update_user_meta( get_current_user_id(), self::DISMISS_META, $this->current_major() );

		$target = wp_get_referer();
		if ( ! $target ) {
			$target = admin_url( 'plugins.php' );
		}
		wp_safe_redirect( $target );
		exit;
	}

	private function install_and_activate( $download ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'fs_unavailable', __( 'The filesystem could not be initialized.', 'wp-ai-workflows' ) );
		}

		$skin      = new WP_Ajax_Upgrader_Skin();
		$upgrader  = new Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $download );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		$skin_errors = $skin->get_errors();
		if ( is_wp_error( $skin_errors ) && $skin_errors->has_errors() ) {
			return $skin_errors;
		}
		if ( true !== $installed ) {
			return new WP_Error( 'install_failed', __( 'The WordPress.org copy could not be installed.', 'wp-ai-workflows' ) );
		}

		$activated = activate_plugin( self::LITE_BASENAME );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}

		return true;
	}

	private function should_offer() {
		$this->ensure_plugin_functions();

		if ( is_plugin_active( self::LITE_BASENAME ) ) {
			return false;
		}
		if ( $this->is_dismissed() ) {
			return false;
		}

		$info = $this->get_remote_info();
		if ( ! $info || empty( $info->version ) ) {
			return false;
		}

		// Downgrade protection: only offer once wp.org serves a version at least
		// as new as the installed one.
		return version_compare( $info->version, WP_AI_WORKFLOWS_PRO_VERSION, '>=' );
	}

	private function get_remote_info() {
		$cached = get_transient( self::INFO_TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => self::LITE_SLUG,
				'fields' => array( 'sections' => false ),
			)
		);

		$data               = new stdClass();
		$data->version      = ( ! is_wp_error( $api ) && ! empty( $api->version ) ) ? $api->version : '';
		$data->download_link = ( ! is_wp_error( $api ) && ! empty( $api->download_link ) ) ? $api->download_link : '';

		$ttl = ( '' === $data->version ) ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS;
		set_transient( self::INFO_TRANSIENT, $data, $ttl );

		return $data;
	}

	private function is_target_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$targets = array(
			'plugins',
			'plugins-network',
			'toplevel_page_wp-ai-workflows',
			'toplevel_page_wp-ai-workflows-tasks',
		);
		return in_array( $screen->id, $targets, true );
	}

	private function is_dismissed() {
		$dismissed = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		return $dismissed >= $this->current_major();
	}

	private function current_major() {
		return (int) strtok( WP_AI_WORKFLOWS_PRO_VERSION, '.' );
	}

	private function lite_is_installed() {
		$this->ensure_plugin_functions();
		$plugins = get_plugins();
		return isset( $plugins[ self::LITE_BASENAME ] );
	}

	private function core_install_url() {
		$url = self_admin_url( 'update.php?action=install-plugin&plugin=' . self::LITE_SLUG );
		return wp_nonce_url( $url, 'install-plugin_' . self::LITE_SLUG );
	}

	private function ensure_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	private function redirect_with_status( $status, $message = '' ) {
		if ( '' !== $message ) {
			set_transient( self::MSG_PREFIX . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		}

		$target = wp_get_referer();
		if ( ! $target ) {
			$target = admin_url( 'plugins.php' );
		}
		$target = add_query_arg( 'wp_ai_wporg_switch', $status, $target );

		wp_safe_redirect( $target );
		exit;
	}
}
