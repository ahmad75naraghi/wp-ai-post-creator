<?php
/**
 * Two-way Bale control: run the plugin from inside Bale chats.
 *
 * A 5-minute WP-Cron event (plus every scheduler tick, as a safety net)
 * polls getUpdates on the configured bot. Messages from the configured
 * chats only are parsed as commands and answered:
 *
 *   راهنما | /start | help          → command reference
 *   نوشتن: <topic> | /new <topic>   → start a draft run (background)
 *   وضعیت | /status                 → today's runs, drafts and queue state
 *   آخرین | /latest                 → newest AI draft (title + link)
 *   انتشار [n] | /publish [n]       → publish the newest (or n-th) draft
 *   صف | /queue                     → pending topics from the topic queue
 *
 * Unknown commands from authorized chats get a short hint. Messages from
 * other chats are silently ignored.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Bale_Commands {

	const POLL_HOOK       = 'aipc_bale_poll';
	const POLL_INTERVAL   = 300; // Seconds.
	const MAX_DAILY_JOBS  = 20;  // Bale-started jobs per day.
	const MAX_PUBLISH_POS = 10;  // "انتشار n" — newest n drafts.

	/**
	 * Hook the cron events.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::POLL_HOOK, array( __CLASS__, 'poll' ) );
		// Safety net: also poll on every scheduler tick, so lost 5-minute
		// events are caught at worst 15 minutes later.
		add_action( AIPC_Scheduler::CRON_HOOK, array( __CLASS__, 'poll' ), 20 );
	}

	/**
	 * Register the 5-minute interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['aipc_bale_5min'] = array(
			'interval' => self::POLL_INTERVAL,
			'display'  => __( 'Every 5 minutes (AI Post Creator — Bale commands)', 'wp-ai-post-creator' ),
		);
		return $schedules;
	}

	/**
	 * Whether two-way commands are active (setting + token + recipients).
	 *
	 * @param array|null $cfg Bale settings (default: stored).
	 * @return bool
	 */
	public static function is_enabled( $cfg = null ) {
		$cfg = null === $cfg ? AIPC_Bale::all() : $cfg;
		return ! empty( $cfg['two_way'] )
			&& '' !== (string) $cfg['token']
			&& ! empty( AIPC_Bale::recipients( $cfg ) );
	}

	/**
	 * Self-heal the polling event: scheduled while commands are enabled,
	 * removed when they are not.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( self::is_enabled() ) {
			if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'aipc_bale_5min', self::POLL_HOOK );
			}
			return;
		}
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	/**
	 * Remove the polling event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Polling
	 * ------------------------------------------------------------------- */

	/**
	 * Cron callback: read new bot messages and answer the commands.
	 *
	 * @return void
	 */
	public static function poll() {
		$cfg = AIPC_Bale::all();
		if ( ! self::is_enabled( $cfg ) ) {
			return;
		}

		$recipients = AIPC_Bale::recipients( $cfg );
		$last       = isset( $cfg['last_update_id'] ) ? (int) $cfg['last_update_id'] : 0;

		$json = AIPC_Bale::get_updates( $cfg['token'], $last + 1, 20 );
		if ( is_wp_error( $json ) ) {
			return; // Next event retries.
		}

		$updates = isset( $json['result'] ) && is_array( $json['result'] ) ? $json['result'] : array();
		if ( empty( $updates ) ) {
			return;
		}

		$max = $last;
		foreach ( $updates as $update ) {
			$uid = isset( $update['update_id'] ) ? (int) $update['update_id'] : 0;
			if ( $uid > $max ) {
				$max = $uid;
			}

			$message = null;
			foreach ( array( 'message', 'channel_post', 'edited_message' ) as $key ) {
				if ( ! empty( $update[ $key ]['chat']['id'] ) ) {
					$message = $update[ $key ];
					break;
				}
			}
			if ( ! $message ) {
				continue;
			}

			$chat_id = (string) $message['chat']['id'];
			$text    = isset( $message['text'] ) ? trim( (string) $message['text'] ) : '';
			if ( '' === $chat_id || '' === $text ) {
				continue;
			}

			// Only the configured chats may command the site.
			if ( ! in_array( $chat_id, $recipients, true ) ) {
				continue;
			}

			$reply = self::handle( $chat_id, $text );
			if ( is_string( $reply ) && '' !== $reply ) {
				AIPC_Bale::send_message( $cfg['token'], $chat_id, $reply );
			}
		}

		if ( $max !== $last ) {
			$cfg['last_update_id'] = $max;
			AIPC_Bale::save( $cfg );
		}
	}

	/* ---------------------------------------------------------------------
	 * Command dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Parse one message and produce the reply (null = stay silent).
	 *
	 * @param string $chat_id Authorized chat id.
	 * @param string $text    Message text.
	 * @return string|null
	 */
	public static function handle( $chat_id, $text ) {
		$cmd = self::normalize( $text );

		if ( '' === $cmd ) {
			return null;
		}

		// Help.
		if ( in_array( $cmd, array( '/start', '/help', 'راهنما', 'دستورات', 'help' ), true ) ) {
			return self::cmd_help();
		}

		// New draft: "نوشتن: <topic>" / "/new <topic>" / "new: <topic>".
		$topic = '';
		if ( preg_match( '/^(?:نوشتن|جدید|new)\s*[:،]\s*(.+)$/u', $cmd, $m ) ) {
			$topic = $m[1];
		} elseif ( preg_match( '#^/new\s+(.+)$#u', $cmd, $m ) ) {
			$topic = $m[1];
		}
		if ( '' !== $topic ) {
			return self::cmd_new( $topic );
		}

		// Publish: "انتشار [n]" / "/publish [n]".
		if ( preg_match( '/^(?:انتشار|publish|\/publish)(?:\s+(\d{1,2}))?$/u', $cmd, $m ) ) {
			$pos = isset( $m[1] ) ? (int) $m[1] : 1;
			return self::cmd_publish( $pos );
		}

		// Simple commands.
		if ( in_array( $cmd, array( 'وضعیت', '/status', 'status' ), true ) ) {
			return self::cmd_status();
		}
		if ( in_array( $cmd, array( 'آخرین', '/latest', 'latest' ), true ) ) {
			return self::cmd_latest();
		}
		if ( in_array( $cmd, array( 'صف', '/queue', 'queue' ), true ) ) {
			return self::cmd_queue();
		}

		return __( 'I did not understand that command. Send «راهنما» (or help) for the list of commands.', 'wp-ai-post-creator' );
	}

	/**
	 * Command reference.
	 *
	 * @return string
	 */
	private static function cmd_help() {
		$lines   = array();
		$lines[] = '🤖 ' . __( 'AI Post Creator — commands:', 'wp-ai-post-creator' );
		$lines[] = '';
		$lines[] = __( '✍️ نوشتن: <topic> — start a new draft about the topic', 'wp-ai-post-creator' );
		$lines[] = __( '📊 وضعیت — today’s runs, drafts and queue', 'wp-ai-post-creator' );
		$lines[] = __( '📄 آخرین — the newest AI draft', 'wp-ai-post-creator' );
		$lines[] = __( '🚀 انتشار [number] — publish the newest (or n-th) draft', 'wp-ai-post-creator' );
		$lines[] = __( '📋 صف — pending topics in the queue', 'wp-ai-post-creator' );
		$lines[] = __( '❓ راهنما — this list', 'wp-ai-post-creator' );
		$lines[] = '';
		$lines[] = __( 'Only chats listed in the plugin settings can use commands.', 'wp-ai-post-creator' );
		return implode( "\n", $lines );
	}

	/**
	 * Start a draft run from a chat message.
	 *
	 * @param string $topic Topic text.
	 * @return string
	 */
	private static function cmd_new( $topic ) {
		$topic = trim( $topic );
		if ( mb_strlen( $topic ) < 3 ) {
			return __( 'The topic is too short — send e.g. «نوشتن: balcony gardening».', 'wp-ai-post-creator' );
		}

		// Daily cap on chat-started runs (cost guard).
		$midnight = strtotime( 'today', current_time( 'timestamp' ) );
		if ( AIPC_Job_Store::count_since( 'bale', $midnight ) >= self::MAX_DAILY_JOBS ) {
			return sprintf(
				/* translators: %d: daily limit. */
				__( 'Daily limit reached: at most %d posts per day can be started from Bale. Try again tomorrow.', 'wp-ai-post-creator' ),
				self::MAX_DAILY_JOBS
			);
		}

		$job = AIPC_Agent::instance()->create_job( $topic, array( 'publish_mode' => 'draft' ), 'bale' );
		if ( is_wp_error( $job ) ) {
			return $job->get_error_message();
		}

		return sprintf(
			/* translators: %s: topic text. */
			__( '✍️ Got it — I’m writing a draft about “%s” now. I’ll message you here as soon as it’s ready.', 'wp-ai-post-creator' ),
			wp_html_excerpt( $topic, 80, '…' )
		);
	}

	/**
	 * Today's status: runs, drafts, queue.
	 *
	 * @return string
	 */
	private static function cmd_status() {
		$midnight = strtotime( 'today', current_time( 'timestamp' ) );

		$total = AIPC_Job_Store::count_since( '', $midnight );
		$running = count( AIPC_Job_Store::running_ids() );

		$done  = 0;
		$error = 0;
		foreach ( AIPC_Job_Store::since( $midnight ) as $job ) {
			if ( 'done' === $job['status'] ) {
				$done++;
			}
			if ( in_array( $job['status'], array( 'error', 'cancelled' ), true ) ) {
				$error++;
			}
		}

		$drafts = count( get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'draft',
			'numberposts'      => 50,
			'fields'           => 'ids',
			'meta_key'         => '_aipc_generated',
			'suppress_filters' => true,
		) ) );

		$lines   = array();
		$lines[] = '📊 ' . __( 'Today’s status', 'wp-ai-post-creator' );
		$lines[] = sprintf(
			/* translators: 1: total jobs, 2: running, 3: done, 4: failed. */
			__( 'Runs: %1$d (⏳ %2$d · ✅ %3$d · ❌ %4$d)', 'wp-ai-post-creator' ),
			$total,
			$running,
			$done,
			$error
		);
		$lines[] = sprintf(
			/* translators: %d: draft count. */
			__( 'Drafts waiting for review: %d', 'wp-ai-post-creator' ),
			$drafts
		);
		$lines[] = sprintf(
			/* translators: %d: pending topic count. */
			__( 'Pending topics in the queue: %d', 'wp-ai-post-creator' ),
			AIPC_Topic_Queue::count_pending()
		);
		return implode( "\n", $lines );
	}

	/**
	 * The newest AI draft (or published post when there is no draft).
	 *
	 * @return string
	 */
	private static function cmd_latest() {
		$post = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'draft',
			'numberposts'      => 1,
			'meta_key'         => '_aipc_generated',
			'suppress_filters' => true,
		) );
		$status = 'draft';
		if ( empty( $post ) ) {
			$post = get_posts( array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'meta_key'         => '_aipc_generated',
				'suppress_filters' => true,
			) );
			$status = 'publish';
		}
		if ( empty( $post ) ) {
			return __( 'No AI drafts yet — start one with «نوشتن: <topic>».', 'wp-ai-post-creator' );
		}

		$lines   = array();
		$lines[] = '📄 ' . get_the_title( $post[0] );
		$lines[] = '🔗 ' . get_permalink( $post[0] );
		$lines[] = 'publish' === $status
			? __( 'Status: published', 'wp-ai-post-creator' )
			: __( 'Status: draft — send «انتشار» to publish it.', 'wp-ai-post-creator' );
		return implode( "\n", $lines );
	}

	/**
	 * Publish the newest (or n-th) AI draft.
	 *
	 * @param int $pos 1 = newest draft.
	 * @return string
	 */
	private static function cmd_publish( $pos ) {
		$pos = max( 1, min( self::MAX_PUBLISH_POS, (int) $pos ) );

		$posts = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'draft',
			'numberposts'      => self::MAX_PUBLISH_POS,
			'meta_key'         => '_aipc_generated',
			'suppress_filters' => true,
		) );
		if ( empty( $posts ) || ! isset( $posts[ $pos - 1 ] ) ) {
			return __( 'No draft found at that position — send «آخرین» to see the newest draft.', 'wp-ai-post-creator' );
		}

		$post_id = (int) $posts[ $pos - 1 ]->ID;
		$res     = wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		), true );
		if ( is_wp_error( $res ) ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'Could not publish: %s', 'wp-ai-post-creator' ),
				$res->get_error_message()
			);
		}

		$job_id = (string) get_post_meta( $post_id, '_aipc_job', true );
		if ( '' !== $job_id ) {
			AIPC_Agent::instance()->append_log( $job_id, __( 'Post published via a Bale command.', 'wp-ai-post-creator' ), 'success' );
		}

		/**
		 * Fires after a draft has been published from a Bale command
		 * (the 🎉 notification uses the same hook as the review inbox).
		 *
		 * @param int    $post_id Post id.
		 * @param string $job_id  Job id.
		 */
		do_action( 'aipc_post_published', $post_id, $job_id );

		return sprintf(
			/* translators: %s: post title. */
			__( '🚀 Published: %s', 'wp-ai-post-creator' ),
			get_the_title( $post_id )
		) . "\n" . '🔗 ' . get_permalink( $post_id );
	}

	/**
	 * The pending topics in the queue.
	 *
	 * @return string
	 */
	private static function cmd_queue() {
		$pending = AIPC_Topic_Queue::pending();
		if ( empty( $pending ) ) {
			return '📋 ' . __( 'The topic queue is empty. Add topics on the Schedule page.', 'wp-ai-post-creator' );
		}

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %d: pending topic count. */
			__( '📋 Pending topics (%d):', 'wp-ai-post-creator' ),
			count( $pending )
		);
		foreach ( array_slice( $pending, 0, 5 ) as $i => $item ) {
			$lines[] = sprintf( '%d. %s', $i + 1, wp_html_excerpt( $item['text'], 80, '…' ) );
		}
		if ( count( $pending ) > 5 ) {
			$lines[] = sprintf(
				/* translators: %d: remaining topic count. */
				__( '…and %d more', 'wp-ai-post-creator' ),
				count( $pending ) - 5
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Normalize a message for matching: unify Persian/Arabic characters and
	 * digits, collapse whitespace, lowercase Latin.
	 *
	 * @param string $text Raw message.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		$from = array( 'ي', 'ك', 'ۀ', 'ة', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$to   = array( 'ی', 'ک', 'ه', 'ه', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$text = str_replace( $from, $to, $text );

		return strtolower( $text );
	}
}
