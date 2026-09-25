<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Node_Execution {

	public function init(): void {
	}

	public static function execute_node( $node, $node_data, $edges, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		if ( ! isset( $node['id'] ) || ! isset( $node['type'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Invalid node structure', 'error', array( 'node' => $node ) );
			return self::create_node_data( 'error', 'Invalid node structure' );
		}

		$node_id   = $node['id'];
		$node_type = $node['type'];

		$input_data = self::get_node_input_data( $node_id, $edges, $node_data );

		$formatted_input = self::format_input_data_for_display( $node, $input_data );

		if ( isset( $node['data']['content'] ) ) {
			$node['data']['content'] = self::replace_input_tags( $node['data']['content'], $input_data );
		}

		if ( $node_type === 'trigger' && $node['data']['triggerType'] === 'webhook' ) {
			$webhook_data = $input_data;
			$webhook_keys = $node['data']['webhookKeys'] ?? array();
			$result       = array();
			foreach ( $webhook_keys as $key ) {
				$value                 = self::get_nested_value( $webhook_data, $key['key'], '/' );
				$result[ $key['key'] ] = array(
					'type'    => 'webhookInput',
					'content' => $value,
					'input'   => $formatted_input,
				);
			}
			return $result;
		}

		$result = null;
		switch ( $node_type ) {
			case 'trigger':
				$result = self::execute_trigger_node( $node, $input_data, $execution_id );
				break;
			case 'humanInput':
				$result = self::execute_human_input_node( $node, $input_data, $execution_id );
				break;
			case 'aiModel':
				$result = self::execute_ai_model_node( $node, $input_data, $execution_id );
				break;
			case 'output':
				$result = self::execute_output_node( $node, $input_data, $execution_id );
				break;
			case 'sendEmail':
				$result = self::execute_send_email_node( $node, $input_data, $execution_id );
				break;
			case 'post':
				$result = self::execute_post_node( $node, $input_data, $execution_id );
				break;
			case 'condition':
				$result = self::execute_condition_node( $node, $input_data, $execution_id );
				break;
			case 'sentimentAnalysis':
				$result = self::execute_sentiment_analysis_node( $node, $input_data, $execution_id );
				break;
			case 'summaryGenerator':
				$result = self::execute_summary_generator_node( $node, $input_data, $execution_id );
				break;
			case 'extractInformation':
				$result = self::execute_extract_information_node( $node, $input_data, $execution_id );
				break;
			case 'writeArticle':
				$result = self::execute_write_article_node( $node, $input_data, $execution_id );
				break;
			case 'optimizeSEO':
				$result = self::execute_optimize_seo_node( $node, $input_data, $execution_id );
				break;
			case 'research':
				$result = self::execute_research_node( $node, $input_data, $execution_id );
				break;
			case 'firecrawl':
				$result = self::execute_firecrawl_node( $node, $input_data, $execution_id );
				break;
			case 'parser':
				$result = self::execute_parser_node( $node, $node_data, $edges, $execution_id );
				break;
			case 'unsplash':
				$result = self::execute_unsplash_node( $node, $input_data, $execution_id );
				break;
			case 'APICall':
				$result = self::execute_api_call_node( $node, $input_data, $execution_id );
				break;
			case 'chat':
				$result = self::execute_chat_node( $node, $input_data, $execution_id );
				break;
			case 'createFile':
				$result = self::execute_create_file_node( $node, $input_data, $execution_id );
				break;
			case 'generatePdf':
				$result = self::execute_generate_pdf_node( $node, $input_data, $execution_id );
				break;
			case 'mediaGenerator':
				$result = self::execute_multimedia_generator_node( $node, $input_data, $execution_id );
				break;
			case 'MCPClient':
				$result = self::execute_mcp_client_node( $node, $input_data, $execution_id );
				break;
			case 'loop':
				// Loop orchestration is owned by the workflow engine
				// (WP_AI_Workflows_Workflow::execute_workflow), which has the
				// topological order + the body sub-branch it needs to run the
				// loop body once per item. If a loop node is ever dispatched
				// here directly (e.g. nested inside another loop's body - a case
				// this port intentionally does not support), degrade gracefully
				// to an empty collected result instead of erroring.
				$loop_output_mode = isset( $node['data']['outputMode'] ) ? $node['data']['outputMode'] : 'accumulate';
				$result           = self::create_node_data(
					'loop',
					self::loop_assemble_output( array(), array(), array(), $loop_output_mode )
				);
				break;
			default:
				WP_AI_Workflows_Utilities::debug_log( 'Unsupported node type', 'error', array( 'node_type' => $node_type ) );
				$result = self::create_node_data( 'error', 'Unsupported node type: ' . $node_type );
		}

		if ( $result !== null && is_array( $result ) && ! isset( $result['input'] ) ) {
			$result['input'] = $formatted_input;
		}

		return $result;
	}

	public static function execute_trigger_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$triggerType = isset( $node['data']['triggerType'] ) ? $node['data']['triggerType'] : 'manual';
		$outputData  = null;

		switch ( $triggerType ) {
			case 'wpCore':
				if ( ! empty( $input_data ) ) {
					$outputData = $input_data;
				} else {
					$transient_key = 'wp_ai_workflow_trigger_data_' . $node['data']['workflowId'];
					$stored_data   = get_transient( $transient_key );
					if ( $stored_data ) {
						$outputData = $stored_data;
						delete_transient( $transient_key );
					}
				}
				break;
			case 'webhook':
				global $initial_webhook_data;
				if ( is_array( $initial_webhook_data ) && isset( $initial_webhook_data['output'] ) ) {
					$outputData = $initial_webhook_data['output'];
				} else {
					$outputData = $initial_webhook_data; // Keep original data type
				}
				break;
			case 'gravityForms':
			case 'wpForms':
			case 'contactForm7':
			case 'ninjaForms':
			case 'elementorForms':
				$outputData = $input_data;
				break;
			case 'rss':
				if ( ! empty( $node['data']['rssSettings']['feedUrl'] ) ) {
					$feed_url           = $node['data']['rssSettings']['feedUrl'];
					$last_execution_key = 'wp_ai_workflows_rss_last_execution_' . md5( $feed_url . $execution_id );

					if ( get_transient( $last_execution_key ) ) {
						return self::create_node_data( 'trigger', array() );
					}

					set_transient( $last_execution_key, time(), 30 );

					include_once ABSPATH . WPINC . '/feed.php';
					$rss = fetch_feed( $feed_url );

					if ( is_wp_error( $rss ) ) {
						return self::create_node_data( 'error', $rss->get_error_message() );
					}

					$maxitems = $node['data']['rssSettings']['maxItems'] ?? 10;
					$items    = $rss->get_items( 0, $maxitems );

					$formatted_items = array_map(
						function ( $item ) use ( $node ) {
							$data = array(
								'title'       => $item->get_title() ?: '',
								'link'        => $item->get_permalink() ?: '',
								'description' => wp_strip_all_tags( $item->get_description() ?: '' ),
								'pubDate'     => $item->get_date( 'Y-m-d H:i:s' ) ?: '',
								'author'      => $item->get_author() ? $item->get_author()->get_name() : '',
							);

							$enclosure = $item->get_enclosure();
							if ( $enclosure ) {
								$data['media_url']    = $enclosure->get_link();
								$data['media_type']   = $enclosure->get_type();
								$data['media_length'] = $enclosure->get_length();
							}

							$categories         = $item->get_categories();
							$data['categories'] = array();
							if ( $categories ) {
								$data['categories'] = implode(
									', ',
									array_map(
										function ( $cat ) {
											return $cat->get_label();
										},
										$categories
									)
								);
							}

							if ( $node['data']['rssSettings']['includeContent'] ) {
								$data['content'] = wp_strip_all_tags( $item->get_content() ?: '' );
							}

							array_walk(
								$data,
								function ( &$value ) {
									if ( is_array( $value ) ) {
										$value = implode( ', ', $value );
									} elseif ( ! is_string( $value ) && ! is_numeric( $value ) ) {
										$value = strval( $value );
									}
								}
							);

							return $data;
						},
						$items
					);

					$items_string = array_map(
						function ( $item ) {
							return implode(
								"\n",
								array_map(
									function ( $key, $value ) {
										return ucfirst( $key ) . ': ' . $value;
									},
									array_keys( $item ),
									$item
								)
							);
						},
						$formatted_items
					);

					$outputData = array(
						'items'            => $items_string,
						'latest'           => ! empty( $formatted_items ) ? $formatted_items[0] : null,
						'feed_title'       => strval( $rss->get_title() ),
						'feed_description' => strval( $rss->get_description() ),
						'feed_link'        => strval( $rss->get_permalink() ),
						'total_items'      => strval( count( $formatted_items ) ),
					);

					$outputData = self::replace_input_tags_recursive( $outputData, $input_data );

					return self::create_node_data( 'trigger', $outputData );
				}
				break;
			case 'workflowOutput':
				$source_workflow_id = isset( $node['data']['selectedWorkflow'] ) ? $node['data']['selectedWorkflow'] : null;

				if ( ! $source_workflow_id ) {
					return self::create_node_data( 'error', 'No source workflow selected' );
				}

				if ( ! empty( $input_data ) ) {
					$outputData = $input_data;
				} else {
					global $wpdb;
					$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';

					$latest_execution = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT output_data 
                        FROM %i 
                        WHERE workflow_id = %s 
                        AND status = 'completed'
                        ORDER BY created_at DESC 
                        LIMIT 1",
							$table_name,
							$source_workflow_id
						)
					);

					if ( $latest_execution ) {
						$outputData = json_decode( $latest_execution->output_data, true );
					} else {
						$outputData = null;
					}
				}
				break;
			case 'manual':
			default:
				$outputData = isset( $node['data']['content'] ) ? $node['data']['content'] : '';
				/**
				 * Filters the output of a manual trigger node.
				 *
				 * Lets programmatic callers (e.g. the Abilities API agent surface)
				 * inject input that overrides the workflow's stored trigger
				 * content for a single execution, without mutating stored data.
				 *
				 * @since 1.9.0
				 *
				 * @param mixed $outputData   The stored trigger content.
				 * @param array $node         The trigger node.
				 * @param mixed $input_data   Data passed to execute_workflow().
				 * @param int   $execution_id The current execution id.
				 */
				$outputData = apply_filters( 'wp_ai_workflows_manual_trigger_output', $outputData, $node, $input_data, $execution_id );
				break;
		}

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Executed trigger node' );
		return self::create_node_data( 'trigger', $outputData );
	}

	private static function get_nested_value( $array, $keys, $delimiter = '.' ) {
		if ( is_string( $keys ) ) {
			$keys = explode( $delimiter, $keys );
		}

		$current = $array;

		foreach ( $keys as $key ) {
			// Handle array index access (e.g., items.0.title or items/0/title)
			if ( is_numeric( $key ) && is_array( $current ) ) {
				$array_keys = array_keys( $current );
				if ( isset( $array_keys[ (int) $key ] ) ) {
					$current = $current[ $array_keys[ (int) $key ] ];
					continue;
				}
			}

			if ( is_array( $current ) && isset( $current[ $key ] ) ) {
				$current = $current[ $key ];
			} else {
				return null;
			}
		}

		return $current;
	}

	public static function execute_human_input_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_log(
			'Executing human input node',
			'debug',
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
			)
		);

		$human_tasks = new WP_AI_Workflows_Human_Tasks();

		$existing_task = $human_tasks->get_task_by_execution_and_node( $execution_id, $node['id'] );

		if ( $existing_task ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Existing task found',
				'debug',
				array(
					'task_id' => $existing_task->id,
					'status'  => $existing_task->status,
				)
			);

			if ( $existing_task->status === 'completed' ) {
				return array(
					'type'    => 'humanInput',
					'status'  => 'completed',
					'content' => $existing_task->content,
				);
			}

			if ( $existing_task->status === 'pending' ) {
				return array(
					'type'   => 'humanInput',
					'status' => 'pending',
					'taskId' => $existing_task->id,
				);
			}
		}

		// A Cloud run's humanInput node dispatches here through the site-step path,
		// where $execution_id is the local site-step row id, not a workflow id (see
		// the hybrid-execution design's "Deviations" section). Looking that id up
		// against the workflows table risks a coincidental id collision with an
		// unrelated local workflow. The site-step row's platform_execution_id is
		// the only identity this site actually has for that run, so use it.
		$site_step = self::get_site_step_for_execution( $execution_id );

		$task_data = array(
			'execution_id' => $execution_id,
			'node_id'      => $node['id'],
		);

		if ( $site_step ) {
			$task_data['workflow_id']   = 'cloud-execution-' . $site_step->platform_execution_id;
			$task_data['workflow_name'] = self::human_task_label(
				$node,
				isset( $site_step->workflow_name ) ? (string) $site_step->workflow_name : '',
				(string) $site_step->platform_execution_id
			);
		} else {
			$task_data['workflow_id'] = $execution_id;
		}

		$task_data += array(
			'assigned_user' => $node['data']['assignmentType'] === 'user' ? $node['data']['selectedUser'] : null,
			'assigned_role' => $node['data']['assignmentType'] === 'role' ? $node['data']['selectedRole'] : null,
			'input_type'    => $node['data']['inputType'],
			'content'       => self::replace_input_tags( $node['data']['content'], $input_data ),
			'instructions'  => isset( $node['data']['instructions'] ) ? self::replace_input_tags( $node['data']['instructions'], $input_data ) : '',
		);

		$task_id = $human_tasks->create_task( $task_data );

		WP_AI_Workflows_Utilities::debug_log(
			'New human input task created',
			'debug',
			array(
				'task_id'   => $task_id,
				'task_data' => $task_data,
			)
		);

			WP_AI_Workflows_Workflow::pause_execution( $execution_id );

			return array(
				'type'   => 'humanInput',
				'status' => 'pending',
				'taskId' => $task_id,
			);
	}

	/**
	 * What a reviewer sees on the Tasks page and in the Operator Inbox for a task
	 * a Cloud run asked for: the step's own name and the workflow it came from, so
	 * the person approving it can tell what they are approving.
	 *
	 * @param array  $node          The humanInput node.
	 * @param string $workflow_name Name the platform sent with the step.
	 * @param string $execution_id  Platform execution id, the last-resort label.
	 * @return string
	 */
	private static function human_task_label( array $node, $workflow_name, $execution_id ) {
		$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		$step = isset( $data['nodeName'] ) ? trim( (string) $data['nodeName'] ) : '';
		// The builder pre-fills "Human Input <node id>", which names nothing.
		if ( '' === $step || 0 === strpos( $step, 'Human Input ' ) ) {
			$input_type = isset( $data['inputType'] ) ? (string) $data['inputType'] : '';
			$step       = 'modification' === $input_type ? 'Modification' : 'Approval';
		}

		$workflow_name = trim( (string) $workflow_name );
		if ( '' === $workflow_name ) {
			$workflow_name = 'Cloud run #' . $execution_id;
		}

		return $step . ': ' . $workflow_name;
	}

	/**
	 * The site-step row this execution id belongs to, when it is one. Used only
	 * to tell a hybrid Cloud dispatch apart from a genuine local execution id;
	 * see execute_human_input_node().
	 *
	 * @param int $execution_id
	 * @return object|null
	 */
	private static function get_site_step_for_execution( $execution_id ) {
		if ( ! class_exists( 'WP_AI_Workflows_Database' ) ) {
			return null;
		}

		global $wpdb;
		WP_AI_Workflows_Database::ensure_site_steps_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				WP_AI_Workflows_Database::site_steps_table(),
				$execution_id
			)
		);
	}

	/**
	 * Prompt fallback for an AI model node whose prompt field is empty: the incoming
	 * node input becomes the prompt, matching the cloud engine.
	 *
	 * @param array $input_data Input keyed by source node id.
	 * @return string Prompt text, empty when there is nothing usable.
	 */
	private static function prompt_from_input_data( $input_data ) {
		if ( ! is_array( $input_data ) ) {
			return '';
		}

		foreach ( $input_data as $source ) {
			$content = ( is_array( $source ) && array_key_exists( 'content', $source ) ) ? $source['content'] : $source;

			if ( is_string( $content ) ) {
				if ( '' !== trim( $content ) ) {
					return $content;
				}
				continue;
			}

			if ( is_bool( $content ) || is_numeric( $content ) ) {
				return (string) $content;
			}

			if ( is_array( $content ) ) {
				foreach ( array( 'content', 'text', 'message', 'output' ) as $field ) {
					if ( isset( $content[ $field ] ) && is_string( $content[ $field ] ) && '' !== trim( $content[ $field ] ) ) {
						return $content[ $field ];
					}
				}

				$encoded = wp_json_encode( $content );
				if ( is_string( $encoded ) && '' !== $encoded && '{}' !== $encoded && '[]' !== $encoded && 'null' !== $encoded ) {
					return $encoded;
				}
			}
		}

		return '';
	}

	/**
	 * The AI model node's own prompt text, keeping newlines and tabs intact.
	 *
	 * @param array $node Node definition.
	 * @return string Prompt text.
	 */
	private static function prompt_content( $node ) {
		$content = isset( $node['data']['content'] ) ? $node['data']['content'] : 'Default prompt';
		return sanitize_textarea_field( (string) $content );
	}


	public static function execute_ai_model_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$content     = self::prompt_content( $node );
		$model       = isset( $node['data']['model'] ) ? $node['data']['model'] : WP_AI_Workflows_Model_Catalog::get_default_model();
		$imageUrls   = isset( $node['data']['imageUrls'] ) ? $node['data']['imageUrls'] : array();
		$parameters  = isset( $node['data']['settings'] ) ? $node['data']['settings'] : array();
		$openaiTools = isset( $node['data']['openaiTools'] ) ? $node['data']['openaiTools'] : null;

		$is_direct_openai = strpos( $model, '/' ) === false;

		$use_openai_tools = false;
		$tools_config     = null;

		if ( $is_direct_openai && ! empty( $openaiTools ) ) {
			$tools_config     = self::prepare_openai_tools( $openaiTools );
			$use_openai_tools = ! empty( $tools_config );

			WP_AI_Workflows_Utilities::debug_log(
				'Tools configuration prepared',
				'debug',
				array(
					'tools_config'     => $tools_config,
					'model'            => $model,
					'is_direct_openai' => $is_direct_openai,
				)
			);
		}

		$prompt             = self::replace_input_tags( $content, $input_data );

		if ( '' === trim( $prompt ) ) {
			$prompt = self::prompt_from_input_data( $input_data );
			if ( '' !== $prompt ) {
				WP_AI_Workflows_Utilities::debug_log(
					'AI model prompt is empty; using incoming input',
					'info',
					array( 'source_nodes' => array_keys( is_array( $input_data ) ? $input_data : array() ) )
				);
			}
		}
		$processedImageUrls = array_map(
			function ( $url ) use ( $input_data ) {
				return self::replace_input_tags( $url, $input_data );
			},
			$imageUrls
		);

		// RAG: opt-in Supabase (pgvector) knowledge base retrieval. Fail-open:
		// retrieval errors leave the prompt unchanged rather than failing the node.
		$kb_cfg = isset( $node['data']['knowledgeBase'] ) ? $node['data']['knowledgeBase'] : null;
		if ( is_array( $kb_cfg ) && ! empty( $kb_cfg['enabled'] ) && ! empty( $kb_cfg['kbId'] )
			&& class_exists( 'WP_AI_Workflows_Knowledge_Base' )
			&& WP_AI_Workflows_Knowledge_Base::is_configured() ) {
			$kb_top_k   = isset( $kb_cfg['topK'] ) ? (int) $kb_cfg['topK'] : 5;
			$kb_context = WP_AI_Workflows_Knowledge_Base::retrieve_context(
				sanitize_text_field( $kb_cfg['kbId'] ),
				$prompt,
				$kb_top_k
			);
			if ( '' !== $kb_context ) {
				$prompt = $kb_context . "\n\n---\n\n" . $prompt;
			}
		}

		// Structured / multi-output: opt-in via node.data.structuredOutput +
		// outputSchema. Requests a strict JSON object (response_format) and appends
		// a plain-language JSON instruction as a fallback for providers that ignore it.
		$structured_output = ! empty( $node['data']['structuredOutput'] );
		$output_schema     = ( isset( $node['data']['outputSchema'] ) && is_array( $node['data']['outputSchema'] ) )
			? $node['data']['outputSchema'] : array();
		if ( $structured_output && ! empty( $output_schema ) ) {
			$response_format = WP_AI_Workflows_Utilities::build_structured_response_format( $output_schema );
			if ( null !== $response_format ) {
				$parameters['response_format'] = $response_format;
				$prompt                        = $prompt . "\n\n" . self::build_structured_output_instruction( $output_schema );
			} else {
				$structured_output = false;
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'AI model input',
			'debug',
			array(
				'content'      => $content,
				'model'        => $model,
				'imageUrls'    => $imageUrls,
				'parameters'   => $parameters,
				'use_tools'    => $use_openai_tools,
				'structured'   => $structured_output,
			)
		);

		// "WordPress AI (site default)" routes through core's provider-agnostic
		// AI Client (WP 7.0+), using whatever provider the site owner configured
		// in Settings > AI (Connectors) - bypasses both BYOK keys and platform
		// credits. Feature-detected so older WordPress builds error clearly.
		if ( 'wordpress_ai' === $model ) {
			return self::execute_ai_model_via_wp_ai_client( $node, $prompt, $parameters, $execution_id );
		}

		// Keyless credit routing: when the router resolves to 'credits', the call
		// goes through the metered platform proxy with NO provider key in the body.
		// There is deliberately no fallback to BYOK on any failure.
		if ( WP_AI_Workflows_AI_Router::ROUTE_CREDITS === WP_AI_Workflows_AI_Router::resolve( isset( $node['data'] ) ? $node['data'] : array() ) ) {
			return self::execute_ai_model_via_credits( $node, $prompt, $model, $processedImageUrls, $parameters, $input_data, $execution_id );
		}

		try {
			if ( $use_openai_tools ) {
				$response = WP_AI_Workflows_Utilities::call_openai_with_tools(
					$prompt,
					$model,
					$processedImageUrls,
					$tools_config,
					$parameters
				);

				if ( isset( $response['usage'] ) ) {
					try {
						$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
						$cost         = $cost_manager->calculate_node_cost(
							$execution_id,
							$node['id'],
							'openai',
							$model,
							$response['usage']['input_tokens'] ?? 0,
							$response['usage']['output_tokens'] ?? 0
						);

						WP_AI_Workflows_Utilities::debug_log(
							'Cost calculation complete',
							'debug',
							array(
								'cost'  => $cost,
								'usage' => $response['usage'],
							)
						);
					} catch ( Exception $e ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Error calculating cost',
							'error',
							array(
								'error' => $e->getMessage(),
							)
						);
					}
				}

				$formatted_citations = '';
				if ( ! empty( $response['citations'] ) ) {
					$formatted_citations = "Sources:\n";
					foreach ( $response['citations'] as $citation ) {
						if ( $citation['type'] === 'file_citation' ) {
							$formatted_citations .= "- File: {$citation['filename']}\n";
						} elseif ( $citation['type'] === 'url_citation' ) {
							$formatted_citations .= "- {$citation['title']}: {$citation['url']}\n";
						}
					}
				}

				$formatted_search_results = '';
				if ( ! empty( $response['search_results'] ) ) {
					$formatted_search_results = "Search Results:\n";
					foreach ( $response['search_results'] as $result ) {
						$formatted_search_results .= "- Type: {$result['type']}\n";
						if ( $result['type'] === 'file_search_call' ) {
							$formatted_search_results .= '  Queries: ' . implode( ', ', $result['queries'] ) . "\n";
						}
						if ( isset( $result['results'] ) && ! empty( $result['results'] ) ) {
							$formatted_search_results .= '  Results: ' . count( $result['results'] ) . " items found\n";
						}
						$formatted_search_results .= "\n";
					}
				}

				$decoded_fields = ( $structured_output && ! empty( $output_schema ) )
					? self::decode_structured_output( $response['text'] )
					: null;

				if ( is_array( $decoded_fields ) ) {
					// Structured output + tools (web search): decode the model JSON
					// into first-class fields exactly as the non-tools path does, so
					// [[field] from node] resolves downstream. Search metadata rides
					// alongside without clobbering a schema field of the same name.
					if ( '' !== $formatted_citations && ! array_key_exists( 'citations', $decoded_fields ) ) {
						$decoded_fields['citations'] = $formatted_citations;
					}
					if ( '' !== $formatted_search_results && ! array_key_exists( 'search_results', $decoded_fields ) ) {
						$decoded_fields['search_results'] = $formatted_search_results;
					}
					return self::create_node_data( 'aiModel', $decoded_fields );
				}

				return self::create_node_data(
					'aiModel',
					array(
						'content'        => $response['text'],
						'citations'      => $formatted_citations,
						'search_results' => $formatted_search_results,
					)
				);
			} else {
				$provider = strpos( $model, '/' ) !== false ? 'openrouter' : 'openai';
				$response = ( $provider === 'openrouter' ) ?
					WP_AI_Workflows_Utilities::call_openrouter_api( $prompt, $model, $processedImageUrls, $parameters ) :
					WP_AI_Workflows_Utilities::call_openai_api( $prompt, $model, $processedImageUrls, $parameters );

				if ( is_wp_error( $response ) ) {
					// Surface the error message as a string, never the WP_Error
					// object itself - that was the WSOD cause. Previously this
					// branch fell through with no return, so the node silently
					// resolved to null on any provider failure.
					return self::create_node_data( 'error', $response->get_error_message() );
				}

				$content = $response['choices'][0]['message']['content'];

				if ( isset( $response['usage'] ) ) {
					try {
						$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
						$cost         = $cost_manager->calculate_node_cost(
							$execution_id,
							$node['id'],
							$provider,
							$model,
							$response['usage']['prompt_tokens'] ?? 0,
							$response['usage']['completion_tokens'] ?? 0
						);

						WP_AI_Workflows_Utilities::debug_log(
							'Cost calculation complete',
							'debug',
							array(
								'cost'  => $cost,
								'usage' => $response['usage'],
							)
						);
					} catch ( Exception $e ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Error calculating cost',
							'error',
							array(
								'error' => $e->getMessage(),
							)
						);
					}
				}

				return self::finalize_ai_model_output( $content, $structured_output, $output_schema );
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'API call failed',
				'error',
				array(
					'error'       => $e->getMessage(),
					'using_tools' => $use_openai_tools,
				)
			);
			return self::create_node_data( 'error', $e->getMessage() );
		}
	}

	/**
	 * Execute an AI model node through the core WordPress AI Client (WP 7.0+).
	 *
	 * "WordPress AI (site default)" - routes the text generation through core's
	 * provider-agnostic AI Client so it uses whatever provider the site owner
	 * configured under Settings > AI (Connectors). Complements (does not replace)
	 * the BYOK and credits paths.
	 *
	 * Feature-detected end to end: if `wp_ai_client_prompt()` is absent (pre-7.0)
	 * or no text-generation provider is configured, it returns a clear error
	 * node result rather than fataling - the workflow keeps running.
	 *
	 * @param array  $node         The AI model node.
	 * @param string $prompt       Tag-resolved user prompt.
	 * @param array  $parameters   Node `settings` (temperature/top_p/max_tokens).
	 * @param int    $execution_id Execution id.
	 * @return array Node data (aiModel content) or error node data.
	 */
	public static function execute_ai_model_via_wp_ai_client( $node, $prompt, $parameters, $execution_id ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'WordPress AI Client requested but wp_ai_client_prompt() is unavailable',
				'warning',
				array( 'node_id' => $node['id'] ?? null )
			);
			return self::create_node_data(
				'error',
				__( 'WordPress AI is unavailable: this requires WordPress 7.0 or later with a provider configured under Settings > AI. Choose a different provider on this node.', 'ai-workflow-automation-lite' )
			);
		}

		try {
			$builder = wp_ai_client_prompt( $prompt );

			if ( ! $builder->is_supported_for_text_generation() ) {
				return self::create_node_data(
					'error',
					__( 'WordPress AI has no text-generation provider configured. Set one up under Settings > AI (Connectors), or choose a different provider on this node.', 'ai-workflow-automation-lite' )
				);
			}

			if ( isset( $parameters['temperature'] ) && is_numeric( $parameters['temperature'] ) ) {
				$builder->using_temperature( (float) $parameters['temperature'] );
			}
			if ( isset( $parameters['top_p'] ) && is_numeric( $parameters['top_p'] ) ) {
				$builder->using_top_p( (float) $parameters['top_p'] );
			}
			if ( isset( $parameters['max_tokens'] ) && is_numeric( $parameters['max_tokens'] ) ) {
				$builder->using_max_tokens( (int) $parameters['max_tokens'] );
			}

			$text = $builder->generate_text();

			if ( is_wp_error( $text ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'WordPress AI Client generation failed',
					'error',
					array( 'error' => $text->get_error_message() )
				);
				return self::create_node_data( 'error', $text->get_error_message() );
			}

			return self::create_node_data( 'aiModel', array( 'content' => (string) $text ) );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'WordPress AI Client threw an exception',
				'error',
				array( 'error' => $e->getMessage() )
			);
			return self::create_node_data( 'error', $e->getMessage() );
		}
	}

	/**
	 * Execute an AI model node through the metered credits proxy (keyless AI).
	 *
	 * Builds an allow-listed payload - no provider key is ever sent - and maps the
	 * proxy result into the same create_node_data('aiModel', ...) shape the BYOK
	 * path produces. On failure it returns an error node result and never falls
	 * back to the site's BYOK key.
	 *
	 * @param array  $node          The AI model node.
	 * @param string $prompt        Tag-resolved user prompt.
	 * @param string $model         Model id.
	 * @param array  $image_urls    Tag-resolved image URLs.
	 * @param array  $parameters    Node `settings` (max_tokens/temperature).
	 * @param mixed  $input_data    Upstream node output (for tag resolution).
	 * @param int    $execution_id  Execution id (for cost recording).
	 * @return array Node data (aiModel content) or error node data.
	 */
	private static function execute_ai_model_via_credits( $node, $prompt, $model, $image_urls, $parameters, $input_data, $execution_id ) {
		$payload = array(
			'model' => $model,
			'input' => $prompt,
		);

		$system_prompt = isset( $node['data']['systemPrompt'] ) ? (string) $node['data']['systemPrompt'] : '';
		if ( '' !== $system_prompt ) {
			$payload['systemPrompt'] = self::replace_input_tags( $system_prompt, $input_data );
		}
		$clean_images = array_values( array_filter( (array) $image_urls ) );
		if ( ! empty( $clean_images ) ) {
			$payload['imageUrls'] = $clean_images;
		}
		if ( is_array( $parameters ) && isset( $parameters['max_tokens'] ) ) {
			$payload['maxTokens'] = (int) $parameters['max_tokens'];
		}
		if ( is_array( $parameters ) && isset( $parameters['temperature'] ) ) {
			$payload['temperature'] = (float) $parameters['temperature'];
		}

		$structured_output = ! empty( $node['data']['structuredOutput'] );
		$output_schema     = ( isset( $node['data']['outputSchema'] ) && is_array( $node['data']['outputSchema'] ) )
			? $node['data']['outputSchema'] : array();
		if ( $structured_output && ! empty( $output_schema ) ) {
			$response_format = WP_AI_Workflows_Utilities::build_structured_response_format( $output_schema );
			if ( null !== $response_format ) {
				$payload['responseFormat'] = $response_format;
			} else {
				$structured_output = false;
			}
		}

		$response = WP_AI_Workflows_Platform_Client::proxy_ai( $payload );

		if ( is_wp_error( $response ) ) {
			return self::credits_ai_error_result( $response );
		}

		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			try {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost_manager->calculate_node_cost(
					$execution_id,
					$node['id'],
					'platform',
					$model,
					$response['usage']['prompt_tokens'] ?? ( $response['usage']['input_tokens'] ?? 0 ),
					$response['usage']['completion_tokens'] ?? ( $response['usage']['output_tokens'] ?? 0 )
				);
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log( 'Error recording credit-mode cost', 'error', array( 'error' => $e->getMessage() ) );
			}
		}

		$content = isset( $response['content'] ) ? (string) $response['content'] : '';
		return self::finalize_ai_model_output( $content, $structured_output, $output_schema );
	}

	/**
	 * Finalize an AI Model node's raw text output into a node-data envelope.
	 *
	 * When structured output is enabled and the raw text parses as a JSON object,
	 * the parsed associative array is stored so each defined field is resolvable
	 * downstream as `[[name] from nodeId]`. Otherwise (structured off, or the
	 * model returned non-JSON) it falls back to the original markdown-processed
	 * single-blob behavior - byte-identical to the pre-feature path.
	 *
	 * @param string $content           Raw model text.
	 * @param bool   $structured_output Whether structured output was requested.
	 * @param array  $output_schema     The user-defined output field list.
	 * @return array Node-data envelope from create_node_data().
	 */
	private static function finalize_ai_model_output( $content, $structured_output, $output_schema ) {
		if ( is_string( $content ) && '' !== trim( $content ) ) {
			$looks_like_json_object = ( '{' === substr( ltrim( $content ), 0, 1 ) );
			if ( $structured_output || $looks_like_json_object ) {
				$decoded = self::decode_structured_output( $content );
				if ( is_array( $decoded ) ) {
					return self::create_node_data( 'aiModel', $decoded );
				}

				if ( $structured_output && ! empty( $output_schema ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Structured AI output was not valid JSON; falling back to single-blob storage',
						'warning',
						array( 'preview' => substr( (string) $content, 0, 200 ) )
					);
				}
			}
		}

		$processed_response = self::process_ai_response( $content );
		if ( is_array( $processed_response ) ) {
			$processed_response = wp_json_encode( $processed_response );
		}
		return self::create_node_data( 'aiModel', $processed_response );
	}

	/**
	 * Decode an AI model's structured-output text into an associative array of
	 * named fields, tolerant of a ```json fence and the common malformed-JSON
	 * sins models emit (raw newlines inside strings, trailing commas, smart
	 * quotes). Returns null when the text is not a decodable JSON object.
	 *
	 * @param string $content Raw model text.
	 * @return array|null Decoded field map, or null.
	 */
	private static function decode_structured_output( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}
		// Providers sometimes wrap JSON in a ```json fence despite instructions.
		$candidate = trim( $content );
		$candidate = preg_replace( '/^```(?:json)?\s*/i', '', $candidate );
		$candidate = preg_replace( '/\s*```$/', '', $candidate );
		$candidate = trim( (string) $candidate );

		if ( '' === $candidate || '{' !== $candidate[0] ) {
			return null;
		}

		$decoded = json_decode( $candidate, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		$repaired = self::repair_jsonish( $candidate );
		if ( null !== $repaired ) {
			$decoded = json_decode( $repaired, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Build a plain-language "return strict JSON with these keys" instruction from
	 * an AI Model node's outputSchema. Appended to the prompt as a fallback so
	 * models/providers that ignore response_format still return parseable JSON.
	 *
	 * @param array $output_schema Array of { name, type, description }.
	 * @return string Instruction block.
	 */
	private static function build_structured_output_instruction( $output_schema ) {
		$lines = array();
		foreach ( $output_schema as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'string';
			$desc = ! empty( $field['description'] ) ? ': ' . (string) $field['description'] : '';
			$lines[] = '- "' . (string) $field['name'] . '" (' . $type . ')' . $desc;
		}
		if ( empty( $lines ) ) {
			return '';
		}
		return "Return ONLY a single valid JSON object (no markdown, no code fences, no commentary) containing exactly these keys:\n"
			. implode( "\n", $lines );
	}

	/**
	 * Map a credits-proxy WP_Error into a clear error node result. Attaches
	 * machine-readable `errorCode` + top-up fields so the builder can raise the
	 * shared TopUpPrompt. Never falls back to BYOK - only ever returns an error.
	 *
	 * @param WP_Error $error
	 * @return array Error node data.
	 */
	private static function credits_ai_error_result( $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();

		switch ( $code ) {
			case 'platform_credits':
				$balance  = is_array( $data ) && isset( $data['balance'] ) ? (float) $data['balance'] : 0;
				$required = is_array( $data ) && isset( $data['required'] ) ? (float) $data['required'] : 0;
				$msg      = sprintf(
					/* translators: 1: available credits, 2: required credits */
					'Insufficient credits: %1$s available, %2$s required. Top up to run this AI node in credit mode. (You were not charged.)',
					$balance,
					$required
				);
				$result              = self::create_node_data( 'error', $msg );
				$result['errorCode'] = 'platform_credits';
				$result['balance']   = $balance;
				$result['required']  = $required;
				$result['topUp']     = true;
				return $result;

			case 'platform_provider':
				$result              = self::create_node_data( 'error', 'The upstream AI provider failed. You were not charged. Please try again.' );
				$result['errorCode'] = 'platform_provider';
				return $result;

			case 'platform_rate_limited':
				$result              = self::create_node_data( 'error', 'The AI service is temporarily rate limited. Please try again in a moment.' );
				$result['errorCode'] = 'platform_rate_limited';
				return $result;

			case 'platform_auth':
			case 'platform_disconnected':
				$result              = self::create_node_data( 'error', 'This site is not connected to a platform account, so credit-mode AI is unavailable. Reconnect your account or switch this node to your own key.' );
				$result['errorCode'] = $code;
				return $result;

			default:
				$result              = self::create_node_data( 'error', $error->get_error_message() );
				$result['errorCode'] = (string) $code;
				return $result;
		}
	}

	private static function prepare_openai_tools( $tools_config ) {
		$tools = array();

		if ( isset( $tools_config['webSearch'] ) && isset( $tools_config['webSearch']['enabled'] ) && $tools_config['webSearch']['enabled'] ) {
			$web_search_tool = array(
				'type' => 'web_search_preview',
			);

			if ( isset( $tools_config['webSearch']['contextSize'] ) ) {
				$web_search_tool['search_context_size'] = $tools_config['webSearch']['contextSize'];
			}

			if ( isset( $tools_config['webSearch']['location'] ) ) {
				$location = $tools_config['webSearch']['location'];
				if ( ! empty( $location['city'] ) || ! empty( $location['region'] ) || ! empty( $location['country'] ) ) {
					$web_search_tool['user_location'] = array(
						'type'     => 'approximate',
						'city'     => $location['city'] ?? null,
						'region'   => $location['region'] ?? null,
						'country'  => $location['country'] ?? null,
						'timezone' => null,
					);
				}
			}

			$tools[] = $web_search_tool;
		}

		if ( isset( $tools_config['fileSearch'] ) && isset( $tools_config['fileSearch']['enabled'] ) &&
			$tools_config['fileSearch']['enabled'] && ! empty( $tools_config['fileSearch']['vectorStoreId'] ) ) {
			$file_search_tool = array(
				'type'             => 'file_search',
				'vector_store_ids' => array( $tools_config['fileSearch']['vectorStoreId'] ),
			);

			if ( isset( $tools_config['fileSearch']['maxResults'] ) ) {
				$file_search_tool['max_num_results'] = intval( $tools_config['fileSearch']['maxResults'] );
			}

			$tools[] = $file_search_tool;
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Prepared OpenAI tools',
			'debug',
			array(
				'tools_count' => count( $tools ),
				'tools'       => array_map(
					function ( $tool ) {
						return $tool['type']; },
					$tools
				),
			)
		);

		return $tools;
	}

	public static function execute_output_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Starting output node execution' );

		global $wpdb;

		$output_content = array_reduce(
			$input_data,
			function ( $carry, $input_node ) {
				$content = '';
				if ( isset( $input_node['content'] ) ) {
					if ( is_array( $input_node['content'] ) ) {
						$content = wp_json_encode( $input_node['content'] );
					} else {
						$content = $input_node['content'];
					}
				}
				return $carry . $content . "\n\n";
			},
			''
		);

		$output_content = trim( $output_content );

		$output_type = isset( $node['data']['outputType'] ) ? $node['data']['outputType'] : 'display';

		$result = array(
			'type'    => 'output',
			'content' => $output_content,
			'status'  => 'success',
			'message' => '',
		);

		if ( isset( $node['data']['delayEnabled'] ) && $node['data']['delayEnabled'] ) {
			$delay_time = WP_AI_Workflows_Utilities::calculate_delay_time( $node['data']['delayValue'], $node['data']['delayUnit'] );

			if ( $delay_time === false ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Failed to calculate delay time',
					'error',
					array(
						'node_id'     => $node['id'],
						'delay_value' => $node['data']['delayValue'],
						'delay_unit'  => $node['data']['delayUnit'],
					)
				);
				WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'error', 'Failed to schedule delayed output' );
				return self::create_node_data( 'error', 'Failed to schedule delayed output due to invalid delay settings' );
			}

			$site_step_running = class_exists( 'WP_AI_Workflows_Site_Step' ) && WP_AI_Workflows_Site_Step::is_running_step();

			wp_schedule_single_event(
				$delay_time,
				'wp_ai_workflows_execute_delayed_output',
				array(
					'node'           => $node,
					'output_content' => $output_content,
					'execution_id'   => $site_step_running ? 0 : $execution_id,
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'scheduled', 'Output scheduled for execution at: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $delay_time ), 'Y-m-d H:i:s' ) );
			return self::create_node_data( 'output', 'Output scheduled for execution at: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $delay_time ), 'Y-m-d H:i:s' ) );
		}

		switch ( $output_type ) {
			case 'save':
				// Sanitize table name to prevent SQL injection
				$selected_table = isset( $node['data']['selectedTable'] ) ? $node['data']['selectedTable'] : 'wp_ai_workflows_outputs';
				$selected_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $selected_table ); // Allow only alphanumeric and underscore
				$table_name = $wpdb->prefix . $selected_table;

				$insert_data = array(
					'created_at' => current_time( 'mysql' ),
				);

				if ( isset( $node['data']['columns'] ) && is_array( $node['data']['columns'] ) ) {
					foreach ( $node['data']['columns'] as $column ) {
						$column_name = sanitize_key( $column['name'] );
						if ( $column_name !== 'id' && $column_name !== 'created_at' ) {
							$mapping      = $column['mapping'];
							$mapped_value = self::replace_input_tags( $mapping, $input_data );

							if ( is_array( $mapped_value ) ) {
								$insert_data[ $column_name ] = wp_json_encode( $mapped_value );
							} else {
								switch ( $column['type'] ) {
									case 'number':
										$insert_data[ $column_name ] = floatval( $mapped_value );
										break;
									case 'datetime':
										$insert_data[ $column_name ] = gmdate( 'Y-m-d H:i:s', strtotime( $mapped_value ) );
										break;
									case 'text':
									default:
										$insert_data[ $column_name ] = $mapped_value;
										break;
								}
							}
						}
					}
				}

				$insert_result = $wpdb->insert( $table_name, $insert_data );

				if ( $insert_result === false ) {
					$result['status']  = 'error';
					$result['message'] = 'Failed to save output to database: ' . $wpdb->last_error;
					WP_AI_Workflows_Utilities::debug_log( 'Database insert error: ' . $wpdb->last_error, 'error' );
				}
				break;

			case 'webhook':
				$webhook_url  = isset( $node['data']['webhookUrl'] ) ? $node['data']['webhookUrl'] : '';
				$webhook_keys = isset( $node['data']['webhookKeys'] ) ? $node['data']['webhookKeys'] : array();

				if ( ! empty( $webhook_url ) ) {
					$webhook_data = self::process_webhook_data( $webhook_keys, $input_data );

					$response = wp_remote_post(
						$webhook_url,
						array(
							'body'    => wp_json_encode( $webhook_data ),
							'headers' => array( 'Content-Type' => 'application/json' ),
							'timeout' => 15,
						)
					);

					if ( is_wp_error( $response ) ) {
						$result['status']  = 'error';
						$result['message'] = 'Webhook request failed: ' . $response->get_error_message();
						WP_AI_Workflows_Utilities::debug_log( 'Webhook error: ' . $response->get_error_message(), 'error' );
					} else {
						$response_code = wp_remote_retrieve_response_code( $response );
						if ( $response_code < 200 || $response_code >= 300 ) {
							$result['status']  = 'warning';
							$result['message'] = "Webhook request received non-200 response: $response_code";
							WP_AI_Workflows_Utilities::debug_log( "Webhook non-200 response: $response_code", 'warning' );
						}
					}
				} else {
					$result['status']  = 'error';
					$result['message'] = 'Webhook URL is empty';
					WP_AI_Workflows_Utilities::debug_log( 'Webhook URL is empty', 'warning' );
				}
				break;

			case 'html':
				break;

			case 'display':
				break;

			case 'googleSheets':
				try {
					$google_service  = new WP_AI_Workflows_Google_Service();
					$spreadsheet_id  = $node['data']['selectedSpreadsheet'];
					$sheet_id        = $node['data']['selectedSheetTab'];
					$column_mappings = $node['data']['columnMappings'];

					$values = array();
					foreach ( $column_mappings as $column => $mapping ) {
						$values[ $column ] = self::replace_input_tags( $mapping, $input_data );
					}

					$append_result = $google_service->append_to_sheet( $spreadsheet_id, $sheet_id, $values );

					if ( isset( $append_result['updates'] ) ) {
						$result['status']  = 'success';
						$result['message'] = 'Data appended to Google Sheet successfully';
						WP_AI_Workflows_Utilities::debug_log( 'Data appended to Google Sheet', 'debug', $append_result );
					} else {
						$result['status']  = 'error';
						$result['message'] = 'Failed to append data to Google Sheet';
						WP_AI_Workflows_Utilities::debug_log( 'Failed to append data to Google Sheet', 'error', $append_result );
					}
				} catch ( Exception $e ) {
					$result['status']  = 'error';
					$result['message'] = 'Error appending to Google Sheet: ' . $e->getMessage();
					WP_AI_Workflows_Utilities::debug_log(
						'Exception while appending to Google Sheet',
						'error',
						array(
							'error_message' => $e->getMessage(),
							'node_id'       => $node['id'],
						)
					);
				}
				break;

			case 'googleDrive':
				try {
					$google_service = new WP_AI_Workflows_Google_Service();
					$folder_id      = $node['data']['selectedDriveFolder'];

					$file_name = '';
					if ( ! empty( $node['data']['driveFileName'] ) ) {
						$file_name = self::replace_input_tags( $node['data']['driveFileName'], $input_data );
					}
					$file_name = $file_name ?: 'output_' . time();

					$file_format   = $node['data']['driveFileFormat'];
					$sharing_level = $node['data']['sharingLevel'] ?? 'private';

					if ( ! empty( $node['data']['driveContent'] ) ) {
						$content = self::replace_input_tags( $node['data']['driveContent'], $input_data );
					} else {
						$content = '';
						foreach ( $input_data as $input_node ) {
							if ( isset( $input_node['content'] ) ) {
								$content .= ( is_array( $input_node['content'] ) ?
									self::format_data_for_drive( $input_node['content'] ) :
									$input_node['content'] ) . "\n\n";
							}
						}
						$content = trim( $content );
					}

					$mime_type  = self::get_mime_type( $file_format );
					$file_name .= '.' . $file_format;

					$create_result = $google_service->create_drive_file(
						$folder_id,
						$file_name,
						$content,
						$mime_type,
						$sharing_level
					);

					WP_AI_Workflows_Utilities::debug_log(
						'Drive API response',
						'debug',
						array(
							'create_result' => $create_result,
						)
					);

					if ( isset( $create_result['id'] ) ) {
						$file_link = 'https://drive.google.com/file/d/' . $create_result['id'] . '/view';
						return array(
							'type'    => 'output',
							'content' => array(
								'status'        => 'success',
								'message'       => 'File created in Google Drive successfully',
								'file_id'       => $create_result['id'],
								'file_name'     => $create_result['name'],
								'file_link'     => $file_link,
								'sharing_level' => $sharing_level,
							),
						);
					} else {
						WP_AI_Workflows_Utilities::debug_log(
							'Failed to create Drive file',
							'error',
							array(
								'create_result' => $create_result,
							)
						);
						return array(
							'type'    => 'error',
							'content' => 'Failed to create file in Google Drive',
						);
					}
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Drive error',
						'error',
						array(
							'error_message' => $e->getMessage(),
							'trace'         => $e->getTraceAsString(),
						)
					);
					return array(
						'type'    => 'error',
						'content' => 'Error creating file in Google Drive: ' . $e->getMessage(),
					);
				}
				break;

			default:
				$result['status']  = 'error';
				$result['message'] = 'Invalid output type';
				WP_AI_Workflows_Utilities::debug_log( "Invalid output type: $output_type", 'error' );
				break;
		}

		if ( ! empty( $node['data']['email'] ) && ! empty( $node['data']['email']['to'] ) ) {
			$email_data = $node['data']['email'];

			$email_to      = self::replace_input_tags( $email_data['to'], $input_data );
			$email_subject = self::replace_input_tags( $email_data['subject'], $input_data );
			$email_body    = self::replace_input_tags( $email_data['body'], $input_data );

			if ( ! empty( $email_to ) && is_email( $email_to ) ) {
				try {
					if ( empty( $email_subject ) ) {
						$email_subject = 'Workflow Output Notification';
					}

					$headers   = array();
					$headers[] = 'Content-Type: text/plain; charset=UTF-8';

					$from_email = get_option( 'admin_email' );
					$from_name  = get_bloginfo( 'name' );
					$headers[]  = 'From: ' . $from_name . ' <' . $from_email . '>';

					$email_result = wp_mail( $email_to, $email_subject, $email_body, $headers );

					if ( $email_result ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Output node email sent successfully',
							'info',
							array(
								'to'      => $email_to,
								'subject' => $email_subject,
								'node_id' => $node['id'],
							)
						);
					} else {
						WP_AI_Workflows_Utilities::debug_log(
							'Failed to send output node email',
							'error',
							array(
								'to'      => $email_to,
								'subject' => $email_subject,
								'node_id' => $node['id'],
							)
						);
					}
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error sending output node email',
						'error',
						array(
							'error'   => $e->getMessage(),
							'node_id' => $node['id'],
						)
					);
				}
			} elseif ( ! empty( $email_to ) && ! is_email( $email_to ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Invalid email address in output node',
						'warning',
						array(
							'email'   => $email_to,
							'node_id' => $node['id'],
						)
					);
			}
		}

					global $wpdb;
					$outputs_table = $wpdb->prefix . 'wp_ai_workflows_outputs';

					$wpdb->insert(
						$outputs_table,
						array(
							'node_id'     => $node['id'],
							'output_data' => is_string( $output_content ) ? $output_content : wp_json_encode( $output_content ),
							'created_at'  => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s' )
					);

		if ( $wpdb->insert_id ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Output saved to database',
				'debug',
				array(
					'node_id'   => $node['id'],
					'output_id' => $wpdb->insert_id,
				)
			);
		}
					WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Output node execution completed' );
					return $result;
	}

	private static function process_webhook_data( $webhook_keys, $input_data ) {
		$result = array();
		foreach ( $webhook_keys as $webhook_key ) {
			$keys  = explode( '/', $webhook_key['key'] );
			$value = self::replace_input_tags( $webhook_key['mapping'], $input_data );
			self::set_nested_value( $result, $keys, $value );
		}
		return $result;
	}

	private static function set_nested_value( &$array, $keys, $value ) {
		$current = &$array;
		foreach ( $keys as $key ) {
			if ( is_numeric( $key ) && is_array( $current ) && ! isset( $current[ $key ] ) ) {
				$current = &$current[];
			} else {
				if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
					$current[ $key ] = array();
				}
				$current = &$current[ $key ];
			}
		}
		$current = $value;
	}

	private static function get_mime_type( $format ) {
		switch ( $format ) {
			case 'txt':
				return 'text/plain';
			case 'docx':
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			case 'csv':
				return 'text/csv';
			case 'pdf':
				return 'application/pdf';
			default:
				return 'text/plain';
		}
	}

	private static function format_data_for_drive( $data, $depth = 0, $indent = '' ) {
		WP_AI_Workflows_Utilities::debug_log(
			'format_data_for_drive called',
			'debug',
			array(
				'data_type'    => gettype( $data ),
				'depth'        => $depth,
				'data_preview' => is_array( $data ) ? 'array(' . count( $data ) . ' items)' : substr( strval( $data ), 0, 100 ),
			)
		);

		if ( ! is_array( $data ) ) {
			return strval( $data );
		}

		$output     = '';
		$new_indent = $indent . '    ';

		foreach ( $data as $key => $value ) {
			$output .= $indent;

			if ( ! is_numeric( $key ) ) {
				$output .= $key . ': ';
			}

			if ( is_array( $value ) ) {
				$output .= "\n" . self::format_data_for_drive( $value, $depth + 1, $new_indent );
			} else {
				$output .= strval( $value ) . "\n";
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'format_data_for_drive result',
			'debug',
			array(
				'depth'          => $depth,
				'output_preview' => substr( $output, 0, 100 ),
			)
		);

		return $output;
	}

	public static function execute_send_email_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$to      = self::replace_input_tags( $node['data']['to'], $input_data );
		$cc      = isset( $node['data']['cc'] ) ? self::replace_input_tags( $node['data']['cc'], $input_data ) : '';
		$bcc     = isset( $node['data']['bcc'] ) ? self::replace_input_tags( $node['data']['bcc'], $input_data ) : '';
		$subject = self::replace_input_tags( $node['data']['subject'], $input_data );
		$body    = self::replace_input_tags( $node['data']['body'], $input_data );

		$processed_attachments = array();
		if ( ! empty( $node['data']['attachments'] ) ) {
			foreach ( $node['data']['attachments'] as $attachment ) {
				$attachment_path = null;
				$temp_id         = null;

				switch ( $attachment['source'] ) {
					case 'upload':
						if ( ! empty( $attachment['id'] ) ) {
							$file_path = get_attached_file( $attachment['id'] );
							if ( $file_path && file_exists( $file_path ) ) {
								$attachment_path = $file_path;
							}
						}
						break;

					case 'external':
					case 'node':
						if ( ! empty( $attachment['url'] ) ) {
							$url = $attachment['source'] === 'node' ?
								self::replace_input_tags( $attachment['url'], $input_data ) :
								$attachment['url'];

							$temp_attachment_id = self::create_attachment_from_url( $url );
							if ( $temp_attachment_id && ! is_wp_error( $temp_attachment_id ) ) {
								$file_path = get_attached_file( $temp_attachment_id );
								if ( $file_path && file_exists( $file_path ) ) {
									$attachment_path = $file_path;
									$temp_id         = $temp_attachment_id;
								}
							}
						}
						break;
				}

				if ( $attachment_path ) {
					$processed_attachments[] = array(
						'path'    => $attachment_path,
						'temp_id' => $temp_id,
					);
				}
			}
		}

		$email_data = array(
			'to'           => $to,
			'cc'           => $cc,
			'bcc'          => $bcc,
			'subject'      => $subject,
			'body'         => $body,
			'useHtml'      => ! empty( $node['data']['useHtml'] ),
			'attachments'  => $processed_attachments,
			'delayEnabled' => ! empty( $node['data']['delayEnabled'] ),
			'delayValue'   => isset( $node['data']['delayValue'] ) ? $node['data']['delayValue'] : 0,
			'delayUnit'    => isset( $node['data']['delayUnit'] ) ? $node['data']['delayUnit'] : 'minutes',
		);

		if ( $email_data['delayEnabled'] ) {
			return self::schedule_delayed_email( $email_data, $execution_id );
		}

		return self::send_email( $email_data, $execution_id );
	}

	public static function send_email( $email_data, $execution_id ) {
		try {
			$headers = array();

			if ( $email_data['useHtml'] ) {
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
			}

			if ( ! empty( $email_data['cc'] ) ) {
				$cc_addresses = array_map( 'trim', explode( ',', $email_data['cc'] ) );
				foreach ( $cc_addresses as $cc ) {
					$headers[] = 'Cc: ' . $cc;
				}
			}

			if ( ! empty( $email_data['bcc'] ) ) {
				$bcc_addresses = array_map( 'trim', explode( ',', $email_data['bcc'] ) );
				foreach ( $bcc_addresses as $bcc ) {
					$headers[] = 'Bcc: ' . $bcc;
				}
			}

			$attachment_paths = array_map(
				function ( $att ) {
					return $att['path'];
				},
				$email_data['attachments']
			);

			$result = wp_mail(
				$email_data['to'],
				$email_data['subject'],
				$email_data['body'],
				$headers,
				$attachment_paths
			);

			foreach ( $email_data['attachments'] as $attachment ) {
				if ( ! empty( $attachment['temp_id'] ) ) {
					wp_delete_attachment( $attachment['temp_id'], true );
				}
			}

			if ( $result ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Email sent successfully',
					'debug',
					array(
						'to'               => $email_data['to'],
						'attachment_count' => count( $attachment_paths ),
					)
				);
				WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'completed', 'Email sent successfully' );
				return self::create_node_data( 'sendEmail', 'Email sent successfully to: ' . $email_data['to'] );
			} else {
				throw new Exception( 'Failed to send email' );
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to send email',
				'error',
				array(
					'to'    => $email_data['to'],
					'error' => $e->getMessage(),
				)
			);
			WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'error', 'Failed to send email: ' . $e->getMessage() );
			return self::create_node_data( 'error', 'Failed to send email to: ' . $email_data['to'] );
		}
	}

	private static function schedule_delayed_email( $email_data, $execution_id ) {
		$delay_time = WP_AI_Workflows_Utilities::calculate_delay_time(
			$email_data['delayValue'],
			$email_data['delayUnit']
		);

		if ( $delay_time === false ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Invalid delay settings',
				'error',
				array(
					'value' => $email_data['delayValue'],
					'unit'  => $email_data['delayUnit'],
				)
			);
			return self::create_node_data( 'error', 'Invalid delay settings' );
		}

		wp_schedule_single_event(
			$delay_time,
			'wp_ai_workflows_send_delayed_email',
			array(
				'email_data'   => $email_data,
				'execution_id' => $execution_id,
			)
		);

		$scheduled_time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $delay_time ), 'Y-m-d H:i:s' );
		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'scheduled',
			"Email scheduled for: $scheduled_time"
		);

		return self::create_node_data( 'sendEmail', "Email scheduled for: $scheduled_time" );
	}

	public static function execute_post_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$post_data = array(
			'post_type'   => isset( $node['data']['selectedPostType'] ) ? $node['data']['selectedPostType'] : 'post',
			'post_status' => isset( $node['data']['postStatus'] ) ? $node['data']['postStatus'] : 'publish',
		);

		$acf_fields     = array();
		$product_fields = array();
		$meta_fields    = array();

		if ( isset( $node['data']['fieldMappings'] ) ) {
			$resolved = array();

			foreach ( $node['data']['fieldMappings'] as $field => $value ) {
				$replaced_value     = self::replace_input_tags( $value, $input_data );
				$resolved[ $field ] = self::strip_unresolved_input_tags( $replaced_value );
			}

			$mapping_buckets = WP_AI_Workflows_Post_Fields::split_mappings( $resolved, $post_data['post_type'] );

			foreach ( $mapping_buckets['core'] as $field => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$post_data[ $field ] = $value;
				}
			}

			$acf_fields = $mapping_buckets['acf'];

			foreach ( $mapping_buckets['product'] as $field => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$product_fields[ $field ] = $value;
				}
			}

			foreach ( $mapping_buckets['meta'] as $field => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$meta_fields[ $field ] = $value;
				}
			}
		}

		if ( ! empty( $node['data']['selectedAuthor'] ) ) {
			$author_value = self::replace_input_tags( $node['data']['selectedAuthor'], $input_data );

			WP_AI_Workflows_Utilities::debug_log(
				'Processing author assignment',
				'debug',
				array(
					'author_value'   => $author_value,
					'original_value' => $node['data']['selectedAuthor'],
				)
			);

			if ( is_numeric( $author_value ) ) {
				$user = get_user_by( 'id', intval( $author_value ) );
				if ( $user && user_can( $user, 'edit_posts' ) ) {
					$post_data['post_author'] = intval( $author_value );
					WP_AI_Workflows_Utilities::debug_log(
						'Author set by ID',
						'info',
						array(
							'user_id'   => intval( $author_value ),
							'user_name' => $user->display_name,
						)
					);
				} else {
					WP_AI_Workflows_Utilities::debug_log(
						'Invalid author ID or user lacks permissions',
						'warning',
						array(
							'author_value' => $author_value,
						)
					);
				}
			} else {
				$user = get_user_by( 'login', $author_value );
				if ( ! $user ) {
					$user = get_user_by( 'email', $author_value );
				}
				if ( $user && user_can( $user, 'edit_posts' ) ) {
					$post_data['post_author'] = $user->ID;
					WP_AI_Workflows_Utilities::debug_log(
						'Author set by username/email',
						'info',
						array(
							'user_id'      => $user->ID,
							'user_name'    => $user->display_name,
							'lookup_value' => $author_value,
						)
					);
				} else {
					WP_AI_Workflows_Utilities::debug_log(
						'Author not found or lacks permissions',
						'warning',
						array(
							'lookup_value' => $author_value,
						)
					);
				}
			}
		}

		if ( ! isset( $post_data['post_title'] ) ) {
			$post_data['post_title'] = 'Auto-generated post ' . current_time( 'mysql' );
		}

		if ( ! isset( $post_data['post_content'] ) ) {
			$post_data['post_content'] = self::pick_fallback_post_content( $input_data );
		}

		if ( $post_data['post_status'] === 'future' && isset( $node['data']['scheduledDate'] ) ) {
			$post_data['post_date']     = $node['data']['scheduledDate'];
			$post_data['post_date_gmt'] = get_gmt_from_date( $node['data']['scheduledDate'] );
		} elseif ( $post_data['post_status'] === 'future' ) {
			$post_data['post_status'] = 'publish';
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Post data prepared',
			'debug',
			array(
				'post_data'  => $post_data,
				'acf_fields' => $acf_fields,
			)
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Error in post node execution', 'error', array( 'error' => $post_id->get_error_message() ) );
			return self::create_node_data( 'error', $post_id->get_error_message() );
		}

		if ( ! empty( $node['data']['selectedCategories'] ) && ! is_wp_error( $post_id ) ) {
			$categories_value = self::replace_input_tags( $node['data']['selectedCategories'], $input_data );

			WP_AI_Workflows_Utilities::debug_log(
				'Processing categories',
				'debug',
				array(
					'categories_value' => $categories_value,
					'post_type'        => $post_data['post_type'],
					'original_value'   => $node['data']['selectedCategories'],
				)
			);

			$main_taxonomy = 'category'; // Default for posts
			if ( $post_data['post_type'] === 'product' ) {
				$main_taxonomy = 'product_cat';
			} else {
				$taxonomies = get_object_taxonomies( $post_data['post_type'], 'objects' );
				foreach ( $taxonomies as $taxonomy ) {
					if ( $taxonomy->hierarchical ) {
						$main_taxonomy = $taxonomy->name;
						break;
					}
				}
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Determined taxonomy',
				'debug',
				array(
					'taxonomy'  => $main_taxonomy,
					'post_type' => $post_data['post_type'],
				)
			);

			$category_ids = array();

			if ( is_array( $categories_value ) ) {
				foreach ( $categories_value as $item ) {
					if ( is_numeric( $item ) ) {
						$category_ids[] = intval( $item );
					} else {
						$item = trim( $item );
						if ( ! empty( $item ) ) {
							$term = get_term_by( 'name', $item, $main_taxonomy );
							if ( ! $term ) {
								$term = get_term_by( 'slug', $item, $main_taxonomy );
							}

							if ( $term ) {
								$category_ids[] = $term->term_id;
							} else {
								$new_term = wp_insert_term( $item, $main_taxonomy );
								if ( ! is_wp_error( $new_term ) ) {
									$category_ids[] = $new_term['term_id'];
									WP_AI_Workflows_Utilities::debug_log(
										'Created new category from array',
										'info',
										array(
											'name'     => $item,
											'taxonomy' => $main_taxonomy,
											'term_id'  => $new_term['term_id'],
										)
									);
								}
							}
						}
					}
				}
			} else {
				$category_items = is_string( $categories_value ) ? explode( ',', $categories_value ) : array( $categories_value );

				foreach ( $category_items as $item ) {
					$item = trim( $item );
					if ( empty( $item ) ) {
						continue;
					}

					if ( is_numeric( $item ) ) {
						$category_ids[] = intval( $item );
					} else {
						$term = get_term_by( 'name', $item, $main_taxonomy );
						if ( ! $term ) {
							$term = get_term_by( 'slug', $item, $main_taxonomy );
						}

						if ( $term ) {
							$category_ids[] = $term->term_id;
							WP_AI_Workflows_Utilities::debug_log(
								'Found existing category',
								'debug',
								array(
									'name'     => $item,
									'term_id'  => $term->term_id,
									'taxonomy' => $main_taxonomy,
								)
							);
						} else {
							$new_term = wp_insert_term( $item, $main_taxonomy );
							if ( ! is_wp_error( $new_term ) ) {
								$category_ids[] = $new_term['term_id'];
								WP_AI_Workflows_Utilities::debug_log(
									'Created new category from string',
									'info',
									array(
										'name'     => $item,
										'taxonomy' => $main_taxonomy,
										'term_id'  => $new_term['term_id'],
									)
								);
							} else {
								WP_AI_Workflows_Utilities::debug_log(
									'Failed to create category',
									'error',
									array(
										'name'     => $item,
										'taxonomy' => $main_taxonomy,
										'error'    => $new_term->get_error_message(),
									)
								);
							}
						}
					}
				}
			}

			$valid_category_ids = array_filter(
				$category_ids,
				function ( $id ) use ( $main_taxonomy ) {
					return term_exists( $id, $main_taxonomy );
				}
			);

			if ( ! empty( $valid_category_ids ) ) {
				$result = wp_set_post_terms( $post_id, $valid_category_ids, $main_taxonomy );
				if ( ! is_wp_error( $result ) ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Categories assigned successfully',
						'info',
						array(
							'post_id'        => $post_id,
							'taxonomy'       => $main_taxonomy,
							'category_ids'   => $valid_category_ids,
							'total_assigned' => count( $valid_category_ids ),
						)
					);
				} else {
					WP_AI_Workflows_Utilities::debug_log(
						'Error assigning categories',
						'error',
						array(
							'error'        => $result->get_error_message(),
							'post_id'      => $post_id,
							'taxonomy'     => $main_taxonomy,
							'category_ids' => $valid_category_ids,
						)
					);
				}
			} else {
				WP_AI_Workflows_Utilities::debug_log(
					'No valid categories to assign',
					'warning',
					array(
						'post_id'             => $post_id,
						'original_categories' => $categories_value,
						'processed_ids'       => $category_ids,
					)
				);
			}
		}

		if ( ! empty( $node['data']['featuredImage'] ) ) {
			$featured_image = $node['data']['featuredImage'];
			$attachment_id  = null;

			if ( $featured_image['source'] === 'upload' && ! empty( $featured_image['id'] ) ) {
				$attachment_id = $featured_image['id'];
			} elseif ( ! empty( $featured_image['url'] ) ) {
				$url = self::replace_input_tags( $featured_image['url'], $input_data );
				if ( $url ) {
					$attachment_id = self::create_attachment_from_url( $url, $post_id );
				}
			}

			if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
				WP_AI_Workflows_Utilities::debug_log(
					'Featured image set',
					'debug',
					array(
						'attachment_id' => $attachment_id,
						'source'        => $featured_image['source'],
					)
				);
			}
		}

		if ( $post_data['post_type'] === 'product' && ! empty( $node['data']['productImages'] ) ) {
			$gallery_ids = array();

			foreach ( $node['data']['productImages'] as $image ) {
				$attachment_id = null;

				if ( $image['source'] === 'upload' && ! empty( $image['id'] ) ) {
					$attachment_id = $image['id'];
				} elseif ( ! empty( $image['url'] ) ) {
					$url = self::replace_input_tags( $image['url'], $input_data );
					if ( $url ) {
						$attachment_id = self::create_attachment_from_url( $url, $post_id );
					}
				}

				if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
					$gallery_ids[] = $attachment_id;
				}
			}

			if ( ! empty( $gallery_ids ) ) {
				update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
				WP_AI_Workflows_Utilities::debug_log(
					'Product gallery updated',
					'debug',
					array(
						'gallery_ids' => $gallery_ids,
					)
				);
			}
		}

		if ( ! empty( $product_fields ) ) {
			$applied_product = WP_AI_Workflows_Post_Fields::apply_product_fields( $post_id, $product_fields );
			WP_AI_Workflows_Utilities::debug_log( 'Product fields written', 'debug', array( 'fields' => $applied_product ) );
		}

		if ( ! empty( $meta_fields ) ) {
			$applied_meta = WP_AI_Workflows_Post_Fields::apply_meta( $post_id, $meta_fields );
			WP_AI_Workflows_Utilities::debug_log( 'Post meta written', 'debug', array( 'keys' => $applied_meta ) );
		}

		if ( ! empty( $acf_fields ) && function_exists( 'update_field' ) ) {
			foreach ( $acf_fields as $field_name => $field_value ) {
				update_field( $field_name, $field_value, $post_id );
			}
			WP_AI_Workflows_Utilities::debug_log(
				'ACF fields updated',
				'debug',
				array(
					'acf_fields' => $acf_fields,
				)
			);
		}

		$result = array(
			'message'  => "Post created with ID: {$post_id}",
			'post_id'  => $post_id,
			'post_url' => get_permalink( $post_id ),
		);

		WP_AI_Workflows_Utilities::debug_log(
			'Post node execution complete',
			'debug',
			array(
				'result' => $result,
			)
		);
		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Executed Post node' );

		return self::create_node_data( 'post', $result );
	}

	public static function execute_condition_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$condition_groups = $node['data']['conditionGroups'];
		$final_result     = false;

		foreach ( $condition_groups as $group ) {
			$group_result = true; // For AND groups

			foreach ( $group['conditions'] as $condition ) {
				$input_value      = self::replace_input_tags( $condition['input'], $input_data );
				$comparison_value = $condition['value'];
				$result           = false;

				switch ( $condition['comparison'] ) {
					case 'equals':
						$result = ( $input_value == $comparison_value );
						break;
					case 'contains':
						$result = ( strpos( $input_value, $comparison_value ) !== false );
						break;
					case 'startsWith':
						$result = ( strpos( $input_value, $comparison_value ) === 0 );
						break;
					case 'endsWith':
						$result = ( substr( $input_value, -strlen( $comparison_value ) ) === $comparison_value );
						break;
					case 'greaterThan':
						$result = ( is_numeric( $input_value ) && is_numeric( $comparison_value ) &&
									floatval( $input_value ) > floatval( $comparison_value ) );
						break;
					case 'lessThan':
						$result = ( is_numeric( $input_value ) && is_numeric( $comparison_value ) &&
									floatval( $input_value ) < floatval( $comparison_value ) );
						break;
				}

				if ( $group['type'] === 'AND' ) {
					$group_result = $group_result && $result;
					if ( ! $group_result ) {
						break; // Short circuit if any condition is false
					}
				} else { // OR group
					$group_result = $result;
					if ( $group_result ) {
						break; // Short circuit if any condition is true
					}
				}
			}

			$final_result = $final_result || $group_result;
			if ( $final_result ) {
				break; // Short circuit if any group is true
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Condition evaluation',
			'debug',
			array(
				'node_id'          => $node['id'],
				'condition_groups' => $condition_groups,
				'input_data'       => $input_data,
				'result'           => $final_result,
			)
		);

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Executed Condition node' );

		return array(
			'type'       => 'condition',
			'content'    => $final_result,
			'input_data' => $input_data,
		);
	}

	/**
	 * Resolve which BYOK provider + model a content node (sentiment analysis,
	 * summary generation, information extraction, article writing, SEO
	 * optimization) should use when the running workflow is in LOCAL (BYOK)
	 * mode.
	 *
	 * These nodes have no per-node model selector - unlike the aiModel node,
	 * which reads a model id off the node and infers the provider from its
	 * format (a "/" means OpenRouter) - so they previously hardcoded the
	 * native OpenAI provider + `gpt-5-mini` outright. That broke any
	 * Local-mode user who configured only an OpenRouter (or other non-OpenAI)
	 * key: the node forced OpenAI and failed with a missing-key error.
	 *
	 * This mirrors the aiModel node's intent instead: respect whichever BYOK
	 * provider key the user actually configured, falling back to the same
	 * default model family, just routed through OpenRouter when that is the
	 * only key present.
	 *
	 * @return array{provider:string,model:string}
	 */
	private static function resolve_local_content_provider_model() {
		if ( ! empty( WP_AI_Workflows_Utilities::get_openai_api_key() ) ) {
			return array(
				'provider' => 'openai',
				'model'    => WP_AI_Workflows_Model_Catalog::get_default_model(),
			);
		}

		if ( ! empty( WP_AI_Workflows_Utilities::get_openrouter_api_key() ) ) {
			// Same default model family, routed through OpenRouter (the
			// provider the user actually configured) instead of the native
			// OpenAI API.
			return array(
				'provider' => 'openrouter',
				'model'    => 'openai/' . WP_AI_Workflows_Model_Catalog::get_default_model(),
			);
		}

		// No BYOK key configured for either provider: keep the previous
		// default so the existing "OpenAI API key is not set" error path is
		// unchanged.
		return array(
			'provider' => 'openai',
			'model'    => WP_AI_Workflows_Model_Catalog::get_default_model(),
		);
	}

	/**
	 * Call the configured AI provider for a content node, honoring LOCAL-mode
	 * BYOK provider routing. CLOUD-mode behavior is untouched: it still calls
	 * the native OpenAI API with the `gpt-5-mini` server default, exactly as
	 * before.
	 *
	 * @param string $prompt Fully input-tag-resolved prompt text.
	 * @return array{response:array|WP_Error,provider:string,model:string}
	 */
	private static function call_content_model( $prompt ) {
		$provider = 'openai';
		$model    = WP_AI_Workflows_Model_Catalog::get_default_model();

		if ( WP_AI_Workflows_AI_Router::MODE_CLOUD !== WP_AI_Workflows_AI_Router::get_execution_mode() ) {
			$resolved = self::resolve_local_content_provider_model();
			$provider = $resolved['provider'];
			$model    = $resolved['model'];
		}

		$response = ( 'openrouter' === $provider )
			? WP_AI_Workflows_Utilities::call_openrouter_api( $prompt, $model )
			: WP_AI_Workflows_Utilities::call_openai_api( $prompt, $model );

		return array(
			'response' => $response,
			'provider' => $provider,
			'model'    => $model,
		);
	}

	public static function execute_sentiment_analysis_node( $node, $input_data, $execution_id ) {
		$content = $node['data']['content'];
		$prompt  = "Analyze the sentiment of the following text and return only one of these options and only in one word: very negative, negative, neutral, positive, very positive. Text: $content";

		$call     = self::call_content_model( $prompt );
		$response = $call['response'];
		$model    = $call['model'];
		$provider = $call['provider'];

		if ( ! is_wp_error( $response ) && isset( $response['usage'] ) ) {
			try {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost         = $cost_manager->calculate_node_cost(
					$execution_id,
					$node['id'],
					$provider,
					$model,
					$response['usage']['prompt_tokens'] ?? 0,
					$response['usage']['completion_tokens'] ?? 0
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Sentiment analysis cost calculated',
					'debug',
					array(
						'cost'              => $cost,
						'model'             => $model,
						'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
						'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
					)
				);
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error calculating sentiment analysis cost',
					'error',
					array(
						'error'   => $e->getMessage(),
						'node_id' => $node['id'],
					)
				);
			}
		}

		// Extract just the content from the response. Never pass the WP_Error
		// object itself through as node content - it isn't a string, and any
		// later string interpolation/concatenation of it (templating,
		// wp_json_encode consumers, etc.) is a WSOD waiting to happen.
		$content = is_wp_error( $response ) ? $response->get_error_message() : $response['choices'][0]['message']['content'];
		return self::create_node_data( is_wp_error( $response ) ? 'error' : 'sentimentAnalysis', $content );
	}

	public static function execute_summary_generator_node( $node, $input_data, $execution_id ) {
		$content = $node['data']['content'];
		$prompt  = "Summarize the following text into key points in maximum 50 words: $content";

		$call     = self::call_content_model( $prompt );
		$response = $call['response'];
		$model    = $call['model'];
		$provider = $call['provider'];

		if ( ! is_wp_error( $response ) && isset( $response['usage'] ) ) {
			try {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost         = $cost_manager->calculate_node_cost(
					$execution_id,
					$node['id'],
					$provider,
					$model,
					$response['usage']['prompt_tokens'] ?? 0,
					$response['usage']['completion_tokens'] ?? 0
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Summary generator cost calculated',
					'debug',
					array(
						'cost'              => $cost,
						'model'             => $model,
						'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
						'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
					)
				);
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error calculating summary cost',
					'error',
					array(
						'error'   => $e->getMessage(),
						'node_id' => $node['id'],
					)
				);
			}
		}

		// Never pass the WP_Error object itself through as node content.
		$content = is_wp_error( $response ) ? $response->get_error_message() : $response['choices'][0]['message']['content'];
		return self::create_node_data( is_wp_error( $response ) ? 'error' : 'summaryGenerator', $content );
	}

	public static function execute_extract_information_node( $node, $input_data, $execution_id ) {
		$content          = $node['data']['content'];
		$extractionFields = $node['data']['extractionFields'];

		$prompt = "Extract the following information from the text. For each label, provide the information in the following format:
            [Label Name]:
            - Item 1
            - Item 2
            - Item 3
            
            If a label is not a list, simply provide the information on a single line after the label.
            
            Labels to extract:\n";

		foreach ( $extractionFields as $field ) {
			$prompt .= "[{$field['name']}]: {$field['description']}" . ( $field['isList'] ? ' (This is a list)' : '' ) . "\n";
		}

		$prompt .= "\nIf a particular label doesn't have any matching information in the text, leave it blank after the colon. For example:\n[Empty Label]: \n\nText to analyze:\n$content";

		$call     = self::call_content_model( $prompt );
		$response = $call['response'];
		$model    = $call['model'];
		$provider = $call['provider'];

		if ( ! is_wp_error( $response ) ) {
			if ( isset( $response['usage'] ) ) {
				try {
					$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
					$cost         = $cost_manager->calculate_node_cost(
						$execution_id,
						$node['id'],
						$provider,
						$model,
						$response['usage']['prompt_tokens'] ?? 0,
						$response['usage']['completion_tokens'] ?? 0
					);

					WP_AI_Workflows_Utilities::debug_log(
						'Information extraction cost calculated',
						'debug',
						array(
							'cost'              => $cost,
							'model'             => $model,
							'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
							'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
						)
					);
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error calculating extraction cost',
						'error',
						array(
							'error'   => $e->getMessage(),
							'node_id' => $node['id'],
						)
					);
				}
			}

			$content       = $response['choices'][0]['message']['content'];
			$extractedData = array();
			foreach ( $extractionFields as $field ) {
				$pattern = "/\[{$field['name']}\]:\s*((?:(?!^\[).)*)/ms";
				if ( preg_match( $pattern, $content, $matches ) ) {
					$value = trim( $matches[1] );
					if ( $field['isList'] ) {
						$value = preg_split( '/\s*-\s*/', $value, -1, PREG_SPLIT_NO_EMPTY );
						$value = array_map( 'trim', $value );
					}
					$extractedData[ $field['name'] ] = $value;
				} else {
					$extractedData[ $field['name'] ] = $field['isList'] ? array() : '';
				}
			}

			WP_AI_Workflows_Utilities::debug_log( 'Extracted data', 'debug', $extractedData );
			return self::create_node_data( 'extractInformation', $extractedData );
		}

		return self::create_node_data( 'error', 'Error processing extraction response' );
	}


	public static function execute_write_article_node( $node, $input_data, $execution_id ) {
		$content    = $node['data']['content'];
		$word_count = $node['data']['wordCount'];
		$prompt     = "I am writing a blog post about $content. Craft and engaging and SEO-friendly article incorporating the main keywords for this subject in around $word_count words. make sure the article has a proper SEO-friendly structure and format";

		$call     = self::call_content_model( $prompt );
		$response = $call['response'];
		$model    = $call['model'];
		$provider = $call['provider'];

		if ( ! is_wp_error( $response ) && isset( $response['usage'] ) ) {
			try {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost         = $cost_manager->calculate_node_cost(
					$execution_id,
					$node['id'],
					$provider,
					$model,
					$response['usage']['prompt_tokens'] ?? 0,
					$response['usage']['completion_tokens'] ?? 0
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Article writing cost calculated',
					'debug',
					array(
						'cost'              => $cost,
						'model'             => $model,
						'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
						'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
					)
				);
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error calculating article cost',
					'error',
					array(
						'error'   => $e->getMessage(),
						'node_id' => $node['id'],
					)
				);
			}
		}

		// Never pass the WP_Error object itself through as node content.
		$content = is_wp_error( $response ) ? $response->get_error_message() : $response['choices'][0]['message']['content'];
		return self::create_node_data( is_wp_error( $response ) ? 'error' : 'writeArticle', $content );
	}

	public static function execute_optimize_seo_node( $node, $input_data, $execution_id ) {
		$content  = $node['data']['content'];
		$keywords = $node['data']['keywords'];
		$prompt   = "Optimize the following text for SEO including its header structure, focusing on these primary keywords: $keywords. Make sure that based on the article you find and integrate around 10 very relevant LSA (Latent Semantic Indexing) keywords to integrate naturally into the content to make it more comprehensive and SEO-rich. Finally make sure it is written in an NLP-friendly way. You need to keep the formatting, word count, and structure of the text and the contents exactly as it is, and only optimize the SEO, nothing else.  Text: $content";

		$call     = self::call_content_model( $prompt );
		$response = $call['response'];
		$model    = $call['model'];
		$provider = $call['provider'];

		if ( ! is_wp_error( $response ) && isset( $response['usage'] ) ) {
			try {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost         = $cost_manager->calculate_node_cost(
					$execution_id,
					$node['id'],
					$provider,
					$model,
					$response['usage']['prompt_tokens'] ?? 0,
					$response['usage']['completion_tokens'] ?? 0
				);

				WP_AI_Workflows_Utilities::debug_log(
					'SEO optimization cost calculated',
					'debug',
					array(
						'cost'              => $cost,
						'model'             => $model,
						'prompt_tokens'     => $response['usage']['prompt_tokens'] ?? 0,
						'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
					)
				);
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error calculating SEO optimization cost',
					'error',
					array(
						'error'   => $e->getMessage(),
						'node_id' => $node['id'],
					)
				);
			}
		}

		// Never pass the WP_Error object itself through as node content.
		$content = is_wp_error( $response ) ? $response->get_error_message() : $response['choices'][0]['message']['content'];
		return self::create_node_data( is_wp_error( $response ) ? 'error' : 'optimizeSEO', $content );
	}

	public static function get_post_types() {
		WP_AI_Workflows_Utilities::debug_log( 'Fetching post types', 'debug' );
		$post_types      = get_post_types( array( 'public' => true ), 'objects' );
		$formatted_types = array();
		foreach ( $post_types as $post_type ) {
			$formatted_types[] = array(
				'name'  => $post_type->name,
				'label' => $post_type->label,
			);
		}
		WP_AI_Workflows_Utilities::debug_log( 'Post types fetched', 'debug', array( 'post_types' => $formatted_types ) );
		return new WP_REST_Response( $formatted_types, 200 );
	}

	public static function get_post_fields( $request ) {
		$post_type        = $request->get_param( 'post_type' );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object ) {
			return new WP_Error( 'invalid_post_type', 'Invalid post type', array( 'status' => 400 ) );
		}

		$fields = array();

		$default_fields = array(
			'post_title'   => 'Title',
			'post_content' => 'Content',
			'post_excerpt' => 'Excerpt',
		);

		foreach ( $default_fields as $name => $label ) {
			$fields[] = array(
				'name'  => $name,
				'label' => $label,
			);
		}

		$registered_meta = get_registered_meta_keys( $post_type );
		foreach ( $registered_meta as $meta_key => $meta_args ) {
			$fields[] = array(
				'name'  => $meta_key,
				'label' => ucfirst( str_replace( '_', ' ', $meta_key ) ),
			);
		}

		if ( $post_type === 'product' && class_exists( 'WC_Product' ) ) {
			$wc_fields = array(
				'_regular_price' => 'Regular Price',
				'_sale_price'    => 'Sale Price',
				'_sku'           => 'SKU',
				'_stock'         => 'Stock Quantity',
				'_stock_status'  => 'Stock Status',
			);
			foreach ( $wc_fields as $name => $label ) {
				$fields[] = array(
					'name'  => $name,
					'label' => $label,
				);
			}
		}

		if ( function_exists( 'acf_get_field_groups' ) ) {
			$field_groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
			foreach ( $field_groups as $field_group ) {
				$acf_fields = acf_get_fields( $field_group );
				foreach ( $acf_fields as $field ) {
					$fields[] = array(
						'name'  => 'acf_' . $field['name'],
						'label' => $field['label'] . ' (ACF)',
					);
				}
			}
		}

		$fields = apply_filters( 'wp_ai_workflows_post_fields', $fields, $post_type );

		$unique_fields = array_unique( $fields, SORT_REGULAR );
		return new WP_REST_Response( $unique_fields, 200 );
	}

	public static function execute_research_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$node_data = $node['data'] ?? array();

		$content     = $node_data['content'] ?? 'Default research query';
		$model       = $node_data['model'] ?? 'sonar';
		$temperature = floatval( $node_data['temperature'] ?? 1.0 );

		// Build additional parameters - ONLY include one type of penalty
		$additional_params = array(
			'searchContext'         => $node_data['searchContext'] ?? true,
			'citations'             => $node_data['citations'] ?? true,
			'searchDomainFilters'   => $node_data['searchDomainFilters'] ?? array(),
			'search_recency_filter' => $node_data['search_recency_filter'] ?? 'any',
			'citationQuality'       => $node_data['citationQuality'] ?? 'standard',
			'top_p'                 => floatval( $node_data['top_p'] ?? 1 ),
		);

		// Only add one type of penalty - prefer frequency_penalty if both are set
		if ( isset( $node_data['frequency_penalty'] ) && $node_data['frequency_penalty'] != 0 ) {
			$additional_params['frequency_penalty'] = floatval( $node_data['frequency_penalty'] );
		} elseif ( isset( $node_data['presence_penalty'] ) && $node_data['presence_penalty'] != 0 ) {
			$additional_params['presence_penalty'] = floatval( $node_data['presence_penalty'] );
		}

		$prompt = self::replace_input_tags( $content, $input_data );

		$response = WP_AI_Workflows_Utilities::call_perplexity_api(
			$prompt,
			$model,
			$temperature,
			$additional_params
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			WP_AI_Workflows_Utilities::debug_log( 'Error in Research node response', 'error', array( 'error_message' => $error_message ) );
			return self::create_node_data( 'error', 'Error: ' . $error_message );
		}

		try {
			if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
				throw new Exception( 'Unexpected API response structure' );
			}

			if ( isset( $response['usage'] ) ) {
				try {
					$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
					$cost         = $cost_manager->calculate_node_cost(
						$execution_id,
						$node['id'],
						'perplexity',
						$model,
						$response['usage']['prompt_tokens'] ?? 0,
						$response['usage']['completion_tokens'] ?? 0
					);
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Error calculating cost',
						'error',
						array(
							'error'   => $e->getMessage(),
							'node_id' => $node['id'],
						)
					);
				}
			}

			$output = array(
				'content'   => $response['choices'][0]['message']['content'],
				'citations' => $response['citations'] ?? array(),
			);

			if ( isset( $response['search_context'] ) ) {
				$output['search_context'] = $response['search_context'];
			}

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				'Executed Research node successfully',
				$node['id']
			);

			return self::create_node_data( 'research', $output );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error processing research response',
				'error',
				array(
					'error'    => $e->getMessage(),
					'response' => $response,
				)
			);
			return self::create_node_data( 'error', 'Error processing research response: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether the site owner lets nodes reach private, loopback and link-local addresses.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function private_network_allowed( $url ) {
		/**
		 * Allow nodes to reach private, loopback and link-local addresses.
		 *
		 * @param bool   $allow Default false.
		 * @param string $url   The URL the node wants to reach.
		 */
		return (bool) apply_filters( 'wp_ai_workflows_allow_private_network_requests', false, $url );
	}

	/**
	 * Whether a node may send an outbound request to this URL. Addresses only this
	 * server can reach are refused, so a workflow can never be pointed at the
	 * network behind the site. A site owner who needs one opts in with the
	 * `wp_ai_workflows_allow_private_network_requests` filter.
	 *
	 * @param string $url
	 * @return true|WP_Error
	 */
	public static function validate_outbound_url( $url ) {
		$url = trim( (string) $url );

		if ( '' !== $url && self::private_network_allowed( $url ) ) {
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( in_array( $scheme, array( 'http', 'https' ), true ) && wp_parse_url( $url, PHP_URL_HOST ) ) {
				return true;
			}
		}

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'invalid_url', 'Invalid URL provided.' );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return new WP_Error( 'invalid_url', 'Invalid URL provided.' );
		}

		foreach ( self::resolve_host_addresses( $host ) as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'blocked_url', 'This address is not allowed: ' . $host );
			}
		}

		return true;
	}

	/**
	 * Every address a host resolves to, including the host itself when it is
	 * already an IP literal.
	 *
	 * @param string $host
	 * @return array<int,string>
	 */
	private static function resolve_host_addresses( $host ) {
		$bare = trim( strtolower( $host ), '[]' );

		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return array( $bare );
		}

		$addresses = array();

		$ipv4 = gethostbynamel( $bare );
		if ( is_array( $ipv4 ) ) {
			$addresses = $ipv4;
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $bare, DNS_AAAA );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unresolvable name is handled below.
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		return $addresses;
	}

	public static function execute_firecrawl_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$firecrawl = new WP_AI_Workflows_Firecrawl();
		$data      = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		list( $operation, $params ) = self::firecrawl_build_params( $data, $input_data );

		if ( ! empty( $params['url'] ) ) {
			$allowed = self::validate_outbound_url( $params['url'] );
			if ( is_wp_error( $allowed ) ) {
				WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'error', $allowed->get_error_message(), $node['id'] );
				return self::create_node_data( 'error', $allowed->get_error_message() );
			}
		}

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Starting Firecrawl ' . $operation . ' operation', $node['id'] );

		switch ( $operation ) {
			case 'crawl':
				$result = $firecrawl->crawl( $params );
				break;
			case 'map':
				$result = $firecrawl->map( $params );
				break;
			case 'search':
				$result = $firecrawl->search( $params );
				break;
			case 'agent':
				$result = $firecrawl->agent( $params );
				break;
			case 'scrape':
			default:
				$result = $firecrawl->scrape( $params );
				break;
		}

		WP_AI_Workflows_Utilities::debug_log( 'Firecrawl node execution result', 'debug', array( 'result' => $result ) );

		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'error', 'Firecrawl operation failed: ' . $result->get_error_message(), $node['id'] );
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		if ( isset( $result['stats'] ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				sprintf(
					'Crawl operation finished, continuing workflow. Pages crawled: %d/%d',
					$result['stats']['completed'] ?? 0,
					$result['stats']['total'] ?? 0
				),
				$node['id']
			);
		} else {
			WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', ucfirst( $operation ) . ' operation finished, continuing workflow', $node['id'] );
		}

		if ( ! empty( $data['selectedPages'] ) && isset( $result['results'] ) && is_array( $result['results'] ) ) {
			$selected          = (array) $data['selectedPages'];
			$result['results'] = array_values(
				array_filter(
					$result['results'],
					function ( $page ) use ( $selected ) {
						return isset( $page['url'] ) && in_array( $page['url'], $selected, true );
					}
				)
			);
		}

		return self::create_node_data( 'firecrawl', $result );
	}

	/**
	 * Build the [ operation, params ] pair for a Firecrawl node, translating BOTH the
	 * new v2 node shape and the legacy v0/v1 shape into the flat params the v2 client
	 * expects. Keeps old saved workflows running without a hard break.
	 *
	 * @param array $data       Node `data`.
	 * @param array $input_data Upstream node outputs (for [Input from ...] tag resolution).
	 * @return array{0:string,1:array}
	 */
	private static function firecrawl_build_params( array $data, $input_data ) {
		$operation = isset( $data['operation'] ) ? (string) $data['operation'] : 'scrape';

		$resolve = function ( $value ) use ( $input_data ) {
			return is_string( $value ) ? self::replace_input_tags( $value, $input_data ) : $value;
		};

		// Legacy shape: a single `format` string and no `formats` array.
		if ( ! isset( $data['formats'] ) && isset( $data['format'] ) ) {
			return self::firecrawl_build_legacy_params( $data, $resolve );
		}

		$formats = isset( $data['formats'] ) && is_array( $data['formats'] ) ? $data['formats'] : array( 'markdown' );

		switch ( $operation ) {
			case 'crawl':
				$params = array(
					'url'               => $resolve( $data['url'] ?? '' ),
					'limit'             => $data['limit'] ?? '',
					'includePaths'      => $data['includePaths'] ?? array(),
					'excludePaths'      => $data['excludePaths'] ?? array(),
					'crawlEntireDomain' => ! empty( $data['crawlEntireDomain'] ),
					'sitemap'           => $data['sitemap'] ?? '',
					'maxDiscoveryDepth' => $data['maxDiscoveryDepth'] ?? '',
					'scrapeFormats'     => $formats,
					'onlyMainContent'   => $data['onlyMainContent'] ?? true,
					'waitFor'           => $data['waitFor'] ?? 0,
				);
				break;
			case 'map':
				$params = array(
					'url'               => $resolve( $data['url'] ?? '' ),
					'search'            => $resolve( $data['mapSearch'] ?? '' ),
					'limit'             => $data['mapLimit'] ?? '',
					'includeSubdomains' => ! empty( $data['includeSubdomains'] ),
					'sitemap'           => $data['sitemap'] ?? '',
				);
				break;
			case 'search':
				$params = array(
					'query'         => $resolve( $data['query'] ?? '' ),
					'sources'       => $data['sources'] ?? array( 'web' ),
					'categories'    => $data['categories'] ?? array(),
					'limit'         => $data['searchLimit'] ?? '',
					'location'      => $resolve( $data['location'] ?? '' ),
					'scrapeFormats' => ! empty( $data['searchScrape'] ) ? $formats : array(),
				);
				break;
			case 'agent':
				$params = array(
					'prompt' => $resolve( $data['agentPrompt'] ?? '' ),
					'schema' => self::firecrawl_fields_to_schema( $data['agentFields'] ?? array() ),
					'urls'   => array_map( $resolve, (array) ( $data['agentUrls'] ?? array() ) ),
				);
				break;
			case 'scrape':
			default:
				$operation = 'scrape';
				$params    = array(
					'url'             => $resolve( $data['url'] ?? '' ),
					'formats'         => $formats,
					'onlyMainContent' => $data['onlyMainContent'] ?? true,
					'waitFor'         => $data['waitFor'] ?? 0,
					'timeout'         => $data['timeout'] ?? 0,
					'mobile'          => ! empty( $data['isMobile'] ),
					'includeTags'     => $data['includeTags'] ?? array(),
					'excludeTags'     => $data['excludeTags'] ?? array(),
					'jsonPrompt'      => $resolve( $data['jsonPrompt'] ?? '' ),
					'jsonSchema'      => self::firecrawl_fields_to_schema( $data['jsonFields'] ?? array() ),
					'question'        => $resolve( $data['question'] ?? '' ),
				);
				break;
		}

		return array( $operation, $params );
	}

	/**
	 * Map the legacy v0/v1 Firecrawl node shape onto the flat v2 params. The old node
	 * only supported scrape + crawl with a single `format` (markdown|html|rawHtml|links|
	 * screenshot|extract); `extract` becomes the v2 `json` structured format.
	 *
	 * @param array    $data
	 * @param callable $resolve
	 * @return array{0:string,1:array}
	 */
	private static function firecrawl_build_legacy_params( array $data, $resolve ) {
		$operation = ( isset( $data['operation'] ) && 'crawl' === $data['operation'] ) ? 'crawl' : 'scrape';
		$format    = isset( $data['format'] ) ? (string) $data['format'] : 'markdown';

		$json_prompt = '';
		$json_schema = array();
		if ( 'extract' === $format ) {
			$formats = array( 'json' );
			if ( isset( $data['extractType'] ) && 'prompt' === $data['extractType'] ) {
				$json_prompt = $resolve( $data['extractPrompt'] ?? '' );
			} else {
				$json_schema = self::firecrawl_fields_to_schema( $data['extractFields'] ?? array() );
			}
		} else {
			$formats = array( $format );
		}

		if ( 'crawl' === $operation ) {
			$params = array(
				'url'               => $resolve( $data['url'] ?? '' ),
				'limit'             => $data['limit'] ?? '',
				'crawlEntireDomain' => ! empty( $data['allowBackwardLinks'] ) || ! empty( $data['allowExternalLinks'] ),
				'sitemap'           => ! empty( $data['ignoreSitemap'] ) ? 'skip' : 'include',
				'maxDiscoveryDepth' => $data['maxDepth'] ?? '',
				'scrapeFormats'     => $formats,
				'onlyMainContent'   => $data['onlyMainContent'] ?? true,
				'waitFor'           => $data['waitFor'] ?? 0,
			);
		} else {
			$params = array(
				'url'             => $resolve( $data['url'] ?? '' ),
				'formats'         => $formats,
				'onlyMainContent' => $data['onlyMainContent'] ?? true,
				'waitFor'         => $data['waitFor'] ?? 0,
				'timeout'         => $data['timeout'] ?? 0,
				'mobile'          => ! empty( $data['isMobile'] ),
				'includeTags'     => $data['includeTags'] ?? array(),
				'excludeTags'     => $data['excludeTags'] ?? array(),
				'jsonPrompt'      => $json_prompt,
				'jsonSchema'      => $json_schema,
			);
		}

		return array( $operation, $params );
	}

	/**
	 * Convert a [ { name, type }, ... ] field list (used by the scrape JSON format and
	 * the Agent operation) into a minimal JSON Schema object. Returns [] when empty so
	 * the caller can omit the schema entirely.
	 *
	 * @param mixed $fields
	 * @return array
	 */
	private static function firecrawl_fields_to_schema( $fields ) {
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return array();
		}
		$properties = array();
		$required   = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $field['name'] );
			if ( '' === $name ) {
				continue;
			}
			$type                = ( isset( $field['type'] ) && in_array( $field['type'], array( 'string', 'number', 'boolean', 'array', 'object' ), true ) ) ? $field['type'] : 'string';
			$properties[ $name ] = array( 'type' => $type );
			$required[]          = $name;
		}
		if ( empty( $properties ) ) {
			return array();
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}

	public static function execute_parser_node( $node, $node_data, $edges, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$input_type      = isset( $node['data']['inputType'] ) ? $node['data']['inputType'] : 'link';
		$parser_settings = isset( $node['data']['parserSettings'] ) ? $node['data']['parserSettings'] : array();

		if ( $input_type === 'link' ) {
			$document_url = isset( $node['data']['documentLink'] ) ? $node['data']['documentLink'] : '';
			$parsed_content = WP_AI_Workflows_Parser::parse_document_with_llamaparse( $document_url, $parser_settings );

			if ( is_wp_error( $parsed_content ) ) {
				return self::create_node_data( 'error', $parsed_content->get_error_message() );
			}

			return self::create_node_data( 'parser', $parsed_content );
		} else {
			$parsed_contents = isset( $node['data']['parsedContents'] ) ? $node['data']['parsedContents'] : array();
			if ( empty( $parsed_contents ) ) {
				return self::create_node_data( 'error', 'No parsed content available for uploaded files' );
			}

			return self::create_node_data( 'parser', $parsed_contents );
		}
	}

	public static function execute_unsplash_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$api_key = WP_AI_Workflows_Utilities::get_unsplash_api_key();
		if ( empty( $api_key ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Unsplash API key missing', 'error' );
			return self::create_node_data( 'error', 'Unsplash API key is not set' );
		}

		$search_term   = isset( $node['data']['searchTerm'] ) ? $node['data']['searchTerm'] : '';
		$image_size    = isset( $node['data']['imageSize'] ) ? $node['data']['imageSize'] : 'regular';
		$orientation   = isset( $node['data']['orientation'] ) ? $node['data']['orientation'] : 'all';
		$random_result = isset( $node['data']['randomResult'] ) && $node['data']['randomResult'];

		$search_term = self::replace_input_tags( $search_term, $input_data );

		if ( empty( $search_term ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Empty search term', 'error' );
			return self::create_node_data( 'error', 'Search term is required' );
		}

		$query_params = array(
			'query'    => $search_term,
			'per_page' => $random_result ? 10 : 1,
		);

		if ( $orientation !== 'all' ) {
			$query_params['orientation'] = $orientation;
		}

		$url = add_query_arg( $query_params, 'https://api.unsplash.com/search/photos' );

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Unsplash API request failed',
				'error',
				array(
					'error' => $response->get_error_message(),
				)
			);
			return self::create_node_data( 'error', 'Failed to fetch image: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code === 429 ) {
			WP_AI_Workflows_Utilities::debug_log( 'Unsplash rate limit exceeded', 'error' );
			return self::create_node_data( 'error', 'Rate limit exceeded' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $response_code !== 200 || empty( $body['results'] ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Unsplash API error',
				'error',
				array(
					'response_code' => $response_code,
					'body'          => $body,
				)
			);
			return self::create_node_data( 'error', 'Failed to fetch image from Unsplash' );
		}

		$result = $random_result ?
		$body['results'][ array_rand( $body['results'] ) ] :
		$body['results'][0];

		$image_url = isset( $result['urls'][ $image_size ] ) ?
		$result['urls'][ $image_size ] :
		$result['urls']['regular']; // fallback to regular size

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Fetched image from Unsplash' );

		return self::create_node_data( 'unsplash', $image_url );
	}

	/**
	 * Generate PDF node - cloud-rendered, credit-metered.
	 *
	 * PHP cannot run a headless Chromium locally, so this node NEVER renders on the
	 * WordPress site. It assembles a render request from the node config (a built-in
	 * template + mapped data, or custom HTML) and forwards it to the platform's PDF
	 * render service (POST /api/v1/pdf/generate) authenticated with the site key.
	 * The returned public fileUrl becomes the node's output so a downstream Output /
	 * Send Email node can attach or link it.
	 *
	 * A connected + funded platform account is REQUIRED. When the site is not
	 * connected we fail with a clear, actionable message (sign up / connect + fund
	 * credits) instead of a cryptic error; a 402 from the service surfaces as an
	 * out-of-credits message (see WP_AI_Workflows_Platform_Client::map_response).
	 *
	 * @param array  $node         Node definition (data holds the config).
	 * @param mixed  $input_data   Upstream node outputs (for variable resolution).
	 * @param int    $execution_id Current execution id (for status updates).
	 * @return array create_node_data result whose `content` is the rendered fileUrl.
	 */
	public static function execute_generate_pdf_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		if ( ! class_exists( 'WP_AI_Workflows_Platform_Client' ) || ! WP_AI_Workflows_Platform_Client::is_connected() ) {
			return self::create_node_data(
				'error',
				'PDF generation runs on the cloud render service and is metered in credits. Connect a free account (Settings → Account) and add credits to use the Generate PDF node.'
			);
		}

		$data        = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
		$source_mode = isset( $data['sourceMode'] ) ? sanitize_key( $data['sourceMode'] ) : 'template';

		$body = array();

		if ( 'custom' === $source_mode ) {
			$html = isset( $data['customHtml'] ) ? (string) $data['customHtml'] : '';
			$html = self::replace_input_tags( $html, $input_data );
			if ( '' === trim( $html ) ) {
				return self::create_node_data( 'error', 'Custom HTML is required when the Generate PDF node is in Custom mode.' );
			}
			$body['template'] = 'custom';
			$body['html']     = $html;
		} else {
			$template = isset( $data['template'] ) ? sanitize_text_field( $data['template'] ) : '';
			if ( '' === $template ) {
				return self::create_node_data( 'error', 'Please choose a PDF template.' );
			}
			$body['template'] = $template;
			$body['data']     = self::build_pdf_template_data( $data, $input_data );
		}

		$options = array();
		$paper   = isset( $data['paper'] ) ? sanitize_text_field( $data['paper'] ) : '';
		if ( 'A4' === $paper || 'Letter' === $paper ) {
			$options['paper'] = $paper;
		}
		if ( isset( $data['landscape'] ) ) {
			$options['landscape'] = (bool) $data['landscape'];
		}
		$file_name = isset( $data['fileName'] ) ? (string) $data['fileName'] : '';
		$file_name = trim( self::replace_input_tags( $file_name, $input_data ) );
		if ( '' !== $file_name ) {
			$options['fileName'] = sanitize_file_name( $file_name );
		}
		if ( ! empty( $options ) ) {
			$body['options'] = $options;
		}

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'Rendering PDF in the cloud' );

		$result = WP_AI_Workflows_Platform_Client::render_pdf( $body );
		if ( is_wp_error( $result ) ) {
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		$file_url = isset( $result['fileUrl'] ) ? esc_url_raw( (string) $result['fileUrl'] ) : '';
		if ( '' === $file_url ) {
			return self::create_node_data( 'error', 'The PDF render service did not return a file URL.' );
		}
		$file_id = isset( $result['fileId'] ) ? sanitize_text_field( (string) $result['fileId'] ) : '';

		WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'PDF generated' );

		$saved = self::save_pdf_to_media_library( $file_id, isset( $options['fileName'] ) ? $options['fileName'] : '' );

		$local_url = isset( $saved['url'] ) ? (string) $saved['url'] : '';
		$output    = self::create_node_data( 'generatePdf', '' !== $local_url ? $local_url : $file_url );

		$output['pdf'] = array(
			'fileUrl'        => '' !== $local_url ? $local_url : $file_url,
			'platformUrl'    => $file_url,
			'attachmentId'   => isset( $saved['id'] ) ? (int) $saved['id'] : 0,
			'attachmentUrl'  => $local_url,
			'fileId'         => $file_id,
			'pages'          => isset( $result['pages'] ) ? (int) $result['pages'] : 0,
			'bytes'          => isset( $result['bytes'] ) ? (int) $result['bytes'] : 0,
			'creditsCharged' => isset( $result['creditsCharged'] ) ? (float) $result['creditsCharged'] : 0,
		);
		return $output;
	}

	/**
	 * Put the rendered PDF in the media library. A failure here is not a run
	 * failure: the step keeps the platform URL and the reason is logged.
	 *
	 * @param string $file_id   Platform file id from render_pdf()'s `fileId`.
	 * @param string $file_name Configured file name, used for the attachment's title.
	 * @return array{id:int,url:string} Zero id and empty url when nothing was saved.
	 */
	private static function save_pdf_to_media_library( $file_id, $file_name = '' ) {
		$none = array(
			'id'  => 0,
			'url' => '',
		);

		if ( '' === (string) $file_id ) {
			return $none;
		}

		$bytes = WP_AI_Workflows_Platform_Client::fetch_pdf_bytes( $file_id );
		if ( is_wp_error( $bytes ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Rendered PDF could not be fetched for the media library',
				'warning',
				array( 'error' => $bytes->get_error_message() )
			);
			return $none;
		}

		$name = sanitize_file_name( (string) $file_name );
		if ( '' === $name ) {
			$name = 'document-' . gmdate( 'Ymd-His' );
		}
		$name = preg_replace( '/\.pdf$/i', '', $name ) . '.pdf';

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		add_filter( 'upload_mimes', array( __CLASS__, 'allow_pdf_type' ), 10, 1 );
		$upload = wp_upload_bits( $name, null, $bytes );
		remove_filter( 'upload_mimes', array( __CLASS__, 'allow_pdf_type' ) );

		if ( ! empty( $upload['error'] ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Rendered PDF could not be written to the uploads directory',
				'warning',
				array( 'error' => $upload['error'] )
			);
			return $none;
		}

		$attachment    = array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => preg_replace( '/\.pdf$/i', '', $name ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		);
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Rendered PDF could not be saved to the media library',
				'warning',
				array( 'error' => is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'no attachment id' )
			);
			return $none;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		$url = wp_get_attachment_url( (int) $attachment_id );

		return array(
			'id'  => (int) $attachment_id,
			'url' => is_string( $url ) ? $url : '',
		);
	}

	/**
	 * Turn the node's flat `templateData` map (field dot-path => raw value) into the
	 * nested `data` object the render templates expect. Variable tags are resolved
	 * against the upstream node outputs; a value that parses as JSON (e.g. a mapped
	 * array/object field) is decoded, otherwise it is kept as a string.
	 *
	 * @param array $data       Node data.
	 * @param mixed $input_data Upstream outputs for tag resolution.
	 * @return array Nested data object.
	 */
	private static function build_pdf_template_data( $data, $input_data ) {
		$map = isset( $data['templateData'] ) && is_array( $data['templateData'] ) ? $data['templateData'] : array();
		$out = array();

		foreach ( $map as $path => $raw ) {
			$path = (string) $path;
			if ( '' === $path ) {
				continue;
			}
			$value = is_string( $raw ) ? self::replace_input_tags( $raw, $input_data ) : $raw;

			// If the resolved value looks like JSON (array/object), decode it so
			// array/object template fields (e.g. invoice line items) work.
			if ( is_string( $value ) ) {
				$trimmed = trim( $value );
				if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
					$decoded = json_decode( $trimmed, true );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$value = $decoded;
					}
				}
			}

			$keys   = explode( '.', $path );
			$cursor = &$out;
			foreach ( $keys as $i => $key ) {
				if ( $i === count( $keys ) - 1 ) {
					$cursor[ $key ] = $value;
				} else {
					if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
						$cursor[ $key ] = array();
					}
					$cursor = &$cursor[ $key ];
				}
			}
			unset( $cursor );
		}

		return $out;
	}

	public static function execute_chat_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_log(
			'Executing chat node',
			'debug',
			array(
				'node'       => $node,
				'input_data' => $input_data,
			)
		);

		$settings      = $node['data'];
		$model         = $settings['model'] ?? 'anthropic/claude-sonnet-5';
		$system_prompt = $settings['systemPrompt'] ?? '';

		$system_prompt = self::replace_input_tags( $system_prompt, $input_data );

		$settings['systemPrompt'] = $system_prompt;

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Configuring chat node',
			$node['id']
		);

		WP_AI_Workflows_Utilities::debug_log(
			'Chat node processed',
			'debug',
			array(
				'system_prompt' => $system_prompt,
				'model'         => $model,
			)
		);

		return array(
			'type'    => 'chat',
			'content' => array(
				'model'        => $model,
				'systemPrompt' => $system_prompt,
				'settings'     => $settings,
			),
		);
	}


	public static function execute_rss_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
			)
		);

		$settings = $node['data']['rssSettings'];
		$feed_url = $settings['feedUrl'];

		if ( empty( $feed_url ) ) {
			return self::create_node_data( 'error', 'RSS feed URL is required' );
		}

		try {
			include_once ABSPATH . WPINC . '/feed.php';
			$rss = fetch_feed( $feed_url );

			if ( is_wp_error( $rss ) ) {
				throw new Exception( $rss->get_error_message() );
			}

			$maxitems = $settings['maxItems'] ?? 10;
			$items    = $rss->get_items( 0, $maxitems );

			$filtered_items = array_filter(
				$items,
				function ( $item ) use ( $settings ) {
					if ( ! empty( $settings['filters']['title'] ) &&
					stripos( $item->get_title(), $settings['filters']['title'] ) === false ) {
						return false;
					}

					if ( ! empty( $settings['filters']['content'] ) ) {
						$content = $settings['includeContent'] ?
						$item->get_content() : $item->get_description();
						if ( stripos( $content, $settings['filters']['content'] ) === false ) {
							return false;
						}
					}

					return true;
				}
			);

			$formatted_items = array_map(
				function ( $item ) use ( $settings ) {
					$data = array(
						'title'       => $item->get_title() ?: '',
						'link'        => $item->get_permalink() ?: '',
						'description' => wp_strip_all_tags( $item->get_description() ?: '' ),
						'pubDate'     => $item->get_date( 'Y-m-d H:i:s' ) ?: '',
						'author'      => $item->get_author() ? $item->get_author()->get_name() : '',
					);

					$enclosure = $item->get_enclosure();
					if ( $enclosure ) {
						$data['media_url']    = $enclosure->get_link();
						$data['media_type']   = $enclosure->get_type();
						$data['media_length'] = $enclosure->get_length();
					}

					$categories = $item->get_categories();
					if ( $categories ) {
						$data['categories'] = array_map(
							function ( $cat ) {
								return $cat->get_label();
							},
							$categories
						);
					} else {
						$data['categories'] = array();
					}

					if ( $settings['includeContent'] ) {
						$data['content'] = $item->get_content();
					}

					$additional = $item->get_item_tags( '', '' );
					if ( $additional ) {
						foreach ( $additional as $key => $value ) {
							if ( ! isset( $data[ $key ] ) ) {
								$data[ $key ] = $value;
							}
						}
					}

					if ( ! $settings['includeContent'] ) {
						$data['description'] = wp_strip_all_tags( $data['description'] );
						if ( isset( $data['content'] ) ) {
							$data['content'] = wp_strip_all_tags( $data['content'] );
						}
					}

					array_walk_recursive(
						$data,
						function ( &$value ) {
							if ( is_array( $value ) ) {
								$value = implode( ', ', $value );
							} elseif ( ! is_string( $value ) && ! is_numeric( $value ) ) {
								$value = strval( $value );
							}
						}
					);

					return $data;
				},
				$filtered_items
			);

			$output_data = array(
				'items'            => $formatted_items,
				'latest'           => ! empty( $formatted_items ) ? reset( $formatted_items ) : null,
				'feed_title'       => $rss->get_title(),
				'feed_description' => $rss->get_description(),
				'feed_link'        => $rss->get_permalink(),
				'total_items'      => count( $formatted_items ),
			);

			WP_AI_Workflows_Utilities::update_execution_status( $execution_id, 'processing', 'RSS feed processed' );
			return self::create_node_data( 'rss', $output_data );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'RSS feed error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return self::create_node_data( 'error', 'RSS feed error: ' . $e->getMessage() );
		}
	}

	/**
	 * Execute the Multimedia Generator node
	 *
	 * @param array $node The node configuration
	 * @param array $input_data Input data from connected nodes
	 * @param int $execution_id Current execution ID
	 * @return array Result data
	 */
	public static function execute_multimedia_generator_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
			)
		);

		$node_id   = $node['id'];
		$node_data = isset( $node['data'] ) ? $node['data'] : array();

		$model_type     = isset( $node_data['modelType'] ) ? $node_data['modelType'] : 'textToImage';
		$selected_model = isset( $node_data['selectedModel'] ) ? $node_data['selectedModel'] : '';

		if ( empty( $selected_model ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'No model selected',
				'error',
				array(
					'node_id' => $node_id,
				)
			);
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'No multimedia generation model selected',
				$node_id
			);
			return self::create_node_data( 'error', 'No model selected' );
		}

		$multimedia_generator = new WP_AI_Workflows_Multimedia_Generator();

		try {
			// NEW (scalable) path: if the node carries dynamic OpenAPI-driven field
			// values, submit them generically to the selected model. This is the
			// primary path for nodes built with the dynamic picker.
			$field_values = isset( $node_data['fieldValues'] ) ? $node_data['fieldValues'] : null;
			if ( is_array( $field_values ) && ! empty( $field_values ) ) {
				return self::execute_dynamic_media_generation(
					$node_data,
					$input_data,
					$multimedia_generator,
					$execution_id
				);
			}

			// LEGACY path (backward compat): existing nodes with hardcoded model +
			// per-mode params still run exactly as before.
			if ( $model_type === 'textToImage' ) {
				return self::execute_text_to_image_generation(
					$node_data,
					$input_data,
					$multimedia_generator,
					$execution_id
				);
			} elseif ( $model_type === 'imageToVideo' ) {
				return self::execute_image_to_video_generation(
					$node_data,
					$input_data,
					$multimedia_generator,
					$execution_id
				);
			} elseif ( $model_type === 'textToVideo' ) {
				return self::execute_text_to_video_generation(
					$node_data,
					$input_data,
					$multimedia_generator,
					$execution_id
				);
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Invalid model type',
				'error',
				array(
					'node_id'    => $node_id,
					'model_type' => $model_type,
				)
			);
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Invalid multimedia generation type: ' . $model_type,
				$node_id
			);
			return self::create_node_data( 'error', 'Invalid model type: ' . $model_type );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in multimedia generation',
				'error',
				array(
					'node_id' => $node_id,
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Multimedia generation error: ' . $e->getMessage(),
				$node_id
			);
			return self::create_node_data( 'error', 'Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Execute MCP Client node
	 *
	 * @param array $node Node configuration
	 * @param array $input_data Input data from connected nodes
	 * @param int $execution_id Current execution ID
	 * @return array Result data
	 */
	public static function execute_mcp_client_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
				'server_type'  => $node['data']['serverType'] ?? 'unknown',
			)
		);

		try {
			// Pipedream "Connect an App" node: saves { appSlug, toolName, toolConfig,
			// appName, ... } with no serverType. A node carrying a serverType is a
			// legacy "connect to an MCP server" node and falls through below.
			if ( ! empty( $node['data']['appSlug'] ) && empty( $node['data']['serverType'] ) ) {
				return self::execute_apps_node( $node, $input_data, $execution_id );
			}

			if ( empty( $node['data']['serverType'] ) ) {
				WP_AI_Workflows_Utilities::update_execution_status(
					$execution_id,
					'error',
					'MCP server type is required',
					$node['id']
				);
				return self::create_node_data( 'error', 'MCP server type is required' );
			}

			if ( empty( $node['data']['selectedTool'] ) ) {
				WP_AI_Workflows_Utilities::update_execution_status(
					$execution_id,
					'error',
					'No tool selected for MCP node',
					$node['id']
				);
				return self::create_node_data( 'error', 'No tool selected for MCP node' );
			}

			$server_type          = $node['data']['serverType'];
			$selected_tool        = $node['data']['selectedTool'];
			$connection_config    = $node['data']['connectionConfig'] ?? array();
			$custom_server_config = $node['data']['customServerConfig'] ?? null;
			$tool_parameters_raw  = $node['data']['toolParameters'] ?? array();

			$tool_parameters = array();
			foreach ( $tool_parameters_raw as $param ) {
				if ( ! empty( $param['name'] ) ) {
					$value = $param['value'] ?? '';

					$processed_value = self::replace_input_tags( $value, $input_data );

					if ( ( strpos( $processed_value, '[' ) === 0 || strpos( $processed_value, '{' ) === 0 ) ) {
						$json_decoded = json_decode( $processed_value, true );
						if ( json_last_error() === JSON_ERROR_NONE ) {
							$processed_value = $json_decoded;
						}
					}

					$tool_parameters[ $param['name'] ] = $processed_value;
				}
			}

			WP_AI_Workflows_Utilities::debug_log(
				'MCP node parameters processed',
				'debug',
				array(
					'node_id'              => $node['id'],
					'tool'                 => $selected_tool,
					'raw_parameters'       => $tool_parameters_raw,
					'processed_parameters' => $tool_parameters,
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				"Executing MCP tool: {$selected_tool}",
				$node['id']
			);

			$server_config = array(
				'serverType'         => $server_type,
				'connectionConfig'   => $connection_config,
				'customServerConfig' => $custom_server_config,
			);

			$result = WP_AI_Workflows_MCP_Client::execute_tool(
				$server_config,
				$selected_tool,
				$tool_parameters
			);

			if ( isset( $result['type'] ) && $result['type'] === 'error' ) {
				WP_AI_Workflows_Utilities::update_execution_status(
					$execution_id,
					'error',
					'MCP tool execution failed: ' . $result['content'],
					$node['id']
				);
				return self::create_node_data( 'error', $result['content'] );
			}

			WP_AI_Workflows_Utilities::debug_log(
				'MCP tool executed successfully',
				'info',
				array(
					'node_id'     => $node['id'],
					'tool'        => $selected_tool,
					'result_type' => $result['type'] ?? 'unknown',
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				"MCP tool {$selected_tool} completed successfully",
				$node['id']
			);

			// The MCP Client already returns in the format: ['type' => 'mcpClient', 'content' => ...]
			return $result;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'MCP node execution failed',
				'error',
				array(
					'node_id' => $node['id'],
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'MCP execution error: ' . $e->getMessage(),
				$node['id']
			);

			return self::create_node_data( 'error', 'MCP execution failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Execute a Pipedream "Connect an App" node (the rebuilt Apps node).
	 *
	 * The action runs on the PLATFORM (Pipedream + the org's connected accounts live
	 * there; local PHP cannot and must not run it). We resolve variable tags in the
	 * saved tool config, then call the metered `/api/v1/mcp/actions/run` endpoint via
	 * the site-key client - which reserves + settles exactly 1 credit against the org
	 * resolved server-side from THIS site's key, so a local run bills identically to a
	 * cloud run. The result is returned in the legacy-compatible mcpClient shape so
	 * downstream nodes and `{{variables}}` from this node keep working.
	 *
	 * @param array $node         Node configuration.
	 * @param array $input_data   Upstream node outputs.
	 * @param int   $execution_id Current execution ID.
	 * @return array Node result data.
	 */
	private static function execute_apps_node( $node, $input_data, $execution_id ) {
		$data      = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
		$app_slug  = isset( $data['appSlug'] ) ? (string) $data['appSlug'] : '';
		$tool_name = isset( $data['toolName'] ) ? (string) $data['toolName'] : '';
		$app_label = ! empty( $data['appName'] )
			? (string) $data['appName']
			: ( '' !== $app_slug ? $app_slug : 'Connect an App' );

		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
				'app_slug'     => $app_slug,
				'tool_name'    => $tool_name,
			)
		);

		if ( '' === $tool_name ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'No action selected for the "' . $app_label . '" app',
				$node['id']
			);
			return self::create_node_data( 'error', 'No action selected for this app node.' );
		}

		$tool_config = isset( $data['toolConfig'] ) && is_array( $data['toolConfig'] ) ? $data['toolConfig'] : array();
		$resolved    = self::replace_input_tags_recursive( $tool_config, $input_data );

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Running ' . $app_label . ' action',
			$node['id']
		);

		// Execute server-side via the platform (metered: reserves + settles 1 credit).
		$response = WP_AI_Workflows_Platform_Client::apps_execute( $app_slug, $tool_name, $resolved );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WP_AI_Workflows_Utilities::debug_log(
				'Apps node execution failed',
				'error',
				array(
					'node_id' => $node['id'],
					'app'     => $app_slug,
					'tool'    => $tool_name,
					'code'    => $response->get_error_code(),
					'error'   => $msg,
				)
			);
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				$app_label . ' action failed: ' . $msg,
				$node['id']
			);
			return self::create_node_data( 'error', $msg );
		}

		// Platform returns { success, data, creditsCharged, balanceAfter }, where
		// `data` is the executeToolCall envelope { success, data: {app,tool,result,…} }.
		// Surface the inner action payload as the node content.
		$payload = isset( $response['data'] ) ? $response['data'] : array();
		$content = ( is_array( $payload ) && isset( $payload['data'] ) ) ? $payload['data'] : $payload;

		WP_AI_Workflows_Utilities::debug_log(
			'Apps node executed successfully',
			'info',
			array(
				'node_id'         => $node['id'],
				'app'             => $app_slug,
				'tool'            => $tool_name,
				'credits_charged' => isset( $response['creditsCharged'] ) ? $response['creditsCharged'] : null,
				'balance_after'   => isset( $response['balanceAfter'] ) ? $response['balanceAfter'] : null,
			)
		);

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			$app_label . ' action completed successfully',
			$node['id']
		);

		return self::create_node_data( 'mcpClient', $content );
	}

	/**
	 * Execute a dynamic (OpenAPI-driven) media generation. The node's `fieldValues`
	 * map is passed generically to the selected Fal model - no per-model parameter
	 * logic. Variable tags inside string values are resolved against upstream input.
	 *
	 * @param array $node_data Node configuration data.
	 * @param array $input_data Input data from connected nodes.
	 * @param WP_AI_Workflows_Multimedia_Generator $multimedia_generator Generator instance.
	 * @param int $execution_id Current execution ID.
	 * @return array Result data.
	 */
	private static function execute_dynamic_media_generation( $node_data, $input_data, $multimedia_generator, $execution_id ) {
		$model  = isset( $node_data['selectedModel'] ) ? $node_data['selectedModel'] : '';
		$inputs = isset( $node_data['fieldValues'] ) && is_array( $node_data['fieldValues'] ) ? $node_data['fieldValues'] : array();

		$inputs = self::resolve_media_inputs( $inputs, $input_data );

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Generating media with ' . $model,
			$node_data['id']
		);

		$result = $multimedia_generator->submit_generation( $model, $inputs );

		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Media generation failed: ' . $result->get_error_message(),
				$node_data['id']
			);
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		// Best-effort cost tracking (known legacy models only; unknown models skip).
		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost = $multimedia_generator->estimate_cost( $model, 'image', 1 );
			if ( $cost !== null ) {
				$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,
					$node_data['id'],
					1,
					'image'
				);
			}
		}

		$media = $multimedia_generator->extract_media_outputs( $result );
		$urls  = isset( $media['urls'] ) ? $media['urls'] : array();

		if ( empty( $urls ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'No media URL in dynamic generation result',
				'warning',
				array(
					'execution_id' => $execution_id,
					'model'        => $model,
				)
			);
			$output_data = 'No media URL found in result';
		} elseif ( count( $urls ) === 1 ) {
			$output_data = $urls[0];
		} else {
			$output_data = wp_json_encode( $urls );
		}

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Media generation successful',
			$node_data['id']
		);

		return self::create_node_data( 'multimediaGenerator', $output_data );
	}

	/**
	 * Recursively resolve upstream variable tags inside a media input map's string
	 * values, dropping empty-string entries so optional fields left blank are not
	 * sent to the model.
	 *
	 * @param array $inputs Field values.
	 * @param array $input_data Upstream input data.
	 * @return array
	 */
	private static function resolve_media_inputs( $inputs, $input_data ) {
		$out = array();
		foreach ( $inputs as $key => $value ) {
			if ( is_array( $value ) ) {
				$resolved = self::resolve_media_inputs( $value, $input_data );
				if ( ! empty( $resolved ) ) {
					$out[ $key ] = $resolved;
				}
			} elseif ( is_string( $value ) ) {
				$resolved = self::replace_input_tags( $value, $input_data );
				if ( '' !== trim( $resolved ) ) {
					$out[ $key ] = $resolved;
				}
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Execute text-to-image generation
	 *
	 * @param array $node_data Node configuration data
	 * @param array $input_data Input data from connected nodes
	 * @param WP_AI_Workflows_Multimedia_Generator $multimedia_generator Generator instance
	 * @param int $execution_id Current execution ID
	 * @return array Result data
	 */
	private static function execute_text_to_image_generation( $node_data, $input_data, $multimedia_generator, $execution_id ) {
		$model           = isset( $node_data['selectedModel'] ) ? $node_data['selectedModel'] : '';
		$prompt          = isset( $node_data['prompt'] ) ? self::replace_input_tags( $node_data['prompt'], $input_data ) : '';
		$negative_prompt = isset( $node_data['negativePrompt'] )
		? self::replace_input_tags( $node_data['negativePrompt'], $input_data )
		: '';

		if ( empty( $prompt ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Prompt is required for text-to-image generation',
				$node_data['id']
			);
			return self::create_node_data( 'error', 'Prompt is required for text-to-image generation' );
		}

		$num_inference_steps = isset( $node_data['numInferenceSteps'] ) ? intval( $node_data['numInferenceSteps'] ) : 16;
		$seed                = isset( $node_data['seed'] ) && $node_data['seed'] !== -1 ? intval( $node_data['seed'] ) : null;
		$num_images          = isset( $node_data['numImages'] ) ? intval( $node_data['numImages'] ) : 1;

		$image_size = isset( $node_data['imageSize'] ) ? $node_data['imageSize'] : array(
			'width'  => 1024,
			'height' => 1024,
		);

		$output_format = isset( $node_data['outputFormat'] ) ? $node_data['outputFormat'] : 'jpeg';

		$params = array(
			'model'               => $model,
			'prompt'              => $prompt,
			'negative_prompt'     => $negative_prompt,
			'num_inference_steps' => $num_inference_steps,
			'seed'                => $seed,
			'image_size'          => $image_size,
			'num_images'          => $num_images,
			'output_format'       => $output_format,
		);

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Generating image with ' . $model,
			$node_data['id']
		);

		$result = $multimedia_generator->generate_image( $params );

		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Image generation failed: ' . $result->get_error_message(),
				$node_data['id']
			);
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$cost         = $multimedia_generator->estimate_cost( $model, 'image', $num_images );

			if ( $cost !== null ) {
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,  // Pass the execution ID
					$node_data['id'], // Pass the node ID
					$num_images,
					'image'
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Image generation cost tracked',
					'info',
					array(
						'execution_id' => $execution_id,
						'model'        => $model,
						'cost'         => $cost,
						'num_images'   => $num_images,
					)
				);
			}
		}

		$output_data = '';

		$node_data['result'] = $result;

		if ( ! empty( $result['images'] ) && is_array( $result['images'] ) && ! empty( $result['images'][0]['url'] ) ) {
			if ( count( $result['images'] ) === 1 ) {
				$output_data = $result['images'][0]['url'];
			} else {
				$image_urls  = array_map(
					function ( $img ) {
						return $img['url'];
					},
					$result['images']
				);
				$output_data = wp_json_encode( $image_urls );
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Image generation URL extracted',
				'info',
				array(
					'execution_id' => $execution_id,
					'url'          => ( is_array( $output_data ) ? 'Multiple URLs' : $output_data ),
				)
			);
		} else {
			WP_AI_Workflows_Utilities::debug_log(
				'No image URLs in result',
				'warning',
				array(
					'execution_id' => $execution_id,
					'result'       => $result,
				)
			);
			$output_data = 'No image URL found in result';
		}

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Image generation successful',
			$node_data['id']
		);

		return self::create_node_data( 'multimediaGenerator', $output_data );
	}

	/**
	 * Execute image-to-video generation
	 *
	 * @param array $node_data Node configuration data
	 * @param array $input_data Input data from connected nodes
	 * @param WP_AI_Workflows_Multimedia_Generator $multimedia_generator Generator instance
	 * @param int $execution_id Current execution ID
	 * @return array Result data
	 */
	private static function execute_image_to_video_generation( $node_data, $input_data, $multimedia_generator, $execution_id ) {
		$model        = isset( $node_data['selectedModel'] ) ? $node_data['selectedModel'] : '';
		$image_url    = isset( $node_data['imageUrl'] ) ? self::replace_input_tags( $node_data['imageUrl'], $input_data ) : '';
		$prompt       = isset( $node_data['prompt'] ) ? self::replace_input_tags( $node_data['prompt'], $input_data ) : '';
		$video_length = isset( $node_data['videoLength'] ) ? intval( $node_data['videoLength'] ) : 5;

		if ( empty( $image_url ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Image URL is required for image-to-video generation',
				$node_data['id']
			);
			return self::create_node_data( 'error', 'Image URL is required for image-to-video generation' );
		}

		$params = array(
			'model'        => $model,
			'image_url'    => $image_url,
			'prompt'       => $prompt,
			'video_length' => $video_length,
		);

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Converting image to video with ' . $model,
			$node_data['id']
		);

		$result = $multimedia_generator->generate_video( $params );

		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Video generation failed: ' . $result->get_error_message(),
				$node_data['id']
			);
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$cost         = $multimedia_generator->estimate_cost( $model, 'video', $video_length );

			if ( $cost !== null ) {
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,  // Pass the execution ID
					$node_data['id'], // Pass the node ID
					1, // Always 1 video
					'video'
				);

				WP_AI_Workflows_Utilities::debug_log(
					'Video generation cost tracked',
					'info',
					array(
						'execution_id' => $execution_id,
						'model'        => $model,
						'cost'         => $cost,
					)
				);
			}
		}

		$node_data['result'] = $result;

		$output_data = '';

		if ( ! empty( $result['video'] ) && ! empty( $result['video']['url'] ) ) {
			$output_data = $result['video']['url'];

			WP_AI_Workflows_Utilities::debug_log(
				'Video generation URL extracted',
				'info',
				array(
					'execution_id' => $execution_id,
					'url'          => $output_data,
				)
			);
		} else {
			WP_AI_Workflows_Utilities::debug_log(
				'No video URL in result',
				'warning',
				array(
					'execution_id' => $execution_id,
					'result'       => $result,
				)
			);
			$output_data = 'No video URL found in result';
		}

		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Video generation successful',
			$node_data['id']
		);

		return self::create_node_data( 'multimediaGenerator', $output_data );
	}

	/**
	 * Execute text-to-video generation
	 *
	 * @param array $node_data Node configuration data
	 * @param array $input_data Input data from connected nodes
	 * @param WP_AI_Workflows_Multimedia_Generator $multimedia_generator Generator instance
	 * @param int $execution_id Current execution ID
	 * @return array Result data
	 */
	private static function execute_text_to_video_generation( $node_data, $input_data, $multimedia_generator, $execution_id ) {
		// Extract and process parameters
		$model        = isset( $node_data['selectedModel'] ) ? $node_data['selectedModel'] : '';
		$prompt       = isset( $node_data['prompt'] ) ? self::replace_input_tags( $node_data['prompt'], $input_data ) : '';
		$video_length = isset( $node_data['videoLength'] ) ? intval( $node_data['videoLength'] ) : 5;

		// Validate prompt
		if ( empty( $prompt ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Prompt is required for text-to-video generation',
				$node_data['id']
			);
			return self::create_node_data( 'error', 'Prompt is required for text-to-video generation' );
		}

		// Prepare parameters for API call
		$params = array(
			'model'        => $model,
			'prompt'       => $prompt,
			'video_length' => $video_length,
		);

		// Update execution status
		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Generating video from text with ' . $model,
			$node_data['id']
		);

		// Generate the video
		$result = $multimedia_generator->generate_video( $params );

		// Handle errors
		if ( is_wp_error( $result ) ) {
			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'Video generation failed: ' . $result->get_error_message(),
				$node_data['id']
			);
			return self::create_node_data( 'error', $result->get_error_message() );
		}

		// Track costs if available
		if ( class_exists( 'WP_AI_Workflows_Cost_Management' ) ) {
			$cost_manager = WP_AI_Workflows_Cost_Management::get_instance();
			$cost         = $multimedia_generator->estimate_cost( $model, 'video', $video_length );

			if ( $cost !== null ) {
				// Use the simplified cost tracking method
				$cost_manager->track_multimedia_cost_simple(
					'fal_ai',
					$model,
					$cost,
					$execution_id,  // Pass the execution ID
					$node_data['id'], // Pass the node ID
					1, // Always 1 video
					'video'
				);

				// Add cost information to logs
				WP_AI_Workflows_Utilities::debug_log(
					'Video generation cost tracked',
					'info',
					array(
						'execution_id' => $execution_id,
						'model'        => $model,
						'cost'         => $cost,
					)
				);
			}
		}

		// Store the full result in the node for display in the UI
		$node_data['result'] = $result;

		// Extract just the URL for passing to next node
		$output_data = '';

		if ( ! empty( $result['video'] ) && ! empty( $result['video']['url'] ) ) {
			$output_data = $result['video']['url'];

			// Log success with URL
			WP_AI_Workflows_Utilities::debug_log(
				'Video generation URL extracted',
				'info',
				array(
					'execution_id' => $execution_id,
					'url'          => $output_data,
				)
			);
		} else {
			// No video URL found in the result
			WP_AI_Workflows_Utilities::debug_log(
				'No video URL in result',
				'warning',
				array(
					'execution_id' => $execution_id,
					'result'       => $result,
				)
			);
			$output_data = 'No video URL found in result';
		}

		// Update status to success
		WP_AI_Workflows_Utilities::update_execution_status(
			$execution_id,
			'processing',
			'Video generation successful',
			$node_data['id']
		);

		// Return just the URL to the next node
		return self::create_node_data( 'multimediaGenerator', $output_data );
	}

	public static function execute_api_call_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node_id'      => $node['id'],
				'execution_id' => $execution_id,
			)
		);

		try {
			$url            = self::replace_input_tags( $node['data']['url'], $input_data );
			$method         = strtoupper( $node['data']['method'] );
			$timeout        = intval( $node['data']['responseConfig']['timeout'] ?? 30000 );
			$retry_count    = intval( $node['data']['responseConfig']['retryCount'] ?? 0 );
			$cache_response = (bool) ( $node['data']['responseConfig']['cacheResponse'] ?? false );
			$cache_time     = intval( $node['data']['responseConfig']['cacheTime'] ?? 300 );

			$allowed = self::validate_outbound_url( $url );
			if ( is_wp_error( $allowed ) ) {
				throw new Exception( esc_html( $allowed->get_error_message() ) );
			}

			$headers = array();
			if ( ! empty( $node['data']['headers'] ) && is_array( $node['data']['headers'] ) ) {
				foreach ( $node['data']['headers'] as $header ) {
					if ( ! empty( $header['name'] ) && isset( $header['value'] ) ) {
						$header_name             = str_replace( ' ', '-', trim( $header['name'] ) );
						$header_value            = self::replace_input_tags( $header['value'], $input_data );
						$headers[ $header_name ] = $header_value;
					}
				}
			}

			$args = array(
				'method'             => $method,
				'timeout'            => $timeout / 1000,
				'sslverify'          => true,
				'headers'            => $headers,
				// Revalidates every hop, so a public URL cannot redirect inward.
				'reject_unsafe_urls' => ! self::private_network_allowed( $url ),
			);

			if ( $method !== 'GET' && ! empty( $node['data']['body'] ) ) {
				$body = $node['data']['body'];

				$is_json_content = false;
				if ( isset( $args['headers']['Content-Type'] ) ) {
					$is_json_content = strpos( strtolower( $args['headers']['Content-Type'] ), 'application/json' ) !== false;
				} else {
					$is_json_content                 = true;
					$args['headers']['Content-Type'] = 'application/json';
				}

				if ( $is_json_content ) {
					// CRITICAL: Replace variables FIRST, then validate JSON
				// Variables like [Input from ...] make the JSON invalid before replacement
				$body = self::replace_input_tags( $body, $input_data );

				$decoded_body = json_decode( $body, true );
					if ( json_last_error() === JSON_ERROR_NONE ) {
							$args['body'] = wp_json_encode( $decoded_body );
						if ( $args['body'] === false ) {
							throw new Exception( 'Failed to encode request body as JSON' );
						}
					} else {
						throw new Exception( 'Invalid JSON in request body: ' . json_last_error_msg() );
					}
				} else {
					$args['body'] = self::replace_input_tags( $body, $input_data );
				}

				WP_AI_Workflows_Utilities::debug_log(
					'Body processing',
					'debug',
					array(
						'is_json_content' => $is_json_content,
						'body_type'       => gettype( $args['body'] ),
						'body_length'     => is_string( $args['body'] ) ? strlen( $args['body'] ) : 0,
					)
				);
			}

			$query_params = array();
			if ( ! empty( $node['data']['queryParams'] ) && is_array( $node['data']['queryParams'] ) ) {
				foreach ( $node['data']['queryParams'] as $param ) {
					if ( ! empty( $param['key'] ) && isset( $param['value'] ) ) {
						$param_value                   = self::replace_input_tags( $param['value'], $input_data );
						$query_params[ $param['key'] ] = $param_value;
					}
				}
			}

			if ( ! empty( $node['data']['auth'] ) ) {
				try {
					WP_AI_Workflows_Utilities::debug_log(
						'Processing API authentication',
						'debug',
						array(
							'node_id'   => $node['id'],
							'auth_type' => $node['data']['auth']['type'],
						)
					);

					switch ( $node['data']['auth']['type'] ) {
						case 'basic':
							if ( ! empty( $node['data']['auth']['username'] ) && ! empty( $node['data']['auth']['password'] ) ) {
								$username = $node['data']['auth']['username'];
								$password = $node['data']['auth']['password'];

								if ( strpos( $username, 'enc_' ) === 0 ) {
									$encrypted_username = substr( $username, 4 );
									$decrypted_username = WP_AI_Workflows_Encryption::decrypt( $encrypted_username );
									if ( $decrypted_username === false ) {
										throw new Exception( 'Failed to decrypt username' );
									}
									$username = $decrypted_username;
								}

								if ( strpos( $password, 'enc_' ) === 0 ) {
									$encrypted_password = substr( $password, 4 );
									$decrypted_password = WP_AI_Workflows_Encryption::decrypt( $encrypted_password );
									if ( $decrypted_password === false ) {
										throw new Exception( 'Failed to decrypt password' );
									}
									$password = $decrypted_password;
								}

								$args['headers']['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
							}
							break;

						case 'bearer':
							if ( ! empty( $node['data']['auth']['token'] ) ) {
								$token = $node['data']['auth']['token'];
								if ( strpos( $token, 'enc_' ) === 0 ) {
									$encrypted_token = substr( $token, 4 );
									$decrypted_token = WP_AI_Workflows_Encryption::decrypt( $encrypted_token );
									if ( $decrypted_token === false ) {
										throw new Exception( 'Failed to decrypt bearer token' );
									}
									$token = $decrypted_token;
								}
								$args['headers']['Authorization'] = 'Bearer ' . $token;
							}
							break;

						case 'apiKey':
							if ( ! empty( $node['data']['auth']['apiKey'] ) && ! empty( $node['data']['auth']['apiKeyName'] ) ) {
								$api_key = $node['data']['auth']['apiKey'];
								if ( strpos( $api_key, 'enc_' ) === 0 ) {
									$encrypted_key = substr( $api_key, 4 );
									$decrypted_key = WP_AI_Workflows_Encryption::decrypt( $encrypted_key );
									if ( $decrypted_key === false ) {
										throw new Exception( 'Failed to decrypt API key' );
									}
									$api_key = $decrypted_key;
								}
								$args['headers'][ $node['data']['auth']['apiKeyName'] ] = $api_key;
							}
							break;
					}

					WP_AI_Workflows_Utilities::debug_log(
						'Authentication processed successfully',
						'debug',
						array(
							'node_id'   => $node['id'],
							'auth_type' => $node['data']['auth']['type'],
						)
					);

				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Authentication processing failed',
						'error',
						array(
							'error'     => $e->getMessage(),
							'node_id'   => $node['id'],
							'auth_type' => $node['data']['auth']['type'],
						)
					);
					throw new Exception( 'Authentication error: ' . $e->getMessage() );
				}
			}

			if ( ! empty( $query_params ) ) {
				$url = add_query_arg( $query_params, $url );
			}

			// Log request configuration (excluding sensitive data)
			WP_AI_Workflows_Utilities::debug_log(
				'API request configuration',
				'debug',
				array(
					'url'          => $url,
					'method'       => $method,
					'header_keys'  => array_keys( $args['headers'] ),
					'has_body'     => isset( $args['body'] ),
					'query_params' => array_keys( $query_params ),
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				"Making API call to: $url",
				$node['id']
			);

			$response = self::execute_with_retry( $url, $args, $retry_count );
			if ( ! is_wp_error( $response ) ) {
				$response_code = wp_remote_retrieve_response_code( $response );
				if ( $response_code >= 400 ) {
					throw new Exception( "API request failed with status: $response_code" );
				}
			}

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			$response_code    = wp_remote_retrieve_response_code( $response );
			$response_body    = wp_remote_retrieve_body( $response );
			$response_headers = wp_remote_retrieve_headers( $response );

			$decoded_response = json_decode( $response_body, true );
			$response_data    = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_response : $response_body;


			if ( ! empty( $node['data']['responseConfig']['jsonPath'] ) ) {
				try {
					if ( is_array( $response_data ) ) {

						$extract_specific_values = function ( $array, $path ) use ( &$extract_specific_values ) {
							WP_AI_Workflows_Utilities::debug_log(
								'Starting extraction function',
								'debug',
								array(
									'path'             => $path,
									'array_type'       => gettype( $array ),
									'first_level_keys' => is_array( $array ) ? array_keys( $array ) : 'not_array',
								)
							);

							if ( ! is_array( $array ) ) {
								return null;
							}

							if ( empty( $path ) ) {
								return null;
							}

							// Handle numeric index access (e.g., "0.name")
							if ( preg_match( '/^(\d+)\.(.+)$/', $path, $matches ) ) {
								$index = intval( $matches[1] );
								$field = $matches[2];

								WP_AI_Workflows_Utilities::debug_log(
									'Numeric index case',
									'debug',
									array(
										'index'      => $index,
										'field'      => $field,
										'has_index'  => isset( $array[ $index ] ),
										'index_type' => isset( $array[ $index ] ) ? gettype( $array[ $index ] ) : 'not_set',
									)
								);

								if ( ! isset( $array[ $index ] ) || ! is_array( $array[ $index ] ) ) {
									return null;
								}

								$item = $array[ $index ];

								WP_AI_Workflows_Utilities::debug_log(
									'Found indexed item',
									'debug',
									array(
										'item_keys'       => array_keys( $item ),
										'requested_field' => $field,
										'has_field'       => isset( $item[ $field ] ),
									)
								);

								if ( ! str_contains( $field, '.' ) ) {
									if ( isset( $item[ $field ] ) ) {
										WP_AI_Workflows_Utilities::debug_log(
											'Direct field access result',
											'debug',
											array(
												'field' => $field,
												'value_type' => gettype( $item[ $field ] ),
												'value' => $item[ $field ],
											)
										);
										return $item[ $field ];
									}
									return null;
								}

								$current_value = $item;
								$field_parts   = explode( '.', $field );

								foreach ( $field_parts as $part ) {
									if ( ! is_array( $current_value ) || ! isset( $current_value[ $part ] ) ) {
										return null;
									}
									$current_value = $current_value[ $part ];
								}

								return $current_value;
							}

							// Handle prefix paths (e.g., "products.0.name")
							$parts = explode( '.', $path );
							if ( count( $parts ) > 1 && isset( $array[ $parts[0] ] ) && is_array( $array[ $parts[0] ] ) ) {
								WP_AI_Workflows_Utilities::debug_log(
									'Found array prefix',
									'debug',
									array(
										'prefix'         => $parts[0],
										'remaining_path' => implode( '.', array_slice( $parts, 1 ) ),
									)
								);
								return $extract_specific_values( $array[ $parts[0] ], implode( '.', array_slice( $parts, 1 ) ) );
							}

							// Handle field equality condition (e.g., "id=182.email")
							if ( preg_match( '/^(\w+)=([^\.]+)\.(.+)$/', $path, $matches ) ) {
								$filterField = $matches[1];
								$filterValue = trim( $matches[2], '"\'' );
								$targetField = $matches[3];

								WP_AI_Workflows_Utilities::debug_log(
									'Field equality case',
									'debug',
									array(
										'filterField' => $filterField,
										'filterValue' => $filterValue,
										'targetField' => $targetField,
										'array_count' => count( $array ),
									)
								);

								$results = array();
								foreach ( $array as $item ) {
									if ( is_array( $item ) &&
										isset( $item[ $filterField ] ) &&
										(string) $item[ $filterField ] === (string) $filterValue ) {

										$current_value = $item;
										$field_parts   = explode( '.', $targetField );

										$found = true;
										foreach ( $field_parts as $part ) {
											if ( ! is_array( $current_value ) || ! isset( $current_value[ $part ] ) ) {
												$found = false;
												break;
											}
											$current_value = $current_value[ $part ];
										}

										if ( $found ) {
											$results[] = $current_value;
										}
									}
								}

								WP_AI_Workflows_Utilities::debug_log(
									'Field equality result',
									'debug',
									array(
										'results_count' => count( $results ),
										'results'       => $results,
									)
								);

								return count( $results ) === 1 ? reset( $results ) : ( ! empty( $results ) ? $results : null );
							}

							// Handle simple field (e.g., "name")
							if ( ! str_contains( $path, '.' ) ) {
								$results = array();
								foreach ( $array as $k => $v ) {
									if ( $k === $path ) {
										$results[] = $v;
									} elseif ( is_array( $v ) ) {
										$nested_result = $extract_specific_values( $v, $path );
										if ( $nested_result !== null ) {
											if ( is_array( $nested_result ) ) {
												$results = array_merge( $results, $nested_result );
											} else {
												$results[] = $nested_result;
											}
										}
									}
								}

								return count( $results ) === 1 ? reset( $results ) : ( ! empty( $results ) ? $results : null );
							}

							return null;
						};

						WP_AI_Workflows_Utilities::debug_log(
							'Starting extraction with data',
							'debug',
							array(
								'path'             => $node['data']['responseConfig']['jsonPath'],
								'response_type'    => gettype( $response_data ),
								'is_array'         => is_array( $response_data ),
								'first_level_keys' => is_array( $response_data ) ? array_keys( $response_data ) : 'not_array',
							)
						);

						$extracted_data = $extract_specific_values( $response_data, $node['data']['responseConfig']['jsonPath'] );

						WP_AI_Workflows_Utilities::debug_log(
							'Value extraction completed',
							'debug',
							array(
								'pattern'     => $node['data']['responseConfig']['jsonPath'],
								'result_type' => gettype( $extracted_data ),
								'is_array'    => is_array( $extracted_data ),
								'value'       => $extracted_data,
							)
						);

						$response_data = $extracted_data;
					}
				} catch ( Exception $e ) {
					WP_AI_Workflows_Utilities::debug_log(
						'Value extraction failed',
						'error',
						array(
							'error' => $e->getMessage(),
							'path'  => $node['data']['responseConfig']['jsonPath'],
						)
					);
				}
			}

			$is_test = strpos( $execution_id, 'test-' ) === 0;

			if ( $is_test ) {
				$result = array(
					'type'    => 'apiCall',
					'content' => array(
						'status'  => $response_code,
						'headers' => $response_headers,
						'data'    => $response_data,
					),
				);
			} else {

				$result = array(
					'type'    => 'apiCall',
					'content' => $response_data,
				);
			}

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				"API call completed with status: $response_code",
				$node['id']
			);

			return $result;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'API call failed',
				'error',
				array(
					'error'   => $e->getMessage(),
					'node_id' => $node['id'],
				)
			);

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'error',
				'API call error: ' . $e->getMessage(),
				$node['id']
			);

			if ( strpos( $execution_id, 'test-' ) === 0 ) {
				return array(
					'type'    => 'apiCall',
					'content' => array(
						'status'  => 500,
						'headers' => array(),
						'data'    => array( 'error' => $e->getMessage() ),
					),
				);
			} else {
				return array(
					'type'    => 'apiCall',
					'content' => array( 'error' => $e->getMessage() ),
				);
			}
		}
	}

	/**
	 * Execute create file node
	 */
	public static function execute_create_file_node( $node, $input_data, $execution_id ) {
		WP_AI_Workflows_Utilities::debug_function(
			__FUNCTION__,
			array(
				'node'         => $node,
				'execution_id' => $execution_id,
			)
		);

		$file_format    = isset( $node['data']['fileFormat'] ) ? $node['data']['fileFormat'] : 'txt';
		$file_name      = isset( $node['data']['fileName'] ) ? $node['data']['fileName'] : 'output-' . time();
		$file_content   = isset( $node['data']['fileContent'] ) ? $node['data']['fileContent'] : '';
		$format_options = isset( $node['data']['formatOptions'] ) ? $node['data']['formatOptions'] : array();

		$file_name    = self::replace_input_tags( $file_name, $input_data );
		$file_content = self::replace_input_tags( $file_content, $input_data );

		$file_content = self::clean_content_for_file( $file_content, $file_format );

		$file_name = sanitize_file_name( $file_name );

		if ( ! preg_match( '/\.' . preg_quote( $file_format, '/' ) . '$/i', $file_name ) ) {
			$file_name .= '.' . $file_format;
		}

		try {
			$upload_dir       = wp_upload_dir();
			$ai_workflows_dir = $upload_dir['basedir'] . '/wp-ai-workflows';
			$ai_workflows_url = $upload_dir['baseurl'] . '/wp-ai-workflows';

			if ( ! file_exists( $ai_workflows_dir ) ) {
				wp_mkdir_p( $ai_workflows_dir );

				// Create .htaccess file to protect direct file access if needed
				$htaccess_content = "Options -Indexes\n";
				file_put_contents( $ai_workflows_dir . '/.htaccess', $htaccess_content );
			}

			$unique_id     = uniqid();
			$relative_path = gmdate( 'Y/m' );
			$file_dir      = $ai_workflows_dir . '/' . $relative_path;

			if ( ! file_exists( $file_dir ) ) {
				wp_mkdir_p( $file_dir );
			}

			$file_path = $file_dir . '/' . $file_name;
			$file_url  = $ai_workflows_url . '/' . $relative_path . '/' . $file_name;

			switch ( $file_format ) {
				case 'txt':
					file_put_contents( $file_path, $file_content );
					break;

				case 'docx':
					if ( class_exists( 'ZipArchive' ) ) {
						$result = self::create_simple_docx( $file_path, $file_content );
						if ( ! $result ) {
							$file_path   = str_replace( '.docx', '.txt', $file_path );
							$file_url    = str_replace( '.docx', '.txt', $file_url );
							$file_name   = str_replace( '.docx', '.txt', $file_name );
							$file_format = 'txt';
							file_put_contents( $file_path, $file_content );

							WP_AI_Workflows_Utilities::debug_log(
								'DOCX creation failed, created TXT file instead',
								'warning'
							);
						}
					} else {
						$file_path   = str_replace( '.docx', '.txt', $file_path );
						$file_url    = str_replace( '.docx', '.txt', $file_url );
						$file_name   = str_replace( '.docx', '.txt', $file_name );
						$file_format = 'txt';
						file_put_contents( $file_path, $file_content );

						WP_AI_Workflows_Utilities::debug_log(
							'ZipArchive not available, created TXT file instead',
							'warning'
						);
					}
					break;

				case 'html':
					$html_content = self::convert_markdown_to_document_html( $file_content );
					file_put_contents( $file_path, $html_content );
					break;

				default:
					$file_path = $file_dir . '/' . $file_name . '.txt';
					$file_url  = $ai_workflows_url . '/' . $relative_path . '/' . $file_name . '.txt';
					file_put_contents( $file_path, $file_content );
					break;
			}

			$attachment_id = null;
			$save_to_media = isset( $node['data']['saveToMedia'] ) ? $node['data']['saveToMedia'] : true;

			if ( $save_to_media ) {
				$attachment_id = self::save_file_to_media_library( $file_path, $file_name, $file_format );

				if ( $attachment_id ) {
					$file_url = wp_get_attachment_url( $attachment_id );
				}
			}

			WP_AI_Workflows_Utilities::update_execution_status(
				$execution_id,
				'processing',
				'File created: ' . $file_name,
				$node['id']
			);

			return self::create_node_data( 'url', $file_url );

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error creating file',
				'error',
				array( 'error' => $e->getMessage() )
			);

			return self::create_node_data( 'error', 'Failed to create file: ' . $e->getMessage() );
		}
	}

	/**
	 * Clean content for file output, handling HTML tags and formatting
	 *
	 * @param string $content Raw content
	 * @param string $format File format (txt, docx, pdf)
	 * @return string Cleaned content
	 */
	private static function clean_content_for_file( $content, $format ) {
		$content = str_replace( '<br>', "\n", $content );
		$content = str_replace( '<br/>', "\n", $content );
		$content = str_replace( '<br />', "\n", $content );

		if ( $format === 'txt' ) {
			$content = preg_replace( '/<ul>/', "\n", $content );
			$content = preg_replace( '/<\/ul>/', "\n", $content );
			$content = preg_replace( '/<li>/', '• ', $content );
			$content = preg_replace( '/<\/li>/', "\n", $content );

			$content = wp_strip_all_tags( $content );
		}

		$content = str_replace( "\r\n", "\n", $content );
		$content = str_replace( "\r", "\n", $content );

		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return $content;
	}

	/**
	 * Convert markdown to HTML specifically for file generation
	 * Uses a unique name to avoid conflicts with existing methods
	 */
	private static function convert_markdown_to_document_html( $content ) {
		$content = self::clean_content_for_document_html( $content );

		$content = preg_replace( '/^#\s+(.+)$/m', '<h1>$1</h1>', $content );
		$content = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $content );
		$content = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $content );
		$content = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $content );
		$content = preg_replace( '/^#####\s+(.+)$/m', '<h5>$1</h5>', $content );
		$content = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $content );

		$content = preg_replace( '/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content );
		$content = preg_replace( '/\*(.*?)\*/s', '<em>$1</em>', $content );
		$content = preg_replace( '/__(.*?)__/s', '<strong>$1</strong>', $content );
		$content = preg_replace( '/_(.*?)_/s', '<em>$1</em>', $content );

		$content = preg_replace( '/^[\*\-]\s+(.+)$/m', '<li>$1</li>', $content );

		$content = preg_replace( '/^(\d+)[\.\)]\s+(.+)$/m', '<li>$2</li>', $content );

		$lines   = explode( "\n", $content );
		$result  = array();
		$in_list = false;

		foreach ( $lines as $line ) {
			if ( strpos( $line, '<li>' ) === 0 ) {
				if ( ! $in_list ) {
					$result[] = '<ul>';
					$in_list  = true;
				}
				$result[] = $line;
			} else {
				if ( $in_list ) {
					$result[] = '</ul>';
					$in_list  = false;
				}
				$result[] = $line;
			}
		}

		if ( $in_list ) {
			$result[] = '</ul>';
		}

		$content = implode( "\n", $result );

		$paragraphs = preg_split( '/\n{2,}/', $content );
		$content    = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if (
			preg_match( '/^<(h[1-6]|ul|ol|li|div|blockquote|pre)/i', $paragraph ) ||
			preg_match( '/^<\/(h[1-6]|ul|ol|li|div|blockquote|pre)/i', $paragraph )
			) {
				$content .= $paragraph . "\n\n";
			} elseif ( ! empty( $paragraph ) ) {
				$content .= '<p>' . $paragraph . '</p>' . "\n\n";
			}
		}

		$content = preg_replace( '/<p><li>(.*?)<\/li><\/p>/s', '<li>$1</li>', $content );

		$content = preg_replace( '/<\/ul>\s*<ul>/s', '', $content );

		$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Generated Document</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        h1 {
            color: #333;
        }
        h2 {
            color: #555;
        }
        p {
            line-height: 1.6;
            color: #666;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .quote {
            font-style: italic;
            color: #0073e6;
            border-left: 4px solid #0073e6;
            padding-left: 10px;
            margin: 20px 0;
        }
        ul, ol {
            padding-left: 25px;
        }
        li {
            margin-bottom: 5px;
        }
        strong {
            font-weight: bold;
            color: #444;
        }
        em {
            font-style: italic;
            color: #555;
        }
    </style>
