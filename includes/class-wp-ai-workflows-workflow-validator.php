<?php
/**
 * Programmatic, catalog-aware validation of a generated (or imported) workflow:
 * every node type is real, every required field is present, and every variable
 * tag / edge satisfies the semantic contract of the node it references (source
 * of truth is WP_AI_Workflows_Node_Catalog). Returns machine-readable issues fed
 * back to the generator's self-repair loop.
 *
 * Pure PHP (no WordPress function required); loadable under PHPUnit with
 * WP_AI_WORKFLOWS_NODE_CATALOG_TEST defined.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_AI_WORKFLOWS_NODE_CATALOG_TEST' ) ) {
	exit;
}

class WP_AI_Workflows_Workflow_Validator {

	/** Canvas-only node types that are never executed and are exempt from most checks. */
	const ANNOTATION_TYPES = array( 'stickyNote', 'textAnnotation', 'shape' );

	/**
	 * Node types that can legitimately START a workflow (have no required upstream
	 * input). Mirrors the executor's entry semantics: an explicit trigger, or a
	 * self-sufficient source node the user drives directly.
	 *
	 * @var string[]
	 */
	const ENTRY_TYPES = array( 'trigger', 'chat', 'aiModel', 'parser', 'firecrawl' );

	/**
	 * Validate a workflow. Returns a list of issues; an empty list means the
	 * workflow is valid. Each issue is:
	 *   array(
	 *     'code'     => machine code (e.g. 'unknown_node_type'),
	 *     'severity' => 'error' | 'warning',
	 *     'message'  => human-readable, actionable text (fed to the repair model),
	 *     'node_id'  => optional offending node id,
	 *     'field'    => optional offending field/key,
	 *     'ref'      => optional offending reference/id,
	 *   )
	 *
	 * @param array $workflow array( 'nodes' => [...], 'edges' => [...] ).
	 * @return array[] Issues.
	 */
	public static function validate( $workflow ) {
		$issues = array();

		$nodes = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : null;
		$edges = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

		if ( null === $nodes || empty( $nodes ) ) {
			$issues[] = self::issue( 'empty_workflow', 'error', 'The workflow has no nodes. A workflow must contain at least a trigger/source node and an output node.' );
			return $issues;
		}

		$known_types = self::known_types();

		// Index nodes by id; flag structural problems.
		$by_id = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['id'] ) || ! isset( $node['type'] ) ) {
				$issues[] = self::issue( 'malformed_node', 'error', 'A node is missing its required "id" or "type".' );
				continue;
			}
			$id = $node['id'];
			if ( isset( $by_id[ $id ] ) ) {
				$issues[] = self::issue( 'duplicate_node_id', 'error', "Duplicate node id \"{$id}\". Every node id must be unique.", $id );
				continue;
			}
			$by_id[ $id ] = $node;
		}

		// Per-node: type validity, required fields, references, consumer shape.
		foreach ( $by_id as $id => $node ) {
			$type = $node['type'];

			if ( in_array( $type, self::ANNOTATION_TYPES, true ) ) {
				continue; // Canvas-only; no execution contract.
			}

			if ( ! in_array( $type, $known_types, true ) ) {
				$issues[] = self::issue(
					'unknown_node_type',
					'error',
					"Node \"{$id}\" has type \"{$type}\", which does not exist in the node catalog. Use only the node types listed in the catalog.",
					$id,
					null,
					$type
				);
				continue; // No contract for an unknown type - further checks would be noise.
			}

			$data     = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			$manifest = self::catalog_get( $type );
			$contract = self::catalog_contract( $type );

			// Required config fields present.
			foreach ( self::required_fields( $manifest ) as $field ) {
				if ( ! self::has_value( $data, $field ) ) {
					$issues[] = self::issue(
						'missing_required_field',
						'error',
						"Node \"{$id}\" ({$type}) is missing the required field \"{$field}\".",
						$id,
						$field
					);
				}
			}

			// Variable-tag references honour the producing node's contract.
			self::check_references( $id, $type, $data, $by_id, $edges, $issues );

			// Multi-field consumer shape (e.g. Post needs title + content as separate inputs).
			self::check_consumer_shape( $id, $type, $data, $contract, $by_id, $issues );
		}

		// Edge endpoints + source handles.
		self::check_edges( $by_id, $edges, $issues );

		// Whole-graph checks.
		self::check_graph( $by_id, $edges, $issues );

		return $issues;
	}

	/**
	 * Convenience: does the workflow have any blocking (severity=error) issue?
	 *
	 * @param array[] $issues Result of validate().
	 * @return bool
	 */
	public static function has_errors( $issues ) {
		foreach ( $issues as $i ) {
			if ( isset( $i['severity'] ) && 'error' === $i['severity'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compact, model-facing rendering of the issues for the repair prompt.
	 *
	 * @param array[] $issues Result of validate().
	 * @return string
	 */
	public static function to_repair_text( $issues ) {
		$lines = array();
		foreach ( $issues as $i ) {
			$sev    = isset( $i['severity'] ) ? strtoupper( $i['severity'] ) : 'ERROR';
			$code   = isset( $i['code'] ) ? $i['code'] : 'issue';
			$lines[] = "- [{$sev}:{$code}] " . ( isset( $i['message'] ) ? $i['message'] : '' );
		}
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------- */
	/* Reference checking                                                      */
	/* ---------------------------------------------------------------------- */

	/**
	 * Scan every string in a node's data for variable tags and validate each one
	 * against the referenced node's contract.
	 *
	 * @param string $id     Referencing node id.
	 * @param string $type   Referencing node type.
	 * @param array  $data   Referencing node data.
	 * @param array  $by_id  All nodes indexed by id.
	 * @param array  $edges  Edges.
	 * @param array  $issues Accumulator (by reference).
	 * @return void
	 */
	private static function check_references( $id, $type, $data, $by_id, $edges, array &$issues ) {
		$strings = self::collect_strings( $data );

		foreach ( $strings as $text ) {
			foreach ( self::extract_tags( $text ) as $tag ) {
				$ref_id = $tag['node'];

				// The referenced node must exist.
				if ( ! isset( $by_id[ $ref_id ] ) ) {
					$issues[] = self::issue(
						'unknown_node_reference',
						'error',
						"Node \"{$id}\" references \"{$ref_id}\" in a variable tag ({$tag['raw']}), but no node with that id exists. Only reference nodes that are present upstream.",
						$id,
						null,
						$ref_id
					);
					continue;
				}

				$ref_node     = $by_id[ $ref_id ];
				$ref_type     = isset( $ref_node['type'] ) ? $ref_node['type'] : '';
				$ref_contract = self::catalog_contract( $ref_type );

				if ( 'input' === $tag['kind'] ) {
					// [Input from X] - whole-output reference. Valid for any real node.
					// (Connectivity is repaired separately by the generator.)
					continue;
				}

				// Field references: [[field] from X] or [[field] from X:action].
				self::check_field_reference( $id, $tag, $ref_id, $ref_node, $ref_type, $ref_contract, $edges, $issues );
			}
		}
	}

	/**
	 * Validate a single [[field] from X(:action)] reference against X's contract.
	 *
	 * @param string $id           Referencing node id.
	 * @param array  $tag          Parsed tag.
	 * @param string $ref_id       Referenced node id.
	 * @param array  $ref_node     Referenced node.
	 * @param string $ref_type     Referenced node type.
	 * @param array|null $ref_contract Referenced node contract.
	 * @param array  $edges        Edges.
	 * @param array  $issues       Accumulator (by reference).
	 * @return void
	 */
	private static function check_field_reference( $id, $tag, $ref_id, $ref_node, $ref_type, $ref_contract, $edges, array &$issues ) {
		$field    = $tag['field'];
		$ref_data = isset( $ref_node['data'] ) && is_array( $ref_node['data'] ) ? $ref_node['data'] : array();
		$produces = ( is_array( $ref_contract ) && isset( $ref_contract['produces'] ) ) ? $ref_contract['produces'] : array();

		// Action-scoped reference: [[field] from chat-1:action-1].
		if ( 'action' === $tag['kind'] ) {
			if ( 'chat' !== $ref_type ) {
				$issues[] = self::issue(
					'invalid_action_reference',
					'error',
					"Node \"{$id}\" uses an action reference {$tag['raw']}, but \"{$ref_id}\" is a \"{$ref_type}\" node: only chat nodes expose action fields.",
					$id,
					$field,
					$ref_id
				);
				return;
			}
			$action = self::find_chat_action( $ref_data, $tag['action'] );
			if ( null === $action ) {
				$issues[] = self::issue(
					'unknown_chat_action',
					'error',
					"Node \"{$id}\" references action \"{$tag['action']}\" on chat node \"{$ref_id}\", but that chat node defines no such action.",
					$id,
					$field,
					$ref_id
				);
				return;
			}
			if ( ! self::action_has_field( $action, $field ) ) {
				$issues[] = self::issue(
					'unknown_chat_action_field',
					'error',
					"Node \"{$id}\" references field \"{$field}\" of action \"{$tag['action']}\" on chat \"{$ref_id}\", but that action has no such field.",
					$id,
					$field,
					$ref_id
				);
			}
			return;
		}

		// Plain field reference: [[field] from X].
		$root = self::field_root( $field ); // first segment before any dot.

		// Dynamic-field producers (form triggers): field names are known only at
		// runtime - accept.
		if ( ! empty( $produces['dynamicFields'] ) ) {
			return;
		}

		// Genuine named-field producers with a STATIC field list (APICall, loop).
		if ( ! empty( $produces['namedFields'] ) && isset( $produces['fields'] ) && is_array( $produces['fields'] ) && ! empty( $produces['fields'] ) ) {
			if ( ! in_array( $root, $produces['fields'], true ) ) {
				$issues[] = self::issue(
					'unknown_named_field',
					'warning',
					"Node \"{$id}\" references field \"{$field}\" from \"{$ref_id}\" ({$ref_type}), whose known output fields are: " . implode( ', ', $produces['fields'] ) . '.',
					$id,
					$field,
					$ref_id
				);
			}
			return;
		}

		// Named-field producers whose fields come from the node's own data.
		if ( ! empty( $produces['fieldsFrom'] ) ) {
			if ( 'extractInformation' === $ref_type ) {
				$names = self::extraction_field_names( $ref_data );
				if ( ! empty( $names ) && ! in_array( $root, $names, true ) ) {
					$issues[] = self::issue(
						'unknown_extracted_field',
						'error',
						"Node \"{$id}\" references field \"{$field}\" from Extract Information node \"{$ref_id}\", but that node only extracts: " . implode( ', ', $names ) . '. Add an extraction field named "' . $root . '" or reference an existing one.',
						$id,
						$field,
						$ref_id
					);
				}
				return;
			}
			// AI Prompt node with native structured output enabled: it produces
			// one named field per outputSchema[].name. Only treat it as a named
			// producer when the toggle is on - otherwise fall through to the
			// single-blob contract check (the JSON-in-prompt escape hatch still
			// applies for a plain AI node).
			if ( 'aiModel' === $ref_type ) {
				if ( ! empty( $ref_data['structuredOutput'] ) ) {
					$names = self::structured_output_field_names( $ref_data );
					if ( ! empty( $names ) && ! in_array( $root, $names, true ) ) {
						$issues[] = self::issue(
							'unknown_structured_field',
							'error',
							"Node \"{$id}\" references field \"{$field}\" from AI Prompt node \"{$ref_id}\", but that node's structured output only defines: " . implode( ', ', $names ) . '. Add an output field named "' . $root . '" or reference an existing one.',
							$id,
							$field,
							$ref_id
						);
					}
					return;
				}
				// structuredOutput off → not a named producer; continue below.
			} else {
				// Other dynamic-source producers (e.g. firecrawl json.*/agent.*): accept.
				return;
			}
		}

		// Firecrawl structured prefixes.
		if ( 'firecrawl' === $ref_type ) {
			if ( in_array( $root, array( 'json', 'agent', 'extract' ), true ) ) {
				return;
			}
		}

		// Routing nodes that forward content (condition / humanInput): a bare
		// field access is unusual but not fatal.
		if ( isset( $produces['handles'] ) && empty( $produces['namedFields'] ) ) {
			$issues[] = self::issue(
				'named_field_on_routing_node',
				'warning',
				"Node \"{$id}\" reads field \"{$field}\" from routing node \"{$ref_id}\" ({$ref_type}); routing nodes forward the whole content. Use [Input from {$ref_id}] instead.",
				$id,
				$field,
				$ref_id
			);
			return;
		}

		// THE CORE CONTRACT CHECK - a fabricated field on a single text/media blob.
		// The producing node emits ONE output; it has no named "{$field}" field.
		if ( empty( $produces['namedFields'] ) ) {
			$json_extractable = ! empty( $produces['jsonExtractable'] );
			$emits_json       = $json_extractable && self::text_requests_json( $ref_node );

			if ( ! $emits_json ) {
				if ( 'aiModel' === $ref_type ) {
					$fix = "Fix by ONE of: (a) PREFERRED: enable Structured output on \"{$ref_id}\" and define an output field named \"{$root}\" (structuredOutput=true with outputSchema:[{name:\"{$root}\", …}]), then keep {$tag['raw']}; (b) instruct \"{$ref_id}\" to return strict JSON containing a \"{$root}\" key; (c) insert an AI Extract Information node after \"{$ref_id}\" with an extraction field named \"{$root}\"; or (d) reference the whole output with [Input from {$ref_id}].";
				} else {
					$fix = $json_extractable
						? "Fix by ONE of: (a) instruct \"{$ref_id}\" to return strict JSON containing a \"{$root}\" key, then keep {$tag['raw']}; (b) insert an AI Extract Information node after \"{$ref_id}\" with an extraction field named \"{$root}\" and reference [[{$root}] from <that node>]; or (c) reference the whole output with [Input from {$ref_id}]."
						: "Fix by referencing the whole output with [Input from {$ref_id}]. This node type does not produce named fields.";
				}

				$issues[] = self::issue(
					'contract_field_from_text_blob',
					'error',
					"Node \"{$id}\" references field \"{$field}\" from \"{$ref_id}\" ({$ref_type}), but that node produces a SINGLE unnamed output with no \"{$root}\" field. This tag will not resolve. " . $fix,
					$id,
					$field,
					$ref_id
				);
			}
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Consumer shape checking                                                 */
	/* ---------------------------------------------------------------------- */

	/**
	 * Multi-field consumers (Post, save Output, Generate PDF) must feed each
	 * required field from its OWN real output. The classic generator failure is
	 * wiring one plain AI text blob into several distinct fields (title + content)
	 * and inventing per-field tags - flag that so the repair loop inserts a bridge.
	 *
	 * @param string     $id       Consumer node id.
	 * @param string     $type     Consumer node type.
	 * @param array      $data     Consumer node data.
	 * @param array|null $contract Consumer contract.
	 * @param array      $by_id    All nodes by id.
	 * @param array      $issues   Accumulator (by reference).
	 * @return void
	 */
	private static function check_consumer_shape( $id, $type, $data, $contract, $by_id, array &$issues ) {
		if ( ! is_array( $contract ) || empty( $contract['consumes']['multiField'] ) ) {
			return;
		}
		$mf       = $contract['consumes']['multiField'];
		$map_key  = isset( $mf['field'] ) ? $mf['field'] : '';
		$required = isset( $mf['requiredKeys'] ) && is_array( $mf['requiredKeys'] ) ? $mf['requiredKeys'] : array();

		if ( '' === $map_key || empty( $required ) ) {
			return;
		}

		$map = isset( $data[ $map_key ] ) && is_array( $data[ $map_key ] ) ? $data[ $map_key ] : array();

		// Required keys present.
		foreach ( $required as $key ) {
			if ( ! isset( $map[ $key ] ) || '' === trim( (string) $map[ $key ] ) ) {
				$issues[] = self::issue(
					'missing_multifield_key',
					'error',
					"Node \"{$id}\" ({$type}) must map the field \"{$key}\" in {$map_key}, but it is missing or empty.",
					$id,
					$key
				);
			}
		}

		// Same single blob wired into ≥2 distinct required slots ⇒ shape mismatch.
		$blob_sources = array(); // normalized [Input from X] value => list of keys.
		foreach ( $required as $key ) {
			if ( ! isset( $map[ $key ] ) || ! is_string( $map[ $key ] ) ) {
				continue;
			}
			$val  = trim( $map[ $key ] );
			$tags = self::extract_tags( $val );
			// Exactly one whole-output tag and nothing else meaningful.
			if ( 1 === count( $tags ) && 'input' === $tags[0]['kind'] ) {
				$src = $tags[0]['node'];
				if ( isset( $by_id[ $src ] ) && self::is_single_blob_producer( $by_id[ $src ] ) ) {
					$blob_sources[ $src ][] = $key;
				}
			}
		}
		foreach ( $blob_sources as $src => $keys ) {
			if ( count( $keys ) >= 2 ) {
				$issues[] = self::issue(
					'multifield_shared_blob',
					'error',
					"Node \"{$id}\" ({$type}) feeds one single text blob from \"{$src}\" into " . count( $keys ) . ' distinct fields (' . implode( ', ', $keys ) . "). A single AI output cannot fill several structured fields. PREFERRED: if \"{$src}\" is an AI Prompt node, enable Structured output on it with one output field per target and map [[key] from {$src}] per field. Otherwise produce each field from its own AI node, or insert an AI Extract Information node after \"{$src}\" (one extraction field per target) and map each field from it, or make \"{$src}\" emit strict JSON and map [[key] from {$src}] per field.",
					$id,
					null,
					$src
				);
			}
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Edge + graph checking                                                   */
	/* ---------------------------------------------------------------------- */

	/**
	 * Edge endpoints must exist; a sourceHandle must be valid for the source node.
	 *
	 * @param array $by_id  Nodes by id.
	 * @param array $edges  Edges.
	 * @param array $issues Accumulator (by reference).
	 * @return void
	 */
	private static function check_edges( $by_id, $edges, array &$issues ) {
		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) || ! isset( $edge['source'], $edge['target'] ) ) {
				$issues[] = self::issue( 'malformed_edge', 'error', 'An edge is missing its "source" or "target".' );
				continue;
			}
			$src = $edge['source'];
			$tgt = $edge['target'];

			if ( ! isset( $by_id[ $src ] ) ) {
				$issues[] = self::issue( 'invalid_edge_endpoint', 'error', "An edge references source \"{$src}\", which is not a node in the workflow.", null, null, $src );
			}
			if ( ! isset( $by_id[ $tgt ] ) ) {
				$issues[] = self::issue( 'invalid_edge_endpoint', 'error', "An edge references target \"{$tgt}\", which is not a node in the workflow.", null, null, $tgt );
			}

			if ( isset( $edge['sourceHandle'] ) && '' !== $edge['sourceHandle'] && isset( $by_id[ $src ] ) ) {
				$allowed = self::allowed_source_handles( $by_id[ $src ] );
				// Empty allowed set = single default output; a handle is unexpected but harmless.
				if ( ! empty( $allowed ) && ! in_array( $edge['sourceHandle'], $allowed, true ) ) {
					$issues[] = self::issue(
						'invalid_source_handle',
						'error',
						"An edge from \"{$src}\" uses sourceHandle \"{$edge['sourceHandle']}\", which is not one of its valid handles: " . implode( ', ', $allowed ) . '.',
						null,
						null,
						$src
					);
				}
			}
		}
	}

	/**
	 * Whole-graph checks: entry node present, a terminal output exists, no orphan
	 * executable nodes, no cycles.
	 *
	 * @param array $by_id  Nodes by id.
	 * @param array $edges  Edges.
	 * @param array $issues Accumulator (by reference).
	 * @return void
	 */
	private static function check_graph( $by_id, $edges, array &$issues ) {
		// Adjacency + connectivity.
		$outgoing  = array();
		$connected = array();
		foreach ( $edges as $edge ) {
			if ( ! isset( $edge['source'], $edge['target'] ) ) {
				continue;
			}
			$outgoing[ $edge['source'] ][] = $edge['target'];
			$connected[ $edge['source'] ]  = true;
			$connected[ $edge['target'] ]  = true;
		}

		$executable = array();
		foreach ( $by_id as $id => $node ) {
			if ( ! in_array( $node['type'], self::ANNOTATION_TYPES, true ) ) {
				$executable[ $id ] = $node;
			}
		}

		// Entry node.
		$has_entry = false;
		foreach ( $executable as $node ) {
			if ( in_array( $node['type'], self::ENTRY_TYPES, true ) ) {
				$has_entry = true;
				break;
			}
		}
		if ( ! $has_entry && ! empty( $executable ) ) {
			$issues[] = self::issue( 'no_trigger', 'error', 'The workflow has no entry/trigger node. Start it with a Trigger (or a self-sufficient source such as a Chat or AI Prompt node).' );
		}

		// Terminal output.
		$has_terminal_output = false;
		$has_any_terminal    = false;
		foreach ( $executable as $id => $node ) {
			$is_terminal = empty( $outgoing[ $id ] );
			if ( $is_terminal ) {
				$has_any_terminal = true;
				if ( self::is_output_kind( $node['type'] ) ) {
					$has_terminal_output = true;
				}
			}
		}
		if ( $has_any_terminal && ! $has_terminal_output && count( $executable ) > 1 ) {
			$issues[] = self::issue( 'no_output', 'error', 'The workflow does not end in an output/delivery node (Output, Post, Send Email, Create File, ...). Add a terminal node that delivers the result.' );
		}

		// Orphans.
		if ( count( $executable ) > 1 ) {
			foreach ( $executable as $id => $node ) {
				if ( empty( $connected[ $id ] ) ) {
					$issues[] = self::issue( 'disconnected_node', 'error', "Node \"{$id}\" ({$node['type']}) is not connected to any other node. Every executable node must be wired into the flow.", $id );
				}
			}
		}

		// Cycles.
		if ( self::has_cycle( $executable, $outgoing ) ) {
			$issues[] = self::issue( 'cycle_detected', 'error', 'The workflow contains a cycle (a node eventually connects back to an earlier node). Workflows must flow strictly forward.' );
		}
	}

	/* ---------------------------------------------------------------------- */
	/* Tag parsing + small helpers                                             */
	/* ---------------------------------------------------------------------- */

	/**
	 * Extract every variable tag from a string. Returns a list of:
	 *   [ 'kind' => 'input'|'field'|'action', 'node' => id, 'field' => name|null,
	 *     'action' => id|null, 'raw' => original ].
	 *
	 * @param string $text Text.
	 * @return array[]
	 */
	public static function extract_tags( $text ) {
		$tags = array();
		if ( ! is_string( $text ) || '' === $text ) {
			return $tags;
		}

		// [[field] from node:action]
		if ( preg_match_all( '/\[\[([^\]]+)\] from ([\w-]+):([^\]]+)\]/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $set ) {
				$tags[] = array(
					'kind'   => 'action',
					'field'  => trim( $set[1] ),
					'node'   => $set[2],
					'action' => trim( $set[3] ),
					'raw'    => $set[0],
				);
			}
		}

		// [[field] from node]  (no action segment)
		if ( preg_match_all( '/\[\[([^\]]+)\] from ([\w-]+)\]/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $set ) {
				$tags[] = array(
					'kind'   => 'field',
					'field'  => trim( $set[1] ),
					'node'   => $set[2],
					'action' => null,
					'raw'    => $set[0],
				);
			}
		}

		// [Input from node]
		if ( preg_match_all( '/\[Input from ([\w-]+)\]/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $set ) {
				$tags[] = array(
					'kind'   => 'input',
					'field'  => null,
					'node'   => $set[1],
					'action' => null,
					'raw'    => $set[0],
				);
			}
		}

		return $tags;
	}

	/**
	 * Recursively collect every string scalar within a data structure.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function collect_strings( $value ) {
		$out = array();
		if ( is_string( $value ) ) {
			if ( '' !== $value ) {
				$out[] = $value;
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				foreach ( self::collect_strings( $item ) as $s ) {
					$out[] = $s;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a node type is a terminal output/delivery node.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private static function is_output_kind( $type ) {
		$contract = self::catalog_contract( $type );
		if ( is_array( $contract ) && isset( $contract['produces']['kind'] ) && 'terminal' === $contract['produces']['kind'] ) {
			return true;
		}
		// Fallback for types without an explicit terminal contract.
		return in_array( $type, array( 'output', 'post', 'sendEmail', 'createFile', 'generatePdf', 'mediaGenerator', 'unsplash', 'MCPClient' ), true );
	}

	/**
	 * Whether a node produces a single, unnamed text/media blob (no named fields).
	 *
	 * @param array $node Node.
	 * @return bool
	 */
	private static function is_single_blob_producer( $node ) {
		$type     = isset( $node['type'] ) ? $node['type'] : '';
		$contract = self::catalog_contract( $type );
		if ( ! is_array( $contract ) || ! isset( $contract['produces'] ) ) {
			return false;
		}
		$p = $contract['produces'];
		return empty( $p['namedFields'] ) && empty( $p['dynamicFields'] ) && empty( $p['handles'] );
	}

	/**
	 * Heuristic: does a producing node's text ask the model to emit JSON? (Makes a
	 * [[field] from X] reference legitimately resolvable.)
	 *
	 * @param array $node Node.
	 * @return bool
	 */
	private static function text_requests_json( $node ) {
		$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
		foreach ( self::collect_strings( $data ) as $s ) {
			if ( preg_match( '/\bjson\b/i', $s ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The set of valid sourceHandles for a node (routing handles + chat action ids).
	 *
	 * @param array $node Node.
	 * @return string[]
	 */
	private static function allowed_source_handles( $node ) {
		$type     = isset( $node['type'] ) ? $node['type'] : '';
		$contract = self::catalog_contract( $type );
		$handles  = array();

		if ( is_array( $contract ) && isset( $contract['produces']['handles'] ) && is_array( $contract['produces']['handles'] ) ) {
			$handles = $contract['produces']['handles'];
		}

		// Chat action ids are valid source handles.
		if ( 'chat' === $type ) {
			$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
				foreach ( $data['actions'] as $a ) {
					if ( isset( $a['id'] ) ) {
						$handles[] = $a['id'];
					}
				}
			}
		}

		return $handles;
	}

	/**
	 * First path segment of a possibly-dotted field name.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private static function field_root( $field ) {
		$field = (string) $field;
		$pos   = strpos( $field, '.' );
		return false === $pos ? $field : substr( $field, 0, $pos );
	}

	/**
	 * Required field names for a node type from its manifest config.
	 *
	 * @param array|null $manifest Manifest.
	 * @return string[]
	 */
	private static function required_fields( $manifest ) {
		$out = array();
		if ( is_array( $manifest ) && isset( $manifest['config'] ) && is_array( $manifest['config'] ) ) {
			foreach ( $manifest['config'] as $f ) {
				if ( ! empty( $f['required'] ) && isset( $f['name'] ) ) {
					$out[] = $f['name'];
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a data field holds a non-empty value.
	 *
	 * @param array  $data  Node data.
	 * @param string $field Field name.
	 * @return bool
	 */
	private static function has_value( $data, $field ) {
		if ( ! array_key_exists( $field, $data ) ) {
			return false;
		}
		$v = $data[ $field ];
		if ( is_string( $v ) ) {
			return '' !== trim( $v );
		}
		if ( is_array( $v ) ) {
			return ! empty( $v );
		}
		return null !== $v;
	}

	/**
	 * Extraction field names declared on an Extract Information node.
	 *
	 * @param array $data Node data.
	 * @return string[]
	 */
	private static function extraction_field_names( $data ) {
		$names = array();
		if ( isset( $data['extractionFields'] ) && is_array( $data['extractionFields'] ) ) {
			foreach ( $data['extractionFields'] as $f ) {
				if ( isset( $f['name'] ) && '' !== $f['name'] ) {
					$names[] = $f['name'];
				}
			}
		}
		return $names;
	}

	/**
	 * Output field names declared on an AI Prompt node with structured output
	 * enabled (node.data.outputSchema[].name).
	 *
	 * @param array $data Node data.
	 * @return string[]
	 */
	private static function structured_output_field_names( $data ) {
		$names = array();
		if ( isset( $data['outputSchema'] ) && is_array( $data['outputSchema'] ) ) {
			foreach ( $data['outputSchema'] as $f ) {
				if ( is_array( $f ) && isset( $f['name'] ) && '' !== trim( (string) $f['name'] ) ) {
					$names[] = trim( (string) $f['name'] );
				}
			}
		}
		return $names;
	}

	/**
	 * Find a chat action by id.
	 *
	 * @param array  $data      Chat node data.
	 * @param string $action_id Action id.
	 * @return array|null
	 */
	private static function find_chat_action( $data, $action_id ) {
		if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
			foreach ( $data['actions'] as $a ) {
				if ( isset( $a['id'] ) && $a['id'] === $action_id ) {
					return $a;
				}
			}
		}
		return null;
	}

	/**
	 * Whether a chat action defines a field with the given name.
	 *
	 * @param array  $action Action.
	 * @param string $field  Field name.
	 * @return bool
	 */
	private static function action_has_field( $action, $field ) {
		if ( isset( $action['fields'] ) && is_array( $action['fields'] ) ) {
			foreach ( $action['fields'] as $f ) {
				if ( isset( $f['name'] ) && $f['name'] === $field ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Iterative-ish DFS cycle detection over executable nodes.
	 *
	 * @param array $executable Executable nodes by id.
	 * @param array $outgoing   Adjacency (source id => target ids).
	 * @return bool
	 */
	private static function has_cycle( $executable, $outgoing ) {
		$visited = array();
		$stack   = array();

		$visit = function ( $id ) use ( &$visit, &$visited, &$stack, $outgoing, $executable ) {
			$visited[ $id ] = true;
			$stack[ $id ]   = true;
			if ( isset( $outgoing[ $id ] ) ) {
				foreach ( $outgoing[ $id ] as $next ) {
					if ( ! isset( $executable[ $next ] ) ) {
						continue;
					}
					if ( ! isset( $visited[ $next ] ) ) {
						if ( $visit( $next ) ) {
							return true;
						}
					} elseif ( ! empty( $stack[ $next ] ) ) {
						return true;
					}
				}
			}
			$stack[ $id ] = false;
			return false;
		};

		foreach ( $executable as $id => $_ ) {
			if ( ! isset( $visited[ $id ] ) ) {
				if ( $visit( $id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Build an issue record.
	 *
	 * @param string      $code     Machine code.
	 * @param string      $severity 'error' | 'warning'.
	 * @param string      $message  Human message.
	 * @param string|null $node_id  Node id.
	 * @param string|null $field    Field.
	 * @param string|null $ref      Reference.
	 * @return array
	 */
	private static function issue( $code, $severity, $message, $node_id = null, $field = null, $ref = null ) {
		$i = array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
		if ( null !== $node_id ) {
			$i['node_id'] = $node_id;
		}
		if ( null !== $field ) {
			$i['field'] = $field;
		}
		if ( null !== $ref ) {
			$i['ref'] = $ref;
		}
		return $i;
	}

	/* ---------------------------------------------------------------------- */
	/* Catalog access (indirected so the class is trivially test-stubbable)    */
	/* ---------------------------------------------------------------------- */

	/**
	 * Canonical node types known to the catalog.
	 *
	 * @return string[]
	 */
	private static function known_types() {
		if ( class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			return WP_AI_Workflows_Node_Catalog::known_types();
		}
		return array();
	}

	/**
	 * Manifest for a type.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	private static function catalog_get( $type ) {
		if ( class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			return WP_AI_Workflows_Node_Catalog::get( $type );
		}
		return null;
	}

	/**
	 * Normalized contract for a type.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	private static function catalog_contract( $type ) {
		if ( class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			return WP_AI_Workflows_Node_Catalog::get_contract( $type );
		}
		return null;
	}
}
