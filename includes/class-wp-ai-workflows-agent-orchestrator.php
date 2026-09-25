<?php
/**
 * Agent Orchestrator - the bounded, ReAct-style agentic loop.
 *
 * Replaces the chat handler's single-round tool handling when Agent mode is ON:
 *   model(messages + governed tool schemas) -> if tool_calls: execute each via
 *   the registry (guardrails + audit) -> append observations -> reason again ->
 *   until a final answer OR one of the guards trips (maxSteps, per-run tool cap,
 *   loop detection, pending confirmation).
 *
 * It is provider-agnostic: the caller supplies a $model_caller that returns a
 * Chat-Completions-shaped response, so this works for both the OpenRouter path
 * and the OpenAI path. Each step is emitted (thought -> tool -> observation) via
 * an optional callback for the widget / live-execution panel, and every AI step
 * is metered via an optional callback (ceil-credit settle lives in the caller).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Agent_Orchestrator {

	const DEFAULT_MAX_STEPS     = 6;
	const MAX_STEPS_CEILING     = 20;
	const LOOP_REPEAT_THRESHOLD = 3;  // identical tool+args this many times = loop.

	/** @var WP_AI_Workflows_Agent_Tool_Registry */
	private $registry;

	/** @var callable(array $messages, array $tools):array */
	private $model_caller;

	/** @var int */
	private $max_steps;

	/** @var int Hard ceiling on total tool executions across the whole run. */
	private $tool_call_cap;

	/** @var callable|null fn(array $step):void */
	private $emit;

	/** @var callable|null fn(int $step_index):void */
	private $meter;

	/**
	 * @param WP_AI_Workflows_Agent_Tool_Registry $registry     Governed tools.
	 * @param callable                             $model_caller fn(messages,tools):chat-response.
	 * @param array                                $config       { maxSteps, emit, meter }.
	 */
	public function __construct( $registry, $model_caller, array $config = array() ) {
		$this->registry     = $registry;
		$this->model_caller = $model_caller;

		$requested       = isset( $config['maxSteps'] ) ? (int) $config['maxSteps'] : self::DEFAULT_MAX_STEPS;
		$this->max_steps = max( 1, min( self::MAX_STEPS_CEILING, $requested ) );
		// Per-run tool-execution cap: generous relative to steps, but bounded so a
		// single runaway step cannot fan out unboundedly.
		$this->tool_call_cap = max( 12, $this->max_steps * 4 );

		$this->emit  = isset( $config['emit'] ) && is_callable( $config['emit'] ) ? $config['emit'] : null;
		$this->meter = isset( $config['meter'] ) && is_callable( $config['meter'] ) ? $config['meter'] : null;
	}

	/**
	 * Run the loop.
	 *
	 * @param array $messages Initial Chat-Completions messages (system+history+user).
	 * @param array $ctx      Tool execution context (session_id, confirmed_tools, …).
	 * @return array{final:string,steps:array,stop_reason:string,steps_used:int,blocks:array}
	 */
	public function run( array $messages, array $ctx = array() ) {
		$steps       = array();
		$final       = '';
		$stop_reason = 'final';
		$completed   = false;
		$pending     = null;
		$call_counts = array();
		$tool_execs  = 0;
		$model_calls = 0;
		// Rich-message blocks (product/content cards, quick replies) surfaced by
		// content tools. Accumulated across the run and returned to the widget;
		// only the tool's compact `summary` is fed back to the model.
		$ui_blocks = array();

		for ( $i = 1; $i <= $this->max_steps; $i++ ) {
			$response = call_user_func( $this->model_caller, $messages, $this->registry->get_tool_schemas() );
			++$model_calls;
			if ( $this->meter ) {
				call_user_func( $this->meter, $i );
			}

			$message    = isset( $response['choices'][0]['message'] ) ? $response['choices'][0]['message'] : array();
			$content    = isset( $message['content'] ) ? (string) $message['content'] : '';
			$tool_calls = isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ? $message['tool_calls'] : array();

			// No tool calls -> the model has produced its final answer.
			if ( empty( $tool_calls ) ) {
				$final     = $content;
				$completed = true;
				break;
			}

			// Snapshot the conversation BEFORE this batch's assistant turn so a
			// confirmation-required pause can persist a clean, single-call resume
			// state (no dangling sibling tool_call_ids). Args captured here are the
			// exact ones the pause locks - the resume never re-plans them.
			$batch_base = $messages;

			// Record the assistant turn carrying the tool calls (contract for the
			// follow-up tool messages).
			$messages[] = array(
				'role'       => 'assistant',
				'content'    => '' !== $content ? $content : null,
				'tool_calls' => $tool_calls,
			);

			$halt = false;
			foreach ( $tool_calls as $tc ) {
				$name     = isset( $tc['function']['name'] ) ? (string) $tc['function']['name'] : '';
				$raw_args = isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '{}';
				$args     = json_decode( is_string( $raw_args ) ? $raw_args : wp_json_encode( $raw_args ), true );
				if ( ! is_array( $args ) ) {
					$args = array();
				}
				$call_id = isset( $tc['id'] ) ? (string) $tc['id'] : ( 'call_' . $i );

				// Per-run tool-execution cap.
				if ( $tool_execs >= $this->tool_call_cap ) {
					$stop_reason = 'tool_cap';
					$final       = 'I stopped after reaching the maximum number of tool calls for this run.';
					$halt        = true;
					break;
				}

				// Loop detection: identical tool + args repeated too often.
				$sig                 = $name . '|' . md5( (string) wp_json_encode( $args ) );
				$call_counts[ $sig ] = isset( $call_counts[ $sig ] ) ? $call_counts[ $sig ] + 1 : 1;
				if ( $call_counts[ $sig ] >= self::LOOP_REPEAT_THRESHOLD ) {
					$stop_reason = 'loop_detected';
					$step        = array(
						'step'        => $i,
						'thought'     => $content,
						'tool'        => $name,
						'args'        => $args,
						'status'      => 'loop_detected',
						'observation' => 'Loop detected: identical tool call repeated.',
					);
					$steps[] = $step;
					$this->emit_step( $step );
					$final = 'I stopped because I detected a repeating loop of identical tool calls.';
					$halt  = true;
					break;
				}

				++$tool_execs;
				$result     = $this->registry->execute( $name, $args, $ctx );
				$obs_status = isset( $result['status'] ) ? $result['status'] : 'error';
				$obs_raw    = isset( $result['content'] ) ? $result['content'] : '';

				// Confirmation-required tool -> pause the whole run for approval.
				if ( 'pending_confirmation' === $obs_status ) {
					$stop_reason = 'pending_confirmation';
					$task_id     = isset( $result['task_id'] ) ? (int) $result['task_id'] : 0;
					$step        = array(
						'step'        => $i,
						'thought'     => $content,
						'tool'        => $name,
						'args'        => $args,
						'status'      => $obs_status,
						'task_id'     => $task_id,
						'observation' => is_string( $obs_raw ) ? $obs_raw : (string) wp_json_encode( $obs_raw ),
					);
					$steps[] = $step;
					$this->emit_step( $step );
					// Persist the exact state a later approval resumes from: the
					// conversation up to (not including) this batch, the single
					// pending call, and its LOCKED args. resume() replays only this
					// call - the model never re-plans the arguments (no TOCTOU).
					$pending = array(
						'messages' => $batch_base,
						'thought'  => $content,
						'call_id'  => $call_id,
						'tool'     => $name,
						'args'     => $args,
						'task_id'  => $task_id,
					);
					$final = 'I need approval before continuing. A request has been sent for human review.';
					$halt  = true;
					break;
				}

				// Dual-channel tool results: a tool may return interactive UI blocks
				// (rich cards) alongside a compact `summary` for the model. Surface
				// the blocks to the widget; feed only the summary back to the model.
				if ( is_array( $obs_raw ) && isset( $obs_raw['_ui_blocks'] ) ) {
					foreach ( (array) $obs_raw['_ui_blocks'] as $blk ) {
						if ( is_array( $blk ) && ! empty( $blk['type'] ) ) {
							$ui_blocks[] = $blk;
						}
					}
					$obs_raw = isset( $obs_raw['summary'] ) ? (string) $obs_raw['summary'] : wp_json_encode( $obs_raw );
				}

				// Feed the observation back to the model.
				$observation_str = is_string( $obs_raw ) ? $obs_raw : (string) wp_json_encode( $obs_raw );
				$messages[]      = array(
					'role'         => 'tool',
					'tool_call_id' => $call_id,
					'name'         => $name,
					'content'      => $observation_str,
				);

				$step = array(
					'step'        => $i,
					'thought'     => $content,
					'tool'        => $name,
					'args'        => $args,
					'status'      => $obs_status,
					'observation' => mb_substr( $observation_str, 0, 2000 ),
				);
				$steps[] = $step;
				$this->emit_step( $step );
			}

			if ( $halt ) {
				break;
			}
			// Otherwise loop again: reason over the appended observations.
		}

		if ( ! $completed && 'final' === $stop_reason ) {
			$stop_reason = 'max_steps';
			if ( '' === $final ) {
				$final = 'I reached the maximum number of reasoning steps before finishing.';
			}
		}

		return array(
			'final'       => $final,
			'steps'       => $steps,
			'stop_reason' => $stop_reason,
			'steps_used'  => $model_calls,
			'blocks'      => $ui_blocks,
			// Non-null only on a confirmation pause: the locked resume state the
			// chat handler persists so an approval can continue the exact run.
			'pending'     => $pending,
		);
	}

	/**
	 * Continue a run that paused for human approval.
	 *
	 * Approving executes ONLY the approved call, with the args LOCKED at pause
	 * time (replayed from $pending, never re-planned by the model - no TOCTOU).
	 * Rejecting injects a clean "owner declined" observation so the model answers
	 * gracefully. In both cases the loop then continues normally to a final answer.
	 *
	 * @param array $pending  Pause state from a run() result (`pending`).
	 * @param bool  $approved True to run the tool, false to reject it.
	 * @param array $ctx      Tool execution context.
	 * @return array Same shape as run(), plus `decision` and `resumed` => true.
	 */
	public function resume( array $pending, $approved, array $ctx = array() ) {
		$messages = isset( $pending['messages'] ) && is_array( $pending['messages'] ) ? $pending['messages'] : array();
		$name     = isset( $pending['tool'] ) ? (string) $pending['tool'] : '';
		$args     = isset( $pending['args'] ) && is_array( $pending['args'] ) ? $pending['args'] : array();
		$call_id  = isset( $pending['call_id'] ) && '' !== (string) $pending['call_id'] ? (string) $pending['call_id'] : 'call_resume';
		$thought  = isset( $pending['thought'] ) ? (string) $pending['thought'] : '';

		// Re-seed the single, normalized assistant tool-call turn (exactly the
		// approved call, so there are never dangling sibling tool_call_ids). The
		// arguments here are the ones locked at pause time.
		$messages[] = array(
			'role'       => 'assistant',
			'content'    => '' !== $thought ? $thought : null,
			'tool_calls' => array(
				array(
					'id'       => $call_id,
					'type'     => 'function',
					'function' => array(
						'name'      => $name,
						'arguments' => (string) wp_json_encode( $args ),
					),
				),
			),
		);

		$ui_blocks = array();
		if ( $approved ) {
			// Mark this tool confirmed so the registry runs it (and any re-call in
			// the same continuation) without pausing again. Args are the locked ones.
			$ctx['confirmed_tools'][ $name ] = true;
			$result  = $this->registry->execute( $name, $args, $ctx );
			$obs_raw = isset( $result['content'] ) ? $result['content'] : '';

			// Dual-channel result: surface UI blocks to the widget, feed only the
			// compact summary back to the model (mirrors the run() loop).
			if ( is_array( $obs_raw ) && isset( $obs_raw['_ui_blocks'] ) ) {
				foreach ( (array) $obs_raw['_ui_blocks'] as $blk ) {
					if ( is_array( $blk ) && ! empty( $blk['type'] ) ) {
						$ui_blocks[] = $blk;
					}
				}
				$obs_raw = isset( $obs_raw['summary'] ) ? (string) $obs_raw['summary'] : wp_json_encode( $obs_raw );
			}
			$observation_str = is_string( $obs_raw ) ? $obs_raw : (string) wp_json_encode( $obs_raw );
			$decision        = 'approved';
		} else {
			$observation_str = 'The site owner declined this action, so it was NOT performed. Do not attempt it again. Briefly and politely tell the customer you were unable to do that, and offer an alternative if you can.';
			$decision        = 'rejected';
		}

		$messages[] = array(
			'role'         => 'tool',
			'tool_call_id' => $call_id,
			'name'         => $name,
			'content'      => $observation_str,
		);

		$decision_step = array(
			'step'        => 0,
			'thought'     => $thought,
			'tool'        => $name,
			'args'        => $args,
			'status'      => $decision,
			'observation' => mb_substr( $observation_str, 0, 2000 ),
		);
		$this->emit_step( $decision_step );

		// Continue reasoning over the (approved-or-declined) observation until a
		// final answer. The model already has the result in context, so it will
		// not re-request the same tool.
		$continue           = $this->run( $messages, $ctx );
		$continue['blocks'] = array_merge( $ui_blocks, ( isset( $continue['blocks'] ) && is_array( $continue['blocks'] ) ) ? $continue['blocks'] : array() );
		$continue['steps']  = array_merge( array( $decision_step ), isset( $continue['steps'] ) ? (array) $continue['steps'] : array() );
		$continue['resumed']  = true;
		$continue['decision'] = $decision;
		return $continue;
	}

	/**
	 * @param array $step Step trace entry.
	 * @return void
	 */
	private function emit_step( array $step ) {
		if ( $this->emit ) {
			call_user_func( $this->emit, $step );
		}
	}
}
