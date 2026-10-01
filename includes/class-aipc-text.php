<?php
/**
 * Persian text helpers — half-space (ZWNJ, U+200C) preservation.
 *
 * AI models frequently write Persian without the half-space (e.g.
 * «می شود» instead of «می‌شود»), and the classic editor is known to
 * strip the raw U+200C character on save. This helper (v1.10.0) fixes
 * the common patterns rule-based and armors the character in post
 * content by storing it as the &zwnj; HTML entity, which survives
 * every editor round-trip.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static Persian-spacing utilities.
 */
class AIPC_Text {

	/**
	 * The zero-width non-joiner (نیم‌فاصله), UTF-8 encoded.
	 */
	const ZWNJ = "\xE2\x80\x8C";

	/**
	 * Insert half-spaces where Persian orthography requires them and
	 * keep every half-space that is already there.
	 *
	 * Rules (same spirit as the well-known virastar tools):
	 * - «می / نمی» + space + Persian word  → prefix joined with ZWNJ.
	 * - word + space + «ها / های / هایی / تر / ترین» → suffix joined
	 *   with ZWNJ (only when the suffix ends at a word boundary).
	 *
	 * Existing ZWNJ characters are never touched; non-Persian text
	 * passes through unchanged.
	 *
	 * @param string $text Plain text (or HTML — tags are untouched).
	 * @return string
	 */
	public static function fix_zwnj( $text ) {
		$text = (string) $text;
		if ( '' === $text || ! preg_match( '/[\x{0600}-\x{06FF}]/u', $text ) ) {
			return $text;
		}

		// «می / نمی» prefix: a space between the prefix and a following
		// Persian letter becomes a half-space.
		$fixed = preg_replace(
			'/(^|[\s\x{200C}«»"\'\(\[>؛،:.!؟-])((?:ن)?می)[ ]+(?=[\x{0622}-\x{063A}\x{0641}-\x{064A}\x{066E}-\x{06D5}])/u',
			'$1$2' . self::ZWNJ,
			$text
		);

		// Plural / comparative suffixes: «… ها», «… های», «… هایی»,
		// «… تر», «… ترین» — joined with a half-space when the suffix
		// itself ends at a word boundary.
		$fixed = preg_replace(
			'/(?<=[\x{0622}-\x{063A}\x{0641}-\x{064A}\x{066E}-\x{06D5}])[ ]+(ها|های|هایی|تر|ترین)(?=$|[\s.,;:!؟،؛»"\'\)\]<])/u',
			self::ZWNJ . '$1',
			$fixed
		);

		// GLUED suffixes: some models/gateways strip the ZWNJ entirely,
		// leaving the suffix welded to the word («حرفهای», «خانوادهها»,
		// «علاقهمندان»). Repair word-by-word so root words that merely
		// end in the same letters (تنها، بها، اشتها …) can be excepted.
		$fixed2 = preg_replace_callback(
			'/[\x{0621}-\x{06CC}\x{200C}]{3,}/u',
			array( __CLASS__, 'fix_word' ),
			null === $fixed ? $text : $fixed
		);
		if ( null !== $fixed2 ) {
			$fixed = $fixed2;
		}

		// preg_replace() returns null on a (theoretical) PCRE error —
		// never lose the original text in that case.
		return null === $fixed ? $text : $fixed;
	}

	/**
	 * Repair one Persian word with a glued suffix.
	 *
	 * Only unambiguous patterns are touched:
	 * - «…ها / …های / …هایی» after a forward-joining letter (after
	 *   non-joining letters like ا د ر و the ZWNJ has no visual effect).
	 * - «…ه» + ای / مند(ی|ان) / سازی / گذاری / بندی / ریزی.
	 * Root words that genuinely end in these letters are excepted.
	 *
	 * @param array $m Regex match (the word).
	 * @return string
	 */
	public static function fix_word( $m ) {
		$word = $m[0];
		static $exceptions = array( 'تنها', 'تنهای', 'تنهایی', 'بها', 'بهای', 'اشتها', 'اشتهای', 'رها', 'رهای', 'رهایی', 'بهسازی' );
		if ( in_array( $word, $exceptions, true ) || false !== strpos( $word, self::ZWNJ ) ) {
			return $word;
		}

		// «…های» is ambiguous when glued (حرفهای = حرفه‌ای یا حرف‌های).
		// A curated list of common ه-ending stems picks the ه+ای reading.
		static $eh_stems = array(
			'حرفه', 'کافه', 'خانه', 'مقاله', 'برنامه', 'نقطه', 'لحظه', 'ذره', 'ویژه', 'گسترده',
			'ساده', 'پیچیده', 'جداگانه', 'دوگانه', 'چندگانه', 'رایانه', 'کارخانه', 'رودخانه',
			'کتابخانه', 'شبکه', 'جلسه', 'مدرسه', 'مزرعه', 'میوه', 'قهوه', 'سرمایه', 'هزینه',
			'گزینه', 'زمینه', 'بهینه', 'نمونه', 'نسخه', 'پایه', 'تازه', 'اندازه', 'منطقه',
			'ماده', 'دوره', 'پروژه', 'مرحله', 'فاصله', 'علاقه', 'وقفه', 'عادلانه', 'ماهانه', 'روزانه',
		);
		if ( preg_match( '/^(.+ه)ای$/u', $word, $mm ) && in_array( $mm[1], $eh_stems, true ) ) {
			return $mm[1] . self::ZWNJ . 'ای';
		}

		$join  = 'بپتثجچحخسشصضطظعغفقکگلمنهیئ';
		$fixed = preg_replace( '/(?<=[' . $join . '])(ها|های|هایی)$/u', self::ZWNJ . '$1', $word );
		if ( null !== $fixed && $fixed === $word ) {
			$fixed = preg_replace( '/ه(ای|مند|مندی|مندان|سازی|گذاری|بندی|ریزی)$/u', 'ه' . self::ZWNJ . '$1', $word );
		}
		return null === $fixed ? $word : $fixed;
	}

	/**
	 * Armor half-spaces for post content: raw U+200C becomes the
	 * &zwnj; HTML entity, which no editor strips.
	 *
	 * @param string $html Post HTML.
	 * @return string
	 */
	public static function entity_zwnj( $html ) {
		return str_replace( self::ZWNJ, '&zwnj;', (string) $html );
	}

	/**
	 * Convenience: fix Persian spacing, then armor for content storage.
	 *
	 * @param string $html Post HTML.
	 * @return string
	 */
	public static function fix_zwnj_html( $html ) {
		return self::entity_zwnj( self::fix_zwnj( $html ) );
	}
}
