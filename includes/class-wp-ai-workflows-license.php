<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_License {

	/**
	 * Persist the legacy license key locally. In v2.0 there is NO remote
	 * activation and NO shared secret - the key is simply stored so the platform
	 * client can redeem it server-side when the site connects to an account.
	 *
	 * @param string $license_key Raw key from the request.
	 * @return array{success:bool,message:string,error_code:?string}
	 */
	public function activate_license( $license_key ) {
		$license_key = sanitize_text_field( (string) $license_key );

		if ( '' === $license_key ) {
			return array(
				'success'    => false,
				'message'    => 'License key is required.',
				'error_code' => 'missing_key',
			);
		}

		update_option( 'wp_ai_workflows_license_key', $license_key );

		return array(
			'success'    => true,
			'message'    => 'License key saved. Connect your platform account to redeem it.',
			'error_code' => null,
		);
	}

	/**
	 * Local-only deactivation: clears the stored legacy license options. No remote
	 * call is made (the SLM server is retired).
	 *
	 * @return array{success:bool,message:string,error_code:?string}
	 */
	public function deactivate_license() {
		$this->cleanup_license_data();

		return array(
			'success'    => true,
			'message'    => 'Local license data cleared.',
			'error_code' => null,
		);
	}

	/**
	 * Free-first: no remote license check, no token, no HTTP. Returns the locally
	 * known state so legacy callers (scheduled cron, update-check) keep working.
	 *
	 * @param string|null $license_key Unused; accepted for signature back-compat.
	 * @return array{is_active:bool,token:null}
	 */
	public function check_license( $license_key = null ) {
		return array(
			'is_active' => (bool) get_option( 'wp_ai_workflows_last_license_state', false ),
			'token'     => null,
		);
	}

	/**
	 * Local license-state read. Delegates to the (secret-free) secure-storage
	 * verifier when available, else falls back to the stored flag.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( class_exists( 'WP_AI_Workflows_License_Security' ) ) {
			return WP_AI_Workflows_License_Security::verify_license_state();
		}

		return (bool) get_option( 'wp_ai_workflows_last_license_state', false );
	}

	/**
	 * The locally stored legacy license key (also read by the platform client for
	 * server-side redemption).
	 *
	 * @return string
	 */
	public function get_license_key() {
		return get_option( 'wp_ai_workflows_license_key', '' );
	}

	/**
	 * @return string
	 */
	public function get_license_expiry() {
		return get_option( 'wp_ai_workflows_license_expiry', '' );
	}

	/**
	 * Update the stored legacy license key.
	 *
	 * @param string $license_key Raw key.
	 * @return bool
	 */
	public function update_license_key( $license_key ) {
		return (bool) update_option( 'wp_ai_workflows_license_key', sanitize_text_field( (string) $license_key ) );
	}

	/**
	 * Local-only license detail snapshot. No remote call in v2.0 (agency/domain
	 * data is now owned by the platform account, not the SLM server).
	 *
	 * @return array<string,mixed>
	 */
	public function get_license_details() {
		$license_key = $this->get_license_key();

		return array(
			'is_active'    => $this->is_active(),
			'is_agency'    => false,
			'max_domains'  => 0,
			'used_domains' => 0,
			'expiry_date'  => $this->get_license_expiry(),
			'error'        => '' === $license_key ? 'No license key' : null,
		);
	}

	/**
	 * Remove all locally stored legacy license options/transients.
	 *
	 * @return void
	 */
	private function cleanup_license_data() {
		delete_option( 'wp_ai_workflows_license_key' );
		delete_option( 'wp_ai_workflows_license_status' );
		delete_option( 'wp_ai_workflows_license_expiry' );
		delete_option( 'wp_ai_workflows_license_token' );
		delete_option( 'wp_ai_workflows_license_token_expiry' );
		delete_option( 'wp_ai_workflows_last_license_state' );
		delete_transient( 'wp_ai_workflows_license_token' );
		delete_option( 'wp_ai_workflows_check_fail_count' );
		delete_option( 'wp_ai_workflows_last_failed_check' );
	}
}
