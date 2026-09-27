<?php
/**
 * Cron scheduler: automatic post generation at configured times.
 *
 * Each schedule entry owns a local time (HH:MM), weekdays, an optional fixed
 * topic (empty = invented from the site prompt) and the run options (tone,
 * length, language, image/FAQ/TOC). A 15-minute cron tick fires the earliest
 * due entry — one job per tick — and resumes unfinished automatic jobs if a
 * previous tick was interrupted.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Scheduler {

	const OPTION    = 'aipc_schedule';
	const CRON_HOOK = 'aipc_cron_tick';

	/**
	 * Cron tick length in seconds.
	 */
	const TICK = 900;

	/**
	 * Hard budget (seconds) for one tick's step loop.
	 */
	const BUDGET = 600;

	/**
	 * Hook the cron filters/actions.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Register the 15-minute interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['aipc_quarter_hour'] = array(
			'interval' => self::TICK,
			'display'  => __( 'Every 15 minutes (AI Post Creator)', 'wp-ai-post-creator' ),
		);
		return $schedules;
	}

	/**
	 * Self-heal the recurring tick event.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'aipc_quarter_hour', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the tick event (deactivation/uninstall).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Full config (entries + last-fired state).
	 *
	 * @return array
	 */
	public static function config() {
		$cfg = get_option( self::OPTION, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return wp_parse_args( $cfg, array(
			'entries' => array(),
			'state'   => array(),
		) );
	}

	/**
	 * Persist the config.
	 *
	 * @param array $cfg Config.
	 * @return void
	 */
	private static function persist( $cfg ) {
		update_option( self::OPTION, $cfg, false );
	}

	/**
	 * All schedule entries.
	 *
	 * @return array
	 */
	public static function entries() {
		return self::config()['entries'];
	}

	/**
	 * One entry by id.
	 *
	 * @param string $id Entry id.
	 * @return array|null
	 */
	public static function get_entry( $id ) {
		foreach ( self::entries() as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === (string) $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Sanitize one entry.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	public static function sanitize_entry( $in ) {
		$in   = is_array( $in ) ? $in : array();
		$s    = AIPC_Settings::all();
		$opts = isset( $in['opts'] ) && is_array( $in['opts'] ) ? $in['opts'] : array();

		$time = isset( $in['time'] ) ? trim( (string) $in['time'] ) : '';
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = '09:00';
		}
		$parts   = explode( ':', $time );
		$time    = sprintf( '%02d:%02d', (int) $parts[0], (int) $parts[1] );

		$days = array();
		if ( isset( $in['days'] ) && is_array( $in['days'] ) ) {
			foreach ( $in['days'] as $day ) {
				$day = absint( $day );
				if ( $day >= 0 && $day <= 6 ) {
					$days[] = $day;
				}
			}
		}
		$days = array_values( array_unique( $days ) );
		if ( empty( $days ) ) {
			$days = array( 0, 1, 2, 3, 4, 5, 6 );
		}
		sort( $days );

		$tone = isset( $opts['tone'] ) ? sanitize_key( $opts['tone'] ) : '';
		if ( ! array_key_exists( $tone, AIPC_Settings::tones() ) ) {
			$tone = $s['default_tone'];
		}

		$length = isset( $opts['length'] ) ? sanitize_key( $opts['length'] ) : '';
		if ( ! array_key_exists( $length, AIPC_Settings::lengths() ) ) {
			$length = $s['default_length'];
		}

		$language = isset( $opts['language'] ) ? sanitize_key( $opts['language'] ) : '';
		if ( '' === $language || ! array_key_exists( $language, AIPC_Settings::languages() ) ) {
			$language = $s['content_language'];
		}

		return array(
			'id'      => isset( $in['id'] ) ? sanitize_key( $in['id'] ) : '',
			'time'    => $time,
			'days'    => $days,
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'topic'   => mb_substr( sanitize_text_field( isset( $in['topic'] ) ? $in['topic'] : '' ), 0, 400 ),
			'opts'    => array(
				'tone'     => $tone,
				'length'   => $length,
				'language' => $language,
				'image'    => empty( $opts['image'] ) ? 0 : 1,
				'faq'      => empty( $opts['faq'] ) ? 0 : 1,
				'toc'      => empty( $opts['toc'] ) ? 0 : 1,
			),
		);
	}

	/**
	 * Insert or update an entry.
	 *
	 * @param array $in Raw entry input.
	 * @return array The saved entry.
	 */
	public static function save_entry( $in ) {
		$cfg   = self::config();
		$entry = self::sanitize_entry( $in );

		if ( '' === $entry['id'] ) {
			$entry['id'] = 'sch_' . strtolower( wp_generate_password( 8, false, false ) );
		}

		$found = false;
		foreach ( $cfg['entries'] as $i => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $entry['id'] ) {
				$cfg['entries'][ $i ] = $entry;
				$found                = true;
				break;
			}
		}
		if ( ! $found ) {
			$cfg['entries'][] = $entry;
		}

		self::persist( $cfg );
		return $entry;
	}

	/**
	 * Delete an entry.
	 *
	 * @param string $id Entry id.
	 * @return bool
	 */
	public static function delete_entry( $id ) {
		$cfg    = self::config();
		$kept   = array();
		$found  = false;

		foreach ( $cfg['entries'] as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === (string) $id ) {
				$found = true;
				continue;
			}
			$kept[] = $entry;
		}
		if ( ! $found ) {
			return false;
		}

		$cfg['entries'] = $kept;
		if ( isset( $cfg['state'][ $id ] ) ) {
			unset( $cfg['state'][ $id ] );
		}
		self::persist( $cfg );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Due logic
	 * ------------------------------------------------------------------- */

	/**
	 * Local (site timezone) timestamp of an entry's slot today.
	 *
	 * @param array $entry Entry.
	 * @param int   $now   Local now.
	 * @return int
	 */
	private static function slot_ts( $entry, $now ) {
		$h = (int) wp_date( 'H', $now );
		$i = (int) wp_date( 'i', $now );
		$s = (int) wp_date( 's', $now );
		$midnight = $now - ( $h * 3600 + $i * 60 + $s );

		$parts = explode( ':', $entry['time'] );
		return $midnight + (int) $parts[0] * 3600 + (int) $parts[1] * 60;
	}

	/**
	 * Whether an entry is due to fire now (same-day catch-up, once per day).
	 *
	 * @param array     $entry Entry.
	 * @param int|null  $now   Local timestamp (default: now).
	 * @param array|null $state Last-fired state (default: stored).
	 * @return bool
	 */
	public static function entry_due( $entry, $now = null, $state = null ) {
		if ( empty( $entry['enabled'] ) ) {
			return false;
		}
		$now   = $now ? $now : current_time( 'timestamp' );
		$state = null === $state ? self::config()['state'] : $state;

		$today = wp_date( 'Y-m-d', $now );
		$w     = (int) wp_date( 'w', $now );

		if ( ! in_array( $w, (array) $entry['days'], true ) ) {
			return false;
		}
		if ( isset( $state[ $entry['id'] ] ) && $state[ $entry['id'] ] === $today ) {
			return false;
		}
		return $now >= self::slot_ts( $entry, $now );
	}

	/* ---------------------------------------------------------------------
	 * The tick
	 * ------------------------------------------------------------------- */

	/**
	 * Cron callback: resume/ retry an unfinished automatic job, else fire the
	 * earliest due schedule entry (one job per tick).
	 *
	 * @return void
	 */
	public static function tick() {
		$cfg = self::config();
		if ( empty( $cfg['entries'] ) ) {
			return;
		}

		$agent = AIPC_Agent::instance();

		// 1. Resume or retry an unfinished automatic job first.
		foreach ( array_reverse( $agent->get_all_jobs() ) as $job ) {
			if ( 'cron' !== ( isset( $job['source'] ) ? $job['source'] : 'manual' ) ) {
				continue;
			}
			if ( 'running' === $job['status'] ) {
				self::run_steps( $job['id'] );
				return;
			}
			if ( 'error' === $job['status'] ) {
				$retries = isset( $cfg['state']['retries'][ $job['id'] ] ) ? (int) $cfg['state']['retries'][ $job['id'] ] : 0;
				if ( $retries < 3 && ( time() - (int) $job['updated'] ) < DAY_IN_SECONDS ) {
					$cfg['state']['retries'][ $job['id'] ] = $retries + 1;
					self::persist( $cfg );
					$agent->retry_job( $job['id'] );
					self::run_steps( $job['id'] );
				}
				return;
			}
		}

		// 2. Fire the earliest due entry.
		$now = current_time( 'timestamp' );
		$due = null;
		foreach ( $cfg['entries'] as $entry ) {
			if ( self::entry_due( $entry, $now, $cfg['state'] ) ) {
				if ( ! $due || self::slot_ts( $entry, $now ) < self::slot_ts( $due, $now ) ) {
					$due = $entry;
				}
			}
		}
		if ( ! $due ) {
			return;
		}

		$cfg['state'][ $due['id'] ] = wp_date( 'Y-m-d', $now );
		self::persist( $cfg );

		$job = self::start_job_for_entry( $due['id'], 'cron' );
		if ( is_wp_error( $job ) ) {
			return;
		}
		self::run_steps( $job['id'] );
	}

	/**
	 * Create a job for a schedule entry.
	 *
	 * @param string $entry_id Entry id.
	 * @param string $source   Job source (cron|manual).
	 * @return array|WP_Error
	 */
	public static function start_job_for_entry( $entry_id, $source = 'cron' ) {
		$entry = self::get_entry( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'aipc_schedule', __( 'Schedule entry not found.', 'wp-ai-post-creator' ) );
		}
		return AIPC_Agent::instance()->create_job( $entry['topic'], $entry['opts'], $source );
	}

	/**
	 * Drive a job's remaining steps to completion (within the time budget).
	 *
	 * @param string $job_id Job id.
	 * @return array|WP_Error Last client state.
	 */
	public static function run_steps( $job_id ) {
		$agent = AIPC_Agent::instance();
		$t0    = time();
		$guard = 0;

		while ( $guard++ < 80 ) {
			$state = $agent->execute_step( $job_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( ! isset( $state['status'] ) || 'running' !== $state['status'] ) {
				return $state;
			}
			if ( ! empty( $state['busy'] ) ) {
				sleep( 2 );
				continue;
			}
			if ( time() - $t0 > self::BUDGET ) {
				return $state; // Resumed by the next tick.
			}
		}
		return $state;
	}

	/**
	 * Weekday labels (WordPress locale order, Sunday = 0).
	 *
	 * @return array
	 */
	public static function day_labels() {
		$labels = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$labels[ $d ] = $GLOBALS['wp_locale']->get_weekday( $d );
		}
		return $labels;
	}
}
