<?php
/**
 * Settings page.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc = AIPC_Settings::all();
?>
<div class="wrap aipc-wrap">
	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">🤖</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'AI Post Creator — Settings', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Connect any OpenAI-compatible provider and tune content generation.', 'wp-ai-post-creator' ); ?></p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'aipc' ); ?>

		<section class="aipc-card">
			<h2>🎯 <?php esc_html_e( 'Site prompt', 'wp-ai-post-creator' ); ?></h2>
			<p class="description" style="margin-bottom:8px;">
				<?php esc_html_e( 'Tell the agent what this site is about: its purpose, audience and voice. The agent uses this to pick the category, invent topics and stay on-brand.', 'wp-ai-post-creator' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-site-prompt"><?php esc_html_e( 'Site prompt', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<textarea id="aipc-site-prompt" rows="5" class="large-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[site_prompt]"
							placeholder="<?php esc_attr_e( 'e.g. A Persian cooking blog focused on quick weeknight dinners for busy families. Goal: practical, tested recipes with everyday ingredients. Warm, encouraging voice.', 'wp-ai-post-creator' ); ?>"><?php echo esc_textarea( $aipc['site_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Example: what the site is, what it is about, its goal, its audience, the voice you want.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="aipc-card">
			<h2>🔌 <?php esc_html_e( 'Provider connection', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-base-url"><?php esc_html_e( 'API base URL', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="url" id="aipc-base-url" class="regular-text code"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[api_base_url]"
							value="<?php echo esc_attr( $aipc['api_base_url'] ); ?>"
							placeholder="https://api.openai.com/v1" />
						<p class="description">
							<?php esc_html_e( 'Any OpenAI-compatible endpoint. Examples:', 'wp-ai-post-creator' ); ?><br />
							<code>https://api.openai.com/v1</code> (OpenAI) ·
							<code>https://openrouter.ai/api/v1</code> (OpenRouter) ·
							<code>https://api.groq.com/openai/v1</code> (Groq) ·
							<code>https://api.deepseek.com/v1</code> (DeepSeek) ·
							<code>http://localhost:11434/v1</code> (Ollama) ·
							<code>http://localhost:1234/v1</code> (LM Studio)
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-api-key"><?php esc_html_e( 'API key', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="password" id="aipc-api-key" class="regular-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[api_key]"
							autocomplete="new-password"
							placeholder="<?php echo $aipc['api_key'] ? esc_attr__( '•••••••••••• (saved — leave empty to keep)', 'wp-ai-post-creator' ) : esc_attr__( 'sk-…', 'wp-ai-post-creator' ); ?>"
							value="" />
						<button type="button" class="button aipc-btn-ghost" id="aipc-toggle-key"><?php esc_html_e( 'Show', 'wp-ai-post-creator' ); ?></button>
						<p class="description"><?php esc_html_e( 'Stored in your own database and only sent to the provider you configured. Ollama / LM Studio accept any dummy value.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection test', 'wp-ai-post-creator' ); ?></th>
					<td>
						<button type="button" class="button button-secondary" id="aipc-test"><?php esc_html_e( 'Test connection', 'wp-ai-post-creator' ); ?></button>
						<span id="aipc-test-result" class="aipc-inline-status" aria-live="polite"></span>
					</td>
				</tr>
			</table>
		</section>

		<section class="aipc-card">
			<h2>🧠 <?php esc_html_e( 'Model & generation', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-model"><?php esc_html_e( 'Chat model', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="text" id="aipc-model" class="regular-text code" list="aipc-model-list"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[chat_model]"
							value="<?php echo esc_attr( $aipc['chat_model'] ); ?>" />
						<datalist id="aipc-model-list">
							<option value="gpt-4o"></option>
							<option value="gpt-4o-mini"></option>
							<option value="gpt-4.1"></option>
							<option value="gpt-4.1-mini"></option>
							<option value="deepseek-chat"></option>
							<option value="llama-3.1-70b-versatile"></option>
						</datalist>
						<button type="button" class="button aipc-btn-ghost" id="aipc-fetch-models"><?php esc_html_e( 'Load models from provider', 'wp-ai-post-creator' ); ?></button>
						<span id="aipc-models-status" class="aipc-inline-status" aria-live="polite"></span>
						<p class="description"><?php esc_html_e( 'Type any model id your provider supports, or load the list automatically.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-temperature"><?php esc_html_e( 'Temperature', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="number" id="aipc-temperature" min="0" max="2" step="0.1" class="small-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[temperature]"
							value="<?php echo esc_attr( $aipc['temperature'] ); ?>" />
						<p class="description"><?php esc_html_e( '0 = precise and repetitive, 1+ = creative. 0.6–0.8 works well for articles.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-max-tokens"><?php esc_html_e( 'Max tokens per step', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="number" id="aipc-max-tokens" min="256" max="16000" step="64" class="small-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[max_tokens]"
							value="<?php echo esc_attr( $aipc['max_tokens'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Each agent step (plan, each section, SEO…) may use up to this many tokens.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="number" id="aipc-timeout" min="15" max="600" step="5" class="small-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[request_timeout]"
							value="<?php echo esc_attr( $aipc['request_timeout'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Increase for slow local models (Ollama) or very long generations.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="aipc-card">
			<h2>📝 <?php esc_html_e( 'Content defaults', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-language"><?php esc_html_e( 'Default language', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<select id="aipc-language" class="aipc-select"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[content_language]">
							<?php foreach ( AIPC_Settings::languages() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $aipc['content_language'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-tone"><?php esc_html_e( 'Default tone', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<select id="aipc-tone" class="aipc-select"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[default_tone]">
							<?php foreach ( AIPC_Settings::tones() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $aipc['default_tone'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-length"><?php esc_html_e( 'Default length', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<select id="aipc-length" class="aipc-select"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[default_length]">
							<?php foreach ( AIPC_Settings::lengths() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $aipc['default_length'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Extras', 'wp-ai-post-creator' ); ?></th>
					<td>
						<fieldset>
							<label class="aipc-check">
								<input type="checkbox" name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[add_toc]" value="1" <?php checked( $aipc['add_toc'] ); ?> />
								<?php esc_html_e( 'Add a table of contents by default', 'wp-ai-post-creator' ); ?>
							</label>
							<label class="aipc-check">
								<input type="checkbox" name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[add_faq]" value="1" <?php checked( $aipc['add_faq'] ); ?> />
								<?php esc_html_e( 'Add an FAQ block with schema markup by default', 'wp-ai-post-creator' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>
		</section>

		<section class="aipc-card">
			<h2>🖼 <?php esc_html_e( 'Featured images', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable image generation', 'wp-ai-post-creator' ); ?></th>
					<td>
						<label class="aipc-check">
							<input type="checkbox" name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[image_enabled]" value="1" <?php checked( $aipc['image_enabled'] ); ?> />
							<?php esc_html_e( 'Let the agent generate a featured image (skipped gracefully if the provider does not support it)', 'wp-ai-post-creator' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-image-model"><?php esc_html_e( 'Image model', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<input type="text" id="aipc-image-model" class="regular-text code"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[image_model]"
							value="<?php echo esc_attr( $aipc['image_model'] ); ?>" />
						<p class="description">dall-e-3 · dall-e-2 · gpt-image-1 …</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-image-size"><?php esc_html_e( 'Image size', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<select id="aipc-image-size" class="aipc-select"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[image_size]">
							<?php foreach ( AIPC_Settings::image_sizes() as $size ) : ?>
								<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $aipc['image_size'], $size ); ?>><?php echo esc_html( $size ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '1792×1024 is a good wide header for DALL·E 3; gpt-image-1 uses 1536×1024.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="aipc-card">
			<h2>⌨ <?php esc_html_e( 'Advanced', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-extra-prompt"><?php esc_html_e( 'Extra system instructions', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<textarea id="aipc-extra-prompt" rows="4" class="large-text code"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[system_prompt_extra]"
							placeholder="<?php esc_attr_e( 'e.g. Always address the reader as “you” and include practical examples.', 'wp-ai-post-creator' ); ?>"><?php echo esc_textarea( $aipc['system_prompt_extra'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Appended to every agent request — steer style, vocabulary, formatting rules…', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'wp-ai-post-creator' ); ?></th>
					<td>
						<label class="aipc-check">
							<input type="checkbox" name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( $aipc['delete_on_uninstall'] ); ?> />
							<?php esc_html_e( 'Delete all plugin data (settings, jobs, SEO meta) when the plugin is uninstalled', 'wp-ai-post-creator' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</section>

		<?php submit_button( __( 'Save settings', 'wp-ai-post-creator' ) ); ?>
	</form>
</div>
