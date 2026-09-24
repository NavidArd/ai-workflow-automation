<?php
/**
 * WP AI Workflows - Workflow Viewer
 *
 * Handles the frontend workflow viewer functionality
 *
 * @package WP_AI_Workflows
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Workflow Viewer class
 */
class WP_AI_Workflows_Viewer {
	private static $instance = null;
	private $script_loaded   = false;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the workflow viewer
	 */
	public function init() {
		// Register shortcode
		add_shortcode( 'wp_ai_workflow_preview', array( $this, 'render_workflow_preview' ) );

		// Register scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
	}

	/**
	 * Register necessary scripts and styles
	 */
	public function register_scripts() {
		if ( is_admin() ) {
			return;
		}

		// Register scripts
		$js_files  = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/js/main.*.js' );
		$css_files = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/css/main.*.css' );

		if ( ! empty( $js_files ) ) {
			wp_register_script(
				'wp-ai-workflows-viewer',
				WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/js/' . basename( $js_files[0] ),
				array( 'react', 'react-dom' ),
				WP_AI_WORKFLOWS_PRO_VERSION,
				true
			);

			// Localize script with required data
			wp_localize_script(
				'wp-ai-workflows-viewer',
				'wpAiWorkflowsSettings',
				array(
					'apiUrl'    => rest_url( 'wp-ai-workflows/v1' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'siteUrl'   => get_site_url(),
					'assetsUrl' => WP_AI_WORKFLOWS_PLUGIN_URL . 'assets',
					'isViewer'  => true,
				)
			);
		}

		if ( ! empty( $css_files ) ) {
			wp_register_style(
				'wp-ai-workflows-viewer',
				WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/css/' . basename( $css_files[0] ),
				array(),
				WP_AI_WORKFLOWS_PRO_VERSION
			);
		}
	}

	/**
	 * Enqueue all necessary assets
	 */
	public function enqueue_assets() {
		if ( ! $this->script_loaded ) {
			// Enqueue React first
			wp_enqueue_script( 'react' );
			wp_enqueue_script( 'react-dom' );

			// Then enqueue our scripts
			wp_enqueue_script( 'wp-ai-workflows-viewer' );
			wp_enqueue_style( 'wp-ai-workflows-viewer' );

			$this->script_loaded = true;
		}
	}

	/**
	 * Check if current device is mobile
	 *
	 * @return boolean True if mobile, false otherwise
	 */
	private function is_mobile() {
		// Check server-side if possible using User-Agent
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			if ( preg_match( '/(android|iphone|ipod|ipad|blackberry|webos|windows\s+phone)/i', $user_agent ) ) {
				return true;
			}
		}

		// We can't reliably detect all mobile devices server-side,
		// so we'll also use JS for client-side detection
		return false;
	}

