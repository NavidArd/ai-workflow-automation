<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Production-Ready MCP (Model Context Protocol) Client for WP AI Workflows
 * Handles real API communication with various services and proper stdio communication
 */
class WP_AI_Workflows_MCP_Client {

	/**
	 * Preset server configurations with their default tools
	 */
	private static $preset_servers = array(
		'slack'           => array(
			'name'        => 'Slack',
			'description' => 'Send messages, manage channels, and interact with Slack workspaces',
			'transport'   => 'http',
			'base_url'    => 'https://slack.com/api',
			'tools'       => array(
				array(
					'id'          => 'send_message',
					'name'        => 'Send Message',
					'description' => 'Send a message to a channel or user',
				),
				array(
					'id'          => 'list_channels',
					'name'        => 'List Channels',
					'description' => 'Get list of channels in workspace',
				),
				array(
					'id'          => 'get_messages',
					'name'        => 'Get Messages',
					'description' => 'Retrieve messages from a channel',
				),
				array(
					'id'          => 'create_channel',
					'name'        => 'Create Channel',
					'description' => 'Create a new channel',
				),
				array(
					'id'          => 'invite_user',
					'name'        => 'Invite User',
					'description' => 'Invite user to channel',
				),
			),
		),
		'github'          => array(
			'name'        => 'GitHub',
			'description' => 'Manage repositories, issues, pull requests, and GitHub workflows',
			'transport'   => 'http',
			'base_url'    => 'https://api.github.com',
			'tools'       => array(
				array(
					'id'          => 'create_issue',
					'name'        => 'Create Issue',
					'description' => 'Create a new issue in a repository',
				),
				array(
					'id'          => 'list_repos',
					'name'        => 'List Repositories',
					'description' => 'Get list of repositories',
				),
				array(
					'id'          => 'create_pr',
					'name'        => 'Create Pull Request',
					'description' => 'Create a new pull request',
				),
				array(
					'id'          => 'get_file_content',
					'name'        => 'Get File Content',
					'description' => 'Retrieve content of a file',
				),
				array(
					'id'          => 'commit_file',
					'name'        => 'Commit File',
					'description' => 'Create or update a file',
				),
			),
		),
		'notion'          => array(
			'name'        => 'Notion',
			'description' => 'Create pages, update databases, and manage Notion workspaces',
			'transport'   => 'http',
			'base_url'    => 'https://api.notion.com/v1',
			'tools'       => array(
				array(
					'id'          => 'create_page',
					'name'        => 'Create Page',
					'description' => 'Create a new page',
				),
				array(
					'id'          => 'update_database',
					'name'        => 'Update Database',
					'description' => 'Add or update database entries',
				),
				array(
					'id'          => 'search_pages',
					'name'        => 'Search Pages',
					'description' => 'Search for pages and databases',
				),
				array(
					'id'          => 'add_block',
					'name'        => 'Add Block',
					'description' => 'Add content blocks to a page',
				),
			),
		),
		'google_drive'    => array(
			'name'        => 'Google Drive',
			'description' => 'Upload files, create folders, and manage Google Drive storage',
			'transport'   => 'http',
			'base_url'    => 'https://www.googleapis.com/drive/v3',
			'tools'       => array(
				array(
					'id'          => 'upload_file',
					'name'        => 'Upload File',
					'description' => 'Upload a file to Drive',
				),
				array(
					'id'          => 'search_files',
					'name'        => 'Search Files',
					'description' => 'Search for files and folders',
				),
				array(
					'id'          => 'create_folder',
					'name'        => 'Create Folder',
					'description' => 'Create a new folder',
				),
				array(
					'id'          => 'share_file',
					'name'        => 'Share File',
					'description' => 'Share a file with others',
				),
			),
		),
		'google_calendar' => array(
			'name'        => 'Google Calendar',
			'description' => 'Create events, manage calendars, and schedule meetings',
			'transport'   => 'http',
			'base_url'    => 'https://www.googleapis.com/calendar/v3',
			'tools'       => array(
				array(
					'id'          => 'create_event',
					'name'        => 'Create Event',
					'description' => 'Create a new calendar event',
				),
				array(
					'id'          => 'list_events',
					'name'        => 'List Events',
					'description' => 'Get upcoming events',
				),
				array(
					'id'          => 'find_free_slots',
					'name'        => 'Find Free Slots',
					'description' => 'Find available time slots',
				),
				array(
					'id'          => 'update_event',
					'name'        => 'Update Event',
					'description' => 'Update an existing event',
				),
			),
		),
		'gmail'           => array(
			'name'        => 'Gmail',
			'description' => 'Send emails, search messages, and manage Gmail',
			'transport'   => 'http',
			'base_url'    => 'https://gmail.googleapis.com/gmail/v1',
			'tools'       => array(
				array(
					'id'          => 'send_email',
					'name'        => 'Send Email',
					'description' => 'Send an email message',
				),
				array(
					'id'          => 'search_emails',
					'name'        => 'Search Emails',
					'description' => 'Search for email messages',
				),
				array(
					'id'          => 'create_draft',
					'name'        => 'Create Draft',
					'description' => 'Create a draft email',
				),
				array(
					'id'          => 'add_label',
					'name'        => 'Add Label',
					'description' => 'Add label to messages',
				),
			),
		),
		'postgresql'      => array(
			'name'        => 'PostgreSQL',
			'description' => 'Execute queries and manage PostgreSQL databases',
			'transport'   => 'http',
			'tools'       => array(
				array(
					'id'          => 'query',
					'name'        => 'Execute Query',
					'description' => 'Execute a SQL query',
				),
				array(
					'id'          => 'insert',
					'name'        => 'Insert Record',
					'description' => 'Insert a new record',
				),
				array(
					'id'          => 'update',
					'name'        => 'Update Record',
					'description' => 'Update existing records',
				),
				array(
					'id'          => 'create_table',
					'name'        => 'Create Table',
					'description' => 'Create a new table',
				),
			),
		),
		'chroma'          => array(
			'name'        => 'Chroma',
			'description' => 'Manage vector databases and embeddings with Chroma',
			'transport'   => 'http',
			'tools'       => array(
				array(
					'id'          => 'add_documents',
					'name'        => 'Add Documents',
					'description' => 'Add documents to a collection',
				),
				array(
					'id'          => 'query_collection',
					'name'        => 'Query Collection',
					'description' => 'Search for similar documents',
				),
				array(
					'id'          => 'create_collection',
					'name'        => 'Create Collection',
					'description' => 'Create a new collection',
				),
				array(
					'id'          => 'delete_documents',
					'name'        => 'Delete Documents',
					'description' => 'Remove documents from collection',
				),
			),
		),
	);

