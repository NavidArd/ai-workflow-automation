<?php
/**
 * WP_AI_Workflows_Workflow_Translator - converts the plugin's stored React Flow
 * workflow JSON into the cloud engine's native `definition` and decides per-node
 * cloud eligibility. Pure PHP (no WordPress functions), invoked server-side at
 * submit time since scheduled/trigger-fired cloud runs have no browser.
 *
 * Fail-closed: unsupported nodes are rejected and named, never silently dropped.
 * `frontend/src/config/cloudNodeMap.js` is an advisory mirror - keep in sync.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow standalone unit testing without a WP bootstrap.
	if ( ! class_exists( 'WP_AI_Workflows_Translation_Error' ) ) {
		// no-op: the error class is defined below regardless.
		true;
	}
}

/**
 * Plain-PHP error value returned by the translator when a workflow cannot be
 * translated for Cloud. Lists every offending node so the UI can name them all.
 * NOT a WP_Error (keeps the translator WP-free / unit-testable).
 */
class WP_AI_Workflows_Translation_Error {

	/** @var array<int,array{id:string,type:string,reason:string}> */
	private $nodes;

	/**
	 * @param array<int,array{id:string,type:string,reason:string}> $nodes Offending nodes.
	 */
	public function __construct( array $nodes ) {
		$this->nodes = array_values( $nodes );
	}

	/** @return array<int,array{id:string,type:string,reason:string}> */
	public function get_nodes() {
		return $this->nodes;
	}

	/**
	 * A single human-readable summary line naming each offending node.
	 *
	 * @return string
	 */
	public function get_message() {
		$parts = array();
		foreach ( $this->nodes as $n ) {
			$label   = '' !== $n['type'] ? $n['type'] : 'node';
			$parts[] = $label . ' (' . $n['id'] . '): ' . $n['reason'];
		}
		return 'This workflow cannot run in Cloud. ' . implode( ' | ', $parts );
	}
}

class WP_AI_Workflows_Workflow_Translator {

	/** Non-executable visual aids - stripped from the cloud definition (R4.3). */
	const ANNOTATION_TYPES = array( 'stickyNote', 'textAnnotation', 'shape' );

	/** Plugin type id => engine processor id (case/naming remap, R4.2). */
	const TYPE_REMAP = array(
		'APICall'   => 'apiCall',
		'MCPClient' => 'mcpClient',
	);

	/**
	 * Engine processor ids that are cloud-executable 1:1 (post-remap). `output` is
	 * here but additionally gated by outputType (WP-local types blocked), and a
	 * supported type can still be refused for a setting the cloud cannot honour
	 * (see unsupported_setting()).
	 */
	const SUPPORTED_ENGINE_TYPES = array(
		'trigger', 'aiModel', 'output', 'condition', 'humanInput',
		'sendEmail', 'research', 'firecrawl', 'sentimentAnalysis',
		'summaryGenerator', 'extractInformation', 'writeArticle', 'optimizeSEO',
		'mediaGenerator', 'apiCall', 'mcpClient', 'loop',
	);

	/**
	 * `output` node types that write into a WordPress DB table / shortcode store and
	 * are rewritten to a `wpAction` `save_output` callback (Wave 4). Shortcodes read
	 * saved outputs, so they map to the same save.
	 */
	const SAVE_OUTPUT_TYPES = array( 'save', 'database', 'shortcode' );

	/**
	 * `output` node types that still produce a WordPress-local side effect for which
	 * there is NO v2.0 callback action (Google integrations run with the site's own
	 * creds; html/file are local render/file writes). Blocked fail-closed. The
	 * rewritable set (`save`/`database`/`shortcode`/`post`) is handled by the Wave 4
	 * `wpAction` rewrite and is intentionally NOT listed here. Cloud-safe output
	 * types: `display`, `webhook`.
	 */
	const WP_LOCAL_OUTPUT_TYPES = array(
		'html', 'file', 'googleSheets', 'googleDrive', 'google_sheets',
	);

