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
				'permission_callback' => array( __CLASS__, 'can_edit' ),
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
					'faq'             => array( 'type' => 'boolean' ),
					'toc'             => array( 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
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
			$conn['base_url'] = untrailingslashit( esc_url_raw( $base ) );
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
			'faq'             => $request->get_param( 'faq' ),
			'toc'             => $request->get_param( 'toc' ),
			'mode'            => $request->get_param( 'mode' ),
			'post_id'         => $request->get_param( 'post_id' ),
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
