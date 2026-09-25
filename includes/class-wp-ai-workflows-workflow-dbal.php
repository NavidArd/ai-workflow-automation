<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data Access Layer for Workflow operations
 */
class WP_AI_Workflows_Workflow_DBAL {
	/**
	 * Get all workflows with optional pagination and search
	 */
	public static function get_all_workflows( $page = 1, $per_page = 200, $search = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$where        = '';
			$where_params = array();
			if ( ! empty( $search ) ) {
				$where          = 'WHERE name LIKE %s';
				$where_params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}

			if ( ! empty( $where_params ) ) {
				$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE name LIKE %s", $table_name, $where_params[0] ) );
			} else {
				$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_name ) );
			}

			if ( $total > 0 ) {
				$limit = '';
				if ( $per_page > 0 ) {
					$offset = ( $page - 1 ) * $per_page;
					$limit  = $wpdb->prepare( "LIMIT %d OFFSET %d", $per_page, $offset );
				}

				if ( $per_page > 0 ) {
					if ( ! empty( $where_params ) ) {
						$results = $wpdb->get_results( $wpdb->prepare( "SELECT data FROM %i WHERE name LIKE %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $where_params[0], $per_page, $offset ), ARRAY_A );
					} else {
						$results = $wpdb->get_results( $wpdb->prepare( "SELECT data FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $per_page, $offset ), ARRAY_A );
					}
				} else {
					if ( ! empty( $where_params ) ) {
						$results = $wpdb->get_results( $wpdb->prepare( "SELECT data FROM %i WHERE name LIKE %s ORDER BY updated_at DESC", $table_name, $where_params[0] ), ARRAY_A );
					} else {
						$results = $wpdb->get_results( $wpdb->prepare( "SELECT data FROM %i ORDER BY updated_at DESC", $table_name ), ARRAY_A );
					}
				}

				if ( ! empty( $results ) ) {
					$workflows = array();
					foreach ( $results as $row ) {
						$workflows[] = json_decode( $row['data'], true );
					}

					return $workflows;
				}
			} else {
				return array();
			}
		}
	}


	/**
	 * Get a single workflow by ID
	 */
	public static function get_workflow_by_id( $workflow_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id = %s",
					$table_name,
					$workflow_id
				),
				ARRAY_A
			);

			if ( $row ) {
				$workflow_data = json_decode( $row['data'], true );

				if ( json_last_error() === JSON_ERROR_NONE && is_array( $workflow_data ) ) {
					// Validate and clean nodes
					if ( isset( $workflow_data['nodes'] ) && is_array( $workflow_data['nodes'] ) ) {
						$valid_nodes = array();
						foreach ( $workflow_data['nodes'] as $node ) {
							if ( ! isset( $node['type'] ) || $node['type'] === '' || ! is_string( $node['type'] ) ) {
								WP_AI_Workflows_Utilities::debug_log(
									'Removing invalid node',
									'warning',
									array(
										'workflow_id' => $workflow_id,
										'node_id'     => $node['id'] ?? 'unknown',
										'node_type'   => $node['type'] ?? 'empty',
									)
								);
								continue;
							}
							$valid_nodes[] = $node;
						}
						$workflow_data['nodes'] = $valid_nodes;

						// Also clean up edges that reference removed nodes
						if ( isset( $workflow_data['edges'] ) && is_array( $workflow_data['edges'] ) ) {
							$valid_node_ids         = array_column( $valid_nodes, 'id' );
							$workflow_data['edges'] = array_filter(
								$workflow_data['edges'],
								function ( $edge ) use ( $valid_node_ids ) {
									return in_array( $edge['source'], $valid_node_ids ) && in_array( $edge['target'], $valid_node_ids );
								}
							);
							$workflow_data['edges'] = array_values( $workflow_data['edges'] ); // Re-index
						}
					}

					// Ensure tags are properly formatted
					if ( isset( $workflow_data['tags'] ) ) {
						if ( ! is_array( $workflow_data['tags'] ) ) {
							$workflow_data['tags'] = array();
						} else {
							$validated_tags = array();
							foreach ( $workflow_data['tags'] as $tag ) {
								if ( is_array( $tag ) && isset( $tag['name'] ) ) {
									$validated_tags[] = array(
										'id'    => isset( $tag['id'] ) ? $tag['id'] : uniqid( 'tag_' ),
										'name'  => $tag['name'],
										'color' => isset( $tag['color'] ) ? $tag['color'] : self::get_random_tag_color( $tag['name'] ),
									);
								}
							}
							$workflow_data['tags'] = $validated_tags;
						}
					} else {
						$workflow_data['tags'] = array();
					}

					return $workflow_data;
				}
			}
		}

		return null;
	}

	/**
	 * Create a new workflow
	 */
	public static function create_workflow( $workflow ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		$wpdb->query( 'START TRANSACTION' );

		try {

			$db_inserted = false;
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
				$db_inserted = $wpdb->insert(
					$table_name,
					array(
						'id'         => $workflow['id'],
						'name'       => $workflow['name'],
						'status'     => $workflow['status'],
						'data'       => wp_json_encode( $workflow ),
						'created_by' => $workflow['createdBy'],
						'created_at' => $workflow['createdAt'],
						'updated_at' => isset( $workflow['updatedAt'] ) ? $workflow['updatedAt'] : $workflow['createdAt'],
					)
				);
			}

			if ( $db_inserted !== false ) {
				$wpdb->query( 'COMMIT' );
				return $workflow;
			} else {
				$wpdb->query( 'ROLLBACK' );
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to create workflow',
					'error',
					array(
						'workflow_id' => $workflow['id'],
						'db_inserted' => $db_inserted,
					)
				);
				return false;
			}
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			WP_AI_Workflows_Utilities::debug_log(
				'Exception creating workflow',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Update an existing workflow
	 */
	public static function update_workflow( $workflow_id, $updated_workflow ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		WP_AI_Workflows_Utilities::debug_log(
			'Updating workflow',
			'debug',
			array(
				'workflow_id' => $workflow_id,
				'has_tags'    => isset( $updated_workflow['tags'] ),
				'tags_type'   => isset( $updated_workflow['tags'] ) ? gettype( $updated_workflow['tags'] ) : 'not set',
			)
		);

		try {
			$wpdb->query( 'START TRANSACTION' );

			// Ensure tags are properly formatted as an array
			if ( isset( $updated_workflow['tags'] ) ) {
				if ( is_string( $updated_workflow['tags'] ) ) {
					$decoded_tags = json_decode( $updated_workflow['tags'], true );
					if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_tags ) ) {
						$updated_workflow['tags'] = $decoded_tags;
					} else {
						$updated_workflow['tags'] = array();
					}
				} elseif ( ! is_array( $updated_workflow['tags'] ) ) {
					$updated_workflow['tags'] = array();
				}

				$validated_tags = array();
				foreach ( $updated_workflow['tags'] as $tag ) {
					if ( is_array( $tag ) && isset( $tag['name'] ) ) {
						$validated_tags[] = array(
							'id'    => isset( $tag['id'] ) ? $tag['id'] : uniqid( 'tag_' ),
							'name'  => $tag['name'],
							'color' => isset( $tag['color'] ) ? $tag['color'] : self::get_random_tag_color( $tag['name'] ),
						);
					}
				}
				$updated_workflow['tags'] = $validated_tags;
			} else {
				$updated_workflow['tags'] = array();
			}

			$db_updated = false;
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
				$workflow_data_json = wp_json_encode( $updated_workflow );

				if ( $workflow_data_json === false ) {
					throw new Exception( 'Failed to encode workflow data as JSON' );
				}

				$db_updated = $wpdb->update(
					$table_name,
					array(
						'name'       => $updated_workflow['name'],
						'status'     => $updated_workflow['status'],
						'data'       => $workflow_data_json,
						'updated_at' => isset( $updated_workflow['updatedAt'] ) ? $updated_workflow['updatedAt'] : current_time( 'mysql' ),
					),
					array( 'id' => $workflow_id )
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Database update result',
					'debug',
					array(
						'workflow_id' => $workflow_id,
						'db_updated'  => $db_updated,
						'last_error'  => $wpdb->last_error,
					)
				);
			}

			if ( $db_updated !== false ) {
				$wpdb->query( 'COMMIT' );
				return $updated_workflow;
			} else {
				$wpdb->query( 'ROLLBACK' );
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to update workflow',
					'error',
					array(
						'workflow_id' => $workflow_id,
						'db_updated'  => $db_updated,
					)
				);
				return false;
			}
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			WP_AI_Workflows_Utilities::debug_log(
				'Exception updating workflow',
				'error',
				array(
					'error'       => $e->getMessage(),
					'workflow_id' => $workflow_id,
				)
			);
			return false;
		}
	}


	/**
	 * Delete a workflow
	 */
	public static function delete_workflow( $workflow_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		$wpdb->query( 'START TRANSACTION' );

		try {

			$db_deleted = false;
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
				$db_deleted = $wpdb->delete(
					$table_name,
					array( 'id' => $workflow_id )
				);
			}

			if ( $db_deleted !== false ) {
				$wpdb->query( 'COMMIT' );
				return true;
			} else {
				$wpdb->query( 'ROLLBACK' );
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to delete workflow',
					'error',
					array(
						'workflow_id' => $workflow_id,
						'db_deleted'  => $db_deleted,
					)
				);
				return false;
			}
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			WP_AI_Workflows_Utilities::debug_log(
				'Exception deleting workflow',
				'error',
				array(
					'error'       => $e->getMessage(),
					'workflow_id' => $workflow_id,
				)
			);
			return false;
		}
	}


	/**
	 * Update the status of a workflow
	 */
	public static function update_workflow_status( $workflow_id, $status ) {
		$workflow = self::get_workflow_by_id( $workflow_id );
		if ( ! $workflow ) {
			return false;
		}

		$workflow['status']    = $status;
		$workflow['updatedAt'] = current_time( 'mysql' );

		return self::update_workflow( $workflow_id, $workflow );
	}

	/**
	 * Search workflows by text and other filters
	 */
	public static function search_workflows( $search_text = '', $status = null, $tags = array(), $page = 1, $per_page = 200 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		WP_AI_Workflows_Utilities::debug_log(
			'Starting search_workflows',
			'debug',
			array(
				'search_text' => $search_text,
				'status'      => $status,
				'tags'        => $tags,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$where_clauses = array();
			$where_params  = array();

			if ( ! empty( $search_text ) ) {
				$where_clauses[] = '(name LIKE %s OR data LIKE %s)';
				$where_params[]  = '%' . $wpdb->esc_like( $search_text ) . '%';
				$where_params[]  = '%' . $wpdb->esc_like( $search_text ) . '%';
			}

			if ( $status !== null ) {
				$where_clauses[] = 'status = %s';
				$where_params[]  = $status;
			}

			$where = '';
			if ( ! empty( $where_clauses ) ) {
				$where = 'WHERE ' . implode( ' AND ', $where_clauses );
			}

			if ( ! empty( $where_params ) ) {
				if ( ! empty( $search_text ) && $status !== null ) {
					$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (name LIKE %s OR data LIKE %s) AND status = %s", $table_name, $where_params[0], $where_params[1], $where_params[2] ) );
				} elseif ( ! empty( $search_text ) ) {
					$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (name LIKE %s OR data LIKE %s)", $table_name, $where_params[0], $where_params[1] ) );
				} elseif ( $status !== null ) {
					$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = %s", $table_name, $where_params[0] ) );
				}
			} else {
				$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_name ) );
			}

			$offset = ( $page - 1 ) * $per_page;
			if ( ! empty( $where_params ) ) {
				if ( ! empty( $search_text ) && $status !== null ) {
					$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (name LIKE %s OR data LIKE %s) AND status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $where_params[0], $where_params[1], $where_params[2], $per_page, $offset ), ARRAY_A );
				} elseif ( ! empty( $search_text ) ) {
					$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (name LIKE %s OR data LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $where_params[0], $where_params[1], $per_page, $offset ), ARRAY_A );
				} elseif ( $status !== null ) {
					$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $where_params[0], $per_page, $offset ), ARRAY_A );
				}
			} else {
				$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table_name, $per_page, $offset ), ARRAY_A );
			}

			WP_AI_Workflows_Utilities::debug_log(
				'DB query executed successfully',
				'debug'
			);

			$workflows = array();
			if ( ! empty( $results ) ) {
				foreach ( $results as $row ) {
					try {
						$workflow_data = json_decode( $row['data'], true );

						if ( json_last_error() !== JSON_ERROR_NONE ) {
							WP_AI_Workflows_Utilities::debug_log(
								'JSON decode error',
								'error',
								array(
									'workflow_id' => $row['id'],
									'error'       => json_last_error_msg(),
									'data_sample' => substr( $row['data'], 0, 100 ) . '...',
								)
							);

							$repaired_workflow = self::auto_repair_workflow( $row['id'] );

							if ( $repaired_workflow ) {
								WP_AI_Workflows_Utilities::debug_log(
									'Workflow auto-repaired successfully',
									'info',
									array(
										'workflow_id' => $row['id'],
									)
								);
								$workflow_data = $repaired_workflow;
							} else {
								$workflow_data = array(
									'id'           => $row['id'],
									'name'         => $row['name'] . ' (⚠️ Auto-Repair Failed)',
									'status'       => $row['status'],
									'createdAt'    => $row['created_at'],
									'updatedAt'    => $row['updated_at'],
									'createdBy'    => $row['created_by'] ?? 'unknown',
									'nodes'        => array(),
									'edges'        => array(),
									'needs_repair' => true,
								);
							}
						} else {
							// Ensure the decoded data has the basic required properties
							if ( ! isset( $workflow_data['id'] ) ) {
								$workflow_data['id'] = $row['id'];
							}

							if ( ! isset( $workflow_data['name'] ) ) {
								$workflow_data['name'] = $row['name'] ?? 'Unnamed Workflow';
							}

							if ( ! isset( $workflow_data['status'] ) ) {
								$workflow_data['status'] = $row['status'] ?? 'inactive';
							}

							if ( ! isset( $workflow_data['createdAt'] ) ) {
								$workflow_data['createdAt'] = $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
							}

							if ( ! isset( $workflow_data['updatedAt'] ) ) {
								$workflow_data['updatedAt'] = $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' );
							}

							if ( ! isset( $workflow_data['nodes'] ) ) {
								$workflow_data['nodes'] = array();
							}

							if ( ! isset( $workflow_data['edges'] ) ) {
								$workflow_data['edges'] = array();
							}
						}

						if ( ! empty( $tags ) && ! isset( $workflow_data['needs_repair'] ) ) {
							$workflow_tags = array_column( $workflow_data['tags'] ?? array(), 'name' );
							if ( empty( array_intersect( $tags, $workflow_tags ) ) ) {
								continue; // Skip this workflow if it doesn't have any of the specified tags
							}
						}

						$workflows[] = $workflow_data;
					} catch ( Exception $e ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Error processing workflow data',
							'error',
							array(
								'workflow_id' => $row['id'],
								'error'       => $e->getMessage(),
							)
						);

						// Still include a basic workflow structure for the UI
						$workflows[] = array(
							'id'           => $row['id'],
							'name'         => $row['name'] . ' (⚠️ Error: ' . substr( $e->getMessage(), 0, 30 ) . ')',
							'status'       => $row['status'] ?? 'inactive',
							'createdAt'    => $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
							'updatedAt'    => $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ),
							'createdBy'    => $row['created_by'] ?? 'unknown',
							'nodes'        => array(),
							'edges'        => array(),
							'needs_repair' => true,
						);
					}
				}
			}

			return array(
				'workflows'   => $workflows,
				'total'       => (int) $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			);
		}

		return array(
			'workflows'   => $workflows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		);
	}

	/**
	 * Add or update a tag for a workflow
	 */
	public static function add_workflow_tag( $workflow_id, $tag_name, $tag_color = null ) {
		$workflow = self::get_workflow_by_id( $workflow_id );
		if ( ! $workflow ) {
			return false;
		}

		if ( ! isset( $workflow['tags'] ) || ! is_array( $workflow['tags'] ) ) {
			$workflow['tags'] = array();
		}

		foreach ( $workflow['tags'] as $key => $tag ) {
			if ( $tag['name'] === $tag_name ) {
				if ( $tag_color !== null ) {
					$workflow['tags'][ $key ]['color'] = $tag_color;
				}
				return self::update_workflow( $workflow_id, $workflow );
			}
		}

		$workflow['tags'][] = array(
			'id'    => uniqid( 'tag_' ),
			'name'  => $tag_name,
			'color' => $tag_color ?: self::get_random_tag_color( $tag_name ),
		);

		return self::update_workflow( $workflow_id, $workflow );
	}

	/**
	 * Generate a random color for a tag
	 */
	private static function get_random_tag_color( $tag_name ) {
		$colors = array(
			'magenta',
			'red',
			'volcano',
			'orange',
			'gold',
			'lime',
			'green',
			'cyan',
			'blue',
			'geekblue',
			'purple',
		);

		// Use the tag name to generate a consistent color
		$hash = 0;
		$str  = $tag_name;
		for ( $i = 0; $i < strlen( $str ); $i++ ) {
			$hash = ord( $str[ $i ] ) + ( ( $hash << 5 ) - $hash );
		}

		return $colors[ abs( $hash ) % count( $colors ) ];
	}

	/**
	 * Get all unique tags across all workflows
	 */
	public static function get_all_tags() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
		$tags       = array();

		// First try to get from the database table
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$workflows = $wpdb->get_col( $wpdb->prepare( "SELECT data FROM %i", $table_name ) );

			if ( ! empty( $workflows ) ) {
				foreach ( $workflows as $workflow_data ) {
					$workflow = json_decode( $workflow_data, true );
					if ( isset( $workflow['tags'] ) && is_array( $workflow['tags'] ) ) {
						foreach ( $workflow['tags'] as $tag ) {
							$tags[ $tag['name'] ] = $tag;
						}
					}
				}
			}
		}

		// Also check options table for any tags not in the DB
		$option_workflows = get_option( 'wp_ai_workflows', array() );
		foreach ( $option_workflows as $workflow ) {
			if ( isset( $workflow['tags'] ) && is_array( $workflow['tags'] ) ) {
				foreach ( $workflow['tags'] as $tag ) {
					$tags[ $tag['name'] ] = $tag;
				}
			}
		}

		return array_values( $tags );
	}

	/**
	 * Get workflows with specific status or matching criteria
	 */
	public static function get_workflows_by_status( $status, $limit = 200 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT data FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d",
					$table_name,
					$status,
					$limit
				),
				ARRAY_A
			);

			if ( ! empty( $results ) ) {
				$workflows = array();
				foreach ( $results as $row ) {
					$workflows[] = json_decode( $row['data'], true );
				}
				return $workflows;
			}
		}
		return array();
	}

	/**
	 * Count workflows by status
	 */
	public static function count_workflows_by_status() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';
		$counts     = array(
			'total'     => 0,
			'active'    => 0,
			'inactive'  => 0,
			'scheduled' => 0,
		);

		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) == $table_name ) {
			$results = $wpdb->get_results(
				$wpdb->prepare( "SELECT status, COUNT(*) as count FROM %i GROUP BY status", $table_name )
			);

			if ( ! empty( $results ) ) {
				foreach ( $results as $row ) {
					if ( isset( $counts[ $row->status ] ) ) {
						$counts[ $row->status ] = (int) $row->count;
					}
					$counts['total'] += (int) $row->count;
				}

				// Add scheduled workflows (those with enabled schedule)
				$scheduled           = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE data LIKE %s AND data LIKE %s",
						$table_name,
						'%"enabled":true%',
						'%"schedule":%'
					)
				);
				$counts['scheduled'] = (int) $scheduled;

				return $counts;
			}
		}

		return $counts;
	}

	/**
	 * Auto-repair a workflow if JSON data is corrupted
	 *
	 * @param string $workflow_id The ID of the workflow to repair
	 * @return array|null The repaired workflow or null if repair failed
	 */
	public static function auto_repair_workflow( $workflow_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_workflow_data';

		WP_AI_Workflows_Utilities::debug_log(
			'Auto-repairing workflow',
			'info',
			array(
				'workflow_id' => $workflow_id,
			)
		);

		// Step 1: Try to recover from options table
		$options_workflows = get_option( 'wp_ai_workflows', array() );
		$repaired          = false;

		if ( is_array( $options_workflows ) ) {
			foreach ( $options_workflows as $workflow ) {
				if ( is_array( $workflow ) && isset( $workflow['id'] ) && $workflow['id'] === $workflow_id ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Found workflow in options table',
						'info',
						array(
							'workflow_id' => $workflow_id,
							'name'        => $workflow['name'] ?? 'Unknown',
						)
					);

					$result = $wpdb->update(
						$table_name,
						array(
							'data'       => wp_json_encode( $workflow ),
							'updated_at' => current_time( 'mysql' ),
						),
						array( 'id' => $workflow_id )
					);

					if ( $result !== false ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Successfully repaired workflow from options',
							'info',
							array(
								'workflow_id' => $workflow_id,
							)
						);

						return $workflow;
					}

					break;
				}
			}
		}

		// Step 2: If not found in options, try to recover from execution history
		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %s",
				$table_name,
				$workflow_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Workflow not found in database',
				'error',
				array(
					'workflow_id' => $workflow_id,
				)
			);
			return null;
		}

		$executions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE workflow_id = %s AND status = %s ORDER BY created_at DESC LIMIT %d",
				$executions_table,
				$workflow_id,
				'completed',
				5
			),
			ARRAY_A
		);

		if ( ! empty( $executions ) ) {
			$latest_execution = $executions[0];
			$output_data      = json_decode( $latest_execution['output_data'], true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $output_data ) ) {
				// We have valid execution data, try to reconstruct the workflow
				$reconstructed_workflow = array(
					'id'        => $workflow_id,
					'name'      => $row['name'],
					'status'    => $row['status'],
					'createdAt' => $row['created_at'],
					'updatedAt' => $row['updated_at'],
					'createdBy' => $row['created_by'],
					'nodes'     => array(),
					'edges'     => array(),
					'viewport'  => array(
						'x'    => 0,
						'y'    => 0,
						'zoom' => 1,
					),
				);

				// Extract node IDs and types from execution data
				$i = 0;
				foreach ( $output_data as $node_id => $node_output ) {
					if ( ! is_array( $node_output ) ) {
						continue;
					}

					$node_type = $node_output['type'] ?? 'unknown';

					$reconstructed_workflow['nodes'][] = array(
						'id'       => $node_id,
						'type'     => $node_type,
						'position' => array(
							'x' => 250 + $i * 150,
							'y' => 100 + ( $i % 3 ) * 150,
						),
						'data'     => array(
							'label'         => ucfirst( $node_type ) . ' Node',
							'reconstructed' => true,
						),
					);
					++$i;
				}

				// Create edges between nodes in the order they appear
				$node_ids = array_keys( $output_data );
				for ( $j = 0; $j < count( $node_ids ) - 1; $j++ ) {
					$reconstructed_workflow['edges'][] = array(
						'id'     => 'edge-' . $j,
						'source' => $node_ids[ $j ],
						'target' => $node_ids[ $j + 1 ],
					);
				}

				$result = $wpdb->update(
					$table_name,
					array(
						'data'       => wp_json_encode( $reconstructed_workflow ),
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $workflow_id )
				);

				if ( $result !== false ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Successfully reconstructed workflow from execution data',
						'info',
						array(
							'workflow_id' => $workflow_id,
							'node_count'  => count( $reconstructed_workflow['nodes'] ),
							'edge_count'  => count( $reconstructed_workflow['edges'] ),
						)
					);

					return $reconstructed_workflow;
				}
			}
		}

		// Step 3: Create a minimal valid workflow as a last resort
		$minimal_workflow = array(
			'id'        => $workflow_id,
			'name'      => $row['name'],
			'status'    => $row['status'],
			'createdAt' => $row['created_at'],
			'updatedAt' => $row['updated_at'],
			'createdBy' => $row['created_by'] ?? 'unknown',
			'nodes'     => array(),
			'edges'     => array(),
			'viewport'  => array(
				'x'    => 0,
				'y'    => 0,
				'zoom' => 1,
			),
			'recovered' => 'minimal',
		);

		$result = $wpdb->update(
			$table_name,
			array(
				'data'       => wp_json_encode( $minimal_workflow ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $workflow_id )
		);

		if ( $result !== false ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Created minimal valid workflow structure',
				'info',
				array(
					'workflow_id' => $workflow_id,
				)
			);

			return $minimal_workflow;
		}

		return null;
	}
}
