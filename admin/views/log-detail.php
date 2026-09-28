<?php
/**
 * Log detail page: one job, everything about it.
 *
 * @package wp-ai-post-creator
 * @var array $job The job (set by AIPC_Admin::render_logs).
 */

defined( 'ABSPATH' ) || exit;

$aipc_total_ms = 0;
foreach ( (array) $job['timings'] as $aipc_ms ) {
	$aipc_total_ms += (int) $aipc_ms;
}
$aipc_dur = max( 0, (int) $job['updated'] - (int) $job['created'] );
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">🧾</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Job details', 'wp-ai-post-creator' ); ?> <span class="aipc-hint"><?php echo esc_html( $job['id'] ); ?></span></h1>
			<p class="aipc-sub">
				<?php
				echo esc_html( date_i18n( 'Y/m/d H:i:s', (int) $job['created'] ) ) . ' · ' .
					sprintf( /* translators: %s: duration in seconds */ __( '%s wall time', 'wp-ai-post-creator' ), $aipc_dur . 's' ) . ' · ' .
					sprintf( /* translators: %d: API call count */ __( '%d API calls', 'wp-ai-post-creator' ), (int) $job['usage']['calls'] );
				?>
			</p>
		</div>
	</div>

	<p>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'page', 'aipc-logs', admin_url( 'admin.php' ) ) ); ?>">← <?php esc_html_e( 'Back to logs', 'wp-ai-post-creator' ); ?></a>
		<a class="button aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_delete_job&id=' . $job['id'] ), 'aipc_delete_job' ) ); ?>"><?php esc_html_e( 'Delete job', 'wp-ai-post-creator' ); ?></a>
	</p>

	<section class="aipc-card">
		<h2>📋 <?php esc_html_e( 'Overview', 'wp-ai-post-creator' ); ?></h2>
		<table class="aipc-table widefat striped">
			<tbody>
				<tr><td class="aipc-kv-k"><?php esc_html_e( 'Topic', 'wp-ai-post-creator' ); ?></td><td><?php echo esc_html( $job['topic'] ); ?></td></tr>
				<tr><td class="aipc-kv-k"><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></td><td><?php echo esc_html( $job['status'] ); ?><?php if ( ! empty( $job['error']['message'] ) ) : ?> — <em><?php echo esc_html( $job['error']['message'] ); ?></em> (<?php echo esc_html( $job['error']['step'] ); ?>)<?php endif; ?></td></tr>
				<tr><td class="aipc-kv-k"><?php esc_html_e( 'Tone / length / language', 'wp-ai-post-creator' ); ?></td><td class="aipc-code"><?php echo esc_html( $job['args']['tone'] . ' · ' . $job['args']['length'] . ' · ' . $job['args']['language'] ); ?></td></tr>
				<tr><td class="aipc-kv-k"><?php esc_html_e( 'Tokens (prompt + completion)', 'wp-ai-post-creator' ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $job['usage']['prompt'] ) . ' + ' . number_format_i18n( (int) $job['usage']['completion'] ) ); ?></td></tr>
				<tr><td class="aipc-kv-k"><?php esc_html_e( 'AI time (sum of steps)', 'wp-ai-post-creator' ); ?></td><td><?php echo esc_html( round( $aipc_total_ms / 1000, 1 ) . 's' ); ?></td></tr>
				<?php if ( ! empty( $job['data']['result'] ) ) : ?>
					<tr><td class="aipc-kv-k"><?php esc_html_e( 'Result', 'wp-ai-post-creator' ); ?></td>
					<td>
						<?php echo esc_html( sprintf( /* translators: 1: post id, 2: word count */ __( 'Draft post #%1$d · %2$d words', 'wp-ai-post-creator' ), (int) $job['data']['result']['post_id'], (int) $job['data']['result']['words'] ) ); ?> ·
						<a href="<?php echo esc_url( $job['data']['result']['edit'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit post', 'wp-ai-post-creator' ); ?></a> ·
						<a href="<?php echo esc_url( $job['data']['result']['view'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View post', 'wp-ai-post-creator' ); ?></a>
					</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</section>

	<section class="aipc-card">
		<h2>🪜 <?php esc_html_e( 'Steps', 'wp-ai-post-creator' ); ?></h2>
		<table class="aipc-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Step', 'wp-ai-post-creator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></th>
					<th><?php esc_html_e( 'Time', 'wp-ai-post-creator' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $job['steps'] as $aipc_i => $aipc_step ) : ?>
				<tr>
					<td><?php echo esc_html( ( $aipc_i + 1 ) . '. ' . $aipc_step['label'] ); ?></td>
					<td>
						<span class="aipc-badge aipc-badge-<?php echo 'done' === $aipc_step['status'] ? 'ok' : ( 'failed' === $aipc_step['status'] ? 'err' : ( 'skipped' === $aipc_step['status'] ? 'warn' : 'run' ) ); ?>">
							<?php echo esc_html( $aipc_step['status'] ); ?>
						</span>
					</td>
					<td><?php echo isset( $job['timings'][ $aipc_step['id'] ] ) ? esc_html( round( $job['timings'][ $aipc_step['id'] ] / 1000, 1 ) . 's' ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="aipc-card">
		<h2>📞 <?php esc_html_e( 'API calls', 'wp-ai-post-creator' ); ?></h2>
		<?php if ( empty( $job['calls'] ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'No calls recorded.', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="aipc-table widefat striped">
				<thead>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'Step', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Connection', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Model', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Prompt → completion', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Time', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Result', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) $job['calls'] as $aipc_i => $aipc_call ) : ?>
					<tr>
						<td><?php echo esc_html( $aipc_i + 1 ); ?></td>
						<td><?php echo esc_html( $aipc_call['label'] ); ?></td>
						<td><strong><?php echo esc_html( $aipc_call['conn'] ); ?></strong></td>
						<td class="aipc-code"><?php echo esc_html( $aipc_call['model'] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $aipc_call['pt'] ) . ' → ' . number_format_i18n( $aipc_call['ct'] ) ); ?></td>
						<td><?php echo esc_html( round( $aipc_call['ms'] / 1000, 1 ) . 's' ); ?></td>
						<td>
							<?php if ( $aipc_call['ok'] ) : ?>
								<span class="aipc-badge aipc-badge-ok">OK</span>
							<?php else : ?>
								<span class="aipc-badge aipc-badge-err" title="<?php echo esc_attr( $aipc_call['err'] ); ?>">✕ <?php esc_html_e( 'Failed', 'wp-ai-post-creator' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aipc-card">
		<h2>🖥 <?php esc_html_e( 'Console log', 'wp-ai-post-creator' ); ?></h2>
		<div class="aipc-terminal aipc-terminal-static">
			<?php foreach ( (array) $job['log'] as $aipc_entry ) : ?>
				<div class="aipc-line aipc-l-<?php echo esc_attr( $aipc_entry['level'] ); ?>">
					<span class="aipc-time"><?php echo esc_html( date_i18n( 'H:i:s', (int) $aipc_entry['t'] ) ); ?></span>
					<span class="aipc-tag"><?php echo esc_html( $aipc_entry['level'] ); ?></span>
					<span class="aipc-msg"><?php echo esc_html( $aipc_entry['msg'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

</div>
