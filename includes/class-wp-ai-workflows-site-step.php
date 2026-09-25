<?php
/**
 * WP_AI_Workflows_Site_Step - runs exactly one node of a Cloud workflow on this
 * site, on behalf of the platform's engine, and reports the result back.
 *
 * A step arrives through the signed platform callback, is recorded durably,
 * runs through the unmodified local executor, and answers inline when it can or
 * on cron when it cannot.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Site_Step {

	/** Cron hook that runs one accepted step off the open request. */
	const CRON_RUN = 'wp_ai_workflows_run_site_step';

	/** Cron hook that starts the next queued step and retires stale ones. */
	const CRON_DRAIN = 'wp_ai_workflows_drain_site_steps';

	/** Transient prefix: the pending work of an accepted step. */
	const JOB_PREFIX = 'wpaw_ss_job_';

	/** Transient prefix: the full result bytes behind a reference. */
	const BYTES_PREFIX = 'wpaw_ss_bytes_';

	/** Transient prefix: the response already returned for a step. */
	const RESPONSE_PREFIX = 'wpaw_ss_resp_';

	/** Transient prefix: the per-minute request counter. */
	const RATE_PREFIX = 'wpaw_ss_rate_';

	/** Steps this site will run at the same time; the rest queue. */
	const MAX_CONCURRENT = 4;

	/** Name of the advisory lock guarding admission to a running slot. */
	const ADMISSION_LOCK = 'wpaw_site_step_admission';

	/** Seconds to wait for the admission lock before failing safe to the queue. */
	const ADMISSION_LOCK_TIMEOUT = 3;

	/** Wall-clock seconds a step answering inline may take. */
	const SYNC_BUDGET = 20;

	/** Accepted steps per minute before the site sheds load. */
	const RATE_PER_MINUTE = 60;

	/** Result bytes returned inline before a reference is used instead. */
	const DEFAULT_MAX_RESULT_BYTES = 262144;

	/** Ceiling on what the platform may ask to be returned inline. */
	const MAX_RESULT_BYTES_CEILING = 1048576;

	/** Seconds an accepted step has to finish. */
	const DEADLINE_DEFAULT = 900;

	/** Seconds a step waiting on a person has to finish. */
	const DEADLINE_HUMAN = 2592000;

	/** How long a result stays fetchable, matching the record's retention. */
	const RESULT_TTL = 604800;

	/**
	 * How long the answer already given for a step is kept, so a late retry gets
	 * it back rather than a bare duplicate marker. Short, because the record is
	 * what actually stops a second run, and a site should not carry a week of
	 * step responses in its options table.
	 */
	const RESPONSE_TTL = 3600;

	/** After this, a step still marked running is assumed to have died. */
	const STALE_RUN_SECONDS = 300;

	/** How many times a finished step's result is retried before delivery is abandoned. */
	const MAX_DELIVERY_ATTEMPTS = 8;

	/** Seconds a newly received step may wait for its job to be stored. */
	const JOB_GRACE_SECONDS = 120;

	/** Minimum time between delivery retries picked up by the drain sweep. */
	const DELIVERY_RETRY_BACKOFF = 120;

	/**
	 * True while a node runs as a site step.
	 *
	 * @var bool
	 */
	private static $running_step = false;

	/**
	 * Whether a site step is currently running a node.
	 *
	 * @return bool
	 */
	public static function is_running_step() {
		return self::$running_step;
	}

	/**
	 * Register the cron handlers. Called once from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_RUN, array( __CLASS__, 'run_scheduled' ), 10, 1 );
		add_action( self::CRON_DRAIN, array( __CLASS__, 'drain' ) );

		// Deferred to init (after the default priority): this runs before
		// WordPress fires init at all, and the 5-minute schedule it relies on is
		// only registered once WP_AI_Workflows_Workflow's own init hook has run.
		add_action( 'init', array( __CLASS__, 'ensure_drain_watchdog' ), 20 );
	}

	/**
	 * A recurring backstop: a step's own one-shot run/drain event can be lost (a
	 * dead worker, a restarted site) with nothing else left to notice. This
	 * guarantees drain() keeps sweeping even when no other step is active.
	 *
	 * @return void
	 */
	public static function ensure_drain_watchdog() {
		if ( false === wp_next_scheduled( self::CRON_DRAIN ) ) {
			wp_schedule_event( time(), 'wp_ai_workflows_5min', self::CRON_DRAIN );
		}
	}

	/* ---------------------------------------------------------------------------
	 * Inbound: the run_node callback action
	 * ------------------------------------------------------------------------- */

	/**
	 * Accept one site step. Answers inline when the node is expected to finish
	 * inside the budget, and with 202 when it is not, or when this site is
	 * already running as many steps as it will.
	 *
	 * @param array $payload  The callback payload.
	 * @param array $envelope The callback body around it.
	 * @return array|WP_Error
	 */
	public static function handle( array $payload, array $envelope = array() ) {
		$step_id      = self::clean_id( isset( $payload['stepId'] ) ? $payload['stepId'] : '', 64 );
		$execution_id = self::clean_id( isset( $envelope['executionId'] ) ? $envelope['executionId'] : ( isset( $payload['executionId'] ) ? $payload['executionId'] : '' ), 64 );
		$node_id      = self::clean_id( isset( $envelope['nodeId'] ) ? $envelope['nodeId'] : ( isset( $payload['nodeId'] ) ? $payload['nodeId'] : '' ), 255 );
		$node_type    = isset( $payload['nodeType'] ) ? (string) $payload['nodeType'] : '';

		if ( '' === $step_id || '' === $execution_id || '' === $node_id ) {
			return self::error( 'invalid_payload', 'This step is missing its identifiers.', 400 );
		}
		if ( ! self::type_allowed( $node_type ) ) {
			return self::error( 'unknown_node_type', 'This step is not supported on your site.', 400 );
		}

		$config = isset( $payload['config'] ) && is_array( $payload['config'] ) ? $payload['config'] : array();
		$inputs = self::clean_inputs( isset( $payload['inputs'] ) ? $payload['inputs'] : array() );
		$max    = self::clean_max_bytes( isset( $payload['maxResultBytes'] ) ? $payload['maxResultBytes'] : 0 );
		$mode   = ( isset( $payload['mode'] ) && 'async' === $payload['mode'] ) ? 'async' : 'sync';

		if ( ! self::within_rate_limit() ) {
			return self::error( 'rate_limited', 'This site is handling too many steps right now.', 429 );
		}

		WP_AI_Workflows_Database::ensure_site_steps_table();

		$step_mode = self::step_mode( $node_type, $config );
		$async     = ( 'async' === $mode || 'async' === $step_mode );
		$deadline  = self::deadline( $node_type, isset( $payload['deadline'] ) ? $payload['deadline'] : 0 );

		$workflow_name = isset( $payload['workflowName'] ) ? sanitize_text_field( (string) $payload['workflowName'] ) : '';
		if ( mb_strlen( $workflow_name ) > 191 ) {
			$workflow_name = mb_substr( $workflow_name, 0, 191 );
		}

		$row_id = self::insert_row( $step_id, $execution_id, $node_id, $node_type, $async ? 'async' : 'sync', $workflow_name );
		if ( null === $row_id ) {
			$existing = self::get_row( $step_id );
			if ( $existing ) {
				return self::replay( $existing );
			}
			return self::error( 'db_error', 'This step could not be recorded.', 500 );
		}

		$job = array(
			'row_id'       => $row_id,
			'step_id'      => $step_id,
			'execution_id' => $execution_id,
			'node_id'      => $node_id,
			'node_type'    => $node_type,
			'config'       => $config,
			'inputs'       => $inputs,
			'max_bytes'    => $max,
			'deadline'     => $deadline,
			'resume_url'   => self::clean_resume_url( isset( $payload['resumeUrl'] ) ? $payload['resumeUrl'] : '' ),
		);

		if ( $async ) {
			$has_room = ! self::at_capacity( $execution_id, $step_id );
			self::store_job( $job, $deadline );
			if ( $has_room ) {
				self::schedule_run( $step_id );
			} else {
				self::schedule_drain();
			}
			return self::queued_response( $step_id, $node_type );
		}

		if ( self::try_reserve_slot( $execution_id, $step_id ) ) {
			return self::run_now( $job );
		}

		self::store_job( $job, $deadline );
		self::schedule_drain();
		return self::queued_response( $step_id, $node_type );
	}

	/**
	 * The 202 the platform sees when a step is queued rather than run now.
	 *
	 * @param string $step_id
	 * @param string $node_type
	 * @return array
	 */
	private static function queued_response( $step_id, $node_type ) {
		$accepted = array(
			'_status'  => 202,
			'accepted' => true,
			'stepId'   => $step_id,
		);
		if ( 'humanInput' !== $node_type ) {
			$accepted['estimatedSeconds'] = 60;
		}
		return $accepted;
	}

	/**
	 * Serve the bytes behind a large result exactly once.
	 *
	 * @param array $payload {stepId}
	 * @return array|WP_Error
	 */
	public static function fetch_result( array $payload ) {
		$step_id = self::clean_id( isset( $payload['stepId'] ) ? $payload['stepId'] : '', 64 );
		if ( '' === $step_id ) {
			return self::error( 'invalid_payload', 'This request is missing its step identifier.', 400 );
		}

		$bytes = get_transient( self::BYTES_PREFIX . $step_id );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return self::error( 'ref_not_found', 'That result is no longer available on your site.', 404 );
		}
		delete_transient( self::BYTES_PREFIX . $step_id );

		$output = json_decode( $bytes, true );

		return array(
			'stepId'   => $step_id,
			'output'   => ( null === $output ) ? $bytes : $output,
			'mime'     => 'application/json',
			'encoding' => 'utf8',
			'bytes'    => strlen( $bytes ),
			'sha256'   => hash( 'sha256', $bytes ),
		);
	}

	/* ---------------------------------------------------------------------------
	 * Running a step
	 * ------------------------------------------------------------------------- */

	/**
	 * Run a step on the open request and answer with its result. The caller must
	 * already hold the running slot (see try_reserve_slot()) before calling this.
	 *
	 * @param array $job
	 * @return array|WP_Error
	 */
	private static function run_now( array $job ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::SYNC_BUDGET );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		}

		$started = microtime( true );
		$result  = self::execute( $job );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000.0 );

		if ( is_wp_error( $result ) ) {
			return self::settle_failure( $job, $result, $elapsed );
		}

		return self::settle_success( $job, $result, $elapsed, false );
	}

	/**
	 * Cron handler for an accepted step. Claims the row so a doubled event, or a
	 * drain racing a scheduled run, can never run the same node twice.
	 *
	 * @param string $step_id
	 * @return void
	 */
	public static function run_scheduled( $step_id ) {
		$step_id = self::clean_id( $step_id, 64 );
		if ( '' === $step_id || ! self::claim( $step_id ) ) {
			return;
		}

		$job = get_transient( self::JOB_PREFIX . $step_id );
		if ( ! is_array( $job ) || empty( $job['node_id'] ) ) {
			$row = self::get_row( $step_id );
			self::settle_failure(
				array(
					'step_id'      => $step_id,
					'execution_id' => $row ? $row->platform_execution_id : '',
					'node_id'      => $row ? $row->node_id : '',
					'node_type'    => $row ? $row->node_type : '',
					'resume_url'   => '',
				),
				self::error( 'step_expired', 'Your site no longer had this step to run.', 410 ),
				0,
				true,
				'expired'
			);
			self::drain();
			return;
		}

		if ( time() > (int) $job['deadline'] ) {
			self::settle_failure( $job, self::error( 'step_expired', 'This step passed its deadline before it could run.', 410 ), 0, true, 'expired' );
			self::drain();
			return;
		}

		$started = microtime( true );
		$result  = self::execute( $job );
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000.0 );

		if ( is_wp_error( $result ) ) {
			self::settle_failure( $job, $result, $elapsed, true );
			self::drain();
			return;
		}

		// A task waiting on a person holds no worker: park the step until the task
		// is answered, and let the next queued step start.
		$task_id = self::pending_task_id( $result );
		if ( $task_id > 0 ) {
			self::mark( $step_id, array( 'status' => 'awaiting', 'result_ref' => 'task:' . $task_id ) );
			self::drain();
			return;
		}

		self::settle_success( $job, $result, $elapsed, true );
		self::drain();
	}

	/**
	 * Run the node itself. The executor is called unmodified against a graph
	 * synthesised from the real upstream ids, so the node resolves its own
	 * variable tags exactly as it would in a Local run.
	 *
	 * @param array $job
	 * @return array|WP_Error
	 */
	private static function execute( array $job ) {
		$node_id = (string) $job['node_id'];
		$edges     = array();
		$node_data = array();

		$inputs = ( isset( $job['inputs'] ) && is_array( $job['inputs'] ) ) ? $job['inputs'] : array();

		foreach ( $inputs as $source_id => $entry ) {
			$edges[]                          = array( 'source' => (string) $source_id, 'target' => $node_id );
			$node_data[ (string) $source_id ] = $entry;
		}

		$node = array(
			'id'   => $node_id,
			'type' => (string) $job['node_type'],
			'data' => is_array( $job['config'] ) ? $job['config'] : array(),
		);

		try {
			self::$running_step = true;
			$result = WP_AI_Workflows_Node_Execution::execute_node( $node, $node_data, $edges, (int) $job['row_id'] );
		} catch ( Throwable $e ) {
			return self::error( 'node_failed', 'This step could not be completed on your site.', 500 );
		} finally {
			self::$running_step = false;
		}

		if ( ! is_array( $result ) ) {
			return self::error( 'node_failed', 'This step returned nothing on your site.', 500 );
		}
		if ( isset( $result['type'] ) && 'error' === $result['type'] ) {
			$message = isset( $result['content'] ) && is_scalar( $result['content'] ) ? (string) $result['content'] : 'This step failed on your site.';
			return self::error( 'node_error', $message, 500 );
		}

		return $result;
	}

	/**
	 * Record a completed step and build the response the platform reads.
	 *
	 * @param array $job
	 * @param array $result   Node result.
	 * @param int   $elapsed  Milliseconds.
	 * @param bool  $callback Send the result to the platform rather than return it.
	 * @return array
	 */
	private static function settle_success( array $job, array $result, $elapsed, $callback ) {
		$encoded = wp_json_encode( $result );
		$encoded = is_string( $encoded ) ? $encoded : '';
		$bytes   = strlen( $encoded );
		$max     = isset( $job['max_bytes'] ) ? (int) $job['max_bytes'] : self::DEFAULT_MAX_RESULT_BYTES;

		$response = array(
			'ranAt'      => time(),
			'durationMs' => $elapsed,
		);

		$result_ref = null;

		if ( $bytes > $max && '' !== $encoded ) {
			set_transient( self::BYTES_PREFIX . $job['step_id'], $encoded, self::RESULT_TTL );
			$result_ref            = 'bytes:' . $bytes;
			$response['truncated'] = true;
			$response['ref']       = array(
				'kind'     => 'site',
				'stepId'   => $job['step_id'],
				'mime'     => 'application/json',
				'encoding' => 'utf8',
				'bytes'    => $bytes,
				'sha256'   => hash( 'sha256', $encoded ),
				'preview'  => self::preview( $result ),
			);
			$response['output']    = array(
				'type'    => isset( $result['type'] ) ? $result['type'] : 'text',
				'content' => self::preview( $result ),
			);
		} else {
			$response['output'] = $result;
		}

		$fields = array(
			'status'      => 'completed',
			'duration_ms' => $elapsed,
			'result_ref'  => $result_ref,
		);

		// The exact body the platform needs to hear about this step, persisted
		// before delivery is even attempted: if the process dies right here, the
		// drain sweep still has what it needs to retry.
		$body = null;
		if ( $callback ) {
			$body = array(
				'stepId'     => $job['step_id'],
				'nodeId'     => $job['node_id'],
				'success'    => true,
				'output'     => $response['output'],
				'durationMs' => $elapsed,
			);
			// The platform's endpoint 500s on an explicit "ref": null, so the key
			// is only present at all when there is one to send.
			if ( isset( $response['ref'] ) ) {
				$body['ref'] = $response['ref'];
			}
			$fields['delivery_payload'] = self::encode_delivery( $body );
		}

		self::mark( $job['step_id'], $fields );
		set_transient( self::RESPONSE_PREFIX . $job['step_id'], $response, self::RESPONSE_TTL );
		delete_transient( self::JOB_PREFIX . $job['step_id'] );

		self::log( $job['step_id'], $job['node_type'], 'completed', $elapsed );

		if ( null !== $body ) {
			self::post_completion( $job, $body );
		}

		return $response;
	}

	/**
	 * Record a failed step and build the failure the platform reads.
	 *
	 * @param array    $job
	 * @param WP_Error $error
	 * @param int      $elapsed
	 * @param bool     $callback Send the failure to the platform.
	 * @param string   $status   Terminal status to record.
	 * @return WP_Error
	 */
	private static function settle_failure( array $job, $error, $elapsed, $callback = false, $status = 'failed' ) {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		$fields = array(
			'status'        => ( 'expired' === $status ) ? 'expired' : 'failed',
			'error_code'    => (string) $code,
			'error_message' => (string) $message,
			'duration_ms'   => $elapsed,
		);

		$body = null;
		if ( $callback && ! empty( $job['execution_id'] ) ) {
			$body = array(
				'stepId'     => $job['step_id'],
				'nodeId'     => $job['node_id'],
				'success'    => false,
				'error'      => (string) $message,
				'errorCode'  => (string) $code,
				'durationMs' => $elapsed,
			);
			$fields['delivery_payload'] = self::encode_delivery( $body );
		}

		self::mark( $job['step_id'], $fields );
		delete_transient( self::JOB_PREFIX . $job['step_id'] );

		self::log( $job['step_id'], isset( $job['node_type'] ) ? $job['node_type'] : '', 'failed', $elapsed );

		if ( null !== $body ) {
			self::post_completion( $job, $body );
		}

		return $error;
	}

	/* ---------------------------------------------------------------------------
	 * Human tasks
	 * ------------------------------------------------------------------------- */

	/**
	 * Report an answered human task back to the platform. Returns false when the
	 * task belongs to a Local run, which resumes the way it always has.
	 *
	 * @param array  $task
	 * @param mixed  $content
	 * @param string $action
	 * @return bool Whether this resolver handled the task.
	 */
	public static function resolve_task( $task, $content, $action ) {
		$row = self::task_row( $task );
		if ( null === $row ) {
			return false;
		}

		$job = get_transient( self::JOB_PREFIX . $row->step_id );
		if ( ! is_array( $job ) ) {
			$job = array(
				'step_id'      => $row->step_id,
				'execution_id' => $row->platform_execution_id,
				'node_id'      => $row->node_id,
				'node_type'    => $row->node_type,
				'max_bytes'    => self::DEFAULT_MAX_RESULT_BYTES,
				'resume_url'   => '',
			);
		}

		$result = array(
			'type'    => 'humanInput',
			'status'  => ( 'reject' === $action ) ? 'rejected' : 'completed',
			'content' => $content,
			'action'  => $action,
		);

		self::settle_success( $job, $result, 0, true );
		self::drain();
		return true;
	}

	/**
	 * The awaiting site-step row a human task belongs to, or null.
	 *
	 * @param array $task
	 * @return object|null
	 */
	private static function task_row( $task ) {
		$task_id = 0;
		if ( is_array( $task ) && isset( $task['id'] ) ) {
			$task_id = (int) $task['id'];
		} elseif ( is_object( $task ) && isset( $task->id ) ) {
			$task_id = (int) $task->id;
		}
		if ( $task_id <= 0 ) {
			return null;
		}
		WP_AI_Workflows_Database::ensure_site_steps_table();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- correlation lookup on a unique key.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE result_ref = %s AND status = 'awaiting' LIMIT 1",
				WP_AI_Workflows_Database::site_steps_table(),
				'task:' . $task_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * The task id a human input node parked on, or 0.
	 *
	 * @param array $result
	 * @return int
	 */
	private static function pending_task_id( array $result ) {
		if ( ! isset( $result['type'] ) || 'humanInput' !== $result['type'] ) {
			return 0;
		}
		if ( ! isset( $result['status'] ) || 'pending' !== $result['status'] ) {
			return 0;
		}
		return isset( $result['taskId'] ) ? (int) $result['taskId'] : 0;
	}

	/* ---------------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------------- */

	/**
	 * Start the next queued steps this site has room for, and retire any that
	 * passed their deadline.
	 *
	 * @return void
	 */
	public static function drain() {
		WP_AI_Workflows_Database::ensure_site_steps_table();

		global $wpdb;
		$table = WP_AI_Workflows_Database::site_steps_table();

		// A worker that died mid-step must not hold a slot for ever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stale-run sweep.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'failed', error_code = 'step_abandoned', updated_at = %s
				 WHERE status = 'running' AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )",
				$table,
				current_time( 'mysql', true ),
				self::STALE_RUN_SECONDS
			)
		);

		// A completion the platform never confirmed gets another shot, whether it
		// failed outright or the worker never got to try. Independent of the queue
		// below, so it still runs even when nothing is waiting to be started.
		self::redeliver_pending();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- queue sweep.
		$queued = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'received' ORDER BY id ASC LIMIT 20",
				$table
			)
		);
		if ( empty( $queued ) ) {
			return;
		}

		$running = self::count_running();
		$busy    = array();

		foreach ( $queued as $row ) {
			$job = get_transient( self::JOB_PREFIX . $row->step_id );
			if ( ! is_array( $job ) ) {
				// The row is written a moment before its job; a sweep in between must not expire it.
				$created = isset( $row->created_at ) ? strtotime( $row->created_at . ' UTC' ) : false;
				if ( false !== $created && $created > time() - self::JOB_GRACE_SECONDS ) {
					continue;
				}
				self::mark( $row->step_id, array( 'status' => 'expired', 'error_code' => 'step_expired' ) );
				continue;
			}
			if ( time() > (int) $job['deadline'] ) {
				self::settle_failure( $job, self::error( 'step_expired', 'This step passed its deadline before it could run.', 410 ), 0, true, 'expired' );
				continue;
			}
			if ( $running >= self::MAX_CONCURRENT ) {
				self::schedule_drain( MINUTE_IN_SECONDS );
				return;
			}
			if ( isset( $busy[ $row->platform_execution_id ] ) || self::execution_busy( $row->platform_execution_id, $row->step_id ) ) {
				continue;
			}

			$busy[ $row->platform_execution_id ] = true;
			++$running;
			self::schedule_run( $row->step_id );
		}
	}

	/**
	 * Retry delivering a finished step's result where the platform never
	 * confirmed it: an immediate delivery attempt that failed, or one that never
	 * ran at all because the worker died first. The exact body was persisted at
	 * settle time, so no work is redone, only redelivered.
	 *
	 * @return void
	 */
	private static function redeliver_pending() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delivery retry sweep.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE delivered_at IS NULL AND delivery_payload IS NOT NULL
				 AND delivery_attempts < %d AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
				 ORDER BY updated_at ASC LIMIT 10",
				WP_AI_Workflows_Database::site_steps_table(),
				self::MAX_DELIVERY_ATTEMPTS,
				self::DELIVERY_RETRY_BACKOFF
			)
		);
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$body = json_decode( (string) $row->delivery_payload, true );
			if ( ! is_array( $body ) ) {
				self::mark( $row->step_id, array( 'delivery_error' => 'payload_unreadable' ) );
				continue;
			}

			$job = array(
				'step_id'      => $row->step_id,
				'execution_id' => $row->platform_execution_id,
				'node_id'      => $row->node_id,
				'resume_url'   => '',
			);
			self::post_completion( $job, $body, (int) $row->delivery_attempts );
		}
	}

	/**
	 * Whether this site is already as busy as it will get for this step.
	 *
	 * @param string $execution_id
	 * @param string $step_id
	 * @return bool
	 */
	private static function at_capacity( $execution_id, $step_id ) {
		return self::count_running() >= self::MAX_CONCURRENT || self::execution_busy( $execution_id, $step_id );
	}

	/**
	 * Reserve a running slot for a step, atomically. The row is written to
	 * 'running' only once the reservation is confirmed to be within budget, so a
	 * burst of simultaneous requests can never admit more than MAX_CONCURRENT at
	 * once: the count and the mark happen under the same advisory lock.
	 *
	 * Fails safe: if the lock cannot be taken within the timeout, this reports no
	 * room rather than guess, and the step queues like any other over-capacity
	 * step. A worker that dies while holding the lock releases it when its MySQL
	 * connection closes; a worker that dies after reserving but before finishing
	 * is caught by the stale-run sweep in drain(), the same as any other step.
	 *
	 * @param string $execution_id
	 * @param string $step_id
	 * @return bool Whether the slot was reserved.
	 */
	private static function try_reserve_slot( $execution_id, $step_id ) {
		global $wpdb;

		$lock = substr( self::ADMISSION_LOCK . ':' . $wpdb->prefix, 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a table read.
		$got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::ADMISSION_LOCK_TIMEOUT ) );
		if ( '1' !== (string) $got_lock ) {
			return false;
		}

		try {
			if ( self::count_running() >= self::MAX_CONCURRENT || self::execution_busy( $execution_id, $step_id ) ) {
				return false;
			}
			self::mark( $step_id, array( 'status' => 'running' ) );
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock release.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Steps currently executing across every run on this site.
	 *
	 * @return int
	 */
	private static function count_running() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- capacity check.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = 'running'",
				WP_AI_Workflows_Database::site_steps_table()
			)
		);
	}

	/**
	 * Whether a run already has a step in flight on this site.
	 *
	 * @param string $execution_id
	 * @param string $step_id
	 * @return bool
	 */
	private static function execution_busy( $execution_id, $step_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- capacity check.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE platform_execution_id = %s AND step_id != %s AND status IN ( 'running', 'awaiting' )",
				WP_AI_Workflows_Database::site_steps_table(),
				(string) $execution_id,
				(string) $step_id
			)
		);
		return $count > 0;
	}

	/**
	 * Schedule one step to run off the open request, and nudge cron so it starts
	 * now rather than on the next visitor.
	 *
	 * @param string $step_id
	 * @return void
	 */
	private static function schedule_run( $step_id ) {
		wp_schedule_single_event( time(), self::CRON_RUN, array( (string) $step_id ) );
		spawn_cron();
	}

	/**
	 * Schedule the queue sweep.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private static function schedule_drain( $delay = 0 ) {
		$when = time() + max( 0, (int) $delay );
		if ( false === wp_next_scheduled( self::CRON_DRAIN ) ) {
			wp_schedule_single_event( $when, self::CRON_DRAIN );
			if ( 0 === (int) $delay ) {
				spawn_cron();
			}
		}
	}

	/* ---------------------------------------------------------------------------
	 * Reporting back to the platform
	 * ------------------------------------------------------------------------- */

	/**
	 * Send a finished step's result to the platform, signed the same way the
	 * platform signs its request to this site. Tries a short, immediate burst;
	 * anything left undelivered stays on the row (see settle_success() /
	 * settle_failure()) for the drain sweep to retry, so a failure here is never
	 * the end of the story.
	 *
	 * @param array $job
	 * @param array $body
	 * @param int   $prior_attempts Attempts already made on earlier calls (redelivery).
	 * @return void
	 */
	private static function post_completion( array $job, array $body, $prior_attempts = 0 ) {
		$url = self::completion_url( $job );
		if ( '' === $url ) {
			self::record_delivery_outcome( $job['step_id'], (int) $prior_attempts, 'no_completion_url' );
			return;
		}

		$secret = WP_AI_Workflows_Platform_Client::get_callback_secret();
		if ( is_wp_error( $secret ) || '' === (string) $secret ) {
			self::record_delivery_outcome( $job['step_id'], (int) $prior_attempts, 'not_connected' );
			return;
		}
		$authorization = WP_AI_Workflows_Platform_Client::get_authorization_header();
		if ( '' === $authorization ) {
			self::record_delivery_outcome( $job['step_id'], (int) $prior_attempts, 'not_connected' );
			return;
		}

		$raw      = wp_json_encode( $body );
		$raw      = is_string( $raw ) ? $raw : '{}';
		$attempts = (int) $prior_attempts;
		$reason   = 'unknown';

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$timestamp = (string) time();
			$nonce     = wp_generate_password( 32, false, false );

			$args = array(
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Authorization'     => $authorization,
					'X-WPAW-Signature'  => hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $raw, (string) $secret ),
					'X-WPAW-Timestamp'  => $timestamp,
					'X-WPAW-Nonce'      => $nonce,
					'X-WPAW-Key-Prefix' => WP_AI_Workflows_Platform_Client::get_key_prefix(),
				),
				'body'    => $raw,
			);

			$response = wp_remote_request( $url, $args );
			++$attempts;

			if ( is_wp_error( $response ) ) {
				$reason = 'network_error';
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					self::mark(
						$job['step_id'],
						array(
							'delivered_at'      => current_time( 'mysql', true ),
							'delivery_attempts' => $attempts,
							'delivery_error'    => null,
							'delivery_payload'  => null,
						)
					);
					return;
				}
				$reason = 'http_' . $code;
				if ( $code < 500 ) {
					break; // Not the kind of failure three quick retries will fix.
				}
			}

			if ( $attempt < 2 ) {
				sleep( $attempt + 1 );
			}
		}

		self::record_delivery_outcome( $job['step_id'], $attempts, $reason );
	}

	/**
	 * Record a delivery attempt that did not succeed, so it is never silent and
	 * the drain sweep knows to retry it (see redeliver_pending()).
	 *
	 * @param string $step_id
	 * @param int    $attempts
	 * @param string $reason
	 * @return void
	 */
	private static function record_delivery_outcome( $step_id, $attempts, $reason ) {
		self::mark(
			$step_id,
			array(
				'delivery_attempts' => (int) $attempts,
				'delivery_error'    => substr( (string) $reason, 0, 190 ),
			)
		);

		WP_AI_Workflows_Utilities::debug_log(
			( (int) $attempts >= self::MAX_DELIVERY_ATTEMPTS ) ? 'Site step result delivery abandoned' : 'Site step result delivery failed, will retry',
			'warning',
			array(
				'step_id'  => sanitize_key( $step_id ),
				'attempts' => (int) $attempts,
				'reason'   => sanitize_key( (string) $reason ),
			)
		);
	}

	/**
	 * JSON for a completion body, or null if it cannot be encoded.
	 *
	 * @param array $body
	 * @return string|null
	 */
	private static function encode_delivery( array $body ) {
		$encoded = wp_json_encode( $body );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Where a finished step reports to. A platform-supplied address is honoured
	 * only when it is on the platform's own host.
	 *
	 * @param array $job
	 * @return string
	 */
	private static function completion_url( array $job ) {
		$base = WP_AI_Workflows_Platform_Client::base_url();

		if ( ! empty( $job['resume_url'] ) && 0 === strpos( (string) $job['resume_url'], $base . '/' ) ) {
			return (string) $job['resume_url'];
		}
		if ( empty( $job['execution_id'] ) || empty( $job['step_id'] ) ) {
			return '';
		}

		return $base . '/api/v1/execute/workflow/' . rawurlencode( (string) $job['execution_id'] )
			. '/site-step/' . rawurlencode( (string) $job['step_id'] );
	}

	/* ---------------------------------------------------------------------------
	 * Record keeping
	 * ------------------------------------------------------------------------- */

	/**
	 * Insert the record for a newly accepted step. Returns null when the step id
	 * is already recorded, which is what makes a retry a no-op.
	 *
	 * @param string $step_id
	 * @param string $execution_id
	 * @param string $node_id
	 * @param string $node_type
	 * @param string $mode
	 * @param string $workflow_name Name of the cloud workflow this step belongs to.
	 * @return int|null
	 */
	private static function insert_row( $step_id, $execution_id, $node_id, $node_type, $mode, $workflow_name = '' ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable idempotency record.
		$inserted = $wpdb->insert(
			WP_AI_Workflows_Database::site_steps_table(),
			array(
				'step_id'               => $step_id,
				'platform_execution_id' => $execution_id,
				'workflow_name'         => '' === $workflow_name ? null : $workflow_name,
				'node_id'               => $node_id,
				'node_type'             => $node_type,
				'mode'                  => $mode,
				'status'                => 'received',
				'created_at'            => current_time( 'mysql', true ),
				'updated_at'            => current_time( 'mysql', true ),
			)
		);
		$wpdb->suppress_errors( $suppress );

		return $inserted ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Claim a queued step for execution. Exactly one caller wins.
	 *
	 * @param string $step_id
	 * @return bool
	 */
	private static function claim( $step_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic claim.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', updated_at = %s WHERE step_id = %s AND status = 'received'",
				WP_AI_Workflows_Database::site_steps_table(),
				current_time( 'mysql', true ),
				(string) $step_id
			)
		);
		return 1 === (int) $claimed;
	}

	/**
	 * Update a step's record.
	 *
	 * @param string $step_id
	 * @param array  $fields
	 * @return void
	 */
	private static function mark( $step_id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- status update.
		$wpdb->update(
			WP_AI_Workflows_Database::site_steps_table(),
			$fields,
			array( 'step_id' => (string) $step_id )
		);
	}

	/**
	 * One step's record.
	 *
	 * @param string $step_id
	 * @return object|null
	 */
	private static function get_row( $step_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- idempotency lookup on a unique key.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE step_id = %s",
				WP_AI_Workflows_Database::site_steps_table(),
				(string) $step_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * The answer for a step this site has already seen. A step that has finished
	 * returns what it returned before; one still in flight is still accepted.
	 *
	 * @param object $row
	 * @return array|WP_Error
	 */
	private static function replay( $row ) {
		if ( 'completed' === $row->status ) {
			$cached = get_transient( self::RESPONSE_PREFIX . $row->step_id );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			return array(
				'stepId'     => $row->step_id,
				'duplicate'  => true,
				'ranAt'      => strtotime( (string) $row->updated_at . ' UTC' ),
				'durationMs' => (int) $row->duration_ms,
			);
		}

		if ( in_array( $row->status, array( 'failed', 'expired' ), true ) ) {
			$code    = '' !== (string) $row->error_code ? (string) $row->error_code : 'step_failed';
			$message = '' !== (string) $row->error_message ? (string) $row->error_message : 'This step already failed on your site.';
			return self::error( $code, $message, 500 );
		}

		return array(
			'_status'  => 202,
			'accepted' => true,
			'stepId'   => $row->step_id,
		);
	}

	/* ---------------------------------------------------------------------------
	 * Payload handling
	 * ------------------------------------------------------------------------- */

	/**
	 * Whether this node type may run on the site at all. A type whose manifest
	 * keeps it in the cloud is never dispatchable here.
	 *
	 * @param string $type
	 * @return bool
	 */
	public static function type_allowed( $type ) {
		if ( '' === (string) $type || ! class_exists( 'WP_AI_Workflows_Node_Catalog' ) ) {
			return false;
		}
		$manifest = WP_AI_Workflows_Node_Catalog::get( (string) $type );
		if ( ! is_array( $manifest ) || empty( $manifest['locus'] ) ) {
			return false;
		}
		return in_array( (string) $manifest['locus'], array( 'site', 'either' ), true );
	}

	/**
	 * Whether this node runs long enough to need the cron path.
	 *
	 * @param string $type
	 * @param array  $config
	 * @return string 'sync' or 'async'
	 */
	private static function step_mode( $type, array $config ) {
		if ( ! class_exists( 'WP_AI_Workflows_Workflow_Translator' ) ) {
			return 'sync';
		}
		$plan = WP_AI_Workflows_Workflow_Translator::node_locus(
			array( 'id' => 'step', 'type' => (string) $type, 'data' => $config )
		);
		$mode = isset( $plan['siteStepMode'] ) ? (string) $plan['siteStepMode'] : 'sync';

		/**
		 * Force a node type onto the asynchronous path on a site where it is slow.
		 *
		 * @param bool   $async Whether this step should run on cron.
		 * @param string $type  Node type.
		 */
		if ( apply_filters( 'wp_ai_workflows_site_step_async', false, (string) $type ) ) {
			$mode = 'async';
		}

		return 'async' === $mode ? 'async' : 'sync';
	}

	/**
	 * When this step stops being worth finishing.
	 *
	 * @param string $type
	 * @param mixed  $requested
	 * @return int
	 */
	private static function deadline( $type, $requested ) {
		$ceiling = ( 'humanInput' === $type ) ? self::DEADLINE_HUMAN : self::DEADLINE_DEFAULT;
		$default = time() + $ceiling;

		$requested = (int) $requested;
		if ( $requested > time() && $requested <= $default ) {
			return $requested;
		}
		return $default;
	}

	/**
	 * Keep a step within this site's per-minute budget.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		$key   = self::RATE_PREFIX . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Persist the work an accepted step still has to do.
	 *
	 * @param array $job
	 * @param int   $deadline
	 * @return void
	 */
	private static function store_job( array $job, $deadline ) {
		$ttl = max( MINUTE_IN_SECONDS, (int) $deadline - time() + MINUTE_IN_SECONDS );
		set_transient( self::JOB_PREFIX . $job['step_id'], $job, $ttl );
	}

	/**
	 * Constrain an identifier to something safe to store and log.
	 *
	 * @param mixed $value
	 * @param int   $length
	 * @return string
	 */
	private static function clean_id( $value, $length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[^A-Za-z0-9_\-.:]/', '', (string) $value );
		return substr( (string) $value, 0, (int) $length );
	}

	/**
	 * Keep only well-formed upstream entries, keyed by their real node ids.
	 *
	 * @param mixed $inputs
	 * @return array
	 */
	private static function clean_inputs( $inputs ) {
		if ( ! is_array( $inputs ) ) {
			return array();
		}
		$out = array();
		foreach ( $inputs as $source_id => $entry ) {
			$key = self::clean_id( $source_id, 255 );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $key ] = $entry;
		}
		return $out;
	}

	/**
	 * Constrain what the platform may ask to be returned inline.
	 *
	 * @param mixed $value
	 * @return int
	 */
	private static function clean_max_bytes( $value ) {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return self::DEFAULT_MAX_RESULT_BYTES;
		}
		return max( 1024, min( $value, self::MAX_RESULT_BYTES_CEILING ) );
	}

	/**
	 * Accept a platform-supplied resume address only when it is the platform's.
	 *
	 * @param mixed $url
	 * @return string
	 */
	private static function clean_resume_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$base = WP_AI_Workflows_Platform_Client::base_url();
		return ( 0 === strpos( $url, $base . '/' ) ) ? $url : '';
	}

	/**
	 * A short, readable stand-in for a result too large to send inline.
	 *
	 * @param array $result
	 * @return string
	 */
	private static function preview( array $result ) {
		$content = isset( $result['content'] ) ? $result['content'] : '';
		if ( is_scalar( $content ) ) {
			return substr( (string) $content, 0, 500 );
		}
		$encoded = wp_json_encode( $content );
		return is_string( $encoded ) ? substr( $encoded, 0, 500 ) : '';
	}

	/**
	 * A typed failure carrying the HTTP status the callback answers with.
	 *
	 * @param string $code
	 * @param string $message
	 * @param int    $status
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}

	/**
	 * Payload-free record of what happened.
	 *
	 * @param string $step_id
	 * @param string $node_type
	 * @param string $status
	 * @param int    $elapsed
	 * @return void
	 */
	private static function log( $step_id, $node_type, $status, $elapsed ) {
		WP_AI_Workflows_Utilities::debug_log(
			'Site step ' . sanitize_key( $status ),
			'info',
			array(
				'step_id'     => sanitize_key( $step_id ),
				'node_type'   => sanitize_key( $node_type ),
				'duration_ms' => (int) $elapsed,
			)
		);
	}
}
