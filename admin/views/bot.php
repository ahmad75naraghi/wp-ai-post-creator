<?php
/**
 * Bale / Telegram bot page: platform + bot settings, a step-by-step setup
 * guide, the bot's capabilities and a troubleshooting reference.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_bale = AIPC_Bale::all();
$aipc_msg  = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';

$aipc_notices = array(
	'bale_saved'  => __( 'Bale notification settings saved.', 'wp-ai-post-creator' ),
	'webhook_ok'  => __( 'Settings saved — the webhook is registered: the bot now answers instantly.', 'wp-ai-post-creator' ),
	'webhook_fail' => '', // Built below from the stored error.
);
if ( 'webhook_fail' === $aipc_msg ) {
	$aipc_notices['webhook_fail'] = sprintf(
		/* translators: %s: error message. */
		__( 'Settings saved, but the webhook could not be registered: %s — the bot falls back to polling every minute.', 'wp-ai-post-creator' ),
		(string) get_transient( 'aipc_webhook_error' )
	);
	delete_transient( 'aipc_webhook_error' );
}
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">🤖</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Bale / Telegram Bot', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Connect the agent to a Bale or Telegram bot: get notified about every post, and run everything — writing, publishing, scheduling — straight from the chat.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<span class="aipc-chip"><?php echo 'telegram' === $aipc_bale['platform'] ? '✈️ Telegram' : '🟢 Bale'; ?></span>
				<?php if ( ! empty( $aipc_bale['enabled'] ) ) : ?>
					<span class="aipc-chip">📣 <?php esc_html_e( 'Notifications: on', 'wp-ai-post-creator' ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $aipc_bale['two_way'] ) ) : ?>
					<span class="aipc-chip">💬 <?php esc_html_e( 'Commands: on', 'wp-ai-post-creator' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
	</div>

	<?php if ( $aipc_msg && ! empty( $aipc_notices[ $aipc_msg ] ) ) : ?>
		<div class="notice <?php echo 'webhook_fail' === $aipc_msg ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $aipc_notices[ $aipc_msg ] ); ?></p></div>
	<?php endif; ?>

	<div class="aipc-card">
		<div class="aipc-heading">
			<h2><?php esc_html_e( 'Setup guide', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'bot-setup', __( 'Bale and Telegram bots speak the same API, so the plugin supports both — pick the platform in the settings below. Replies arrive in one of two ways: instantly via the webhook (recommended), or through the WP-Cron poll about once a minute.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'Follow these six steps once; after that everything runs from the chat.', 'wp-ai-post-creator' ); ?></p>
		<ol class="aipc-steps-list">
			<li><?php esc_html_e( 'Create the bot — in Bale message @Bot_Father (in Telegram: @BotFather), send /newbot, pick a name, and copy the token it hands back (it looks like 123456:ABC-DEF…).', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Pick the platform & paste the token — choose Bale or Telegram below, paste the token and save.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Say hello to your bot — open it in the messenger and send it any message (bots can never write first).', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Detect your chat ID — click “Detect chat ID”; your numeric ID is filled in automatically. For a channel, make the bot an admin and enter @channelusername as a recipient.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Enable & test — tick both checkboxes (message after every post + accept commands), save, then “Send test message” must deliver a ✅ to your chat.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Send «منو» to the bot — the tappable menu appears and everything (new topic, queue, drafts, publish, schedule) is one button away.', 'wp-ai-post-creator' ); ?></li>
		</ol>
	</div>

	<div class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Bot settings', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'sched-bale', __( 'Bale is an Iranian messenger. The bot token comes from @Bot_Father; each chat ID receives the post’s featured image, summary and link when a run finishes (and a periodic report, if enabled). Delivery results are recorded in the job log.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description"><?php esc_html_e( 'After every generated post the agent can message a Bale chat with the featured image, the summary and the link to the article. Create a bot with @Bot_Father in Bale, paste its token here, and enter the chat ID of the person (or channel) that should receive the messages.', 'wp-ai-post-creator' ); ?></p>

		<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'aipc_save_bale' ); ?>
			<input type="hidden" name="action" value="aipc_save_bale" />

			<div class="aipc-grid">
				<div class="aipc-field">
					<label><?php esc_html_e( 'Platform', 'wp-ai-post-creator' ); ?></label>
					<select class="aipc-input" name="platform">
						<option value="bale" <?php selected( $aipc_bale['platform'], 'bale' ); ?>>Bale — tapi.bale.ai</option>
						<option value="telegram" <?php selected( $aipc_bale['platform'], 'telegram' ); ?>>Telegram — api.telegram.org</option>
					</select>
					<p class="description"><?php esc_html_e( 'Both platforms speak the same bot API. Your server must be able to reach the one you pick: from inside Iran Telegram’s API is usually blocked (Bale works), while some foreign hosts cannot reach Bale.', 'wp-ai-post-creator' ); ?></p>
				</div>
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
					<label><?php esc_html_e( 'Default notification image (URL)', 'wp-ai-post-creator' ); ?></label>
					<input type="url" class="aipc-input code" name="default_image" dir="ltr"
						placeholder="https://example.com/wp-content/uploads/cover.jpg"
						value="<?php echo esc_attr( isset( $aipc_bale['default_image'] ) ? $aipc_bale['default_image'] : '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Sent above the message when a post has no featured image. Paste an image address from your media library; leave empty to send text-only in that case.', 'wp-ai-post-creator' ); ?></p>
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
					<?php aipc_help( 'bale-two-way', __( 'The bot only obeys the chat IDs listed above. Anyone in those chats can: send «نوشتن: a topic» to start a draft, «وضعیت» for today’s runs, «آخرین» for the newest draft, «انتشار» to publish it, «صف» for the topic queue, and «راهنما» for the full list. Without the webhook, messages are read via WP-Cron about once a minute. Up to 20 posts per day can be started from the chat.', 'wp-ai-post-creator' ) ); ?>
				</div>
				<div class="aipc-checks">
					<label class="aipc-check"><input type="checkbox" name="two_way" value="1" <?php checked( ! empty( $aipc_bale['two_way'] ) ); ?> /> <?php esc_html_e( 'Accept commands from Bale chats', 'wp-ai-post-creator' ); ?></label>
				</div>
				<p class="description"><?php esc_html_e( 'Commands: نوشتن: <topic> · وضعیت · آخرین · انتشار · صف · راهنما', 'wp-ai-post-creator' ); ?></p>
			</div>

			<div class="aipc-card aipc-card-inner">
				<div class="aipc-heading">
					<h3><?php esc_html_e( 'Instant replies (webhook)', 'wp-ai-post-creator' ); ?></h3>
					<?php aipc_help( 'bot-webhook', __( 'Without a webhook the bot reads new messages via WP-Cron (about once a minute, and only when the site gets visits) — so button presses and commands answer with a delay. With the webhook on, Bale/Telegram pushes every message and button press straight to your site and the bot answers within seconds. Requires a publicly reachable site over HTTPS. While the webhook is active, polling pauses automatically.', 'wp-ai-post-creator' ) ); ?>
				</div>
				<div class="aipc-checks">
					<label class="aipc-check"><input type="checkbox" name="webhook" value="1" <?php checked( ! empty( $aipc_bale['webhook'] ) ); ?> /> <?php esc_html_e( 'Answer instantly via webhook (recommended)', 'wp-ai-post-creator' ); ?></label>
				</div>
				<p class="description"><?php esc_html_e( 'Saving with this on registers the webhook with the platform automatically; turning it off removes it and polling resumes.', 'wp-ai-post-creator' ); ?></p>
				<?php if ( AIPC_Bale::webhook_active( $aipc_bale ) ) : ?>
					<p class="description" style="direction:ltr;text-align:left"><code><?php echo esc_html( AIPC_Bale::webhook_url( $aipc_bale ) ); ?></code></p>
				<?php endif; ?>
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
			<h2><?php esc_html_e( 'What the bot can do', 'wp-ai-post-creator' ); ?></h2>
		</div>
		<p class="description"><?php esc_html_e( 'Once two-way commands are on, every authorized chat can:', 'wp-ai-post-creator' ); ?></p>
		<ul class="aipc-steps-list">
			<li><?php esc_html_e( '📲 Get the image + summary + link after every generated post, with “Publish now” / “Schedule” buttons under drafts.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( '📱 Open the tappable menu with «منو»: new topic, topic queue, drafts, status.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( '✍️ Start drafts with «نوشتن: topic» or straight from the queue buttons.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( '⏰ Schedule with Jalali or Gregorian dates — «1404/07/20 18:30», «2026-10-12 18:30», «فردا 18:30».', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( '🧾 Receive daily or weekly activity reports at the time you pick.', 'wp-ai-post-creator' ); ?></li>
		</ul>
	</div>

	<div class="aipc-card">
		<div class="aipc-heading">
			<h2><?php esc_html_e( 'Troubleshooting', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'bot-trouble', __( 'The two most common causes: WP-Cron not running (nothing is polled, nothing is sent on schedule) and a token/platform mismatch. The Cron status box on the Schedule page shows whether ticks actually happen.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<ul class="aipc-steps-list">
			<li><?php esc_html_e( 'No answer to commands or buttons? First enable “Accept commands from Bale chats”. Without the webhook, replies ride on WP-Cron — expect a delay of a minute or more (longer on sites with little traffic). If nothing ever arrives, check the Cron status box on the Schedule page.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Replies too slow? Turn on “Instant replies (webhook)” above and save — button presses and commands are then answered within seconds instead of waiting for the next cron poll.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Test message fails? Re-check the token (no stray spaces), make sure the platform matches the token’s messenger, and add at least one chat ID — the exact error appears next to the button.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( '“Detect chat ID” finds nothing? Send a fresh message to the bot first — detection reads the bot’s most recent incoming messages.', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Channel gets nothing? The bot must be an admin of the channel, and the recipient must be @channelusername (or the channel’s numeric ID).', 'wp-ai-post-creator' ); ?></li>
			<li><?php esc_html_e( 'Keep the token private — anyone who has it controls the bot, and two sites polling the same bot steal each other’s messages.', 'wp-ai-post-creator' ); ?></li>
		</ul>
	</div>

</div>
