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
		add_action( 'admin_post_aipc_toggle_connection', array( __CLASS__, 'handle_toggle_connection' ) );
		add_action( 'admin_post_aipc_regen_thumb', array( __CLASS__, 'handle_regen_thumb' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'thumb_row_action' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'thumb_notices' ) );
		add_action( 'admin_post_aipc_publish_draft', array( __CLASS__, 'handle_publish_draft' ) );
		add_action( 'admin_post_aipc_save_steps', array( __CLASS__, 'handle_save_steps' ) );
		add_action( 'admin_post_aipc_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
		add_action( 'admin_post_aipc_delete_job', array( __CLASS__, 'handle_delete_job' ) );
		add_action( 'admin_post_aipc_save_schedule', array( __CLASS__, 'handle_save_schedule' ) );
		add_action( 'admin_post_aipc_delete_schedule', array( __CLASS__, 'handle_delete_schedule' ) );
		add_action( 'admin_post_aipc_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_aipc_save_bale', array( __CLASS__, 'handle_save_bale' ) );
		add_action( 'admin_post_aipc_add_topics', array( __CLASS__, 'handle_add_topics' ) );
		add_action( 'admin_post_aipc_remove_topic', array( __CLASS__, 'handle_remove_topic' ) );
		add_action( 'admin_post_aipc_clear_topics', array( __CLASS__, 'handle_clear_topics' ) );
		add_action( 'admin_post_aipc_save_schedule_settings', array( __CLASS__, 'handle_save_schedule_settings' ) );
		add_action( 'admin_post_aipc_git_check', array( __CLASS__, 'handle_git_check' ) );
		add_action( 'admin_post_aipc_git_test', array( __CLASS__, 'handle_git_test' ) );
		add_action( 'admin_post_aipc_git_update', array( __CLASS__, 'handle_git_update' ) );
		add_action( 'admin_post_aipc_save_update_settings', array( __CLASS__, 'handle_save_update_settings' ) );
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
			__( 'Rewrite post', 'wp-ai-post-creator' ),
			__( 'Rewrite post', 'wp-ai-post-creator' ),
			'edit_posts',
			'aipc-rewrite',
			array( __CLASS__, 'render_rewrite' )
		);

		add_submenu_page(
			'aipc',
			__( 'Review drafts', 'wp-ai-post-creator' ),
			__( 'Review drafts', 'wp-ai-post-creator' ),
			'edit_posts',
			'aipc-review',
			array( __CLASS__, 'render_review' )
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
			__( 'AI Schedule', 'wp-ai-post-creator' ),
			__( 'Schedule', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-schedule',
			array( __CLASS__, 'render_schedule' )
		);

		add_submenu_page(
			'aipc',
			__( 'AI Settings', 'wp-ai-post-creator' ),
			__( 'Settings', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-settings',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'aipc',
			__( 'Update from Git', 'wp-ai-post-creator' ),
			__( 'Update from Git', 'wp-ai-post-creator' ),
			'manage_options',
			'aipc-update',
			array( __CLASS__, 'render_update' )
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
	public static function render_rewrite() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/rewrite.php';
	}

	public static function render_review() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/review.php';
	}

	/**
	 * Render the connections screen.
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
	public static function render_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/schedule.php';
	}

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
	 * "Regenerate AI image" link in the posts-list row actions (v1.10.0).
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function thumb_row_action( $actions, $post ) {
		if ( 'post' !== $post->post_type || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aipc_regen_thumb&post=' . (int) $post->ID ),
			'aipc_regen_thumb_' . (int) $post->ID
		);
		$actions['aipc_regen_thumb'] = '<a href="' . esc_url( $url ) . '">🖼 ' . esc_html__( 'Regenerate AI image', 'wp-ai-post-creator' ) . '</a>';
		return $actions;
	}

	/**
	 * Generate a fresh AI featured image for one post (v1.10.0).
	 *
	 * @return void
	 */
	public static function handle_regen_thumb() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'aipc_regen_thumb_' . $post_id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-ai-post-creator' ) );
		}

		$res  = AIPC_Agent::regenerate_thumbnail( $post_id );
		$args = array( 'aipc_msg' => is_wp_error( $res ) ? 'thumb_err' : 'thumb_ok' );
		if ( is_wp_error( $res ) ) {
			$args['aipc_err'] = rawurlencode( mb_substr( $res->get_error_message(), 0, 200 ) );
		}

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'aipc_msg', 'aipc_err' ), $back ) : admin_url( 'edit.php' );
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/**
	 * Result notice for the thumbnail regeneration (posts list).
	 *
	 * @return void
	 */
	public static function thumb_notices() {
		if ( ! isset( $_GET['aipc_msg'] ) ) {
			return;
		}
		$msg = sanitize_key( wp_unslash( $_GET['aipc_msg'] ) );
		if ( 'thumb_ok' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>🖼 ' . esc_html__( 'A new AI featured image was generated and set for the post.', 'wp-ai-post-creator' ) . '</p></div>';
		} elseif ( 'thumb_err' === $msg ) {
			$err = isset( $_GET['aipc_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['aipc_err'] ) ) ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>⚠️ ' . esc_html__( 'Could not generate a new featured image.', 'wp-ai-post-creator' ) . ( $err ? ' — ' . esc_html( $err ) : '' ) . '</p></div>';
		}
	}

	/**
	 * Enable/disable a connection without opening the edit form (v1.9.3).
	 *
	 * @return void
	 */
	public static function handle_toggle_connection() {
		self::guard( 'aipc_toggle_connection' );

		$id   = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$conn = $id ? AIPC_Connections::get( $id ) : null;
		$msg  = 'saved';
		if ( $conn ) {
			$conn['enabled'] = empty( $conn['enabled'] ) ? 1 : 0;
			AIPC_Connections::save( $conn );
			$msg = $conn['enabled'] ? 'enabled' : 'disabled';
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-connections', 'aipc_msg' => $msg ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Publish a reviewed AI draft (from the Review page).
	 *
	 * @return void
	 */
	public static function handle_publish_draft() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-ai-post-creator' ) );
		}
		check_admin_referer( 'aipc_publish_draft' );

		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$result = self::publish_draft( $id );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-review', 'aipc_msg' => $result ? 'published' : 'publish_failed' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Publish one AI-generated draft (shared by the review handler).
	 *
	 * Fires aipc_post_published so Bale subscribers get the 🎉 notice.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function publish_draft( $post_id ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'post' !== $post->post_type ) {
			return false;
		}
		if ( ! current_user_can( 'publish_posts' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return false;
		}

		$res = wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		), true );

		if ( is_wp_error( $res ) ) {
			return false;
		}

		$job_id = (string) get_post_meta( $post_id, '_aipc_job', true );
		if ( '' !== $job_id ) {
			AIPC_Agent::instance()->append_log( $job_id, __( 'Post published.', 'wp-ai-post-creator' ), 'success' );
		}

		/**
		 * Fires after a reviewed AI draft has been published from the inbox.
		 *
		 * @param int    $post_id Post id.
		 * @param string $job_id  Originating job id (may be empty).
		 */
		do_action( 'aipc_post_published', $post_id, $job_id );

		return true;
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
				'connections' => isset( $data['connections'] ) && is_array( $data['connections'] )
					? array_map( 'sanitize_key', $data['connections'] )
					: ( isset( $data['connection'] ) ? array( sanitize_key( $data['connection'] ) ) : array() ),
				'prompt'      => isset( $data['prompt'] ) ? sanitize_textarea_field( $data['prompt'] ) : '',
				'reset'       => ! empty( $data['reset'] ),
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

	/**
	 * Save (create or update) a schedule entry.
	 *
	 * @return void
	 */
	public static function handle_save_schedule() {
		self::guard( 'aipc_save_schedule' );

		$raw = isset( $_POST['days'] ) && is_array( $_POST['days'] ) ? array_map( 'absint', wp_unslash( $_POST['days'] ) ) : array();

		$entry = AIPC_Scheduler::save_entry( array(
			'id'            => isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '',
			'time'          => isset( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '',
			'days'          => $raw,
			'enabled'       => ! empty( $_POST['enabled'] ),
			'use_queue'     => ! empty( $_POST['use_queue'] ),
			'topic'         => isset( $_POST['topic'] ) ? wp_unslash( $_POST['topic'] ) : '',
			'publish'       => isset( $_POST['publish'] ) ? sanitize_key( wp_unslash( $_POST['publish'] ) ) : 'draft',
			'publish_delay' => isset( $_POST['publish_delay'] ) ? absint( wp_unslash( $_POST['publish_delay'] ) ) : 60,
			'opts'          => array(
				'tone'     => isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '',
				'length'   => isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '',
				'language' => isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '',
				'image'    => ! empty( $_POST['image'] ),
				'faq'      => ! empty( $_POST['faq'] ),
				'toc'      => ! empty( $_POST['toc'] ),
			),
		) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'schedule_saved', 'edit' => $entry['id'] ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Delete a schedule entry.
	 *
	 * @return void
	 */
	public static function handle_delete_schedule() {
		self::guard( 'aipc_delete_schedule' );

		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( $id ) {
			AIPC_Scheduler::delete_entry( $id );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'schedule_deleted' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Start a schedule entry immediately and open the console on it.
	 *
	 * @return void
	 */
	public static function handle_run_now() {
		self::guard( 'aipc_run_now' );

		$id  = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$job = $id ? AIPC_Scheduler::start_job_for_entry( $id, 'manual' ) : null;

		if ( is_wp_error( $job ) || ! $job ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'aipc-schedule', 'aipc_msg' => 'run_failed' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc', 'job' => $job['id'], 'aipc_msg' => 'run_started' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Save the schedule settings (daily post limit).
	 *
	 * @return void
	 */
	public static function handle_save_schedule_settings() {
		self::guard( 'aipc_save_schedule_settings' );

		AIPC_Scheduler::save_settings( array(
			'daily_limit' => isset( $_POST['daily_limit'] ) ? absint( wp_unslash( $_POST['daily_limit'] ) ) : 0,
		) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'schedule_settings_saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Save the Bale notification settings.
	 *
	 * @return void
	 */
	public static function render_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-post-creator' ) );
		}
		require AIPC_PLUGIN_DIR . 'admin/views/update.php';
	}

	/**
	 * Save the Git connection (repository, branch, token).
	 *
	 * @return void
	 */
	public static function handle_save_update_settings() {
		self::guard( 'aipc_save_update_settings' );

		AIPC_Updater::save_config( wp_unslash( $_POST ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-update', 'aipc_git' => 'saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Test the Git connection (repository, branch, token) against GitHub.
	 *
	 * Submitted from the settings form via the button's formaction, so it
	 * carries its own nonce field next to the save nonce.
	 *
	 * @return void
	 */
	public static function handle_git_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-ai-post-creator' ) );
		}
		if ( ! isset( $_POST['aipc_nonce_test'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aipc_nonce_test'] ) ), 'aipc_git_test' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-ai-post-creator' ) );
		}

		$result = AIPC_Updater::test_connection(
			isset( $_POST['repo'] ) ? wp_unslash( $_POST['repo'] ) : '',
			isset( $_POST['branch'] ) ? wp_unslash( $_POST['branch'] ) : '',
			isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : ''
		);

		$args = array( 'page' => 'aipc-update' );
		if ( is_wp_error( $result ) ) {
			$args['aipc_git'] = 'test_failed';
			$args['err']      = rawurlencode( sanitize_text_field( $result->get_error_message() ) );
		} else {
			$args['aipc_git'] = 'tested';
			$args['ver']      = rawurlencode( sanitize_text_field( $result['version'] ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Refresh the remote version from GitHub (stored configuration).
	 *
	 * @return void
	 */
	public static function handle_git_check() {
		self::guard( 'aipc_git_check' );

		$result = AIPC_Updater::remote_version( null, true );

		$args = array( 'page' => 'aipc-update' );
		if ( is_wp_error( $result ) ) {
			$args['aipc_git'] = 'check_failed';
			$args['err']      = rawurlencode( sanitize_text_field( $result->get_error_message() ) );
		} else {
			$args['aipc_git'] = 'checked';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Run the Git update (stored configuration).
	 *
	 * @return void
	 */
	public static function handle_git_update() {
		self::guard( 'aipc_git_update' );

		$force  = ! empty( $_POST['force'] );
		$result = AIPC_Updater::run( null, $force );

		$args = array( 'page' => 'aipc-update' );
		if ( is_wp_error( $result ) ) {
			$args['aipc_git'] = 'failed';
			$args['err']      = rawurlencode( sanitize_text_field( $result->get_error_message() ) );
		} else {
			$args['aipc_git'] = 'updated';
			$args['to']       = rawurlencode( sanitize_text_field( $result['version'] ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Add topics to the queue (textarea, one per line).
	 *
	 * @return void
	 */
	public static function handle_add_topics() {
		self::guard( 'aipc_add_topics' );

		$topics = isset( $_POST['topics'] ) ? (string) wp_unslash( $_POST['topics'] ) : '';
		AIPC_Topic_Queue::add_many( $topics, 'manual' );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'topics_added' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Remove one topic from the queue.
	 *
	 * @return void
	 */
	public static function handle_remove_topic() {
		self::guard( 'aipc_remove_topic' );

		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( $id ) {
			AIPC_Topic_Queue::remove( $id );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'topic_removed' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Remove every pending topic.
	 *
	 * @return void
	 */
	public static function handle_clear_topics() {
		self::guard( 'aipc_clear_topics' );

		AIPC_Topic_Queue::clear_pending();

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'queue_cleared' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_save_bale() {
		self::guard( 'aipc_save_bale' );

		$cfg = AIPC_Bale::sanitize( wp_unslash( $_POST ), AIPC_Bale::all() );
		AIPC_Bale::save( $cfg );
		AIPC_Bale_Commands::maybe_schedule();

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'aipc-schedule', 'aipc_msg' => 'bale_saved' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}

/**
 * Render a contextual help toggle: a small "?" icon that expands into an
 * explanation right below it (pure <details>/<summary> — no JS, keyboard-
 * and screen-reader-friendly, works in RTL).
 *
 * The text MUST arrive already translated (views pass __() output) so the
 * translation pipeline keeps extracting it.
 *
 * @param string $slug Stable id (also used by the e2e suite).
 * @param string $text Help text (a little HTML is allowed).
 * @return void
 */
function aipc_help( $slug, $text ) {
	if ( ! is_string( $text ) || '' === trim( $text ) ) {
		return;
	}
	?>
	<details class="aipc-help" data-aipc-help="<?php echo esc_attr( $slug ); ?>">
		<summary role="button" aria-label="<?php esc_attr_e( 'What is this section for?', 'wp-ai-post-creator' ); ?>"><span aria-hidden="true">?</span></summary>
		<div class="aipc-help-panel"><?php echo wp_kses_post( $text ); ?></div>
	</details>
	<?php
}
