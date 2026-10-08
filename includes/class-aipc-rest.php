<?php
/**
 * REST API routes for the agent console and the admin screens.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the /aipc/v1 namespace routes.
 */
final class AIPC_REST {

	const NS = 'aipc/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			self::NS,
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => array( __CLASS__, 'can_start' ),
				'args'                => array(
					'topic'           => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'tone'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'length'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'language'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'language_custom' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'image'           => array( 'type' => 'boolean' ),
					'force_image'     => array( 'type' => 'boolean' ),
					'faq'             => array( 'type' => 'boolean' ),
					'toc'             => array( 'type' => 'boolean' ),
					'force'           => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => array( __CLASS__, 'can_step' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'since'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'state' ),
				'permission_callback' => array( __CLASS__, 'can_state' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'since'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'retry' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'job_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'connection_test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => self::connection_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/connection/models',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'connection_models' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => self::connection_args(),
			)
		);

		register_rest_route(
			self::NS,
			'/bale/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bale_test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'token'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'chat_id'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'chat_ids' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bale/chat-id',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bale_chat_id' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'token' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bot-webhook/(?P<secret>[A-Za-z0-9]{16,64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'bot_webhook' ),
				// Public by design: the long random path secret is the
				// credential (validated with hash_equals below).
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/topics/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'topics_suggest' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'limit'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'exclude' => array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize_exclude_texts' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/topics/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'topics_dismiss' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'text' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/topics/add',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'topics_add' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'texts'  => array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize_topic_texts' ),
					),
					'source' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	/**
	 * Sanitize a list of topic texts (REST arg).
	 *
	 * @param array $texts Raw list.
	 * @return array
	 */
	public static function sanitize_topic_texts( $texts ) {
		$out = array();
		foreach ( (array) $texts as $text ) {
			$text = sanitize_text_field( (string) $text );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Sanitize the already-on-screen suggestion list (REST arg, 1.23.0).
	 * Larger cap than topics/add: several "show more" rounds add up.
	 *
	 * @param array $texts Raw list.
	 * @return array
	 */
	public static function sanitize_exclude_texts( $texts ) {
		$out = array();
		foreach ( (array) $texts as $text ) {
			$text = sanitize_text_field( (string) $text );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return array_slice( $out, 0, 100 );
	}

	/**
	 * Shared argument definition for connection endpoints.
	 *
	 * @return array
	 */
	private static function connection_args() {
		return array(
			'id'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'base_url'   => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'api_key'    => array( 'type' => 'string' ),
			'chat_model' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		);
	}

	/**
	 * Permission: users who can write posts.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission: administrators.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission: start a job (edit_posts + rate limit).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_start() {
		if ( ! self::can_edit() ) {
			return false;
		}
		return self::rate_ok( 'start' );
	}

	/**
	 * Permission: execute a step (edit_posts + rate limit).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_step() {
		if ( ! self::can_edit() ) {
			return false;
		}
		return self::rate_ok( 'step' );
	}

	/**
	 * Permission: watch job state (edit_posts + rate limit).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_state() {
		if ( ! self::can_edit() ) {
			return false;
		}
		return self::rate_ok( 'state' );
	}

	/**
	 * Per-user rolling rate limit for the agent endpoints. Limits are per
	 * minute; 0 disables a limit. Filter: aipc_rest_rate_limit.
	 *
	 * @param string $route Route key (start|step|state).
	 * @return true|WP_Error
	 */
	private static function rate_ok( $route ) {
		$defaults = array(
			'start' => 30,
			'step'  => 240,
			'state' => 300,
		);
		$limit = (int) apply_filters( 'aipc_rest_rate_limit', isset( $defaults[ $route ] ) ? $defaults[ $route ] : 60, $route );
		if ( $limit <= 0 ) {
			return true;
		}
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return true; // Anonymous callers are rejected by the capability check.
		}

		$bucket = (int) ( time() / MINUTE_IN_SECONDS );
		$key    = 'aipc_rl_' . md5( $user . '|' . $route . '|' . $bucket );
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'aipc_rate',
				__( 'Too many requests — please wait a moment and try again.', 'wp-ai-post-creator' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Send a Bale test message (posted values win over the stored ones).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function bale_test( $request ) {
		$cfg = AIPC_Bale::all();

		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			$token = $cfg['token'];
		}

		// Recipients: posted (list or single) win over the stored ones.
		$raw = trim( (string) $request->get_param( 'chat_ids' ) );
		if ( '' === $raw ) {
			$raw = trim( (string) $request->get_param( 'chat_id' ) );
		}
		$recipients = '' !== $raw ? AIPC_Bale::parse_recipients( $raw ) : AIPC_Bale::recipients( $cfg );

		if ( '' === $token || empty( $recipients ) ) {
			return new WP_Error( 'aipc_bale', __( 'Enter a Bale bot token and chat ID first.', 'wp-ai-post-creator' ), array( 'status' => 400 ) );
		}

		$sent   = 0;
		$error  = null;
		$errors = array();
		foreach ( $recipients as $chat_id ) {
			$res = AIPC_Bale::test( $token, $chat_id );
			if ( is_wp_error( $res ) ) {
				$errors[ $chat_id ] = $res->get_error_message();
				if ( null === $error ) {
					$error = $res->get_error_message();
				}
			} else {
				$sent++;
			}
		}

		return array(
			'ok'     => $sent === count( $recipients ),
			'sent'   => $sent,
			'total'  => count( $recipients ),
			'error'  => $error,
			'errors' => (object) $errors,
		);
	}

	/**
	 * Detect the latest chat id for a Bale bot (from getUpdates).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	/**
	 * Instant-mode webhook: Bale/Telegram POSTs every update here the
	 * moment it happens (message or button press). The long random path
	 * secret authenticates the platform; unauthorized chats are filtered
	 * inside process_update() exactly like the poll.
	 *
	 * @param WP_REST_Request $request Request (JSON body = one update).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bot_webhook( $request ) {
		$cfg    = AIPC_Bale::all();
		$secret = (string) $request['secret'];

		if ( ! AIPC_Bale::webhook_active( $cfg )
			|| ! hash_equals( (string) $cfg['webhook_secret'], $secret ) ) {
			return new WP_Error(
				'aipc_forbidden',
				__( 'Invalid webhook secret.', 'wp-ai-post-creator' ),
				array( 'status' => 403 )
			);
		}

		$update = json_decode( (string) $request->get_body(), true );
		if ( is_array( $update ) ) {
			AIPC_Bale_Commands::process_update( $update, $cfg );

			// Track the id so a later switch back to polling does not
			// replay already-handled updates.
			$uid = isset( $update['update_id'] ) ? (int) $update['update_id'] : 0;
			if ( $uid > (int) $cfg['last_update_id'] ) {
				$fresh = AIPC_Bale::all();
				$fresh['last_update_id'] = $uid;
				AIPC_Bale::save( $fresh );
			}
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function bale_chat_id( $request ) {
		$cfg = AIPC_Bale::all();

		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			$token = $cfg['token'];
		}
		if ( '' === $token ) {
			return new WP_Error( 'aipc_bale', __( 'Enter a Bale bot token first.', 'wp-ai-post-creator' ), array( 'status' => 400 ) );
		}

		$res = AIPC_Bale::latest_chat_id( $token );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'error' => $res->get_error_message() );
		}
		return array(
			'ok'      => true,
			'chat_id' => $res['chat_id'],
			'name'    => $res['name'],
		);
	}

	/**
	 * Build a connection array from the request: posted values win, but a
	 * saved connection (by id) fills the gaps — including its stored API key
	 * when the request does not send one (write-only key UX).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Connection or error.
	 */
	private static function connection_from_request( $request ) {
		$id   = (string) $request->get_param( 'id' );
		$conn = $id ? AIPC_Connections::get( $id ) : null;
		$conn = $conn ? $conn : AIPC_Connections::sanitize( array() );

		$base = trim( (string) $request->get_param( 'base_url' ) );
		$key  = (string) $request->get_param( 'api_key' );

		if ( '' !== $base ) {
			$base = untrailingslashit( esc_url_raw( $base ) );
			// SSRF guard for unsaved input (saved connections were already
			// validated or grandfathered when they were stored).
			if ( ! AIPC_Network::is_safe_url( $base ) ) {
				return new WP_Error(
					'aipc_config',
					__( 'This base URL is blocked by the outbound network guard (private or reserved address).', 'wp-ai-post-creator' ),
					array( 'status' => 400 )
				);
			}
			$conn['base_url'] = $base;
		}
		if ( '' !== $key ) {
			$conn['api_key'] = $key;
		}
		$model = trim( (string) $request->get_param( 'chat_model' ) );
		if ( '' !== $model ) {
			$conn['chat_model'] = sanitize_text_field( $model );
		}

		if ( empty( $conn['base_url'] ) || 0 !== strpos( $conn['base_url'], 'http' ) ) {
			return new WP_Error( 'aipc_config', __( 'Enter a valid API base URL first.', 'wp-ai-post-creator' ), array( 'status' => 400 ) );
		}

		return $conn;
	}

	/**
	 * Start a new agent job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( $request ) {
		$topic = trim( (string) $request->get_param( 'topic' ) );

		$args = array(
			'tone'            => $request->get_param( 'tone' ),
			'length'          => $request->get_param( 'length' ),
			'language'        => $request->get_param( 'language' ),
			'language_custom' => $request->get_param( 'language_custom' ),
			'image'           => $request->get_param( 'image' ),
			'force_image'     => $request->get_param( 'force_image' ),
			'faq'             => $request->get_param( 'faq' ),
			'toc'             => $request->get_param( 'toc' ),
			'mode'            => $request->get_param( 'mode' ),
			'post_id'         => $request->get_param( 'post_id' ),
			'force'           => $request->get_param( 'force' ) ? 1 : 0,
		);

		// Auto-publishing from the console requires the publish capability.
		if ( current_user_can( 'publish_posts' ) ) {
			$args['publish_mode']  = $request->get_param( 'publish_mode' );
			$args['publish_delay'] = $request->get_param( 'publish_delay' );
		}

		$job = AIPC_Agent::instance()->create_job( $topic, $args );
		if ( is_wp_error( $job ) ) {
			return new WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( AIPC_Agent::instance()->client_state( $job ) );
	}

	/**
	 * Execute the next job step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function step( $request ) {
		$state = AIPC_Agent::instance()->execute_step(
			(string) $request->get_param( 'job_id' ),
			(int) $request->get_param( 'since' )
		);
		if ( is_wp_error( $state ) ) {
			return new WP_Error( $state->get_error_code(), $state->get_error_message(), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $state );
	}

	/**
	 * Read a job's current state WITHOUT executing anything — the console
	 * polls this while the background runner drives the steps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function state( $request ) {
		$job = AIPC_Agent::instance()->get_job( (string) $request->get_param( 'job_id' ) );
		if ( ! $job ) {
			return new WP_Error( 'aipc_job', __( 'Job not found or expired. Please start again.', 'wp-ai-post-creator' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( AIPC_Agent::instance()->client_state( $job, (int) $request->get_param( 'since' ) ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel( $request ) {
		$job = AIPC_Agent::instance()->cancel_job( (string) $request->get_param( 'job_id' ) );
		if ( is_wp_error( $job ) ) {
			return new WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 404 ) );
		}
		return rest_ensure_response( AIPC_Agent::instance()->client_state( $job ) );
	}

	/**
	 * Retry the failed step of a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function retry( $request ) {
		$job = AIPC_Agent::instance()->retry_job( (string) $request->get_param( 'job_id' ) );
		if ( is_wp_error( $job ) ) {
			return new WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 404 ) );
		}
		return rest_ensure_response( AIPC_Agent::instance()->client_state( $job ) );
	}

	/**
	 * Test a connection (saved by id, or an unsaved form's values).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connection_test( $request ) {
		$conn = self::connection_from_request( $request );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		$client = new AIPC_API_Client( $conn );
		$result = $client->test();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Suggest topics from the configured research sources (RSS).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function topics_suggest( $request ) {
		$limit   = (int) $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 12;
		$exclude = (array) $request->get_param( 'exclude' );
		$result  = AIPC_Topic_Queue::suggest( $limit, $exclude );
		return rest_ensure_response( array(
			'suggestions' => $result['suggestions'],
			'count'       => count( $result['suggestions'] ),
			'sources'     => $result['sources'],
			'has_sources' => '' !== trim( (string) AIPC_Settings::get( 'source_sites' ) ),
		) );
	}

	/**
	 * Never show a suggestion again (1.23.0).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function topics_dismiss( $request ) {
		$ok = AIPC_Topic_Queue::dismiss( (string) $request->get_param( 'text' ) );
		return rest_ensure_response( array(
			'dismissed' => (bool) $ok,
		) );
	}

	/**
	 * Add topics to the queue (used by the suggestion picker and the form).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function topics_add( $request ) {
		$texts  = (array) $request->get_param( 'texts' );
		$source = 'rss' === (string) $request->get_param( 'source' ) ? 'rss' : 'manual';
		$added  = AIPC_Topic_Queue::add_many( $texts, $source );
		return rest_ensure_response( array(
			'added'        => $added,
			'skipped'      => count( $texts ) - $added,
			'pending'      => AIPC_Topic_Queue::count_pending(),
		) );
	}

	/**
	 * List the models of a connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connection_models( $request ) {
		$conn = self::connection_from_request( $request );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		$client = new AIPC_API_Client( $conn );
		$models = $client->models();
		if ( is_wp_error( $models ) ) {
			return new WP_Error( $models->get_error_code(), $models->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'models' => $models ) );
	}
}
