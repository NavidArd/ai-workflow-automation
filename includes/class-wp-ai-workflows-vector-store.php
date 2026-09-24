<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles vector store operations for OpenAI integration
 */
class WP_AI_Workflows_Vector_Store {
	private $openai_api_key;
	private $stores_table;
	private $files_table;

	public function __construct() {
		global $wpdb;
		$this->stores_table = $wpdb->prefix . 'wp_ai_workflows_vector_stores';
		$this->files_table  = $wpdb->prefix . 'wp_ai_workflows_vector_files';
	}

	/**
	 * Create database tables if they don't exist
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$stores_table = "CREATE TABLE IF NOT EXISTS {$this->stores_table} (
            id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            is_default TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

		$files_table = "CREATE TABLE IF NOT EXISTS {$this->files_table} (
            id VARCHAR(255) NOT NULL,
            store_id VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100),
            size BIGINT,
            status VARCHAR(20) DEFAULT 'pending',
            url TEXT,
            local_path TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY store_id (store_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $stores_table );
		dbDelta( $files_table );
	}

	private function get_api_key() {
		if ( $this->openai_api_key === null ) {
			$this->openai_api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		}
		return $this->openai_api_key;
	}

	/**
	 * Create a new vector store
	 */
	public function create_store( $name, $description = '' ) {
		global $wpdb;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		try {
			$response = $this->call_openai_api(
				'POST',
				'vector_stores',
				array(
					'name' => $name,
				)
			);

			if ( empty( $response['id'] ) ) {
				throw new Exception( 'Failed to create vector store in OpenAI' );
			}

			$result = $wpdb->insert(
				$this->stores_table,
				array(
					'id'          => $response['id'],
					'name'        => $name,
					'description' => $description,
					'is_default'  => 0,
				)
			);

			if ( $result === false ) {
				throw new Exception( 'Failed to save vector store to database' );
			}

			$store_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $this->stores_table ) );
			if ( $store_count == 1 ) {
				$wpdb->update(
					$this->stores_table,
					array( 'is_default' => 1 ),
					array( 'id' => $response['id'] )
				);
				$response['is_default'] = true;
			} else {
				$response['is_default'] = false;
			}

			$response['file_count'] = 0;

			return $response;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Vector store creation error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Get all vector stores
	 */
	public function get_all_stores() {
		global $wpdb;

		try {
			$stores = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.*, COUNT(f.id) as file_count
					FROM %i s
					LEFT JOIN %i f ON s.id = f.store_id
					GROUP BY s.id
					ORDER BY s.is_default DESC, s.created_at DESC",
					$this->stores_table,
					$this->files_table
				),
				ARRAY_A
			);

			if ( empty( $stores ) ) {
				return array();
			}

			return array_map(
				function ( $store ) {
					$store['file_count'] = (int) $store['file_count'];
					$store['is_default'] = (bool) $store['is_default'];
					return $store;
				},
				$stores
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Get vector stores error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Update a vector store
	 */
	public function update_store( $store_id, $name = null, $description = null ) {
		global $wpdb;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			),
			ARRAY_A
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		$update_data = array();
		$api_data    = array();

		if ( $name !== null ) {
			$update_data['name'] = $name;
			$api_data['name']    = $name;
		}

		if ( $description !== null ) {
			$update_data['description'] = $description;
		}

		if ( empty( $update_data ) ) {
			return $store;
		}

		try {
			if ( ! empty( $api_data ) ) {
				$response = $this->call_openai_api( 'POST', "vector_stores/{$store_id}", $api_data );

				if ( empty( $response['id'] ) ) {
					throw new Exception( 'Failed to update vector store in OpenAI' );
				}
			}

			$result = $wpdb->update(
				$this->stores_table,
				$update_data,
				array( 'id' => $store_id )
			);

			if ( $result === false ) {
				throw new Exception( 'Failed to update vector store in database' );
			}

			$updated_store = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id = %s",
					$this->stores_table,
					$store_id
				),
				ARRAY_A
			);

