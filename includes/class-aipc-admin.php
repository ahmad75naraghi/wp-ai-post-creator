<?php
/**
 * Admin menus and pages.
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
}
