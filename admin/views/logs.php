<?php
/**
 * Logs page: summary + job list.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_jobs  = AIPC_Agent::instance()->get_all_jobs();
$aipc_stats = AIPC_Agent::stats();
$aipc_msg   = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';

$aipc_per_page = 20;
$aipc_total    = count( $aipc_jobs );
$aipc_pages    = max( 1, (int) ceil( $aipc_total / $aipc_per_page ) );
$aipc_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$aipc_paged    = min( $aipc_paged, $aipc_pages );
$aipc_slice    = array_slice( $aipc_jobs, ( $aipc_paged - 1 ) * $aipc_per_page, $aipc_per_page );

/**
 * Status badge markup.
 *
 * @param string $status Job status.
 * @return string
 */
function aipc_status_badge( $status ) {
	$labels = array(
		'running'   => array( __( 'Running', 'wp-ai-post-creator' ), 'run' ),
		'done'      => array( __( 'Done', 'wp-ai-post-creator' ), 'ok' ),
		'error'     => array( __( 'Error', 'wp-ai-post-creator' ), 'err' ),
		'cancelled' => array( __( 'Cancelled', 'wp-ai-post-creator' ), 'warn' ),
	);
	$label = isset( $labels[ $status ] ) ? $labels[ $status ] : array( $status, 'warn' );
	return '<span class="aipc-badge aipc-badge-' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span>';
}
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">📊</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'AI Logs', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Every agent run: status, timing, tokens, per-step API calls and the full console log.', 'wp-ai-post-creator' ); ?></p>
		</div>
	</div>

	<?php if ( 'cleared' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🗑 <?php esc_html_e( 'All logs cleared.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'deleted' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🗑 <?php esc_html_e( 'Job deleted.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
		<h2>📈 <?php esc_html_e( 'Summary', 'wp-ai-post-creator' ); ?></h2>
		<div class="aipc-stats-grid">
			<div class="aipc-stat"><strong><?php echo esc_html( number_format_i18n( $aipc_stats['jobs'] ) ); ?></strong><span><?php esc_html_e( 'total jobs', 'wp-ai-post-creator' ); ?></span></div>
			<div class="aipc-stat aipc-stat-ok"><strong><?php echo esc_html( number_format_i18n( $aipc_stats['done'] ) ); ?></strong><span><?php esc_html_e( 'successful', 'wp-ai-post-creator' ); ?></span></div>
			<div class="aipc-stat aipc-stat-err"><strong><?php echo esc_html( number_format_i18n( $aipc_stats['error'] ) ); ?></strong><span><?php esc_html_e( 'failed', 'wp-ai-post-creator' ); ?></span></div>
			<div class="aipc-stat"><strong><?php echo esc_html( number_format_i18n( $aipc_stats['calls'] ) ); ?></strong><span><?php esc_html_e( 'API calls', 'wp-ai-post-creator' ); ?></span></div>
			<div class="aipc-stat"><strong><?php echo esc_html( number_format_i18n( $aipc_stats['prompt_tokens'] + $aipc_stats['completion_tokens'] ) ); ?></strong><span><?php esc_html_e( 'tokens used', 'wp-ai-post-creator' ); ?></span></div>
		</div>

		<?php if ( ! empty( $aipc_stats['by_connection'] ) ) : ?>
			<h3><?php esc_html_e( 'Usage per connection', 'wp-ai-post-creator' ); ?></h3>
			<table class="aipc-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connection', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Calls', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Prompt tokens', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Completion tokens', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aipc_stats['by_connection'] as $aipc_name => $aipc_usage ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $aipc_name ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $aipc_usage['calls'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $aipc_usage['prompt_tokens'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $aipc_usage['completion_tokens'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aipc-card">
		<div class="aipc-step-head">
			<h2>🗒 <?php esc_html_e( 'Jobs', 'wp-ai-post-creator' ); ?> <span class="aipc-hint">(<?php echo esc_html( number_format_i18n( $aipc_total ) ); ?>)</span></h2>
			<?php if ( $aipc_total ) : ?>
				<a class="button aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_clear_logs' ), 'aipc_clear_logs' ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Delete ALL job logs? This cannot be undone.', 'wp-ai-post-creator' ) ); ?>');">🗑 <?php esc_html_e( 'Clear all logs', 'wp-ai-post-creator' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( ! $aipc_total ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'No jobs yet. Generate your first post!', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Topic / title', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Steps', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'API calls', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aipc_slice as $aipc_job ) : ?>
					<?php
					$aipc_done = 0;
					foreach ( $aipc_job['steps'] as $aipc_s ) {
						if ( in_array( $aipc_s['status'], array( 'done', 'skipped' ), true ) ) {
							$aipc_done++;
						}
					}
					$aipc_dur = max( 0, (int) $aipc_job['updated'] - (int) $aipc_job['created'] );
					$aipc_post_id = ! empty( $aipc_job['post_id'] ) ? (int) $aipc_job['post_id'] : 0;
					?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'Y/m/d H:i', (int) $aipc_job['created'] ) ); ?></td>
						<td>
							<strong><?php echo esc_html( mb_substr( $aipc_job['topic'], 0, 70 ) ); ?></strong>
							<?php if ( 'cron' === ( isset( $aipc_job['source'] ) ? $aipc_job['source'] : 'manual' ) ) : ?>
								<span class="aipc-badge aipc-badge-cron">⏱ <?php esc_html_e( 'Scheduled', 'wp-ai-post-creator' ); ?></span>
							<?php endif; ?>
							<?php if ( $aipc_post_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $aipc_post_id, 'raw' ) ); ?>" target="_blank" rel="noopener">#<?php echo esc_html( $aipc_post_id ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo aipc_status_badge( $aipc_job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $aipc_done . '/' . count( $aipc_job['steps'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $aipc_job['usage']['calls'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $aipc_job['usage']['prompt'] + (int) $aipc_job['usage']['completion'] ) ); ?></td>
						<td><?php echo esc_html( $aipc_dur . 's' ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'aipc-logs', 'job' => $aipc_job['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Details', 'wp-ai-post-creator' ); ?></a> ·
							<a class="aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_delete_job&id=' . $aipc_job['id'] ), 'aipc_delete_job' ) ); ?>"><?php esc_html_e( 'Delete', 'wp-ai-post-creator' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $aipc_pages > 1 ) : ?>
				<p class="aipc-pagination">
					<?php
					echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
						'base'    => add_query_arg( 'paged', '%#%' ),
						'current' => $aipc_paged,
						'total'   => $aipc_pages,
					) );
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</section>

</div>
