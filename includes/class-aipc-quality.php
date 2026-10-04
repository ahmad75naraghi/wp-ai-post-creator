<?php
/**
 * Quality gate (1.22.0): zero-cost pre-publish checks on the finished
 * article HTML. The score (0–100) is stored on the job; when it falls
 * below the `aipc_quality_threshold` filter (default 60) auto-publishing
 * is cancelled and the post stays a draft for human review.
 *
 * All checks are local string analysis — no API calls, no tokens.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Quality {

	/**
	 * Machine-cliché phrases that make text read AI-generated.
	 *
	 * @var string[]
	 */
	private static $cliches = array(
		'در دنیای امروز',
		'شایان ذکر است',
		'همانطور که می‌دانید',
		'بدون شک',
		'در این مقاله سعی کردیم',
		'in today\'s world',
		'it is worth mentioning',
		'in conclusion,',
		'delve into',
		'unlock the power',
	);

	/**
	 * Analyse the finished article HTML.
	 *
	 * @param string $html Final post HTML (before insertion).
	 * @param array  $job  Job (for keyword/args context).
	 * @return array {score: int 0-100, issues: string[]}
	 */
	public static function check( $html, array $job ) {
		$score  = 100;
		$issues = array();

		$text  = wp_strip_all_tags( (string) $html );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? count( $words ) : 0;

		// 1. Thin content.
		if ( $words < 300 ) {
			$score   -= 25;
			$issues[] = sprintf(
				/* translators: %d: word count. */
				__( 'Very short article (%d words)', 'wp-ai-post-creator' ),
				$words
			);
		}

		// 2. Heading structure: empty or duplicated H2s.
		preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/si', (string) $html, $h2m );
		$h2s   = array_map( static function ( $h ) {
			return trim( wp_strip_all_tags( $h ) );
		}, isset( $h2m[1] ) ? $h2m[1] : array() );
		$blank = count( array_filter( $h2s, static function ( $h ) {
			return '' === $h;
		} ) );
		if ( $blank > 0 ) {
			$score   -= 10;
			$issues[] = __( 'Empty section heading', 'wp-ai-post-creator' );
		}
		$norm = array_map( 'mb_strtolower', array_filter( $h2s ) );
		if ( count( $norm ) !== count( array_unique( $norm ) ) ) {
			$score   -= 10;
			$issues[] = __( 'Duplicated section headings', 'wp-ai-post-creator' );
		}

		// 3. Focus-keyword stuffing / absence.
		$kw = isset( $job['data']['plan']['primary_keyword'] ) ? trim( (string) $job['data']['plan']['primary_keyword'] ) : '';
		if ( '' !== $kw && $words > 0 ) {
			$occ     = mb_substr_count( mb_strtolower( $text ), mb_strtolower( $kw ) );
			$density = $occ / max( 1, $words ) * 100;
			if ( $density > 3 ) {
				$score   -= 15;
				$issues[] = sprintf(
					/* translators: %s: keyword. */
					__( 'Keyword stuffing: “%s” is repeated far too often', 'wp-ai-post-creator' ),
					$kw
				);
			} elseif ( 0 === $occ ) {
				$score   -= 5;
				$issues[] = sprintf(
					/* translators: %s: keyword. */
					__( 'The focus keyword “%s” never appears in the text', 'wp-ai-post-creator' ),
					$kw
				);
			}
		}

		// 4. Machine clichés (AI-sounding stock phrases).
		$low     = mb_strtolower( $text );
		$hits    = 0;
		$example = '';
		foreach ( self::$cliches as $phrase ) {
			if ( false !== mb_strpos( $low, mb_strtolower( $phrase ) ) ) {
				$hits++;
				if ( '' === $example ) {
					$example = $phrase;
				}
			}
		}
		if ( $hits > 0 ) {
			$score   -= min( 15, $hits * 5 );
			$issues[] = sprintf(
				/* translators: 1: count, 2: example phrase. */
				__( '%1$d machine-cliché phrase(s), e.g. “%2$s”', 'wp-ai-post-creator' ),
				$hits,
				$example
			);
		}

		// 5. Broken internal links (pointing to this site but no post).
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		preg_match_all( '/href=["\']([^"\']+)["\']/i', (string) $html, $lm );
		$broken = 0;
		foreach ( isset( $lm[1] ) ? $lm[1] : array() as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host !== $home ) {
				continue; // External or relative — not checked.
			}
			if ( 0 === url_to_postid( $href ) ) {
				$broken++;
			}
		}
		if ( $broken > 0 ) {
			$score   -= min( 10, $broken * 5 );
			$issues[] = sprintf(
				/* translators: %d: link count. */
				__( '%d internal link(s) do not resolve to a post', 'wp-ai-post-creator' ),
				$broken
			);
		}

		// 6. Duplicate title among existing posts.
		$title = isset( $job['data']['plan']['title'] ) ? trim( (string) $job['data']['plan']['title'] ) : '';
		if ( '' !== $title ) {
			$dupe = get_posts( array(
				'post_type'              => 'post',
				'post_status'            => array( 'publish', 'draft', 'future' ),
				'title'                  => $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( ! empty( $dupe ) ) {
				$score   -= 10;
				$issues[] = sprintf(
					/* translators: %d: post id. */
					__( 'A post with the exact same title already exists (#%d)', 'wp-ai-post-creator' ),
					(int) $dupe[0]
				);
			}
		}

		// 7. Promised featured image missing.
		if ( ! empty( $job['args']['image'] ) && empty( $job['data']['image']['attachment_id'] ) ) {
			$score   -= 10;
			$issues[] = __( 'No featured image was generated', 'wp-ai-post-creator' );
		}

		return array(
			'score'  => max( 0, min( 100, (int) $score ) ),
			'issues' => $issues,
		);
	}
}
