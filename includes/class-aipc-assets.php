<?php
/**
 * Admin asset registration and localization.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads CSS/JS on the plugin pages only.
 */
final class AIPC_Assets {

	/**
	 * Hook the enqueuer.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Enqueue assets for the plugin screens.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		$screen = self::screen_for_hook( $hook );
		if ( '' === $screen ) {
			return;
		}

		wp_enqueue_style(
			'aipc-admin',
			AIPC_PLUGIN_URL . 'assets/admin.css',
			array(),
			AIPC_VERSION
		);

		if ( 'agent' === $screen || 'rewrite' === $screen ) {
			wp_enqueue_script(
				'aipc-agent',
				AIPC_PLUGIN_URL . 'assets/admin-agent.js',
				array(),
				AIPC_VERSION,
				true
			);
			$extra = ( 'rewrite' === $screen ) ? array( 'mode' => 'rewrite' ) : array();
			self::inline_data( 'aipc-agent', self::data_for_agent( $extra ) );
		} elseif ( 'connections' === $screen ) {
			wp_enqueue_script(
				'aipc-connections',
				AIPC_PLUGIN_URL . 'assets/admin-connections.js',
				array(),
				AIPC_VERSION,
				true
			);
			self::inline_data( 'aipc-connections', self::data_for_connections() );
		} elseif ( 'schedule' === $screen ) {
			wp_enqueue_script(
				'aipc-schedule',
				AIPC_PLUGIN_URL . 'assets/admin-schedule.js',
				array(),
				AIPC_VERSION,
				true
			);
			self::inline_data( 'aipc-schedule', self::data_for_schedule() );
		}
	}

	/**
	 * Resolve the plugin screen from an admin page hook — locale-proof.
	 *
	 * The part of a submenu hook before "_page_" is sanitize_title() of the
	 * *translated* top-level menu title (e.g. percent-encoded Persian on a
	 * fa_IR site), so hardcoded English hook names silently stop matching on
	 * localized admins — which used to leave every subpage without CSS/JS.
	 * Match the stable page slug after "_page_" instead.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return string Screen key, or '' when this is not a plugin page.
	 */
	public static function screen_for_hook( $hook ) {
		$screens = array(
			'aipc'             => 'agent',
			'aipc-rewrite'     => 'rewrite',
			'aipc-review'      => 'review',
			'aipc-connections' => 'connections',
			'aipc-prompts'     => 'prompts',
			'aipc-logs'        => 'logs',
			'aipc-schedule'    => 'schedule',
			'aipc-settings'    => 'settings',
			'aipc-update'      => 'update',
		);

		$hook = (string) $hook;
		if ( 'toplevel_page_aipc' === $hook ) {
			return $screens['aipc'];
		}
		$pos = strpos( $hook, '_page_' );
		if ( false === $pos ) {
			return '';
		}
		$slug = substr( $hook, $pos + strlen( '_page_' ) );
		return isset( $screens[ $slug ] ) ? $screens[ $slug ] : '';
	}