	/**
	 * Executable types that must run Locally, each with the reason the builder shows.
	 * `post` is not here: it is rewritten to a `wpAction` insert_post callback.
	 */
	const LOCAL_ONLY_TYPES = array(
		'unsplash'    => 'Unsplash images are fetched by your site, so this workflow needs to run Locally.',
		'createFile'  => 'Create File writes into your site\'s media library, so this workflow needs to run Locally.',
		'generatePdf' => 'Generate PDF is rendered by our service and collected by your site, so this workflow needs to run Locally. It still renders in the cloud on credits.',
		'parser'      => 'Document parsing runs on your site, so this workflow needs to run Locally.',
	);

	/**
	 * Translate a stored workflow blob into a cloud definition, or return a typed
	 * error naming every node that blocks Cloud.
	 *
	 * @param array $workflow Decoded workflow JSON (['nodes'=>[], 'edges'=>[], ...]).
	 * @return array{definition:array{nodes:array,edges:array}}|WP_AI_Workflows_Translation_Error
	 */
	public static function translate( array $workflow ) {
		$nodes = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array();
		$edges = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

		$kept_ids     = array();   // original id => true, for edge filtering
		$stripped_ids = array();   // annotation ids to drop edges for
		$out_nodes    = array();
		$offending    = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id   = isset( $node['id'] ) ? (string) $node['id'] : '';
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';

			// Step 1: strip annotations.
			if ( in_array( $type, self::ANNOTATION_TYPES, true ) ) {
				$stripped_ids[ $id ] = true;
				continue;
			}

			// Steps 2 + 3: classify (remap + support check + WP-local gate).
			$class = self::classify_node( $node );
			if ( ! $class['supported'] ) {
				$offending[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $class['reason'],
				);
				continue;
			}

			// Step 5 (Wave 4): WP-side-effect rewrite. A node classified as `wpAction`
			// (post node, or output save/database/shortcode/post) becomes a signed
			// callback carrying {action, payload} - see WP_AI_Workflows_Platform_Callback.
			if ( 'wpAction' === $class['engineType'] ) {
				$data = self::build_wp_action_data( $node );
			} else {
				// Step 4: strict data allow-list (task 2.2). Returns mapped data or a
				// reason string if a required field is missing / contract unverified.
				$data = self::filter_node_data( $class['engineType'], isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array() );
			}
			if ( is_string( $data ) ) {
				$offending[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $data,
				);
				continue;
			}

			// Step 6a: assemble node, preserving id + position, remapped type.
			$assembled = array(
				'id'   => $id,
				'type' => $class['engineType'],
				'data' => $data,
			);
			if ( isset( $node['position'] ) ) {
				$assembled['position'] = $node['position'];
			}
			$out_nodes[]      = $assembled;
			$kept_ids[ $id ]  = true;
		}

		if ( ! empty( $offending ) ) {
			return new WP_AI_Workflows_Translation_Error( $offending );
		}

