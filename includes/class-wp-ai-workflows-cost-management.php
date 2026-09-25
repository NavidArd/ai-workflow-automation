<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Cost_Management {
	private static $instance = null;
	private $cost_settings_table;
	private $node_costs_table;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->cost_settings_table = $wpdb->prefix . 'wp_ai_workflows_cost_settings';
		$this->node_costs_table    = $wpdb->prefix . 'wp_ai_workflows_node_costs';

		add_action( 'wp_ai_workflows_daily_maintenance', array( $this, 'sync_openrouter_costs' ) );
		add_action( 'wp_ajax_wp_ai_workflows_sync_costs', array( $this, 'handle_manual_cost_sync' ) );
	}

	/**
	 * Initialize default cost settings
	 */
	public function initialize_cost_settings() {
		global $wpdb;

		$default_costs = array(
			// OpenAI Direct Models (bare ids -> native OpenAI). Per-million-token
			// USD prices grounded against the live OpenRouter catalog (July 2026).
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-5.5',
				'input_cost'  => 5.0,
				'output_cost' => 30.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-5.1',
				'input_cost'  => 1.25,
				'output_cost' => 10.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-5',
				'input_cost'  => 1.25,
				'output_cost' => 10.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-5-mini',
				'input_cost'  => 0.25,
				'output_cost' => 2.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-5-nano',
				'input_cost'  => 0.05,
				'output_cost' => 0.4,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-4.1',
				'input_cost'  => 2.0,
				'output_cost' => 8.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-4.1-mini',
				'input_cost'  => 0.4,
				'output_cost' => 1.6,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'o3',
				'input_cost'  => 2.0,
				'output_cost' => 8.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'o4-mini',
				'input_cost'  => 1.1,
				'output_cost' => 4.4,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-4o',
				'input_cost'  => 2.5,
				'output_cost' => 10.0,
			),
			array(
				'provider'    => 'openai',
				'model'       => 'gpt-4o-mini',
				'input_cost'  => 0.15,
				'output_cost' => 0.6,
			),

			// Perplexity Direct Models
			array(
				'provider'    => 'perplexity',
				'model'       => 'Sonar',
				'input_cost'  => 1.0,
				'output_cost' => 1.0,
			),
			array(
				'provider'    => 'perplexity',
				'model'       => 'Sonar-pro',
				'input_cost'  => 3.0,
				'output_cost' => 15.0,
			),
			array(
				'provider'    => 'perplexity',
				'model'       => 'Sonar-reasoning',
				'input_cost'  => 1.0,
				'output_cost' => 5.0,
			),
			array(
				'provider'    => 'perplexity',
				'model'       => 'Sonar-reasoning-pro',
				'input_cost'  => 2.0,
				'output_cost' => 8.0,
			),
			array(
				'provider'    => 'perplexity',
				'model'       => 'Sonar-deep-research',
				'input_cost'  => 2.0,
				'output_cost' => 8.0,
			),
		);

		$this->initialize_multimedia_cost_settings();

		foreach ( $default_costs as $cost ) {
			$wpdb->replace(
				$this->cost_settings_table,
				array(
					'provider'    => $cost['provider'],
					'model'       => $cost['model'],
					'input_cost'  => $cost['input_cost'],
					'output_cost' => $cost['output_cost'],
				),
				array( '%s', '%s', '%f', '%f' )
			);
		}

		// After initializing default costs, sync with OpenRouter for the latest models
		$this->sync_openrouter_costs();

		WP_AI_Workflows_Utilities::debug_log(
			'Cost settings initialized',
			'info',
			array(
				'total_models' => count( $default_costs ),
			)
		);
	}

	public function get_costs_data() {
		$costs = get_option( 'wp_ai_workflows_costs', array() );

		// Initialize with default structure if empty
		if ( empty( $costs ) ) {
			$costs = array(
				'total'      => 0,
				'multimedia' => array(
					'total'     => 0,
					'providers' => array(),
					'models'    => array(),
					'usage'     => array(
						'images' => 0,
						'videos' => 0,
					),
				),
			);
		}

		return $costs;
	}

	/**
	 * Track multimedia generation costs
	 *
	 * @param string $provider Provider name (e.g. 'fal_ai')
	 * @param string $model Model used for generation
	 * @param float $cost Estimated cost of the generation
	 * @param int $quantity Number of items generated
	 * @param string $type Type of media generated (image, video)
	 * @return bool Whether the cost was successfully tracked
	 */
	public function track_multimedia_cost_simple( $provider, $model, $cost, $execution_id, $node_id = 'multimedia', $quantity = 1, $type = 'image' ) {
		global $wpdb;

		$wpdb->insert(
			$this->node_costs_table,
			array(
				'execution_id'      => $execution_id,
				'node_id'           => $node_id,
				'model'             => $model,
				'provider'          => $provider,
				'prompt_tokens'     => 1, // Not applicable for multimedia
				'completion_tokens' => 1, // Not applicable for multimedia
				'cost'              => $cost,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%f' )
		);

		if ( $execution_id > 0 ) {
			$this->update_execution_total_cost( $execution_id );
		}

		$monthly_totals = get_option( 'wp_ai_workflows_multimedia_costs', array() );
		$month_key      = gmdate( 'Y-m' );

		if ( ! isset( $monthly_totals[ $month_key ] ) ) {
			$monthly_totals[ $month_key ] = array(
				'total'  => 0,
				'images' => 0,
				'videos' => 0,
				'models' => array(),
			);
		}

		$monthly_totals[ $month_key ]['total'] += $cost;
		if ( $type === 'image' ) {
			$monthly_totals[ $month_key ]['images'] += $quantity;
		} elseif ( $type === 'video' ) {
			$monthly_totals[ $month_key ]['videos'] += $quantity;
		}

		if ( ! isset( $monthly_totals[ $month_key ]['models'][ $model ] ) ) {
			$monthly_totals[ $month_key ]['models'][ $model ] = array(
				'cost'  => 0,
				'count' => 0,
			);
		}
		$monthly_totals[ $month_key ]['models'][ $model ]['cost']  += $cost;
		$monthly_totals[ $month_key ]['models'][ $model ]['count'] += $quantity;

		return update_option( 'wp_ai_workflows_multimedia_costs', $monthly_totals );
	}

	/**
	 * Initialize cost settings for multimedia generation
	 */
	public function initialize_multimedia_cost_settings() {
		$settings = $this->get_cost_settings();

		if ( ! isset( $settings['multimedia'] ) ) {
			$settings['multimedia'] = array(
				'budget' => 0,
				'alerts' => array(
					'enabled'   => false,
					'threshold' => 80, // percentage of budget
				),
				'limits' => array(
					'enabled' => false,
					'action'  => 'warn', // 'warn' or 'block'
				),
			);

			update_option( 'wp_ai_workflows_cost_settings', $settings );
		}
	}

	/**
	 * Sync costs with OpenRouter API
	 *
	 * @return array Results of the sync operation
	 */
	public function sync_openrouter_costs() {
		global $wpdb;

		$result = array(
			'success'        => false,
			'models_added'   => 0,
			'models_updated' => 0,
			'errors'         => array(),
		);

		try {
			$response = wp_remote_get(
				'https://openrouter.ai/api/v1/models',
				array(
					'timeout' => 15,
					'headers' => array_merge(
						array( 'Content-Type' => 'application/json' ),
						WP_AI_Workflows_Utilities::openrouter_headers()
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$result['errors'][] = 'API Error: ' . $response->get_error_message();
				WP_AI_Workflows_Utilities::debug_log(
					'Error fetching OpenRouter models for cost sync',
					'error',
					array(
						'error' => $response->get_error_message(),
					)
				);
				return $result;
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			if ( $status_code !== 200 ) {
				$result['errors'][] = 'API returned status: ' . $status_code;
				WP_AI_Workflows_Utilities::debug_log(
					'OpenRouter API returned non-200 status for cost sync',
					'error',
					array(
						'status' => $status_code,
					)
				);
				return $result;
			}

			$body        = wp_remote_retrieve_body( $response );
			$models_data = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$result['errors'][] = 'Failed to parse response: ' . json_last_error_msg();
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to parse OpenRouter response for cost sync',
					'error',
					array(
						'error' => json_last_error_msg(),
					)
				);
				return $result;
			}

			if ( ! isset( $models_data['data'] ) || ! is_array( $models_data['data'] ) ) {
				$result['errors'][] = 'Invalid response format';
				WP_AI_Workflows_Utilities::debug_log( 'Invalid OpenRouter API response format for cost sync', 'error' );
				return $result;
			}

			foreach ( $models_data['data'] as $model ) {
				if ( ! isset( $model['id'] ) || ! isset( $model['pricing'] ) ) {
					continue;
				}

				$model_id         = $model['id'];
				$prompt_price     = isset( $model['pricing']['prompt'] ) ? floatval( $model['pricing']['prompt'] ) : 0;
				$completion_price = isset( $model['pricing']['completion'] ) ? floatval( $model['pricing']['completion'] ) : 0;

				// Convert from per-token to per-million tokens
				$input_cost  = $prompt_price * 1000000;
				$output_cost = $completion_price * 1000000;

				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM %i 
                     WHERE provider = 'openrouter' AND model = %s",
						$this->cost_settings_table,
						$model_id
					)
				);

				if ( $existing ) {
					if ( $existing->input_cost != $input_cost || $existing->output_cost != $output_cost ) {
						$wpdb->update(
							$this->cost_settings_table,
							array(
								'input_cost'  => $input_cost,
								'output_cost' => $output_cost,
								'updated_at'  => current_time( 'mysql' ),
							),
							array(
								'provider' => 'openrouter',
								'model'    => $model_id,
							),
							array( '%f', '%f', '%s' ),
							array( '%s', '%s' )
						);
						++$result['models_updated'];
					}
				} else {
					$wpdb->insert(
						$this->cost_settings_table,
						array(
							'provider'    => 'openrouter',
							'model'       => $model_id,
							'input_cost'  => $input_cost,
							'output_cost' => $output_cost,
							// updated_at will be set by default via DEFAULT CURRENT_TIMESTAMP
						),
						array( '%s', '%s', '%f', '%f' )
					);
					++$result['models_added'];
				}
			}

			$result['success'] = true;

			update_option( 'wp_ai_workflows_last_cost_sync', time() );

			WP_AI_Workflows_Utilities::debug_log(
				'OpenRouter costs synced successfully',
				'info',
				array(
					'models_added'   => $result['models_added'],
					'models_updated' => $result['models_updated'],
				)
			);

		} catch ( Exception $e ) {
			$result['errors'][] = 'Exception: ' . $e->getMessage();
			WP_AI_Workflows_Utilities::debug_log(
				'Exception during OpenRouter cost sync',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
		}

		return $result;
	}

	/**
	 * AJAX handler for manual cost sync
	 */
	public function handle_manual_cost_sync() {
		check_ajax_referer( 'wp_ai_workflows_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
			return;
		}

		$result = $this->sync_openrouter_costs();

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						'Costs synced successfully. Added %d new models, updated %d existing models.',
						$result['models_added'],
						$result['models_updated']
					),
					'data'    => $result,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => 'Failed to sync costs. ' . implode( ' ', $result['errors'] ),
					'data'    => $result,
				)
			);
		}
	}

	/**
	 * Get cost settings
	 */
	public function get_cost_settings( $provider = null, $model = null ) {
		global $wpdb;

		if ( $provider && $model ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE provider = %s AND model = %s",
					$this->cost_settings_table,
					$provider,
					$model
				)
			);
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY provider, model", $this->cost_settings_table ) );
	}

	/**
	 * Update cost setting
	 */
	public function update_cost_setting( $provider, $model, $input_cost, $output_cost ) {
		global $wpdb;

		WP_AI_Workflows_Utilities::debug_log(
			'Updating cost setting',
			'debug',
			array(
				'provider'    => $provider,
				'model'       => $model,
				'input_cost'  => $input_cost,
				'output_cost' => $output_cost,
			)
		);

		$result = $wpdb->update(
			$this->cost_settings_table,
			array(
				'input_cost'  => $input_cost,
				'output_cost' => $output_cost,
				'updated_at'  => current_time( 'mysql' ),
			),
			array(
				'provider' => $provider,
				'model'    => $model,
			),
			array( '%f', '%f', '%s' ),
			array( '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Calculate node cost
	 */
	public function calculate_node_cost( $execution_id, $node_id, $provider, $model, $prompt_tokens, $completion_tokens ) {
		global $wpdb;

		$original_model = $model;
		if ( strpos( $model, '/' ) !== false && $provider === 'openrouter' ) {
			$model = $model; // Leave as is, since we store the full path in costs table
		}

		$cost_setting = $this->get_cost_settings( $provider, $model );

		// If not found, try checking if it's a new model that needs syncing
		if ( ! $cost_setting && $provider === 'openrouter' ) {
			$this->sync_openrouter_costs();
			$cost_setting = $this->get_cost_settings( $provider, $model );
		}

		if ( ! $cost_setting ) {
			// Before falling back to a flat default, try the dynamic model
			// catalog (OpenRouter live pricing merged with bare-OpenAI ids).
			$catalog_pricing = WP_AI_Workflows_Model_Catalog::get_model_pricing( $model );

			if ( $catalog_pricing ) {
				$input_cost  = $catalog_pricing['input'];
				$output_cost = $catalog_pricing['output'];

				WP_AI_Workflows_Utilities::debug_log(
					'Cost settings sourced from dynamic model catalog',
					'debug',
					array(
						'provider' => $provider,
						'model'    => $model,
					)
				);
			} else {
				WP_AI_Workflows_Utilities::debug_log(
					'Cost settings not found for model',
					'warning',
					array(
						'provider'       => $provider,
						'model'          => $model,
						'original_model' => $original_model,
					)
				);

				$input_cost  = 5.0; // Default $5 per million tokens input
				$output_cost = 15.0; // Default $15 per million tokens output
			}
		} else {
			$input_cost  = $cost_setting->input_cost;
			$output_cost = $cost_setting->output_cost;
		}

		$calculated_input_cost  = ( $prompt_tokens / 1000000 ) * $input_cost;
		$calculated_output_cost = ( $completion_tokens / 1000000 ) * $output_cost;
		$total_cost             = $calculated_input_cost + $calculated_output_cost;

		$wpdb->insert(
			$this->node_costs_table,
			array(
				'execution_id'      => $execution_id,
				'node_id'           => $node_id,
				'model'             => $model,
				'provider'          => $provider,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'cost'              => $total_cost,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%f' )
		);

		$this->update_execution_total_cost( $execution_id );

		WP_AI_Workflows_Utilities::debug_log(
			'Node cost calculated',
			'debug',
			array(
				'execution_id' => $execution_id,
				'node_id'      => $node_id,
				'model'        => $model,
				'total_cost'   => $total_cost,
			)
		);

		return $total_cost;
	}

	/**
	 * Update execution total cost
	 */
	private function update_execution_total_cost( $execution_id ) {
		if ( empty( $execution_id ) ) {
			return;
		}

		// A site step has no local execution row of its own.
		if ( class_exists( 'WP_AI_Workflows_Site_Step' ) && WP_AI_Workflows_Site_Step::is_running_step() ) {
			return;
		}

		global $wpdb;

		$total_cost = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(cost) FROM %i WHERE execution_id = %d",
				$this->node_costs_table,
				$execution_id
			)
		);

		$cost_details = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT node_id, model, provider, prompt_tokens, completion_tokens, cost 
             FROM %i 
             WHERE execution_id = %d",
				$this->node_costs_table,
				$execution_id
			)
		);

		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
		$wpdb->update(
			$executions_table,
			array(
				'total_cost'   => $total_cost,
				'cost_details' => wp_json_encode( $cost_details ),
			),
			array( 'id' => $execution_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get execution costs
	 */
	public function get_execution_costs( $execution_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE execution_id = %d ORDER BY created_at",
				$this->node_costs_table,
				$execution_id
			)
		);
	}

	/**
	 * Get workflow total cost
	 */
	public function get_workflow_total_cost( $workflow_id, $date_from = null, $date_to = null ) {
		global $wpdb;

		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
		if ( $date_from && $date_to ) {
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(total_cost) FROM %i WHERE workflow_id = %s AND created_at BETWEEN %s AND %s",
					$executions_table,
					$workflow_id,
					$date_from,
					$date_to
				)
			) ?: 0;
		} else {
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(total_cost) FROM %i WHERE workflow_id = %s",
					$executions_table,
					$workflow_id
				)
			) ?: 0;
		}
	}

	/**
	 * Get cost statistics
	 */
	public function get_cost_statistics( $workflow_id = null, $date_from = null, $date_to = null ) {
		global $wpdb;

		$where_clauses = array();
		$where_values  = array();

		if ( $workflow_id ) {
			$where_clauses[] = 'e.workflow_id = %s';
			$where_values[]  = $workflow_id;
		}

		if ( $date_from && $date_to ) {
			$where_clauses[] = 'e.created_at BETWEEN %s AND %s';
			$where_values[]  = $date_from;
			$where_values[]  = $date_to;
		}

		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
		
		// Simplified query approach - use separate queries for different conditions
		if ( $workflow_id && $date_from && $date_to ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						SUM(nc.cost) as total_cost,
						COUNT(DISTINCT e.id) as total_executions,
						SUM(nc.prompt_tokens) as total_prompt_tokens,
						SUM(nc.completion_tokens) as total_completion_tokens,
						nc.provider,
						nc.model,
						COUNT(*) as usage_count
					FROM %i e
					JOIN %i nc ON e.id = nc.execution_id
					WHERE e.workflow_id = %s AND e.created_at BETWEEN %s AND %s
					GROUP BY nc.provider, nc.model",
					$executions_table,
					$this->node_costs_table,
					$workflow_id,
					$date_from,
					$date_to
				)
			);
		} elseif ( $workflow_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						SUM(nc.cost) as total_cost,
						COUNT(DISTINCT e.id) as total_executions,
						SUM(nc.prompt_tokens) as total_prompt_tokens,
						SUM(nc.completion_tokens) as total_completion_tokens,
						nc.provider,
						nc.model,
						COUNT(*) as usage_count
					FROM %i e
					JOIN %i nc ON e.id = nc.execution_id
					WHERE e.workflow_id = %s
					GROUP BY nc.provider, nc.model",
					$executions_table,
					$this->node_costs_table,
					$workflow_id
				)
			);
		} elseif ( $date_from && $date_to ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						SUM(nc.cost) as total_cost,
						COUNT(DISTINCT e.id) as total_executions,
						SUM(nc.prompt_tokens) as total_prompt_tokens,
						SUM(nc.completion_tokens) as total_completion_tokens,
						nc.provider,
						nc.model,
						COUNT(*) as usage_count
					FROM %i e
					JOIN %i nc ON e.id = nc.execution_id
					WHERE e.created_at BETWEEN %s AND %s
					GROUP BY nc.provider, nc.model",
					$executions_table,
					$this->node_costs_table,
					$date_from,
					$date_to
				)
			);
		} else {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						SUM(nc.cost) as total_cost,
						COUNT(DISTINCT e.id) as total_executions,
						SUM(nc.prompt_tokens) as total_prompt_tokens,
						SUM(nc.completion_tokens) as total_completion_tokens,
						nc.provider,
						nc.model,
						COUNT(*) as usage_count
					FROM %i e
					JOIN %i nc ON e.id = nc.execution_id
					GROUP BY nc.provider, nc.model",
					$executions_table,
					$this->node_costs_table
				)
			);
		}
	}

	/**
	 * Get daily costs
	 */
	public function get_daily_costs( $date_from, $date_to ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					DATE(nc.created_at) as date,
					nc.model,
					SUM(nc.cost) as cost,
					SUM(nc.prompt_tokens) as prompt_tokens,
					SUM(nc.completion_tokens) as completion_tokens,
					COUNT(*) as api_calls
				FROM %i nc
				WHERE nc.created_at BETWEEN %s AND %s
				GROUP BY DATE(nc.created_at), nc.model
				ORDER BY date",
				$this->node_costs_table,
				$date_from,
				$date_to
			)
		);
	}
}
