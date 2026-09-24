<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Whitelabel {

	public function __construct() {
	}

	/**
	 * Initialize the class
	 * This method is required for all component classes
	 */
	public function init() {
		$this->init_hooks();
	}

	public function init_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'apply_whitelabel_styles' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'apply_custom_branding' ), 100 );

		add_filter( 'wp_ai_workflows_email_template', array( $this, 'customize_email_template' ), 10, 2 );
		add_filter( 'wp_ai_workflows_email_subject', array( $this, 'customize_email_subject' ), 10, 1 );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_replace_logo' ), 999 );
	}

	/**
	 * Get whitelabel settings.
	 *
	 * NOTE: access control lives in the REST layer
	 * (WP_AI_Workflows_Platform_Client::whitelabel_management_gate() enforces
	 * connected + Business tier + org owner with a live management session). The
	 * retired SLM "agency license" check has been removed — there is no license path
	 * to white-labeling anymore.
	 */
	public function get_whitelabel_settings() {
		$settings = get_option(
			'wp_ai_workflows_whitelabel_settings',
			array(
				'enable_whitelabel'     => false,
				'logo_url'              => '',
				'header_text'           => 'AI Workflow Automation',
				'primary_color'         => '#1890ff',
				'menu_bg_color'         => '#001529',
				'menu_text_color'       => '#ffffff',
				'custom_email_subject'  => 'New Task Awaiting Your Input',
				'custom_email_template' => '',
			)
		);

		return $settings;
	}

	/**
	 * Update whitelabel settings
	 */
	public function update_whitelabel_settings( $request ) {
		// Access control is enforced by the REST layer (whitelabel_management_gate:
		// connected + Business tier + org owner with a live management session).
		$params = $request->get_params();

		$settings = array(
			'enable_whitelabel'     => isset( $params['enable_whitelabel'] ) ? (bool) $params['enable_whitelabel'] : false,
			'logo_url'              => isset( $params['logo_url'] ) ? sanitize_url( $params['logo_url'] ) : '',
			'header_text'           => isset( $params['header_text'] ) ? sanitize_text_field( $params['header_text'] ) : 'AI Workflow Automation',
			'primary_color'         => isset( $params['primary_color'] ) ? sanitize_text_field( $params['primary_color'] ) : '#1890ff',
			'menu_bg_color'         => isset( $params['menu_bg_color'] ) ? sanitize_text_field( $params['menu_bg_color'] ) : '#001529',
			'menu_text_color'       => isset( $params['menu_text_color'] ) ? sanitize_text_field( $params['menu_text_color'] ) : '#ffffff',
			'custom_email_subject'  => isset( $params['custom_email_subject'] ) ? sanitize_text_field( $params['custom_email_subject'] ) : 'New Task Awaiting Your Input',
			'custom_email_template' => isset( $params['custom_email_template'] ) ? wp_kses_post( $params['custom_email_template'] ) : '',
		);

		update_option( 'wp_ai_workflows_whitelabel_settings', $settings );

		add_action(
			'admin_footer',
			function () use ( $settings ) {
				$header_data = array(
					'enabled'       => ! empty( $settings['enable_whitelabel'] ) ? '1' : '0',
					'headerText'    => $settings['header_text'],
					'logoUrl'       => $settings['logo_url'],
					'primaryColor'  => $settings['primary_color'],
					'menuBgColor'   => $settings['menu_bg_color'],
					'menuTextColor' => $settings['menu_text_color'],
				);

				echo '<script>
            // Update the global white label settings
            window.wpAiWorkflowsWhitelabel = ' . json_encode( $header_data ) . ';
            
            // Apply settings without page reload
            if (typeof window.wpAiWorkflowsApplySettings === "function") {
                window.wpAiWorkflowsApplySettings(' . json_encode( $header_data ) . ');
            } 
            // DO NOT reload the page
            </script>';
			}
		);

		return array(
			'success' => true,
			'message' => 'Whitelabel settings updated successfully',
		);
	}

	/**
	 * Handle logo upload
	 */
	public function handle_logo_upload( $request ) {
		// Access control is enforced by the REST layer (whitelabel_management_gate).
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$files = $request->get_file_params();
		if ( empty( $files['logo'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'No logo file uploaded',
				),
				400
			);
		}

		$uploaded_file = $files['logo'];

		$allowed_types = array( 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/svg+xml' );
		if ( ! in_array( $uploaded_file['type'], $allowed_types ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid file type. Allowed types: PNG, JPEG, GIF, SVG',
				),
				400
			);
		}

		$upload_overrides = array(
			'test_form'   => false,
			'test_size'   => true,
			'test_upload' => true,
		);

		$movefile = wp_handle_upload( $uploaded_file, $upload_overrides );

		if ( $movefile && ! isset( $movefile['error'] ) ) {
			$settings             = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
			$settings['logo_url'] = $movefile['url'];
			update_option( 'wp_ai_workflows_whitelabel_settings', $settings );

			return array(
				'success' => true,
				'message' => 'Logo uploaded successfully',
				'data'    => array(
					'url' => $movefile['url'],
				),
			);
		} else {
			return array(
				'success' => false,
				'message' => $movefile['error'],
			);
		}
	}

	/**
	 * Apply whitelabel styles to admin
	 */
	public function apply_whitelabel_styles( $hook ) {
		if ( strpos( $hook, 'wp-ai-workflows' ) === false ) {
			return;
		}

		$settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
		if ( empty( $settings ) || empty( $settings['enable_whitelabel'] ) ) {
			return;
		}

		$custom_css = $this->generate_custom_css( $settings );

		wp_add_inline_style( 'wp-ai-workflows-admin-styles', $custom_css );
	}

	/**
	 * Apply custom branding (logo and header text)
	 */
	public function apply_custom_branding( $hook ) {
		if ( strpos( $hook, 'wp-ai-workflows' ) === false ) {
			return;
		}

		$settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
		if ( empty( $settings ) || empty( $settings['enable_whitelabel'] ) ) {
			add_action(
				'admin_head',
				function () {
					echo '<script>window.wpAiWorkflowsWhitelabel = { enabled: "0" };</script>';
				}
			);
			return;
		}

		$header_data = array(
			'enabled'       => '1', // Use string "1" to match expected format
			'headerText'    => isset( $settings['header_text'] ) ? $settings['header_text'] : 'AI Workflow Automation',
			'logoUrl'       => isset( $settings['logo_url'] ) ? $settings['logo_url'] : '',
			'primaryColor'  => isset( $settings['primary_color'] ) ? $settings['primary_color'] : '#1890ff',
			'menuBgColor'   => isset( $settings['menu_bg_color'] ) ? $settings['menu_bg_color'] : '#001529',
			'menuTextColor' => isset( $settings['menu_text_color'] ) ? $settings['menu_text_color'] : '#ffffff',
		);

		add_action(
			'admin_head',
			function () use ( $header_data ) {
				echo '<script>window.wpAiWorkflowsWhitelabel = ' . json_encode( $header_data ) . ';</script>';
			}
		);

		add_action(
			'admin_footer',
			function () {
				echo '<script>
            function wpAiWorkflowsReloadWhitelabel() {
                console.log("Whitelabel settings changed, reloading page...");
                location.reload();
            }
            </script>';
			}
		);
	}

	/**
	 * Generate custom CSS from settings
	 */
	private function generate_custom_css( $settings ) {
		$css = '';

		// Use both selector patterns to be safe
		$selectors = array( '.wp-ai-workflows-wrapper', '#wp-ai-workflows-root' );

		foreach ( $selectors as $selector ) {
			if ( ! empty( $settings['primary_color'] ) ) {
				$css .= "{$selector} .ant-btn-primary { background-color: " . esc_attr( $settings['primary_color'] ) . " !important; }\n";
				$css .= "{$selector} .ant-btn-primary:hover { background-color: " . esc_attr( $settings['primary_color'] ) . " !important; filter: brightness(1.1); }\n";
				$css .= "{$selector} a { color: " . esc_attr( $settings['primary_color'] ) . " !important; }\n";
				$css .= "{$selector} .ant-menu-item-selected { color: " . esc_attr( $settings['primary_color'] ) . " !important; }\n";
				$css .= "{$selector} .ant-switch-checked { background-color: " . esc_attr( $settings['primary_color'] ) . " !important; }\n";
			}

			if ( ! empty( $settings['menu_bg_color'] ) ) {
				$css .= "{$selector} .ant-layout-header, {$selector} .ant-menu.ant-menu-dark { background-color: " . esc_attr( $settings['menu_bg_color'] ) . " !important; }\n";
			}

			if ( ! empty( $settings['menu_text_color'] ) ) {
				$css .= "{$selector} .ant-menu.ant-menu-dark .ant-menu-item, {$selector} .ant-menu.ant-menu-dark .ant-menu-item > a { color: " . esc_attr( $settings['menu_text_color'] ) . " !important; }\n";
			}
		}

		$css .= "#wp-ai-workflows-root .logo-container { 
            min-width: 180px !important;
            max-width: 520px !important;
            width: 30% !important;
            height: 32px !important;
            margin-top: 2px !important;
            margin-left: auto !important;
            margin-right: 50 !important;
            flex-shrink: 0 !important;
            position: relative !important;
            overflow: hidden !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            text-align: right !important;
            z-index: 100 !important;
          }\n";

			$css .= "#wp-ai-workflows-root .logo-container img {
            height: 100% !important;
            width: auto !important;
            max-height: 32px !important;
            object-fit: contain !important;
            display: block !important;
            margin-left: auto !important;
          }\n";

		return $css;
	}

	/**
	 * Customize email template for task notifications
	 */
	public function customize_email_template( $template, $task ) {
		$settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
		if ( empty( $settings ) || empty( $settings['enable_whitelabel'] ) || empty( $settings['custom_email_template'] ) ) {
			return $template;
		}

		$custom_template = $settings['custom_email_template'];

		$logo_url = ! empty( $settings['logo_url'] ) ? $settings['logo_url'] : get_site_icon_url( 96 );
		if ( empty( $logo_url ) ) {
			$logo_url = admin_url( 'images/wordpress-logo.png' );
		}

		$site_name = get_bloginfo( 'name' );
		$admin_url = admin_url( 'admin.php?page=wp-ai-workflows-tasks' );

		$content_display = 'No content provided';
		if ( isset( $task['content'] ) && ! is_null( $task['content'] ) ) {
			if ( is_string( $task['content'] ) ) {
				$content_display = $task['content'];
			} elseif ( is_array( $task['content'] ) || is_object( $task['content'] ) ) {
				$content_display = print_r( $task['content'], true );
			}
		}

		$instructions = '';
		if ( ! empty( $task['instructions'] ) ) {
			$instructions = $task['instructions'];
			if ( is_string( $instructions ) ) {
				$decoded = json_decode( $instructions, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$instructions = $decoded;
				}
			}
			if ( is_array( $instructions ) || is_object( $instructions ) ) {
				$instructions = print_r( $instructions, true );
			}
		}

		$placeholders = array(
			'{{site_name}}'     => $site_name,
			'{{task_id}}'       => $task['id'],
			'{{workflow_name}}' => $task['workflow_name'],
			'{{input_type}}'    => $task['input_type'],
			'{{content}}'       => $content_display,
			'{{instructions}}'  => $instructions,
			'{{admin_url}}'     => $admin_url,
			'{{site_icon_url}}' => $logo_url,
		);

		foreach ( $placeholders as $placeholder => $value ) {
			$custom_template = str_replace( $placeholder, $value, $custom_template );
		}

		return $custom_template;
	}

	/**
	 * Customize email subject for task notifications
	 */
	public function customize_email_subject( $subject ) {
		$settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
		if ( empty( $settings ) || empty( $settings['enable_whitelabel'] ) || empty( $settings['custom_email_subject'] ) ) {
			return $subject;
		}

		return $settings['custom_email_subject'];
	}

	/**
	 * Apply logo replacement on frontend
	 */
	public function maybe_replace_logo() {
		global $post;
		if ( ! is_object( $post ) || ! has_shortcode( $post->post_content, 'wp_ai_workflow_chat' ) ) {
			return;
		}

		$settings = get_option( 'wp_ai_workflows_whitelabel_settings', array() );
		if ( empty( $settings ) || empty( $settings['enable_whitelabel'] ) ) {
			return;
		}

		$css = '';

		if ( ! empty( $settings['logo_url'] ) ) {
			$logo_url = esc_url( $settings['logo_url'] );
			$css     .= ".wp-ai-workflows-chat-widget .chat-header-logo { background-image: url({$logo_url}) !important; background-size: contain; background-position: center; background-repeat: no-repeat; }";
		}

		if ( ! empty( $settings['primary_color'] ) ) {
			$primary_color = esc_attr( $settings['primary_color'] );
			$css          .= ".wp-ai-workflows-chat-widget .chat-primary-button, .wp-ai-workflows-chat-widget .chat-send-button { background-color: {$primary_color} !important; }";
			$css          .= ".wp-ai-workflows-chat-widget .chat-widget-header { background-color: {$primary_color} !important; }";
		}

		if ( ! empty( $css ) ) {
			wp_add_inline_style( 'wp-ai-workflows-chat-styles', $css );
		}
	}

	/**
	 * Import whitelabel settings from uploaded JSON file
	 */
	public function import_whitelabel_settings( $request ) {
		// Access control is enforced by the REST layer (whitelabel_management_gate).
		$files = $request->get_file_params();

		if ( empty( $files['settings_file'] ) ) {
			return array(
				'success' => false,
				'message' => 'No file uploaded',
			);
		}

		$file = $files['settings_file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return array(
				'success' => false,
				'message' => 'Upload failed: ' . $this->get_upload_error_message( $file['error'] ),
			);
		}

		$content  = file_get_contents( $file['tmp_name'] );
		$settings = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'message' => 'Invalid JSON file: ' . json_last_error_msg(),
			);
		}

		$required_fields = array( 'enable_whitelabel', 'primary_color', 'menu_bg_color', 'menu_text_color' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $settings[ $field ] ) ) {
				return array(
					'success' => false,
					'message' => "Missing required field: {$field}",
				);
			}
		}

		$sanitized_settings = array(
			'enable_whitelabel'     => (bool) $settings['enable_whitelabel'],
			'logo_url'              => isset( $settings['logo_url'] ) ? sanitize_url( $settings['logo_url'] ) : '',
			'header_text'           => isset( $settings['header_text'] ) ? sanitize_text_field( $settings['header_text'] ) : 'AI Workflow Automation',
			'primary_color'         => sanitize_text_field( $settings['primary_color'] ),
			'menu_bg_color'         => sanitize_text_field( $settings['menu_bg_color'] ),
			'menu_text_color'       => sanitize_text_field( $settings['menu_text_color'] ),
			'custom_email_subject'  => isset( $settings['custom_email_subject'] ) ? sanitize_text_field( $settings['custom_email_subject'] ) : 'New Task Awaiting Your Input',
			'custom_email_template' => isset( $settings['custom_email_template'] ) ? wp_kses_post( $settings['custom_email_template'] ) : '',
		);

		update_option( 'wp_ai_workflows_whitelabel_settings', $sanitized_settings );

		add_action(
			'admin_footer',
			function () use ( $sanitized_settings ) {
				$header_data = array(
					'enabled'       => ! empty( $sanitized_settings['enable_whitelabel'] ) ? '1' : '0',
					'headerText'    => $sanitized_settings['header_text'],
					'logoUrl'       => $sanitized_settings['logo_url'],
					'primaryColor'  => $sanitized_settings['primary_color'],
					'menuBgColor'   => $sanitized_settings['menu_bg_color'],
					'menuTextColor' => $sanitized_settings['menu_text_color'],
				);

				echo '<script>
            // Update the global white label settings
            window.wpAiWorkflowsWhitelabel = ' . json_encode( $header_data ) . ';
            
            // Call reload function if it exists
            if (typeof wpAiWorkflowsReloadWhitelabel === "function") {
                wpAiWorkflowsReloadWhitelabel();
            }
            </script>';
			}
		);

		return array(
			'success'  => true,
			'message'  => 'Settings imported successfully',
			'settings' => $sanitized_settings,
		);
	}

	/**
	 * Get upload error message
	 */
	private function get_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
				return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
			case UPLOAD_ERR_FORM_SIZE:
				return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
			case UPLOAD_ERR_PARTIAL:
				return 'The uploaded file was only partially uploaded';
			case UPLOAD_ERR_NO_FILE:
				return 'No file was uploaded';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'Missing a temporary folder';
			case UPLOAD_ERR_CANT_WRITE:
				return 'Failed to write file to disk';
			case UPLOAD_ERR_EXTENSION:
				return 'A PHP extension stopped the file upload';
			default:
				return 'Unknown upload error';
		}
	}
}
