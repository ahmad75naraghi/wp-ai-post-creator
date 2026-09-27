<?php
/**
 * Git self-updater: pull the latest plugin files straight from a GitHub
 * repository (public or private, via a Personal Access Token).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the Git connection (repository, branch, PAT — write-only) in its own
 * non-autoloaded option, checks the remote version and swaps the plugin files
 * with a backup + automatic rollback.
 */
final class AIPC_Updater {

	const OPTION      = 'aipc_git';
	const DEFAULT_REPO = 'ahmad75naraghi/wp-ai-post-creator';
	const SLUG        = 'wp-ai-post-creator';
	const MAINFILE    = 'wp-ai-post-creator.php';

	/* ---------------------------------------------------------------------
	 * Configuration (repository / branch / token)
	 * ------------------------------------------------------------------- */

	/**
	 * The stored Git configuration merged with defaults.
	 *
	 * @return array { repo, branch, token }
	 */
	public static function config() {
		$cfg = get_option( self::OPTION, array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return wp_parse_args( $cfg, array(
			'repo'   => self::DEFAULT_REPO,
			'branch' => 'main',
			'token'  => '',
		) );
	}

	/**
	 * Sanitize and store the Git configuration.
	 *
	 * The token is write-only: an empty field keeps the stored token.
	 *
	 * @param array $in Raw input (repo, branch, token).
	 * @return array Stored config.
	 */
	public static function save_config( array $in ) {
		$old = self::config();

		$repo   = isset( $in['repo'] ) ? self::sanitize_repo( (string) wp_unslash( $in['repo'] ) ) : $old['repo'];
		$branch = isset( $in['branch'] ) ? self::sanitize_branch( (string) wp_unslash( $in['branch'] ) ) : $old['branch'];

		$token = isset( $in['token'] ) ? trim( (string) wp_unslash( $in['token'] ) ) : '';
		if ( '' === $token ) {
			$token = $old['token']; // Write-only: empty = keep.
		}
		$token = substr( preg_replace( '/[^A-Za-z0-9_.\-]/', '', $token ), 0, 255 );

		$cfg = array(
			'repo'   => $repo,
			'branch' => $branch,
			'token'  => $token,
		);

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $cfg, '', false ); // Autoload off — holds a secret.
		} else {
			update_option( self::OPTION, $cfg );
		}

