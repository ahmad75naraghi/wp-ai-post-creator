<?php
/**
 * Admin menus, pages and form handlers.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menu, settings and renders the pages.
 */
final class AIPC_Admin {

	/**
	 * Hook everything.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );

		add_action( 'admin_post_aipc_save_connection', array( __CLASS__, 'handle_save_connection' ) );
		add_action( 'admin_post_aipc_delete_connection', array( __CLASS__, 'handle_delete_connection' ) );
		add_action( 'admin_post_aipc_save_steps', array( __CLASS__, 'handle_save_steps' ) );
		add_action( 'admin_post_aipc_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
		add_action( 'admin_post_aipc_delete_job', array( __CLASS__, 'handle_delete_job' ) );
	}

	/**
	 * Register the menus.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'AI Post Creator', 'wp-ai-post-creator' ),
			__( 'AI Post Creator', 'wp-ai-post-creator' ),
			'edit_posts',
			'aipc',
			array( __CLASS__, 'render_new' ),
			'dashicons-welcome-write-blog',
			26
		);

		add_submenu_page(
			'aipc',
			__( 'New AI Post', 'wp-ai-post-creator' ),
			__( 'New AI Post', 'wp-ai-post-creator' ),
			'edit_posts',
			'aipc',
			array( __CLASS__, 'render_new' )
		);

		add_submenu_page(
			'aipc',
			__( 'AI Connections', 'wp-ai-post-creator' ),
			__( 'Connections', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-connections',
			array( __CLASS__, 'render_connections' )
		);

		add_submenu_page(
			'aipc',
			__( 'Prompts & Steps', 'wp-ai-post-creator' ),
			__( 'Prompts & Steps', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-prompts',
			array( __CLASS__, 'render_prompts' )
		);

		add_submenu_page(
			'aipc',
			__( 'AI Logs', 'wp-ai-post-creator' ),
			__( 'Logs', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-logs',
			array( __CLASS__, 'render_logs' )
		);

		add_submenu_page(
			'aipc',
			__( 'AI Settings', 'wp-ai-post-creator' ),
			__( 'Settings', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Register the settings option group.
	 *
	 * @return void
	 */
	public static function settings() {
		register_setting(
			'aipc',
			AIPC_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'AIPC_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page renderers
	 * ------------------------------------------------------------------- */

	/**
	 * Render the agent console page.
	 *
	 * @return void
	 */
	public static function render_new() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/new-post.php';
	}

	/**
	 * Render the connections page.
	 *
	 * @return void
	 */
	public static function render_connections() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/connections.php';
	}

	/**
	 * Render the prompts & steps page.
	 *
	 * @return void
	 */
	public static function render_prompts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/prompts.php';
	}

	/**
	 * Render the logs page (list or single-job detail).
	 *
	 * @return void
	 */
	public static function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}

		$job_id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		if ( '' !== $job_id ) {
			$job = AIPC_Agent::instance()->get_job( $job_id );
			if ( ! $job ) {
				wp_die( esc_html__( 'Job not found or already cleaned up.', 'wp-ai-post-creator' ) );
			}
			require AIPC_PLUGIN_DIR . 'admin/views/log-detail.php';
			return;
		}
		require AIPC_PLUGIN_DIR . 'admin/views/logs.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/* ---------------------------------------------------------------------
	 * Form handlers (admin-post)
	 * ------------------------------------------------------------------- */

	/**
	 * Guard helper: verify capability + nonce, or die.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-ai-post-creator' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save (create or update) a connection.
	 *
	 * @return void
	 */
	public static function handle_save_connection() {
		self::guard( 'aipc_save_connection' );

		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$old  = $id ? AIPC_Connections::get( $id ) : null;
		$conn = AIPC_Connections::sanitize( wp_unslash( $_POST ), $old );

		$saved = AIPC_Connections::save( $conn );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-connections', 'aipc_msg' => 'saved', 'edit' => $saved['id'] ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Delete a connection.
	 *
	 * @return void
	 */
	public static function handle_delete_connection() {
		self::guard( 'aipc_delete_connection' );

		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( $id ) {
			AIPC_Connections::delete( $id );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-connections', 'aipc_msg' => 'deleted' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Save per-step configuration (prompts + connection overrides).
	 *
	 * @return void
	 */
	public static function handle_save_steps() {
		self::guard( 'aipc_save_steps' );

		$raw  = isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : array();
		$cfg  = array();

		foreach ( $raw as $step => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$cfg[ $step ] = array(
				'connection' => isset( $data['connection'] ) ? sanitize_key( $data['connection'] ) : '',
				'prompt'     => isset( $data['prompt'] ) ? sanitize_textarea_field( $data['prompt'] ) : '',
				'reset'      => ! empty( $data['reset'] ),
			);
		}

		AIPC_Steps::save_all( $cfg );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-prompts', 'aipc_msg' => 'saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Clear all jobs/logs.
	 *
	 * @return void
	 */
	public static function handle_clear_logs() {
		self::guard( 'aipc_clear_logs' );
		AIPC_Agent::instance()->clear_jobs();

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-logs', 'aipc_msg' => 'cleared' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Delete a single job.
	 *
	 * @return void
	 */
	public static function handle_delete_job() {
		self::guard( 'aipc_delete_job' );

		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( $id ) {
			AIPC_Agent::instance()->delete_job( $id );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-logs', 'aipc_msg' => 'deleted' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
