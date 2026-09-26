<?php
/**
 * The agent orchestrator: a multi-step generation pipeline with job persistence.
 *
 * Pipeline (each step is executed in its own REST request so nothing times out,
 * and every step is automatically retried until it passes or attempts run out):
 *
 *   plan (category + topic from the site prompt)
 *   → outline → intro → section×N → conclusion
 *   → copywrite (copywriting + SEO + originality revision pass)
 *   → faq → seo (Rank Math summary) → image (from topic + summary)
 *   → finalize (always saves a draft)
 *
 * Every step can use its OWN AI connection and prompt template — both are
 * managed from the admin (AIPC_Connections + AIPC_Steps).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the generation agent.
 */
final class AIPC_Agent {

	const OPTION        = 'aipc_jobs';
	const STATS_OPTION  = 'aipc_stats';
	const MAX_JOBS      = 30;
	const STALE_SECONDS = 86400;

	/**
	 * Singleton.
	 *
	 * @var AIPC_Agent|null
	 */
	private static $instance = null;

	/**
	 * API clients keyed by connection id.
	 *
	 * @var array
	 */
	private $clients = array();

	/**
	 * Get the shared instance.
	 *
	 * @return AIPC_Agent
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ---------------------------------------------------------------------
	 * Connections & prompts
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the connection a step must use (step override → default).
	 *
	 * @param string $step Logical step id (e.g. 'plan', 'image').
	 * @return array Connection data.
	 * @throws Exception When nothing is configured.
	 */
	private function resolve_connection( $step ) {
		$cfg  = AIPC_Steps::get( $step );
		$conn = null;

		if ( ! empty( $cfg['connection'] ) ) {
			$conn = AIPC_Connections::get( $cfg['connection'] );
		}
		if ( ! $conn ) {
			$conn = AIPC_Connections::get_default();
		}

		/**
		 * Filter the connection used by a step.
		 *
		 * @param array|null $conn Connection data.
		 * @param string     $step Logical step id.
		 */
		$conn = apply_filters( 'aipc_step_connection', $conn, $step );

		if ( ! $conn || empty( $conn['base_url'] ) ) {
			throw new Exception( __( 'No AI connection is configured yet. Add one under AI Post Creator → Connections.', 'wp-ai-post-creator' ) );
		}

		return $conn;
	}

	/**
	 * Cached API client for a step's connection.
	 *
	 * @param string $step Logical step id.
	 * @return AIPC_API_Client
	 * @throws Exception When nothing is configured.
	 */
	private function client_for( $step ) {
		$conn = $this->resolve_connection( $step );
		$key  = $conn['id'] ? $conn['id'] : md5( wp_json_encode( $conn ) );

		if ( ! isset( $this->clients[ $key ] ) ) {
			$this->clients[ $key ] = new AIPC_API_Client( $conn );
		}
		return $this->clients[ $key ];
	}

	/**
	 * Render a step prompt template with its placeholder values.
	 *
	 * @param string $step Logical step id.
	 * @param array  $args Placeholder values.
	 * @return string
	 */
	private function render_prompt( $step, array $args ) {
		$template = AIPC_Steps::prompt_for( $step );

		/**
		 * Filter the prompt template before placeholders are replaced.
		 *
		 * @param string $template Prompt template.
		 * @param string $step     Logical step id.
		 * @param array  $args     Placeholder values.
		 */
		$template = apply_filters( 'aipc_step_prompt', $template, $step, $args );

		return strtr( $template, $args );
	}

	/**
	 * Global placeholder values shared by every step.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private function global_args( array $job ) {
		$tones = AIPC_Settings::tones();
		$tone  = isset( $tones[ $job['args']['tone'] ] ) ? $tones[ $job['args']['tone'] ] : 'professional';

		$site = trim( (string) AIPC_Settings::get( 'site_prompt' ) );
		if ( '' !== $site ) {
			$site_prompt = ' SITE CONTEXT — what this website is about (every topic, title and sentence must fit this): ' . $site;
		} else {
			$site_prompt = '';
		}

		return array(
			'{{lang}}'        => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
			'{{tone}}'        => $tone,
			'{{site_prompt}}' => $site_prompt,
			'{{extra}}'       => trim( (string) AIPC_Settings::get( 'system_prompt_extra' ) ),
		);
	}

	/**
	 * The system prompt, rendered from the 'system' template.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	private function system_prompt( array $job ) {
		$p = $this->render_prompt( 'system', $this->global_args( $job ) );
		return apply_filters( 'aipc_system_prompt', $p, $job );
	}

	/* ---------------------------------------------------------------------
	 * Job storage
	 * ------------------------------------------------------------------- */

