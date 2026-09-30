<?php
/**
 * Assembles and persists the generated post.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the final WordPress post from agent data.
 */
final class AIPC_Post_Builder {

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'the_content', array( __CLASS__, 'append_schema' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
	}

	/**
	 * Enqueue minimal front-end styles for generated posts.
	 *
	 * @return void
	 */
	public static function enqueue_front() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! get_post_meta( $post_id, '_aipc_generated', true ) ) {
			return;
		}
		wp_enqueue_style( 'aipc-front', AIPC_PLUGIN_URL . 'assets/front.css', array(), AIPC_VERSION );
	}

	/**
	 * Append the FAQ JSON-LD schema stored in post meta.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function append_schema( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}
		$schema = get_post_meta( $post_id, '_aipc_faq_schema', true );
		return $schema ? $content . "\n" . $schema : $content;
	}

	/**
	 * Assemble the final post HTML from agent data.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function build_content( array $job ) {
		$d      = $job['data'];
		$headings = array();
		foreach ( $d['outline'] as $section ) {
			$headings[] = $section['heading'];
		}

		$html = '';

		// Table of contents.
		if ( $job['args']['toc'] && count( $headings ) > 2 ) {
			$toc_title = ! empty( $d['plan']['toc_title'] ) ? $d['plan']['toc_title'] : __( 'Table of contents', 'wp-ai-post-creator' );
			$html     .= self::build_toc( $headings, $toc_title );
		}

		// Introduction.
		$html .= isset( $d['content']['intro'] ) ? trim( $d['content']['intro'] ) : '';

		// Body sections with anchored H2 headings.
		foreach ( $d['outline'] as $i => $section ) {
			$sec = isset( $d['content']['sections'][ $i ] ) ? trim( $d['content']['sections'][ $i ] ) : '';
			$html .= "\n\n" . '<h2 id="aipc-s-' . (int) $i . '">' . esc_html( $section['heading'] ) . '</h2>' . "\n" . $sec;
		}

		// Conclusion (model-written, includes its own H2).
		if ( ! empty( $d['content']['conclusion'] ) ) {
			$html .= "\n\n" . trim( $d['content']['conclusion'] );
		}

		// FAQ.
		if ( ! empty( $d['faq']['items'] ) ) {
			$heading = ! empty( $d['faq']['heading'] ) ? $d['faq']['heading'] : __( 'Frequently asked questions', 'wp-ai-post-creator' );
			$html   .= "\n\n" . '<h2 id="aipc-faq">' . esc_html( $heading ) . '</h2>';
			foreach ( $d['faq']['items'] as $item ) {
				$html .= "\n" . '<h3>' . esc_html( $item['q'] ) . '</h3>' . "\n" . '<p>' . esc_html( $item['a'] ) . '</p>';
			}
		}

		return trim( $html );
	}

	/**
	 * Build the table of contents block.
	 *
	 * @param array  $headings Section headings.
	 * @param string $title    Block title.
	 * @return string
	 */
	public static function build_toc( array $headings, $title ) {
		$out = '<div class="aipc-toc"><p class="aipc-toc-title">' . esc_html( $title ) . '</p><ul>';
		foreach ( $headings as $i => $heading ) {
			$out .= '<li><a href="#aipc-s-' . (int) $i . '">' . esc_html( $heading ) . '</a></li>';
		}
		$out .= '</ul></div>';
		return $out;
	}

	/**
	 * Build FAQ JSON-LD schema.
	 *
	 * @param array $items List of {q, a}.
	 * @return string
	 */
	public static function faq_schema( array $items ) {
		$entities = array();
		foreach ( $items as $item ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $item['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['a'],
				),
			);
		}
		$ld = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
		return '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
	}

	/**
	 * Create the WordPress post from job data. The post is ALWAYS saved as a
	 * draft for human review.
	 *
	 * @param array $job Job.
	 * @return int|WP_Error Post id.
	 */
	public static function create( array $job ) {
		$d = $job['data'];

		$content = self::build_content( $job );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'aipc_empty', __( 'Generated content was empty.', 'wp-ai-post-creator' ) );
		}

		$postarr = array(
			'post_title'   => AIPC_Text::fix_zwnj( sanitize_text_field( $d['plan']['title'] ) ),
			'post_name'    => ! empty( $d['seo']['slug'] ) ? $d['seo']['slug'] : sanitize_title( $d['plan']['title'] ),
			'post_content' => AIPC_Text::fix_zwnj_html( $content ),
			'post_excerpt' => AIPC_Text::fix_zwnj( isset( $d['seo']['excerpt'] ) ? $d['seo']['excerpt'] : '' ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);

		/**
		 * Filter the arguments passed to wp_insert_post().
		 *
		 * @param array $postarr Post args.
		 * @param array $job     Agent job.
		 */
		$postarr = apply_filters( 'aipc_post_args', $postarr, $job );

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Tags (wp_set_object_terms creates missing terms by name).
		$tags = ! empty( $d['seo']['tags'] ) ? (array) $d['seo']['tags'] : array();
		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', $tags ), 'post_tag', true );
		}

		// Category chosen by the agent during the plan step.
		$cat_id = ! empty( $d['plan']['category_id'] ) ? (int) $d['plan']['category_id'] : 0;
		if ( ! $cat_id ) {
			$cat_id = (int) get_option( 'default_category' );
		}
		if ( $cat_id ) {
			// Replace the default category rather than appending to it.
			wp_set_object_terms( $post_id, array( $cat_id ), 'category', false );
		}

		// SEO meta: plugin keys + the big SEO plugins.
		self::set_seo_meta( $post_id, isset( $d['seo'] ) ? $d['seo'] : array() );

		// Focus keyword (Rank Math / Yoast) from the plan step.
		$focus = ! empty( $d['plan']['primary_keyword'] ) ? sanitize_text_field( (string) $d['plan']['primary_keyword'] ) : '';
		if ( '' !== $focus ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', $focus );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
		}

		// Featured image.
		if ( ! empty( $d['image']['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $d['image']['attachment_id'] );
		}

		// Markers + FAQ schema.
		update_post_meta( $post_id, '_aipc_generated', time() );
		update_post_meta( $post_id, '_aipc_job', $job['id'] );
		if ( ! empty( $d['faq']['items'] ) ) {
			update_post_meta( $post_id, '_aipc_faq_schema', self::faq_schema( $d['faq']['items'] ) );
		}

		return (int) $post_id;
	}

	/**
	 * Update an existing post from job data (rewrite mode). The post id,
	 * status, author, slug and categories are preserved.
	 *
	 * @param array $job Job.
	 * @return int|WP_Error Post id.
	 */
	public static function update( array $job ) {
		$d       = $job['data'];
		$post_id = (int) $job['args']['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'aipc_rewrite', __( 'The post to rewrite was not found.', 'wp-ai-post-creator' ) );
		}

		$content = self::build_content( $job );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'aipc_empty', __( 'Generated content was empty.', 'wp-ai-post-creator' ) );
		}

		$postarr = array(
			'ID'           => $post_id,
			'post_title'   => AIPC_Text::fix_zwnj( sanitize_text_field( $d['plan']['title'] ) ),
			'post_content' => AIPC_Text::fix_zwnj_html( $content ),
			'post_excerpt' => ! empty( $d['seo']['excerpt'] ) ? $d['seo']['excerpt'] : $post->post_excerpt,
		);

		/**
		 * Filter the arguments passed to wp_update_post() in rewrite mode.
		 *
		 * @param array $postarr Post args.
		 * @param array $job     Agent job.
		 */
		$postarr = apply_filters( 'aipc_post_args', $postarr, $job );

		$res = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Tags: append the fresh ones to the existing set.
		$tags = ! empty( $d['seo']['tags'] ) ? array_map( 'sanitize_text_field', (array) $d['seo']['tags'] ) : array();
		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $post_id, $tags, 'post_tag', true );
		}

		self::set_seo_meta( $post_id, isset( $d['seo'] ) ? $d['seo'] : array() );

		$focus = ! empty( $d['plan']['primary_keyword'] ) ? sanitize_text_field( (string) $d['plan']['primary_keyword'] ) : '';
		if ( '' !== $focus ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', $focus );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
		}

		if ( ! empty( $d['image']['attachment_id'] ) ) {
			set_post_thumbnail( $post_id, (int) $d['image']['attachment_id'] );
		}

		update_post_meta( $post_id, '_aipc_generated', time() );
		update_post_meta( $post_id, '_aipc_job', $job['id'] );
		if ( ! empty( $d['faq']['items'] ) ) {
			update_post_meta( $post_id, '_aipc_faq_schema', self::faq_schema( $d['faq']['items'] ) );
		}

		return (int) $post_id;
	}

	/**
	 * Store SEO meta for the plugin and popular SEO plugins.
	 *
	 * @param int   $post_id Post id.
	 * @param array $seo     SEO data.
	 * @return void
	 */
	public static function set_seo_meta( $post_id, $seo ) {
		$title = AIPC_Text::fix_zwnj( isset( $seo['meta_title'] ) ? sanitize_text_field( $seo['meta_title'] ) : '' );
		$desc  = AIPC_Text::fix_zwnj( isset( $seo['meta_description'] ) ? sanitize_text_field( $seo['meta_description'] ) : '' );

		if ( '' === $title && '' === $desc ) {
			return;
		}

		update_post_meta( $post_id, '_aipc_meta_title', $title );
		update_post_meta( $post_id, '_aipc_meta_description', $desc );

		// Yoast SEO.
		update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );

		// Rank Math.
		update_post_meta( $post_id, 'rank_math_title', $title );
		update_post_meta( $post_id, 'rank_math_description', $desc );

		// All in One SEO (legacy per-post key).
		update_post_meta( $post_id, '_aioseo_description', $desc );
	}

	/**
	 * Upload raw image bytes into the media library.
	 *
	 * @param string $bits     Image data.
	 * @param string $filename Desired filename.
	 * @param string $alt      Alt text.
	 * @return int|WP_Error Attachment id.
	 */
	public static function upload_image( $bits, $filename, $alt ) {
		if ( empty( $bits ) ) {
			return new WP_Error( 'aipc_upload', __( 'Empty image data.', 'wp-ai-post-creator' ) );
		}

		$filename = sanitize_file_name( $filename );
		$upload   = wp_upload_bits( $filename, null, $bits );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'aipc_upload', $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = array(
			'post_mime_type' => ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/png',
			'post_title'     => sanitize_text_field( $alt ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attach_id, $metadata );
		}

		update_post_meta( $attach_id, '_wp_attachment_image_alt', mb_substr( sanitize_text_field( $alt ), 0, 125 ) );

		return (int) $attach_id;
	}
}
