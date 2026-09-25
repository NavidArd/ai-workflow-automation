<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class WP_AI_Workflows_Generator {
	private $system_prompt = null;
	private $prompt_path   = null;
	private $template_path = null;
	private $workflow      = null;

	public function __construct() {
		$this->prompt_path   = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/prompts/system_prompt.xml';
		$this->template_path = WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/templates/system_prompt.xml';
	}

	private function ensure_system_prompt() {
		if ( $this->system_prompt === null ) {
			$this->load_system_prompt();
		}
		return $this->system_prompt;
	}

	private function load_system_prompt() {
		$cache_key     = 'wp_ai_workflows_system_prompt';
		$cached_prompt = wp_cache_get( $cache_key );

		if ( $cached_prompt !== false ) {
			$this->system_prompt = $cached_prompt;
			return;
		}

		if ( ! file_exists( $this->prompt_path ) ) {
			$this->initialize_prompt_file();
		}

		$prompt = file_get_contents( $this->prompt_path );

		if ( empty( $prompt ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'System prompt is empty', 'error' );
			throw new Exception( 'System prompt is empty' );
		}

		// Self-heal a stale runtime prompt file missing {NODE_CATALOG} (e.g. upgraded
		// from a pre-catalog build): fall back to the shipped template in memory
		// without rewriting the on-disk copy.
		if ( false === strpos( $prompt, '{NODE_CATALOG}' )
			&& file_exists( $this->template_path ) ) {
			$template = file_get_contents( $this->template_path );
			if ( ! empty( $template ) && false !== strpos( $template, '{NODE_CATALOG}' ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Runtime system prompt is stale (missing {NODE_CATALOG}); using shipped template instead',
					'warning'
				);
				$prompt = $template;
			}
		}

		// Inject the dynamic node catalog built from the Node Manifest System.
		// to_prompt_section() is deterministic (fixed ordering/field emission) so the
		// assembled prompt stays byte-identical across requests, keeping it a valid
		// Anthropic prompt-cache prefix (see generate_workflow()).
		if ( false !== strpos( $prompt, '{NODE_CATALOG}' )
			&& class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			$catalog = WP_AI_Workflows_Node_Catalog::to_prompt_section();
			if ( ! empty( $catalog ) ) {
				$prompt = str_replace( '{NODE_CATALOG}', $catalog, $prompt );
			}
		}

		// Local cache of the assembled prompt; avoids re-reading the file and
		// re-serializing the catalog per request. clear_prompt_cache() busts this key.
		wp_cache_set( $cache_key, $prompt, '', HOUR_IN_SECONDS );

		$this->system_prompt = $prompt;

		WP_AI_Workflows_Utilities::debug_log(
			'System prompt loaded from file',
			'debug',
			array(
				'length' => strlen( $prompt ),
			)
		);
	}

	private function initialize_prompt_file() {
		if ( ! file_exists( $this->template_path ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'System prompt template not found',
				'error',
				array(
					'template_path' => $this->template_path,
				)
			);
			throw new Exception( 'System prompt template not found' );
		}

		$prompts_dir = dirname( $this->prompt_path );
		if ( ! file_exists( $prompts_dir ) ) {
			wp_mkdir_p( $prompts_dir );
		}

		if ( ! copy( $this->template_path, $this->prompt_path ) ) {
			throw new Exception( 'Failed to initialize system prompt file' );
		}

		WP_AI_Workflows_Utilities::debug_log(
			'System prompt file initialized',
			'debug',
			array(
				'path' => $this->prompt_path,
			)
		);
	}

	public function generate_workflow( $prompt, $answers = array(), $model = '' ) {
		try {
			$system_prompt = $this->ensure_system_prompt();

			if ( empty( $system_prompt ) ) {
				throw new Exception( 'System prompt not loaded' );
			}

			WP_AI_Workflows_Utilities::debug_log(
				'Starting workflow generation',
				'info',
				array(
					'prompt' => $prompt,
				)
			);

			// The system prompt is fully static (the user's request is sent as the
			// user message below) so the instructions+catalog prefix stays
			// byte-identical across requests and hits the Anthropic prompt cache.

			$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
			if ( empty( $api_key ) ) {
				throw new Exception( 'OpenRouter API key is not configured' );
			}

			// Generation loop: generate -> clean -> validate against the node catalog +
			// semantic contracts -> on failure, feed the precise errors back to the
			// model for a bounded number of repair rounds rather than saving a broken
			// workflow. The static system prefix carries an Anthropic `cache_control`
			// breakpoint, so only the small user request + repair messages are uncached.
			$max_attempts = 3;

			// Fold the clarify-step answers + chosen AI model into the request as
			// explicit hard constraints, appended to the user message so the static
			// system prefix stays byte-identical for prompt caching.
			$chosen_model = is_string( $model ) ? trim( $model ) : '';
			$decisions    = $this->build_decisions_block( $answers, $chosen_model );
			$user_content = $prompt;
			if ( '' !== $decisions ) {
				$user_content .= "\n\n" . $decisions;
			}

			$messages     = array(
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
				array(
					'role'    => 'user',
					'content' => $user_content,
				),
			);

			$last_issues = array();

			for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
				$content = $this->call_openrouter( $api_key, $messages );

				$parsed = $this->parse_workflow_json( $content );

				if ( null === $parsed ) {
					$issues = array(
						array(
							'code'     => 'invalid_json',
							'severity' => 'error',
							'message'  => 'Your output was not a single valid JSON object. Return ONLY one JSON object with "nodes" and "edges": no prose, no code fences.',
						),
					);
				} else {
					// Preserve the raw parse so clean_edges()'s node-type lookups work.
					$this->workflow = $parsed;

					$workflow         = $this->fix_missing_connections( $parsed );
					$cleaned_workflow = array(
						'nodes' => $this->clean_nodes( $workflow['nodes'] ?? array() ),
						'edges' => $this->clean_edges( $workflow['edges'] ?? array() ),
					);

					// Resolve any "Connect an App" nodes against the LIVE app registry
					// so their appSlug/toolName come from real search results, never a
					// hallucinated guess. Runs before validation so resolved nodes carry
					// the required appSlug/toolName; unresolvable ones are dropped.
					$cleaned_workflow = $this->resolve_app_nodes( $cleaned_workflow );

					// Enforce the user's chosen AI model on the general LLM nodes so the
					// pick from the clarify step is honored even if the model omitted or
					// changed it. Users can still swap any node's model on the canvas.
					if ( '' !== $chosen_model ) {
						$cleaned_workflow['nodes'] = $this->apply_model_choice( $cleaned_workflow['nodes'], $chosen_model );
					}

					$issues = WP_AI_Workflows_Workflow_Validator::validate( $cleaned_workflow );

					if ( ! WP_AI_Workflows_Workflow_Validator::has_errors( $issues )
						&& $this->validate_workflow_structure( $cleaned_workflow ) ) {
						WP_AI_Workflows_Utilities::debug_log(
							'Workflow generated and validated',
							'info',
							array(
								'attempt'  => $attempt,
								'warnings' => $issues,
							)
						);
						return $cleaned_workflow;
					}
				}

				$last_issues = $issues;

				WP_AI_Workflows_Utilities::debug_log(
					'Generated workflow failed validation',
					'warning',
					array(
						'attempt' => $attempt,
						'issues'  => $issues,
					)
				);

				// Feed the errors back for a repair round (unless out of rounds).
				if ( $attempt < $max_attempts ) {
					$messages[] = array(
						'role'    => 'assistant',
						'content' => $content,
					);
					$messages[] = array(
						'role'    => 'user',
						'content' => $this->build_repair_message( $issues ),
					);
				}
			}

			// Exhausted the repair budget: fail GRACEFULLY with a clear, specific
			// message rather than saving a broken workflow.
			throw new Exception(
				'The workflow could not be generated correctly after ' . $max_attempts
				. " attempts. Please refine your request and try again. Remaining issues:\n"
				. WP_AI_Workflows_Workflow_Validator::to_repair_text( $last_issues )
			);

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Workflow generation error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw $e;
		}
	}


	/**
	 * One OpenRouter chat completion. Returns the assistant message content, or
	 * throws on a transport / structure error. The messages array (system prefix
	 * with its cache_control breakpoint + the conversation so far) is built by the
	 * caller so repair rounds can append to it while keeping the cached prefix.
	 *
	 * @param string $api_key  OpenRouter API key.
	 * @param array  $messages Chat messages.
	 * @param array  $args     Optional overrides: 'temperature', 'max_tokens', 'stop'
	 *                         and 'timeout'. Omitting $args reproduces the build-path
	 *                         request byte-for-byte (temperature 0.2, 20000 tokens,
	 *                         the </response>/</output> stops) so prompt caching and
	 *                         the repair loop are unaffected. The cheap analyze pass
	 *                         passes a small max_tokens and an empty 'stop'.
	 * @return string Assistant message content.
	 * @throws Exception On request failure or unexpected response shape.
	 */
	private function call_openrouter( $api_key, $messages, $args = array() ) {
		$body = array(
			'model'       => WP_AI_Workflows_Model_Catalog::get_reasoning_model(),
			'messages'    => $messages,
			'temperature' => isset( $args['temperature'] ) ? $args['temperature'] : 0.2,
			'max_tokens'  => isset( $args['max_tokens'] ) ? $args['max_tokens'] : 20000,
		);

		// Default (build path) keeps the JSON-guard stop sequences. A caller that
		// wants none passes an explicit empty 'stop' (the analyze pass does this).
		if ( ! array_key_exists( 'stop', $args ) ) {
			$body['stop'] = array( '</response>', '</output>' );
		} elseif ( ! empty( $args['stop'] ) ) {
			$body['stop'] = $args['stop'];
		}

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'headers' => array_merge(
					array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					WP_AI_Workflows_Utilities::openrouter_headers()
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => isset( $args['timeout'] ) ? $args['timeout'] : 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON via WP_Error, not echoed as HTML
			throw new Exception( 'API request failed: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			throw new Exception( 'Invalid API response structure' );
		}

		return $data['choices'][0]['message']['content'];
	}

	/**
	 * Extract and decode the workflow JSON from a model reply. Tolerates a fenced
	 * code block. Returns the decoded array, or null when the content is not a
	 * single valid JSON object (so the caller can request a repair round).
	 *
	 * @param string $content Model reply.
	 * @return array|null
	 */
	private function parse_workflow_json( $content ) {
		if ( ! is_string( $content ) ) {
			return null;
		}
		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $content, $matches ) ) {
			$content = trim( $matches[1] );
		} else {
			$content = trim( $content );
		}
		$parsed = json_decode( $content, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $parsed ) ) {
			return null;
		}
		return $parsed;
	}

	/**
	 * Clarify-first PHASE 1: a lightweight, cheap first pass that inspects the
	 * user's request and returns 0 to 4 genuinely ambiguous, outcome-changing
	 * questions (each with concrete option chips) for the UI to ask BEFORE the
	 * expensive contract-aware build. Returns a sanitized list - never the raw
	 * model text - so the REST layer can hand it straight to the frontend.
	 *
	 * Deliberately does NOT send the full node catalog: it only needs enough
	 * capability context to ask smart questions, so the prompt stays small and
	 * fast. A fully-specified request yields an empty array (UI skips to build).
	 *
	 * @param string $prompt The user's plain-language workflow description.
	 * @return array[] List of { id, question, options:[{label,value}], allowMultiple }.
	 * @throws Exception When the API key is missing or the request transport fails.
	 */
	public function analyze_request( $prompt ) {
		$api_key = WP_AI_Workflows_Utilities::get_openrouter_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'OpenRouter API key is not configured' );
		}

		$system = <<<'PROMPT'
