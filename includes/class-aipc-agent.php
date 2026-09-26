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
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the generation agent.
 */
final class AIPC_Agent {

	const OPTION        = 'aipc_jobs';
	const MAX_JOBS      = 8;
	const STALE_SECONDS = 86400;

	/**
	 * Singleton.
	 *
	 * @var AIPC_Agent|null
	 */
	private static $instance = null;

	/**
	 * API client.
	 *
	 * @var AIPC_API_Client|null
	 */
	private $client = null;

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

	/**
	 * API client (lazy).
	 *
	 * @return AIPC_API_Client
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = new AIPC_API_Client();
		}
		return $this->client;
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
			'model'      => $s['chat_model'],
			'cursor'     => 0,
			'steps'      => array(
				array( 'id' => 'plan', 'label' => __( 'Category & topic selection', 'wp-ai-post-creator' ), 'status' => 'pending' ),
				array( 'id' => 'outline', 'label' => __( 'Article outline', 'wp-ai-post-creator' ), 'status' => 'pending' ),
			),
			'data'       => array(),
			'usage'      => array( 'prompt' => 0, 'completion' => 0, 'calls' => 0 ),
			'log'        => array(),
			'post_id'    => 0,
			'error'      => null,
		);

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
			/* translators: 1: model name, 2: tone, 3: length. */
			__( 'Setup — model: %1$s · tone: %2$s · length: %3$s', 'wp-ai-post-creator' ),
			$job['model'], $job['args']['tone'], $job['args']['length']
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

		// Give slow steps room to finish.
		if ( function_exists( 'set_time_limit' ) ) {
			$timeout = (int) AIPC_Settings::get( 'request_timeout' );
			@set_time_limit( max( 180, ( $timeout + 90 ) * 3 ) );
		}

		$step = isset( $job['steps'][ $job['cursor'] ] ) ? $job['steps'][ $job['cursor'] ] : null;
		if ( ! $step ) {
			delete_transient( $lock_key );
			return $this->client_state( $job, $since );
		}

		$max_attempts = (int) apply_filters( 'aipc_step_attempts', 3, $job, $step['id'] );
		$attempt      = 0;
		$passed       = false;

		while ( ! $passed && $attempt < $max_attempts ) {
			$attempt++;
			try {
				$this->run_step( $job, $step['id'] );
				$passed = true;
			} catch ( Exception $e ) {
				if ( $attempt < $max_attempts ) {
					$this->log( $job, sprintf(
						/* translators: 1: attempt number, 2: total attempts, 3: error message. */
						__( 'Attempt %1$d/%2$d failed (%3$s) — retrying…', 'wp-ai-post-creator' ),
						$attempt,
						$max_attempts,
						$e->getMessage()
					), 'warn' );
					$this->save_job( $job );
				}
			}
		}

		if ( ! $passed ) {
			$idx = $job['cursor'];
			if ( isset( $job['steps'][ $idx ] ) ) {
				$job['steps'][ $idx ]['status'] = 'failed';
			}
			$job['status'] = 'error';
			$job['error']  = array(
				'step'    => $step['id'],
				'message' => $e->getMessage(),
			);
			$this->log( $job, sprintf(
				/* translators: %s: error message. */
				__( 'Step failed: %s', 'wp-ai-post-creator' ),
				$e->getMessage()
			), 'error' );
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
	 * Prompt helpers
	 * ------------------------------------------------------------------- */

	/**
	 * The site prompt (what this site is about), if set.
	 *
	 * @return string
	 */
	private function site_prompt() {
		$site = trim( (string) AIPC_Settings::get( 'site_prompt' ) );
		if ( '' === $site ) {
			return '';
		}
		return 'SITE CONTEXT — what this website is about (every topic, title and sentence must fit this): ' . $site;
	}

	/**
	 * Base system prompt.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	private function system_prompt( array $job ) {
		$tones = AIPC_Settings::tones();
		$tone  = isset( $tones[ $job['args']['tone'] ] ) ? $tones[ $job['args']['tone'] ] : 'professional';
		$lang  = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$p = 'You are an expert blog writer, copywriter and SEO specialist. '
			. 'You produce original, accurate, engaging and well-structured content. '
			. 'Never mention that you are an AI. Never invent statistics, quotes or sources. '
			. 'Write in ' . $lang . '. Overall tone: ' . $tone . '.';

		$site = $this->site_prompt();
		if ( '' !== $site ) {
			$p .= ' ' . $site;
		}

		$extra = trim( (string) AIPC_Settings::get( 'system_prompt_extra' ) );
		if ( '' !== $extra ) {
			$p .= ' ' . $extra;
		}

		return apply_filters( 'aipc_system_prompt', $p, $job );
	}

	/**
	 * JSON chat wrapper that stores usage and throws on failure.
	 *
	 * @param array  $job  Job (by ref).
	 * @param string $user User prompt.
	 * @param array  $opts Chat options.
	 * @return array Decoded JSON.
	 * @throws Exception On failure.
	 */
	private function ask_json( array &$job, $user, $opts = array() ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->system_prompt( $job ) . ' Respond ONLY with a single valid JSON object — no markdown fences, no commentary.',
			),
			array( 'role' => 'user', 'content' => $user ),
		);
		$messages = apply_filters( 'aipc_messages', $messages, $job );

