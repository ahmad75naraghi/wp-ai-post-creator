<?php
/**
 * Plugin Name:       AI Post Creator
 * Plugin URI:        https://github.com/ahmad75naraghi/wp-ai-post-creator
 * Description:       Agent-style AI content engine. Connect any OpenAI-compatible API (OpenAI, OpenRouter, Groq, DeepSeek, Ollama, LM Studio …) and generate complete, SEO-optimized posts from scratch — outline to featured image — in the background, on a schedule, with two-way Bale commands, a topic queue and a live agent console.
 * Version:           1.19.1
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Ahmad Naraghi
 * Author URI:        https://github.com/ahmad75naraghi
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-post-creator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPC_VERSION', '1.19.1' );
define( 'AIPC_PLUGIN_FILE', __FILE__ );
define( 'AIPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-text.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-trace.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-settings.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-network.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-connections.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-steps.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-api-client.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-stock.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-job-store.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-agent.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-post-builder.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-topic-queue.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-scheduler.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-updater.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-bale.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-bale-commands.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-rest.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-admin.php';
require_once AIPC_PLUGIN_DIR . 'includes/class-aipc-assets.php';

/**
 * Boot the plugin on plugins_loaded so translations load before init.
 *
 * @return void
 */
function aipc_boot() {
	load_plugin_textdomain( 'wp-ai-post-creator', false, dirname( plugin_basename( AIPC_PLUGIN_FILE ) ) . '/languages' );

	AIPC_Connections::maybe_migrate();

	// Create/upgrade the jobs table and move legacy option data into it.
	AIPC_Job_Store::maybe_upgrade();

	AIPC_Admin::register();
	AIPC_Assets::register();
	AIPC_Post_Builder::register();
	AIPC_Scheduler::register();
	AIPC_Scheduler::maybe_schedule();
	AIPC_Bale::register();
	AIPC_Bale_Commands::register();
	AIPC_Bale_Commands::maybe_schedule();

	// REST routes must be registered on rest_api_init.
	add_action( 'rest_api_init', array( 'AIPC_REST', 'register' ) );

	add_action( 'aipc_daily_cleanup', array( 'AIPC_Agent', 'cleanup_static' ) );

	// Delayed publishing of scheduled posts.
	add_action( 'aipc_publish_post', array( 'AIPC_Scheduler', 'publish_post' ), 10, 2 );
	add_filter( 'plugin_action_links_' . plugin_basename( AIPC_PLUGIN_FILE ), 'aipc_action_links' );
}
add_action( 'plugins_loaded', 'aipc_boot' );

/**
 * Add quick links on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function aipc_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc' ) ) . '">' . esc_html__( 'New AI Post', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-rewrite' ) ) . '">' . esc_html__( 'Rewrite post', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-connections' ) ) . '">' . esc_html__( 'Connections', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-prompts' ) ) . '">' . esc_html__( 'Prompts & Steps', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-logs' ) ) . '">' . esc_html__( 'Logs', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-schedule' ) ) . '">' . esc_html__( 'Schedule', 'wp-ai-post-creator' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=aipc-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-ai-post-creator' ) . '</a>'
	);
	return $links;
}

/**
 * Activate: seed default settings and schedule daily cleanup.
 *
 * @return void
 */
function aipc_activate() {
	if ( false === get_option( 'aipc_settings', false ) ) {
		add_option( 'aipc_settings', AIPC_Settings::defaults(), '', false );
	}
	// Create/upgrade the jobs table right away (boot also does this lazily).
	AIPC_Job_Store::maybe_upgrade();
	if ( ! wp_next_scheduled( 'aipc_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aipc_daily_cleanup' );
	}
	if ( ! wp_next_scheduled( 'aipc_cron_tick' ) ) {
		AIPC_Scheduler::register();
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'aipc_quarter_hour', 'aipc_cron_tick' );
	}
}
register_activation_hook( __FILE__, 'aipc_activate' );

/**
 * Deactivate: clear scheduled events.
 *
 * @return void
 */
function aipc_deactivate() {
	wp_clear_scheduled_hook( 'aipc_daily_cleanup' );
	wp_clear_scheduled_hook( 'aipc_cron_tick' );
	wp_clear_scheduled_hook( AIPC_Scheduler::RUNNER_HOOK ); // All runner events.
	wp_clear_scheduled_hook( AIPC_Bale_Commands::POLL_HOOK );
}
register_deactivation_hook( __FILE__, 'aipc_deactivate' );
