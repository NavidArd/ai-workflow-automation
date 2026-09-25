<?php
/**
 * Supabase (pgvector) Knowledge Base.
 *
 * Self-hosted RAG store backed by a Supabase Postgres database with the
 * `pgvector` extension. Handles content chunking, embedding (OpenAI
 * `text-embedding-3-small`), vector upsert via Supabase's PostgREST endpoint,
 * and cosine similarity search via the `match_documents` RPC. Retrieved chunks
 * are formatted into a context preamble that the AI Model node and the chat
 * handler inject into the prompt (opt-in per node / per chat).
 *
 * Supabase is the ONLY vector-DB provider. The abstraction is deliberately
 * small so a future provider could slot in behind the same public surface
 * (is_configured / ingest_text / search / retrieve_context), but no other
 * provider ships today.
 *
 * One-time owner setup (run once in the Supabase SQL editor) is documented in
 * {@see WP_AI_Workflows_Knowledge_Base::get_setup_sql()} and surfaced in the UI.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Knowledge_Base {

	/** Embedding model + dimension (kept consistent across ingest + query). */
	const EMBEDDING_MODEL = 'text-embedding-3-small';
	const EMBEDDING_DIM   = 1536;

	/** Default Supabase table + chunking defaults (chars). */
	const DEFAULT_TABLE = 'wpaw_documents';
	const CHUNK_SIZE    = 1000;
	const CHUNK_OVERLAP = 200;

	/** Max chunks embedded in a single OpenAI request. */
	const EMBED_BATCH = 64;

	/** @var string Local KB registry table (names + counts, NOT the vectors). */
	private $kb_table;

	public function __construct() {
		global $wpdb;
		$this->kb_table = $wpdb->prefix . 'wp_ai_workflows_knowledge_bases';
		$this->ensure_table_exists();
	}

	/**
	 * Self-heal: the registry table can be missing on installs where
	 * WP_AI_Workflows_Database::create_tables() had already recorded the
	 * current plugin version (as a stored option) before this table was
	 * added to that version-gated migration, so its
	 * `CREATE TABLE IF NOT EXISTS` never ran for existing installs.
	 *
	 * Cheap existence check via SHOW TABLES, cached in a transient so it
	 * only hits the database once per day instead of on every request.
	 */
	private function ensure_table_exists() {
		if ( false !== get_transient( 'wp_ai_workflows_kb_table_ok' ) ) {
			return;
		}

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->kb_table ) );

		if ( $exists !== $this->kb_table ) {
			$this->create_tables();
		}

		set_transient( 'wp_ai_workflows_kb_table_ok', 1, DAY_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the Supabase connection config from plugin settings.
	 *
	 * @return array{url:string,key:string,table:string}
	 */
	public static function get_config() {
		$settings = get_option( 'wp_ai_workflows_settings', array() );

		$url = isset( $settings['supabase_url'] ) ? esc_url_raw( trim( (string) $settings['supabase_url'] ) ) : '';
		$url = untrailingslashit( $url );

		$table = isset( $settings['supabase_table'] ) ? (string) $settings['supabase_table'] : '';
		// Postgres identifiers: letters, digits, underscore only.
		$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		if ( '' === $table ) {
			$table = self::DEFAULT_TABLE;
		}

		return array(
			'url'   => $url,
			'key'   => WP_AI_Workflows_Utilities::get_supabase_key(),
			'table' => $table,
		);
	}

	/**
	 * True when a Supabase project URL + service key are configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$cfg = self::get_config();
		return '' !== $cfg['url'] && '' !== $cfg['key'];
	}

	/**
	 * The one-time SQL the owner runs in Supabase to enable pgvector + the
	 * matching function. Table name is interpolated from config; it is a
	 * validated identifier ([a-zA-Z0-9_]) so this is safe to display/run.
	 *
	 * @return string
	 */
	public static function get_setup_sql() {
		$cfg   = self::get_config();
		$table = $cfg['table'];
		$dim   = self::EMBEDDING_DIM;

		return implode(
			"\n",
			array(
				'-- Run once in the Supabase SQL editor.',
				'create extension if not exists vector;',
				'',
				"create table if not exists {$table} (",
				'    id         bigserial primary key,',
				'    kb_id      text not null,',
				'    content    text not null,',
				"    metadata   jsonb default '{}'::jsonb,",
				"    embedding  vector({$dim}),",
				'    created_at timestamptz default now()',
				');',
				'',
				"create index if not exists {$table}_kb_id_idx on {$table} (kb_id);",
				"create index if not exists {$table}_embedding_idx",
				"    on {$table} using ivfflat (embedding vector_cosine_ops) with (lists = 100);",
				'',
				'create or replace function match_documents(',
				"    query_embedding vector({$dim}),",
				'    match_count int default 5,',
				'    filter_kb_id text default null',
				') returns table (',
				'    id bigint,',
				'    kb_id text,',
				'    content text,',
				'    metadata jsonb,',
				'    similarity float',
				') language sql stable as $$',
				'    select id, kb_id, content, metadata,',
				'           1 - (embedding <=> query_embedding) as similarity',
				"    from {$table}",
				'    where filter_kb_id is null or kb_id = filter_kb_id',
				'    order by embedding <=> query_embedding',
				'    limit match_count;',
				'$$;',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Local registry table
	 * ------------------------------------------------------------------- */

	/**
	 * Create the local KB registry table (names + doc counts). The vectors
	 * themselves live in Supabase, not WordPress.
	 */
	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = $wpdb->prepare(
			"CREATE TABLE IF NOT EXISTS %i (
				id VARCHAR(64) NOT NULL,
				name VARCHAR(255) NOT NULL,
				description TEXT,
				doc_count INT DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id)
			) " . $charset_collate, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is passed as %i; charset comes from $wpdb->get_charset_collate().
			$this->kb_table
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * List all knowledge bases with their document (chunk) counts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_all_kbs() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i ORDER BY created_at DESC", $this->kb_table ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $row ) {
				$row['doc_count'] = (int) $row['doc_count'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * Create a knowledge base (a logical collection keyed by kb_id in Supabase).
	 *
	 * @param string $name        Human name.
	 * @param string $description Optional description.
	 * @return array The new KB row.
	 * @throws Exception On validation or DB failure.
	 */
	public function create_kb( $name, $description = '' ) {
		global $wpdb;

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			throw new Exception( 'A knowledge base name is required.' );
		}

		$id = 'kb_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );

		$result = $wpdb->insert(
			$this->kb_table,
			array(
				'id'          => $id,
				'name'        => $name,
				'description' => sanitize_textarea_field( $description ),
				'doc_count'   => 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			throw new Exception( 'Failed to save the knowledge base.' );
		}

		return array(
			'id'          => $id,
			'name'        => $name,
			'description' => $description,
			'doc_count'   => 0,
		);
	}

	/**
	 * Delete a knowledge base: removes its vectors from Supabase, then the
	 * local registry row. Supabase deletion errors are logged, not fatal.
	 *
	 * @param string $kb_id
	 * @return bool
	 * @throws Exception When the KB does not exist locally.
	 */
	public function delete_kb( $kb_id ) {
		global $wpdb;

		$kb_id = sanitize_text_field( $kb_id );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id = %s", $this->kb_table, $kb_id ) );
		if ( ! $exists ) {
			throw new Exception( 'Knowledge base not found.' );
		}

		// Best-effort remove vectors from Supabase.
		if ( self::is_configured() ) {
			try {
				self::supabase_delete_kb_rows( $kb_id );
			} catch ( Exception $e ) {
				WP_AI_Workflows_Utilities::debug_log(
					'Knowledge base Supabase deletion warning',
					'warning',
					array( 'kb_id' => $kb_id, 'error' => $e->getMessage() )
				);
			}
		}

		$wpdb->delete( $this->kb_table, array( 'id' => $kb_id ), array( '%s' ) );

		return true;
	}

	/**
	 * Increment the stored document (chunk) count for a KB.
	 *
	 * @param string $kb_id
	 * @param int    $delta
	 */
	private function bump_doc_count( $kb_id, $delta ) {
		global $wpdb;
		// %i table + %d/%s bound params - no interpolation.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET doc_count = doc_count + %d WHERE id = %s",
				$this->kb_table,
				(int) $delta,
				$kb_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Ingestion
	 * ------------------------------------------------------------------- */

	/**
	 * Chunk free text into overlapping windows on word boundaries.
	 *
	 * @param string $text
	 * @param int    $size    Target chunk length (chars).
	 * @param int    $overlap Overlap between consecutive chunks (chars).
	 * @return array<int,string>
	 */
	public static function chunk_text( $text, $size = self::CHUNK_SIZE, $overlap = self::CHUNK_OVERLAP ) {
		$text = wp_strip_all_tags( (string) $text );
		// Collapse runs of spaces/tabs but preserve newlines as soft structure.
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = trim( preg_replace( "/\n{3,}/u", "\n\n", $text ) );

		if ( '' === $text ) {
			return array();
		}

		$size    = max( 100, (int) $size );
		$overlap = max( 0, min( (int) $overlap, $size - 50 ) );
		$len     = mb_strlen( $text );

		if ( $len <= $size ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;

		while ( $start < $len ) {
			$chunk = mb_substr( $text, $start, $size );

			// Snap the end back to the last space so we don't split a word,
			// unless we're at the final chunk.
			if ( $start + $size < $len ) {
				$last_space = mb_strrpos( $chunk, ' ' );
				if ( false !== $last_space && $last_space > (int) ( $size * 0.5 ) ) {
					$chunk = mb_substr( $chunk, 0, $last_space );
				}
			}

			$chunk = trim( $chunk );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}

			$advance = mb_strlen( $chunk ) - $overlap;
			if ( $advance < 1 ) {
				$advance = max( 1, mb_strlen( $chunk ) );
			}
			$start += $advance;
		}

		return $chunks;
	}

	/**
	 * Embed one or more texts with the configured embedding model.
	 *
	 * @param array<int,string> $texts
	 * @return array<int,array<int,float>> Vectors, index-aligned with $texts.
	 * @throws Exception On missing key or API failure.
	 */
	public static function embed_texts( array $texts ) {
		$texts = array_values( array_filter( array_map( 'strval', $texts ), static function ( $t ) {
			return '' !== trim( $t );
		} ) );

		if ( empty( $texts ) ) {
			return array();
		}

		$api_key = WP_AI_Workflows_Utilities::get_openai_api_key();
		if ( empty( $api_key ) ) {
			throw new Exception( 'An OpenAI API key is required to generate embeddings. Add one under Settings.' );
		}

		$vectors = array();

		foreach ( array_chunk( $texts, self::EMBED_BATCH ) as $batch ) {
			$response = wp_remote_post(
				'https://api.openai.com/v1/embeddings',
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model' => self::EMBEDDING_MODEL,
							'input' => $batch,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'Embedding request failed: ' . esc_html( $response->get_error_message() ) );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code >= 400 ) {
				$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown embedding error';
				throw new Exception( 'Embedding API error: ' . esc_html( $msg ) );
			}

			if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
				throw new Exception( 'Embedding API returned no data.' );
			}

			// Preserve request order via the returned index.
			$ordered = array();
			foreach ( $body['data'] as $item ) {
				$ordered[ (int) $item['index'] ] = $item['embedding'];
			}
			ksort( $ordered );
			foreach ( $ordered as $vec ) {
				$vectors[] = $vec;
			}
		}

		return $vectors;
	}

	/**
	 * Ingest a block of text: chunk -> embed -> store in Supabase.
	 *
	 * @param string $kb_id
	 * @param string $content
	 * @param array  $metadata Extra metadata stored alongside each chunk.
	 * @return array{added:int} Number of chunks stored.
	 * @throws Exception On configuration, embedding or storage failure.
	 */
	public function ingest_text( $kb_id, $content, $metadata = array() ) {
		if ( ! self::is_configured() ) {
			throw new Exception( 'Supabase is not configured. Add your project URL and API key first.' );
		}

		$kb_id = sanitize_text_field( $kb_id );
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id = %s", $this->kb_table, $kb_id ) );
		if ( ! $exists ) {
			throw new Exception( 'Knowledge base not found.' );
		}

		$chunks = self::chunk_text( $content );
		if ( empty( $chunks ) ) {
			throw new Exception( 'No text content to ingest.' );
		}

		$vectors = self::embed_texts( $chunks );
		if ( count( $vectors ) !== count( $chunks ) ) {
			throw new Exception( 'Embedding count mismatch; nothing was stored.' );
		}

		$rows = array();
		foreach ( $chunks as $i => $chunk ) {
			$rows[] = array(
				'kb_id'     => $kb_id,
				'content'   => $chunk,
				'metadata'  => (object) $metadata,
				// pgvector canonical text input: '[f,f,...]'. Unambiguous via PostgREST.
				'embedding' => '[' . implode( ',', array_map( array( __CLASS__, 'format_float' ), $vectors[ $i ] ) ) . ']',
			);
		}

		self::supabase_insert_rows( $rows );
		$this->bump_doc_count( $kb_id, count( $rows ) );

		return array( 'added' => count( $rows ) );
	}

	/**
	 * Ingest WordPress posts (title + rendered content + light metadata).
	 *
	 * @param string $kb_id
	 * @param int[]  $post_ids
	 * @param string $post_type
	 * @return array{added_count:int,total_posts:int,errors:array}
	 * @throws Exception On configuration failure.
	 */
	public function ingest_wp_posts( $kb_id, $post_ids, $post_type = 'post' ) {
		if ( ! self::is_configured() ) {
			throw new Exception( 'Supabase is not configured. Add your project URL and API key first.' );
		}
		if ( ! post_type_exists( $post_type ) ) {
			throw new Exception( 'Invalid post type: ' . esc_html( $post_type ) );
		}

		$added_count = 0;
		$errors      = array();

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			try {
				$post = get_post( $post_id );
				if ( ! $post ) {
					$errors[] = "Post ID {$post_id} not found";
					continue;
				}

				$body    = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
				$content = '# ' . $post->post_title . "\n\n" . $body;

				$meta = array(
					'source'    => 'wordpress',
					'post_id'   => $post_id,
					'post_type' => $post->post_type,
					'title'     => $post->post_title,
					'url'       => get_permalink( $post_id ),
				);

				$result       = $this->ingest_text( $kb_id, $content, $meta );
				$added_count += (int) $result['added'];
			} catch ( Exception $e ) {
				$errors[] = "Error adding post ID {$post_id}: " . $e->getMessage();
				WP_AI_Workflows_Utilities::debug_log(
					'KB WP ingest error',
					'error',
					array( 'post_id' => $post_id, 'error' => $e->getMessage() )
				);
			}
		}

		return array(
			'success'     => true,
			'added_count' => $added_count,
			'total_posts' => count( (array) $post_ids ),
			'errors'      => $errors,
		);
	}

	/**
	 * Ingest an uploaded document file into a knowledge base.
	 *
	 * Plain-text formats (txt/text/md/markdown/csv) are read directly off disk;
	 * rich formats (pdf/doc/docx/rtf) are converted to text via the plugin's
	 * existing LlamaParse integration ({@see WP_AI_Workflows_Parser}). The
	 * extracted text is then run through the SAME pipeline as pasted text
	 * (chunk -> embed -> Supabase upsert), tagged with the source filename.
	 *
	 * The caller is responsible for validating the upload (MIME/extension
	 * allowlist, size cap, capability + nonce) and for deleting the file
	 * afterwards; this method only reads it.
	 *
	 * @param string $kb_id         Target knowledge base id.
	 * @param string $file_path     Absolute server path to the stored upload.
	 * @param string $file_url      Public URL of the stored upload (LlamaParse
	 *                              resolves it back to a local path).
	 * @param string $original_name Original client filename (metadata + extension).
	 * @return array{added:int} Number of chunks stored.
	 * @throws Exception On configuration, parsing, embedding or storage failure.
	 */
	public function ingest_file( $kb_id, $file_path, $file_url, $original_name ) {
		if ( ! self::is_configured() ) {
			throw new Exception( 'Supabase is not configured. Add your project URL and API key first.' );
		}

		$ext         = strtolower( pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );
		$plain_types = array( 'txt', 'text', 'md', 'markdown', 'csv' );

		if ( in_array( $ext, $plain_types, true ) ) {
			// Plain text: read straight off disk, no external parser needed.
			$text = self::read_text_file( $file_path );
		} else {
			// Rich formats (pdf, doc, docx, rtf) -> LlamaParse.
			if ( '' === (string) WP_AI_Workflows_Utilities::get_llamaparse_api_key() ) {
				throw new Exception(
					sprintf(
						'Parsing ".%s" files needs a LlamaParse API key. Add one under Settings, or upload a plain-text file (.txt, .md, .csv) instead.',
						esc_html( $ext )
					)
				);
			}

			// Parser settings mirror the defaults used by the Parser node; all
			// keys are supplied so the parser never touches an undefined index.
			$parser_settings = array(
				'language'            => 'en',
				'parsingInstructions' => '',
				'skipDiagonalText'    => false,
				'doNotUnrollColumns'  => false,
				'targetPages'         => '',
			);

			$parsed = WP_AI_Workflows_Parser::parse_document_with_llamaparse( $file_url, $parser_settings );
			if ( is_wp_error( $parsed ) ) {
				throw new Exception( 'Document parsing failed: ' . esc_html( $parsed->get_error_message() ) );
			}
			$text = (string) $parsed;
		}

		if ( '' === trim( (string) $text ) ) {
			throw new Exception( 'No readable text could be extracted from the file.' );
		}

		$metadata = array(
			'source'   => 'file',
			'filename' => sanitize_file_name( (string) $original_name ),
			'filetype' => $ext,
		);

		return $this->ingest_text( $kb_id, $text, $metadata );
	}

	/**
	 * Read a plain-text file off disk via WP_Filesystem.
	 *
	 * @param string $file_path Absolute server path.
	 * @return string File contents.
	 * @throws Exception When the file cannot be read.
	 */
	private static function read_text_file( $file_path ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$contents = $wp_filesystem ? $wp_filesystem->get_contents( $file_path ) : false;
		if ( false === $contents ) {
			throw new Exception( 'Could not read the uploaded file.' );
		}

		return (string) $contents;
	}

	/* ---------------------------------------------------------------------
	 * Retrieval (the RAG read path)
	 * ------------------------------------------------------------------- */

	/**
	 * Embed the query and run cosine similarity search via the match_documents
	 * RPC. Returns the top-K chunks with their similarity scores.
	 *
	 * @param string $kb_id  KB to filter by (empty = search all).
	 * @param string $query
	 * @param int    $top_k
	 * @return array<int,array{content:string,metadata:mixed,similarity:float}>
	 * @throws Exception On configuration / API failure.
	 */
	public static function search( $kb_id, $query, $top_k = 5 ) {
		if ( ! self::is_configured() ) {
			throw new Exception( 'Supabase is not configured.' );
		}

		$query = (string) $query;
		if ( '' === trim( $query ) ) {
			return array();
		}

		$vectors = self::embed_texts( array( $query ) );
		if ( empty( $vectors ) ) {
			return array();
		}
		$embedding = '[' . implode( ',', array_map( array( __CLASS__, 'format_float' ), $vectors[0] ) ) . ']';

		$cfg  = self::get_config();
		$url  = $cfg['url'] . '/rest/v1/rpc/match_documents';
		$body = array(
			'query_embedding' => $embedding,
			'match_count'     => max( 1, (int) $top_k ),
			'filter_kb_id'    => '' !== (string) $kb_id ? sanitize_text_field( $kb_id ) : null,
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'apikey'        => $cfg['key'],
					'Authorization' => 'Bearer ' . $cfg['key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Supabase search failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = isset( $decoded['message'] ) ? $decoded['message'] : 'Unknown error';
			throw new Exception( 'Supabase search error: ' . esc_html( $msg ) );
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $row ) {
			$out[] = array(
				'content'    => isset( $row['content'] ) ? (string) $row['content'] : '',
				'metadata'   => isset( $row['metadata'] ) ? $row['metadata'] : array(),
				'similarity' => isset( $row['similarity'] ) ? (float) $row['similarity'] : 0.0,
			);
		}

		return $out;
	}

	/**
	 * Retrieve top-K chunks and format them into a context preamble suitable
	 * for prepending to a prompt. Never throws - on any failure it logs and
	 * returns an empty string so the AI call proceeds unchanged (fail-open,
	 * opt-in behaviour: no KB context is simply no context).
	 *
	 * @param string $kb_id
	 * @param string $query
	 * @param int    $top_k
	 * @return string Context preamble, or '' when nothing is retrieved.
	 */
	public static function retrieve_context( $kb_id, $query, $top_k = 5 ) {
		try {
			$rows = self::search( $kb_id, $query, $top_k );
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log(
				'KB retrieval failed (continuing without context)',
				'warning',
				array( 'kb_id' => $kb_id, 'error' => $e->getMessage() )
			);
			return '';
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$context  = "Use the following knowledge base excerpts to answer the request. ";
		$context .= "If the answer is not contained in them, say so rather than guessing.\n\n";
		$context .= "--- KNOWLEDGE BASE ---\n";

		$i = 1;
		foreach ( $rows as $row ) {
			$snippet = trim( (string) $row['content'] );
			if ( '' === $snippet ) {
				continue;
			}
			$context .= '[' . $i . '] ' . $snippet . "\n\n";
			++$i;
		}
		$context .= "--- END KNOWLEDGE BASE ---";

		return rtrim( $context );
	}

	/* ---------------------------------------------------------------------
	 * Supabase PostgREST helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Insert vector rows into the configured Supabase table via PostgREST.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @throws Exception On HTTP or API error.
	 */
	private static function supabase_insert_rows( array $rows ) {
		$cfg = self::get_config();
		$url = $cfg['url'] . '/rest/v1/' . rawurlencode( $cfg['table'] );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'apikey'        => $cfg['key'],
					'Authorization' => 'Bearer ' . $cfg['key'],
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=minimal',
				),
				'body'    => wp_json_encode( $rows ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Supabase insert failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg     = isset( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
			throw new Exception( 'Supabase insert error: ' . esc_html( $msg ) );
		}
	}

	/**
	 * Delete all vector rows for a KB via PostgREST.
	 *
	 * @param string $kb_id
	 * @throws Exception On HTTP or API error.
	 */
	private static function supabase_delete_kb_rows( $kb_id ) {
		$cfg = self::get_config();
		$url = $cfg['url'] . '/rest/v1/' . rawurlencode( $cfg['table'] )
			. '?kb_id=eq.' . rawurlencode( $kb_id );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 45,
				'headers' => array(
					'apikey'        => $cfg['key'],
					'Authorization' => 'Bearer ' . $cfg['key'],
					'Prefer'        => 'return=minimal',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Supabase delete failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			throw new Exception( 'Supabase delete error: HTTP ' . (int) $code );
		}
	}

	/**
	 * Verify connectivity by calling the match_documents RPC with a zero vector.
	 * Returns true on success; throws with a clear message otherwise.
	 *
	 * @return bool
	 * @throws Exception
	 */
	public static function test_connection() {
		if ( ! self::is_configured() ) {
			throw new Exception( 'Add your Supabase project URL and API key first.' );
		}

		$cfg  = self::get_config();
		$url  = $cfg['url'] . '/rest/v1/rpc/match_documents';
		$zero = '[' . implode( ',', array_fill( 0, self::EMBEDDING_DIM, '0' ) ) . ']';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'apikey'        => $cfg['key'],
					'Authorization' => 'Bearer ' . $cfg['key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'query_embedding' => $zero,
						'match_count'     => 1,
						'filter_kb_id'    => null,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Could not reach Supabase: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			throw new Exception( 'Connected, but the match_documents function is missing. Run the setup SQL in Supabase.' );
		}
		if ( 401 === $code || 403 === $code ) {
			throw new Exception( 'Supabase rejected the API key. Check the service role / anon key.' );
		}
		if ( $code >= 400 ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg     = isset( $decoded['message'] ) ? $decoded['message'] : 'HTTP ' . $code;
			throw new Exception( 'Supabase error: ' . esc_html( $msg ) );
		}

		return true;
	}

	/**
	 * Compact float formatter for pgvector text literals (avoids locale commas
	 * and scientific notation surprises).
	 *
	 * @param float $f
	 * @return string
	 */
	public static function format_float( $f ) {
		return rtrim( rtrim( sprintf( '%.8F', (float) $f ), '0' ), '.' );
	}
}
