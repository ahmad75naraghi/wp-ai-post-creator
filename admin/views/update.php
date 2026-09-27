<?php
/**
 * Update-from-Git page: pull the latest plugin files straight from GitHub.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

$aipc_branch = AIPC_Settings::get( 'update_branch' );
$aipc_remote = get_transient( 'aipc_git_v_' . md5( $aipc_branch ) );
$aipc_remote = is_string( $aipc_remote ) ? $aipc_remote : '';
$aipc_backup = AIPC_Updater::last_backup();
$aipc_git    = isset( $_GET['aipc_git'] ) ? sanitize_key( wp_unslash( $_GET['aipc_git'] ) ) : '';
$aipc_err    = isset( $_GET['err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['err'] ) ) ) : '';
$aipc_to     = isset( $_GET['to'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['to'] ) ) ) : '';
?>
<div class="wrap aipc-wrap">

	<div class="aipc-header">
		<div class="aipc-logo" aria-hidden="true">⬇️</div>
		<div class="aipc-header-text">
			<h1><?php esc_html_e( 'Update from Git', 'wp-ai-post-creator' ); ?></h1>
			<p class="aipc-sub"><?php esc_html_e( 'Download the latest plugin files straight from the GitHub repository and replace this installation in place.', 'wp-ai-post-creator' ); ?></p>
			<p class="aipc-meta">
				<span class="aipc-chip">📦 <?php echo esc_html( AIPC_Updater::REPO ); ?></span>
				<a class="aipc-chip aipc-chip-link" href="https://github.com/<?php echo esc_attr( AIPC_Updater::REPO ); ?>" target="_blank" rel="noopener">GitHub ↗</a>
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
	<?php elseif ( 'failed' === $aipc_git || 'check_failed' === $aipc_git ) : ?>
		<div class="notice notice-error is-dismissible"><p>⚠ <?php echo esc_html( $aipc_err ); ?></p></div>
	<?php elseif ( 'checked' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The remote version was refreshed.', 'wp-ai-post-creator' ); ?></p></div>
	<?php elseif ( 'saved' === $aipc_git ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Branch saved.', 'wp-ai-post-creator' ); ?></p></div>
	<?php endif; ?>

	<section class="aipc-card">
		<h2><?php esc_html_e( 'Versions', 'wp-ai-post-creator' ); ?></h2>
		<table class="aipc-table widefat" style="max-width:640px;">
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
				<tr>
					<td><strong><?php esc_html_e( 'Branch', 'wp-ai-post-creator' ); ?></strong></td>
					<td><code><?php echo esc_html( $aipc_branch ); ?></code></td>
				</tr>
			</tbody>
		</table>

		<form class="aipc-limit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-block-start:14px;">
			<?php wp_nonce_field( 'aipc_save_update_settings' ); ?>
			<input type="hidden" name="action" value="aipc_save_update_settings" />
			<label for="aipc-git-branch" style="font-weight:600;"><?php esc_html_e( 'Repository branch', 'wp-ai-post-creator' ); ?></label>
			<input type="text" id="aipc-git-branch" class="aipc-input" name="branch"
				style="width:280px;" value="<?php echo esc_attr( $aipc_branch ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Save branch', 'wp-ai-post-creator' ); ?></button>
			<span class="description"><?php esc_html_e( 'main for stable releases, or a development branch.', 'wp-ai-post-creator' ); ?></span>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-block-start:10px;">
			<?php wp_nonce_field( 'aipc_git_check' ); ?>
			<input type="hidden" name="action" value="aipc_git_check" />
			<input type="hidden" name="branch" value="<?php echo esc_attr( $aipc_branch ); ?>" />
			<button type="submit" class="button">🔄 <?php esc_html_e( 'Check for updates now', 'wp-ai-post-creator' ); ?></button>
		</form>
	</section>

	<section class="aipc-card">
		<h2><?php esc_html_e( 'Update now', 'wp-ai-post-creator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The button downloads the repository snapshot, verifies the plugin header, backs up the current files to wp-content/aipc-backups and swaps the new files in. If anything fails, the previous version is restored automatically.', 'wp-ai-post-creator' ); ?>
			<?php esc_html_e( 'Your settings, connections, schedules and posts are files-independent and stay untouched.', 'wp-ai-post-creator' ); ?>
		</p>
		<?php if ( '' !== $aipc_remote && version_compare( $aipc_remote, AIPC_VERSION, '<' ) ) : ?>
			<div class="notice notice-warning inline" style="max-width:760px;"><p>
				<?php
				printf(
					/* translators: %s: branch name. */
					esc_html__( 'The branch “%s” holds an OLDER version than the installed one — updating would downgrade the plugin.', 'wp-ai-post-creator' ),
					esc_html( $aipc_branch )
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'aipc_git_update' ); ?>
			<input type="hidden" name="action" value="aipc_git_update" />
			<input type="hidden" name="branch" value="<?php echo esc_attr( $aipc_branch ); ?>" />
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
