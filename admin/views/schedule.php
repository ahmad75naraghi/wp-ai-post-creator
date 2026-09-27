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
	'run_started'      => __( 'The agent just started — the console page is now running it.', 'wp-ai-post-creator' ),
	'run_failed'       => __( 'Could not start the agent. Check that a connection is configured.', 'wp-ai-post-creator' ),
	'bale_saved'       => __( 'Bale notification settings saved.', 'wp-ai-post-creator' ),
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
		<h2><?php esc_html_e( 'Automatic schedules', 'wp-ai-post-creator' ); ?></h2>
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
							<td><?php echo '' !== $aipc_entry['topic'] ? esc_html( wp_trim_words( $aipc_entry['topic'], 8, '…' ) ) : '<em>' . esc_html__( 'Automatic (site prompt)', 'wp-ai-post-creator' ) . '</em>'; ?></td>
							<td><?php echo esc_html( implode( ' · ', $aipc_opt_summary ) ); ?></td>
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

		<?php
		$aipc_e      = $aipc_editing ? $aipc_editing : array( 'time' => '09:00', 'days' => array( 0, 1, 2, 3, 4, 5, 6 ), 'enabled' => 1, 'topic' => '', 'opts' => array( 'tone' => $aipc_s['default_tone'], 'length' => $aipc_s['default_length'], 'language' => $aipc_s['content_language'], 'image' => (int) $aipc_s['image_enabled'], 'faq' => (int) $aipc_s['add_faq'], 'toc' => (int) $aipc_s['add_toc'] ) );
		$aipc_opts   = $aipc_e['opts'];
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
		<h2><?php esc_html_e( 'Bale notifications', 'wp-ai-post-creator' ); ?></h2>
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
					<label><?php esc_html_e( 'Chat ID', 'wp-ai-post-creator' ); ?></label>
					<input type="text" class="aipc-input code" name="chat_id" id="aipc-bale-chat"
						placeholder="123456789"
						value="<?php echo esc_attr( $aipc_bale['chat_id'] ); ?>" />
					<p class="description"><?php esc_html_e( 'The numeric ID of the person. Send any message to your bot, then use the button below to detect it automatically.', 'wp-ai-post-creator' ); ?></p>
				</div>
			</div>

			<div class="aipc-checks">
				<label class="aipc-check"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $aipc_bale['enabled'] ) ); ?> /> <?php esc_html_e( 'Send a Bale message after every generated post', 'wp-ai-post-creator' ); ?></label>
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
		<h2><?php esc_html_e( 'Cron status', 'wp-ai-post-creator' ); ?></h2>
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