	/**
	 * Render the workflow preview shortcode
	 *
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function render_workflow_preview( $atts ) {
		// Parse shortcode attributes
		$atts = shortcode_atts(
			array(
				'id'                  => 0,             // Workflow ID
				'height'              => '500px',       // Height of the viewer
				'dark_mode'           => 'false',       // Dark mode toggle
				'show_minimap'        => 'true',        // Show minimap toggle
				'show_controls'       => 'true',        // Show controls toggle
				'nodes_draggable'     => 'false',       // Allow node dragging
				'nodes_connectable'   => 'false',       // Allow node connecting
				'elements_selectable' => 'false',       // Allow element selection
				'show_sidebar'        => 'false',       // Show simplified sidebar
				'allow_pan_zoom'      => 'true',        // Allow pan and zoom
				'show_toolbar'        => 'false',       // Show toolbar
				'allow_download'      => 'false',       // Allow workflow download
				'hide_on_mobile'      => 'false',       // Hide on mobile devices
				'hide_powered_by'     => 'false',       // Hide powered by text
				'overlay_text'        => 'Click to Explore Workflow', // Text for the overlay
			),
			$atts,
			'wp_ai_workflow_preview'
		);

		// Generate a unique ID for this instance
		$viewer_id = 'workflow-viewer-' . uniqid();

		// Check for mobile and hide_on_mobile setting
		$hide_on_mobile = $atts['hide_on_mobile'] === 'true';
		$is_mobile      = $this->is_mobile();

		// Mobile detection script that runs before any workflow data is loaded
		$mobile_detection_script = '';
		$mobile_message          = '';

		if ( $hide_on_mobile ) {
			// Add a script to detect mobile devices on the client side
			$mobile_detection_script = '<script>
                (function() {
                    function isMobileDevice() {
                        return window.innerWidth < 768 || 
                            /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                    }
                    
                    // Add to window for later use
                    window.wpAiWorkflowsMobile = isMobileDevice();
                    
                    // Show/hide elements immediately based on mobile detection
                    if (window.wpAiWorkflowsMobile) {
                        document.addEventListener("DOMContentLoaded", function() {
                            var container = document.getElementById("' . esc_js( $viewer_id ) . '");
                            var mobileMsg = document.getElementById("' . esc_js( $viewer_id ) . '-mobile-message");
                            
                            if (container) container.style.display = "none";
                            if (mobileMsg) mobileMsg.style.display = "block";
                        });
                    }
                })();
            </script>';

			// Create mobile message
			$mobile_message = '<div id="' . esc_attr( $viewer_id ) . '-mobile-message" style="display:none; padding:20px; text-align:center; border:1px solid #eee; border-radius:4px; background-color:#f9f9f9; color:#666; margin-bottom:20px;">
                <div style="font-size:16px; margin-bottom:8px;">
                    This workflow preview is not available on mobile devices
                </div>
                <div style="font-size:13px;">
                    Please view on a desktop or tablet for the full experience
                </div>
            </div>';
		}

		// If we're on mobile and hide_on_mobile is true, just return the mobile message
		if ( $is_mobile && $hide_on_mobile ) {
			return $mobile_detection_script . $mobile_message;
		}

		// Validate workflow ID
		if ( empty( $atts['id'] ) ) {
			return '<div class="workflow-viewer-error">Invalid workflow ID</div>';
		}

		// Get workflow data
		$workflow_data = $this->get_workflow_data( $atts['id'] );
		if ( ! $workflow_data ) {
			return '<div class="workflow-viewer-error">Workflow not found or not accessible</div>';
		}

		// Enqueue necessary scripts and styles
		$this->enqueue_assets();

		// Auto-enable toolbar if download is enabled
		if ( $atts['allow_download'] === 'true' && $atts['show_toolbar'] === 'false' ) {
			$atts['show_toolbar'] = 'true';
		}

		// Convert string booleans to actual booleans for the config
		$config = array(
			'workflowData'       => $workflow_data,
			'height'             => esc_attr( $atts['height'] ),
			'darkMode'           => $atts['dark_mode'] === 'true',
			'showMinimap'        => $atts['show_minimap'] === 'true',
			'showControls'       => $atts['show_controls'] === 'true',
			'nodesDraggable'     => $atts['nodes_draggable'] === 'true',
			'nodesConnectable'   => $atts['nodes_connectable'] === 'true',
			'elementsSelectable' => $atts['elements_selectable'] === 'true',
			'showSidebar'        => $atts['show_sidebar'] === 'true',
			'allowPanZoom'       => $atts['allow_pan_zoom'] === 'true',
			'showToolbar'        => $atts['show_toolbar'] === 'true',
			'allowDownload'      => $atts['allow_download'] === 'true',
			'hideOnMobile'       => $hide_on_mobile,
			'hidePoweredBy'      => $atts['hide_powered_by'] === 'true',
			'overlayText'        => esc_attr( $atts['overlay_text'] ),
		);

		// Encode the config for the data attribute
		$encoded_config = base64_encode( wp_json_encode( $config ) );

		// Conditional loading script - only load workflow data if not on mobile
		$conditional_loading_script = '';
		if ( $hide_on_mobile ) {
			$conditional_loading_script = '<script>
                (function() {
                    document.addEventListener("DOMContentLoaded", function() {
                        // If not mobile or hide_on_mobile is false, initialize the viewer
                        if (!window.wpAiWorkflowsMobile) {
                            if (typeof window.wpAiWorkflowsViewer !== "undefined" && 
                                typeof window.wpAiWorkflowsViewer.init === "function") {
                                window.wpAiWorkflowsViewer.init("' . esc_js( $viewer_id ) . '");
                            }
                        }
                    });
                })();
            </script>';
		} else {
			// If hide_on_mobile is false, always initialize
			$conditional_loading_script = '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    if (typeof window.wpAiWorkflowsViewer !== "undefined" && 
                        typeof window.wpAiWorkflowsViewer.init === "function") {
                        window.wpAiWorkflowsViewer.init("' . esc_js( $viewer_id ) . '");
                    }
                });
            </script>';
		}

		// Return container HTML with proper styling and conditional loading
		$output  = $mobile_detection_script . $mobile_message;
		$output .= sprintf(
			'<div id="%s" class="wp-ai-workflows-viewer-container" data-workflow-id="%s" data-config="%s" style="height: %s; width: 100%%;">
                <div class="workflow-viewer-loading">
                    <div class="loading-spinner"></div>
                    <div class="loading-text">Loading workflow preview...</div>
                </div>
            </div>',
			esc_attr( $viewer_id ),
			esc_attr( $atts['id'] ),
			esc_attr( $encoded_config ),
			esc_attr( $atts['height'] )
		);
		$output .= $conditional_loading_script;

		return $output;
	}

	/**
	 * Get workflow data from the options table
	 *
	 * @param string $workflow_id The workflow ID
	 * @return array|null Workflow data or null if not found
	 */
	private function get_workflow_data( $workflow_id ) {
		// Get workflow using DBAL
		$workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow_id );

		if ( ! $workflow ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Workflow not found',
				'error',
				array(
					'workflow_id' => $workflow_id,
				)
			);
			return null;
		}

		return $workflow;
	}
}
