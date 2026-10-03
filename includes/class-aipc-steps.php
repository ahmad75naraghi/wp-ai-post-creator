<?php
/**
 * Pipeline step registry: labels, default prompts, placeholders and
 * per-step configuration (connection override + custom prompt template).
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry for the agent pipeline steps.
 */
final class AIPC_Steps {

	const OPTION = 'aipc_steps';

	/**
	 * The step registry. Each entry:
	 *   label         — human name (console + admin)
	 *   kind          — system | chat_json | chat_html | image
	 *   desc          — short description for the admin UI
	 *   prompt        — default prompt template
	 *   placeholders  — placeholder => description (admin UI help)
	 *
	 * @return array
	 */
	public static function registry() {
		return array(

			'system' => array(
				'label' => __( 'System prompt (all steps)', 'wp-ai-post-creator' ),
				'kind'  => 'system',
				'desc'  => __( 'Base identity sent with every request, before the step prompt.', 'wp-ai-post-creator' ),
				'prompt' => 'You are an expert blog writer, copywriter and SEO specialist. You produce original, accurate, engaging and well-structured content. Never mention that you are an AI. Never invent statistics, quotes or sources. Write in {{lang}}. Overall tone: {{tone}}.{{site_prompt}} {{extra}}',
				'placeholders' => array(
					'{{lang}}'        => __( 'Content language name', 'wp-ai-post-creator' ),
					'{{tone}}'        => __( 'Selected tone label', 'wp-ai-post-creator' ),
					'{{site_prompt}}' => __( 'Site prompt context (empty when not configured)', 'wp-ai-post-creator' ),
					'{{extra}}'       => __( 'Extra system instructions from the settings', 'wp-ai-post-creator' ),
				),
			),

			'plan' => array(
				'label' => __( 'Category & topic selection', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Picks one of the existing categories and builds the topic from the site prompt.', 'wp-ai-post-creator' ),
				'prompt' => 'SITE CONTEXT: {{site_context}}

CATEGORIES (existing post categories of this website):
{{categories}}

EXISTING ARTICLES ALREADY PUBLISHED HERE:
{{recent_posts}}
Do NOT pick a topic that duplicates or closely resembles one of these — the new article must cover fresh ground or a clearly different angle.

RESEARCH SOURCES provided by the site owner (titles, links and summaries):
{{sources}}
Use them for inspiration and grounding; you may reference at most 1-2 of them later with a link. Never copy their sentences.

{{topic_hint}}

You are planning a blog article (~{{words}} words) written in {{lang}}.
STEP 1: choose the ONE best-fitting category from the list above — you MUST use its exact name.
STEP 2: decide the topic and a compelling SEO title for it.
STEP 3: from the internal linking candidates below, pick the ones genuinely relevant to this topic (max 4, or an empty list).
INTERNAL LINKING CANDIDATES:
{{link_candidates}}

Return ONLY this JSON object (values in {{lang}} unless noted):
{
  "category": "exact category name copied from the list",
  "title": "compelling SEO title, max 60 characters, in {{lang}}",
  "title_options": ["one alternative title, in {{lang}}"],
  "topic_brief": "2-3 sentences describing what the article will cover, in {{lang}}",
  "audience": "target audience, one sentence, in {{lang}}",
  "intent": "the main search intent, in {{lang}}",
  "primary_keyword": "main keyword, in {{lang}}",
  "secondary_keywords": ["4-8 related keywords, in {{lang}}"],
  "angle": "a unique angle that makes this article stand out, one sentence, in {{lang}}",
  "toc_title": "short label for the table of contents, in {{lang}}",
  "internal_links": [{"title": "copied exactly from the candidates", "url": "its url"}]
}',
				'placeholders' => array(
					'{{site_context}}'     => __( 'The site prompt (or a fallback note)', 'wp-ai-post-creator' ),
					'{{categories}}'       => __( 'Bullet list of the site’s post categories', 'wp-ai-post-creator' ),
					'{{recent_posts}}'     => __( 'Bullet list of existing article titles (avoid duplicating them)', 'wp-ai-post-creator' ),
					'{{sources}}'          => __( 'Research items from the configured source sites (or empty)', 'wp-ai-post-creator' ),
					'{{link_candidates}}'  => __( 'Bullet list of existing articles usable as internal links', 'wp-ai-post-creator' ),
					'{{topic_hint}}'       => __( 'The user’s topic hint, or “invent one” instruction', 'wp-ai-post-creator' ),
					'{{words}}'            => __( 'Target word count for the article', 'wp-ai-post-creator' ),
					'{{sections}}'         => __( 'Planned section count', 'wp-ai-post-creator' ),
					'{{lang}}'             => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'outline' => array(
				'label' => __( 'Article outline', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Builds the section outline for the chosen length.', 'wp-ai-post-creator' ),
				'prompt' => 'Working title: "{{title}}"
Topic brief: {{topic_brief}}
Primary keyword: {{primary_keyword}}
Angle: {{angle}}

Create the outline for a ~{{words}} word article with exactly {{sections}} main sections.
The introduction and conclusion are handled separately — do NOT include them.
Order sections logically for the reader.
Return ONLY this JSON object:
{"sections": [{"heading": "section heading (H2 level, in {{lang}}, no numbering)", "brief": "2-3 sentences describing exactly what this section must cover"}]}',
				'placeholders' => array(
					'{{title}}'           => __( 'Working title', 'wp-ai-post-creator' ),
					'{{topic_brief}}'     => __( 'Topic brief from the plan step', 'wp-ai-post-creator' ),
					'{{primary_keyword}}' => __( 'Primary keyword', 'wp-ai-post-creator' ),
					'{{angle}}'           => __( 'Unique angle from the plan step', 'wp-ai-post-creator' ),
					'{{words}}'           => __( 'Target word count', 'wp-ai-post-creator' ),
					'{{sections}}'        => __( 'Planned section count', 'wp-ai-post-creator' ),
					'{{lang}}'            => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'intro' => array(
				'label' => __( 'Introduction', 'wp-ai-post-creator' ),
				'kind'  => 'chat_html',
				'desc'  => __( 'Writes the article introduction.', 'wp-ai-post-creator' ),
				'prompt' => 'Write the INTRODUCTION for the article "{{title}}".
Topic brief: {{topic_brief}}
Primary keyword: {{primary_keyword}}
Target audience: {{audience}}

Requirements:
- 80-140 words, 1-2 paragraphs, written in {{lang}}
- Hook the reader in the first sentence
- Include the primary keyword naturally
- Briefly state what the reader will learn
- Use only <p> tags — no headings, no lists
HTML fragment only.',
				'placeholders' => array(
					'{{title}}'           => __( 'Working title', 'wp-ai-post-creator' ),
					'{{topic_brief}}'     => __( 'Topic brief', 'wp-ai-post-creator' ),
					'{{primary_keyword}}' => __( 'Primary keyword', 'wp-ai-post-creator' ),
					'{{audience}}'        => __( 'Target audience', 'wp-ai-post-creator' ),
					'{{lang}}'            => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'section' => array(
				'label' => __( 'Body section', 'wp-ai-post-creator' ),
				'kind'  => 'chat_html',
				'desc'  => __( 'Writes one body section (runs once per outline section).', 'wp-ai-post-creator' ),
				'prompt' => 'You are writing section {{index}} of {{total}} for the article "{{title}}".

SECTION HEADING: {{section_heading}}
WHAT TO COVER: {{section_brief}}
TARGET LENGTH: about {{per_words}} words
PRIMARY KEYWORD (use once, naturally): {{primary_keyword}}
{{keywords_block}}{{prev_block}}{{internal_links}}
RULES:
- Do NOT repeat the section heading — start directly with the content
- You may use <h3>/<h4> subheadings, <p>, <ul>, <ol>, <table>, <blockquote>, <strong>, <em>
- If the internal-links list above is present, weave at most 1-2 of those links naturally into the text where they genuinely help the reader
- Be specific and useful; no filler, no generic fluff
HTML fragment only.',
				'placeholders' => array(
					'{{index}}'           => __( 'Current section number (1-based)', 'wp-ai-post-creator' ),
					'{{total}}'           => __( 'Total section count', 'wp-ai-post-creator' ),
					'{{title}}'           => __( 'Working title', 'wp-ai-post-creator' ),
					'{{section_heading}}' => __( 'This section’s heading', 'wp-ai-post-creator' ),
					'{{section_brief}}'   => __( 'What this section must cover', 'wp-ai-post-creator' ),
					'{{per_words}}'       => __( 'Word target for this section', 'wp-ai-post-creator' ),
					'{{primary_keyword}}' => __( 'Primary keyword', 'wp-ai-post-creator' ),
					'{{keywords_block}}'  => __( 'Secondary keywords line (or empty)', 'wp-ai-post-creator' ),
					'{{prev_block}}'      => __( 'Previous section tail for continuity (or empty)', 'wp-ai-post-creator' ),
					'{{internal_links}}'  => __( 'Internal linking list chosen in the plan step (or empty)', 'wp-ai-post-creator' ),
				),
			),

			'conclusion' => array(
				'label' => __( 'Conclusion & call to action', 'wp-ai-post-creator' ),
				'kind'  => 'chat_html',
				'desc'  => __( 'Writes the conclusion with a call to action.', 'wp-ai-post-creator' ),
				'prompt' => 'Write the CONCLUSION for the article "{{title}}".
The article covered:
{{headings}}

Requirements:
- Start with a single <h2> heading in {{lang}} (e.g. the local word for "Conclusion")
- Then 1-2 paragraphs (100-160 words total)
- Summarize the key takeaways, then end with a clear call to action (comment, share, or read a related article)
HTML fragment only.',
				'placeholders' => array(
					'{{title}}'    => __( 'Working title', 'wp-ai-post-creator' ),
					'{{headings}}' => __( 'Bullet list of section headings', 'wp-ai-post-creator' ),
					'{{lang}}'     => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'copywrite' => array(
				'label' => __( 'Copywriting & SEO pass', 'wp-ai-post-creator' ),
				'kind'  => 'chat_html',
				'desc'  => __( 'Revises the whole draft for copy quality, SEO and originality.', 'wp-ai-post-creator' ),
				'prompt' => 'COPYWRITING & SEO REVISION PASS.
Below is the draft body of the article "{{title}}". Improve it as a professional copywriter and SEO editor:
- COPYWRITING: sharper hooks, clearer flow, more persuasive and specific wording, remove filler and repetition.
- SEO: natural use of the primary keyword \'{{primary_keyword}}\', better subheading phrasing, stronger topic sentences.
- ORIGINALITY: rephrase anything that reads like generic boilerplate or copied phrasing — the final text must be original and pass as human-written.
- LINKS: keep every <a href> link that exists in the draft (internal and external) — you may reposition or rephrase the anchor text, but never drop a link.
{{internal_links}}
STRICT FORMAT RULES (the pipeline breaks without them):
- Return ONLY the article body as raw HTML.
- Structure: an introduction with NO <h2> heading, then exactly {{sections}} main sections each starting with its own <h2> heading, then ONE final <h2> conclusion block.
- Do not add or remove sections, do not merge them; no TOC, no FAQ, no title tag.
- Keep the same language and overall meaning; keep it approximately the same length.

DRAFT:
{{draft}}',
				'placeholders' => array(
					'{{title}}'           => __( 'Working title', 'wp-ai-post-creator' ),
					'{{primary_keyword}}' => __( 'Primary keyword', 'wp-ai-post-creator' ),
					'{{sections}}'        => __( 'Section count', 'wp-ai-post-creator' ),
					'{{draft}}'           => __( 'The full assembled draft HTML', 'wp-ai-post-creator' ),
					'{{internal_links}}'  => __( 'Reminder of the internal links that must survive the revision', 'wp-ai-post-creator' ),
				),
			),

			'faq' => array(
				'label' => __( 'FAQ section (rich results)', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Writes the FAQ questions and answers.', 'wp-ai-post-creator' ),
				'prompt' => 'For the article "{{title}}", write 4-5 FAQ questions a reader would also ask (the "People also ask" style).
Return ONLY this JSON object, everything in {{lang}}:
{"faq_heading": "short H2 heading for the FAQ block, in {{lang}}", "items": [{"q": "question", "a": "1-3 sentence answer"}]}',
				'placeholders' => array(
					'{{title}}' => __( 'Working title', 'wp-ai-post-creator' ),
					'{{lang}}'  => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'seo' => array(
				'label' => __( 'SEO summary for Rank Math', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Meta title/description (the Rank Math summary), slug, excerpt and tags.', 'wp-ai-post-creator' ),
				'prompt' => 'SEO metadata + Rank Math summary for the article "{{title}}".
Opening of the article: {{intro}}

Generate SEO metadata. The meta_description is THE summary used for Rank Math — it must be compelling and contain the primary keyword.
Return ONLY this JSON object:
{
  "meta_title": "SEO title, max 60 characters, in {{lang}}",
  "meta_description": "meta description / Rank Math summary, max 155 characters, includes the primary keyword, in {{lang}}",
  "slug": "english-url-friendly-slug — lowercase English words separated by hyphens, max 6 words",
  "excerpt": "post excerpt, max 160 characters, in {{lang}}",
  "tags": ["5-8 short tag words, in {{lang}}"]
}',
				'placeholders' => array(
					'{{title}}' => __( 'Working title', 'wp-ai-post-creator' ),
					'{{intro}}' => __( 'Opening of the finished introduction', 'wp-ai-post-creator' ),
					'{{lang}}'  => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'image_prompt' => array(
				'label' => __( 'Image prompt', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Turns the topic + Rank Math summary into an image-generation prompt.', 'wp-ai-post-creator' ),
				'prompt' => 'Create a JSON object with one key "prompt": a detailed image-generation prompt (max 60 words) for a professional featured/hero blog image. Also include a key "keywords": 2-4 short English stock-photo search words naming the main visible subject.
Article title: "{{title}}".
Article summary: "{{summary}}".
Style: modern, editorial, visually striking, high quality.
CRITICAL: the image must contain NO text, NO words, NO letters, NO watermarks.
Describe the scene only. JSON only.',
				'placeholders' => array(
					'{{title}}'   => __( 'Working title', 'wp-ai-post-creator' ),
					'{{summary}}' => __( 'Rank Math summary (meta description)', 'wp-ai-post-creator' ),
				),
			),

			'image' => array(
				'label' => __( 'Featured image (image API)', 'wp-ai-post-creator' ),
				'kind'  => 'image',
				'desc'  => __( 'Calls the image API of the assigned connection and saves the result to the media library. No prompt — the image model and size come from the connection and settings.', 'wp-ai-post-creator' ),
				'prompt' => '',
				'placeholders' => array(),
			),

			'rw_analyze' => array(
				'label' => __( 'Rewrite: analysis & new outline', 'wp-ai-post-creator' ),
				'kind'  => 'chat_json',
				'desc'  => __( 'Analyzes the existing post (rewrite mode) and builds the improved outline.', 'wp-ai-post-creator' ),
				'prompt' => 'REWRITE ANALYSIS — an existing published article must be refreshed.
Existing title: "{{existing_title}}"
EXISTING ARTICLE CONTENT:
{{existing_content}}

SITE CONTEXT: {{site_context}}

RESEARCH SOURCES provided by the site owner:
{{sources}}

INTERNAL LINKING CANDIDATES (other articles of this site):
{{link_candidates}}

TASK: analyse the existing article and plan its refresh (~{{words}} words, {{lang}}). Keep its core topic and every fact that is still valid; fix anything outdated; find a better angle, a sharper SEO title and a cleaner structure.

Return ONLY this JSON object:
{
  "title": "improved SEO title, max 60 characters, in {{lang}} — keep the original meaning",
  "primary_keyword": "main keyword, in {{lang}}",
  "secondary_keywords": ["4-8 related keywords, in {{lang}}"],
  "notes": "3-5 bullet-style notes in {{lang}} on what to keep, fix and improve",
  "sections": [{"heading": "section heading (H2 level, in {{lang}})", "brief": "2-3 sentences describing exactly what this section must cover"}],
  "internal_links": [{"title": "copied from the candidates", "url": "its url"}]
}',
				'placeholders' => array(
					'{{existing_title}}'   => __( 'Title of the post being rewritten', 'wp-ai-post-creator' ),
					'{{existing_content}}' => __( 'The current content of the post being rewritten', 'wp-ai-post-creator' ),
					'{{site_context}}'     => __( 'The site prompt (or a fallback note)', 'wp-ai-post-creator' ),
					'{{sources}}'          => __( 'Research items from the configured source sites (or empty)', 'wp-ai-post-creator' ),
					'{{link_candidates}}'  => __( 'Bullet list of this site’s other articles usable as internal links', 'wp-ai-post-creator' ),
					'{{words}}'            => __( 'Target word count for the article', 'wp-ai-post-creator' ),
					'{{lang}}'             => __( 'Content language name', 'wp-ai-post-creator' ),
				),
			),

			'rw_rewrite' => array(
				'label' => __( 'Rewrite: full revision pass', 'wp-ai-post-creator' ),
				'kind'  => 'chat_html',
				'desc'  => __( 'Rewrites the whole existing post: fresher copy, better SEO, full originality.', 'wp-ai-post-creator' ),
				'prompt' => 'FULL REWRITE of the article "{{title}}".
IMPROVEMENT NOTES from the analysis:
{{notes}}

RESEARCH SOURCES provided by the site owner:
{{sources}}

Rewrite the existing article below as a professional copywriter and SEO editor:
- COPYWRITING: sharper hooks, clearer flow, more persuasive and specific wording, remove filler and repetition.
- SEO: natural use of the primary keyword \'{{primary_keyword}}\', better subheading phrasing, stronger topic sentences.
- ORIGINALITY: rephrase every sentence that reads like generic boilerplate — the final text must be original and pass as human-written; keep all facts from the original that are still valid.
- LINKS: keep every <a href> link from the original content, and weave in the internal links listed below where they genuinely help.
{{internal_links}}

STRICT FORMAT RULES (the pipeline breaks without them):
- Return ONLY the article body as raw HTML.
- Structure: an introduction with NO <h2> heading, then exactly {{sections}} main sections each starting with its own <h2> heading, then ONE final <h2> conclusion block.
- Do not add or remove sections, do not merge them; no TOC, no FAQ, no title tag.
- Same language as the original, approximately the same length or longer.

EXISTING ARTICLE:
{{draft}}',
				'placeholders' => array(
					'{{title}}'           => __( 'Improved title from the analysis step', 'wp-ai-post-creator' ),
					'{{notes}}'           => __( 'Improvement notes from the analysis step', 'wp-ai-post-creator' ),
					'{{primary_keyword}}' => __( 'Primary keyword', 'wp-ai-post-creator' ),
					'{{sections}}'        => __( 'Section count from the analysis step', 'wp-ai-post-creator' ),
					'{{sources}}'         => __( 'Research items from the configured source sites (or empty)', 'wp-ai-post-creator' ),
					'{{internal_links}}'  => __( 'Internal links to weave in (or empty)', 'wp-ai-post-creator' ),
					'{{draft}}'           => __( 'The current content of the post being rewritten', 'wp-ai-post-creator' ),
				),
			),
		);
	}

	/**
	 * All per-step configuration (connection override + custom prompt).
	 *
	 * @return array step => {connection: string, prompt: string}
	 */
	public static function all_config() {
		$cfg = get_option( self::OPTION, array() );
		return is_array( $cfg ) ? $cfg : array();
	}

	/**
	 * One step's configuration merged with defaults.
	 *
	 * @param string $step Step id.
	 * @return array {connection: string, prompt: string}
	 */
	public static function get( $step ) {
		$cfg = self::all_config();

		$connections = array();
		if ( isset( $cfg[ $step ]['connections'] ) && is_array( $cfg[ $step ]['connections'] ) ) {
			$connections = $cfg[ $step ]['connections'];
		} elseif ( ! empty( $cfg[ $step ]['connection'] ) ) {
			$connections = array( $cfg[ $step ]['connection'] ); // Legacy 1.2–1.4 format.
		}

		return array(
			'connections' => array_values( array_filter( array_map( 'strval', (array) $connections ) ) ),
			'prompt'      => isset( $cfg[ $step ]['prompt'] ) ? (string) $cfg[ $step ]['prompt'] : '',
		);
	}

	/**
	 * Effective prompt for a step (custom or the default template).
	 *
	 * @param string $step Step id.
	 * @return string
	 */
	public static function prompt_for( $step ) {
		$cfg   = self::get( $step );
		$reg   = self::registry();
		$default = isset( $reg[ $step ]['prompt'] ) ? $reg[ $step ]['prompt'] : '';
		$custom  = trim( $cfg['prompt'] );
		if ( '' === $custom || $custom === $default ) {
			return $default;
		}
		return $custom;
	}

	/**
	 * Whether a step has a custom (non-default) prompt.
	 *
	 * @param string $step Step id.
	 * @return bool
	 */
	public static function has_custom_prompt( $step ) {
		$cfg = self::get( $step );
		$reg = self::registry();
		$default = isset( $reg[ $step ]['prompt'] ) ? $reg[ $step ]['prompt'] : '';
		return '' !== trim( $cfg['prompt'] ) && trim( $cfg['prompt'] ) !== $default;
	}

	/**
	 * Save the whole configuration map.
	 *
	 * @param array $cfg step => {connection, prompt}.
	 * @return void
	 */
	public static function save_all( $cfg ) {
		$clean = array();
		$reg   = self::registry();

		foreach ( $reg as $step => $meta ) {
			if ( ! isset( $cfg[ $step ] ) || ! is_array( $cfg[ $step ] ) ) {
				continue;
			}
			$prompt  = isset( $cfg[ $step ]['prompt'] ) ? trim( (string) $cfg[ $step ]['prompt'] ) : '';
			$default = isset( $meta['prompt'] ) ? $meta['prompt'] : '';

			if ( ! empty( $cfg[ $step ]['reset'] ) ) {
				$prompt = '';
			}
			if ( '' !== $prompt && $prompt === $default ) {
				$prompt = ''; // Identical to default — store as default.
			}

			// Connection chain: ordered list of connection ids (fallback order).
			$raw = array();
			if ( isset( $cfg[ $step ]['connections'] ) && is_array( $cfg[ $step ]['connections'] ) ) {
				$raw = $cfg[ $step ]['connections'];
			} elseif ( ! empty( $cfg[ $step ]['connection'] ) ) {
				$raw = array( $cfg[ $step ]['connection'] ); // Legacy single select.
			}

			$connections = array();
			foreach ( $raw as $conn_id ) {
				$conn_id = sanitize_key( (string) $conn_id );
				if ( '' !== $conn_id && AIPC_Connections::get( $conn_id ) && ! in_array( $conn_id, $connections, true ) ) {
					$connections[] = $conn_id;
				}
			}

			$clean[ $step ] = array(
				'connections' => $connections,
				'prompt'      => mb_substr( $prompt, 0, 20000 ),
			);
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $clean, '', false );
		} else {
			update_option( self::OPTION, $clean );
		}
	}
}
