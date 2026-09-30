<?php
/**
 * Multiple AI connections (providers).
 *
 * Each connection is an independent OpenAI-compatible endpoint with its own
 * base URL, API key, models and parameters. Pipeline steps can each be
 * assigned a different connection (see AIPC_Steps).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection storage and CRUD.
 */
final class AIPC_Connections {

	const OPTION = 'aipc_connections';

	/**
	 * All connections.
	 *
	 * @return array
	 */
	public static function all() {
		$conns = get_option( self::OPTION, array() );
		if ( ! is_array( $conns ) ) {
			return array();
		}
		// Connections stored before 1.8.0 have no purpose/priority.
		foreach ( $conns as $i => $conn ) {
			if ( ! isset( $conn['purpose'] ) || ! in_array( $conn['purpose'], array( 'both', 'chat', 'image' ), true ) ) {
				$conns[ $i ]['purpose'] = 'both';
			}
			if ( ! isset( $conn['priority'] ) || (int) $conn['priority'] < 1 ) {
				$conns[ $i ]['priority'] = 10;
			}
		}
		return $conns;
	}

	/**
	 * Connections usable for a purpose ('chat' or 'image'), ordered by
	 * priority (lower number = tried first), then default flag, then name.
	 *
	 * A connection participates when its own purpose matches or is 'both'.
	 * The agent gives each entry its own retry budget (3 attempts by
	 * default) and falls back to the next one on repeated failure.
	 *
	 * @param string $purpose 'chat' or 'image'.
	 * @return array[] Ordered connection data (may be empty).
	 */
	public static function for_purpose( $purpose ) {
		$purpose = ( 'image' === $purpose ) ? 'image' : 'chat';
		$pool    = array();
		foreach ( self::all() as $conn ) {
			if ( empty( $conn['base_url'] ) ) {
				continue;
			}
			if ( 'both' === $conn['purpose'] || $purpose === $conn['purpose'] ) {
				$pool[] = $conn;
			}
		}
		usort( $pool, function ( $a, $b ) {
			$pa = (int) $a['priority'];
			$pb = (int) $b['priority'];
			if ( $pa !== $pb ) {
				return $pa - $pb;
			}
			$da = empty( $a['is_default'] ) ? 1 : 0;
			$db = empty( $b['is_default'] ) ? 1 : 0;
			if ( $da !== $db ) {
				return $da - $db;
			}
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		} );

		/**
		 * Filter the priority-ordered connection pool for a purpose.
		 *
		 * @param array[] $pool    Ordered connection data.
		 * @param string  $purpose 'chat' or 'image'.
		 */
		return apply_filters( 'aipc_connections_for_purpose', $pool, $purpose );
	}

	/**
	 * Count connections.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::all() );
	}

	/**
	 * Get one connection by id.
	 *
	 * @param string $id Connection id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = (string) $id;
		foreach ( self::all() as $conn ) {
			if ( isset( $conn['id'] ) && $conn['id'] === $id ) {
				return $conn;
			}
		}
		return null;
	}

	/**
	 * The default connection (first one flagged, else first).
	 *
	 * @return array|null
	 */
	public static function get_default() {
		$conns = self::all();
		if ( empty( $conns ) ) {
			return null;
		}
		foreach ( $conns as $conn ) {
			if ( ! empty( $conn['is_default'] ) ) {
				return $conn;
			}
		}
		return $conns[0];
	}

