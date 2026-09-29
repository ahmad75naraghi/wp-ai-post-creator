<?php
/**
 * Prompts & Steps page: per-step connection assignment + prompt templates.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_registry = AIPC_Steps::registry();
$aipc_conns    = AIPC_Connections::all();
$aipc_default  = AIPC_Connections::get_default();
$aipc_msg      = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">⌨</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Prompts & Steps', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub">
				<?php esc_html_e( 'Assign each pipeline step its own AI connection and edit its prompt template.', 'wp-ai-post-creator' ); ?>
				<?php if ( $aipc_default ) : ?>
					⭐ <?php echo esc_html( sprintf( /* translators: %s: connection name */ __( 'Default connection: %s', 'wp-ai-post-creator' ), $aipc_default['name'] ) ); ?>
				<?php else : ?>
					⚠️ <?php esc_html_e( 'No connection configured yet.', 'wp-ai-post-creator' ); ?>
				<?php endif; ?>
			</p>
		</div>
	</div>

	<?php if ( 'saved' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert aipc-alert-ok"><p>✅ <?php esc_html_e( 'Prompts & assignments saved.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'aipc_save_steps' ); ?>
		<input type="hidden" name="action" value="aipc_save_steps" />

		<div class="aipc-sticky-actions">
			<button type="submit" class="button button-primary">💾 <?php esc_html_e( 'Save all', 'wp-ai-post-creator' ); ?></button>
			<span class="aipc-hint"><?php esc_html_e( 'Tip: leaving a prompt exactly as its default keeps it in “default” mode (auto-improved on plugin updates).', 'wp-ai-post-creator' ); ?></span>
			<?php aipc_help( 'pr-chains', __( 'Each pipeline step can use its own connection, or an ordered fallback chain: if a provider keeps failing, the agent switches to the next one automatically. Empty = the default connection.', 'wp-ai-post-creator' ) ); ?>
			<?php aipc_help( 'pr-prompts', __( 'Prompts are the instructions sent to the AI for each step. A prompt left exactly at its default stays in “default” mode and is improved automatically on plugin updates; editing it freezes your own version.', 'wp-ai-post-creator' ) ); ?>
		</div>

		<?php foreach ( $aipc_registry as $aipc_step => $aipc_meta ) : ?>
			<?php
		$aipc_cfg     = AIPC_Steps::get( $aipc_step );
		$aipc_value   = AIPC_Steps::has_custom_prompt( $aipc_step ) ? $aipc_cfg['prompt'] : $aipc_meta['prompt'];
		$aipc_is_img  = 'image' === $aipc_meta['kind'];
		$aipc_is_sys  = 'system' === $aipc_meta['kind'];
		$aipc_chain   = isset( $aipc_cfg['connections'] ) && is_array( $aipc_cfg['connections'] ) ? $aipc_cfg['connections'] : array();
		$aipc_chain_names = array();
		foreach ( $aipc_chain as $aipc_cid ) {
			$aipc_cc = AIPC_Connections::get( $aipc_cid );
			if ( $aipc_cc ) {
				$aipc_chain_names[] = $aipc_cc['name'];
			}
		}
		?>
			<section class="aipc-card aipc-step-card">
				<div class="aipc-step-head">
					<h2><?php echo esc_html( $aipc_meta['label'] ); ?></h2>
					<span class="aipc-chip"><?php echo esc_html( $aipc_meta['kind'] ); ?></span>
					<?php if ( AIPC_Steps::has_custom_prompt( $aipc_step ) ) : ?>
						<span class="aipc-chip aipc-chip-custom">✎ <?php esc_html_e( 'custom', 'wp-ai-post-creator' ); ?></span>
					<?php endif; ?>
					<?php if ( ! $aipc_is_sys && ! empty( $aipc_chain_names ) ) : ?>
						<span class="aipc-chip aipc-chip-custom">🔌 <?php echo esc_html( implode( ' → ', $aipc_chain_names ) ); ?></span>
						<?php if ( count( $aipc_chain_names ) > 1 ) : ?>
							<span class="aipc-chip aipc-chip-custom">🛡 <?php echo esc_html( sprintf( /* translators: %d: number of connections */ __( '%d-step fallback chain', 'wp-ai-post-creator' ), count( $aipc_chain_names ) ) ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<p class="aipc-hint"><?php echo esc_html( $aipc_meta['desc'] ); ?></p>

				<div class="aipc-grid">
					<?php if ( ! $aipc_is_sys ) : ?>
						<div class="aipc-field">
							<label><?php esc_html_e( 'AI connections chain (fallback order)', 'wp-ai-post-creator' ); ?></label>
							<select class="aipc-select" name="steps[<?php echo esc_attr( $aipc_step ); ?>][connections][]" multiple size="<?php echo min( 4, max( 2, count( $aipc_conns ) + 1 ) ); ?>">
								<?php foreach ( $aipc_conns as $aipc_conn ) : ?>
									<option value="<?php echo esc_attr( $aipc_conn['id'] ); ?>" <?php selected( true, in_array( $aipc_conn['id'], $aipc_chain, true ) ); ?>><?php echo esc_html( $aipc_conn['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Hold Ctrl / Cmd to pick several. Each selected connection gets its own retry budget; when one keeps failing the agent automatically switches to the next. Empty = default connection.', 'wp-ai-post-creator' ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( ! $aipc_is_img ) : ?>
						<div class="aipc-field aipc-field-reset">
							<label><?php esc_html_e( 'Reset', 'wp-ai-post-creator' ); ?></label>
							<label class="aipc-check">
								<input type="checkbox" name="steps[<?php echo esc_attr( $aipc_step ); ?>][reset]" value="1" />
								<?php esc_html_e( 'restore the default prompt on save', 'wp-ai-post-creator' ); ?>
							</label>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( ! $aipc_is_img ) : ?>
					<label class="aipc-label"><?php esc_html_e( 'Prompt template', 'wp-ai-post-creator' ); ?></label>
					<textarea class="large-text code aipc-prompt-area" rows="<?php echo 'system' === $aipc_meta['kind'] ? 4 : 10; ?>"
						name="steps[<?php echo esc_attr( $aipc_step ); ?>][prompt]"><?php echo esc_textarea( $aipc_value ); ?></textarea>

					<?php if ( 'chat_json' === $aipc_meta['kind'] ) : ?>
						<p class="aipc-hint">ℹ <?php esc_html_e( 'A “respond ONLY with valid JSON” instruction is appended automatically.', 'wp-ai-post-creator' ); ?></p>
					<?php elseif ( 'chat_html' === $aipc_meta['kind'] ) : ?>
						<p class="aipc-hint">ℹ <?php esc_html_e( 'A “respond with raw HTML only” instruction is appended automatically.', 'wp-ai-post-creator' ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $aipc_meta['placeholders'] ) ) : ?>
						<details class="aipc-options">
							<summary><?php esc_html_e( 'Available placeholders', 'wp-ai-post-creator' ); ?></summary>
							<table class="aipc-table widefat striped">
								<tbody>
								<?php foreach ( $aipc_meta['placeholders'] as $aipc_ph => $aipc_ph_desc ) : ?>
									<tr>
										<td class="aipc-code aipc-ph"><?php echo esc_html( $aipc_ph ); ?></td>
										<td><?php echo esc_html( $aipc_ph_desc ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>

		<div class="aipc-sticky-actions">
			<button type="submit" class="button button-primary">💾 <?php esc_html_e( 'Save all', 'wp-ai-post-creator' ); ?></button>
		</div>
	</form>

</div>