			return $updated_store;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Vector store update error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Delete a vector store
	 */
	public function delete_store( $store_id ) {
		global $wpdb;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			),
			ARRAY_A
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		try {
			$this->call_openai_api( 'DELETE', "vector_stores/{$store_id}" );

			$wpdb->delete( $this->files_table, array( 'store_id' => $store_id ) );

			$result = $wpdb->delete( $this->stores_table, array( 'id' => $store_id ) );

			if ( $result === false ) {
				throw new Exception( 'Failed to delete vector store from database' );
			}

			// If this was the default store, set a new default
			if ( $store['is_default'] ) {
				$new_default = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM %i ORDER BY created_at DESC LIMIT 1", $this->stores_table ) );
				if ( $new_default ) {
					$wpdb->update(
						$this->stores_table,
						array( 'is_default' => 1 ),
						array( 'id' => $new_default->id )
					);
				}
			}

			return true;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Vector store deletion error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Get files in a vector store
	 */
	public function get_store_files( $store_id ) {
		global $wpdb;

		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			)
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		try {
			$files = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE store_id = %s ORDER BY created_at DESC",
					$this->files_table,
					$store_id
				),
				ARRAY_A
			);

			return $files ?: array();
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Get vector store files error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Upload a file to a vector store
	 */
	public function upload_file( $store_id, $file ) {
		global $wpdb;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			)
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		$allowed_types = array(
			'application/pdf',
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'text/plain',
			'text/markdown',
			'text/csv',
			'application/json',
		);

		if ( ! in_array( $file['type'], $allowed_types ) ) {
			throw new Exception( 'Unsupported file type. Allowed types: PDF, Word, Text, Markdown, CSV, JSON' );
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

			if ( isset( $upload['error'] ) ) {
				throw new Exception( $upload['error'] );
			}

			$filename   = basename( $upload['file'] );
			$attachment = array(
				'post_mime_type' => $upload['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => $upload['url'],
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

			if ( is_wp_error( $attachment_id ) ) {
				throw new Exception( $attachment_id->get_error_message() );
			}

			wp_update_attachment_metadata(
				$attachment_id,
				wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
			);

			$file_contents = file_get_contents( $upload['file'] );
			$purpose       = 'assistants';

			$file_upload_response = $this->call_openai_api(
				'POST',
				'files',
				array(
					'purpose' => 'assistants',
					'file'    => new \CURLFile( $upload['file'] ),
				),
				true
			);

			if ( empty( $file_upload_response['id'] ) ) {
				throw new Exception( 'Failed to upload file to OpenAI' );
			}

			$file_id = $file_upload_response['id'];

			$response = $this->call_openai_api(
				'POST',
				"vector_stores/{$store_id}/files",
				array(
					'file_id' => $file_id,
				)
			);

			if ( empty( $response['id'] ) ) {
				throw new Exception( 'Failed to add file to vector store' );
			}

			$result = $wpdb->insert(
				$this->files_table,
				array(
					'id'         => $response['id'],
					'store_id'   => $store_id,
					'filename'   => $filename,
					'mime_type'  => $upload['type'],
					'size'       => $file['size'],
					'status'     => 'active',
					'url'        => $upload['url'],
					'local_path' => $upload['file'],
				)
			);

			if ( $result === false ) {
				throw new Exception( 'Failed to save file to database' );
			}

			$response['filename']  = $filename;
			$response['mime_type'] = $upload['type'];
			$response['size']      = $file['size'];
			$response['url']       = $upload['url'];
			$response['status']    = 'active';

			return $response;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Vector store file upload error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Delete a file from a vector store
	 */
	public function delete_file( $file_id ) {
		global $wpdb;

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		$file = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->files_table,
				$file_id
			)
		);

		if ( ! $file ) {
			throw new Exception( 'File not found' );
		}

		try {
			$this->call_openai_api( 'DELETE', "vector_stores/{$file->store_id}/files/{$file_id}" );

			if ( ! empty( $file->local_path ) && file_exists( $file->local_path ) ) {
				unlink( $file->local_path );
			}

			$result = $wpdb->delete( $this->files_table, array( 'id' => $file_id ) );

			if ( $result === false ) {
				throw new Exception( 'Failed to delete file from database' );
			}

			return true;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Vector store file deletion error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Set a vector store as default
	 */
	public function set_default_store( $store_id ) {
		global $wpdb;

		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			)
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		try {
			$wpdb->update(
				$this->stores_table,
				array( 'is_default' => 0 ),
				array( 'is_default' => 1 )
			);

			$result = $wpdb->update(
				$this->stores_table,
				array( 'is_default' => 1 ),
				array( 'id' => $store_id )
			);

			if ( $result === false ) {
				throw new Exception( 'Failed to set default vector store' );
			}

			return true;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Set default vector store error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Get the default vector store
	 */
	public function get_default_store() {
		global $wpdb;

		try {
			$store = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM %i WHERE is_default = 1", $this->stores_table ),
				ARRAY_A
			);

			if ( ! $store ) {
				$store = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC LIMIT 1", $this->stores_table ),
					ARRAY_A
				);
			}

			return $store;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Get default vector store error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Call OpenAI API
	 */
	private function call_openai_api( $method, $endpoint, $data = null, $is_multipart = false, $custom_headers = array() ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		$url     = "https://api.openai.com/v1/{$endpoint}";
		$headers = array(
			'Authorization' => 'Bearer ' . $this->openai_api_key,
		);

		foreach ( $custom_headers as $key => $value ) {
			$headers[ $key ] = $value;
		}

		if ( ! $is_multipart ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 60, // Increased timeout for file uploads
		);

		if ( $data !== null ) {
			if ( $is_multipart ) {
				// Handle multipart/form-data for file uploads. Set the boundary
				// Content-Type on the request args that are actually sent.
				$boundary                        = wp_generate_password( 24, false );
				$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

				$payload = '';
				foreach ( $data as $key => $value ) {
					if ( $key === 'file' && $value instanceof \CURLFile ) {
						$file_path     = $value->getFilename();
						$file_contents = file_get_contents( $file_path );
						$file_name     = basename( $file_path );

						$payload .= "--$boundary\r\n";
						$payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"$file_name\"\r\n";
						$payload .= "Content-Type: application/octet-stream\r\n\r\n";
						$payload .= $file_contents . "\r\n";
					} else {
						$payload .= "--$boundary\r\n";
						$payload .= "Content-Disposition: form-data; name=\"$key\"\r\n\r\n";
						$payload .= $value . "\r\n";
					}
				}
				$payload .= "--$boundary--\r\n";

				$args['body'] = $payload;
			} elseif ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ) ) ) {
				$args['body'] = json_encode( $data );
			}
		}

		// This request is NOT streaming, so it goes through the WordPress HTTP
		// API rather than raw cURL. Multipart file uploads are supported by
		// building the multipart body manually above (with an explicit boundary
		// header); wp_remote_request forwards that body verbatim. This keeps TLS
		// verification, timeouts, and proxy handling under WordPress's control.
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'OpenAI API request failed: ' . esc_html( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			throw new Exception( 'OpenAI API error: ' . esc_html( $error_message ) );
		}

		return $body;
	}

	public function add_wp_content( $store_id, $post_ids, $post_type = 'post' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		global $wpdb;
		$store = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$this->stores_table,
				$store_id
			)
		);

