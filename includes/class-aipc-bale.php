<?php
/**
 * Bale messenger notifications.
 *
 * After every successfully created post the agent fires `aipc_post_created`;
 * this class sends the featured image + summary + link to a configured Bale
 * chat through the Bale Bot API (https://tapi.bale.ai/bot<token>/…).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Bale {

	const OPTION  = 'aipc_bale';
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
	 * Settings (enabled, token, chat_id).
	 *
	 * @return array
	 */
	public static function all() {
		$cfg = get_option( self::OPTION, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return wp_parse_args( $cfg, array(
			'enabled' => 0,
			'token'   => '',
			'chat_id' => '',
		) );
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

		$chat = isset( $in['chat_id'] ) ? trim( (string) $in['chat_id'] ) : '';
		if ( '' === $chat ) {
			$chat = isset( $old['chat_id'] ) ? $old['chat_id'] : '';
		}

		return array(
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'token'   => sanitize_text_field( $token ),
			'chat_id' => sanitize_text_field( $chat ),
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
	 * Send a test message.
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
	 * Notification
	 * ------------------------------------------------------------------- */

	/**
	 * Notify the configured chat about a freshly created post: featured image
	 * (when present) + title + summary + link.
	 *
	 * @param int    $post_id Post id.
	 * @param string $job_id  Job id.
	 * @return void
	 */
	public static function notify( $post_id, $job_id ) {
		$cfg = self::all();
		if ( empty( $cfg['enabled'] ) || '' === $cfg['token'] || '' === $cfg['chat_id'] ) {
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

		$sent  = false;
		$error = null;

		if ( '' !== $photo ) {
			$res = self::send_photo( $cfg['token'], $cfg['chat_id'], $photo, $text );
			if ( ! is_wp_error( $res ) ) {
				$sent = true;
			} else {
				$error = $res;
			}
		}

		if ( ! $sent ) {
			$res = self::send_message( $cfg['token'], $cfg['chat_id'], $text );
			if ( ! is_wp_error( $res ) ) {
				$sent = true;
			} else {
				$error = $res;
			}
		}

		$agent = AIPC_Agent::instance();
		if ( $sent ) {
			$agent->append_log( $job_id, __( 'Bale notification sent (image, summary and link).', 'wp-ai-post-creator' ), 'success' );
		} else {
			$agent->append_log( $job_id, sprintf(
				/* translators: %s: error message. */
				__( 'Bale notification failed: %s', 'wp-ai-post-creator' ),
				$error ? $error->get_error_message() : __( 'unknown error', 'wp-ai-post-creator' )
			), 'warn' );
		}
	}
}