	/**
	 * Initialize the MCP Client
	 */
	public function init() {
		// Any initialization code if needed
	}

	/**
	 * Discover available tools from an MCP server
	 */
	public static function discover_tools( $server_type, $config = null ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'server_type' => $server_type,
				'has_config'  => ! empty( $config ),
			)
		);

		try {
			// For preset servers, return the predefined tools immediately
			if ( isset( self::$preset_servers[ $server_type ] ) ) {
				$tools = self::$preset_servers[ $server_type ]['tools'];

				WP_AI_Workflows_Utilities::debug_log(
					'Returning preset tools',
					'info',
					array(
						'server_type' => $server_type,
						'tool_count'  => count( $tools ),
					)
				);

				return new WP_REST_Response( $tools, 200 );
			}

			// For custom servers, attempt to discover tools from the actual MCP server
			if ( $server_type === 'custom' && ! empty( $config ) ) {
				return self::discover_tools_from_server( $config );
			}

			return new WP_Error( 'invalid_server', 'Invalid server type or missing configuration', array( 'status' => 400 ) );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Tool discovery failed',
				'error',
				array(
					'server_type' => $server_type,
					'error'       => $e->getMessage(),
				)
			);

			return new WP_Error( 'discovery_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Discover tools from a custom MCP server
	 */
	private static function discover_tools_from_server( $config ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'config' => $config ) );

		$custom_config = $config['customServerConfig'] ?? $config;

		if ( empty( $custom_config['connectionType'] ) ) {
			return new WP_Error( 'missing_connection_type', 'Connection type is required for custom servers', array( 'status' => 400 ) );
		}

		try {
			if ( $custom_config['connectionType'] === 'http' ) {
				return self::discover_tools_http( $custom_config );
			} elseif ( $custom_config['connectionType'] === 'stdio' ) {
				return self::discover_tools_stdio( $custom_config );
			} else {
				return new WP_Error( 'invalid_connection_type', 'Invalid connection type', array( 'status' => 400 ) );
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Custom server tool discovery failed',
				'error',
				array(
					'error'           => $e->getMessage(),
					'connection_type' => $custom_config['connectionType'],
				)
			);

			return new WP_Error( 'discovery_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Discover tools from HTTP MCP server
	 */
	private static function discover_tools_http( $config ) {
		if ( empty( $config['endpoint'] ) ) {
			return new WP_Error( 'missing_endpoint', 'Endpoint URL is required for HTTP servers', array( 'status' => 400 ) );
		}

		$endpoint = rtrim( $config['endpoint'], '/' );

		// Build MCP tools/list request
		$request_body = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => 'tools/list',
			'params'  => array(),
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		// Add authentication if configured
		if ( ! empty( $config['authFields'] ) ) {
			foreach ( $config['authFields'] as $auth_field ) {
				if ( ! empty( $auth_field['key'] ) && ! empty( $auth_field['value'] ) ) {
					$headers[ $auth_field['key'] ] = $auth_field['value'];
				}
			}
		}

		$response = wp_remote_post(
			$endpoint . '/mcp',
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'HTTP MCP request failed',
				'error',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				)
			);

			return new WP_Error( 'http_request_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code !== 200 ) {
			return new WP_Error( 'http_error', "Server returned status: $response_code", array( 'status' => $response_code ) );
		}

		$data = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON response from server', array( 'status' => 500 ) );
		}

		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'mcp_error', $data['error']['message'] ?? 'Unknown MCP error', array( 'status' => 500 ) );
		}

		if ( ! isset( $data['result']['tools'] ) ) {
			return new WP_Error( 'no_tools', 'No tools found in server response', array( 'status' => 404 ) );
		}

		// Convert MCP tools format to our format
		$tools = array();
		foreach ( $data['result']['tools'] as $tool ) {
			$tools[] = array(
				'id'          => $tool['name'],
				'name'        => $tool['name'],
				'description' => $tool['description'] ?? 'No description available',
			);
		}

		WP_AI_Workflows_Utilities::debug_log(
			'HTTP MCP tools discovered',
			'info',
			array(
				'endpoint'   => $endpoint,
				'tool_count' => count( $tools ),
			)
		);

		return new WP_REST_Response( $tools, 200 );
	}

	/**
	 * Production stdio communication with MCP servers
	 */
	private static function discover_tools_stdio( $config ) {
		if ( empty( $config['command'] ) ) {
			return new WP_Error( 'missing_command', 'Command is required for stdio servers', array( 'status' => 400 ) );
		}

		try {
			$command      = escapeshellcmd( $config['command'] );
			$args         = ! empty( $config['args'] ) ? array_map( 'escapeshellarg', $config['args'] ) : array();
			$full_command = $command . ' ' . implode( ' ', $args );

			// Build MCP tools/list request
			$request = array(
				'jsonrpc' => '2.0',
				'id'      => wp_generate_uuid4(),
				'method'  => 'tools/list',
				'params'  => array(),
			);

			$json_request = wp_json_encode( $request ) . "\n";

			// Open process with pipes
			$descriptorspec = array(
				0 => array( 'pipe', 'r' ), // stdin
				1 => array( 'pipe', 'w' ), // stdout
				2 => array( 'pipe', 'w' ),  // stderr
			);

			$process = proc_open( $full_command, $descriptorspec, $pipes );

			if ( ! is_resource( $process ) ) {
				throw new Exception( 'Failed to start MCP server process' );
			}

			// Write request to stdin
			fwrite( $pipes[0], $json_request );
			fclose( $pipes[0] );

			// Read response from stdout with timeout
			stream_set_timeout( $pipes[1], 30 );
			$response = '';
			while ( ! feof( $pipes[1] ) ) {
				$chunk = fread( $pipes[1], 8192 );
				if ( $chunk === false ) {
					break;
				}
				$response .= $chunk;

				// Check for timeout
				$info = stream_get_meta_data( $pipes[1] );
				if ( $info['timed_out'] ) {
					throw new Exception( 'MCP server response timeout' );
				}
			}

			// Get stderr for error information
			$error_output = stream_get_contents( $pipes[2] );

			fclose( $pipes[1] );
			fclose( $pipes[2] );

			$return_code = proc_close( $process );

			if ( $return_code !== 0 ) {
				throw new Exception( 'MCP server exited with code ' . intval( $return_code ) . ': ' . esc_html( $error_output ) );
			}

			// Parse JSON response
			$lines         = explode( "\n", trim( $response ) );
			$json_response = null;

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}

				$data = json_decode( $line, true );
				if ( json_last_error() === JSON_ERROR_NONE && isset( $data['jsonrpc'] ) ) {
					$json_response = $data;
					break;
				}
			}

			if ( ! $json_response ) {
				throw new Exception( 'Invalid JSON response from MCP server' );
			}

			if ( isset( $json_response['error'] ) ) {
				throw new Exception( $json_response['error']['message'] ?? 'Unknown MCP error' );
			}

			if ( ! isset( $json_response['result']['tools'] ) ) {
				throw new Exception( 'No tools found in MCP server response' );
			}

			// Convert MCP tools format to our format
			$tools = array();
			foreach ( $json_response['result']['tools'] as $tool ) {
				$tools[] = array(
					'id'          => $tool['name'],
					'name'        => $tool['name'],
					'description' => $tool['description'] ?? 'No description available',
				);
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Stdio MCP tools discovered',
				'info',
				array(
					'command'    => $command,
					'tool_count' => count( $tools ),
				)
			);

			return new WP_REST_Response( $tools, 200 );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Stdio MCP discovery failed',
				'error',
				array(
					'command' => $config['command'],
					'error'   => $e->getMessage(),
				)
			);

			// Fallback to configured custom tools
			if ( ! empty( $config['customTools'] ) ) {
				$tools = array();
				foreach ( $config['customTools'] as $tool ) {
					if ( ! empty( $tool['id'] ) && ! empty( $tool['name'] ) ) {
						$tools[] = array(
							'id'          => $tool['id'],
							'name'        => $tool['name'],
							'description' => $tool['description'] ?? 'No description available',
						);
					}
				}

				return new WP_REST_Response( $tools, 200 );
			}

			return new WP_Error( 'stdio_discovery_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Test MCP connection and tool execution
	 */
	public static function test_connection( $params ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'server_type' => $params['serverType'] ?? 'unknown',
				'has_tool'    => ! empty( $params['selectedTool'] ),
			)
		);

		try {
			$server_type     = $params['serverType'] ?? '';
			$selected_tool   = $params['selectedTool'] ?? '';
			$tool_parameters = $params['toolParameters'] ?? array();

			if ( empty( $server_type ) ) {
				return new WP_Error( 'missing_server_type', 'Server type is required', array( 'status' => 400 ) );
			}

			if ( empty( $selected_tool ) ) {
				return new WP_Error( 'missing_tool', 'Tool selection is required for testing', array( 'status' => 400 ) );
			}

			// For preset servers, test with real API calls
			if ( isset( self::$preset_servers[ $server_type ] ) ) {
				return self::test_preset_server( $server_type, $selected_tool, $tool_parameters, $params );
			}

			// For custom servers, test the actual MCP connection
			if ( $server_type === 'custom' ) {
				return self::test_custom_server( $params );
			}

			return new WP_Error( 'invalid_server_type', 'Invalid server type', array( 'status' => 400 ) );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'MCP connection test failed',
				'error',
				array(
					'error'       => $e->getMessage(),
					'server_type' => $params['serverType'] ?? 'unknown',
				)
			);

			return new WP_Error( 'test_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Test preset server with actual API calls
	 */
	private static function test_preset_server( $server_type, $selected_tool, $tool_parameters, $params ) {
		$connection_config = $params['connectionConfig'] ?? array();

		// Validate required authentication for preset servers
		$validation_result = self::validate_preset_server_auth( $server_type, $connection_config );
		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		try {
			// Execute the actual API call
			$result = self::execute_preset_api_call( $server_type, $selected_tool, $tool_parameters, $connection_config );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Preset server test completed',
				'info',
				array(
					'server_type' => $server_type,
					'tool'        => $selected_tool,
					'success'     => true,
				)
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
					'message' => "Successfully tested {$selected_tool} on " . self::$preset_servers[ $server_type ]['name'],
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'api_test_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Execute actual API calls for preset servers
	 */
	private static function execute_preset_api_call( $server_type, $tool, $parameters, $auth_config ) {
		switch ( $server_type ) {
			case 'slack':
				return self::execute_slack_api( $tool, $parameters, $auth_config );

			case 'github':
				return self::execute_github_api( $tool, $parameters, $auth_config );

			case 'gmail':
				return self::execute_gmail_api( $tool, $parameters, $auth_config );

			case 'notion':
				return self::execute_notion_api( $tool, $parameters, $auth_config );

			case 'google_drive':
				return self::execute_google_drive_api( $tool, $parameters, $auth_config );

			case 'google_calendar':
				return self::execute_google_calendar_api( $tool, $parameters, $auth_config );

			case 'postgresql':
				return self::execute_postgresql_query( $tool, $parameters, $auth_config );

			case 'chroma':
				return self::execute_chroma_api( $tool, $parameters, $auth_config );

			default:
				throw new Exception( 'Unsupported server type: ' . esc_html( $server_type ) );
		}
	}

	/**
	 * Execute Slack API calls
	 */
	private static function execute_slack_api( $tool, $parameters, $auth_config ) {
		$bot_token = $auth_config['bot_token'] ?? '';
		if ( empty( $bot_token ) ) {
			throw new Exception( 'Slack bot token is required' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $bot_token,
			'Content-Type'  => 'application/json',
		);

		switch ( $tool ) {
			case 'send_message':
				$data = array(
					'channel' => $parameters['channel'] ?? '',
					'text'    => $parameters['text'] ?? '',
				);
				if ( ! empty( $parameters['thread_ts'] ) ) {
					$data['thread_ts'] = $parameters['thread_ts'];
				}

				return self::make_http_request( 'POST', 'https://slack.com/api/chat.postMessage', $headers, $data );

			case 'list_channels':
				$query_params = array(
					'types' => $parameters['types'] ?? 'public_channel,private_channel',
					'limit' => $parameters['limit'] ?? 100,
				);

				return self::make_http_request( 'GET', 'https://slack.com/api/conversations.list', $headers, null, $query_params );

			case 'get_messages':
				$query_params = array(
					'channel' => $parameters['channel'] ?? '',
					'limit'   => $parameters['limit'] ?? 10,
				);

				return self::make_http_request( 'GET', 'https://slack.com/api/conversations.history', $headers, null, $query_params );

			case 'create_channel':
				$data = array(
					'name'       => $parameters['name'] ?? '',
					'is_private' => filter_var( $parameters['is_private'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				);

				return self::make_http_request( 'POST', 'https://slack.com/api/conversations.create', $headers, $data );

			default:
				throw new Exception( 'Unsupported Slack tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute GitHub API calls
	 */
	private static function execute_github_api( $tool, $parameters, $auth_config ) {
		$access_token = $auth_config['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			throw new Exception( 'GitHub access token is required' );
		}

		$headers = array(
			'Authorization' => 'token ' . $access_token,
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'WP-AI-Workflows/1.0',
		);

		switch ( $tool ) {
			case 'create_issue':
				$repo = $parameters['repo'] ?? '';
				if ( empty( $repo ) ) {
					throw new Exception( 'Repository name is required' );
				}

				$data = array(
					'title' => $parameters['title'] ?? '',
					'body'  => $parameters['body'] ?? '',
				);

				if ( ! empty( $parameters['labels'] ) ) {
					$data['labels'] = explode( ',', $parameters['labels'] );
				}

				return self::make_http_request( 'POST', "https://api.github.com/repos/{$repo}/issues", $headers, $data );

			case 'list_repos':
				$query_params = array(
					'type'     => $parameters['type'] ?? 'all',
					'sort'     => $parameters['sort'] ?? 'updated',
					'per_page' => 30,
				);

				return self::make_http_request( 'GET', 'https://api.github.com/user/repos', $headers, null, $query_params );

			case 'create_pr':
				$repo = $parameters['repo'] ?? '';
				if ( empty( $repo ) ) {
					throw new Exception( 'Repository name is required' );
				}

				$data = array(
					'title' => $parameters['title'] ?? '',
					'head'  => $parameters['head'] ?? '',
					'base'  => $parameters['base'] ?? 'main',
					'body'  => $parameters['body'] ?? '',
				);

				return self::make_http_request( 'POST', "https://api.github.com/repos/{$repo}/pulls", $headers, $data );

			case 'get_file_content':
				$repo = $parameters['repo'] ?? '';
				$path = $parameters['path'] ?? '';
				if ( empty( $repo ) || empty( $path ) ) {
					throw new Exception( 'Repository name and file path are required' );
				}

				$query_params = array(
					'ref' => $parameters['ref'] ?? 'main',
				);

				return self::make_http_request( 'GET', "https://api.github.com/repos/{$repo}/contents/{$path}", $headers, null, $query_params );

			default:
				throw new Exception( 'Unsupported GitHub tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute Gmail API calls
	 */
	private static function execute_gmail_api( $tool, $parameters, $auth_config ) {
		$access_token = $auth_config['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			throw new Exception( 'Gmail access token is required' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json',
		);

		switch ( $tool ) {
			case 'send_email':
				$to      = $parameters['to'] ?? '';
				$subject = $parameters['subject'] ?? '';
				$body    = $parameters['body'] ?? '';

				if ( empty( $to ) || empty( $subject ) ) {
					throw new Exception( 'Recipient email and subject are required' );
				}

				// Create RFC 2822 compliant message
				$message_content  = "To: {$to}\r\n";
				$message_content .= "Subject: {$subject}\r\n";
				if ( ! empty( $parameters['cc'] ) ) {
					$message_content .= "Cc: {$parameters['cc']}\r\n";
				}
				$message_content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
				$message_content .= $body;

				$encoded_message = base64_encode( $message_content );
				$encoded_message = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $encoded_message );

				$data = array(
					'raw' => $encoded_message,
				);

				return self::make_http_request( 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $headers, $data );

			case 'search_emails':
				$query       = $parameters['query'] ?? '';
				$max_results = $parameters['max_results'] ?? 10;

				$query_params = array(
					'q'          => $query,
					'maxResults' => $max_results,
				);

				return self::make_http_request( 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages', $headers, null, $query_params );

			case 'create_draft':
				$to      = $parameters['to'] ?? '';
				$subject = $parameters['subject'] ?? '';
				$body    = $parameters['body'] ?? '';

				if ( empty( $to ) || empty( $subject ) ) {
					throw new Exception( 'Recipient email and subject are required' );
				}

				// Create RFC 2822 compliant message
				$message_content  = "To: {$to}\r\n";
				$message_content .= "Subject: {$subject}\r\n";
				$message_content .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
				$message_content .= $body;

				$encoded_message = base64_encode( $message_content );
				$encoded_message = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $encoded_message );

				$data = array(
					'message' => array(
						'raw' => $encoded_message,
					),
				);

				return self::make_http_request( 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/drafts', $headers, $data );

			default:
				throw new Exception( 'Unsupported Gmail tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute Notion API calls
	 */
	private static function execute_notion_api( $tool, $parameters, $auth_config ) {
		$integration_token = $auth_config['integration_token'] ?? '';
		if ( empty( $integration_token ) ) {
			throw new Exception( 'Notion integration token is required' );
		}

		$headers = array(
			'Authorization'  => 'Bearer ' . $integration_token,
			'Content-Type'   => 'application/json',
			'Notion-Version' => '2022-06-28',
		);

		switch ( $tool ) {
			case 'create_page':
				$parent_id = $parameters['parent_id'] ?? '';
				$title     = $parameters['title'] ?? '';

				if ( empty( $parent_id ) || empty( $title ) ) {
					throw new Exception( 'Parent ID and title are required' );
				}

				$data = array(
					'parent'     => array( 'page_id' => $parent_id ),
					'properties' => array(
						'title' => array(
							'title' => array(
								array( 'text' => array( 'content' => $title ) ),
							),
						),
					),
				);

				if ( ! empty( $parameters['content'] ) ) {
					$data['children'] = array(
						array(
							'object'    => 'block',
							'type'      => 'paragraph',
							'paragraph' => array(
								'rich_text' => array(
									array(
										'type' => 'text',
										'text' => array( 'content' => $parameters['content'] ),
									),
								),
							),
						),
					);
				}

				return self::make_http_request( 'POST', 'https://api.notion.com/v1/pages', $headers, $data );

			case 'search_pages':
				$data = array(
					'query'     => $parameters['query'] ?? '',
					'page_size' => 100,
				);

				if ( ! empty( $parameters['filter'] ) ) {
					$filter_data = json_decode( $parameters['filter'], true );
					if ( $filter_data ) {
						$data['filter'] = $filter_data;
					}
				}

				return self::make_http_request( 'POST', 'https://api.notion.com/v1/search', $headers, $data );

			default:
				throw new Exception( 'Unsupported Notion tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute Google Drive API calls
	 */
	private static function execute_google_drive_api( $tool, $parameters, $auth_config ) {
		$access_token = $auth_config['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			throw new Exception( 'Google Drive access token is required' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $access_token,
		);

		switch ( $tool ) {
			case 'search_files':
				$query_params = array(
					'q'        => $parameters['query'] ?? "name contains ''",
					'pageSize' => 10,
					'fields'   => 'files(id,name,mimeType,createdTime)',
				);

				if ( ! empty( $parameters['mime_type'] ) ) {
					$query_params['q'] = "mimeType='{$parameters['mime_type']}'";
				}

				return self::make_http_request( 'GET', 'https://www.googleapis.com/drive/v3/files', $headers, null, $query_params );

			case 'create_folder':
				$name = $parameters['name'] ?? '';
				if ( empty( $name ) ) {
					throw new Exception( 'Folder name is required' );
				}

				$data = array(
					'name'     => $name,
					'mimeType' => 'application/vnd.google-apps.folder',
				);

				if ( ! empty( $parameters['parent_folder_id'] ) ) {
					$data['parents'] = array( $parameters['parent_folder_id'] );
				}

				$headers['Content-Type'] = 'application/json';

				return self::make_http_request( 'POST', 'https://www.googleapis.com/drive/v3/files', $headers, $data );

			default:
				throw new Exception( 'Unsupported Google Drive tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute Google Calendar API calls
	 */
	private static function execute_google_calendar_api( $tool, $parameters, $auth_config ) {
		$access_token = $auth_config['access_token'] ?? '';
		if ( empty( $access_token ) ) {
			throw new Exception( 'Google Calendar access token is required' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json',
		);

		switch ( $tool ) {
			case 'create_event':
				$summary    = $parameters['summary'] ?? '';
				$start_time = $parameters['start_time'] ?? '';
				$end_time   = $parameters['end_time'] ?? '';

				if ( empty( $summary ) || empty( $start_time ) || empty( $end_time ) ) {
					throw new Exception( 'Summary, start time, and end time are required' );
				}

				$data = array(
					'summary' => $summary,
					'start'   => array( 'dateTime' => $start_time ),
					'end'     => array( 'dateTime' => $end_time ),
				);

				if ( ! empty( $parameters['description'] ) ) {
					$data['description'] = $parameters['description'];
				}

				if ( ! empty( $parameters['attendees'] ) ) {
					$attendees         = explode( ',', $parameters['attendees'] );
					$data['attendees'] = array_map(
						function ( $email ) {
							return array( 'email' => trim( $email ) );
						},
						$attendees
					);
				}

				return self::make_http_request( 'POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events', $headers, $data );

			case 'list_events':
				$query_params = array(
					'maxResults'   => $parameters['max_results'] ?? 10,
					'singleEvents' => 'true',
					'orderBy'      => 'startTime',
				);

				if ( ! empty( $parameters['time_min'] ) ) {
					$query_params['timeMin'] = $parameters['time_min'];
				}

				$calendar_id = $parameters['calendar_id'] ?? 'primary';

				return self::make_http_request( 'GET', "https://www.googleapis.com/calendar/v3/calendars/{$calendar_id}/events", $headers, null, $query_params );

			default:
				throw new Exception( 'Unsupported Google Calendar tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Execute PostgreSQL queries
	 */
	private static function execute_postgresql_query( $tool, $parameters, $auth_config ) {
		$host     = $auth_config['host'] ?? '';
		$port     = $auth_config['port'] ?? 5432;
		$database = $auth_config['database'] ?? '';
		$username = $auth_config['username'] ?? '';
		$password = $auth_config['password'] ?? '';

		if ( empty( $host ) || empty( $database ) || empty( $username ) ) {
			throw new Exception( 'PostgreSQL connection parameters are required' );
		}

		try {
			$dsn = "pgsql:host={$host};port={$port};dbname={$database}";
			$pdo = new PDO(
				$dsn,
				$username,
				$password,
				array(
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_TIMEOUT => 30,
				)
			);

			switch ( $tool ) {
				case 'query':

					$sql = $parameters['sql'] ?? '';
					if ( empty( $sql ) ) {
						throw new Exception( 'SQL query is required' );
					}

					// Basic SQL injection protection by validating SQL structure
					$sql = trim( $sql );
					if ( preg_match( '/;\s*(?:DROP|DELETE|TRUNCATE|ALTER|CREATE|GRANT|REVOKE)\s+/i', $sql ) ) {
						throw new Exception( 'Dangerous SQL operations are not allowed' );
					}

					$params = array();
					if ( ! empty( $parameters['params'] ) ) {
						$params = json_decode( $parameters['params'], true ) ?: array();
					}

					$stmt = $pdo->prepare( $sql );
					$stmt->execute( $params );

					return $stmt->fetchAll( PDO::FETCH_ASSOC );

				case 'insert':
					$table = $parameters['table'] ?? '';
					$data  = $parameters['data'] ?? '{}';

					if ( empty( $table ) ) {
						throw new Exception( 'Table name is required' );
					}

					// Sanitize table name to prevent SQL injection
					if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table ) ) {
						throw new Exception( 'Invalid table name format' );
					}

					$insert_data = json_decode( $data, true );
					if ( ! $insert_data ) {
						throw new Exception( 'Valid JSON data is required' );
					}

					$columns = array_keys( $insert_data );
					
					// Sanitize column names to prevent SQL injection
					foreach ( $columns as $column ) {
						if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ) {
							throw new Exception( 'Invalid column name format: ' . esc_html( $column ) );
						}
					}
					
					$placeholders = array_map(
						function ( $col ) {
							return ':' . $col;
						},
						$columns
					);

					// Use quoted identifiers for PostgreSQL
					$quoted_table = '"' . str_replace( '"', '""', $table ) . '"';
					$quoted_columns = array_map(
						function ( $col ) {
							return '"' . str_replace( '"', '""', $col ) . '"';
						},
						$columns
					);

					$sql = "INSERT INTO {$quoted_table} (" . implode( ', ', $quoted_columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

					$stmt = $pdo->prepare( $sql );
					foreach ( $insert_data as $key => $value ) {
						$stmt->bindValue( ':' . $key, $value );
					}

					$stmt->execute();

					return array( 'affected_rows' => $stmt->rowCount() );

				case 'update':
					$table = $parameters['table'] ?? '';
					$data  = $parameters['data'] ?? '{}';
					$where_clause = $parameters['where'] ?? '';
					$where_params = $parameters['where_params'] ?? '{}';

					if ( empty( $table ) || empty( $where_clause ) ) {
						throw new Exception( 'Table name and WHERE clause are required for update' );
					}

					// Sanitize table name to prevent SQL injection
					if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table ) ) {
						throw new Exception( 'Invalid table name format' );
					}

					// Basic validation of WHERE clause to prevent SQL injection
					// This should only contain column names, operators, and placeholders
					$where_clause = trim( $where_clause );
					if ( preg_match( '/;\s*(?:DROP|DELETE|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|INSERT|UPDATE)\s+/i', $where_clause ) ) {
						throw new Exception( 'Invalid WHERE clause: dangerous SQL operations detected' );
					}

					$update_data = json_decode( $data, true );
					if ( ! $update_data ) {
						throw new Exception( 'Valid JSON data is required' );
					}

					$where_data = json_decode( $where_params, true ) ?: array();

					// Sanitize column names to prevent SQL injection
					foreach ( array_keys( $update_data ) as $column ) {
						if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ) {
							throw new Exception( 'Invalid column name format: ' . esc_html( $column ) );
						}
					}

					// Build SET clause with proper quoting
					$set_clauses = array();
					foreach ( $update_data as $column => $value ) {
						$quoted_column = '"' . str_replace( '"', '""', $column ) . '"';
						$set_clauses[] = "{$quoted_column} = :{$column}";
					}

					$quoted_table = '"' . str_replace( '"', '""', $table ) . '"';
					$sql = "UPDATE {$quoted_table} SET " . implode( ', ', $set_clauses ) . " WHERE {$where_clause}";

					$stmt = $pdo->prepare( $sql );

					// Bind update values
					foreach ( $update_data as $key => $value ) {
						$stmt->bindValue( ':' . $key, $value );
					}

					// Bind WHERE parameters
					foreach ( $where_data as $key => $value ) {
						$stmt->bindValue( ':' . $key, $value );
					}

					$stmt->execute();

					return array( 'affected_rows' => $stmt->rowCount() );

				case 'create_table':
					$table_name = $parameters['table_name'] ?? '';
					$columns_definition = $parameters['columns'] ?? '';

					if ( empty( $table_name ) || empty( $columns_definition ) ) {
						throw new Exception( 'Table name and columns definition are required' );
					}

					// Sanitize table name to prevent SQL injection
					if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name ) ) {
						throw new Exception( 'Invalid table name format' );
					}

					// Basic validation of columns definition to prevent SQL injection
					$columns_definition = trim( $columns_definition );
					if ( empty( $columns_definition ) ) {
						throw new Exception( 'Columns definition cannot be empty' );
					}

					// Prevent dangerous SQL operations in column definitions
					if ( preg_match( '/;\s*(?:DROP|DELETE|TRUNCATE|ALTER|GRANT|REVOKE|INSERT|UPDATE|CREATE\s+(?:DATABASE|USER|ROLE))\s+/i', $columns_definition ) ) {
						throw new Exception( 'Invalid column definition: dangerous SQL operations detected' );
					}

					// Use quoted identifier for table name
					$quoted_table = '"' . str_replace( '"', '""', $table_name ) . '"';
					
					// Note: columns_definition should contain properly formatted PostgreSQL column definitions
					// This is a basic implementation - in production, consider more strict validation
					$sql = "CREATE TABLE {$quoted_table} ({$columns_definition})";

					$stmt = $pdo->prepare( $sql );
					$stmt->execute();

					return array( 'message' => 'Table ' . esc_html( $table_name ) . ' created successfully' );

				default:
					throw new Exception( 'Unsupported PostgreSQL tool: ' . esc_html( $tool ) );
			}
		} catch ( PDOException $e ) {
			throw new Exception( 'PostgreSQL error: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Execute Chroma API calls
	 */
	private static function execute_chroma_api( $tool, $parameters, $auth_config ) {
		$host    = $auth_config['host'] ?? 'localhost';
		$port    = $auth_config['port'] ?? 8000;
		$api_key = $auth_config['api_key'] ?? '';

		$base_url = "http://{$host}:{$port}";

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		switch ( $tool ) {
			case 'query_collection':
				$collection_name = $parameters['collection_name'] ?? '';
				$query_texts     = $parameters['query_texts'] ?? '[]';

				if ( empty( $collection_name ) ) {
					throw new Exception( 'Collection name is required' );
				}

				// Sanitize collection name to prevent URL injection
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $collection_name ) ) {
					throw new Exception( 'Invalid collection name format' );
				}

				$query_array = json_decode( $query_texts, true );
				if ( ! $query_array ) {
					throw new Exception( 'Valid query texts array is required' );
				}

				$data = array(
					'query_texts' => $query_array,
					'n_results'   => intval( $parameters['n_results'] ?? 5 ),
				);

				// URL encode collection name for safety
				$encoded_collection_name = rawurlencode( $collection_name );
				return self::make_http_request( 'POST', "{$base_url}/api/v1/collections/{$encoded_collection_name}/query", $headers, $data );

			case 'create_collection':
				$collection_name = $parameters['collection_name'] ?? '';
				if ( empty( $collection_name ) ) {
					throw new Exception( 'Collection name is required' );
				}

				// Sanitize collection name to prevent injection attacks
				if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $collection_name ) ) {
					throw new Exception( 'Invalid collection name format' );
				}

				$data = array(
					'name' => $collection_name,
				);

				return self::make_http_request( 'POST', "{$base_url}/api/v1/collections", $headers, $data );

			default:
				throw new Exception( 'Unsupported Chroma tool: ' . esc_html( $tool ) );
		}
	}

	/**
	 * Make HTTP request helper
	 */
	private static function make_http_request( $method, $url, $headers = array(), $data = null, $query_params = array() ) {
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( $data !== null ) {
			$args['body'] = is_array( $data ) ? wp_json_encode( $data ) : $data;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'HTTP request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 400 ) {
			$error_data    = json_decode( $response_body, true );
			$error_message = $error_data['error']['message'] ?? $error_data['message'] ?? "HTTP $response_code error";
			throw new Exception( esc_html( $error_message ) );
		}

		$decoded_response = json_decode( $response_body, true );
		return $decoded_response ?: $response_body;
	}

	/**
	 * Test custom server connection
	 */
	private static function test_custom_server( $params ) {
		$custom_config   = $params['customServerConfig'] ?? array();
		$selected_tool   = $params['selectedTool'] ?? '';
		$tool_parameters = $params['toolParameters'] ?? array();

		if ( empty( $custom_config['connectionType'] ) ) {
			return new WP_Error( 'missing_connection_type', 'Connection type is required', array( 'status' => 400 ) );
		}

		if ( $custom_config['connectionType'] === 'http' ) {
			return self::test_custom_http_server( $custom_config, $selected_tool, $tool_parameters );
		} elseif ( $custom_config['connectionType'] === 'stdio' ) {
			return self::test_custom_stdio_server( $custom_config, $selected_tool, $tool_parameters );
		}

		return new WP_Error( 'invalid_connection_type', 'Invalid connection type', array( 'status' => 400 ) );
	}

	/**
	 * Test custom HTTP server with actual MCP protocol
	 */
	private static function test_custom_http_server( $config, $tool, $parameters ) {
		if ( empty( $config['endpoint'] ) ) {
			return new WP_Error( 'missing_endpoint', 'Endpoint URL is required', array( 'status' => 400 ) );
		}

		$endpoint = rtrim( $config['endpoint'], '/' );

		// Build MCP tool call request
		$request_body = array(
			'jsonrpc' => '2.0',
			'id'      => wp_generate_uuid4(),
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => $tool,
				'arguments' => $parameters,
			),
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		// Add authentication headers
		if ( ! empty( $config['authFields'] ) ) {
			foreach ( $config['authFields'] as $auth_field ) {
				if ( ! empty( $auth_field['key'] ) && ! empty( $auth_field['value'] ) ) {
					$headers[ $auth_field['key'] ] = $auth_field['value'];
				}
			}
		}

		try {
			$result = self::make_http_request( 'POST', $endpoint . '/mcp', $headers, $request_body );

			if ( isset( $result['error'] ) ) {
				return new WP_Error( 'mcp_error', $result['error']['message'] ?? 'Unknown MCP error', array( 'status' => 500 ) );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result['result'] ?? $result,
					'message' => "Successfully executed {$tool} on custom HTTP server",
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'http_request_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Test custom stdio server with actual MCP protocol
	 */
	private static function test_custom_stdio_server( $config, $tool, $parameters ) {
		if ( empty( $config['command'] ) ) {
			return new WP_Error( 'missing_command', 'Command is required', array( 'status' => 400 ) );
		}

		try {
			$command      = escapeshellcmd( $config['command'] );
			$args         = ! empty( $config['args'] ) ? array_map( 'escapeshellarg', $config['args'] ) : array();
			$full_command = $command . ' ' . implode( ' ', $args );

			// Build MCP tool call request
			$request = array(
				'jsonrpc' => '2.0',
				'id'      => wp_generate_uuid4(),
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => $parameters,
				),
			);

			$json_request = wp_json_encode( $request ) . "\n";

			// Open process with pipes
			$descriptorspec = array(
				0 => array( 'pipe', 'r' ), // stdin
				1 => array( 'pipe', 'w' ), // stdout
				2 => array( 'pipe', 'w' ),  // stderr
			);

			$process = proc_open( $full_command, $descriptorspec, $pipes );

			if ( ! is_resource( $process ) ) {
				return new WP_Error( 'process_failed', 'Failed to start MCP server process', array( 'status' => 500 ) );
			}

			// Write request to stdin
			fwrite( $pipes[0], $json_request );
			fclose( $pipes[0] );

			// Read response from stdout with timeout
			stream_set_timeout( $pipes[1], 30 );
			$response = '';
			while ( ! feof( $pipes[1] ) ) {
				$chunk = fread( $pipes[1], 8192 );
				if ( $chunk === false ) {
					break;
				}
				$response .= $chunk;

				// Check for timeout
				$info = stream_get_meta_data( $pipes[1] );
				if ( $info['timed_out'] ) {
					fclose( $pipes[1] );
					fclose( $pipes[2] );
					proc_close( $process );
					return new WP_Error( 'timeout', 'MCP server response timeout', array( 'status' => 500 ) );
				}
			}

			// Get stderr for error information
			$error_output = stream_get_contents( $pipes[2] );

			fclose( $pipes[1] );
			fclose( $pipes[2] );

			$return_code = proc_close( $process );

			if ( $return_code !== 0 ) {
				return new WP_Error( 'process_error', 'MCP server exited with code ' . intval( $return_code ) . ': ' . esc_html( $error_output ), array( 'status' => 500 ) );
			}

			// Parse JSON response
			$lines         = explode( "\n", trim( $response ) );
			$json_response = null;

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) ) {
					continue;
				}

				$data = json_decode( $line, true );
				if ( json_last_error() === JSON_ERROR_NONE && isset( $data['jsonrpc'] ) ) {
					$json_response = $data;
					break;
				}
			}

			if ( ! $json_response ) {
				return new WP_Error( 'invalid_response', 'Invalid JSON response from MCP server', array( 'status' => 500 ) );
			}

			if ( isset( $json_response['error'] ) ) {
				return new WP_Error( 'mcp_error', $json_response['error']['message'] ?? 'Unknown MCP error', array( 'status' => 500 ) );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $json_response['result'] ?? $json_response,
					'message' => "Successfully executed {$tool} on custom stdio server",
				),
				200
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'stdio_test_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Validate authentication for preset servers
	 */
	private static function validate_preset_server_auth( $server_type, $config ) {
		$required_fields = array();

		switch ( $server_type ) {
			case 'slack':
				$required_fields = array( 'bot_token' );
				break;
			case 'github':
				$required_fields = array( 'access_token' );
				break;
			case 'notion':
				$required_fields = array( 'integration_token' );
				break;
			case 'google_drive':
			case 'google_calendar':
			case 'gmail':
				$required_fields = array( 'access_token' );
				break;
			case 'postgresql':
				$required_fields = array( 'host', 'database', 'username', 'password' );
				break;
			case 'chroma':
				$required_fields = array( 'host' );
				break;
		}

		foreach ( $required_fields as $field ) {
			if ( empty( $config[ $field ] ) ) {
				return new WP_Error( 'missing_auth_field', 'Missing required field: ' . esc_html( $field ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	/**
	 * Execute MCP tool (called during workflow execution)
	 */
	public static function execute_tool( $server_config, $tool, $parameters ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'tool'        => $tool,
				'server_type' => $server_config['serverType'] ?? 'unknown',
			)
		);

		try {
			$server_type = $server_config['serverType'] ?? '';

			if ( isset( self::$preset_servers[ $server_type ] ) ) {
				$result = self::execute_preset_api_call( $server_type, $tool, $parameters, $server_config['connectionConfig'] ?? array() );

				WP_AI_Workflows_Utilities::debug_log(
					'Preset server tool executed',
					'info',
					array(
						'server_type' => $server_type,
						'tool'        => $tool,
						'success'     => true,
					)
				);

				return array(
					'type'    => 'mcpClient',
					'content' => $result,
				);
			}

			if ( $server_type === 'custom' ) {
				$result = self::execute_custom_server_tool( $server_config, $tool, $parameters );
				return $result;
			}

			return array(
				'type'    => 'error',
				'content' => 'Invalid server type: ' . $server_type,
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'MCP tool execution failed',
				'error',
				array(
					'tool'  => $tool,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'type'    => 'error',
				'content' => 'Tool execution failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Execute tool on custom server
	 */
	private static function execute_custom_server_tool( $server_config, $tool, $parameters ) {
		$custom_config = $server_config['customServerConfig'] ?? array();

		if ( $custom_config['connectionType'] === 'http' ) {
			$result = self::test_custom_http_server( $custom_config, $tool, $parameters );

			if ( is_wp_error( $result ) ) {
				return array(
					'type'    => 'error',
					'content' => $result->get_error_message(),
				);
			}

			$response_data = $result->get_data();
			return array(
				'type'    => 'mcpClient',
				'content' => $response_data['data'] ?? $response_data,
			);
		}

		if ( $custom_config['connectionType'] === 'stdio' ) {
			$result = self::test_custom_stdio_server( $custom_config, $tool, $parameters );

			if ( is_wp_error( $result ) ) {
				return array(
					'type'    => 'error',
					'content' => $result->get_error_message(),
				);
			}

			$response_data = $result->get_data();
			return array(
				'type'    => 'mcpClient',
				'content' => $response_data['data'] ?? $response_data,
			);
		}

		return array(
			'type'    => 'error',
			'content' => 'Invalid connection type for custom server',
		);
	}
}
