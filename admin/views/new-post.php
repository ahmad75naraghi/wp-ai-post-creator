<?php
/**
 * Agent console page.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_settings = AIPC_Settings::all();
$aipc_has_site_prompt = (bool) trim( (string) $aipc_settings['site_prompt'] );
$aipc_conn = AIPC_Connections::get_default();
$aipc_links = admin_url( 'admin.php?page=aipc-settings' );
$aipc_conn_links = admin_url( 'admin.php?page=aipc-connections' );
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">🤖</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'AI Post Creator', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Agent mode: from a bare topic to a complete, SEO-ready post — step by step.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<?php if ( $aipc_conn ) : ?>
					<span class="aipc-chip">🔌 <?php echo esc_html( sprintf( /* translators: %s: connection name */ __( 'Default: %s', 'wp-ai-post-creator' ), $aipc_conn['name'] ) ); ?></span>
					<span class="aipc-chip">⚙ <?php echo esc_html( sprintf( /* translators: %s: model name */ __( 'Model: %s', 'wp-ai-post-creator' ), $aipc_conn['chat_model'] ) ); ?></span>
				<?php else : ?>
					<span class="aipc-chip">⚠ <?php esc_html_e( 'No connection configured', 'wp-ai-post-creator' ); ?></span>
				<?php endif; ?>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-rewrite' ) ); ?>">♻️ <?php esc_html_e( 'Rewrite post', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( $aipc_conn_links ); ?>"><?php esc_html_e( 'Connections', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-prompts' ) ); ?>"><?php esc_html_e( 'Prompts & Steps', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-logs' ) ); ?>"><?php esc_html_e( 'Logs', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( $aipc_links ); ?>"><?php esc_html_e( 'Settings', 'wp-ai-post-creator' ); ?></a>
			</p>
		</div>
	</div>

	<?php if ( ! $aipc_conn ) : ?>
		<div class="aipc-card aipc-alert">
			<p>
				<strong>🔑 <?php esc_html_e( 'No AI connection configured.', 'wp-ai-post-creator' ); ?></strong>
				<?php esc_html_e( 'Add an OpenAI-compatible connection (URL + API key) to start generating posts.', 'wp-ai-post-creator' ); ?>
				<a class="button button-small" href="<?php echo esc_url( $aipc_conn_links ); ?>">
					<?php esc_html_e( 'Open connections', 'wp-ai-post-creator' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $aipc_has_site_prompt ) : ?>
		<div class="aipc-card aipc-alert">
			<p>
				<strong>🎯 <?php esc_html_e( 'No site prompt configured.', 'wp-ai-post-creator' ); ?></strong>
				<?php esc_html_e( 'Describe your site — what it is about, its goal and audience — so the agent can invent fitting topics.', 'wp-ai-post-creator' ); ?>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-settings' ) ); ?>">
					<?php esc_html_e( 'Open settings', 'wp-ai-post-creator' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<section class="aipc-card" id="aipc-form-card">
		<form id="aipc-form" autocomplete="off">
			<label class="aipc-label" for="aipc-topic"><?php esc_html_e( 'Topic (optional)', 'wp-ai-post-creator' ); ?></label>
			<textarea id="aipc-topic" rows="2" maxlength="400"
				placeholder="<?php esc_attr_e( 'Leave empty and the agent will invent a topic from the site prompt — or write your own idea here…', 'wp-ai-post-creator' ); ?>"></textarea>
			<p class="aipc-hint">
				<?php esc_html_e( 'The agent always picks one of your existing post categories and builds the topic from the site prompt.', 'wp-ai-post-creator' ); ?>
				<?php esc_html_e( 'The post is always saved as a draft for your review.', 'wp-ai-post-creator' ); ?>
				<?php esc_html_e( 'Each step uses the connection and prompt assigned under Prompts & Steps.', 'wp-ai-post-creator' ); ?>
			</p>

			<details class="aipc-options" id="aipc-options">
				<summary><?php esc_html_e( 'Options', 'wp-ai-post-creator' ); ?> <span class="aipc-summary-hint"><?php esc_html_e( 'tone, length, language, SEO…', 'wp-ai-post-creator' ); ?></span></summary>

				<div class="aipc-grid">
					<div class="aipc-field">
						<label for="aipc-tone"><?php esc_html_e( 'Tone', 'wp-ai-post-creator' ); ?></label>
						<select id="aipc-tone" class="aipc-select"></select>
					</div>
					<div class="aipc-field">
						<label for="aipc-length"><?php esc_html_e( 'Length', 'wp-ai-post-creator' ); ?></label>
						<select id="aipc-length" class="aipc-select"></select>
					</div>
					<div class="aipc-field">
						<label for="aipc-language"><?php esc_html_e( 'Content language', 'wp-ai-post-creator' ); ?></label>
						<select id="aipc-language" class="aipc-select"></select>
					</div>
					<div class="aipc-field aipc-hidden" id="aipc-language-custom-field">
						<label for="aipc-language-custom"><?php esc_html_e( 'Language (write its English name)', 'wp-ai-post-creator' ); ?></label>
						<input type="text" id="aipc-language-custom" class="aipc-input" placeholder="e.g. Italian" />
					</div>
				</div>

				<?php if ( current_user_can( 'publish_posts' ) ) : ?>
					<div class="aipc-grid" style="margin-block-start:12px;">
						<div class="aipc-field">
							<label for="aipc-publish-mode"><?php esc_html_e( 'After creation', 'wp-ai-post-creator' ); ?></label>
							<select id="aipc-publish-mode" class="aipc-select">
								<option value="draft"><?php esc_html_e( '📝 Keep as draft', 'wp-ai-post-creator' ); ?></option>
								<option value="now"><?php esc_html_e( '🚀 Publish immediately', 'wp-ai-post-creator' ); ?></option>
								<option value="delay"><?php esc_html_e( '⏱ Publish later', 'wp-ai-post-creator' ); ?></option>
							</select>
						</div>
						<div class="aipc-field aipc-hidden" id="aipc-publish-delay-field">
							<label for="aipc-publish-delay"><?php esc_html_e( 'Delay (minutes)', 'wp-ai-post-creator' ); ?></label>
							<input type="number" id="aipc-publish-delay" class="aipc-input" min="15" max="10080" step="1" value="60" />
						</div>
					</div>
				<?php endif; ?>

				<div class="aipc-toggles">
					<label class="aipc-toggle">
						<input type="checkbox" id="aipc-opt-image" />
						<span><?php esc_html_e( 'Featured image', 'wp-ai-post-creator' ); ?></span>
						<em><?php esc_html_e( 'generate & attach a hero image', 'wp-ai-post-creator' ); ?></em>
					</label>
					<label class="aipc-toggle">
						<input type="checkbox" id="aipc-opt-faq" />
						<span><?php esc_html_e( 'FAQ block', 'wp-ai-post-creator' ); ?></span>
						<em><?php esc_html_e( 'answers + Google rich-results schema', 'wp-ai-post-creator' ); ?></em>
					</label>
					<label class="aipc-toggle">
						<input type="checkbox" id="aipc-opt-toc" />
						<span><?php esc_html_e( 'Table of contents', 'wp-ai-post-creator' ); ?></span>
						<em><?php esc_html_e( 'linked jump menu', 'wp-ai-post-creator' ); ?></em>
					</label>
				</div>
			</details>

			<div class="aipc-actions">
				<button type="button" id="aipc-start" class="button button-primary button-hero">✦ <?php esc_html_e( 'Generate post', 'wp-ai-post-creator' ); ?></button>
				<span class="aipc-hint"><?php esc_html_e( 'The agent plans, writes and assembles the post live — you can cancel anytime.', 'wp-ai-post-creator' ); ?></span>
			</div>
		</form>
	</section>

	<section class="aipc-card aipc-hidden" id="aipc-console">
		<div class="aipc-console-head">
			<div class="aipc-console-title">
				<span class="aipc-pulse" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Agent console', 'wp-ai-post-creator' ); ?></strong>
			</div>
			<div class="aipc-progress">
				<div class="aipc-bar-track"><div class="aipc-bar" id="aipc-bar"></div></div>
				<span class="aipc-pct" id="aipc-pct">…</span>
			</div>
		</div>

		<ol class="aipc-steps" id="aipc-steps"></ol>

		<div class="aipc-terminal" id="aipc-terminal" dir="ltr" role="log" aria-live="polite"></div>

		<div class="aipc-console-actions">
			<button type="button" id="aipc-cancel" class="button">■ <?php esc_html_e( 'Cancel agent', 'wp-ai-post-creator' ); ?></button>
			<button type="button" id="aipc-retry" class="button aipc-hidden">↻ <?php esc_html_e( 'Retry step', 'wp-ai-post-creator' ); ?></button>
		</div>
	</section>

	<section class="aipc-card aipc-result aipc-hidden" id="aipc-result">
		<div class="aipc-result-emoji" aria-hidden="true">✅</div>
		<h2><?php esc_html_e( 'Your post is ready!', 'wp-ai-post-creator' ); ?></h2>
		<h3 id="aipc-result-title"></h3>
		<p class="aipc-stats" id="aipc-result-stats"></p>
		<p class="aipc-result-actions">
			<a id="aipc-result-edit" class="button button-primary" target="_blank" rel="noopener">✎ <?php esc_html_e( 'Edit post', 'wp-ai-post-creator' ); ?></a>
			<a id="aipc-result-view" class="button" target="_blank" rel="noopener">👁 <?php esc_html_e( 'View post', 'wp-ai-post-creator' ); ?></a>
			<button type="button" id="aipc-new" class="button">＋ <?php esc_html_e( 'Create another', 'wp-ai-post-creator' ); ?></button>
		</p>
	</section>

</div>