		$res = $this->client()->chat_json( $messages, $opts );
		if ( is_wp_error( $res ) ) {
			throw new Exception( $res->get_error_message() );
		}
		$this->add_usage( $job, $res['usage'] );
		return $res['data'];
	}

	/**
	 * HTML chat wrapper: strips fences, sanitizes, enforces a minimum length.
	 *
	 * @param array  $job      Job (by ref).
	 * @param string $user     User prompt.
	 * @param int    $min_word Minimum word count.
	 * @return string HTML.
	 * @throws Exception On failure.
	 */
	private function ask_html( array &$job, $user, $opts = array(), $min_word = 30 ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->system_prompt( $job ) . ' Respond with raw HTML fragments only — no markdown, no code fences, no commentary.',
			),
			array( 'role' => 'user', 'content' => $user ),
		);
		$messages = apply_filters( 'aipc_messages', $messages, $job );

		$res = $this->client()->chat( $messages, $opts );
		if ( is_wp_error( $res ) ) {
			throw new Exception( $res->get_error_message() );
		}
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

		// The categories the agent MUST choose from.
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
		if ( '' === $site ) {
			$site = '(No site prompt configured — write for a general audience.)';
		}

		$topic_part = ( '' !== $job['topic'] )
			? 'The user suggests this topic: "' . $job['topic'] . '" — build the article around it, adapted to the site context.'
			: 'No topic given — invent the single best topic yourself. It must fit the site context AND the chosen category.';

		$prompt = 'SITE CONTEXT: ' . $site . "\n\n"
			. "CATEGORIES (existing post categories of this website):\n"
			. $cat_lines . "\n"
			. $topic_part . "\n\n"
			. 'You are planning a blog article (~' . $spec['words'] . ' words) written in ' . $lang . ".\n"
			. "STEP 1: choose the ONE best-fitting category from the list above — you MUST use its exact name.\n"
			. "STEP 2: decide the topic and a compelling SEO title for it.\n\n"
			. 'Return ONLY this JSON object (values in ' . $lang . " unless noted):\n"
			. "{\n"
			. '  "category": "exact category name copied from the list", ' . "\n"
			. '  "title": "compelling SEO title, max 60 characters, in ' . $lang . '", ' . "\n"
			. '  "title_options": ["one alternative title, in ' . $lang . '"], ' . "\n"
			. '  "topic_brief": "2-3 sentences describing what the article will cover, in ' . $lang . '", ' . "\n"
			. '  "audience": "target audience, one sentence, in ' . $lang . '", ' . "\n"
			. '  "intent": "the main search intent, in ' . $lang . '", ' . "\n"
			. '  "primary_keyword": "main keyword, in ' . $lang . '", ' . "\n"
			. '  "secondary_keywords": ["4-8 related keywords, in ' . $lang . '"], ' . "\n"
			. '  "angle": "a unique angle that makes this article stand out, one sentence, in ' . $lang . '", ' . "\n"
			. '  "toc_title": "short label for the table of contents, in ' . $lang . '" ' . "\n"
			. '}';

		$data = $this->ask_json( $job, $prompt );

		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			throw new Exception( __( 'The plan did not include a title. Please retry.', 'wp-ai-post-creator' ) );
		}

		// The chosen category must exist on the site.
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

		$prompt = 'Working title: "' . $plan['title'] . "\"\n"
			. 'Topic brief: ' . ( isset( $plan['topic_brief'] ) ? $plan['topic_brief'] : '' ) . "\n"
			. 'Primary keyword: ' . ( isset( $plan['primary_keyword'] ) ? $plan['primary_keyword'] : '' ) . "\n"
			. 'Angle: ' . ( isset( $plan['angle'] ) ? $plan['angle'] : '' ) . "\n\n"
			. 'Create the outline for a ~' . $spec['words'] . ' word article with exactly ' . $spec['sections'] . " main sections.\n"
			. 'The introduction and conclusion are handled separately — do NOT include them.' . "\n"
			. "Order sections logically for the reader.\n"
			. "Return ONLY this JSON object:\n"
			. '{"sections": [{"heading": "section heading (H2 level, in ' . $lang . ', no numbering)", "brief": "2-3 sentences describing exactly what this section must cover"}]}';

		$data     = $this->ask_json( $job, $prompt );
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

		// Extend the manifest with the content steps.
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
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$prompt = 'Write the INTRODUCTION for the article "' . $plan['title'] . "\".\n"
			. 'Topic brief: ' . ( isset( $plan['topic_brief'] ) ? $plan['topic_brief'] : $job['topic'] ) . "\n"
			. 'Primary keyword: ' . ( isset( $plan['primary_keyword'] ) ? $plan['primary_keyword'] : '' ) . "\n"
			. 'Target audience: ' . ( isset( $plan['audience'] ) ? $plan['audience'] : '' ) . "\n\n"
			. "Requirements:\n"
			. '- 80-140 words, 1-2 paragraphs, written in ' . $lang . "\n"
			. "- Hook the reader in the first sentence\n"
			. "- Include the primary keyword naturally\n"
			. "- Briefly state what the reader will learn\n"
			. "- Use only <p> tags — no headings, no lists\n"
			. 'HTML fragment only.';

		$html = $this->ask_html( $job, $prompt, array(), 40 );

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

		$prev = '';
		if ( $i > 0 && isset( $job['data']['content']['sections'][ $i - 1 ] ) ) {
			$prev = wp_strip_all_tags( $job['data']['content']['sections'][ $i - 1 ] );
			$prev = mb_substr( trim( preg_replace( '/\s+/u', ' ', $prev ) ), -220 );
		}

		$prompt = 'You are writing section ' . ( $i + 1 ) . ' of ' . $total . ' for the article "' . $plan['title'] . "\".\n\n"
			. 'SECTION HEADING: ' . $section['heading'] . "\n"
			. 'WHAT TO COVER: ' . $section['brief'] . "\n"
			. 'TARGET LENGTH: about ' . $per_words . " words\n"
			. 'PRIMARY KEYWORD (use once, naturally): ' . ( isset( $plan['primary_keyword'] ) ? $plan['primary_keyword'] : '' ) . "\n"
			. ( ! empty( $keywords ) ? 'SECONDARY KEYWORDS (weave in if relevant): ' . implode( ', ', array_slice( $keywords, 0, 4 ) ) . "\n" : '' )
			. ( '' !== $prev ? "PREVIOUS SECTION ENDS WITH: …" . $prev . "\n(maintain flow — do not repeat information)\n" : '' )
			. "\nRULES:\n"
			. "- Do NOT repeat the section heading — start directly with the content\n"
			. "- You may use <h3>/<h4> subheadings, <p>, <ul>, <ol>, <table>, <blockquote>, <strong>, <em>\n"
			. "- Be specific and useful; no filler, no generic fluff\n"
			. 'HTML fragment only.';

		$html = $this->ask_html( $job, $prompt, array(), 40 );

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
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$headings = '';
		foreach ( $job['data']['outline'] as $section ) {
			$headings .= '- ' . $section['heading'] . "\n";
		}

		$prompt = 'Write the CONCLUSION for the article "' . $plan['title'] . "\".\n"
			. "The article covered:\n" . $headings
			. "\nRequirements:\n"
			. '- Start with a single <h2> heading in ' . $lang . " (e.g. the local word for \"Conclusion\")\n"
			. "- Then 1-2 paragraphs (100-160 words total)\n"
			. "- Summarize the key takeaways, then end with a clear call to action (comment, share, or read a related article)\n"
			. 'HTML fragment only.';

		$html = $this->ask_html( $job, $prompt, array(), 40 );

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
	 * Sends the whole assembled draft body back to the model and requires the
	 * revised version to keep the exact same structure (intro + N <h2>
	 * sections + a final <h2> conclusion) so it can be re-split safely.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_copywrite( array &$job ) {
		$d   = $job['data'];
		$plan = $d['plan'];
		$n    = count( $d['outline'] );

		// Assemble the draft body (same shape the final post uses).
		$draft = isset( $d['content']['intro'] ) ? trim( $d['content']['intro'] ) : '';
		foreach ( $d['outline'] as $i => $section ) {
			$sec   = isset( $d['content']['sections'][ $i ] ) ? trim( $d['content']['sections'][ $i ] ) : '';
			$draft .= "\n\n" . '<h2 id="aipc-s-' . (int) $i . '">' . esc_html( $section['heading'] ) . '</h2>' . "\n" . $sec;
		}
		if ( ! empty( $d['content']['conclusion'] ) ) {
			$draft .= "\n\n" . trim( $d['content']['conclusion'] );
		}

		$orig_words = self::count_words( $draft );

		$prompt = 'COPYWRITING & SEO REVISION PASS.' . "\n"
			. 'Below is the draft body of the article "' . $plan['title'] . '". Improve it as a professional copywriter and SEO editor:' . "\n"
			. "- COPYWRITING: sharper hooks, clearer flow, more persuasive and specific wording, remove filler and repetition.\n"
			. "- SEO: natural use of the primary keyword '" . ( isset( $plan['primary_keyword'] ) ? $plan['primary_keyword'] : '' ) . "', better subheading phrasing, stronger topic sentences.\n"
			. "- ORIGINALITY: rephrase anything that reads like generic boilerplate or copied phrasing — the final text must be original and pass as human-written.\n\n"
			. 'STRICT FORMAT RULES (the pipeline breaks without them):' . "\n"
			. '- Return ONLY the article body as raw HTML.' . "\n"
			. '- Structure: an introduction with NO <h2> heading, then exactly ' . $n . ' main sections each starting with its own <h2> heading, then ONE final <h2> conclusion block. Total: exactly ' . ( $n + 1 ) . " <h2> headings.\n"
			. "- Do not add or remove sections, do not merge them; no TOC, no FAQ, no title tag.\n"
			. "- Keep the same language and overall meaning; keep it approximately the same length.\n\n"
			. 'DRAFT:' . "\n" . $draft;

		$opts = array( 'max_tokens' => min( 16000, max( (int) AIPC_Settings::get( 'max_tokens' ), 6000 ) ) );
		$revised = $this->ask_html( $job, $prompt, $opts, 150 );

		// Split the revised body back into pieces.
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

		$intro      = trim( array_shift( $parts ) );       // Before the first <h2>.
		$conclusion = trim( array_pop( $parts ) );         // Last <h2> block (keeps its heading).

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
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$prompt = 'For the article "' . $plan['title'] . '", write 4-5 FAQ questions a reader would also ask (the "People also ask" style).' . "\n"
			. 'Return ONLY this JSON object, everything in ' . $lang . ":\n"
			. '{"faq_heading": "short H2 heading for the FAQ block, in ' . $lang . '", "items": [{"q": "question", "a": "1-3 sentence answer"}]}';

		$data = $this->ask_json( $job, $prompt );

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
		$lang  = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );
		$intro = isset( $job['data']['content']['intro'] ) ? mb_substr( wp_strip_all_tags( $job['data']['content']['intro'] ), 0, 300 ) : '';

		$prompt = 'SEO metadata + Rank Math summary for the article "' . $plan['title'] . "\".\n"
			. 'Opening of the article: ' . $intro . "\n\n"
			. 'Generate SEO metadata. The meta_description is THE summary used for Rank Math — it must be compelling and contain the primary keyword.' . "\n"
			. 'Return ONLY this JSON object:' . "\n"
			. "{\n"
			. '  "meta_title": "SEO title, max 60 characters, in ' . $lang . '", ' . "\n"
			. '  "meta_description": "meta description / Rank Math summary, max 155 characters, includes the primary keyword, in ' . $lang . '", ' . "\n"
			. '  "slug": "english-url-friendly-slug — lowercase English words separated by hyphens, max 6 words", ' . "\n"
			. '  "excerpt": "post excerpt, max 160 characters, in ' . $lang . '", ' . "\n"
			. '  "tags": ["5-8 short tag words, in ' . $lang . '"] ' . "\n"
			. '}';

		$data = $this->ask_json( $job, $prompt );

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

		$ask = 'Create a JSON object with one key "prompt": a detailed image-generation prompt (max 60 words) '
			. 'for a professional featured/hero blog image. '
			. 'Article title: "' . $plan['title'] . '". '
			. ( '' !== $summary ? 'Article summary: "' . $summary . '". ' : '' )
			. 'Style: modern, editorial, visually striking, high quality. '
			. 'CRITICAL: the image must contain NO text, NO words, NO letters, NO watermarks. '
			. 'Describe the scene only. JSON only.';

		$data = $this->ask_json( $job, $ask );
		$iprompt = ! empty( $data['prompt'] ) ? (string) $data['prompt'] : $plan['title'];

		$this->log( $job, sprintf(
			/* translators: %s: image prompt. */
			__( 'Generating featured image — prompt: %s', 'wp-ai-post-creator' ),
			mb_substr( $iprompt, 0, 120 )
		), 'info' );

		$image = $this->client()->image( $iprompt );

		if ( is_wp_error( $image ) ) {
			$this->log( $job, sprintf(
				/* translators: %s: error message. */
				__( 'Image generation failed (%s) — continuing without a featured image.', 'wp-ai-post-creator' ),
				$image->get_error_message()
			), 'warn' );
			$this->advance( $job, 'skipped' );
			return;
		}

		$bits = ! empty( $image['bits'] ) ? $image['bits'] : '';
		if ( '' === $bits && ! empty( $image['url'] ) ) {
			$bits = $this->client()->download( $image['url'] );
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
