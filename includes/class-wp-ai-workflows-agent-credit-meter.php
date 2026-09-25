<?php
/**
 * Agent Credit Meter - credits-proxy model transport for Agent mode.
 *
 * Dependency-injected model-caller that slots into the orchestrator's
 * $model_caller seam: routes model calls through the metered platform proxy
 * (`/proxy/ai`), enforces a per-run credit cap, and hard-stops on
 * insufficient credits - there is never a silent BYOK fallback (locked policy).
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed control-flow signal for a credit-driven hard stop of an agent run. The
 * reason distinguishes an out-of-credits 402 from hitting the per-run cap so the
 * caller can surface the right message.
 */
class WP_AI_Workflows_Agent_Credit_Exception extends Exception {

	const REASON_INSUFFICIENT = 'insufficient_credits';
	const REASON_CAP          = 'credit_cap';

	/** @var string */
	private $reason;

	/**
	 * @param string $reason  One of the REASON_* constants.
	 * @param string $message Human-readable message.
	 */
	public function __construct( $reason, $message ) {
		parent::__construct( $message );
		$this->reason = (string) $reason;
	}

	/**
	 * @return string REASON_* constant.
	 */
	public function get_reason() {
		return $this->reason;
	}
}

class WP_AI_Workflows_Agent_Credit_Meter {

	/** Default per-run credit ceiling when the node does not set one. */
	const DEFAULT_RUN_CAP = 60;

	/** @var string Selected model id (bare OpenAI id or `vendor/model`). */
	private $model;

	/** @var string|null Optional system prompt forwarded to the proxy. */
	private $system_prompt;

	/** @var array Model params (temperature / max_tokens). */
	private $params;

	/** @var int Per-run credit ceiling. */
	private $run_cap;

	/** @var callable fn(array $payload):array|WP_Error - defaults to proxy_ai. */
	private $proxy;

	/** @var float Cumulative credits settled across the run. */
	private $charged = 0.0;

	/** @var int Number of metered model calls made. */
	private $steps_metered = 0;

	/**
	 * @param string $model Model id.
	 * @param array  $opts  { systemPrompt, params, runCap, proxy }.
	 */
	public function __construct( $model, array $opts = array() ) {
		$this->model         = (string) $model;
		$this->system_prompt = isset( $opts['systemPrompt'] ) ? (string) $opts['systemPrompt'] : '';
		$this->params        = isset( $opts['params'] ) && is_array( $opts['params'] ) ? $opts['params'] : array();

		$requested     = isset( $opts['runCap'] ) ? (int) $opts['runCap'] : self::DEFAULT_RUN_CAP;
		$this->run_cap = max( 1, $requested );

		// Injectable transport for tests; defaults to the real metered proxy.
		if ( isset( $opts['proxy'] ) && is_callable( $opts['proxy'] ) ) {
			$this->proxy = $opts['proxy'];
		} else {
			$this->proxy = array( 'WP_AI_Workflows_Platform_Client', 'proxy_ai' );
		}
	}

	/**
	 * The orchestrator's $model_caller. Forwards one reasoning step through the
	 * credits proxy, meters the credits the backend settled, and returns a
	 * Chat-Completions-shaped response. Never falls back to BYOK.
	 *
	 * @param array $messages Chat-Completions messages (history + tool turns).
	 * @param array $tools    Governed tool schemas (may be empty).
	 * @return array {choices:[{message:{content, tool_calls?}}]}
	 * @throws WP_AI_Workflows_Agent_Credit_Exception On cap reached / insufficient credits.
	 * @throws Exception On any other proxy transport error.
	 */
	public function model_call( array $messages, array $tools ) {
		// Fail closed BEFORE spending: a runaway agent cannot outspend its run cap.
		if ( $this->charged >= $this->run_cap ) {
			throw new WP_AI_Workflows_Agent_Credit_Exception(
				esc_html( WP_AI_Workflows_Agent_Credit_Exception::REASON_CAP ),
				esc_html( 'This agent run reached its per-run credit limit and was stopped.' )
			);
		}

		$payload = array(
			'model'    => $this->model,
			'messages' => $messages,
		);
		if ( ! empty( $tools ) ) {
			// Forward the governed tool schemas so a tool-capable proxy can drive the
			// loop. Harmless on a content-only proxy (ignored server-side).
			$payload['tools']       = $tools;
			$payload['tool_choice'] = 'auto';
		}
		if ( '' !== $this->system_prompt ) {
			$payload['systemPrompt'] = $this->system_prompt;
		}
		if ( isset( $this->params['temperature'] ) ) {
			$payload['temperature'] = (float) $this->params['temperature'];
		}
		if ( isset( $this->params['max_tokens'] ) ) {
			$payload['maxTokens'] = (int) $this->params['max_tokens'];
		}

		$data = call_user_func( $this->proxy, $payload );

		if ( is_wp_error( $data ) ) {
			// 402 insufficient credits -> hard stop with a top-up message. NEVER BYOK.
			if ( 'platform_credits' === $data->get_error_code() ) {
				throw new WP_AI_Workflows_Agent_Credit_Exception(
					esc_html( WP_AI_Workflows_Agent_Credit_Exception::REASON_INSUFFICIENT ),
					esc_html( 'You are out of credits for this agent run. Please top up to continue.' )
				);
			}
			// Any other proxy failure is a plain transport error (no charge, no fallback).
			throw new Exception( 'Agent credits proxy error: ' . esc_html( $data->get_error_message() ) );
		}

		// Accrue the credits the backend actually settled for this step.
		if ( isset( $data['creditsCharged'] ) ) {
			$this->charged += (float) $data['creditsCharged'];
		}
		++$this->steps_metered;

		return $this->to_chat_completions( $data );
	}

	/**
	 * Cumulative credits settled across the run so far.
	 *
	 * @return float
	 */
	public function credits_charged() {
		return $this->charged;
	}

	/**
	 * Number of metered model calls made.
	 *
	 * @return int
	 */
	public function steps_metered() {
		return $this->steps_metered;
	}

	/**
	 * @return int Per-run credit ceiling.
	 */
	public function run_cap() {
		return $this->run_cap;
	}

	/**
	 * Normalise a proxy response into a Chat-Completions-shaped array. Handles
	 * content-only proxies today; forward-compatible with proxies that also
	 * return tool calls.
	 *
	 * @param array $data Decoded proxy payload.
	 * @return array
	 */
	private function to_chat_completions( array $data ) {
		// Already a full Chat-Completions envelope -> pass straight through.
		if ( isset( $data['choices'] ) && is_array( $data['choices'] ) ) {
			return $data;
		}

		$message = array(
			'role'    => 'assistant',
			'content' => isset( $data['content'] ) ? (string) $data['content'] : '',
		);

		// Accept either camelCase (proxy style) or snake_case (raw provider style).
		if ( ! empty( $data['toolCalls'] ) && is_array( $data['toolCalls'] ) ) {
			$message['tool_calls'] = $data['toolCalls'];
		} elseif ( ! empty( $data['tool_calls'] ) && is_array( $data['tool_calls'] ) ) {
			$message['tool_calls'] = $data['tool_calls'];
		}

		return array(
			'choices' => array(
				array( 'message' => $message ),
			),
		);
	}
}
