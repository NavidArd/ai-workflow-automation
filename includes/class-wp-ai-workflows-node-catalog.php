<?php
/**
 * WP_AI_Workflows_Node_Catalog - source-of-truth registry for node capability
 * manifests, loaded from includes/nodes/manifests/*.json and validated against
 * includes/nodes/manifest.schema.json (kept in sync with
 * frontend/src/components/nodes/nodeTypes.js and the executor switch in
 * class-wp-ai-workflows-node-execution.php).
 *
 * Pure PHP (WP calls guarded by function_exists()) so it can load before WP
 * boots; results are transient-cached and invalidated via a hash of the
 * manifest set. to_prompt_section() output is deterministically ordered to
 * stay a stable, cacheable generator-prompt prefix.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_NODE_CATALOG_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Node_Catalog {

	/**
	 * Bumped when the manifest schema or the catalog serialization changes so
	 * old transients are discarded even if manifest files are byte-identical.
	 */
	const VERSION = '1.1.0';

	/** Transient key prefix for the cached, validated catalog. */
	const CACHE_PREFIX = 'wpaw_node_catalog_';

	/**
	 * Deterministic palette/category ordering for to_prompt_section(). Categories
	 * not listed here sort last, alphabetically. Mirrors FloatingSidebar.js groups.
	 *
	 * @var string[]
	 */
	const CATEGORY_ORDER = array(
		'Core',
		'AI Actions',
		'Data Processing',
		'Content & Media',
		'Communication',
		'Logic & Control',
	);

	/**
	 * In-process memo of the loaded + validated catalog (type => manifest array).
	 *
	 * @var array<string,array>|null
	 */
	private static $memo = null;

	/**
	 * Absolute path to the manifests directory.
	 *
	 * @return string
	 */
	public static function get_manifest_dir() {
		return __DIR__ . '/nodes/manifests';
	}

	/**
	 * Absolute path to the JSON Schema that validates the manifests themselves.
	 *
	 * @return string
	 */
	public static function get_schema_path() {
		return __DIR__ . '/nodes/manifest.schema.json';
	}

	/**
	 * All manifests keyed by canonical node type, sorted in deterministic
	 * catalog order. Uses a transient cache when WordPress is available.
	 *
	 * @return array<string,array>
	 */
	public static function get_all() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$cache_key = self::CACHE_PREFIX . self::manifest_set_hash();

		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				self::$memo = $cached;
				return self::$memo;
			}
		}

		$catalog = self::load_and_sort();

		if ( function_exists( 'set_transient' ) && ! empty( $catalog ) ) {
			// DAY_IN_SECONDS may be undefined outside WP; fall back to a literal.
			$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			set_transient( $cache_key, $catalog, $ttl );
		}

		self::$memo = $catalog;
		return self::$memo;
	}

	/**
	 * Single manifest for a node type, or null if none is registered.
	 *
	 * @param string $type Canonical node type.
	 * @return array|null
	 */
	public static function get( $type ) {
		$all = self::get_all();
		return isset( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/**
	 * The config field schema for a node type - the shape used to render the
	 * builder form AND to validate generated/imported node `data`. Returns the
	 * manifest's `config[]` array, or null if the type is unknown.
	 *
	 * @param string $type Canonical node type.
	 * @return array|null
	 */
	public static function get_schema( $type ) {
		$manifest = self::get( $type );
		if ( null === $manifest ) {
			return null;
		}
		return isset( $manifest['config'] ) && is_array( $manifest['config'] ) ? $manifest['config'] : array();
	}

	/**
	 * The decoded JSON Schema used to validate the manifests themselves.
	 *
	 * @return array|null
	 */
	public static function get_manifest_schema() {
		$raw = @file_get_contents( self::get_schema_path() );
		if ( false === $raw ) {
			return null;
		}
		$schema = json_decode( $raw, true );
		return is_array( $schema ) ? $schema : null;
	}

	/**
	 * Assemble the generator's node-catalog block from the manifests. DETERMINISTIC
	 * (stable ordering + stable field emission) so it can serve as a cacheable
	 * prompt prefix. Consumed by the generator in a later item.
	 *
	 * @return string
	 */
	public static function to_prompt_section() {
		$all   = self::get_all();
		$lines = array();

		$lines[] = '<node_catalog>';
		foreach ( $all as $type => $m ) {
			$modes   = isset( $m['modes'] ) ? implode( ',', $m['modes'] ) : '';
			$credits = isset( $m['credits'] ) ? (string) $m['credits'] : '0';
			$label   = isset( $m['label'] ) ? $m['label'] : $type;
			$cat     = isset( $m['category'] ) ? $m['category'] : '';

			$lines[] = sprintf(
				'<node type="%s" label="%s" category="%s" modes="%s" credits="%s">',
				$type,
				$label,
				$cat,
				$modes,
				$credits
			);

			if ( ! empty( $m['description'] ) ) {
				$lines[] = '  <description>' . $m['description'] . '</description>';
			}

			if ( ! empty( $m['capabilities'] ) && is_array( $m['capabilities'] ) ) {
				$lines[] = '  <capabilities>' . implode( '; ', $m['capabilities'] ) . '</capabilities>';
			}

			if ( ! empty( $m['config'] ) && is_array( $m['config'] ) ) {
				$lines[] = '  <config>';
				foreach ( $m['config'] as $field ) {
					$attrs = 'name="' . ( isset( $field['name'] ) ? $field['name'] : '' ) . '"';
					$attrs .= ' type="' . ( isset( $field['type'] ) ? $field['type'] : '' ) . '"';
					if ( ! empty( $field['required'] ) ) {
						$attrs .= ' required="true"';
					}
					if ( ! empty( $field['supportsVariables'] ) ) {
						$attrs .= ' supportsVariables="true"';
					}
					if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
						$attrs .= ' options="' . implode( '|', array_map( 'strval', $field['options'] ) ) . '"';
					}
					if ( array_key_exists( 'default', $field ) ) {
						$attrs .= ' default="' . self::scalar_to_string( $field['default'] ) . '"';
					}
					$desc    = isset( $field['description'] ) ? $field['description'] : '';
					$lines[] = '    <field ' . $attrs . '>' . $desc . '</field>';
				}
				$lines[] = '  </config>';
			}

			if ( ! empty( $m['inputs'] ) && is_array( $m['inputs'] ) ) {
				$lines[] = '  <inputs>' . implode( ',', $m['inputs'] ) . '</inputs>';
			}
			if ( isset( $m['outputs'] ) && is_array( $m['outputs'] ) ) {
				$lines[] = '  <outputs>' . implode( ',', $m['outputs'] ) . '</outputs>';
			}

			if ( isset( $m['example'] ) ) {
				$lines[] = '  <example>' . wp_json_encode_compat( $m['example'] ) . '</example>';
			}

			if ( ! empty( $m['keywords'] ) && is_array( $m['keywords'] ) ) {
				$lines[] = '  <keywords>' . implode( ',', $m['keywords'] ) . '</keywords>';
			}

			// Semantic I/O contract (produces/consumes/constraints) the generator uses
			// to wire node shapes and insert bridge nodes; emitted as compact JSON.
			$contract = self::get_contract_for_manifest( $type, $m );
			if ( ! empty( $contract ) ) {
				$lines[] = '  <contract>' . wp_json_encode_compat( $contract ) . '</contract>';
			}

			$lines[] = '</node>';
		}
		$lines[] = '</node_catalog>';

		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------- */
	/* Semantic contracts                                                      */
	/* ---------------------------------------------------------------------- */

	/**
	 * The list of canonical node types the catalog knows about (manifest types).
	 * Used by the workflow validator to check that a generated node `type` is real.
	 *
	 * @return string[]
	 */
	public static function known_types() {
		return array_keys( self::get_all() );
	}

	/**
	 * The normalized semantic contract for a node type: the manifest's `contract`
	 * block merged over a derived default. Encodes what the node PRODUCES (text vs
	 * named fields vs routing handles vs media, and cardinality) and what it
	 * CONSUMES (multi-field mappings, distinct required inputs). Returns null for
	 * an unknown type.
	 *
	 * @param string $type Canonical node type.
	 * @return array|null
	 */
	public static function get_contract( $type ) {
		$m = self::get( $type );
		if ( null === $m ) {
			return null;
		}
		return self::get_contract_for_manifest( $type, $m );
	}

	/**
	 * Normalize a manifest's contract: merge its explicit `contract` block over a
	 * default derived from existing manifest fields (config → consumed fields,
	 * single text-blob output). Deterministic - safe for the cacheable prompt.
	 *
	 * @param string $type Canonical node type.
	 * @param array  $m    Manifest.
	 * @return array
	 */
	public static function get_contract_for_manifest( $type, array $m ) {
		$default  = self::derive_default_contract( $m );
		$explicit = isset( $m['contract'] ) && is_array( $m['contract'] ) ? $m['contract'] : array();

		$out = $default;

		if ( isset( $explicit['produces'] ) && is_array( $explicit['produces'] ) ) {
			$out['produces'] = array_merge( $default['produces'], $explicit['produces'] );
		}
		if ( isset( $explicit['consumes'] ) && is_array( $explicit['consumes'] ) ) {
			$out['consumes'] = array_merge( $default['consumes'], $explicit['consumes'] );
		}
		if ( isset( $explicit['constraints'] ) && is_array( $explicit['constraints'] ) ) {
			$out['constraints'] = $explicit['constraints'];
		}

		return $out;
	}

	/**
	 * Derive a sensible default contract from the manifest fields when (or before)
	 * an explicit contract is supplied: a single text-blob output referenced as
	 * [Input from {id}], and consumed fields = the config fields that are required
	 * or accept variable tags.
	 *
	 * @param array $m Manifest.
	 * @return array
	 */
	private static function derive_default_contract( array $m ) {
		$fields = array();
		if ( isset( $m['config'] ) && is_array( $m['config'] ) ) {
			foreach ( $m['config'] as $f ) {
				$required = ! empty( $f['required'] );
				$supports = ! empty( $f['supportsVariables'] );
				if ( $required || $supports ) {
					$fields[] = array(
						'name'              => isset( $f['name'] ) ? $f['name'] : '',
						'required'          => $required,
						'supportsVariables' => $supports,
					);
				}
			}
		}

		return array(
			'produces'    => array(
				'kind'           => 'text',
				'cardinality'    => 'single',
				'namedFields'    => false,
				'jsonExtractable' => true,
				'reference'      => '[Input from {id}]',
			),
			'consumes'    => array(
				'fields' => $fields,
			),
			'constraints' => array(),
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Loading + validation                                                    */
	/* ---------------------------------------------------------------------- */

	/**
	 * Load every manifest file, decode it, and validate it against the JSON
	 * Schema. Invalid manifests are skipped (the CI guard is what turns an
	 * invalid/missing manifest into a hard failure - see the guard test).
	 *
	 * @return array<string,array> type => manifest.
	 */
	public static function load_manifests() {
		$dir    = self::get_manifest_dir();
		$schema = self::get_manifest_schema();
		$out    = array();

		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) ) {
			return $out;
		}

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );
			if ( false === $raw ) {
				continue;
			}
			$manifest = json_decode( $raw, true );
			if ( ! is_array( $manifest ) || empty( $manifest['type'] ) ) {
				continue;
			}
			if ( null !== $schema ) {
				$errors = self::validate_manifest( $manifest, $schema );
				if ( ! empty( $errors ) ) {
					// Skip invalid manifests at runtime; the guard test reports them.
					continue;
				}
			}
			$out[ $manifest['type'] ] = $manifest;
		}

		return $out;
	}

	/**
	 * Load + sort into deterministic catalog order.
	 *
	 * @return array<string,array>
	 */
	private static function load_and_sort() {
		$catalog = self::load_manifests();

		uasort(
			$catalog,
			static function ( $a, $b ) {
				$ca = self::category_rank( isset( $a['category'] ) ? $a['category'] : '' );
				$cb = self::category_rank( isset( $b['category'] ) ? $b['category'] : '' );
				if ( $ca !== $cb ) {
					return $ca <=> $cb;
				}
				return strcmp( isset( $a['type'] ) ? $a['type'] : '', isset( $b['type'] ) ? $b['type'] : '' );
			}
		);

		return $catalog;
	}

	/**
	 * Validate a single manifest against a JSON Schema (the subset of keywords
	 * this schema uses: type, required, properties, additionalProperties, items,
	 * enum, minItems, minimum, pattern). Returns a list of human-readable error
	 * strings; empty means valid.
	 *
	 * @param array $manifest Decoded manifest.
	 * @param array $schema   Decoded JSON Schema.
	 * @return string[] Errors.
	 */
	public static function validate_manifest( array $manifest, array $schema ) {
		$errors = array();
		self::validate_node( $manifest, $schema, '', $errors );
		return $errors;
	}

	/**
	 * Recursive schema check for the keyword subset used by manifest.schema.json.
	 *
	 * @param mixed  $data   Value under validation.
	 * @param array  $schema Schema node.
	 * @param string $path   JSON-pointer-ish path for error messages.
	 * @param array  $errors Accumulator (by reference).
	 * @return void
	 */
	private static function validate_node( $data, array $schema, $path, array &$errors ) {
		$label = '' === $path ? '(root)' : $path;

		// enum.
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			if ( ! in_array( $data, $schema['enum'], true ) ) {
				$errors[] = $label . ': value ' . self::scalar_to_string( $data ) . ' is not one of the allowed enum values';
			}
		}

		if ( ! isset( $schema['type'] ) ) {
			return;
		}

		switch ( $schema['type'] ) {
			case 'object':
				if ( ! is_array( $data ) || ( ! empty( $data ) && self::is_list( $data ) ) ) {
					$errors[] = $label . ': expected object';
					return;
				}
				if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
					foreach ( $schema['required'] as $req ) {
						if ( ! array_key_exists( $req, $data ) ) {
							$errors[] = $label . ': missing required property "' . $req . '"';
						}
					}
				}
				$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
				if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
					foreach ( array_keys( $data ) as $key ) {
						if ( ! isset( $props[ $key ] ) ) {
							$errors[] = $label . ': unexpected property "' . $key . '"';
						}
					}
				}
				foreach ( $props as $key => $sub ) {
					if ( array_key_exists( $key, $data ) && is_array( $sub ) ) {
						self::validate_node( $data[ $key ], $sub, ( '' === $path ? $key : $path . '.' . $key ), $errors );
					}
				}
				break;

			case 'array':
				if ( ! is_array( $data ) || ( ! empty( $data ) && ! self::is_list( $data ) ) ) {
					$errors[] = $label . ': expected array';
					return;
				}
				if ( isset( $schema['minItems'] ) && count( $data ) < (int) $schema['minItems'] ) {
					$errors[] = $label . ': expected at least ' . $schema['minItems'] . ' item(s)';
				}
				if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
					foreach ( $data as $i => $item ) {
						self::validate_node( $item, $schema['items'], $path . '[' . $i . ']', $errors );
					}
				}
				break;

			case 'string':
				if ( ! is_string( $data ) ) {
					$errors[] = $label . ': expected string';
					return;
				}
				if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/', $data ) ) {
					$errors[] = $label . ': "' . $data . '" does not match pattern ' . $schema['pattern'];
				}
				break;

			case 'number':
				if ( ! ( is_int( $data ) || is_float( $data ) ) || is_bool( $data ) ) {
					$errors[] = $label . ': expected number';
					return;
				}
				if ( isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
					$errors[] = $label . ': must be >= ' . $schema['minimum'];
				}
				break;

			case 'boolean':
				if ( ! is_bool( $data ) ) {
					$errors[] = $label . ': expected boolean';
				}
				break;
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers                                                                 */
	/* ---------------------------------------------------------------------- */

	/**
	 * Cache-busting hash of the manifest set: filenames + mtimes + schema mtime +
	 * catalog VERSION. Any add/edit/remove changes the hash and the transient key.
	 *
	 * @return string
	 */
	public static function manifest_set_hash() {
		$parts = array( self::VERSION );

		$schema_path = self::get_schema_path();
		if ( file_exists( $schema_path ) ) {
			$parts[] = 'schema:' . filemtime( $schema_path );
		}

		$files = glob( self::get_manifest_dir() . '/*.json' );
		if ( is_array( $files ) ) {
			sort( $files );
			foreach ( $files as $file ) {
				$parts[] = basename( $file ) . ':' . filemtime( $file );
			}
		}

		return substr( md5( implode( '|', $parts ) ), 0, 16 );
	}

	/**
	 * Rank of a category for deterministic ordering.
	 *
	 * @param string $category Category name.
	 * @return int
	 */
	private static function category_rank( $category ) {
		$idx = array_search( $category, self::CATEGORY_ORDER, true );
		return false === $idx ? count( self::CATEGORY_ORDER ) : (int) $idx;
	}

	/**
	 * Whether an array is a sequential list (0..n-1 integer keys).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * Render a scalar for attribute/error output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar_to_string( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return wp_json_encode_compat( $value );
	}
}

if ( ! function_exists( 'wp_json_encode_compat' ) ) {
	/**
	 * Deterministic JSON encoder for catalog output. Prefers WordPress'
	 * wp_json_encode when available, otherwise falls back to json_encode with
	 * stable flags so to_prompt_section() output is identical across runs.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	function wp_json_encode_compat( $data ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data, $flags );
			return false === $encoded ? '' : $encoded;
		}
		$encoded = json_encode( $data, $flags );
		return false === $encoded ? '' : $encoded;
	}
}