		if ( ! $store ) {
			throw new Exception( 'Vector store not found' );
		}

		if ( ! post_type_exists( $post_type ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Invalid post type',
				'error',
				array(
					'post_type' => $post_type,
				)
			);
			throw new Exception( 'Invalid post type: ' . esc_html( $post_type ) );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Adding WordPress content to vector store',
			'debug',
			array(
				'post_type' => $post_type,
				'post_ids'  => $post_ids,
				'store_id'  => $store_id,
			)
		);

		$added_count = 0;
		$errors      = array();

		foreach ( $post_ids as $post_id ) {
			try {
				$post = get_post( $post_id );

				if ( ! $post ) {
					$errors[] = "Post ID {$post_id} not found";
					continue;
				}

				WP_AI_Workflows_Utilities::debug_log(
					'Processing post for vector store',
					'debug',
					array(
						'post_id'    => $post_id,
						'post_title' => $post->post_title,
						'post_type'  => $post->post_type,
					)
				);

				$post_title = $post->post_title;

				$post_content = $post->post_content;
				$post_content = apply_filters( 'the_content', $post_content );
				$post_content = strip_tags( $post_content );

				$post_url = get_permalink( $post_id );

				$author_id   = $post->post_author;
				$author_name = get_the_author_meta( 'display_name', $author_id );

				$categories = array();
				if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
					$post_categories = get_the_category( $post_id );
					if ( ! empty( $post_categories ) ) {
						foreach ( $post_categories as $category ) {
							$categories[] = $category->name;
						}
					}
				}

				$tags = array();
				if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
					$post_tags = get_the_tags( $post_id );
					if ( ! empty( $post_tags ) ) {
						foreach ( $post_tags as $tag ) {
							$tags[] = $tag->name;
						}
					}
				}

				$excerpt = $post->post_excerpt;
				if ( empty( $excerpt ) ) {
					$excerpt = wp_trim_words( $post_content, 55, '...' );
				}

