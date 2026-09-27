<?php
/**
 * Settings page (content defaults).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc = AIPC_Settings::all();
?>
<div class="wrap aipc-wrap">
	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">⚙️</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'AI Post Creator — Settings', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Content defaults. Providers, models and per-step prompts live under Connections and Prompts & Steps.', 'wp-ai-post-creator' ); ?></p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'aipc' ); ?>

		<section class="aipc-card">
			<h2>🎯 <?php esc_html_e( 'Site prompt', 'wp-ai-post-creator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aipc-site-prompt"><?php esc_html_e( 'Site prompt', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<textarea id="aipc-site-prompt" rows="5" class="large-text"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[site_prompt]"
							placeholder="<?php esc_attr_e( 'e.g. A Persian cooking blog focused on quick weeknight dinners for busy families. Goal: practical, tested recipes with everyday ingredients. Warm, encouraging voice.', 'wp-ai-post-creator' ); ?>"><?php echo esc_textarea( $aipc['site_prompt'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Tell the agent what this site is about: its purpose, audience and voice. Used to pick the category, invent topics and keep every sentence on-brand.', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipc-source-sites"><?php esc_html_e( 'Research source sites', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<textarea id="aipc-source-sites" rows="3" class="large-text code"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[source_sites]"
							placeholder="https://example.com/news
https://blog.example.org"><?php echo esc_textarea( $aipc['source_sites'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional: one URL per line (max 8). The agent reads each site’s RSS feed while planning and grounds the topic and facts in their latest articles.', 'wp-ai-post-creator' ); ?></p>
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
					<th scope="row"><label for="aipc-image-size"><?php esc_html_e( 'Image size', 'wp-ai-post-creator' ); ?></label></th>
					<td>
						<select id="aipc-image-size" class="aipc-select"
							name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[image_size]">
							<?php foreach ( AIPC_Settings::image_sizes() as $size ) : ?>
								<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $aipc['image_size'], $size ); ?>><?php echo esc_html( $size ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '1792×1024 is a good wide header for DALL·E 3; gpt-image-1 uses 1536×1024. The image model itself is set per connection.', 'wp-ai-post-creator' ); ?></p>
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
						<p class="description"><?php esc_html_e( 'Appended to every agent request (the {{extra}} placeholder of the system prompt).', 'wp-ai-post-creator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'wp-ai-post-creator' ); ?></th>
					<td>
						<label class="aipc-check">
							<input type="checkbox" name="<?php echo esc_attr( AIPC_Settings::OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( $aipc['delete_on_uninstall'] ); ?> />
							<?php esc_html_e( 'Delete all plugin data (settings, connections, jobs, SEO meta) when the plugin is uninstalled', 'wp-ai-post-creator' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</section>

		<?php submit_button( __( 'Save settings', 'wp-ai-post-creator' ) ); ?>
	</form>
</div>
