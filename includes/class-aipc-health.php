<?php
/**
 * Connection health / circuit breaker (1.22.0).
 *
 * Every provider attempt reports its outcome here. A connection that
 * fails `aipc_health_streak` times in a row (default 5) is put on a
 * cooldown (`aipc_health_cooldown`, default 30 minutes): it is not
 * removed from the chain, only moved to the end, so healthy
 * connections are tried first and articles/images finish faster.
 * Daily ok/fail counters feed the connections page and the Bale report.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Health {

	const OPTION = 'aipc_conn_health';

	/**
	 * All health rows, keyed by connection id (name as fallback key).
	 *
	 * @return array
	 */
	public static function all() {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Key for a connection array.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	private static function key( array $conn ) {
		if ( ! empty( $conn['id'] ) ) {
			return (string) $conn['id'];
		}
		return 'name_' . sanitize_key( isset( $conn['name'] ) ? $conn['name'] : 'unknown' );
	}

	/**
	 * Record one attempt outcome.
	 *
	 * @param array $conn    Connection the attempt used.
	 * @param bool  $success Whether the step attempt succeeded.
	 * @return void
	 */
	public static function record( array $conn, $success ) {
		$key   = self::key( $conn );
		$today = wp_date( 'Y-m-d' );
		$all   = self::all();
		$row   = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$row   = wp_parse_args( $row, array(
			'name'           => '',
			'day'            => $today,
			'ok'             => 0,
			'fail'           => 0,
			'streak'         => 0,
			'cooldown_until' => 0,
		) );

		if ( $row['day'] !== $today ) { // Daily counters reset at midnight.
			$row['day']  = $today;
			$row['ok']   = 0;
			$row['fail'] = 0;
		}
		$row['name'] = isset( $conn['name'] ) ? (string) $conn['name'] : $row['name'];

		if ( $success ) {
			$row['ok']++;
			$row['streak']         = 0;
			$row['cooldown_until'] = 0;
		} else {
			$row['fail']++;
			$row['streak']++;

			/**
			 * Filter the consecutive-failure count that triggers a cooldown.
			 *
			 * @param int   $streak Default 5.
			 * @param array $conn   Connection.
			 */
			$streak = (int) apply_filters( 'aipc_health_streak', 5, $conn );

			/**
			 * Filter the cooldown length in seconds.
			 *
			 * @param int   $seconds Default 30 minutes.
			 * @param array $conn    Connection.
			 */
			$cooldown = (int) apply_filters( 'aipc_health_cooldown', 30 * MINUTE_IN_SECONDS, $conn );

			if ( $row['streak'] >= max( 1, $streak ) ) {
				$row['cooldown_until'] = time() + $cooldown;
			}
		}

		$all[ $key ] = $row;
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $all, '', false );
		} else {
			update_option( self::OPTION, $all );
		}
	}

	/**
	 * Is the connection currently on cooldown?
	 *
	 * @param array $conn Connection.
	 * @return bool
	 */
	public static function on_cooldown( array $conn ) {
		$all = self::all();
		$key = self::key( $conn );
		return isset( $all[ $key ]['cooldown_until'] ) && (int) $all[ $key ]['cooldown_until'] > time();
	}

	/**
	 * Reorder a step chain: healthy connections first, cooldown ones
	 * last (stable — original priority order is kept inside each group).
	 *
	 * @param array[] $chain Ordered connections.
	 * @return array[]
	 */
	public static function order( array $chain ) {
		if ( count( $chain ) < 2 ) {
			return $chain;
		}
		$healthy = array();
		$cooling = array();
		foreach ( $chain as $conn ) {
			if ( self::on_cooldown( $conn ) ) {
				$cooling[] = $conn;
			} else {
				$healthy[] = $conn;
			}
		}
		return array_merge( $healthy, $cooling );
	}

	/**
	 * Traffic-light status for the connections page.
	 *
	 * @param array $conn Connection.
	 * @return string ok|warn|down
	 */
	public static function status( array $conn ) {
		$all = self::all();
		$key = self::key( $conn );
		if ( ! isset( $all[ $key ] ) ) {
			return 'ok';
		}
		$row = $all[ $key ];
		if ( (int) $row['cooldown_until'] > time() ) {
			return 'down';
		}
		if ( $row['day'] === wp_date( 'Y-m-d' ) && (int) $row['fail'] > 0 ) {
			return 'warn';
		}
		return 'ok';
	}

	/**
	 * Today's failure counts for the Bale report.
	 *
	 * @return array[] Rows {name, fail}.
	 */
	public static function trouble_today() {
		$today = wp_date( 'Y-m-d' );
		$rows  = array();
		foreach ( self::all() as $row ) {
			if ( isset( $row['day'], $row['fail'] ) && $row['day'] === $today && (int) $row['fail'] > 0 ) {
				$rows[] = array(
					'name' => (string) $row['name'],
					'fail' => (int) $row['fail'],
				);
			}
		}
		return $rows;
	}
}
