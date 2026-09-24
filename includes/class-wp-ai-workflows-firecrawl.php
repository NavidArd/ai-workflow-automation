<?php
/**
 * Firecrawl v2 API client.
 *
 * Wraps the Firecrawl v2 REST API (https://docs.firecrawl.dev/api-reference/v2-introduction).
 * Every request is authenticated with `Authorization: Bearer <key>` and is sent ONLY to the
 * fixed Firecrawl API host — the user-supplied target URL / query is passed as a request-body
 * parameter for Firecrawl to fetch, never used as the request endpoint (SSRF-safe: this plugin
 * never dereferences an arbitrary user URL server-side).
 *
 * Operations:
 *   - scrape  -> POST /v2/scrape           single URL -> markdown/html/links/json/question/highlights
 *   - crawl   -> POST /v2/crawl (+ poll)   async site crawl (submit -> poll GET /v2/crawl/{id})
 *   - map     -> POST /v2/map              fast URL discovery
 *   - search  -> POST /v2/search           web/news/images search + optional page content
 *   - agent   -> POST /v2/agent            structured extraction (replaces the DEPRECATED /extract)
 *
 * All public entrypoints accept a flat, already-normalized params array (legacy v0/v1 node
 * shapes are normalized upstream in the executor via normalize_legacy_data()) and never throw:
 * failures return a WP_Error so a node can fail gracefully without a fatal.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_FIRECRAWL_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Firecrawl {

	/** Fixed Firecrawl v2 API base. The ONLY host this client ever contacts. */
	private $api_base = 'https://api.firecrawl.dev/v2/';

	/** @var string|null Lazily-resolved API key. */
	private $api_key = null;

	/** Simple string formats passed straight through to the v2 `formats` array. */
	private $simple_formats = array( 'markdown', 'html', 'rawHtml', 'links', 'summary', 'highlights' );

	private function get_api_key() {
		if ( null === $this->api_key ) {
			$this->api_key = WP_AI_Workflows_Utilities::get_firecrawl_api_key();
		}
		return $this->api_key;
	}

	/**
	 * Allow tests to inject the API base + key (kept locked in production). Only usable
	 * when the unit-test constant is defined so production behaviour is unchanged.
	 *
	 * @param string      $base
	 * @param string|null $key
	 */
	public function set_test_transport( $base, $key = 'fc-test-key' ) {
		if ( defined( 'WP_AI_WORKFLOWS_FIRECRAWL_TEST' ) && WP_AI_WORKFLOWS_FIRECRAWL_TEST ) {
			$this->api_base = rtrim( $base, '/' ) . '/';
			$this->api_key  = $key;
		}
	}

	/* ---------------------------------------------------------------------
	 * Operation entrypoints
	 * ------------------------------------------------------------------- */

	/**
	 * Scrape a single URL.
	 *
	 * @param array $params { url, formats[], onlyMainContent, waitFor, timeout, mobile,
	 *                        jsonPrompt, jsonSchema, question, includeTags, excludeTags }
	 * @return array|WP_Error
	 */
	public function scrape( $params ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'params' => $params ) );

		$url = $this->sanitize_target_url( $params['url'] ?? '' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$body        = $this->build_scrape_options( $params );
		$body['url'] = $url;

		$response = $this->make_request( 'scrape', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

		return array(
			'status'    => 'success',
			'operation' => 'scrape',
			'url'       => $url,
			'content'   => $this->primary_text_from_scrape( $data ),
			'data'      => $data,
		);
	}

	/**
	 * Crawl a whole site (async job -> poll to completion).
	 *
	 * @param array $params { url, limit, includePaths[], excludePaths[], crawlEntireDomain,
	 *                        sitemap, maxDiscoveryDepth, scrapeFormats[], onlyMainContent }
	 * @return array|WP_Error
	 */
	public function crawl( $params ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'params' => $params ) );

		$url = $this->sanitize_target_url( $params['url'] ?? '' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$body = array( 'url' => $url );

		if ( isset( $params['limit'] ) && '' !== $params['limit'] ) {
			$body['limit'] = max( 1, (int) $params['limit'] );
		}
		$include = $this->clean_string_list( $params['includePaths'] ?? array() );
		if ( ! empty( $include ) ) {
			$body['includePaths'] = $include;
		}
		$exclude = $this->clean_string_list( $params['excludePaths'] ?? array() );
		if ( ! empty( $exclude ) ) {
			$body['excludePaths'] = $exclude;
		}
		if ( ! empty( $params['crawlEntireDomain'] ) ) {
			$body['crawlEntireDomain'] = true;
		}
		if ( ! empty( $params['sitemap'] ) && in_array( $params['sitemap'], array( 'include', 'skip', 'only' ), true ) ) {
			$body['sitemap'] = $params['sitemap'];
		}
		if ( ! empty( $params['maxDiscoveryDepth'] ) ) {
			$body['maxDiscoveryDepth'] = max( 1, (int) $params['maxDiscoveryDepth'] );
		}

		$scrape_opts = $this->build_scrape_options(
			array(
				'formats'         => $params['scrapeFormats'] ?? array( 'markdown' ),
				'onlyMainContent' => $params['onlyMainContent'] ?? true,
				'waitFor'         => $params['waitFor'] ?? 0,
			)
		);
		$body['scrapeOptions'] = $scrape_opts;

		$submit = $this->make_request( 'crawl', $body );
		if ( is_wp_error( $submit ) ) {
			return $submit;
		}

		$crawl_id = $submit['id'] ?? ( $submit['jobId'] ?? null );
		if ( empty( $crawl_id ) ) {
			return new WP_Error( 'firecrawl_unexpected_response', 'Unexpected response from Firecrawl crawl submit (no job id).' );
		}

		$result = $this->poll_crawl_status( $crawl_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pages   = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$results = array();
		$blocks  = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$content   = $this->primary_text_from_scrape( $page );
			$meta      = isset( $page['metadata'] ) && is_array( $page['metadata'] ) ? $page['metadata'] : array();
			$page_url  = $meta['sourceURL'] ?? ( $meta['url'] ?? ( $page['url'] ?? '' ) );
			$results[] = array(
				'url'           => $page_url,
				'content'       => $content,
				'contentLength' => strlen( $content ),
				'timestamp'     => gmdate( 'c' ),
				'metadata'      => $meta,
			);
			if ( '' !== $content ) {
				$blocks[] = ( '' !== $page_url ? "# {$page_url}\n\n" : '' ) . $content;
			}
		}

		return array(
			'status'    => 'success',
			'operation' => 'crawl',
			'url'       => $url,
			'content'   => implode( "\n\n---\n\n", $blocks ),
			'results'   => $results,
			'stats'     => array(
				'completed' => (int) ( $result['completed'] ?? count( $results ) ),
				'total'     => (int) ( $result['total'] ?? count( $results ) ),
			),
			'data'      => $result,
		);
	}

	/**
	 * Map a site: fast URL discovery.
	 *
	 * @param array $params { url, search, limit, includeSubdomains, sitemap }
	 * @return array|WP_Error
	 */
	public function map( $params ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'params' => $params ) );

		$url = $this->sanitize_target_url( $params['url'] ?? '' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$body = array( 'url' => $url );
		if ( ! empty( $params['search'] ) ) {
			$body['search'] = sanitize_text_field( $params['search'] );
		}
		if ( isset( $params['limit'] ) && '' !== $params['limit'] ) {
			$body['limit'] = max( 1, (int) $params['limit'] );
		}
		if ( ! empty( $params['includeSubdomains'] ) ) {
			$body['includeSubdomains'] = true;
		}
		if ( ! empty( $params['sitemap'] ) && in_array( $params['sitemap'], array( 'include', 'skip', 'only' ), true ) ) {
			$body['sitemap'] = $params['sitemap'];
		}

		$response = $this->make_request( 'map', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_links = array();
		if ( isset( $response['links'] ) && is_array( $response['links'] ) ) {
			$raw_links = $response['links'];
		} elseif ( isset( $response['data']['links'] ) && is_array( $response['data']['links'] ) ) {
			$raw_links = $response['data']['links'];
		}

		$links = array();
		foreach ( $raw_links as $link ) {
			if ( is_string( $link ) ) {
				$links[] = array( 'url' => $link );
			} elseif ( is_array( $link ) && isset( $link['url'] ) ) {
				$links[] = array(
					'url'         => $link['url'],
					'title'       => $link['title'] ?? '',
					'description' => $link['description'] ?? '',
				);
			}
		}

		$url_list = array_map(
			function ( $l ) {
				return $l['url'];
			},
			$links
		);

		return array(
			'status'    => 'success',
			'operation' => 'map',
			'url'       => $url,
			'content'   => implode( "\n", $url_list ),
			'links'     => $links,
			'data'      => $response,
		);
	}

	/**
	 * Web search (+ optional page content).
	 *
	 * @param array $params { query, sources[], categories[], limit, scrapeFormats[], location }
	 * @return array|WP_Error
	 */
	public function search( $params ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'params' => $params ) );

		$query = isset( $params['query'] ) ? trim( (string) $params['query'] ) : '';
		if ( '' === $query ) {
			return new WP_Error( 'firecrawl_missing_query', 'A search query is required.' );
		}

		$body = array( 'query' => $query );

		$sources = $this->clean_string_list( $params['sources'] ?? array() );
		$sources = array_values( array_intersect( $sources, array( 'web', 'news', 'images' ) ) );
		if ( ! empty( $sources ) ) {
			$body['sources'] = $sources;
		}
		$categories = $this->clean_string_list( $params['categories'] ?? array() );
		if ( ! empty( $categories ) ) {
			$body['categories'] = $categories;
		}
		if ( isset( $params['limit'] ) && '' !== $params['limit'] ) {
			$body['limit'] = max( 1, (int) $params['limit'] );
		}
		if ( ! empty( $params['location'] ) ) {
			$body['location'] = sanitize_text_field( $params['location'] );
		}
		if ( ! empty( $params['scrapeFormats'] ) ) {
			$scrape_opts = $this->build_scrape_options(
				array(
					'formats'         => $params['scrapeFormats'],
					'onlyMainContent' => $params['onlyMainContent'] ?? true,
				)
			);
			$body['scrapeOptions'] = $scrape_opts;
		}

		$response = $this->make_request( 'search', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data    = isset( $response['data'] ) ? $response['data'] : $response;
		$results = array();

		// v2 groups results by source (web/news/images); flatten into a single list.
		if ( is_array( $data ) ) {
			$groups = array();
			if ( isset( $data['web'] ) || isset( $data['news'] ) || isset( $data['images'] ) ) {
				foreach ( array( 'web', 'news', 'images' ) as $src ) {
					if ( isset( $data[ $src ] ) && is_array( $data[ $src ] ) ) {
						$groups[ $src ] = $data[ $src ];
					}
				}
			} else {
				$groups['web'] = $data; // Flat array fallback.
			}
			foreach ( $groups as $src => $items ) {
				foreach ( (array) $items as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$results[] = array(
						'source'      => $src,
						'url'         => $item['url'] ?? '',
						'title'       => $item['title'] ?? '',
						'description' => $item['description'] ?? ( $item['snippet'] ?? '' ),
						'content'     => $this->primary_text_from_scrape( $item ),
					);
				}
			}
		}

		$lines = array();
		foreach ( $results as $r ) {
			$lines[] = trim( ( $r['title'] ? $r['title'] . ' — ' : '' ) . $r['url'] . ( $r['description'] ? "\n" . $r['description'] : '' ) );
		}

		return array(
			'status'    => 'success',
			'operation' => 'search',
			'query'     => $query,
			'content'   => implode( "\n\n", $lines ),
			'results'   => $results,
			'data'      => $response,
		);
	}

	/**
	 * Agent extraction — the v2 replacement for the deprecated /extract endpoint.
	 * Structured extraction from a prompt (+ optional schema and/or seed URLs).
	 *
	 * @param array $params { prompt, schema (JSON-schema array), urls[] }
	 * @return array|WP_Error
	 */
	public function agent( $params ) {
		WP_AI_Workflows_Utilities::debug_function( __FUNCTION__, array( 'params' => $params ) );

		$prompt = isset( $params['prompt'] ) ? trim( (string) $params['prompt'] ) : '';

		if ( '' === $prompt && empty( $params['schema'] ) ) {
			return new WP_Error( 'firecrawl_missing_prompt', 'An extraction prompt or schema is required for the Agent operation.' );
		}

		$body = array();
		if ( '' !== $prompt ) {
			$body['prompt'] = $prompt;
		}
		if ( ! empty( $params['schema'] ) && is_array( $params['schema'] ) ) {
			$body['schema'] = $params['schema'];
		}

		$urls = $this->clean_string_list( $params['urls'] ?? array() );
		if ( ! empty( $urls ) ) {
			$sanitized = array();
			foreach ( $urls as $u ) {
				$clean = $this->sanitize_target_url( $u );
				if ( ! is_wp_error( $clean ) ) {
					$sanitized[] = $clean;
				}
			}
			if ( ! empty( $sanitized ) ) {
				$body['urls'] = $sanitized;
			}
		}

		$response = $this->make_request( 'agent', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$extracted = $response['data'] ?? ( $response['json'] ?? $response );

		return array(
			'status'    => 'success',
			'operation' => 'agent',
			'content'   => is_scalar( $extracted ) ? (string) $extracted : wp_json_encode( $extracted ),
			'data'      => $extracted,
			'raw'       => $response,
		);
	}

	/* ---------------------------------------------------------------------
	 * Builders / helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Assemble the shared v2 scrape options block (also used inside crawl/search).
	 *
	 * @param array $opts
	 * @return array
	 */
	private function build_scrape_options( array $opts ) {
		$body = array(
			'formats'         => $this->build_formats( $opts['formats'] ?? array( 'markdown' ), $opts ),
			'onlyMainContent' => isset( $opts['onlyMainContent'] ) ? (bool) $opts['onlyMainContent'] : true,
		);

		if ( isset( $opts['waitFor'] ) && (int) $opts['waitFor'] > 0 ) {
			$body['waitFor'] = (int) $opts['waitFor'];
		}
		if ( isset( $opts['timeout'] ) && (int) $opts['timeout'] > 0 ) {
			$body['timeout'] = (int) $opts['timeout'];
		}
		if ( ! empty( $opts['mobile'] ) ) {
			$body['mobile'] = true;
		}
		$include = $this->clean_string_list( $opts['includeTags'] ?? array() );
		if ( ! empty( $include ) ) {
			$body['includeTags'] = $include;
		}
		$exclude = $this->clean_string_list( $opts['excludeTags'] ?? array() );
		if ( ! empty( $exclude ) ) {
			$body['excludeTags'] = $exclude;
		}

		return $body;
	}

	/**
	 * Build the v2 `formats` array. Simple formats pass through as strings; the
	 * structured formats (json, question, screenshot) become objects carrying their
	 * prompt/schema. Falls back to ["markdown"] when nothing valid is selected.
	 *
	 * @param mixed $formats
	 * @param array $opts
	 * @return array
	 */
	private function build_formats( $formats, array $opts ) {
		$formats = $this->clean_string_list( $formats );
		$out     = array();

		foreach ( $formats as $f ) {
			if ( in_array( $f, $this->simple_formats, true ) ) {
				$out[] = $f;
				continue;
			}
			if ( 'json' === $f ) {
				$obj = array( 'type' => 'json' );
				if ( ! empty( $opts['jsonPrompt'] ) ) {
					$obj['prompt'] = (string) $opts['jsonPrompt'];
				}
				if ( ! empty( $opts['jsonSchema'] ) && is_array( $opts['jsonSchema'] ) ) {
					$obj['schema'] = $opts['jsonSchema'];
				}
				$out[] = $obj;
				continue;
			}
			if ( 'question' === $f ) {
				$obj = array( 'type' => 'question' );
				if ( ! empty( $opts['question'] ) ) {
					$obj['prompt'] = (string) $opts['question'];
				}
				$out[] = $obj;
				continue;
			}
			if ( 'screenshot' === $f ) {
				$out[] = array( 'type' => 'screenshot' );
				continue;
			}
		}

		if ( empty( $out ) ) {
			$out[] = 'markdown';
		}
		return $out;
	}

	/**
	 * Pick the best human/AI-usable text from a v2 scrape data object.
	 *
	 * @param mixed $data
	 * @return string
	 */
	private function primary_text_from_scrape( $data ) {
		if ( ! is_array( $data ) ) {
			return is_scalar( $data ) ? (string) $data : '';
		}
		if ( ! empty( $data['markdown'] ) ) {
			return (string) $data['markdown'];
		}
		if ( ! empty( $data['summary'] ) ) {
			return (string) $data['summary'];
		}
		if ( ! empty( $data['content'] ) && is_string( $data['content'] ) ) {
			return $data['content'];
		}
		if ( isset( $data['json'] ) ) {
			return wp_json_encode( $data['json'] );
		}
		if ( isset( $data['question'] ) ) {
			return is_scalar( $data['question'] ) ? (string) $data['question'] : wp_json_encode( $data['question'] );
		}
		if ( ! empty( $data['html'] ) ) {
			return (string) $data['html'];
		}
		if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
			return implode( "\n", $data['links'] );
		}
		return '';
	}

	private function clean_string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$v = trim( $v );
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Validate and normalize a user-supplied target URL (the thing Firecrawl will
	 * fetch). Only http/https are allowed. This value is a request-body parameter,
	 * never an endpoint, so it is not an SSRF vector — this is defence-in-depth / UX.
	 *
	 * @param string $url
	 * @return string|WP_Error
	 */
	private function sanitize_target_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'firecrawl_missing_url', 'A target URL is required.' );
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean ) {
			return new WP_Error( 'firecrawl_invalid_url', 'The target URL is not a valid http(s) URL.' );
		}
		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Crawl polling
	 * ------------------------------------------------------------------- */

	/**
	 * Poll a crawl job to completion. Capped so a runaway crawl can never hang a
	 * request forever.
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	private function poll_crawl_status( $crawl_id ) {
		$max_attempts = 120; // ~120 * 1s = 2 min ceiling.
		$delay        = 1;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$result = $this->get_crawl_results( $crawl_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$status = $result['status'] ?? '';
			if ( 'completed' === $status ) {
				return $result;
			}
			if ( 'failed' === $status || 'error' === $status || 'cancelled' === $status ) {
				return new WP_Error(
					'firecrawl_crawl_failed',
					'Crawl operation failed: ' . ( $result['error'] ?? $status )
				);
			}

			if ( $attempt < $max_attempts - 1 ) {
				sleep( $delay );
			}
		}

		return new WP_Error(
			'firecrawl_crawl_timeout',
			'Crawl operation timed out after ' . ( $max_attempts * $delay ) . ' seconds.'
		);
	}

	/**
	 * Fetch crawl job status/results. Kept public: the REST progress endpoint uses it.
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public function get_crawl_results( $crawl_id ) {
		$crawl_id = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $crawl_id );
		if ( '' === $crawl_id ) {
			return new WP_Error( 'firecrawl_invalid_job', 'Invalid crawl job id.' );
		}
		return $this->make_request( 'crawl/' . $crawl_id, null, 'GET' );
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------- */

	/**
	 * Perform a request against the fixed Firecrawl API host.
	 *
	 * @param string     $path   Path relative to the v2 base (e.g. 'scrape', 'crawl/abc').
	 * @param array|null $params JSON body for POST.
	 * @param string     $method HTTP method.
	 * @return array|WP_Error
	 */
	private function make_request( $path, $params = null, $method = 'POST' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'firecrawl_api_key_missing', 'Firecrawl API key is not set.' );
		}

		$endpoint = $this->api_base . ltrim( $path, '/' );

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'timeout' => 60,
			'method'  => $method,
		);

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $endpoint, $args );
		} else {
			if ( null !== $params ) {
				$args['body'] = wp_json_encode( $params );
			}
			$response = wp_remote_post( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Firecrawl API request failed',
				'error',
				array( 'error' => $response->get_error_message() )
			);
			return new WP_Error( 'firecrawl_api_wp_error', $response->get_error_message() );
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );

		$data = json_decode( $raw_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_AI_Workflows_Utilities::debug_log(
				'Firecrawl JSON decode error',
				'error',
				array( 'body' => $raw_body )
			);
			return new WP_Error( 'firecrawl_json_error', 'Failed to decode Firecrawl API response.' );
		}

		if ( $response_code < 200 || $response_code >= 300 ) {
			$error_message = 'Unknown error';
			if ( is_array( $data ) ) {
				$error_message = $data['error'] ?? ( $data['message'] ?? $error_message );
				if ( is_array( $error_message ) ) {
					$error_message = wp_json_encode( $error_message );
				}
			}
			WP_AI_Workflows_Utilities::debug_log(
				'Firecrawl API error',
				'error',
				array(
					'status_code' => $response_code,
					'error'       => $error_message,
				)
			);
			return new WP_Error( 'firecrawl_api_error', $error_message, array( 'status' => $response_code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
