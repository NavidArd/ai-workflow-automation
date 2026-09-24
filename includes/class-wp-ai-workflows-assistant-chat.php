<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Assistant_Chat {
	private $session_id;
	private $workflow_id;
	private $workflow_context;
	private $selected_node;
	private $mode;
	private $prompt_path;

	public function __construct( $workflow_id = null, $session_id = null ) {
		if ( $workflow_id && $session_id ) {
			$this->workflow_id = $workflow_id;
			$this->session_id  = $session_id;
			$this->load_session( $session_id );
		}
		$this->prompt_path = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/assistant_system_prompt.xml';
	}

	public function init() {
	}

	/**
	 * Assembled STATIC system prompt: XML instructions with the dynamic node
	 * catalog injected in place of {NODE_CATALOG}. Deterministic and cached
	 * (1h) so it forms a valid Anthropic prompt-cache prefix in get_ai_response().
	 *
	 * @return string
	 * @throws Exception When the prompt file is missing.
	 */
	private function get_system_prompt() {
		$cache_key = 'wp_ai_workflows_assistant_system_prompt';
		$cached    = wp_cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( ! file_exists( $this->prompt_path ) ) {
			throw new Exception( 'Assistant system prompt not found' );
		}

		$prompt = file_get_contents( $this->prompt_path );

		// Inject the manifest-built node catalog. If the placeholder is absent (older
		// prompt file) the text is left untouched, so this is safe to run always.
		if ( false !== strpos( $prompt, '{NODE_CATALOG}' )
			&& class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			$catalog = WP_AI_Workflows_Node_Catalog::to_prompt_section();
			if ( ! empty( $catalog ) ) {
				$prompt = str_replace( '{NODE_CATALOG}', $catalog, $prompt );
			}
		}

		wp_cache_set( $cache_key, $prompt, '', HOUR_IN_SECONDS );

		return $prompt;
	}

	public function start_session( $workflow_id, $session_id = null ) {
		$this->workflow_id = $workflow_id;

		if ( $session_id ) {
			$this->load_session( $session_id );
		} else {
			$this->create_session();
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Session started',
			'debug',
			array(
				'session_id' => $this->session_id,
			)
		);

		return $this->session_id;
	}

	private function load_session( $session_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE session_id = %s AND workflow_id = %s",
				$table_name,
				$session_id,
				$this->workflow_id
			)
		);

		if ( ! $session ) {
			throw new Exception( 'Invalid session' );
		}

		$this->session_id       = $session->session_id;
		$this->workflow_context = json_decode( $session->workflow_context, true );
		$this->selected_node    = $session->selected_node;
		$this->mode             = $session->mode;

		$this->update_session_timestamp();
	}

	private function create_session() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		WP_AI_Workflows_Utilities::debug_log(
			'Creating new assistant session',
			'debug',
			array(
				'workflow_id' => $this->workflow_id,
			)
		);

		$this->session_id = wp_generate_uuid4();
		$this->mode       = 'chat';

		// Use prepare directly in query to safely handle table name
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO %i (session_id, workflow_id, mode, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)",
			$table_name,
			$this->session_id,
			$this->workflow_id,
			$this->mode,
			current_time( 'mysql' ),
			current_time( 'mysql' )
		) );

		if ( $wpdb->last_error ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Database error creating session',
				'error',
				array(
					'error' => esc_html( $wpdb->last_error ),
					'table' => $table_name,
				)
			);
			throw new Exception( 'Failed to create session: ' . esc_html( $wpdb->last_error ) );
		}

		if ( $result === false || $result === 0 ) {
			WP_AI_Workflows_Utilities::debug_log( 'Failed to insert session', 'error' );
			throw new Exception( 'Failed to create session: Insert failed' );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Session created successfully',
			'debug',
			array(
				'session_id' => $this->session_id,
			)
		);
	}

	public function send_message( $content ) {
		global $wpdb;

		if ( ! $this->session_id ) {
			throw new Exception( 'No active session' );
		}

		$this->add_message( 'user', $content );

		try {
			$response = $this->get_ai_response( $content );

			if ( $this->mode === 'assistant' && is_string( $response ) ) {
				$json_match = preg_match( '/\{[\s\S]*\}/m', $response, $matches );
				if ( $json_match && $matches[0] ) {
					try {
						$parsed = json_decode( $matches[0], true );
						if ( isset( $parsed['changes'] ) && isset( $parsed['explanation'] ) ) {
							if ( ! empty( $parsed['explanation'] ) ) {
								$this->add_message( 'assistant', $parsed['explanation'] );
							}

							$message_id = $this->add_message(
								'assistant',
								'workflow-changes-approval',
								array(
									'changes'        => $parsed['changes'],
									'approvalStatus' => 'pending',
									'messageId'      => uniqid( 'approval-' ),
								)
							);

							return $response;
						}
					} catch ( Exception $e ) {
						// Not valid JSON, treat as regular message.
					}
				}
			}

			$this->add_message( 'assistant', $response );

			return $response;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Assistant chat error',
				'error',
				array(
					'error'      => $e->getMessage(),
					'session_id' => $this->session_id,
				)
			);
			throw $e;
		}
	}

	private function get_ai_response( $message ) {
		try {
			$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
			if ( empty( $api_key ) ) {
				throw new Exception( 'OpenRouter API key not configured' );
			}

			// STATIC, cacheable prefix: the instructions + manifest node catalog.
			// It is byte-identical across EVERY assistant request (chat AND edit
			// mode — the active mode now lives in the dynamic user turn below, not
			// in this prefix), so it is a valid Anthropic prompt-cache prefix.
			$system_prompt = $this->get_system_prompt();

			// Prompt caching: the big static prefix comes FIRST as a system content
			// block marked with an Anthropic `cache_control` breakpoint, so every
			// request after the first reads it from cache (~90% cheaper + faster).
			// OpenRouter forwards `cache_control` to Anthropic; on a non-Anthropic
			// fallback model it is simply ignored (safe). The conversation history
			// and the user's current turn (with the live canvas state) go LAST, as
			// the dynamic, uncached tail — mirrors the generator's proven approach.
			$messages = array(
				array(
					'role'    => 'system',
					'content' => array(
						array(
							'type'          => 'text',
							'text'          => $system_prompt,
							'cache_control' => array( 'type' => 'ephemeral' ),
						),
					),
				),
			);

			// Conversation history (dynamic; not cached). The workflow-changes-approval
			// placeholder rows carry no useful text for the model — represent them as a
			// short note instead of leaking the raw sentinel string into the transcript.
			foreach ( $this->get_chat_history() as $msg ) {
				$text = ( 'workflow-changes-approval' === $msg->content )
					? '(Proposed workflow changes were shown to the user for approval.)'
					: $msg->content;
				$messages[] = array(
					'role'    => $msg->role,
					'content' => $text,
				);
			}

			// Final USER turn (dynamic; not cached): the active mode, the live canvas
			// state, the selected node, and the user's actual message. Kept LAST so the
			// static prefix above remains the cache-hitting portion of the request.
			$context_string = $this->workflow_context ?
				wp_json_encode( $this->workflow_context, JSON_PRETTY_PRINT ) :
				'No workflow context available';

			$mode_reminder = 'assistant' === $this->mode
				? 'ACTIVE MODE: EDIT. Respond with ONLY the single JSON changes object described in <edit_response_format>. No prose before or after it.'
				: 'ACTIVE MODE: CHAT. Answer in professional HTML as described in <chat_response_format>. Do not return JSON changes.';

			$user_turn = $mode_reminder . "\n\n"
				. "Current workflow canvas state:\n```json\n" . $context_string . "\n```\n"
				. ( $this->selected_node ? 'Selected node: ' . $this->selected_node . "\n" : '' )
				. "\nUser request:\n" . $message;

			$messages[] = array(
				'role'    => 'user',
				'content' => $user_turn,
			);

			$request_body = array(
				'model'       => WP_AI_Workflows_Model_Catalog::get_reasoning_model(),
				'messages'    => $messages,
				'temperature' => 0.7,
				'max_tokens'  => 10000,
			);

			if ( $this->mode === 'assistant' ) {
				$request_body['response_format'] = array( 'type' => 'json_object' );
			}

			$response = wp_remote_post(
				'https://openrouter.ai/api/v1/chat/completions',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'HTTP-Referer'  => get_site_url(),
						'X-Title'       => 'WP AI Workflow Assistant',
					),
					'body'    => wp_json_encode( $request_body ),
					'timeout' => 120,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
				throw new Exception( 'Invalid API response' );
			}

			// Cache observability: log the provider usage block so the prompt-cache
			// write -> read (cache_creation_input_tokens on the first call, then
			// cached/cache_read tokens on the next) is verifiable from the debug log.
			// No prompt content and no secrets are logged.
			if ( isset( $data['usage'] ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Assistant OpenRouter usage',
					'info',
					array(
						'mode'  => $this->mode,
						'usage' => $data['usage'],
					)
				);
			}

			$content = $data['choices'][0]['message']['content'];

			if ( $this->mode === 'assistant' ) {
				$content           = $data['choices'][0]['message']['content'];
				$suggested_changes = json_decode( $content, true );

				if ( ! $suggested_changes || json_last_error() !== JSON_ERROR_NONE ) {
					return wp_json_encode(
						array(
							'explanation' => 'I understand your request but need more specific information about what to change. Could you please be more specific about what aspects of the workflow you\'d like me to improve?',
							'changes'     => array(
								'modified'    => array(),
								'added'       => array(),
								'removed'     => array(),
								'connections' => array(),
							),
						)
					);
				}
			}

			return $content;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error in get_ai_response',
				'error',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			throw $e;
		}
	}

	private function validate_suggested_changes( $changes ) {
		if ( ! isset( $changes['explanation'] ) || ! isset( $changes['changes'] ) ) {
			return false;
		}

		$required_change_types = array( 'modified', 'added', 'removed', 'connections' );
		foreach ( $required_change_types as $type ) {
			if ( ! isset( $changes['changes'][ $type ] ) ) {
				return false;
			}
		}

		return true;
	}

	public function apply_workflow_changes( $changes ) {
		try {
			$workflow = $this->workflow_context;

			WP_AI_Workflows_Utilities::debug_log(
				'Starting workflow changes',
				'debug',
				array(
					'initial_workflow' => $workflow,
					'changes'          => $changes,
				)
			);

			if ( ! empty( $changes['modified'] ) ) {
				foreach ( $changes['modified'] as $modification ) {
					foreach ( $workflow['nodes'] as &$node ) {
						if ( $node['id'] === $modification['id'] ) {
							if ( isset( $modification['after']['settings'] ) ) {
								$node['data']['settings'] = array_merge(
									$node['data']['settings'] ?? array(),
									$modification['after']['settings']
								);
							}

							if ( isset( $modification['after']['content'] ) ) {
								$node['data']['content'] = $modification['after']['content'];
							} elseif ( isset( $modification['after']['prompt'] ) ) {
								$node['data']['content'] = $modification['after']['prompt'];
							} elseif ( isset( $modification['after']['systemPrompt'] ) ) {

								$node['data']['content'] = $modification['after']['systemPrompt'];
							}

							if ( isset( $modification['after']['model'] ) ) {
								$node['data']['model'] = $modification['after']['model'];
							}

							if ( isset( $modification['after']['nodeName'] ) ) {
								$node['data']['nodeName'] = $modification['after']['nodeName'];
							}

							switch ( $node['type'] ) {
								case 'trigger':
									if ( isset( $modification['after']['triggerType'] ) ) {
										$node['data']['triggerType'] = $modification['after']['triggerType'];
									}
									if ( isset( $modification['after']['selectedForm'] ) ) {
										$node['data']['selectedForm'] = $modification['after']['selectedForm'];
									}
									if ( isset( $modification['after']['selectedFields'] ) ) {
										$node['data']['selectedFields'] = $modification['after']['selectedFields'];
									}
									break;

								case 'aiModel':
									if ( isset( $modification['after']['imageUrls'] ) ) {
										$node['data']['imageUrls'] = $modification['after']['imageUrls'];
									}
									if ( isset( $modification['after']['openaiTools'] ) ) {
										$node['data']['openaiTools'] = $modification['after']['openaiTools'];
									}
									break;

								case 'output':
									if ( isset( $modification['after']['outputType'] ) ) {
										$node['data']['outputType'] = $modification['after']['outputType'];
									}
									break;

								case 'chat':
									if ( isset( $modification['after']['systemPrompt'] ) ) {
										$node['data']['systemPrompt'] = $modification['after']['systemPrompt'];
									}

									// Backward compatibility: also accept 'content'.
									if ( isset( $modification['after']['content'] ) && ! isset( $modification['after']['systemPrompt'] ) ) {
										$node['data']['systemPrompt'] = $modification['after']['content'];
									}

									if ( isset( $modification['after']['design'] ) ) {
										$node['data']['design'] = $modification['after']['design'];
									}
									if ( isset( $modification['after']['behavior'] ) ) {
										$node['data']['behavior'] = $modification['after']['behavior'];
									}
									if ( isset( $modification['after']['model'] ) ) {
										$node['data']['model'] = $modification['after']['model'];
									}
									if ( isset( $modification['after']['modelParams'] ) ) {
										$node['data']['modelParams'] = $modification['after']['modelParams'];
									}
									if ( isset( $modification['after']['actions'] ) ) {
										$node['data']['actions'] = $modification['after']['actions'];
									}
									break;

								case 'APICall':
									if ( isset( $modification['after']['method'] ) ) {
										$node['data']['method'] = $modification['after']['method'];
									}
									if ( isset( $modification['after']['url'] ) ) {
										$node['data']['url'] = $modification['after']['url'];
									}
									if ( isset( $modification['after']['body'] ) ) {
										$node['data']['body'] = $modification['after']['body'];
									}
									if ( isset( $modification['after']['headers'] ) ) {
										$node['data']['headers'] = $modification['after']['headers'];
									}
									if ( isset( $modification['after']['queryParams'] ) ) {
										$node['data']['queryParams'] = $modification['after']['queryParams'];
									}
									if ( isset( $modification['after']['auth'] ) ) {
										$node['data']['auth'] = $modification['after']['auth'];
									}
									if ( isset( $modification['after']['responseConfig'] ) ) {
										$node['data']['responseConfig'] = $modification['after']['responseConfig'];
									}
									break;

								// Add cases for other node types as needed
							}

							// Generic data merge as fallback.
							if ( isset( $modification['after']['data'] ) ) {
								$node['data'] = array_merge(
									$node['data'],
									$modification['after']['data']
								);
							}

							WP_AI_Workflows_Utilities::debug_log(
								'Node modified',
								'debug',
								array(
									'node_id' => $node['id'],
									'after'   => $node,
								)
							);
						}
					}
				}
			}

			if ( ! empty( $changes['added'] ) ) {
				foreach ( $changes['added'] as $new_node ) {
					if ( ! isset( $new_node['id'] ) || empty( $new_node['id'] ) ) {
						$new_node['id'] = ( $new_node['type'] ?? 'node' ) . '-' . time() . rand( 1000, 9999 );
					}

					if ( ! isset( $new_node['position'] ) ) {
						$max_x = 250;
						$max_y = 300;

						foreach ( $workflow['nodes'] as $existing_node ) {
							if ( isset( $existing_node['position']['x'] ) && $existing_node['position']['x'] > $max_x ) {
								$max_x = $existing_node['position']['x'] + 400;
							}
						}

						$new_node['position'] = array(
							'x' => $max_x,
							'y' => $max_y,
						);
					}

					if ( ! isset( $new_node['data'] ) ) {
						$new_node['data'] = array();
					}

					$new_node['draggable'] = true;

					switch ( $new_node['type'] ) {
						case 'aiModel':
							$new_node['data']['nodeName']  = $new_node['data']['nodeName'] ?? 'AI Model ' . $new_node['id'];
							$new_node['data']['settings']  = $new_node['data']['settings'] ?? array(
								'temperature'        => 0.1,
								'top_p'              => 1.0,
								'top_k'              => 0,
								'frequency_penalty'  => 0.0,
								'presence_penalty'   => 0.0,
								'repetition_penalty' => 1.0,
								'max_tokens'         => 4096,
							);
							$new_node['data']['model']     = $new_node['data']['model'] ?? WP_AI_Workflows_Model_Catalog::get_default_model();
							$new_node['data']['content']   = $new_node['data']['content'] ?? '';
							$new_node['data']['imageUrls'] = $new_node['data']['imageUrls'] ?? array();
							break;

						case 'trigger':
							$new_node['data']['nodeName']    = $new_node['data']['nodeName'] ?? 'Trigger ' . $new_node['id'];
							$new_node['data']['triggerType'] = $new_node['data']['triggerType'] ?? 'manual';
							$new_node['data']['content']     = $new_node['data']['content'] ?? '';
							break;

						case 'output':
							$new_node['data']['nodeName']   = $new_node['data']['nodeName'] ?? 'Output ' . $new_node['id'];
							$new_node['data']['outputType'] = $new_node['data']['outputType'] ?? 'text';
							break;

						case 'post':
							$new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Post ' . $new_node['id'];
							break;

						case 'chat':
							$new_node['data']['nodeName'] = $new_node['data']['nodeName'] ?? 'Chat ' . $new_node['id'];

							// Normalize legacy 'content' into 'systemPrompt'.
							if ( isset( $new_node['data']['content'] ) && ! isset( $new_node['data']['systemPrompt'] ) ) {
								$new_node['data']['systemPrompt'] = $new_node['data']['content'];
								unset( $new_node['data']['content'] );
							} else {
								$new_node['data']['systemPrompt'] = $new_node['data']['systemPrompt'] ?? '';
							}

							$new_node['data']['model'] = $new_node['data']['model'] ?? 'anthropic/claude-sonnet-5';

							$new_node['data']['modelParams'] = $new_node['data']['modelParams'] ?? array(
								'temperature'        => 0.7,
								'top_p'              => 1.0,
								'top_k'              => 0,
								'frequency_penalty'  => 0.0,
								'presence_penalty'   => 0.0,
								'repetition_penalty' => 1.0,
								'max_tokens'         => 10000,
							);

							$new_node['data']['design'] = $new_node['data']['design'] ?? array(
								'theme'          => 'light',
								'position'       => 'bottom-right',
								'dimensions'     => array(
									'width'        => 380,
									'height'       => 600,
									'borderRadius' => 12,
								),
								'colors'         => array(
									'primary'    => '#1677ff',
									'secondary'  => '#f5f5f5',
									'text'       => '#000000',
									'background' => '#ffffff',
								),
								'font'           => array(
									'family'     => 'Inter, system-ui, sans-serif',
									'size'       => '14px',
									'headerSize' => '16px',
								),
								'botName'        => 'AI Assistant',
								'botIcon'        => 'robot',
								'quickResponses' => array(),
								'customCSS'      => '',
								'sendButtonText' => 'Send',
								'showPoweredBy'  => true,
							);

							$new_node['data']['behavior'] = $new_node['data']['behavior'] ?? array(
								'initialMessage'      => 'Hello! How can I help you today?',
								'initialMessageType'  => 'static',
								'placeholderText'     => 'Type your message here...',
								'maxHistoryLength'    => 50,
								'showTypingIndicator' => true,
								'soundEffects'        => true,
								'showCitations'       => false,
								'autoOpenDelay'       => 0,
								'persistHistory'      => true,
								'includePageContext'  => false,
								'streamResponses'     => false,
								'rateLimit'           => array(
									'enabled'     => true,
									'maxMessages' => 10,
									'timeWindow'  => 60,
								),
							);

							$new_node['data']['openaiTools'] = $new_node['data']['openaiTools'] ?? array(
								'webSearch'  => array(
									'enabled'     => false,
									'contextSize' => 'medium',
									'location'    => array(
										'city'    => '',
										'region'  => '',
										'country' => '',
									),
								),
								'fileSearch' => array(
									'enabled'       => false,
									'vectorStoreId' => '',
									'maxResults'    => 5,
								),
							);

							$new_node['data']['actions'] = $new_node['data']['actions'] ?? array();

							if ( isset( $workflow['id'] ) ) {
								$new_node['data']['workflowId'] = $workflow['id'];
							}
							break;
					}

					// Resolve a "Connect an App" node against the live app registry so
					// its appSlug/toolName are real, never a guessed value (shared with
					// the generator). If unresolvable, the node keeps its intent and the
					// builder shows the app picker for the user to configure.
					if ( ( $new_node['type'] ?? '' ) === 'MCPClient' && class_exists( 'WP_AI_Workflows_Generator' ) ) {
						$resolved_app = WP_AI_Workflows_Generator::resolve_app_node_data( $new_node['data'] ?? array() );
						if ( is_array( $resolved_app ) ) {
							$new_node['data'] = array_merge( $new_node['data'] ?? array(), $resolved_app );
						}
					}

					$workflow['nodes'][] = $new_node;

					WP_AI_Workflows_Utilities::debug_log(
						'Added node',
						'debug',
						array(
							'node_id' => $new_node['id'],
							'node'    => $new_node,
						)
					);
				}
			}

			if ( ! empty( $changes['removed'] ) ) {
				$workflow['nodes'] = array_filter(
					$workflow['nodes'],
					function ( $node ) use ( $changes ) {
						return ! in_array( $node['id'], $changes['removed'] );
					}
				);

				if ( ! empty( $workflow['edges'] ) ) {
					$workflow['edges'] = array_filter(
						$workflow['edges'],
						function ( $edge ) use ( $changes ) {
							return ! in_array( $edge['source'], $changes['removed'] ) &&
								! in_array( $edge['target'], $changes['removed'] );
						}
					);
				}
			}

			if ( ! empty( $changes['connections'] ) ) {
				if ( ! isset( $workflow['edges'] ) || ! is_array( $workflow['edges'] ) ) {
					$workflow['edges'] = array();
				}

				foreach ( $changes['connections'] as $connection ) {
					if ( $connection['action'] === 'add' ) {
						$edge = $connection['edge'];

						// Normalize source and target handles.
						$sourceHandle = isset( $edge['sourceHandle'] ) ?
							( $edge['sourceHandle'] === 'output' ? 'a' : $edge['sourceHandle'] ) : 'a';
						$targetHandle = isset( $edge['targetHandle'] ) ?
							( $edge['targetHandle'] === 'input' ? null : $edge['targetHandle'] ) : null;

						$edgeId = 'xy-edge__' . $edge['source'] . $sourceHandle . '-' . $edge['target'];

						$newEdge = array(
							'id'           => $edgeId,
							'type'         => 'default',
							'animated'     => false,
							'source'       => $edge['source'],
							'sourceHandle' => $sourceHandle,
							'target'       => $edge['target'],
						);

						if ( $targetHandle !== null ) {
							$newEdge['targetHandle'] = $targetHandle;
						}

						$edge_exists = false;
						foreach ( $workflow['edges'] as $existing_edge ) {
							if ( $existing_edge['source'] === $newEdge['source'] &&
								$existing_edge['target'] === $newEdge['target'] ) {
								$edge_exists = true;
								break;
							}
						}

						if ( ! $edge_exists ) {
							$workflow['edges'][] = $newEdge;
							WP_AI_Workflows_Utilities::debug_log(
								'Added edge',
								'debug',
								array(
									'edge' => $newEdge,
								)
							);
						}
					} elseif ( $connection['action'] === 'remove' ) {
						$edge_to_remove = $connection['edge'];

						$workflow['edges'] = array_filter(
							$workflow['edges'],
							function ( $edge ) use ( $edge_to_remove ) {
								// Check by ID first (more specific)
								if ( isset( $edge_to_remove['id'] ) && $edge['id'] === $edge_to_remove['id'] ) {
									WP_AI_Workflows_Utilities::debug_log(
										'Removing edge by ID',
										'debug',
										array(
											'edge_id' => $edge['id'],
										)
									);
									return false;
								}

								// Fallback to source/target matching if no ID provided
								if ( $edge['source'] === $edge_to_remove['source'] &&
								$edge['target'] === $edge_to_remove['target'] ) {
									WP_AI_Workflows_Utilities::debug_log(
										'Removing edge by source/target',
										'debug',
										array(
											'source' => $edge['source'],
											'target' => $edge['target'],
										)
									);
									return false;
								}

								return true;
							}
						);

						// IMPORTANT: Reindex the array after filtering
						$workflow['edges'] = array_values( $workflow['edges'] );
					}
				}

				// Final reindexing to ensure proper JSON encoding
				$workflow['edges'] = array_values( $workflow['edges'] );

				WP_AI_Workflows_Utilities::debug_log(
					'Connection changes applied',
					'debug',
					array(
						'final_edge_count'      => count( $workflow['edges'] ),
						'connections_processed' => count( $changes['connections'] ),
					)
				);
			}

			$original_workflow = WP_AI_Workflows_Workflow_DBAL::get_workflow_by_id( $workflow['id'] );

			if ( isset( $original_workflow['createdBy'] ) && empty( $workflow['createdBy'] ) ) {
				$workflow['createdBy'] = $original_workflow['createdBy'];
			}

			if ( isset( $original_workflow['status'] ) ) {
				$workflow['status'] = $original_workflow['status'];
			} else {
				$workflow['status'] = 'active';
			}

			if ( isset( $original_workflow['createdAt'] ) ) {
				$workflow['createdAt'] = $original_workflow['createdAt'];
			}

			$workflow['updatedAt'] = current_time( 'mysql' );

			$update_result = WP_AI_Workflows_Workflow_DBAL::update_workflow( $workflow['id'], $workflow );

			if ( $update_result === false ) {
				throw new Exception( 'Failed to save workflow changes to database' );
			}

			$this->update_workflow_context( $workflow );

			WP_AI_Workflows_Utilities::debug_log(
				'Workflow changes saved successfully',
				'debug',
				array(
					'workflow_id'   => $workflow['id'],
					'update_result' => $update_result,
				)
			);

			return $workflow;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Error applying workflow changes',
				'error',
				array(
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
					'changes' => $changes,
				)
			);
			throw $e;
		}
	}

	public function update_workflow_context( $workflow_data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		if ( ! $this->session_id ) {
			WP_AI_Workflows_Utilities::debug_log( 'Cannot update context - no session ID', 'error' );
			throw new Exception( 'No active session' );
		}

		$this->workflow_context = $workflow_data;

		WP_AI_Workflows_Utilities::debug_log(
			'Updating workflow context',
			'debug',
			array(
				'session_id'   => $this->session_id,
				'context_size' => strlen( json_encode( $workflow_data ) ),
			)
		);

		// Use prepare directly in query to safely handle table name
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET workflow_context = %s, updated_at = %s WHERE session_id = %s",
			$table_name,
			json_encode( $workflow_data ),
			current_time( 'mysql' ),
			$this->session_id
		) );

		if ( $result === false ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to update context',
				'error',
				array(
					'last_error' => $wpdb->last_error,
				)
			);
			throw new Exception( 'Failed to update workflow context' );
		}

		return $result;
	}


	public function update_selected_node( $node_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		$this->selected_node = $node_id;

		// Use prepare directly in query to safely handle table name
		$wpdb->query( $wpdb->prepare(
			"UPDATE %i SET selected_node = %s, updated_at = %s WHERE session_id = %s",
			$table_name,
			$node_id,
			current_time( 'mysql' ),
			$this->session_id
		) );
	}

	private function update_session_timestamp() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		// Use prepare directly in query to safely handle table name
		$wpdb->query( $wpdb->prepare(
			"UPDATE %i SET updated_at = %s WHERE session_id = %s",
			$table_name,
			current_time( 'mysql' ),
			$this->session_id
		) );
	}

	public function get_session_id() {
		return $this->session_id;
	}

	public function update_mode( $mode ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';

		WP_AI_Workflows_Utilities::debug_log(
			'Updating session mode',
			'debug',
			array(
				'session_id' => $this->session_id,
				'new_mode'   => $mode,
			)
		);

		// Use prepare directly in query to safely handle table name
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET mode = %s, updated_at = %s WHERE session_id = %s",
			$table_name,
			$mode,
			current_time( 'mysql' ),
			$this->session_id
		) );

		if ( $result === false ) {
			throw new Exception( 'Failed to update session mode' );
		}

		$this->mode = $mode;
		return true;
	}

	public function add_message( $role, $content, $metadata = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

		// Use prepare directly in query to safely handle table name
		if ( $metadata !== null ) {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO %i (session_id, role, content, metadata, created_at) VALUES (%s, %s, %s, %s, %s)",
				$table_name,
				$this->session_id,
				$role,
				$content,
				json_encode( $metadata ),
				current_time( 'mysql' )
			) );
		} else {
			$result = $wpdb->query( $wpdb->prepare(
				"INSERT INTO %i (session_id, role, content, created_at) VALUES (%s, %s, %s, %s)",
				$table_name,
				$this->session_id,
				$role,
				$content,
				current_time( 'mysql' )
			) );
		}

		if ( $result === false || $result === 0 ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Failed to add message',
				'error',
				array(
					'error' => $wpdb->last_error,
				)
			);
			throw new Exception( 'Failed to add message' );
		}

		// Since we're using wpdb->query instead of insert, we need to get the last insert ID differently
		return $wpdb->insert_id;
	}

	/**
	 * Trim message history to a maximum number of messages
	 *
	 * @param int $max_messages Maximum number of messages to keep
	 * @return bool True if messages were trimmed, false otherwise
	 */
	private function trim_message_history( $max_messages = 30 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE session_id = %s",
				$table_name,
				$this->session_id
			)
		);

		if ( $count <= $max_messages ) {
			return false;
		}

		$to_remove = $count - $max_messages;

		$message_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT message_id FROM %i 
				WHERE session_id = %s 
				ORDER BY created_at ASC 
				LIMIT %d",
			$table_name,
			$this->session_id,
			$to_remove
		)
		);

		if ( empty( $message_ids ) ) {
			return false;
		}

		// Delete the oldest messages using individual deletes to avoid string concatenation
		foreach ( $message_ids as $message_id ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM %i WHERE message_id = %d",
				$table_name,
				$message_id
			) );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Trimmed assistant chat history',
			'debug',
			array(
				'session_id'    => $this->session_id,
				'removed_count' => $to_remove,
				'new_count'     => $max_messages,
			)
		);

		return true;
	}

	public function get_chat_history() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

		$messages = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT message_id, role, content, metadata, created_at 
			FROM %i 
			WHERE session_id = %s 
			ORDER BY created_at ASC",
			$table_name,
			$this->session_id
		)
		);

		foreach ( $messages as &$message ) {
			if ( $message->metadata ) {
				$message->metadata = json_decode( $message->metadata, true );
			}
		}

		return $messages;
	}

	/**
	 * Delete sessions and messages older than 90 days.
	 */
	public static function cleanup_old_data() {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'wp_ai_workflows_assistant_sessions';
		$messages_table = $wpdb->prefix . 'wp_ai_workflows_assistant_messages';

		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

		$old_sessions = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT session_id FROM %i WHERE updated_at < %s",
			$sessions_table,
			$cutoff_date
		)
		);

		if ( empty( $old_sessions ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'No old assistant sessions to clean up' );
			return;
		}

		$count = count( $old_sessions );

		if ( $count > 0 ) {
			foreach ( $old_sessions as $session_id ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM %i WHERE session_id = %s",
					$messages_table,
					$session_id
				) );
			}

			foreach ( $old_sessions as $session_id ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM %i WHERE session_id = %s",
					$sessions_table,
					$session_id
				) );
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Cleaned up old assistant sessions',
			'info',
			array(
				'sessions_removed' => $count,
				'cutoff_date'      => $cutoff_date,
			)
		);
	}
}
