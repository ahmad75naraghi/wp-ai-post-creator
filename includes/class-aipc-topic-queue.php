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

	const OPTION      = 'aipc_topic_queue';
	const MAX_PENDING = 100; // Queue length cap.
	const MAX_ITEMS   = 300; // Pending + used history cap.

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
		update_option( self::OPTION, array( 'items' => array_values( $items ) ), false );
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
	public static function suggest( $limit = 12 ) {
		$limit = max( 1, min( 30, (int) $limit ) );

		$urls = array();
		foreach ( preg_split( '/\n+/', (string) AIPC_Settings::get( 'source_sites' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		if ( empty( $urls ) ) {
			return array();
		}
		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}

		// Known topics: everything ever queued + recent post titles.
		$known = array();
		foreach ( self::all()['items'] as $item ) {
			if ( isset( $item['norm'] ) ) {
				$known[ $item['norm'] ] = true;
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

		$suggestions = array();
		foreach ( array_slice( $urls, 0, 5 ) as $url ) {
			$feed = fetch_feed( rtrim( $url, '/' ) . '/feed/' );
			if ( is_wp_error( $feed ) || ! method_exists( $feed, 'get_item_quantity' ) || ! $feed->get_item_quantity() ) {
				continue;
			}
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			foreach ( $feed->get_items( 0, 8 ) as $item ) {
				$title = self::clean_title( (string) $item->get_title() );
				if ( '' === $title ) {
					continue;
				}
				$norm = self::normalize_key( $title );
				if ( isset( $known[ $norm ] ) ) {
					continue;
				}
				$known[ $norm ] = true;
				$suggestions[]  = array(
					'text'   => $title,
					'source' => $host,
					'url'    => esc_url_raw( (string) $item->get_permalink() ),
				);
				if ( count( $suggestions ) >= $limit ) {
					break 2;
				}
			}
		}
		return $suggestions;
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
