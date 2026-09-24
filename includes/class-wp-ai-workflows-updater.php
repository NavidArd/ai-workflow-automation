<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Updater {
	private static $instance = null;
	private $api_url;
	private $plugin_slug;
	private $version;
	private $cache_key;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// wp.org Guideline 8: the directory-hosted build must not ship an
		// external update mechanism. Under that slug, register nothing.
		if ( 'ai-workflow-automation-lite' === strtok( WP_AI_WORKFLOWS_PRO_BASENAME, '/' ) ) {
			return;
		}

		$this->plugin_slug = WP_AI_WORKFLOWS_PRO_BASENAME;
		$this->version     = WP_AI_WORKFLOWS_PRO_VERSION;
		$this->cache_key   = 'wp_ai_workflows_pro_update_check';
		$this->api_url     = 'https://wpaiworkflowautomation.com/wp-json/wp-ai-workflows/v1/update-check/pro';

		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
	}

	public function request() {
		$cached_result = get_transient( $this->cache_key );
		if ( false !== $cached_result ) {
			return $cached_result;
		}

		// Phase 3 (R6.3): the update check is UNAUTHENTICATED — no SLM key/secret
		// params are ever sent. When the site is connected, the platform site key
		// MAY be attached as a bearer header for download-entitlement only; it never
		// gates the metadata check itself (a disconnected free build still updates).
		$headers = array( 'Accept' => 'application/json' );
		if ( class_exists( 'WP_AI_Workflows_Platform_Client' )
			&& WP_AI_Workflows_Platform_Client::is_connected() ) {
			$bearer = WP_AI_Workflows_Platform_Client::get_update_authorization_header();
			if ( is_string( $bearer ) && '' !== $bearer ) {
				$headers['Authorization'] = $bearer;
			}
		}

		$remote = wp_remote_get(
			$this->api_url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if (
			is_wp_error( $remote )
			|| 200 !== wp_remote_retrieve_response_code( $remote )
			|| empty( wp_remote_retrieve_body( $remote ) )
		) {
			set_transient( $this->cache_key, false, HOUR_IN_SECONDS );
			return false;
		}

		$remote = json_decode( wp_remote_retrieve_body( $remote ) );
		set_transient( $this->cache_key, $remote, HOUR_IN_SECONDS );

		return $remote;
	}


	function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( $this->plugin_slug !== $args->slug ) {
			return $res;
		}

		$remote = $this->request();

		if ( ! $remote ) {
			return $res;
		}

		$res = new stdClass();

		$res->name           = $remote->name ?? '';
		$res->slug           = $remote->slug ?? '';
		$res->version        = $remote->version ?? '';
		$res->tested         = $remote->tested ?? '';
		$res->requires       = $remote->requires ?? '';
		$res->author         = $remote->author ?? '';
		$res->author_profile = $remote->author_profile ?? '';
		$res->download_link  = $remote->download_url ?? '';
		$res->trunk          = $remote->download_url ?? '';
		$res->requires_php   = $remote->requires_php ?? '';
		$res->last_updated   = $remote->last_updated ?? '';

		$res->sections = array(
			'description'  => $remote->sections->description ?? '',
			'installation' => $remote->sections->installation ?? '',
			'changelog'    => $remote->sections->changelog ?? '',
		);

		if ( ! empty( $remote->banners ) ) {
			$res->banners = array(
				'low'  => $remote->banners->low ?? '',
				'high' => $remote->banners->high ?? '',
			);
		}

		return $res;
	}

	public function update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->request();

		if (
			$remote
			&& version_compare( $this->version, $remote->version, '<' )
			&& version_compare( $remote->requires, get_bloginfo( 'version' ), '<=' )
		) {
			$res              = new stdClass();
			$res->slug        = $this->plugin_slug;
			$res->plugin      = WP_AI_WORKFLOWS_PRO_BASENAME;
			$res->new_version = $remote->version;
			$res->tested      = $remote->tested;
			$res->package     = $remote->download_url;

			$transient->response[ $res->plugin ] = $res;
		}

		return $transient;
	}

	public function purge( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}
}
