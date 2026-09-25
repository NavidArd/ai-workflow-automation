<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Google_Service {
	private $access_token;
	private $client_id;
	private $client_secret;
	private $redirect_uri;
	private $drive_base_url  = 'https://www.googleapis.com/drive/v3';
	private $sheets_base_url = 'https://sheets.googleapis.com/v4';

	public function __construct() {
		$this->initialize_settings();
		$this->access_token = $this->get_access_token();
	}

	private function initialize_settings() {
		$settings            = WP_AI_Workflows_Utilities::get_google_settings();
		$this->client_id     = $settings['google_client_id'];
		$this->client_secret = $settings['google_client_secret'];
		$this->redirect_uri  = $settings['google_redirect_uri'];
	}

	private function get_access_token() {
		$tokens = WP_AI_Workflows_Utilities::get_google_tokens();

		if ( ! $tokens['access_token'] ) {
			throw new Exception( 'No access token available. User needs to authenticate.' );
		}

		$token_info  = get_option( 'wp_ai_workflows_google_token_info', array() );
		$expiry_time = isset( $token_info['expires_at'] ) ? $token_info['expires_at'] : 0;

		// If token expires in less than 5 minutes, refresh it
		if ( time() + 300 > $expiry_time ) {
			try {
				$this->refresh_token();
				$tokens = WP_AI_Workflows_Utilities::get_google_tokens();
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Token refresh failed',
					'error',
					array(
						'error' => $e->getMessage(),
					)
				);
				throw $e;
			}
		}

		return $tokens['access_token'];
	}

	private function refresh_token() {
		WP_AI_Workflows_Utilities::debug_log( 'Refreshing access token', 'debug' );
		$tokens = WP_AI_Workflows_Utilities::get_google_tokens();

		if ( empty( $tokens['refresh_token'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'No refresh token available', 'error' );
			$this->handle_invalid_tokens();
			throw new Exception( 'No refresh token available. User needs to re-authenticate.' );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'refresh_token' => $tokens['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error refreshing token',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			$this->handle_invalid_tokens();
			throw new Exception( 'Failed to refresh token: ' . esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			if ( $body['error'] === 'invalid_grant' ) {
				WP_AI_Workflows_Utilities::debug_log( 'Invalid grant error - token expired or revoked', 'error' );
				$this->handle_invalid_tokens();
				throw new Exception( 'Google access has been revoked or expired. Please re-authenticate.' );
			}
			throw new Exception( 'Error refreshing token: ' . esc_html( $body['error_description'] ?? $body['error'] ) );
		}

		if ( ! isset( $body['access_token'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Invalid refresh token response', 'error', $body );
			$this->handle_invalid_tokens();
			throw new Exception( 'Invalid refresh token response from Google' );
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		update_option(
			'wp_ai_workflows_google_token_info',
			array(
				'expires_at' => time() + $expires_in,
				'token_type' => $body['token_type'] ?? 'Bearer',
				'scope'      => $body['scope'] ?? '',
			)
		);

		$this->access_token = $body['access_token'];
		WP_AI_Workflows_Utilities::update_google_tokens( $this->access_token, $tokens['refresh_token'] );
		WP_AI_Workflows_Utilities::debug_log( 'Access token refreshed successfully', 'debug' );
	}

	private function handle_invalid_tokens() {
		delete_option( 'wp_ai_workflows_google_access_token' );
		delete_option( 'wp_ai_workflows_google_refresh_token' );
		delete_option( 'wp_ai_workflows_google_token_info' );
		update_option( 'wp_ai_workflows_google_integrated', false );

		set_transient( 'wp_ai_workflows_needs_google_reauth', true, HOUR_IN_SECONDS );

		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$reauth_url  = admin_url( 'admin.php?page=wp-ai-workflows&action=settings' );

		$message = sprintf(
			'Your Google integration for AI Workflows on %s needs to be reauthorized. ' .
			'Please visit %s to reauthorize the integration.',
			$site_name,
			$reauth_url
		);

		wp_mail(
			$admin_email,
			'Action Required: Reauthorize Google Integration',
			$message,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		WP_AI_Workflows_Utilities::debug_log( 'Cleared invalid Google tokens', 'info' );
	}

	private function make_request( $url, $method = 'GET', $body = null ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
			),
			'method'  => $method,
		);

		if ( $body ) {
			$args['body']                    = json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'WP Error in make_request: ' . $response->get_error_message(), 'error' );
			throw new Exception( 'Request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code === 401 ) {
			$this->refresh_token();
			return $this->make_request( $url, $method, $body );
		}

		if ( $status_code >= 400 ) {
			throw new Exception( 'Request failed with status code ' . absint( $status_code ) . ': ' . esc_html( $body ) );
		}

		$response_body = json_decode( $body, true );
		WP_AI_Workflows_Utilities::debug_log( 'Google API response', 'debug', $response_body );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log( 'JSON decode error: ' . json_last_error_msg(), 'error' );
			WP_AI_Workflows_Utilities::debug_log( 'Raw response body: ' . $body, 'error' );
			throw new Exception( 'Invalid JSON response: ' . esc_html( json_last_error_msg() ) );
		}

		return $response_body;
	}

	public function list_spreadsheets() {
		$response = $this->make_request( $this->drive_base_url . '/files?q=mimeType%3D%27application%2Fvnd.google-apps.spreadsheet%27' );
		return $response['files'];
	}

	public function list_drive_items() {
		$response = $this->make_request( $this->drive_base_url . '/files?q=mimeType%3D%27application%2Fvnd.google-apps.folder%27%20or%20mimeType!%3D%27application%2Fvnd.google-apps.folder%27' );
		return array(
			'folders' => array_filter(
				$response['files'],
				function ( $file ) {
					return $file['mimeType'] === 'application/vnd.google-apps.folder';
				}
			),
			'files'   => array_filter(
				$response['files'],
				function ( $file ) {
					return $file['mimeType'] !== 'application/vnd.google-apps.folder';
				}
			),
		);
	}

	public function check_sheet_changes( $sheet_id, $tab_id, $trigger_type, $last_check_time ) {
		try {
			$last_check_time_rfc3339 = gmdate( 'c', strtotime( $last_check_time ) );

			$response = $this->make_request( $this->sheets_base_url . "/spreadsheets/$sheet_id/values/$tab_id?majorDimension=ROWS&valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING" );

			if ( ! isset( $response['values'] ) ) {
				throw new Exception( 'Failed to retrieve sheet values' );
			}

			$current_values = $response['values'];

			$previous_values = $this->get_previous_sheet_state( $sheet_id, $tab_id );

			$changes = $this->compare_sheet_states( $previous_values, $current_values );

			$filtered_changes = $this->filter_changes_by_trigger_type( $changes, $trigger_type );

			$this->store_sheet_state( $sheet_id, $tab_id, $current_values );

			WP_AI_Workflows_Utilities::debug_log(
				'Sheet changes detected',
				'info',
				array(
					'sheet_id'      => $sheet_id,
					'tab_id'        => $tab_id,
					'changes_count' => count( $filtered_changes ),
				)
			);

			return $filtered_changes;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error checking sheet changes',
				'error',
				array(
					'sheet_id' => $sheet_id,
					'tab_id'   => $tab_id,
					'error'    => $e->getMessage(),
				)
			);
			return array();
		}
	}

	private function compare_sheet_states( $previous_values, $current_values ) {
		$changes            = array();
		$current_row_count  = count( $current_values );
		$previous_row_count = count( $previous_values );

		for ( $i = 0; $i < max( $current_row_count, $previous_row_count ); $i++ ) {
			$current_row  = $current_values[ $i ] ?? null;
			$previous_row = $previous_values[ $i ] ?? null;

			if ( $current_row && ! $previous_row ) {
				$changes[] = array(
					'type'      => 'row_added',
					'row_index' => $i + 1,
					'data'      => $current_row,
				);
			} elseif ( ! $current_row && $previous_row ) {
				$changes[] = array(
					'type'      => 'row_deleted',
					'row_index' => $i + 1,
					'data'      => $previous_row,
				);
			} elseif ( $current_row && $previous_row && $this->rows_are_different( $current_row, $previous_row ) ) {
				$changes[] = array(
					'type'      => 'row_updated',
					'row_index' => $i + 1,
					'old_data'  => $previous_row,
					'new_data'  => $current_row,
					'changes'   => $this->get_row_changes( $previous_row, $current_row ),
				);
			}
		}

		return $changes;
	}

	private function rows_are_different( $row1, $row2 ) {
		if ( count( $row1 ) !== count( $row2 ) ) {
			return true;
		}

		for ( $i = 0; $i < count( $row1 ); $i++ ) {
			if ( $this->normalize_value( $row1[ $i ] ) !== $this->normalize_value( $row2[ $i ] ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_value( $value ) {
		return trim( (string) $value );
	}

	private function get_row_changes( $old_row, $new_row ) {
		$changes  = array();
		$max_cols = max( count( $old_row ), count( $new_row ) );

		for ( $i = 0; $i < $max_cols; $i++ ) {
			$old_value = $old_row[ $i ] ?? null;
			$new_value = $new_row[ $i ] ?? null;

			if ( $this->normalize_value( $old_value ) !== $this->normalize_value( $new_value ) ) {
				$changes[] = array(
					'column_index' => $i + 1,
					'old_value'    => $old_value,
					'new_value'    => $new_value,
				);
			}
		}

		return $changes;
	}

	private function filter_changes_by_trigger_type( $changes, $trigger_type ) {
		switch ( $trigger_type ) {
			case 'rowAdded':
				return array_filter(
					$changes,
					function ( $change ) {
						return $change['type'] === 'row_added';
					}
				);
			case 'rowUpdated':
				return array_filter(
					$changes,
					function ( $change ) {
						return $change['type'] === 'row_updated';
					}
				);
			case 'rowAddedOrUpdated':
				return array_filter(
					$changes,
					function ( $change ) {
						return in_array( $change['type'], array( 'row_added', 'row_updated' ) );
					}
				);
			default:
				return $changes;
		}
	}

	private function get_previous_sheet_state( $sheet_id, $tab_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_sheet_states';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sheet_state FROM %i WHERE sheet_id = %s AND tab_id = %s",
				$table_name,
				$sheet_id,
				$tab_id
			)
		);

		if ( $result === null ) {
			WP_AI_Workflows_Utilities::debug_log(
				'No previous sheet state found',
				'info',
				array(
					'sheet_id' => $sheet_id,
					'tab_id'   => $tab_id,
				)
			);
			return array();
		}

		$decoded_state = json_decode( $result, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error decoding previous sheet state',
				'error',
				array(
					'sheet_id'   => $sheet_id,
					'tab_id'     => $tab_id,
					'json_error' => json_last_error_msg(),
				)
			);
			return array();
		}

		return $decoded_state;
	}

	private function store_sheet_state( $sheet_id, $tab_id, $values ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_sheet_states';

		$encoded_state = json_encode( $values );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error encoding sheet state',
				'error',
				array(
					'sheet_id'   => $sheet_id,
					'tab_id'     => $tab_id,
					'json_error' => json_last_error_msg(),
				)
			);
			return;
		}

		// Use direct query with proper escaping for schema operations
		$result = $wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO %i (sheet_id, tab_id, sheet_state, updated_at) VALUES (%s, %s, %s, %s)",
				$table_name,
				$sheet_id,
				$tab_id,
				$encoded_state,
				current_time( 'mysql', 1 )
			)
		);

		if ( $result === false ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error storing sheet state',
				'error',
				array(
					'sheet_id' => $sheet_id,
					'tab_id'   => $tab_id,
					'db_error' => $wpdb->last_error,
				)
			);
		} else {
			WP_AI_Workflows_Utilities::debug_log(
				'Sheet state stored successfully',
				'info',
				array(
					'sheet_id' => $sheet_id,
					'tab_id'   => $tab_id,
				)
			);
		}
	}

	public function check_drive_changes( $watch_type, $item_id, $trigger_type, $last_check_time ) {
		$last_check_time_rfc3339 = gmdate( 'c', strtotime( $last_check_time ) );

		$changes = array();

		if ( $watch_type === 'file' ) {
			$response = $this->make_request( $this->drive_base_url . "/files/$item_id?fields=id,name,mimeType,modifiedTime,createdTime" );

			if ( strtotime( $response['modifiedTime'] ) > strtotime( $last_check_time ) ) {
				$changes[] = array(
					'type'          => 'file_updated',
					'file_id'       => $response['id'],
					'file_name'     => $response['name'],
					'modified_time' => $response['modifiedTime'],
				);
			}
		} elseif ( $watch_type === 'folder' ) {
			$query          = "'" . $item_id . "' in parents and (modifiedTime > '$last_check_time_rfc3339' or createdTime > '$last_check_time_rfc3339')";
			$files_response = $this->make_request( $this->drive_base_url . '/files?q=' . urlencode( $query ) . '&fields=files(id,name,mimeType,modifiedTime,createdTime)' );

			foreach ( $files_response['files'] ?? array() as $file ) {
				$file_data = array(
					'file_id'       => $file['id'],
					'file_name'     => $file['name'],
					'is_folder'     => $file['mimeType'] === 'application/vnd.google-apps.folder',
					'modified_time' => $file['modifiedTime'],
					'created_time'  => $file['createdTime'],
				);

				if ( strtotime( $file['createdTime'] ) > strtotime( $last_check_time ) ) {
					$changes[] = array_merge( $file_data, array( 'type' => $file_data['is_folder'] ? 'folder_created' : 'file_created' ) );
				} elseif ( strtotime( $file['modifiedTime'] ) > strtotime( $last_check_time ) ) {
					$changes[] = array_merge( $file_data, array( 'type' => $file_data['is_folder'] ? 'folder_updated' : 'file_updated' ) );
				}
			}

			// Check if the watched folder itself was modified
			$folder_response = $this->make_request( $this->drive_base_url . "/files/$item_id?fields=modifiedTime" );
			if ( strtotime( $folder_response['modifiedTime'] ) > strtotime( $last_check_time ) ) {
				$changes[] = array(
					'type'          => 'watch_folder_updated',
					'folder_id'     => $item_id,
					'modified_time' => $folder_response['modifiedTime'],
				);
			}
		}

		return $changes;
	}

	public function get_spreadsheet_tabs( $spreadsheet_id ) {
		try {
			$endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}?fields=sheets.properties(sheetId,title)";
			$response = $this->make_request( $endpoint );

			if ( ! isset( $response['sheets'] ) || ! is_array( $response['sheets'] ) ) {
				WP_AI_Workflows_Utilities::debug_log( 'Sheets not found or not an array', 'error', $response );
				throw new Exception( 'No valid sheets found in the response' );
			}

			$sheets = array_map(
				function ( $sheet ) {
					return array(
						'id'   => $sheet['properties']['sheetId'],
						'name' => $sheet['properties']['title'],
					);
				},
				$response['sheets']
			);

			return $sheets;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log( 'Error in get_spreadsheet_tabs: ' . $e->getMessage(), 'error' );
			throw $e;
		}
	}

	public function get_sheet_columns( $spreadsheet_id, $sheet_id ) {
		try {
			$sheets_metadata_endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}?fields=sheets.properties";
			WP_AI_Workflows_Utilities::debug_log( 'Fetching sheets metadata', 'debug', array( 'endpoint' => $sheets_metadata_endpoint ) );
			$sheets_metadata = $this->make_request( $sheets_metadata_endpoint );

			$sheet_name = null;
			foreach ( $sheets_metadata['sheets'] as $sheet ) {
				if ( $sheet['properties']['sheetId'] == $sheet_id ) {
					$sheet_name = $sheet['properties']['title'];
					break;
				}
			}

			if ( ! $sheet_name ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Sheet not found',
					'error',
					array(
						'sheet_id'       => $sheet_id,
						'spreadsheet_id' => $spreadsheet_id,
					)
				);
				throw new Exception( 'Sheet with ID ' . esc_html( $sheet_id ) . ' not found' );
			}

			$endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}/values/{$sheet_name}!1:1";
			WP_AI_Workflows_Utilities::debug_log( 'Fetching sheet columns', 'debug', array( 'endpoint' => $endpoint ) );
			$response = $this->make_request( $endpoint );

			if ( ! isset( $response['values'] ) || ! is_array( $response['values'] ) || empty( $response['values'][0] ) ) {
				WP_AI_Workflows_Utilities::debug_log( 'No columns found', 'error', $response );
				throw new Exception( 'No columns found in the response' );
			}

			$columns = array();
			foreach ( $response['values'][0] as $index => $value ) {
				if ( ! empty( $value ) ) {
					$columns[] = $value;
				} else {
					$columns[] = 'Column ' . ( $index + 1 );
				}
			}

			WP_AI_Workflows_Utilities::debug_log( 'Processed columns', 'debug', $columns );

			return $columns;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in get_sheet_columns',
				'error',
				array(
					'message'        => $e->getMessage(),
					'spreadsheet_id' => $spreadsheet_id,
					'sheet_id'       => $sheet_id,
				)
			);
			throw $e;
		}
	}

	public function append_to_sheet( $spreadsheet_id, $sheet_id, $values ) {
		$sheets_metadata_endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}?fields=sheets.properties";
		$sheets_metadata          = $this->make_request( $sheets_metadata_endpoint );

		$sheet_name = null;
		foreach ( $sheets_metadata['sheets'] as $sheet ) {
			if ( $sheet['properties']['sheetId'] == $sheet_id ) {
				$sheet_name = $sheet['properties']['title'];
				break;
			}
		}

		if ( ! $sheet_name ) {
			throw new Exception( 'Sheet with ID ' . esc_html( $sheet_id ) . ' not found' );
		}

		$headers_endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}/values/{$sheet_name}!1:1";
		$headers_response = $this->make_request( $headers_endpoint );
		$headers          = $headers_response['values'][0] ?? array();

		$ordered_values = array();
		foreach ( $headers as $header ) {
			$ordered_values[] = $values[ $header ] ?? '';
		}

		$endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}/values/{$sheet_name}:append?valueInputOption=USER_ENTERED";
		$body     = array(
			'values' => array( $ordered_values ),
		);
		return $this->make_request( $endpoint, 'POST', $body );
	}

	public function list_drive_folders() {
		$all_folders = array();
		$page_token  = null;

		do {
			$query_params = array(
				'q'        => "mimeType='application/vnd.google-apps.folder'",
				'fields'   => 'nextPageToken, files(id,name)',
				'pageSize' => 1000,
			);

			if ( $page_token ) {
				$query_params['pageToken'] = $page_token;
			}

			$url = $this->drive_base_url . '/files?' . http_build_query( $query_params );

			try {
				$response = $this->make_request( $url );

				if ( isset( $response['files'] ) ) {
					$all_folders = array_merge( $all_folders, $response['files'] );
				}

				$page_token = isset( $response['nextPageToken'] ) ? $response['nextPageToken'] : null;

			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error fetching Drive folders',
					'error',
					array(
						'error'      => $e->getMessage(),
						'page_token' => $page_token,
					)
				);
				break;
			}
		} while ( $page_token );

		usort(
			$all_folders,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		WP_AI_Workflows_Utilities::debug_log(
			'Drive folders fetched',
			'debug',
			array(
				'total_folders' => count( $all_folders ),
			)
		);

		return $all_folders;
	}

	public function create_drive_file( $folder_id, $file_name, $file_content, $mime_type, $sharing_level = 'private' ) {
		$metadata = array(
			'name'    => $file_name,
			'parents' => array( $folder_id ),
		);

		$file_size = strlen( $file_content );

		$create_result = ( $file_size <= 5 * 1024 * 1024 )
			? $this->simple_upload( $metadata, $file_content, $mime_type )
			: $this->resumable_upload( $metadata, $file_content, $mime_type );

		if ( isset( $create_result['id'] ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'File created, applying sharing settings',
				'debug',
				array(
					'file_id'       => $create_result['id'],
					'sharing_level' => $sharing_level,
				)
			);

			if ( $sharing_level !== 'private' ) {
				try {
					$permission = array(
						'type' => 'anyone',
						'role' => ( $sharing_level === 'anyone_write' ) ? 'writer' : 'reader',
					);

					$permission_url = "{$this->drive_base_url}/files/{$create_result['id']}/permissions";
					$this->make_request( $permission_url, 'POST', $permission );

					// Update file to be discoverable via link
					$update_url = "{$this->drive_base_url}/files/{$create_result['id']}";
					$this->make_request(
						$update_url,
						'PATCH',
						array(
							'copyRequiresWriterPermission' => false,
							'writersCanShare'              => true,
						)
					);
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error setting file permissions',
						'error',
						array(
							'error'   => $e->getMessage(),
							'file_id' => $create_result['id'],
						)
					);
				}
			}

			try {
				$get_url                      = "{$this->drive_base_url}/files/{$create_result['id']}?fields=webViewLink,webContentLink";
				$file_metadata                = $this->make_request( $get_url, 'GET' );
				$create_result['webViewLink'] = $file_metadata['webViewLink'] ??
					"https://drive.google.com/file/d/{$create_result['id']}/view";
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error getting sharing link',
					'error',
					array(
						'error'   => $e->getMessage(),
						'file_id' => $create_result['id'],
					)
				);
				$create_result['webViewLink'] = "https://drive.google.com/file/d/{$create_result['id']}/view";
			}
		}

		return $create_result;
	}

	private function set_file_permissions( $file_id, $sharing_level ) {
		$permission = array();

		switch ( $sharing_level ) {
			case 'anyone_read':
				$permission = array(
					'type'               => 'anyone',
					'role'               => 'reader',
					'allowFileDiscovery' => false,
				);
				break;

			case 'anyone_write':
				$permission = array(
					'type'               => 'anyone',
					'role'               => 'writer',
					'allowFileDiscovery' => false,
				);
				break;

			case 'domain_read':
				$permission = array(
					'type' => 'domain',
					'role' => 'reader',
				);
				break;

			case 'domain_write':
				$permission = array(
					'type' => 'domain',
					'role' => 'writer',
				);
				break;

			case 'private':
			default:
				// No permission changes needed for private
				return;
		}

		if ( ! empty( $permission ) ) {
			$url = "{$this->drive_base_url}/files/{$file_id}/permissions";
			return $this->make_request( $url, 'POST', $permission );
		}
	}



	private function simple_upload( $metadata, $file_content, $mime_type ) {
		$url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';

		$boundary    = md5( time() );
		$delimiter   = "\r\n--" . $boundary . "\r\n";
		$close_delim = "\r\n--" . $boundary . '--';

		$body =
			$delimiter .
			"Content-Type: application/json\r\n\r\n" .
			json_encode( $metadata ) .
			$delimiter .
			"Content-Type: {$mime_type}\r\n\r\n" .
			$file_content .
			$close_delim;

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'  => 'Bearer ' . $this->access_token,
					'Content-Type'   => 'multipart/related; boundary=' . $boundary,
					'Content-Length' => strlen( $body ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Failed to upload file: ' . esc_html( $response->get_error_message() ) );
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 && $status_code !== 201 ) {
			$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			throw new Exception( 'Failed to upload file. Status code: ' . esc_html( $status_code ) . '. Error: ' . esc_html( $error_message ) );
		}

		return $body;
	}

	private function resumable_upload( $metadata, $file_content, $mime_type ) {
		// Step 1: Initiate resumable upload session
		$init_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';

		$init_response = wp_remote_post(
			$init_url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'           => 'Bearer ' . $this->access_token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => $mime_type,
					'X-Upload-Content-Length' => strlen( $file_content ),
				),
				'body'    => json_encode( $metadata ),
			)
		);

		if ( is_wp_error( $init_response ) ) {
			throw new Exception( 'Failed to initiate resumable upload: ' . esc_html( $init_response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $init_response );
		if ( $status_code !== 200 ) {
			throw new Exception( 'Failed to initiate resumable upload. Status code: ' . esc_html( $status_code ) );
		}

		$upload_url = wp_remote_retrieve_header( $init_response, 'location' );
		if ( empty( $upload_url ) ) {
			throw new Exception( 'Failed to get upload URL from resumable upload initiation' );
		}

		// Step 2: Upload the file content
		$upload_response = wp_remote_request(
			$upload_url,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Content-Type'   => $mime_type,
					'Content-Length' => strlen( $file_content ),
				),
				'body'    => $file_content,
			)
		);

		if ( is_wp_error( $upload_response ) ) {
			throw new Exception( 'Failed to upload file content: ' . esc_html( $upload_response->get_error_message() ) );
		}

		$body        = json_decode( wp_remote_retrieve_body( $upload_response ), true );
		$status_code = wp_remote_retrieve_response_code( $upload_response );

		if ( $status_code !== 200 && $status_code !== 201 ) {
			$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			throw new Exception( 'Failed to upload file content. Status code: ' . esc_html( $status_code ) . '. Error: ' . esc_html( $error_message ) );
		}

		return $body;
	}

	public function get_sheet_data( $spreadsheet_id, $sheet_id ) {
		$sheets_metadata_endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}?fields=sheets.properties";
		$sheets_metadata          = $this->make_request( $sheets_metadata_endpoint );

		$sheet_name = null;
		foreach ( $sheets_metadata['sheets'] as $sheet ) {
			if ( $sheet['properties']['sheetId'] == $sheet_id ) {
				$sheet_name = $sheet['properties']['title'];
				break;
			}
		}

		if ( ! $sheet_name ) {
			throw new Exception( 'Sheet with ID ' . esc_html( $sheet_id ) . ' not found' );
		}

		$endpoint = $this->sheets_base_url . "/spreadsheets/{$spreadsheet_id}/values/{$sheet_name}";
		$response = $this->make_request( $endpoint );

		return $response['values'];
	}

	public function get_file_content( $file_id ) {
		$file_metadata = $this->make_request( $this->drive_base_url . "/files/{$file_id}?fields=name,mimeType" );
		$download_url  = $this->drive_base_url . "/files/{$file_id}?alt=media";

		$response = wp_remote_get(
			$download_url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->access_token ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Failed to download file: ' . esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );

		return array(
			'name'     => $file_metadata['name'],
			'mimeType' => $file_metadata['mimeType'],
			'content'  => base64_encode( $body ),
		);
	}
}
