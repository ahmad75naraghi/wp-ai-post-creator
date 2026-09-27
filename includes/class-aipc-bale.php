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

	const OPTION   = 'aipc_bale';
	const API_BASE = 'https://tapi.bale.ai/bot';

	/**
	 * Hook the post-created notification.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'aipc_post_created', array( __CLASS__, 'notify' ), 10, 2 );
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
			'enabled'     => 0,
			'token'       => '',
			'chat_ids'    => array(),
			'chat_id'     => '', // Legacy single recipient (1.3.0).
			'report'      => '', // '' | daily | weekly.
			'report_time' => '21:00',
			'report_day'  => 6, // Weekday for weekly reports (Saturday).
			'last_report' => '', // Y-m-d when the last report was sent.
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
		$cfg = null === $cfg ? self::all() : $cfg;

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

		return array(
			'enabled'     => empty( $in['enabled'] ) ? 0 : 1,
			'token'       => sanitize_text_field( $token ),
			'chat_ids'    => $chat_ids,
			'chat_id'     => isset( $old['chat_id'] ) ? $old['chat_id'] : '',
			'report'      => $report,
			'report_time' => $report_time,
			'report_day'  => $report_day,
			'last_report' => isset( $old['last_report'] ) ? $old['last_report'] : '',
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
			self::API_BASE . $token . '/' . $method,
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
	 * @param string $token   Bot token.
	 * @param string $chat_id Chat id.
	 * @param string $text    Message text.
	 * @return array|WP_Error
	 */
	public static function send_message( $token, $chat_id, $text ) {
		if ( '' === (string) $chat_id ) {
			return new WP_Error( 'aipc_bale', __( 'Bale chat ID is not configured.', 'wp-ai-post-creator' ) );
		}
		return self::api( $token, 'sendMessage', array(
			'chat_id' => $chat_id,
			'text'    => $text,
		) );
	}

	/**
	 * Send a photo with a caption.
	 *
	 * @param string $token   Bot token.
	 * @param string $chat_id Chat id.
	 * @param string $photo   Photo URL.
	 * @param string $caption Caption.
	 * @return array|WP_Error
	 */
	public static function send_photo( $token, $chat_id, $photo, $caption = '' ) {
		$body = array(
			'chat_id' => $chat_id,
			'photo'   => $photo,
		);
		if ( '' !== $caption ) {
			$body['caption'] = $caption;
		}
		return self::api( $token, 'sendPhoto', $body );
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

		$title = get_the_title( $post );

		$summary = (string) get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' === $summary ) {
			$summary = (string) get_post_meta( $post_id, '_aipc_meta_description', true );
		}
		if ( '' === $summary ) {
			$summary = (string) $post->post_excerpt;
		}
		$summary = wp_strip_all_tags( $summary );
		if ( mb_strlen( $summary ) > 400 ) {
			$summary = mb_substr( $summary, 0, 400 ) . '…';
		}

		$words = AIPC_Agent::count_words( $post->post_content );

		$text = '✍️ ' . __( 'New AI post is ready', 'wp-ai-post-creator' ) . "\n\n"
			. $title . "\n\n"
			. $summary . "\n\n"
			. '🔗 ' . get_permalink( $post_id ) . "\n\n"
			. '📊 ' . sprintf(
				/* translators: %d: word count. */
				__( '%d words · saved as a draft', 'wp-ai-post-creator' ),
				$words
			);

		$photo = '';
		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$photo = (string) wp_get_attachment_url( $thumb );
		}

		$sent        = 0;
		$first_error = null;

		foreach ( $recipients as $chat_id ) {
			$done = false;
			if ( '' !== $photo ) {
				$res = self::send_photo( $cfg['token'], $chat_id, $photo, $text );
				if ( ! is_wp_error( $res ) ) {
					$done = true;
				} elseif ( null === $first_error ) {
					$first_error = $res;
				}
			}
			if ( ! $done ) {
				$res = self::send_message( $cfg['token'], $chat_id, $text );
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

		foreach ( AIPC_Agent::instance()->get_all_jobs() as $job ) {
			if ( (int) $job['created'] < $since ) {
				continue;
			}
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
