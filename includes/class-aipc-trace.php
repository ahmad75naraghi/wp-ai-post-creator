<?php
/**
 * Full API trace log (v1.11.0).
 *
 * When enabled (Settings → Advanced) every request and response
 * exchanged with the AI providers is appended as a JSON line to a
 * protected file under wp-content/uploads/aipc-logs/ — prompts, model
 * output, full error bodies, HTTP status and timing, plus the job/step
 * context. API keys are redacted and long base64 blobs (images) are
 * collapsed, so the file stays readable and safe to share.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSONL trace writer with context + redaction.
 */
class AIPC_Trace {

	/**
	 * Option storing the random log file name (unguessable URL).
	 */
	const OPT_FILE = 'aipc_trace_file';

	/**
	 * Rotate when the live file exceeds this size (one old file is kept).
	 */
	const MAX_BYTES = 8388608; // 8 MB.

	/**
	 * Current job/step context merged into every entry.
	 *
	 * @var array
	 */
	protected static $context = array();

	/**
	 * Is tracing enabled in the settings?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) AIPC_Settings::get( 'debug_log' );
	}

	/**
	 * Set the context (job id, step, …) attached to subsequent entries.
	 *
	 * @param array $ctx Context values.
	 * @return void
	 */
	public static function set_context( $ctx ) {
		self::$context = is_array( $ctx ) ? $ctx : array();
	}

	/**
	 * Clear the context.
	 *
	 * @return void
	 */
	public static function clear_context() {
		self::$context = array();
	}

	/**
	 * Log directory (protected against direct access).
	 *
	 * @return string
	 */
	public static function dir() {
		return WP_CONTENT_DIR . '/uploads/aipc-logs';
	}

	/**
	 * Full path of the live trace file.
	 *
	 * @return string
	 */
	public static function path() {
		$name = get_option( self::OPT_FILE, '' );
		if ( ! is_string( $name ) || '' === $name ) {
			$name = 'aipc-trace-' . strtolower( wp_generate_password( 12, false, false ) ) . '.log';
			update_option( self::OPT_FILE, $name, false );
		}
		return self::dir() . '/' . $name;
	}

	/**
	 * Combined size of the live + rotated file, in bytes.
	 *
	 * @return int
	 */
	public static function size() {
		$path = self::path();
		$size = file_exists( $path ) ? (int) filesize( $path ) : 0;
		if ( file_exists( $path . '.1' ) ) {
			$size += (int) filesize( $path . '.1' );
		}
		return $size;
	}

	/**
	 * Delete the trace files.
	 *
	 * @return void
	 */
	public static function clear() {
		$path = self::path();
		if ( file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( file_exists( $path . '.1' ) ) {
			@unlink( $path . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Append one entry (no-op while tracing is disabled).
	 *
	 * @param string $event Entry type (e.g. 'api_call', 'download').
	 * @param array  $data  Entry payload.
	 * @return void
	 */
	public static function log( $event, array $data = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$entry = array_merge(
			array(
				'time'  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'event' => (string) $event,
			),
			self::$context,
			$data
		);
		$entry = self::redact( $entry );

		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $line ) {
			return;
		}

		self::ensure_dir();
		$path = self::path();
		if ( file_exists( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			@rename( $path, $path . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged — keeps one older generation.
		}
		@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Recursively strip secrets and collapse huge base64 blobs.
	 *
	 * @param mixed $value Entry or fragment.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && preg_match( '/^(api[_-]?key|authorization|password|secret|token)$/i', $k ) ) {
					$out[ $k ] = '***';
					continue;
				}
				$out[ $k ] = self::redact( $v );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			// Bearer tokens that slipped into error messages.
			$value = preg_replace( '/Bearer\s+[A-Za-z0-9._\-]{8,}/', 'Bearer ***', $value );
			// Long base64 runs (images) → placeholder with the length.
			$value = preg_replace_callback(
				'/[A-Za-z0-9+\/]{300,}={0,2}/',
				function ( $m ) {
					return '[base64 omitted: ' . strlen( $m[0] ) . ' chars]';
				},
				$value
			);
			// Keep single fields readable.
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > 20000 ) {
				$value = mb_substr( $value, 0, 20000 ) . '…[truncated]';
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Create the protected directory on first use.
	 *
	 * @return void
	 */
	private static function ensure_dir() {
		$dir = self::dir();
		if ( is_dir( $dir ) && file_exists( $dir . '/.htaccess' ) ) {
			return;
		}
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