		return $cfg;
	}

	/**
	 * Validate an "owner/name" repository slug.
	 *
	 * @param string $repo Raw input.
	 * @return string Valid slug or the default repository.
	 */
	public static function sanitize_repo( $repo ) {
		$repo = trim( (string) $repo );
		if ( preg_match( '#^([A-Za-z0-9_.\-]{1,100})/([A-Za-z0-9_.\-]{1,100})$#', $repo, $m ) ) {
			return $m[1] . '/' . $m[2];
		}
		return self::DEFAULT_REPO;
	}

	/**
	 * Validate a branch name (letters, digits, . _ - / but no traversal).
	 *
	 * @param string $branch Raw branch.
	 * @return string
	 */
	public static function sanitize_branch( $branch ) {
		$branch = trim( (string) $branch );
		if ( '' === $branch ) {
			return 'main';
		}
		$branch = preg_replace( '/[^A-Za-z0-9._\-\/]/', '', $branch );
		if ( false !== strpos( $branch, '..' ) || strlen( $branch ) > 100 ) {
			return 'main';
		}
		return $branch;
	}

	/* ---------------------------------------------------------------------
	 * Version check + connection test
	 * ------------------------------------------------------------------- */

	/**
	 * Latest available version for a repo/branch (cached in a transient).
	 *
	 * @param string $branch Branch; null = the stored one.
	 * @param bool   $force  Bypass the cache.
	 * @param string $repo   Repository; null = the stored one.
	 * @return string|WP_Error Version or error.
	 */
	public static function remote_version( $branch = null, $force = false, $repo = null ) {
		$cfg    = self::config();
		$repo   = null === $repo ? $cfg['repo'] : self::sanitize_repo( $repo );
		$branch = null === $branch ? $cfg['branch'] : self::sanitize_branch( $branch );
		$key    = 'aipc_git_v_' . md5( $repo . '|' . $branch );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$res = self::http_get(
			'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode( $branch ) . '/' . self::MAINFILE,
			30
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code || '' === $body ) {
			return self::http_error( $code, 'check' );
		}

		$version = self::parse_version( $body );
		if ( null === $version ) {
			return new WP_Error( 'aipc_git', __( 'The repository branch does not look like this plugin (no version header found).', 'wp-ai-post-creator' ) );
		}

		$ttl = (int) apply_filters( 'aipc_git_version_ttl', HOUR_IN_SECONDS, $repo, $branch );
		set_transient( $key, $version, max( 0, $ttl ) );

		return $version;
	}

	/**
	 * Test the repository/branch/token combination against GitHub.
	 *
	 * Empty values fall back to the stored configuration, so the button can
	 * test the saved setup as well as unsaved form input.
	 *
	 * @param string $repo   Repository slug.
	 * @param string $branch Branch.
	 * @param string $token  PAT (empty = the stored one).
	 * @return array|WP_Error { version } or error.
	 */
	public static function test_connection( $repo, $branch, $token = '' ) {
		$cfg    = self::config();
		$repo   = self::sanitize_repo( '' !== trim( (string) $repo ) ? $repo : $cfg['repo'] );
		$branch = self::sanitize_branch( '' !== trim( (string) $branch ) ? $branch : $cfg['branch'] );
		$token  = trim( (string) $token );
		if ( '' === $token ) {
			$token = $cfg['token'];
		}

		$res = self::http_get(
			'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode( $branch ) . '/' . self::MAINFILE,
			30,
			$token
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code || '' === $body ) {
			return self::http_error( $code, 'test' );
		}

		$version = self::parse_version( $body );
		if ( null === $version ) {
			return new WP_Error( 'aipc_git', __( 'The repository branch does not look like this plugin (no version header found).', 'wp-ai-post-creator' ) );
		}

		return array( 'version' => $version );
	}

	/**
	 * Drop the cached remote version for a repo/branch.
	 *
	 * @param string $repo   Repository; null = stored.
	 * @param string $branch Branch; null = stored.
	 * @return void
	 */
	public static function flush_cache( $repo = null, $branch = null ) {
		$cfg    = self::config();
		$repo   = null === $repo ? $cfg['repo'] : self::sanitize_repo( $repo );
		$branch = null === $branch ? $cfg['branch'] : self::sanitize_branch( $branch );
		delete_transient( 'aipc_git_v_' . md5( $repo . '|' . $branch ) );
	}

	/* ---------------------------------------------------------------------
	 * The update itself
	 * ------------------------------------------------------------------- */

	/**
	 * Run the full update: download → verify → backup → swap → verify.
	 *
	 * @param string $branch Branch override; null = the stored one.
	 * @param bool   $force  Allow reinstalling the same or an older version.
	 * @return array|WP_Error { ok, version, backup, message } or error.
	 */
	public static function run( $branch = null, $force = false ) {
		$cfg        = self::config();
		$repo       = $cfg['repo'];
		$branch     = null === $branch ? $cfg['branch'] : self::sanitize_branch( $branch );
		$plugin_dir = WP_PLUGIN_DIR . '/' . self::SLUG;
		$main_file  = $plugin_dir . '/' . self::MAINFILE;

		if ( ! is_dir( $plugin_dir ) || ! is_readable( $main_file ) ) {
			return new WP_Error( 'aipc_git', __( 'The installed plugin folder was not found.', 'wp-ai-post-creator' ) );
		}

		if ( ! wp_is_writable( WP_PLUGIN_DIR ) || ! wp_is_writable( $plugin_dir ) ) {
			return new WP_Error( 'aipc_git', __( 'The plugins folder is not writable — update by FTP instead.', 'wp-ai-post-creator' ) );
		}

		$installed = AIPC_VERSION;
		$remote    = self::remote_version( $branch, true, $repo );

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( ! $force && version_compare( $remote, $installed, '<=' ) ) {
			return new WP_Error(
				'aipc_git',
				sprintf(
					/* translators: 1: branch, 2: remote version, 3: installed version. */
					__( 'The branch “%1$s” holds version %2$s, which is not newer than the installed %3$s. Tick “Reinstall anyway” to force it.', 'wp-ai-post-creator' ),
					$branch,
					$remote,
					$installed
				)
			);
		}

		// Same filesystem as the plugins dir → atomic renames, no cross-device moves.
		$work = WP_CONTENT_DIR . '/aipc-git-tmp-' . time() . '-' . wp_rand( 100, 999 );
		if ( ! wp_mkdir_p( $work ) ) {
			return new WP_Error( 'aipc_git', __( 'Could not create a temporary folder for the update.', 'wp-ai-post-creator' ) );
		}

		$zip_path = $work . '/package.zip';

		try {
			self::download_zip( $repo, $branch, $cfg['token'], $zip_path );

			$extract_to = $work . '/extracted';
			if ( ! wp_mkdir_p( $extract_to ) || ! self::extract_zip( $zip_path, $extract_to ) ) {
				throw new Exception( __( 'Could not extract the update package.', 'wp-ai-post-creator' ) );
			}

			$src = self::find_package_dir( $extract_to );
			if ( is_wp_error( $src ) ) {
				throw new Exception( $src->get_error_message() );
			}

			$new_version = self::parse_version( (string) file_get_contents( $src . '/' . self::MAINFILE ) );
			if ( null === $new_version ) {
				throw new Exception( __( 'The downloaded package does not contain a valid plugin header.', 'wp-ai-post-creator' ) );
			}

			// Back up the current files OUTSIDE the plugins dir (never shows as a duplicate plugin).
			$backup_root = WP_CONTENT_DIR . '/aipc-backups';
			if ( ! wp_mkdir_p( $backup_root ) ) {
				throw new Exception( __( 'Could not create the backup folder.', 'wp-ai-post-creator' ) );
			}
			$backup = $backup_root . '/' . self::SLUG . '-' . gmdate( 'Ymd-His' );

			clearstatcache();
			if ( ! @rename( $plugin_dir, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Exception( __( 'Could not move the current plugin folder to the backup location.', 'wp-ai-post-creator' ) );
			}

			// Swap the new files in; fall back to a copy when rename is impossible.
			clearstatcache();
			if ( ! @rename( $src, $plugin_dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! self::rcopy( $src, $plugin_dir ) ) {
					@rename( $backup, $plugin_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					throw new Exception( __( 'Moving the new files into place failed — the previous version was restored.', 'wp-ai-post-creator' ) );
				}
			}

			clearstatcache();
			if ( ! is_readable( $plugin_dir . '/' . self::MAINFILE ) ) {
				@rename( $backup, $plugin_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Exception( __( 'Verification of the new files failed — the previous version was restored.', 'wp-ai-post-creator' ) );
			}

			self::prune_backups( $backup_root );
			self::rrmdir( $work );
			self::flush_cache( $repo, $branch );
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache();
			}

			return array(
				'ok'      => true,
				'version' => $new_version,
				'backup'  => $backup,
				'message' => sprintf(
					/* translators: %s: new version number. */
					__( 'Updated successfully to %s.', 'wp-ai-post-creator' ),
					$new_version
				),
			);
		} catch ( Exception $e ) {
			self::rrmdir( $work );
			return new WP_Error( 'aipc_git', $e->getMessage() );
		}
	}

	/**
	 * Latest known backup folder.
	 *
	 * @return string Path or '' when none exists.
	 */
	public static function last_backup() {
		$root = WP_CONTENT_DIR . '/aipc-backups';
		if ( ! is_dir( $root ) ) {
			return '';
		}
		$dirs = glob( $root . '/' . self::SLUG . '-*', GLOB_ONLYDIR );
		return $dirs ? (string) end( $dirs ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Authenticated GET (adds the PAT header when a token is given).
	 *
	 * @param string $url     URL.
	 * @param int    $timeout Timeout seconds.
	 * @param string $token   Optional PAT; null = the stored one.
	 * @return array|WP_Error Response.
	 */
	private static function http_get( $url, $timeout, $token = null ) {
		$args = array( 'timeout' => $timeout, 'redirection' => 3 );
		if ( null === $token ) {
			$token = self::config()['token'];
		}
		if ( '' !== $token ) {
			$args['headers'] = array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/vnd.github+json',
			);
		}
		$res = wp_safe_remote_get( $url, apply_filters( 'aipc_git_request_args', $args, $url ) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res;
	}

	/**
	 * Map a GitHub HTTP status to a human-readable error.
	 *
	 * @param int    $code HTTP status.
	 * @param string $mode 'check' or 'test' (for the message wording).
	 * @return WP_Error
	 */
	private static function http_error( $code, $mode ) {
		if ( 404 === $code ) {
			return new WP_Error(
				'aipc_git',
				__( 'The repository or branch was not found (HTTP 404). Check the owner/name and branch — or the token for private repositories.', 'wp-ai-post-creator' )
			);
		}
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'aipc_git',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The token was rejected (HTTP %d).', 'wp-ai-post-creator' ),
					$code
				)
			);
		}
		return new WP_Error(
			'aipc_git',
			sprintf(
				/* translators: %d: HTTP status code. */
				'check' === $mode
					? __( 'GitHub returned HTTP %d while checking the version.', 'wp-ai-post-creator' )
					: __( 'GitHub returned HTTP %d while testing the connection.', 'wp-ai-post-creator' ),
				$code
			)
		);
	}

	/**
	 * Parse the plugin version out of a main-file source.
	 *
	 * @param string $source File contents.
	 * @return string|null
	 */
	private static function parse_version( $source ) {
		if ( preg_match( '/\* Version:\s*([0-9][0-9A-Za-z.\-]*)/i', $source, $m ) ) {
			return $m[1];
		}
		if ( preg_match( "/define\(\s*'AIPC_VERSION',\s*'([^']+)'/", $source, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Download the repository snapshot.
	 *
	 * Without a token: the public codeload zipball URL.
	 * With a token: the api.github.com zipball endpoint (documented PAT auth,
	 * also works for private repositories).
	 *
	 * @param string $repo    Repository.
	 * @param string $branch  Branch.
	 * @param string $token   PAT ('' = anonymous).
	 * @param string $to_path Destination file.
	 * @return void
	 * @throws Exception On download errors.
	 */
	private static function download_zip( $repo, $branch, $token, $to_path ) {
		if ( '' !== $token ) {
			$url = 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode( $branch );
		} else {
			$url = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . $branch;
		}

		$res = self::http_get( $url, 300, '' !== $token ? $token : null );

		if ( is_wp_error( $res ) ) {
			throw new Exception( $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code || strlen( $body ) < 100 || 0 !== strpos( $body, 'PK' ) ) {
			throw new Exception(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The download from GitHub failed (HTTP %d or invalid archive).', 'wp-ai-post-creator' ),
					$code
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === file_put_contents( $to_path, $body ) ) {
			throw new Exception( __( 'Could not save the downloaded package.', 'wp-ai-post-creator' ) );
		}
	}

	/**
	 * Extract a zip archive (ZipArchive when available, PclZip otherwise).
	 *
	 * @param string $zip Archive path.
	 * @param string $to  Destination dir.
	 * @return bool
	 */
	private static function extract_zip( $zip, $to ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$z = new ZipArchive();
			if ( true === $z->open( $zip ) ) {
				$ok = $z->extractTo( $to );
				$z->close();
				if ( $ok ) {
					return true;
				}
			}
		}

		if ( ! class_exists( 'PclZip' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
		$archive = new PclZip( $zip );
		$result  = $archive->extract( PCLZIP_OPT_PATH, $to );
		return is_array( $result ) && ! empty( $result );
	}

	/**
	 * Locate the extracted package directory (zipballs wrap a single folder).
	 *
	 * @param string $dir Extraction dir.
	 * @return string|WP_Error
	 */
	private static function find_package_dir( $dir ) {
		$dirs = glob( $dir . '/*', GLOB_ONLYDIR );
		if ( ! $dirs || ! is_readable( $dirs[0] . '/' . self::MAINFILE ) ) {
			return new WP_Error( 'aipc_git', __( 'The downloaded package is not a valid plugin archive.', 'wp-ai-post-creator' ) );
		}
		return $dirs[0];
	}

	/**
	 * Keep only the newest two backups.
	 *
	 * @param string $root Backups root.
	 * @return void
	 */
	private static function prune_backups( $root ) {
		$dirs = glob( $root . '/' . self::SLUG . '-*', GLOB_ONLYDIR );
		if ( ! $dirs || count( $dirs ) <= 2 ) {
			return;
		}
		sort( $dirs );
		foreach ( array_slice( $dirs, 0, count( $dirs ) - 2 ) as $old ) {
			self::rrmdir( $old );
		}
	}

	/**
	 * Recursive copy.
	 *
	 * @param string $src Source dir.
	 * @param string $dst Destination dir.
	 * @return bool
	 */
	private static function rcopy( $src, $dst ) {
		if ( ! wp_mkdir_p( $dst ) ) {
			return false;
		}
		$items = @scandir( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$from = $src . '/' . $item;
			$to   = $dst . '/' . $item;
			if ( is_dir( $from ) ) {
				if ( ! self::rcopy( $from, $to ) ) {
					return false;
				}
			} elseif ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false;
			}
		}
		return true;
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $dir Dir.
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_dir( $path ) ) {
					self::rrmdir( $path );
				} else {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
