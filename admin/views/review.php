<?php
/**
 * Draft review inbox: every AI-generated draft in one place.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_msg = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';

$aipc_drafts = get_posts( array(
	'post_type'        => 'post',
	'post_status'      => array( 'draft', 'pending' ),
	'numberposts'      => 50,
	'orderby'          => 'modified',
	'order'            => 'DESC',
	'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array(
			'key'     => '_aipc_generated',
			'compare' => 'EXISTS',
		),
	),
	'suppress_filters' => true,
) );

// Map post id -> newest job row (light, no payloads) for the origin column.
$aipc_job_by_post = array();
foreach ( AIPC_Agent::instance()->get_all_jobs() as $aipc_row ) {
	if ( ! empty( $aipc_row['post_id'] ) ) {
		$aipc_job_by_post[ (int) $aipc_row['post_id'] ] = $aipc_row; // Newest first → first wins.
	}
}
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">📥</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Review drafts', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'AI-generated drafts waiting for your review. Publish, edit or rewrite them — nothing goes live until you say so.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<span class="aipc-chip">📝 <?php echo esc_html( sprintf( /* translators: %d: draft count */ _n( '%d draft waiting', '%d drafts waiting', count( $aipc_drafts ), 'wp-ai-post-creator' ), count( $aipc_drafts ) ) ); ?></span>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc' ) ); ?>">✦ <?php esc_html_e( 'New AI Post', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-rewrite' ) ); ?>">♻️ <?php esc_html_e( 'Rewrite post', 'wp-ai-post-creator' ); ?></a>
				<a class="aipc-chip aipc-chip-link" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-logs' ) ); ?>"><?php esc_html_e( 'Logs', 'wp-ai-post-creator' ); ?></a>
			</p>
		</div>
	</div>

	<?php if ( 'published' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🚀 <?php esc_html_e( 'Draft published.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'publish_failed' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>⚠ <?php esc_html_e( 'Could not publish the draft. Please try again from the editor.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
		<h2>🗒 <?php esc_html_e( 'Drafts waiting for review', 'wp-ai-post-creator' ); ?></h2>

		<?php if ( empty( $aipc_drafts ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'Nothing to review. The agent always saves new posts as drafts — they will appear here.', 'wp-ai-post-creator' ); ?> —</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc' ) ); ?>">✦ <?php esc_html_e( 'Generate post', 'wp-ai-post-creator' ); ?></a>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Words', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Origin', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aipc_drafts as $aipc_draft ) : ?>
						<?php
						$aipc_origin = isset( $aipc_job_by_post[ $aipc_draft->ID ] )
							? $aipc_job_by_post[ $aipc_draft->ID ]
							: null;
						$aipc_origin_label = '—';
						if ( $aipc_origin ) {
							if ( 'cron' === $aipc_origin['source'] ) {
								$aipc_origin_label = '⏱ ' . __( 'Scheduled', 'wp-ai-post-creator' );
							} else {
								$aipc_origin_label = '✦ ' . __( 'Manual', 'wp-ai-post-creator' );
							}
							if ( 'rewrite' === $aipc_origin['mode'] ) {
								$aipc_origin_label .= ' · ♻️ ' . __( 'Rewrite', 'wp-ai-post-creator' );
							}
						}
						?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $aipc_draft->ID, 'raw' ) ); ?>">
										<?php echo esc_html( wp_html_excerpt( $aipc_draft->post_title, 80, '…' ) ); ?>
									</a>
								</strong>
							</td>
							<td>
								<span class="aipc-badge <?php echo 'pending' === $aipc_draft->post_status ? 'aipc-badge-warn' : 'aipc-badge-run'; ?>">
									<?php echo esc_html( 'pending' === $aipc_draft->post_status ? __( 'Pending', 'wp-ai-post-creator' ) : __( 'Draft', 'wp-ai-post-creator' ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( AIPC_Agent::count_words( $aipc_draft->post_content ) ) ); ?></td>
							<td><?php echo esc_html( $aipc_origin_label ); ?></td>
							<td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $aipc_draft->post_modified ) ) ); ?></td>
							<td class="aipc-review-actions">
								<a class="button button-small" href="<?php echo esc_url( (string) get_edit_post_link( $aipc_draft->ID, 'raw' ) ); ?>">✎ <?php esc_html_e( 'Edit post', 'wp-ai-post-creator' ); ?></a>
								<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( (string) get_preview_post_link( $aipc_draft ) ); ?>">👁 <?php esc_html_e( 'Preview', 'wp-ai-post-creator' ); ?></a>
								<?php if ( current_user_can( 'publish_posts' ) ) : ?>
									<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_publish_draft&id=' . $aipc_draft->ID ), 'aipc_publish_draft' ) ); ?>"
										onclick="return confirm('<?php echo esc_js( sprintf( /* translators: %s: post title */ __( 'Publish “%s” now?', 'wp-ai-post-creator' ), $aipc_draft->post_title ) ); ?>');">🚀 <?php esc_html_e( 'Publish', 'wp-ai-post-creator' ); ?></a>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'post', $aipc_draft->ID, admin_url( 'admin.php?page=aipc-rewrite' ) ) ); ?>">♻️ <?php esc_html_e( 'Rewrite', 'wp-ai-post-creator' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

</div>
