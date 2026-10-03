<?php
/**
 * Update-from-Git page: repository, branch and token + self-update.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_cfg        = AIPC_Updater::config();
$aipc_remote_key = 'aipc_git_v_' . md5( $aipc_cfg['repo'] . '|' . $aipc_cfg['branch'] );
$aipc_remote     = get_transient( $aipc_remote_key );
$aipc_remote     = is_string( $aipc_remote ) ? $aipc_remote : '';
$aipc_backup     = AIPC_Updater::last_backup();
$aipc_git        = isset( $_GET['aipc_git'] ) ? sanitize_key( wp_unslash( $_GET['aipc_git'] ) ) : '';
$aipc_err        = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) : '';
$aipc_to         = isset( $_GET['to'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['to'] ) ) ) : '';
$aipc_ver        = isset( $_GET['ver'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ver'] ) ) ) : '';
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">⬇️</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Update from Git', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Download the latest plugin files straight from the GitHub repository and replace this installation in place.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<span class="aipc-chip">📦 <?php echo esc_html( $aipc_cfg['repo'] ); ?></span>
				<span class="aipc-chip">🌿 <?php echo esc_html( $aipc_cfg['branch'] ); ?></span>
				<span class="aipc-chip"><?php echo '' !== $aipc_cfg['token'] ? '🔑 PAT' : '🌍 public'; ?></span>
				<a class="aipc-chip aipc-chip-link" href="https://github.com/<?php echo esc_attr( $aipc_cfg['repo'] ); ?>" target="_blank" rel="noopener">GitHub ↗</a>
			</p>
		</div>
	</div>

	<?php if ( 'updated' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				/* translators: %s: version number. */
				esc_html__( 'Updated successfully to %s.', 'wp-ai-post-creator' ),
				esc_html( $aipc_to )
			);
			?>
		</p></div>
	<?php elseif ( 'tested' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				/* translators: %s: version number. */
				esc_html__( 'Connection test succeeded — latest version: %s', 'wp-ai-post-creator' ),
				esc_html( $aipc_ver )
			);
			?>
		</p></div>
	<?php elseif ( 'failed' === $aipc_git || 'check_failed' === $aipc_git || 'test_failed' === $aipc_git ) : ?>
		<div class="notice notice-error is-dismissible"><p>⚠ <?php echo esc_html( $aipc_err ); ?></p></div>
	<?php elseif ( 'checked' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The remote version was refreshed.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'saved' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Update settings saved.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Update settings', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'up-settings', __( 'Where updates come from: the GitHub repository, the branch to track, and an optional Personal Access Token for private repositories (write-only, stored on your site only).', 'wp-ai-post-creator' ) ); ?>
		</div>

		<form class="aipc-conn-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'aipc_save_update_settings' ); ?>
			<?php wp_nonce_field( 'aipc_git_test', 'aipc_nonce_test' ); ?>
			<input type="hidden" name="action" value="aipc_save_update_settings" />

			<div class="aipc-grid">
				<div class="aipc-field">
					<label for="aipc-git-repo"><?php esc_html_e( 'GitHub repository', 'wp-ai-post-creator' ); ?></label>
					<input type="text" id="aipc-git-repo" class="aipc-input code" name="repo"
						placeholder="<?php esc_attr_e( 'owner/name — for example ahmad75naraghi/wp-ai-post-creator', 'wp-ai-post-creator' ); ?>"
						value="<?php echo esc_attr( $aipc_cfg['repo'] ); ?>" />
				</div>
				<div class="aipc-field">
					<label for="aipc-git-branch"><?php esc_html_e( 'Repository branch', 'wp-ai-post-creator' ); ?></label>
					<input type="text" id="aipc-git-branch" class="aipc-input code" name="branch"
						placeholder="main"
						value="<?php echo esc_attr( $aipc_cfg['branch'] ); ?>" />
					<p class="description"><?php esc_html_e( 'main for stable releases, or a development branch.', 'wp-ai-post-creator' ); ?></p>
				</div>
			</div>

			<div class="aipc-field">
				<label for="aipc-git-token"><?php esc_html_e( 'Personal Access Token', 'wp-ai-post-creator' ); ?></label>
				<input type="password" id="aipc-git-token" class="aipc-input code" name="token" autocomplete="new-password"
					placeholder="<?php echo '' !== $aipc_cfg['token'] ? esc_attr__( '••••• (saved — leave empty to keep)', 'wp-ai-post-creator' ) : 'ghp_… / github_pat_…'; ?>"
					value="" />
				<p class="description"><?php esc_html_e( 'Used for private repositories and higher rate limits. Stored on your site only, never displayed again, and sent only to github.com over HTTPS.', 'wp-ai-post-creator' ); ?></p>
			</div>

			<p class="aipc-inline-form">
				<button type="submit" class="button button-primary">💾 <?php esc_html_e( 'Save settings', 'wp-ai-post-creator' ); ?></button>
				<button type="submit" class="button"
					formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=aipc_git_test' ) ); ?>">🔌 <?php esc_html_e( 'Test connection', 'wp-ai-post-creator' ); ?></button>
				<span class="description"><?php esc_html_e( 'The test uses the values above; an empty token field tests the stored one.', 'wp-ai-post-creator' ); ?></span>
			</p>
		</form>
	</section>

	<section class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Versions', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'up-versions', __( 'Compares the installed version with the remote branch. A newer remote version means an update is available.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<table class="aipc-table widefat aipc-kv-table">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Installed version', 'wp-ai-post-creator' ); ?></strong></td>
					<td>v<?php echo esc_html( AIPC_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Available on GitHub', 'wp-ai-post-creator' ); ?></strong></td>
					<td>
						<?php if ( '' === $aipc_remote ) : ?>
							<em><?php esc_html_e( 'not checked yet', 'wp-ai-post-creator' ); ?></em>
						<?php else : ?>
							v<?php echo esc_html( $aipc_remote ); ?>
							<?php if ( version_compare( $aipc_remote, AIPC_VERSION, '>' ) ) : ?>
								<span class="aipc-badge aipc-badge-ok"><?php esc_html_e( 'Update available', 'wp-ai-post-creator' ); ?></span>
							<?php elseif ( version_compare( $aipc_remote, AIPC_VERSION, '<' ) ) : ?>
								<span class="aipc-badge aipc-badge-warn"><?php esc_html_e( 'Older than the installed version', 'wp-ai-post-creator' ); ?></span>
							<?php else : ?>
								<span class="aipc-badge aipc-badge-ok"><?php esc_html_e( 'Up to date', 'wp-ai-post-creator' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aipc-form-gap">
			<?php wp_nonce_field( 'aipc_git_check' ); ?>
			<input type="hidden" name="action" value="aipc_git_check" />
			<button type="submit" class="button">🔄 <?php esc_html_e( 'Check for updates now', 'wp-ai-post-creator' ); ?></button>
		</form>
	</section>

	<section class="aipc-card">
<div class="aipc-heading">
					<h2><?php esc_html_e( 'Update now', 'wp-ai-post-creator' ); ?></h2>
			<?php aipc_help( 'up-run', __( 'Downloads the branch snapshot, verifies the plugin header, backs up the current files and swaps the new ones in — rolling back automatically if anything fails.', 'wp-ai-post-creator' ) ); ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'The button downloads the repository snapshot, verifies the plugin header, backs up the current files to wp-content/aipc-backups and swaps the new files in. If anything fails, the previous version is restored automatically.', 'wp-ai-post-creator' ); ?>
			<?php esc_html_e( 'Your settings, connections, schedules and posts are files-independent and stay untouched.', 'wp-ai-post-creator' ); ?>
		</p>
		<?php if ( '' !== $aipc_remote && version_compare( $aipc_remote, AIPC_VERSION, '<' ) ) : ?>
			<div class="notice notice-warning inline aipc-notice-narrow"><p>
				<?php
				printf(
					/* translators: %s: branch name. */
					esc_html__( 'The branch “%s” holds an OLDER version than the installed one — updating would downgrade the plugin.', 'wp-ai-post-creator' ),
					esc_html( $aipc_cfg['branch'] )
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'aipc_git_update' ); ?>
			<input type="hidden" name="action" value="aipc_git_update" />
			<p>
				<button type="submit" class="button button-primary button-hero"
					onclick="return confirm('<?php echo esc_js( __( 'Replace all plugin files with the latest version from GitHub?', 'wp-ai-post-creator' ) ); ?>');">
					⬇️ <?php esc_html_e( 'Update from Git', 'wp-ai-post-creator' ); ?>
				</button>
			</p>
			<p>
				<label class="aipc-check">
					<input type="checkbox" name="force" value="1" />
					<?php esc_html_e( 'Reinstall anyway (also when the branch is not newer)', 'wp-ai-post-creator' ); ?>
				</label>
			</p>
		</form>

		<?php if ( '' !== $aipc_backup ) : ?>
			<p class="description">
				🛡 <?php
				printf(
					/* translators: %s: backup path. */
					esc_html__( 'Last backup of the previous version: %s', 'wp-ai-post-creator' ),
					'<code>' . esc_html( AIPC_Updater::last_backup() ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
	</section>

</div>
