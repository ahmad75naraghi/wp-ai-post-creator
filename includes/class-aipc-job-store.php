<?php
/**
 * Job storage: a dedicated database table (v1.6) with automatic migration
 * from the pre-1.6 `aipc_jobs` option, plus a legacy fallback.
 *
 * Table: {$wpdb->prefix}aipc_jobs
 *   id, created, updated, status, mode, source, post_id  — queryable columns
 *   topic, steps_total, steps_done, calls, prompt_tokens, completion_tokens
 *   payload — the full job array as JSON (the source of truth)
 *
 * When the table cannot be created (e.g. a DB user without CREATE rights) the
 * store transparently falls back to the old option-based storage, so the
 * plugin keeps working exactly like 1.5.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Job_Store {

	const TABLE          = 'aipc_jobs';
	const SCHEMA_OPTION  = 'aipc_schema_version';
	const SCHEMA_VERSION = '1';
	const LEGACY_OPTION  = 'aipc_jobs'; // Pre-1.6 storage.
	const LIST_LIMIT     = 200;

	/**
	 * Table availability, cached per request.
	 *
	 * @var bool|null
	 */
	private static $available = null;

	/**
	 * The full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------- */

	/**
	 * Create/upgrade the table on plugin boot. Runs at most once per version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$version = get_option( self::SCHEMA_OPTION, '' );
		if ( self::SCHEMA_VERSION === $version ) {
			return;
		}

		self::ensure_table();

		if ( '' === $version ) {
			self::migrate_legacy_option();
		}
		// Future schema changes: if ( version_compare( $version, '2', '<' ) ) { … }

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Create the table when missing (idempotent).
	 *
	 * @return void
	 */
	public static function ensure_table() {
		global $wpdb;
		$table   = self::table();

		// Already created? Nothing to do — future versions migrate columns
		// explicitly in maybe_upgrade(), so dbDelta never sees an existing
		// table (its type-diff ALTERs fail silently on SQLite drop-ins).
		if ( self::table_exists() ) {
			return;
		}

		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id VARCHAR(40) NOT NULL,
			created BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT '',
			mode VARCHAR(10) NOT NULL DEFAULT '',
			source VARCHAR(10) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			steps_total INT UNSIGNED NOT NULL DEFAULT 0,
			steps_done INT UNSIGNED NOT NULL DEFAULT 0,
			calls INT UNSIGNED NOT NULL DEFAULT 0,
			prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			topic LONGTEXT NULL,
			payload LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created (created),
			KEY source (source)
		) {$charset};";

		// dbDelta is the canonical cross-DB path (MySQL + SQLite drop-ins).
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		// Verify (dbDelta may silently fail on exotic setups); retry raw.
		if ( ! self::table_exists() ) {
			$raw = preg_replace( '/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', trim( $sql ), 1 );
			$wpdb->query( $raw ); // phpcs:ignore WordPress.DB.PreparedSQL
			self::$available = null;
		}
	}

	/**
	 * Whether the table exists and is queryable (cached per request).
	 *
	 * @return bool
	 */
	public static function available() {
		// Checked on every call (cheap): the filter can be toggled within a
		// request, e.g. by tests or per-request site policies.
		if ( false === apply_filters( 'aipc_jobs_table_enabled', true ) ) {
			return false;
		}
		if ( null === self::$available ) {
			self::$available = self::table_exists();
		}
		return self::$available;
	}

	/**
	 * Raw table-existence check (SHOW TABLES LIKE — reliable on MySQL and SQLite drop-ins alike; a SELECT on a missing table returns int(1) on some SQLite drop-ins).
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		$prev = $wpdb->hide_errors();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
		if ( $prev ) {
			$wpdb->show_errors();
		}
		return $found === self::table();
	}

	/**
	 * Move jobs from the pre-1.6 option into the table, then drop the option.
	 *
	 * @return void
	 */
	private static function migrate_legacy_option() {
		$legacy = get_option( self::LEGACY_OPTION, null );
		if ( null === $legacy || ! is_array( $legacy ) ) {
			return; // Never used or already migrated.
		}

		// Without the table, save() would write back into the option we are
		// about to remove — keep the option untouched instead.
		if ( ! self::available() ) {
			return;
		}

		$migrated = 0;
		$valid    = 0;
		foreach ( $legacy as $job ) {
			if ( ! is_array( $job ) || empty( $job['id'] ) ) {
				continue;
			}
			$valid++;
			if ( self::save( $job ) ) {
				$migrated++;
			}
		}

		// Drop the option only when every row made it into the table.
		if ( $migrated === $valid ) {
			delete_option( self::LEGACY_OPTION );
		}
	}

	/* ---------------------------------------------------------------------
	 * CRUD
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch one full job (payload included).
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return null;
		}
		if ( ! self::available() ) {
			return self::legacy_get( $id );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT payload FROM ' . self::table() . ' WHERE id = %s', $id ), ARRAY_A );
		if ( ! $row || '' === (string) $row['payload'] ) {
			return null;
		}
		$job = json_decode( (string) $row['payload'], true );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Insert or update a job.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	public static function save( array $job ) {
		if ( empty( $job['id'] ) ) {
			return false;
		}
		if ( ! self::available() ) {
			return self::legacy_save( $job );
		}
		global $wpdb;
		$table = self::table();

		$steps_total = 0;
		$steps_done  = 0;
		if ( isset( $job['steps'] ) && is_array( $job['steps'] ) ) {
			$steps_total = count( $job['steps'] );
			foreach ( $job['steps'] as $step ) {
				if ( isset( $step['status'] ) && in_array( $step['status'], array( 'done', 'skipped' ), true ) ) {
					$steps_done++;
				}
			}
		}

		$row = array(
			'id'                => (string) $job['id'],
			'created'           => isset( $job['created'] ) ? (int) $job['created'] : time(),
			'updated'           => isset( $job['updated'] ) ? (int) $job['updated'] : time(),
			'status'            => isset( $job['status'] ) ? (string) $job['status'] : '',
			'mode'              => isset( $job['mode'] ) ? (string) $job['mode'] : 'new',
			'source'            => isset( $job['source'] ) ? (string) $job['source'] : 'manual',
			'post_id'           => isset( $job['post_id'] ) ? (int) $job['post_id'] : 0,
			'steps_total'       => $steps_total,
			'steps_done'        => $steps_done,
			'calls'             => isset( $job['usage']['calls'] ) ? (int) $job['usage']['calls'] : 0,
			'prompt_tokens'     => isset( $job['usage']['prompt'] ) ? (int) $job['usage']['prompt'] : 0,
			'completion_tokens' => isset( $job['usage']['completion'] ) ? (int) $job['usage']['completion'] : 0,
			'topic'             => isset( $job['topic'] ) ? (string) $job['topic'] : '',
			'payload'           => wp_json_encode( $job ),
		);
		$format = array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %s", $job['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'id' => $row['id'] ), $format, array( '%s' ) );
		} else {
			$wpdb->insert( $table, $row, $format );
		}
		return true;
	}

	/**
	 * Light rows (no payload), newest first — for lists and counters.
	 *
	 * @param int $limit  Maximum rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public static function all( $limit = self::LIST_LIMIT, $offset = 0 ) {
		if ( ! self::available() ) {
			return self::legacy_all();
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, created, updated, status, mode, source, post_id, steps_total, steps_done, calls, prompt_tokens, completion_tokens, topic FROM {$table} ORDER BY created DESC, id DESC LIMIT %d OFFSET %d", absint( $limit ), absint( $offset ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( __CLASS__, 'cast_row' ), $rows ) : array();
	}

	/**
	 * Full jobs created at/after a timestamp (oldest first).
	 *
	 * @param int $ts Unix timestamp.
	 * @return array[]
	 */
	public static function since( $ts ) {
		if ( ! self::available() ) {
			$jobs = array();
			foreach ( self::legacy_all() as $job ) {
				if ( (int) $job['created'] >= (int) $ts ) {
					$jobs[] = $job;
				}
			}
			return $jobs;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE created >= %d ORDER BY created ASC", (int) $ts ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$jobs = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$job = json_decode( (string) $row['payload'], true );
				if ( is_array( $job ) ) {
					$jobs[] = $job;
				}
			}
		}
		return $jobs;
	}

	/**
	 * Count jobs by source created at/after a timestamp.
	 *
	 * @param string $source Source (cron|manual|'').
	 * @param int    $ts     Unix timestamp.
	 * @return int
	 */
	public static function count_since( $source, $ts ) {
		if ( ! self::available() ) {
			$count = 0;
			foreach ( self::legacy_all() as $job ) {
				if ( ( '' === $source || $source === ( isset( $job['source'] ) ? $job['source'] : 'manual' ) ) && (int) $job['created'] >= (int) $ts ) {
					$count++;
				}
			}
			return $count;
		}
		global $wpdb;
		$table = self::table();
		if ( '' === $source ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created >= %d", (int) $ts ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND created >= %d", $source, (int) $ts ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Unfinished (running/error) cron jobs, oldest first — for the scheduler tick.
	 *
	 * @return array[] Rows with id, status, updated.
	 */
	public static function unfinished_cron() {
		if ( ! self::available() ) {
			$out = array();
			foreach ( array_reverse( self::legacy_all() ) as $job ) {
				if ( 'cron' === ( isset( $job['source'] ) ? $job['source'] : 'manual' )
					&& in_array( $job['status'], array( 'running', 'error' ), true ) ) {
					$out[] = array(
						'id'      => $job['id'],
						'status'  => $job['status'],
						'updated' => isset( $job['updated'] ) ? (int) $job['updated'] : 0,
					);
				}
			}
			return $out;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, status, updated FROM {$table} WHERE source = 'cron' AND status IN ('running','error') ORDER BY created ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[] = array(
					'id'      => (string) $row['id'],
					'status'  => (string) $row['status'],
					'updated' => (int) $row['updated'],
				);
			}
		}
		return $out;
	}

	/**
	 * Ids of every running job (any source) — runner re-arm safety net.
	 *
	 * @return string[]
	 */
	public static function running_ids() {
		if ( ! self::available() ) {
			$out = array();
			foreach ( self::legacy_all() as $job ) {
				if ( 'running' === ( isset( $job['status'] ) ? $job['status'] : '' ) ) {
					$out[] = (string) $job['id'];
				}
			}
			return $out;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'running'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Delete one job.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return false;
		}
		if ( ! self::available() ) {
			return self::legacy_delete( $id );
		}
		global $wpdb;
		$table = self::table();
		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Delete every job.
	 *
	 * @return void
	 */
	public static function clear() {
		if ( ! self::available() ) {
			update_option( self::LEGACY_OPTION, array(), false );
			return;
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Delete jobs older than the retention window (daily cron).
	 *
	 * @return void
	 */
	public static function prune() {
		if ( ! self::available() ) {
			return; // The legacy backend keeps its own 30-job cap on save.
		}
		$days = (int) apply_filters( 'aipc_job_retention_days', 90 );
		if ( $days <= 0 ) {
			return; // 0 = keep forever.
		}
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created < %d", time() - $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Cast a raw row to int where appropriate.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function cast_row( array $row ) {
		foreach ( array( 'created', 'updated', 'post_id', 'steps_total', 'steps_done', 'calls', 'prompt_tokens', 'completion_tokens' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Legacy option backend (used when the table is unavailable)
	 * ------------------------------------------------------------------- */

	/**
	 * Legacy: all jobs from the option.
	 *
	 * @return array
	 */
	private static function legacy_all() {
		$jobs = get_option( self::LEGACY_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		uasort( $jobs, function ( $a, $b ) {
			return (int) $b['created'] - (int) $a['created'];
		} );
		return $jobs;
	}

	/**
	 * Legacy: fetch one job.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	private static function legacy_get( $id ) {
		$jobs = get_option( self::LEGACY_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		return isset( $jobs[ $id ] ) && is_array( $jobs[ $id ] ) ? $jobs[ $id ] : null;
	}

	/**
	 * Legacy: persist one job (with the 1.5 job cap).
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function legacy_save( array $job ) {
		$jobs           = get_option( self::LEGACY_OPTION, array() );
		$jobs           = is_array( $jobs ) ? $jobs : array();
		$jobs[ $job['id'] ] = $job;

		// Keep the pre-1.6 limits: last 30, younger than 24h.
		uasort( $jobs, function ( $a, $b ) {
			return (int) $b['created'] - (int) $a['created'];
		} );
		$keep = array();
		$i    = 0;
		foreach ( $jobs as $id => $j ) {
			$i++;
			if ( $i <= 30 && ( time() - (int) $j['created'] ) <= DAY_IN_SECONDS ) {
				$keep[ $id ] = $j;
			}
		}
		update_option( self::LEGACY_OPTION, $keep, false );
		return true;
	}

	/**
	 * Legacy: delete one job.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	private static function legacy_delete( $id ) {
		$jobs = get_option( self::LEGACY_OPTION, array() );
		$jobs = is_array( $jobs ) ? $jobs : array();
		if ( ! isset( $jobs[ $id ] ) ) {
			return false;
		}
		unset( $jobs[ $id ] );
		update_option( self::LEGACY_OPTION, $jobs, false );
		return true;
	}
}
