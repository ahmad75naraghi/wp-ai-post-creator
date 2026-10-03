<?php
/**
 * Jobs & Cron page: every unfinished job and pending cron event, with
 * one-click stop buttons so nothing piles up in the background.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_overview  = AIPC_Admin::cron_overview();
$aipc_jobs      = $aipc_overview['jobs'];
$aipc_publishes = $aipc_overview['publishes'];
$aipc_events    = $aipc_overview['events'];
$aipc_future    = $aipc_overview['future'];
$aipc_msg       = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';

$aipc_dt_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

$aipc_status_labels = array(
	'running' => array( __( 'Running', 'wp-ai-post-creator' ), 'run' ),
	'queued'  => array( __( 'Queued', 'wp-ai-post-creator' ), 'cron' ),
	'error'   => array( __( 'Error', 'wp-ai-post-creator' ), 'err' ),
);

$aipc_hook_labels = array(
	'aipc_cron_tick'    => __( 'Schedule tick — checks whether a scheduled topic is due', 'wp-ai-post-creator' ),
	'aipc_bale_poll'    => __( 'Bot poll — fetches new bot messages (fallback when the webhook is off)', 'wp-ai-post-creator' ),
	'aipc_run_job'      => __( 'Agent runner — executes the next step of a job', 'wp-ai-post-creator' ),
	'aipc_publish_post' => __( 'Delayed publish', 'wp-ai-post-creator' ),
);
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">⏳</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Jobs & Cron', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Everything still running or waiting in the background — stop any of it with one click so nothing piles up.', 'wp-ai-post-creator' ); ?></p>
		</div>
	</div>

	<?php if ( 'job_cancelled' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🛑 <?php esc_html_e( 'Job cancelled.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'jobs_cancelled' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🛑 <?php esc_html_e( 'All unfinished jobs cancelled.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'publish_unscheduled' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🗓 <?php esc_html_e( 'Delayed publish cancelled — the post stays a draft.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'future_reverted' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>↩️ <?php esc_html_e( 'Scheduled post moved back to draft.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
		<div class="aipc-step-head">
			<div class="aipc-heading">
				<h2>🔄 <?php esc_html_e( 'Unfinished jobs', 'wp-ai-post-creator' ); ?> <span class="aipc-hint">(<?php echo esc_html( number_format_i18n( count( $aipc_jobs ) ) ); ?>)</span></h2>
				<?php aipc_help( 'cron-jobs', __( 'Agent runs that are still running, queued, or stopped on an error. Cancelling marks the job as cancelled and removes its runner cron event, so it will never resume on its own.', 'wp-ai-post-creator' ) ); ?>
			</div>
			<?php if ( ! empty( $aipc_jobs ) ) : ?>
				<a class="button aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_cancel_all_jobs' ), 'aipc_cancel_all_jobs' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel every unfinished job?', 'wp-ai-post-creator' ) ); ?>');">🛑 <?php esc_html_e( 'Cancel all', 'wp-ai-post-creator' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( empty( $aipc_jobs ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'Nothing is running — all jobs are finished.', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Topic', 'wp-ai-post-creator' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Progress', 'wp-ai-post-creator' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Source', 'wp-ai-post-creator' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Last activity', 'wp-ai-post-creator' ); ?></th>
						<th style="width:170px"><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aipc_jobs as $aipc_job ) : ?>
						<?php
						$aipc_label = isset( $aipc_status_labels[ $aipc_job['status'] ] )
							? $aipc_status_labels[ $aipc_job['status'] ]
							: array( $aipc_job['status'], 'warn' );
						$aipc_total = isset( $aipc_job['steps_total'] ) ? (int) $aipc_job['steps_total'] : 0;
						$aipc_done  = isset( $aipc_job['steps_done'] ) ? (int) $aipc_job['steps_done'] : 0;
						$aipc_upd   = isset( $aipc_job['updated'] ) ? (int) $aipc_job['updated'] : 0;
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== (string) $aipc_job['topic'] ? $aipc_job['topic'] : __( '(no topic)', 'wp-ai-post-creator' ) ); ?></strong></td>
							<td><span class="aipc-badge aipc-badge-<?php echo esc_attr( $aipc_label[1] ); ?>"><?php echo esc_html( $aipc_label[0] ); ?></span></td>
							<td><?php echo esc_html( $aipc_total > 0 ? $aipc_done . ' / ' . $aipc_total : '—' ); ?></td>
							<td><?php echo 'cron' === $aipc_job['source'] ? '⏱ ' . esc_html__( 'Scheduled', 'wp-ai-post-creator' ) : '👤 ' . esc_html__( 'Manual', 'wp-ai-post-creator' ); ?></td>
							<td><?php echo $aipc_upd ? esc_html( sprintf( /* translators: %s: human time diff. */ __( '%s ago', 'wp-ai-post-creator' ), human_time_diff( $aipc_upd ) ) ) : '—'; ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=aipc&job=' . rawurlencode( $aipc_job['id'] ) ) ); ?>"><?php esc_html_e( 'View', 'wp-ai-post-creator' ); ?></a>
								&nbsp;|&nbsp;
								<a class="aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_cancel_job&id=' . rawurlencode( $aipc_job['id'] ) ), 'aipc_cancel_job' ) ); ?>"><?php esc_html_e( 'Cancel', 'wp-ai-post-creator' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aipc-card">
		<div class="aipc-heading">
			<h2>🗓 <?php esc_html_e( 'Pending publishes', 'wp-ai-post-creator' ); ?> <span class="aipc-hint">(<?php echo esc_html( number_format_i18n( count( $aipc_publishes ) + count( $aipc_future ) ) ); ?>)</span></h2>
			<?php aipc_help( 'cron-publishes', __( 'Posts waiting to go live: delayed auto-publishes created by the agent, and posts you scheduled (from the bot or the editor). Cancelling keeps the post as a draft — nothing is deleted.', 'wp-ai-post-creator' ) ); ?>
		</div>

		<?php if ( empty( $aipc_publishes ) && empty( $aipc_future ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'No pending publishes.', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'wp-ai-post-creator' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'Type', 'wp-ai-post-creator' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Publishes at', 'wp-ai-post-creator' ); ?></th>
						<th style="width:170px"><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aipc_publishes as $aipc_ev ) : ?>
						<?php
						$aipc_pid   = isset( $aipc_ev['args'][0] ) ? (int) $aipc_ev['args'][0] : 0;
						$aipc_title = $aipc_pid ? get_the_title( $aipc_pid ) : '';
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== $aipc_title ? $aipc_title : sprintf( '#%d', $aipc_pid ) ); ?></strong></td>
							<td><span class="aipc-badge aipc-badge-cron">⏱ <?php esc_html_e( 'Delayed publish', 'wp-ai-post-creator' ); ?></span></td>
							<td><?php echo esc_html( wp_date( $aipc_dt_format, $aipc_ev['ts'] ) ); ?></td>
							<td>
								<?php if ( $aipc_pid ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $aipc_pid ) ); ?>"><?php esc_html_e( 'Edit', 'wp-ai-post-creator' ); ?></a>
									&nbsp;|&nbsp;
								<?php endif; ?>
								<a class="aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_unschedule_publish&post=' . $aipc_pid ), 'aipc_unschedule_publish' ) ); ?>"><?php esc_html_e( 'Cancel publish', 'wp-ai-post-creator' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $aipc_future as $aipc_post ) : ?>
						<tr>
							<td><strong><?php echo esc_html( get_the_title( $aipc_post ) ); ?></strong></td>
							<td><span class="aipc-badge aipc-badge-run">📅 <?php esc_html_e( 'Scheduled post', 'wp-ai-post-creator' ); ?></span></td>
							<td><?php echo esc_html( get_the_date( $aipc_dt_format, $aipc_post ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $aipc_post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'wp-ai-post-creator' ); ?></a>
								&nbsp;|&nbsp;
								<a class="aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_revert_future&post=' . (int) $aipc_post->ID ), 'aipc_revert_future' ) ); ?>"><?php esc_html_e( 'Back to draft', 'wp-ai-post-creator' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aipc-card">
		<div class="aipc-heading">
			<h2>🔁 <?php esc_html_e( 'Recurring plugin events', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'cron-events', __( 'The plugin’s own heartbeat: these recurring events keep schedules, the bot, and job runners working. They are managed automatically — listed here for transparency only.', 'wp-ai-post-creator' ) ); ?>
		</div>

		<?php if ( empty( $aipc_events ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'No plugin cron events are registered right now.', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th style="width:200px"><?php esc_html_e( 'Hook', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'What it does', 'wp-ai-post-creator' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Next run', 'wp-ai-post-creator' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Repeats', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aipc_events as $aipc_ev ) : ?>
						<tr>
							<td><code><?php echo esc_html( $aipc_ev['hook'] ); ?></code></td>
							<td><?php echo esc_html( isset( $aipc_hook_labels[ $aipc_ev['hook'] ] ) ? $aipc_hook_labels[ $aipc_ev['hook'] ] : '—' ); ?></td>
							<td><?php echo esc_html( wp_date( $aipc_dt_format, $aipc_ev['ts'] ) ); ?></td>
							<td><?php echo '' !== $aipc_ev['schedule'] ? esc_html( $aipc_ev['schedule'] ) : esc_html__( 'once', 'wp-ai-post-creator' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

</div>