	/**
	 * Insert or update a connection.
	 *
	 * @param array $conn Connection data (with id).
	 * @return array The saved connection.
	 */
	public static function save( array $conn ) {
		$conns = self::all();

		// Merge with the existing record (by id) so partial updates keep
		// stored values (api_key, tuned limits) and the row is always complete.
		$id  = isset( $conn['id'] ) ? (string) $conn['id'] : '';
		$old = array( 'id' => $id );
		if ( '' !== $id ) {
			foreach ( $conns as $existing ) {
				if ( isset( $existing['id'] ) && $existing['id'] === $id ) {
					$old = $existing;
					break;
				}
			}
		}
		$conn = self::sanitize( $conn, $old );

		if ( '' === $conn['id'] ) {
			$conn['id'] = 'c_' . strtolower( wp_generate_password( 10, false, false ) );
		}
		$conn['updated'] = time();
		if ( empty( $conn['created'] ) ) {
			$conn['created'] = time();
		}

		$found = false;
		foreach ( $conns as $i => $existing ) {
			if ( $existing['id'] === $conn['id'] ) {
				$conns[ $i ] = $conn;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			$conns[] = $conn;
		}

		// Only one default at a time.
		if ( ! empty( $conn['is_default'] ) ) {
			foreach ( $conns as $i => $existing ) {
				if ( $existing['id'] !== $conn['id'] ) {
					$conns[ $i ]['is_default'] = 0;
				}
			}
		} elseif ( 1 === count( $conns ) ) {
			$conns[0]['is_default'] = 1;
		}

		self::persist( $conns );
		return $conn;
	}

	/**
	 * Delete a connection.
	 *
	 * @param string $id Connection id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$conns = self::all();
		$kept  = array();
		$was_default = false;
		$found = false;

		foreach ( $conns as $conn ) {
			if ( $conn['id'] === (string) $id ) {
				$found       = true;
				$was_default = ! empty( $conn['is_default'] );
				continue;
			}
			$kept[] = $conn;
		}
		if ( ! $found ) {
			return false;
		}

		if ( $was_default && ! empty( $kept ) ) {
			$kept[0]['is_default'] = 1;
		}

		self::persist( $kept );

		// Steps pointing at the deleted connection fall back to the default —
		// clean both the legacy single value and the fallback-chain array.
		$steps = AIPC_Steps::all_config();
		$dirty = false;
		foreach ( $steps as $key => $cfg ) {
			if ( ! empty( $cfg['connection'] ) && $cfg['connection'] === (string) $id ) {
				$steps[ $key ]['connection'] = '';
				$dirty = true;
			}
			if ( ! empty( $cfg['connections'] ) && is_array( $cfg['connections'] ) ) {
				$kept_conn = array();
				foreach ( $cfg['connections'] as $conn_id ) {
					if ( (string) $conn_id !== (string) $id ) {
						$kept_conn[] = $conn_id;
					}
				}
				if ( count( $kept_conn ) !== count( $cfg['connections'] ) ) {
					$steps[ $key ]['connections'] = $kept_conn;
					$dirty                        = true;
				}
			}
		}
		if ( $dirty ) {
			AIPC_Steps::save_all( $steps );
		}

		return true;
	}

	/**
	 * Persist the list.
	 *
	 * @param array $conns Connections.
	 * @return void
	 */
	private static function persist( $conns ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, array_values( $conns ), '', false );
		} else {
			update_option( self::OPTION, array_values( $conns ) );
		}
	}

	/**
	 * Sanitize connection input. An empty api_key keeps the stored one.
	 *
	 * @param array      $in  Raw input.
	 * @param array|null $old Existing connection (optional).
	 * @return array
	 */
	public static function sanitize( $in, $old = null ) {
		$in  = is_array( $in ) ? $in : array();
		$old = is_array( $old ) ? $old : array();

		$name = isset( $in['name'] ) ? sanitize_text_field( $in['name'] ) : '';
		if ( '' === $name ) {
			$name = isset( $old['name'] ) ? $old['name'] : __( 'Connection', 'wp-ai-post-creator' );
		}

		$base = isset( $in['base_url'] ) ? esc_url_raw( trim( (string) $in['base_url'] ) ) : '';
		if ( '' === $base || 0 !== strpos( $base, 'http' ) ) {
			$base = isset( $old['base_url'] ) ? $old['base_url'] : '';
		} elseif ( ! AIPC_Network::is_safe_url( $base ) ) {
			// SSRF guard: a NEW blocked host is refused. An unchanged stored
			// one is grandfathered (add the aipc_outbound_allowlist filter or
			// enable aipc_allow_private_hosts to use internal endpoints).
			$old_base = isset( $old['base_url'] ) ? $old['base_url'] : '';
			$base     = ( $old_base === $base ) ? $base : $old_base;
		}

		$key = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		if ( '' === $key ) {
			$key = isset( $old['api_key'] ) ? $old['api_key'] : '';
		}

		$temp = isset( $in['temperature'] ) ? (float) $in['temperature'] : ( isset( $old['temperature'] ) ? $old['temperature'] : 0.7 );
		if ( $temp < 0 || $temp > 2 ) {
			$temp = 0.7;
		}

		$max_tokens = isset( $in['max_tokens'] ) ? absint( $in['max_tokens'] ) : ( isset( $old['max_tokens'] ) ? $old['max_tokens'] : 4000 );
		if ( $max_tokens < 256 ) {
			$max_tokens = 256;
		} elseif ( $max_tokens > 16000 ) {
			$max_tokens = 16000;
		}

		$timeout = isset( $in['request_timeout'] ) ? absint( $in['request_timeout'] ) : ( isset( $old['request_timeout'] ) ? $old['request_timeout'] : 120 );
		if ( $timeout < 15 ) {
			$timeout = 15;
		} elseif ( $timeout > 600 ) {
			$timeout = 600;
		}

		$purpose = isset( $in['purpose'] ) ? sanitize_key( (string) $in['purpose'] ) : ( isset( $old['purpose'] ) ? $old['purpose'] : 'both' );
		if ( ! in_array( $purpose, array( 'both', 'chat', 'image' ), true ) ) {
			$purpose = 'both';
		}

		$priority = isset( $in['priority'] ) ? absint( $in['priority'] ) : ( isset( $old['priority'] ) ? absint( $old['priority'] ) : 10 );
		if ( $priority < 1 ) {
			$priority = 10;
		} elseif ( $priority > 999 ) {
			$priority = 999;
		}

		$conn = array(
			'id'              => isset( $old['id'] ) ? $old['id'] : '',
			'name'            => $name,
			'base_url'        => untrailingslashit( $base ),
			'api_key'         => $key,
			'chat_model'      => isset( $in['chat_model'] ) && '' !== trim( (string) $in['chat_model'] ) ? sanitize_text_field( $in['chat_model'] ) : ( isset( $old['chat_model'] ) ? $old['chat_model'] : 'gpt-4o-mini' ),
			'image_model'     => isset( $in['image_model'] ) && '' !== trim( (string) $in['image_model'] ) ? sanitize_text_field( $in['image_model'] ) : ( isset( $old['image_model'] ) ? $old['image_model'] : 'dall-e-3' ),
			'temperature'     => $temp,
			'max_tokens'      => $max_tokens,
			'request_timeout' => $timeout,
			'purpose'         => $purpose,
			'priority'        => $priority,
			'is_default'      => empty( $in['is_default'] ) ? ( isset( $old['is_default'] ) ? $old['is_default'] : 0 ) : 1,
			'created'         => isset( $old['created'] ) ? $old['created'] : 0,
			'updated'         => time(),
		);

		return $conn;
	}

	/**
	 * Migrate 1.1-style single-provider settings into a connection.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		if ( self::count() > 0 ) {
			return;
		}
		$old = get_option( 'aipc_settings', array() );
		if ( ! is_array( $old ) || empty( $old['api_base_url'] ) ) {
			return;
		}

		self::save( array(
			'name'            => __( 'Main connection', 'wp-ai-post-creator' ),
			'base_url'        => $old['api_base_url'],
			'api_key'         => isset( $old['api_key'] ) ? $old['api_key'] : '',
			'chat_model'      => isset( $old['chat_model'] ) ? $old['chat_model'] : 'gpt-4o-mini',
			'image_model'     => isset( $old['image_model'] ) ? $old['image_model'] : 'dall-e-3',
			'temperature'     => isset( $old['temperature'] ) ? $old['temperature'] : 0.7,
			'max_tokens'      => isset( $old['max_tokens'] ) ? $old['max_tokens'] : 4000,
			'request_timeout' => isset( $old['request_timeout'] ) ? $old['request_timeout'] : 120,
			'is_default'      => 1,
		) );
	}

	/**
	 * Public-safe list (no API keys) for the admin UI.
	 *
	 * @return array
	 */
	public static function all_for_ui() {
		$out = array();
		foreach ( self::all() as $conn ) {
			$out[] = array(
				'id'          => $conn['id'],
				'name'        => $conn['name'],
				'base_url'    => $conn['base_url'],
				'host'        => (string) wp_parse_url( $conn['base_url'], PHP_URL_HOST ),
				'chat_model'  => $conn['chat_model'],
				'image_model' => $conn['image_model'],
				'is_default'  => empty( $conn['is_default'] ) ? 0 : 1,
				'has_key'     => ! empty( $conn['api_key'] ),
			);
		}
		return $out;
	}
}
