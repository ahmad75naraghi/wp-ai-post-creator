<?php
/**
 * REST API routes for the agent console and the settings screen.
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
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'tone'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'length'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'language'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
					'language_custom' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'category_id'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'status'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
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
			'/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'models' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
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
	 * Start a new agent job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( $request ) {
		$topic = trim( (string) $request->get_param( 'topic' ) );

		$job = AIPC_Agent::instance()->create_job( $topic, $request->get_params() );
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
	 * List provider models (settings screen).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function models() {
		$client = new AIPC_API_Client();
		$models = $client->models();
		if ( is_wp_error( $models ) ) {
			return new WP_Error( $models->get_error_code(), $models->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'models' => $models ) );
	}

	/**
	 * Test the provider connection (settings screen).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test() {
		$client = new AIPC_API_Client();
		$result = $client->test();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( $result );
	}
}
