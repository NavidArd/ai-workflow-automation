<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Chat_Handler {
	private $session;
	private $model;
	private $system_prompt;
	private $model_params;
	private $actions;
	private $openai_tools;
	private $current_message;
	private $page_context;
	private $key_source = '';
	// Per-node AI source (additive): '' = legacy (implicit routing), 'credits' =
	// keyless metered proxy, 'byok' = site's own keys. An explicit choice
	// overrides the implicit preference with no silent fallback either way.
	private $ai_source = '';
	private $knowledge_base = null;
	private $memory_config = null;
	private $agent_config  = null;
	// Whether the visitor sees the agent's activity/step-trace (default true).
	// When false, steps are stripped at the source before reaching the browser.
	private $show_agent_activity = true;

	public function __construct( $workflow_id, $session_id = null ) {
		$this->session = new WP_AI_Workflows_Chat_Session( $workflow_id, $session_id );
		$this->load_workflow_config();
	}

	/**
	 * Load workflow configuration
	 */
	private function load_workflow_config() {
		global $wpdb;
		$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';

		// Always load from workflow config first
		$workflow = $this->get_workflow_by_id( $this->session->get_workflow_id() );
		if ( $workflow ) {
			$chat_node = $this->find_chat_node( $workflow['nodes'] );
			if ( $chat_node ) {
				$this->model         = $chat_node['data']['model'];
				$this->system_prompt = $chat_node['data']['systemPrompt'];
				$this->model_params  = $chat_node['data']['modelParams'];
				$this->actions       = isset( $chat_node['data']['actions'] ) ? $chat_node['data']['actions'] : array();
				$this->openai_tools  = isset( $chat_node['data']['openaiTools'] ) ? $chat_node['data']['openaiTools'] : null;
				$this->key_source    = isset( $chat_node['data']['keySource'] ) ? $chat_node['data']['keySource'] : '';
				$this->ai_source     = isset( $chat_node['data']['aiSource'] ) ? (string) $chat_node['data']['aiSource'] : '';
				$this->knowledge_base = isset( $chat_node['data']['knowledgeBase'] ) ? $chat_node['data']['knowledgeBase'] : null;
				$this->memory_config  = isset( $chat_node['data']['memory'] ) ? $chat_node['data']['memory'] : null;
				$this->agent_config   = isset( $chat_node['data']['agent'] ) ? $chat_node['data']['agent'] : null;
				// Per-node "Show agent activity" toggle (additive; absent = on).
				if ( isset( $chat_node['data']['behavior']['showAgentActivity'] ) ) {
					$this->show_agent_activity = (bool) $chat_node['data']['behavior']['showAgentActivity'];
				}
			}
		}

		// Fallback to latest execution if needed
		if ( ! $this->model ) {
			$execution = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i 
                WHERE workflow_id = %s 
                AND status = 'completed' 
                ORDER BY created_at DESC 
                LIMIT 1",
					$executions_table,
					$this->session->get_workflow_id()
				)
			);

			if ( $execution && $execution->output_data ) {
				$output_data = json_decode( $execution->output_data, true );

				foreach ( $output_data as $node_id => $node_output ) {
					if ( isset( $node_output['type'] ) && $node_output['type'] === 'chat' ) {
						$this->model         = $node_output['content']['model'] ?? null;
						$this->system_prompt = $node_output['content']['systemPrompt'] ?? null;
						$this->model_params  = $node_output['content']['modelParams'] ?? array();
						$this->actions       = $node_output['content']['actions'] ?? array();
						$this->openai_tools  = $node_output['content']['openaiTools'] ?? null;
						$this->ai_source     = isset( $node_output['content']['aiSource'] ) ? (string) $node_output['content']['aiSource'] : '';
						$this->knowledge_base = $node_output['content']['knowledgeBase'] ?? null;
						$this->memory_config = $node_output['content']['memory'] ?? null;
						$this->agent_config = $node_output['content']['agent'] ?? null;
						if ( isset( $node_output['content']['behavior']['showAgentActivity'] ) ) {
							$this->show_agent_activity = (bool) $node_output['content']['behavior']['showAgentActivity'];
						}
						break;
					}
				}
			}
		}

		if ( ! $this->model ) {
			WP_AI_Workflows_Utilities::debug_log(
				'No model found in chat configuration',
				'error',
				array(
					'workflow_id' => $this->session->get_workflow_id(),
				)
			);
		}
	}

	public function get_session() {
		return $this->session;
	}

	/**
	 * Main message handler
	 */
	public function handle_message( $message, $stream = false, $page_context = null, $is_initial_message = false ) {
		$allowed_origins = array( get_site_url() );
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) && ! in_array( $_SERVER['HTTP_ORIGIN'], $allowed_origins ) ) {
			throw new Exception( 'Invalid request origin' );
		}

		$this->page_context = $page_context;

		if ( get_transient( 'wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id() ) ) {
			$this->load_workflow_config();
			delete_transient( 'wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id() );
		}

		if ( strlen( $message ) > 2000 ) {
			throw new Exception( 'Message too long' );
		}
		if ( empty( trim( $message ) ) ) {
			throw new Exception( 'Empty message' );
		}

		$message = wp_kses(
			$message,
			array(
				'a'      => array(
					'href'   => array(),
					'target' => array( '_blank' ),
				),
				'b'      => array(),
				'strong' => array(),
				'i'      => array(),
				'em'     => array(),
				'code'   => array(),
				'pre'    => array(),
			)
		);

		if ( $is_initial_message ) {
			try {
				$original_system_prompt = $this->system_prompt;

				$initial_prompt  = $original_system_prompt;
				$initial_prompt .= "\n\n--- ADDITIONAL INSTRUCTIONS FOR INITIAL GREETING ---\n\n";
				$initial_prompt .= 'You are generating the initial greeting message for this chat session. ';
				$initial_prompt .= 'This will be the first message the user sees when they open the chat.';

				if ( ! empty( $page_context ) ) {
					$initial_prompt .= "\n\nThe user is currently on a page with the following information:";

					if ( ! empty( $page_context['page_title'] ) ) {
						$initial_prompt .= "\nTitle: " . esc_html( $page_context['page_title'] );
					}

					if ( ! empty( $page_context['page_url'] ) ) {
						$initial_prompt .= "\nURL: " . esc_url( $page_context['page_url'] );
					}

					if ( ! empty( $page_context['page_type'] ) ) {
						$initial_prompt .= "\nPage type: " . esc_html( $page_context['page_type'] );
					}

					if ( ! empty( $page_context['content_summary'] ) ) {
						$initial_prompt .= "\nContent summary: " . esc_html( $page_context['content_summary'] );
					}

					if ( ! empty( $page_context['product_info'] ) ) {
						$product         = $page_context['product_info'];
						$initial_prompt .= "\nThe user is viewing a product:";

						if ( ! empty( $product['price'] ) ) {
							$initial_prompt .= "\n- Price: " . esc_html( $product['price'] );
						}

						if ( ! empty( $product['sku'] ) ) {
							$initial_prompt .= "\n- SKU: " . esc_html( $product['sku'] );
						}

						if ( ! empty( $product['stock_status'] ) ) {
							$initial_prompt .= "\n- Stock Status: " . esc_html( $product['stock_status'] );
						}

						if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
							$initial_prompt .= "\n- Categories: " . esc_html( implode( ', ', $product['categories'] ) );
						}
					}
				}

				$initial_prompt .= "\n\nWrite a brief, friendly initial greeting message that:";
				$initial_prompt .= "\n1. Is contextually relevant to the page the user is viewing";
				$initial_prompt .= "\n2. Is brief and concise (2-3 sentences)";
				$initial_prompt .= "\n3. Maintains the tone and character established in the main instructions above";
				$initial_prompt .= "\n4. Invites the user to ask questions relevant to the current page";
				$initial_prompt .= "\n5. Avoids being overly salesy or pushy";

				$this->system_prompt = $initial_prompt;

				$message = 'Generate an appropriate initial greeting for this website visitor.';

				$result = $this->process_normal_message( $message, $stream );

				$this->system_prompt = $original_system_prompt;

				return $result;
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error in initial message generation',
					'error',
					array(
						'error' => $e->getMessage(),
					)
				);

				if ( isset( $original_system_prompt ) ) {
					$this->system_prompt = $original_system_prompt;
				}
			}
		}

		return $this->process_normal_message( $message, $stream );
	}

	/**
	 * Process normal messages
	 */
	private function process_normal_message( $message, $stream ) {
		try {
			if ( ! $this->session->can_send_message() ) {
				throw new Exception( 'Rate limit exceeded' );
			}

			// Human handoff (Phase 2a): if the conversation is already
			// human-controlled, or the visitor explicitly asks for a person,
			// route through the handoff path instead of the bot. Additive:
			// no-op unless handoff is enabled on the node.
			$handoff_response = $this->maybe_handle_handoff( $message );
			if ( null !== $handoff_response ) {
				return $handoff_response;
			}

			// Every chat node is an agent: when it has agentic capabilities
			// (Actions, or agent-decided handoff) run the bounded loop; otherwise
			// fall through to the legacy single-round path below.
			if ( $this->should_run_agent_loop() ) {
				return $this->process_agent_message( $message );
			}

			$this->current_message = $message;
			$context               = $this->prepare_context( $this->session->get_history() );

			$raw_response = $this->get_ai_response( $context );
			$processed = $this->process_response( $raw_response );

			$this->session->add_message( 'user', $message );
			$this->session->add_message( 'assistant', $processed['display_message'] );

			// Update opt-in memory (rolling summary + recent turns).
			$this->record_memory_turn( $message, $processed['display_message'] );

			if ( $processed['type'] === 'action' ) {
				return $this->handle_action_response( $processed );
			}

			return array(
				'type'            => $processed['type'],
				'display_message' => $processed['display_message'],
				'message'         => $processed['display_message'],
				'session_id'      => $this->session->get_session_id(),
				'citations'       => $processed['citations'] ?? null,
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error processing message',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw new Exception( 'Unable to process message. Please try again later.' );
		}
	}

	/**
	 * Reasoning models (gpt-5*, o1/o3/o4*) reject a non-default temperature/
	 * top_p/penalty (HTTP 400) and need `max_completion_tokens` instead of
	 * `max_tokens`, floored so hidden reasoning tokens can't empty the reply.
	 * No-op for non-reasoning models.
	 *
	 * @param array  $data  Request body already populated from model_params (mutated in place).
	 * @param string $model Model id (may carry a vendor/ prefix, e.g. `openai/gpt-5-nano`).
	 * @return void
	 */
	private function shape_reasoning_params( array &$data, $model ) {
		if ( ! WP_AI_Workflows_Utilities::is_reasoning_model( $model ) ) {
			return;
		}
		unset( $data['temperature'], $data['top_p'], $data['frequency_penalty'], $data['presence_penalty'] );
		if ( isset( $data['max_tokens'] ) ) {
			$data['max_completion_tokens'] = max( (int) $data['max_tokens'], WP_AI_Workflows_Utilities::MIN_REASONING_COMPLETION_TOKENS );
			unset( $data['max_tokens'] );
		}
	}

	/**
	 * Resolve which AI route this chat node's calls take: the keyless metered
	 * proxy (ROUTE_CREDITS) or the site's own provider keys (ROUTE_BYOK).
	 *
	 * 'credits' => ROUTE_CREDITS; never silently falls back to BYOK.
	 * 'byok'    => ROUTE_BYOK, even while connected; never silently meters credits.
	 * ''        => legacy node - defers to the workflow router's hidden global
	 *              `prefer_credits_when_connected` preference.
	 *
	 * Applies uniformly to every AI transport on the node (buffered reply,
	 * streaming reply, agent loop, and the handoff intent classifier).
	 *
	 * @return string WP_AI_Workflows_AI_Router::ROUTE_CREDITS | ROUTE_BYOK
	 */
	private function resolve_chat_route() {
		if ( ! class_exists( 'WP_AI_Workflows_AI_Router' ) ) {
			return 'byok';
		}
		if ( WP_AI_Workflows_AI_Router::ROUTE_CREDITS === $this->ai_source ) {
			return WP_AI_Workflows_AI_Router::ROUTE_CREDITS;
		}
		if ( WP_AI_Workflows_AI_Router::ROUTE_BYOK === $this->ai_source ) {
			return WP_AI_Workflows_AI_Router::ROUTE_BYOK;
		}
		// Legacy node - exactly today's behaviour (implicit workflow/global route).
		return WP_AI_Workflows_AI_Router::resolve( array( 'keySource' => $this->key_source ) );
	}

	/**
	 * Get AI response - route to appropriate API
	 */
	private function get_ai_response( $context ) {
		// Keyless credit routing: chat runs locally, but its AI calls can be
		// metered through the platform proxy (buffered, not streamed) when this
		// node's AI source resolves to credits. NO BYOK fallback on failure.
		if ( WP_AI_Workflows_AI_Router::ROUTE_CREDITS === $this->resolve_chat_route() ) {
			return $this->get_ai_response_via_credits( $context );
		}

		// Check if this is an OpenRouter model
		$is_openrouter = strpos( $this->model, '/' ) !== false &&
						strpos( $this->model, 'openai/' ) !== 0 &&
						! in_array(
							$this->model,
							array(
								// Current bare OpenAI ids (native OpenAI routing).
								'gpt-5.5',
								'gpt-5.1',
								'gpt-5',
								'gpt-5-mini',
								'gpt-5-nano',
								'gpt-4.1',
								'gpt-4.1-mini',
								'gpt-4o',
								'gpt-4o-mini',
								'o3',
								'o4-mini',
								'o1',
							)
						);

		if ( $is_openrouter ) {
			// OpenRouter uses Chat Completions API
			$tools = $this->prepare_openrouter_tools();
			return ! empty( $tools ) ?
				$this->call_openrouter_with_tools( $context, $tools ) :
				$this->call_openrouter( $context );
		} else {
			// OpenAI uses Responses API
			return $this->call_openai_responses_api( $context );
		}
	}

	/**
	 * Metered (keyless) chat completion via the platform proxy. Buffered /
	 * non-streaming; returns a Chat-Completions-shaped array so
	 * process_response() handles it unchanged. NO BYOK fallback on failure.
	 *
	 * @param array $context Prepared message array.
	 * @return array {choices:[{message:{content}}]}
	 * @throws Exception On any proxy failure (clear, non-charged message).
	 */
	private function get_ai_response_via_credits( $context ) {
		$payload = array(
			'model'    => $this->model,
			'messages' => $context,
		);
		if ( ! empty( $this->system_prompt ) ) {
			$payload['systemPrompt'] = $this->system_prompt;
		}
		if ( is_array( $this->model_params ) ) {
			if ( isset( $this->model_params['max_tokens'] ) ) {
				$payload['maxTokens'] = (int) $this->model_params['max_tokens'];
			}
			// Reasoning models (gpt-5*, o1/o3/o4*) reject a non-default
			// temperature outright; the credits proxy forwards straight to
			// the same providers, so apply the same guard client-side too.
			if ( isset( $this->model_params['temperature'] )
				&& ! WP_AI_Workflows_Utilities::is_reasoning_model( $this->model )
			) {
				$payload['temperature'] = (float) $this->model_params['temperature'];
			}
		}

		$response = WP_AI_Workflows_Platform_Client::proxy_ai( $payload );
		if ( is_wp_error( $response ) ) {
			// Never fall back to BYOK - surface a clear gate instead.
			switch ( $response->get_error_code() ) {
				case 'platform_credits':
					$msg = 'You are out of credits for keyless chat. Please top up to keep this chatbot on Credits, or switch its AI source to your own API keys.';
					break;
				case 'platform_disconnected':
					$msg = 'This site is not connected to a platform account, so keyless Credits chat is unavailable. Connect your site, or switch this chatbot\'s AI source to your own API keys.';
					break;
				default:
					$msg = 'The chat AI service is temporarily unavailable. Please try again shortly.';
					break;
			}
			throw new Exception( esc_html( $msg ) );
		}

		$content = isset( $response['content'] ) ? (string) $response['content'] : '';
		return array(
			'choices' => array(
				array( 'message' => array( 'content' => $content ) ),
			),
		);
	}

	/**
	 * Emit a credit-metered simple-chat completion over SSE.
	 *
	 * The keyless credits proxy is buffered (non-streaming), so for a streaming
	 * request that resolves to credits we fetch the buffered reply and push it
	 * to the browser as a single SSE content frame. Any gate/transport error is
	 * surfaced as an SSE error frame - never a BYOK fallback.
	 *
	 * @param array $context Prepared message array (already includes the user turn).
	 * @return void Exits after emitting [DONE].
	 */
	private function stream_credits_buffered_response( $context ) {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		ob_implicit_flush( true );

		echo 'data: ' . wp_json_encode(
			array(
				'content'    => '',
				'session_id' => $this->session->get_session_id(),
			)
		) . "\n\n";
		flush();

		try {
			$raw     = $this->get_ai_response_via_credits( $context );
			$content = isset( $raw['choices'][0]['message']['content'] )
				? (string) $raw['choices'][0]['message']['content']
				: '';

			echo 'data: ' . wp_json_encode( array( 'content' => $content ) ) . "\n\n";
			flush();

			if ( '' !== $content ) {
				$this->session->add_message( 'assistant', $content );
				$this->record_memory_turn( $this->current_message, $content );
			}
		} catch ( Exception $e ) {
			// Clear, non-charged gate - NOT a BYOK completion.
			echo 'data: ' . wp_json_encode(
				array(
					'error'   => true,
					'message' => $e->getMessage(),
				)
			) . "\n\n";
			flush();
		}

		echo "data: [DONE]\n\n";
		flush();
		exit;
	}

	/**
	 * OpenAI Responses API call
	 */
	private function call_openai_responses_api( $messages ) {
		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'No OpenAI API key is configured. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		$system_message = null;
		$user_messages  = array();

		foreach ( $messages as $msg ) {
			if ( $msg['role'] === 'system' ) {
				$system_message = $msg['content'];
			} else {
				$user_messages[] = $msg;
			}
		}

		$input = count( $user_messages ) === 1 ?
			$user_messages[0]['content'] :
			$user_messages;

		$tools = array();

		if ( ! empty( $this->openai_tools ) ) {
			$native_tools = $this->prepare_native_tools( $this->openai_tools );
			$tools        = array_merge( $tools, $native_tools );
		}

		if ( ! empty( $this->actions ) ) {
			$function_tools = $this->prepare_function_tools( $this->actions );
			$tools          = array_merge( $tools, $function_tools );
		}

		$model = strpos( $this->model, 'openai/' ) === 0 ?
			substr( $this->model, 7 ) : $this->model;

		$data = array(
			'model' => $model,
			'input' => $input,
			'store' => true,
		);

		if ( $system_message ) {
			$data['instructions'] = $system_message;
		}

		if ( ! empty( $tools ) ) {
			$data['tools'] = $tools;
		}

		// Reasoning models reject a non-default temperature/top_p (HTTP 400), so
		// omit them entirely; `max_output_tokens` is floored for reasoning
		// models so hidden reasoning tokens can't consume the whole budget.
		$is_reasoning_model = WP_AI_Workflows_Utilities::is_reasoning_model( $model );
		if ( ! $is_reasoning_model ) {
			if ( isset( $this->model_params['temperature'] ) ) {
				$data['temperature'] = floatval( $this->model_params['temperature'] );
			}
			if ( isset( $this->model_params['top_p'] ) ) {
				$data['top_p'] = floatval( $this->model_params['top_p'] );
			}
		}
		if ( isset( $this->model_params['max_tokens'] ) ) {
			$requested                 = intval( $this->model_params['max_tokens'] );
			$data['max_output_tokens'] = $is_reasoning_model
				? max( $requested, WP_AI_Workflows_Utilities::MIN_REASONING_COMPLETION_TOKENS )
				: $requested;
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Error calling OpenAI API: ' . esc_html( $response->get_error_message() ) );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );

		if ( $http_code >= 400 ) {
			$error_data    = json_decode( $body, true );
			$error_message = isset( $error_data['error']['message'] ) ?
				$error_data['error']['message'] :
				'HTTP ' . $http_code . ' error';
			throw new Exception( 'OpenAI API error: ' . esc_html( $error_message ) );
		}

		$result = json_decode( $body, true );

		if ( ! $result ) {
			throw new Exception( 'Invalid JSON response from OpenAI API' );
		}

		return $this->normalize_responses_to_chat_format( $result );
	}

	/**
	 * Prepare native OpenAI tools (web search, file search)
	 */
	private function prepare_native_tools( $tools_config ) {
		$tools = array();

		if ( isset( $tools_config['webSearch']['enabled'] ) && $tools_config['webSearch']['enabled'] ) {
			$tools[] = array( 'type' => 'web_search' );
		}

		if ( isset( $tools_config['fileSearch']['enabled'] ) &&
			$tools_config['fileSearch']['enabled'] &&
			! empty( $tools_config['fileSearch']['vectorStoreId'] ) ) {
			$tools[] = array(
				'type'             => 'file_search',
				'vector_store_ids' => array( $tools_config['fileSearch']['vectorStoreId'] ),
			);
		}

		return $tools;
	}

	/**
	 * Prepare function tools from actions
	 */
	private function prepare_function_tools( $actions ) {
		$tools = array();

		foreach ( $actions as $action ) {
			$properties = array();
			$required   = array();

			if ( ! empty( $action['fields'] ) ) {
				foreach ( $action['fields'] as $field ) {
					$property_def = array(
						'type'        => $this->map_field_type( $field['type'] ),
						'description' => $field['description'] ?? '',
					);

					if ( ! empty( $field['options'] ) ) {
						$property_def['enum'] = $field['options'];
					}

					$properties[ $field['name'] ] = $property_def;

					if ( $field['required'] ) {
						$required[] = $field['name'];
					}
				}
			}

			$tools[] = array(
				'type'        => 'function',
				'name'        => 'action_' . $action['id'],
				'description' => $action['description'],
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'strict'      => false,
			);
		}

		return $tools;
	}

	/**
	 * Prepare tools for OpenRouter
	 */
	private function prepare_openrouter_tools() {
		$tools = array();

		if ( ! empty( $this->actions ) ) {
			foreach ( $this->actions as $action ) {
				$properties = array();
				$required   = array();

				if ( ! empty( $action['fields'] ) ) {
					foreach ( $action['fields'] as $field ) {
						$property_def = array(
							'type'        => $this->map_field_type( $field['type'] ),
							'description' => $field['description'] ?? '',
						);

						if ( ! empty( $field['options'] ) ) {
							$property_def['enum'] = $field['options'];
						}

						$properties[ $field['name'] ] = $property_def;

						if ( $field['required'] ) {
							$required[] = $field['name'];
						}
					}
				}

				$tools[] = array(
					'type'     => 'function',
					'function' => array(
						'name'        => 'action_' . $action['id'],
						'description' => $action['description'],
						'parameters'  => array(
							'type'       => 'object',
							'properties' => $properties,
							'required'   => $required,
						),
					),
				);
			}
		}

		return $tools;
	}

	/**
	 * Normalize Responses API format to Chat Completions format
	 */
	private function normalize_responses_to_chat_format( $result ) {
		$normalized = array(
			'choices' => array(
				array(
					'message'       => array(
						'role'       => 'assistant',
						'content'    => null,
						'tool_calls' => array(),
					),
					'finish_reason' => 'stop',
				),
			),
		);

		if ( isset( $result['output_text'] ) && ! empty( $result['output_text'] ) ) {
			$normalized['choices'][0]['message']['content'] = $result['output_text'];
			return $normalized;
		}

		if ( isset( $result['output'] ) && is_array( $result['output'] ) ) {
			foreach ( $result['output'] as $output ) {
				if ( $output['type'] === 'message' ) {
					if ( isset( $output['content'] ) ) {
						if ( is_string( $output['content'] ) ) {
							$normalized['choices'][0]['message']['content'] = $output['content'];
						} elseif ( is_array( $output['content'] ) ) {
							foreach ( $output['content'] as $content ) {
								if ( ( $content['type'] === 'text' || $content['type'] === 'output_text' ) &&
									isset( $content['text'] ) ) {
									$normalized['choices'][0]['message']['content'] = $content['text'];
									break;
								}
							}
						}
					}
				} elseif ( $output['type'] === 'function_call' ) {
					$normalized['choices'][0]['message']['tool_calls'][] = array(
						'id'       => $output['id'] ?? 'call_' . uniqid(),
						'type'     => 'function',
						'function' => array(
							'name'      => $output['name'],
							'arguments' => is_string( $output['arguments'] ) ?
								$output['arguments'] :
								json_encode( $output['arguments'] ),
						),
					);
					$normalized['choices'][0]['finish_reason']           = 'tool_calls';
				}
			}
		}

		return $normalized;
	}

	/**
	 * Call OpenRouter API
	 */
	private function call_openrouter( $messages ) {
		$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'No OpenRouter API key is configured. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		$data = array(
			'model'    => $this->model,
			'messages' => $messages,
		);

		if ( ! empty( $this->model_params ) ) {
			foreach ( $this->model_params as $key => $value ) {
				$data[ $key ] = $value;
			}
		}
		// OpenRouter forwards straight to the underlying provider, so a
		// reasoning model behind it rejects a non-default temperature/top_p
		// the same way native OpenAI does.
		$this->shape_reasoning_params( $data, $this->model );

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'HTTP-Referer'  => get_site_url(),
					'X-Title'       => 'WP AI Workflows',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Error calling OpenRouter: ' . esc_html( $response->get_error_message() ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( isset( $result['error'] ) ) {
			throw new Exception( 'OpenRouter error: ' . esc_html( $result['error']['message'] ) );
		}

		return $result;
	}

	/**
	 * Call OpenRouter with tools
	 */
	private function call_openrouter_with_tools( $messages, $tools ) {
		$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'No OpenRouter API key is configured. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		$data = array(
			'model'       => $this->model,
			'messages'    => $messages,
			'tools'       => $tools,
			'tool_choice' => 'auto',
		);

		if ( ! empty( $this->model_params ) ) {
			foreach ( $this->model_params as $key => $value ) {
				$data[ $key ] = $value;
			}
		}
		// See call_openrouter() - same reasoning-model constraint applies.
		$this->shape_reasoning_params( $data, $this->model );

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'HTTP-Referer'  => get_site_url(),
					'X-Title'       => 'WP AI Workflows',
				),
				'body'    => wp_json_encode( $data ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Error calling OpenRouter: ' . esc_html( $response->get_error_message() ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );

		if ( isset( $result['error'] ) ) {
			throw new Exception( 'OpenRouter error: ' . esc_html( $result['error']['message'] ) );
		}

		return $result;
	}

	/**
	 * Process AI response
	 */
	private function process_response( $response ) {
		if ( isset( $response['choices'][0]['message']['tool_calls'] ) &&
			! empty( $response['choices'][0]['message']['tool_calls'] ) ) {

			$tool_calls = $response['choices'][0]['message']['tool_calls'];

			foreach ( $tool_calls as $tool_call ) {
				$function_name = $tool_call['function']['name'];
				$arguments     = json_decode( $tool_call['function']['arguments'], true );

				if ( strpos( $function_name, 'action_' ) === 0 ) {
					$action_id = substr( $function_name, 7 );

					$action = $this->find_action( $action_id );
					if ( $action ) {
						return array(
							'type'            => 'action',
							'display_message' => "I'll process that request for you right away.",
							'action_id'       => $action_id,
							'action_data'     => $arguments,
							'confidence'      => 1.0,
						);
					}
				}
			}
		}

		$content   = $response['choices'][0]['message']['content'] ?? '';
		$citations = $response['citations'] ?? array();

		return array(
			'type'            => 'message',
			'display_message' => $content,
			'message'         => $content,
			'citations'       => $citations,
			'data'            => null,
		);
	}

	/**
	 * Handle action response
	 */
	private function handle_action_response( $processed ) {
		$action = $this->find_action( $processed['action_id'] );
		if ( ! $action ) {
			throw new Exception( 'Invalid action requested' );
		}

		$response = array(
			'type'               => 'action',
			'display_message'    => $processed['display_message'],
			'action_id'          => $processed['action_id'],
			'has_pending_result' => true,
			'session_id'         => $this->session->get_session_id(),
			'message'            => $processed['display_message'],
		);

		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			header( 'Content-Type: application/json' );
			echo wp_json_encode( $response );
			fastcgi_finish_request();
		}

		$execution_result = WP_AI_Workflows_Workflow::execute_workflow(
			$this->session->get_workflow_id(),
			$processed['action_data'],
			null,
			$this->session->get_session_id(),
			null,
			null,
			$processed['action_id']
		);

		if ( isset( $execution_result['execution_id'] ) ) {
			$execution_id  = $execution_result['execution_id'];
			$execution_key = 'wp_ai_workflows_pending_execution_' . $this->session->get_session_id();

			set_transient(
				$execution_key,
				array(
					'execution_id' => $execution_id,
					'action_id'    => $processed['action_id'],
					'workflow_id'  => $this->session->get_workflow_id(),
					'timestamp'    => time(),
				),
				3600
			);

			global $wpdb;
			$sessions_table = $wpdb->prefix . 'wp_ai_workflows_sessions';
			
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET metadata = %s WHERE session_id = %s",
					$sessions_table,
					wp_json_encode(
						array(
							'status'       => 'processing',
							'execution_id' => $execution_id,
						)
					),
					$this->session->get_session_id()
				)
			);
		}

		return $response;
	}

	/**
	 * Prepare conversation context
	 */
	private function prepare_context( $history ) {
		$messages = array();

		$system_prompt = $this->system_prompt;

		// Persona migration safety-net: old nodes not re-saved since the
		// Instructions-field consolidation still carry the persona in
		// data.agent.persona - prepend it so nothing is lost. No-op once the
		// node is re-saved (persona already merged into $system_prompt).
		if ( is_array( $this->agent_config )
			&& ! empty( $this->agent_config['persona'] ) ) {
			$persona = trim( (string) $this->agent_config['persona'] );
			if ( '' !== $persona && false === strpos( (string) $system_prompt, $persona ) ) {
				$system_prompt = '' !== trim( (string) $system_prompt )
					? $persona . "\n\n" . $system_prompt
					: $persona;
			}
		}

		if ( ! empty( $this->page_context ) ) {
			$system_prompt .= "\n\n### CURRENT PAGE CONTEXT ###\n";
			$system_prompt .= "The user is currently on a page with the following information:\n";

			$system_prompt .= '- Title: ' . esc_html( $this->page_context['page_title'] ) . "\n";
			$system_prompt .= '- URL: ' . esc_url( $this->page_context['page_url'] ) . "\n";
			$system_prompt .= '- Type: ' . esc_html( $this->page_context['page_type'] ) . "\n";

			if ( ! empty( $this->page_context['content_summary'] ) ) {
				$system_prompt .= "\nContent Summary:\n" . esc_html( $this->page_context['content_summary'] ) . "\n";
			}

			if ( ! empty( $this->page_context['product_info'] ) ) {
				$product        = $this->page_context['product_info'];
				$system_prompt .= "\nProduct Information:\n";
				$system_prompt .= '- Price: ' . esc_html( $product['price'] ) . "\n";

				if ( ! empty( $product['sale_price'] ) ) {
					$system_prompt .= '- Sale Price: ' . esc_html( $product['sale_price'] ) . "\n";
				}

				$system_prompt .= '- SKU: ' . esc_html( $product['sku'] ) . "\n";
				$system_prompt .= '- Stock Status: ' . esc_html( $product['stock_status'] ) . "\n";

				if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
					$system_prompt .= '- Categories: ' . esc_html( implode( ', ', $product['categories'] ) ) . "\n";
				}
			}

			$system_prompt .= "\nPlease use this context to provide more relevant and personalized responses to the user's questions.";
		}

		// RAG (opt-in): when the node is backed by a KB, retrieve chunks relevant
		// to the current message and append them to the system prompt. Fail-open:
		// any retrieval error leaves the prompt unchanged. Injecting here
		// covers every downstream path since they all consume $messages.
		$kb_context = '';
		$kb_cfg     = $this->knowledge_base;
		if ( is_array( $kb_cfg ) && ! empty( $kb_cfg['enabled'] ) && ! empty( $kb_cfg['kbId'] )
			&& ! empty( $this->current_message )
			&& class_exists( 'WP_AI_Workflows_Knowledge_Base' )
			&& WP_AI_Workflows_Knowledge_Base::is_configured() ) {
			$kb_top_k   = isset( $kb_cfg['topK'] ) ? (int) $kb_cfg['topK'] : 5;
			$kb_context = WP_AI_Workflows_Knowledge_Base::retrieve_context(
				sanitize_text_field( $kb_cfg['kbId'] ),
				(string) $this->current_message,
				$kb_top_k
			);
		}
		/**
		 * Filter the KB retrieval block before injection. Runs regardless of
		 * whether a vector store is configured, so integrators (or a future
		 * provider) can supply/adjust the KB context independently of memory.
		 *
		 * @param string $kb_context Retrieved KB context (may be '').
		 * @param string $query      The current user message.
		 */
		$kb_context = apply_filters( 'wp_ai_workflows_kb_context', (string) $kb_context, (string) $this->current_message );
		if ( '' !== $kb_context ) {
			$system_prompt .= "\n\n" . $kb_context;
		}

		// Shared-file awareness (opt-in): if the visitor just shared a
		// document/image, surface the filename plus an extracted-text snippet
		// (or an image note) to the model this turn. Consumed once; fail-open.
		if ( class_exists( 'WP_AI_Workflows_Chat_Uploads' ) && $this->session ) {
			$pending = WP_AI_Workflows_Chat_Uploads::consume_pending( $this->session->get_session_id() );
			if ( is_array( $pending ) && ! empty( $pending['name'] ) ) {
				$block = "\n\n### SHARED FILE ###\n";
				$block .= 'The user shared a file: ' . sanitize_text_field( (string) $pending['name'] ) . "\n";
				if ( ! empty( $pending['is_image'] ) ) {
					$block .= "This is an image attachment. If you can view images, describe or use it; otherwise acknowledge the shared image.\n";
				} elseif ( '' !== trim( (string) $pending['extract'] ) ) {
					$block .= "Extracted document content (may be truncated):\n\"\"\"\n";
					$block .= (string) $pending['extract'] . "\n\"\"\"\n";
				} else {
					$block .= "The file's text could not be extracted; acknowledge the attachment and ask the user what they'd like to do with it.\n";
				}
				$system_prompt .= $block;
			}
		}

		// Chat memory (opt-in, per-node scope): rolling summary + recent turns,
		// injected as a distinctly-labelled block. Fail-open on any error.
		$memory_block = $this->build_memory_context( $history );
		if ( '' !== $memory_block ) {
			$system_prompt .= "\n\n" . $memory_block;
		}

		$messages[] = array(
			'role'    => 'system',
			'content' => $system_prompt,
		);

		foreach ( $history as $msg ) {
			$messages[] = array(
				'role'    => $msg->role,
				'content' => $msg->content,
			);
		}

		// Add the current message (skipped when there is none - e.g. rebuilding
		// the base context to resume a paused approval, where the triggering user
		// turn is already in the persisted history).
		if ( '' !== (string) $this->current_message ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $this->current_message,
			);
		}

		/**
		 * Filter the fully-assembled chat context (system + history + user)
		 * before it is sent to the AI. Also a test seam for asserting injected
		 * memory / KB blocks.
		 *
		 * @param array $messages Assembled message array.
		 */
		return apply_filters( 'wp_ai_workflows_chat_context', $messages );
	}

	/**
	 * Whether opt-in memory is enabled + valid on this chat node.
	 *
	 * @return bool
	 */
	private function memory_enabled() {
		return is_array( $this->memory_config )
			&& ! empty( $this->memory_config['enabled'] )
			&& class_exists( 'WP_AI_Workflows_Chat_Memory' );
	}

	/**
	 * Resolve the effective {scope, key} for the current request from the node's
	 * requested scope + the current session/user.
	 *
	 * @return array{scope:string,key:string}
	 */
	private function resolve_memory_scope_key() {
		$requested = isset( $this->memory_config['scope'] )
			? (string) $this->memory_config['scope']
			: WP_AI_Workflows_Chat_Memory::SCOPE_CONVERSATION;

		return WP_AI_Workflows_Chat_Memory::resolve_scope_key(
			$requested,
			$this->session->get_session_id()
		);
	}

	/**
	 * Build the memory context block to inject, or '' when memory is off/empty.
	 * Never throws.
	 *
	 * @param array $history Live session history.
	 * @return string
	 */
	private function build_memory_context( $history ) {
		if ( ! $this->memory_enabled() ) {
			return '';
		}

		try {
			$sk       = $this->resolve_memory_scope_key();
			$provider = WP_AI_Workflows_Chat_Memory::get_provider( $this->memory_config );

			$recent = array();
			foreach ( (array) $history as $msg ) {
				$recent[] = array(
					'role'    => isset( $msg->role ) ? $msg->role : 'user',
					'content' => isset( $msg->content ) ? $msg->content : '',
				);
			}

			return (string) $provider->build_context( $sk['key'], $sk['scope'], $recent );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Chat memory context build failed (continuing without memory)',
				'warning',
				array( 'error' => $e->getMessage() )
			);
			return '';
		}
	}

	/**
	 * Record a completed turn (user + assistant) into memory. Never throws.
	 *
	 * @param string $user_message      The user's message.
	 * @param string $assistant_message The assistant's reply.
	 * @return void
	 */
	private function record_memory_turn( $user_message, $assistant_message ) {
		if ( ! $this->memory_enabled() ) {
			return;
		}

		try {
			$sk       = $this->resolve_memory_scope_key();
			$provider = WP_AI_Workflows_Chat_Memory::get_provider( $this->memory_config );
			$provider->record(
				$sk['key'],
				$sk['scope'],
				array(
					array(
						'role'    => 'user',
						'content' => (string) $user_message,
					),
					array(
						'role'    => 'assistant',
						'content' => (string) $assistant_message,
					),
				)
			);
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Chat memory record failed (continuing)',
				'warning',
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Find action by ID
	 */
	private function find_action( $action_id ) {
		foreach ( $this->actions as $action ) {
			if ( $action['id'] === $action_id ) {
				return $action;
			}
		}
		return null;
	}

	// Human handoff (Phase 2a): conversation-mode routing + keyword trigger.
	// Opt-in - none of this runs unless data.agent.handoff.enabled is truthy.

	/**
	 * Raw handoff config for this node, or null when handoff is not enabled.
	 *
	 * @return array|null
	 */
	private function handoff_config_raw() {
		if ( ! class_exists( 'WP_AI_Workflows_Handoff_Manager' ) ) {
			return null;
		}
		if ( ! WP_AI_Workflows_Handoff_Manager::is_enabled( $this->agent_config ) ) {
			return null;
		}
		return $this->agent_config['handoff'];
	}

	/**
	 * Route a message through the handoff path when appropriate.
	 *
	 * Returns a response array (short-circuiting the bot) when:
	 *   - the conversation is already human-controlled (relay the message to the
	 *     human; the bot stays silent), or
	 *   - the visitor explicitly asks for a human (start a handoff).
	 * Returns null to let the normal bot/agent path run.
	 *
	 * @param string $message Sanitized visitor message.
	 * @return array|null
	 */
	private function maybe_handle_handoff( $message ) {
		$raw = $this->handoff_config_raw();
		if ( null === $raw ) {
			return null;
		}

		$manager    = new WP_AI_Workflows_Handoff_Manager();
		$config      = WP_AI_Workflows_Handoff_Manager::normalize_config( $raw );
		$session_id  = $this->session->get_session_id();
		$workflow_id = $this->session->get_workflow_id();
		$store       = $manager->store();

		// 1) Already handed off - the human owns it; the bot only relays.
		if ( $store->is_human_controlled( $session_id ) ) {
			$this->session->add_message( 'user', $message );
			$manager->relay_visitor_message( $session_id, $config, $message );
			return array(
				'type'            => 'handoff',
				'display_message' => '', // No bot reply while a human is in control.
				'message'         => '',
				'session_id'      => $session_id,
				'citations'       => null,
				'mode'            => $store->get_mode( $session_id ),
				'handoff'         => array( 'relayed' => true ),
			);
		}

		// 2) Explicit "talk to a human" request -> start a handoff now. Keyword is
		//    the fast path; semantic intent detection catches natural phrasings.
		$trigger_reason = $this->handoff_trigger_reason( $manager, $config, $message );
		if ( '' !== $trigger_reason ) {
			$this->session->add_message( 'user', $message );
			$result = $manager->start(
				$session_id,
				$workflow_id,
				$config,
				array( 'reason' => 'The visitor asked to speak with a human.' )
			);

			$ack = ! empty( $result['ok'] )
				? (string) $result['message']
				: "I'm sorry, I couldn't connect you to a human right now. Please try again shortly.";
			$this->session->add_message( 'assistant', $ack );

			return array(
				'type'            => 'handoff',
				'display_message' => $ack,
				'message'         => $ack,
				'session_id'      => $session_id,
				'citations'       => null,
				'mode'            => ! empty( $result['ok'] ) ? $result['mode'] : WP_AI_Workflows_Handoff_Store::MODE_BOT,
				'handoff'         => array(
					'started' => ! empty( $result['ok'] ),
					'trigger' => $trigger_reason,
				),
			);
		}

		// Otherwise: not a handoff turn - let the bot/agent answer.
		return null;
	}

	/**
	 * Streaming counterpart of maybe_handle_handoff. Emits an SSE handoff frame
	 * (mode + any acknowledgement) and returns true when the turn was handled by
	 * the handoff path; returns false to let the normal streaming path run.
	 *
	 * @param string $message Sanitized visitor message.
	 * @return bool
	 */
	private function maybe_stream_handoff( $message ) {
		$raw = $this->handoff_config_raw();
		if ( null === $raw ) {
			return false;
		}

		$manager     = new WP_AI_Workflows_Handoff_Manager();
		$config      = WP_AI_Workflows_Handoff_Manager::normalize_config( $raw );
		$session_id  = $this->session->get_session_id();
		$workflow_id = $this->session->get_workflow_id();
		$store       = $manager->store();

		$human_controlled = $store->is_human_controlled( $session_id );
		$trigger_reason   = $human_controlled ? '' : $this->handoff_trigger_reason( $manager, $config, $message );
		$requests_human   = ! $human_controlled && '' !== $trigger_reason;

		if ( ! $human_controlled && ! $requests_human ) {
			return false; // Not a handoff turn.
		}

		$ack  = '';
		$mode = $store->get_mode( $session_id );

		if ( $human_controlled ) {
			$this->session->add_message( 'user', $message );
			$manager->relay_visitor_message( $session_id, $config, $message );
		} else {
			$this->session->add_message( 'user', $message );
			$result = $manager->start(
				$session_id,
				$workflow_id,
				$config,
				array( 'reason' => 'The visitor asked to speak with a human.' )
			);
			$ack  = ! empty( $result['ok'] )
				? (string) $result['message']
				: "I'm sorry, I couldn't connect you to a human right now. Please try again shortly.";
			$mode = ! empty( $result['ok'] ) ? $result['mode'] : WP_AI_Workflows_Handoff_Store::MODE_BOT;
			$this->session->add_message( 'assistant', $ack );
		}

		// Emit as SSE (mirrors the streaming transport the widget already reads).
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		if ( ob_get_level() ) {
			ob_end_flush();
		}

		if ( '' !== $ack ) {
			echo 'data: ' . wp_json_encode( array( 'content' => $ack ) ) . "\n\n";
		}
		echo 'data: ' . wp_json_encode(
			array(
				'handoff' => array(
					'mode'    => $mode,
					'relayed' => $human_controlled,
					'trigger' => $trigger_reason,
				),
			)
		) . "\n\n";
		echo "data: [DONE]\n\n";
		flush();
		exit;
	}

	/**
	 * System instruction for the semantic handoff-intent classifier. Kept tiny so
	 * the classification call is cheap (a single-token "true"/"false" completion).
	 */
	const HANDOFF_INTENT_PROMPT = "You classify a customer-support chat message. Decide whether the user is asking to be connected to, or to talk with, a HUMAN / live agent / real person / support representative instead of continuing with the AI assistant. Answer with a single word: \"true\" if they want a human, otherwise \"false\". No explanation, no punctuation.";

	/**
	 * Decide why (if at all) this turn should hand off to a human: keyword fast
	 * path first (free), then semantic intent (one cheap model call, gated by a
	 * lexical pre-filter and only when intent detection is enabled).
	 *
	 * @param WP_AI_Workflows_Handoff_Manager $manager Manager instance.
	 * @param array                           $config  Normalized handoff config.
	 * @param string                          $message Visitor message.
	 * @return string 'keyword' | 'intent' | '' (no handoff).
	 */
	private function handoff_trigger_reason( $manager, array $config, $message ) {
		if ( $manager->message_requests_human( $message, $config ) ) {
			return 'keyword';
		}
		$classifier = function ( $msg ) {
			return $this->classify_wants_human( $msg );
		};
		if ( $manager->message_requests_human_by_intent( $message, $config, $classifier ) ) {
			return 'intent';
		}
		return '';
	}

	/**
	 * Cheap LLM classification of a single message: does the visitor want a human?
	 * Runs on the node's resolved AI source - the metered credits proxy when the
	 * node's AI source is credits, otherwise the site's own key ({@see
	 * agent_model_call}). Honouring the source here keeps the invariant that an
	 * explicit 'credits' choice never silently uses a BYOK key (and vice-versa),
	 * even for this internal classification call. Throws on transport error - the
	 * caller ({@see message_requests_human_by_intent}) fails open, so a classifier
	 * fault (including a credits gate) degrades gracefully to keyword-only.
	 *
	 * @param string $message Visitor message.
	 * @return bool
	 * @throws Exception On model/transport error.
	 */
	private function classify_wants_human( $message ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => self::HANDOFF_INTENT_PROMPT,
			),
			array(
				'role'    => 'user',
				'content' => (string) $message,
			),
		);

		if ( WP_AI_Workflows_AI_Router::ROUTE_CREDITS === $this->resolve_chat_route()
			&& class_exists( 'WP_AI_Workflows_Platform_Client' ) ) {
			// Metered path - buffered proxy call, NEVER a BYOK fallback.
			$resp = WP_AI_Workflows_Platform_Client::proxy_ai(
				array(
					'model'    => $this->model,
					'messages' => $messages,
				)
			);
			if ( is_wp_error( $resp ) ) {
				throw new Exception( esc_html( $resp->get_error_message() ) );
			}
			$content = isset( $resp['content'] ) ? strtolower( trim( (string) $resp['content'] ) ) : '';
		} else {
			$resp    = $this->agent_model_call( $messages, array() );
			$content = isset( $resp['choices'][0]['message']['content'] )
				? strtolower( trim( (string) $resp['choices'][0]['message']['content'] ) )
				: '';
		}

		return ( 'true' === $content || 0 === strpos( $content, 'true' ) || false !== strpos( $content, 'wants_human' ) );
	}

	// Agentic engine: governed tool registry + bounded agentic loop. Every chat
	// node IS an agent - there is no "agent mode" toggle. The node runs the
	// bounded loop only when it has a genuine agentic capability (Actions, or
	// agent-decided handoff); otherwise it degrades to the legacy single-round
	// path (one model call, identical output/streaming/cost).

	/**
	 * Whether this node should run the bounded agentic loop for a message.
	 *
	 * True when the node has a genuine multi-step capability: one or more
	 * connect-a-workflow Actions, or human handoff the agent may decide to
	 * escalate (trigger 'agent'/'both'; keyword-only handoff is pre-loop).
	 * Otherwise degrades to the classic single-reply path. The removed
	 * `data.agent.enabled` toggle is ignored - driven purely by capabilities.
	 *
	 * @return bool
	 */
	private function should_run_agent_loop() {
		if ( ! class_exists( 'WP_AI_Workflows_Agent_Orchestrator' ) ) {
			return false;
		}

		// Connect-a-workflow actions - the agent's primary tools.
		if ( is_array( $this->actions ) && ! empty( $this->actions ) ) {
			return true;
		}

		// Opt-in content/commerce concierge tools. Only a genuinely-registerable
		// tool triggers the loop, so a simple chatbot with neither enabled stays
		// on the byte-identical single-reply path (product tool needs Woo active).
		if ( $this->content_tools_active() ) {
			return true;
		}

		// Agent-decided human handoff needs the loop to expose handoff_to_human.
		if ( is_array( $this->agent_config ) && ! empty( $this->agent_config['handoff']['enabled'] ) ) {
			$trigger = isset( $this->agent_config['handoff']['trigger'] )
				? (string) $this->agent_config['handoff']['trigger']
				: 'both';
			if ( 'agent' === $trigger || 'both' === $trigger ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an opt-in content/commerce concierge tool is enabled AND actually
	 * registerable on this install. search_content is always registerable;
	 * search_products only when WooCommerce is active - so toggling the product
	 * tool on a non-Woo site does NOT force the loop (backward-compat preserved).
	 *
	 * @return bool
	 */
	private function content_tools_active() {
		if ( ! class_exists( 'WP_AI_Workflows_Agent_Content_Tools' ) ) {
			return false;
		}
		$tools = ( is_array( $this->agent_config ) && isset( $this->agent_config['tools'] ) && is_array( $this->agent_config['tools'] ) )
			? $this->agent_config['tools']
			: array();

		if ( ! empty( $tools['search_content']['enabled'] ) ) {
			return true;
		}
		if ( ! empty( $tools['search_products']['enabled'] )
			&& WP_AI_Workflows_Agent_Content_Tools::woocommerce_active() ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve the node's maxSteps (bounded by the orchestrator).
	 *
	 * @return int
	 */
	private function agent_max_steps() {
		return isset( $this->agent_config['maxSteps'] )
			? (int) $this->agent_config['maxSteps']
			: WP_AI_Workflows_Agent_Orchestrator::DEFAULT_MAX_STEPS;
	}

	/**
	 * Whether this agent run is metered through the platform credits proxy (premium
	 * path) rather than the customer's own provider key (BYOK/local, free). Uses the
	 * single locked resolver - the same one the plain chat + AI model node use - so
	 * an agent run meters exactly when a plain chat call would.
	 *
	 * @return bool True = credits-metered, false = BYOK/local (free step-counting).
	 */
	private function agent_uses_credits() {
		return class_exists( 'WP_AI_Workflows_AI_Router' )
			&& class_exists( 'WP_AI_Workflows_Agent_Credit_Meter' )
			&& WP_AI_Workflows_AI_Router::ROUTE_CREDITS === $this->resolve_chat_route();
	}

	/**
	 * The per-run credit ceiling for a credit-metered agent run (node override or
	 * the meter's default). Enforced BEFORE each step so a runaway loop can never
	 * outspend its budget.
	 *
	 * @return int
	 */
	private function agent_credit_cap() {
		return isset( $this->agent_config['creditCap'] )
			? (int) $this->agent_config['creditCap']
			: WP_AI_Workflows_Agent_Credit_Meter::DEFAULT_RUN_CAP;
	}

	/**
	 * Build the credits-proxy model transport for a metered agent run. Slots into
	 * the orchestrator's existing $model_caller seam via [ $meter, 'model_call' ].
	 *
	 * @return WP_AI_Workflows_Agent_Credit_Meter
	 */
	private function build_agent_credit_meter() {
		return new WP_AI_Workflows_Agent_Credit_Meter(
			$this->model,
			array(
				'systemPrompt' => is_string( $this->system_prompt ) ? $this->system_prompt : '',
				'params'       => is_array( $this->model_params ) ? $this->model_params : array(),
				'runCap'       => $this->agent_credit_cap(),
			)
		);
	}

	/**
	 * A clean stop-result for a credit-driven halt (out of credits / cap reached),
	 * shaped exactly like an orchestrator run() return so callers stay uniform.
	 *
	 * @param WP_AI_Workflows_Agent_Credit_Exception $e Credit signal.
	 * @return array{final:string,steps:array,stop_reason:string,steps_used:int}
	 */
	private function agent_credit_stop_result( WP_AI_Workflows_Agent_Credit_Exception $e ) {
		return array(
			'final'       => $e->getMessage(),
			'steps'       => array(),
			'stop_reason' => $e->get_reason(),
			'steps_used'  => 0,
			'blocks'      => array(),
		);
	}

	/**
	 * Build the governed tool registry for this node (actions, KB, MCP, native,
	 * built-ins) with per-tool guardrails from data.agent.tools.
	 *
	 * @return WP_AI_Workflows_Agent_Tool_Registry
	 */
	private function build_agent_registry() {
		$handler = $this;

		// Action tools dispatch through the exact legacy async action path.
		$dispatcher = function ( $action_id, $args ) use ( $handler ) {
			$action = $handler->find_action( $action_id );
			if ( ! $action ) {
				return 'Unknown action.';
			}
			$handler->execute_action_async( $action_id, $args );
			$name = isset( $action['name'] ) ? $action['name'] : $action_id;
			return 'Action "' . $name . '" was dispatched; its workflow is running and results will follow.';
		};

		return WP_AI_Workflows_Agent_Tools::build_registry(
			$this->session->get_session_id(),
			$this->session->get_workflow_id(),
			$this->agent_config,
			is_array( $this->actions ) ? $this->actions : array(),
			$this->openai_tools,
			$this->knowledge_base,
			$dispatcher
		);
	}

	/**
	 * The honest, friendly line the visitor sees while a guarded action waits on
	 * the site owner. Kept short; the widget keeps polling so the real reply
	 * streams in by itself the moment it is approved.
	 */
	const AWAITING_APPROVAL_MESSAGE = '⏳ Waiting for the site owner to approve this action, usually quick. I\'ll continue right here as soon as it\'s approved.';

	/**
	 * When an agent run paused for approval, persist the arg-locked resume state
	 * (keyed by the human-task id) and flag the session so the widget keeps
	 * polling. No-op for any other stop reason.
	 *
	 * @param array $result Orchestrator run() result.
	 * @return bool True when the run is awaiting approval.
	 */
	private function note_pending_approval( array $result ) {
		if ( empty( $result['stop_reason'] ) || 'pending_confirmation' !== $result['stop_reason'] ) {
			return false;
		}
		$pending = isset( $result['pending'] ) && is_array( $result['pending'] ) ? $result['pending'] : array();
		$task_id = isset( $pending['task_id'] ) ? (int) $pending['task_id'] : 0;
		if ( $task_id > 0 && class_exists( 'WP_AI_Workflows_Agent_Approvals' ) ) {
			WP_AI_Workflows_Agent_Approvals::remember_pending(
				$task_id,
				$pending,
				$this->session->get_session_id(),
				$this->session->get_workflow_id()
			);
		}
		return true;
	}

	/**
	 * Resume a paused agent run after an approval decision. Runs the orchestrator
	 * from the locked pause state (approved => the tool runs with the locked args;
	 * rejected => a clean decline observation), persists the continuation as the
	 * next assistant turn (so history + reloads render it), and returns it.
	 *
	 * @param array $pending  Locked pause state (messages + tool + args).
	 * @param bool  $approved Approve (run the tool) or reject (decline it).
	 * @return array{final:string,blocks:array,stop_reason:string}
	 */
	public function resume_agent_approval( array $pending, $approved ) {
		$registry     = $this->build_agent_registry();
		$uses_credits = $this->agent_uses_credits();
		$meter        = $uses_credits ? $this->build_agent_credit_meter() : null;
		$model_caller = $uses_credits ? array( $meter, 'model_call' ) : array( $this, 'agent_model_call' );

		$orchestrator = new WP_AI_Workflows_Agent_Orchestrator(
			$registry,
			$model_caller,
			array( 'maxSteps' => $this->agent_max_steps() )
		);

		try {
			$result = $orchestrator->resume( $pending, (bool) $approved, array( 'confirmed_tools' => array() ) );
		} catch ( WP_AI_Workflows_Agent_Credit_Exception $e ) {
			$result = $this->agent_credit_stop_result( $e );
		}

		$final  = (string) $result['final'];
		$blocks = ( isset( $result['blocks'] ) && is_array( $result['blocks'] ) ) ? $result['blocks'] : array();

		// Persist the resumed turn (durable) so a reload re-renders it + its cards.
		$this->session->add_message( 'assistant', $final, empty( $blocks ) ? null : array( 'blocks' => $blocks ) );

		return array(
			'final'       => $final,
			'blocks'      => $blocks,
			'stop_reason' => isset( $result['stop_reason'] ) ? (string) $result['stop_reason'] : 'final',
		);
	}

	/**
	 * Fallback resume context for a task that predates the persisted pause state:
	 * rebuild [system + history] (no new user turn - the triggering message is
	 * already in the persisted history) so the locked tool still replays against
	 * a faithful conversation.
	 *
	 * @param array $pending Partial pending state (tool + args already set).
	 * @return array Pending state with `messages` populated.
	 */
	public function rebuild_pending_for_resume( array $pending ) {
		$this->current_message = '';
		$pending['messages']   = $this->prepare_context( $this->session->get_history() );
		if ( empty( $pending['call_id'] ) ) {
			$pending['call_id'] = 'call_resume';
		}
		return $pending;
	}

	/**
	 * Run the bounded agentic loop for a message (non-streaming).
	 *
	 * @param string $message User message.
	 * @return array Response array - same family as the legacy path plus `agent`.
	 */
	private function process_agent_message( $message ) {
		$this->current_message = $message;
		$context               = $this->prepare_context( $this->session->get_history() );
		$registry              = $this->build_agent_registry();

		// Route selection (locked resolver). CREDITS = metered proxy per step;
		// BYOK/local = the customer's own key, free (steps counted only).
		$uses_credits = $this->agent_uses_credits();
		$meter        = $uses_credits ? $this->build_agent_credit_meter() : null;
		$model_caller = $uses_credits ? array( $meter, 'model_call' ) : array( $this, 'agent_model_call' );

		$step_count   = 0;
		$orchestrator = new WP_AI_Workflows_Agent_Orchestrator(
			$registry,
			$model_caller,
			array(
				'maxSteps' => $this->agent_max_steps(),
				'meter'    => function ( $i ) use ( &$step_count ) {
					// BYOK step counter. On the credits path the real per-step credit
					// settle happens server-side in the proxy; the meter tracks the
					// accrued credits (read back below).
					++$step_count;
				},
			)
		);

		try {
			$result = $orchestrator->run( $context, array( 'confirmed_tools' => array() ) );
		} catch ( WP_AI_Workflows_Agent_Credit_Exception $e ) {
			// Out of credits / per-run cap reached -> clean hard stop with a top-up
			// message. NEVER a silent BYOK fallback (locked policy).
			$result = $this->agent_credit_stop_result( $e );
		}

		// Paused for approval: persist the arg-locked resume state and show the
		// visitor an honest, friendly waiting line instead of the internal note.
		$awaiting = $this->note_pending_approval( $result );
		if ( $awaiting ) {
			$result['final'] = self::AWAITING_APPROVAL_MESSAGE;
		}
		$final = (string) $result['final'];

		// Rich-message blocks (product/content cards, quick replies) surfaced by
		// content tools during the run. Sanitised server-side at the source.
		$blocks = ( isset( $result['blocks'] ) && is_array( $result['blocks'] ) ) ? $result['blocks'] : array();

		// On the credits path report the real credits settled; otherwise the free
		// BYOK step count.
		$credits_used = ( $uses_credits && $meter ) ? $meter->credits_charged() : $step_count;

		// Persist the turn so history + memory stay consistent (mirrors legacy).
		// Blocks are stored in the message metadata so they re-render on reload.
		$this->session->add_message( 'user', $message );
		$this->session->add_message( 'assistant', $final, empty( $blocks ) ? null : array( 'blocks' => $blocks ) );
		$this->record_memory_turn( $message, $final );

		return array(
			'type'            => 'message',
			'display_message' => $final,
			'message'         => $final,
			'session_id'      => $this->session->get_session_id(),
			'citations'       => null,
			'blocks'          => $blocks,
			'agent'           => array(
				'enabled'      => true,
				'billing'      => $uses_credits ? 'credits' : 'byok',
				// Steps are stripped when the node hides agent activity, so the
				// visitor's browser never receives the tool-call trace.
				'steps'        => $this->show_agent_activity ? $result['steps'] : array(),
				'stop_reason'  => $result['stop_reason'],
				'steps_used'   => $result['steps_used'],
				'credits_used' => $credits_used,
				// Signals the widget to keep polling: the reply will stream in by
				// itself once the site owner approves the paused action.
				'awaiting_approval' => $awaiting,
			),
		);
	}

	/**
	 * Run the agent loop and stream the step trace + final answer as SSE. Used
	 * when the node has streaming enabled AND Agent mode on.
	 *
	 * @param string $message User message.
	 * @return void
	 */
	private function stream_agent_message( $message ) {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		if ( ob_get_level() ) {
			ob_end_flush();
		}

		$this->current_message = $message;
		$context               = $this->prepare_context( $this->session->get_history() );
		$this->session->add_message( 'user', $message );

		$registry = $this->build_agent_registry();

		// Route selection (locked resolver): metered proxy vs BYOK/local (free).
		$uses_credits = $this->agent_uses_credits();
		$meter        = $uses_credits ? $this->build_agent_credit_meter() : null;
		$model_caller = $uses_credits ? array( $meter, 'model_call' ) : array( $this, 'agent_model_call' );

		$orchestrator = new WP_AI_Workflows_Agent_Orchestrator(
			$registry,
			$model_caller,
			array(
				'maxSteps' => $this->agent_max_steps(),
				// Only stream the step-trace to the browser when the node shows
				// agent activity; otherwise the tool-call frames are never
				// emitted (privacy + payload). agent_done still carries billing
				// metadata (no tool details) and is always sent below.
				'emit'     => $this->show_agent_activity
					? function ( $step ) {
						echo 'data: ' . wp_json_encode( array( 'agent_step' => $step ) ) . "\n\n";
						flush();
					}
					: function ( $step ) {
						// Activity hidden - swallow the step, emit nothing.
						unset( $step );
					},
			)
		);

		try {
			$result = $orchestrator->run( $context, array( 'confirmed_tools' => array() ) );
		} catch ( WP_AI_Workflows_Agent_Credit_Exception $e ) {
			// Credit-driven hard stop (out of credits / cap). Clean top-up message;
			// never a silent BYOK fallback (locked policy).
			$result = $this->agent_credit_stop_result( $e );
		}

		// Paused for approval: persist the arg-locked resume state and swap the
		// internal note for the honest, friendly waiting line.
		$awaiting = $this->note_pending_approval( $result );
		if ( $awaiting ) {
			$result['final'] = self::AWAITING_APPROVAL_MESSAGE;
		}
		$final = (string) $result['final'];

		// Rich-message blocks (cards / quick replies) surfaced by content tools.
		$blocks = ( isset( $result['blocks'] ) && is_array( $result['blocks'] ) ) ? $result['blocks'] : array();
		if ( ! empty( $blocks ) ) {
			echo 'data: ' . wp_json_encode( array( 'blocks' => $blocks ) ) . "\n\n";
			flush();
		}

		echo 'data: ' . wp_json_encode( array( 'content' => $final ) ) . "\n\n";
		echo 'data: ' . wp_json_encode(
			array(
				'agent_done' => array(
					'billing'           => $uses_credits ? 'credits' : 'byok',
					'stop_reason'       => $result['stop_reason'],
					'steps_used'        => $result['steps_used'],
					'credits_used'      => ( $uses_credits && $meter ) ? $meter->credits_charged() : 0,
					// Keep the widget polling: the reply continues by itself on approval.
					'awaiting_approval' => $awaiting,
				),
			)
		) . "\n\n";
		echo "data: [DONE]\n\n";
		flush();

		// Persist blocks in message metadata so history re-renders the cards.
		$this->session->add_message( 'assistant', $final, empty( $blocks ) ? null : array( 'blocks' => $blocks ) );
		$this->record_memory_turn( $message, $final );
		exit;
	}

	/**
	 * Provider-agnostic Chat-Completions model call for the agent loop. Returns
	 * a Chat-Completions-shaped response. Public so the orchestrator can call it
	 * as a callable. This is the BYOK/local transport (customer's own key, free).
	 * The credit-metered transport lives in WP_AI_Workflows_Agent_Credit_Meter and is
	 * selected in process_/stream_agent_message when the run resolves to CREDITS.
	 *
	 * @param array $messages Chat messages (may include assistant/tool turns).
	 * @param array $tools    Governed tool schemas (may be empty).
	 * @return array
	 * @throws Exception On transport/API error.
	 */
	public function agent_model_call( array $messages, array $tools ) {
		$is_openrouter = strpos( $this->model, '/' ) !== false
			&& strpos( $this->model, 'openai/' ) !== 0
			&& ! in_array(
				$this->model,
				array( 'gpt-5.5', 'gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini', 'o3', 'o4-mini', 'o1' ),
				true
			);

		if ( $is_openrouter ) {
			$endpoint = 'https://openrouter.ai/api/v1/chat/completions';
			$api_key  = WP_AI_Workflows_Utilities::get_openrouter_api_key();
			$model    = $this->model;
			$headers  = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
				'HTTP-Referer'  => get_site_url(),
				'X-Title'       => 'WP AI Workflows',
			);
		} else {
			$endpoint = 'https://api.openai.com/v1/chat/completions';
			$api_key  = WP_AI_Workflows_Utilities::get_openai_api_key();
			$model    = strpos( $this->model, 'openai/' ) === 0 ? substr( $this->model, 7 ) : $this->model;
			$headers  = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			);
		}

		if ( empty( $api_key ) ) {
			throw new Exception( 'No API key is configured for the selected model. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		$params = is_array( $this->model_params ) ? $this->model_params : array();

		if ( $is_openrouter ) {
			// OpenRouter forwards straight to the underlying provider:
			// shape it exactly like the direct-OpenAI branch below.
			$data = array(
				'model'    => $model,
				'messages' => $messages,
			);
			if ( isset( $params['temperature'] ) ) {
				$data['temperature'] = (float) $params['temperature'];
			}
			if ( isset( $params['top_p'] ) ) {
				$data['top_p'] = (float) $params['top_p'];
			}
			if ( isset( $params['max_tokens'] ) ) {
				$data['max_tokens'] = (int) $params['max_tokens'];
			}
			$this->shape_reasoning_params( $data, $model );
		} else {
			// Reasoning models reject the legacy `max_tokens` field and a
			// non-default temperature/top_p (HTTP 400); reuse the same shaping
			// as the AI Model node's direct-OpenAI call.
			$data = WP_AI_Workflows_Utilities::build_openai_chat_body( $model, $messages, $params );
		}
		if ( ! empty( $tools ) ) {
			$data['tools']       = $tools;
			$data['tool_choice'] = 'auto';
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $data ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Agent model call failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code >= 400 ) {
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			throw new Exception( 'Agent model API error: ' . esc_html( $msg ) );
		}
		if ( ! is_array( $json ) ) {
			throw new Exception( 'Invalid response from agent model API.' );
		}
		return $json;
	}

	/**
	 * Handle streaming message
	 */
	public function handle_streaming_message( $message, $page_context = null ) {
		$allowed_origins = array( get_site_url() );
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) && ! in_array( $_SERVER['HTTP_ORIGIN'], $allowed_origins ) ) {
			throw new Exception( 'Invalid request origin' );
		}

		$this->page_context = $page_context;

		if ( get_transient( 'wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id() ) ) {
			$this->load_workflow_config();
			delete_transient( 'wp_ai_workflows_refresh_chat_' . $this->session->get_workflow_id() );
		}

		if ( strlen( $message ) > 2000 ) {
			throw new Exception( 'Message too long' );
		}
		if ( empty( trim( $message ) ) ) {
			throw new Exception( 'Empty message' );
		}

		$message = wp_kses(
			$message,
			array(
				'a'      => array(
					'href'   => array(),
					'target' => array( '_blank' ),
				),
				'b'      => array(),
				'strong' => array(),
				'i'      => array(),
				'em'     => array(),
				'code'   => array(),
				'pre'    => array(),
			)
		);

		try {
			if ( ! $this->session->can_send_message() ) {
				throw new Exception( 'Rate limit exceeded' );
			}

			// Human handoff (Phase 2a): if the conversation is human-controlled or
			// the visitor is asking for a person, handle it here (SSE) instead of
			// streaming a bot reply. No-op unless handoff is enabled on the node.
			if ( $this->maybe_stream_handoff( $message ) ) {
				return;
			}

			// Agentic engine: when the node has agentic capabilities (Actions, or
			// agent-decided handoff) run the loop and stream steps + final as SSE.
			// Simple chat nodes fall through to the token-streaming path below.
			if ( $this->should_run_agent_loop() ) {
				$this->stream_agent_message( $message );
				return;
			}

			$this->current_message = $message;
			$context               = $this->prepare_context( $this->session->get_history() );

			$this->session->add_message( 'user', $message );

			// Credit-metered simple chat: the keyless proxy is buffered
			// (non-streaming). When this node's AI source resolves to credits,
			// emit the buffered completion as SSE instead of streaming through a
			// BYOK key - an explicit 'credits' choice never falls back silently.
			if ( WP_AI_Workflows_AI_Router::ROUTE_CREDITS === $this->resolve_chat_route() ) {
				$this->stream_credits_buffered_response( $context );
				return;
			}

			$is_openrouter = strpos( $this->model, '/' ) !== false &&
							strpos( $this->model, 'openai/' ) !== 0 &&
							! in_array(
								$this->model,
								array(
									// Current bare OpenAI ids (native OpenAI routing).
									'gpt-5.5',
									'gpt-5.1',
									'gpt-5',
									'gpt-5-mini',
									'gpt-5-nano',
									'gpt-4.1',
									'gpt-4.1-mini',
									'gpt-4o',
									'gpt-4o-mini',
									'o3',
									'o4-mini',
									'o1',
								)
							);

			if ( $is_openrouter ) {
				return $this->stream_openrouter_response( $context );
			} else {
				return $this->stream_openai_response( $context );
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in streaming',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);

			// Return error as SSE format
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			echo 'data: ' . json_encode(
				array(
					'error'   => true,
					'message' => 'Unable to process message. Please try again later.',
				)
			) . "\n\n";
			echo "data: [DONE]\n\n";
			exit;
		}
	}

	/**
	 * Apply security hardening to a streaming cURL handle.
	 *
	 * Enforces TLS certificate verification explicitly (never trust defaults for
	 * something transmitting API keys) and honours WordPress proxy configuration
	 * (WP_PROXY_HOST etc.) so streaming requests behave like the rest of the
	 * plugin's HTTP traffic. Kept in one place so both streaming providers share
	 * identical, auditable transport security.
	 *
	 * @param resource|\CurlHandle $ch      cURL handle.
	 * @param string               $target_url Destination URL (for proxy bypass checks).
	 * @return void
	 */
	private function harden_streaming_curl( $ch, $target_url ) {
		// Explicit TLS verification - do not rely on libcurl defaults.
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

		// Honour WordPress proxy settings when configured and not bypassed.
		if ( class_exists( 'WP_HTTP_Proxy' ) ) {
			$proxy = new WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $target_url ) ) {
				curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
				curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );
				if ( $proxy->use_authentication() ) {
					curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
					curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
				}
			}
		}
	}

	/**
	 * Stream OpenAI response using the Responses API (Server-Sent Events).
	 *
	 * Uses raw cURL rather than the WordPress HTTP API on purpose: wp_remote_*
	 * buffers the entire response body before returning, which makes real-time
	 * SSE token streaming to the browser impossible. cURL's CURLOPT_WRITEFUNCTION
	 * lets us forward each chunk as it arrives. Transport security is applied via
	 * harden_streaming_curl() (explicit TLS verification + WP proxy support).
	 */
	private function stream_openai_response( $messages ) {
		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'No OpenAI API key is configured. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		ob_implicit_flush( true );

		echo 'data: ' . json_encode(
			array(
				'content'    => '',
				'session_id' => $this->session->get_session_id(),
			)
		) . "\n\n";
		flush();

		$system_message = null;
		$user_messages  = array();

		foreach ( $messages as $msg ) {
			if ( $msg['role'] === 'system' ) {
				$system_message = $msg['content'];
			} else {
				$user_messages[] = $msg;
			}
		}

		$input = count( $user_messages ) === 1 ?
			$user_messages[0]['content'] :
			$user_messages;

		$tools = array();
		if ( ! empty( $this->openai_tools ) ) {
			$tools = array_merge( $tools, $this->prepare_native_tools( $this->openai_tools ) );
		}
		if ( ! empty( $this->actions ) ) {
			$tools = array_merge( $tools, $this->prepare_function_tools( $this->actions ) );
		}

		$model = strpos( $this->model, 'openai/' ) === 0 ?
			substr( $this->model, 7 ) : $this->model;

		$data = array(
			'model'  => $model,
			'input'  => $input,
			'stream' => true,
			'store'  => true,
		);

		if ( $system_message ) {
			$data['instructions'] = $system_message;
		}

		if ( ! empty( $tools ) ) {
			$data['tools'] = $tools;
		}

		// Reasoning models reject a non-default temperature/top_p (HTTP 400):
		// omit them entirely for those models.
		if ( ! WP_AI_Workflows_Utilities::is_reasoning_model( $model ) ) {
			if ( isset( $this->model_params['temperature'] ) ) {
				$data['temperature'] = floatval( $this->model_params['temperature'] );
			}
			if ( isset( $this->model_params['top_p'] ) ) {
				$data['top_p'] = floatval( $this->model_params['top_p'] );
			}
		}

		$ch = curl_init( 'https://api.openai.com/v1/responses' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $api_key,
			)
		);
		curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		$this->harden_streaming_curl( $ch, 'https://api.openai.com/v1/responses' );

		$responseAccumulator = '';
		$toolCalls           = array();

		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $data ) use ( &$responseAccumulator, &$toolCalls ) {
				$lines = explode( "\n", $data );

				foreach ( $lines as $line ) {
					if ( strlen( trim( $line ) ) === 0 ) {
						continue;
					}

					if ( strpos( $line, 'data:' ) === 0 ) {
						$jsonData = trim( substr( $line, 5 ) );

						if ( $jsonData === '[DONE]' ) {
							continue;
						}

						try {
							$event = json_decode( $jsonData, true );

							if ( isset( $event['type'] ) ) {
								$this->handle_streaming_event( $event, $responseAccumulator, $toolCalls );
							}
						} catch ( Exception $e ) {
							// Continue processing
						}
					}
				}

				return strlen( $data );
			}
		);

		curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err      = curl_error( $ch );
		curl_close( $ch );

		if ( $err || $httpCode >= 400 ) {
			echo 'data: ' . json_encode(
				array(
					'error'   => true,
					'message' => 'Error connecting to AI service.',
				)
			) . "\n\n";
			echo "data: [DONE]\n\n";
			flush();
		} else {
			if ( ! empty( $toolCalls ) ) {

				foreach ( $toolCalls as $toolCall ) {
					if ( isset( $toolCall['name'] ) && strpos( $toolCall['name'], 'action_' ) === 0 ) {
						$action_id = substr( $toolCall['name'], 7 );
						$arguments = isset( $toolCall['arguments'] ) ?
							json_decode( $toolCall['arguments'], true ) : array();

						echo 'data: ' . json_encode(
							array(
								'type'               => 'action',
								'action_id'          => $action_id,
								'action_data'        => $arguments,
								'has_pending_result' => true,
								'session_id'         => $this->session->get_session_id(),
							)
						) . "\n\n";
						flush();

						$this->execute_action_async( $action_id, $arguments );

						// Don't send DONE yet - the frontend polls for the action result.
						exit;
					}
				}
			} elseif ( ! empty( $responseAccumulator ) ) {
				$this->session->add_message( 'assistant', $responseAccumulator );
				$this->record_memory_turn( $this->current_message, $responseAccumulator );
			}

			if ( empty( $toolCalls ) ) {
				echo "data: [DONE]\n\n";
				flush();
			}
		}

		exit;
	}

	/**
	 * Handle streaming events from Responses API
	 */
	private function handle_streaming_event( $event, &$responseAccumulator, &$toolCalls ) {
		switch ( $event['type'] ) {
			// Text content events
			case 'response.text.delta':
			case 'response.output_text.delta':
			case 'response.content_part.delta':
				if ( isset( $event['delta'] ) ) {
					$content = $event['delta'];
					echo 'data: ' . json_encode( array( 'content' => $content ) ) . "\n\n";
					flush();
					$responseAccumulator .= $content;
				}
				break;

			// Initialize function call - Send processing message immediately
			case 'response.output_item.added':
				if ( isset( $event['item'] ) && $event['item']['type'] === 'function_call' ) {
					// Send processing message as soon as we detect a function call
					echo 'data: ' . json_encode(
						array(
							'content' => 'Processing your request...',
						)
					) . "\n\n";
					flush();

					$itemId               = $event['item']['id'];
					$toolCalls[ $itemId ] = array(
						'id'        => $itemId,
						'call_id'   => $event['item']['call_id'] ?? null,
						'name'      => $event['item']['name'],
						'arguments' => '',
					);
				}
				break;

			// Accumulate function arguments
			case 'response.function_call_arguments.delta':
				if ( isset( $event['item_id'] ) && isset( $event['delta'] ) ) {
					if ( isset( $toolCalls[ $event['item_id'] ] ) ) {
						$toolCalls[ $event['item_id'] ]['arguments'] .= $event['delta'];
					}
				}
				break;

			// Function arguments complete
			case 'response.function_call_arguments.done':
				if ( isset( $event['item'] ) ) {
					$itemId               = $event['item']['id'];
					$toolCalls[ $itemId ] = array(
						'id'        => $itemId,
						'name'      => $event['item']['name'],
						'arguments' => $event['item']['arguments'],
					);
				}
				break;
		}
	}

	/**
	 * Stream OpenRouter response (Server-Sent Events).
	 *
	 * Uses raw cURL rather than the WordPress HTTP API on purpose: wp_remote_*
	 * buffers the full body before returning, so real-time SSE token streaming to
	 * the browser is not possible through it. cURL's CURLOPT_WRITEFUNCTION lets us
	 * forward each chunk as it arrives. Transport security is applied via
	 * harden_streaming_curl() (explicit TLS verification + WP proxy support).
	 */
	private function stream_openrouter_response( $messages ) {
		$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'No OpenRouter API key is configured. Add one in Settings, or switch this chatbot to Credits (keyless).' );
		}

		$tools = $this->prepare_openrouter_tools();

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		ob_implicit_flush( true );

		echo 'data: ' . json_encode(
			array(
				'content'    => '',
				'session_id' => $this->session->get_session_id(),
			)
		) . "\n\n";
		flush();

		$data = array(
			'model'    => $this->model,
			'messages' => $messages,
			'stream'   => true,
		);

		if ( ! empty( $tools ) ) {
			$data['tools']       = $tools;
			$data['tool_choice'] = 'auto';
		}

		if ( ! empty( $this->model_params ) ) {
			foreach ( $this->model_params as $key => $value ) {
				$data[ $key ] = $value;
			}
		}
		// See call_openrouter() - same reasoning-model constraint applies.
		$this->shape_reasoning_params( $data, $this->model );

		$ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $api_key,
				'HTTP-Referer: ' . get_site_url(),
				'X-Title: WP AI Workflows',
			)
		);
		curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		$this->harden_streaming_curl( $ch, 'https://openrouter.ai/api/v1/chat/completions' );

		$responseAccumulator = '';
		$toolCallAccumulator = array();

		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $curl, $data ) use ( &$responseAccumulator, &$toolCallAccumulator ) {
				$lines = explode( "\n", $data );

				foreach ( $lines as $line ) {
					if ( strlen( trim( $line ) ) === 0 ) {
						continue;
					}

					if ( strpos( $line, ':' ) === 0 ) {
						continue; // OpenRouter comment
					}

					if ( strpos( $line, 'data:' ) === 0 ) {
						$jsonData = trim( substr( $line, 5 ) );

						if ( $jsonData === '[DONE]' ) {
							if ( ! empty( $toolCallAccumulator ) ) {

								foreach ( $toolCallAccumulator as $toolCall ) {
									$function_name = $toolCall['function']['name'];
									$arguments     = json_decode( $toolCall['function']['arguments'], true );

									if ( strpos( $function_name, 'action_' ) === 0 ) {
										$action_id = substr( $function_name, 7 );

										echo 'data: ' . json_encode(
											array(
												'type' => 'action',
												'action_id' => $action_id,
												'action_data' => $arguments,
												'has_pending_result' => true,
												'session_id' => $this->session->get_session_id(),
											)
										) . "\n\n";
										flush();

										$this->execute_action_async( $action_id, $arguments );

										exit; // No DONE - the frontend polls for the action result.
									}
								}
							} elseif ( ! empty( $responseAccumulator ) ) {
								$this->session->add_message( 'assistant', $responseAccumulator );
								$this->record_memory_turn( $this->current_message, $responseAccumulator );
							}

							if ( empty( $toolCallAccumulator ) ) {
								echo "data: [DONE]\n\n";
								flush();
							}
							continue;
						}

						try {
							$responseData = json_decode( $jsonData, true );

							if ( isset( $responseData['choices'][0]['delta'] ) ) {
								$delta = $responseData['choices'][0]['delta'];

								if ( isset( $delta['tool_calls'] ) ) {
									if ( empty( $toolCallAccumulator ) ) {
										echo 'data: ' . json_encode(
											array(
												'content' => 'Processing your request...',
											)
										) . "\n\n";
										flush();
									}

									foreach ( $delta['tool_calls'] as $toolCallDelta ) {
										$index = $toolCallDelta['index'];

										if ( ! isset( $toolCallAccumulator[ $index ] ) ) {
											$toolCallAccumulator[ $index ] = array(
												'id'       => '',
												'type'     => 'function',
												'function' => array(
													'name' => '',
													'arguments' => '',
												),
											);
										}

										if ( isset( $toolCallDelta['id'] ) ) {
											$toolCallAccumulator[ $index ]['id'] = $toolCallDelta['id'];
										}
										if ( isset( $toolCallDelta['function']['name'] ) ) {
											$toolCallAccumulator[ $index ]['function']['name'] .= $toolCallDelta['function']['name'];
										}
										if ( isset( $toolCallDelta['function']['arguments'] ) ) {
											$toolCallAccumulator[ $index ]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
										}
									}
								}

								if ( isset( $delta['content'] ) && empty( $toolCallAccumulator ) ) {
									$content = $delta['content'];
									echo 'data: ' . json_encode( array( 'content' => $content ) ) . "\n\n";
									flush();
									$responseAccumulator .= $content;
								}
							}
						} catch ( Exception $e ) {
							// Handle silently
						}
					}
				}

				return strlen( $data );
			}
		);

		curl_exec( $ch );
		curl_close( $ch );

		exit;
	}

	/**
	 * Execute action asynchronously
	 */
	private function execute_action_async( $action_id, $params ) {
		$execution_result = WP_AI_Workflows_Workflow::execute_workflow(
			$this->session->get_workflow_id(),
			$params,
			null,
			$this->session->get_session_id(),
			null,
			null,
			$action_id
		);

		if ( isset( $execution_result['execution_id'] ) ) {
			$execution_key = 'wp_ai_workflows_pending_execution_' . $this->session->get_session_id();
			set_transient(
				$execution_key,
				array(
					'execution_id' => $execution_result['execution_id'],
					'action_id'    => $action_id,
					'workflow_id'  => $this->session->get_workflow_id(),
					'timestamp'    => time(),
				),
				3600
			);
		}
	}

	/**
	 * Check action result (static method for cron/scheduled execution)
	 */
	public static function check_action_result( $execution_id, $action_id, $workflow_id, $session_id ) {
		try {
			global $wpdb;
			$executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';

			$execution = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id = %d",
					$executions_table,
					$execution_id
				)
			);

			if ( ! $execution ) {
				return;
			}

			// Still in progress - reschedule and check again shortly.
			if ( $execution->status === 'processing' || $execution->status === 'paused' ) {
				wp_schedule_single_event(
					time() + 3,
					'wp_ai_workflows_check_action_result',
					array(
						'execution_id' => $execution_id,
						'action_id'    => $action_id,
						'workflow_id'  => $workflow_id,
						'session_id'   => $session_id,
					)
				);
				return;
			}

			$output_data = json_decode( $execution->output_data, true );
			if ( empty( $output_data ) ) {
				return;
			}

			$chat_handler = new self( $workflow_id, $session_id );

			$chat_history      = $chat_handler->session->get_history();
			$formatted_history = array();

			if ( ! empty( $chat_history ) ) {
				$recent_history = array_slice( $chat_history, -5 );
				foreach ( $recent_history as $msg ) {
					$formatted_history[] = $msg->role . ': ' . $msg->content;
				}
			}

			// Exclude the chat node itself from the results shown to the AI.
			$chat_node_id = null;
			foreach ( $output_data as $node_id => $node_output ) {
				if ( isset( $node_output['type'] ) && $node_output['type'] === 'chat' ) {
					$chat_node_id = $node_id;
					break;
				}
			}

			$workflow_output = array();
			foreach ( $output_data as $node_id => $node_output ) {
				if ( $node_id !== $chat_node_id ) {
					$workflow_output[ $node_id ] = $node_output;
				}
			}

			$prompt  = "You just completed an automated action for the user. Here's what happened:\n\n";
			$prompt .= "Workflow Results:\n" . json_encode( $workflow_output, JSON_PRETTY_PRINT ) . "\n\n";
			$prompt .= 'Please provide a natural, conversational response about what you accomplished. ';
			$prompt .= 'Include all important details like URLs, IDs, or specific content that was created. ';
			$prompt .= 'Speak as if you personally completed the task. Be helpful and specific.';

			if ( ! empty( $formatted_history ) ) {
				$prompt = "Recent conversation:\n" . implode( "\n", $formatted_history ) . "\n\n" . $prompt;
			}

			$context = array(
				array(
					'role'    => 'system',
					'content' => $chat_handler->system_prompt . "\n\nYou have access to automated actions and have just completed one. Respond naturally about what you've done, including all relevant details from the results.",
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);

			try {
				$raw_response = $chat_handler->get_ai_response( $context );

				$response_content = '';
				if ( is_array( $raw_response ) && isset( $raw_response['choices'][0]['message']['content'] ) ) {
					$response_content = $raw_response['choices'][0]['message']['content'];
				}

				if ( ! empty( $response_content ) ) {
					$chat_handler->session->add_message( 'assistant', $response_content );

					set_transient(
						'wp_ai_workflows_action_result_' . $session_id,
						array(
							'role'      => 'assistant',
							'content'   => $response_content,
							'timestamp' => time(),
						),
						3600
					);
				}
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Error generating action result response',
					'error',
					array(
						'error' => $e->getMessage(),
					)
				);
			}
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Critical error in check_action_result',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Map field types to JSON schema types
	 */
	private function map_field_type( $type ) {
		$type_map = array(
			'text'    => 'string',
			'number'  => 'number',
			'email'   => 'string',
			'url'     => 'string',
			'select'  => 'string',
			'boolean' => 'boolean',
			'array'   => 'array',
			'phone'   => 'string',
		);

		return $type_map[ $type ] ?? 'string';
	}

	/**
	 * Get chat history
	 */
	public function get_chat_history() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_chat_messages';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i 
            WHERE session_id = %s 
            ORDER BY created_at ASC",
				$table_name,
				$this->session->get_session_id()
			)
		);
	}

	/**
	 * Get workflow by ID
	 */
	private function get_workflow_by_id( $workflow_id ) {
		return WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow_id );
	}

	/**
	 * Find chat node in workflow
	 */
	private function find_chat_node( $nodes ) {
		foreach ( $nodes as $node ) {
			if ( $node['type'] === 'chat' ) {
				return $node;
			}
		}
		return null;
	}
}
