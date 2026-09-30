<?php
/**
 * Connections management page.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_conns    = AIPC_Connections::all();
$aipc_editing  = null;
$aipc_edit_id  = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
$aipc_msg      = isset( $_GET['aipc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aipc_msg'] ) ) : '';

if ( '' !== $aipc_edit_id ) {
	$aipc_editing = AIPC_Connections::get( $aipc_edit_id );
}

/**
 * Render one connection form (add or edit).
 *
 * @param array|null $conn Connection being edited (null = add form).
 * @return void
 */
if ( ! function_exists( 'aipc_connection_form' ) ) :
function aipc_connection_form( $conn ) {
	$editing = is_array( $conn );
	?>
	<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'aipc_save_connection' ); ?>
		<input type="hidden" name="action" value="aipc_save_connection" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $conn['id'] : '' ); ?>" />

<div class="aipc-heading">
					<h3><?php echo $editing ? esc_html__( 'Edit connection', 'wp-ai-post-creator' ) : esc_html__( 'Add a new connection', 'wp-ai-post-creator' ); ?></h3>
			<?php aipc_help( 'conn-form', __( 'The base URL must point to an OpenAI-compatible endpoint, usually ending in /v1 (e.g. https://api.openai.com/v1). A provider’s website address is not an API endpoint. Small mistakes are corrected automatically: a pasted full endpoint URL (e.g. …/v1/chat/completions) has its endpoint path stripped, and if the test finds the API under /v1 the field is fixed for you. The API key is write-only: leave the field empty to keep the stored key. Private or internal addresses are blocked by the outbound network guard unless you enable “Allow private/LAN addresses” in Settings → Advanced.', 'wp-ai-post-creator' ) ); ?>
		</div>

		<div class="aipc-grid">
			<div class="aipc-field">
				<label><?php esc_html_e( 'Name', 'wp-ai-post-creator' ); ?></label>
				<input type="text" class="aipc-input" name="name" required
					placeholder="<?php esc_attr_e( 'e.g. OpenAI · Groq · Local Ollama', 'wp-ai-post-creator' ); ?>"
					value="<?php echo esc_attr( $editing ? $conn['name'] : '' ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'API base URL', 'wp-ai-post-creator' ); ?></label>
				<input type="url" class="aipc-input code" name="base_url" required
					placeholder="https://api.openai.com/v1"
					value="<?php echo esc_attr( $editing ? $conn['base_url'] : '' ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'API key', 'wp-ai-post-creator' ); ?></label>
				<input type="password" class="aipc-input" name="api_key" autocomplete="new-password"
					placeholder="<?php echo $editing && ! empty( $conn['api_key'] ) ? esc_attr__( '••••• (saved — leave empty to keep)', 'wp-ai-post-creator' ) : esc_attr__( 'sk-… (any value for Ollama/LM Studio)', 'wp-ai-post-creator' ); ?>"
					value="" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Chat model', 'wp-ai-post-creator' ); ?></label>
				<input type="text" class="aipc-input code" name="chat_model" list="<?php echo esc_attr( 'aipc-models-' . ( $editing ? $conn['id'] : 'new' ) ); ?>"
					value="<?php echo esc_attr( $editing ? $conn['chat_model'] : 'gpt-4o-mini' ); ?>" />
				<datalist id="<?php echo esc_attr( 'aipc-models-' . ( $editing ? $conn['id'] : 'new' ) ); ?>"></datalist>
				<div class="aipc-model-picker" hidden>
					<input type="text" class="aipc-input aipc-model-filter" placeholder="<?php esc_attr_e( 'Type to filter the models…', 'wp-ai-post-creator' ); ?>" />
					<div class="aipc-model-list" role="listbox"></div>
				</div>
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Image model', 'wp-ai-post-creator' ); ?></label>
				<input type="text" class="aipc-input code" name="image_model"
					value="<?php echo esc_attr( $editing ? $conn['image_model'] : 'dall-e-3' ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Purpose', 'wp-ai-post-creator' ); ?>
					<?php aipc_help( 'conn-routing', __( 'What this connection is used for. Steps without an explicit connection chain (Prompts page) automatically use every matching connection in priority order: text steps take the chat-capable connections, the featured-image step takes the image-capable ones. This is how you send images to a different server than the text.', 'wp-ai-post-creator' ) ); ?>
				</label>
				<?php $aipc_purpose = $editing && isset( $conn['purpose'] ) ? $conn['purpose'] : 'both'; ?>
				<select class="aipc-input" name="purpose">
					<option value="both" <?php selected( $aipc_purpose, 'both' ); ?>><?php esc_html_e( 'Chat & images', 'wp-ai-post-creator' ); ?></option>
					<option value="chat" <?php selected( $aipc_purpose, 'chat' ); ?>><?php esc_html_e( 'Chat only', 'wp-ai-post-creator' ); ?></option>
					<option value="image" <?php selected( $aipc_purpose, 'image' ); ?>><?php esc_html_e( 'Images only', 'wp-ai-post-creator' ); ?></option>
				</select>
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Priority', 'wp-ai-post-creator' ); ?>
					<?php aipc_help( 'conn-priority', __( 'Lower number = tried first (1 is the highest priority). When a connection fails 3 attempts in a row, the run automatically switches to the next connection of the same purpose in priority order.', 'wp-ai-post-creator' ) ); ?>
				</label>
				<input type="number" class="aipc-input" name="priority" min="1" max="999" step="1"
					value="<?php echo esc_attr( $editing && isset( $conn['priority'] ) ? (int) $conn['priority'] : 10 ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Temperature', 'wp-ai-post-creator' ); ?></label>
				<input type="number" class="aipc-input" name="temperature" min="0" max="2" step="0.1"
					value="<?php echo esc_attr( $editing ? $conn['temperature'] : 0.7 ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Max tokens per step', 'wp-ai-post-creator' ); ?></label>
				<input type="number" class="aipc-input" name="max_tokens" min="256" max="16000" step="64"
					value="<?php echo esc_attr( $editing ? $conn['max_tokens'] : 4000 ); ?>" />
			</div>
			<div class="aipc-field">
				<label><?php esc_html_e( 'Timeout (seconds)', 'wp-ai-post-creator' ); ?></label>
				<input type="number" class="aipc-input" name="request_timeout" min="15" max="600" step="5"
					value="<?php echo esc_attr( $editing ? $conn['request_timeout'] : 120 ); ?>" />
			</div>
		</div>

		<p>
			<label class="aipc-check">
				<input type="checkbox" name="is_default" value="1" <?php checked( $editing && ! empty( $conn['is_default'] ) ); ?> />
				<?php esc_html_e( 'Use as the default connection', 'wp-ai-post-creator' ); ?>
			</label>
		</p>

		<div class="aipc-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save connection', 'wp-ai-post-creator' ); ?></button>
			<button type="button" class="button aipc-btn-test" data-target="<?php echo esc_attr( ( $editing ? $conn['id'] : 'new' ) ); ?>"><?php esc_html_e( 'Test connection', 'wp-ai-post-creator' ); ?></button>
			<button type="button" class="button aipc-btn-models" data-target="<?php echo esc_attr( ( $editing ? $conn['id'] : 'new' ) ); ?>"><?php esc_html_e( 'Load models from provider', 'wp-ai-post-creator' ); ?></button>
			<span class="aipc-inline-status" aria-live="polite"></span>
		</div>
	</form>
	<?php
}
endif;
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">🔌</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'AI Connections', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Configure any number of OpenAI-compatible providers — then assign each pipeline step its own connection under Prompts & Steps.', 'wp-ai-post-creator' ); ?></p>
		</div>
	</div>

	<?php if ( 'saved' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert aipc-alert-ok"><p>✅ <?php esc_html_e( 'Connection saved.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'deleted' === $aipc_msg ) : ?>
		<div class="aipc-card aipc-alert"><p>🗑 <?php esc_html_e( 'Connection deleted.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
<div class="aipc-heading">
					<h2>🗂 <?php esc_html_e( 'Your connections', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'conn-list', __( 'A connection is one AI service: base URL, API key and the models to use. One connection is the default; steps without their own assignment use it. Use Test after saving to verify the setup.', 'wp-ai-post-creator' ) ); ?>
		</div>

		<?php if ( empty( $aipc_conns ) ) : ?>
			<p class="aipc-empty">— <?php esc_html_e( 'No connections yet. Add your first one below.', 'wp-ai-post-creator' ); ?> —</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped aipc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Endpoint', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Chat model', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Image model', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Default', 'wp-ai-post-creator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-ai-post-creator' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aipc_conns as $aipc_conn ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $aipc_conn['name'] ); ?></strong></td>
						<td class="aipc-code"><?php echo esc_html( $aipc_conn['base_url'] ); ?></td>
						<td class="aipc-code"><?php echo esc_html( $aipc_conn['chat_model'] ); ?></td>
						<td class="aipc-code"><?php echo esc_html( $aipc_conn['image_model'] ); ?></td>
						<td>
							<?php
							$aipc_purpose_labels = array(
								'both'  => __( 'Chat & images', 'wp-ai-post-creator' ),
								'chat'  => __( 'Chat only', 'wp-ai-post-creator' ),
								'image' => __( 'Images only', 'wp-ai-post-creator' ),
							);
							$aipc_row_purpose    = isset( $aipc_conn['purpose'] ) && isset( $aipc_purpose_labels[ $aipc_conn['purpose'] ] ) ? $aipc_conn['purpose'] : 'both';
							echo esc_html( $aipc_purpose_labels[ $aipc_row_purpose ] );
							?>
						</td>
						<td><?php echo esc_html( isset( $aipc_conn['priority'] ) ? (int) $aipc_conn['priority'] : 10 ); ?></td>
						<td><?php echo ! empty( $aipc_conn['is_default'] ) ? '⭐ ' . esc_html__( 'Yes', 'wp-ai-post-creator' ) : '—'; ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'aipc-connections', 'edit' => $aipc_conn['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'wp-ai-post-creator' ); ?></a> ·
							<a class="aipc-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aipc_delete_connection&id=' . $aipc_conn['id'] ), 'aipc_delete_connection' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this connection? Steps using it will fall back to the default connection.', 'wp-ai-post-creator' ) ); ?>');"><?php esc_html_e( 'Delete', 'wp-ai-post-creator' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<section class="aipc-card">
		<?php
		if ( $aipc_editing ) {
			aipc_connection_form( $aipc_editing );
		}
		?>
		<details class="aipc-options" <?php echo $aipc_editing ? '' : 'open'; ?> id="aipc-add-connection">
			<summary><?php esc_html_e( 'Add a new connection', 'wp-ai-post-creator' ); ?></summary>
			<?php aipc_connection_form( null ); ?>
		</details>
	</section>

	<section class="aipc-card">
<div class="aipc-heading">
					<h2>💡 <?php esc_html_e( 'Example endpoints', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'conn-examples', __( 'Ready-made endpoint patterns for popular providers — copy your provider’s pattern into the connection form above.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="aipc-code aipc-hint">
			https://api.openai.com/v1 (OpenAI) ·
			https://openrouter.ai/api/v1 (OpenRouter) ·
			https://api.groq.com/openai/v1 (Groq) ·
			https://api.deepseek.com/v1 (DeepSeek) ·
			http://localhost:11434/v1 (Ollama) ·
			http://localhost:1234/v1 (LM Studio) ·
			http://localhost:20128/v1 (OmniRoute)
		</p>
		<p class="aipc-hint">
			<?php esc_html_e( 'Self-hosted gateways (OmniRoute, Ollama, LM Studio) run on your own machine — the WordPress server must be able to reach that address. If the gateway runs on another machine in your network, enter its LAN address and enable “Allow private/LAN addresses” under Settings → Advanced.', 'wp-ai-post-creator' ); ?>
		</p>
	</section>

</div>
