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
		$plugin_pages = array(
			'toplevel_page_aipc'                    => 'agent',
			'ai-post-creator_page_aipc-connections' => 'connections',
			'ai-post-creator_page_aipc-prompts'     => 'prompts',
			'ai-post-creator_page_aipc-logs'        => 'logs',
			'ai-post-creator_page_aipc-settings'    => 'settings',
		);

		if ( ! isset( $plugin_pages[ $hook ] ) ) {
			return;
		}
		$screen = $plugin_pages[ $hook ];

		wp_enqueue_style(
			'aipc-admin',
			AIPC_PLUGIN_URL . 'assets/admin.css',
			array(),
			AIPC_VERSION
		);

		if ( 'agent' === $screen ) {
			wp_enqueue_script(
				'aipc-agent',
				AIPC_PLUGIN_URL . 'assets/admin-agent.js',
				array(),
				AIPC_VERSION,
				true
			);
			self::inline_data( 'aipc-agent', self::data_for_agent() );
		} elseif ( 'connections' === $screen ) {
			wp_enqueue_script(
				'aipc-connections',
				AIPC_PLUGIN_URL . 'assets/admin-connections.js',
				array(),
				AIPC_VERSION,
				true
			);
			self::inline_data( 'aipc-connections', self::data_for_connections() );
		}
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
	private static function data_for_agent() {
		$s    = AIPC_Settings::all();
		$conn = AIPC_Connections::get_default();

		return array(
			'restUrl'       => esc_url_raw( rest_url( 'aipc/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
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
				'planning'      => __( 'Planning…', 'wp-ai-post-creator' ),
				'working'       => __( 'The agent is working — keep this tab open.', 'wp-ai-post-creator' ),
				'networkError'  => __( 'Connection error:', 'wp-ai-post-creator' ),
				'timeout'       => __( 'The request timed out.', 'wp-ai-post-creator' ),
				'failed'        => __( 'The agent stopped with an error. You can retry the failed step.', 'wp-ai-post-creator' ),
				'cancelConfirm' => __( 'Cancel the agent? The current progress will be kept but nothing new will run.', 'wp-ai-post-creator' ),
				'cancelled'     => __( 'Agent cancelled.', 'wp-ai-post-creator' ),
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
				'okModels'      => __( 'Connection successful — %d models found.', 'wp-ai-post-creator' ),
				'failed'        => __( 'Connection failed:', 'wp-ai-post-creator' ),
				'loadingModels' => __( 'Loading models…', 'wp-ai-post-creator' ),
				'modelsOk'      => __( '%d models loaded — pick one in the list.', 'wp-ai-post-creator' ),
				'modelsFail'    => __( 'Could not load the model list:', 'wp-ai-post-creator' ),
			),
		);
	}
}