	/**
	 * Load all jobs.
	 *
	 * @return array
	 */
	private function all_jobs() {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Persist all jobs (autoload off).
	 *
	 * @param array $jobs Jobs keyed by id.
	 * @return void
	 */
	private function save_all_jobs( $jobs ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $jobs, '', false );
		} else {
			update_option( self::OPTION, $jobs );
		}
	}

	/**
	 * Fetch one job.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public function get_job( $id ) {
		$jobs = $this->all_jobs();
		$id   = (string) $id;
		return isset( $jobs[ $id ] ) && is_array( $jobs[ $id ] ) ? $jobs[ $id ] : null;
	}

	/**
	 * All jobs, newest first (for the logs screen).
	 *
	 * @return array
	 */
	public function get_all_jobs() {
		$jobs = $this->all_jobs();
		uasort( $jobs, function ( $a, $b ) {
			return (int) $b['created'] - (int) $a['created'];
		} );
		return $jobs;
	}

	/**
	 * Delete one job (from the logs screen).
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public function delete_job( $id ) {
		$jobs = $this->all_jobs();
		if ( ! isset( $jobs[ (string) $id ] ) ) {
			return false;
		}
		unset( $jobs[ (string) $id ] );
		$this->save_all_jobs( $jobs );
		return true;
	}

	/**
	 * Clear all jobs/logs.
	 *
	 * @return void
	 */
	public function clear_jobs() {
		$this->save_all_jobs( array() );
	}

	/**
	 * Save one job.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public function save_job( $job ) {
		$jobs           = $this->all_jobs();
		$job['updated'] = time();
		$jobs[ $job['id'] ] = $job;
		$this->save_all_jobs( $jobs );
	}

	/**
	 * Remove old jobs (daily cron + on job creation).
	 *
	 * @return void
	 */
	public function cleanup() {
		$jobs = $this->all_jobs();
		if ( empty( $jobs ) ) {
			return;
		}
		uasort( $jobs, function ( $a, $b ) {
			return (int) $b['created'] - (int) $a['created'];
		} );
		$keep = array();
		$i    = 0;
		foreach ( $jobs as $id => $job ) {
			$i++;
			$stale = ( time() - (int) $job['created'] ) > self::STALE_SECONDS;
			if ( $i <= self::MAX_JOBS && ! $stale ) {
				$keep[ $id ] = $job;
			}
		}
		if ( count( $keep ) !== count( $jobs ) ) {
			$this->save_all_jobs( $keep );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function cleanup_static() {
		self::instance()->cleanup();
	}

	/* ---------------------------------------------------------------------
	 * Usage stats
	 * ------------------------------------------------------------------- */

	/**
	 * Read the aggregate stats.
	 *
	 * @return array
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		$defaults = array(
			'jobs'              => 0,
			'done'              => 0,
			'error'             => 0,
			'cancelled'         => 0,
			'calls'             => 0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'by_connection'     => array(),
		);
		return wp_parse_args( is_array( $stats ) ? $stats : array(), $defaults );
	}

	/**
	 * Record a finished job into the aggregate stats (once per job).
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 */
	private function record_stats( array &$job ) {
		if ( ! empty( $job['stats_recorded'] ) ) {
			return;
		}
		$job['stats_recorded'] = 1;

		$stats = self::stats();
		$stats['jobs']++;
		if ( isset( $stats[ $job['status'] ] ) ) {
			$stats[ $job['status'] ]++;
		}
		$stats['calls']             += (int) $job['usage']['calls'];
		$stats['prompt_tokens']     += (int) $job['usage']['prompt'];
		$stats['completion_tokens'] += (int) $job['usage']['completion'];

		foreach ( (array) $job['calls'] as $call ) {
			$name = isset( $call['conn'] ) && '' !== $call['conn'] ? $call['conn'] : __( 'Unknown connection', 'wp-ai-post-creator' );
			if ( ! isset( $stats['by_connection'][ $name ] ) ) {
				$stats['by_connection'][ $name ] = array( 'calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0 );
			}
			$stats['by_connection'][ $name ]['calls']++;
			$stats['by_connection'][ $name ]['prompt_tokens']     += (int) $call['pt'];
			$stats['by_connection'][ $name ]['completion_tokens'] += (int) $call['ct'];
		}

		if ( false === get_option( self::STATS_OPTION, false ) ) {
			add_option( self::STATS_OPTION, $stats, '', false );
		} else {
			update_option( self::STATS_OPTION, $stats );
		}
	}

	/* ---------------------------------------------------------------------
	 * Job lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Validate and normalize job arguments.
	 *
	 * @param array $args Raw args.
	 * @param array $s    Settings.
	 * @return array
	 */
	private function sanitize_args( $args, $s ) {
		$args = is_array( $args ) ? $args : array();

		$tone = isset( $args['tone'] ) ? sanitize_key( $args['tone'] ) : '';
		if ( ! array_key_exists( $tone, AIPC_Settings::tones() ) ) {
			$tone = $s['default_tone'];
		}

		$length = isset( $args['length'] ) ? sanitize_key( $args['length'] ) : '';
		if ( ! array_key_exists( $length, AIPC_Settings::lengths() ) ) {
			$length = $s['default_length'];
		}

		$language = isset( $args['language'] ) ? sanitize_key( $args['language'] ) : '';
		if ( '' === $language || ! array_key_exists( $language, AIPC_Settings::languages() ) ) {
			$language = $s['content_language'];
		}

		return array(
			'tone'            => $tone,
			'length'          => $length,
			'language'        => $language,
			'language_custom' => isset( $args['language_custom'] ) ? mb_substr( sanitize_text_field( $args['language_custom'] ), 0, 40 ) : '',
			'image'           => empty( $args['image'] ) ? 0 : 1,
			'faq'             => empty( $args['faq'] ) ? 0 : 1,
			'toc'             => empty( $args['toc'] ) ? 0 : 1,
		);
	}

	/**
	 * Create a new job. The topic is optional: when empty, the agent invents
	 * one from the site prompt during the plan step.
	 *
	 * @param string $topic Optional topic hint.
	 * @param array  $args  Options from the request.
	 * @return array|WP_Error Job or error.
	 */
	public function create_job( $topic, $args = array() ) {
		if ( ! AIPC_Connections::get_default() ) {
			return new WP_Error( 'aipc_config', __( 'No AI connection is configured yet. Add one under AI Post Creator → Connections.', 'wp-ai-post-creator' ) );
		}

		$topic = trim( wp_strip_all_tags( (string) $topic ) );
		if ( mb_strlen( $topic ) > 400 ) {
			$topic = mb_substr( $topic, 0, 400 );
		}

		$s   = AIPC_Settings::all();
		$id  = 'job_' . strtolower( wp_generate_password( 12, false, false ) );
		$job = array(
			'id'         => $id,
			'created'    => time(),
			'updated'    => time(),
			'status'     => 'running',
			'topic'      => $topic,
			'args'       => $this->sanitize_args( $args, $s ),
			'model'      => '',
			'cursor'     => 0,
			'steps'      => array(
				array( 'id' => 'plan', 'label' => __( 'Category & topic selection', 'wp-ai-post-creator' ), 'status' => 'pending' ),
				array( 'id' => 'outline', 'label' => __( 'Article outline', 'wp-ai-post-creator' ), 'status' => 'pending' ),
			),
			'data'       => array(),
			'usage'      => array( 'prompt' => 0, 'completion' => 0, 'calls' => 0 ),
			'calls'      => array(),
			'timings'    => array(),
			'log'        => array(),
			'post_id'    => 0,
			'error'      => null,
			'stats_recorded' => 0,
		);

		$default_conn = AIPC_Connections::get_default();
		$job['model'] = $default_conn['chat_model'];

		if ( '' !== $topic ) {
			$this->log( $job, sprintf(
				/* translators: %s: topic. */
				__( 'Agent started — suggested topic: “%s”', 'wp-ai-post-creator' ),
				$topic
			), 'info' );
		} else {
			$this->log( $job, __( 'Agent started — the topic will be invented from the site prompt.', 'wp-ai-post-creator' ), 'info' );
		}
		$this->log( $job, sprintf(
			/* translators: 1: connection name, 2: model name, 3: tone, 4: length. */
			__( 'Setup — default connection: %1$s (%2$s) · tone: %3$s · length: %4$s', 'wp-ai-post-creator' ),
			$default_conn['name'],
			$default_conn['chat_model'],
			$job['args']['tone'],
			$job['args']['length']
		), 'info' );

		$this->save_job( $job );
		$this->cleanup();
		return $job;
	}

	/**
	 * Cancel a running job.
	 *
	 * @param string $id Job id.
	 * @return array|WP_Error
	 */
	public function cancel_job( $id ) {
		$job = $this->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'aipc_job', __( 'Job not found or already cleaned up.', 'wp-ai-post-creator' ) );
		}
		if ( 'running' === $job['status'] || 'error' === $job['status'] ) {
			$job['status'] = 'cancelled';
			$this->log( $job, __( 'Agent cancelled by user.', 'wp-ai-post-creator' ), 'warn' );
			$this->record_stats( $job );
			$this->save_job( $job );
		}
		return $job;
	}

	/**
	 * Retry the failed step of a job.
	 *
	 * @param string $id Job id.
	 * @return array|WP_Error
	 */
	public function retry_job( $id ) {
		$job = $this->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'aipc_job', __( 'Job not found or already cleaned up.', 'wp-ai-post-creator' ) );
		}
		if ( 'error' !== $job['status'] ) {
			return $job;
		}
		$idx = $job['cursor'];
		if ( isset( $job['steps'][ $idx ] ) ) {
			$job['steps'][ $idx ]['status'] = 'pending';
		}
		$job['status'] = 'running';
		$job['error']  = null;
		$this->log( $job, __( 'Retrying failed step…', 'wp-ai-post-creator' ), 'info' );
		$this->save_job( $job );
		return $job;
	}

	/* ---------------------------------------------------------------------
	 * Step execution
	 * ------------------------------------------------------------------- */

	/**
	 * Execute the next step of a job. Each call runs exactly one step so the
	 * browser can stream progress without hitting PHP execution limits.
	 *
	 * Every step is automatically retried up to N attempts when it fails, so
	 * transient model errors don't abort the run.
	 *
	 * @param string $id    Job id.
	 * @param int    $since Log index the client already has.
	 * @return array|WP_Error Client state.
	 */
	public function execute_step( $id, $since = 0 ) {
		$job = $this->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'aipc_job', __( 'Job not found or expired. Please start again.', 'wp-ai-post-creator' ) );
		}

		if ( 'running' !== $job['status'] ) {
			return $this->client_state( $job, $since );
		}

		$lock_key = 'aipc_lock_' . $job['id'];
		if ( get_transient( $lock_key ) ) {
			$state         = $this->client_state( $job, $since );
			$state['busy'] = true;
			return $state;
		}
		set_transient( $lock_key, 1, 600 );

		// Give slow steps room to finish (retries included).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		$step = isset( $job['steps'][ $job['cursor'] ] ) ? $job['steps'][ $job['cursor'] ] : null;
		if ( ! $step ) {
			delete_transient( $lock_key );
			return $this->client_state( $job, $since );
		}

		$logical = ( 0 === strpos( $step['id'], 'section_' ) ) ? 'section' : $step['id'];

		$max_attempts = (int) apply_filters( 'aipc_step_attempts', 3, $job, $step['id'] );
		$attempt      = 0;
		$passed       = false;
		$last_error   = null;
		$t0           = microtime( true );

		while ( ! $passed && $attempt < $max_attempts ) {
			$attempt++;
			try {
				$this->run_step( $job, $step['id'] );
				$passed = true;
			} catch ( Exception $e ) {
				$last_error = $e->getMessage();
				if ( $attempt < $max_attempts ) {
					$this->log( $job, sprintf(
						/* translators: 1: attempt number, 2: total attempts, 3: error message. */
						__( 'Attempt %1$d/%2$d failed (%3$s) — retrying…', 'wp-ai-post-creator' ),
						$attempt,
						$max_attempts,
						$last_error
					), 'warn' );
					$this->save_job( $job );
				}
			}
		}

		// Step timing (all attempts).
		$job['timings'][ $step['id'] ] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( ! $passed ) {
			$idx = $job['cursor'];
			if ( isset( $job['steps'][ $idx ] ) ) {
				$job['steps'][ $idx ]['status'] = 'failed';
			}
			$job['status'] = 'error';
			$job['error']  = array(
				'step'    => $step['id'],
				'message' => $last_error,
			);
			$this->log( $job, sprintf(
				/* translators: %s: error message. */
				__( 'Step failed: %s', 'wp-ai-post-creator' ),
				$last_error
			), 'error' );
			$this->record_stats( $job );
			$this->save_job( $job );
			delete_transient( $lock_key );
			return $this->client_state( $job, $since );
		}

		$this->save_job( $job );
		delete_transient( $lock_key );
		return $this->client_state( $job, $since );
	}

	/**
	 * Dispatch a single step.
	 *
	 * @param array  $job     Job (by ref).
	 * @param string $step_id Step id.
	 * @return void
	 * @throws Exception On any failure.
	 */
	private function run_step( array &$job, $step_id ) {
		switch ( $step_id ) {
			case 'plan':
				$this->step_plan( $job );
				break;
			case 'outline':
				$this->step_outline( $job );
				break;
			case 'intro':
				$this->step_intro( $job );
				break;
			case 'conclusion':
				$this->step_conclusion( $job );
				break;
			case 'copywrite':
				$this->step_copywrite( $job );
				break;
			case 'faq':
				$this->step_faq( $job );
				break;
			case 'seo':
				$this->step_seo( $job );
				break;
			case 'image':
				$this->step_image( $job );
				break;
			case 'finalize':
				$this->step_finalize( $job );
				break;
			default:
				if ( 0 === strpos( $step_id, 'section_' ) ) {
					$this->step_section( $job, (int) substr( $step_id, 8 ) );
					break;
				}
				throw new Exception( 'Unknown step: ' . $step_id );
		}
	}

	/**
	 * Mark the current step complete and move the cursor forward.
	 *
	 * @param array  $job    Job (by ref).
	 * @param string $status done|skipped.
	 * @return void
	 */
	private function advance( array &$job, $status = 'done' ) {
		$idx = $job['cursor'];
		if ( isset( $job['steps'][ $idx ] ) ) {
			$job['steps'][ $idx ]['status'] = $status;
		}
		$job['cursor']++;
	}

	/* ---------------------------------------------------------------------
	 * AI request helpers (per-step connection + prompt + call log)
	 * ------------------------------------------------------------------- */

	/**
	 * Record one API call in the job log.
	 *
	 * @param array  $job   Job (by ref).
	 * @param string $step  Logical step id.
	 * @param string $conn  Connection name.
	 * @param string $model Model used.
	 * @param array  $usage {prompt_tokens, completion_tokens}.
	 * @param float  $ms    Duration in milliseconds.
	 * @param bool   $ok    Success flag.
	 * @param string $err   Error message.
	 * @return void
	 */
	private function record_call( array &$job, $step, $conn, $model, $usage, $ms, $ok = true, $err = '' ) {
		$job['calls'][] = array(
			'step'    => $step,
			'label'   => isset( $job['steps'][ $job['cursor'] ]['label'] ) ? $job['steps'][ $job['cursor'] ]['label'] : $step,
			'conn'    => $conn,
			'model'   => $model,
			'pt'      => is_array( $usage ) && isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0,
			'ct'      => is_array( $usage ) && isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
			'ms'      => (int) round( $ms ),
			'ok'      => $ok ? 1 : 0,
			'err'     => $ok ? '' : mb_substr( (string) $err, 0, 300 ),
			't'       => time(),
		);
	}

	/**
	 * Current step label (for call logs).
	 *
	 * @param array $job Job.
	 * @return string
	 */
	private function current_label( array $job ) {
		return isset( $job['steps'][ $job['cursor'] ]['label'] ) ? $job['steps'][ $job['cursor'] ]['label'] : '';
	}

	/**
	 * JSON chat wrapper bound to a step's connection and prompt template.
	 *
	 * @param array  $job  Job (by ref).
	 * @param string $step Logical step id.
	 * @param array  $args Placeholder values.
	 * @param array  $opts Extra chat options.
	 * @return array Decoded JSON.
	 * @throws Exception On failure.
	 */
	private function ask_json( array &$job, $step, array $args = array(), $opts = array() ) {
		$client = $this->client_for( $step );
		$conn   = $this->resolve_connection( $step );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->system_prompt( $job ) . ' Respond ONLY with a single valid JSON object — no markdown fences, no commentary.',
			),
			array( 'role' => 'user', 'content' => $this->render_prompt( $step, $args ) ),
		);
		$messages = apply_filters( 'aipc_messages', $messages, $job, $step );

		$t0  = microtime( true );
		$res = $client->chat_json( $messages, $opts );
		$ms  = ( microtime( true ) - $t0 ) * 1000;

		if ( is_wp_error( $res ) ) {
			$this->record_call( $job, $step, $conn['name'], $conn['chat_model'], array(), $ms, false, $res->get_error_message() );
			throw new Exception( $res->get_error_message() );
		}

		$this->record_call( $job, $step, $conn['name'], $res['model'], $res['usage'], $ms );
		$this->add_usage( $job, $res['usage'] );
		return $res['data'];
	}

	/**
	 * HTML chat wrapper bound to a step's connection and prompt template.
	 *
	 * @param array  $job      Job (by ref).
	 * @param string $step     Logical step id.
	 * @param array  $args     Placeholder values.
	 * @param array  $opts     Extra chat options.
	 * @param int    $min_word Minimum word count.
	 * @return string HTML.
	 * @throws Exception On failure.
	 */
	private function ask_html( array &$job, $step, array $args = array(), $opts = array(), $min_word = 30 ) {
		$client = $this->client_for( $step );
		$conn   = $this->resolve_connection( $step );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->system_prompt( $job ) . ' Respond with raw HTML fragments only — no markdown, no code fences, no commentary.',
			),
			array( 'role' => 'user', 'content' => $this->render_prompt( $step, $args ) ),
		);
		$messages = apply_filters( 'aipc_messages', $messages, $job, $step );

		$t0  = microtime( true );
		$res = $client->chat( $messages, $opts );
		$ms  = ( microtime( true ) - $t0 ) * 1000;

		if ( is_wp_error( $res ) ) {
			$this->record_call( $job, $step, $conn['name'], $conn['chat_model'], array(), $ms, false, $res->get_error_message() );
			throw new Exception( $res->get_error_message() );
		}

		$this->record_call( $job, $step, $conn['name'], $res['model'], $res['usage'], $ms );
		$this->add_usage( $job, $res['usage'] );

		$html = trim( $res['content'] );
		$html = preg_replace( '/^```(?:html)?\s*/i', '', $html );
		$html = preg_replace( '/\s*```$/', '', $html );
		$html = trim( $html );
		$html = wp_kses_post( $html );

		$words = self::count_words( $html );
		if ( $words < $min_word ) {
			throw new Exception( __( 'The model returned too little content for this step. Please retry.', 'wp-ai-post-creator' ) );
		}
		return $html;
	}

	/**
	 * Accumulate token usage.
	 *
	 * @param array $job   Job (by ref).
	 * @param array $usage Usage payload from the provider.
	 * @return void
	 */
	private function add_usage( array &$job, $usage ) {
		if ( ! is_array( $usage ) ) {
			return;
		}
		$job['usage']['prompt']     += isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$job['usage']['completion'] += isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
		$job['usage']['calls']++;
	}

	/**
	 * Append a console log entry.
	 *
	 * @param array  $job   Job (by ref).
	 * @param string $msg   Message.
	 * @param string $level info|success|warn|error.
	 * @return void
	 */
	private function log( array &$job, $msg, $level = 'info' ) {
		$job['log'][] = array(
			't'     => time(),
			'level' => $level,
			'msg'   => $msg,
		);
	}

	/**
	 * Unicode-safe word count.
	 *
	 * @param string $html HTML or text.
	 * @return int
	 */
	public static function count_words( $html ) {
		$text = trim( wp_strip_all_tags( (string) $html ) );
		if ( '' === $text ) {
			return 0;
		}
		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? count( $parts ) : 0;
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------- */

	/**
	 * Step: pick a category from the existing post categories and build the
	 * topic from the site prompt (plus the optional user hint).
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_plan( array &$job ) {
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );
		$spec = AIPC_Settings::length_specs( $job['args']['length'] );

		$categories = get_categories( array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		) );

		$cat_lines = '';
		foreach ( $categories as $category ) {
			$desc = trim( (string) $category->description );
			$cat_lines .= '- ' . $category->name . ( '' !== $desc ? ' — ' . mb_substr( $desc, 0, 120 ) : '' ) . "\n";
		}
		if ( '' === $cat_lines ) {
			$cat_lines = '- ' . __( 'Uncategorized', 'wp-ai-post-creator' ) . "\n";
		}

		$site = trim( (string) AIPC_Settings::get( 'site_prompt' ) );
		$site_context = ( '' !== $site )
			? $site
			: __( '(No site prompt configured — write for a general audience.)', 'wp-ai-post-creator' );

		$topic_hint = ( '' !== $job['topic'] )
			? sprintf(
				/* translators: %s: user topic. */
				__( 'The user suggests this topic: "%s" — build the article around it, adapted to the site context.', 'wp-ai-post-creator' ),
				$job['topic']
			)
			: __( 'No topic given — invent the single best topic yourself. It must fit the site context AND the chosen category.', 'wp-ai-post-creator' );

		$args = array(
			'{{site_context}}' => $site_context,
			'{{categories}}'   => trim( $cat_lines ),
			'{{topic_hint}}'   => $topic_hint,
			'{{words}}'        => (string) $spec['words'],
			'{{sections}}'     => (string) $spec['sections'],
			'{{lang}}'         => $lang,
		);

		$data = $this->ask_json( $job, 'plan', $args );

		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			throw new Exception( __( 'The plan did not include a title. Please retry.', 'wp-ai-post-creator' ) );
		}

		$chosen_id = 0;
		$chosen_name = isset( $data['category'] ) ? trim( (string) $data['category'] ) : '';
		if ( '' !== $chosen_name ) {
			foreach ( $categories as $category ) {
				if ( mb_strtolower( $category->name ) === mb_strtolower( $chosen_name ) ) {
					$chosen_id   = (int) $category->term_id;
					$chosen_name = $category->name;
					break;
				}
			}
		}
		if ( ! $chosen_id ) {
			throw new Exception( __( 'The model did not pick a category from the list. Retrying.', 'wp-ai-post-creator' ) );
		}

		if ( ! is_array( $data['secondary_keywords'] ) ) {
			$data['secondary_keywords'] = array();
		}
		$data['secondary_keywords'] = array_slice( array_map( 'sanitize_text_field', $data['secondary_keywords'] ), 0, 8 );
		$data['category']     = $chosen_name;
		$data['category_id']  = $chosen_id;

		$job['data']['plan'] = $data;
		if ( '' === $job['topic'] ) {
			$job['topic'] = (string) $data['title'];
		}

		$this->log( $job, sprintf(
			/* translators: %s: category name. */
			__( 'Chosen category: %s', 'wp-ai-post-creator' ),
			$chosen_name
		), 'info' );
		$this->log( $job, sprintf(
			/* translators: %s: audience description. */
			__( 'Audience: %s', 'wp-ai-post-creator' ),
			isset( $data['audience'] ) ? (string) $data['audience'] : '—'
		), 'info' );
		$this->log( $job, sprintf(
			/* translators: %s: keyword. */
			__( 'Primary keyword: %s', 'wp-ai-post-creator' ),
			isset( $data['primary_keyword'] ) ? (string) $data['primary_keyword'] : '—'
		), 'info' );
		if ( ! empty( $data['secondary_keywords'] ) ) {
			$this->log( $job, sprintf(
				/* translators: %s: comma separated keywords. */
				__( 'Secondary keywords: %s', 'wp-ai-post-creator' ),
				implode( ', ', $data['secondary_keywords'] )
			), 'info' );
		}
		$this->log( $job, sprintf(
			/* translators: %s: title. */
			__( 'Working title: “%s”', 'wp-ai-post-creator' ),
			$data['title']
		), 'success' );

		$this->advance( $job );
	}

	/**
	 * Step: build the outline and extend the step manifest.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_outline( array &$job ) {
		$plan = $job['data']['plan'];
		$spec = AIPC_Settings::length_specs( $job['args']['length'] );
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{topic_brief}}'     => isset( $plan['topic_brief'] ) ? (string) $plan['topic_brief'] : '',
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{angle}}'           => isset( $plan['angle'] ) ? (string) $plan['angle'] : '',
			'{{words}}'           => (string) $spec['words'],
			'{{sections}}'        => (string) $spec['sections'],
			'{{lang}}'            => $lang,
		);

		$data     = $this->ask_json( $job, 'outline', $args );
		$sections = array();

		if ( ! empty( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( $data['sections'] as $section ) {
				if ( empty( $section['heading'] ) ) {
					continue;
				}
				$sections[] = array(
					'heading' => sanitize_text_field( (string) $section['heading'] ),
					'brief'   => isset( $section['brief'] ) ? sanitize_text_field( (string) $section['brief'] ) : '',
				);
			}
		}

		if ( count( $sections ) < 2 ) {
			throw new Exception( __( 'The outline was empty or malformed. Please retry.', 'wp-ai-post-creator' ) );
		}

		$job['data']['outline'] = $sections;

		$new_steps   = array();
		$new_steps[] = array( 'id' => 'intro', 'label' => __( 'Introduction', 'wp-ai-post-creator' ), 'status' => 'pending' );
		foreach ( $sections as $i => $section ) {
			$new_steps[] = array(
				'id'     => 'section_' . $i,
				'label'  => $section['heading'],
				'status' => 'pending',
			);
		}
		$new_steps[] = array( 'id' => 'conclusion', 'label' => __( 'Conclusion & call to action', 'wp-ai-post-creator' ), 'status' => 'pending' );
		$new_steps[] = array( 'id' => 'copywrite', 'label' => __( 'Copywriting & SEO pass', 'wp-ai-post-creator' ), 'status' => 'pending' );
		if ( $job['args']['faq'] ) {
			$new_steps[] = array( 'id' => 'faq', 'label' => __( 'FAQ section (rich results)', 'wp-ai-post-creator' ), 'status' => 'pending' );
		}
		$new_steps[] = array( 'id' => 'seo', 'label' => __( 'SEO summary for Rank Math', 'wp-ai-post-creator' ), 'status' => 'pending' );
		if ( $job['args']['image'] && AIPC_Settings::get( 'image_enabled' ) ) {
			$new_steps[] = array( 'id' => 'image', 'label' => __( 'Featured image', 'wp-ai-post-creator' ), 'status' => 'pending' );
		}
		$new_steps[] = array( 'id' => 'finalize', 'label' => __( 'Save post', 'wp-ai-post-creator' ), 'status' => 'pending' );

		array_splice( $job['steps'], $job['cursor'] + 1, 0, $new_steps );

		$this->log( $job, sprintf(
			/* translators: %d: section count. */
			_n( 'Outline ready — %d section.', 'Outline ready — %d sections.', count( $sections ), 'wp-ai-post-creator' ),
			count( $sections )
		), 'success' );
		foreach ( $sections as $section ) {
			$this->log( $job, '• ' . $section['heading'], 'info' );
		}

		$this->advance( $job );
	}

	/**
	 * Step: introduction.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_intro( array &$job ) {
		$plan = $job['data']['plan'];

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{topic_brief}}'     => isset( $plan['topic_brief'] ) ? (string) $plan['topic_brief'] : $job['topic'],
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{audience}}'        => isset( $plan['audience'] ) ? (string) $plan['audience'] : '',
			'{{lang}}'            => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$html = $this->ask_html( $job, 'intro', $args, array(), 40 );

		$job['data']['content']['intro'] = $html;
		$this->log( $job, sprintf(
			/* translators: %d: word count. */
			__( 'Introduction written (%d words)', 'wp-ai-post-creator' ),
			self::count_words( $html )
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: one body section.
	 *
	 * @param array $job Job (by ref).
	 * @param int   $i   Section index.
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_section( array &$job, $i ) {
		$outline = $job['data']['outline'];
		if ( ! isset( $outline[ $i ] ) ) {
			throw new Exception( 'Missing section #' . $i );
		}

		$plan      = $job['data']['plan'];
		$section   = $outline[ $i ];
		$spec      = AIPC_Settings::length_specs( $job['args']['length'] );
		$total     = count( $outline );
		$per_words = max( 120, (int) round( $spec['words'] / ( $total + 2 ) ) );
		$keywords  = isset( $plan['secondary_keywords'] ) ? $plan['secondary_keywords'] : array();

		$keywords_block = '';
		if ( ! empty( $keywords ) ) {
			$keywords_block = 'SECONDARY KEYWORDS (weave in if relevant): ' . implode( ', ', array_slice( $keywords, 0, 4 ) ) . "\n";
		}

		$prev_block = '';
		if ( $i > 0 && isset( $job['data']['content']['sections'][ $i - 1 ] ) ) {
			$prev = wp_strip_all_tags( $job['data']['content']['sections'][ $i - 1 ] );
			$prev = mb_substr( trim( preg_replace( '/\s+/u', ' ', $prev ) ), -220 );
			$prev_block = "PREVIOUS SECTION ENDS WITH: …" . $prev . "\n(maintain flow — do not repeat information)\n";
		}

		$args = array(
			'{{index}}'           => (string) ( $i + 1 ),
			'{{total}}'           => (string) $total,
			'{{title}}'           => $plan['title'],
			'{{section_heading}}' => $section['heading'],
			'{{section_brief}}'   => $section['brief'],
			'{{per_words}}'       => (string) $per_words,
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{keywords_block}}'  => $keywords_block,
			'{{prev_block}}'      => $prev_block,
		);

		$html = $this->ask_html( $job, 'section', $args, array(), 40 );

		$job['data']['content']['sections'][ $i ] = $html;
		$this->log( $job, sprintf(
			/* translators: 1: word count, 2: section heading. */
			__( 'Section written (%1$d words) — %2$s', 'wp-ai-post-creator' ),
			self::count_words( $html ),
			$section['heading']
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: conclusion.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_conclusion( array &$job ) {
		$plan = $job['data']['plan'];

		$headings = '';
		foreach ( $job['data']['outline'] as $section ) {
			$headings .= '- ' . $section['heading'] . "\n";
		}

		$args = array(
			'{{title}}'    => $plan['title'],
			'{{headings}}' => trim( $headings ),
			'{{lang}}'     => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$html = $this->ask_html( $job, 'conclusion', $args, array(), 40 );

		$job['data']['content']['conclusion'] = $html;
		$this->log( $job, sprintf(
			/* translators: %d: word count. */
			__( 'Conclusion written (%d words)', 'wp-ai-post-creator' ),
			self::count_words( $html )
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: the copywriting / SEO / originality revision pass.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_copywrite( array &$job ) {
		$d    = $job['data'];
		$plan = $d['plan'];
		$n    = count( $d['outline'] );

		$draft = isset( $d['content']['intro'] ) ? trim( $d['content']['intro'] ) : '';
		foreach ( $d['outline'] as $i => $section ) {
			$sec   = isset( $d['content']['sections'][ $i ] ) ? trim( $d['content']['sections'][ $i ] ) : '';
			$draft .= "\n\n" . '<h2 id="aipc-s-' . (int) $i . '">' . esc_html( $section['heading'] ) . '</h2>' . "\n" . $sec;
		}
		if ( ! empty( $d['content']['conclusion'] ) ) {
			$draft .= "\n\n" . trim( $d['content']['conclusion'] );
		}

		$orig_words = self::count_words( $draft );

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{sections}}'        => (string) $n,
			'{{draft}}'           => $draft,
		);

		$conn   = $this->resolve_connection( 'copywrite' );
		$opts   = array( 'max_tokens' => min( 16000, max( (int) $conn['max_tokens'], 6000 ) ) );
		$revised = $this->ask_html( $job, 'copywrite', $args, $opts, 150 );

		$parts = preg_split( '/(?=<h2\b)/i', $revised, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_values( array_filter( array_map( 'trim', $parts ), function ( $part ) {
			return '' !== $part;
		} ) );

		if ( count( $parts ) !== $n + 2 ) {
			throw new Exception( __( 'The revision lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
		}

		$new_words = self::count_words( $revised );
		if ( $orig_words > 0 && $new_words < (int) floor( $orig_words * 0.6 ) ) {
			throw new Exception( __( 'The revision came back much shorter than the draft. Retrying.', 'wp-ai-post-creator' ) );
		}

		$intro      = trim( array_shift( $parts ) );
		$conclusion = trim( array_pop( $parts ) );

		if ( count( $parts ) !== $n ) {
			throw new Exception( __( 'The revision lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
		}

		foreach ( $parts as $i => $part ) {
			if ( ! preg_match( '#^<h2([^>]*)>(.*?)</h2>#is', $part, $m ) ) {
				throw new Exception( __( 'The revision lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
			}
			$heading = trim( wp_strip_all_tags( $m[2] ) );
			if ( '' === $heading ) {
				throw new Exception( __( 'The revision lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
			}
			$job['data']['outline'][ $i ]['heading'] = sanitize_text_field( $heading );
			$job['data']['content']['sections'][ $i ] = trim( substr( $part, strlen( $m[0] ) ) );
		}

		$job['data']['content']['intro']      = $intro;
		$job['data']['content']['conclusion'] = $conclusion;

		$this->log( $job, sprintf(
			/* translators: 1: original word count, 2: revised word count. */
			__( 'Copywriting & SEO pass complete (%1$d → %2$d words)', 'wp-ai-post-creator' ),
			$orig_words,
			$new_words
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: FAQ.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_faq( array &$job ) {
		$plan = $job['data']['plan'];

		$args = array(
			'{{title}}' => $plan['title'],
			'{{lang}}'  => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$data = $this->ask_json( $job, 'faq', $args );

		$items = array();
		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$items[] = array(
					'q' => sanitize_text_field( (string) $item['q'] ),
					'a' => sanitize_text_field( (string) $item['a'] ),
				);
			}
		}
		if ( count( $items ) < 2 ) {
			throw new Exception( __( 'The FAQ step returned no usable questions. Please retry.', 'wp-ai-post-creator' ) );
		}

		$job['data']['faq'] = array(
			'heading' => ! empty( $data['faq_heading'] ) ? sanitize_text_field( (string) $data['faq_heading'] ) : __( 'Frequently asked questions', 'wp-ai-post-creator' ),
			'items'   => array_slice( $items, 0, 6 ),
		);

		$this->log( $job, sprintf(
			/* translators: %d: number of questions. */
			__( 'FAQ ready — %d questions (FAQ schema will be added)', 'wp-ai-post-creator' ),
			count( $job['data']['faq']['items'] )
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: SEO metadata + the Rank Math summary.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_seo( array &$job ) {
		$plan  = $job['data']['plan'];
		$intro = isset( $job['data']['content']['intro'] ) ? mb_substr( wp_strip_all_tags( $job['data']['content']['intro'] ), 0, 300 ) : '';

		$args = array(
			'{{title}}' => $plan['title'],
			'{{intro}}' => $intro,
			'{{lang}}'  => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$data = $this->ask_json( $job, 'seo', $args );

		if ( empty( $data['meta_title'] ) || empty( $data['meta_description'] ) ) {
			throw new Exception( __( 'SEO metadata was incomplete. Please retry.', 'wp-ai-post-creator' ) );
		}

		if ( ! is_array( $data['tags'] ) ) {
			$data['tags'] = array();
		}
		$data['tags'] = array_slice( array_map( 'sanitize_text_field', $data['tags'] ), 0, 8 );

		$job['data']['seo'] = array(
			'meta_title'       => sanitize_text_field( (string) $data['meta_title'] ),
			'meta_description' => mb_substr( sanitize_text_field( (string) $data['meta_description'] ), 0, 200 ),
			'slug'             => sanitize_title( isset( $data['slug'] ) ? (string) $data['slug'] : $plan['title'] ),
			'excerpt'          => mb_substr( sanitize_text_field( isset( $data['excerpt'] ) ? (string) $data['excerpt'] : '' ), 0, 200 ),
			'tags'             => $data['tags'],
		);

		$this->log( $job, sprintf(
			/* translators: %s: meta title. */
			__( 'SEO title: %s', 'wp-ai-post-creator' ),
			$job['data']['seo']['meta_title']
		), 'info' );
		$this->log( $job, sprintf(
			/* translators: %s: Rank Math summary. */
			__( 'Rank Math summary: %s', 'wp-ai-post-creator' ),
			$job['data']['seo']['meta_description']
		), 'info' );
		$this->log( $job, sprintf(
			/* translators: %s: slug. */
			__( 'Slug: /%s/', 'wp-ai-post-creator' ),
			$job['data']['seo']['slug']
		), 'info' );
		if ( ! empty( $data['tags'] ) ) {
			$this->log( $job, sprintf(
				/* translators: %s: comma separated tags. */
				__( 'Tags: %s', 'wp-ai-post-creator' ),
				implode( ', ', $data['tags'] )
			), 'info' );
		}
		$this->log( $job, __( 'SEO metadata ready', 'wp-ai-post-creator' ), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: featured image built from the topic + the Rank Math summary.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_image( array &$job ) {
		if ( ! AIPC_Settings::get( 'image_enabled' ) ) {
			$this->log( $job, __( 'Featured image disabled in settings — skipped.', 'wp-ai-post-creator' ), 'warn' );
			$this->advance( $job, 'skipped' );
			return;
		}

		$plan    = $job['data']['plan'];
		$summary = isset( $job['data']['seo']['meta_description'] ) ? $job['data']['seo']['meta_description'] : '';

		$args = array(
			'{{title}}'   => $plan['title'],
			'{{summary}}' => $summary,
		);

		$data    = $this->ask_json( $job, 'image_prompt', $args );
		$iprompt = ! empty( $data['prompt'] ) ? (string) $data['prompt'] : $plan['title'];

		$this->log( $job, sprintf(
			/* translators: %s: image prompt. */
			__( 'Generating featured image — prompt: %s', 'wp-ai-post-creator' ),
			mb_substr( $iprompt, 0, 120 )
		), 'info' );

		$client = $this->client_for( 'image' );
		$conn   = $this->resolve_connection( 'image' );

		$t0    = microtime( true );
		$image = $client->image( $iprompt );
		$ms    = ( microtime( true ) - $t0 ) * 1000;

		if ( is_wp_error( $image ) ) {
			$this->record_call( $job, 'image', $conn['name'], $conn['image_model'], array(), $ms, false, $image->get_error_message() );
			$this->log( $job, sprintf(
				/* translators: %s: error message. */
				__( 'Image generation failed (%s) — continuing without a featured image.', 'wp-ai-post-creator' ),
				$image->get_error_message()
			), 'warn' );
			$this->advance( $job, 'skipped' );
			return;
		}

		$this->record_call( $job, 'image', $conn['name'], $conn['image_model'], array(), $ms, true );
		$job['usage']['calls']++;

		$bits = ! empty( $image['bits'] ) ? $image['bits'] : '';
		if ( '' === $bits && ! empty( $image['url'] ) ) {
			$bits = $client->download( $image['url'] );
			if ( is_wp_error( $bits ) ) {
				$this->log( $job, __( 'Could not download the generated image — continuing without one.', 'wp-ai-post-creator' ), 'warn' );
				$this->advance( $job, 'skipped' );
				return;
			}
		}

		$filename  = 'aipc-' . sanitize_key( str_replace( 'job_', '', $job['id'] ) ) . '.png';
		$attach_id = AIPC_Post_Builder::upload_image( $bits, $filename, $plan['title'] );

		if ( is_wp_error( $attach_id ) ) {
			$this->log( $job, sprintf(
				/* translators: %s: error message. */
				__( 'Could not save the image to the media library (%s) — continuing without one.', 'wp-ai-post-creator' ),
				$attach_id->get_error_message()
			), 'warn' );
			$this->advance( $job, 'skipped' );
			return;
		}

		$job['data']['image'] = array(
			'attachment_id' => (int) $attach_id,
			'prompt'        => $iprompt,
		);
		$this->log( $job, sprintf(
			/* translators: %d: attachment id. */
			__( 'Featured image saved to the media library (#%d)', 'wp-ai-post-creator' ),
			$attach_id
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step: assemble and save the post — always as a draft.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_finalize( array &$job ) {
		$post_id = AIPC_Post_Builder::create( $job );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		$job['post_id'] = (int) $post_id;

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		if ( ! $edit_link ) {
			$edit_link = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		$words = self::count_words( AIPC_Post_Builder::build_content( $job ) );

		$job['data']['result'] = array(
			'post_id' => (int) $post_id,
			'title'   => get_the_title( $post_id ),
			'edit'    => $edit_link,
			'view'    => (string) get_permalink( $post_id ),
			'words'   => $words,
			'status'  => 'draft',
		);

		$job['status'] = 'done';

		$this->log( $job, sprintf(
			/* translators: %d: post id. */
			__( 'Draft created: #%d', 'wp-ai-post-creator' ),
			$post_id
		), 'success' );
		$this->log( $job, sprintf(
			/* translators: 1: word count, 2: prompt tokens, 3: completion tokens. */
			__( 'Done — %1$d words · %2$d prompt + %3$d completion tokens', 'wp-ai-post-creator' ),
			$words,
			$job['usage']['prompt'],
			$job['usage']['completion']
		), 'success' );

		$this->record_stats( $job );
		$this->advance( $job );
	}

	/* ---------------------------------------------------------------------
	 * Client state
	 * ------------------------------------------------------------------- */

	/**
	 * Build the JSON state sent to the browser console.
	 *
	 * @param array $job   Job.
	 * @param int   $since Send log entries from this index.
	 * @return array
	 */
	public function client_state( array $job, $since = 0 ) {
		$steps = array();
		$done  = 0;
		foreach ( $job['steps'] as $step ) {
			$steps[] = array(
				'id'     => $step['id'],
				'label'  => $step['label'],
				'status' => $step['status'],
			);
			if ( in_array( $step['status'], array( 'done', 'skipped' ), true ) ) {
				$done++;
			}
		}

		$total    = count( $job['steps'] );
		$planned  = ! empty( $job['data']['outline'] );
		$progress = $planned && $total > 0 ? (int) round( $done / $total * 100 ) : null;

		return array(
			'id'       => $job['id'],
			'status'   => $job['status'],
			'topic'    => $job['topic'],
			'steps'    => $steps,
			'progress' => $progress,
			'logs'     => array_slice( $job['log'], (int) $since ),
			'since'    => count( $job['log'] ),
			'usage'    => $job['usage'],
			'result'   => isset( $job['data']['result'] ) ? $job['data']['result'] : null,
			'error'    => $job['error'],
		);
	}
}