				$content  = '# ' . $post_title . "\n\n";
				$content .= '**URL:** ' . $post_url . "\n";
				$content .= '**Date:** ' . get_the_date( 'F j, Y', $post ) . "\n";
				$content .= '**Author:** ' . $author_name . "\n";
				$content .= '**Post Type:** ' . $post->post_type . "\n";

				if ( ! empty( $categories ) ) {
					$content .= '**Categories:** ' . implode( ', ', $categories ) . "\n";
				}

				if ( ! empty( $tags ) ) {
					$content .= '**Tags:** ' . implode( ', ', $tags ) . "\n";
				}

				if ( $post->post_type === 'product' ) {
					if ( function_exists( 'wc_get_product' ) ) {
						$product = wc_get_product( $post_id );
						if ( $product ) {
							$content .= '**Price:** ' . $product->get_price_html() . "\n";
							$content .= '**SKU:** ' . $product->get_sku() . "\n";

							if ( $product->managing_stock() ) {
								$content .= '**Stock:** ' . $product->get_stock_quantity() . "\n";
							}
						}
					}
				}

				$content .= "\n**Excerpt:**\n" . $excerpt . "\n\n";
				$content .= "**Full Content:**\n" . $post_content;

				$upload_dir = wp_upload_dir();
				$tmp_dir    = $upload_dir['basedir'] . '/wp-ai-workflows-tmp';

				if ( ! file_exists( $tmp_dir ) ) {
					wp_mkdir_p( $tmp_dir );
				}

				$filename  = sanitize_title( $post_title ) . '-' . uniqid() . '.txt';
				$temp_file = $tmp_dir . '/' . $filename;

				file_put_contents( $temp_file, $content );

				$filesize = filesize( $temp_file );

				$file = array(
					'name'     => $filename,
					'type'     => 'text/plain',
					'tmp_name' => $temp_file,
					'error'    => 0,
					'size'     => $filesize,
				);

				$file_upload_response = $this->call_openai_api(
					'POST',
					'files',
					array(
						'purpose' => 'assistants',
						'file'    => new \CURLFile( $temp_file ),
					),
					true
				);

				if ( empty( $file_upload_response['id'] ) ) {
					throw new Exception( 'Failed to upload file to OpenAI' );
				}

				$file_id = $file_upload_response['id'];

				$response = $this->call_openai_api(
					'POST',
					"vector_stores/{$store_id}/files",
					array(
						'file_id' => $file_id,
					)
				);

				if ( empty( $response['id'] ) ) {
					throw new Exception( 'Failed to add file to vector store' );
				}

				$result = $wpdb->insert(
					$this->files_table,
					array(
						'id'         => $response['id'],
						'store_id'   => $store_id,
						'filename'   => $filename,
						'mime_type'  => 'text/plain',
						'size'       => $filesize,
						'status'     => 'active',
						'url'        => get_permalink( $post_id ),
						'local_path' => $temp_file,
					)
				);

				@unlink( $temp_file );

				if ( $result === false ) {
					throw new Exception( 'Failed to save file metadata to database' );
				}

				++$added_count;

				WP_AI_Workflows_Utilities::debug_log(
					'Successfully added post to vector store',
					'info',
					array(
						'post_id'        => $post_id,
						'file_id'        => $file_id,
						'vector_file_id' => $response['id'],
					)
				);

			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error adding post to vector store',
					'error',
					array(
						'post_id' => $post_id,
						'error'   => $e->getMessage(),
					)
				);

				$errors[] = "Error adding post ID {$post_id}: " . $e->getMessage();
			}
		}

		return array(
			'success'     => true,
			'added_count' => $added_count,
			'total_posts' => count( $post_ids ),
			'errors'      => $errors,
		);
	}

	public function fetch_openai_vector_stores() {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		try {
			$response = $this->call_openai_api(
				'GET',
				'vector_stores',
				null,
				false,
				array(
					'OpenAI-Beta' => 'assistants=v2',
				)
			);

			if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
				throw new Exception( 'Invalid response from OpenAI API' );
			}

			return $response;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Fetch OpenAI vector stores error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}

	/**
	 * Fetch files for a vector store directly from OpenAI
	 */
	public function fetch_openai_vector_store_files( $store_id ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenAI API key is required' );
		}

		try {
			$response = $this->call_openai_api(
				'GET',
				"vector_stores/{$store_id}/files",
				null,
				false,
				array(
					'OpenAI-Beta' => 'assistants=v2',
				)
			);

			if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
				throw new Exception( 'Invalid response from OpenAI API' );
			}

			return $response;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Fetch OpenAI vector store files error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}
}