		// Step 6b: keep only edges between two kept nodes (drops annotation edges).
		$out_edges = array();
		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$source = isset( $edge['source'] ) ? (string) $edge['source'] : '';
			$target = isset( $edge['target'] ) ? (string) $edge['target'] : '';
			if ( isset( $stripped_ids[ $source ] ) || isset( $stripped_ids[ $target ] ) ) {
				continue;
			}
			if ( ! isset( $kept_ids[ $source ] ) || ! isset( $kept_ids[ $target ] ) ) {
				continue;
			}
			$assembled_edge = array(
				'source' => $source,
				'target' => $target,
			);
			if ( isset( $edge['id'] ) ) {
				$assembled_edge['id'] = (string) $edge['id'];
			}
			if ( isset( $edge['sourceHandle'] ) ) {
				$assembled_edge['sourceHandle'] = $edge['sourceHandle'];
			}
			if ( isset( $edge['targetHandle'] ) ) {
				$assembled_edge['targetHandle'] = $edge['targetHandle'];
			}
			$out_edges[] = $assembled_edge;
		}

		return array(
			'definition' => array(
				'nodes' => $out_nodes,
				'edges' => $out_edges,
			),
		);
	}

	/**
	 * Advisory pre-check used by the REST layer for the builder (R3.5/R4.4): list
	 * every node that would block Cloud, with a plain reason. Annotations are not
	 * reported (they are silently stripped, not blockers).
	 *
	 * @param array $nodes Node array from the stored workflow.
	 * @return array<int,array{id:string,type:string,reason:string}>
	 */
	public static function get_unsupported_nodes( array $nodes ) {
		$unsupported = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';
			if ( in_array( $type, self::ANNOTATION_TYPES, true ) ) {
				continue;
			}
			$class = self::classify_node( $node );
			if ( ! $class['supported'] ) {
				$unsupported[] = array(
					'id'     => isset( $node['id'] ) ? (string) $node['id'] : '',
					'type'   => $type,
					'reason' => $class['reason'],
				);
			}
		}
		return $unsupported;
	}

	/**
	 * Classify a single node: is it cloud-supported, and if so under which engine
	 * type. Applies the identifier remap and the WP-local output gate.
	 *
	 * @param array $node
	 * @return array{supported:bool,engineType:string,reason:string}
	 */
	private static function classify_node( array $node ) {
		$type        = isset( $node['type'] ) ? (string) $node['type'] : '';
		$engine_type = isset( self::TYPE_REMAP[ $type ] ) ? self::TYPE_REMAP[ $type ] : $type;
		$data        = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		// Chat: hybrid (Q5) - the widget runs locally; only its AI calls are metered.
		if ( 'chat' === $type ) {
			return self::blocked( 'chat runs on this site; enable credit mode to meter its AI calls instead of running it in Cloud.' );
		}

		if ( isset( self::LOCAL_ONLY_TYPES[ $type ] ) ) {
			return self::blocked( self::LOCAL_ONLY_TYPES[ $type ] );
		}

		$setting_reason = self::unsupported_setting( $type, $data );
		if ( '' !== $setting_reason ) {
			return self::blocked( $setting_reason );
		}

		// Wave 4: the `post` node is rewritten to a `wpAction` insert_post callback.
		if ( 'post' === $type ) {
			return array( 'supported' => true, 'engineType' => 'wpAction', 'reason' => '' );
		}

		if ( 'output' === $engine_type ) {
			$output_type = isset( $node['data']['outputType'] ) ? (string) $node['data']['outputType'] : '';

			// Wave 4: WP-local writes are rewritten to a `wpAction` callback instead
			// of being blocked. save/database/shortcode -> save_output; post -> insert_post.
			if ( in_array( $output_type, self::SAVE_OUTPUT_TYPES, true ) || 'post' === $output_type ) {
				return array( 'supported' => true, 'engineType' => 'wpAction', 'reason' => '' );
			}

			// Remaining WP-local output types have no v2.0 callback action - blocked.
			if ( in_array( $output_type, self::WP_LOCAL_OUTPUT_TYPES, true ) ) {
				return self::blocked( 'This output type runs on your WordPress site and is not yet supported in Cloud. Use Display/Webhook output, or run Locally.' );
			}
			// Only known cloud-safe output types pass (fail-closed on anything else).
			if ( '' !== $output_type && ! in_array( $output_type, array( 'display', 'webhook' ), true ) ) {
				return self::blocked( 'This output type is not yet supported in Cloud. Use Display/Webhook, or run Locally.' );
			}
			return array( 'supported' => true, 'engineType' => 'output', 'reason' => '' );
		}

		if ( in_array( $engine_type, self::SUPPORTED_ENGINE_TYPES, true ) ) {
			return array( 'supported' => true, 'engineType' => $engine_type, 'reason' => '' );
		}

		return self::blocked( 'This node type is not supported in Cloud and must run Locally.' );
	}

	/**
	 * @param string $reason
	 * @return array{supported:bool,engineType:string,reason:string}
	 */
	private static function blocked( $reason ) {
		return array( 'supported' => false, 'engineType' => '', 'reason' => $reason );
	}

	/**
	 * A cloud-supported node type can still be configured for something the cloud
	 * engine cannot do. Returns the reason to show in the builder before the run,
	 * or '' when the node can go.
	 *
	 * @param string $type Plugin node type.
	 * @param array  $data Node data.
	 * @return string
	 */
	private static function unsupported_setting( $type, array $data ) {
		$output_type = isset( $data['outputType'] ) ? (string) $data['outputType'] : '';
		if ( 'output' === $type && 'post' === $output_type ) {
			$type = 'post';
		}

		switch ( $type ) {
			case 'post':
				return self::post_setting_reason( $data );

			case 'firecrawl':
				$operation = isset( $data['operation'] ) ? (string) $data['operation'] : '';
				if ( '' !== $operation && ! in_array( $operation, array( 'scrape', 'crawl' ), true ) ) {
					return 'Cloud can scrape and crawl. Map, search and agent are run by your site, so this workflow needs to run Locally.';
				}
				return '';

			case 'sendEmail':
				if ( ! empty( $data['delayEnabled'] ) ) {
					return 'A delayed send is scheduled by your site. Turn the delay off to run in Cloud, or run this workflow Locally.';
				}
				return '';

			case 'aiModel':
				$kb = isset( $data['knowledgeBase'] ) && is_array( $data['knowledgeBase'] ) ? $data['knowledgeBase'] : array();
				if ( ! empty( $kb['enabled'] ) && ! empty( $kb['kbId'] ) ) {
					return 'A Knowledge Base is stored by your site and read during the run, so this workflow needs to run Locally.';
				}
				return '';

			case 'humanInput':
				$assigned = ( 'role' === ( isset( $data['assignmentType'] ) ? $data['assignmentType'] : '' ) )
					? ( isset( $data['selectedRole'] ) ? $data['selectedRole'] : '' )
					: ( isset( $data['selectedUser'] ) ? $data['selectedUser'] : '' );
				if ( ! empty( $assigned ) ) {
					return 'A task assigned to a WordPress user or role is tracked by your site, so this workflow needs to run Locally.';
				}
				return '';

			case 'loop':
				$loop_type = isset( $data['loopType'] ) ? (string) $data['loopType'] : '';
				if ( '' !== $loop_type && 'forEach' !== $loop_type ) {
					return 'Cloud can loop over a list. Count and While loops are run by your site, so this workflow needs to run Locally.';
				}
				return '';
		}

		return '';
	}

	/**
	 * Post fields a cloud run cannot write. The callback creates the post from the
	 * title, excerpt, content, status and post type; everything else on the node is
	 * applied by the site's own execution path.
	 *
	 * @param array $data Node data.
	 * @return string
	 */
	private static function post_setting_reason( array $data ) {
		$preamble = 'A Cloud run can set the title, excerpt, content, status and post type. ';
		$mappings = isset( $data['fieldMappings'] ) && is_array( $data['fieldMappings'] ) ? $data['fieldMappings'] : array();

		foreach ( $mappings as $field => $value ) {
			if ( 0 === strpos( (string) $field, 'acf_' ) && '' !== trim( (string) $value ) ) {
				return $preamble . 'ACF fields are written by your site, so this workflow needs to run Locally.';
			}
		}

		// The builder always carries a featuredImage shape; only a url or an
		// attachment id means the node really sets one.
		$featured     = isset( $data['featuredImage'] ) ? $data['featuredImage'] : '';
		$has_featured = is_array( $featured )
			? ( ! empty( $featured['url'] ) || ! empty( $featured['id'] ) )
			: '' !== trim( (string) $featured );
		if ( $has_featured ) {
			return $preamble . 'A featured image is added by your site, so this workflow needs to run Locally.';
		}

		$site_only = array(
			'selectedAuthor'     => 'The author is set by your site',
			'selectedCategories' => 'Categories are set by your site',
			'productImages'      => 'Product images are added by your site',
		);
		foreach ( $site_only as $field => $sentence ) {
			if ( ! empty( $data[ $field ] ) ) {
				return $preamble . $sentence . ', so this workflow needs to run Locally.';
			}
		}

		if ( 'future' === ( isset( $data['postStatus'] ) ? (string) $data['postStatus'] : '' ) ) {
			return 'A scheduled post is timed by your site, so this workflow needs to run Locally.';
		}

		return '';
	}

	/**
	 * Provider-key field names that must never be sent to the platform - BYOK is
	 * local-only. Dropped from every node regardless of contract; Cloud runs
	 * exclusively on the platform's own keys.
	 */
	const PROVIDER_KEY_FIELDS = array(
		'apiKey', 'api_key', 'openai_api_key', 'openrouter_api_key',
		'anthropic_api_key', 'perplexity_api_key', 'firecrawl_api_key',
		'llamaparse_api_key', 'fal_api_key', 'unsplash_api_key', 'tokens',
	);

	/**
	 * Fields that are meaningful ONLY for local execution and must be stripped
	 * before a cloud submission. `keySource` (task 3.1) is a BYOK/credits routing
	 * hint consumed by WP_AI_Workflows_AI_Router on the Local path; Cloud runs
	 * always use the platform's own keys, so it is dropped here explicitly (it is
	 * also absent from every contract allow-list, so this is defence in depth).
	 */
	const LOCAL_ROUTING_FIELDS = array( 'keySource' );

	/**
	 * Strict per-node data allow-list. Each supported engine type declares its
	 * `required` and `optional` fields; fields outside the union are dropped, and
	 * a missing required field or an unverified contract makes the node
	 * unsupported (fail-closed). Provider keys are never listed - they are
	 * stripped unconditionally (PROVIDER_KEY_FIELDS).
	 *
	 * @return array<string,array{required:array,optional:array}>
	 */
	private static function contracts() {
		return array(
			// Builder + TriggerNode.ts
			'trigger'            => array(
				'required' => array(),
				'optional' => array( 'triggerType', 'content', 'selectedForm', 'selectedFields', 'webhookUrl', 'webhookKeys', 'selectedWpCoreTrigger', 'wpCoreTriggerConditions', 'selectedWorkflow', 'rssSettings', 'nodeName' ),
			),
			// Builder + AIModelNode.ts. An empty prompt is legal: the node then uses
			// its incoming input. knowledgeBase is site-held and blocked upstream.
			'aiModel'            => array(
				'required' => array(),
				'optional' => array( 'model', 'content', 'settings', 'imageUrls', 'openaiTools', 'structuredOutput', 'outputSchema', 'nodeName' ),
			),
			// Builder + OutputNode.ts (only display/webhook reach here).
			'output'             => array(
				'required' => array( 'outputType' ),
				'optional' => array( 'webhookUrl', 'webhookKeys', 'delayEnabled', 'delayValue', 'delayUnit', 'nodeName' ),
			),
			// Builder + ConditionNode.ts (conditions/operator are the pre-group shape).
			'condition'          => array(
				'required' => array(),
				'optional' => array( 'conditionGroups', 'conditions', 'operator', 'nodeName' ),
			),
			// Builder + HumanInputNode.ts. Assignment is site-held and blocked upstream.
			'humanInput'         => array(
				'required' => array(),
				'optional' => array( 'inputType', 'content', 'instructions', 'nodeName' ),
			),
			// Builder + SendEmailNode.ts. A delayed send is blocked upstream.
			'sendEmail'          => array(
				'required' => array( 'to', 'subject', 'body' ),
				'optional' => array( 'cc', 'bcc', 'useHtml', 'attachments', 'nodeName' ),
			),
			// Builder + ResearchNode.ts.
			'research'           => array(
				'required' => array(),
				'optional' => array( 'model', 'content', 'searchContext', 'citations', 'citationQuality', 'searchDomainFilters', 'search_recency_filter', 'temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'nodeName' ),
			),
			// Builder + FirecrawlNode.ts. Map, search and agent are blocked upstream.
			'firecrawl'          => array(
				'required' => array(),
				'optional' => array(
					'operation', 'url', 'query', 'formats', 'onlyMainContent', 'waitFor', 'timeout', 'isMobile',
					'includeTags', 'excludeTags', 'jsonPrompt', 'jsonFields', 'question',
					'limit', 'includePaths', 'excludePaths', 'crawlEntireDomain', 'sitemap', 'maxDiscoveryDepth',
					'mapSearch', 'mapLimit', 'includeSubdomains',
					'sources', 'categories', 'searchLimit', 'searchScrape', 'location',
					'agentPrompt', 'agentFields', 'agentUrls',
					// Legacy v0/v1 fields retained for backward compatibility.
					'format', 'extractType', 'extractFields', 'extractPrompt', 'maxDepth', 'ignoreSitemap', 'allowBackwardLinks', 'allowExternalLinks', 'length',
					'nodeName',
				),
			),
			// Builder + SentimentAnalysisNode.ts.
			'sentimentAnalysis'  => array(
				'required' => array(),
				'optional' => array( 'content', 'model', 'settings', 'nodeName' ),
			),
			// Builder + SummaryGeneratorNode.ts.
			'summaryGenerator'   => array(
				'required' => array(),
				'optional' => array( 'content', 'maxLength', 'summaryStyle', 'model', 'settings', 'nodeName' ),
			),
			// Builder + ExtractInformationNode.ts.
			'extractInformation' => array(
				'required' => array(),
				'optional' => array( 'content', 'extractionFields', 'model', 'settings', 'nodeName' ),
			),
			// Builder + WriteArticleNode.ts.
			'writeArticle'       => array(
				'required' => array(),
				'optional' => array( 'content', 'keywords', 'tone', 'wordCount', 'writingStyle', 'includeHeadings', 'includeConclusion', 'includeSources', 'model', 'settings', 'nodeName' ),
			),
			// Builder + OptimizeSEONode.ts.
			'optimizeSEO'        => array(
				'required' => array(),
				'optional' => array( 'content', 'keywords', 'seoFocus', 'targetLength', 'includeHeadings', 'includeMetaDescription', 'includeTitle', 'model', 'settings', 'nodeName' ),
			),
			// Builder + MediaGeneratorNode.ts. fieldValues carries the model's own
			// input schema, so its keys change with the selected model.
			'mediaGenerator'     => array(
				'required' => array( 'selectedModel' ),
				'optional' => array( 'modelGroup', 'fieldValues', 'nodeName' ),
			),
			// Builder + APICallNode.ts (auth is the user's endpoint credential, not an AI provider key).
			'apiCall'            => array(
				'required' => array( 'url' ),
				'optional' => array( 'method', 'headers', 'body', 'queryParams', 'auth', 'responseConfig', 'nodeName' ),
			),
			// Builder + MCPClientNode.ts (the cloud re-checks the app connection itself).
			'mcpClient'          => array(
				'required' => array( 'appSlug', 'toolName' ),
				'optional' => array( 'toolConfig', 'nodeName' ),
			),
			// Builder + LoopNodeV4.ts. Count and While loops are blocked upstream; the
			// loop body is found from the edges leaving the `iteration` handle.
			'loop'               => array(
				'required' => array(),
				'optional' => array( 'loopType', 'content', 'maxIterations', 'outputMode', 'continueOnError', 'nodeName' ),
			),
		);
	}

	/**
	 * Build the `wpAction` node data ({action, payload}) for a WP-side-effect node.
	 * The payload is a narrow, explicit allow-list per action - never the raw node
	 * data. WP_AI_Workflows_Platform_Callback validates + sanitizes it again on
	 * receipt (fail-closed on both ends).
	 *
	 * @param array $node
	 * @return array{action:string,payload:array}
	 */
	private static function build_wp_action_data( array $node ) {
		$type = isset( $node['type'] ) ? (string) $node['type'] : '';
		$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		// The `post` node and an `output` node with outputType `post` both create/
		// update a WordPress post.
		$output_type = isset( $data['outputType'] ) ? (string) $data['outputType'] : '';
		if ( 'post' === $type || 'post' === $output_type ) {
			return self::build_post_action( $data );
		}

		// output save/database/shortcode -> save_output.
		return self::build_save_output_action( $data );
	}

	/**
	 * insert_post / update_post payload from a post (or output-post) node. Values are
	 * carried verbatim (the cloud engine interpolates variables in node data before
	 * the wpAction node runs); the WP callback sanitizes on receipt. Non-core field
	 * mappings (WooCommerce fields, custom meta) ride along under `product` / `meta`
	 * when WP_AI_Workflows_Post_Fields is available; ACF mappings never forward here,
	 * post_setting_reason() already refuses Cloud for those.
	 *
	 * @param array $data
	 * @return array{action:string,payload:array}
	 */
	private static function build_post_action( array $data ) {
		// The Post node stores its fields under `fieldMappings` (post_title /
		// post_content) with selectedPostType + postStatus; an output-post node
		// carries them as flat title/content/postType/status. Read both shapes.
		$mappings = ( isset( $data['fieldMappings'] ) && is_array( $data['fieldMappings'] ) ) ? $data['fieldMappings'] : array();
		$pick     = static function ( array $candidates, $fallback = '' ) {
			foreach ( $candidates as $candidate ) {
				if ( null !== $candidate && '' !== $candidate ) {
					return (string) $candidate;
				}
			}
			return $fallback;
		};

		$payload = array(
			'title'     => $pick( array( $mappings['post_title'] ?? null, $data['title'] ?? null ) ),
			'excerpt'   => $pick( array( $mappings['post_excerpt'] ?? null, $data['excerpt'] ?? null ) ),
			'content'   => $pick( array( $mappings['post_content'] ?? null, $data['content'] ?? null ) ),
			'status'    => $pick( array( $data['postStatus'] ?? null, $data['status'] ?? null ), 'draft' ),
			'post_type' => $pick( array( $data['selectedPostType'] ?? null, $data['postType'] ?? null ), 'post' ),
		);

		if ( class_exists( 'WP_AI_Workflows_Post_Fields' ) ) {
			$split     = WP_AI_Workflows_Post_Fields::split_mappings( $mappings, $payload['post_type'] );
			$not_empty = static function ( $value ) {
				return '' !== $value;
			};

			$product = array_filter( $split['product'], $not_empty );
			if ( ! empty( $product ) ) {
				$payload['product'] = $product;
			}

			$meta = array_filter( $split['meta'], $not_empty );
			if ( ! empty( $meta ) ) {
				$payload['meta'] = $meta;
			}
		}

		// Update mode when the node targets an existing post id.
		$post_id = isset( $data['postId'] ) ? $data['postId'] : ( isset( $data['post_id'] ) ? $data['post_id'] : null );
		if ( ! empty( $post_id ) ) {
			$payload['id'] = $post_id;
			return array( 'action' => 'update_post', 'payload' => $payload );
		}
		return array( 'action' => 'insert_post', 'payload' => $payload );
	}

	/**
	 * save_output payload from an output save/database/shortcode node. Carries the
	 * target table and the column config; the WP callback sanitizes the table name
	 * and column keys and inserts a row.
	 *
	 * @param array $data
	 * @return array{action:string,payload:array}
	 */
	private static function build_save_output_action( array $data ) {
		$payload = array(
			'table'   => isset( $data['selectedTable'] ) && '' !== $data['selectedTable'] ? (string) $data['selectedTable'] : 'wp_ai_workflows_outputs',
			'columns' => isset( $data['columns'] ) && is_array( $data['columns'] ) ? array_values( $data['columns'] ) : array(),
		);
		if ( isset( $data['content'] ) ) {
			$payload['content'] = (string) $data['content'];
		}
		return array( 'action' => 'save_output', 'payload' => $payload );
	}

	/**
	 * Strict per-node data allow-list. Drops unlisted + provider-key fields, rejects
	 * a node missing a required field or lacking a verified contract (fail-closed).
	 *
	 * @param string $engine_type
	 * @param array  $data
	 * @return array|string Mapped data, or a reason string if the node is unsupported.
	 */
	private static function filter_node_data( $engine_type, array $data ) {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $engine_type ] ) ) {
			// A supported type with no verified contract degrades to unsupported.
			return 'This node type is not yet verified for Cloud submission and must run Locally.';
		}
		$contract = $contracts[ $engine_type ];
		$allowed  = array_merge( $contract['required'], $contract['optional'] );

		// Fail-closed on any missing required field.
		foreach ( $contract['required'] as $field ) {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] || '' === $data[ $field ] ) {
				return 'A required field ("' . $field . '") is missing, so this node cannot run in Cloud.';
			}
		}

		// Keep only allow-listed fields; never a provider key, never a local-only
		// routing hint (keySource) - both are stripped for Cloud.
		$out = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data )
				&& ! in_array( $field, self::PROVIDER_KEY_FIELDS, true )
				&& ! in_array( $field, self::LOCAL_ROUTING_FIELDS, true ) ) {
				$out[ $field ] = $data[ $field ];
			}
		}
		return $out;
	}
}
