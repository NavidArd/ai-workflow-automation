<?php
/**
 * Dynamic AI model catalog.
 *
 * Fetches the list of available AI models (with per-token pricing) from the
 * provider APIs, merges them, and caches the result in a transient. When the
 * remote fetch fails it falls back to a hard-coded list built from the live
 * OpenRouter catalog so the builder UI and validator always have something
 * sensible to work with.
 *
 * Routing rule used across the plugin: a model id containing "/" routes to
 * OpenRouter; a bare id (no slash) routes to the native OpenAI API.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Model_Catalog {

	/**
	 * Transient key for the merged catalog.
	 */
	const TRANSIENT_KEY = 'wp_ai_workflows_model_catalog';

	/**
	 * Cache lifetime for the merged catalog (~12 hours).
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Current default model.
	 *
	 * The cheap OpenAI "workhorse" tier. Verified present on the live
	 * OpenRouter catalog as "openai/gpt-5-mini"; the bare id below routes to
	 * the native OpenAI API. Used only when NO model is specified.
	 */
	const DEFAULT_MODEL = 'gpt-5-mini';

	/**
	 * Model the plugin uses for its OWN reasoning - the workflow generator and
	 * the Workflow Sage assistant both "think" with this model via OpenRouter.
	 *
	 * This is NOT the model assigned to the AI nodes a user builds; it is the
	 * brain that plans/edits workflows. Kept as a single, well-named constant so
	 * bumping it to the next-gen model is a one-line change. Verified present on
	 * the live OpenRouter catalog as "anthropic/claude-sonnet-5" (July 2026).
	 */
	const REASONING_MODEL = 'anthropic/claude-sonnet-5';

	/**
	 * Resolve the model used for the plugin's own AI reasoning (generator +
	 * assistant). Filterable so site owners can pin a different current model
	 * without editing code.
	 *
	 * @return string OpenRouter model id.
	 */
	public static function get_reasoning_model() {
		/**
		 * Filter the model the plugin uses to generate and edit workflows.
		 *
		 * @param string $model OpenRouter model id (contains "/").
		 */
		$model = apply_filters( 'wp_ai_workflows_reasoning_model', self::REASONING_MODEL );

		return is_string( $model ) && '' !== trim( $model ) ? trim( $model ) : self::REASONING_MODEL;
	}

	/**
	 * Get the merged model catalog.
	 *
	 * @param bool $force Bypass the cache and re-fetch from the providers.
	 * @return array {
	 *     @type array  $data       List of model entries (OpenRouter schema shape).
	 *     @type string $source     One of 'cache', 'live', 'partial', 'fallback'.
	 *     @type int    $fetched_at Unix timestamp of the fetch.
	 * }
	 */
	public static function get_catalog( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && ! empty( $cached['data'] ) ) {
				$cached['source'] = 'cache';
				return $cached;
			}
		}

		$entries    = array();
		$sources_ok = array();

		// 1) OpenRouter - public, no key required, includes per-token pricing.
		$openrouter = self::fetch_openrouter_models();
		if ( is_array( $openrouter ) && ! empty( $openrouter ) ) {
			$entries          = array_merge( $entries, $openrouter );
			$sources_ok[]     = 'openrouter';
		}

		// 2) OpenAI - only when the user has configured a key. The /v1/models
		//    endpoint does not return pricing, so we decorate known ids from the
		//    static pricing map and leave the rest priced null.
		$openai = self::fetch_openai_models();
		if ( is_array( $openai ) && ! empty( $openai ) ) {
			$entries      = array_merge( $entries, $openai );
			$sources_ok[] = 'openai';
		}

		if ( empty( $entries ) ) {
			// Everything failed - serve the hard-coded fallback so the UI and the
			// validator still work. Cache it briefly so a transient outage does
			// not hammer the provider APIs on every request.
			$catalog = array(
				'data'       => self::get_fallback_models(),
				'source'     => 'fallback',
				'fetched_at' => time(),
			);
			set_transient( self::TRANSIENT_KEY, $catalog, HOUR_IN_SECONDS );

			WP_AI_Workflows_Utilities::debug_log(
				'Model catalog: all remote fetches failed, using hard-coded fallback',
				'warning',
				array( 'model_count' => count( $catalog['data'] ) )
			);

			return $catalog;
		}

		$entries = self::deduplicate( $entries );

		$catalog = array(
			'data'       => $entries,
			'source'     => ( count( $sources_ok ) > 1 ) ? 'live' : 'partial',
			'sources'    => $sources_ok,
			'fetched_at' => time(),
		);

		set_transient( self::TRANSIENT_KEY, $catalog, self::CACHE_TTL );

		WP_AI_Workflows_Utilities::debug_log(
			'Model catalog refreshed',
			'info',
			array(
				'model_count' => count( $entries ),
				'sources'     => $sources_ok,
			)
		);

		return $catalog;
	}

	/**
	 * Fetch and normalize models from the public OpenRouter catalog.
	 *
	 * @return array|false Normalized entries, or false on failure.
	 */
	private static function fetch_openrouter_models() {
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
			WP_AI_Workflows_Utilities::debug_log(
				'Model catalog: OpenRouter fetch failed',
				'error',
				array( 'error' => $response->get_error_message() )
			);
			return false;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Model catalog: OpenRouter returned non-200',
				'error',
				array( 'status' => wp_remote_retrieve_response_code( $response ) )
			);
			return false;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Model catalog: OpenRouter response could not be parsed', 'error' );
			return false;
		}

		$entries = array();
		foreach ( $decoded['data'] as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}
			$entries[] = array(
				'id'             => $model['id'],
				'name'           => isset( $model['name'] ) ? $model['name'] : $model['id'],
				'description'    => isset( $model['description'] ) ? $model['description'] : '',
				'context_length' => isset( $model['context_length'] ) ? (int) $model['context_length'] : 4096,
				'pricing'        => isset( $model['pricing'] ) ? $model['pricing'] : null,
				'route'          => 'openrouter',
			);
		}

		return $entries;
	}

	/**
	 * Fetch and normalize models from the native OpenAI API.
	 *
	 * Degrades gracefully: returns false when no OpenAI key is configured or the
	 * request fails, so the catalog still serves OpenRouter + fallback ids.
	 *
	 * @return array|false Normalized entries, or false when unavailable.
	 */
	private static function fetch_openai_models() {
		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( empty( $api_key ) ) {
			// No key: this is expected, not an error.
			return false;
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Model catalog: OpenAI fetch failed',
				'error',
				array( 'error' => $response->get_error_message() )
			);
			return false;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Model catalog: OpenAI returned non-200',
				'error',
				array( 'status' => wp_remote_retrieve_response_code( $response ) )
			);
			return false;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			WP_AI_Workflows_Utilities::debug_log( 'Model catalog: OpenAI response could not be parsed', 'error' );
			return false;
		}

		$pricing_map = self::get_openai_pricing_map();
		$entries     = array();

		foreach ( $decoded['data'] as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}
			$id = $model['id'];

			// Only surface chat/completion-capable families as selectable models.
			if ( 0 !== strpos( $id, 'gpt-' ) && 0 !== strpos( $id, 'o1' ) && 0 !== strpos( $id, 'o3' ) && 0 !== strpos( $id, 'o4' ) ) {
				continue;
			}

			$pricing = isset( $pricing_map[ $id ] ) ? $pricing_map[ $id ] : null;

			$entries[] = array(
				'id'             => $id,
				'name'           => $id,
				'description'    => '',
				'context_length' => self::guess_openai_context( $id ),
				'pricing'        => $pricing,
				'route'          => 'openai',
			);
		}

		return $entries;
	}

	/**
	 * Merge duplicate ids, preferring the entry that carries pricing.
	 *
	 * @param array $entries Raw merged entries.
	 * @return array
	 */
	private static function deduplicate( $entries ) {
		$by_id = array();
		foreach ( $entries as $entry ) {
			$id = $entry['id'];
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = $entry;
				continue;
			}
			// Keep whichever has pricing.
			if ( empty( $by_id[ $id ]['pricing'] ) && ! empty( $entry['pricing'] ) ) {
				$by_id[ $id ] = $entry;
			}
		}
		return array_values( $by_id );
	}

	/**
	 * Determine whether a model id is present in the current catalog.
	 *
	 * Non-fatal helper used by the permissive validator for a debug note only.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public static function is_known_model( $model ) {
		if ( empty( $model ) ) {
			return false;
		}
		$catalog = self::get_catalog();
		foreach ( $catalog['data'] as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $model ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get per-million-token pricing for a model from the catalog when known.
	 *
	 * @param string $model Model id.
	 * @return array|null { 'input' => float, 'output' => float } per 1M tokens, or null.
	 */
	public static function get_model_pricing( $model ) {
		if ( empty( $model ) ) {
			return null;
		}
		$catalog = self::get_catalog();
		foreach ( $catalog['data'] as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $model && ! empty( $entry['pricing'] ) ) {
				$prompt     = isset( $entry['pricing']['prompt'] ) ? (float) $entry['pricing']['prompt'] : 0;
				$completion = isset( $entry['pricing']['completion'] ) ? (float) $entry['pricing']['completion'] : 0;
				if ( $prompt > 0 || $completion > 0 ) {
					return array(
						'input'  => $prompt * 1000000,
						'output' => $completion * 1000000,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Current default model id (cheap OpenAI workhorse). Bare id -> native OpenAI.
	 *
	 * @return string
	 */
	public static function get_default_model() {
		return self::DEFAULT_MODEL;
	}

	/**
	 * Clear the cached catalog.
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Static per-million-token pricing for bare OpenAI ids.
	 *
	 * Values are per-token (matching the OpenRouter schema) so the same code path
	 * consumes them. Grounded against the live OpenRouter catalog (July 2026).
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function get_openai_pricing_map() {
		return array(
			'gpt-5.5'     => array( 'prompt' => '0.000005', 'completion' => '0.00003' ),
			'gpt-5.1'     => array( 'prompt' => '0.00000125', 'completion' => '0.00001' ),
			'gpt-5'       => array( 'prompt' => '0.00000125', 'completion' => '0.00001' ),
			'gpt-5-mini'  => array( 'prompt' => '0.00000025', 'completion' => '0.000002' ),
			'gpt-5-nano'  => array( 'prompt' => '0.00000005', 'completion' => '0.0000004' ),
			'gpt-4.1'     => array( 'prompt' => '0.000002', 'completion' => '0.000008' ),
			'gpt-4.1-mini' => array( 'prompt' => '0.0000004', 'completion' => '0.0000016' ),
			'gpt-4o'      => array( 'prompt' => '0.0000025', 'completion' => '0.00001' ),
			'gpt-4o-mini' => array( 'prompt' => '0.00000015', 'completion' => '0.0000006' ),
			'o3'          => array( 'prompt' => '0.000002', 'completion' => '0.000008' ),
			'o4-mini'     => array( 'prompt' => '0.0000011', 'completion' => '0.0000044' ),
		);
	}

	/**
	 * Reasonable context-length defaults for OpenAI ids (the /v1/models endpoint
	 * does not report context windows).
	 *
	 * @param string $id Model id.
	 * @return int
	 */
	private static function guess_openai_context( $id ) {
		if ( false !== strpos( $id, 'gpt-5' ) ) {
			return 400000;
		}
		if ( false !== strpos( $id, 'gpt-4.1' ) ) {
			return 1000000;
		}
		if ( false !== strpos( $id, 'gpt-4' ) ) {
			return 128000;
		}
		if ( 0 === strpos( $id, 'o1' ) || 0 === strpos( $id, 'o3' ) || 0 === strpos( $id, 'o4' ) ) {
			return 200000;
		}
		return 16384;
	}

	/**
	 * Hard-coded fallback catalog, grounded against the live OpenRouter catalog
	 * (fetched July 2026). ~15 current models across OpenAI / Anthropic / Google /
	 * Meta / DeepSeek, plus current bare OpenAI ids (native routing).
	 *
	 * @return array
	 */
	public static function get_fallback_models() {
		$p = function ( $prompt, $completion ) {
			return array(
				'prompt'     => (string) $prompt,
				'completion' => (string) $completion,
			);
		};

		return array(
			// --- Bare OpenAI ids (route to native OpenAI API) ---
			array(
				'id'             => 'gpt-5.5',
				'name'           => 'GPT-5.5',
				'description'    => 'OpenAI flagship',
				'context_length' => 400000,
				'pricing'        => $p( '0.000005', '0.00003' ),
				'route'          => 'openai',
			),
			array(
				'id'             => 'gpt-5.1',
				'name'           => 'GPT-5.1',
				'description'    => 'OpenAI high-intelligence',
				'context_length' => 400000,
				'pricing'        => $p( '0.00000125', '0.00001' ),
				'route'          => 'openai',
			),
			array(
				'id'             => 'gpt-5-mini',
				'name'           => 'GPT-5 Mini',
				'description'    => 'Affordable, fast workhorse',
				'context_length' => 400000,
				'pricing'        => $p( '0.00000025', '0.000002' ),
				'route'          => 'openai',
			),
			array(
				'id'             => 'gpt-5-nano',
				'name'           => 'GPT-5 Nano',
				'description'    => 'Cheapest OpenAI tier',
				'context_length' => 400000,
				'pricing'        => $p( '0.00000005', '0.0000004' ),
				'route'          => 'openai',
			),
			array(
				'id'             => 'gpt-4.1',
				'name'           => 'GPT-4.1',
				'description'    => 'Long-context 1M window',
				'context_length' => 1000000,
				'pricing'        => $p( '0.000002', '0.000008' ),
				'route'          => 'openai',
			),
			array(
				'id'             => 'gpt-4o-mini',
				'name'           => 'GPT-4o Mini (legacy)',
				'description'    => 'Retained for back-compat',
				'context_length' => 128000,
				'pricing'        => $p( '0.00000015', '0.0000006' ),
				'route'          => 'openai',
			),

			// --- OpenRouter ids (contain "/") ---
			array(
				'id'             => 'openai/gpt-5.5',
				'name'           => 'GPT-5.5 (via OpenRouter)',
				'description'    => '',
				'context_length' => 400000,
				'pricing'        => $p( '0.000005', '0.00003' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'anthropic/claude-opus-4.8',
				'name'           => 'Claude Opus 4.8',
				'description'    => 'Anthropic flagship',
				'context_length' => 200000,
				'pricing'        => $p( '0.000005', '0.000025' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'anthropic/claude-sonnet-5',
				'name'           => 'Claude Sonnet 5',
				'description'    => 'Balanced Anthropic model',
				'context_length' => 200000,
				'pricing'        => $p( '0.000002', '0.00001' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'anthropic/claude-haiku-4.5',
				'name'           => 'Claude Haiku 4.5',
				'description'    => 'Fast, cheap Anthropic model',
				'context_length' => 200000,
				'pricing'        => $p( '0.000001', '0.000005' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'google/gemini-3-flash-preview',
				'name'           => 'Gemini 3 Flash',
				'description'    => 'Google fast multimodal',
				'context_length' => 1000000,
				'pricing'        => $p( '0.0000005', '0.000003' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'google/gemini-2.5-pro',
				'name'           => 'Gemini 2.5 Pro',
				'description'    => 'Google high-intelligence',
				'context_length' => 1000000,
				'pricing'        => $p( '0.00000125', '0.00001' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'meta-llama/llama-4-maverick',
				'name'           => 'Llama 4 Maverick',
				'description'    => 'Meta open model',
				'context_length' => 1000000,
				'pricing'        => $p( '0.00000015', '0.0000006' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'deepseek/deepseek-v3.2',
				'name'           => 'DeepSeek V3.2',
				'description'    => 'DeepSeek general model',
				'context_length' => 163840,
				'pricing'        => $p( '0.0000002288', '0.0000003432' ),
				'route'          => 'openrouter',
			),
			array(
				'id'             => 'deepseek/deepseek-r1',
				'name'           => 'DeepSeek R1',
				'description'    => 'DeepSeek reasoning model',
				'context_length' => 128000,
				'pricing'        => $p( '0.0000007', '0.0000025' ),
				'route'          => 'openrouter',
			),
		);
	}
}