You are the planning front-end of an AI workflow generator for WordPress. Given a user's plain-language description of an automation, decide what genuinely AMBIGUOUS, outcome-changing decisions still need to be made before the workflow can be built.

The builder can use these capabilities:
- Triggers: manual run, a schedule, a form submission (Gravity Forms, WPForms, Contact Form 7, Ninja Forms), a WordPress event (e.g. new post or user), an incoming webhook, or an RSS feed.
- AI steps: prompt an LLM, research the web, write an article, summarize text, extract structured fields, analyze sentiment, optimize SEO, generate images or video.
- Outputs: create or update a WordPress post (draft or published), send an email, save a file, call an external API/webhook, or display the result on screen.
- Human-in-the-loop: a human approval or modification step before anything is published or sent.

Return ONLY a strict JSON array (no prose, no code fences, no trailing commentary) of 0 to 4 questions. Ask a question ONLY when its answer would meaningfully change the built workflow AND the user has not already specified it. Prefer the fewest questions possible. If the request is already fully specified, return [].

Each array element MUST have exactly this shape:
{"id":"snake_case_id","question":"A short, plain question?","options":[{"label":"Human label","value":"short_value"}],"allowMultiple":false}

Rules:
- 2 to 4 options per question. Each option must be a concrete, mutually distinct choice.
- Set "allowMultiple" to true only when several options can sensibly be combined.
- NEVER ask which AI model to use. That is chosen separately.
- NEVER ask about visual styling, node positions, colors, or anything cosmetic.
- Good questions cover: what triggers the workflow, where the result goes, draft vs. publish, whether a human must approve first, tone or format, or which fields to extract.

