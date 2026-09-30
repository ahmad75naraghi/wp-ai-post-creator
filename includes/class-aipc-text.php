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

		// preg_replace() returns null on a (theoretical) PCRE error —
		// never lose the original text in that case.
		return null === $fixed ? $text : $fixed;
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
