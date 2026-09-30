<?php
/**
 * Outbound network guard (SSRF hardening).
 *
 * Every user-configurable URL the server will request (AI connection base
 * URLs, research source sites, provider-returned image URLs) passes through
 * AIPC_Network::validate_url() first. Private/reserved IP ranges are blocked
 * by default; loopback is allowed by default so local model servers (Ollama,
 * LM Studio) keep working out of the box. Two filters let site owners tune
 * the policy:
 *
 *   aipc_allow_private_hosts (bool)  — allow every private/reserved range.
 *   aipc_allow_loopback     (bool)  — allow/deny 127.0.0.0/8, ::1, localhost
 *                                     (default: true).
 *   aipc_outbound_allowlist (array) — exact hostnames (or *.example.com
 *                                     wildcards, or IP literals) that are
 *                                     always allowed.
 *
 * Note: hostnames are not DNS-resolved (by design — a hostname that points at
 * a private address can only be caught at request time). For strict
 * environments combine this class with WP core's WP_HTTP_BLOCK_EXTERNAL /
 * WP_ACCESSIBLE_HOSTS constants.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Network {

	/**
	 * Whether an outbound URL passes the guard.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_safe_url( $url ) {
		return ! is_wp_error( self::validate_url( $url ) );
	}

	/**
	 * Validate an outbound URL.
	 *
	 * @param string $url Raw URL.
	 * @return string|WP_Error The cleaned URL or an error describing why it is blocked.
	 */
	public static function validate_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'aipc_url', __( 'Empty URL.', 'wp-ai-post-creator' ) );
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'aipc_url', __( 'Only http(s) URLs are allowed.', 'wp-ai-post-creator' ) );
		}

		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), ". \t\n\r" ) );
		// IPv6 literals arrive wrapped in brackets.
		if ( 0 === strpos( $host, '[' ) ) {
			$host = rtrim( substr( $host, 1 ), ']' );
		}
		if ( '' === $host ) {
			return new WP_Error( 'aipc_url', __( 'The URL has no host.', 'wp-ai-post-creator' ) );
		}

		// The explicit allowlist always wins.
		if ( self::host_allowed( $host ) ) {
			return esc_url_raw( $url );
		}

		$is_loopback = self::is_loopback_host( $host );
		$is_private  = self::is_private_host( $host );

		if ( $is_loopback && ! apply_filters( 'aipc_allow_loopback', true ) ) {
			return new WP_Error(
				'aipc_url',
				__( 'Blocked: loopback hosts are disabled on this site (aipc_allow_loopback filter).', 'wp-ai-post-creator' )
			);
		}

		if ( $is_private && ! apply_filters( 'aipc_allow_private_hosts', self::private_hosts_allowed() ) ) {
			return new WP_Error(
				'aipc_url',
				__( 'Blocked: the host is a private or reserved address. Enable “Allow private/LAN addresses” under Settings → Advanced (or use the “aipc_outbound_allowlist” filter) to allow it.', 'wp-ai-post-creator' )
			);
		}

		return esc_url_raw( $url );
	}

	/**
	 * Default for the aipc_allow_private_hosts filter — the Settings toggle
	 * (Settings → Advanced → “Allow private/LAN addresses”), so users with a
	 * self-hosted gateway (OmniRoute, Ollama on another machine …) don't
	 * need code. The filter still has the final word.
	 *
	 * @return bool
	 */
	private static function private_hosts_allowed() {
		return class_exists( 'AIPC_Settings' ) && (bool) AIPC_Settings::get( 'allow_private_hosts' );
	}

	/**
	 * Whether the host is on the aipc_outbound_allowlist filter.
	 *
	 * @param string $host Lowercased host (no brackets, no trailing dot).
	 * @return bool
	 */
	private static function host_allowed( $host ) {
		$allowlist = apply_filters( 'aipc_outbound_allowlist', array() );
		if ( ! is_array( $allowlist ) ) {
			return false;
		}
		foreach ( $allowlist as $allowed ) {
			$allowed = strtolower( trim( (string) $allowed, ". \t\n\r" ) );
			if ( '' === $allowed ) {
				continue;
			}
			if ( 0 === strpos( $allowed, '*.' ) ) {
				$suffix = substr( $allowed, 1 ); // ".example.com"
				if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			} elseif ( $host === $allowed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Loopback host? (localhost, *.localhost, 127.0.0.0/8, ::1)
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_loopback_host( $host ) {
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
			return true;
		}
		$ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( ! $ip ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return '127' === substr( $ip, 0, 3 ); // 127.0.0.0/8
		}
		return '::1' === $ip;
	}

	/**
	 * Private / reserved / link-local host? (loopback handled separately)
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_private_host( $host ) {
		$ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( ! $ip ) {
			return false; // Plain hostnames are not resolved here.
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::ipv4_private( $ip );
		}

		$bin = @inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return false;
		}

		// IPv4-mapped (::ffff:a.b.c.d) / IPv4-compatible (::a.b.c.d).
		$mapped = self::ipv6_tail_v4( $bin );
		if ( null !== $mapped ) {
			return self::ipv4_private( $mapped );
		}

		// ULA fc00::/7, link-local fe80::/10, multicast ff00::/8, unspecified ::.
		if ( self::ipv6_prefix( $bin, 'fc00::', 7 )
			|| self::ipv6_prefix( $bin, 'fe80::', 10 )
			|| self::ipv6_prefix( $bin, 'ff00::', 8 ) ) {
			return true;
		}
		return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" === $bin;
	}

	/**
	 * Whether an IPv4 string is in a private/reserved range (loopback excluded).
	 *
	 * @param string $ip IPv4.
	 * @return bool
	 */
	private static function ipv4_private( $ip ) {
		$n = (int) ip2long( $ip );
		if ( $n < 0 ) {
			$n += 4294967296;
		}
		// [start, end] pairs as unsigned 32-bit ints.
		$ranges = array(
			array( 0,           16777215 ),      // 0.0.0.0/8
			array( 167772160,  184549375 ),     // 10.0.0.0/8
			array( 1681915904, 1686110207 ),    // 100.64.0.0/10 (CGNAT)
			array( 2851995648, 2852061183 ),    // 169.254.0.0/16 (link-local / metadata)
			array( 2886729728, 2887778303 ),    // 172.16.0.0/12
			array( 3221225472, 3221225727 ),    // 192.0.0.0/24
			array( 3221225984, 3221226239 ),    // 192.0.2.0/24 (documentation)
			array( 3232235520, 3232301055 ),    // 192.168.0.0/16
			array( 3323068416, 3323199487 ),    // 198.18.0.0/15 (benchmark)
			array( 3758096384, 4026531839 ),    // 224.0.0.0/4 (multicast)
			array( 4026531840, 4294967295 ),    // 240.0.0.0/4 (reserved)
		);
		foreach ( $ranges as $range ) {
			if ( $n >= $range[0] && $n <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IPv6 prefix match.
	 *
	 * @param string $bin       16-byte packed address.
	 * @param string $range     Range start (human readable).
	 * @param int    $prefix    Prefix length in bits.
	 * @return bool
	 */
	private static function ipv6_prefix( $bin, $range, $prefix ) {
		$range_bin = @inet_pton( $range );
		if ( false === $range_bin ) {
			return false;
		}
		$bytes = intdiv( (int) $prefix, 8 );
		$bits  = (int) $prefix % 8;
		if ( $bytes > 0 && substr( $bin, 0, $bytes ) !== substr( $range_bin, 0, $bytes ) ) {
			return false;
		}
		if ( $bits > 0 ) {
			$mask = 0xff << ( 8 - $bits ) & 0xff;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) !== ( ord( $range_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Extract the embedded IPv4 of an IPv4-mapped/compatible IPv6 address.
	 *
	 * ::1 (loopback) and :: (unspecified) never map — they are handled by
	 * is_loopback_host() / the final :: check in is_private_host().
	 *
	 * @param string $bin 16-byte packed address.
	 * @return string|null IPv4 string or null.
	 */
	private static function ipv6_tail_v4( $bin ) {
		$zero10 = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
		$zero12 = $zero10 . "\x00\x00";
		// IPv4-mapped: ::ffff:a.b.c.d
		if ( substr( $bin, 0, 10 ) === $zero10 && "\xff\xff" === substr( $bin, 10, 2 ) ) {
			return inet_ntop( substr( $bin, 12 ) );
		}
		// IPv4-compatible (deprecated): ::a.b.c.d — except ::1 and ::.
		if ( substr( $bin, 0, 12 ) === $zero12 ) {
			$tail = substr( $bin, 12 );
			if ( "\x00\x00\x00\x01" === $tail || "\x00\x00\x00\x00" === $tail ) {
				return null;
			}
			return inet_ntop( $tail );
		}
		return null;
	}
}