	/**
	 * Print the AIPC object before a script.
	 *
	 * Uses wp_json_encode directly so Persian strings are not HTML-entity encoded
	 * (unlike wp_localize_script).
	 *
	 * @param string $handle Script handle.
	 * @param array  $data   Data.
	 * @return void
	 */
	private static function inline_data( $handle, $data ) {
		wp_add_inline_script(
			$handle,
			'window.AIPC = ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Data for the agent console page.
	 *
	 * @return array
	 */
	private static function data_for_agent( $extra = array() ) {
		$s    = AIPC_Settings::all();
		$conn = AIPC_Connections::get_default();

		$resume_id = '';
		if ( isset( $_GET['job'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = sanitize_key( wp_unslash( $_GET['job'] ) );
			$job       = AIPC_Agent::instance()->get_job( $candidate );
			if ( $job && in_array( $job['status'], array( 'running', 'error', 'done' ), true ) ) {
				$owner = isset( $job['user'] ) ? (int) $job['user'] : 0;
				if ( get_current_user_id() === $owner
					|| current_user_can( 'edit_others_posts' )
					|| ( 'cron' === $job['source'] && current_user_can( 'manage_options' ) ) ) {
					$resume_id = $job['id'];
				}
			}
		}

		return array(
			'restUrl'       => esc_url_raw( rest_url( 'aipc/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'resumeJobId'   => $resume_id,
			'extraArgs'     => $extra,
			'model'         => $conn ? $conn['chat_model'] : '',
			'provider'      => $conn ? (string) wp_parse_url( $conn['base_url'], PHP_URL_HOST ) : '',
			'hasConnection' => (bool) $conn,
			'hasSitePrompt' => (bool) trim( (string) $s['site_prompt'] ),
			'tones'         => AIPC_Settings::tones(),
			'lengths'       => AIPC_Settings::lengths(),
			'languages'     => AIPC_Settings::languages(),
			'defaults'      => array(
				'tone'     => $s['default_tone'],
				'length'   => $s['default_length'],
				'language' => $s['content_language'],
				'image'    => (bool) $s['image_enabled'],
				'faq'      => (bool) $s['add_faq'],
				'toc'      => (bool) $s['add_toc'],
			),
			'i18n'          => array(
				'starting'      => __( 'Starting the agent…', 'wp-ai-post-creator' ),
				'resuming'      => __( 'Resuming the running agent…', 'wp-ai-post-creator' ),
				'needPost'      => __( 'Pick a post to rewrite first.', 'wp-ai-post-creator' ),
				'planning'      => __( 'Planning…', 'wp-ai-post-creator' ),
				'working'       => __( 'The agent runs on the server — you can close this tab, it keeps going.', 'wp-ai-post-creator' ),
				'networkError'  => __( 'Connection error:', 'wp-ai-post-creator' ),
				'timeout'       => __( 'The request timed out.', 'wp-ai-post-creator' ),
				'failed'        => __( 'The agent stopped with an error. You can retry the failed step.', 'wp-ai-post-creator' ),
				'cancelConfirm' => __( 'Cancel the agent? The current progress will be kept but nothing new will run.', 'wp-ai-post-creator' ),
				'cancelled'     => __( 'Agent cancelled.', 'wp-ai-post-creator' ),
				'serverStalled' => __( 'The server-side runner seems stalled — progress has not changed for a while. Check that WP-Cron works on your site (see the Schedule page).', 'wp-ai-post-creator' ),
				'noKeyTitle'    => __( 'No AI connection configured', 'wp-ai-post-creator' ),
				'noKeyBody'     => __( 'Add an OpenAI-compatible connection (URL + API key) to start generating posts.', 'wp-ai-post-creator' ),
				'goToSettings'  => __( 'Open connections', 'wp-ai-post-creator' ),
				'words'         => __( 'words', 'wp-ai-post-creator' ),
				'tokens'        => __( 'tokens', 'wp-ai-post-creator' ),
				'sec'           => __( 's', 'wp-ai-post-creator' ),
			),
		);
	}

	/**
	 * Data for the schedule page.
	 *
	 * @return array
	 */
	private static function data_for_schedule() {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'aipc/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'testing'    => __( 'Sending test message…', 'wp-ai-post-creator' ),
				'ok'         => __( 'Test message sent — check Bale!', 'wp-ai-post-creator' ),
				'failed'     => __( 'Failed:', 'wp-ai-post-creator' ),
				'fetching'   => __( 'Detecting chat ID…', 'wp-ai-post-creator' ),
				'noChat'     => __( 'No messages found. Send any message to your bot in Bale first, then try again.', 'wp-ai-post-creator' ),
				'chatFound'  => __( 'Chat ID detected: %s', 'wp-ai-post-creator' ),
				'needToken'  => __( 'Enter a bot token first.', 'wp-ai-post-creator' ),
				'needChat'   => __( 'Enter at least one chat ID.', 'wp-ai-post-creator' ),
				'suggesting' => __( 'Fetching suggestions from your sources…', 'wp-ai-post-creator' ),
				'suggestFail'=> __( 'Could not fetch suggestions:', 'wp-ai-post-creator' ),
				'noneFound'  => __( 'No new topics found in your sources — try again later or add topics manually.', 'wp-ai-post-creator' ),
				'noSources'  => __( 'No research sources are configured. Add them on the Settings page first.', 'wp-ai-post-creator' ),
				'adding'     => __( 'Adding…', 'wp-ai-post-creator' ),
				'added'      => __( 'Added! Reloading…', 'wp-ai-post-creator' ),
				'addFail'    => __( 'Could not add the topics:', 'wp-ai-post-creator' ),
			),
		);
	}

	/**
	 * Data for the connections page.
	 *
	 * @return array
	 */
	private static function data_for_connections() {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'aipc/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				'testing'       => __( 'Testing connection…', 'wp-ai-post-creator' ),
				'ok'            => __( 'Connection successful!', 'wp-ai-post-creator' ),
				'okNoModels'    => __( 'Connection successful (model list not supported by this provider).', 'wp-ai-post-creator' ),
				'fixedBase'     => __( 'Connected via %s — the base URL was missing /v1, so the field was corrected. Save the connection to keep it.', 'wp-ai-post-creator' ),
				'okModels'      => __( 'Connection successful — %d models found.', 'wp-ai-post-creator' ),
				'failed'        => __( 'Connection failed:', 'wp-ai-post-creator' ),
				'loadingModels' => __( 'Loading models…', 'wp-ai-post-creator' ),
				'modelsOk'      => __( '%d models loaded — pick one in the list.', 'wp-ai-post-creator' ),
				'modelsFail'    => __( 'Could not load the model list:', 'wp-ai-post-creator' ),
			),
		);
	}
}