Output the JSON array and nothing else.
PROMPT;

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$content = $this->call_openrouter(
			$api_key,
			$messages,
			array(
				'temperature' => 0.3,
				'max_tokens'  => 1200,
				'stop'        => array(),
			)
		);

		$questions = $this->parse_questions_json( $content );

		WP_AI_Workflows_Utilities::debug_log(
			'Workflow analyze produced clarifying questions',
			'info',
			array( 'count' => count( $questions ) )
		);

		return $questions;
	}

	/**
	 * Parse + STRICTLY sanitize the analyze model's reply into a safe question list.
	 * Tolerates a fenced code block. Silently drops malformed questions/options and
	 * caps the result at 4 questions with 2 to 4 options each. Returns [] on any parse
	 * failure so the caller can fall through to a direct build.
	 *
	 * @param string $content Raw model reply.
	 * @return array[]
	 */
	private function parse_questions_json( $content ) {
		if ( ! is_string( $content ) ) {
			return array();
		}

		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $content, $matches ) ) {
			$content = trim( $matches[1] );
		} else {
			$content = trim( $content );
		}

		$decoded = json_decode( $content, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		$questions = array();
		$index     = 0;

		foreach ( $decoded as $raw ) {
			if ( count( $questions ) >= 4 ) {
				break;
			}
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$question_text = isset( $raw['question'] ) ? sanitize_text_field( $raw['question'] ) : '';
			if ( '' === $question_text ) {
				continue;
			}

			$options     = array();
			$raw_options = isset( $raw['options'] ) && is_array( $raw['options'] ) ? $raw['options'] : array();
			foreach ( $raw_options as $raw_option ) {
				if ( count( $options ) >= 4 ) {
					break;
				}
				if ( is_array( $raw_option ) ) {
					$label = isset( $raw_option['label'] ) ? sanitize_text_field( $raw_option['label'] ) : '';
					$value = isset( $raw_option['value'] ) ? sanitize_text_field( $raw_option['value'] ) : $label;
				} else {
					$label = sanitize_text_field( (string) $raw_option );
					$value = $label;
				}
				if ( '' === $label ) {
					continue;
				}
				if ( '' === $value ) {
					$value = $label;
				}
				$options[] = array(
					'label' => $label,
					'value' => $value,
				);
			}

			// A meaningful choice needs at least two distinct options.
			if ( count( $options ) < 2 ) {
				continue;
			}

			$id = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
			if ( '' === $id ) {
				$id = 'q_' . $index;
			}

			$questions[] = array(
				'id'            => $id,
				'question'      => $question_text,
				'options'       => $options,
				'allowMultiple' => ! empty( $raw['allowMultiple'] ),
			);

			$index++;
		}

		return $questions;
	}

	/**
	 * Turn the clarify-step answers + chosen model into an explicit HARD-CONSTRAINTS
	 * block appended to the build request. The model is instructed to treat every
	 * line as a requirement (approval=yes forces a human-approval node, a trigger
	 * choice forces that trigger, etc). Returns '' when there is nothing to add.
	 *
	 * Accepts answers as a list of { question, values:[...] } (the shape the REST
	 * layer forwards from the UI). Everything is sanitized before it reaches here,
	 * but we sanitize again defensively.
	 *
	 * @param mixed  $answers      Clarify answers from the UI.
	 * @param string $chosen_model Selected AI model id (may be empty).
	 * @return string
	 */
	private function build_decisions_block( $answers, $chosen_model ) {
		$lines = array();

		if ( is_array( $answers ) ) {
			foreach ( $answers as $answer ) {
				if ( ! is_array( $answer ) ) {
					continue;
				}

				$question = isset( $answer['question'] ) ? sanitize_text_field( $answer['question'] ) : '';

				$values = array();
				if ( isset( $answer['values'] ) && is_array( $answer['values'] ) ) {
					foreach ( $answer['values'] as $value ) {
						$value = sanitize_text_field( (string) $value );
						if ( '' !== $value ) {
							$values[] = $value;
						}
					}
				} elseif ( isset( $answer['value'] ) ) {
					$value = sanitize_text_field( (string) $answer['value'] );
					if ( '' !== $value ) {
						$values[] = $value;
					}
				}

				if ( '' === $question || empty( $values ) ) {
					continue;
				}

				$lines[] = '- ' . $question . ' ' . implode( ', ', $values );
			}
		}

		$chosen_model = is_string( $chosen_model ) ? trim( $chosen_model ) : '';
		if ( '' !== $chosen_model ) {
			$lines[] = '- Use this AI model for every AI / LLM node in the workflow (aiModel and chat nodes): ' . sanitize_text_field( $chosen_model );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "<user_decisions>\n"
			. "The user answered clarifying questions about this workflow. Treat EVERY line below as a HARD REQUIREMENT the generated workflow MUST satisfy:\n"
			. implode( "\n", $lines )
			. "\n</user_decisions>";
	}

	/**
	 * Force the user's chosen AI model onto the general LLM nodes (aiModel, chat).
	 * Runs after cleaning so the clarify-step pick is authoritative even when the
	 * model forgot to set it. Leaves specialized nodes (e.g. Research/Perplexity,
	 * media generators) alone - their model spaces are different.
	 *
	 * @param array[] $nodes        Cleaned nodes.
	 * @param string  $chosen_model Model id to apply.
	 * @return array[]
	 */
	private function apply_model_choice( $nodes, $chosen_model ) {
		$chosen_model = $this->validate_and_get_model( $chosen_model );
		$targets      = array( 'aiModel', 'chat' );

		foreach ( $nodes as &$node ) {
			if ( isset( $node['type'] ) && in_array( $node['type'], $targets, true ) ) {
				if ( ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) {
					$node['data'] = array();
				}
				$node['data']['model'] = $chosen_model;
			}
		}
		unset( $node );

		return $nodes;
	}

	/**
	 * Build the repair instruction fed back to the model after a failed validation.
	 * It restates the semantic contracts the model must honour (so it does not just
	 * shuffle the same mistake) and appends the precise, machine-readable errors.
	 *
	 * @param array[] $issues Validation issues.
	 * @return string
	 */
	private function build_repair_message( $issues ) {
		$errors = WP_AI_Workflows_Workflow_Validator::to_repair_text( $issues );

		return "The workflow you returned FAILED validation. Fix ONLY the problems listed and return the COMPLETE corrected workflow as a single JSON object (\"nodes\" and \"edges\"): no prose, no code fences.\n\n"
			. "Honour these contracts:\n"
			. "- Use ONLY node types that appear in the catalog.\n"
			. "- A plain AI Prompt / Research / Write Article / Summary node produces ONE text output, referenced ONLY as [Input from node-id]. It has NO named fields. Do NOT write [[field] from that-node] unless you ALSO instruct that node (in its content) to return strict JSON containing that exact key.\n"
			. "- To fill several DISTINCT fields of a consumer (e.g. a Post's title AND content) from one AI result: either (a) make the AI node emit strict JSON and map [[key] from node-id] per field; or (b) insert an AI Extract Information node (one extractionField per target field) and map [[field] from extractInformation-id]; or (c) use a separate AI node per field.\n"
			. "- Every [Input from X] and [[field] from X] must reference a node that EXISTS in the workflow and is connected upstream.\n"
			. "- Chat action outputs are [[field] from chat-id:action-id]; their edges set sourceHandle to the action id.\n\n"
			. "Validation errors to fix:\n" . $errors;
	}

	private function validate_workflow( $workflow ) {
		if ( ! isset( $workflow['nodes'] ) || ! isset( $workflow['edges'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Missing nodes or edges', 'error' );
			return false;
		}

		try {
			$cleaned_workflow = array(
				'nodes' => $this->clean_nodes( $workflow['nodes'] ),
				'edges' => $this->clean_edges( $workflow['edges'] ),
			);

			// Lift any generated sticky note / text annotation that landed on top of
			// a node so it sits cleanly ABOVE the node instead of covering it.
			$cleaned_workflow['nodes'] = $this->reposition_annotations_above_nodes( $cleaned_workflow['nodes'] );

			if ( ! $this->validate_workflow_structure( $cleaned_workflow ) ) {
				return false;
			}

			return $cleaned_workflow;

		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Workflow validation error',
				'error',
				array(
					'error' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	private function normalize_node_type( $type ) {

		$annotation_types = array(
			'stickyNote',
			'textAnnotation',
			'shape',
		);

		if ( in_array( $type, $annotation_types ) ) {
			return $type;
		}

		$output_types = array(
			'display',
			'database',
			'shortcode',
			'webhook',
			'google_sheets',
			'google_drive',
			'html',  // Adding this in case it comes through
		);

		return in_array( strtolower( $type ), $output_types ) ? 'output' : $type;
	}

	private function clean_nodes( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Nodes is not an array', 'error' );
			return array();
		}

		$cleaned_nodes = array();

		foreach ( $nodes as $node ) {
			if ( ! isset( $node['id'] ) || ! isset( $node['type'] ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Node missing required properties',
					'error',
					array(
						'node' => $node,
					)
				);
				continue;
			}

			$normalized_type = $this->normalize_node_type( $node['type'] );

			$clean_node = array(
				'id'       => $node['id'],
				'type'     => $normalized_type,
				'position' => isset( $node['position'] ) && is_array( $node['position'] ) ?
					$node['position'] : array(
						'x' => 0,
						'y' => 0,
					),
			);

			if ( in_array( $normalized_type, array( 'textAnnotation', 'shape' ) ) ) {
				$clean_node['className'] = 'react-flow-annotation';
				$clean_node['zIndex']    = -1;
			}

			$clean_node['data'] = $this->clean_node_data( $normalized_type, $node['data'] ?? array() );

			WP_AI_Workflows_Utilities::debug_log(
				'Cleaned node',
				'debug',
				array(
					'original' => $node,
					'cleaned'  => $clean_node,
				)
			);

			$cleaned_nodes[] = $clean_node;
		}

		return $cleaned_nodes;
	}

	/**
	 * Reposition generated annotations so they sit above the node they describe.
	 *
	 * The model frequently drops a sticky note / text annotation on top of a node;
	 * this finds the node each annotation overlaps most and lifts it to
	 *     y = node.y - annotationHeight - GAP
	 * aligned to the node's x. Annotations already clear of every node, and shapes,
	 * are left untouched.
	 *
	 * @param array $nodes Cleaned nodes.
	 * @return array Nodes with annotation positions corrected.
	 */
	private function reposition_annotations_above_nodes( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return $nodes;
		}

		$gap              = 60;   // clear vertical gap between note bottom and node top.
		$node_w           = 400;  // approx node width (see <positioning_rules>).
		$node_h           = 200;  // approx node body height used only for overlap testing.
		$annotation_types = array( 'stickyNote', 'textAnnotation' );

		// Real (non-annotation) nodes with usable numeric positions.
		$targets = array();
		foreach ( $nodes as $n ) {
			if ( in_array( $n['type'], array( 'stickyNote', 'textAnnotation', 'shape' ), true ) ) {
				continue;
			}
			if ( ! isset( $n['position']['x'], $n['position']['y'] ) || ! is_numeric( $n['position']['x'] ) || ! is_numeric( $n['position']['y'] ) ) {
				continue;
			}
			$targets[] = $n;
		}
		if ( empty( $targets ) ) {
			return $nodes;
		}

		// Count notes already stacked above each target so several notes for the
		// same node fan upward instead of piling on the same spot.
		$stack = array();

		foreach ( $nodes as &$n ) {
			if ( ! in_array( $n['type'], $annotation_types, true ) ) {
				continue;
			}
			if ( ! isset( $n['position']['x'], $n['position']['y'] ) || ! is_numeric( $n['position']['x'] ) || ! is_numeric( $n['position']['y'] ) ) {
				continue;
			}

			$ax = (float) $n['position']['x'];
			$ay = (float) $n['position']['y'];
			$aw = isset( $n['data']['size']['width'] ) && is_numeric( $n['data']['size']['width'] ) ? (float) $n['data']['size']['width'] : 200;
			$ah = isset( $n['data']['size']['height'] ) && is_numeric( $n['data']['size']['height'] ) ? (float) $n['data']['size']['height'] : 150;

			// Pick the node this annotation overlaps most; tie-break on center distance.
			$best         = null;
			$best_overlap = 0.0;
			$best_dist    = null;
			foreach ( $targets as $t ) {
				$tx      = (float) $t['position']['x'];
				$ty      = (float) $t['position']['y'];
				$ox      = max( 0, min( $ax + $aw, $tx + $node_w ) - max( $ax, $tx ) );
				$oy      = max( 0, min( $ay + $ah, $ty + $node_h ) - max( $ay, $ty ) );
				$overlap = $ox * $oy;
				$dx      = ( $ax + $aw / 2 ) - ( $tx + $node_w / 2 );
				$dy      = ( $ay + $ah / 2 ) - ( $ty + $node_h / 2 );
				$dist    = $dx * $dx + $dy * $dy;
				if ( $overlap > $best_overlap || ( $overlap === $best_overlap && ( null === $best_dist || $dist < $best_dist ) ) ) {
					$best         = $t;
					$best_overlap = $overlap;
					$best_dist    = $dist;
				}
			}

			// Leave notes that don't actually cover a node where they are.
			if ( null === $best || $best_overlap <= 0 ) {
				continue;
			}

			$tid   = $best['id'];
			$level = isset( $stack[ $tid ] ) ? $stack[ $tid ] : 0;

			$stack[ $tid ] = $level + 1;

			$tx = (float) $best['position']['x'];
			$ty = (float) $best['position']['y'];

			$n['position']['x'] = $tx + ( $level * 30 );
			$n['position']['y'] = $ty - $ah - $gap - ( $level * ( $ah + 20 ) );
		}
		unset( $n );

		return $nodes;
	}


	private function clean_edges( $edges ) {
		$cleaned_edges = array();

		foreach ( $edges as $edge ) {
			if ( ! isset( $edge['id'] ) || ! isset( $edge['source'] ) || ! isset( $edge['target'] ) ) {
				continue;
			}

			$cleaned_edge = array(
				'id'     => $edge['id'],
				'source' => $edge['source'],
				'target' => $edge['target'],
			);

			if ( isset( $edge['sourceHandle'] ) ) {
				$cleaned_edge['sourceHandle'] = $edge['sourceHandle'];
			}

			if ( isset( $edge['targetHandle'] ) ) {
				$cleaned_edge['targetHandle'] = $edge['targetHandle'];
			}

			if ( strpos( $edge['source'], 'condition-' ) === 0 && ! isset( $edge['sourceHandle'] ) ) {
				$cleaned_edge['sourceHandle'] = 'true';
			}

			if ( strpos( $edge['source'], 'humanInput-' ) === 0 && ! isset( $edge['sourceHandle'] ) ) {
				$node_type = $this->get_node_type( $edge['source'] );
				if ( $node_type === 'approval' ) {
					$cleaned_edge['sourceHandle'] = 'approve';
				} elseif ( $node_type === 'modification' ) {
					$cleaned_edge['sourceHandle'] = 'modify';
				}
			}

			if ( strpos( $edge['source'], 'chat-' ) === 0 && ! isset( $edge['sourceHandle'] ) ) {

				WP_AI_Workflows_Utilities::debug_log(
					'Chat node connection missing sourceHandle',
					'warning',
					array(
						'edge' => $edge,
					)
				);
			}

			$cleaned_edges[] = $cleaned_edge;
		}

		return $cleaned_edges;
	}

	private function get_node_type( $node_id ) {
		foreach ( $this->workflow['nodes'] as $node ) {
			if ( $node['id'] === $node_id && isset( $node['data']['inputType'] ) ) {
				return $node['data']['inputType'];
			}
		}
		return null;
	}

	private function clean_number( $value, $min, $max, $default ) {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return min( max( (float) $value, $min ), $max );
	}

	private function clean_node_data( $type, $data ) {
		$cleaned_data = array();

		if ( in_array( $type, array( 'stickyNote', 'textAnnotation', 'shape' ) ) ) {
			return array(
				'content'   => $data['content'] ?? '',
				'color'     => $data['color'] ?? '',
				'size'      => $data['size'] ?? array(),
				'fontSize'  => $data['fontSize'] ?? 14,
				'shapeType' => $data['shapeType'] ?? 'rectangle',
				'onChange'  => null,
				'onDelete'  => null,
			);
		}

		$cleaned_data['nodeName'] = $data['nodeName'] ?? "$type-" . substr( uniqid(), -4 );

		if ( $type === 'shortcode' ) {
			$type               = 'output';
			$data['outputType'] = 'html';
		}

		switch ( $type ) {
			case 'trigger':
				$cleaned_data['triggerType'] = $data['triggerType'] ?? 'manual';
				$cleaned_data['content']     = $data['content'] ?? '';

				switch ( $cleaned_data['triggerType'] ) {
					case 'gravityForms':
						$cleaned_data['selectedForm']   = $data['selectedForm'] ?? null;
						$cleaned_data['selectedFields'] = $data['selectedFields'] ?? array();
						break;
					case 'webhook':
						$cleaned_data['webhookUrl']  = '';
						$cleaned_data['webhookKeys'] = $data['webhookKeys'] ?? array();
						break;
					case 'wpCore':
						$cleaned_data['selectedWpCoreTrigger']   = $data['selectedWpCoreTrigger'] ?? '';
						$cleaned_data['wpCoreTriggerConditions'] = $data['wpCoreTriggerConditions'] ?? array();
						break;
					case 'workflowOutput':
						$cleaned_data['selectedWorkflow'] = $data['selectedWorkflow'] ?? null;
						break;
					case 'rss':
						$cleaned_data['rssSettings'] = array(
							'feedUrl'         => $data['rssSettings']['feedUrl'] ?? '',
							'pollingInterval' => in_array(
								$data['rssSettings']['pollingInterval'] ?? '15min',
								array( '5min', '15min', '30min', '1hour', '6hours', '24hours' )
							) ? $data['rssSettings']['pollingInterval'] : '15min',
							'maxItems'        => min(
								max( (int) ( $data['rssSettings']['maxItems'] ?? 10 ), 1 ),
								50
							),
							'includeContent'  => (bool) ( $data['rssSettings']['includeContent'] ?? false ),
							'filters'         => array(
								'title'      => $data['rssSettings']['filters']['title'] ?? '',
								'content'    => $data['rssSettings']['filters']['content'] ?? '',
								'categories' => is_array( $data['rssSettings']['filters']['categories'] ?? null )
									? $data['rssSettings']['filters']['categories']
									: array(),
							),
						);
						break;
				}
				break;

			case 'aiModel':
				$cleaned_data['model']     = $this->validate_and_get_model( $data['model'] ?? WP_AI_Workflows_Model_Catalog::get_default_model() );
				$cleaned_data['content']   = $data['content'] ?? '';
				$cleaned_data['imageUrls'] = array();
				$cleaned_data['settings']  = array(
					'temperature'       => $this->clean_number( $data['settings']['temperature'] ?? 1, 0, 2, 1 ),
					'max_tokens'        => $this->clean_number( $data['settings']['max_tokens'] ?? 4096, 1, 32768, 4096 ),
					'top_p'             => $this->clean_number( $data['settings']['top_p'] ?? 1, 0, 1, 1 ),
					'frequency_penalty' => $this->clean_number( $data['settings']['frequency_penalty'] ?? 0, -2, 2, 0 ),
					'presence_penalty'  => $this->clean_number( $data['settings']['presence_penalty'] ?? 0, -2, 2, 0 ),
				);
				break;

			case 'sentimentAnalysis':
			case 'summaryGenerator':
				$cleaned_data['content'] = $data['content'] ?? '';
				break;

			case 'extractInformation':
				$cleaned_data['content']          = $data['content'] ?? '';
				$cleaned_data['extractionFields'] = array_map(
					function ( $field ) {
						return array(
							'name'        => $field['name'] ?? '',
							'description' => $field['description'] ?? '',
							'isList'      => $field['isList'] ?? false,
						);
					},
					$data['extractionFields'] ?? array()
				);
				break;

			case 'writeArticle':
				$cleaned_data['content']   = $data['content'] ?? '';
				$cleaned_data['wordCount'] = $this->clean_number( $data['wordCount'] ?? 500, 100, 10000, 500 );
				break;

			case 'optimizeSEO':
				$cleaned_data['content']  = $data['content'] ?? '';
				$cleaned_data['keywords'] = $data['keywords'] ?? '';
				break;

			case 'research':
				$cleaned_data['content']         = $data['content'] ?? '';
				$cleaned_data['model']           = $this->validate_and_get_model( $data['model'] ?? 'sonar' );
				$cleaned_data['maxTokens']       = $this->clean_number( $data['maxTokens'] ?? 4096, 1, 32768, 4096 );
				$cleaned_data['temperature']     = $this->clean_number( $data['temperature'] ?? 0.7, 0, 2, 0.7 );
				$cleaned_data['returnCitations'] = $data['returnCitations'] ?? true;
				break;

			case 'parser':
				$cleaned_data['inputType']    = $data['inputType'] ?? 'link';
				$cleaned_data['documentLink'] = $data['documentLink'] ?? '';

				if ( is_string( $data['uploadedFiles'] ?? null ) && strpos( $data['uploadedFiles'], '[Input from' ) !== false ) {
					$cleaned_data['uploadedFiles'] = $data['uploadedFiles'];
				} else {

					$cleaned_data['uploadedFiles'] = '';
				}

				$cleaned_data['parserSettings'] = array(
					'language'            => $data['parserSettings']['language'] ?? 'en',
					'parsingInstructions' => $data['parserSettings']['parsingInstructions'] ?? '',
					'skipDiagonalText'    => $data['parserSettings']['skipDiagonalText'] ?? false,
					'doNotUnrollColumns'  => $data['parserSettings']['doNotUnrollColumns'] ?? false,
					'targetPages'         => $data['parserSettings']['targetPages'] ?? '',
				);
				break;

			case 'firecrawl':
				$cleaned_data['operation'] = in_array( $data['operation'] ?? 'scrape', array( 'scrape', 'crawl', 'map', 'search', 'agent' ), true )
					? $data['operation']
					: 'scrape';

				// New v2 formats (array). Fall back to the legacy single `format` when the
				// AI emitted the old shape.
				if ( isset( $data['formats'] ) && is_array( $data['formats'] ) ) {
					$cleaned_data['formats'] = array_values(
						array_intersect(
							$data['formats'],
							array( 'markdown', 'html', 'rawHtml', 'links', 'summary', 'highlights', 'json', 'question', 'screenshot' )
						)
					);
				} elseif ( isset( $data['format'] ) ) {
					$cleaned_data['format'] = $data['format'];
				} else {
					$cleaned_data['formats'] = array( 'markdown' );
				}

				$cleaned_data['onlyMainContent'] = $data['onlyMainContent'] ?? true;
				$cleaned_data['waitFor']         = $this->clean_number( $data['waitFor'] ?? 0, 0, 60000, 0 );
				$cleaned_data['timeout']         = $this->clean_number( $data['timeout'] ?? 30000, 1000, 120000, 30000 );

				switch ( $cleaned_data['operation'] ) {
					case 'crawl':
						$cleaned_data['url']               = $data['url'] ?? '';
						$cleaned_data['limit']             = $this->clean_number( $data['limit'] ?? 10, 1, 500, 10 );
						$cleaned_data['includePaths']      = $data['includePaths'] ?? array();
						$cleaned_data['excludePaths']      = $data['excludePaths'] ?? array();
						$cleaned_data['crawlEntireDomain'] = $data['crawlEntireDomain'] ?? false;
						$cleaned_data['sitemap']           = in_array( $data['sitemap'] ?? 'include', array( 'include', 'skip', 'only' ), true ) ? $data['sitemap'] : 'include';
						$cleaned_data['maxDiscoveryDepth'] = $this->clean_number( $data['maxDiscoveryDepth'] ?? 2, 1, 10, 2 );
						break;
					case 'map':
						$cleaned_data['url']               = $data['url'] ?? '';
						$cleaned_data['mapSearch']         = $data['mapSearch'] ?? '';
						$cleaned_data['mapLimit']          = $this->clean_number( $data['mapLimit'] ?? 100, 1, 5000, 100 );
						$cleaned_data['includeSubdomains'] = $data['includeSubdomains'] ?? false;
						break;
					case 'search':
						$cleaned_data['query']       = $data['query'] ?? '';
						$cleaned_data['sources']     = is_array( $data['sources'] ?? null ) ? array_values( array_intersect( $data['sources'], array( 'web', 'news', 'images' ) ) ) : array( 'web' );
						$cleaned_data['categories']  = $data['categories'] ?? array();
						$cleaned_data['searchLimit'] = $this->clean_number( $data['searchLimit'] ?? 5, 1, 50, 5 );
						break;
					case 'agent':
						$cleaned_data['agentPrompt'] = $data['agentPrompt'] ?? '';
						$cleaned_data['agentFields'] = $data['agentFields'] ?? array();
						$cleaned_data['agentUrls']   = $data['agentUrls'] ?? array();
						break;
					case 'scrape':
					default:
						$cleaned_data['url']         = $data['url'] ?? '';
						$cleaned_data['jsonPrompt']  = $data['jsonPrompt'] ?? '';
						$cleaned_data['jsonFields']  = $data['jsonFields'] ?? array();
						$cleaned_data['question']    = $data['question'] ?? '';
						$cleaned_data['includeTags'] = $data['includeTags'] ?? array();
						$cleaned_data['excludeTags'] = $data['excludeTags'] ?? array();
						$cleaned_data['isMobile']    = $data['isMobile'] ?? false;
						break;
				}

				// Preserve legacy extract fields so previously generated / imported
				// workflows keep resolving after the v2 redesign.
				if ( ( $data['format'] ?? '' ) === 'extract' ) {
					$cleaned_data['extractType']   = $data['extractType'] ?? 'prompt';
					$cleaned_data['extractPrompt'] = $data['extractPrompt'] ?? '';
					$cleaned_data['extractFields'] = $data['extractFields'] ?? array();
				}
				break;

			case 'unsplash':
				$cleaned_data['searchTerm']   = $data['searchTerm'] ?? '';
				$cleaned_data['imageSize']    = in_array( $data['imageSize'] ?? '', array( 'raw', 'full', 'regular', 'small' ) ) ?
					$data['imageSize'] : 'regular';
				$cleaned_data['orientation']  = in_array( $data['orientation'] ?? '', array( 'all', 'landscape', 'portrait', 'squarish' ) ) ?
					$data['orientation'] : 'all';
				$cleaned_data['randomResult'] = $data['randomResult'] ?? false;
				break;

			case 'multimediaGenerator':
			case 'mediaGenerator':
				$cleaned_data['modelType']      = in_array( $data['modelType'] ?? '', array( 'textToImage', 'imageToVideo', 'textToVideo' ) ) ?
					$data['modelType'] : 'textToImage';
				$cleaned_data['selectedModel']  = $data['selectedModel'] ?? '';
				$cleaned_data['prompt']         = $data['prompt'] ?? '';
				$cleaned_data['negativePrompt'] = $data['negativePrompt'] ?? '';

				if ( $cleaned_data['modelType'] === 'imageToVideo' ) {
					$cleaned_data['imageUrl'] = $data['imageUrl'] ?? '';
				}

				if ( in_array( $cleaned_data['modelType'], array( 'imageToVideo', 'textToVideo' ) ) ) {
					$cleaned_data['videoLength'] = in_array( $data['videoLength'] ?? '', array( 5, 6, 7, 8, 10 ) ) ?
						$data['videoLength'] : 5;
					$cleaned_data['aspectRatio'] = in_array( $data['aspectRatio'] ?? '', array( '16:9', '9:16', '1:1', 'auto' ) ) ?
						$data['aspectRatio'] : '16:9';
				}

				if ( isset( $data['result'] ) ) {
					$cleaned_data['result'] = $data['result'];
				}
				break;

			case 'createFile':
				$cleaned_data['fileName']    = $data['fileName'] ?? '';
				$cleaned_data['fileFormat']  = in_array( $data['fileFormat'] ?? '', array( 'txt', 'docx', 'html' ) ) ?
					$data['fileFormat'] : 'txt';
				$cleaned_data['fileContent'] = $data['fileContent'] ?? '';
				$cleaned_data['saveToMedia'] = $data['saveToMedia'] !== false; // Default to true
				break;

			case 'humanInput':
				$cleaned_data['inputType']      = in_array( $data['inputType'] ?? '', array( 'approval', 'modification' ) ) ?
					$data['inputType'] : 'approval';
				$cleaned_data['assignmentType'] = in_array( $data['assignmentType'] ?? '', array( 'user', 'role' ) ) ?
					$data['assignmentType'] : 'user';
				$cleaned_data['selectedUser']   = $data['selectedUser'] ?? '';
				$cleaned_data['selectedRole']   = $data['selectedRole'] ?? '';
				$cleaned_data['content']        = $data['content'] ?? '';
				$cleaned_data['instructions']   = $data['instructions'] ?? '';
				break;

			case 'condition':
				$cleaned_data['conditionGroups'] = array_map(
					function ( $group ) {
						return array(
							'type'       => in_array( $group['type'] ?? '', array( 'AND', 'OR' ) ) ? $group['type'] : 'AND',
							'conditions' => array_map(
								function ( $condition ) {
									return array(
										'input'      => $condition['input'] ?? '',
										'comparison' => $condition['comparison'] ?? 'equals',
										'value'      => $condition['value'] ?? '',
									);
								},
								$group['conditions'] ?? array()
							),
						);
					},
					$data['conditionGroups'] ?? array()
				);
				break;

			case 'sendEmail':
				$cleaned_data['to']           = $data['to'] ?? '';
				$cleaned_data['cc']           = $data['cc'] ?? '';
				$cleaned_data['bcc']          = $data['bcc'] ?? '';
				$cleaned_data['subject']      = $data['subject'] ?? '';
				$cleaned_data['body']         = $data['body'] ?? '';
				$cleaned_data['useHtml']      = $data['useHtml'] ?? true;
				$cleaned_data['delayEnabled'] = $data['delayEnabled'] ?? false;
				$cleaned_data['delayValue']   = $this->clean_number( $data['delayValue'] ?? 1, 1, 10000, 1 );
				$delay_unit                   = $data['delayUnit'] ?? 'minutes';
				$cleaned_data['delayUnit']    = in_array( $delay_unit, array( 'minutes', 'hours', 'days' ) ) ?
					$delay_unit : 'minutes';
				$cleaned_data['attachments']  = $data['attachments'] ?? array();
				break;

			case 'post':
				$cleaned_data['selectedPostType'] = $data['selectedPostType'] ?? 'post';
				$cleaned_data['fieldMappings']    = $data['fieldMappings'] ?? array();
				$cleaned_data['postStatus']       = in_array( $data['postStatus'] ?? '', array( 'draft', 'publish', 'private', 'pending', 'future' ) ) ?
					$data['postStatus'] : 'draft';
				$cleaned_data['scheduledDate']    = $data['scheduledDate'] ?? null;
				break;

			case 'output':
				$valid_output_types         = array( 'display', 'save', 'html', 'webhook', 'googleSheets', 'googleDrive' );
				$cleaned_data['outputType'] = in_array( $data['outputType'] ?? '', $valid_output_types ) ?
				$data['outputType'] : 'display';

				$cleaned_data['content']       = $data['content'] ?? '';
				$cleaned_data['displayOutput'] = $data['displayOutput'] ?? 'Output will be displayed here after execution';
				$cleaned_data['delayEnabled']  = $data['delayEnabled'] ?? false;
				$cleaned_data['delayValue']    = $this->clean_number( $data['delayValue'] ?? 1, 1, 10000, 1 );
				$delay_unit                    = $data['delayUnit'] ?? 'minutes';
				$cleaned_data['delayUnit']     = in_array( $delay_unit, array( 'minutes', 'hours', 'days' ) ) ?
				$delay_unit : 'minutes';

				switch ( $cleaned_data['outputType'] ) {
					case 'html':
						$cleaned_data['workflowId'] = $data['workflowId'] ?? null;
						break;
					case 'save':
						$cleaned_data['selectedTable'] = $data['selectedTable'] ?? '';
						break;
					case 'webhook':
						$cleaned_data['webhookUrl']  = $data['webhookUrl'] ?? '';
						$cleaned_data['webhookKeys'] = $data['webhookKeys'] ?? array();
						break;
					case 'googleSheets':
						$cleaned_data['selectedSpreadsheet'] = $data['selectedSpreadsheet'] ?? '';
						$cleaned_data['selectedSheetTab']    = $data['selectedSheetTab'] ?? '';
						$cleaned_data['columnMappings']      = $data['columnMappings'] ?? array();
						break;
					case 'googleDrive':
						$cleaned_data['selectedDriveFolder'] = $data['selectedDriveFolder'] ?? '';
						$cleaned_data['driveFileName']       = $data['driveFileName'] ?? '';
						$cleaned_data['driveFileFormat']     = in_array( $data['driveFileFormat'] ?? '', array( 'txt', 'docx', 'pdf', 'csv' ) ) ?
						$data['driveFileFormat'] : 'txt';
						break;
				}
				break;

			case 'apiCall':
				$cleaned_data['method'] = in_array( $data['method'] ?? '', array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS' ) ) ?
				$data['method'] : 'GET';
				$cleaned_data['url']    = $data['url'] ?? '';

				$cleaned_data['headers'] = array_map(
					function ( $header ) {
						return array(
							'name'  => $header['name'] ?? '',
							'value' => $header['value'] ?? '',
						);
					},
					$data['headers'] ?? array()
				);

				$cleaned_data['queryParams'] = array_map(
					function ( $param ) {
						return array(
							'key'   => $param['key'] ?? '',
							'value' => $param['value'] ?? '',
						);
					},
					$data['queryParams'] ?? array()
				);

				$cleaned_data['body'] = $data['body'] ?? null;

				$cleaned_data['auth'] = array(
					'type'       => in_array( $data['auth']['type'] ?? 'none', array( 'none', 'basic', 'bearer', 'apiKey' ) ) ?
					$data['auth']['type'] : 'none',
					'username'   => $data['auth']['username'] ?? '',
					'password'   => $data['auth']['password'] ?? '',
					'token'      => $data['auth']['token'] ?? '',
					'apiKey'     => $data['auth']['apiKey'] ?? '',
					'apiKeyName' => $data['auth']['apiKeyName'] ?? 'X-API-Key',
				);

				$cleaned_data['responseConfig'] = array(
					'timeout'       => $this->clean_number( $data['responseConfig']['timeout'] ?? 30000, 1000, 300000, 30000 ),
					'retryCount'    => $this->clean_number( $data['responseConfig']['retryCount'] ?? 0, 0, 5, 0 ),
					'jsonPath'      => $data['responseConfig']['jsonPath'] ?? '',
					'cacheResponse' => $data['responseConfig']['cacheResponse'] ?? false,
					'cacheTime'     => $this->clean_number( $data['responseConfig']['cacheTime'] ?? 300, 60, 86400, 300 ),
				);
				break;
			case 'MCPClient':
				// "Connect an App" node. The generator emits app INTENT
				// (appService + actionIntent); resolve_app_nodes() then fills
				// appSlug/toolName from the live registry. Preserve both the intent
				// and any already-resolved fields for that resolution step.
				$cleaned_data['appService']   = isset( $data['appService'] ) ? sanitize_text_field( (string) $data['appService'] ) : '';
				$cleaned_data['actionIntent'] = isset( $data['actionIntent'] ) ? sanitize_text_field( (string) $data['actionIntent'] ) : '';
				$cleaned_data['appSlug']      = isset( $data['appSlug'] ) ? sanitize_text_field( (string) $data['appSlug'] ) : '';
				$cleaned_data['toolName']     = isset( $data['toolName'] ) ? sanitize_text_field( (string) $data['toolName'] ) : '';
				$cleaned_data['appName']      = isset( $data['appName'] ) ? sanitize_text_field( (string) $data['appName'] ) : '';
				$cleaned_data['appIcon']      = isset( $data['appIcon'] ) ? esc_url_raw( (string) $data['appIcon'] ) : '';
				$cleaned_data['authStatus']   = isset( $data['authStatus'] ) ? sanitize_text_field( (string) $data['authStatus'] ) : '';
				$cleaned_data['toolConfig']   = self::sanitize_tool_config( $data['toolConfig'] ?? array() );
				break;

			case 'chat':
				$cleaned_data['model']        = $this->validate_and_get_model( $data['model'] ?? 'anthropic/claude-sonnet-5' );
				$cleaned_data['systemPrompt'] = $data['systemPrompt'] ?? '';

				$cleaned_data['actions'] = array();
				if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
					foreach ( $data['actions'] as $action ) {
						$cleaned_action = array(
							'id'          => $action['id'] ?? ( 'action-' . substr( uniqid(), -8 ) ),
							'name'        => $action['name'] ?? 'Action',
							'description' => $action['description'] ?? '',
							'fields'      => array(),
						);

						if ( isset( $action['fields'] ) && is_array( $action['fields'] ) ) {
							foreach ( $action['fields'] as $field ) {
								$cleaned_action['fields'][] = array(
									'name'     => $field['name'] ?? '',
									'type'     => in_array( $field['type'] ?? 'text', array( 'text', 'email', 'number', 'phone' ) ) ?
										$field['type'] : 'text',
									'required' => (bool) ( $field['required'] ?? false ),
								);
							}
						}

						$cleaned_data['actions'][] = $cleaned_action;
					}
				}

				$cleaned_data['modelParams'] = array(
					'temperature'        => $this->clean_number( $data['modelParams']['temperature'] ?? 1.0, 0, 2, 1.0 ),
					'top_p'              => $this->clean_number( $data['modelParams']['top_p'] ?? 1.0, 0, 1, 1.0 ),
					'top_k'              => $this->clean_number( $data['modelParams']['top_k'] ?? 0, 0, 100, 0 ),
					'frequency_penalty'  => $this->clean_number( $data['modelParams']['frequency_penalty'] ?? 0.0, -2, 2, 0.0 ),
					'presence_penalty'   => $this->clean_number( $data['modelParams']['presence_penalty'] ?? 0.0, -2, 2, 0.0 ),
					'repetition_penalty' => $this->clean_number( $data['modelParams']['repetition_penalty'] ?? 1.0, 0, 2, 1.0 ),
					'max_tokens'         => $this->clean_number( $data['modelParams']['max_tokens'] ?? 4096, 1, 32768, 4096 ),
				);

				$cleaned_data['openaiTools'] = array(
					'webSearch'  => array(
						'enabled'     => (bool) ( $data['openaiTools']['webSearch']['enabled'] ?? false ),
						'contextSize' => in_array(
							$data['openaiTools']['webSearch']['contextSize'] ?? 'medium',
							array( 'low', 'medium', 'high' )
						) ? $data['openaiTools']['webSearch']['contextSize'] : 'medium',
						'location'    => array(
							'city'    => $data['openaiTools']['webSearch']['location']['city'] ?? '',
							'region'  => $data['openaiTools']['webSearch']['location']['region'] ?? '',
							'country' => $data['openaiTools']['webSearch']['location']['country'] ?? '',
						),
					),
					'fileSearch' => array(
						'enabled'       => (bool) ( $data['openaiTools']['fileSearch']['enabled'] ?? false ),
						'vectorStoreId' => $data['openaiTools']['fileSearch']['vectorStoreId'] ?? '',
						'maxResults'    => $this->clean_number( $data['openaiTools']['fileSearch']['maxResults'] ?? 5, 1, 20, 5 ),
					),
				);

				$cleaned_data['design'] = array(
					'theme'          => in_array( $data['design']['theme'] ?? 'light', array( 'light', 'dark', 'custom' ) ) ?
						$data['design']['theme'] : 'light',
					'position'       => in_array(
						$data['design']['position'] ?? 'bottom-right',
						array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'inline' )
					) ?
						$data['design']['position'] : 'bottom-right',
					'dimensions'     => array(
						'width'        => $this->clean_number( $data['design']['dimensions']['width'] ?? 380, 300, 800, 380 ),
						'height'       => $this->clean_number( $data['design']['dimensions']['height'] ?? 600, 400, 800, 600 ),
						'borderRadius' => $this->clean_number( $data['design']['dimensions']['borderRadius'] ?? 12, 0, 24, 12 ),
					),
					'colors'         => array(
						'primary'    => $data['design']['colors']['primary'] ?? '#1677ff',
						'secondary'  => $data['design']['colors']['secondary'] ?? '#f5f5f5',
						'text'       => $data['design']['colors']['text'] ?? '#000000',
						'background' => $data['design']['colors']['background'] ?? '#ffffff',
					),
					'font'           => array(
						'family'     => $data['design']['font']['family'] ?? 'Inter, system-ui, sans-serif',
						'size'       => $data['design']['font']['size'] ?? '14px',
						'headerSize' => $data['design']['font']['headerSize'] ?? '16px',
					),
					'botName'        => $data['design']['botName'] ?? 'AI Assistant',
					'botIcon'        => in_array( $data['design']['botIcon'] ?? 'robot', array( 'robot', 'assistant', 'brain', 'chat' ) ) ?
						$data['design']['botIcon'] : 'robot',
					'sendButtonText' => $data['design']['sendButtonText'] ?? 'Send',
					'showPoweredBy'  => $data['design']['showPoweredBy'] !== false,
					'customCSS'      => $data['design']['customCSS'] ?? '',
					'quickResponses' => is_array( $data['design']['quickResponses'] ?? null ) ?
						array_map(
							function ( $qr ) {
								return array(
									'text'    => $qr['text'] ?? 'Quick response',
									'message' => $qr['message'] ?? 'Quick response message',
								);
							},
							$data['design']['quickResponses']
						) : array(),
				);

				$cleaned_data['behavior'] = array(
					'initialMessageType'  => in_array( $data['behavior']['initialMessageType'] ?? 'static', array( 'static', 'dynamic' ) ) ?
						$data['behavior']['initialMessageType'] : 'static',
					'initialMessage'      => $data['behavior']['initialMessage'] ?? 'Hello! How can I help you today?',
					'placeholderText'     => $data['behavior']['placeholderText'] ?? 'Type your message here...',
					'maxHistoryLength'    => $this->clean_number( $data['behavior']['maxHistoryLength'] ?? 50, 10, 100, 50 ),
					'showTypingIndicator' => $data['behavior']['showTypingIndicator'] !== false,
					'soundEffects'        => $data['behavior']['soundEffects'] !== false,
					'showCitations'       => (bool) ( $data['behavior']['showCitations'] ?? false ),
					'autoOpenDelay'       => $this->clean_number( $data['behavior']['autoOpenDelay'] ?? 0, 0, 60, 0 ),
					'persistHistory'      => $data['behavior']['persistHistory'] !== false,
					'includePageContext'  => (bool) ( $data['behavior']['includePageContext'] ?? false ),
					'streamResponses'     => (bool) ( $data['behavior']['streamResponses'] ?? false ),
					'rateLimit'           => array(
						'enabled'     => $data['behavior']['rateLimit']['enabled'] ?? true,
						'maxMessages' => $this->clean_number( $data['behavior']['rateLimit']['maxMessages'] ?? 10, 1, 100, 10 ),
						'timeWindow'  => $this->clean_number( $data['behavior']['rateLimit']['timeWindow'] ?? 60, 10, 3600, 60 ),
					),
				);

				if ( ! empty( $cleaned_data['actions'] ) && $cleaned_data['behavior']['streamResponses'] ) {
					$cleaned_data['behavior']['streamResponses'] = false;
				}
				break;
		}

		return $cleaned_data;
	}

	/**
	 * Resolve every "Connect an App" (MCPClient) node in a cleaned workflow against
	 * the LIVE Pipedream app registry. The generator only emits app INTENT
	 * (appService + actionIntent) so the real appSlug/toolName originate from actual
	 * search results and can never be hallucinated. A node whose service cannot be
	 * resolved is DROPPED (its edges bridged) rather than emitted with a guessed
	 * slug.
	 *
	 * @param array $workflow array( 'nodes' => [...], 'edges' => [...] ).
	 * @return array Workflow with app nodes resolved (and unresolved ones removed).
	 */
	private function resolve_app_nodes( $workflow ) {
		if ( empty( $workflow['nodes'] ) || ! is_array( $workflow['nodes'] ) ) {
			return $workflow;
		}

		$drop = array();
		foreach ( $workflow['nodes'] as &$node ) {
			if ( ! isset( $node['type'] ) || 'MCPClient' !== $node['type'] ) {
				continue;
			}
			$resolved = self::resolve_app_node_data( isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array() );
			if ( null === $resolved ) {
				$drop[ $node['id'] ] = true;
				WP_AI_Workflows_Utilities::debug_log(
					'Connect-an-App node could not be resolved to a live app; dropping it',
					'warning',
					array( 'node_id' => $node['id'] )
				);
				continue;
			}
			$node['data'] = $resolved;
		}
		unset( $node );

		if ( ! empty( $drop ) ) {
			$workflow = $this->drop_nodes_bridging_edges( $workflow, $drop );
		}

		return $workflow;
	}

	/**
	 * Remove nodes by id, reconnecting each removed node's upstream sources straight
	 * to its downstream targets so the flow is not severed.
	 *
	 * @param array $workflow Workflow.
	 * @param array $drop     Map of node id => true to remove.
	 * @return array
	 */
	private function drop_nodes_bridging_edges( $workflow, $drop ) {
		$nodes = array();
		foreach ( $workflow['nodes'] as $n ) {
			if ( empty( $drop[ $n['id'] ] ) ) {
				$nodes[] = $n;
			}
		}

		$edges = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

		foreach ( array_keys( $drop ) as $dropped ) {
			$sources = array();
			$targets = array();
			foreach ( $edges as $e ) {
				if ( $e['target'] === $dropped ) {
					$sources[] = $e['source'];
				}
				if ( $e['source'] === $dropped ) {
					$targets[] = $e['target'];
				}
			}
			foreach ( $sources as $s ) {
				foreach ( $targets as $t ) {
					if ( $s === $t || ! empty( $drop[ $s ] ) || ! empty( $drop[ $t ] ) ) {
						continue;
					}
					$edges[] = array(
						'id'     => 'edge-' . $s . '-' . $t,
						'source' => $s,
						'target' => $t,
					);
				}
			}
		}

		$edges = array_values(
			array_filter(
				$edges,
				function ( $e ) use ( $drop ) {
					return empty( $drop[ $e['source'] ] ) && empty( $drop[ $e['target'] ] );
				}
			)
		);

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Resolve a single "Connect an App" node's data against the LIVE app registry.
	 * Shared by the generator and the in-canvas assistant so both emit real,
	 * registry-sourced slugs/actions. Returns fully-resolved node data (appSlug,
	 * appName, appIcon, toolName, toolConfig, authStatus='none') or null when the
	 * named service cannot be resolved - callers must never fall back to a guess.
	 *
	 * Generation cannot connect the user's own account (per-user OAuth), so the
	 * resolved node lands in a needs-connect state (authStatus='none').
	 *
	 * @param array $data Node data carrying app intent (appService + actionIntent).
	 * @return array|null Resolved data, or null when unresolvable.
	 */
	public static function resolve_app_node_data( $data ) {
		if ( ! class_exists( 'WP_AI_Workflows_Platform_Client' ) ) {
			return null;
		}
		$data = is_array( $data ) ? $data : array();

		// Seed the live search from the named service (preferred). Fall back to any
		// model-provided slug/name so a node is still grounded - but ALWAYS re-resolve
		// so the emitted slug/action come from real registry results, not a guess.
		$seed = '';
		foreach ( array( 'appService', 'appSlug', 'appName', 'nodeName' ) as $k ) {
			if ( isset( $data[ $k ] ) && '' !== trim( (string) $data[ $k ] ) ) {
				$seed = trim( (string) $data[ $k ] );
				break;
			}
		}
		if ( '' === $seed ) {
			return null;
		}

		$search = WP_AI_Workflows_Platform_Client::apps_search( $seed, 10 );
		if ( is_wp_error( $search ) || empty( $search['data'] ) || ! is_array( $search['data'] ) ) {
			return null;
		}

		$apps = self::filter_connectable_apps( $search['data'] );
		if ( empty( $apps ) ) {
			return null;
		}

		$app = self::pick_best_app( $apps, $seed );
		if ( ! is_array( $app ) ) {
			return null;
		}

		// Prefer the human-readable nameSlug ("google_sheets") over the opaque
		// registry id ("app_168hvn"): it is what Connect Link and connection records
		// are keyed by, matches the action-name prefixes, and both apps_tools and
		// apps_connect accept it. Fall back to slug, then id.
		$app_slug = '';
		foreach ( array( 'nameSlug', 'slug', 'id' ) as $k ) {
			if ( isset( $app[ $k ] ) && '' !== trim( (string) $app[ $k ] ) ) {
				$app_slug = trim( (string) $app[ $k ] );
				break;
			}
		}
		$app_slug = sanitize_text_field( $app_slug );
		if ( '' === $app_slug ) {
			return null;
		}
		$app_name = isset( $app['name'] ) ? sanitize_text_field( (string) $app['name'] ) : $app_slug;
		$app_icon = isset( $app['iconUrl'] ) ? esc_url_raw( (string) $app['iconUrl'] ) : '';

		// Resolve the action (toolName) from the app's REAL action list, matched to
		// the requested intent. The action name is never guessed.
		$intent    = isset( $data['actionIntent'] ) ? (string) $data['actionIntent'] : '';
		$tools_res = WP_AI_Workflows_Platform_Client::apps_tools( $app_slug );
		if ( is_wp_error( $tools_res ) || empty( $tools_res['data'] ) || ! is_array( $tools_res['data'] ) ) {
			return null;
		}
		$tool = self::pick_best_tool( $tools_res['data'], $intent );
		if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
			return null;
		}

		return array(
			'nodeName'   => isset( $data['nodeName'] ) && '' !== trim( (string) $data['nodeName'] )
				? sanitize_text_field( (string) $data['nodeName'] )
				: $app_name,
			'appSlug'    => $app_slug,
			'appName'    => $app_name,
			'appIcon'    => $app_icon,
			'toolName'   => sanitize_text_field( (string) $tool['name'] ),
			'toolConfig' => self::sanitize_tool_config( $data['toolConfig'] ?? array() ),
			'authStatus' => 'none',
		);
	}

	/**
	 * Gating seam for app resolution. Full-registry search can surface an app with
	 * no production OAuth client, where the user would dead-end at connect. This is
	 * the SINGLE point to later drop in a "supported/connectable apps" filter.
	 *
	 * TODO: filter to apps with a working production OAuth client once that policy
	 * signal is available from the platform. For now, pass through unchanged.
	 *
	 * @param array $apps Registry search results.
	 * @return array
	 */
	private static function filter_connectable_apps( $apps ) {
		return is_array( $apps ) ? array_values( $apps ) : array();
	}

	/**
	 * Choose the registry app that best matches the seed: an exact slug/name match,
	 * else the top result (the registry is relevance / featured-weight sorted).
	 *
	 * @param array  $apps Registry search results.
	 * @param string $seed Search seed (the named service).
	 * @return array
	 */
	private static function pick_best_app( $apps, $seed ) {
		$needle = self::normalize_text( $seed );
		foreach ( $apps as $app ) {
			if ( ! is_array( $app ) ) {
				continue;
			}
			$candidates = array(
				isset( $app['id'] ) ? $app['id'] : '',
				isset( $app['name'] ) ? $app['name'] : '',
				isset( $app['nameSlug'] ) ? $app['nameSlug'] : '',
				isset( $app['slug'] ) ? $app['slug'] : '',
			);
			foreach ( $candidates as $c ) {
				if ( '' !== $c && self::normalize_text( $c ) === $needle ) {
					return $app;
				}
			}
		}
		return $apps[0];
	}

	/**
	 * Choose the action whose name+description best matches the intended action, by
	 * counting intent keyword hits. Falls back to the first action when nothing
	 * scores (the app's action list is itself relevance-ordered).
	 *
	 * @param array  $tools  App action list.
	 * @param string $intent Plain-language action description.
	 * @return array|null
	 */
	private static function pick_best_tool( $tools, $intent ) {
		$tokens     = self::keywords( $intent );
		$best       = null;
		$best_score = -1;
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}
			$hay   = self::normalize_text( ( isset( $tool['name'] ) ? $tool['name'] : '' ) . ' ' . ( isset( $tool['description'] ) ? $tool['description'] : '' ) );
			$score = 0;
			foreach ( $tokens as $t ) {
				if ( '' !== $t && false !== strpos( $hay, $t ) ) {
					$score++;
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $tool;
			}
		}
		if ( null !== $best ) {
			return $best;
		}
		return isset( $tools[0] ) && is_array( $tools[0] ) ? $tools[0] : null;
	}

	/**
	 * Keep only the string/scalar values of a toolConfig object so the generated
	 * config (which may hold [Input from …] variable tags) is safe to persist.
	 *
	 * @param mixed $config Raw toolConfig.
	 * @return array
	 */
	private static function sanitize_tool_config( $config ) {
		if ( ! is_array( $config ) ) {
			return array();
		}
		$out = array();
		foreach ( $config as $k => $v ) {
			$key = sanitize_text_field( (string) $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_string( $v ) ) {
				$out[ $key ] = sanitize_textarea_field( $v );
			} elseif ( is_scalar( $v ) ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Lowercase and reduce a string to space-separated alphanumeric tokens.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = strtolower( (string) $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Split text into meaningful keyword tokens (drops short words + common stop
	 * words) for matching an action intent against action names/descriptions.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function keywords( $text ) {
		$stop = array( 'the', 'a', 'an', 'to', 'of', 'in', 'on', 'for', 'with', 'and', 'my', 'me', 'it', 'this', 'that', 'into', 'from', 'your' );
		$out  = array();
		foreach ( explode( ' ', self::normalize_text( $text ) ) as $w ) {
			if ( strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	private function validate_workflow_structure( $workflow ) {
		$output_node_types = array(
			'output',
			'post',
			'sendEmail',
			'humanInput',
			'condition',
			'firecrawl',
			'unsplash',
			'research',
			'shortcode',
			'display',
			'webhook',
			'APICall',
			'googleSheets',
			'googleDrive',
			'chat',
			'save',
			'mediaGenerator',
			'createFile',
			'MCPClient', // Connect an App - delivers the result into an external app.
		);

		$trigger_node_types = array(
			'trigger',
			'chat',
			'aiModel',
			'parser',
			'firecrawl',
		);

		$allowed_disconnected_types = array( 'shape', 'textAnnotation', 'stickyNote' );

		$has_trigger = false;
		foreach ( $workflow['nodes'] as $node ) {
			if ( in_array( $node['type'], $trigger_node_types ) ) {
				$has_trigger = true;
				break;
			}
		}

		if ( ! $has_trigger ) {
			WP_AI_Workflows_Utilities::debug_log( 'No trigger node found', 'error' );
			return false;
		}

		$node_connections = array();
		foreach ( $workflow['edges'] as $edge ) {
			if ( ! isset( $node_connections[ $edge['source'] ] ) ) {
				$node_connections[ $edge['source'] ] = array();
			}
			if ( ! isset( $node_connections[ $edge['target'] ] ) ) {
				$node_connections[ $edge['target'] ] = array();
			}
			$node_connections[ $edge['source'] ][] = $edge['target'];
		}

		// Terminal nodes: nodes with no outgoing connections.
		$terminal_nodes = array();
		foreach ( $workflow['nodes'] as $node ) {
			$node_id = $node['id'];
			if ( ! isset( $node_connections[ $node_id ] ) || empty( $node_connections[ $node_id ] ) ) {
				$terminal_nodes[] = $node;
			}
		}

		$valid_terminal_found = false;
		foreach ( $terminal_nodes as $node ) {
			if ( in_array( $node['type'], $output_node_types ) ) {
				$valid_terminal_found = true;
				break;
			}
		}

		if ( ! $valid_terminal_found && ! empty( $terminal_nodes ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'No valid output found in terminal nodes',
				'error',
				array(
					'terminal_nodes' => array_map(
						function ( $node ) {
							return array(
								'id'   => $node['id'],
								'type' => $node['type'],
							);
						},
						$terminal_nodes
					),
				)
			);
			return false;
		}

		$connected_nodes = array();
		foreach ( $workflow['edges'] as $edge ) {
			$connected_nodes[] = $edge['source'];
			$connected_nodes[] = $edge['target'];
		}
		$connected_nodes = array_unique( $connected_nodes );

		foreach ( $workflow['nodes'] as $node ) {
			if ( ! in_array( $node['id'], $connected_nodes ) && ! in_array( $node['type'], $allowed_disconnected_types ) ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Disconnected node found',
					'error',
					array(
						'node_id'   => $node['id'],
						'node_type' => $node['type'],
					)
				);
				return false;
			}
		}

		if ( ! $this->validate_workflow_flow( $workflow['nodes'], $node_connections ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Invalid workflow flow detected', 'error' );
			return false;
		}

		return true;
	}


	private function validate_workflow_flow( $nodes, $connections ) {
		$visited         = array();
		$recursion_stack = array();

		foreach ( $nodes as $node ) {
			if ( ! isset( $visited[ $node['id'] ] ) ) {
				if ( $this->has_cycle( $node['id'], $visited, $recursion_stack, $connections ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private function has_cycle( $node_id, &$visited, &$recursion_stack, $connections ) {
		$visited[ $node_id ]         = true;
		$recursion_stack[ $node_id ] = true;

		if ( isset( $connections[ $node_id ] ) ) {
			foreach ( $connections[ $node_id ] as $adjacent ) {
				if ( ! isset( $visited[ $adjacent ] ) ) {
					if ( $this->has_cycle( $adjacent, $visited, $recursion_stack, $connections ) ) {
						return true;
					}
				} elseif ( isset( $recursion_stack[ $adjacent ] ) && $recursion_stack[ $adjacent ] ) {
					return true;
				}
			}
		}

		$recursion_stack[ $node_id ] = false;
		return false;
	}

	private function fix_missing_connections( $workflow ) {
		$nodes     = $workflow['nodes'];
		$edges     = $workflow['edges'] ?? array();
		$new_edges = array();

		$existing_connections = array();
		foreach ( $edges as $edge ) {
			$connection_key = $edge['source'] . '->' . $edge['target'];
			if ( isset( $edge['sourceHandle'] ) ) {
				$connection_key .= '->' . $edge['sourceHandle'];
			}
			$existing_connections[ $connection_key ] = true;
		}

		foreach ( $nodes as $target_node ) {
			$input_references = $this->find_input_references( $target_node['data'] );

			foreach ( $input_references as $source_node_id => $ref_info ) {
				$source_node = null;
				foreach ( $nodes as $node ) {
					if ( $node['id'] === $source_node_id ) {
						$source_node = $node;
						break;
					}
				}

				if ( $source_node ) {
					if ( $source_node['type'] === 'chat' && $ref_info['type'] === 'action' && ! empty( $ref_info['actions'] ) ) {
						foreach ( $ref_info['actions'] as $action_id ) {
							$connection_key = $source_node_id . '->' . $target_node['id'] . '->' . $action_id;
							if ( ! isset( $existing_connections[ $connection_key ] ) ) {
								$new_edges[]                             = array(
									'id'           => 'e' . substr( uniqid(), -6 ),
									'source'       => $source_node_id,
									'target'       => $target_node['id'],
									'sourceHandle' => $action_id,
								);
								$existing_connections[ $connection_key ] = true;
							}
						}
					} else {
						$handles = $this->get_possible_handles( $source_node['type'] );

						if ( empty( $handles ) ) {
							$connection_key = $source_node_id . '->' . $target_node['id'];
							if ( ! isset( $existing_connections[ $connection_key ] ) ) {
								$new_edges[]                             = array(
									'id'     => 'e' . substr( uniqid(), -6 ),
									'source' => $source_node_id,
									'target' => $target_node['id'],
								);
								$existing_connections[ $connection_key ] = true;
							}
						} else {
							// Node with multiple predefined outputs (e.g. condition, humanInput).
							foreach ( $handles as $handle ) {
								$connection_key = $source_node_id . '->' . $target_node['id'] . '->' . $handle;
								if ( ! isset( $existing_connections[ $connection_key ] ) ) {
									$new_edges[]                             = array(
										'id'           => 'e' . substr( uniqid(), -6 ),
										'source'       => $source_node_id,
										'target'       => $target_node['id'],
										'sourceHandle' => $handle,
									);
									$existing_connections[ $connection_key ] = true;
								}
							}
						}
					}
				}
			}
		}

		if ( ! empty( $new_edges ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Added missing connections',
				'info',
				array(
					'new_edges' => $new_edges,
				)
			);
		}

		$workflow['edges'] = array_merge( $edges, $new_edges );
		return $workflow;
	}

	private function get_possible_handles( $node_type ) {
		switch ( $node_type ) {
			case 'condition':
				return array( 'true', 'false' );
			case 'humanInput':
				return array( 'approve', 'revert', 'modify' );
			default:
				return array();
		}
	}

	private function find_input_references( $data ) {
		$references = array();

		$extract_node_ids = function ( $text ) {
			$found_refs = array();

			// Match pattern: [Input from node-id]
			if ( preg_match_all( '/\[Input from ([\w-]+)\]/', $text, $matches ) ) {
				foreach ( $matches[1] as $node_id ) {
					if ( ! isset( $found_refs[ $node_id ] ) ) {
						$found_refs[ $node_id ] = array(
							'type'    => 'default',
							'actions' => array(),
						);
					}
				}
			}

			// Match pattern: [[Field Name] from node-id] or [[Field Name] from node-id:action-id]
			if ( preg_match_all( '/\[\[([^\]]+)\] from ([\w-]+)(?::([^\]]+))?\]/', $text, $matches ) ) {
				for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
					$node_id   = $matches[2][ $i ];
					$action_id = isset( $matches[3][ $i ] ) && $matches[3][ $i ] !== '' ? $matches[3][ $i ] : null;

					if ( ! isset( $found_refs[ $node_id ] ) ) {
						$found_refs[ $node_id ] = array(
							'type'    => 'default',
							'actions' => array(),
						);
					}

					if ( $action_id !== null ) {
						$found_refs[ $node_id ]['type'] = 'action';
						if ( ! in_array( $action_id, $found_refs[ $node_id ]['actions'] ) ) {
							$found_refs[ $node_id ]['actions'][] = $action_id;
						}
					}
				}
			}

			return $found_refs;
		};

		$search_references = function ( $value ) use ( &$search_references, $extract_node_ids, &$references ) {
			if ( is_string( $value ) ) {
				$refs = $extract_node_ids( $value );
				foreach ( $refs as $node_id => $ref_info ) {
					if ( ! isset( $references[ $node_id ] ) ) {
						$references[ $node_id ] = $ref_info;
					} else {
						if ( $ref_info['type'] === 'action' && ! empty( $ref_info['actions'] ) ) {
							$references[ $node_id ]['type']    = 'action';
							$references[ $node_id ]['actions'] = array_unique(
								array_merge(
									$references[ $node_id ]['actions'],
									$ref_info['actions']
								)
							);
						}
					}
				}
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$search_references( $item );
				}
			}
		};

		$searchable_fields = array(
			'content',
			'body',
			'subject',
			'fileName',
			'fileContent',
			'imageUrl',
			'prompt',
			'url',
			'systemPrompt',
			'to',
			'cc',
			'bcc',
		);

		foreach ( $searchable_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$search_references( $data[ $field ] );
			}
		}

		// Field mappings (post nodes and database nodes).
		if ( isset( $data['fieldMappings'] ) && is_array( $data['fieldMappings'] ) ) {
			foreach ( $data['fieldMappings'] as $mapping ) {
				$search_references( $mapping );
			}
		}

		if ( isset( $data['webhookKeys'] ) && is_array( $data['webhookKeys'] ) ) {
			foreach ( $data['webhookKeys'] as $key ) {
				if ( isset( $key['mapping'] ) ) {
					$search_references( $key['mapping'] );
				}
			}
		}

		if ( isset( $data['conditionGroups'] ) && is_array( $data['conditionGroups'] ) ) {
			foreach ( $data['conditionGroups'] as $group ) {
				if ( isset( $group['conditions'] ) && is_array( $group['conditions'] ) ) {
					foreach ( $group['conditions'] as $condition ) {
						if ( isset( $condition['input'] ) ) {
							$search_references( $condition['input'] );
						}
					}
				}
			}
		}

		WP_AI_Workflows_Utilities::debug_log(
			'Found input references',
			'debug',
			array(
				'node_data'  => $data,
				'references' => $references,
			)
		);

		return $references;
	}

	/**
	 * Resolve the model to use for a node.
	 *
	 * Permissive by design: an unknown model id is passed through unchanged (the
	 * provider API will reject it if it is truly invalid) so that brand-new models
	 * are NOT silently downgraded. Only when NO model is specified do we fall back
	 * to the current default from the dynamic catalog. Validation against the live
	 * catalog is advisory only and produces a debug note.
	 *
	 * @param string $model Requested model id (may be empty).
	 * @return string Model id to use.
	 */
	private function validate_and_get_model( $model ) {
		$model = is_string( $model ) ? trim( $model ) : '';

		if ( '' === $model ) {
			return WP_AI_Workflows_Model_Catalog::get_default_model();
		}

		if ( ! WP_AI_Workflows_Model_Catalog::is_known_model( $model ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Model not found in catalog; passing through to provider (not downgraded)',
				'debug',
				array( 'model' => $model )
			);
		}

		return $model;
	}

	private function prepare_workflow_for_frontend( $workflow ) {
		foreach ( $workflow['nodes'] as &$node ) {
			$node['data']['updateNodeData'] = true; // Will be replaced with actual function in frontend
			$node['data']['onDelete']       = true; // Will be replaced with actual function in frontend
		}
		return $workflow;
	}

	public function clear_prompt_cache() {
		// Busts the local assembly cache (static XML + injected node catalog), which
		// is complementary to the Anthropic API-level prompt cache: this only avoids
		// re-reading the file / re-serializing the catalog per request.
		$this->system_prompt = null;
		wp_cache_delete( 'wp_ai_workflows_system_prompt' );
	}

	public function refresh_prompt() {
		$this->clear_prompt_cache();
		if ( file_exists( $this->prompt_path ) ) {
			wp_delete_file( $this->prompt_path );
		}
		$this->ensure_system_prompt();
	}
}
