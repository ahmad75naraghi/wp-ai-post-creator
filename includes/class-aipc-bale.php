<?php
/**
 * Bale messenger notifications.
 *
 * After every successfully created post the agent fires `aipc_post_created`;
 * this class sends the featured image + summary + link to every configured
 * Bale chat (person or channel) through the Bale Bot API
 * (https://tapi.bale.ai/bot<token>/…). It can also send a daily/weekly
 * activity report with usage statistics.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Bale {

	const OPTION      = 'aipc_bale';
	const API_BASE    = 'https://tapi.bale.ai/bot';
	const API_BASE_TG = 'https://api.telegram.org/bot';

	/**
	 * Hook the post-created notification.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'aipc_post_created', array( __CLASS__, 'notify' ), 10, 2 );
		add_action( 'aipc_post_published', array( __CLASS__, 'notify_published' ), 10, 2 );
		// Posts scheduled from a Bale chat announce themselves when WP
		// publishes them at the chosen time.
		add_action( 'future_to_publish', array( __CLASS__, 'on_future_publish' ) );
	}

	/**
	 * Settings (enabled, token, recipients, periodic report).
	 *
	 * @return array
	 */
	public static function all() {
		$cfg = get_option( self::OPTION, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return wp_parse_args( $cfg, array(
			'enabled'        => 0,
			'platform'       => 'bale', // bale|telegram — same Bot API, different endpoint (1.15.0).
			'token'          => '',
			'chat_ids'       => array(),
			'chat_id'        => '', // Legacy single recipient (1.3.0).
			'report'         => '', // '' | daily | weekly.
			'report_time'    => '21:00',
			'report_day'     => 6, // Weekday for weekly reports (Saturday).
			'last_report'    => '', // Y-m-d when the last report was sent.
			'two_way'        => 0, // Accept commands from chats (1.7.0).
			'last_update_id' => 0, // Last processed getUpdates id.
			'default_image'  => '', // Fallback picture for posts without a featured image (1.9.2).
		) );
	}

	/**
	 * All recipient chat ids (chat_ids list, falling back to the legacy
	 * single chat_id entry).
	 *
	 * @param array|null $cfg Settings (default: stored).
	 * @return array
	 */
	public static function recipients( $cfg = null ) {
		// Tolerate partial arrays (e.g. sanitize() with an empty $old).
		$cfg = null === $cfg
			? self::all()
			: wp_parse_args( (array) $cfg, array( 'chat_ids' => array(), 'chat_id' => '' ) );

		$ids = array();
		foreach ( (array) $cfg['chat_ids'] as $id ) {
			$id = trim( (string) $id );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		if ( empty( $ids ) && '' !== trim( (string) $cfg['chat_id'] ) ) {
			$ids[] = trim( (string) $cfg['chat_id'] );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Parse a free-form recipients field (newline/comma separated).
	 *
	 * @param string $raw Raw input.
	 * @return array
	 */
	public static function parse_recipients( $raw ) {
		$ids = array();
		foreach ( preg_split( '/[\n,]+/u', (string) $raw ) as $id ) {
			$id = sanitize_text_field( trim( $id ) );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sanitize settings. An empty token keeps the stored one (write-only UI).
	 *
	 * @param array     $in  Raw input.
	 * @param array|null $old Stored settings.
	 * @return array
	 */
	public static function sanitize( $in, $old = array() ) {
		$in  = is_array( $in ) ? $in : array();
		$old = is_array( $old ) ? $old : array();

		$token = isset( $in['token'] ) ? trim( (string) $in['token'] ) : '';
		if ( '' === $token ) {
			$token = isset( $old['token'] ) ? $old['token'] : '';
		}

		// Recipients: a posted textarea/list replaces the stored list.
		if ( isset( $in['chat_ids'] ) ) {
			$raw = is_array( $in['chat_ids'] ) ? implode( "\n", $in['chat_ids'] ) : (string) $in['chat_ids'];
			$chat_ids = self::parse_recipients( $raw );
		} elseif ( isset( $in['chat_id'] ) && '' !== trim( (string) $in['chat_id'] ) ) {
			// Legacy single-field post (1.3.0 form / REST arg).
			$chat_ids = self::parse_recipients( (string) $in['chat_id'] );
		} else {
			$chat_ids = self::recipients( $old );
		}

		$report = isset( $in['report'] ) ? sanitize_key( $in['report'] ) : ( isset( $old['report'] ) ? $old['report'] : '' );
		if ( ! in_array( $report, array( '', 'daily', 'weekly' ), true ) ) {
			$report = '';
		}

		$report_time = isset( $in['report_time'] ) ? trim( (string) $in['report_time'] ) : ( isset( $old['report_time'] ) ? $old['report_time'] : '21:00' );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $report_time ) ) {
			$report_time = '21:00';
		}
		$parts       = explode( ':', $report_time );
		$report_time = sprintf( '%02d:%02d', (int) $parts[0], (int) $parts[1] );

		$report_day = isset( $in['report_day'] ) ? absint( $in['report_day'] ) : ( isset( $old['report_day'] ) ? (int) $old['report_day'] : 6 );
		if ( $report_day < 0 || $report_day > 6 ) {
			$report_day = 6;
		}

		$default_image = isset( $in['default_image'] ) ? esc_url_raw( trim( (string) $in['default_image'] ) ) : ( isset( $old['default_image'] ) ? $old['default_image'] : '' );
		if ( '' !== $default_image && 0 !== strpos( $default_image, 'http' ) ) {
			$default_image = '';
		}

		$platform = isset( $in['platform'] ) ? sanitize_key( $in['platform'] ) : ( isset( $old['platform'] ) ? $old['platform'] : 'bale' );
		if ( ! in_array( $platform, array( 'bale', 'telegram' ), true ) ) {
			$platform = 'bale';
		}

		return array(
			'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
			'platform'       => $platform,
			'token'          => sanitize_text_field( $token ),
			'chat_ids'       => $chat_ids,
			'chat_id'        => isset( $old['chat_id'] ) ? $old['chat_id'] : '',
			'report'         => $report,
			'report_time'    => $report_time,
			'report_day'     => $report_day,
			'last_report'    => isset( $old['last_report'] ) ? $old['last_report'] : '',
			'two_way'        => empty( $in['two_way'] ) ? 0 : 1,
			'last_update_id' => isset( $old['last_update_id'] ) ? absint( $old['last_update_id'] ) : 0,
			'default_image'  => $default_image,
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array $cfg Sanitized settings.
	 * @return array
	 */
	public static function save( $cfg ) {
		update_option( self::OPTION, $cfg, false );
		return $cfg;
	}

	/* ---------------------------------------------------------------------
	 * Bot API
	 * ------------------------------------------------------------------- */

	/**
	 * The Bot API base URL for the configured platform. Bale and Telegram
	 * speak the same protocol — only the endpoint differs.
	 *
	 * @return string
	 */
	public static function api_base() {
		$cfg = self::all();
		return 'telegram' === $cfg['platform'] ? self::API_BASE_TG : self::API_BASE;
	}

	/**
	 * Call a Bot API method.
	 *
	 * @param string $token  Bot token.
	 * @param string $method Method name (sendMessage, sendPhoto, getUpdates…).
	 * @param array  $body   JSON body.
	 * @return array|WP_Error Decoded response or error.
	 */
	private static function api( $token, $method, $body ) {
		if ( '' === $token ) {
			return new WP_Error( 'aipc_bale', __( 'Bale bot token is not configured.', 'wp-ai-post-creator' ) );
		}

		$res = wp_remote_post(
			self::api_base() . $token . '/' . $method,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'aipc_bale', sprintf(
				/* translators: %s: error message. */
				__( 'Could not reach Bale: %s', 'wp-ai-post-creator' ),
				$res->get_error_message()
			) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 || empty( $json['ok'] ) ) {
			$msg = isset( $json['description'] ) ? $json['description'] : mb_substr( wp_remote_retrieve_body( $res ), 0, 200 );
			return new WP_Error( 'aipc_bale', sprintf(
				/* translators: %s: API error description. */
				__( 'Bale API error: %s', 'wp-ai-post-creator' ),
				$msg
			) );
		}

		return $json;
	}

	/**
	 * Send a text message.
	 *
	 * @param string     $token   Bot token.
	 * @param string     $chat_id Chat id.
	 * @param string     $text    Message text.
	 * @param array|null $markup  Optional reply_markup (inline keyboard).
	 * @return array|WP_Error
	 */
	public static function send_message( $token, $chat_id, $text, $markup = null ) {
		if ( '' === (string) $chat_id ) {
			return new WP_Error( 'aipc_bale', __( 'Bale chat ID is not configured.', 'wp-ai-post-creator' ) );
		}
		$body = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);
		if ( is_array( $markup ) && ! empty( $markup ) ) {
			$body['reply_markup'] = $markup;
		}
		return self::api( $token, 'sendMessage', $body );
	}

	/**
	 * Send a photo with a caption.
	 *
	 * @param string     $token   Bot token.
	 * @param string     $chat_id Chat id.
	 * @param string     $photo   Photo URL.
	 * @param string     $caption Caption.
	 * @param array|null $markup  Optional reply_markup (inline keyboard).
	 * @return array|WP_Error
	 */
	public static function send_photo( $token, $chat_id, $photo, $caption = '', $markup = null ) {
		$body = array(
			'chat_id' => $chat_id,
			'photo'   => $photo,
		);
		if ( '' !== $caption ) {
			$body['caption'] = $caption;
		}
		if ( is_array( $markup ) && ! empty( $markup ) ) {
			$body['reply_markup'] = $markup;
		}
		return self::api( $token, 'sendPhoto', $body );
	}

	/**
	 * Acknowledge an inline-keyboard button press (dismisses the loading
	 * state on the button). Errors are irrelevant to the caller.
	 *
	 * @param string $token       Bot token.
	 * @param string $callback_id Callback query id.
	 * @return array|WP_Error
	 */
	public static function answer_callback( $token, $callback_id ) {
		return self::api( $token, 'answerCallbackQuery', array(
			'callback_query_id' => $callback_id,
		) );
	}

	/**
	 * Fetch new bot messages (getUpdates). Passing the last processed
	 * update id + 1 confirms everything before it, so already-handled
	 * commands never run twice.
	 *
	 * @param string $token  Bot token.
	 * @param int    $offset Return updates with a greater update id.
	 * @param int    $limit  Maximum updates.
	 * @return array|WP_Error
	 */
	public static function get_updates( $token, $offset = 0, $limit = 20 ) {
		return self::api( $token, 'getUpdates', array(
			'offset' => max( 0, (int) $offset ),
			'limit'  => max( 1, min( 100, (int) $limit ) ),
		) );
	}

	/**
	 * Detect the chat id from the newest message the bot has received.
	 *
	 * @param string $token Bot token.
	 * @return array|WP_Error {chat_id, name}
	 */
	public static function latest_chat_id( $token ) {
		$json = self::api( $token, 'getUpdates', array( 'limit' => 10 ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$updates = isset( $json['result'] ) && is_array( $json['result'] ) ? $json['result'] : array();
		foreach ( array_reverse( $updates ) as $update ) {
			foreach ( array( 'message', 'channel_post', 'edited_message' ) as $key ) {
				if ( ! empty( $update[ $key ]['chat']['id'] ) ) {
					$chat = $update[ $key ]['chat'];
					return array(
						'chat_id' => (string) $chat['id'],
						'name'    => isset( $chat['first_name'] ) ? $chat['first_name']
							: ( isset( $chat['title'] ) ? $chat['title'] : '' ),
					);
				}
			}
		}

		return new WP_Error( 'aipc_bale', __( 'No messages found. Send any message to your bot in Bale first, then try again.', 'wp-ai-post-creator' ) );
	}

	/**
	 * Send a test message to one chat.
	 *
	 * @param string $token   Bot token.
	 * @param string $chat_id Chat id.
	 * @return array|WP_Error
	 */
	public static function test( $token, $chat_id ) {
		return self::send_message(
			$token,
			$chat_id,
			'✅ ' . __( 'Test message from AI Post Creator — your Bale connection works!', 'wp-ai-post-creator' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Post notification
	 * ------------------------------------------------------------------- */

	/**
	 * Notify every configured chat about a freshly created post: featured
	 * image (when present) + title + summary + link.
	 *
	 * @param int    $post_id Post id.
	 * @param string $job_id  Job id.
	 * @return void
	 */
	public static function notify( $post_id, $job_id ) {
		$cfg        = self::all();
		$recipients = self::recipients( $cfg );
		if ( empty( $cfg['enabled'] ) || '' === $cfg['token'] || empty( $recipients ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$text   = self::post_message( $post_id );
		$photo  = self::photo_for( $post_id, $cfg );
		$markup = self::post_keyboard( $post_id, $cfg );

		$sent        = 0;
		$first_error = null;

		foreach ( $recipients as $chat_id ) {
			$done = false;
			if ( '' !== $photo ) {
				$res = self::send_photo( $cfg['token'], $chat_id, $photo, $text, $markup );
				if ( ! is_wp_error( $res ) ) {
					$done = true;
				} elseif ( null === $first_error ) {
					$first_error = $res;
				}
			}
			if ( ! $done ) {
				$res = self::send_message( $cfg['token'], $chat_id, $text, $markup );
				if ( ! is_wp_error( $res ) ) {
					$done = true;
				} elseif ( null === $first_error ) {
					$first_error = $res;
				}
			}
			if ( $done ) {
				$sent++;
			}
		}

		$agent = AIPC_Agent::instance();
		$total = count( $recipients );

		if ( $sent === $total ) {
			if ( 1 === $total ) {
				$agent->append_log( $job_id, __( 'Bale notification sent (image, summary and link).', 'wp-ai-post-creator' ), 'success' );
			} else {
				$agent->append_log( $job_id, sprintf(
					/* translators: %d: number of chats. */
					__( 'Bale notification sent to %d chats (image, summary and link).', 'wp-ai-post-creator' ),
					$sent
				), 'success' );
			}
		} elseif ( $sent > 0 ) {
			$agent->append_log( $job_id, sprintf(
				/* translators: 1: chats reached, 2: total chats. */
				__( 'Bale notification sent to %1$d of %2$d chats.', 'wp-ai-post-creator' ),
				$sent,
				$total
			), 'warn' );
		} else {
			$agent->append_log( $job_id, sprintf(
				/* translators: %s: error message. */
				__( 'Bale notification failed: %s', 'wp-ai-post-creator' ),
				$first_error ? $first_error->get_error_message() : __( 'unknown error', 'wp-ai-post-creator' )
			), 'warn' );
		}
	}

	/**
	 * Notify every chat that a delayed-scheduled post has just been published.
	 *
	 * @param int    $post_id Post id.
	 * @param string $job_id  Job id.
	 * @return void
	 */
	public static function notify_published( $post_id, $job_id ) {
		$cfg        = self::all();
		$recipients = self::recipients( $cfg );
		if ( empty( $cfg['enabled'] ) || '' === $cfg['token'] || empty( $recipients ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$text  = self::post_message( $post_id );
		$photo = self::photo_for( $post_id, $cfg );

		foreach ( $recipients as $chat_id ) {
			$done = false;
			if ( '' !== $photo ) {
				$res  = self::send_photo( $cfg['token'], $chat_id, $photo, $text );
				$done = ! is_wp_error( $res );
			}
			if ( ! $done ) {
				self::send_message( $cfg['token'], $chat_id, $text );
			}
		}
	}

	/**
	 * The chat message for a post notification:
	 *
	 *   🔻Title
	 *
	 *   🌱🌱Summary🌱🌱
	 *   Read the full article at the link below 👇👇👇
	 *   https://…
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function post_message( $post_id ) {
		$post  = get_post( $post_id );
		$title = get_the_title( $post );

		$summary = (string) get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' === $summary ) {
			$summary = (string) get_post_meta( $post_id, '_aipc_meta_description', true );
		}
		if ( '' === $summary && $post ) {
			$summary = (string) $post->post_excerpt;
		}
		$summary = wp_strip_all_tags( $summary );
		if ( mb_strlen( $summary ) > 400 ) {
			$summary = mb_substr( $summary, 0, 400 ) . '…';
		}

		return '🔻' . $title . "\n\n"
			. '🌱🌱' . $summary . '🌱🌱' . "\n"
			. __( 'Read the full article at the link below', 'wp-ai-post-creator' ) . '👇👇👇' . "\n"
			. get_permalink( $post_id );
	}

	/**
	 * The inline keyboard under a draft notification: a "Publish now" and
	 * a "Schedule" button. Only unpublished posts get buttons, and only
	 * when two-way commands are on — pressing a button arrives as a
	 * callback_query through the same getUpdates poll, so without the
	 * poller the buttons would be dead.
	 *
	 * @param int        $post_id Post id.
	 * @param array|null $cfg     Bale settings (default: stored).
	 * @return array|null reply_markup array, or null for no buttons.
	 */
	public static function post_keyboard( $post_id, $cfg = null ) {
		$cfg = null === $cfg ? self::all() : $cfg;
		if ( ! class_exists( 'AIPC_Bale_Commands' ) || ! AIPC_Bale_Commands::is_enabled( $cfg ) ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return null;
		}

		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => '🚀 ' . __( 'Publish now', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:pub:' . (int) $post_id,
					),
					array(
						'text'          => '⏰ ' . __( 'Schedule', 'wp-ai-post-creator' ),
						'callback_data' => 'aipc:sch:' . (int) $post_id,
					),
				),
			),
		);
	}

	/**
	 * When WordPress publishes a post that was scheduled from a Bale chat,
	 * announce it with the same 🎉 notification as other publishes.
	 *
	 * @param WP_Post $post The post that just went future → publish.
	 * @return void
	 */
	public static function on_future_publish( $post ) {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		if ( ! $post_id || '' === (string) get_post_meta( $post_id, '_aipc_bale_scheduled', true ) ) {
			return;
		}
		delete_post_meta( $post_id, '_aipc_bale_scheduled' );

		$job_id = (string) get_post_meta( $post_id, '_aipc_job', true );
		if ( '' !== $job_id ) {
			AIPC_Agent::instance()->append_log( $job_id, __( 'Scheduled post published.', 'wp-ai-post-creator' ), 'success' );
		}

		/** This action is documented in includes/class-aipc-bale-commands.php */
		do_action( 'aipc_post_published', $post_id, $job_id );
	}

	/**
	 * The picture sent above a post notification: the featured image, or
	 * the default notification image configured in the Bale settings.
	 *
	 * @param int   $post_id Post id.
	 * @param array $cfg     Bale settings.
	 * @return string Image URL, or '' when neither exists.
	 */
	private static function photo_for( $post_id, $cfg ) {
		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$url = (string) wp_get_attachment_url( $thumb );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return isset( $cfg['default_image'] ) ? (string) $cfg['default_image'] : '';
	}

	/* ---------------------------------------------------------------------
	 * Periodic report
	 * ------------------------------------------------------------------- */

	/**
	 * Send the periodic report when it is due (called from the cron tick).
	 *
	 * @return bool True when a report was sent.
	 */
	public static function maybe_send_report() {
		$cfg        = self::all();
		$recipients = self::recipients( $cfg );

		if ( '' === $cfg['report'] || '' === $cfg['token'] || empty( $recipients ) ) {
			return false;
		}

		$now   = current_time( 'timestamp' );
		$today = wp_date( 'Y-m-d', $now );

		if ( $cfg['last_report'] === $today ) {
			return false; // Already sent today.
		}

		if ( 'weekly' === $cfg['report'] && (int) wp_date( 'w', $now ) !== (int) $cfg['report_day'] ) {
			return false; // Weekly reports only fire on their weekday.
		}

		$h = (int) wp_date( 'H', $now );
		$i = (int) wp_date( 'i', $now );
		$s = (int) wp_date( 's', $now );
		$midnight = $now - ( $h * 3600 + $i * 60 + $s );

		$parts = explode( ':', $cfg['report_time'] );
		$slot  = $midnight + (int) $parts[0] * 3600 + (int) $parts[1] * 60;

		if ( $now < $slot ) {
			return false; // Not yet time.
		}

		$text = self::build_report( $cfg['report'], $now );

		$sent = 0;
		foreach ( $recipients as $chat_id ) {
			$res = self::send_message( $cfg['token'], $chat_id, $text );
			if ( ! is_wp_error( $res ) ) {
				$sent++;
			}
		}

		if ( $sent > 0 ) {
			$cfg['last_report'] = $today;
			self::save( $cfg );
			return true;
		}
		return false;
	}

	/**
	 * Build the report message text for the period.
	 *
	 * @param string $mode daily|weekly.
	 * @param int    $now  Local now.
	 * @return string
	 */
	private static function build_report( $mode, $now ) {
		$daily    = ( 'daily' === $mode );
		$h        = (int) wp_date( 'H', $now );
		$i        = (int) wp_date( 'i', $now );
		$s        = (int) wp_date( 's', $now );
		$midnight = $now - ( $h * 3600 + $i * 60 + $s );
		$since    = $daily ? $midnight : $midnight - 6 * DAY_IN_SECONDS;

		$total      = 0;
		$done       = 0;
		$failed     = 0;
		$drafts     = 0;
		$prompt     = 0;
		$completion = 0;
		$calls      = 0;
		$by_conn    = array();

		foreach ( AIPC_Agent::instance()->get_jobs_since( $since ) as $job ) {
			$total++;
			if ( 'done' === $job['status'] ) {
				$done++;
				if ( ! empty( $job['post_id'] ) ) {
					$drafts++;
				}
			}
			if ( in_array( $job['status'], array( 'error', 'cancelled' ), true ) ) {
				$failed++;
			}
			$prompt     += (int) $job['usage']['prompt'];
			$completion += (int) $job['usage']['completion'];

			foreach ( (array) $job['calls'] as $call ) {
				$calls++;
				$name = isset( $call['conn'] ) ? $call['conn'] : '';
				if ( ! isset( $by_conn[ $name ] ) ) {
					$by_conn[ $name ] = array( 'calls' => 0, 'tokens' => 0 );
				}
				$by_conn[ $name ]['calls']++;
				$by_conn[ $name ]['tokens'] += (int) $call['pt'] + (int) $call['ct'];
			}
		}

		$title = $daily
			? __( 'Daily report', 'wp-ai-post-creator' )
			: __( 'Weekly report', 'wp-ai-post-creator' );

		$lines   = array();
		$lines[] = '🧾 ' . $title . ' — ' . get_bloginfo( 'name' );
		$lines[] = '';

		if ( 0 === $total ) {
			$lines[] = __( 'No runs in this period.', 'wp-ai-post-creator' );
		} else {
			$lines[] = sprintf(
				/* translators: 1: total jobs, 2: successful, 3: failed. */
				__( 'Jobs: %1$d (✅ %2$d · ❌ %3$d)', 'wp-ai-post-creator' ),
				$total,
				$done,
				$failed
			);
			$lines[] = sprintf(
				/* translators: %d: draft count. */
				__( 'Drafts created: %d', 'wp-ai-post-creator' ),
				$drafts
			);
			$lines[] = sprintf(
				/* translators: 1: prompt tokens, 2: completion tokens. */
				__( 'Tokens: %1$s prompt + %2$s completion', 'wp-ai-post-creator' ),
				number_format_i18n( $prompt ),
				number_format_i18n( $completion )
			);
			$lines[] = sprintf(
				/* translators: %d: API call count. */
				__( '%d API calls', 'wp-ai-post-creator' ),
				$calls
			);

			if ( ! empty( $by_conn ) ) {
				$lines[] = '';
				$lines[] = __( 'Usage per connection', 'wp-ai-post-creator' );
				foreach ( $by_conn as $name => $usage ) {
					$lines[] = sprintf(
						/* translators: 1: connection name, 2: call count, 3: token count. */
						__( '• %1$s — %2$d calls · %3$s tokens', 'wp-ai-post-creator' ),
						$name,
						$usage['calls'],
						number_format_i18n( $usage['tokens'] )
					);
				}
			}
		}

		return implode( "\n", $lines );
	}
}
