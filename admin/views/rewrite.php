<?php
/**
 * Rewrite console page: refresh an existing post with the agent.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_conn     = AIPC_Connections::get_default();
$aipc_conn_url = admin_url( 'admin.php?page=aipc-connections' );
$aipc_posts    = get_posts( array(
	'post_type'        => 'post',
	'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
	'numberposts'      => 100,
	'orderby'          => 'modified',
	'order'            => 'DESC',
	'suppress_filters' => true,
) );

// Preselect a post handed over by other screens (?post=ID, e.g. the review inbox).
$aipc_preselect = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">♻️</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Rewrite post', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Agent mode: give an existing post a full copywriting + SEO refresh — fresher copy, better structure, full originality.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<?php if ( $aipc_conn ) : ?>
					<span class="aipc-chip">🔌 <?php echo esc_html( sprintf( /* translators: %s: connection name */ __( 'Default: %s', 'wp-ai-post-creator' ), $aipc_conn['name'] ) ); ?></span>
				<?php else : ?>
					<span class="aipc-chip">⚠ <?php esc_html_e( 'No connection configured', 'wp-ai-post-creator' ); ?></span>
				<?php endif; ?>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc' ) ); ?>"><?php esc_html_e( 'New AI Post', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( $aipc_conn_url ); ?>"><?php esc_html_e( 'Connections', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-logs' ) ); ?>"><?php esc_html_e( 'Logs', 'wp-ai-post-creator' ); ?></a>
			</p>
		</div>
	</div>

	<?php if ( ! $aipc_conn ) : ?>
		<div class="aipc-card aipc-alert">
			<p>
				<strong>🔑 <?php esc_html_e( 'No AI connection configured.', 'wp-ai-post-creator' ); ?></strong>
				<a class="button button-small" href="<?php echo esc_url( $aipc_conn_url ); ?>"><?php esc_html_e( 'Open connections', 'wp-ai-post-creator' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $aipc_posts ) ) : ?>
		<div class="aipc-card aipc-alert">
			<p><strong>📝 <?php esc_html_e( 'No posts found.', 'wp-ai-post-creator' ); ?></strong>
			<?php esc_html_e( 'Create your first post with the agent, then come back to refresh it anytime.', 'wp-ai-post-creator' ); ?></p>
		</div>
	<?php else : ?>

	<section class="aipc-card" id="aipc-form-card">
		<form id="aipc-form" autocomplete="off">
			<label class="aipc-label" for="aipc-rewrite-post"><?php esc_html_e( 'Post to rewrite', 'wp-ai-post-creator' ); ?></label>
			<select id="aipc-rewrite-post" class="aipc-select" required>
				<option value=""><?php esc_html_e( '— pick a post —', 'wp-ai-post-creator' ); ?></option>
				<?php foreach ( $aipc_posts as $aipc_post ) : ?>
					<option value="<?php echo esc_attr( $aipc_post->ID ); ?>"<?php selected( $aipc_preselect, (int) $aipc_post->ID ); ?>>
						<?php
						echo esc_html(
							wp_html_excerpt( $aipc_post->post_title, 80, '…' )
							. ' · ' . date_i18n( 'Y/m/d', strtotime( $aipc_post->post_date ) )
							. ' · ' . $aipc_post->post_status
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="aipc-hint">
				<?php esc_html_e( 'The agent analyzes the post, rewrites every paragraph (keeping the facts and the links), refreshes the SEO metadata and can generate a new featured image.', 'wp-ai-post-creator' ); ?>
				<?php esc_html_e( 'The post is updated in place — its status, author, address and category stay untouched.', 'wp-ai-post-creator' ); ?>
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

				<div class="aipc-toggles">
					<label class="aipc-toggle">
						<input type="checkbox" id="aipc-opt-image" />
						<span><?php esc_html_e( 'New featured image', 'wp-ai-post-creator' ); ?></span>
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
				<button type="button" id="aipc-start" class="button button-primary button-hero">♻️ <?php esc_html_e( 'Rewrite post', 'wp-ai-post-creator' ); ?></button>
				<span class="aipc-hint"><?php esc_html_e( 'The agent rewrites the post live — you can cancel anytime.', 'wp-ai-post-creator' ); ?></span>
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
		<h2><?php esc_html_e( 'Your post was rewritten!', 'wp-ai-post-creator' ); ?></h2>
		<h3 id="aipc-result-title"></h3>
		<p class="aipc-stats" id="aipc-result-stats"></p>
		<p class="aipc-result-actions">
			<a id="aipc-result-edit" class="button button-primary" target="_blank" rel="noopener">✎ <?php esc_html_e( 'Edit post', 'wp-ai-post-creator' ); ?></a>
			<a id="aipc-result-view" class="button" target="_blank" rel="noopener">👁 <?php esc_html_e( 'View post', 'wp-ai-post-creator' ); ?></a>
			<button type="button" id="aipc-new" class="button">＋ <?php esc_html_e( 'Rewrite another', 'wp-ai-post-creator' ); ?></button>
		</p>
	</section>

	<?php endif; ?>

</div>