</head>
<body>
<div class="container">
' . $content . '
</div>
</body>
</html>';

		return $html;
	}

	/**
	 * Clean content for HTML document output
	 */
	private static function clean_content_for_document_html( $content ) {
		$content = str_replace( "\r\n", "\n", $content );
		$content = str_replace( "\r", "\n", $content );

		$content = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $content );

		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		$lines       = explode( "\n", $content );
		$fixed_lines = array();

		foreach ( $lines as $line ) {
			$line = preg_replace( '/^(#{1,6})([^\s#])/', '$1 $2', $line );

			$line = preg_replace( '/^(-|\*|\d+\.)([^\s])/', '$1 $2', $line );

			$fixed_lines[] = $line;
		}

		return implode( "\n", $fixed_lines );
	}

	/**
	 * Create a simple DOCX file with improved formatting
	 */
	private static function create_simple_docx( $file_path, $content ) {
		try {
			$temp_dir = sys_get_temp_dir() . '/docx_' . uniqid();
			if ( ! file_exists( $temp_dir ) ) {
				wp_mkdir_p( $temp_dir );
			}
			wp_mkdir_p( $temp_dir . '/word' );
			wp_mkdir_p( $temp_dir . '/_rels' );
			wp_mkdir_p( $temp_dir . '/word/_rels' );

			$wordml = self::convert_markdown_to_wordml( $content );

			$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" 
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">
  <w:body>' . $wordml . '</w:body>
</w:document>';

			file_put_contents( $temp_dir . '/word/document.xml', $document_xml );

			$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>';

			file_put_contents( $temp_dir . '/[Content_Types].xml', $content_types );

			$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>';

			file_put_contents( $temp_dir . '/_rels/.rels', $rels );

			$document_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable" Target="fontTable.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>
</Relationships>';

			file_put_contents( $temp_dir . '/word/_rels/document.xml.rels', $document_rels );

			$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:pPr/>
    <w:rPr>
      <w:sz w:val="24"/>
      <w:szCs w:val="24"/>
      <w:lang w:val="en-US" w:eastAsia="en-US" w:bidi="ar-SA"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading1">
    <w:name w:val="heading 1"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="Normal"/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="240" w:after="120"/>
      <w:outlineLvl w:val="0"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:bCs/>
      <w:sz w:val="36"/>
      <w:szCs w:val="36"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading2">
    <w:name w:val="heading 2"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="Normal"/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="240" w:after="120"/>
      <w:outlineLvl w:val="1"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:bCs/>
      <w:sz w:val="32"/>
      <w:szCs w:val="32"/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="Heading3">
    <w:name w:val="heading 3"/>
    <w:basedOn w:val="Normal"/>
    <w:next w:val="Normal"/>
    <w:pPr>
      <w:keepNext/>
      <w:spacing w:before="240" w:after="120"/>
      <w:outlineLvl w:val="2"/>
    </w:pPr>
    <w:rPr>
      <w:b/>
      <w:bCs/>
      <w:sz w:val="28"/>
      <w:szCs w:val="28"/>
    </w:rPr>
  </w:style>
  <w:style w:type="character" w:styleId="DefaultParagraphFont">
    <w:name w:val="Default Paragraph Font"/>
    <w:uiPriority w:val="1"/>
    <w:semiHidden/>
    <w:unhideWhenUsed/>
  </w:style>
  <w:style w:type="character" w:styleId="Strong">
    <w:name w:val="Strong"/>
    <w:basedOn w:val="DefaultParagraphFont"/>
    <w:rPr>
      <w:b/>
      <w:bCs/>
    </w:rPr>
  </w:style>
  <w:style w:type="character" w:styleId="Emphasis">
    <w:name w:val="Emphasis"/>
    <w:basedOn w:val="DefaultParagraphFont"/>
    <w:rPr>
      <w:i/>
      <w:iCs/>
    </w:rPr>
  </w:style>
  <w:style w:type="paragraph" w:styleId="ListParagraph">
    <w:name w:val="List Paragraph"/>
    <w:basedOn w:val="Normal"/>
    <w:pPr>
      <w:ind w:left="720"/>
      <w:contextualSpacing/>
    </w:pPr>
  </w:style>
</w:styles>';

			file_put_contents( $temp_dir . '/word/styles.xml', $styles_xml );

			$font_table = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:font w:name="Calibri">
    <w:panose1 w:val="020F0502020204030204"/>
    <w:charset w:val="00"/>
    <w:family w:val="swiss"/>
    <w:pitch w:val="variable"/>
    <w:sig w:usb0="E10002FF" w:usb1="4000ACFF" w:usb2="00000009" w:usb3="00000000" w:csb0="0000019F" w:csb1="00000000"/>
  </w:font>
  <w:font w:name="Times New Roman">
    <w:panose1 w:val="02020603050405020304"/>
    <w:charset w:val="00"/>
    <w:family w:val="roman"/>
    <w:pitch w:val="variable"/>
    <w:sig w:usb0="E0002AFF" w:usb1="C0007841" w:usb2="00000009" w:usb3="00000000" w:csb0="000001FF" w:csb1="00000000"/>
  </w:font>
  <w:font w:name="Arial">
    <w:panose1 w:val="020B0604020202020204"/>
    <w:charset w:val="00"/>
    <w:family w:val="swiss"/>
    <w:pitch w:val="variable"/>
    <w:sig w:usb0="E0002AFF" w:usb1="C0007843" w:usb2="00000009" w:usb3="00000000" w:csb0="000001FF" w:csb1="00000000"/>
  </w:font>
</w:fonts>';

			file_put_contents( $temp_dir . '/word/fontTable.xml', $font_table );

			$settings_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:zoom w:percent="100"/>
  <w:defaultTabStop w:val="720"/>
  <w:characterSpacingControl w:val="doNotCompress"/>
  <w:compat/>
</w:settings>';

			file_put_contents( $temp_dir . '/word/settings.xml', $settings_xml );

			wp_mkdir_p( $temp_dir . '/docProps' );

			$core_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" 
                  xmlns:dc="http://purl.org/dc/elements/1.1/" 
                  xmlns:dcterms="http://purl.org/dc/terms/">
  <dc:title>Generated Document</dc:title>
  <dc:creator>WP AI Workflows</dc:creator>
  <cp:lastModifiedBy>WP AI Workflows</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:modified>
</cp:coreProperties>';

			file_put_contents( $temp_dir . '/docProps/core.xml', $core_xml );

			$app_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
  <Application>WP AI Workflows</Application>
  <AppVersion>1.0.0</AppVersion>
</Properties>';

			file_put_contents( $temp_dir . '/docProps/app.xml', $app_xml );

			$zip = new ZipArchive();
			if ( $zip->open( $file_path, ZipArchive::CREATE ) !== true ) {
				throw new Exception( 'Cannot create DOCX file' );
			}

			self::add_directory_to_zip( $zip, $temp_dir );
			$zip->close();

			self::remove_directory( $temp_dir );

			return true;
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'DOCX creation error',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			return false;
		}
	}

	/**
	 * Convert markdown content to WordML for DOCX files
	 */
	private static function convert_markdown_to_wordml( $content ) {
		$content = self::clean_content_for_document_html( $content );

		if ( strpos( $content, '<ul>' ) !== false || strpos( $content, '<li>' ) !== false ) {
			$content = self::preprocess_html_lists( $content );
		}

		$wordml     = '';
		$lines      = explode( "\n", $content );
		$in_list    = false;
		$list_items = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				if ( ! $in_list ) {
					$wordml .= '<w:p><w:pPr/><w:r><w:t xml:space="preserve"> </w:t></w:r></w:p>';
				}
				continue;
			}

			if ( strpos( $line, '###LIST_ITEM###' ) === 0 ) {
				if ( ! $in_list ) {
					$in_list = true;
				}
				$item_content = substr( $line, 14 ); // Remove the marker
				$list_items[] = self::process_text_formatting_for_wordml( $item_content );
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $matches ) ) {
				$level = strlen( $matches[1] );
				$text  = htmlspecialchars( $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( $in_list ) {
					$wordml    .= self::create_wordml_list( $list_items );
					$list_items = array();
					$in_list    = false;
				}

				$wordml .= "<w:p><w:pPr><w:pStyle w:val=\"Heading$level\"/></w:pPr><w:r><w:t xml:space=\"preserve\">$text</w:t></w:r></w:p>";
				continue;
			}

			if ( preg_match( '/^[\*\-]\s+(.+)$/', $line, $matches ) ) {
				if ( ! $in_list ) {
					$in_list = true;
				}
				$list_items[] = self::process_text_formatting_for_wordml( $matches[1] );
				continue;
			}

			if ( preg_match( '/^(\d+)[\.\)]\s+(.+)$/', $line, $matches ) ) {
				if ( ! $in_list ) {
					$in_list = true;
				}
				$list_items[] = self::process_text_formatting_for_wordml( $matches[2] );
				continue;
			}

			if ( $in_list ) {
				$wordml    .= self::create_wordml_list( $list_items );
				$list_items = array();
				$in_list    = false;
			}

			$wordml .= '<w:p><w:pPr/>' . self::process_text_formatting_for_wordml( $line ) . '</w:p>';
		}

		if ( $in_list && ! empty( $list_items ) ) {
			$wordml .= self::create_wordml_list( $list_items );
		}

		return $wordml;
	}

	/**
	 * Process HTML lists in the content and mark them for special handling
	 */
	private static function preprocess_html_lists( $content ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true ); // Suppress warnings for malformed HTML

		$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $content . '</div>' );
		libxml_clear_errors();

		$xpath  = new DOMXPath( $dom );
		$result = $content;

		$list_items = $xpath->query( '//li' );
		if ( $list_items->length > 0 ) {
			$replacements = array();

			foreach ( $list_items as $item ) {
				$item_html = $dom->saveHTML( $item );
				$item_text = $item->textContent;

				$marker = '###LIST_ITEM###' . trim( $item_text );

				$replacements[ $item_html ] = $marker;
			}

			foreach ( $replacements as $search => $replace ) {
				$result = str_replace( $search, $replace, $result );
			}

			$result = str_replace( array( '<ul>', '</ul>' ), '', $result );
		}

		return $result;
	}

	/**
	 * Process text formatting for WordML (bold, italic, etc.)
	 */
	private static function process_text_formatting_for_wordml( $text ) {
		$text = self::convert_html_formatting_to_markdown( $text );

		$result = '';

		$parts          = preg_split( '/(\*\*|__)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$is_bold        = false;
		$processed_text = '';

		foreach ( $parts as $part ) {
			if ( $part === '**' || $part === '__' ) {
				$is_bold = ! $is_bold;
			} elseif ( ! empty( $part ) ) {
				if ( $is_bold ) {
					$processed_text .= '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars( $part, ENT_QUOTES, 'UTF-8' ) . '</w:t></w:r>';
				} else {
					$italic_parts = preg_split( '/(\*|_)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE );
					$is_italic    = false;

					foreach ( $italic_parts as $italic_part ) {
						if ( $italic_part === '*' || $italic_part === '_' ) {
							$is_italic = ! $is_italic;
						} elseif ( ! empty( $italic_part ) ) {
							if ( $is_italic ) {
								$processed_text .= '<w:r><w:rPr><w:i/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars( $italic_part, ENT_QUOTES, 'UTF-8' ) . '</w:t></w:r>';
							} else {
								$processed_text .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars( $italic_part, ENT_QUOTES, 'UTF-8' ) . '</w:t></w:r>';
							}
						}
					}
				}
			}
		}

		return $processed_text ?: '<w:r><w:t xml:space="preserve">' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</w:t></w:r>';
	}

	/**
	 * Convert HTML formatting to Markdown for consistent processing
	 */
	private static function convert_html_formatting_to_markdown( $text ) {
		$text = preg_replace( '/<strong>(.*?)<\/strong>/s', '**$1**', $text );
		$text = preg_replace( '/<b>(.*?)<\/b>/s', '**$1**', $text );

		$text = preg_replace( '/<em>(.*?)<\/em>/s', '*$1*', $text );
		$text = preg_replace( '/<i>(.*?)<\/i>/s', '*$1*', $text );

		return $text;
	}

	/**
	 * Create a WordML list with the given items
	 */
	private static function create_wordml_list( $items ) {
		$list_xml = '';

		foreach ( $items as $item ) {
			$list_xml .= "<w:p>
            <w:pPr>
                <w:pStyle w:val=\"ListParagraph\"/>
                <w:numPr>
                    <w:ilvl w:val=\"0\"/>
                    <w:numId w:val=\"1\"/>
                </w:numPr>
            </w:pPr>
            $item
        </w:p>";
		}

		return $list_xml;
	}


	/**
	 * Helper function to add directory contents to a zip file
	 */
	private static function add_directory_to_zip( $zip, $dir, $base_in_zip = '' ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $name => $file ) {
			if ( ! $file->isDir() ) {
				$filePath     = $file->getRealPath();
				$relativePath = $base_in_zip . substr( $filePath, strlen( $dir ) + 1 );

				$zip->addFile( $filePath, $relativePath );
			}
		}
	}

	/**
	 * Helper function to remove a directory and its contents
	 */
	private static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != '.' && $object != '..' ) {
				if ( is_dir( $dir . '/' . $object ) ) {
					self::remove_directory( $dir . '/' . $object );
				} else {
					wp_delete_file( $dir . '/' . $object );
				}
			}
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->rmdir( $dir );
		}
	}

	/**
	 * Save file to WordPress media library
	 *
	 * @param string $file_path Path to the file
	 * @param string $file_name Name of the file
	 * @param string $file_format File format extension
	 * @return int|false Attachment ID if successful, false on failure
	 */
	private static function save_file_to_media_library( $file_path, $file_name, $file_format ) {
		$mime_type = 'text/plain';
		if ( $file_format === 'html' ) {
			$mime_type = 'text/html';
		} elseif ( $file_format === 'docx' ) {
			$mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		}

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => $file_name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( ! is_wp_error( $attachment_id ) ) {
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );
			return $attachment_id;
		}

		return false;
	}


	private static function replace_input_tags_recursive( $data, $input_data ) {
		if ( is_string( $data ) ) {
			return self::replace_input_tags( $data, $input_data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::replace_input_tags_recursive( $value, $input_data );
			}
		}

		return $data;
	}


	/**
	 * @param string $url
	 * @param int    $parent_post_id
	 * @param string $file_name Overrides the name derived from the URL, for a URL
	 *                          whose path says nothing useful (a download endpoint).
	 * @return int|WP_Error|null
	 */
	private static function create_attachment_from_url( $url, $parent_post_id = 0, $file_name = '' ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$clean_url = strtok( $url, '?' );

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error downloading file',
				'error',
				array(
					'error' => $temp_file->get_error_message(),
				)
			);
			return $temp_file;
		}

		// fileinfo is not guaranteed on shared hosting, and this used to fatal there.
		$mime_type = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $temp_file ) : '';
		if ( '' === $mime_type && function_exists( 'wp_check_filetype' ) ) {
			$checked   = wp_check_filetype( '' !== $file_name ? $file_name : (string) $clean_url );
			$mime_type = empty( $checked['type'] ) ? '' : (string) $checked['type'];
		}

		$ext = self::get_file_extension_from_mime( $mime_type );

		$filename = '' !== $file_name
			? sanitize_file_name( $file_name )
			: sanitize_file_name( pathinfo( $clean_url, PATHINFO_FILENAME ) . '.' . $ext );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => filesize( $temp_file ),
			'type'     => $mime_type,
		);

		add_filter( 'upload_mimes', array( __CLASS__, 'allow_image_types' ), 10, 1 );
		add_filter( 'upload_dir', array( __CLASS__, 'set_upload_dir' ), 10, 1 );

		$attachment_id = media_handle_sideload( $file_array, $parent_post_id );

		remove_filter( 'upload_mimes', array( __CLASS__, 'allow_image_types' ) );
		remove_filter( 'upload_dir', array( __CLASS__, 'set_upload_dir' ) );

		wp_delete_file( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error creating attachment',
				'error',
				array(
					'error' => $attachment_id->get_error_message(),
				)
			);
			return null;
		}

		return $attachment_id;
	}

	public static function allow_attachment_types( $mimes ) {
		$mimes['pdf']      = 'application/pdf';
		$mimes['doc|docx'] = 'application/msword';
		$mimes['xls|xlsx'] = 'application/vnd.ms-excel';
		$mimes['ppt|pptx'] = 'application/vnd.ms-powerpoint';

		$mimes['zip'] = 'application/zip';
		$mimes['rar'] = 'application/x-rar-compressed';

		$mimes['txt'] = 'text/plain';
		$mimes['csv'] = 'text/csv';

		$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
		$mimes['gif']          = 'image/gif';
		$mimes['png']          = 'image/png';
		$mimes['webp']         = 'image/webp';

		return $mimes;
	}

	private static function get_file_extension_from_mime( $mime_type ) {
		$mime_map = array(
			'image/jpeg'                    => 'jpg',
			'image/jpg'                     => 'jpg',
			'image/png'                     => 'png',
			'image/gif'                     => 'gif',
			'image/webp'                    => 'webp',

			'application/pdf'               => 'pdf',
			'application/msword'            => 'doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.ms-excel'      => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'application/vnd.ms-powerpoint' => 'ppt',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

			'application/zip'               => 'zip',
			'application/x-rar-compressed'  => 'rar',

			'text/plain'                    => 'txt',
			'text/csv'                      => 'csv',
		);

		return isset( $mime_map[ $mime_type ] ) ? $mime_map[ $mime_type ] : 'bin';
	}

	/**
	 * @param array $mimes
	 * @return array
	 */
	public static function allow_pdf_type( $mimes ) {
		$mimes['pdf'] = 'application/pdf';
		return $mimes;
	}

	public static function allow_image_types( $mimes ) {
		$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
		$mimes['gif']          = 'image/gif';
		$mimes['png']          = 'image/png';
		$mimes['webp']         = 'image/webp';

		return $mimes;
	}

	public static function set_upload_dir( $upload ) {
		if ( ! file_exists( $upload['path'] ) ) {
			wp_mkdir_p( $upload['path'] );
		}
		return $upload;
	}

	private static function get_node_input_data( $node_id, $edges, $node_data ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'node_id' => $node_id ) );

		$input_data = array();
		foreach ( $edges as $edge ) {
			if ( $edge['target'] == $node_id ) {
				$source_id = $edge['source'];
				if ( isset( $node_data[ $source_id ] ) ) {
					$input_data[ $source_id ] = $node_data[ $source_id ];
				}
			}
		}
		return $input_data;
	}

	/**
	 * Format input data for display in execution details
	 *
	 * @param array $node The node configuration
	 * @param array $input_data Raw input data from connected nodes
	 * @return array Formatted input data for display
	 */
	private static function format_input_data_for_display( $node, $input_data ) {
		$node_type = $node['type'] ?? 'unknown';
		$node_config = $node['data'] ?? array();

		$formatted = array(
			'raw_inputs' => array(),
			'processed_config' => array(),
		);

		foreach ( $input_data as $source_id => $source_data ) {
			$formatted['raw_inputs'][ $source_id ] = array(
				'type' => $source_data['type'] ?? 'unknown',
				'content' => $source_data['content'] ?? null,
			);
		}

		switch ( $node_type ) {
			case 'trigger':
				$trigger_type = $node_config['triggerType'] ?? 'manual';
				$formatted['processed_config']['trigger_type'] = $trigger_type;

				if ( $trigger_type === 'webhook' && isset( $node_config['webhookKeys'] ) ) {
					$formatted['processed_config']['webhook_keys'] = $node_config['webhookKeys'];
				} elseif ( in_array( $trigger_type, array( 'gravityForms', 'wpForms', 'contactForm7', 'ninjaForms', 'elementorForms' ) ) ) {
					$formatted['processed_config']['form_id'] = $node_config['formId'] ?? null;
					$formatted['processed_config']['form_fields'] = $node_config['formFields'] ?? array();
				}
				break;

			case 'aiModel':
				$prompt = $node_config['content'] ?? '';
				if ( ! empty( $input_data ) ) {
					$prompt = self::replace_input_tags( $prompt, $input_data );
				}
				$formatted['processed_config']['final_prompt'] = $prompt;
				$formatted['processed_config']['model'] = $node_config['model'] ?? null;
				$formatted['processed_config']['provider'] = $node_config['provider'] ?? null;
				$formatted['processed_config']['temperature'] = $node_config['temperature'] ?? null;
				$formatted['processed_config']['max_tokens'] = $node_config['maxTokens'] ?? null;
				break;

			case 'sendEmail':
				$formatted['processed_config']['to'] = $node_config['to'] ?? '';
				$formatted['processed_config']['subject'] = $node_config['subject'] ?? '';
				$formatted['processed_config']['body'] = $node_config['body'] ?? '';
				break;

			case 'APICall':
				$formatted['processed_config']['method'] = $node_config['method'] ?? 'GET';
				$formatted['processed_config']['url'] = $node_config['url'] ?? '';
				$formatted['processed_config']['headers'] = $node_config['headers'] ?? array();
				$formatted['processed_config']['body'] = $node_config['body'] ?? '';
				break;

			case 'condition':
				$formatted['processed_config']['condition_type'] = $node_config['conditionType'] ?? 'simple';
				$formatted['processed_config']['left_value'] = $node_config['leftValue'] ?? '';
				$formatted['processed_config']['operator'] = $node_config['operator'] ?? '';
				$formatted['processed_config']['right_value'] = $node_config['rightValue'] ?? '';
				break;

			case 'parser':
				$formatted['processed_config']['parse_type'] = $node_config['parseType'] ?? 'json';
				$formatted['processed_config']['json_path'] = $node_config['jsonPath'] ?? '';
				break;

			case 'output':
				$formatted['processed_config']['output_type'] = $node_config['outputType'] ?? 'display';
				$formatted['processed_config']['content'] = $node_config['content'] ?? '';
				break;

			case 'humanInput':
				$formatted['processed_config']['input_type'] = $node_config['inputType'] ?? 'approval';
				$formatted['processed_config']['instructions'] = $node_config['instructions'] ?? '';
				$formatted['processed_config']['assigned_to'] = $node_config['assignedTo'] ?? 'any';
				break;

			case 'post':
				$formatted['processed_config']['post_type']   = $node_config['selectedPostType'] ?? $node_config['postType'] ?? 'post';
				$formatted['processed_config']['post_status'] = $node_config['postStatus'] ?? 'draft';
				$field_mappings = ( isset( $node_config['fieldMappings'] ) && is_array( $node_config['fieldMappings'] ) )
					? $node_config['fieldMappings'] : array();
				$resolve_for_display = function ( $raw ) use ( $input_data ) {
					if ( ! is_string( $raw ) || '' === $raw || empty( $input_data ) ) {
						return $raw;
					}
					return self::strip_unresolved_input_tags( self::replace_input_tags( $raw, $input_data ) );
				};
				$title_raw   = $field_mappings['post_title'] ?? ( $node_config['title'] ?? '' );
				$content_raw = $field_mappings['post_content'] ?? ( $node_config['content'] ?? '' );
				$formatted['processed_config']['title']   = $resolve_for_display( $title_raw );
				$formatted['processed_config']['content'] = $resolve_for_display( $content_raw );
				break;

			default:
				if ( isset( $node_config['content'] ) ) {
					$formatted['processed_config']['content'] = $node_config['content'];
				}
				break;
		}

		return $formatted;
	}

	private static function process_dynamic_variables( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		$patterns = array(
			'{{current_date}}'       => current_time( 'Y-m-d' ),
			'{{current_time}}'       => current_time( 'H:i:s' ),
			'{{current_datetime}}'   => current_time( 'Y-m-d H:i:s' ),
			'{{current_timestamp}}'  => current_time( 'timestamp' ),
			'{{yesterday}}'          => wp_date( 'Y-m-d', strtotime( '-1 day' ) ),
			'{{tomorrow}}'           => wp_date( 'Y-m-d', strtotime( '+1 day' ) ),
			'{{current_month}}'      => current_time( 'F' ),
			'{{current_year}}'       => current_time( 'Y' ),
			'{{current_day}}'        => current_time( 'l' ),

			'{{site_name}}'          => get_bloginfo( 'name' ),
			'{{site_url}}'           => get_site_url(),
			'{{admin_email}}'        => get_bloginfo( 'admin_email' ),
			'{{current_user}}'       => wp_get_current_user()->user_login ?? '',
			'{{current_user_email}}' => wp_get_current_user()->user_email ?? '',
			'{{current_user_id}}'    => get_current_user_id(),

			'{{post_id}}'            => get_the_ID(),
			'{{post_title}}'         => get_the_title(),
			'{{post_excerpt}}'       => get_the_excerpt(),
			'{{post_content}}'       => get_post_field( 'post_content', get_the_ID() ),
			'{{post_author}}'        => get_the_author(),
			'{{post_date}}'          => get_the_date(),
			'{{post_modified_date}}' => get_the_modified_date(),
			'{{post_type}}'          => get_post_type(),
			'{{post_status}}'        => get_post_status(),
			'{{post_url}}'           => get_permalink(),
			'{{post_categories}}'    => wp_strip_all_tags( get_the_category_list( ', ' ) ),
			'{{post_tags}}'          => wp_strip_all_tags( get_the_tag_list( '', ', ', '' ) ),

			'{{php_version}}'        => phpversion(),
			'{{wp_version}}'         => get_bloginfo( 'version' ),
			'{{server_ip}}'          => sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) ),
			'{{client_ip}}'          => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			global $product;

			if ( is_product() && $product ) {
				$wc_patterns = array(
					'{{product_id}}'                => $product->get_id(),
					'{{product_name}}'              => $product->get_name(),
					'{{product_price}}'             => $product->get_price(),
					'{{product_regular_price}}'     => $product->get_regular_price(),
					'{{product_sale_price}}'        => $product->get_sale_price(),
					'{{product_sku}}'               => $product->get_sku(),
					'{{product_stock}}'             => $product->get_stock_quantity(),
					'{{product_stock_status}}'      => $product->get_stock_status(),
					'{{product_category}}'          => wp_strip_all_tags( wc_get_product_category_list( $product->get_id() ) ),
					'{{product_short_description}}' => $product->get_short_description(),
					'{{product_type}}'              => $product->get_type(),
					'{{product_url}}'               => get_permalink( $product->get_id() ),
					'{{product_rating}}'            => $product->get_average_rating(),
					'{{product_review_count}}'      => $product->get_review_count(),
				);
				$patterns    = array_merge( $patterns, $wc_patterns );

				if ( $product->is_type( 'variable' ) ) {
					$patterns['{{product_min_price}}'] = $product->get_variation_price( 'min' );
					$patterns['{{product_max_price}}'] = $product->get_variation_price( 'max' );
				}
			}

			if ( WC()->cart ) {
				$cart_patterns = array(
					'{{cart_total}}'      => WC()->cart->get_total(),
					'{{cart_subtotal}}'   => WC()->cart->get_subtotal(),
					'{{cart_item_count}}' => WC()->cart->get_cart_contents_count(),
					'{{cart_url}}'        => wc_get_cart_url(),
					'{{checkout_url}}'    => wc_get_checkout_url(),
				);
				$patterns      = array_merge( $patterns, $cart_patterns );
			}
		}

		if ( strpos( $content, '{{random_number}}' ) !== false ) {
			$patterns['{{random_number}}'] = wp_rand( 0, 100 );
		}
		if ( strpos( $content, '{{random_number_1000}}' ) !== false ) {
			$patterns['{{random_number_1000}}'] = wp_rand( 0, 1000 );
		}
		if ( strpos( $content, '{{random_string}}' ) !== false ) {
			$patterns['{{random_string}}'] = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ), 0, 8 );
		}
		if ( strpos( $content, '{{unique_id}}' ) !== false ) {
			$patterns['{{unique_id}}'] = uniqid();
		}

		return str_replace( array_keys( $patterns ), array_values( $patterns ), $content );
	}

	/**
	 * Remove any canonical input tags left unresolved after replace_input_tags so
	 * a missing upstream field never leaks its literal `[[field] from node]` /
	 * `[Input from node]` text into a rendered value (post title/content, etc.).
	 *
	 * @param mixed $value Post-substitution value.
	 * @return mixed String with residual tags stripped, or the value unchanged.
	 */
	private static function strip_unresolved_input_tags( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		return preg_replace(
			array(
				'/\[\[[^\]]+\] from [\w-]+\]/',
				'/\[Input from [\w-]+\]/',
			),
			'',
			$value
		);
	}

	/**
	 * Choose the post body when no `post_content` mapping resolved. Picks the
	 * longest article-bearing input rather than blindly concatenating every input:
	 * an array input (flat structured AI output) contributes its content/body/text
	 * field, and a bare URL input (e.g. a media/image node whose content is just an
	 * image URL) is never treated as the post body.
	 *
	 * @param array $input_data Upstream node outputs keyed by node id.
	 * @return string
	 */
	private static function pick_fallback_post_content( $input_data ) {
		if ( ! is_array( $input_data ) ) {
			return '';
		}
		$best = '';
		foreach ( $input_data as $input ) {
			if ( ! is_array( $input ) || ! isset( $input['content'] ) ) {
				continue;
			}
			$candidate = $input['content'];
			$text      = '';
			if ( is_array( $candidate ) ) {
				foreach ( array( 'content', 'body', 'text', 'html', 'article' ) as $preferred ) {
					foreach ( $candidate as $key => $val ) {
						if ( is_string( $key ) && 0 === strcasecmp( $key, $preferred ) && is_string( $val ) ) {
							$text = $val;
							break 2;
						}
					}
				}
			} elseif ( is_string( $candidate ) ) {
				$text = $candidate;
			}

			$text = trim( (string) $text );
			if ( '' === $text || self::is_bare_url( $text ) ) {
				continue;
			}
			if ( strlen( $text ) > strlen( $best ) ) {
				$best = $text;
			}
		}
		return $best;
	}

	/**
	 * Whether a string is nothing but a single http(s) URL.
	 *
	 * @param string $value Candidate string.
	 * @return bool
	 */
	private static function is_bare_url( $value ) {
		return is_string( $value ) && 1 === preg_match( '#^https?://\S+$#i', trim( $value ) );
	}

	private static function replace_input_tags( $content, $input_data ) {
		$content = self::process_dynamic_variables( $content );

		$content = preg_replace_callback(
			'/\[Input from ([\w-]+)\]/',
			function ( $matches ) use ( $input_data ) {
				$node_id = $matches[1];
				if ( isset( $input_data[ $node_id ] ) ) {
					if ( $input_data[ $node_id ]['type'] === 'condition' ) {
						// For condition nodes, use the input data from the previous node
						$prev_node_data = reset( $input_data[ $node_id ]['input_data'] );
						return is_array( $prev_node_data['content'] )
						? wp_json_encode( $prev_node_data['content'] )
						: strval( $prev_node_data['content'] );
					} elseif ( $input_data[ $node_id ]['type'] === 'chat' && isset( $input_data[ $node_id ]['content']['action_params'] ) ) {
						// For chat nodes with action parameters, return those parameters
						return is_array( $input_data[ $node_id ]['content']['action_params'] )
						? wp_json_encode( $input_data[ $node_id ]['content']['action_params'] )
						: strval( $input_data[ $node_id ]['content']['action_params'] );
					} else {
						return is_array( $input_data[ $node_id ]['content'] )
						? wp_json_encode( $input_data[ $node_id ]['content'] )
						: strval( $input_data[ $node_id ]['content'] );
					}
				}
				return $matches[0];
			},
			$content
		);

		$content = preg_replace_callback(
			'/\[\[([^\]]+)\] from ([\w-]+)\]/',
			function ( $matches ) use ( $input_data ) {
				$field_path = $matches[1];
				$node_id    = $matches[2];

				WP_AI_Workflows_Utilities::debug_log(
					'Processing [[field] from] tag',
					'debug',
					array(
						'field_path'        => $field_path,
						'node_id'           => $node_id,
						'input_data_exists' => isset( $input_data[ $node_id ] ),
						'input_data_type'   => isset( $input_data[ $node_id ] ) ? $input_data[ $node_id ]['type'] : 'not_set',
					)
				);

				if ( isset( $input_data[ $node_id ] ) ) {
					if ( $input_data[ $node_id ]['type'] === 'condition' ) {
						// For condition nodes, use the input data from the previous node
						$prev_node_data = reset( $input_data[ $node_id ]['input_data'] );
						$node_content   = $prev_node_data['content'];
					} elseif ( $input_data[ $node_id ]['type'] === 'chat' &&
						isset( $input_data[ $node_id ]['content']['action_params'] ) ) {
						// For chat nodes with action parameters, access the action parameters

						if ( strpos( $field_path, '.' ) !== false ) {
							list($action_id, $param_name) = explode( '.', $field_path, 2 );

							$action_params = $input_data[ $node_id ]['content']['action_params'];

							WP_AI_Workflows_Utilities::debug_log(
								'Processing action param access',
								'debug',
								array(
									'action_id'         => $action_id,
									'param_name'        => $param_name,
									'current_action_id' => $input_data[ $node_id ]['content']['current_action_id'] ?? 'none',
									'action_params'     => array_keys( $action_params ),
								)
							);

							// Check if parameter exists, regardless of action ID
							// This allows access to parameters from any action
							if ( isset( $action_params[ $param_name ] ) ) {
									$value = $action_params[ $param_name ];
								if ( is_array( $value ) ) {
									return implode( ', ', array_filter( $value ) );
								}
									return strval( $value );
							}
						}

						if ( isset( $input_data[ $node_id ]['content']['action_params'][ $field_path ] ) ) {
							$value = $input_data[ $node_id ]['content']['action_params'][ $field_path ];
							if ( is_array( $value ) ) {
								return implode( ', ', array_filter( $value ) );
							}
							return strval( $value );
						}

						WP_AI_Workflows_Utilities::debug_log(
							'Accessing chat action params',
							'debug',
							array(
								'field_path'       => $field_path,
								'available_params' => array_keys( $input_data[ $node_id ]['content']['action_params'] ),
								'value_exists'     => isset( $input_data[ $node_id ]['content']['action_params'][ $field_path ] ),
							)
						);

						$node_content = $input_data[ $node_id ]['content'];
					} elseif ( $input_data[ $node_id ]['type'] === 'trigger' ) {
						$node_content = $input_data[ $node_id ]['content'];

						// Special handling for trigger node content if it's the formatted data
						if ( is_array( $node_content ) && isset( $node_content['formatted_data'] ) ) {
							$node_content = $node_content['formatted_data'];
						}

						if ( is_string( $node_content ) && $node_content !== '' ) {
							$first_char = substr( $node_content, 0, 1 );
							if ( $first_char === '{' || $first_char === '[' ) {
								$decoded = json_decode( $node_content, true );
								if ( json_last_error() === JSON_ERROR_NONE ) {
									$node_content = $decoded;

									WP_AI_Workflows_Utilities::debug_log(
										'Decoded JSON string in trigger node content',
										'debug',
										array(
											'field_path'   => $field_path,
											'decoded_keys' => is_array( $node_content ) ? array_keys( $node_content ) : 'not_array',
										)
									);
								}
							}
						}

						if ( strpos( $field_path, '.' ) !== false ) {
							$value = self::get_nested_value( $node_content, $field_path );
							if ( $value !== null ) {
								if ( is_array( $value ) ) {
									return implode( ', ', array_filter( $value ) );
								}
								return strval( $value );
							}
						}

						if ( isset( $node_content[ $field_path ] ) ) {
							$field_value = $node_content[ $field_path ];
							if ( is_array( $field_value ) ) {
								return implode( ', ', array_filter( $field_value ) );
							}
							return strval( $field_value );
						}

						WP_AI_Workflows_Utilities::debug_log(
							'Trigger node field access',
							'debug',
							array(
								'field_path'   => $field_path,
								'node_content' => $node_content,
								'field_exists' => isset( $node_content[ $field_path ] ),
							)
						);
					} elseif ( $input_data[ $node_id ]['type'] === 'firecrawl' ) {
						$node_content = $input_data[ $node_id ]['content'];

						// Handle structured extraction output (v2 json/agent + legacy extract).
						$structured_prefixes = array( 'extract.', 'json.', 'agent.' );
						$matched_prefix      = '';
						foreach ( $structured_prefixes as $prefix ) {
							if ( strpos( $field_path, $prefix ) === 0 ) {
								$matched_prefix = $prefix;
								break;
							}
						}

						if ( '' !== $matched_prefix ) {
							$structured_path = substr( $field_path, strlen( $matched_prefix ) );

							// Candidate roots, newest v2 shape first, legacy last.
							$roots = array();
							if ( isset( $node_content['data']['json'] ) ) {
								$roots[] = $node_content['data']['json'];
							}
							if ( isset( $node_content['data'] ) && is_array( $node_content['data'] ) ) {
								$roots[] = $node_content['data'];
							}
							if ( isset( $node_content['content']['data']['extract'] ) ) {
								$roots[] = $node_content['content']['data']['extract'];
							}
							if ( isset( $node_content['data']['extract'] ) ) {
								$roots[] = $node_content['data']['extract'];
							}

							$path_parts = array_filter( explode( '.', $structured_path ), 'strlen' );

							foreach ( $roots as $root ) {
								$current = $root;
								$found   = true;
								foreach ( $path_parts as $part ) {
									if ( is_array( $current ) && array_key_exists( $part, $current ) ) {
										$current = $current[ $part ];
									} else {
										$found = false;
										break;
									}
								}
								if ( $found ) {
									if ( is_array( $current ) || is_object( $current ) ) {
										return wp_json_encode( $current );
									}
									return strval( $current );
								}
							}

							WP_AI_Workflows_Utilities::debug_log(
								'Firecrawl structured field not found',
								'debug',
								array( 'field_path' => $field_path )
							);
							return $matches[0];
						}

						if ( isset( $node_content['content'] ) ) {
							return is_array( $node_content['content'] )
							? json_encode( $node_content['content'] )
							: strval( $node_content['content'] );
						}
					} else {
						$node_content = $input_data[ $node_id ]['content'];

						if ( is_string( $node_content ) && $node_content !== '' ) {
							$first_char = substr( $node_content, 0, 1 );
							if ( $first_char === '{' || $first_char === '[' ) {
								$decoded = json_decode( $node_content, true );
								if ( json_last_error() === JSON_ERROR_NONE ) {
									$node_content = $decoded;
								}
							}
						}
					}

					list( $found, $resolved ) = self::resolve_ai_output_field( $node_content, $field_path );
					if ( $found ) {
						return $resolved;
					}

					WP_AI_Workflows_Utilities::debug_log(
						'Field not found in node content',
						'warning',
						array(
							'field_path'        => $field_path,
							'node_content_type' => gettype( $node_content ),
						)
					);
				}
				return $matches[0];
			},
			$content
		);

		return $content;
	}

	/**
	 * Resolve a named field ([[field] from nodeId]) out of an AI/generic node's
	 * stored content, tolerant of the several shapes that content can take.
	 *
	 * Resolution order:
	 *   1. If content is a JSON string, decode it first.
	 *   2. Dotted path (a.b.c) via get_nested_value().
	 *   3. Exact top-level key.
	 *   4. Case-insensitive top-level key (models sometimes return "Title"/"Body"
	 *      when the schema/prompt asked for "title"/"body").
	 *   5. Recurse into a nested `content` value - this reaches a structured JSON
	 *      blob stored as a string under the AI tools/RAG envelope
	 *      ({content, citations, search_results}), which was the cause of the
	 *      leaked-tag bug when feeding structured fields into a Post node.
	 *
	 * @param mixed  $node_content Stored node content (array|string|scalar).
	 * @param string $field_path   The requested field path.
	 * @return array{0:bool,1:string|null} [found, stringified value].
	 */
	private static function resolve_ai_output_field( $node_content, $field_path ) {
		if ( is_string( $node_content ) ) {
			$trimmed = trim( $node_content );
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
				$decoded = json_decode( $trimmed, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$repaired = self::repair_jsonish( $trimmed );
					$decoded  = ( null !== $repaired ) ? json_decode( $repaired, true ) : null;
				}
				if ( is_array( $decoded ) ) {
					$node_content = $decoded;
				} else {
					// Decode and repair both failed: pull the requested field
					// straight out of the raw JSON-ish text so genuinely sloppy
					// model output still resolves instead of leaking the tag.
					$regex = self::extract_field_from_jsonish( $trimmed, $field_path );
					return ( null !== $regex ) ? array( true, $regex ) : array( false, null );
				}
			}
		}

		if ( ! is_array( $node_content ) ) {
			return array( false, null );
		}

		// 1) Dotted nested path.
		if ( strpos( $field_path, '.' ) !== false ) {
			$value = self::get_nested_value( $node_content, $field_path );
			if ( null !== $value ) {
				return array( true, self::stringify_field_value( $value ) );
			}
		}

		// 2) Exact top-level key. A directly matched field wins outright - never
		// recurse into its own value hunting a same-named nested key (that emptied
		// a flat decoded `content` whose value merely started with '{').
		if ( array_key_exists( $field_path, $node_content ) ) {
			return array( true, self::stringify_field_value( $node_content[ $field_path ] ) );
		}

		// 3) Case-insensitive top-level key.
		foreach ( $node_content as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, $field_path ) ) {
				return array( true, self::stringify_field_value( $value ) );
			}
		}

		// 4) Key absent at this level: dig into a nested `content` envelope
		// (legacy tools/RAG output, or a single-blob content that is itself JSON).
		// Guard against infinite recursion: only recurse when the nested value differs.
		if ( isset( $node_content['content'] ) && $node_content['content'] !== $node_content ) {
			list( $found, $resolved ) = self::resolve_ai_output_field( $node_content['content'], $field_path );
			if ( $found ) {
				return array( true, $resolved );
			}
		}

		return array( false, null );
	}

	/**
	 * Best-effort repair of the common JSON mistakes models make, applied only
	 * after a raw decode has already failed: escape raw control characters
	 * (newline/carriage-return/tab) that sit inside string literals, drop trailing
	 * commas before } or ], and normalise smart quotes. Conservative - never
	 * touches structure, only makes an otherwise-valid object parseable.
	 *
	 * @param string $json Raw JSON-ish text.
	 * @return string|null Repaired text, or null.
	 */
	private static function repair_jsonish( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$json = strtr(
			$json,
			array(
				"\xE2\x80\x9C" => '"',
				"\xE2\x80\x9D" => '"',
				"\xE2\x80\x98" => "'",
				"\xE2\x80\x99" => "'",
			)
		);

		$out     = '';
		$in_str  = false;
		$escaped = false;
		$len     = strlen( $json );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[ $i ];
			if ( $escaped ) {
				$out    .= $ch;
				$escaped = false;
				continue;
			}
			if ( '\\' === $ch ) {
				$out    .= $ch;
				$escaped = true;
				continue;
			}
			if ( '"' === $ch ) {
				$in_str = ! $in_str;
				$out   .= $ch;
				continue;
			}
			if ( $in_str ) {
				if ( "\n" === $ch ) {
					$out .= '\\n';
					continue;
				}
				if ( "\r" === $ch ) {
					$out .= '\\r';
					continue;
				}
				if ( "\t" === $ch ) {
					$out .= '\\t';
					continue;
				}
			}
			$out .= $ch;
		}

		return preg_replace( '/,\s*([}\]])/', '$1', $out );
	}

	/**
	 * Last-ditch extraction of a single string field from JSON-ish text that will
	 * not decode even after repair. Matches "<field>": "<value>" (case-insensitive
	 * on the key, honouring backslash escapes in the value) and unescapes the
	 * result. Returns null when the field is absent.
	 *
	 * @param string $json  Raw JSON-ish text.
	 * @param string $field Requested field name.
	 * @return string|null
	 */
	private static function extract_field_from_jsonish( $json, $field ) {
		if ( ! is_string( $json ) || '' === $json || '' === (string) $field ) {
			return null;
		}
		$pattern = '/"' . preg_quote( (string) $field, '/' ) . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i';
		if ( preg_match( $pattern, $json, $m ) ) {
			$unescaped = json_decode( '"' . $m[1] . '"' );
			return is_string( $unescaped ) ? $unescaped : stripcslashes( $m[1] );
		}
		return null;
	}

	/**
	 * Stringify a resolved field value for tag substitution: a list of scalars is
	 * joined with ", "; a nested array/object is JSON-encoded; booleans become
	 * "true"/"false"; everything else is cast to string.
	 *
	 * @param mixed $value Resolved value.
	 * @return string
	 */
	private static function stringify_field_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			$is_list    = array_keys( $value ) === range( 0, count( $value ) - 1 );
			$all_scalar = true;
			foreach ( $value as $item ) {
				if ( is_array( $item ) || is_object( $item ) ) {
					$all_scalar = false;
					break;
				}
			}
			if ( $is_list && $all_scalar ) {
				return implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
			}
			return wp_json_encode( $value );
		}
		return strval( $value );
	}



	private static function execute_with_retry( $url, $args, $retry_count ) {
		$attempts     = 0;
		$max_attempts = max( 1, $retry_count + 1 );
		$last_error   = null;

		do {
			if ( $attempts > 0 ) {
				// Exponential backoff with jitter
				$delay = min( pow( 2, $attempts ) + wp_rand( 0, 1000 ) / 1000, 30 );
				sleep( $delay );
			}

			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$status = wp_remote_retrieve_response_code( $response );
				if ( $status < 500 ) {
					return $response;
				}
				$last_error = new WP_Error( 'http_error', "HTTP Error: $status" );
			} else {
				$last_error = $response;
			}

			++$attempts;
		} while ( $attempts < $max_attempts );

		return $last_error;
	}

	private static function process_api_response( $response, $node ) {
		if ( is_wp_error( $response ) ) {
			return self::create_node_data( 'error', $response->get_error_message() );
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );

		$decoded_body = json_decode( $body, true );
		$data         = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded_body : $body;

		$extracted_data = $data;
		if ( ! empty( $node['data']['responseConfig']['jsonPath'] ) ) {
			try {
				if ( ! class_exists( '\Flow\JSONPath\JSONPath' ) ) {
					require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'vendor/autoload.php';
				}

				if ( is_array( $data ) ) {
					$jsonPath       = new \Flow\JSONPath\JSONPath( $data );
					$extracted_data = $jsonPath->find( $node['data']['responseConfig']['jsonPath'] );

					if ( $extracted_data instanceof \Flow\JSONPath\JSONPath ) {
						$extracted_data = $extracted_data->getData();
					}
				}
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'JSONPath extraction failed',
					'warning',
					array(
						'error'     => $e->getMessage(),
						'path'      => $node['data']['responseConfig']['jsonPath'],
						'data_type' => gettype( $data ),
					)
				);
				$extracted_data = $data;
			}
		}

		$formatted_response = array(
			'status'       => $status,
			'headers'      => $headers,
			'data'         => $extracted_data,
			'raw_response' => $data,
		);

		return self::create_node_data( 'apiCall', $formatted_response );
	}

	/**
	 * Execute a Loop / While node.
	 *
	 * Ported from the SaaS platform's LoopNodeV4 processor so a workflow with a
	 * loop produces the SAME result whether it runs Local (this PHP engine) or
	 * Cloud (the platform engine). The plugin engine is a single-pass
	 * topological executor, so - unlike the platform's re-entrant engine - this
	 * node OWNS its body sub-branch: it runs the body once per iteration inline,
	 * exposing the per-item variables the platform exposes, then hands control
	 * back to the "After loop" (completed) continuation.
	 *
	 * Per-iteration the loop node's own output is the iteration data with the
	 * platform's exact field names, so body nodes reference the current item via
	 *   [[currentItem] from LOOP_ID]  [[currentIndex] from LOOP_ID]
	 *   [[totalCount] from LOOP_ID]   [[isFirst] from LOOP_ID]  [[isLast] from LOOP_ID]
	 *
	 * @param array  $loop_node     The loop node.
	 * @param array  $node_data     Accumulated outputs of already-executed nodes.
	 * @param array  $edges         Workflow edges.
	 * @param array  $sorted_nodes  Topologically-sorted nodes (execution order).
	 * @param mixed  $execution_id  Current execution id.
	 * @return array {
	 *     @type array $result       Loop node result ({ type, content }).
	 *     @type array $body_nodes   Body node ids the engine must skip in its own pass.
	 *     @type array $body_outputs Last-iteration body outputs (for the UI).
	 * }
	 */
	public static function execute_loop_node( $loop_node, $node_data, $edges, $sorted_nodes, $execution_id ) {
		$loop_id = isset( $loop_node['id'] ) ? $loop_node['id'] : '';
		$data    = ( isset( $loop_node['data'] ) && is_array( $loop_node['data'] ) ) ? $loop_node['data'] : array();

		$loop_type         = isset( $data['loopType'] ) ? $data['loopType'] : 'forEach';
		$output_mode       = isset( $data['outputMode'] ) ? $data['outputMode'] : 'accumulate';
		$continue_on_error = isset( $data['continueOnError'] ) ? (bool) $data['continueOnError'] : true;

		// Hard safety cap - fail-closed on runaway loops regardless of config.
		$hard_cap       = 1000;
		$max_iterations = isset( $data['maxIterations'] ) ? intval( $data['maxIterations'] ) : 100;
		if ( $max_iterations < 1 ) {
			$max_iterations = 1;
		}
		if ( $max_iterations > $hard_cap ) {
			$max_iterations = $hard_cap;
		}

		$input_data = self::get_node_input_data( $loop_id, $edges, $node_data );

		$body_ids     = WP_AI_Workflows_Workflow::get_downstream_nodes( $loop_id, $edges, 'iteration' );
		$body_ordered = array();
		foreach ( $sorted_nodes as $sn ) {
			if ( in_array( $sn['id'], $body_ids, true ) ) {
				// Nested loops / human-input / triggers inside a loop body are
				// not supported in this port - skip them defensively.
				if ( in_array( $sn['type'], array( 'loop', 'humanInput', 'trigger' ), true ) ) {
					continue;
				}
				$body_ordered[] = $sn;
			}
		}

		$results           = array();
		$errors            = array();
		$items_for_output  = array();
		$last_body_outputs = array();

		if ( 'while' === $loop_type ) {
			$while_input      = isset( $data['whileInput'] ) ? $data['whileInput'] : '';
			$while_comparison = isset( $data['whileComparison'] ) ? $data['whileComparison'] : 'equals';
			$while_value      = isset( $data['whileValue'] ) ? $data['whileValue'] : '';

			$context = $node_data; // Grows with each iteration's body outputs.
			$index   = 0;
			while ( $index < $max_iterations ) {
				$while_context = self::get_node_input_data( $loop_id, $edges, $context );
				$current_value = self::replace_input_tags( $while_input, $while_context );

				if ( ! self::loop_eval_condition( $current_value, $while_comparison, $while_value ) ) {
					break;
				}

				$iteration_data = array(
					'currentItem'  => $current_value,
					'currentIndex' => $index,
					'totalCount'   => $max_iterations,
					'isFirst'      => ( 0 === $index ),
					'isLast'       => false,
				);

				$run = self::loop_run_body_once( $body_ordered, $iteration_data, $loop_id, $context, $edges, $execution_id );

				if ( null !== $run['error'] ) {
					$errors[] = array(
						'index' => $index,
						'error' => $run['error'],
					);
					if ( ! $continue_on_error ) {
						break;
					}
				}

				$results[]          = $run['collected'];
				$items_for_output[] = $current_value;
				$last_body_outputs  = $run['outputs'];

				// Feed body outputs + this iteration's collected result back into
				// the context so the next condition check sees fresh data.
				$context             = $run['outputs'];
				$context[ $loop_id ] = array(
					'type'    => 'loop',
					'content' => $run['collected'],
				);
				++$index;
			}
		} else {
			$items = self::loop_build_items( $loop_type, $data, $input_data );
			if ( ! is_array( $items ) ) {
				$items = array();
			}
			$total = count( $items );
			if ( $total > $max_iterations ) {
				$items = array_slice( $items, 0, $max_iterations );
				$total = $max_iterations;
			}
			$items_for_output = $items;

			foreach ( $items as $index => $item ) {
				$iteration_data = array(
					'currentItem'  => $item,
					'currentIndex' => $index,
					'totalCount'   => $total,
					'isFirst'      => ( 0 === $index ),
					'isLast'       => ( $index === $total - 1 ),
				);

				$run = self::loop_run_body_once( $body_ordered, $iteration_data, $loop_id, $node_data, $edges, $execution_id );

				if ( null !== $run['error'] ) {
					$errors[] = array(
						'index' => $index,
						'error' => $run['error'],
					);
					if ( ! $continue_on_error ) {
						break;
					}
				}

				$results[]         = $run['collected'];
				$last_body_outputs = $run['outputs'];
			}
		}

		$final = self::loop_assemble_output( $items_for_output, $results, $errors, $output_mode );

		// Surface only the body node outputs (not the outer-context copy) so the
		// builder can show them as executed.
		$body_only_outputs = array();
		foreach ( $body_ordered as $bn ) {
			if ( isset( $last_body_outputs[ $bn['id'] ] ) ) {
				$body_only_outputs[ $bn['id'] ] = $last_body_outputs[ $bn['id'] ];
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Loop node executed',
			'info',
			array(
				'loop_id'        => $loop_id,
				'loop_type'      => $loop_type,
				'iterations'     => count( $results ),
				'errors'         => count( $errors ),
				'body_node_ids'  => $body_ids,
				'max_iterations' => $max_iterations,
			)
		);

		return array(
			'result'       => array(
				'type'    => 'loop',
				'content' => $final,
			),
			'body_nodes'   => $body_ids,
			'body_outputs' => $body_only_outputs,
		);
	}

	/**
	 * Run the loop body sub-branch once for a single iteration.
	 *
	 * @param array  $body_ordered   Body nodes in execution order.
	 * @param array  $iteration_data Per-item variables ({ currentItem, currentIndex, ... }).
	 * @param string $loop_id        Loop node id.
	 * @param array  $base_node_data Node data to seed each iteration with.
	 * @param array  $edges          Workflow edges.
	 * @param mixed  $execution_id   Current execution id.
	 * @return array { collected, outputs, error }
	 */
	private static function loop_run_body_once( $body_ordered, $iteration_data, $loop_id, $base_node_data, $edges, $execution_id ) {
		$iter_node_data              = $base_node_data;
		$iter_node_data[ $loop_id ]  = array(
			'type'    => 'loop',
			'content' => $iteration_data,
		);
		$collected = null;
		$error     = null;

		foreach ( $body_ordered as $bnode ) {
			try {
				$bres = self::execute_node( $bnode, $iter_node_data, $edges, $execution_id );
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
				break;
			}

			if ( null !== $bres ) {
				$iter_node_data[ $bnode['id'] ] = $bres;

				if ( is_array( $bres ) && isset( $bres['type'] ) && 'error' === $bres['type'] ) {
					$error = ( isset( $bres['content'] ) && is_string( $bres['content'] ) )
						? $bres['content']
						: 'Loop body node error';
				}

				$collected = ( is_array( $bres ) && array_key_exists( 'content', $bres ) )
					? $bres['content']
					: $bres;
			}
		}

		return array(
			'collected' => $collected,
			'outputs'   => $iter_node_data,
			'error'     => $error,
		);
	}

	/**
	 * Build the list of items to iterate over for for-each / count loops.
	 *
	 * @param string $loop_type  'forEach' | 'count'.
	 * @param array  $data       Loop node data.
	 * @param array  $input_data Resolved input data for the loop node.
	 * @return array
	 */
	private static function loop_build_items( $loop_type, $data, $input_data ) {
		if ( 'count' === $loop_type ) {
			$count = isset( $data['iterationCount'] ) ? intval( $data['iterationCount'] ) : 0;
			if ( $count < 0 ) {
				$count = 0;
			}
			$items = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$items[] = $i;
			}
			return $items;
		}

		// for-each: resolve the configured collection, else fall back to the
		// first connected node's content (auto-detect an array).
		$collection = isset( $data['content'] ) ? $data['content'] : '';
		if ( is_string( $collection ) && '' !== $collection ) {
			$resolved = self::replace_input_tags( $collection, $input_data );
			return self::loop_parse_array( $resolved );
		}

		foreach ( $input_data as $src ) {
			if ( is_array( $src ) && array_key_exists( 'content', $src ) ) {
				return self::loop_parse_array( $src['content'] );
			}
		}

		return array();
	}

	/**
	 * Coerce an arbitrary input into an array of items. Port of LoopNodeV4's
	 * parseArray (JSON arrays, comma / newline lists, {items|data|results|content}
	 * wrappers, single-value wrapping).
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	private static function loop_parse_array( $input ) {
		if ( is_array( $input ) ) {
			if ( self::loop_is_list( $input ) ) {
				return $input;
			}
			foreach ( array( 'items', 'data', 'results', 'content' ) as $field ) {
				if ( isset( $input[ $field ] ) && is_array( $input[ $field ] ) ) {
					return $input[ $field ];
				}
			}
			return array( $input );
		}

		if ( is_string( $input ) ) {
			$trimmed = trim( $input );
			if ( '' === $trimmed ) {
				return array();
			}
			if ( strlen( $trimmed ) >= 2 && '[' === $trimmed[0] && ']' === substr( $trimmed, -1 ) ) {
				$parsed = json_decode( $trimmed, true );
				if ( is_array( $parsed ) ) {
					return $parsed;
				}
			}
			if ( false !== strpos( $trimmed, ',' ) ) {
				return array_values(
					array_filter(
						array_map( 'trim', explode( ',', $trimmed ) ),
						function ( $v ) {
							return '' !== $v;
						}
					)
				);
			}
			if ( false !== strpos( $trimmed, "\n" ) ) {
				return array_values(
					array_filter(
						array_map( 'trim', explode( "\n", $trimmed ) ),
						function ( $v ) {
							return '' !== $v;
						}
					)
				);
			}
			return array( $trimmed );
		}

		if ( null === $input ) {
			return array();
		}

		return array( $input );
	}

	/**
	 * Whether an array is a sequential list (0..n-1 keys). array_is_list polyfill.
	 *
	 * @param array $arr Array to test.
	 * @return bool
	 */
	private static function loop_is_list( $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$expected = 0;
		foreach ( $arr as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	/**
	 * Assemble the loop's final output. Port of LoopNodeV4's outputMode handling
	 * (accumulate | last | concatenate | individual).
	 *
	 * @param array  $items       Items iterated over.
	 * @param array  $results     Collected per-iteration results.
	 * @param array  $errors      Per-iteration errors ({ index, error }).
	 * @param string $output_mode Output mode.
	 * @return array
	 */
	private static function loop_assemble_output( $items, $results, $errors, $output_mode ) {
		$processed   = count( $results );
		$total       = count( $items );
		$error_count = count( $errors );

		switch ( $output_mode ) {
			case 'last':
				$last = $processed > 0 ? $results[ $processed - 1 ] : null;
				return array(
					'content'        => $last,
					'lastResult'     => $last,
					'lastItem'       => $total > 0 ? $items[ $total - 1 ] : null,
					'processedCount' => $processed,
					'totalCount'     => $total,
				);

			case 'concatenate':
				$parts = array();
				foreach ( $results as $r ) {
					if ( null === $r ) {
						continue;
					}
					if ( is_string( $r ) ) {
						$parts[] = $r;
					} elseif ( is_array( $r ) && isset( $r['content'] ) ) {
						$parts[] = is_string( $r['content'] ) ? $r['content'] : wp_json_encode( $r['content'] );
					} else {
						$parts[] = wp_json_encode( $r );
					}
				}
				$concat = implode( "\n", $parts );
				return array(
					'content'          => $concat,
					'concatenatedText' => $concat,
					'separator'        => "\n",
					'itemCount'        => count( $parts ),
					'totalLength'      => strlen( $concat ),
				);

			case 'individual':
				$paired = array();
				foreach ( $items as $i => $item ) {
					$paired[] = array(
						'input'    => $item,
						'output'   => isset( $results[ $i ] ) ? $results[ $i ] : null,
						'index'    => $i,
						'hasError' => self::loop_has_error( $errors, $i ),
					);
				}
				return array(
					'content'        => $paired,
					'results'        => $results,
					'items'          => $items,
					'paired'         => $paired,
					'processedCount' => $processed,
					'totalCount'     => $total,
					'errors'         => $errors,
				);

			case 'accumulate':
			default:
				return array(
					'content'        => $results,
					'results'        => $results,
					'items'          => $items,
					'processedCount' => $processed,
					'totalCount'     => $total,
					'successCount'   => $processed - $error_count,
					'errorCount'     => $error_count,
					'errors'         => $errors,
					'firstResult'    => $processed > 0 ? $results[0] : null,
					'lastResult'     => $processed > 0 ? $results[ $processed - 1 ] : null,
				);
		}
	}

	/**
	 * Whether an error was recorded for a given iteration index.
	 *
	 * @param array $errors Errors list.
	 * @param int   $index  Iteration index.
	 * @return bool
	 */
	private static function loop_has_error( $errors, $index ) {
		foreach ( $errors as $err ) {
			if ( isset( $err['index'] ) && $err['index'] === $index ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate a while-loop continuation condition. Mirrors the comparators used
	 * by execute_condition_node.
	 *
	 * @param mixed  $input      Current value.
	 * @param string $comparison Comparison operator.
	 * @param mixed  $value      Comparison value.
	 * @return bool
	 */
	private static function loop_eval_condition( $input, $comparison, $value ) {
		$input_str = is_array( $input ) ? wp_json_encode( $input ) : (string) $input;

		switch ( $comparison ) {
			case 'equals':
				return $input_str === (string) $value;
			case 'notEquals':
				return $input_str !== (string) $value;
			case 'contains':
				return '' !== (string) $value && false !== strpos( $input_str, (string) $value );
			case 'greaterThan':
				return floatval( $input ) > floatval( $value );
			case 'lessThan':
				return floatval( $input ) < floatval( $value );
			default:
				return false;
		}
	}

	private static function create_node_data( $type, $content, $input = null ) {

		if ( is_string( $content ) ) {
			$content = html_entity_decode( stripslashes( $content ), ENT_QUOTES, 'UTF-8' );
		} elseif ( is_array( $content ) ) {
			array_walk_recursive(
				$content,
				function ( &$item ) {
					if ( is_string( $item ) ) {
						$item = html_entity_decode( stripslashes( $item ), ENT_QUOTES, 'UTF-8' );
					}
				}
			);
		}

		$result = array(
			'type'    => $type,
			'content' => $content,
		);

		if ( $input !== null ) {
			$result['input'] = $input;
		}

		return $result;
	}

	private static function process_ai_response( $response ) {
		$response = str_replace( '<br>', "\n", $response );
		$response = str_replace( '<br />', "\n", $response );

		$response = self::markdown_to_html( $response );

		$response = preg_replace( '/<\/li><li>/', "</li>\n<li>", $response );

		return $response;
	}

	private static function markdown_to_html( $text ) {
		$text = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text );

		$text = preg_replace( '/^\s*-\s+/m', '<li>', $text );
		$text = preg_replace( '/(<li>.*?)(\n|$)/s', '$1</li>$2', $text );
		$text = preg_replace( '/((?:<li>.*?<\/li>\s*)+)/', '<ul>$1</ul>', $text );

		$text = preg_replace( '/(?<!>)\n(?!<)/', '<br>', $text );

		return $text;
	}
}
