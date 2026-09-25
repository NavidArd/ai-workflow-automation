<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Preview Handler - Clean Version
 */
class WP_AI_Workflows_Chat_Preview {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'wp_ajax_wp_ai_workflows_chat_preview', array( $this, 'render_preview_page' ) );
	}

	public function render_preview_page() {
		$js_files  = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/js/main.*.js' );
		$css_files = glob( WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/css/main.*.css' );

		$js_url  = ! empty( $js_files ) ?
			plugins_url( 'build/static/js/' . basename( $js_files[0] ), WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php' ) : '';
		$css_url = ! empty( $css_files ) ?
			plugins_url( 'build/static/css/' . basename( $css_files[0] ), WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php' ) : '';
		if ( $css_url ) {
			wp_register_style( 'wp-ai-workflows-chat-preview-css', $css_url, array(), WP_AI_WORKFLOWS_PRO_VERSION );
			wp_enqueue_style( 'wp-ai-workflows-chat-preview-css' );
		}

		if ( $js_url ) {
			wp_register_script( 'wp-ai-workflows-chat-preview-js', $js_url, array(), WP_AI_WORKFLOWS_PRO_VERSION, true );
			wp_enqueue_script( 'wp-ai-workflows-chat-preview-js' );

			$api_url    = esc_js( rest_url( 'wp-ai-workflows/v1' ) );
			$rest_nonce = esc_js( wp_create_nonce( 'wp_rest' ) );
			$site_url   = esc_js( get_site_url() );
			$assets_url = esc_js( plugins_url( 'assets', WP_AI_WORKFLOWS_PLUGIN_DIR . 'wp-ai-workflows.php' ) );

			$inline_js = "
window.wpAiWorkflowsSettings = {
	apiUrl: '{$api_url}',
	nonce: '{$rest_nonce}',
	siteUrl: '{$site_url}',
	assetsUrl: '{$assets_url}',
	isChat: true,
	isPreview: true
};

let chatAppReady = false;
let pendingConfig = null;

const checkForChatApp = setInterval(() => {
	if (window.wpAiWorkflowsChat && window.wpAiWorkflowsChat.initChat) {
		chatAppReady = true;
		clearInterval(checkForChatApp);

		if (pendingConfig) {
			initializeChat(pendingConfig);
		}
	}
}, 100);

function initializeChat(config) {
	const container = document.getElementById('chat-preview-container');
	if (!container) return;

	container.innerHTML = '';
	container.className = 'wp-ai-workflows-chat-container';
	container.dataset.workflowId = config.workflow_id;
	container.dataset.config = btoa(JSON.stringify(config));

	if (window.wpAiWorkflowsChat && window.wpAiWorkflowsChat.initChat) {
		try {
			window.wpAiWorkflowsChat.initChat('chat-preview-container');
		} catch (error) {
			console.error('[Preview] Error initializing chat:', error);
		}
	}
}

window.addEventListener('message', function(event) {
	if (event.origin !== window.location.origin) return;

	if (event.data && event.data.type === 'INIT_CHAT') {
		if (chatAppReady) {
			initializeChat(event.data.config);
		} else {
			pendingConfig = event.data.config;
		}
	}

	if (event.data && event.data.type === 'UPDATE_CHAT_CONFIG') {
		const container = document.getElementById('chat-preview-container');
		if (container && event.data.config) {
			container.dataset.config = btoa(JSON.stringify(event.data.config));

			if (window.wpAiWorkflowsChat && window.wpAiWorkflowsChat.initChat) {
				container.innerHTML = '';
				window.wpAiWorkflowsChat.initChat('chat-preview-container');
			}
		}
	}
});

window.addEventListener('load', function() {
	if (window.parent !== window) {
		window.parent.postMessage({ type: 'PREVIEW_READY' }, '*');
	}
});
";

			wp_add_inline_script( 'wp-ai-workflows-chat-preview-js', $inline_js, 'before' );
		}
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Chat Preview</title>
			<?php if ( $css_url ) : ?>
				<?php wp_print_styles( array( 'wp-ai-workflows-chat-preview-css' ) ); ?>
			<?php endif; ?>
			<style>
				body {
					margin: 0;
					padding: 0;
					background: #ffffff;
					height: 100vh;
					overflow: hidden;
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				}
				#chat-preview-container {
					width: 100%;
					height: 100vh;
				}
				.wp-ai-workflows-chat-container {
					width: 100%;
					height: 100%;
				}
				/* Override positioning for preview */
				.wp-ai-workflows-chat-widget {
					position: relative !important;
					width: 100% !important;
					height: 100% !important;
					box-shadow: none !important;
					border-radius: 0 !important;
				}
				.wp-ai-workflows-chat-widget.inline {
					margin: 0 !important;
				}
				/* Hide launcher button in preview */
				.chat-launcher {
					display: none !important;
				}
				.loading-message {
					display: flex;
					align-items: center;
					justify-content: center;
					height: 100vh;
					font-size: 14px;
					color: #666;
				}
			</style>
		</head>
		<body>
			<div id="chat-preview-container">
				<div class="loading-message">Loading chat preview...</div>
			</div>
			
			<?php if ( $js_url ) : ?>
				<?php wp_print_scripts( array( 'wp-ai-workflows-chat-preview-js' ) ); ?>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}
}
