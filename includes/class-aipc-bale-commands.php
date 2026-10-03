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
 * Draft notifications also carry an inline keyboard (Publish now /
 * Schedule). Button presses arrive as callback_query updates; "Schedule"
 * waits for the next message in that chat to be the publish date
 * (Jalali or Gregorian, e.g. «1404/07/20 18:30»).
 *
 * «منو» (or /menu) opens an interactive button menu: start a draft about
 * a free topic (the bot asks for it), pick a topic straight from the
 * topic queue, browse the latest drafts and open per-draft action cards,
 * or check today's status — everything tappable, no commands to remember.
 *
 * Unknown commands from authorized chats get a short hint. Messages from
 * other chats are silently ignored.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Bale_Commands {

	const POLL_HOOK       = 'aipc_bale_poll';
	const POLL_INTERVAL   = 60; // Seconds (was 300 before 1.16.0).
	const MAX_DAILY_JOBS  = 20;  // Bale-started jobs per day.
	const MAX_PUBLISH_POS = 10;  // "انتشار n" — newest n drafts.
	const PENDING_OPTION  = 'aipc_bale_pending'; // chat_id → awaited schedule date.
	const PENDING_TTL     = 1800; // Seconds a "send me the date" request stays open.

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
			'display'  => __( 'Every minute (AI Post Creator — bot commands)', 'wp-ai-post-creator' ),
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
			// Re-register when the interval changed in an update (e.g. the
			// 300s → 60s change in 1.16.0): old events keep their snapshot.
			$event = wp_get_scheduled_event( self::POLL_HOOK );
			if ( $event && isset( $event->interval ) && (int) $event->interval !== self::POLL_INTERVAL ) {
				wp_clear_scheduled_hook( self::POLL_HOOK );
				$event = false;
			}
			if ( ! $event && ! wp_next_scheduled( self::POLL_HOOK ) ) {
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
		if ( AIPC_Bale::webhook_active( $cfg ) ) {
			return; // Instant mode: the platform pushes updates to us.
		}

		$last = isset( $cfg['last_update_id'] ) ? (int) $cfg['last_update_id'] : 0;

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
			self::process_update( $update, $cfg );
		}

		if ( $max !== $last ) {
			$cfg = AIPC_Bale::all(); // Re-read: handlers may have written options.
			$cfg['last_update_id'] = $max;
			AIPC_Bale::save( $cfg );
		}
	}

	/**
	 * Handle one update (message or button press) — shared by the cron
	 * poll and the instant webhook.
	 *
	 * @param array      $update One Bot API update.
	 * @param array|null $cfg    Bale settings (default: stored).
	 * @return void
	 */
	public static function process_update( $update, $cfg = null ) {
		$cfg = null === $cfg ? AIPC_Bale::all() : $cfg;
		if ( ! self::is_enabled( $cfg ) || ! is_array( $update ) ) {
			return;
		}
		$recipients = AIPC_Bale::recipients( $cfg );

		// Inline-keyboard button press (menu / publish now / schedule).
		if ( ! empty( $update['callback_query'] ) && is_array( $update['callback_query'] ) ) {
			$cb      = $update['callback_query'];
			$chat_id = isset( $cb['message']['chat']['id'] ) ? (string) $cb['message']['chat']['id'] : '';
			$data    = isset( $cb['data'] ) ? (string) $cb['data'] : '';

			if ( '' !== $chat_id && in_array( $chat_id, $recipients, true ) ) {
				if ( ! empty( $cb['id'] ) ) {
					AIPC_Bale::answer_callback( $cfg['token'], (string) $cb['id'] );
				}
				$reply = self::handle_callback( $chat_id, $data );
				list( $r_text, $r_markup ) = self::reply_parts( $reply );
				if ( '' !== $r_text ) {
					AIPC_Bale::send_message( $cfg['token'], $chat_id, $r_text, $r_markup );
				}
			}
			return;
		}

		$message = null;
		foreach ( array( 'message', 'channel_post', 'edited_message' ) as $key ) {
			if ( ! empty( $update[ $key ]['chat']['id'] ) ) {
				$message = $update[ $key ];
				break;
			}
		}
		if ( ! $message ) {
			return;
		}

		$chat_id = (string) $message['chat']['id'];
		$text    = isset( $message['text'] ) ? trim( (string) $message['text'] ) : '';
		if ( '' === $chat_id || '' === $text ) {
			return;
		}

		// Only the configured chats may command the site.
		if ( ! in_array( $chat_id, $recipients, true ) ) {
			return;
		}

		// Replying to a draft notification? Then a date in the reply
		// schedules exactly that post — one message, zero roundtrips.
		$reply_post = 0;
		if ( ! empty( $message['reply_to_message']['message_id'] ) ) {
			$reply_post = AIPC_Bale::post_for_message( $chat_id, (int) $message['reply_to_message']['message_id'] );
		}

		$reply = self::handle( $chat_id, $text, $reply_post );
		list( $r_text, $r_markup ) = self::reply_parts( $reply );
		if ( '' !== $r_text ) {
			AIPC_Bale::send_message( $cfg['token'], $chat_id, $r_text, $r_markup );
		}
	}

	/* ---------------------------------------------------------------------
	 * Command dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Parse one message and produce the reply (null = stay silent).
	 *
	 * @param string $chat_id    Authorized chat id.
	 * @param string $text       Message text.
	 * @param int    $reply_post Post id when the message replies to one of
	 *                           our draft notifications (0 = none).
	 * @return string|array|null
	 */
	public static function handle( $chat_id, $text, $reply_post = 0 ) {
		$cmd = self::normalize( $text );

		if ( '' === $cmd ) {
			return null;
		}

		// A button pressed earlier in this chat is waiting for an answer:
		// either the publish date ("Schedule") or a topic ("New topic").
		$entry = self::get_pending_entry( $chat_id );
		if ( $entry ) {
			if ( in_array( $cmd, array( 'لغو', 'cancel', '/cancel' ), true ) ) {
				self::clear_pending( $chat_id );
				return 'topic' === $entry['mode']
					? __( 'Okay, cancelled.', 'wp-ai-post-creator' )
					: __( 'Scheduling cancelled — the post stays as it is.', 'wp-ai-post-creator' );
			}
			if ( 'topic' === $entry['mode'] ) {
				// The raw message (not the lowercased normalization) is the topic.
				self::clear_pending( $chat_id );
				return self::cmd_new( trim( wp_strip_all_tags( (string) $text ) ) );
			}
			$ts = self::parse_datetime( $cmd );
			if ( $ts ) {
				if ( $ts < time() + MINUTE_IN_SECONDS ) {
					return __( 'That time is already in the past — send a future date and time.', 'wp-ai-post-creator' );
				}
				self::clear_pending( $chat_id );
				return self::schedule_post( (int) $entry['post'], $ts );
			}
			return __( 'I could not read that date — send it like «1404/07/20 18:30» or «2026-10-12 18:30», or «لغو» to cancel.', 'wp-ai-post-creator' );
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
		if ( in_array( $cmd, array( 'منو', '/menu', 'menu' ), true ) ) {
			return self::cmd_menu();
		}
		if ( in_array( $cmd, array( 'وضعیت', '/status', 'status' ), true ) ) {
			return self::cmd_status();
		}
		if ( in_array( $cmd, array( 'آخرین', '/latest', 'latest' ), true ) ) {
			return self::cmd_latest();
		}
		if ( in_array( $cmd, array( 'صف', '/queue', 'queue' ), true ) ) {
			return self::cmd_queue();
		}
		if ( in_array( $cmd, array( 'پیش‌نویس‌ها', 'پیشنویسها', 'پیش نویس ها', '/drafts', 'drafts', 'لیست' ), true ) ) {
			return self::cmd_drafts();
		}

		// One-step scheduling (1.17.0): a bare date/time — no button press
		// needed. As a reply to a draft notification it schedules that very
		// post, otherwise the newest AI draft.
		$ts = self::parse_datetime( $cmd );
		if ( $ts ) {
			if ( $ts < time() + MINUTE_IN_SECONDS ) {
				return __( 'That time is already in the past — send a future date and time.', 'wp-ai-post-creator' );
			}
			$target = (int) $reply_post;
			if ( ! $target ) {
				$drafts = get_posts( array(
					'post_type'        => 'post',
					'post_status'      => 'draft',
					'numberposts'      => 1,
					'meta_key'         => '_aipc_generated',
					'suppress_filters' => true,
				) );
				$target = empty( $drafts ) ? 0 : (int) $drafts[0]->ID;
			}
			if ( ! $target ) {
				return __( 'No AI draft is waiting to be scheduled — start one with «نوشتن: topic» first.', 'wp-ai-post-creator' );
			}
			return self::schedule_post( $target, $ts );
		}

		// Unknown input: hint + the tappable menu, so nobody is ever stuck.
		return array(
			'text'   => __( 'I did not understand that command. Send «راهنما» (or help) for the list of commands.', 'wp-ai-post-creator' ),
			'markup' => self::menu_keyboard(),
		);
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
		$lines[] = __( '📑 پیش‌نویس‌ها — the latest drafts, each with action buttons', 'wp-ai-post-creator' );
		$lines[] = __( '📱 منو — the tappable button menu (easiest way!)', 'wp-ai-post-creator' );
		$lines[] = __( '❓ راهنما — this list', 'wp-ai-post-creator' );
		$lines[] = '';
		$lines[] = __( '🔘 Every draft notification has “Publish now” and “Schedule” buttons under it — “Schedule” offers one-tap times, or send a date yourself.', 'wp-ai-post-creator' );
		$lines[] = __( '⚡ Fastest: reply to a draft notification with just the date («فردا 18:30») — it is scheduled immediately, no button needed.', 'wp-ai-post-creator' );
		$lines[] = '';
		$lines[] = __( 'Only chats listed in the plugin settings can use commands.', 'wp-ai-post-creator' );

		// /start and راهنما double as the menu: buttons right away.
		return array(
			'text'   => implode( "\n", $lines ),
			'markup' => self::menu_keyboard(),
		);
	}

	/**
	 * Start a draft run from a chat message.
	 *
	 * @param string $topic Topic text.
	 * @return string
	 */
	private static function cmd_new( $topic ) {
		$res = self::start_draft( $topic );
		return $res['reply'];
	}

	/**
	 * Start a background draft run (shared by «نوشتن», the "New topic"
	 * button and the queue buttons): length check, daily cap, job.
	 *
	 * @param string $topic Topic text.
	 * @return array {reply: string, job_id: string ('' on failure)}
	 */
	private static function start_draft( $topic ) {
		$topic = trim( $topic );
		if ( mb_strlen( $topic ) < 3 ) {
			return array(
				'reply'  => __( 'The topic is too short — send e.g. «نوشتن: balcony gardening».', 'wp-ai-post-creator' ),
				'job_id' => '',
			);
		}

		// Daily cap on chat-started runs (cost guard).
		$midnight = strtotime( 'today', current_time( 'timestamp' ) );
		if ( AIPC_Job_Store::count_since( 'bale', $midnight ) >= self::MAX_DAILY_JOBS ) {
			return array(
				'reply'  => sprintf(
					/* translators: %d: daily limit. */
					__( 'Daily limit reached: at most %d posts per day can be started from Bale. Try again tomorrow.', 'wp-ai-post-creator' ),
					self::MAX_DAILY_JOBS
				),
				'job_id' => '',
			);
		}

		$job = AIPC_Agent::instance()->create_job( $topic, array( 'publish_mode' => 'draft' ), 'bale' );
		if ( is_wp_error( $job ) ) {
			return array(
				'reply'  => $job->get_error_message(),
				'job_id' => '',
			);
		}

		return array(
			'reply'  => sprintf(
				/* translators: %s: topic text. */
				__( '✍️ Got it — I’m writing a draft about “%s” now. I’ll message you here as soon as it’s ready.', 'wp-ai-post-creator' ),
				wp_html_excerpt( $topic, 80, '…' )
			),
			'job_id' => isset( $job['id'] ) ? (string) $job['id'] : '',
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
		$rows = array();
		foreach ( array_slice( $pending, 0, 5 ) as $i => $item ) {
			$lines[] = sprintf( '%d. %s', $i + 1, wp_html_excerpt( $item['text'], 80, '…' ) );
			if ( isset( $item['id'] ) ) {
				$rows[] = array(
					array(
						'text'          => '✍️ ' . ( $i + 1 ) . '. ' . wp_html_excerpt( $item['text'], 26, '…' ),
						'callback_data' => 'aipc:qrun:' . $item['id'],
					),
				);
			}
		}
		if ( count( $pending ) > 5 ) {
			$lines[] = sprintf(
				/* translators: %d: remaining topic count. */
				__( '…and %d more', 'wp-ai-post-creator' ),
				count( $pending ) - 5
			);
		}
		$lines[] = __( 'Tap a topic to start writing it right away.', 'wp-ai-post-creator' );

		return array(
			'text'   => implode( "\n", $lines ),
			'markup' => empty( $rows ) ? null : array( 'inline_keyboard' => $rows ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Inline-keyboard actions (publish now / schedule)
	 * ------------------------------------------------------------------- */

	/**
	 * Handle a button press (post notifications and the interactive menu).
	 *
	 * @param string $chat_id Authorized chat id.
	 * @param string $data    callback_data (aipc:…).
	 * @return array|string|null Reply text or {text, markup} (null = silent).
	 */
	public static function handle_callback( $chat_id, $data ) {
		$data = (string) $data;

		// Menu actions.
		if ( 'aipc:menu' === $data ) {
			return self::cmd_menu();
		}
		if ( 'aipc:help' === $data ) {
			return self::cmd_help();
		}
		if ( 'aipc:status' === $data ) {
			return self::cmd_status();
		}
		if ( 'aipc:queue' === $data ) {
			return self::cmd_queue();
		}
		if ( 'aipc:drafts' === $data ) {
			return self::cmd_drafts();
		}
		if ( 'aipc:new' === $data ) {
			self::set_pending( $chat_id, 'topic' );
			return '✍️ ' . __( 'Send the topic as your next message — I’ll start writing right away. Send «لغو» to cancel.', 'wp-ai-post-creator' );
		}
		if ( preg_match( '/^aipc:qrun:([a-z0-9_]{1,40})$/', $data, $m ) ) {
			return self::cmd_queue_run( $m[1] );
		}
		if ( preg_match( '/^aipc:post:(\d+)$/', $data, $m ) ) {
			return self::cmd_post_card( (int) $m[1] );
		}

		// Quick-schedule preset (1.17.0): one tap, no date typing.
		if ( preg_match( '/^aipc:when:(\d+):(tonight|tom_am|tom_pm|d2_am)$/', $data, $m ) ) {
			self::clear_pending( $chat_id );
			return self::schedule_post( (int) $m[1], self::preset_ts( $m[2] ) );
		}

		if ( ! preg_match( '/^aipc:(pub|sch):(\d+)$/', $data, $m ) ) {
			return null;
		}

		$post_id = (int) $m[2];
		$post    = get_post( $post_id );
		if ( ! $post || '' === (string) get_post_meta( $post_id, '_aipc_generated', true ) ) {
			return __( 'Post not found — it may have been deleted.', 'wp-ai-post-creator' );
		}
		if ( 'publish' === $post->post_status ) {
			return __( 'This post is already published.', 'wp-ai-post-creator' ) . "\n" . '🔗 ' . get_permalink( $post_id );
		}

		if ( 'pub' === $m[1] ) {
			return self::publish_now( $post_id );
		}

		self::set_pending( $chat_id, 'date', $post_id );
		return array(
			'text'   => '⏰ ' . __( 'Tap a quick time below, or send the date and time in one message — Jalali «1404/07/20 18:30» or Gregorian «2026-10-12 18:30»; «فردا 18:30» and «امروز 22:00» work too. Tip: next time just reply to the draft notification with a date — no button needed. Send «لغو» to cancel.', 'wp-ai-post-creator' ),
			'markup' => self::quick_schedule_keyboard( $post_id ),
		);
	}

	/**
	 * Inline keyboard with four one-tap schedule presets.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function quick_schedule_keyboard( $post_id ) {
		$post_id = (int) $post_id;
		return array(
			'inline_keyboard' => array(
				array(
					array( 'text' => '🌙 ' . __( 'Tonight 21:00', 'wp-ai-post-creator' ), 'callback_data' => 'aipc:when:' . $post_id . ':tonight' ),
					array( 'text' => '🌅 ' . __( 'Tomorrow 09:00', 'wp-ai-post-creator' ), 'callback_data' => 'aipc:when:' . $post_id . ':tom_am' ),
				),
				array(
					array( 'text' => '🌇 ' . __( 'Tomorrow 18:00', 'wp-ai-post-creator' ), 'callback_data' => 'aipc:when:' . $post_id . ':tom_pm' ),
					array( 'text' => '📅 ' . __( 'In two days 09:00', 'wp-ai-post-creator' ), 'callback_data' => 'aipc:when:' . $post_id . ':d2_am' ),
				),
			),
		);
	}

	/**
	 * Timestamp for a quick-schedule preset code, in the site timezone.
	 * «Tonight» rolls over to tomorrow when 21:00 has already passed.
	 *
	 * @param string $code tonight|tom_am|tom_pm|d2_am.
	 * @return int
	 */
	public static function preset_ts( $code ) {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		switch ( $code ) {
			case 'tonight':
				$dt = $now->setTime( 21, 0, 0 );
				if ( $dt->getTimestamp() < time() + 5 * MINUTE_IN_SECONDS ) {
					$dt = $dt->modify( '+1 day' );
				}
				break;
			case 'tom_pm':
				$dt = $now->modify( '+1 day' )->setTime( 18, 0, 0 );
				break;
			case 'd2_am':
				$dt = $now->modify( '+2 day' )->setTime( 9, 0, 0 );
				break;
			case 'tom_am':
			default:
				$dt = $now->modify( '+1 day' )->setTime( 9, 0, 0 );
		}
		return $dt->getTimestamp();
	}

	/**
	 * The interactive main menu.
	 *
	 * @return array {text, markup}
	 */
	private static function cmd_menu() {
		return array(
			'text'   => '🤖 ' . __( 'What would you like to do?', 'wp-ai-post-creator' ),
			'markup' => self::menu_keyboard(),
		);
	}

	/**
	 * The main-menu inline keyboard (also attached to unknown input).
	 *
	 * @return array reply_markup array.
	 */
	public static function menu_keyboard() {
		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => '✍️ ' . __( 'New topic', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:new',
					),
					array(
						'text'          => '📋 ' . __( 'Topic queue', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:queue',
					),
				),
				array(
					array(
						'text'          => '📑 ' . __( 'Drafts', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:drafts',
					),
					array(
						'text'          => '📊 ' . __( 'Status', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:status',
					),
				),
				array(
					array(
						'text'          => '❓ ' . __( 'Help', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:help',
					),
				),
			),
		);
	}

	/**
	 * Start writing a topic straight from the queue (queue button press).
	 *
	 * @param string $id Queue item id (tq_…).
	 * @return string Reply text.
	 */
	private static function cmd_queue_run( $id ) {
		$topic = '';
		foreach ( AIPC_Topic_Queue::pending() as $item ) {
			if ( isset( $item['id'] ) && $id === $item['id'] ) {
				$topic = (string) $item['text'];
				break;
			}
		}
		if ( '' === $topic ) {
			return __( 'That topic is no longer in the queue — send «صف» for the current list.', 'wp-ai-post-creator' );
		}

		$res = self::start_draft( $topic );
		if ( '' !== $res['job_id'] ) {
			AIPC_Topic_Queue::mark_used( $id, $res['job_id'] );
		}
		return $res['reply'];
	}

	/**
	 * The latest AI drafts, each with a button that opens its action card.
	 *
	 * @return array|string {text, markup}, or plain text when empty.
	 */
	private static function cmd_drafts() {
		$posts = get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'draft',
			'numberposts'      => 5,
			'meta_key'         => '_aipc_generated',
			'suppress_filters' => true,
		) );
		if ( empty( $posts ) ) {
			return __( 'No AI drafts yet — start one with «نوشتن: <topic>».', 'wp-ai-post-creator' );
		}

		$lines   = array();
		$lines[] = '📑 ' . __( 'Latest drafts — tap one for actions:', 'wp-ai-post-creator' );
		$rows    = array();
		foreach ( $posts as $i => $post ) {
			$lines[] = sprintf(
				'%d. %s (%s)',
				$i + 1,
				get_the_title( $post ),
				wp_date( get_option( 'date_format' ), get_post_timestamp( $post ) )
			);
			$rows[] = array(
				array(
					'text'          => ( $i + 1 ) . '. ' . wp_html_excerpt( get_the_title( $post ), 28, '…' ),
					'callback_data' => 'aipc:post:' . (int) $post->ID,
				),
			);
		}

		return array(
			'text'   => implode( "\n", $lines ),
			'markup' => array( 'inline_keyboard' => $rows ),
		);
	}

	/**
	 * One draft's action card: title, date, link + publish/schedule buttons.
	 *
	 * @param int $post_id Post id.
	 * @return array|string {text, markup}, or plain text.
	 */
	private static function cmd_post_card( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || '' === (string) get_post_meta( $post_id, '_aipc_generated', true ) ) {
			return __( 'Post not found — it may have been deleted.', 'wp-ai-post-creator' );
		}

		$lines   = array();
		$lines[] = '📄 ' . get_the_title( $post );
		$lines[] = '📅 ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_post_timestamp( $post ) );
		$lines[] = '🔗 ' . get_permalink( $post );
		if ( 'publish' === $post->post_status ) {
			$lines[] = __( 'Status: published', 'wp-ai-post-creator' );
		} elseif ( 'future' === $post->post_status ) {
			$lines[] = sprintf(
				/* translators: %s: date/time. */
				__( 'Status: scheduled for %s.', 'wp-ai-post-creator' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_post_timestamp( $post ) )
			);
		} else {
			$lines[] = __( 'Status: draft — choose an action below.', 'wp-ai-post-creator' );
		}

		return array(
			'text'   => implode( "\n", $lines ),
			'markup' => AIPC_Bale::post_keyboard( $post_id ),
		);
	}

	/**
	 * Split a handler reply into text + optional reply_markup.
	 *
	 * @param array|string|null $reply Handler return value.
	 * @return array {string, array|null}
	 */
	private static function reply_parts( $reply ) {
		if ( is_array( $reply ) ) {
			return array(
				isset( $reply['text'] ) ? (string) $reply['text'] : '',
				isset( $reply['markup'] ) && is_array( $reply['markup'] ) ? $reply['markup'] : null,
			);
		}
		return array( is_string( $reply ) ? $reply : '', null );
	}

	/**
	 * Publish a post immediately, stamped with the current date/time.
	 *
	 * @param int $post_id Post id.
	 * @return string Reply text.
	 */
	private static function publish_now( $post_id ) {
		$now = current_time( 'mysql' );
		$res = wp_update_post( array(
			'ID'            => $post_id,
			'post_status'   => 'publish',
			'post_date'     => $now,
			'post_date_gmt' => get_gmt_from_date( $now ),
			'edit_date'     => true,
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

		/** This action is documented in includes/class-aipc-bale-commands.php */
		do_action( 'aipc_post_published', $post_id, $job_id );

		return sprintf(
			/* translators: %s: post title. */
			__( '🚀 Published: %s', 'wp-ai-post-creator' ),
			get_the_title( $post_id )
		) . "\n" . '🔗 ' . get_permalink( $post_id );
	}

	/**
	 * Schedule a post for a future timestamp (native WP `future` status —
	 * WordPress publishes it on time and `future_to_publish` fires the
	 * Bale 🎉 notification).
	 *
	 * @param int $post_id Post id.
	 * @param int $ts      Unix timestamp (future).
	 * @return string Reply text.
	 */
	public static function schedule_post( $post_id, $ts ) {
		$post = get_post( $post_id );
		if ( ! $post || '' === (string) get_post_meta( $post_id, '_aipc_generated', true ) ) {
			return __( 'Post not found — it may have been deleted.', 'wp-ai-post-creator' );
		}
		if ( 'publish' === $post->post_status ) {
			return __( 'This post is already published.', 'wp-ai-post-creator' ) . "\n" . '🔗 ' . get_permalink( $post_id );
		}

		$local = wp_date( 'Y-m-d H:i:s', $ts );
		$res   = wp_update_post( array(
			'ID'            => $post_id,
			'post_status'   => 'future',
			'post_date'     => $local,
			'post_date_gmt' => get_gmt_from_date( $local ),
			'edit_date'     => true,
		), true );
		if ( is_wp_error( $res ) ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'Could not schedule: %s', 'wp-ai-post-creator' ),
				$res->get_error_message()
			);
		}

		update_post_meta( $post_id, '_aipc_bale_scheduled', 1 );

		$when   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		$job_id = (string) get_post_meta( $post_id, '_aipc_job', true );
		if ( '' !== $job_id ) {
			AIPC_Agent::instance()->append_log( $job_id, sprintf(
				/* translators: %s: date/time. */
				__( 'Post scheduled from Bale for %s.', 'wp-ai-post-creator' ),
				$when
			), 'info' );
		}

		return sprintf(
			/* translators: 1: post title, 2: date/time. */
			__( '⏰ Scheduled: “%1$s” will be published on %2$s.', 'wp-ai-post-creator' ),
			get_the_title( $post_id ),
			$when
		);
	}

	/**
	 * Parse a chat message as a date/time in the site timezone.
	 *
	 * Accepted: «1404/07/20 18:30» (Jalali years 1200–1599), «2026-10-12
	 * 18:30» (Gregorian), Persian digits, `.`/`-`/`/` separators, and
	 * «امروز|فردا|today|tomorrow [HH:MM]». A missing time means 09:00.
	 *
	 * @param string $text Raw message.
	 * @return int Unix timestamp, or 0 when unparseable.
	 */
	public static function parse_datetime( $text ) {
		$text = self::normalize( $text );
		$tz   = wp_timezone();

		// Relative day.
		if ( preg_match( '/^(امروز|فردا|today|tomorrow)(?:\s+(\d{1,2}):(\d{1,2}))?$/u', $text, $m ) ) {
			$h  = isset( $m[2] ) ? (int) $m[2] : 9;
			$i  = isset( $m[3] ) ? (int) $m[3] : 0;
			if ( $h > 23 || $i > 59 ) {
				return 0;
			}
			$dt = new DateTimeImmutable( 'now', $tz );
			if ( in_array( $m[1], array( 'فردا', 'tomorrow' ), true ) ) {
				$dt = $dt->modify( '+1 day' );
			}
			return $dt->setTime( $h, $i, 0 )->getTimestamp();
		}

		// Absolute date, optional time.
		if ( ! preg_match( '#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?$#u', $text, $m ) ) {
			return 0;
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
		$h  = isset( $m[4] ) ? (int) $m[4] : 9;
		$i  = isset( $m[5] ) ? (int) $m[5] : 0;
		if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $i > 59 ) {
			return 0;
		}

		if ( $y >= 1200 && $y < 1600 ) {
			list( $y, $mo, $d ) = self::jalali_to_gregorian( $y, $mo, $d );
		}
		if ( ! checkdate( $mo, $d, $y ) ) {
			return 0;
		}

		$dt = DateTimeImmutable::createFromFormat(
			'Y-n-j G:i|',
			sprintf( '%d-%d-%d %d:%02d', $y, $mo, $d, $h, $i ),
			$tz
		);
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * Jalali → Gregorian conversion (astronomical, 33-year cycle).
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month (1–12).
	 * @param int $jd Jalali day.
	 * @return array {gy, gm, gd}
	 */
	private static function jalali_to_gregorian( $jy, $jm, $jd ) {
		$jy  += 1595;
		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 )
			+ $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );

		$gy    = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			$days--;
			$gy   += 100 * intdiv( $days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}
		$gd = $days + 1;

		$leap  = ( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400 );
		$sal_a = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$gm    = 0;
		while ( $gm < 13 && $gd > $sal_a[ $gm ] ) {
			$gd -= $sal_a[ $gm ];
			$gm++;
		}
		return array( $gy, $gm, $gd );
	}

	/* ---------------------------------------------------------------------
	 * Pending "send me the date" state (per chat)
	 * ------------------------------------------------------------------- */

	/**
	 * All pending schedule requests (chat_id → {post, until}).
	 *
	 * @return array
	 */
	private static function pending_all() {
		$p = get_option( self::PENDING_OPTION, array() );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * Remember that this chat owes us an answer: a publish date for a
	 * post (mode `date`) or a topic for a new draft (mode `topic`).
	 *
	 * @param string $chat_id Chat id.
	 * @param string $mode    date|topic.
	 * @param int    $post_id Post id (mode `date` only).
	 * @return void
	 */
	private static function set_pending( $chat_id, $mode, $post_id = 0 ) {
		$p = self::pending_all();
		$p[ (string) $chat_id ] = array(
			'mode'  => 'topic' === $mode ? 'topic' : 'date',
			'post'  => (int) $post_id,
			'until' => time() + self::PENDING_TTL,
		);
		update_option( self::PENDING_OPTION, $p, false );
	}

	/**
	 * The open question for this chat (null = none / expired).
	 *
	 * @param string $chat_id Chat id.
	 * @return array|null {mode, post, until}
	 */
	public static function get_pending_entry( $chat_id ) {
		$p = self::pending_all();
		$k = (string) $chat_id;
		if ( empty( $p[ $k ] ) || ! is_array( $p[ $k ] ) ) {
			return null;
		}
		if ( time() > (int) $p[ $k ]['until'] ) {
			self::clear_pending( $chat_id );
			return null;
		}
		return wp_parse_args( $p[ $k ], array( 'mode' => 'date', 'post' => 0 ) );
	}

	/**
	 * The post this chat is scheduling right now (0 = none / expired).
	 *
	 * @param string $chat_id Chat id.
	 * @return int
	 */
	public static function get_pending( $chat_id ) {
		$entry = self::get_pending_entry( $chat_id );
		return ( $entry && 'date' === $entry['mode'] ) ? (int) $entry['post'] : 0;
	}

	/**
	 * Forget the pending schedule request of a chat.
	 *
	 * @param string $chat_id Chat id.
	 * @return void
	 */
	private static function clear_pending( $chat_id ) {
		$p = self::pending_all();
		unset( $p[ (string) $chat_id ] );
		if ( empty( $p ) ) {
			delete_option( self::PENDING_OPTION );
		} else {
			update_option( self::PENDING_OPTION, $p, false );
		}
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
