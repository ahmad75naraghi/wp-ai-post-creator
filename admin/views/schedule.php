<?php
/**
 * Schedule & notifications management page.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_entries   = AIPC_Scheduler::entries();
$aipc_edit_id   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
$aipc_editing   = $aipc_edit_id ? AIPC_Scheduler::get_entry( $aipc_edit_id ) : null;
$aipc_msg       = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';
$aipc_bale      = AIPC_Bale::all();
$aipc_days      = AIPC_Scheduler::day_labels();
$aipc_s         = AIPC_Settings::all();
$aipc_next_tick = wp_get_scheduled_event( AIPC_Scheduler::CRON_HOOK );

$aipc_notices = array(
	'schedule_saved'   => __( 'Schedule saved.', 'wp-ai-post-creator' ),
	'schedule_deleted' => __( 'Schedule deleted.', 'wp-ai-post-creator' ),
	'schedule_settings_saved' => __( 'Schedule settings saved.', 'wp-ai-post-creator' ),
	'run_started'      => __( 'The agent just started — the console page is now running it.', 'wp-ai-post-creator' ),
	'run_failed'       => __( 'Could not start the agent. Check that a connection is configured.', 'wp-ai-post-creator' ),
	'bale_saved'       => __( 'Bale notification settings saved.', 'wp-ai-post-creator' ),
	'topics_added'     => __( 'Topics added to the queue.', 'wp-ai-post-creator' ),
	'topic_removed'    => __( 'Topic removed.', 'wp-ai-post-creator' ),
	'queue_cleared'    => __( 'Queue cleared.', 'wp-ai-post-creator' ),
);
?>

<div class="wrap aipc-wrap">
	<h1><?php esc_html_e( 'AI Schedule & Notifications', 'wp-ai-post-creator' ); ?></h1>

	<?php if ( isset( $aipc_notices[ $aipc_msg ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $aipc_notices[ $aipc_msg ] ); ?></p></div>
	<?php endif; ?>

	<p class="aipc-lead">
		<?php esc_html_e( 'Let the agent write posts automatically at fixed times — and get a Bale message (image + summary + link) after every post is created.', 'wp-ai-post-creator' ); ?>
	</p>

	<?php if ( ! AIPC_Connections::get_default() ) : ?>
		<div class="notice notice-error"><p>
			<?php esc_html_e( 'No AI connection configured.', 'wp-ai-post-creator' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-connections' ) ); ?>"><?php esc_html_e( 'Open connections', 'wp-ai-post-creator' ); ?></a>
		</p></div>
	<?php endif; ?>

	<div class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Automatic schedules', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'sched-entries', __( 'The plugin checks every 15 minutes (via WP-Cron) and fires the earliest due entry: one full agent run per tick. Missed times are caught up on the next tick the same day, and an entry never fires twice on the same day.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'Each entry starts one full agent run (a complete draft post) at the chosen time on the chosen days. The topic can be fixed — or left empty so the agent invents one from the site prompt.', 'wp-ai-post-creator' ); ?></p>

		<?php if ( empty( $aipc_entries ) ) : ?>
			<p><em><?php esc_html_e( 'No schedules yet. Add your first one below — e.g. every day at 09:00.', 'wp-ai-post-creator' ); ?></em></p>
		<?php else : ?>
			<table class="aipc-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Days', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Topic', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Options', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Publish', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aipc_entries as $aipc_entry ) : ?>
						<?php
						$aipc_day_names = array();
						foreach ( (array) $aipc_entry['days'] as $aipc_d ) {
							$aipc_day_names[] = $aipc_days[ $aipc_d ];
						}
						$aipc_opt_summary = array(
							$aipc_entry['opts']['length'],
							$aipc_entry['opts']['language'],
						);
						if ( ! empty( $aipc_entry['opts']['image'] ) ) { $aipc_opt_summary[] = '🖼'; }
						if ( ! empty( $aipc_entry['opts']['faq'] ) ) { $aipc_opt_summary[] = 'FAQ'; }
						if ( ! empty( $aipc_entry['opts']['toc'] ) ) { $aipc_opt_summary[] = 'TOC'; }
						?>
						<tr>
							<td><strong><?php echo esc_html( $aipc_entry['time'] ); ?></strong></td>
							<td><?php echo esc_html( implode( '، ', $aipc_day_names ) ); ?></td>
							<td><?php
								if ( ! empty( $aipc_entry['use_queue'] ) ) {
									echo '<span class="aipc-badge aipc-badge-ok">' . esc_html__( 'Queue', 'wp-ai-post-creator' ) . '</span> ';
								}
								echo '' !== $aipc_entry['topic'] ? esc_html( wp_trim_words( $aipc_entry['topic'], 8, '…' ) ) : '<em>' . esc_html__( 'Automatic (site prompt)', 'wp-ai-post-creator' ) . '</em>';
							?></td>
							<td><?php echo esc_html( implode( ' · ', $aipc_opt_summary ) ); ?></td>
							<td><?php
								$aipc_p = isset( $aipc_entry['publish'] ) ? $aipc_entry['publish'] : 'draft';
								if ( 'delay' === $aipc_p ) {
									echo esc_html( sprintf( /* translators: %d: minutes. */ __( '⏱ +%d min', 'wp-ai-post-creator' ), isset( $aipc_entry['publish_delay'] ) ? (int) $aipc_entry['publish_delay'] : 60 ) );
								} elseif ( 'now' === $aipc_p ) {
									esc_html_e( '🚀 Immediately', 'wp-ai-post-creator' );
								} else {
									esc_html_e( '📝 Draft', 'wp-ai-post-creator' );
								}
							?></td>
							<td>
								<?php if ( ! empty( $aipc_entry['enabled'] ) ) : ?>
									<span class="aipc-badge aipc-badge-ok"><?php esc_html_e( 'Active', 'wp-ai-post-creator' ); ?></span>
								<?php else : ?>
									<span class="aipc-badge aipc-badge-warn"><?php esc_html_e( 'Paused', 'wp-ai-post-creator' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a class="button button-small"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_run_now&id=' . $aipc_entry['id'] ), 'aipc_run_now' ) ); ?>">
									<?php esc_html_e( 'Run now', 'wp-ai-post-creator' ); ?>
								</a>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-schedule&edit=' . $aipc_entry['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'wp-ai-post-creator' ); ?></a>
								<a class="button button-small aipc-danger"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_delete_schedule&id=' . $aipc_entry['id'] ), 'aipc_delete_schedule' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this schedule?', 'wp-ai-post-creator' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'wp-ai-post-creator' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form class="aipc-limit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-block-start:14px;">
			<?php wp_nonce_field( 'aipc_save_schedule_settings' ); ?>
			<input type="hidden" name="action" value="aipc_save_schedule_settings" />
			<label for="aipc-daily-limit" style="font-weight:600;"><?php esc_html_e( 'Max scheduled posts per day', 'wp-ai-post-creator' ); ?></label>
			<?php aipc_help( 'sched-limit', __( 'Caps how many automatic posts may be created per calendar day (site timezone). 0 = unlimited. Today’s count is shown next to the field.', 'wp-ai-post-creator' ) ); ?>
			<input type="number" id="aipc-daily-limit" class="aipc-input" name="daily_limit" min="0" max="50" step="1"
				style="width:90px;" value="<?php echo esc_attr( AIPC_Scheduler::daily_limit() ); ?>" />
			<span class="description"><?php esc_html_e( '0 = unlimited', 'wp-ai-post-creator' ); ?></span>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'wp-ai-post-creator' ); ?></button>
			<span class="description">
				<?php
				printf(
					/* translators: 1: created today, 2: limit (or infinity). */
					esc_html__( 'Scheduled posts today: %1$d of %2$s', 'wp-ai-post-creator' ),
					esc_html( number_format_i18n( AIPC_Scheduler::cron_jobs_today() ) ),
					esc_html( AIPC_Scheduler::daily_limit() ? number_format_i18n( AIPC_Scheduler::daily_limit() ) : '∞' )
				);
				?>
			</span>
		</form>

		<?php
		$aipc_e      = $aipc_editing ? $aipc_editing : array( 'time' => '09:00', 'days' => array( 0, 1, 2, 3, 4, 5, 6 ), 'enabled' => 1, 'use_queue' => 0, 'topic' => '', 'publish' => 'draft', 'publish_delay' => 60, 'opts' => array( 'tone' => $aipc_s['default_tone'], 'length' => $aipc_s['default_length'], 'language' => $aipc_s['content_language'], 'image' => (int) $aipc_s['image_enabled'], 'faq' => (int) $aipc_s['add_faq'], 'toc' => (int) $aipc_s['add_toc'] ) );
		$aipc_opts   = $aipc_e['opts'];
		$aipc_pub    = isset( $aipc_e['publish'] ) ? $aipc_e['publish'] : 'draft';
		$aipc_pub_dl = isset( $aipc_e['publish_delay'] ) ? (int) $aipc_e['publish_delay'] : 60;
		$aipc_pub_labels = array(
			'draft' => __( '📝 Draft', 'wp-ai-post-creator' ),
			'now'   => __( '🚀 Publish immediately', 'wp-ai-post-creator' ),
			'delay' => __( '⏱ Publish later', 'wp-ai-post-creator' ),
		);
		$aipc_open   = (bool) $aipc_editing;
		?>
		<details class="aipc-details" <?php echo $aipc_open ? 'open' : ''; ?>>
			<summary><?php echo $aipc_editing ? esc_html__( 'Edit schedule', 'wp-ai-post-creator' ) : esc_html__( 'Add a new schedule', 'wp-ai-post-creator' ); ?></summary>

			<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aipc_save_schedule' ); ?>
				<input type="hidden" name="action" value="aipc_save_schedule" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $aipc_editing ? $aipc_editing['id'] : '' ); ?>" />

				<div class="aipc-grid">
					<div class="aipc-field">
						<label><?php esc_html_e( 'Time', 'wp-ai-post-creator' ); ?></label>
						<input type="time" class="aipc-input" name="time" required value="<?php echo esc_attr( $aipc_e['time'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Site timezone. The run starts within ~15 minutes of this time.', 'wp-ai-post-creator' ); ?></p>
					</div>
					<div class="aipc-field">
						<label><?php esc_html_e( 'Topic (optional)', 'wp-ai-post-creator' ); ?></label>
						<input type="text" class="aipc-input" name="topic"
							placeholder="<?php esc_attr_e( 'Empty = invented from the site prompt', 'wp-ai-post-creator' ); ?>"
							value="<?php echo esc_attr( $aipc_e['topic'] ); ?>" />
					</div>
					<div class="aipc-field">
						<label><?php esc_html_e( 'Tone', 'wp-ai-post-creator' ); ?></label>
						<select class="aipc-input" name="tone">
							<?php foreach ( AIPC_Settings::tones() as $aipc_key => $aipc_label ) : ?>
								<option value="<?php echo esc_attr( $aipc_key ); ?>" <?php selected( $aipc_opts['tone'], $aipc_key ); ?>><?php echo esc_html( $aipc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aipc-field">
						<label><?php esc_html_e( 'Length', 'wp-ai-post-creator' ); ?></label>
						<select class="aipc-input" name="length">
							<?php foreach ( AIPC_Settings::lengths() as $aipc_key => $aipc_label ) : ?>
								<option value="<?php echo esc_attr( $aipc_key ); ?>" <?php selected( $aipc_opts['length'], $aipc_key ); ?>><?php echo esc_html( $aipc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="aipc-field">
						<label><?php esc_html_e( 'Content language', 'wp-ai-post-creator' ); ?></label>
						<select class="aipc-input" name="language">
							<?php foreach ( AIPC_Settings::languages() as $aipc_key => $aipc_label ) : ?>
								<option value="<?php echo esc_attr( $aipc_key ); ?>" <?php selected( $aipc_opts['language'], $aipc_key ); ?>><?php echo esc_html( $aipc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="aipc-grid">
					<div class="aipc-field">
						<label><?php esc_html_e( 'After creation', 'wp-ai-post-creator' ); ?></label>
						<select class="aipc-input" name="publish" id="aipc-sch-publish">
							<?php foreach ( $aipc_pub_labels as $aipc_pk => $aipc_pl ) : ?>
								<option value="<?php echo esc_attr( $aipc_pk ); ?>" <?php selected( $aipc_pub, $aipc_pk ); ?>><?php echo esc_html( $aipc_pl ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Posts are always created as drafts first; pick what happens next.', 'wp-ai-post-creator' ); ?></p>
					</div>
					<div class="aipc-field" id="aipc-sch-delay-field">
						<label><?php esc_html_e( 'Delay before publishing (minutes)', 'wp-ai-post-creator' ); ?></label>
						<input type="number" class="aipc-input" name="publish_delay" min="15" max="10080" step="1"
							value="<?php echo esc_attr( $aipc_pub_dl ); ?>" />
						<p class="description"><?php esc_html_e( '15–10080 minutes (up to a week). Only used with “Publish later”.', 'wp-ai-post-creator' ); ?></p>
					</div>
				</div>

				<div class="aipc-field">
					<label><?php esc_html_e( 'Days', 'wp-ai-post-creator' ); ?></label>
					<div class="aipc-days">
						<?php foreach ( $aipc_days as $aipc_num => $aipc_label ) : ?>
							<label class="aipc-day">
								<input type="checkbox" name="days[]" value="<?php echo esc_attr( $aipc_num ); ?>"
									<?php checked( in_array( $aipc_num, (array) $aipc_e['days'], true ) ); ?> />
								<?php echo esc_html( $aipc_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="aipc-checks">
					<label class="aipc-check"><input type="checkbox" name="use_queue" value="1" <?php checked( ! empty( $aipc_e['use_queue'] ) ); ?> /> <?php esc_html_e( 'Take the topic from the queue', 'wp-ai-post-creator' ); ?><?php aipc_help( 'sched-use-queue', __( 'When this entry fires, it takes the oldest pending topic from the topic queue (box above). If the queue is empty it falls back to the fixed topic field — or to the site prompt when that is empty too.', 'wp-ai-post-creator' ) ); ?></label>
					<label class="aipc-check"><input type="checkbox" name="image" value="1" <?php checked( ! empty( $aipc_opts['image'] ) ); ?> /> <?php esc_html_e( 'Featured image', 'wp-ai-post-creator' ); ?></label>
					<label class="aipc-check"><input type="checkbox" name="faq" value="1" <?php checked( ! empty( $aipc_opts['faq'] ) ); ?> /> <?php esc_html_e( 'FAQ block', 'wp-ai-post-creator' ); ?></label>
					<label class="aipc-check"><input type="checkbox" name="toc" value="1" <?php checked( ! empty( $aipc_opts['toc'] ) ); ?> /> <?php esc_html_e( 'Table of contents', 'wp-ai-post-creator' ); ?></label>
					<label class="aipc-check"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $aipc_e['enabled'] ) ); ?> /> <?php esc_html_e( 'Active', 'wp-ai-post-creator' ); ?></label>
				</div>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save schedule', 'wp-ai-post-creator' ); ?></button>
					<?php if ( $aipc_editing ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aipc-schedule' ) ); ?>"><?php esc_html_e( 'Cancel', 'wp-ai-post-creator' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</details>
	</div>

	<div class="aipc-card">
		<div class="aipc-heading">
				<h2><?php esc_html_e( 'Topic queue', 'wp-ai-post-creator' ); ?></h2>
				<?php aipc_help( 'sched-queue', __( 'A FIFO bank of topics for the schedules above: an entry with “Take the topic from the queue” consumes the oldest pending topic each time it fires, then the queue moves on — so every run writes about something new. Fill it by hand, or pull fresh ideas from your research sources with the suggest button.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'Pending topics are used one by one (oldest first). Nothing is wasted: used topics are remembered so they are never suggested again.', 'wp-ai-post-creator' ); ?></p>

		<?php $aipc_pending = AIPC_Topic_Queue::pending(); ?>
		<p>
			<strong><?php echo esc_html( sprintf( /* translators: %d: pending topic count. */ __( 'Pending topics: %d', 'wp-ai-post-creator' ), count( $aipc_pending ) ) ); ?></strong>
			<?php if ( ! empty( $aipc_pending ) ) : ?>
				<a class="button button-small aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_clear_topics' ), 'aipc_clear_topics' ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Remove every pending topic from the queue?', 'wp-ai-post-creator' ) ); ?>');"><?php esc_html_e( 'Clear queue', 'wp-ai-post-creator' ); ?></a>
			<?php endif; ?>
		</p>

		<?php if ( empty( $aipc_pending ) ) : ?>
			<p><em><?php esc_html_e( 'The queue is empty. Add topics below or suggest them from your research sources.', 'wp-ai-post-creator' ); ?></em></p>
		<?php else : ?>
			<table class="aipc-table widefat aipc-tq-table">
				<tbody>
					<?php foreach ( array_slice( $aipc_pending, 0, 15 ) as $aipc_i => $aipc_tq ) : ?>
						<tr>
							<td class="aipc-tq-pos"><?php echo esc_html( number_format_i18n( $aipc_i + 1 ) ); ?></td>
							<td><?php echo esc_html( wp_html_excerpt( $aipc_tq['text'], 120, '…' ) ); ?></td>
							<td><?php echo 'rss' === $aipc_tq['source'] ? '<span class="aipc-badge">' . esc_html__( 'From sources', 'wp-ai-post-creator' ) . '</span>' : '<span class="aipc-badge">' . esc_html__( 'Manual', 'wp-ai-post-creator' ) . '</span>'; ?></td>
							<td><a class="button button-small aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_remove_topic&id=' . $aipc_tq['id'] ), 'aipc_remove_topic' ) ); ?>"><?php esc_html_e( 'Remove', 'wp-ai-post-creator' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $aipc_pending ) > 15 ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: more topics. */ __( '…and %d more in the queue.', 'wp-ai-post-creator' ), count( $aipc_pending ) - 15 ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-block-start:14px;">
			<?php wp_nonce_field( 'aipc_add_topics' ); ?>
			<input type="hidden" name="action" value="aipc_add_topics" />
			<div class="aipc-field">
				<label for="aipc-tq-add"><?php esc_html_e( 'Add topics (one per line)', 'wp-ai-post-creator' ); ?></label>
				<textarea class="aipc-input" id="aipc-tq-add" name="topics" rows="3" placeholder="<?php esc_attr_e( 'e.g. Growing mushrooms at home&#10;A small balcony water garden', 'wp-ai-post-creator' ); ?>"></textarea>
			</div>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add to queue', 'wp-ai-post-creator' ); ?></button></p>
		</form>

		<div class="aipc-tq-suggest">
			<p>
				<button type="button" class="button" id="aipc-tq-suggest-btn"><?php esc_html_e( 'Suggest topics from my sources', 'wp-ai-post-creator' ); ?></button>
				<span class="aipc-inline-status" id="aipc-tq-status"></span>
			</p>
			<div id="aipc-tq-suggest-list" class="aipc-tq-list" hidden></div>
			<p id="aipc-tq-suggest-actions" hidden>
				<button type="button" class="button button-primary" id="aipc-tq-add-selected"><?php esc_html_e( 'Add selected to queue', 'wp-ai-post-creator' ); ?></button>
			</p>
		</div>
	</div>

	<div class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Bale notifications', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'sched-bale', __( 'Bale is an Iranian messenger. The bot token comes from @Bot_Father; each chat ID receives the post’s featured image, summary and link when a run finishes (and a periodic report, if enabled). Delivery results are recorded in the job log.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'After every generated post the agent can message a Bale chat with the featured image, the summary and the link to the article. Create a bot with @Bot_Father in Bale, paste its token here, and enter the chat ID of the person (or channel) that should receive the messages.', 'wp-ai-post-creator' ); ?></p>

		<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'aipc_save_bale' ); ?>
			<input type="hidden" name="action" value="aipc_save_bale" />

			<div class="aipc-grid">
				<div class="aipc-field">
					<label><?php esc_html_e( 'Bot token', 'wp-ai-post-creator' ); ?></label>
					<input type="password" class="aipc-input code" name="token" autocomplete="new-password"
						placeholder="<?php echo '' !== $aipc_bale['token'] ? esc_attr__( '••••• (saved — leave empty to keep)', 'wp-ai-post-creator' ) : '123456:ABC-DEF…'; ?>"
						value="" />
				</div>
				<div class="aipc-field">
					<label><?php esc_html_e( 'Chat IDs (one per line)', 'wp-ai-post-creator' ); ?></label>
					<textarea class="aipc-input code" name="chat_ids" id="aipc-bale-chats" rows="3"
						placeholder="123456789&#10;@mychannel"><?php echo esc_textarea( implode( "\n", AIPC_Bale::recipients( $aipc_bale ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One chat ID per line — a person’s numeric ID or a @channel username. Send any message to your bot, then use the button below to detect it automatically.', 'wp-ai-post-creator' ); ?></p>
				</div>
				<div class="aipc-field">
					<label><?php esc_html_e( 'Periodic report', 'wp-ai-post-creator' ); ?></label>
					<select class="aipc-input" name="report">
						<option value="" <?php selected( $aipc_bale['report'], '' ); ?>><?php esc_html_e( 'Off', 'wp-ai-post-creator' ); ?></option>
						<option value="daily" <?php selected( $aipc_bale['report'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-ai-post-creator' ); ?></option>
						<option value="weekly" <?php selected( $aipc_bale['report'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wp-ai-post-creator' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Reports are sent once per day/week at this time, after it has passed.', 'wp-ai-post-creator' ); ?></p>
				</div>
				<div class="aipc-field">
					<label><?php esc_html_e( 'Report time', 'wp-ai-post-creator' ); ?></label>
					<input type="time" class="aipc-input" name="report_time" value="<?php echo esc_attr( $aipc_bale['report_time'] ); ?>" />
				</div>
				<div class="aipc-field">
					<label><?php esc_html_e( 'Report day (weekly reports)', 'wp-ai-post-creator' ); ?></label>
					<select class="aipc-input" name="report_day">
						<?php foreach ( AIPC_Scheduler::day_labels() as $aipc_num => $aipc_label ) : ?>
							<option value="<?php echo esc_attr( $aipc_num ); ?>" <?php selected( (int) $aipc_bale['report_day'], $aipc_num ); ?>><?php echo esc_html( $aipc_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="aipc-checks">
				<label class="aipc-check"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $aipc_bale['enabled'] ) ); ?> /> <?php esc_html_e( 'Send a Bale message after every generated post', 'wp-ai-post-creator' ); ?></label>
			</div>

			<div class="aipc-card aipc-card-inner">
				<div class="aipc-heading">
					<h3><?php esc_html_e( 'Two-way commands', 'wp-ai-post-creator' ); ?></h3>
					<?php aipc_help( 'bale-two-way', __( 'The bot checks for new messages every ~5 minutes (via WP-Cron) and only obeys the chat IDs listed above. Anyone in those chats can: send «نوشتن: a topic» to start a draft, «وضعیت» for today’s runs, «آخرین» for the newest draft, «انتشار» to publish it, «صف» for the topic queue, and «راهنما» for the full list. Up to 20 posts per day can be started from Bale.', 'wp-ai-post-creator' ) ); ?>
				</div>
				<div class="aipc-checks">
					<label class="aipc-check"><input type="checkbox" name="two_way" value="1" <?php checked( ! empty( $aipc_bale['two_way'] ) ); ?> /> <?php esc_html_e( 'Accept commands from Bale chats', 'wp-ai-post-creator' ); ?></label>
				</div>
				<p class="description"><?php esc_html_e( 'Commands: نوشتن: <topic> · وضعیت · آخرین · انتشار · صف · راهنما', 'wp-ai-post-creator' ); ?></p>
			</div>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'wp-ai-post-creator' ); ?></button>
				<button type="button" class="button aipc-btn-bale-test"><?php esc_html_e( 'Send test message', 'wp-ai-post-creator' ); ?></button>
				<button type="button" class="button aipc-btn-bale-chatid"><?php esc_html_e( 'Detect chat ID', 'wp-ai-post-creator' ); ?></button>
				<span class="aipc-inline-status" id="aipc-bale-status"></span>
			</p>
		</form>
	</div>

	<div class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Cron status', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'sched-cron', __( 'WP-Cron only runs when the site receives visits. On low-traffic sites, disable it in wp-config.php and call wp-cron.php from a real system cron every minute — this panel shows whether the ticks are actually happening.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p>
			<?php if ( $aipc_next_tick ) : ?>
				<?php
				printf(
					/* translators: 1: date/time, 2: timezone name. */
					esc_html__( 'The scheduler runs every 15 minutes. Next tick: %1$s (site timezone: %2$s).', 'wp-ai-post-creator' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $aipc_next_tick->timestamp + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
					esc_html( wp_timezone_string() )
				);
				?>
			<?php else : ?>
				<span class="aipc-badge aipc-badge-err"><?php esc_html_e( 'The cron event is not scheduled.', 'wp-ai-post-creator' ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'WP-Cron is disabled on this site. Make sure a real server cron job calls wp-cron.php every few minutes, or the schedules will not fire:', 'wp-ai-post-creator' ); ?>
				<code>*/15 * * * * <?php echo esc_html( ABSPATH ); ?>wp-cron.php > /dev/null 2>&1</code>
			</p></div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Tip: for exact timing on busy or quiet sites alike, disable WP-Cron in wp-config.php and call wp-cron.php with a server cron job instead.', 'wp-ai-post-creator' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<script>
(function () {
	var sel = document.getElementById('aipc-sch-publish');
	var field = document.getElementById('aipc-sch-delay-field');
	if (!sel || !field) { return; }
	function sync() { field.style.display = (sel.value === 'delay') ? '' : 'none'; }
	sel.addEventListener('change', sync);
	sync();
})();
</script>
