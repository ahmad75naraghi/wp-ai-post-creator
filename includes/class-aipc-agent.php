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

	const STATS_OPTION  = 'aipc_stats';

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
		$chain = $this->resolve_connections( $step );
		return $chain[0];
	}

	/**
	 * Resolve the ordered connection chain for a step. Each connection gets
	 * its own retry budget; when one keeps failing the agent automatically
	 * falls back to the next (step chain → default connection).
	 *
	 * @param string $step Logical step id (e.g. 'plan', 'image').
	 * @return array[] Ordered connection data.
	 * @throws Exception When nothing is configured.
	 */
	private function resolve_connections( $step ) {
		$cfg   = AIPC_Steps::get( $step );
		$chain = array();

		foreach ( $cfg['connections'] as $conn_id ) {
			$conn = AIPC_Connections::get( $conn_id );
			// Disabled connections are skipped everywhere (v1.9.3).
			if ( $conn && ! empty( $conn['base_url'] ) && ! empty( $conn['enabled'] ) ) {
				$chain[] = $conn;
			}
		}

		// No explicit chain on the step: build one automatically from the
		// connections whose purpose matches (image steps use image-capable
		// connections, everything else chat-capable ones), ordered by
		// priority. Each entry keeps its own retry budget below.
		if ( empty( $chain ) ) {
			$purpose = ( 'image' === $step ) ? 'image' : 'chat';
			$chain   = AIPC_Connections::for_purpose( $purpose );
		}
		if ( empty( $chain ) ) {
			$default = AIPC_Connections::get_default();
			if ( $default ) {
				$chain[] = $default;
			}
		}

		// Circuit breaker (1.22.0): connections on cooldown move to the
		// end of the chain so healthy ones are tried first.
		$chain = AIPC_Health::order( $chain );

		/**
		 * Filter the ordered connection chain used by a step.
		 *
		 * @param array[] $chain Ordered connection data.
		 * @param string  $step  Logical step id.
		 */
		$chain = apply_filters( 'aipc_step_connections', $chain, $step );

		// Legacy single-connection filter, applied to the primary connection.
		if ( ! empty( $chain ) ) {
			$primary = apply_filters( 'aipc_step_connection', $chain[0], $step );
			if ( $primary && ! empty( $primary['base_url'] ) ) {
				array_unshift( $chain, $primary );
			}
			$seen   = array();
			$dedupe = array();
			foreach ( $chain as $conn ) {
				$key = ! empty( $conn['id'] ) ? $conn['id'] : md5( wp_json_encode( $conn ) );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$dedupe[]     = $conn;
				}
			}
			$chain = $dedupe;
		}

		if ( empty( $chain ) ) {
			throw new Exception( __( 'No AI connection is configured yet. Add one under AI Post Creator → Connections.', 'wp-ai-post-creator' ) );
		}

		return $chain;
	}

	/**
	 * Cached API client for a connection.
	 *
	 * @param array $conn Connection data.
	 * @return AIPC_API_Client
	 */
	private function client_for( array $conn ) {
		$key = ! empty( $conn['id'] ) ? $conn['id'] : md5( wp_json_encode( $conn ) );

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
	 * Job storage (AIPC_Job_Store: dedicated table, legacy option fallback)
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch one job (full payload).
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public function get_job( $id ) {
		return AIPC_Job_Store::get( (string) $id );
	}

	/**
	 * Light job rows (no payloads), newest first — lists and counters.
	 *
	 * @return array[]
	 */
	public function get_all_jobs() {
		return AIPC_Job_Store::all();
	}

	/**
	 * Full jobs created at/after a timestamp (oldest first) — reports.
	 *
	 * @param int $ts Unix timestamp.
	 * @return array[]
	 */
	public function get_jobs_since( $ts ) {
		return AIPC_Job_Store::since( (int) $ts );
	}

	/**
	 * Delete one job (from the logs screen).
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public function delete_job( $id ) {
		return AIPC_Job_Store::delete( (string) $id );
	}

	/**
	 * Clear all jobs/logs.
	 *
	 * @return void
	 */
	public function clear_jobs() {
		AIPC_Job_Store::clear();
	}

	/**
	 * Save one job.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public function save_job( $job ) {
		if ( ! is_array( $job ) || empty( $job['id'] ) ) {
			return;
		}
		// Cancellation is terminal (1.20.1): a runner that was mid-step
		// when the user cancelled still holds a 'running' copy in memory
		// and would resurrect the job seconds later by saving it. Never
		// let a 'running' copy overwrite a job the user already cancelled.
		if ( isset( $job['status'] ) && 'running' === $job['status'] ) {
			$stored = AIPC_Job_Store::get( (string) $job['id'] );
			if ( $stored && 'cancelled' === $stored['status'] ) {
				return;
			}
		}
		$job['updated'] = time();
		AIPC_Job_Store::save( $job );
	}

	/**
	 * Prune jobs older than the retention window (daily cron + on creation).
	 *
	 * @return void
	 */
	public function cleanup() {
		AIPC_Job_Store::prune();
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
			'rescued_images'    => 0,
			'quality_blocked'   => 0,
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

		// 1.22.0 counters for the dashboard + Bale report.
		if ( ! isset( $stats['rescued_images'] ) ) {
			$stats['rescued_images'] = 0;
		}
		if ( ! isset( $stats['quality_blocked'] ) ) {
			$stats['quality_blocked'] = 0;
		}
		$aipc_img_src = isset( $job['data']['image']['prompt'] ) ? (string) $job['data']['image']['prompt'] : '';
		if ( 0 === strpos( $aipc_img_src, 'stock:' ) || 'default' === $aipc_img_src ) {
			$stats['rescued_images']++;
		}
		if ( ! empty( $job['data']['quality']['blocked'] ) ) {
			$stats['quality_blocked']++;
		}

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

		$mode = isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : 'new';
		if ( ! in_array( $mode, array( 'new', 'rewrite', 'image_fix' ), true ) ) {
			$mode = 'new';
		}

		$publish_mode = isset( $args['publish_mode'] ) ? sanitize_key( $args['publish_mode'] ) : 'draft';
		if ( ! in_array( $publish_mode, array( 'draft', 'now', 'delay' ), true ) ) {
			$publish_mode = 'draft';
		}

		$publish_delay = isset( $args['publish_delay'] ) ? absint( $args['publish_delay'] ) : 60;
		if ( $publish_delay < 15 ) {
			$publish_delay = 15;
		}
		if ( $publish_delay > 10080 ) {
			$publish_delay = 10080;
		}

		return array(
			'tone'            => $tone,
			'length'          => $length,
			'language'        => $language,
			'language_custom' => isset( $args['language_custom'] ) ? mb_substr( sanitize_text_field( $args['language_custom'] ), 0, 40 ) : '',
			'image'           => empty( $args['image'] ) ? 0 : 1,
			'force_image'     => isset( $args['force_image'] )
				? ( empty( $args['force_image'] ) ? 0 : 1 )
				: (int) ! empty( $s['force_image'] ),
			'faq'             => empty( $args['faq'] ) ? 0 : 1,
			'toc'             => empty( $args['toc'] ) ? 0 : 1,
			'mode'            => $mode,
			'post_id'         => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'publish_mode'    => $publish_mode,
			'publish_delay'   => $publish_delay,
		);
	}

	/**
	 * Canonical form of a topic for duplicate detection (1.19.0):
	 * Arabic→Persian letters, digit unification, lowercase, ZWNJ/NBSP →
	 * space, collapsed whitespace.
	 *
	 * @param string $topic Raw topic.
	 * @return string
	 */
	public static function topic_norm( $topic ) {
		$t = AIPC_Topic_Queue::normalize_key( wp_strip_all_tags( (string) $topic ) );
		$t = preg_replace( '/[\x{200c}\x{200b}\x{00a0}]/u', ' ', $t );
		$t = preg_replace( '/\s+/u', ' ', (string) $t );
		return trim( (string) $t );
	}

	/**
	 * Atomic one-winner lock built on add_option()'s unique-key INSERT —
	 * unlike transients there is no read-then-write race (1.19.0).
	 *
	 * @param string $key Logical lock name.
	 * @param int    $ttl Seconds the claim stays valid.
	 * @return bool True when this caller won the claim.
	 */
	public static function claim( $key, $ttl = 600 ) {
		$name = 'aipc_claim_' . md5( $key );
		$ts   = (int) get_option( $name, 0 );
		if ( $ts && $ts > time() - max( 1, (int) $ttl ) ) {
			return false;
		}
		if ( $ts ) {
			delete_option( $name );
		}
		// Opportunistic cleanup of long-expired claim rows.
		if ( 1 === wp_rand( 1, 25 ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( 'aipc_claim_' ) . '%',
				time() - 2 * DAY_IN_SECONDS
			) );
		}
		return false !== add_option( $name, time(), '', false );
	}

	/**
	 * Drop a claim before its TTL expires (e.g. after a failed job).
	 *
	 * @param string $key Logical lock name.
	 * @return void
	 */
	public static function release( $key ) {
		delete_option( 'aipc_claim_' . md5( $key ) );
	}

	/**
	 * Is this topic already being written, recently written, or queued?
	 *
	 * @param string $topic      Topic text.
	 * @param string $source     Request source (cron skips the queue check —
	 *                           the scheduler legitimately consumes queue items).
	 * @param bool   $skip_queue Also skip the queue check (callers that
	 *                           consume a queue item themselves).
	 * @return array|null {type: job|post|queue, id, title} or null.
	 */
	public static function duplicate_of( $topic, $source = 'manual', $skip_queue = false ) {
		$norm = self::topic_norm( $topic );
		if ( '' === $norm ) {
			return null;
		}

		// 1) A job is writing this very topic right now.
		foreach ( AIPC_Job_Store::all( 100 ) as $row ) {
			if ( 'running' === $row['status'] && self::topic_norm( $row['topic'] ) === $norm ) {
				return array( 'type' => 'job', 'id' => (string) $row['id'], 'title' => (string) $row['topic'] );
			}
		}

		// 2) An article about it was created recently.
		$days = max( 0, (int) apply_filters( 'aipc_duplicate_window', 30 ) );
		if ( $days > 0 ) {
			$ids = get_posts( array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_aipc_topic_norm',
				'meta_value'       => $norm,
				'date_query'       => array( array( 'after' => $days . ' days ago' ) ),
			) );
			if ( ! empty( $ids ) ) {
				return array( 'type' => 'post', 'id' => (int) $ids[0], 'title' => get_the_title( $ids[0] ) );
			}
		}

		// 3) Waiting in the topic queue (manual/bot starts only).
		if ( 'cron' !== $source && ! $skip_queue ) {
			$cfg = AIPC_Topic_Queue::all();
			foreach ( $cfg['items'] as $item ) {
				$status = isset( $item['status'] ) ? $item['status'] : 'pending';
				$raw    = isset( $item['text'] ) ? $item['text'] : ( isset( $item['norm'] ) ? $item['norm'] : '' );
				$inorm  = self::topic_norm( $raw );
				if ( 'pending' === $status && '' !== $inorm && $inorm === $norm ) {
					return array( 'type' => 'queue', 'id' => isset( $item['id'] ) ? (string) $item['id'] : '', 'title' => (string) $raw );
				}
			}
		}

		return null;
	}

	/**
	 * Human message for a duplicate_of() hit.
	 *
	 * @param array $dup Duplicate info.
	 * @return string
	 */
	public static function duplicate_message( $dup ) {
		if ( 'job' === $dup['type'] ) {
			return sprintf(
				/* translators: %s: topic. */
				__( 'Duplicate request blocked: a job is already writing “%s” right now. Wait for it to finish, or enable “Allow duplicate topic” to override.', 'wp-ai-post-creator' ),
				$dup['title']
			);
		}
		if ( 'queue' === $dup['type'] ) {
			return sprintf(
				/* translators: %s: topic. */
				__( 'Duplicate request blocked: “%s” is already waiting in the topic queue and will be written on schedule.', 'wp-ai-post-creator' ),
				$dup['title']
			);
		}
		return sprintf(
			/* translators: 1: post title, 2: post id. */
			__( 'Duplicate request blocked: an article about this topic was already created recently — “%1$s” (post #%2$d). Change the topic, or enable “Allow duplicate topic” to write it again.', 'wp-ai-post-creator' ),
			$dup['title'],
			(int) $dup['id']
		);
	}

	/**
	 * Create a new job. The topic is optional: when empty, the agent invents
	 * one from the site prompt during the plan step.
	 *
	 * @param string $topic  Optional topic hint.
	 * @param array  $args   Options from the request ('force' => 1 skips
	 *                       the duplicate-topic guard).
	 * @param string $source Request source (manual|cron|bale).
	 * @return array|WP_Error Job or error.
	 */
	public function create_job( $topic, $args = array(), $source = 'manual' ) {
		if ( ! AIPC_Connections::get_default() ) {
			return new WP_Error( 'aipc_config', __( 'No AI connection is configured yet. Add one under AI Post Creator → Connections.', 'wp-ai-post-creator' ) );
		}

		$topic = trim( wp_strip_all_tags( (string) $topic ) );
		if ( mb_strlen( $topic ) > 400 ) {
			$topic = mb_substr( $topic, 0, 400 );
		}

		// Duplicate-request guard (1.19.0): never write the same topic twice
		// — not while another job is writing it, not when an article about
		// it was created recently, and not when two identical requests race
		// each other. `force` (admin opt-in) bypasses everything.
		$req_mode = isset( $args['mode'] ) && in_array( $args['mode'], array( 'rewrite', 'image_fix' ), true ) ? $args['mode'] : 'new';
		$force    = ! empty( $args['force'] );
		if ( 'new' === $req_mode && '' !== $topic && ! $force ) {
			$dup = self::duplicate_of( $topic, $source, ! empty( $args['from_queue'] ) );
			if ( $dup ) {
				return new WP_Error( 'aipc_duplicate', self::duplicate_message( $dup ) );
			}
			if ( ! self::claim( 'topic_' . self::topic_norm( $topic ), 10 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'aipc_duplicate', __( 'An identical request arrived moments ago and is already being processed — duplicate blocked.', 'wp-ai-post-creator' ) );
			}
		}

		$s   = AIPC_Settings::all();
		$id  = 'job_' . strtolower( wp_generate_password( 12, false, false ) );
		$job = array(
			'id'         => $id,
			'created'    => time(),
			'updated'    => time(),
			'status'     => 'running',
			'source'     => in_array( $source, array( 'cron', 'bale' ), true ) ? $source : 'manual',
			'user'       => get_current_user_id(),
			'topic'      => $topic,
			'args'       => $this->sanitize_args( $args, $s ),
			'mode'       => 'new',
			'model'      => '',
			'cursor'     => 0,
			'steps'      => array(),
			'data'       => array(),
			'usage'      => array( 'prompt' => 0, 'completion' => 0, 'calls' => 0 ),
			'calls'      => array(),
			'timings'    => array(),
			'log'        => array(),
			'post_id'    => 0,
			'error'      => null,
			'stats_recorded' => 0,
		);

		// Rewrite mode: the target post must exist and be editable.
		$rewrite_post = null;
		if ( 'rewrite' === $job['args']['mode'] ) {
			$rewrite_post = get_post( (int) $job['args']['post_id'] );
			if ( ! $rewrite_post || 'post' !== $rewrite_post->post_type ) {
				return new WP_Error( 'aipc_rewrite', __( 'The post to rewrite was not found.', 'wp-ai-post-creator' ) );
			}
			if ( ! current_user_can( 'edit_post', $rewrite_post->ID ) ) {
				return new WP_Error( 'aipc_rewrite', __( 'You are not allowed to edit this post.', 'wp-ai-post-creator' ) );
			}
			$job['mode']     = 'rewrite';
			$job['post_id']  = (int) $rewrite_post->ID;
			$job['topic']    = '' !== $topic ? $topic : $rewrite_post->post_title;
		} elseif ( 'image_fix' === $job['args']['mode'] ) {
			// Image-repair mode (1.22.0): generate ONLY a featured image
			// for an existing post that has none — the content is never
			// touched.
			if ( ! AIPC_Settings::get( 'image_enabled' ) ) {
				return new WP_Error( 'aipc_imgfix', __( 'Featured images are disabled in the settings.', 'wp-ai-post-creator' ) );
			}
			$fix_post = get_post( (int) $job['args']['post_id'] );
			if ( ! $fix_post || 'post' !== $fix_post->post_type ) {
				return new WP_Error( 'aipc_imgfix', __( 'The post to repair was not found.', 'wp-ai-post-creator' ) );
			}
			if ( is_user_logged_in() && ! current_user_can( 'edit_post', $fix_post->ID ) ) {
				return new WP_Error( 'aipc_imgfix', __( 'You are not allowed to edit this post.', 'wp-ai-post-creator' ) );
			}
			$job['mode']           = 'image_fix';
			$job['post_id']        = (int) $fix_post->ID;
			$job['topic']          = $fix_post->post_title;
			$job['args']['image']  = 1;
			$job['data']['plan']   = array( 'title' => $fix_post->post_title, 'primary_keyword' => '' );
			$job['data']['seo']    = array( 'meta_description' => mb_substr( wp_strip_all_tags( get_the_excerpt( $fix_post ) ), 0, 200 ) );
		} else {
			$job['mode'] = 'new';
		}

		$reg = AIPC_Steps::registry();
		if ( 'image_fix' === $job['mode'] ) {
			$job['steps'][] = array( 'id' => 'image', 'label' => $reg['image']['label'], 'status' => 'pending' );
			$job['steps'][] = array( 'id' => 'img_finalize', 'label' => __( 'Attach image', 'wp-ai-post-creator' ), 'status' => 'pending' );
		} elseif ( 'rewrite' === $job['mode'] ) {
			$job['steps'][] = array( 'id' => 'rw_analyze', 'label' => $reg['rw_analyze']['label'], 'status' => 'pending' );
			$job['steps'][] = array( 'id' => 'rw_rewrite', 'label' => $reg['rw_rewrite']['label'], 'status' => 'pending' );
			if ( $job['args']['faq'] ) {
				$job['steps'][] = array( 'id' => 'faq', 'label' => $reg['faq']['label'], 'status' => 'pending' );
			}
			$job['steps'][] = array( 'id' => 'seo', 'label' => $reg['seo']['label'], 'status' => 'pending' );
			if ( $job['args']['image'] && AIPC_Settings::get( 'image_enabled' ) ) {
				$job['steps'][] = array( 'id' => 'image', 'label' => $reg['image']['label'], 'status' => 'pending' );
			}
			$job['steps'][] = array( 'id' => 'rw_finalize', 'label' => __( 'Update post', 'wp-ai-post-creator' ), 'status' => 'pending' );
		} else {
			$job['steps'][] = array( 'id' => 'plan', 'label' => $reg['plan']['label'], 'status' => 'pending' );
			$job['steps'][] = array( 'id' => 'outline', 'label' => $reg['outline']['label'], 'status' => 'pending' );
		}

		$default_conn = AIPC_Connections::get_default();
		$job['model'] = ( $default_conn && ! empty( $default_conn['chat_model'] ) ) ? $default_conn['chat_model'] : '';

		if ( 'rewrite' === $job['mode'] ) {
			$this->log( $job, sprintf(
				/* translators: 1: post title, 2: post id. */
				__( 'Rewriting the existing post “%1$s” (#%2$d) — fresh copy, better SEO, full originality.', 'wp-ai-post-creator' ),
				$rewrite_post->post_title,
				$rewrite_post->ID
			), 'info' );
		} elseif ( '' !== $topic ) {
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

		// Hand the job to the background runner (the console only watches).
		AIPC_Scheduler::schedule_runner( $job['id'] );

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
			// Fence the in-flight runner FIRST (1.20.1): overwrite the
			// runner lock with a token no process owns. A runner that is
			// mid-step right now fails its ownership check at every save
			// point and discards its stale copy instead of overwriting
			// this cancellation — and its step loop stops because the
			// fresh copy it reloads says 'cancelled'.
			set_transient( 'aipc_lock_' . $job['id'], 'cancelled-' . uniqid( '', true ), self::LOCK_TTL );
			$job['status'] = 'cancelled';
			$this->log( $job, __( 'Agent cancelled by user.', 'wp-ai-post-creator' ), 'warn' );
			$this->record_stats( $job );
			$this->save_job( $job );
			AIPC_Scheduler::unschedule_runner( $id );
			self::release( 'topic_' . self::topic_norm( $job['topic'] ) );
		}
		return $job;
	}

	/**
	 * Scan for posts without a featured image and start one quiet
	 * image-repair job per post (1.22.0).
	 *
	 * @param int $limit Max repairs per scan.
	 * @return int Number of repair jobs started.
	 */
	public function repair_missing_images( $limit = 10 ) {
		/**
		 * Filter the maximum number of repair jobs per scan.
		 *
		 * @param int $limit Default 10.
		 */
		$limit = max( 1, (int) apply_filters( 'aipc_image_repair_batch', $limit ) );

		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'future' ),
			'posts_per_page' => $limit * 2,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		$created = 0;
		foreach ( $posts as $aipc_rp ) {
			if ( $created >= $limit ) {
				break;
			}
			// One repair attempt per post per hour — never a pile-up.
			if ( ! self::claim( 'imgfix_' . $aipc_rp->ID, HOUR_IN_SECONDS ) ) {
				continue;
			}
			$job = $this->create_job( $aipc_rp->post_title, array(
				'mode'    => 'image_fix',
				'post_id' => $aipc_rp->ID,
				'image'   => 1,
			), 'manual' );
			if ( ! is_wp_error( $job ) ) {
				$created++;
				AIPC_Scheduler::schedule_runner( $job['id'], 5 + $created * 10 );
			}
		}
		return $created;
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
		// Force-image mode: a retry restarts the whole retry window —
		// otherwise an exhausted window would error out again instantly.
		unset( $job['data']['image_retry'] );
		$this->log( $job, __( 'Retrying failed step…', 'wp-ai-post-creator' ), 'info' );
		$this->save_job( $job );
		AIPC_Scheduler::schedule_runner( $id );
		return $job;
	}

	/* ---------------------------------------------------------------------
	 * Step execution
	 * ------------------------------------------------------------------- */

	/**
	 * Runner-lock TTL. Thanks to the heartbeat it only needs to outlive a
	 * single HTTP request, not a whole step.
	 */
	const LOCK_TTL = 900;

	/** @var string Current runner-lock transient name ('' = none held). */
	private $lock_key = '';

	/** @var string Token proving THIS runner owns the lock. */
	private $lock_token = '';

	/**
	 * Take the runner lock for this process and start the heartbeat.
	 *
	 * @param string $key Lock transient name.
	 * @return void
	 */
	private function lock_acquire( $key ) {
		$this->lock_key   = $key;
		$this->lock_token = uniqid( 'r', true ) . wp_rand( 1000, 9999 );
		set_transient( $key, $this->lock_token, self::LOCK_TTL );
		add_filter( 'pre_http_request', array( $this, 'lock_heartbeat' ), 1, 3 );
	}

	/**
	 * Heartbeat: refresh the lock before every outbound HTTP request
	 * (provider calls, image downloads, stock photos — everything goes
	 * through the WP HTTP API). Refreshes only while we still own it.
	 *
	 * @param false|array|WP_Error $pre  Pre-emptive response (passthrough).
	 * @param array                $args Request args (unused).
	 * @param string               $url  Request URL (unused).
	 * @return false|array|WP_Error Unchanged $pre.
	 */
	public function lock_heartbeat( $pre, $args = array(), $url = '' ) {
		if ( '' !== $this->lock_key && get_transient( $this->lock_key ) === $this->lock_token ) {
			set_transient( $this->lock_key, $this->lock_token, self::LOCK_TTL );
		}
		return $pre;
	}

	/**
	 * Does this process still own the runner lock?
	 *
	 * @return bool
	 */
	private function lock_owned() {
		return '' !== $this->lock_key && get_transient( $this->lock_key ) === $this->lock_token;
	}

	/**
	 * Release the lock (only when still owned — never steal it back from
	 * a runner that legitimately took over) and stop the heartbeat.
	 *
	 * @return void
	 */
	private function lock_release() {
		if ( $this->lock_owned() ) {
			delete_transient( $this->lock_key );
		}
		remove_filter( 'pre_http_request', array( $this, 'lock_heartbeat' ), 1 );
		$this->lock_key   = '';
		$this->lock_token = '';
	}

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
		// Owner-token lock with heartbeat (1.19.1): the transient stores a
		// token unique to THIS runner and is refreshed before every outbound
		// HTTP request (pre_http_request, see lock_heartbeat), so it never
		// expires while the step is genuinely working — no matter how many
		// retries/connections/downloads the step needs. It only dies when
		// this process truly froze, and then the ownership check below
		// makes the stale runner discard its copy instead of saving it.
		$this->lock_acquire( $lock_key );

		// Give slow steps room to finish (retries included).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		$step = isset( $job['steps'][ $job['cursor'] ] ) ? $job['steps'][ $job['cursor'] ] : null;
		if ( ! $step ) {
			$this->lock_release();
			return $this->client_state( $job, $since );
		}

		$logical = ( 0 === strpos( $step['id'], 'section_' ) ) ? 'section' : $step['id'];

		// Force-image wait gate (1.21.0): between two scheduled attempts
		// of a forced image step no provider is called at all — the
		// runner just reports that it is waiting for the next round.
		if ( 'image' === $step['id'] && ! empty( $job['args']['force_image'] )
			&& ! empty( $job['data']['image_retry']['next'] )
			&& time() < (int) $job['data']['image_retry']['next'] ) {
			$this->lock_release();
			return $this->client_state( $job, $since );
		}

		$max_attempts = (int) apply_filters( 'aipc_step_attempts', 3, $job, $step['id'] );
		$chain        = $this->resolve_connections( $logical );
		$attempt      = 0;
		$passed       = false;
		$last_error   = null;
		$t0           = microtime( true );

		// Each connection in the chain gets its own retry budget; when one
		// keeps failing the agent falls back to the next connection.
		foreach ( $chain as $aipc_ci => $conn ) {
			$attempt = 0;
			while ( ! $passed && $attempt < $max_attempts ) {
				$attempt++;
				AIPC_Trace::set_context( array(
					'job'  => $job['id'],
					'step' => $step['id'],
					'conn' => $conn['name'],
					'try'  => $attempt,
				) );
				try {
					$this->run_step( $job, $step['id'], $conn );
					$passed = true;
					AIPC_Health::record( $conn, true );
				} catch ( Exception $e ) {
					$last_error = $e->getMessage();
					AIPC_Health::record( $conn, false );
					if ( $attempt < $max_attempts ) {
						$this->log( $job, sprintf(
							/* translators: 1: attempt number, 2: total attempts, 3: connection name, 4: error message. */
							__( 'Attempt %1$d/%2$d on “%3$s” failed (%4$s) — retrying…', 'wp-ai-post-creator' ),
							$attempt,
							$max_attempts,
							$conn['name'],
							$last_error
						), 'warn' );
						if ( $this->lock_owned() ) { // Never let a stale runner overwrite the new owner's copy.
							$this->save_job( $job );
						}
					}
				}
			}
			if ( $passed ) {
				break;
			}
			if ( $aipc_ci < count( $chain ) - 1 ) {
				$this->log( $job, sprintf(
					/* translators: 1: failed connection name, 2: attempt count, 3: next connection name. */
					__( 'Connection “%1$s” failed after %2$d attempts — switching to “%3$s”.', 'wp-ai-post-creator' ),
					$conn['name'],
					$max_attempts,
					$chain[ $aipc_ci + 1 ]['name']
				), 'warn' );
				if ( $this->lock_owned() ) { // Never let a stale runner overwrite the new owner's copy.
					$this->save_job( $job );
				}
			}
		}

		AIPC_Trace::clear_context();

		// Lost the lock mid-step? Then this process froze long enough for
		// another runner to take over legitimately (1.19.1). The new
		// owner's copy of the job is the truth now — discard every local
		// change. Saving our stale copy would rewind the cursor and make
		// finished steps run again: regenerated featured images, repeated
		// notifications, image-less reruns. Exactly the churn we fix here.
		if ( ! $this->lock_owned() ) {
			$this->lock_release(); // Removes the heartbeat only — never steals the new owner's lock.
			$fresh         = $this->get_job( $id );
			$state         = $this->client_state( $fresh ? $fresh : $job, $since );
			$state['busy'] = true;
			return $state;
		}

		// Step timing (all attempts).
		$job['timings'][ $step['id'] ] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( ! $passed && 'image' === $step['id'] ) {
			// Force-image mode (1.21.0): the user demanded a generated
			// image — the post may not be finished, published or
			// announced without one. Keep the job alive and retry the
			// whole connection chain at growing intervals (1 min → 1 h,
			// driven by the background runner) for up to a day.
			if ( ! empty( $job['args']['force_image'] ) ) {
				$retry_at = $this->image_force_defer( $job, (string) $last_error );
				if ( $retry_at > 0 ) {
					$this->save_job( $job );
					$this->lock_release();
					AIPC_Scheduler::schedule_runner( $job['id'], max( 30, $retry_at - time() ) );
					return $this->client_state( $job, $since );
				}
				// Retry window exhausted — the rescue ladder below is the
				// agreed last resort; when even that fails the job errors
				// out instead of finishing without an image. Drop the
				// spent window so a later retry starts a fresh one.
				unset( $job['data']['image_retry'] );
			}
			// Rescue ladder (1.18.0): stock photo → default image. Only
			// when both are unavailable is the step skipped.
			if ( $this->image_fallback( $job ) ) {
				$this->advance( $job );
				$this->save_job( $job );
				$this->lock_release();
				return $this->client_state( $job, $since );
			}
			if ( empty( $job['args']['force_image'] ) ) {
				// The featured image is optional — skip gracefully when every
				// connection in the chain failed to generate one.
				$this->log( $job, __( 'Image generation failed on every connection — continuing without a featured image.', 'wp-ai-post-creator' ), 'warn' );
				$this->advance( $job, 'skipped' );
				$this->save_job( $job );
				$this->lock_release();
				return $this->client_state( $job, $since );
			}
			// force_image + exhausted window + no rescue image → fall
			// through to the hard-error path; a retry re-runs the step.
		}

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
			AIPC_Scheduler::unschedule_runner( $job['id'] );
			// Release the topic claim so the user can retry right away —
			// failed jobs never block a fresh attempt (1.19.0).
			self::release( 'topic_' . self::topic_norm( $job['topic'] ) );
			$this->lock_release();
			return $this->client_state( $job, $since );
		}

		if ( 'image' === $step['id'] && isset( $job['data']['image_retry'] ) ) {
			// The image finally exists — stop the force-retry machinery
			// for good so nothing ever replaces or re-runs it.
			unset( $job['data']['image_retry'] );
		}

		$this->save_job( $job );
		$this->lock_release();

		if ( 'done' === $job['status'] ) {
			// Nothing left to run in the background.
			AIPC_Scheduler::unschedule_runner( $job['id'] );
		}

		if ( 'done' === $job['status'] && empty( $job['notified'] )
			&& self::claim( 'notify_' . $job['id'], DAY_IN_SECONDS ) ) {
			$job['notified'] = 1;
			$this->save_job( $job );

			/**
			 * Fires once after a job has successfully created its draft post.
			 *
			 * @param int    $post_id Created post id.
			 * @param string $job_id  Job id.
			 */
			do_action( 'aipc_post_created', (int) $job['post_id'], $job['id'] );

			// Reload so late log lines (e.g. notifications) reach the client.
			$fresh = $this->get_job( $job['id'] );
			if ( $fresh ) {
				$job = $fresh;
			}
		}

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
	private function run_step( array &$job, $step_id, array $conn ) {
		switch ( $step_id ) {
			case 'plan':
				$this->step_plan( $job, $conn );
				break;
			case 'outline':
				$this->step_outline( $job, $conn );
				break;
			case 'intro':
				$this->step_intro( $job, $conn );
				break;
			case 'conclusion':
				$this->step_conclusion( $job, $conn );
				break;
			case 'copywrite':
				$this->step_copywrite( $job, $conn );
				break;
			case 'faq':
				$this->step_faq( $job, $conn );
				break;
			case 'seo':
				$this->step_seo( $job, $conn );
				break;
			case 'image':
				$this->step_image( $job, $conn );
				break;
			case 'rw_analyze':
				$this->step_rw_analyze( $job, $conn );
				break;
			case 'rw_rewrite':
				$this->step_rw_rewrite( $job, $conn );
				break;
			case 'rw_finalize':
				$this->step_rw_finalize( $job );
				break;
			case 'img_finalize':
				$this->step_img_finalize( $job );
				break;
			case 'finalize':
				$this->step_finalize( $job );
				break;
			default:
				if ( 0 === strpos( $step_id, 'section_' ) ) {
					$this->step_section( $job, (int) substr( $step_id, 8 ), $conn );
					break;
				}
				throw new Exception( 'Unknown step: ' . $step_id );
		}
	}

	/**
	 * Append a log line to a stored job (used after the run, e.g. notifications).
	 *
	 * @param string $id      Job id.
	 * @param string $message Message.
	 * @param string $level   info|success|warn|error.
	 * @return bool
	 */
	public function append_log( $id, $message, $level = 'info' ) {
		$job = $this->get_job( $id );
		if ( ! $job ) {
			return false;
		}
		$this->log( $job, $message, $level );
		$this->save_job( $job );
		return true;
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
	private function ask_json( array &$job, $step, array $args = array(), $opts = array(), $conn = null ) {
		$conn   = $conn ? $conn : $this->resolve_connection( $step );
		$client = $this->client_for( $conn );

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
	private function ask_html( array &$job, $step, array $args = array(), $opts = array(), $min_word = 30, $conn = null ) {
		$conn   = $conn ? $conn : $this->resolve_connection( $step );
		$client = $this->client_for( $conn );

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
	/**
	 * Recent published post titles (so the agent avoids duplicates).
	 *
	 * @param int $limit Maximum titles.
	 * @return string Bullet list.
	 */
	private function recent_posts_context( $limit = 30 ) {
		$posts = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		) );

		if ( empty( $posts ) ) {
			return '- ' . __( '(No published posts yet.)', 'wp-ai-post-creator' );
		}

		$lines = '';
		foreach ( $posts as $post ) {
			$lines .= '- ' . wp_html_excerpt( $post->post_title, 110, '…' ) . "\n";
		}
		return trim( $lines );
	}

	/**
	 * Internal-link candidates: published posts with their permalinks.
	 *
	 * Since 1.20.0 the candidates are RELEVANCE-ranked, not just "the most
	 * recent": up to 100 recent posts are scored by word overlap between
	 * their title and the topic context, so the model links to genuinely
	 * related articles instead of whatever was published last.
	 *
	 * @param int    $exclude Post id to exclude (rewrite mode).
	 * @param int    $limit   Maximum candidates.
	 * @param string $context Topic/title text used for relevance ranking.
	 * @return string Bullet list.
	 */
	private function link_candidates( $exclude = 0, $limit = 20, $context = '' ) {
		$pool = (int) apply_filters( 'aipc_link_candidate_pool', 100 );
		$posts = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => max( $limit, $pool ),
			'orderby'          => 'date',
			'order'            => 'DESC',
			'post__not_in'     => $exclude ? array( (int) $exclude ) : array(),
			'suppress_filters' => true,
		) );

		if ( empty( $posts ) ) {
			return '- ' . __( '(No published posts yet.)', 'wp-ai-post-creator' );
		}

		// Relevance ranking by normalized token overlap with the context.
		$ctx_tokens = $this->title_tokens( $context );
		if ( ! empty( $ctx_tokens ) && count( $posts ) > $limit ) {
			$scored = array();
			foreach ( $posts as $idx => $post ) {
				$overlap = count( array_intersect( $ctx_tokens, $this->title_tokens( $post->post_title ) ) );
				$scored[] = array( 'post' => $post, 'score' => $overlap, 'recency' => -$idx );
			}
			usort( $scored, function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] - $a['score'];
				}
				return $b['recency'] - $a['recency']; // Newer first on ties.
			} );
			$posts = array_map( function ( $row ) {
				return $row['post'];
			}, $scored );
		}
		$posts = array_slice( $posts, 0, $limit );

		$lines = '';
		foreach ( $posts as $post ) {
			$lines .= '- ' . wp_html_excerpt( $post->post_title, 110, '…' ) . ' — ' . get_permalink( $post ) . "\n";
		}
		return trim( $lines );
	}

	/**
	 * Normalized, de-duplicated meaningful tokens of a title/topic
	 * (ZWNJ/case/digit variants unified, short stop-tokens dropped).
	 *
	 * @param string $text Input text.
	 * @return string[]
	 */
	private function title_tokens( $text ) {
		$norm = self::topic_norm( $text );
		if ( '' === $norm ) {
			return array();
		}
		$tokens = array();
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $norm ) as $tok ) {
			if ( mb_strlen( $tok ) >= 3 ) {
				$tokens[ $tok ] = true;
			}
		}
		return array_keys( $tokens );
	}

	/**
	 * Research context from the configured source sites (RSS feeds).
	 *
	 * @return string Formatted list (empty when no sources are configured).
	 */
	private function source_context() {
		$urls = array();
		foreach ( preg_split( '/\n+/', (string) AIPC_Settings::get( 'source_sites' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		if ( empty( $urls ) ) {
			return '';
		}
		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}

		$lines = '';
		foreach ( array_slice( $urls, 0, 5 ) as $url ) {
			$feed = fetch_feed( rtrim( $url, '/' ) . '/feed/' );
			if ( is_wp_error( $feed ) || ! method_exists( $feed, 'get_item_quantity' ) || ! $feed->get_item_quantity() ) {
				continue;
			}
			$host = wp_parse_url( $url, PHP_URL_HOST );
			// v1.12.0: when source links are disabled in the settings the
			// model never sees the URLs, so it cannot link to them.
			$with_links = (bool) AIPC_Settings::get( 'source_links' );
			$lines .= 'SOURCE: ' . $host . "\n";
			foreach ( $feed->get_items( 0, 6 ) as $item ) {
				$title = wp_html_excerpt( trim( strip_tags( (string) $item->get_title() ) ), 120, '…' );
				$desc  = wp_html_excerpt( trim( strip_tags( (string) $item->get_description() ) ), 180, '…' );
				$link  = $with_links ? esc_url_raw( (string) $item->get_permalink() ) : '';
				if ( '' === $title ) {
					continue;
				}
				$lines .= '- ' . $title . ( '' !== $link ? ' — ' . $link : '' ) . ( '' !== $desc ? ': ' . $desc : '' ) . "\n";
			}
			$lines .= "\n";
		}
		return trim( $lines );
	}

	/**
	 * Sanitize a model-returned internal-links list.
	 *
	 * @param array $raw Raw list of {title, url}.
	 * @return array
	 */
	private function sanitize_internal_links( $raw ) {
		$links = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) {
					continue;
				}
				$url   = esc_url_raw( (string) $link['url'] );
				$title = sanitize_text_field( isset( $link['title'] ) ? (string) $link['title'] : '' );
				if ( '' !== $url ) {
					$links[] = array(
						'title' => $title,
						'url'   => $url,
					);
				}
			}
		}
		return array_slice( $links, 0, 4 );
	}

	/**
	 * Format the internal-links instruction block for writing prompts.
	 *
	 * @param array $plan Plan data (with internal_links).
	 * @return string
	 */
	private function internal_links_block( array $plan ) {
		$links = isset( $plan['internal_links'] ) && is_array( $plan['internal_links'] ) ? $plan['internal_links'] : array();
		if ( empty( $links ) ) {
			return '';
		}
		$lines = 'INTERNAL LINKS — weave these in naturally where they genuinely help the reader (copy each URL exactly, max 1-2 per section):' . "\n";
		foreach ( $links as $link ) {
			$lines .= '- ' . $link['title'] . ' — ' . $link['url'] . "\n";
		}
		return $lines;
	}

	private function step_plan( array &$job, array $conn ) {
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
			'{{site_context}}'    => $site_context,
			'{{categories}}'      => trim( $cat_lines ),
			'{{recent_posts}}'    => $this->recent_posts_context(),
			'{{sources}}'         => $this->source_context(),
			'{{link_candidates}}' => $this->link_candidates( 0, 20, $job['topic'] ),
			'{{topic_hint}}'      => $topic_hint,
			'{{words}}'           => (string) $spec['words'],
			'{{sections}}'        => (string) $spec['sections'],
			'{{lang}}'            => $lang,
		);

		$data = $this->ask_json( $job, 'plan', $args, array(), $conn );

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
		$data['internal_links'] = $this->sanitize_internal_links( isset( $data['internal_links'] ) ? $data['internal_links'] : array() );
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
		if ( ! empty( $data['internal_links'] ) ) {
			$this->log( $job, sprintf(
				/* translators: %d: link count. */
				__( 'Internal linking: %d existing articles will be linked', 'wp-ai-post-creator' ),
				count( $data['internal_links'] )
			), 'info' );
		}

		$this->advance( $job );
	}

	/**
	 * Step: build the outline and extend the step manifest.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_outline( array &$job, array $conn ) {
		$plan = $job['data']['plan'];
		$spec = AIPC_Settings::length_specs( $job['args']['length'] );
		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{topic_brief}}'     => isset( $plan['topic_brief'] ) ? (string) $plan['topic_brief'] : '',
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{angle}}'           => isset( $plan['angle'] ) ? (string) $plan['angle'] : '',
			'{{search_intent}}'   => ! empty( $plan['search_intent'] ) ? sanitize_text_field( (string) $plan['search_intent'] ) : 'informational',
			'{{structure_hint}}'  => isset( $plan['structure_hint'] ) ? sanitize_text_field( (string) $plan['structure_hint'] ) : '',
			'{{words}}'           => (string) $spec['words'],
			'{{sections}}'        => (string) $spec['sections'],
			'{{lang}}'            => $lang,
		);

		$data     = $this->ask_json( $job, 'outline', $args, array(), $conn );
		$sections = array();

		if ( ! empty( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( $data['sections'] as $section ) {
				if ( empty( $section['heading'] ) ) {
					continue;
				}
				$sections[] = array(
					'heading'  => sanitize_text_field( (string) $section['heading'] ),
					'brief'    => isset( $section['brief'] ) ? sanitize_text_field( (string) $section['brief'] ) : '',
					// Evidence plan (1.20.0): the one concrete element this
					// section promises — handed to the writer step.
					'evidence' => isset( $section['evidence'] ) ? sanitize_text_field( (string) $section['evidence'] ) : '',
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
	private function step_intro( array &$job, array $conn ) {
		$plan = $job['data']['plan'];

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{topic_brief}}'     => isset( $plan['topic_brief'] ) ? (string) $plan['topic_brief'] : $job['topic'],
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{audience}}'        => isset( $plan['audience'] ) ? (string) $plan['audience'] : '',
			'{{lang}}'            => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$html = $this->ask_html( $job, 'intro', $args, array(), 40, $conn );

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
	private function step_section( array &$job, $i, array $conn ) {
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
			'{{section_evidence}}' => ( isset( $section['evidence'] ) && '' !== $section['evidence'] )
				? $section['evidence']
				: 'at least one concrete, useful element: a real example, actionable steps, a comparison, or a common mistake and its fix',
			'{{per_words}}'       => (string) $per_words,
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{keywords_block}}'  => $keywords_block,
			'{{prev_block}}'      => $prev_block,
			'{{internal_links}}'  => $this->internal_links_block( $plan ),
		);

		$html = $this->ask_html( $job, 'section', $args, array(), 40, $conn );

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
	private function step_conclusion( array &$job, array $conn ) {
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

		$html = $this->ask_html( $job, 'conclusion', $args, array(), 40, $conn );

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
	private function step_copywrite( array &$job, array $conn ) {
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
			'{{internal_links}}'  => $this->internal_links_block( $plan ),
		);

		$opts    = array( 'max_tokens' => min( 16000, max( (int) $conn['max_tokens'], 6000 ) ) );
		$revised = $this->ask_html( $job, 'copywrite', $args, $opts, 150, $conn );

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
	private function step_faq( array &$job, array $conn ) {
		$plan = $job['data']['plan'];

		$args = array(
			'{{title}}' => $plan['title'],
			'{{lang}}'  => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$data = $this->ask_json( $job, 'faq', $args, array(), $conn );

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
	private function step_seo( array &$job, array $conn ) {
		$plan  = $job['data']['plan'];
		$intro = isset( $job['data']['content']['intro'] ) ? mb_substr( wp_strip_all_tags( $job['data']['content']['intro'] ), 0, 300 ) : '';

		$args = array(
			'{{title}}' => $plan['title'],
			'{{intro}}' => $intro,
			'{{lang}}'  => AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] ),
		);

		$data = $this->ask_json( $job, 'seo', $args, array(), $conn );

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
	private function step_image( array &$job, array $conn ) {
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

		// The image prompt is a chat call — give it its own connection chain.
		$ip_data = null;
		$ip_err  = null;
		foreach ( $this->resolve_connections( 'image_prompt' ) as $ip_conn ) {
			try {
				$ip_data = $this->ask_json( $job, 'image_prompt', $args, array(), $ip_conn );
				break;
			} catch ( Exception $e ) {
				$ip_err = $e->getMessage();
			}
		}
		if ( null === $ip_data ) {
			throw new Exception( $ip_err ? $ip_err : __( 'The image prompt step failed.', 'wp-ai-post-creator' ) );
		}
		$data    = $ip_data;
		$iprompt = ! empty( $data['prompt'] ) ? (string) $data['prompt'] : $plan['title'];

		// Stock-photo search seed (1.18.0): prefer the model's explicit
		// keywords; otherwise the first words of the raw English prompt
		// (before the user's style suffix is appended).
		$kw = '';
		if ( ! empty( $data['keywords'] ) ) {
			$kw = is_array( $data['keywords'] ) ? implode( ' ', array_map( 'strval', $data['keywords'] ) ) : (string) $data['keywords'];
		}
		if ( '' === trim( $kw ) ) {
			$kw = implode( ' ', array_slice( preg_split( '/\s+/', $iprompt ), 0, 8 ) );
		}
		$job['data']['image_query'] = mb_substr( trim( $kw ), 0, 160 );

		$iprompt = self::apply_image_prompt_default( $iprompt );

		$this->log( $job, sprintf(
			/* translators: %s: image prompt. */
			__( 'Generating featured image — prompt: %s', 'wp-ai-post-creator' ),
			mb_substr( $iprompt, 0, 120 )
		), 'info' );

		$client = $this->client_for( $conn );

		$t0    = microtime( true );
		$image = $client->image( $iprompt );
		$ms    = ( microtime( true ) - $t0 ) * 1000;

		if ( is_wp_error( $image ) ) {
			$this->record_call( $job, 'image', $conn['name'], $conn['image_model'], array(), $ms, false, $image->get_error_message() );
			// Throw so the chained retry/fallback logic can try the next
			// connection; when the whole chain fails the step is skipped.
			throw new Exception( $image->get_error_message() );
		}

		$this->record_call( $job, 'image', $conn['name'], $conn['image_model'], array(), $ms, true );
		$job['usage']['calls']++;

		$bits = ! empty( $image['bits'] ) ? $image['bits'] : '';
		if ( '' === $bits && ! empty( $image['url'] ) ) {
			$bits = $client->download( $image['url'] );
			if ( is_wp_error( $bits ) ) {
				// Throw so the retry/failover logic can try again or move to
				// the next image connection; when the whole chain fails the
				// step is skipped gracefully (post continues without image).
				throw new Exception( sprintf(
					/* translators: %s: error message. */
					__( 'Could not download the generated image: %s', 'wp-ai-post-creator' ),
					$bits->get_error_message()
				) );
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
	 * Image rescue ladder (1.18.0): when every AI connection failed, try a
	 * stock photo (Openverse, opt-in), then the site's default featured
	 * image. Fills $job['data']['image'] and returns true on success.
	 * Public for testability.
	 *
	 * @param array $job Job (by reference).
	 * @return bool Whether an image was attached.
	 */
	/**
	 * Force-image mode: plan the next retry of the image step instead of
	 * falling back or skipping (1.21.0).
	 *
	 * @param array  $job   Job (by ref).
	 * @param string $error Last provider error.
	 * @return int Next-attempt timestamp, or 0 when the retry window is
	 *             exhausted (→ rescue ladder, then hard error).
	 */
	private function image_force_defer( array &$job, $error ) {
		$r = isset( $job['data']['image_retry'] ) && is_array( $job['data']['image_retry'] )
			? $job['data']['image_retry']
			: array( 'count' => 0, 'since' => time() );

		/**
		 * Filter the total window (seconds) force-image mode keeps
		 * retrying before the rescue ladder becomes the last resort.
		 *
		 * @param int   $window Seconds (default one day).
		 * @param array $job    Job.
		 */
		$window = (int) apply_filters( 'aipc_force_image_window', DAY_IN_SECONDS, $job );
		if ( time() - (int) $r['since'] >= $window ) {
			$this->log( $job, __( 'Image retry window exhausted — using the rescue images (stock/default) as the last resort.', 'wp-ai-post-creator' ), 'warn' );
			return 0;
		}

		$r['count']++;
		$delays    = array( 60, 120, 300, 600, 900, 1800, 3600 );
		$delay     = $delays[ min( $r['count'] - 1, count( $delays ) - 1 ) ];
		$r['next'] = time() + $delay;

		$job['data']['image_retry'] = $r;
		if ( isset( $job['steps'][ $job['cursor'] ] ) ) {
			$job['steps'][ $job['cursor'] ]['status'] = 'pending'; // Not failed — waiting for the next round.
		}
		$this->log( $job, sprintf(
			/* translators: 1: attempt round, 2: human-readable wait, 3: error message. */
			__( 'A featured image is required (force mode) — round %1$d failed on every connection (%3$s). Next attempt in %2$s; the post will only be finished once the image exists.', 'wp-ai-post-creator' ),
			(int) $r['count'],
			human_time_diff( time(), $r['next'] ),
			mb_substr( (string) $error, 0, 160 )
		), 'warn' );

		return (int) $r['next'];
	}

	public function image_fallback( array &$job ) {
		// Idempotency guard (1.21.0): when an image is already attached
		// (e.g. generated moments ago by this very job) nothing may
		// replace it — the rescue ladder only fills a real gap.
		if ( ! empty( $job['data']['image']['attachment_id'] ) ) {
			return true;
		}

		$title = isset( $job['data']['plan']['title'] ) ? (string) $job['data']['plan']['title'] : '';

		// 1) Openverse stock photo — relevant, CC-licensed, no API key.
		if ( AIPC_Settings::get( 'image_fallback_stock' ) ) {
			$query = isset( $job['data']['image_query'] ) ? trim( (string) $job['data']['image_query'] ) : '';
			if ( '' === $query ) {
				$query = $title;
			}
			$stock = AIPC_Stock::fetch( $query );
			if ( ! is_wp_error( $stock ) ) {
				$filename  = 'aipc-stock-' . sanitize_key( str_replace( 'job_', '', $job['id'] ) ) . '.jpg';
				$attach_id = AIPC_Post_Builder::upload_image( $stock['bits'], $filename, '' !== $title ? $title : $query );
				if ( ! is_wp_error( $attach_id ) ) {
					wp_update_post( array(
						'ID'           => (int) $attach_id,
						'post_excerpt' => $stock['attribution'],
					) );
					update_post_meta( (int) $attach_id, '_aipc_stock_attribution', $stock['attribution'] );
					if ( ! empty( $stock['meta']['source'] ) ) {
						update_post_meta( (int) $attach_id, '_aipc_stock_source', esc_url_raw( $stock['meta']['source'] ) );
					}
					$job['data']['image'] = array(
						'attachment_id' => (int) $attach_id,
						'prompt'        => 'stock: ' . $query,
					);
					$this->log( $job, sprintf(
						/* translators: %d: attachment id. */
						__( 'AI image generation failed — attached a CC-licensed stock photo from Openverse instead (#%d, attribution saved on the attachment).', 'wp-ai-post-creator' ),
						$attach_id
					), 'warn' );
					return true;
				}
			} else {
				$this->log( $job, sprintf(
					/* translators: %s: error message. */
					__( 'Stock photo fallback failed: %s', 'wp-ai-post-creator' ),
					$stock->get_error_message()
				), 'warn' );
			}
		}

		// 2) The site's default featured image — the guaranteed last resort.
		$fallback = trim( (string) AIPC_Settings::get( 'image_fallback' ) );
		if ( '' !== $fallback ) {
			$attach_id = $this->resolve_fallback_attachment( $fallback );
			if ( $attach_id ) {
				$job['data']['image'] = array(
					'attachment_id' => (int) $attach_id,
					'prompt'        => 'default',
				);
				$this->log( $job, sprintf(
					/* translators: %d: attachment id. */
					__( 'AI image generation failed — using the default featured image (#%d).', 'wp-ai-post-creator' ),
					$attach_id
				), 'warn' );
				return true;
			}
			$this->log( $job, __( 'The default featured image setting could not be resolved to an image.', 'wp-ai-post-creator' ), 'warn' );
		}

		return false;
	}

	/**
	 * Resolve the "default featured image" setting to an attachment id.
	 *
	 * Accepts a media-library attachment ID, a media-library URL, or an
	 * external image URL (imported once and cached for reuse).
	 *
	 * @param string $value Setting value.
	 * @return int Attachment id, or 0.
	 */
	private function resolve_fallback_attachment( $value ) {
		if ( ctype_digit( $value ) ) {
			$id = (int) $value;
			return wp_attachment_is_image( $id ) ? $id : 0;
		}

		$id = (int) attachment_url_to_postid( $value );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}

		// External URL: import once, remember the attachment for reuse.
		$cache = get_option( 'aipc_image_fallback_cache', array() );
		$cache = is_array( $cache ) ? $cache : array();
		$key   = md5( $value );
		if ( isset( $cache[ $key ] ) && wp_attachment_is_image( (int) $cache[ $key ] ) ) {
			return (int) $cache[ $key ];
		}
		if ( ! AIPC_Network::is_safe_url( $value ) ) {
			return 0;
		}
		$res = wp_remote_get( $value, array( 'timeout' => 30, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return 0;
		}
		$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
		$bits = (string) wp_remote_retrieve_body( $res );
		if ( strlen( $bits ) < 100 || ( '' !== $type && 0 !== strpos( $type, 'image/' ) ) ) {
			return 0;
		}
		$attach = AIPC_Post_Builder::upload_image( $bits, 'aipc-default-' . substr( $key, 0, 8 ) . '.jpg', __( 'Default featured image', 'wp-ai-post-creator' ) );
		if ( is_wp_error( $attach ) ) {
			return 0;
		}
		$cache[ $key ] = (int) $attach;
		update_option( 'aipc_image_fallback_cache', $cache, false );
		return (int) $attach;
	}

	/**
	 * Append the panel-configured default image prompt (v1.10.0) to a
	 * generated image prompt. Empty setting = no change.
	 *
	 * @param string $prompt Generated image prompt.
	 * @return string
	 */
	public static function apply_image_prompt_default( $prompt ) {
		$extra = trim( (string) AIPC_Settings::get( 'image_prompt_default' ) );
		if ( '' === $extra ) {
			return $prompt;
		}
		if ( false !== mb_stripos( $prompt, $extra ) ) {
			return $prompt; // Already contained (e.g. model echoed it back).
		}
		return rtrim( trim( $prompt ), '.,؛;' ) . '. ' . $extra;
	}

	/**
	 * Build and set a fresh AI featured image for an existing post
	 * (v1.10.0 — "regenerate thumbnail" row action in the posts list).
	 *
	 * Runs outside a job: the title + SEO summary are turned into an
	 * image prompt over the chat-capable connections, then the image
	 * chain generates the picture. The old thumbnail is left in the
	 * media library; the post simply points at the new attachment.
	 *
	 * @param int $post_id Post id.
	 * @return int|WP_Error New attachment id or error.
	 */
	public static function regenerate_thumbnail( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'aipc_post', __( 'Post not found.', 'wp-ai-post-creator' ) );
		}
		AIPC_Trace::set_context( array( 'step' => 'regen_thumbnail', 'post' => (int) $post_id ) );

		$title   = $post->post_title;
		$summary = (string) get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' === $summary ) {
			$summary = (string) get_post_meta( $post_id, '_aipc_meta_description', true );
		}
		if ( '' === $summary ) {
			$summary = (string) $post->post_excerpt;
		}

		// 1) Title + summary → image prompt (chat-capable chain).
		$template   = AIPC_Steps::prompt_for( 'image_prompt' );
		$prompt_msg = strtr( $template, array(
			'{{title}}'   => $title,
			'{{summary}}' => $summary,
		) );

		$iprompt = '';
		foreach ( AIPC_Connections::for_purpose( 'chat' ) as $conn ) {
			$client = new AIPC_API_Client( $conn );
			$data   = $client->chat_json( array(
				array( 'role' => 'system', 'content' => 'You write image-generation prompts. Respond with JSON only.' ),
				array( 'role' => 'user', 'content' => $prompt_msg ),
			) );
			if ( ! is_wp_error( $data ) && ! empty( $data['data']['prompt'] ) ) {
				$iprompt = (string) $data['data']['prompt'];
				break;
			}
		}
		if ( '' === $iprompt ) {
			$iprompt = $title; // Graceful: the title alone still works as a prompt.
		}
		$iprompt = self::apply_image_prompt_default( $iprompt );

		// 2) Generate the picture (image-capable chain, first success wins).
		$last_err = null;
		foreach ( AIPC_Connections::for_purpose( 'image' ) as $conn ) {
			$client = new AIPC_API_Client( $conn );
			$image  = $client->image( $iprompt );
			if ( is_wp_error( $image ) ) {
				$last_err = $image;
				continue;
			}

			$bits = ! empty( $image['bits'] ) ? $image['bits'] : '';
			if ( '' === $bits && ! empty( $image['url'] ) ) {
				$bits = $client->download( $image['url'] );
				if ( is_wp_error( $bits ) ) {
					$last_err = $bits;
					continue;
				}
			}
			if ( '' === $bits ) {
				continue;
			}

			$attach_id = AIPC_Post_Builder::upload_image( $bits, 'aipc-thumb-' . (int) $post_id . '-' . time() . '.png', $title );
			if ( is_wp_error( $attach_id ) ) {
				return $attach_id;
			}
			set_post_thumbnail( $post_id, (int) $attach_id );
			return (int) $attach_id;
		}

		return $last_err ? $last_err : new WP_Error(
			'aipc_image',
			__( 'No image-capable connection is configured (or enabled).', 'wp-ai-post-creator' )
		);
	}

	/**
	 * Step (rewrite): analyze the existing post and build the new outline.
	 *
	 * @param array $job  Job (by ref).
	 * @param array $conn Connection for this attempt.
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_rw_analyze( array &$job, array $conn ) {
		$post = get_post( (int) $job['args']['post_id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			throw new Exception( __( 'The post to rewrite was not found.', 'wp-ai-post-creator' ) );
		}

		$lang = AIPC_Settings::language_name( $job['args']['language'], $job['args']['language_custom'] );
		$spec = AIPC_Settings::length_specs( $job['args']['length'] );

		$existing = wp_strip_all_tags( $post->post_content );
		$existing = mb_substr( trim( preg_replace( '/\s+/u', ' ', $existing ) ), 0, 12000 );

		$site = trim( (string) AIPC_Settings::get( 'site_prompt' ) );
		$site_context = ( '' !== $site )
			? $site
			: __( '(No site prompt configured — write for a general audience.)', 'wp-ai-post-creator' );

		$args = array(
			'{{existing_title}}'   => $post->post_title,
			'{{existing_content}}' => $existing,
			'{{site_context}}'     => $site_context,
			'{{sources}}'          => $this->source_context(),
			'{{link_candidates}}'  => $this->link_candidates( $post->ID, 20, $post->post_title ),
			'{{words}}'            => (string) $spec['words'],
			'{{lang}}'             => $lang,
		);

		$data = $this->ask_json( $job, 'rw_analyze', $args, array(), $conn );

		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			throw new Exception( __( 'The rewrite analysis did not include a title. Please retry.', 'wp-ai-post-creator' ) );
		}

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
			throw new Exception( __( 'The rewrite analysis returned no usable outline. Please retry.', 'wp-ai-post-creator' ) );
		}

		if ( ! is_array( $data['secondary_keywords'] ) ) {
			$data['secondary_keywords'] = array();
		}

		$notes = '';
		if ( isset( $data['notes'] ) && is_array( $data['notes'] ) ) {
			$notes = implode( "\n", array_map( 'sanitize_text_field', $data['notes'] ) );
		} elseif ( isset( $data['notes'] ) ) {
			$notes = sanitize_textarea_field( (string) $data['notes'] );
		}

		// Keep the post's current category.
		$cats   = wp_get_post_categories( $post->ID );
		$cat_id = ! empty( $cats ) ? (int) reset( $cats ) : (int) get_option( 'default_category' );

		$job['data']['plan'] = array(
			'title'             => sanitize_text_field( $data['title'] ),
			'primary_keyword'   => isset( $data['primary_keyword'] ) ? sanitize_text_field( (string) $data['primary_keyword'] ) : '',
			'secondary_keywords' => array_slice( array_map( 'sanitize_text_field', $data['secondary_keywords'] ), 0, 8 ),
			'internal_links'    => $this->sanitize_internal_links( isset( $data['internal_links'] ) ? $data['internal_links'] : array() ),
			'category_id'       => $cat_id,
			'category'          => get_cat_name( $cat_id ),
		);
		$job['data']['outline'] = $sections;
		$job['data']['rewrite'] = array(
			'post_id'        => $post->ID,
			'original_title' => $post->post_title,
			'notes'          => mb_substr( $notes, 0, 4000 ),
		);

		$this->log( $job, sprintf(
			/* translators: %d: section count. */
			_n( 'Rewrite plan ready — %d section.', 'Rewrite plan ready — %d sections.', count( $sections ), 'wp-ai-post-creator' ),
			count( $sections )
		), 'success' );
		$this->log( $job, sprintf(
			/* translators: %s: title. */
			__( 'Improved title: “%s”', 'wp-ai-post-creator' ),
			$data['title']
		), 'info' );

		$this->advance( $job );
	}

	/**
	 * Step (rewrite): rewrite the whole post in one revision pass.
	 *
	 * @param array $job  Job (by ref).
	 * @param array $conn Connection for this attempt.
	 * @return void
	 * @throws Exception On failure.
	 */
	private function step_rw_rewrite( array &$job, array $conn ) {
		$d     = $job['data'];
		$plan  = $d['plan'];
		$n     = count( $d['outline'] );
		$post  = get_post( (int) $job['args']['post_id'] );
		if ( ! $post ) {
			throw new Exception( __( 'The post to rewrite was not found.', 'wp-ai-post-creator' ) );
		}

		$orig_words = self::count_words( $post->post_content );

		$args = array(
			'{{title}}'           => $plan['title'],
			'{{notes}}'           => isset( $d['rewrite']['notes'] ) ? $d['rewrite']['notes'] : '',
			'{{primary_keyword}}' => isset( $plan['primary_keyword'] ) ? (string) $plan['primary_keyword'] : '',
			'{{sections}}'        => (string) $n,
			'{{sources}}'         => $this->source_context(),
			'{{internal_links}}'  => $this->internal_links_block( $plan ),
			'{{draft}}'           => $post->post_content,
		);

		$opts    = array( 'max_tokens' => min( 16000, max( (int) $conn['max_tokens'], 6000 ) ) );
		$revised = $this->ask_html( $job, 'rw_rewrite', $args, $opts, 150, $conn );

		$parts = preg_split( '/(?=<h2\b)/i', $revised, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_values( array_filter( array_map( 'trim', $parts ), function ( $part ) {
			return '' !== $part;
		} ) );

		if ( count( $parts ) !== $n + 2 ) {
			throw new Exception( __( 'The rewrite lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
		}

		$new_words = self::count_words( $revised );
		if ( $orig_words > 0 && $new_words < (int) floor( $orig_words * 0.5 ) ) {
			throw new Exception( __( 'The rewrite came back much shorter than the original. Retrying.', 'wp-ai-post-creator' ) );
		}

		$intro      = trim( array_shift( $parts ) );
		$conclusion = trim( array_pop( $parts ) );

		if ( count( $parts ) !== $n ) {
			throw new Exception( __( 'The rewrite lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
		}

		foreach ( $parts as $i => $part ) {
			if ( ! preg_match( '#^<h2([^>]*)>(.*?)</h2>#is', $part, $m ) ) {
				throw new Exception( __( 'The rewrite lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
			}
			$heading = trim( wp_strip_all_tags( $m[2] ) );
			if ( '' === $heading ) {
				throw new Exception( __( 'The rewrite lost the article structure. Retrying.', 'wp-ai-post-creator' ) );
			}
			$job['data']['outline'][ $i ]['heading'] = sanitize_text_field( $heading );
			$job['data']['content']['sections'][ $i ] = trim( substr( $part, strlen( $m[0] ) ) );
		}

		$job['data']['content']['intro']      = $intro;
		$job['data']['content']['conclusion'] = $conclusion;

		$this->log( $job, sprintf(
			/* translators: 1: original word count, 2: rewritten word count. */
			__( 'Rewrite complete (%1$d → %2$d words)', 'wp-ai-post-creator' ),
			$orig_words,
			$new_words
		), 'success' );
		$this->advance( $job );
	}

	/**
	 * Step (rewrite): update the existing post in place.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 * @throws Exception On failure.
	 */
	/**
	 * Image-repair mode (1.22.0): attach the generated image to the
	 * existing post. The content is never touched.
	 *
	 * @param array $job Job (by ref).
	 * @return void
	 */
	private function step_img_finalize( array &$job ) {
		$post_id = (int) $job['post_id'];
		$att     = isset( $job['data']['image']['attachment_id'] ) ? (int) $job['data']['image']['attachment_id'] : 0;

		if ( $att > 0 ) {
			set_post_thumbnail( $post_id, $att );
			$this->log( $job, sprintf(
				/* translators: 1: post title, 2: post id. */
				__( 'Featured image repaired for “%1$s” (#%2$d).', 'wp-ai-post-creator' ),
				get_the_title( $post_id ),
				$post_id
			), 'success' );
		} else {
			$this->log( $job, __( 'No image could be generated — the post keeps no featured image for now.', 'wp-ai-post-creator' ), 'warn' );
		}

		$job['data']['result'] = array(
			'post_id' => $post_id,
			'title'   => get_the_title( $post_id ),
			'edit'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'view'    => (string) get_permalink( $post_id ),
			'words'   => 0,
			'status'  => (string) get_post_status( $post_id ),
		);
		$job['status']   = 'done';
		$job['notified'] = 1; // Repairs stay quiet — no "post created" Bale message.
		$this->record_stats( $job );
		$this->advance( $job );
	}

	private function step_rw_finalize( array &$job ) {
		$post_id = AIPC_Post_Builder::update( $job );
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
			'status'  => get_post_status( $post_id ),
		);

		$job['status'] = 'done';

		$this->log( $job, sprintf(
			/* translators: %d: post id. */
			__( 'Post updated: #%d', 'wp-ai-post-creator' ),
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

		// Content-refresh bookkeeping (1.22.0): remember when this post
		// was last rewritten so the refresh scheduler can rotate fairly.
		update_post_meta( (int) $post_id, '_aipc_refreshed', time() );

		// The aipc_post_created notification (Bale etc.) is fired by
		// execute_step() when the finished job state is saved.

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
		// Idempotency guard (1.19.0): when an overlapping runner already
		// created this job's post (e.g. a cron re-arm during a hanging
		// provider request), reuse it — never create the post twice.
		$existing = AIPC_Post_Builder::post_for_job( $job['id'] );
		if ( $existing && 'rewrite' !== $job['mode'] ) {
			$job['post_id'] = (int) $existing;
			$job['data']['result'] = array(
				'post_id' => (int) $existing,
				'title'   => get_the_title( $existing ),
				'edit'    => admin_url( 'post.php?post=' . $existing . '&action=edit' ),
				'view'    => (string) get_permalink( $existing ),
				'words'   => self::count_words( AIPC_Post_Builder::build_content( $job ) ),
				'status'  => (string) get_post_status( $existing ),
			);
			$this->log( $job, sprintf(
				/* translators: %d: post id. */
				__( 'This job already created post #%d — duplicate finalize skipped.', 'wp-ai-post-creator' ),
				$existing
			), 'warn' );
			$job['status'] = 'done';
			$this->advance( $job );
			return;
		}

		// Quality gate (1.22.0): zero-cost checks on the finished HTML.
		// A low score cancels auto-publishing — the post stays a draft.
		$aipc_q = AIPC_Quality::check( AIPC_Post_Builder::build_content( $job ), $job );
		$job['data']['quality'] = $aipc_q;

		/**
		 * Filter the minimum quality score required for auto-publishing.
		 *
		 * @param int   $threshold Default 60.
		 * @param array $job       Job.
		 */
		$aipc_thr = (int) apply_filters( 'aipc_quality_threshold', 60, $job );
		if ( $aipc_q['score'] < $aipc_thr && in_array( $job['args']['publish_mode'], array( 'now', 'delay' ), true ) ) {
			$job['data']['quality']['blocked'] = 1;
			$job['args']['publish_mode']       = 'draft';
			$this->log( $job, sprintf(
				/* translators: 1: score, 2: threshold, 3: issue list. */
				__( 'Quality gate: score %1$d/100 is below %2$d — auto-publish cancelled, the post stays a draft for review. Issues: %3$s', 'wp-ai-post-creator' ),
				$aipc_q['score'],
				$aipc_thr,
				implode( ' · ', $aipc_q['issues'] )
			), 'warn' );
		} else {
			$this->log( $job, sprintf(
				/* translators: %d: quality score. */
				__( 'Quality score: %d/100.', 'wp-ai-post-creator' ),
				$aipc_q['score']
			), $aipc_q['score'] < $aipc_thr ? 'warn' : 'info' );
		}

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

		// Scheduled auto-publishing (configured per schedule entry).
		$publish_mode = isset( $job['args']['publish_mode'] ) ? $job['args']['publish_mode'] : 'draft';
		if ( 'now' === $publish_mode ) {
			$up = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
			if ( ! is_wp_error( $up ) ) {
				$job['data']['result']['status'] = 'publish';
				$this->log( $job, __( 'Post published.', 'wp-ai-post-creator' ), 'success' );
			}
		} elseif ( 'delay' === $publish_mode ) {
			$minutes = max( 15, (int) $job['args']['publish_delay'] );
			wp_schedule_single_event( time() + $minutes * MINUTE_IN_SECONDS, 'aipc_publish_post', array( $post_id, $job['id'] ) );
			$this->log( $job, sprintf(
				/* translators: %s: date/time. */
				__( 'Scheduled to publish at %s.', 'wp-ai-post-creator' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() + $minutes * MINUTE_IN_SECONDS )
			), 'info' );
			$job['data']['result']['status'] = 'scheduled';
		}

		$job['status'] = 'done';

		$this->log( $job, sprintf(
			/* translators: %d: post id. */
			( 'publish' === $job['data']['result']['status'] )
				? __( 'Post published: #%d', 'wp-ai-post-creator' )
				: __( 'Draft created: #%d', 'wp-ai-post-creator' ),
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

		$state = array(
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

		// Force-image wait (1.21.0): tell every consumer (background
		// runner, console) when the next image attempt is scheduled.
		if ( 'running' === $job['status'] && ! empty( $job['args']['force_image'] )
			&& ! empty( $job['data']['image_retry']['next'] )
			&& (int) $job['data']['image_retry']['next'] > time() ) {
			$state['retry_at'] = (int) $job['data']['image_retry']['next'];
		}

		return $state;
	}
}
