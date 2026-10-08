<?php
/**
 * Topic queue: a FIFO bank of topics the scheduler can pull from.
 *
 * Schedule entries can take their topic from this queue instead of a fixed
 * text (checkbox "take the topic from the queue"). When the entry fires it
 * consumes the first pending topic; if the queue is empty the entry falls
 * back to its own topic (or the site prompt). The queue can be filled by
 * hand or from the configured research sources (RSS) via the "suggest"
 * button on the Schedule page.
 *
 * Storage: one option (autoload off) holding items with a status:
 *   { id, text, source: manual|rss, added, status: pending|used, job_id, used_at }
 * Used items are kept (capped) so the same topic is never suggested twice.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

final class AIPC_Topic_Queue {

	const OPTION        = 'aipc_topic_queue';
	const FEED_CACHE    = 'aipc_feed_cache';
	const MAX_PENDING   = 100; // Queue length cap.
	const MAX_ITEMS     = 300; // Pending + used history cap.
	const MAX_DISMISSED = 500; // Dismissed-suggestions memory cap.

	/**
	 * Full stored queue.
	 *
	 * @return array
	 */
	public static function all() {
		$cfg = get_option( self::OPTION, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return wp_parse_args( $cfg, array( 'items' => array() ) );
	}

	/**
	 * Persist the queue.
	 *
	 * @param array $items Items.
	 * @return void
	 */
	private static function persist( $items ) {
		$cfg          = self::all(); // Keep the dismissed-suggestions memory.
		$cfg['items'] = array_values( $items );
		update_option( self::OPTION, $cfg, false );
	}

	/**
	 * Pending (not yet consumed) topics, oldest first.
	 *
	 * @return array[]
	 */
	public static function pending() {
		$out = array();
		foreach ( self::all()['items'] as $item ) {
			if ( isset( $item['status'] ) && 'pending' === $item['status'] ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Number of pending topics.
	 *
	 * @return int
	 */
	public static function count_pending() {
		return count( self::pending() );
	}

	/**
	 * The first pending topic (without consuming it).
	 *
	 * @return array|null
	 */
	public static function peek() {
		foreach ( self::pending() as $item ) {
			return $item;
		}
		return null;
	}

	/**
	 * Mark a pending topic as used by a job.
	 *
	 * @param string $id     Topic id.
	 * @param string $job_id Job that consumed it.
	 * @return bool
	 */
	public static function mark_used( $id, $job_id = '' ) {
		$items = self::all()['items'];
		$found = false;
		foreach ( $items as $i => $item ) {
			if ( isset( $item['id'], $item['status'] ) && $id === $item['id'] && 'pending' === $item['status'] ) {
				$items[ $i ]['status'] = 'used';
				$items[ $i ]['job_id'] = sanitize_key( $job_id );
				$items[ $i ]['used_at'] = time();
				$found = true;
				break;
			}
		}
		if ( $found ) {
			self::persist( self::prune( $items ) );
		}
		return $found;
	}

	/**
	 * Add one topic (duplicates are skipped — any status counts).
	 *
	 * @param string $text   Topic text.
	 * @param string $source manual|rss.
	 * @return array|WP_Error The added item.
	 */
	public static function add( $text, $source = 'manual' ) {
		$text = self::normalize_text( $text );
		if ( '' === $text ) {
			return new WP_Error( 'aipc_queue', __( 'The topic is empty.', 'wp-ai-post-creator' ) );
		}
		if ( mb_strlen( $text ) > 400 ) {
			$text = mb_substr( $text, 0, 400 );
		}

		$items = self::all()['items'];
		$key   = self::normalize_key( $text );
		foreach ( $items as $item ) {
			$item_key = isset( $item['norm'] ) ? $item['norm'] : ( isset( $item['text'] ) ? self::normalize_key( $item['text'] ) : '' );
			if ( '' !== $item_key && $item_key === $key ) {
				/* translators: %s: topic text. */
				return new WP_Error( 'aipc_queue', sprintf( __( 'This topic is already in the queue: %s', 'wp-ai-post-creator' ), wp_trim_words( $text, 10, '…' ) ) );
			}
		}

		if ( self::count_pending() >= self::MAX_PENDING ) {
			return new WP_Error( 'aipc_queue', __( 'The topic queue is full.', 'wp-ai-post-creator' ) );
		}

		$items[] = array(
			'id'      => 'tq_' . strtolower( wp_generate_password( 8, false, false ) ),
			'text'    => $text,
			'norm'    => self::normalize_key( $text ),
			'source'  => 'rss' === $source ? 'rss' : 'manual',
			'added'   => time(),
			'status'  => 'pending',
			'job_id'  => '',
			'used_at' => 0,
		);
		self::persist( self::prune( $items ) );
		return $items[ count( $items ) - 1 ];
	}

	/**
	 * Add several topics at once (newline-separated or an array).
	 *
	 * @param string|array $texts  Topics.
	 * @param string       $source manual|rss.
	 * @return int Number actually added (duplicates are skipped).
	 */
	public static function add_many( $texts, $source = 'manual' ) {
		$lines = is_array( $texts ) ? $texts : preg_split( '/\r\n|\r|\n+/', (string) $texts );
		$added = 0;
		foreach ( (array) $lines as $line ) {
			$res = self::add( $line, $source );
			if ( ! is_wp_error( $res ) ) {
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Remove one topic (any status) by id.
	 *
	 * @param string $id Topic id.
	 * @return bool
	 */
	public static function remove( $id ) {
		$items  = self::all()['items'];
		$kept   = array();
		$found  = false;
		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) && $id === (string) $item['id'] ) {
				$found = true;
				continue;
			}
			$kept[] = $item;
		}
		if ( $found ) {
			self::persist( $kept );
		}
		return $found;
	}

	/**
	 * Remove every pending topic (history is kept for dedup).
	 *
	 * @return int Number removed.
	 */
	public static function clear_pending() {
		$items  = self::all()['items'];
		$kept   = array();
		$removed = 0;
		foreach ( $items as $item ) {
			if ( isset( $item['status'] ) && 'pending' === $item['status'] ) {
				$removed++;
				continue;
			}
			$kept[] = $item;
		}
		self::persist( $kept );
		return $removed;
	}

	/**
	 * Drop old used items beyond the storage cap.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function prune( $items ) {
		if ( count( $items ) <= self::MAX_ITEMS ) {
			return $items;
		}
		// Keep all pending + the newest used items.
		$pending = array();
		$used    = array();
		foreach ( $items as $item ) {
			if ( isset( $item['status'] ) && 'pending' === $item['status'] ) {
				$pending[] = $item;
			} else {
				$used[] = $item;
			}
		}
		$used = array_slice( $used, -1 * max( 1, self::MAX_ITEMS - count( $pending ) ) );
		return array_merge( $pending, $used );
	}

	/* ---------------------------------------------------------------------
	 * Suggestions from the research sources (RSS)
	 * ------------------------------------------------------------------- */

	/**
	 * Suggest topics from the configured source sites' RSS feeds.
	 *
	 * Headline titles are cleaned (source-name suffixes stripped), deduped
	 * against the queue (any status) and the latest post titles, and
	 * returned for the admin to review before adding.
	 *
	 * @param int $limit Maximum suggestions.
	 * @return array[] {text, source, url}
	 */
	public static function suggest( $limit = 12, $exclude = array() ) {
		$limit = max( 1, min( 30, (int) $limit ) );

		$urls = array();
		foreach ( preg_split( '/\n+/', (string) AIPC_Settings::get( 'source_sites' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		if ( empty( $urls ) ) {
			return array( 'suggestions' => array(), 'sources' => array() );
		}
		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}

		// Known topics: everything ever queued + recent post titles +
		// dismissed suggestions + the batch already on screen (exclude).
		$known = array();
		foreach ( self::all()['items'] as $item ) {
			if ( isset( $item['norm'] ) ) {
				$known[ $item['norm'] ] = true;
			}
		}
		foreach ( self::dismissed() as $norm => $ts ) {
			$known[ $norm ] = true;
		}
		foreach ( (array) $exclude as $text ) {
			$norm = self::normalize_key( self::normalize_text( (string) $text ) );
			if ( '' !== $norm ) {
				$known[ $norm ] = true;
			}
		}
		foreach ( get_posts( array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'numberposts'      => 50,
			'suppress_filters' => true,
		) ) as $post ) {
			$known[ self::normalize_key( $post->post_title ) ] = true;
		}

		// Collect fresh headlines per source, then interleave round-robin
		// so one busy site never crowds out the others.
		$per_source = array();
		$sources    = array();
		foreach ( $urls as $url ) {
			$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
			$feed_url = self::discover_feed( $url );
			if ( '' === $feed_url ) {
				$sources[] = array( 'host' => $host, 'status' => 'no_feed', 'found' => 0 );
				continue;
			}
			$feed = fetch_feed( $feed_url );
			if ( is_wp_error( $feed ) || ! method_exists( $feed, 'get_item_quantity' ) || ! $feed->get_item_quantity() ) {
				$sources[] = array( 'host' => $host, 'status' => 'no_feed', 'found' => 0 );
				continue;
			}
			$fresh = array();
			foreach ( $feed->get_items( 0, 20 ) as $item ) {
				$title = self::clean_title( (string) $item->get_title() );
				if ( '' === $title ) {
					continue;
				}
				$norm = self::normalize_key( $title );
				if ( isset( $known[ $norm ] ) ) {
					continue;
				}
				$known[ $norm ] = true;
				$fresh[]        = array(
					'text'   => $title,
					'source' => $host,
					'url'    => esc_url_raw( (string) $item->get_permalink() ),
				);
			}
			$sources[]    = array( 'host' => $host, 'status' => 'ok', 'found' => count( $fresh ) );
			$per_source[] = $fresh;
		}

		$suggestions = array();
		$round       = 0;
		while ( count( $suggestions ) < $limit ) {
			$any = false;
			foreach ( $per_source as $list ) {
				if ( isset( $list[ $round ] ) ) {
					$any           = true;
					$suggestions[] = $list[ $round ];
					if ( count( $suggestions ) >= $limit ) {
						break;
					}
				}
			}
			if ( ! $any ) {
				break;
			}
			$round++;
		}

		return array( 'suggestions' => $suggestions, 'sources' => $sources );
	}

	/**
	 * Find the working feed URL for a source site (1.23.0).
	 *
	 * Tries the URL itself when it already looks like a feed, then the
	 * common feed paths (/feed/, /rss, /rss.xml, /atom.xml, …) and
	 * finally HTML `<link rel="alternate">` autodiscovery on the page.
	 * The result (also a miss) is cached in the `aipc_feed_cache` option.
	 *
	 * @param string $url Source site URL.
	 * @return string Feed URL, or '' when none was found.
	 */
	public static function discover_feed( $url ) {
		$url   = trim( (string) $url );
		$key   = md5( self::normalize_key( rtrim( $url, '/' ) ) );
		$cache = get_option( self::FEED_CACHE, array() );
		$cache = is_array( $cache ) ? $cache : array();

		if ( isset( $cache[ $key ]['checked'] ) ) {
			$hit = $cache[ $key ];
			$ttl = '' !== (string) $hit['feed'] ? WEEK_IN_SECONDS : 6 * HOUR_IN_SECONDS;
			if ( time() - (int) $hit['checked'] < $ttl ) {
				return (string) $hit['feed'];
			}
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}

		$found = '';
		foreach ( self::feed_candidates( $url ) as $candidate ) {
			if ( self::feed_works( $candidate ) ) {
				$found = $candidate;
				break;
			}
		}

		// Last resort: read the page HTML and honor its own
		// <link rel="alternate" type="application/rss+xml"> declaration.
		if ( '' === $found ) {
			foreach ( self::feeds_from_html( $url ) as $candidate ) {
				if ( self::feed_works( $candidate ) ) {
					$found = $candidate;
					break;
				}
			}
		}

		$cache[ $key ] = array( 'url' => $url, 'feed' => $found, 'checked' => time() );
		if ( count( $cache ) > 50 ) { // Settings cap sources at 8 — stay tidy.
			$cache = array_slice( $cache, -50, null, true );
		}
		update_option( self::FEED_CACHE, $cache, false );

		return $found;
	}

	/**
	 * Common feed locations for a site URL, most likely first.
	 *
	 * @param string $url Source site URL.
	 * @return string[]
	 */
	private static function feed_candidates( $url ) {
		$base = rtrim( $url, '/' );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$candidates = array();
		if ( preg_match( '/(feed|rss|atom|\.xml)/', $path ) ) {
			$candidates[] = $url; // The configured URL is the feed itself.
		}
		$candidates[] = $base . '/feed/';
		$candidates[] = $base . '/feed';
		$candidates[] = $base . '/rss';
		$candidates[] = $base . '/rss.xml';
		$candidates[] = $base . '/feed.xml';
		$candidates[] = $base . '/atom.xml';
		$candidates[] = $base . '/index.xml';
		$candidates[] = $base . '/?feed=rss2';
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Does this URL serve a non-empty RSS/Atom feed?
	 *
	 * @param string $url Candidate feed URL.
	 * @return bool
	 */
	private static function feed_works( $url ) {
		if ( class_exists( 'AIPC_Network' ) && ! AIPC_Network::is_safe_url( $url ) ) {
			return false;
		}
		// SimplePie logs every miss straight through error_log() (gated
		// only by error_reporting) — probing candidate URLs would spam
		// the log, so silence user-level messages for this one call.
		$er = error_reporting(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		error_reporting( $er & ~E_USER_NOTICE & ~E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		$feed = fetch_feed( $url );
		error_reporting( $er ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		return ! is_wp_error( $feed )
			&& method_exists( $feed, 'get_item_quantity' )
			&& $feed->get_item_quantity() > 0;
	}

	/**
	 * Feed URLs declared by the page's own HTML head
	 * (`<link rel="alternate" type="application/rss+xml" href="…">`).
	 *
	 * @param string $url Page URL.
	 * @return string[] Absolute feed URLs (max 3).
	 */
	private static function feeds_from_html( $url ) {
		if ( class_exists( 'AIPC_Network' ) && ! AIPC_Network::is_safe_url( $url ) ) {
			return array();
		}
		$res = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$html = (string) wp_remote_retrieve_body( $res );
		$html = substr( $html, 0, 100000 ); // The <head> is all we need.

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$origin = $scheme . '://' . $host;

		$found = array();
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( ! preg_match( '/type=["\']application\/(?:rss|atom)\+xml["\']/i', $tag ) ) {
					continue;
				}
				if ( ! preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $m ) ) {
					continue;
				}
				$href = html_entity_decode( trim( $m[1] ), ENT_QUOTES );
				if ( 0 === strpos( $href, '//' ) ) {
					$href = $scheme . ':' . $href;
				} elseif ( 0 === strpos( $href, '/' ) ) {
					$href = $origin . $href;
				} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
					$href = rtrim( $url, '/' ) . '/' . ltrim( $href, '/' );
				}
				$found[] = esc_url_raw( $href );
				if ( count( $found ) >= 3 ) {
					break;
				}
			}
		}
		return array_values( array_filter( array_unique( $found ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Dismissed suggestions (1.23.0)
	 * ------------------------------------------------------------------- */

	/**
	 * Dismissed suggestion keys: {norm: unix_time}.
	 *
	 * @return array
	 */
	public static function dismissed() {
		$cfg = self::all();
		return isset( $cfg['dismissed'] ) && is_array( $cfg['dismissed'] ) ? $cfg['dismissed'] : array();
	}

	/**
	 * Never suggest this headline again.
	 *
	 * @param string $text Suggestion text.
	 * @return bool Whether it was recorded.
	 */
	public static function dismiss( $text ) {
		$text = self::normalize_text( $text );
		$norm = self::normalize_key( $text );
		if ( '' === $norm ) {
			return false;
		}
		$cfg                       = self::all();
		$dismissed                 = isset( $cfg['dismissed'] ) && is_array( $cfg['dismissed'] ) ? $cfg['dismissed'] : array();
		$dismissed[ $norm ]        = time();
		if ( count( $dismissed ) > self::MAX_DISMISSED ) {
			asort( $dismissed ); // Oldest first…
			$dismissed = array_slice( $dismissed, -1 * self::MAX_DISMISSED, null, true ); // …keep the newest.
		}
		$cfg['dismissed'] = $dismissed;
		update_option( self::OPTION, $cfg, false );
		return true;
	}

	/**
	 * Clean a feed headline into a topic: strip tags, collapse spaces, drop
	 * a trailing/leading single-word source label ("عنوان - ایسنا",
	 * "ویدیو | عنوان"), cap the length.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function clean_title( $title ) {
		$t = trim( wp_strip_all_tags( (string) $title ) );
		$t = preg_replace( '/\s+/u', ' ', $t );

		// Trailing " - source" / " | source" (single word, likely a site name).
		$t = preg_replace( '/\s*[|\-–—]\s*[^\s|\-]{2,20}$/u', '', $t );
		// Leading "label | " / "label - " (single word like ویدیو، عکس، گزارش).
		$t = preg_replace( '/^[^\s|\-]{2,20}\s*[|\-–—]\s*/u', '', $t );

		// Trim punctuation from both ends. NB: trim() with a multi-byte
		// character mask operates on bytes and can split a UTF-8 sequence —
		// use a unicode regex instead.
		$t = preg_replace( '/^[\s«»"\'،,.:؛]+/u', '', $t );
		$t = preg_replace( '/[\s«»"\'،,.:؛]+$/u', '', $t );
		if ( mb_strlen( $t ) < 8 || mb_strlen( $t ) > 160 ) {
			$t = mb_strlen( $t ) > 160 ? mb_substr( $t, 0, 160 ) : '';
		}
		return $t;
	}

	/**
	 * Normalize a topic for storage.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize_text( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return $text;
	}

	/**
	 * Comparison key: unifies Persian/Arabic characters and Latin case.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize_key( $text ) {
		$text = (string) $text;
		$from = array( 'ي', 'ك', 'ۀ', 'ة', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$to   = array( 'ی', 'ک', 'ه', 'ه', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$text = str_replace( $from, $to, $text );
		return strtolower( trim( $text ) );
	}
}
