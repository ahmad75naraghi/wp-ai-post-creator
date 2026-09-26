<?php
/**
 * Settings storage and defaults.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central access point for plugin settings.
 */
final class AIPC_Settings {

	const OPTION = 'aipc_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'             => '',
			'api_base_url'        => 'https://api.openai.com/v1',
			'chat_model'          => 'gpt-4o-mini',
			'image_enabled'       => 1,
			'image_model'         => 'dall-e-3',
			'image_size'          => '1792x1024',
			'temperature'         => 0.7,
			'max_tokens'          => 4000,
			'request_timeout'     => 120,
			'site_prompt'         => '',
			'content_language'    => self::default_language(),
			'default_tone'        => 'professional',
			'default_length'      => 'medium',
			'add_toc'             => 1,
			'add_faq'             => 1,
			'system_prompt_extra' => '',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Pick the default content language from the site locale.
	 *
	 * @return string
	 */
	public static function default_language() {
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		return ( 0 === strpos( $locale, 'fa' ) ) ? 'fa' : 'en';
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Available writing tones.
	 *
	 * @return array code => label.
	 */
	public static function tones() {
		return array(
			'professional'  => __( 'Professional', 'wp-ai-post-creator' ),
			'friendly'      => __( 'Friendly', 'wp-ai-post-creator' ),
			'casual'        => __( 'Casual', 'wp-ai-post-creator' ),
			'expert'        => __( 'Expert / technical', 'wp-ai-post-creator' ),
			'persuasive'    => __( 'Persuasive', 'wp-ai-post-creator' ),
			'storytelling'  => __( 'Storytelling', 'wp-ai-post-creator' ),
			'humorous'      => __( 'Humorous', 'wp-ai-post-creator' ),
			'neutral'       => __( 'Neutral', 'wp-ai-post-creator' ),
		);
	}

	/**
	 * Available article lengths.
	 *
	 * @return array code => label.
	 */
	public static function lengths() {
		return array(
			'short'  => __( 'Short — ~600 words', 'wp-ai-post-creator' ),
			'medium' => __( 'Medium — ~1,200 words', 'wp-ai-post-creator' ),
			'long'   => __( 'Long — ~2,200 words', 'wp-ai-post-creator' ),
		);
	}

	/**
	 * Word / section targets per length.
	 *
	 * @param string $code Length code.
	 * @return array { words: int, sections: int }
	 */
	public static function length_specs( $code ) {
		switch ( $code ) {
			case 'short':
				return array( 'words' => 600, 'sections' => 3 );
			case 'long':
				return array( 'words' => 2200, 'sections' => 8 );
			case 'medium':
			default:
				return array( 'words' => 1200, 'sections' => 5 );
		}
	}

	/**
	 * Supported content languages.
	 *
	 * @return array code => label.
	 */
	public static function languages() {
		return array(
			'fa'    => __( 'Persian (Farsi)', 'wp-ai-post-creator' ),
			'en'    => __( 'English', 'wp-ai-post-creator' ),
			'ar'    => __( 'Arabic', 'wp-ai-post-creator' ),
			'tr'    => __( 'Turkish', 'wp-ai-post-creator' ),
			'de'    => __( 'German', 'wp-ai-post-creator' ),
			'fr'    => __( 'French', 'wp-ai-post-creator' ),
			'es'    => __( 'Spanish', 'wp-ai-post-creator' ),
			'ru'    => __( 'Russian', 'wp-ai-post-creator' ),
			'pt'    => __( 'Portuguese', 'wp-ai-post-creator' ),
			'hi'    => __( 'Hindi', 'wp-ai-post-creator' ),
			'zh'    => __( 'Chinese', 'wp-ai-post-creator' ),
			'other' => __( 'Other…', 'wp-ai-post-creator' ),
		);
	}

	/**
	 * Human-readable language name for prompts.
	 *
	 * @param string $code        Language code ('fa', 'en', … or 'other').
	 * @param string $custom_name Free-text language when $code is 'other'.
	 * @return string
	 */
	public static function language_name( $code, $custom_name = '' ) {
		$map = array(
			'fa' => 'Persian (Farsi)',
			'en' => 'English',
			'ar' => 'Arabic',
			'tr' => 'Turkish',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'ru' => 'Russian',
			'pt' => 'Portuguese',
			'hi' => 'Hindi',
			'zh' => 'Chinese (Simplified)',
		);
		if ( 'other' === $code ) {
			$name = trim( (string) $custom_name );
			return '' !== $name ? $name : 'English';
		}
		return isset( $map[ $code ] ) ? $map[ $code ] : 'English';
	}

	/**
	 * Allowed featured-image sizes.
	 *
	 * @return array
	 */
	public static function image_sizes() {
		return array( '1024x1024', '1792x1024', '1024x1792', '1536x1024', '1024x1536', 'auto' );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param array $input Raw settings input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old  = self::all();
		$in   = is_array( $input ) ? $input : array();
		$out  = array();

		// API key: an empty submitted value keeps the stored key.
		$key          = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		$out['api_key'] = ( '' !== $key ) ? $key : $old['api_key'];

		$base = isset( $in['api_base_url'] ) ? esc_url_raw( trim( (string) $in['api_base_url'] ) ) : '';
		if ( '' === $base || 0 !== strpos( $base, 'http' ) ) {
			$base = $old['api_base_url'];
		}
		$out['api_base_url'] = untrailingslashit( $base );

		$out['chat_model'] = isset( $in['chat_model'] ) ? sanitize_text_field( $in['chat_model'] ) : '';
		if ( '' === $out['chat_model'] ) {
			$out['chat_model'] = $old['chat_model'];
		}

		$out['temperature'] = isset( $in['temperature'] ) ? (float) $in['temperature'] : $old['temperature'];
		if ( $out['temperature'] < 0 || $out['temperature'] > 2 ) {
			$out['temperature'] = 0.7;
		}

		$out['max_tokens'] = isset( $in['max_tokens'] ) ? absint( $in['max_tokens'] ) : $old['max_tokens'];
		if ( $out['max_tokens'] > 16000 ) {
			$out['max_tokens'] = 16000;
		}

		$out['request_timeout'] = isset( $in['request_timeout'] ) ? absint( $in['request_timeout'] ) : $old['request_timeout'];
		if ( $out['request_timeout'] < 15 ) {
			$out['request_timeout'] = 15;
		} elseif ( $out['request_timeout'] > 600 ) {
			$out['request_timeout'] = 600;
		}

		$out['content_language'] = isset( $in['content_language'] ) ? sanitize_key( $in['content_language'] ) : $old['content_language'];
		if ( ! array_key_exists( $out['content_language'], self::languages() ) ) {
			$out['content_language'] = self::default_language();
		}

		$out['default_tone'] = isset( $in['default_tone'] ) ? sanitize_key( $in['default_tone'] ) : $old['default_tone'];
		if ( ! array_key_exists( $out['default_tone'], self::tones() ) ) {
			$out['default_tone'] = 'professional';
		}

		$out['default_length'] = isset( $in['default_length'] ) ? sanitize_key( $in['default_length'] ) : $old['default_length'];
		if ( ! array_key_exists( $out['default_length'], self::lengths() ) ) {
			$out['default_length'] = 'medium';
		}

		$site_prompt = isset( $in['site_prompt'] ) ? sanitize_textarea_field( $in['site_prompt'] ) : $old['site_prompt'];
		$out['site_prompt'] = mb_substr( trim( $site_prompt ), 0, 4000 );

		$out['image_model'] = isset( $in['image_model'] ) ? sanitize_text_field( $in['image_model'] ) : $old['image_model'];

		$out['image_size'] = isset( $in['image_size'] ) ? sanitize_text_field( $in['image_size'] ) : $old['image_size'];
		if ( ! in_array( $out['image_size'], self::image_sizes(), true ) ) {
			$out['image_size'] = $old['image_size'];
		}

		$bools = array( 'image_enabled', 'add_toc', 'add_faq', 'delete_on_uninstall' );
		foreach ( $bools as $bool ) {
			$out[ $bool ] = empty( $in[ $bool ] ) ? 0 : 1;
		}

		$extra = isset( $in['system_prompt_extra'] ) ? sanitize_textarea_field( $in['system_prompt_extra'] ) : '';
		$out['system_prompt_extra'] = mb_substr( $extra, 0, 2000 );

		return $out;
	}
}
