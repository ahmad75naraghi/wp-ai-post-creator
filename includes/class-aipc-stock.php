<?php
/**
 * Free stock-photo fallback via the Openverse API (1.18.0).
 *
 * Openverse (openverse.org — a WordPress project) indexes hundreds of
 * millions of CC-licensed photos and needs no API key for anonymous,
 * rate-limited search. When every AI image connection fails, the agent can
 * fetch a relevant, openly-licensed photo here instead of leaving the post
 * without a featured image. Attribution is stored on the attachment.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Openverse stock photo client.
 */
class AIPC_Stock {

	const API = 'https://api.openverse.org/v1/images/';

	/**
	 * Search Openverse for commercially usable photos.
	 *
	 * @param string $query Search text (English works best).
	 * @param int    $limit Max candidates (1–20).
	 * @return array[]|WP_Error Candidate list: {url, title, creator, license, license_version, source}.
	 */
	public static function search( $query, $limit = 5 ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return new WP_Error( 'aipc_stock', __( 'Empty stock photo query.', 'wp-ai-post-creator' ) );
		}

		$url = self::API . '?q=' . rawurlencode( mb_substr( $query, 0, 200 ) )
			. '&page_size=' . max( 1, min( 20, (int) $limit ) )
			. '&license_type=commercial,modification'
			. '&mature=false';

		$res = wp_remote_get( $url, array(
			'timeout'    => 20,
			'user-agent' => 'wp-ai-post-creator/' . AIPC_VERSION . ' (WordPress; +' . home_url( '/' ) . ')',
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) || empty( $json['results'] ) || ! is_array( $json['results'] ) ) {
			return new WP_Error( 'aipc_stock', sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Openverse returned no results (HTTP %d).', 'wp-ai-post-creator' ),
				$code
			) );
		}

		$out = array();
		foreach ( $json['results'] as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$out[] = array(
				'url'             => (string) $item['url'],
				'title'           => isset( $item['title'] ) ? (string) $item['title'] : '',
				'creator'         => isset( $item['creator'] ) ? (string) $item['creator'] : '',
				'license'         => isset( $item['license'] ) ? (string) $item['license'] : '',
				'license_version' => isset( $item['license_version'] ) ? (string) $item['license_version'] : '',
				'source'          => isset( $item['foreign_landing_url'] ) ? (string) $item['foreign_landing_url'] : '',
			);
		}
		if ( empty( $out ) ) {
			return new WP_Error( 'aipc_stock', __( 'Openverse returned no usable photo candidates.', 'wp-ai-post-creator' ) );
		}
		return $out;
	}

	/**
	 * Search and download the first working candidate.
	 *
	 * @param string $query Search text.
	 * @return array|WP_Error {bits, attribution, meta}.
	 */
	public static function fetch( $query ) {
		$cands = self::search( $query );
		if ( is_wp_error( $cands ) ) {
			return $cands;
		}
		foreach ( $cands as $cand ) {
			$bits = self::download( $cand['url'] );
			if ( ! is_wp_error( $bits ) ) {
				return array(
					'bits'        => $bits,
					'attribution' => self::attribution( $cand ),
					'meta'        => $cand,
				);
			}
		}
		return new WP_Error( 'aipc_stock', __( 'Could not download any of the stock photo candidates.', 'wp-ai-post-creator' ) );
	}

	/**
	 * Human attribution line for a candidate (CC licenses require credit).
	 *
	 * @param array $cand Candidate row from search().
	 * @return string
	 */
	public static function attribution( $cand ) {
		$creator = ! empty( $cand['creator'] ) ? (string) $cand['creator'] : __( 'Unknown author', 'wp-ai-post-creator' );
		$license = strtoupper( trim( ( isset( $cand['license'] ) ? $cand['license'] : '' ) . ' ' . ( isset( $cand['license_version'] ) ? $cand['license_version'] : '' ) ) );
		$license = '' !== $license ? 'CC ' . $license : 'CC';
		return sprintf(
			/* translators: 1: photographer name, 2: license label. */
			__( 'Photo: %1$s — via Openverse (%2$s)', 'wp-ai-post-creator' ),
			$creator,
			$license
		);
	}

	/**
	 * Download one image, with sanity checks.
	 *
	 * @param string $url Image URL.
	 * @return string|WP_Error Raw image bytes.
	 */
	private static function download( $url ) {
		if ( ! AIPC_Network::is_safe_url( $url ) ) {
			return new WP_Error( 'aipc_stock', __( 'Unsafe stock photo URL blocked.', 'wp-ai-post-creator' ) );
		}
		$res = wp_remote_get( $url, array(
			'timeout'             => 30,
			'redirection'         => 3,
			'limit_response_size' => 15 * MB_IN_BYTES,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$type = (string) wp_remote_retrieve_header( $res, 'content-type' );
		$bits = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code || strlen( $bits ) < 1000 || ( '' !== $type && 0 !== strpos( $type, 'image/' ) ) ) {
			return new WP_Error( 'aipc_stock', sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Stock photo download failed (HTTP %d).', 'wp-ai-post-creator' ),
				$code
			) );
		}
		return $bits;
	}
}
