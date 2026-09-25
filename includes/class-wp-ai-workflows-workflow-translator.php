<?php
/**
 * WP_AI_Workflows_Workflow_Translator - converts the plugin's stored React Flow
 * workflow JSON into the cloud engine's native `definition` and decides, per
 * node, where that node runs: in the cloud engine or on this site. Pure PHP (no
 * WordPress functions), invoked server-side at submit time since
 * scheduled/trigger-fired cloud runs have no browser.
 *
 * node_locus() is the single implementation of the locus rule. The builder reads
 * the same answer over the execution-plan endpoint; nothing recomputes it.
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

if ( ! class_exists( 'WP_AI_Workflows_Node_Catalog' )
	&& ( defined( 'ABSPATH' ) || defined( 'WP_AI_WORKFLOWS_NODE_CATALOG_TEST' ) )
	&& is_readable( __DIR__ . '/class-wp-ai-workflows-node-catalog.php' ) ) {
	require_once __DIR__ . '/class-wp-ai-workflows-node-catalog.php';
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

	/** Credits a completed Cloud run costs before any per-node cost. */
	const BASE_CREDITS = 1;

	/** Credits the platform's render service charges for a Generate PDF step, in either mode. */
	const PDF_RENDER_CREDITS = 2;

	/** Plugin type id => engine processor id (case/naming remap, R4.2). */
	const TYPE_REMAP = array(
		'APICall'   => 'apiCall',
		'MCPClient' => 'mcpClient',
	);

	/**
	 * Engine processor ids that are cloud-executable 1:1 (post-remap). Only a node
	 * whose resolved locus is `cloud` is classified against this list; a site-locus
	 * node is emitted as a site step instead.
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

	/** The engine node type a site-locus node is emitted as. */
	const SITE_STEP_TYPE = 'siteStep';

	/** Locus values. */
	const LOCUS_CLOUD = 'cloud';
	const LOCUS_SITE  = 'site';

	/** The three labels the builder shows for a node's locus. */
	const LABEL_CLOUD    = 'Runs in the cloud';
	const LABEL_SITE     = 'Runs on your site';
	const LABEL_RENDERED = 'Rendered in the cloud, saved to your site';

	/**
	 * Site-locus types whose work is performed by a cloud service and collected
	 * on the site. They carry the third label and are stamped `hybrid` on the
	 * wire; they are dispatched exactly like any other site step.
	 */
	const RENDERED_IN_CLOUD_TYPES = array( 'generatePdf', 'parser' );

	/** The wire value for a site step whose work happens in the cloud. */
	const LOCUS_HYBRID = 'hybrid';

	/**
	 * Providers the platform holds keys for. A node needing a provider absent from
	 * this list resolves to `site` and runs on the site's own key (rule 3). Kept in
	 * step with the platform's provider inventory.
	 */
	const PLATFORM_PROVIDER_KEYS = array( 'openai', 'openrouter', 'anthropic', 'perplexity', 'firecrawl', 'fal' );

	/** Node type => the provider whose key it needs. Absent types need none. */
	const NODE_PROVIDERS = array(
		'research'       => 'perplexity',
		'firecrawl'      => 'firecrawl',
		'mediaGenerator' => 'fal',
		'unsplash'       => 'unsplash',
		'parser'         => 'llamaparse',
	);

	/** Per-`outputType` locus, including the aliases only older saved workflows use. */
	const OUTPUT_TYPE_LOCUS = array(
		'display'       => self::LOCUS_CLOUD,
		'webhook'       => 'either',
		'save'          => self::LOCUS_SITE,
		'database'      => self::LOCUS_SITE,
		'shortcode'     => self::LOCUS_SITE,
		'post'          => self::LOCUS_SITE,
		'html'          => self::LOCUS_SITE,
		'file'          => self::LOCUS_SITE,
		'googleSheets'  => self::LOCUS_SITE,
		'googleDrive'   => self::LOCUS_SITE,
		'google_sheets' => self::LOCUS_SITE,
	);

	/** `outputType` values whose site step runs long and pauses the cloud run. */
	const OUTPUT_ASYNC_TYPES = array( 'file', 'googleSheets', 'googleDrive', 'google_sheets' );

	/** Reason clauses shown after the label, in the builder's "because" form. */
	const OUTPUT_TYPE_REASONS = array(
		'save'          => 'it writes to a table on your site',
		'database'      => 'it writes to a table on your site',
		'shortcode'     => 'it stores a shortcode output on your site',
		'post'          => 'it creates the post on your site',
		'html'          => 'the HTML is stored on your site',
		'file'          => 'the file is written on your site',
		'googleSheets'  => 'it uses the Google connection stored on your site',
		'googleDrive'   => 'it uses the Google connection stored on your site',
		'google_sheets' => 'it uses the Google connection stored on your site',
	);

	/** Reason clauses for types whose manifest locus is `site` outright. */
	const STATIC_SITE_REASONS = array(
		'parser'      => 'it reads documents from your media library',
		'createFile'  => 'it writes into your media library',
		'generatePdf' => 'your site collects the rendered file',
		'chat'        => 'the chat widget and its sessions live on your site',
		'post'        => 'it creates the post on your site',
		'sendEmail'   => "sends through your site's own mail setup",
	);

	/* ---------------------------------------------------------------------------
	 * Locus - the single implementation of where a node runs
	 * ------------------------------------------------------------------------- */

	/**
	 * Where one node runs, and why. The static locus comes from the node manifest;
	 * an `either` type is resolved here in a fixed order: a configuration that
	 * needs this site wins, then a provider the platform holds a key for, then the
	 * site's own key.
	 *
	 * @param array $node One node from the stored workflow.
	 * @return array{locus:string,reason:string,rule:string,label:string,siteStepMode:string,type:string}
	 */
	public static function node_locus( array $node ) {
		$type = isset( $node['type'] ) ? (string) $node['type'] : '';
		$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		$manifest = self::manifest( $type );
		$static   = isset( $manifest['locus'] ) ? (string) $manifest['locus'] : '';
		$mode     = isset( $manifest['siteStepMode'] ) ? (string) $manifest['siteStepMode'] : 'sync';

		if ( 'output' === $type ) {
			return self::output_locus( $type, $data, $mode );
		}

		if ( self::LOCUS_CLOUD === $static ) {
			return self::locus( self::LOCUS_CLOUD, '', 'static', $type, $mode );
		}

		if ( self::LOCUS_SITE === $static ) {
			$reason = isset( self::STATIC_SITE_REASONS[ $type ] ) ? self::STATIC_SITE_REASONS[ $type ] : '';
			if ( 'post' === $type ) {
				$reason = self::post_setting_reason( $data );
			}
			return self::locus( self::LOCUS_SITE, $reason, 'static', $type, $mode );
		}

		// Rule 1: a setting on this instance needs the site.
		$setting_reason = self::site_setting_reason( $type, $data );
		if ( '' !== $setting_reason ) {
			return self::locus( self::LOCUS_SITE, $setting_reason, 'config', $type, $mode );
		}

		// Rule 2 and rule 3: the provider key decides.
		$provider = isset( self::NODE_PROVIDERS[ $type ] ) ? self::NODE_PROVIDERS[ $type ] : '';
		if ( '' !== $provider && ! in_array( $provider, self::PLATFORM_PROVIDER_KEYS, true ) ) {
			return self::locus( self::LOCUS_SITE, 'your site holds the key for this step', 'key', $type, $mode );
		}

		return self::locus( self::LOCUS_CLOUD, '', 'default', $type, $mode );
	}

	/**
	 * Locus for an `output` node, which is decided by its `outputType`, including
	 * the aliases only workflows saved by older versions carry.
	 *
	 * @param string $type Node type.
	 * @param array  $data Node data.
	 * @param string $mode Manifest site-step mode.
	 * @return array
	 */
	private static function output_locus( $type, array $data, $mode ) {
		$output_type = isset( $data['outputType'] ) && '' !== $data['outputType'] ? (string) $data['outputType'] : 'display';

		if ( in_array( $output_type, self::OUTPUT_ASYNC_TYPES, true ) ) {
			$mode = 'async';
		}

		$locus = isset( self::OUTPUT_TYPE_LOCUS[ $output_type ] ) ? self::OUTPUT_TYPE_LOCUS[ $output_type ] : '';

		if ( self::LOCUS_SITE === $locus ) {
			$reason = isset( self::OUTPUT_TYPE_REASONS[ $output_type ] ) ? self::OUTPUT_TYPE_REASONS[ $output_type ] : 'it writes to your site';
			if ( 'post' === $output_type ) {
				$reason = self::post_setting_reason( $data );
			}
			return self::locus( self::LOCUS_SITE, $reason, 'config', $type, $mode );
		}

		if ( 'either' === $locus ) {
			// A webhook aimed at a private address can only be delivered by the site.
			if ( self::targets_private_host( isset( $data['webhookUrl'] ) ? (string) $data['webhookUrl'] : '' ) ) {
				return self::locus( self::LOCUS_SITE, 'the target address is only reachable from your site', 'config', $type, $mode );
			}
			return self::locus( self::LOCUS_CLOUD, '', 'default', $type, $mode );
		}

		if ( self::LOCUS_CLOUD === $locus ) {
			return self::locus( self::LOCUS_CLOUD, '', 'static', $type, $mode );
		}

		// An outputType no version of the builder has ever written. Fail closed to
		// the site, which can run every output type this plugin implements.
		return self::locus( self::LOCUS_SITE, 'it writes to your site', 'config', $type, $mode );
	}

	/**
	 * Rule 1 of the `either` resolution: a per-instance setting that needs this
	 * site's data, state, credentials or a side effect on it. Returns the reason
	 * clause the builder shows after the label, or '' when nothing forces the site.
	 *
	 * @param string $type Node type.
	 * @param array  $data Node data.
	 * @return string
	 */
	private static function site_setting_reason( $type, array $data ) {
		// A workflow saved before the per-node key selector was retired can still
		// carry it; honour it rather than silently metering the run.
		if ( isset( $data['keySource'] ) && 'byok' === (string) $data['keySource'] ) {
			return 'it is set to use your own key';
		}

		switch ( $type ) {
			case 'aiModel':
				$kb = isset( $data['knowledgeBase'] ) && is_array( $data['knowledgeBase'] ) ? $data['knowledgeBase'] : array();
				if ( ! empty( $kb['enabled'] ) && ! empty( $kb['kbId'] ) ) {
					return 'it reads a Knowledge Base stored on your site';
				}
				return '';

			case 'firecrawl':
				$operation = isset( $data['operation'] ) ? (string) $data['operation'] : '';
				if ( '' !== $operation && ! in_array( $operation, array( 'scrape', 'crawl' ), true ) ) {
					return 'map, search and agent run on your site';
				}
				return '';

			case 'humanInput':
				$assigned = ( 'role' === ( isset( $data['assignmentType'] ) ? $data['assignmentType'] : '' ) )
					? ( isset( $data['selectedRole'] ) ? $data['selectedRole'] : '' )
					: ( isset( $data['selectedUser'] ) ? $data['selectedUser'] : '' );
				if ( ! empty( $assigned ) ) {
					return 'the task is assigned to a WordPress user or role';
				}
				return '';

			case 'APICall':
				if ( self::targets_private_host( isset( $data['url'] ) ? (string) $data['url'] : '' ) ) {
					return 'the target address is only reachable from your site';
				}
				return '';
		}

		return '';
	}

	/**
	 * Whether a configured URL names an address the cloud can never reach. A URL
	 * still carrying a variable tag is not judged here: its host is not known
	 * until the run.
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function targets_private_host( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || false !== strpos( $url, '[' ) || false !== strpos( $url, '{' ) ) {
			return false;
		}
		$host = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url, PHP_URL_HOST )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this file is unit tested without WordPress loaded.
			: parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		if ( class_exists( 'WP_AI_Workflows_Platform_Client' ) ) {
			return WP_AI_Workflows_Platform_Client::is_host_cloud_unreachable( $host );
		}
		return false;
	}

	/**
	 * Assemble a locus answer, including the label the builder renders.
	 *
	 * @param string $locus
	 * @param string $reason
	 * @param string $rule
	 * @param string $type
	 * @param string $mode
	 * @return array
	 */
	private static function locus( $locus, $reason, $rule, $type, $mode ) {
		$rendered = ( self::LOCUS_SITE === $locus ) && in_array( $type, self::RENDERED_IN_CLOUD_TYPES, true );

		// A trigger starts the run rather than doing work, so the builder shows it
		// no locus badge at all. Its locus/wireLocus are untouched: dispatch and
		// the allSite count still rely on them.
		if ( 'trigger' === $type ) {
			$label = '';
		} elseif ( self::LOCUS_SITE === $locus ) {
			$label = $rendered ? self::LABEL_RENDERED : self::LABEL_SITE;
		} else {
			$label = self::LABEL_CLOUD;
		}

		return array(
			'type'         => $type,
			'locus'        => $locus,
			// What the platform's own enum calls this step. `hybrid` is dispatched
			// exactly like `site`; only the label differs.
			'wireLocus'    => $rendered ? self::LOCUS_HYBRID : $locus,
			'reason'       => $reason,
			'rule'         => $rule,
			'label'        => $label,
			'siteStepMode' => ( 'async' === $mode ) ? 'async' : 'sync',
		);
	}

	/**
	 * The manifest for a node type, or an empty array when the catalog is not
	 * loadable.
	 *
	 * @param string $type
	 * @return array
	 */
	private static function manifest( $type ) {
		if ( '' === $type || ! class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			return array();
		}
		$manifest = WP_AI_Workflows_Node_Catalog::get( $type );
		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * The four refusals a Cloud run still cannot get past, computed over the whole
	 * graph. Site reachability is checked by the caller, which knows this site's
	 * own URL.
	 *
	 * @param array $nodes
	 * @param array $edges
	 * @return array<int,array{id:string,type:string,reason:string}>
	 */
	public static function workflow_refusals( array $nodes, array $edges ) {
		$refusals = array();
		$targets  = array();

		foreach ( $edges as $edge ) {
			if ( is_array( $edge ) && isset( $edge['target'] ) ) {
				$targets[ (string) $edge['target'] ] = true;
			}
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id   = isset( $node['id'] ) ? (string) $node['id'] : '';
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';

			if ( 'chat' === $type && ! isset( $targets[ $id ] ) ) {
				$refusals[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => 'A chat workflow answers a visitor live on your site, so it runs on your site.',
				);
			}
		}

		return $refusals;
	}

	/**
	 * The execution plan the builder renders: one entry per executable node, plus
	 * the workflow-level split, credit estimate and refusals.
	 *
	 * @param array $nodes
	 * @param array $edges
	 * @return array
	 */
	public static function execution_plan( array $nodes, array $edges ) {
		$plan        = array();
		$cloud_steps = 0;
		$site_steps   = 0;
		$hybrid_steps = 0;
		$credits      = self::BASE_CREDITS;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';
			if ( in_array( $type, self::ANNOTATION_TYPES, true ) ) {
				continue;
			}

			$entry       = self::node_locus( $node );
			$entry['id'] = isset( $node['id'] ) ? (string) $node['id'] : '';

			if ( self::LOCUS_SITE === $entry['locus'] ) {
				++$site_steps;
				if ( isset( $entry['wireLocus'] ) && self::LOCUS_HYBRID === $entry['wireLocus'] ) {
					++$hybrid_steps;
				}
				// The render is charged in either mode.
				if ( 'generatePdf' === $type ) {
					$entry['credits'] = self::PDF_RENDER_CREDITS;
					$credits         += self::PDF_RENDER_CREDITS;
				} else {
					$entry['credits'] = 0;
				}
			} else {
				$manifest         = self::manifest( $type );
				$entry['credits'] = isset( $manifest['credits'] ) ? (int) $manifest['credits'] : 0;
				$credits         += $entry['credits'];
				// The trigger is the event that starts the run, not a step that does
				// work, so it is never counted as a cloud step. The platform's own
				// estimate counts it the same way.
				if ( 'trigger' !== $type ) {
					++$cloud_steps;
				}
			}

			$plan[] = $entry;
		}

		$summary = array(
			'cloudSteps' => $cloud_steps,
			'siteSteps'  => $site_steps,
			'credits'    => $credits,
			// allSite drives one message: Local would do exactly this for free. A
			// hybrid step is metered by the render service it calls in either mode,
			// so a workflow containing one is not that case.
			'allSite'    => ( 0 === $cloud_steps && $site_steps > 0 && 0 === $hybrid_steps ),
		);
		$summary['line'] = self::summary_line( $summary );

		return array(
			'nodes'    => $plan,
			// The same set the submit path refuses on, so the builder never offers
			// a Cloud run the submit would turn away.
			'refusals' => self::get_unsupported_nodes( $nodes, $edges ),
			'summary'  => $summary,
		);
	}

	/**
	 * The one line shown before a Cloud run. A workflow with nothing to do in the
	 * cloud says so plainly, and points at the mode that costs nothing.
	 *
	 * @param array $summary
	 * @return string
	 */
	public static function summary_line( array $summary ) {
		$cloud   = (int) $summary['cloudSteps'];
		$site    = (int) $summary['siteSteps'];
		$credits = (int) $summary['credits'];

		if ( 0 === $cloud && 0 === $site ) {
			return 'Nothing to run yet.';
		}
		if ( ! empty( $summary['allSite'] ) ) {
			return 'Every step runs on your site. Local mode would do the same without using a credit.';
		}

		if ( 0 === $cloud ) {
			$line = self::plural( $site, 'step' ) . ' on your site';
			return $line . '. About ' . self::plural( $credits, 'credit' ) . '.';
		}

		$line = self::plural( $cloud, 'step' ) . ' in the cloud';
		if ( $site > 0 ) {
			$line .= ', ' . $site . ' on your site';
		}

		return $line . '. About ' . self::plural( $credits, 'credit' ) . '.';
	}

	/**
	 * "1 step" / "6 steps".
	 *
	 * @param int    $count
	 * @param string $noun
	 * @return string
	 */
	private static function plural( $count, $noun ) {
		return $count . ' ' . $noun . ( 1 === (int) $count ? '' : 's' );
	}

	/**
	 * Translate a stored workflow blob into a cloud definition, or return a typed
	 * error naming every node that blocks Cloud.
	 *
	 * @param array $workflow Decoded workflow JSON (['nodes'=>[], 'edges'=>[], ...]).
	 * @param array $opts     {emit?: 'siteStep'|'wpAction'} Emission target for
	 *                        site-locus nodes. `wpAction` is the pre-2.0.9 shape,
	 *                        kept for one release.
	 * @return array{definition:array{nodes:array,edges:array}}|WP_AI_Workflows_Translation_Error
	 */
	public static function translate( array $workflow, array $opts = array() ) {
		$nodes = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array();
		$edges = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

		$emit_wp_action = isset( $opts['emit'] ) && 'wpAction' === $opts['emit'];

		$kept_ids     = array();   // original id => true, for edge filtering
		$stripped_ids = array();   // annotation ids to drop edges for
		$out_nodes    = array();
		$offending    = self::workflow_refusals( $nodes, $edges );

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

			// Step 2: where does this node run?
			$plan = self::node_locus( $node );

			if ( self::LOCUS_SITE === $plan['locus'] ) {
				$assembled = self::build_site_node( $node, $plan, $emit_wp_action );
				if ( null === $assembled ) {
					$offending[] = array(
						'id'     => $id,
						'type'   => $type,
						'reason' => 'This node type is not supported in Cloud and must run Locally.',
					);
					continue;
				}
				if ( isset( $node['position'] ) ) {
					$assembled['position'] = $node['position'];
				}
				$out_nodes[]     = $assembled;
				$kept_ids[ $id ] = true;
				continue;
			}

			// Step 3: a cloud-locus node needs a supported engine type.
			$class = self::classify_node( $node );
			if ( ! $class['supported'] ) {
				$offending[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $class['reason'],
				);
				continue;
			}

			// Step 4: strict data allow-list. Returns mapped data or a reason string
			// if a required field is missing / the contract is unverified.
			$data = self::filter_node_data( $class['engineType'], isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array() );
			if ( is_string( $data ) ) {
				$offending[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $data,
				);
				continue;
			}

			// Step 5: assemble node, preserving id + position, remapped type.
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

		$definition = array(
			'nodes' => $out_nodes,
			'edges' => $out_edges,
		);

		// Names the run on the platform, and the human tasks it asks this site for.
		$name = isset( $workflow['name'] ) && is_scalar( $workflow['name'] ) ? trim( (string) $workflow['name'] ) : '';
		if ( '' !== $name ) {
			$definition['name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 191 ) : substr( $name, 0, 191 );
		}

		return array( 'definition' => $definition );
	}

	/**
	 * Advisory pre-check used by the REST layer for the builder: the nodes that
	 * genuinely block a Cloud run. From 2.0.9 a node that needs the site is
	 * labelled, not refused, so only the workflow-level refusals and a node with a
	 * missing required field appear here. Annotations are never reported.
	 *
	 * @param array $nodes Node array from the stored workflow.
	 * @param array $edges Edge array, needed for the chat-trigger refusal.
	 * @return array<int,array{id:string,type:string,reason:string}>
	 */
	public static function get_unsupported_nodes( array $nodes, array $edges = array() ) {
		$unsupported = self::workflow_refusals( $nodes, $edges );
		$refused_ids = array();
		foreach ( $unsupported as $refusal ) {
			$refused_ids[ $refusal['id'] ] = true;
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id   = isset( $node['id'] ) ? (string) $node['id'] : '';
			$type = isset( $node['type'] ) ? (string) $node['type'] : '';
			if ( in_array( $type, self::ANNOTATION_TYPES, true ) || isset( $refused_ids[ $id ] ) ) {
				continue;
			}

			$plan = self::node_locus( $node );
			if ( self::LOCUS_SITE === $plan['locus'] ) {
				continue;
			}

			$class = self::classify_node( $node );
			if ( ! $class['supported'] ) {
				$unsupported[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $class['reason'],
				);
				continue;
			}

			$data = self::filter_node_data( $class['engineType'], isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array() );
			if ( is_string( $data ) ) {
				$unsupported[] = array(
					'id'     => $id,
					'type'   => $type,
					'reason' => $data,
				);
			}
		}

		return $unsupported;
	}

	/**
	 * Build the emitted node for a site-locus node: a `siteStep` carrying the raw
	 * configuration the site's own engine resolves, or, on the legacy emission
	 * path, the narrow `wpAction` shape 2.0.8 sent.
	 *
	 * @param array $node
	 * @param array $plan           node_locus() answer for this node.
	 * @param bool  $emit_wp_action Use the pre-2.0.9 emission shape.
	 * @return array|null Assembled node, or null when no shape fits.
	 */
	private static function build_site_node( array $node, array $plan, $emit_wp_action ) {
		$id   = isset( $node['id'] ) ? (string) $node['id'] : '';
		$type = isset( $node['type'] ) ? (string) $node['type'] : '';
		$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		if ( $emit_wp_action ) {
			$output_type = isset( $data['outputType'] ) ? (string) $data['outputType'] : '';
			$wp_action   = ( 'post' === $type || 'post' === $output_type || in_array( $output_type, self::SAVE_OUTPUT_TYPES, true ) );
			if ( $wp_action ) {
				return array(
					'id'   => $id,
					'type' => 'wpAction',
					'data' => self::build_wp_action_data( $node ),
				);
			}
			return null;
		}

		return array(
			'id'   => $id,
			'type' => self::SITE_STEP_TYPE,
			'data' => array(
				'nodeType'     => $type,
				'config'       => self::site_step_config( $data ),
				'siteStepMode' => $plan['siteStepMode'],
				'locus'        => $plan['wireLocus'],
				'locusReason'  => $plan['reason'],
			),
		);
	}

	/**
	 * The configuration a site step carries: the node's own data with its variable
	 * tags intact, so the site's engine resolves them exactly as a Local run does.
	 * Provider keys and local routing hints are removed at every depth.
	 *
	 * @param array $data
	 * @return array
	 */
	private static function site_step_config( array $data ) {
		return self::strip_secret_fields( $data );
	}

	/**
	 * Remove provider keys and local-only routing hints at every depth. Nothing
	 * the plugin emits for Cloud may carry a key, whichever side runs the node.
	 *
	 * @param array $data
	 * @return array
	 */
	private static function strip_secret_fields( array $data ) {
		$strip = array_merge( self::PROVIDER_KEY_FIELDS, self::LOCAL_ROUTING_FIELDS );

		$walk = static function ( $value ) use ( &$walk, $strip ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && in_array( $key, $strip, true ) ) {
					continue;
				}
				$out[ $key ] = $walk( $item );
			}
			return $out;
		};

		return $walk( $data );
	}

	/**
	 * Classify a cloud-locus node: under which engine type does it run. Applies the
	 * identifier remap and fails closed on anything unrecognised.
	 *
	 * @param array $node
	 * @return array{supported:bool,engineType:string,reason:string}
	 */
	private static function classify_node( array $node ) {
		$type        = isset( $node['type'] ) ? (string) $node['type'] : '';
		$engine_type = isset( self::TYPE_REMAP[ $type ] ) ? self::TYPE_REMAP[ $type ] : $type;

		if ( 'output' === $engine_type ) {
			$output_type = isset( $node['data']['outputType'] ) ? (string) $node['data']['outputType'] : '';
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
	 * Why a post node runs on the site, as the clause the builder shows after the
	 * label. Every post node runs on the site; the settings only decide which
	 * sentence the customer reads.
	 *
	 * @param array $data Node data.
	 * @return string
	 */
	private static function post_setting_reason( array $data ) {
		$mappings = isset( $data['fieldMappings'] ) && is_array( $data['fieldMappings'] ) ? $data['fieldMappings'] : array();
		$parts    = array();

		if ( ! empty( $data['selectedAuthor'] ) ) {
			$parts[] = 'the author';
		}
		if ( ! empty( $data['selectedCategories'] ) ) {
			$parts[] = 'categories';
		}

		// The builder always carries a featuredImage shape; only a url or an
		// attachment id means the node really sets one.
		$featured     = isset( $data['featuredImage'] ) ? $data['featuredImage'] : '';
		$has_featured = is_array( $featured )
			? ( ! empty( $featured['url'] ) || ! empty( $featured['id'] ) )
			: '' !== trim( (string) $featured );
		if ( $has_featured ) {
			$parts[] = 'a featured image';
		}

		if ( ! empty( $data['productImages'] ) ) {
			$parts[] = 'product images';
		}

		foreach ( $mappings as $field => $value ) {
			if ( 0 === strpos( (string) $field, 'acf_' ) && '' !== trim( (string) $value ) ) {
				$parts[] = 'ACF fields';
				break;
			}
		}

		if ( 'future' === ( isset( $data['postStatus'] ) ? (string) $data['postStatus'] : '' ) ) {
			$parts[] = 'a future publish date';
		}

		if ( empty( $parts ) ) {
			return 'it creates the post on your site';
		}

		return 'it sets ' . self::join_clauses( $parts );
	}

	/**
	 * Join clauses into a readable list: "a", "a and b", "a, b and c".
	 *
	 * @param array<int,string> $parts
	 * @return string
	 */
	private static function join_clauses( array $parts ) {
		$parts = array_values( array_unique( $parts ) );
		$count = count( $parts );
		if ( $count <= 1 ) {
			return $count ? $parts[0] : '';
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ' and ' . $last;
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
			// Builder + LoopNodeV4.ts. The loop body is found from the edges leaving
			// the `iteration` handle.
			'loop'               => array(
				'required' => array(),
				'optional' => array(
					'loopType', 'content', 'maxIterations', 'outputMode', 'continueOnError', 'nodeName',
					'iterationCount', 'whileInput', 'whileComparison', 'whileValue',
				),
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
		// routing hint (keySource) - both are stripped for Cloud, at every depth.
		$out = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data )
				&& ! in_array( $field, self::PROVIDER_KEY_FIELDS, true )
				&& ! in_array( $field, self::LOCAL_ROUTING_FIELDS, true ) ) {
				$out[ $field ] = $data[ $field ];
			}
		}
		return self::strip_secret_fields( $out );
	}
}
