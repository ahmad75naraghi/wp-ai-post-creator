<?php
/**
 * E2E phase 2: drive the agent through the real REST stack and assert results.
 */
error_reporting( E_ALL & ~E_DEPRECATED );

$root = getenv( 'E2E_WP_ROOT' );
if ( ! $root ) {
	$root = '/home/user/.cache/e2e/wordpress';
}

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_USE_THEMES', false );

require $root . '/wp-load.php';

$out = array();

/* ------------------------------------------------------------------ *
 * Unit checks: JSON extraction
 * ------------------------------------------------------------------ */
$out['unit_extract_json'] = array(
	'fence'   => AIPC_API_Client::extract_json( "```json\n{\"a\":1}\n```" ) === array( 'a' => 1 ),
	'chatter' => AIPC_API_Client::extract_json( 'Sure! Here it is: {"a": {"b": "x}y"}, "c": [1,2]} hope it helps' ) === array( 'a' => array( 'b' => 'x}y' ), 'c' => array( 1, 2 ) ),
	'tricky'  => AIPC_API_Client::extract_json( '{"a": "} tricky {"}' ) === array( 'a' => '} tricky {' ),
	'invalid' => AIPC_API_Client::extract_json( 'no json here' ) === null,
);

/* ------------------------------------------------------------------ *
 * Connections & steps configuration
 * ------------------------------------------------------------------ */
rest_get_server();
wp_set_current_user( 1 );

$connections = AIPC_Connections::all();
$out['connections'] = array(
	'count'         => count( $connections ),
	'default_name'  => AIPC_Connections::get_default()['name'],
	'faq_custom'    => AIPC_Steps::has_custom_prompt( 'faq' ),
	'plan_default'  => AIPC_Steps::prompt_for( 'plan' ) === AIPC_Steps::registry()['plan']['prompt'],
	'image_chain'   => AIPC_Steps::get( 'image' )['connections'] === array( $connections[1]['id'] ),
	'prompt_marker' => false !== strpos( AIPC_Steps::prompt_for( 'faq' ), 'FAQ-QUESTIONS-CUSTOM' ),
	'steps_count'   => count( AIPC_Steps::registry() ),
	'has_rw_steps'  => isset( AIPC_Steps::registry()['rw_analyze'] ) && isset( AIPC_Steps::registry()['rw_rewrite'] ),
	'rw_prompt'     => false !== strpos( AIPC_Steps::registry()['rw_analyze']['prompt'], 'REWRITE ANALYSIS' ),
	'source_sites'  => 'https://news.invalid' === AIPC_Settings::all()['source_sites'],
);

/* ------------------------------------------------------------------ *
 * REST: connection test + models (saved id, and raw config)
 * ------------------------------------------------------------------ */
$req = new WP_REST_Request( 'POST', '/aipc/v1/connection/test' );
$req->set_param( 'id', $connections[0]['id'] );
$resp = rest_do_request( $req );
$out['rest_conn_test_by_id'] = array(
	'status' => $resp->get_status(),
	'ok'     => ! empty( $resp->get_data()['ok'] ),
);

$req = new WP_REST_Request( 'POST', '/aipc/v1/connection/models' );
$req->set_param( 'base_url', 'https://mock.invalid/v1' );
$req->set_param( 'api_key', 'sk-chat-key' );
$resp = rest_do_request( $req );
$out['rest_conn_models_raw'] = array(
	'status' => $resp->get_status(),
	'count'  => count( $resp->get_data()['models'] ?? array() ),
);

/* ------------------------------------------------------------------ *
 * REST: permission check — anonymous user must be rejected
 * ------------------------------------------------------------------ */
wp_set_current_user( 0 );
$req  = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', 'test' );
$resp = rest_do_request( $req );
$anon_start = $resp->get_status();

$req  = new WP_REST_Request( 'POST', '/aipc/v1/connection/test' );
$req->set_param( 'base_url', 'https://mock.invalid/v1' );
$resp = rest_do_request( $req );
$anon_conn = $resp->get_status();

$out['rest_permissions'] = array(
	'anonymous_start'       => $anon_start,
	'anonymous_connection'  => $anon_conn,
);
wp_set_current_user( 1 );

/* ------------------------------------------------------------------ *
 * Persian translation bundle loads and resolves (fa_IR) — loaded early
 * so the whole run (and the Bale notification texts) go through it.
 * ------------------------------------------------------------------ */
unload_textdomain( 'wp-ai-post-creator' );
$mo_loaded = load_textdomain(
	'wp-ai-post-creator',
	trailingslashit( WP_PLUGIN_DIR ) . 'wp-ai-post-creator/languages/wp-ai-post-creator-fa_IR.mo'
);
$out['i18n_fa'] = array(
	'mo_loaded'    => $mo_loaded,
	'delete_job'   => __( 'Delete job', 'wp-ai-post-creator' ) === 'حذف کار',
	'logs_title'   => __( 'AI Logs', 'wp-ai-post-creator' ) === 'گزارش‌های هوش مصنوعی',
	'old_string'   => __( 'Starting the agent…', 'wp-ai-post-creator' ) !== 'Starting the agent…',
	'plural_form'  => sprintf( _n( 'Outline ready — %d section.', 'Outline ready — %d sections.', 3, 'wp-ai-post-creator' ), 3 ),
	'placeholder'  => sprintf( __( '⏱ +%d min', 'wp-ai-post-creator' ), 60 ) === '⏱ +60 دقیقه',
	'publish_now'  => __( '🚀 Publish immediately', 'wp-ai-post-creator' ) === '🚀 انتشار بلافاصله',
	'rw_plural'    => sprintf( _n( 'Rewrite plan ready — %d section.', 'Rewrite plan ready — %d sections.', 2, 'wp-ai-post-creator' ), 2 ),
	'rewrite_page' => __( 'Rewrite post', 'wp-ai-post-creator' ) === 'بازنویسی نوشته',
	'sources'      => __( 'Research source sites', 'wp-ai-post-creator' ) === 'سایت‌های مبدأ پژوهش',
);

/* ------------------------------------------------------------------ *
 * REST: full agent run (empty topic -> invented from the site prompt)
 * ------------------------------------------------------------------ */
$req = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', '' );
$req->set_param( 'tone', 'friendly' );
$req->set_param( 'length', 'short' );
$req->set_param( 'language', 'fa' );
$req->set_param( 'image', true );
$req->set_param( 'faq', true );
$req->set_param( 'toc', true );
$resp  = rest_do_request( $req );
$state = $resp->get_data();

$out['start'] = array(
	'status' => $resp->get_status(),
	'has_id' => ! empty( $state['id'] ),
	'steps'  => count( $state['steps'] ?? array() ),
	'logs'   => count( $state['logs'] ?? array() ),
);

$job_id = $state['id'];
$since  = $state['since'] ?? 0;
$guard  = 0;
while ( isset( $state['status'] ) && 'running' === $state['status'] && $guard++ < 50 ) {
	$req = new WP_REST_Request( 'POST', '/aipc/v1/step' );
	$req->set_param( 'job_id', $job_id );
	$req->set_param( 'since', $since );
	$resp  = rest_do_request( $req );
	$state = $resp->get_data();
	$since = $state['since'] ?? $since;
}

$out['agent'] = array(
	'status'   => $state['status'] ?? null,
	'error'    => $state['error'] ?? null,
	'progress' => $state['progress'] ?? null,
	'usage'    => $state['usage'] ?? null,
	'steps'    => array_map( function ( $s ) {
		return $s['status'] . ':' . $s['id'];
	}, $state['steps'] ?? array() ),
	'result'   => $state['result'] ?? null,
);

/* ------------------------------------------------------------------ *
 * Per-call inspection: different connections per step
 * ------------------------------------------------------------------ */
$job = AIPC_Agent::instance()->get_job( $job_id );

/**
 * Unique connection names among calls matching the filter.
 *
 * @param array    $calls  Job calls.
 * @param callable $filter Step filter.
 * @return array
 */
$aipc_conns_for = function ( $calls, $filter ) {
	$names = array();
	foreach ( $calls as $call ) {
		if ( $filter( $call ) ) {
			$names[] = $call['conn'];
		}
	}
	return array_values( array_unique( $names ) );
};

$aipc_ok_calls     = 0;
$aipc_failed_calls = 0;
foreach ( $job['calls'] as $call ) {
	if ( $call['ok'] ) {
		$aipc_ok_calls++;
	} else {
		$aipc_failed_calls++;
	}
}

$out['calls'] = array(
	'total'             => count( $job['calls'] ),
	'chat_conns'        => $aipc_conns_for( $job['calls'], function ( $c ) {
		return 'image' !== $c['step'] && 'image_prompt' !== $c['step'];
	} ),
	'image_conn'        => $aipc_conns_for( $job['calls'], function ( $c ) {
		return 'image' === $c['step'];
	} ),
	'image_prompt_conn' => $aipc_conns_for( $job['calls'], function ( $c ) {
		return 'image_prompt' === $c['step'];
	} ),
	'ok_calls'          => $aipc_ok_calls,
	'failed_calls'      => $aipc_failed_calls,
	'has_timings'       => count( $job['timings'] ) === count( $job['steps'] ),
);

/* ------------------------------------------------------------------ *
 * Assertions on the created post
 * ------------------------------------------------------------------ */
if ( ! empty( $state['result']['post_id'] ) ) {
	$post_id = (int) $state['result']['post_id'];
	$post    = get_post( $post_id );

	$out['post'] = array(
		'status'      => $post->post_status,
		'title'       => $post->post_title,
		'slug'        => $post->post_name,
		'excerpt'     => $post->post_excerpt,
		'has_toc'     => false !== strpos( $post->post_content, 'aipc-toc' ),
		'h2_count'    => substr_count( $post->post_content, '<h2' ),
		'has_anchor'  => false !== strpos( $post->post_content, 'id="aipc-s-0"' ),
		'has_faq'     => false !== strpos( $post->post_content, 'aipc-faq' ),
		'has_list'    => false !== strpos( $post->post_content, '<ul>' ),
		'has_heading' => false !== strpos( $post->post_content, 'بخش بهبودیافته 1' ),
		'words'       => AIPC_Agent::count_words( $post->post_content ),
	);

	$out['meta'] = array(
		'seo_title'     => get_post_meta( $post_id, '_aipc_meta_title', true ),
		'seo_desc'      => get_post_meta( $post_id, '_aipc_meta_description', true ),
		'yoast_desc'    => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
		'rankmath'      => get_post_meta( $post_id, 'rank_math_title', true ),
		'rankmath_desc' => get_post_meta( $post_id, 'rank_math_description', true ),
		'focus_kw'      => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
		'has_schema'    => false !== strpos( (string) get_post_meta( $post_id, '_aipc_faq_schema', true ), 'FAQPage' ),
		'generated'     => (bool) get_post_meta( $post_id, '_aipc_generated', true ),
	);

	$out['terms'] = array(
		'tags'       => wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ),
		'categories' => wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) ),
	);

	$out['thumbnail'] = (int) get_post_thumbnail_id( $post_id );
}

/* ------------------------------------------------------------------ *
 * Aggregate stats
 * ------------------------------------------------------------------ */
$stats = AIPC_Agent::stats();
$out['stats'] = array(
	'jobs'      => $stats['jobs'],
	'done'      => $stats['done'],
	'calls'     => $stats['calls'],
	'has_chat'  => isset( $stats['by_connection']['Chat Mock'] ) && $stats['by_connection']['Chat Mock']['calls'] > 0,
	'has_image' => isset( $stats['by_connection']['Image Mock'] ) && $stats['by_connection']['Image Mock']['calls'] > 0,
);

/* ------------------------------------------------------------------ *
 * Admin pages render (list + detail)
 * ------------------------------------------------------------------ */
$out['admin_pages'] = array(
	'logs_list'   => '',
	'logs_detail' => '',
	'connections' => '',
	'prompts'     => '',
);

unset( $_GET['job'] );
ob_start();
AIPC_Admin::render_logs();
$html = ob_get_clean();
$out['admin_pages']['logs_list'] = array(
	'rendered'   => false !== strpos( $html, 'aipc-stats-grid' ),
	'shows_job'  => false !== strpos( $html, $job_id ),
	'shows_topic'=> false !== strpos( $html, 'سبزی' ),
);

$_GET['job'] = $job_id;
ob_start();
AIPC_Admin::render_logs();
$html = ob_get_clean();
$out['admin_pages']['logs_detail'] = array(
	'rendered'     => false !== strpos( $html, 'Job details' ) || false !== strpos( $html, 'aipc-terminal-static' ),
	'shows_calls'  => false !== strpos( $html, 'Image Mock' ) && false !== strpos( $html, 'Chat Mock' ),
	'shows_steps'  => false !== strpos( $html, __( 'Copywriting & SEO pass', 'wp-ai-post-creator' ) ),
);
unset( $_GET['job'] );

ob_start();
AIPC_Admin::render_connections();
$html = ob_get_clean();
$out['admin_pages']['connections'] = array(
	'rendered'      => false !== strpos( $html, 'aipc-conn-form' ),
	'shows_conns'   => false !== strpos( $html, 'Chat Mock' ) && false !== strpos( $html, 'Image Mock' ),
	'model_picker'  => false !== strpos( $html, 'aipc-model-picker' ) && false !== strpos( $html, 'aipc-model-list' ),
);

ob_start();
AIPC_Admin::render_prompts();
$html = ob_get_clean();
$out['admin_pages']['prompts'] = array(
	'rendered'        => false !== strpos( $html, 'aipc-step-card' ),
	'shows_custom'    => false !== strpos( $html, 'FAQ-QUESTIONS-CUSTOM' ),
	'shows_image_sel' => false !== strpos( $html, 'steps[image]' ),
	'multi_select'    => false !== strpos( $html, 'steps[plan][connections][]' ),
	'shows_rw_steps'  => false !== strpos( $html, 'rw_analyze' ) && false !== strpos( $html, 'rw_rewrite' ),
);

/* ------------------------------------------------------------------ *
 * Sanitization
 * ------------------------------------------------------------------ */
$clean_conn = AIPC_Connections::sanitize( array(
	'name'            => '  Test  ',
	'base_url'        => 'https://api.openai.com/v1/',
	'api_key'         => '',
	'chat_model'      => 'gpt-4o',
	'temperature'     => '9',
	'max_tokens'      => '99999',
	'request_timeout' => '5',
), array( 'api_key' => 'kept-key', 'id' => 'c_x' ) );

$out['sanitize_connection'] = array(
	'name_trimmed'     => 'Test' === $clean_conn['name'],
	'base_normalized'  => 'https://api.openai.com/v1' === $clean_conn['base_url'],
	'key_kept'         => 'kept-key' === $clean_conn['api_key'],
	'temp_clamped'     => 0.7 === $clean_conn['temperature'],
	'tokens_clamped'   => 16000 === $clean_conn['max_tokens'],
	'timeout_clamped'  => 15 === $clean_conn['request_timeout'],
);

$clean_settings = AIPC_Settings::sanitize( array(
	'content_language' => 'fa',
	'default_tone'     => 'professional',
	'default_length'   => 'medium',
	'site_prompt'      => "  توضیح سایت  ",
	'image_size'       => '1024x1024',
	'add_toc'          => '0',
	'add_faq'          => '1',
) );
$out['sanitize_settings'] = array(
	'site_prompt_trimmed' => 'توضیح سایت' === $clean_settings['site_prompt'],
	'toc_off'             => 0 === $clean_settings['add_toc'],
	'faq_on'              => 1 === $clean_settings['add_faq'],
);

/* ------------------------------------------------------------------ *
 * Provider request log (what the plugin actually sent)
 * ------------------------------------------------------------------ */
$log_file = WP_CONTENT_DIR . '/mock-api-log.jsonl';
$requests = array();
if ( file_exists( $log_file ) ) {
	foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$requests[] = json_decode( $line, true );
	}
}
$image_requests = array_values( array_filter( $requests, function ( $r ) {
	return 'images.invalid' === $r['host'];
} ) );
$faq_requests = array_values( array_filter( $requests, function ( $r ) {
	return isset( $r['prompt'] ) && false !== strpos( (string) $r['prompt'], 'FAQ-QUESTIONS-CUSTOM' );
} ) );

$out['provider_requests'] = array(
	'count'          => count( $requests ),
	'hosts'          => array_values( array_unique( array_column( $requests, 'host' ) ) ),
	'image_requests' => count( $image_requests ),
	'image_auth'     => ! empty( $image_requests ) && 'Bearer sk-image-key' === $image_requests[0]['auth'],
	'chat_auth'      => ! empty( $requests ) && 'Bearer sk-chat-key' === $requests[0]['auth'],
	'faq_custom_used'=> count( $faq_requests ) > 0,
);

/* ------------------------------------------------------------------ *
 * v1.5.0 — research sources (RSS) ground the plan step
 * ------------------------------------------------------------------ */
$aipc_rss_requests = array_values( array_filter( $requests, function ( $r ) {
	return 'rss' === $r['host'];
} ) );
$aipc_plan_req = null;
foreach ( $requests as $r ) {
	if ( isset( $r['prompt'] ) && false !== strpos( (string) $r['prompt'], 'SITE CONTEXT' ) ) {
		$aipc_plan_req = $r;
		break;
	}
}
$out['research_sources'] = array(
	'feed_fetched'       => count( $aipc_rss_requests ) >= 1,
	'feed_url'           => ! empty( $aipc_rss_requests ) && false !== strpos( (string) $aipc_rss_requests[0]['url'], 'news.invalid/feed/' ),
	'plan_grounded'      => ! empty( $aipc_plan_req ) && false !== strpos( (string) $aipc_plan_req['prompt'], 'خبر آزمایشی: کشاورزی شهری' ),
	'recent_posts_block' => ! empty( $aipc_plan_req ) && false !== strpos( (string) $aipc_plan_req['prompt'], 'EXISTING ARTICLES' ),
	'link_cands_block'   => ! empty( $aipc_plan_req ) && false !== strpos( (string) $aipc_plan_req['prompt'], 'INTERNAL LINKING CANDIDATES' ),
);

/* ------------------------------------------------------------------ *
 * Cancel behavior on a second job
 * ------------------------------------------------------------------ */
$job2 = AIPC_Agent::instance()->create_job( 'cancel test topic', array( 'length' => 'short', 'language' => 'fa' ) );
AIPC_Agent::instance()->execute_step( $job2['id'], 0 ); // plan
AIPC_Agent::instance()->cancel_job( $job2['id'] );
$after = AIPC_Agent::instance()->get_job( $job2['id'] );
$st    = AIPC_Agent::instance()->execute_step( $after['id'], 0 );
$out['cancel_flow'] = array(
	'status_after_cancel' => $after['status'],
	'status_after_step'   => $st['status'], // stays cancelled
);

/* ------------------------------------------------------------------ *
 * Failure + retry flow: provider fails ALL attempts of the plan step
 * (3 automatic retries), then a manual retry succeeds.
 * ------------------------------------------------------------------ */
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( ! empty( $GLOBALS['aipc_fail_all'] ) && false !== strpos( $url, '/chat/completions' ) ) {
		return array(
			'body'     => json_encode( array( 'error' => array( 'message' => 'mock upstream error' ) ) ),
			'response' => array( 'code' => 500, 'message' => 'Server Error' ),
		);
	}
	return $preempt;
}, 5, 3 );

$GLOBALS['aipc_fail_all'] = true;
$before_log = count( $requests );

$job3   = AIPC_Agent::instance()->create_job( 'failure flow topic', array( 'length' => 'short', 'language' => 'fa' ) );
$state3 = AIPC_Agent::instance()->execute_step( $job3['id'], 0 );

$failed_calls = 0;
$job3 = AIPC_Agent::instance()->get_job( $job3['id'] );
foreach ( $job3['calls'] as $call ) {
	if ( ! $call['ok'] ) {
		$failed_calls++;
	}
}

$out['failure_flow'] = array(
	'status'         => $state3['status'],
	'error_message'  => $state3['error']['message'] ?? null,
	'error_step'     => $state3['error']['step'] ?? null,
	'failed_calls'   => $failed_calls, // expect 3 (one per auto attempt)
	'has_timing'     => isset( $job3['timings']['plan'] ),
);

if ( 'error' === $state3['status'] ) {
	$GLOBALS['aipc_fail_all'] = false;
	$state3 = AIPC_Agent::instance()->retry_job( $job3['id'] );
	$guard  = 0;
	while ( 'running' === $state3['status'] && $guard++ < 30 ) {
		$state3 = AIPC_Agent::instance()->execute_step( $job3['id'], 0 );
	}
	$out['retry_flow'] = array(
		'status_after_retry' => $state3['status'],
		'has_result'         => ! empty( $state3['result']['post_id'] ),
	);
}

/* ------------------------------------------------------------------ *
 * v1.5.0 — publish modes
 * ------------------------------------------------------------------ */

/**
 * Drive a job id to completion through the agent API.
 *
 * @param string $aipc_jid Job id.
 * @return array Final client state.
 */
$aipc_drive = function ( $aipc_jid ) {
	$aipc_st = AIPC_Agent::instance()->execute_step( $aipc_jid, 0 );
	$aipc_g  = 0;
	while ( isset( $aipc_st['status'] ) && 'running' === $aipc_st['status'] && $aipc_g++ < 60 ) {
		$aipc_st = AIPC_Agent::instance()->execute_step( $aipc_jid, 0 );
	}
	return $aipc_st;
};

// --- mode "now": publish immediately after the run ---
$req = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', 'تست انتشار فوری' );
$req->set_param( 'length', 'short' );
$req->set_param( 'language', 'fa' );
$req->set_param( 'image', false );
$req->set_param( 'faq', false );
$req->set_param( 'toc', false );
$req->set_param( 'publish_mode', 'now' );
$resp = rest_do_request( $req );
$aipc_now_state = $aipc_drive( (string) $resp->get_data()['id'] );
$aipc_now_post  = get_post( (int) $aipc_now_state['result']['post_id'] );

$out['publish_now'] = array(
	'run_done'      => 'done' === ( isset( $aipc_now_state['status'] ) ? $aipc_now_state['status'] : '' ),
	'post_status'   => $aipc_now_post ? $aipc_now_post->post_status : null,
	'result_status' => isset( $aipc_now_state['result']['status'] ) ? $aipc_now_state['result']['status'] : null,
	'title_used'    => $aipc_now_post && 'تست انتشار فوری' === $aipc_now_post->post_title,
);

// --- mode "delay": schedule, then the cron callback publishes ---
$req = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', 'تست انتشار با تأخیر' );
$req->set_param( 'length', 'short' );
$req->set_param( 'language', 'fa' );
$req->set_param( 'image', false );
$req->set_param( 'faq', false );
$req->set_param( 'toc', false );
$req->set_param( 'publish_mode', 'delay' );
$req->set_param( 'publish_delay', 15 );
$resp = rest_do_request( $req );
$aipc_delay_state = $aipc_drive( (string) $resp->get_data()['id'] );
$aipc_delay_pid   = (int) $aipc_delay_state['result']['post_id'];
$aipc_evt         = wp_get_scheduled_event( 'aipc_publish_post', array( $aipc_delay_pid, $aipc_delay_state['id'] ) );

$out['publish_delay'] = array(
	'run_done'        => 'done' === ( isset( $aipc_delay_state['status'] ) ? $aipc_delay_state['status'] : '' ),
	'post_still_draft'=> 'draft' === get_post_status( $aipc_delay_pid ),
	'result_status'   => isset( $aipc_delay_state['result']['status'] ) ? $aipc_delay_state['result']['status'] : null,
	'event_scheduled' => is_object( $aipc_evt ),
	'event_in_window' => is_object( $aipc_evt ) && $aipc_evt->timestamp > time() && $aipc_evt->timestamp <= time() + 16 * MINUTE_IN_SECONDS,
);

if ( is_object( $aipc_evt ) ) {
	// The real cron never fires inside e2e — invoke the handler directly.
	AIPC_Scheduler::publish_post( $aipc_delay_pid, $aipc_delay_state['id'] );
	// Second call must be a no-op (post is no longer a draft).
	AIPC_Scheduler::publish_post( $aipc_delay_pid, $aipc_delay_state['id'] );

	$aipc_delay_job = AIPC_Agent::instance()->get_job( $aipc_delay_state['id'] );
	$aipc_pub_log   = false;
	foreach ( (array) $aipc_delay_job['log'] as $aipc_le ) {
		if ( false !== strpos( (string) $aipc_le['msg'], 'منتشر شد' ) ) {
			$aipc_pub_log = true;
		}
	}
	$out['publish_delay']['published'] = 'publish' === get_post_status( $aipc_delay_pid );
	$out['publish_delay']['logged']    = $aipc_pub_log;
}

/* ------------------------------------------------------------------ *
 * v1.5.0 — rewrite mode
 * ------------------------------------------------------------------ */
$aipc_gardening = get_term_by( 'name', 'باغبانی', 'category' );
$aipc_old_pid   = wp_insert_post( array(
	'post_title'    => 'پست قدیمی درباره سبزی‌کاری',
	'post_content'  => "<p>متن قدیمی و کوتاه درباره سبزی‌کاری در بالکن است که باید کاملاً بازنویسی شود تا تازه و اصیل شود و برای مخاطب امروز جذاب باشد.</p>\n<h2>عنوان قدیمی</h2><p>بخش قدیمی با متن تکراری و کلیشه‌ای که اصالتی ندارد و باید با نگاهی نو بازنویسی شود.</p>\n<p>منبع: <a href=\"https://example.com/old\">منبع اصلی</a></p>",
	'post_status'   => 'publish',
	'post_category' => array( (int) $aipc_gardening->term_id ),
) );
$aipc_ref_pid = wp_insert_post( array(
	'post_title'   => 'راهنمای آبیاری گلدان‌ها',
	'post_content' => '<p>مقالهٔ منتشرشدهٔ مرجع درباره آبیاری صحیح گلدان‌ها در فصل‌های مختلف.</p>',
	'post_status'  => 'publish',
) );
$aipc_ref_url = (string) get_permalink( $aipc_ref_pid );

$req = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', '' );
$req->set_param( 'length', 'short' );
$req->set_param( 'language', 'fa' );
$req->set_param( 'image', true );
$req->set_param( 'faq', true );
$req->set_param( 'toc', false );
$req->set_param( 'mode', 'rewrite' );
$req->set_param( 'post_id', $aipc_old_pid );
$resp = rest_do_request( $req );
$aipc_rw_state = $aipc_drive( (string) $resp->get_data()['id'] );
$aipc_rw_post  = get_post( $aipc_old_pid );

$aipc_rw_cats = wp_get_post_terms( $aipc_old_pid, 'category', array( 'fields' => 'names' ) );
$aipc_rw_tags = wp_get_post_terms( $aipc_old_pid, 'post_tag', array( 'fields' => 'names' ) );

$out['rewrite'] = array(
	'run_done'      => 'done' === ( isset( $aipc_rw_state['status'] ) ? $aipc_rw_state['status'] : '' ),
	'same_post'     => isset( $aipc_rw_state['result']['post_id'] ) && (int) $aipc_rw_state['result']['post_id'] === (int) $aipc_old_pid,
	'title'         => $aipc_rw_post && 'عنوان بازنویسی‌شدهٔ بهتر و سئوپسند' === $aipc_rw_post->post_title,
	'status_kept'   => $aipc_rw_post && 'publish' === $aipc_rw_post->post_status,
	'content_new'   => $aipc_rw_post && false !== strpos( $aipc_rw_post->post_content, 'بخش بازنویسی&zwnj;شده 1' ), // ZWNJ stored as entity since v1.10.0.
	'old_link_kept' => $aipc_rw_post && false !== strpos( $aipc_rw_post->post_content, 'https://example.com/old' ),
	'internal_link' => $aipc_rw_post && false !== strpos( $aipc_rw_post->post_content, $aipc_ref_url ),
	'h2_count'      => $aipc_rw_post ? substr_count( $aipc_rw_post->post_content, '<h2' ) : null,
	'has_faq'       => $aipc_rw_post && false !== strpos( $aipc_rw_post->post_content, 'aipc-faq' ),
	'has_schema'    => false !== strpos( (string) get_post_meta( $aipc_old_pid, '_aipc_faq_schema', true ), 'FAQPage' ),
	'generated'     => (bool) get_post_meta( $aipc_old_pid, '_aipc_generated', true ),
	'thumbnail'     => (int) get_post_thumbnail_id( $aipc_old_pid ),
	'cats'          => $aipc_rw_cats,
	'tags'          => $aipc_rw_tags,
	'steps'         => isset( $aipc_rw_state['steps'] ) ? array_map( function ( $s ) {
		return $s['status'] . ':' . $s['id'];
	}, $aipc_rw_state['steps'] ) : null,
	'seo_title'     => (string) get_post_meta( $aipc_old_pid, '_aipc_meta_title', true ),
);

// The rewrite console must list the posts created above.
ob_start();
AIPC_Admin::render_rewrite();
$aipc_rw_html = ob_get_clean();
$out['admin_pages']['rewrite'] = array(
	'rendered'      => false !== strpos( $aipc_rw_html, 'aipc-rewrite-post' ),
	'lists_rewritten'=> false !== strpos( $aipc_rw_html, 'value="' . (int) $aipc_old_pid . '"' ), // rewritten post (its title changed)
	'lists_ref'     => false !== strpos( $aipc_rw_html, 'راهنمای آبیاری گلدان‌ها' ),
	'publish_note'  => false !== strpos( $aipc_rw_html, 'به‌روزرسانی می‌شود' ),
);

// Invalid rewrite target must be rejected.
$aipc_rw_err = AIPC_Agent::instance()->create_job( 'بازنویسی نامعتبر', array( 'mode' => 'rewrite', 'post_id' => 999999 ) );
$aipc_bad_mode = AIPC_Agent::instance()->create_job( 'حالت نامعتبر', array( 'mode' => 'weird', 'length' => 'short', 'language' => 'fa' ) );
if ( ! is_wp_error( $aipc_bad_mode ) ) {
	AIPC_Agent::instance()->cancel_job( $aipc_bad_mode['id'] );
}
$out['rewrite_validation'] = array(
	'bad_post_rejected' => is_wp_error( $aipc_rw_err ),
	'bad_mode_is_new'   => ! is_wp_error( $aipc_bad_mode ) && 'new' === $aipc_bad_mode['mode'],
);

/* ------------------------------------------------------------------ *
 * v1.5.0 — connection fallback chain
 * ------------------------------------------------------------------ */
$aipc_flaky = AIPC_Connections::save( array(
	'name'       => 'Flaky Mock',
	'base_url'   => 'https://flaky.invalid/v1',
	'api_key'    => 'bad-key',
	'chat_model' => 'mock-mini',
	'image_model'=> 'dall-e-3',
) );

// Snapshot the current per-step config, override the plan chain.
$aipc_steps_snapshot = array();
foreach ( AIPC_Steps::registry() as $aipc_step_id => $aipc_step_meta ) {
	$aipc_steps_snapshot[ $aipc_step_id ] = array(
		'connections' => AIPC_Steps::get( $aipc_step_id )['connections'],
		'prompt'      => AIPC_Steps::has_custom_prompt( $aipc_step_id ) ? AIPC_Steps::get( $aipc_step_id )['prompt'] : '',
	);
}
$aipc_steps_plan_chain = $aipc_steps_snapshot;
$aipc_steps_plan_chain['plan']['connections'] = array( $aipc_flaky['id'], $connections[0]['id'] );
AIPC_Steps::save_all( $aipc_steps_plan_chain );

$aipc_fb_job   = AIPC_Agent::instance()->create_job( 'موضوع تست جایگزینی اتصال', array( 'length' => 'short', 'language' => 'fa' ) );
$aipc_fb_state = AIPC_Agent::instance()->execute_step( $aipc_fb_job['id'], 0 );
$aipc_fb_job   = AIPC_Agent::instance()->get_job( $aipc_fb_job['id'] );

$aipc_fb_conns   = array();
$aipc_fb_failed  = 0;
foreach ( (array) $aipc_fb_job['calls'] as $aipc_call ) {
	if ( 'plan' === $aipc_call['step'] ) {
		$aipc_fb_conns[] = $aipc_call['conn'];
		if ( empty( $aipc_call['ok'] ) ) {
			$aipc_fb_failed++;
		}
	}
}
$aipc_fb_conns = array_values( array_unique( $aipc_fb_conns ) );

$aipc_fb_switch = false;
$aipc_fb_retry  = false;
foreach ( (array) $aipc_fb_job['log'] as $aipc_le ) {
	if ( false !== strpos( (string) $aipc_le['msg'], 'تغییر به «Chat Mock»' ) ) {
		$aipc_fb_switch = true;
	}
	if ( false !== strpos( (string) $aipc_le['msg'], 'Flaky Mock' ) && false !== strpos( (string) $aipc_le['msg'], 'تلاش دوباره' ) ) {
		$aipc_fb_retry = true;
	}
}

$out['fallback_chain'] = array(
	'plan_passed'    => isset( $aipc_fb_state['steps'][0]['status'] ) && 'done' === $aipc_fb_state['steps'][0]['status'],
	'still_running'  => 'running' === ( isset( $aipc_fb_state['status'] ) ? $aipc_fb_state['status'] : '' ),
	'conns'          => $aipc_fb_conns, // expect: Flaky Mock → Chat Mock
	'failed_on_flaky'=> 3 === $aipc_fb_failed,
	'switch_logged'  => $aipc_fb_switch,
	'retry_logged'   => $aipc_fb_retry,
	'flaky_requests' => ( function () use ( $log_file ) {
		$aipc_count = 0;
		if ( file_exists( $log_file ) ) {
			foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $aipc_line ) {
				$aipc_row = json_decode( $aipc_line, true );
				if ( $aipc_row && 'flaky.invalid' === $aipc_row['host'] ) {
					$aipc_count++;
				}
			}
		}
		return $aipc_count; // 3 agent attempts x the client-internal retry on 5xx = 6
	} )(),
);

// Cancel before it creates a post / notifies Bale; restore the chain.
AIPC_Agent::instance()->cancel_job( $aipc_fb_job['id'] );
AIPC_Steps::save_all( $aipc_steps_snapshot );

/* ------------------------------------------------------------------ *
 * v1.5.0 — sanitization of the new settings
 * ------------------------------------------------------------------ */
$aipc_src_clean = AIPC_Settings::sanitize( array(
	'source_sites' => "  https://a.invalid  \nnot-a-url\njavascript:alert(1)\nftp://b.invalid\nhttps://a.invalid/\nhttps://b.invalid\n",
) );
$aipc_sched_pub1 = AIPC_Scheduler::sanitize_entry( array( 'time' => '09:00', 'days' => array( 1 ), 'publish' => 'bogus', 'publish_delay' => '5' ) );
$aipc_sched_pub2 = AIPC_Scheduler::sanitize_entry( array( 'time' => '09:00', 'days' => array( 1 ), 'publish' => 'delay', 'publish_delay' => '99999' ) );

$out['sanitize_v150'] = array(
	'sources_clean'  => "https://a.invalid\nhttps://b.invalid" === $aipc_src_clean['source_sites'],
	'pub_default'    => 'draft' === $aipc_sched_pub1['publish'],
	'pub_delay_min'  => 15 === (int) $aipc_sched_pub1['publish_delay'],
	'pub_delay_kept' => 'delay' === $aipc_sched_pub2['publish'],
	'pub_delay_max'  => 10080 === (int) $aipc_sched_pub2['publish_delay'],
);

/* ------------------------------------------------------------------ *
 * Scheduler: cron registration + automatic run via tick()
 * ------------------------------------------------------------------ */
$aipc_schedules = wp_get_schedules();
$out['scheduler_setup'] = array(
	'cron_scheduled' => false !== wp_get_scheduled_event( AIPC_Scheduler::CRON_HOOK ),
	'interval'       => isset( $aipc_schedules['aipc_quarter_hour'] ) && 900 === (int) $aipc_schedules['aipc_quarter_hour']['interval'],
	'entries'        => count( AIPC_Scheduler::entries() ),
	'daily_limit'    => 1 === AIPC_Scheduler::daily_limit(),
	'recipients'     => 2 === count( AIPC_Bale::recipients() ),
);

// Identify the entries by their configured topics.
$aipc_fired_entry   = null;
$aipc_limited_entry = null;
$aipc_paused_entry  = null;
foreach ( AIPC_Scheduler::entries() as $aipc_e ) {
	if ( false !== strpos( (string) $aipc_e['topic'], 'سقف روزانه' ) ) {
		$aipc_limited_entry = $aipc_e;
	} elseif ( false !== strpos( (string) $aipc_e['topic'], 'قارچ' ) ) {
		$aipc_fired_entry = $aipc_e;
	} elseif ( empty( $aipc_e['enabled'] ) ) {
		$aipc_paused_entry = $aipc_e;
	}
}

$aipc_jobs_before = count( AIPC_Agent::instance()->get_all_jobs() );

// First tick: sends the due daily report, then fires the due entry and
// drives the whole run synchronously.
AIPC_Scheduler::tick();

$aipc_cron_job = null;
foreach ( AIPC_Agent::instance()->get_all_jobs() as $aipc_j ) {
	if ( 'cron' === ( isset( $aipc_j['source'] ) ? $aipc_j['source'] : 'manual' ) ) {
		$aipc_cron_job = AIPC_Agent::instance()->get_job( $aipc_j['id'] ); // Full payload.
		break;
	}
}

$out['scheduler_run'] = array(
	'job_created'   => is_array( $aipc_cron_job ),
	'status'        => $aipc_cron_job ? $aipc_cron_job['status'] : null,
	'topic'         => $aipc_cron_job ? $aipc_cron_job['topic'] : null,
	'post_id'       => $aipc_cron_job ? (int) $aipc_cron_job['post_id'] : 0,
	'no_image'      => $aipc_cron_job ? 0 === (int) get_post_thumbnail_id( $aipc_cron_job['post_id'] ) : false,
	'bale_logged'   => false,
);

if ( $aipc_cron_job && $aipc_cron_job['post_id'] ) {
	$aipc_cron_post = get_post( $aipc_cron_job['post_id'] );
	$out['scheduler_run']['post_status'] = $aipc_cron_post ? $aipc_cron_post->post_status : null;
	$out['scheduler_run']['has_faq']     = false !== strpos( $aipc_cron_post->post_content, 'aipc-faq' );
	$out['scheduler_run']['has_toc']     = false !== strpos( $aipc_cron_post->post_content, 'aipc-toc' );
	$out['scheduler_run']['title']       = $aipc_cron_post->post_title;

	$aipc_bale_line = sprintf( __( 'Bale notification sent to %d chats (image, summary and link).', 'wp-ai-post-creator' ), 2 );
	foreach ( $aipc_cron_job['log'] as $aipc_log_entry ) {
		if ( false !== strpos( (string) $aipc_log_entry['msg'], $aipc_bale_line ) ) {
			$out['scheduler_run']['bale_logged'] = true;
		}
	}
}

// Second tick: the fired entry is done for today AND the daily limit (1)
// blocks the second due entry — no new job, no second report.
AIPC_Scheduler::tick();

$out['scheduler_limit'] = array(
	'no_new_job'       => count( AIPC_Agent::instance()->get_all_jobs() ) === $aipc_jobs_before + 1,
	'cron_jobs_today'  => 1 === AIPC_Scheduler::cron_jobs_today(),
	'second_not_fired' => ! isset( AIPC_Scheduler::config()['state'][ $aipc_limited_entry['id'] ] ),
	'second_still_due' => AIPC_Scheduler::entry_due( $aipc_limited_entry ),
);

// Due-state checks.
$aipc_due_state = AIPC_Scheduler::config();
$out['scheduler_due_state'] = array(
	'fired_today' => isset( $aipc_due_state['state'][ $aipc_fired_entry['id'] ] ) && wp_date( 'Y-m-d' ) === $aipc_due_state['state'][ $aipc_fired_entry['id'] ],
	'entry_not_due_anymore' => ! AIPC_Scheduler::entry_due( $aipc_fired_entry ),
	'paused_not_due'        => ! AIPC_Scheduler::entry_due( $aipc_paused_entry ),
);

// Report state: sent once today, not re-sent by the second tick.
$out['report_state'] = array(
	'last_report_today' => wp_date( 'Y-m-d' ) === AIPC_Bale::all()['last_report'],
);

// Sanitization.
$aipc_clean_entry = AIPC_Scheduler::sanitize_entry( array(
	'time' => '25:99',
	'days' => array( 3, 3, 9 ),
	'opts' => array( 'tone' => 'nope', 'length' => 'huge', 'language' => '' ),
) );
$aipc_bale_kept = AIPC_Bale::sanitize( array( 'enabled' => 1, 'token' => '', 'chat_id' => '' ), AIPC_Bale::all() );
AIPC_Scheduler::save_settings( array( 'daily_limit' => 999 ) );
$aipc_limit_clamped = 50 === AIPC_Scheduler::daily_limit();
AIPC_Scheduler::save_settings( array( 'daily_limit' => 1 ) );
$aipc_set = AIPC_Settings::all();
$out['sanitize_v130'] = array(
	'time_clamped'  => '09:00' === $aipc_clean_entry['time'],
	'days_clean'    => array( 3 ) === $aipc_clean_entry['days'],
	'opts_defaults' => $aipc_set['default_tone'] === $aipc_clean_entry['opts']['tone']
		&& $aipc_set['default_length'] === $aipc_clean_entry['opts']['length']
		&& $aipc_set['content_language'] === $aipc_clean_entry['opts']['language'],
	'bale_key_kept' => 'bale-token-123' === $aipc_bale_kept['token'],
	'limit_clamped' => $aipc_limit_clamped,
);

// Schedule admin page renders.
ob_start();
AIPC_Admin::render_schedule();
$aipc_sched_html = ob_get_clean();
$out['admin_pages']['schedule'] = array(
	'rendered'     => false !== strpos( $aipc_sched_html, 'aipc-btn-bale-test' ),
	'shows_entry'  => false !== strpos( $aipc_sched_html, 'شروع کاشت قارچ در خانه' ),
	'shows_bale'   => false !== strpos( $aipc_sched_html, 'aipc_save_bale' ),
	'shows_limit'  => false !== strpos( $aipc_sched_html, __( 'Max scheduled posts per day', 'wp-ai-post-creator' ) ),
	'shows_report' => false !== strpos( $aipc_sched_html, __( 'Periodic report', 'wp-ai-post-creator' ) ),
	'publish_sel'  => false !== strpos( $aipc_sched_html, 'aipc-sch-publish' ),
	'delay_field'  => false !== strpos( $aipc_sched_html, 'publish_delay' ),
	'publish_col'  => false !== strpos( $aipc_sched_html, '⏱' ) || false !== strpos( $aipc_sched_html, '🚀' ),
	'shows_queue'  => false !== strpos( $aipc_sched_html, 'aipc-tq-suggest-btn' ) && false !== strpos( $aipc_sched_html, 'aipc_add_topics' ),
	'shows_use_queue' => false !== strpos( $aipc_sched_html, 'name="use_queue"' ),
	'shows_two_way'   => false !== strpos( $aipc_sched_html, 'name="two_way"' ),
);

/* ------------------------------------------------------------------ *
 * Base URL normalization (v1.7.4): pasted endpoint paths are stripped
 * and a successful test reports the corrected URL for the UI.
 * ------------------------------------------------------------------ */
$aipc_burl = function ( $u ) {
	$c = new AIPC_API_Client( array( 'base_url' => $u ) );
	return $c->base_url();
};
$aipc_fix_test = ( new AIPC_API_Client( array( 'base_url' => 'https://mock.invalid/v1/chat/completions', 'api_key' => 'sk-chat-key' ) ) )->test();
$aipc_ok_test  = ( new AIPC_API_Client( array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-chat-key' ) ) )->test();

$out['base_url_normalize'] = array(
	'strip_chat_completions' => 'https://x.invalid/v1' === $aipc_burl( 'https://x.invalid/v1/chat/completions' ),
	'strip_trailing_slash'   => 'https://x.invalid/v1' === $aipc_burl( 'https://x.invalid/v1/chat/completions/' ),
	'strip_models'           => 'https://x.invalid/v1' === $aipc_burl( 'https://x.invalid/v1/models' ),
	'strip_repeated'         => 'https://x.invalid/v1' === $aipc_burl( 'https://x.invalid/v1/chat/completions/models' ),
	'bare_host_gets_v1'      => 'http://localhost:20128/v1' === $aipc_burl( 'http://localhost:20128' ),
	'custom_path_kept'       => 'https://api.groq.com/openai/v1' === $aipc_burl( 'https://api.groq.com/openai/v1' ),
	'test_reports_fix'       => is_array( $aipc_fix_test ) && ! empty( $aipc_fix_test['ok'] ) && 'https://mock.invalid/v1' === ( $aipc_fix_test['fixed_base_url'] ?? '' ),
	'test_clean_no_fix'      => is_array( $aipc_ok_test ) && ! empty( $aipc_ok_test['ok'] ) && ! isset( $aipc_ok_test['fixed_base_url'] ),
);

/* ------------------------------------------------------------------ *
 * API request headers (v1.9.1): attribution headers + filter.
 * ------------------------------------------------------------------ */
$aipc_seen_headers = null;
$aipc_hdr_filter   = function ( $headers, $url, $conn_no_key ) use ( &$aipc_seen_headers ) {
	$aipc_seen_headers = array( 'headers' => $headers, 'has_key' => isset( $conn_no_key['api_key'] ) );
	$headers['X-AIPC-Test'] = 'on';
	return $headers;
};
add_filter( 'aipc_api_headers', $aipc_hdr_filter, 10, 3 );
( new AIPC_API_Client( array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-chat-key' ) ) )->models();
remove_filter( 'aipc_api_headers', $aipc_hdr_filter, 10 );

$out['api_headers'] = array(
	'referer_sent'   => is_array( $aipc_seen_headers ) && ! empty( $aipc_seen_headers['headers']['HTTP-Referer'] ),
	'title_sent'     => is_array( $aipc_seen_headers ) && isset( $aipc_seen_headers['headers']['X-Title'] ),
	'auth_sent'      => is_array( $aipc_seen_headers ) && 0 === strpos( (string) $aipc_seen_headers['headers']['Authorization'], 'Bearer ' ),
	'key_not_leaked' => is_array( $aipc_seen_headers ) && false === $aipc_seen_headers['has_key'],
);

/* ------------------------------------------------------------------ *
 * Purpose-based connection routing (v1.8.0): chat vs image pools,
 * priority order, agent auto-chains when a step has no explicit chain.
 * ------------------------------------------------------------------ */
$aipc_cr_ok  = AIPC_Connections::sanitize( array( 'name' => 'Img srv', 'base_url' => 'https://mock.invalid/v1', 'purpose' => 'image', 'priority' => '2' ), array() );
$aipc_cr_bad = AIPC_Connections::sanitize( array( 'name' => 'Weird', 'base_url' => 'https://mock.invalid/v1', 'purpose' => 'sound', 'priority' => '5000' ), array() );

$aipc_cr_conn_backup  = get_option( 'aipc_connections' );
$aipc_cr_steps_backup = get_option( 'aipc_steps' );
update_option( 'aipc_connections', array(
	array( 'id' => 'img2',   'name' => 'Image slow', 'base_url' => 'https://img2.invalid/v1', 'purpose' => 'image', 'priority' => 20 ),
	array( 'id' => 'chat1',  'name' => 'Chat main',  'base_url' => 'https://chat.invalid/v1', 'purpose' => 'chat',  'priority' => 1 ),
	array( 'id' => 'img1',   'name' => 'Image fast', 'base_url' => 'https://img1.invalid/v1', 'purpose' => 'image', 'priority' => 5 ),
	array( 'id' => 'any',    'name' => 'Universal',  'base_url' => 'https://both.invalid/v1', 'purpose' => 'both',  'priority' => 7, 'is_default' => 1 ),
	array( 'id' => 'legacy', 'name' => 'Old-style',  'base_url' => 'https://old.invalid/v1' ), // pre-1.8.0: no purpose/priority.
), false );
update_option( 'aipc_steps', array(), false ); // No explicit chains on any step.

$aipc_ids = function ( $pool ) {
	return implode( ',', array_map( function ( $c ) { return $c['id']; }, $pool ) );
};
$aipc_pool_img  = AIPC_Connections::for_purpose( 'image' );
$aipc_pool_chat = AIPC_Connections::for_purpose( 'chat' );

$aipc_rc = new ReflectionMethod( 'AIPC_Agent', 'resolve_connections' );
$aipc_rc->setAccessible( true );
$aipc_chain_img  = $aipc_rc->invoke( AIPC_Agent::instance(), 'image' );
$aipc_chain_plan = $aipc_rc->invoke( AIPC_Agent::instance(), 'plan' );

$out['conn_routing'] = array(
	'sanitize_purpose'   => 'image' === $aipc_cr_ok['purpose'] && 2 === $aipc_cr_ok['priority'],
	'sanitize_fallbacks' => 'both' === $aipc_cr_bad['purpose'] && 999 === $aipc_cr_bad['priority'],
	'image_pool_order'   => 'img1,any,legacy,img2' === $aipc_ids( $aipc_pool_img ),
	'chat_pool_order'    => 'chat1,any,legacy' === $aipc_ids( $aipc_pool_chat ),
	'agent_image_chain'  => 'img1,any,legacy,img2' === $aipc_ids( $aipc_chain_img ),
	'agent_chat_chain'   => 'chat1,any,legacy' === $aipc_ids( $aipc_chain_plan ),
	'legacy_defaults'    => 'both' === $aipc_pool_img[2]['purpose'] && 10 === $aipc_pool_img[2]['priority'],
);

update_option( 'aipc_connections', $aipc_cr_conn_backup, false );
update_option( 'aipc_steps', $aipc_cr_steps_backup, false );

/* ------------------------------------------------------------------ *
 * Disabling connections (v1.9.3): disabled entries are skipped by the
 * purpose pools, the default choice and explicit step chains; the
 * flag survives partial saves and legacy rows default to enabled.
 * ------------------------------------------------------------------ */
$aipc_cd_conn_backup  = get_option( 'aipc_connections' );
$aipc_cd_steps_backup = get_option( 'aipc_steps' );
update_option( 'aipc_connections', array(
	array( 'id' => 'on1',  'name' => 'A on',   'base_url' => 'https://a.invalid/v1', 'purpose' => 'both', 'priority' => 1, 'enabled' => 1 ),
	array( 'id' => 'off1', 'name' => 'B off',  'base_url' => 'https://b.invalid/v1', 'purpose' => 'both', 'priority' => 2, 'enabled' => 0, 'is_default' => 1 ),
	array( 'id' => 'leg',  'name' => 'Legacy', 'base_url' => 'https://c.invalid/v1', 'purpose' => 'chat', 'priority' => 3 ), // pre-1.9.3: no enabled flag.
), false );
update_option( 'aipc_steps', array( 'plan' => array( 'connections' => array( 'off1', 'on1' ) ) ), false );

$aipc_cd_all   = AIPC_Connections::all();
$aipc_cd_pool  = AIPC_Connections::for_purpose( 'chat' );
$aipc_cd_chain = $aipc_rc->invoke( AIPC_Agent::instance(), 'plan' );

$aipc_cd_keep = AIPC_Connections::sanitize( array( 'name' => 'B off' ), array( 'enabled' => 0, 'base_url' => 'https://b.invalid/v1' ) );
$aipc_cd_on   = AIPC_Connections::sanitize( array( 'enabled' => '1' ), array( 'enabled' => 0, 'name' => 'X', 'base_url' => 'https://b.invalid/v1' ) );
$aipc_cd_off  = AIPC_Connections::sanitize( array( 'enabled' => '0' ), array( 'enabled' => 1, 'name' => 'X', 'base_url' => 'https://b.invalid/v1' ) );

$aipc_cd_default = AIPC_Connections::get_default();
$aipc_cd_ui      = AIPC_Connections::all_for_ui();

update_option( 'aipc_connections', array(
	array( 'id' => 'off1', 'name' => 'B off', 'base_url' => 'https://b.invalid/v1', 'enabled' => 0, 'is_default' => 1 ),
), false );
$aipc_cd_none = AIPC_Connections::get_default();

$out['conn_disable'] = array(
	'legacy_enabled'    => 1 === $aipc_cd_all[2]['enabled'],
	'pool_skips_off'    => 'on1,leg' === $aipc_ids( $aipc_cd_pool ),
	'chain_skips_off'   => 'on1' === $aipc_ids( $aipc_cd_chain ),
	'default_skips_off' => is_array( $aipc_cd_default ) && 'on1' === $aipc_cd_default['id'],
	'default_none_left' => null === $aipc_cd_none,
	'sanitize_keeps'    => 0 === $aipc_cd_keep['enabled'],
	'sanitize_toggles'  => 1 === $aipc_cd_on['enabled'] && 0 === $aipc_cd_off['enabled'],
	'ui_exposes_flag'   => isset( $aipc_cd_ui[0]['enabled'], $aipc_cd_ui[1]['enabled'] ) && 1 === $aipc_cd_ui[0]['enabled'] && 0 === $aipc_cd_ui[1]['enabled'],
);

update_option( 'aipc_connections', $aipc_cd_conn_backup, false );
update_option( 'aipc_steps', $aipc_cd_steps_backup, false );

/* ------------------------------------------------------------------ *
 * Image delivery (v1.8.1): force-b64 mode + defensive base64 decoding.
 * ------------------------------------------------------------------ */
$aipc_if_ok   = AIPC_Connections::sanitize( array( 'name' => 'B64', 'base_url' => 'https://mock.invalid/v1', 'image_format' => 'b64' ), array() );
$aipc_if_bad  = AIPC_Connections::sanitize( array( 'name' => 'Odd', 'base_url' => 'https://mock.invalid/v1', 'image_format' => 'jpeg' ), array() );

$aipc_png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$aipc_png     = base64_decode( $aipc_png_b64 );

$aipc_b64_conn  = array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-image-key', 'image_model' => 'mock-image', 'image_format' => 'b64', 'image_api' => 'images' );
$aipc_auto_conn = array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-image-key', 'image_model' => 'mock-image', 'image_api' => 'images' );

$aipc_img_b64      = ( new AIPC_API_Client( $aipc_b64_conn ) )->image( 'A tiny test image' );
$aipc_img_refused  = ( new AIPC_API_Client( $aipc_b64_conn ) )->image( 'URLONLY please' );
$aipc_img_auto_url = ( new AIPC_API_Client( $aipc_auto_conn ) )->image( 'URLONLY please' );

/* Chat-completions image route (v1.9.0) — Gemini/OpenRouter style. */
$aipc_chat_conn    = array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-image-key', 'image_model' => 'mock-gemini-image', 'image_api' => 'chat' );
$aipc_cascade_conn = array( 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-image-key', 'image_model' => 'mock-image', 'image_format' => 'b64' ); // image_api auto.

$aipc_img_chat    = ( new AIPC_API_Client( $aipc_chat_conn ) )->image( 'A tiny test image' );
$aipc_img_cascade = ( new AIPC_API_Client( $aipc_cascade_conn ) )->image( 'URLONLY please' );
$aipc_if_api_ok   = AIPC_Connections::sanitize( array( 'name' => 'ChatImg', 'base_url' => 'https://mock.invalid/v1', 'image_api' => 'chat' ), array() );
$aipc_if_api_bad  = AIPC_Connections::sanitize( array( 'name' => 'OddApi', 'base_url' => 'https://mock.invalid/v1', 'image_api' => 'fax' ), array() );

$out['image_delivery'] = array(
	'sanitize_kept'    => 'b64' === $aipc_if_ok['image_format'],
	'sanitize_default' => 'auto' === $aipc_if_bad['image_format'],
	'decode_plain'     => AIPC_API_Client::decode_b64_image( $aipc_png_b64 ) === $aipc_png,
	'decode_data_uri'  => AIPC_API_Client::decode_b64_image( 'data:image/png;base64,' . $aipc_png_b64 ) === $aipc_png,
	'decode_wrapped'   => AIPC_API_Client::decode_b64_image( substr( $aipc_png_b64, 0, 20 ) . "\n  " . substr( $aipc_png_b64, 20 ) ) === $aipc_png,
	'decode_invalid'   => false === AIPC_API_Client::decode_b64_image( '' ) && false === AIPC_API_Client::decode_b64_image( 'data:image/png;base64' ),
	'b64_gets_bits'    => is_array( $aipc_img_b64 ) && ! empty( $aipc_img_b64['bits'] ) && $aipc_img_b64['bits'] === $aipc_png,
	'b64_refuses_url'  => is_wp_error( $aipc_img_refused ) && false !== strpos( $aipc_img_refused->get_error_message(), 'Base64' ),
	'auto_accepts_url' => is_array( $aipc_img_auto_url ) && ! empty( $aipc_img_auto_url['url'] ),
	'chat_route_bits'  => is_array( $aipc_img_chat ) && ! empty( $aipc_img_chat['bits'] ) && $aipc_img_chat['bits'] === $aipc_png,
	'cascade_to_chat'  => is_array( $aipc_img_cascade ) && ! empty( $aipc_img_cascade['bits'] ) && $aipc_img_cascade['bits'] === $aipc_png,
	'api_sanitized'    => 'chat' === $aipc_if_api_ok['image_api'] && 'auto' === $aipc_if_api_bad['image_api'],
);

/* ------------------------------------------------------------------ *
 * Assets: locale-proof screen detection (v1.7.3)
 * The submenu hook prefix is sanitize_title() of the TRANSLATED menu
 * title (percent-encoded Persian on fa_IR), so matching must key off the
 * stable page slug after "_page_".
 * ------------------------------------------------------------------ */
$aipc_fa_prefix = '%d8%b3%d8%a7%d8%b2%d9%86%d8%af%d9%87-%d9%be%d8%b3%d8%aa'; // sanitize_title of a Persian menu title.

$aipc_assets_reset = function () {
	wp_dequeue_style( 'aipc-admin' );
	foreach ( array( 'aipc-agent', 'aipc-connections', 'aipc-schedule' ) as $aipc_handle ) {
		wp_dequeue_script( $aipc_handle );
	}
};

$aipc_assets_reset();
AIPC_Assets::enqueue( $aipc_fa_prefix . '_page_aipc-connections' );
$aipc_assets_fa_conn_css = wp_style_is( 'aipc-admin', 'enqueued' );
$aipc_assets_fa_conn_js  = wp_script_is( 'aipc-connections', 'enqueued' );

$aipc_assets_reset();
AIPC_Assets::enqueue( 'ai-post-creator_page_aipc-schedule' );
$aipc_assets_en_sched_js = wp_script_is( 'aipc-schedule', 'enqueued' );

$aipc_assets_reset();
AIPC_Assets::enqueue( 'toplevel_page_aipc' );
$aipc_assets_top_js = wp_script_is( 'aipc-agent', 'enqueued' );

$aipc_assets_reset();
AIPC_Assets::enqueue( $aipc_fa_prefix . '_page_aipc-update' );
$aipc_assets_update_css = wp_style_is( 'aipc-admin', 'enqueued' );

$aipc_assets_reset();
AIPC_Assets::enqueue( 'edit.php' );
AIPC_Assets::enqueue( $aipc_fa_prefix . '_page_some-other-plugin' );
$aipc_assets_foreign_off = ! wp_style_is( 'aipc-admin', 'enqueued' );
$aipc_assets_reset();

$out['assets_enqueue'] = array(
	'fa_connections_css' => $aipc_assets_fa_conn_css,
	'fa_connections_js'  => $aipc_assets_fa_conn_js,
	'en_schedule_js'     => $aipc_assets_en_sched_js,
	'toplevel_agent_js'  => $aipc_assets_top_js,
	'fa_update_css'      => $aipc_assets_update_css,
	'foreign_hooks_off'  => $aipc_assets_foreign_off,
	'screen_map'         => 'connections' === AIPC_Assets::screen_for_hook( $aipc_fa_prefix . '_page_aipc-connections' )
		&& 'settings' === AIPC_Assets::screen_for_hook( 'anything_page_aipc-settings' )
		&& '' === AIPC_Assets::screen_for_hook( 'toplevel_page_other' ),
);

/* ------------------------------------------------------------------ *
 * Bale REST endpoints
 * ------------------------------------------------------------------ */
$req = new WP_REST_Request( 'POST', '/aipc/v1/bale/test' );
$req->set_param( 'token', 'bad-token' );
$req->set_param( 'chat_id', '12345' );
$resp = rest_do_request( $req );
$aipc_bad = $resp->get_data();
$out['rest_bale'] = array(
	'bad_token_rejected' => 200 === $resp->get_status() && empty( $aipc_bad['ok'] ) && false !== strpos( (string) $aipc_bad['error'], 'mock bale' ),
);

$req = new WP_REST_Request( 'POST', '/aipc/v1/bale/test' );
$resp = rest_do_request( $req );
$aipc_ok = $resp->get_data();
$out['rest_bale']['stored_ok'] = 200 === $resp->get_status() && ! empty( $aipc_ok['ok'] );

$req = new WP_REST_Request( 'POST', '/aipc/v1/bale/chat-id' );
$resp = rest_do_request( $req );
$aipc_chat = $resp->get_data();
$out['rest_bale']['chat_id_detected'] = ! empty( $aipc_chat['ok'] ) && '98765' === (string) $aipc_chat['chat_id'];

wp_set_current_user( 0 );
$req = new WP_REST_Request( 'POST', '/aipc/v1/bale/test' );
$resp = rest_do_request( $req );
$out['rest_bale']['anonymous_401'] = 401 === $resp->get_status();
wp_set_current_user( 1 );

/* ------------------------------------------------------------------ *
 * Bale mock traffic: one notification per created post (+ test calls)
 * ------------------------------------------------------------------ */
$aipc_bale_requests = array();
if ( file_exists( $log_file ) ) {
	foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$aipc_row = json_decode( $line, true );
		if ( $aipc_row && 'bale' === $aipc_row['host'] ) {
			$aipc_bale_requests[] = $aipc_row;
		}
	}
}
$aipc_methods = array();
foreach ( $aipc_bale_requests as $aipc_r ) {
	$aipc_methods[ $aipc_r['method'] ] = ( isset( $aipc_methods[ $aipc_r['method'] ] ) ? $aipc_methods[ $aipc_r['method'] ] : 0 ) + 1;
}
$aipc_msg_calls = array_values( array_filter( $aipc_bale_requests, function ( $r ) {
	return 'sendMessage' === $r['method'];
} ) );

$aipc_count_photos_where = function ( $needle ) use ( $aipc_bale_requests ) {
	$chats = array();
	foreach ( $aipc_bale_requests as $aipc_r ) {
		if ( 'sendPhoto' === $aipc_r['method'] && false !== strpos( (string) $aipc_r['caption'], $needle ) ) {
			$chats[] = (string) $aipc_r['chat_id'];
		}
	}
	sort( $chats );
	return $chats;
};

$aipc_count_msgs_where = function ( $needle, $with_link ) use ( $aipc_msg_calls ) {
	$chats = array();
	foreach ( $aipc_msg_calls as $aipc_r ) {
		$aipc_body = (string) $aipc_r['text'];
		if ( false !== strpos( $aipc_body, $needle )
			&& ( ! $with_link || false !== strpos( $aipc_body, 'http://localhost' ) ) ) {
			$chats[] = (string) $aipc_r['chat_id'];
		}
	}
	sort( $chats );
	return $chats;
};

$aipc_photo_ok = function ( $needle ) use ( $aipc_bale_requests ) {
	$chats = array();
	$caption_ok = true;
	foreach ( $aipc_bale_requests as $aipc_r ) {
		if ( 'sendPhoto' === $aipc_r['method'] && false !== strpos( (string) $aipc_r['caption'], $needle ) ) {
			$chats[] = (string) $aipc_r['chat_id'];
			if ( false === strpos( (string) $aipc_r['caption'], 'http://localhost' ) || empty( $aipc_r['photo'] ) ) {
				$caption_ok = false;
			}
		}
	}
	sort( $chats );
	return $caption_ok && array( '12345', '67890' ) === $chats;
};

/**
 * Chats that received a sendMessage containing ALL needles.
 *
 * @param array $needles Required substrings.
 * @return array
 */
$aipc_msgs_with_all = function ( array $needles ) use ( $aipc_msg_calls ) {
	$chats = array();
	foreach ( $aipc_msg_calls as $aipc_r ) {
		$aipc_body = (string) $aipc_r['text'];
		$aipc_hit  = true;
		foreach ( $needles as $aipc_needle ) {
			if ( false === strpos( $aipc_body, $aipc_needle ) ) {
				$aipc_hit = false;
				break;
			}
		}
		if ( $aipc_hit ) {
			$chats[] = (string) $aipc_r['chat_id'];
		}
	}
	sort( $chats );
	return $chats;
};

/**
 * Chats that received a sendPhoto whose caption contains the needle.
 *
 * @param string $needle Required substring.
 * @return array
 */
$aipc_photos_with = function ( $needle ) use ( $aipc_bale_requests ) {
	$chats = array();
	foreach ( $aipc_bale_requests as $aipc_r ) {
		if ( 'sendPhoto' === $aipc_r['method'] && false !== strpos( (string) $aipc_r['caption'], $needle ) ) {
			$chats[] = (string) $aipc_r['chat_id'];
		}
	}
	sort( $chats );
	return $chats;
};

$out['bale_traffic'] = array(
	'total'             => count( $aipc_bale_requests ),
	'methods'           => $aipc_methods,
	'balcony_photo_chats'    => $aipc_photo_ok( 'راهنمای کامل سبزی‌کاری در بالکن' ), // main run → both chats
	'retry_notify_chats'     => ( function () use ( $aipc_count_msgs_where ) {
		$chats = $aipc_count_msgs_where( 'failure flow topic', true );
		sort( $chats );
		return array( '12345', '67890' ) === $chats; // retry run (image off) → both chats via text
	} )(),
	'scheduled_msg_chats'    => ( function () use ( $aipc_count_msgs_where ) {
		$chats = $aipc_count_msgs_where( 'شروع کاشت قارچ در خانه', true );
		sort( $chats );
		return array( '12345', '67890' ) === $chats; // scheduled run (no image) → both chats
	} )(),
	'report_msg_chats'       => ( function () use ( $aipc_count_msgs_where ) {
		$chats = $aipc_count_msgs_where( '🧾', false );
		sort( $chats );
		return array( '12345', '67890' ) === $chats; // exactly one report per chat
	} )(),
	'no_bad_chats'       => ! array_filter( $aipc_bale_requests, function ( $r ) {
		return 'getUpdates' !== $r['method'] && ! in_array( (string) $r['chat_id'], array( '12345', '67890' ), true );
	} ),
	// v1.5.0: publish "now" → 🎉 message to every chat
	'publish_now_chats'  => array( '12345', '67890' ) === $aipc_msgs_with_all( array( 'تست انتشار فوری', '🔻' ) ),
	// v1.5.0/1.9.2: publish "delay" → draft + published notification (same 🔻 format, 2 per chat)
	'delay_draft_chats'  => array( '12345', '67890' ) === array_values( array_unique( $aipc_msgs_with_all( array( 'تست انتشار با تأخیر', '🔻' ) ) ) ),
	'delay_pub_chats'    => 4 === count( array_filter( $aipc_msg_calls, function ( $r ) {
		return false !== strpos( (string) $r['text'], 'تست انتشار با تأخیر' ) && false !== strpos( (string) $r['text'], '🔻' );
	} ) ),
	// v1.5.0: rewrite → ♻️ photo notification to every chat
	'rewrite_photo_chats'=> ( function () use ( $aipc_photos_with ) {
		$chats = $aipc_photos_with( 'بازنویسی' );
		return array( '12345', '67890' ) === $chats;
	} )(),
	'rewrite_photo_has_image' => ( function () use ( $aipc_bale_requests ) {
		foreach ( $aipc_bale_requests as $aipc_r ) {
			if ( 'sendPhoto' === $aipc_r['method'] && false !== strpos( (string) $aipc_r['caption'], 'بازنویسی' ) && empty( $aipc_r['photo'] ) ) {
				return false;
			}
		}
		return true;
	} )(),
);

/* ------------------------------------------------------------------ *
 * Bale notification format (v1.9.2): 🔻title / 🌱🌱summary🌱🌱 / read-more
 * line / link — and the default notification image for posts without
 * a featured image.
 * ------------------------------------------------------------------ */
$aipc_fmt_msg = '';
foreach ( $aipc_msg_calls as $aipc_r ) {
	if ( false !== strpos( (string) $aipc_r['text'], 'شروع کاشت قارچ در خانه' ) ) {
		$aipc_fmt_msg = (string) $aipc_r['text'];
		break;
	}
}

$aipc_bale_cfg_bak = AIPC_Bale::all();
AIPC_Bale::save( array_merge( $aipc_bale_cfg_bak, array( 'default_image' => 'https://mock.invalid/default.png' ) ) );
$aipc_noimg_post = wp_insert_post( array( 'post_title' => 'پست بدون تصویر برای بله', 'post_content' => '<p>متن آزمایشی.</p>', 'post_status' => 'publish' ) );
update_post_meta( $aipc_noimg_post, '_aipc_meta_description', 'خلاصهٔ آزمایشی برای پیام بله' );
AIPC_Bale::notify( $aipc_noimg_post, 'job_none' );
AIPC_Bale::save( $aipc_bale_cfg_bak );

$aipc_default_photo = null;
if ( file_exists( $log_file ) ) {
	foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$aipc_row = json_decode( $line, true );
		if ( $aipc_row && 'bale' === $aipc_row['host'] && 'sendPhoto' === $aipc_row['method']
			&& false !== strpos( (string) $aipc_row['caption'], 'پست بدون تصویر برای بله' ) ) {
			$aipc_default_photo = $aipc_row;
		}
	}
}

$aipc_fmt_sane_ok  = AIPC_Bale::sanitize( array( 'default_image' => 'https://example.com/img.png' ), $aipc_bale_cfg_bak );
$aipc_fmt_sane_bad = AIPC_Bale::sanitize( array( 'default_image' => 'javascript:alert(1)' ), $aipc_bale_cfg_bak );

$out['bale_format'] = array(
	'starts_with_marker' => 0 === strpos( $aipc_fmt_msg, '🔻' ),
	'summary_wrapped'    => 2 === substr_count( $aipc_fmt_msg, '🌱🌱' ),
	'read_more_line'     => false !== strpos( $aipc_fmt_msg, 'ادامه مطلب در لینک زیر' ) && false !== strpos( $aipc_fmt_msg, '👇👇👇' ),
	'has_link'           => false !== strpos( $aipc_fmt_msg, 'http://localhost' ),
	'default_img_used'   => is_array( $aipc_default_photo ) && 'https://mock.invalid/default.png' === $aipc_default_photo['photo'],
	'default_img_format' => is_array( $aipc_default_photo ) && false !== strpos( (string) $aipc_default_photo['caption'], '🌱🌱خلاصهٔ آزمایشی برای پیام بله🌱🌱' ),
	'sanitize_url'       => 'https://example.com/img.png' === $aipc_fmt_sane_ok['default_image'],
	'sanitize_blocks_js' => '' === $aipc_fmt_sane_bad['default_image'],
);
wp_delete_post( $aipc_noimg_post, true );

/* ------------------------------------------------------------------ *
 * Persian half-space preservation (v1.10.0): rule-based ZWNJ fixing
 * + &zwnj; armoring for post content.
 * ------------------------------------------------------------------ */
$out['text_zwnj'] = array(
	'prefix_mi'        => 'می‌شود' === AIPC_Text::fix_zwnj( 'می شود' ),
	'prefix_nemi'      => 'نمی‌تواند' === AIPC_Text::fix_zwnj( 'نمی تواند' ),
	'mid_sentence'     => 'او می‌رود' === AIPC_Text::fix_zwnj( 'او می رود' ),
	'suffix_ha'        => 'کتاب‌ها' === AIPC_Text::fix_zwnj( 'کتاب ها' ),
	'suffix_tar'       => 'سریع‌تر است' === AIPC_Text::fix_zwnj( 'سریع تر است' ),
	'suffix_tarin'     => 'مهم‌ترین نکته' === AIPC_Text::fix_zwnj( 'مهم ترین نکته' ),
	'keeps_existing'   => 'می‌شود' === AIPC_Text::fix_zwnj( 'می‌شود' ),
	'word_untouched'   => 'این ترکیب خوب است' === AIPC_Text::fix_zwnj( 'این ترکیب خوب است' ),
	'latin_untouched'  => 'hello world' === AIPC_Text::fix_zwnj( 'hello world' ),
	'entity_armor'     => '<p>می&zwnj;شود</p>' === AIPC_Text::fix_zwnj_html( '<p>می شود</p>' ),
	// v1.12.0 — glued suffixes (ZWNJ stripped entirely by the provider).
	'glued_hay'        => 'نوشیدنی‌های گرم' === AIPC_Text::fix_zwnj( 'نوشیدنیهای گرم' ),
	'glued_ha'         => 'خانواده‌ها' === AIPC_Text::fix_zwnj( 'خانوادهها' ),
	'glued_eh_i'       => 'موکاچینو خانگی حرفه‌ای' === AIPC_Text::fix_zwnj( 'موکاچینو خانگی حرفهای' ),
	'glued_mand'       => 'علاقه‌مندان' === AIPC_Text::fix_zwnj( 'علاقهمندان' ),
	'glued_sazi'       => 'آماده‌سازی' === AIPC_Text::fix_zwnj( 'آمادهسازی' ),
	'exception_tanha'  => 'او تنها بود' === AIPC_Text::fix_zwnj( 'او تنها بود' ),
	'exception_baha'   => 'بهای کالا' === AIPC_Text::fix_zwnj( 'بهای کالا' ),
	'nonjoin_safe'     => 'بارها گفتم' === AIPC_Text::fix_zwnj( 'بارها گفتم' ),
);

/* ------------------------------------------------------------------ *
 * Default image prompt + thumbnail regeneration (v1.10.0).
 * ------------------------------------------------------------------ */
$aipc_rt_set_bak  = get_option( 'aipc_settings' );
$aipc_rt_conn_bak = get_option( 'aipc_connections' );

$aipc_rt_base = is_array( $aipc_rt_set_bak ) ? $aipc_rt_set_bak : array();
update_option( 'aipc_settings', array_merge( $aipc_rt_base, array( 'image_prompt_default' => '' ) ), false );
$aipc_rt_empty = AIPC_Agent::apply_image_prompt_default( 'A cat on a roof' );

update_option( 'aipc_settings', array_merge( $aipc_rt_base, array( 'image_prompt_default' => 'flat vector style, no text' ) ), false );
$aipc_rt_applied = AIPC_Agent::apply_image_prompt_default( 'A cat on a roof' );
$aipc_rt_nodup   = AIPC_Agent::apply_image_prompt_default( 'A cat, flat vector style, no text' );
$aipc_rt_sane    = AIPC_Settings::sanitize( array( 'image_prompt_default' => '  spacious  ' . str_repeat( 'x', 700 ) ) );

update_option( 'aipc_connections', array(
	array( 'id' => 'mockrt', 'name' => 'Mock RT', 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-image-key', 'chat_model' => 'mock-chat', 'image_model' => 'mock-image', 'purpose' => 'both', 'priority' => 1, 'enabled' => 1, 'image_api' => 'images', 'image_format' => 'auto', 'is_default' => 1 ),
), false );

$aipc_rt_post = wp_insert_post( array( 'post_title' => 'عنوان تست تصویر شاخص', 'post_content' => '<p>متن آزمایشی.</p>', 'post_status' => 'publish' ) );
update_post_meta( $aipc_rt_post, '_aipc_meta_description', 'خلاصهٔ آزمایشی برای تصویر.' );
$aipc_rt_res = AIPC_Agent::regenerate_thumbnail( $aipc_rt_post );

$aipc_rt_prompt = '';
if ( file_exists( $log_file ) ) {
	foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$aipc_row = json_decode( $line, true );
		if ( $aipc_row && isset( $aipc_row['url'] ) && false !== strpos( (string) $aipc_row['url'], '/images/generations' )
			&& false !== strpos( (string) $aipc_row['prompt'], 'flat vector style' ) ) {
			$aipc_rt_prompt = (string) $aipc_row['prompt'];
		}
	}
}

update_option( 'aipc_connections', array(), false );
$aipc_rt_noconn = AIPC_Agent::regenerate_thumbnail( $aipc_rt_post );
$aipc_rt_nopost = AIPC_Agent::regenerate_thumbnail( 999999 );

$out['image_defaults'] = array(
	'empty_no_change'  => 'A cat on a roof' === $aipc_rt_empty,
	'suffix_appended'  => 'A cat on a roof. flat vector style, no text' === $aipc_rt_applied,
	'no_duplicate'     => 'A cat, flat vector style, no text' === $aipc_rt_nodup,
	'sanitize_caps'    => 600 === mb_strlen( $aipc_rt_sane['image_prompt_default'] ),
	'regen_attachment' => is_int( $aipc_rt_res ) && $aipc_rt_res > 0,
	'regen_thumb_set'  => (int) get_post_thumbnail_id( $aipc_rt_post ) === (int) $aipc_rt_res,
	'regen_uses_suffix' => '' !== $aipc_rt_prompt && false !== strpos( $aipc_rt_prompt, 'balcony' ),
	'regen_no_conn'    => is_wp_error( $aipc_rt_noconn ),
	'regen_no_post'    => is_wp_error( $aipc_rt_nopost ),
);

wp_delete_post( $aipc_rt_post, true );
update_option( 'aipc_settings', $aipc_rt_set_bak, false );
update_option( 'aipc_connections', $aipc_rt_conn_bak, false );

/* ------------------------------------------------------------------ *
 * Full API trace log (v1.11.0): request/response capture, context,
 * redaction, rotation plumbing, enable/disable + clear semantics.
 * ------------------------------------------------------------------ */
$aipc_tl_set_bak = get_option( 'aipc_settings' );
$aipc_tl_base    = is_array( $aipc_tl_set_bak ) ? $aipc_tl_set_bak : array();

update_option( 'aipc_settings', array_merge( $aipc_tl_base, array( 'debug_log' => 1 ) ), false );
AIPC_Trace::clear();

$aipc_tl_conn   = array( 'name' => 'TraceConn', 'base_url' => 'https://mock.invalid/v1', 'api_key' => 'sk-chat-key', 'chat_model' => 'mock-chat', 'image_model' => 'mock-image', 'image_api' => 'images', 'request_timeout' => 30, 'temperature' => 0.7, 'max_tokens' => 1000 );
AIPC_Trace::set_context( array( 'job' => 'job_trace', 'step' => 'unit', 'conn' => 'TraceConn', 'try' => 1 ) );
$aipc_tl_client = new AIPC_API_Client( $aipc_tl_conn );
$aipc_tl_client->chat( array( array( 'role' => 'user', 'content' => 'سلام تست ترِیس' ) ) );
$aipc_tl_client->image( 'A tiny trace test image' );
AIPC_Trace::clear_context();

$aipc_tl_raw  = file_exists( AIPC_Trace::path() ) ? file_get_contents( AIPC_Trace::path() ) : '';
$aipc_tl_rows = array();
foreach ( array_filter( explode( "\n", $aipc_tl_raw ) ) as $aipc_tl_line ) {
	$aipc_tl_row = json_decode( $aipc_tl_line, true );
	if ( is_array( $aipc_tl_row ) ) {
		$aipc_tl_rows[] = $aipc_tl_row;
	}
}

$aipc_tl_size_on = AIPC_Trace::size();
update_option( 'aipc_settings', array_merge( $aipc_tl_base, array( 'debug_log' => 0 ) ), false );
$aipc_tl_client->chat( array( array( 'role' => 'user', 'content' => 'should not be logged' ) ) );
$aipc_tl_size_off = AIPC_Trace::size();

$aipc_tl_redacted = AIPC_Trace::redact( array( 'api_key' => 'sk-secret', 'blob' => str_repeat( 'A', 500 ), 'msg' => 'Bearer sk-abcdefgh12345 rest' ) );

$out['api_trace'] = array(
	'file_created'   => '' !== $aipc_tl_raw && count( $aipc_tl_rows ) >= 2,
	'request_logged' => false !== strpos( $aipc_tl_raw, 'سلام تست ترِیس' ),
	'reply_logged'   => false !== strpos( $aipc_tl_raw, '"choices"' ) || false !== strpos( $aipc_tl_raw, '"data"' ),
	'context_kept'   => isset( $aipc_tl_rows[0]['job'], $aipc_tl_rows[0]['step'], $aipc_tl_rows[0]['try'] ) && 'job_trace' === $aipc_tl_rows[0]['job'],
	'status_and_ms'  => isset( $aipc_tl_rows[0]['status'], $aipc_tl_rows[0]['ms'] ) && 200 === $aipc_tl_rows[0]['status'],
	'no_key_leak'    => false === strpos( $aipc_tl_raw, 'sk-chat-key' ),
	'b64_collapsed'  => '***' === $aipc_tl_redacted['api_key'] && false !== strpos( $aipc_tl_redacted['blob'], '[base64 omitted: 500 chars]' ) && false !== strpos( $aipc_tl_redacted['msg'], 'Bearer ***' ),
	'off_means_off'  => $aipc_tl_size_off === $aipc_tl_size_on && $aipc_tl_size_on > 0,
	'dir_protected'  => file_exists( AIPC_Trace::dir() . '/.htaccess' ),
	'clear_works'    => ( AIPC_Trace::clear() === null ) && 0 === AIPC_Trace::size(),
);

update_option( 'aipc_settings', $aipc_tl_set_bak, false );

/* ------------------------------------------------------------------ *
 * Link policy (v1.12.0): one internal link per target URL, and the
 * optional removal of research-source links.
 * ------------------------------------------------------------------ */
$aipc_lp_set_bak = get_option( 'aipc_settings' );
$aipc_lp_base    = is_array( $aipc_lp_set_bak ) ? $aipc_lp_set_bak : array();

$aipc_lp_html = '<p><a href="http://localhost/a/">اول</a> و <a href="http://localhost/a/">دوم</a> و '
	. '<a href="http://localhost/b/">دیگر</a> و <a href="https://example.com/x">بیرونی ۱</a> و '
	. '<a href="https://example.com/x">بیرونی ۲</a> و <a href="https://news.invalid/item-1">منبع</a></p>';

update_option( 'aipc_settings', array_merge( $aipc_lp_base, array( 'source_links' => 1, 'source_sites' => 'https://news.invalid' ) ), false );
$aipc_lp_on = AIPC_Post_Builder::clean_links( $aipc_lp_html );

update_option( 'aipc_settings', array_merge( $aipc_lp_base, array( 'source_links' => 0, 'source_sites' => 'https://news.invalid' ) ), false );
$aipc_lp_off = AIPC_Post_Builder::clean_links( $aipc_lp_html );

$aipc_lp_sc = new ReflectionMethod( 'AIPC_Agent', 'source_context' );
$aipc_lp_sc->setAccessible( true );
$aipc_lp_ctx_off = (string) $aipc_lp_sc->invoke( AIPC_Agent::instance() );
update_option( 'aipc_settings', array_merge( $aipc_lp_base, array( 'source_links' => 1, 'source_sites' => 'https://news.invalid' ) ), false );
$aipc_lp_ctx_on = (string) $aipc_lp_sc->invoke( AIPC_Agent::instance() );

$out['link_policy'] = array(
	'first_internal_kept'  => false !== strpos( $aipc_lp_on, '<a href="http://localhost/a/">اول</a>' ),
	'dup_internal_unwrap'  => false !== strpos( $aipc_lp_on, '> و دوم و <' ) || ( false !== strpos( $aipc_lp_on, 'دوم' ) && 1 === substr_count( $aipc_lp_on, 'href="http://localhost/a/"' ) ),
	'other_internal_kept'  => false !== strpos( $aipc_lp_on, '<a href="http://localhost/b/">دیگر</a>' ),
	'external_untouched'   => 2 === substr_count( $aipc_lp_on, 'href="https://example.com/x"' ),
	'source_kept_when_on'  => false !== strpos( $aipc_lp_on, '<a href="https://news.invalid/item-1">منبع</a>' ),
	'source_cut_when_off'  => false === strpos( $aipc_lp_off, 'href="https://news.invalid' ) && false !== strpos( $aipc_lp_off, 'منبع' ),
	'prompt_links_on'      => false !== strpos( $aipc_lp_ctx_on, 'https://news.invalid/item-1' ),
	'prompt_links_off'     => false === strpos( $aipc_lp_ctx_off, 'https://news.invalid/item-1' ) && false !== strpos( $aipc_lp_ctx_off, 'SOURCE: news.invalid' ),
);

update_option( 'aipc_settings', $aipc_lp_set_bak, false );

/* ------------------------------------------------------------------ *
 * ZWNJ forensic pipeline (v1.12.1): prove the half-space survives every
 * single stage — provider bytes (escaped + raw), kses, DB save, read
 * back, front-end filters, and an editor-style re-save.
 * ------------------------------------------------------------------ */
$aipc_zp_zwnj = "\xE2\x80\x8C";

// Stage 1: provider JSON → PHP string (both encodings providers use).
$aipc_zp_escaped = json_decode( '{"content":"<p>می\u200cشود تست</p>"}', true );
$aipc_zp_raw     = json_decode( '{"content":"<p>می' . $aipc_zp_zwnj . 'شود تست</p>"}', true );

// Stage 2: the agent's HTML cleanup (wp_kses_post) on both forms.
$aipc_zp_kses_char   = wp_kses_post( '<p>می' . $aipc_zp_zwnj . 'شود</p>' );
$aipc_zp_kses_entity = wp_kses_post( '<p>می&zwnj;شود</p>' );

// Stage 3: save → DB → read back (raw char and armored entity).
$aipc_zp_pid  = wp_insert_post( array(
	'post_title'   => 'عنوان می' . $aipc_zp_zwnj . 'شود',
	'post_content' => '<p>خام: می' . $aipc_zp_zwnj . 'شود — زره: می&zwnj;شود</p>',
	'post_status'  => 'publish',
) );
$aipc_zp_post = get_post( $aipc_zp_pid );

// Stage 4: front-end rendering filters.
$aipc_zp_front = apply_filters( 'the_content', $aipc_zp_post->post_content );

// Stage 5: editor-style re-save WITHOUT unfiltered_html (kses filters
// active, data slashed exactly like wp-admin does).
kses_init_filters();
wp_update_post( wp_slash( array( 'ID' => $aipc_zp_pid, 'post_content' => $aipc_zp_post->post_content ) ) );
kses_remove_filters();
$aipc_zp_resaved = get_post( $aipc_zp_pid );

$out['zwnj_pipeline'] = array(
	'provider_escaped' => is_array( $aipc_zp_escaped ) && false !== strpos( $aipc_zp_escaped['content'], $aipc_zp_zwnj ),
	'provider_raw'     => is_array( $aipc_zp_raw ) && false !== strpos( $aipc_zp_raw['content'], $aipc_zp_zwnj ),
	'kses_keeps_char'  => false !== strpos( $aipc_zp_kses_char, $aipc_zp_zwnj ),
	'kses_keeps_entity' => false !== strpos( $aipc_zp_kses_entity, '&zwnj;' ),
	'db_keeps_title'   => false !== strpos( $aipc_zp_post->post_title, $aipc_zp_zwnj ),
	'db_keeps_char'    => false !== strpos( $aipc_zp_post->post_content, $aipc_zp_zwnj ),
	'db_keeps_entity'  => false !== strpos( $aipc_zp_post->post_content, '&zwnj;' ),
	'front_renders'    => false !== strpos( $aipc_zp_front, $aipc_zp_zwnj ) && false !== strpos( $aipc_zp_front, '&zwnj;' ),
	'editor_resave'    => false !== strpos( $aipc_zp_resaved->post_content, '&zwnj;' ) && false !== strpos( $aipc_zp_resaved->post_content, $aipc_zp_zwnj ),
	'armor_converts'   => 'می&zwnj;شود' === AIPC_Text::fix_zwnj_html( 'می' . $aipc_zp_zwnj . 'شود' ),
);
wp_delete_post( $aipc_zp_pid, true );

/* ------------------------------------------------------------------ *
 * Custom image size (v1.12.1).
 * ------------------------------------------------------------------ */
$aipc_cs_ok1 = AIPC_Settings::sanitize( array( 'image_size_select' => 'custom', 'image_size_custom' => '800x600' ) );
$aipc_cs_ok2 = AIPC_Settings::sanitize( array( 'image_size_select' => '1024x1024' ) );
$aipc_cs_ok3 = AIPC_Settings::sanitize( array( 'image_size' => '640X480' ) );
$aipc_cs_bad = AIPC_Settings::sanitize( array( 'image_size_select' => 'custom', 'image_size_custom' => 'huge;drop table' ) );
$aipc_cs_old = AIPC_Settings::all();

$out['image_size_custom'] = array(
	'custom_accepted'  => '800x600' === $aipc_cs_ok1['image_size'],
	'preset_accepted'  => '1024x1024' === $aipc_cs_ok2['image_size'],
	'direct_normalized' => '640x480' === $aipc_cs_ok3['image_size'],
	'junk_rejected'    => $aipc_cs_bad['image_size'] === $aipc_cs_old['image_size'],
);

/* ------------------------------------------------------------------ *
 * Bale inline buttons (v1.13.0): publish-now / schedule keyboard,
 * callback handling, Jalali+Gregorian date parsing, native future
 * scheduling.
 * ------------------------------------------------------------------ */
$aipc_bb_old_cfg = get_option( AIPC_Bale::OPTION, array() );
update_option( AIPC_Bale::OPTION, array(
	'enabled'  => 1,
	'token'    => 'test-token',
	'chat_ids' => array( '99' ),
	'two_way'  => 1,
), false );
delete_option( AIPC_Bale_Commands::PENDING_OPTION );

// Capture every Bale API request instead of hitting the network.
$aipc_bb_reqs = array();
$aipc_bb_mock = function ( $pre, $args, $url ) use ( &$aipc_bb_reqs ) {
	if ( false !== strpos( $url, 'tapi.bale.ai' ) ) {
		$aipc_bb_reqs[] = array( 'url' => $url, 'body' => isset( $args['body'] ) ? (string) $args['body'] : '' );
		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"ok":true,"result":{}}',
			'cookies'  => array(),
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $aipc_bb_mock, 4, 3 );

$aipc_bb_tz  = wp_timezone();
$aipc_bb_exp = ( new DateTimeImmutable( '2026-10-12 18:30', $aipc_bb_tz ) )->getTimestamp();
$aipc_bb_tom = ( new DateTimeImmutable( 'now', $aipc_bb_tz ) )->modify( '+1 day' )->setTime( 10, 0, 0 )->getTimestamp();

// Two draft posts "created by the agent".
$aipc_bb_p1 = wp_insert_post( array( 'post_title' => 'Bale button post one', 'post_content' => '<p>x</p>', 'post_status' => 'draft' ) );
$aipc_bb_p2 = wp_insert_post( array( 'post_title' => 'Bale button post two', 'post_content' => '<p>y</p>', 'post_status' => 'draft' ) );
update_post_meta( $aipc_bb_p1, '_aipc_generated', time() );
update_post_meta( $aipc_bb_p2, '_aipc_generated', time() );

$aipc_bb_kb = AIPC_Bale::post_keyboard( $aipc_bb_p1 );
$aipc_bb_kb_json = wp_json_encode( $aipc_bb_kb );

// Notification body carries the keyboard.
$aipc_bb_reqs = array();
AIPC_Bale::notify( $aipc_bb_p1, 'no-such-job' );
$aipc_bb_notify_body = ! empty( $aipc_bb_reqs ) ? $aipc_bb_reqs[0]['body'] : '';

// Publish-now button.
$aipc_bb_pub_reply = AIPC_Bale_Commands::handle_callback( '99', 'aipc:pub:' . $aipc_bb_p1 );
$aipc_bb_pub_post  = get_post( $aipc_bb_p1 );
$aipc_bb_again     = AIPC_Bale_Commands::handle_callback( '99', 'aipc:pub:' . $aipc_bb_p1 );

// Schedule button → pending → date reply.
$aipc_bb_sch_reply = AIPC_Bale_Commands::handle_callback( '99', 'aipc:sch:' . $aipc_bb_p2 );
$aipc_bb_pending   = AIPC_Bale_Commands::get_pending( '99' );
$aipc_bb_past      = AIPC_Bale_Commands::handle( '99', '2020-01-01 10:00' );
$aipc_bb_past_keep = AIPC_Bale_Commands::get_pending( '99' ) === $aipc_bb_p2;
$aipc_bb_badfmt    = AIPC_Bale_Commands::handle( '99', 'بلبل' );
$aipc_bb_sched     = AIPC_Bale_Commands::handle( '99', '۱۴۰۵/۰۷/۲۰ ۱۸:۳۰' );
$aipc_bb_sch_post  = get_post( $aipc_bb_p2 );

// Cancel flow on a fresh pending.
AIPC_Bale_Commands::handle_callback( '99', 'aipc:sch:' . $aipc_bb_p2 );
$aipc_bb_cancel  = AIPC_Bale_Commands::handle( '99', 'لغو' );
$aipc_bb_cleared = AIPC_Bale_Commands::get_pending( '99' );

// WP publishes the scheduled post → Bale 🎉 hook fires once.
$aipc_bb_reqs = array();
AIPC_Bale::on_future_publish( get_post( $aipc_bb_p2 ) );
$aipc_bb_hook_sent = count( $aipc_bb_reqs ) > 0;
$aipc_bb_meta_gone = '' === (string) get_post_meta( $aipc_bb_p2, '_aipc_bale_scheduled', true );

$out['bale_buttons'] = array(
	'parse_gregorian'    => AIPC_Bale_Commands::parse_datetime( '2026-10-12 18:30' ) === $aipc_bb_exp,
	'parse_jalali'       => AIPC_Bale_Commands::parse_datetime( '1405/07/20 18:30' ) === $aipc_bb_exp,
	'parse_fa_digits'    => AIPC_Bale_Commands::parse_datetime( '۱۴۰۵/۰۷/۲۰ ۱۸:۳۰' ) === $aipc_bb_exp,
	'parse_tomorrow'     => AIPC_Bale_Commands::parse_datetime( 'فردا 10:00' ) === $aipc_bb_tom,
	'parse_default_time' => AIPC_Bale_Commands::parse_datetime( '2026-10-12' ) === $aipc_bb_exp - ( 9 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS ),
	'parse_invalid'      => 0 === AIPC_Bale_Commands::parse_datetime( 'بلبل' ) && 0 === AIPC_Bale_Commands::parse_datetime( '2026-13-01 10:00' ),
	'keyboard_draft'     => is_array( $aipc_bb_kb )
		&& false !== strpos( $aipc_bb_kb_json, 'aipc:pub:' . $aipc_bb_p1 )
		&& false !== strpos( $aipc_bb_kb_json, 'aipc:sch:' . $aipc_bb_p1 ),
	'notify_has_buttons' => false !== strpos( $aipc_bb_notify_body, 'reply_markup' )
		&& false !== strpos( $aipc_bb_notify_body, 'aipc:pub:' . $aipc_bb_p1 ),
	'pub_publishes_now'  => 'publish' === $aipc_bb_pub_post->post_status
		&& abs( strtotime( $aipc_bb_pub_post->post_date_gmt . ' +0000' ) - time() ) < 120
		&& false !== strpos( (string) $aipc_bb_pub_reply, '🚀' ),
	'pub_idempotent'     => false !== strpos( (string) $aipc_bb_again, '🔗' )
		&& 'publish' === get_post( $aipc_bb_p1 )->post_status,
	'keyboard_published' => null === AIPC_Bale::post_keyboard( $aipc_bb_p1 ),
	'sch_asks_for_date'  => false !== strpos( (string) $aipc_bb_sch_reply, '⏰' ) && $aipc_bb_pending === $aipc_bb_p2,
	'past_rejected'      => is_string( $aipc_bb_past ) && '' !== $aipc_bb_past
		&& false === strpos( $aipc_bb_past, '⏰' ) && $aipc_bb_past_keep,
	'badfmt_hint'        => false !== strpos( (string) $aipc_bb_badfmt, '18:30' ),
	'date_schedules'     => 'future' === $aipc_bb_sch_post->post_status
		&& '2026-10-12 18:30:00' === wp_date( 'Y-m-d H:i:s', strtotime( $aipc_bb_sch_post->post_date_gmt . ' +0000' ) )
		&& false !== strpos( (string) $aipc_bb_sched, '⏰' )
		&& 0 === AIPC_Bale_Commands::get_pending( '99' ),
	'cancel_clears'      => is_string( $aipc_bb_cancel ) && '' !== $aipc_bb_cancel && 0 === $aipc_bb_cleared,
	'future_hook_fires'  => $aipc_bb_hook_sent && $aipc_bb_meta_gone,
);

remove_filter( 'pre_http_request', $aipc_bb_mock, 4 );
wp_delete_post( $aipc_bb_p1, true );
wp_delete_post( $aipc_bb_p2, true );
delete_option( AIPC_Bale_Commands::PENDING_OPTION );
update_option( AIPC_Bale::OPTION, $aipc_bb_old_cfg, false );

/* ------------------------------------------------------------------ *
 * v1.6 — outbound network guard (SSRF protection)
 * ------------------------------------------------------------------ */
$aipc_is_safe = function ( $url ) {
	return AIPC_Network::is_safe_url( $url );
};

$out['network_guard'] = array(
	'public_ok'       => $aipc_is_safe( 'https://api.openai.com/v1' ),
	'host_ok'         => $aipc_is_safe( 'https://mock.invalid/v1' ),
	'loopback_ok'     => $aipc_is_safe( 'http://localhost:11434/v1' ) && $aipc_is_safe( 'http://127.0.0.1:1234/v1' ) && $aipc_is_safe( 'http://[::1]:9000/v1' ),
	'private_10'      => ! $aipc_is_safe( 'http://10.0.0.5/v1' ),
	'private_172'     => ! $aipc_is_safe( 'http://172.20.3.4/v1' ),
	'private_192'     => ! $aipc_is_safe( 'http://192.168.1.10/v1' ),
	'metadata'        => ! $aipc_is_safe( 'http://169.254.169.254/latest/meta-data' ),
	'carrier_nat'     => ! $aipc_is_safe( 'http://100.64.7.7/v1' ),
	'v6_ula'          => ! $aipc_is_safe( 'http://[fd00::5]/v1' ),
	'v6_link_local'   => ! $aipc_is_safe( 'http://[fe80::1]/v1' ),
	'v4_mapped'       => ! $aipc_is_safe( 'http://[::ffff:10.0.0.5]/v1' ),
	'bad_scheme'      => ! $aipc_is_safe( 'ftp://example.com/x' ),
	'no_host'         => ! $aipc_is_safe( 'https://' ),
);

// Allowlist filter: exact IP + wildcard suffix, others still blocked.
$aipc_allow_hosts = function () {
	return array( '10.1.2.3', '*.corp.example' );
};
add_filter( 'aipc_outbound_allowlist', $aipc_allow_hosts );
$out['network_guard']['allowlist'] = $aipc_is_safe( 'http://10.1.2.3/v1' )
	&& $aipc_is_safe( 'http://intranet.corp.example/feed/' )
	&& ! $aipc_is_safe( 'http://10.9.9.9/v1' );
remove_filter( 'aipc_outbound_allowlist', $aipc_allow_hosts );

// Private-hosts filter opens internal ranges; loopback filter closes them.
$aipc_priv_on = function () {
	return true;
};
add_filter( 'aipc_allow_private_hosts', $aipc_priv_on );
$out['network_guard']['private_filter'] = $aipc_is_safe( 'http://10.0.0.5/v1' );
remove_filter( 'aipc_allow_private_hosts', $aipc_priv_on );

$aipc_lb_off = function () {
	return false;
};
add_filter( 'aipc_allow_loopback', $aipc_lb_off );
$out['network_guard']['loopback_filter'] = ! $aipc_is_safe( 'http://localhost:11434/v1' );
remove_filter( 'aipc_allow_loopback', $aipc_lb_off );

// Call sites: connections, settings and image downloads all go through it.
$aipc_conn_blocked = AIPC_Connections::sanitize( array( 'name' => 'Blocked', 'base_url' => 'http://192.168.0.9/v1' ), array() );
$aipc_conn_kept    = AIPC_Connections::sanitize( array( 'name' => 'Kept', 'base_url' => 'http://192.168.0.9/v1' ), array( 'name' => 'Old', 'base_url' => 'http://192.168.0.9/v1', 'api_key' => 'k' ) );
$aipc_src_net      = AIPC_Settings::sanitize( array( 'source_sites' => "https://good.example\nhttp://10.0.0.7\nhttps://better.example" ) );
$aipc_dl_blocked   = ( new AIPC_API_Client( array( 'base_url' => 'https://mock.invalid/v1' ) ) )->download( 'http://169.254.169.254/x' );
$out['network_guard'] += array(
	'conn_new_blocked'   => '' === $aipc_conn_blocked['base_url'],
	'conn_grandfathered' => 'http://192.168.0.9/v1' === $aipc_conn_kept['base_url'],
	'sources_filtered'   => "https://good.example\nhttps://better.example" === $aipc_src_net['source_sites'],
	'download_blocked'   => is_wp_error( $aipc_dl_blocked ),
);

// REST connection test: an unsaved blocked URL is rejected with 400.
$aipc_net_req = new WP_REST_Request( 'POST', '/aipc/v1/connection/test' );
$aipc_net_req->set_param( 'base_url', 'http://10.1.2.3/v1' );
$aipc_net_req->set_param( 'api_key', 'sk-x' );
$out['network_guard']['rest_raw_blocked'] = 400 === rest_do_request( $aipc_net_req )->get_status();

/* ------------------------------------------------------------------ *
 * v1.6 — jobs database table (AIPC_Job_Store)
 * ------------------------------------------------------------------ */
$out['db_jobs'] = array(
	'available'      => AIPC_Job_Store::available(),
	'schema_version' => AIPC_Job_Store::SCHEMA_VERSION === (string) get_option( 'aipc_schema_version' ),
	'legacy_gone'    => false === get_option( 'aipc_jobs', false ),
);

$aipc_store_job = array(
	'id'      => 'job_storeref1',
	'created' => time(),
	'updated' => time(),
	'status'  => 'running',
	'mode'    => 'new',
	'source'  => 'cron',
	'user'    => 1,
	'topic'   => 'تست ذخیره در جدول',
	'args'    => array(),
	'cursor'  => 1,
	'steps'   => array(
		array( 'id' => 'plan', 'label' => 'P', 'status' => 'done' ),
		array( 'id' => 'outline', 'label' => 'O', 'status' => 'pending' ),
	),
	'data'    => array(),
	'usage'   => array( 'prompt' => 11, 'completion' => 7, 'calls' => 1 ),
	'calls'   => array(),
	'timings' => array(),
	'log'     => array( array( 't' => time(), 'level' => 'info', 'msg' => 'stored' ) ),
	'post_id' => 0,
	'error'   => null,
	'stats_recorded' => 0,
);
AIPC_Agent::instance()->save_job( $aipc_store_job );

$aipc_store_back = AIPC_Agent::instance()->get_job( 'job_storeref1' );
$aipc_store_light = null;
foreach ( AIPC_Agent::instance()->get_all_jobs() as $aipc_row ) {
	if ( 'job_storeref1' === $aipc_row['id'] ) {
		$aipc_store_light = $aipc_row;
		break;
	}
}
$out['db_jobs'] += array(
	'roundtrip'     => is_array( $aipc_store_back ) && 'stored' === $aipc_store_back['log'][0]['msg'] && 'running' === $aipc_store_back['status'] && 'تست ذخیره در جدول' === $aipc_store_back['topic'],
	'light_columns' => is_array( $aipc_store_light ) && 2 === (int) $aipc_store_light['steps_total'] && 1 === (int) $aipc_store_light['steps_done'] && 1 === (int) $aipc_store_light['calls'] && 18 === (int) $aipc_store_light['prompt_tokens'] + (int) $aipc_store_light['completion_tokens'],
	'count_since'   => AIPC_Job_Store::count_since( 'cron', 0 ) >= 1,
	'unfinished'    => in_array( 'job_storeref1', array_map( function ( $r ) { return $r['id']; }, AIPC_Job_Store::unfinished_cron() ), true ),
	'running_ids'   => in_array( 'job_storeref1', AIPC_Job_Store::running_ids(), true ),
);

// Retention: jobs older than the window are pruned, newer ones kept.
$aipc_old_job = $aipc_store_job;
$aipc_old_job['id']      = 'job_agedout1';
$aipc_old_job['source']  = 'manual';
$aipc_old_job['status']  = 'done';
$aipc_old_job['created'] = time() - 100 * DAY_IN_SECONDS;
$aipc_old_job['updated'] = time() - 100 * DAY_IN_SECONDS;
AIPC_Agent::instance()->save_job( $aipc_old_job );
AIPC_Agent::instance()->cleanup();
$out['db_jobs']['pruned'] = null === AIPC_Agent::instance()->get_job( 'job_agedout1' );
$out['db_jobs']['kept']   = is_array( AIPC_Agent::instance()->get_job( 'job_storeref1' ) );

// Legacy option fallback when the table is disabled by filter.
add_filter( 'aipc_jobs_table_enabled', '__return_false' );
$aipc_legacy_job = $aipc_store_job;
$aipc_legacy_job['id']     = 'job_legacyref1';
$aipc_legacy_job['source'] = 'manual';
$aipc_legacy_job['status'] = 'done';
AIPC_Agent::instance()->save_job( $aipc_legacy_job );
$aipc_legacy_opt = get_option( 'aipc_jobs', array() );
$out['db_jobs'] += array(
	'legacy_roundtrip' => is_array( AIPC_Agent::instance()->get_job( 'job_legacyref1' ) ),
	'legacy_in_option' => isset( $aipc_legacy_opt['job_legacyref1'] ),
);
remove_filter( 'aipc_jobs_table_enabled', '__return_false' );
delete_option( 'aipc_jobs' );

// Migration: a 1.5-style option is folded into the table on upgrade.
$aipc_legacy_payload = array();
foreach ( array( 'job_legacy_a', 'job_legacy_b' ) as $aipc_lid ) {
	$aipc_legacy_payload[ $aipc_lid ] = array(
		'id'      => $aipc_lid,
		'created' => time() - 120,
		'updated' => time() - 60,
		'status'  => 'done',
		'mode'    => 'new',
		'source'  => 'manual',
		'user'    => 1,
		'topic'   => 'مهاجرت ' . $aipc_lid,
		'args'    => array(),
		'cursor'  => 3,
		'steps'   => array( array( 'id' => 'plan', 'label' => 'P', 'status' => 'done' ) ),
		'data'    => array( 'k' => 'v' ),
		'usage'   => array( 'prompt' => 5, 'completion' => 4, 'calls' => 1 ),
		'calls'   => array(),
		'timings' => array(),
		'log'     => array(),
		'post_id' => 0,
		'error'   => null,
		'stats_recorded' => 0,
	);
}
update_option( 'aipc_jobs', $aipc_legacy_payload, false );
delete_option( 'aipc_schema_version' );
AIPC_Job_Store::maybe_upgrade();
$out['db_jobs'] += array(
	'migrated_rows'  => is_array( AIPC_Agent::instance()->get_job( 'job_legacy_a' ) ) && is_array( AIPC_Agent::instance()->get_job( 'job_legacy_b' ) ),
	'migrated_data'  => 'v' === AIPC_Agent::instance()->get_job( 'job_legacy_b' )['data']['k'],
	'option_dropped' => false === get_option( 'aipc_jobs', false ),
	'version_stored' => AIPC_Job_Store::SCHEMA_VERSION === (string) get_option( 'aipc_schema_version' ),
);
AIPC_Agent::instance()->delete_job( 'job_legacy_a' );
AIPC_Agent::instance()->delete_job( 'job_legacy_b' );
AIPC_Agent::instance()->delete_job( 'job_storeref1' );

/* ------------------------------------------------------------------ *
 * v1.6 — background runner (server-side job execution)
 * ------------------------------------------------------------------ */
$out['background_runner'] = array(
	'hook_registered' => (bool) has_action( AIPC_Scheduler::RUNNER_HOOK ),
	'runner_bound'    => (bool) has_action( AIPC_Scheduler::RUNNER_HOOK, array( 'AIPC_Scheduler', 'run_job' ) ),
);

$aipc_bg_job = AIPC_Agent::instance()->create_job( 'اجرا در پس‌زمینه', array(
	'tone'     => 'friendly',
	'length'   => 'short',
	'language' => 'fa',
	'image'    => 0,
	'faq'      => 0,
	'toc'      => 0,
) );
$out['background_runner']['event_scheduled'] = is_array( $aipc_bg_job ) && (bool) wp_next_scheduled( AIPC_Scheduler::RUNNER_HOOK, array( $aipc_bg_job['id'] ) );

// Drive the ENTIRE run server-side: no REST /step call anywhere.
AIPC_Scheduler::run_job( $aipc_bg_job['id'] );
$aipc_bg_after = AIPC_Agent::instance()->get_job( $aipc_bg_job['id'] );

$out['background_runner'] += array(
	'done'         => is_array( $aipc_bg_after ) && 'done' === $aipc_bg_after['status'],
	'post_created' => is_array( $aipc_bg_after ) && ! empty( $aipc_bg_after['post_id'] ),
	'all_steps'    => is_array( $aipc_bg_after ) && 0 === count( array_filter( $aipc_bg_after['steps'], function ( $s ) {
		return 'done' !== $s['status'] && 'skipped' !== $s['status'];
	} ) ),
	'post_is_draft'=> is_array( $aipc_bg_after ) && 'draft' === get_post_status( $aipc_bg_after['post_id'] ),
	'no_event'     => false === wp_next_scheduled( AIPC_Scheduler::RUNNER_HOOK, array( $aipc_bg_job['id'] ) ),
	'idempotent'   => ( function () use ( $aipc_bg_job ) {
		AIPC_Scheduler::run_job( $aipc_bg_job['id'] ); // Terminal → no-op.
		$job = AIPC_Agent::instance()->get_job( $aipc_bg_job['id'] );
		return is_array( $job ) && 'done' === $job['status'] && false === wp_next_scheduled( AIPC_Scheduler::RUNNER_HOOK, array( $aipc_bg_job['id'] ) );
	} )(),
);

/* ------------------------------------------------------------------ *
 * v1.6 — read-only /state endpoint (console becomes a viewer)
 * ------------------------------------------------------------------ */
$aipc_state_job = AIPC_Agent::instance()->create_job( 'تست حالت', array(
	'tone'     => 'friendly',
	'length'   => 'short',
	'language' => 'fa',
	'image'    => 0,
	'faq'      => 0,
	'toc'      => 0,
) );
AIPC_Agent::instance()->execute_step( $aipc_state_job['id'], 0 ); // plan only.

$aipc_state_req = new WP_REST_Request( 'POST', '/aipc/v1/state' );
$aipc_state_req->set_param( 'job_id', $aipc_state_job['id'] );
$aipc_state_req->set_param( 'since', 0 );
$aipc_state_resp = rest_do_request( $aipc_state_req );
$aipc_st1        = $aipc_state_resp->get_data();
$aipc_st2        = rest_do_request( $aipc_state_req )->get_data();

$out['rest_state'] = array(
	'status'       => 200 === $aipc_state_resp->get_status(),
	'shape'        => isset( $aipc_st1['status'], $aipc_st1['steps'], $aipc_st1['logs'], $aipc_st1['since'], $aipc_st1['usage'] ),
	'read_only'    => 'running' === $aipc_st2['status'] && count( $aipc_st2['steps'] ) === count( $aipc_st1['steps'] ),
	'cursor_stable'=> $aipc_st2['since'] === $aipc_st1['since'],
	'not_found'    => ( function () {
		$req = new WP_REST_Request( 'POST', '/aipc/v1/state' );
		$req->set_param( 'job_id', 'job_missing' );
		return 404 === rest_do_request( $req )->get_status();
	} )(),
);

wp_set_current_user( 0 );
$aipc_state_req2 = new WP_REST_Request( 'POST', '/aipc/v1/state' );
$aipc_state_req2->set_param( 'job_id', $aipc_state_job['id'] );
$out['rest_state']['anonymous_401'] = 401 === rest_do_request( $aipc_state_req2 )->get_status();
wp_set_current_user( 1 );

// Cancelling drops the runner event.
AIPC_Agent::instance()->cancel_job( $aipc_state_job['id'] );
$out['rest_state']['cancel_drops_event'] = false === wp_next_scheduled( AIPC_Scheduler::RUNNER_HOOK, array( $aipc_state_job['id'] ) );

/* ------------------------------------------------------------------ *
 * v1.6 — REST rate limiting (per user, per minute)
 * ------------------------------------------------------------------ */
$aipc_limit_two = function ( $limit, $route ) {
	return 'start' === $route ? 2 : $limit;
};
add_filter( 'aipc_rest_rate_limit', $aipc_limit_two, 10, 2 );

// Fresh bucket so earlier /start calls in this run don't count.
delete_transient( 'aipc_rl_' . md5( '1|start|' . (int) ( time() / MINUTE_IN_SECONDS ) ) );

$aipc_rl_codes   = array();
$aipc_rl_bodies  = array();
$aipc_rl_started = array();
for ( $aipc_i = 0; $aipc_i < 3; $aipc_i++ ) {
	$aipc_rl_req = new WP_REST_Request( 'POST', '/aipc/v1/start' );
	$aipc_rl_req->set_param( 'topic', 'تست محدودیت نرخ ' . $aipc_i );
	$aipc_rl_req->set_param( 'tone', 'friendly' );
	$aipc_rl_req->set_param( 'length', 'short' );
	$aipc_rl_req->set_param( 'language', 'fa' );
	$aipc_rl_req->set_param( 'image', 0 );
	$aipc_rl_req->set_param( 'faq', 0 );
	$aipc_rl_req->set_param( 'toc', 0 );
	$aipc_rl_resp  = rest_do_request( $aipc_rl_req );
	$aipc_rl_codes[] = $aipc_rl_resp->get_status();
	$aipc_rl_bodies[] = $aipc_rl_resp->get_data();
	if ( 200 === $aipc_rl_resp->get_status() ) {
		$aipc_rl_started[] = $aipc_rl_bodies[ $aipc_i ]['id'];
	}
}
remove_filter( 'aipc_rest_rate_limit', $aipc_limit_two, 10, 2 );

foreach ( $aipc_rl_started as $aipc_rl_id ) {
	AIPC_Agent::instance()->cancel_job( $aipc_rl_id );
}

$out['rate_limit'] = array(
	'first_ok'   => 200 === $aipc_rl_codes[0],
	'second_ok'  => 200 === $aipc_rl_codes[1],
	'third_429'  => 429 === $aipc_rl_codes[2],
	'error_code' => isset( $aipc_rl_bodies[2]['code'] ) && 'aipc_rate' === $aipc_rl_bodies[2]['code'],
	'jobs_kept'  => count( $aipc_rl_started ) === 2,
);

/* ------------------------------------------------------------------ *
 * v1.6 — draft review inbox
 * ------------------------------------------------------------------ */
$aipc_review_html = ( function () {
	ob_start();
	AIPC_Admin::render_review();
	return ob_get_clean();
} )();

$out['review_page'] = array(
	'rendered'      => false !== strpos( $aipc_review_html, 'aipc-review-actions' ) && false !== strpos( $aipc_review_html, 'پیش‌نویس‌های در انتظار بازبینی' ),
	'lists_draft'   => false !== strpos( $aipc_review_html, 'راهنمای کامل سبزی‌کاری در بالکن' ),
	'lists_bg'      => false !== strpos( $aipc_review_html, 'اجرا در پس‌زمینه' ),
	'shows_publish' => false !== strpos( $aipc_review_html, 'aipc_publish_draft' ),
	'shows_rewrite' => false !== strpos( $aipc_review_html, 'aipc-rewrite' ),
	'shows_origin'  => false !== strpos( $aipc_review_html, '⏱' ),
	'no_published'  => false === strpos( $aipc_review_html, 'تست انتشار فوری' ),
	'handler_bound' => (bool) has_action( 'admin_post_aipc_publish_draft' ),
);

// Publishing through the review flow: post goes live, fires the hook,
// disappears from the inbox.
$aipc_review_pid   = is_array( $aipc_bg_after ) ? (int) $aipc_bg_after['post_id'] : 0;
$aipc_pub_fired    = false;
$aipc_pub_capture  = function () use ( & $aipc_pub_fired ) {
	$aipc_pub_fired = true;
};
add_action( 'aipc_post_published', $aipc_pub_capture );
$aipc_review_ok = AIPC_Admin::publish_draft( $aipc_review_pid );
remove_action( 'aipc_post_published', $aipc_pub_capture );

$aipc_review_after = ( function () {
	ob_start();
	AIPC_Admin::render_review();
	return ob_get_clean();
} )();

$out['review_page'] += array(
	'publish_ok'    => $aipc_review_ok,
	'publish_status'=> 'publish' === get_post_status( $aipc_review_pid ),
	'hook_fired'    => $aipc_pub_fired,
	'gone_from_list'=> false === strpos( $aipc_review_after, 'اجرا در پس‌زمینه' ),
	'double_publish'=> ! AIPC_Admin::publish_draft( $aipc_review_pid ), // Already live → refused.
);

/* ------------------------------------------------------------------ *
 * v1.6 fix — deleting a connection cleans per-step fallback chains
 * ------------------------------------------------------------------ */
$aipc_chain_snapshot = array();
foreach ( AIPC_Steps::registry() as $aipc_step_id => $aipc_step_meta ) {
	$aipc_step_cfg = AIPC_Steps::get( $aipc_step_id );
	$aipc_chain_snapshot[ $aipc_step_id ] = array(
		'connections' => $aipc_step_cfg['connections'],
		'prompt'      => AIPC_Steps::has_custom_prompt( $aipc_step_id ) ? $aipc_step_cfg['prompt'] : '',
	);
}

$aipc_chain_c1 = AIPC_Connections::save( array(
	'name'       => 'Chain One',
	'base_url'   => 'https://one.invalid/v1',
	'api_key'    => 'k1',
	'chat_model' => 'mock-mini',
	'image_model' => 'dall-e-3',
) );
$aipc_chain_c2 = AIPC_Connections::save( array(
	'name'       => 'Chain Two',
	'base_url'   => 'https://two.invalid/v1',
	'api_key'    => 'k2',
	'chat_model' => 'mock-mini',
	'image_model' => 'dall-e-3',
) );

$aipc_chain_cfg = $aipc_chain_snapshot;
$aipc_chain_cfg['conclusion'] = array( 'connections' => array( $aipc_chain_c1['id'], $aipc_chain_c2['id'] ), 'prompt' => '' );
AIPC_Steps::save_all( $aipc_chain_cfg );

AIPC_Connections::delete( $aipc_chain_c1['id'] );
$out['chain_cleanup'] = array(
	'array_cleaned' => array( $aipc_chain_c2['id'] ) === AIPC_Steps::get( 'conclusion' )['connections'],
);

// Legacy single-connection key stored raw (as an old 1.4 option would have it).
$aipc_chain_raw = $aipc_chain_snapshot;
$aipc_chain_raw['conclusion'] = array( 'connection' => $aipc_chain_c2['id'], 'prompt' => '' );
update_option( 'aipc_steps', $aipc_chain_raw, false );
AIPC_Connections::delete( $aipc_chain_c2['id'] );
$out['chain_cleanup'] += array(
	'legacy_cleaned' => array() === AIPC_Steps::get( 'conclusion' )['connections'],
);

AIPC_Steps::save_all( $aipc_chain_snapshot ); // Restore.

/* ------------------------------------------------------------------ *
 * v1.6 — contextual help toggles ("?" icons on every section)
 * ------------------------------------------------------------------ */
// The settings screen uses the options API (settings_fields) — admin-only
// includes that a bare wp-load context does not load.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/options.php';

$aipc_help_count = function ( $html ) {
	return preg_match_all( '/class="aipc-help"/', $html, $aipc_m );
};
$aipc_help_panels = function ( $html ) {
	return preg_match_all( '/aipc-help-panel/', $html, $aipc_m );
};

$aipc_help_pages = array();

ob_start();
AIPC_Admin::render_new();
$aipc_help_pages['agent'] = ob_get_clean();

ob_start();
AIPC_Admin::render_rewrite();
$aipc_help_pages['rewrite'] = ob_get_clean();

ob_start();
AIPC_Admin::render_review();
$aipc_help_pages['review'] = ob_get_clean();

ob_start();
AIPC_Admin::render_connections();
$aipc_help_pages['connections'] = ob_get_clean();

ob_start();
AIPC_Admin::render_prompts();
$aipc_help_pages['prompts'] = ob_get_clean();

ob_start();
AIPC_Admin::render_schedule();
$aipc_help_pages['schedule'] = ob_get_clean();

ob_start();
AIPC_Admin::render_settings();
$aipc_help_pages['settings'] = ob_get_clean();

unset( $_GET['job'] );
ob_start();
AIPC_Admin::render_logs();
$aipc_help_pages['logs'] = ob_get_clean();

$_GET['job'] = $aipc_cron_job ? $aipc_cron_job['id'] : $job_id;
ob_start();
AIPC_Admin::render_logs();
$aipc_help_pages['log_detail'] = ob_get_clean();
unset( $_GET['job'] );

ob_start();
AIPC_Admin::render_update();
$aipc_help_pages['update'] = ob_get_clean();

$aipc_help_expected = array(
	'agent'       => 3,
	'rewrite'     => 1,
	'review'      => 1,
	'connections' => 3,
	'prompts'     => 2,
	'schedule'    => 7,
	'settings'    => 4,
	'logs'        => 2,
	'log_detail'  => 4,
	'update'      => 3,
);

$out['help_tooltips'] = array();
foreach ( $aipc_help_pages as $aipc_page => $aipc_html ) {
	$aipc_count = $aipc_help_count( $aipc_html );
	$out['help_tooltips'][ $aipc_page ] = array(
		'count'  => $aipc_count,
		'enough' => $aipc_count >= $aipc_help_expected[ $aipc_page ],
		'panels' => $aipc_help_panels( $aipc_html ) === $aipc_count,
	);
}

$out['help_tooltips'] += array(
	'slug_agent'      => false !== strpos( $aipc_help_pages['agent'], 'data-aipc-help="new-console"' ),
	'slug_publish'    => false !== strpos( $aipc_help_pages['agent'], 'data-aipc-help="new-publish"' ),
	'slug_rewrite'    => false !== strpos( $aipc_help_pages['rewrite'], 'data-aipc-help="rw-post"' ),
	'slug_review'     => false !== strpos( $aipc_help_pages['review'], 'data-aipc-help="review-inbox"' ),
	'slug_conn_form'  => false !== strpos( $aipc_help_pages['connections'], 'data-aipc-help="conn-form"' ),
	'slug_chain'      => false !== strpos( $aipc_help_pages['prompts'], 'data-aipc-help="pr-chains"' ),
	'slug_bale'       => false !== strpos( $aipc_help_pages['schedule'], 'data-aipc-help="sched-bale"' ),
	'slug_queue'      => false !== strpos( $aipc_help_pages['schedule'], 'data-aipc-help="sched-queue"' ),
	'slug_use_queue'  => false !== strpos( $aipc_help_pages['schedule'], 'data-aipc-help="sched-use-queue"' ),
	'slug_two_way'    => false !== strpos( $aipc_help_pages['schedule'], 'data-aipc-help="bale-two-way"' ),
	'slug_limit'      => false !== strpos( $aipc_help_pages['schedule'], 'data-aipc-help="sched-limit"' ),
	'slug_settings'   => false !== strpos( $aipc_help_pages['settings'], 'data-aipc-help="settings-site-prompt"' ),
	'slug_logs'       => false !== strpos( $aipc_help_pages['logs'], 'data-aipc-help="logs-jobs"' ),
	'slug_detail'     => false !== strpos( $aipc_help_pages['log_detail'], 'data-aipc-help="ld-calls"' ),
	'slug_update'     => false !== strpos( $aipc_help_pages['update'], 'data-aipc-help="up-run"' ),
	'aria_label'      => false !== strpos( $aipc_help_pages['settings'], 'این بخش برای چیست؟' ),
	'text_fa'         => false !== strpos( $aipc_help_pages['settings'], 'مهم‌ترین تنظیم افزونه' ),
	'not_open'        => false === strpos( $aipc_help_pages['settings'], '<details class="aipc-help" data-aipc-help="settings-site-prompt" open' ),
);

/* ------------------------------------------------------------------ *
 * v1.7.0 — topic queue (+ suggestions from the research sources)
 * ------------------------------------------------------------------ */
$aipc_tq_added = AIPC_Topic_Queue::add_many( "  موضوع صف یک: پرورش قارچ در خانه  \n\nموضوع صف دو: باغچهٔ آبی کوچک\nموضوع صف یک: پرورش قارچ در خانه\n" );
$aipc_tq_before = AIPC_Topic_Queue::count_pending();

// An entry with "use_queue" consumes the oldest pending topic…
$aipc_tq_entry = AIPC_Scheduler::save_entry( array(
	'time'      => '10:30',
	'days'      => array( 1 ),
	'use_queue' => 1,
	'topic'     => 'موضوع جایگزین ثابت',
) );
$aipc_tq_job = AIPC_Scheduler::start_job_for_entry( $aipc_tq_entry['id'] );
$aipc_tq_first = AIPC_Topic_Queue::all()['items'];
$aipc_tq_used = null;
foreach ( $aipc_tq_first as $aipc_tq_item ) {
	if ( 'used' === $aipc_tq_item['status'] ) {
		$aipc_tq_used = $aipc_tq_item;
	}
}
if ( ! is_wp_error( $aipc_tq_job ) && 'running' === $aipc_tq_job['status'] ) {
	AIPC_Agent::instance()->cancel_job( $aipc_tq_job['id'] );
	AIPC_Scheduler::unschedule_runner( $aipc_tq_job['id'] );
}

// Snapshot the queue depth right after the first (queue-consuming) run:
// the first topic was consumed, the second one must still be pending.
$aipc_tq_pending_left = AIPC_Topic_Queue::count_pending();

// …and the entry falls back to the fixed topic when the queue runs empty.
AIPC_Topic_Queue::clear_pending();
$aipc_tq_job2 = AIPC_Scheduler::start_job_for_entry( $aipc_tq_entry['id'] );
if ( ! is_wp_error( $aipc_tq_job2 ) && 'running' === $aipc_tq_job2['status'] ) {
	AIPC_Agent::instance()->cancel_job( $aipc_tq_job2['id'] );
	AIPC_Scheduler::unschedule_runner( $aipc_tq_job2['id'] );
}
AIPC_Scheduler::delete_entry( $aipc_tq_entry['id'] );

// REST: suggest topics from the (mocked) research sources, then add them.
$req = new WP_REST_Request( 'POST', '/aipc/v1/topics/suggest' );
$aipc_tq_sugg = rest_do_request( $req );
$aipc_tq_sugg_data = $aipc_tq_sugg->get_data();
$aipc_tq_first_sugg = isset( $aipc_tq_sugg_data['suggestions'][0]['text'] ) ? $aipc_tq_sugg_data['suggestions'][0]['text'] : '';

$req = new WP_REST_Request( 'POST', '/aipc/v1/topics/add' );
$req->set_param( 'texts', array( $aipc_tq_first_sugg ) );
$req->set_param( 'source', 'rss' );
$aipc_tq_addr = rest_do_request( $req );

// Anonymous callers must be rejected by both endpoints.
wp_set_current_user( 0 );
$aipc_tq_anon_req = new WP_REST_Request( 'POST', '/aipc/v1/topics/suggest' );
$aipc_tq_anon_sugg = rest_do_request( $aipc_tq_anon_req );
$aipc_tq_anon_req2 = new WP_REST_Request( 'POST', '/aipc/v1/topics/add' );
$aipc_tq_anon_req2->set_param( 'texts', array( 'موضوع ناشناس' ) );
$aipc_tq_anon_add = rest_do_request( $aipc_tq_anon_req2 );
wp_set_current_user( 1 );

$out['topic_queue'] = array(
	'bulk_added'     => 2 === $aipc_tq_added,               // 3 lines, 1 duplicate
	'count_after'    => 2 === $aipc_tq_before,
	'job_topic'      => ! is_wp_error( $aipc_tq_job ) && 'موضوع صف یک: پرورش قارچ در خانه' === $aipc_tq_job['topic'],
	'consumed'       => ! empty( $aipc_tq_used ) && 'موضوع صف یک: پرورش قارچ در خانه' === $aipc_tq_used['text'] && ! is_wp_error( $aipc_tq_job ) && (string) $aipc_tq_used['job_id'] === (string) $aipc_tq_job['id'],
	'pending_left'   => 1 === $aipc_tq_pending_left,
	'fallback_topic' => ! is_wp_error( $aipc_tq_job2 ) && 'موضوع جایگزین ثابت' === $aipc_tq_job2['topic'],
	'suggest_status' => 200 === $aipc_tq_sugg->get_status(),
	'suggest_count'  => isset( $aipc_tq_sugg_data['count'] ) ? $aipc_tq_sugg_data['count'] : 0,
	'suggest_text'   => false !== strpos( $aipc_tq_first_sugg, 'کشاورزی شهری' ) || false !== strpos( $aipc_tq_first_sugg, 'خاک مناسب' ),
	'has_sources'    => ! empty( $aipc_tq_sugg_data['has_sources'] ),
	'rest_added'     => 200 === $aipc_tq_addr->get_status() && 1 === $aipc_tq_addr->get_data()['added'],
	'rest_dup'       => ( function () {
		$req = new WP_REST_Request( 'POST', '/aipc/v1/topics/add' );
		$req->set_param( 'texts', array( 'دوباره همان' ) );
		rest_do_request( $req );
		$req = new WP_REST_Request( 'POST', '/aipc/v1/topics/add' );
		$req->set_param( 'texts', array( 'دوباره همان' ) );
		$resp = rest_do_request( $req );
		return 0 === $resp->get_data()['added'] && 1 === $resp->get_data()['skipped'];
	} )(),
	'anon_suggest'   => in_array( $aipc_tq_anon_sugg->get_status(), array( 401, 403 ), true ),
	'anon_add'       => in_array( $aipc_tq_anon_add->get_status(), array( 401, 403 ), true ),
);

AIPC_Topic_Queue::clear_pending();

/* ------------------------------------------------------------------ *
 * v1.7.0 — two-way Bale commands (staged getUpdates via the mock)
 * ------------------------------------------------------------------ */
$aipc_bc_snapshot = AIPC_Bale::all();

// Enable two-way commands and confirm the polling event appears.
AIPC_Bale::save( wp_parse_args( array(
	'two_way' => 1,
	'enabled' => 1,
	'token'   => 'bale-token-123',
	'chat_ids' => array( '12345', '67890' ),
), $aipc_bc_snapshot ) );
AIPC_Bale_Commands::maybe_schedule();

$aipc_bc_stage = function ( $updates ) {
	update_option( 'aipc_mock_bale_updates', wp_json_encode( $updates ) );
};
$aipc_bc_bale_log = function () {
	$out = array();
	$log = WP_CONTENT_DIR . '/mock-api-log.jsonl';
	if ( file_exists( $log ) ) {
		foreach ( file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$row = json_decode( $line, true );
			if ( $row && 'bale' === $row['host'] ) {
				$out[] = $row;
			}
		}
	}
	return $out;
};

// 1) "نوشتن: <topic>" starts a background draft run.
$aipc_bc_stage( array( array(
	'update_id' => 100,
	'message'   => array( 'chat' => array( 'id' => 12345 ), 'text' => 'نوشتن: موضوع تست فرمان بله' ),
) ) );
$aipc_bc_log_before = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();

$aipc_bc_midnight = strtotime( 'today', current_time( 'timestamp' ) );
$aipc_bc_job = null;
foreach ( AIPC_Job_Store::since( $aipc_bc_midnight ) as $aipc_bc_j ) {
	if ( 'bale' === ( isset( $aipc_bc_j['source'] ) ? $aipc_bc_j['source'] : '' ) ) {
		$aipc_bc_job = $aipc_bc_j;
	}
}
$aipc_bc_replies = array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before );
$aipc_bc_reply1 = '';
foreach ( $aipc_bc_replies as $aipc_bc_r ) {
	if ( '12345' === (string) $aipc_bc_r['chat_id'] && ! empty( $aipc_bc_r['text'] ) ) {
		$aipc_bc_reply1 = $aipc_bc_r['text'];
	}
}

// Drive the chat-started run to completion (it becomes the newest draft).
$aipc_bc_state = array();
if ( $aipc_bc_job ) {
	$aipc_bc_state = AIPC_Scheduler::run_steps( $aipc_bc_job['id'] );
	$aipc_bc_job = AIPC_Agent::instance()->get_job( $aipc_bc_job['id'] );
}

// 2) وضعیت / آخرین / صف replies.
$aipc_bc_stage( array(
	array( 'update_id' => 101, 'message' => array( 'chat' => array( 'id' => 12345 ), 'text' => 'وضعیت' ) ),
	array( 'update_id' => 102, 'message' => array( 'chat' => array( 'id' => 12345 ), 'text' => 'آخرین' ) ),
	array( 'update_id' => 103, 'message' => array( 'chat' => array( 'id' => 12345 ), 'text' => 'صف' ) ),
) );
$aipc_bc_log_before2 = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();
$aipc_bc_replies2 = array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before2 );
$aipc_bc_texts2 = array();
foreach ( $aipc_bc_replies2 as $aipc_bc_r ) {
	if ( ! empty( $aipc_bc_r['text'] ) ) {
		$aipc_bc_texts2[] = (string) $aipc_bc_r['text'];
	}
}
$aipc_bc_status_reply = '';
$aipc_bc_latest_reply = '';
$aipc_bc_queue_reply = '';
foreach ( $aipc_bc_texts2 as $aipc_bc_txt ) {
	if ( 0 === strpos( $aipc_bc_txt, '📊' ) ) { $aipc_bc_status_reply = $aipc_bc_txt; }
	if ( 0 === strpos( $aipc_bc_txt, '📄' ) ) { $aipc_bc_latest_reply = $aipc_bc_txt; }
	if ( 0 === strpos( $aipc_bc_txt, '📋' ) ) { $aipc_bc_queue_reply = $aipc_bc_txt; }
}

// 3) «انتشار ۱» (Persian digit) publishes the newest draft.
$aipc_bc_draft_id = $aipc_bc_job ? (int) $aipc_bc_job['post_id'] : 0;
$aipc_bc_was_draft = $aipc_bc_draft_id ? get_post_status( $aipc_bc_draft_id ) : '';
$aipc_bc_stage( array( array(
	'update_id' => 104,
	'message'   => array( 'chat' => array( 'id' => 12345 ), 'text' => 'انتشار ۱' ),
) ) );
$aipc_bc_log_before3 = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();
$aipc_bc_replies3 = array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before3 );
$aipc_bc_publish_reply = '';
foreach ( $aipc_bc_replies3 as $aipc_bc_r ) {
	if ( '12345' === (string) $aipc_bc_r['chat_id'] && ! empty( $aipc_bc_r['text'] ) && 0 === strpos( (string) $aipc_bc_r['text'], '🚀' ) ) {
		$aipc_bc_publish_reply = (string) $aipc_bc_r['text'];
	}
}
$aipc_bc_published = $aipc_bc_draft_id ? get_post_status( $aipc_bc_draft_id ) : '';
$aipc_bc_job_after = $aipc_bc_draft_id ? AIPC_Agent::instance()->get_job( $aipc_bc_job['id'] ) : null;
$aipc_bc_publish_logged = false;
if ( $aipc_bc_job_after ) {
	foreach ( (array) $aipc_bc_job_after['log'] as $aipc_bc_le ) {
		if ( false !== strpos( (string) $aipc_bc_le['msg'], 'فرمان بله' ) || false !== strpos( (string) $aipc_bc_le['msg'], 'Bale command' ) ) {
			$aipc_bc_publish_logged = true;
		}
	}
}

// 4) Unauthorized chat: ignored silently (no reply, no job).
$aipc_bc_bale_jobs_before = ( function () {
	$n = 0;
	foreach ( AIPC_Job_Store::since( 0 ) as $aipc_bc_j ) {
		if ( 'bale' === ( isset( $aipc_bc_j['source'] ) ? $aipc_bc_j['source'] : '' ) ) { $n++; }
	}
	return $n;
} )();
$aipc_bc_stage( array( array(
	'update_id' => 105,
	'message'   => array( 'chat' => array( 'id' => 55555 ), 'text' => 'نوشتن: تلاش نفوذ' ),
) ) );
$aipc_bc_log_before4 = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();
$aipc_bc_replies4 = array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before4 );
$aipc_bc_stranger_reply = 0;
foreach ( $aipc_bc_replies4 as $aipc_bc_r ) {
	if ( '55555' === (string) $aipc_bc_r['chat_id'] ) { $aipc_bc_stranger_reply++; }
}
$aipc_bc_bale_jobs_after = ( function () {
	$n = 0;
	foreach ( AIPC_Job_Store::since( 0 ) as $aipc_bc_j ) {
		if ( 'bale' === ( isset( $aipc_bc_j['source'] ) ? $aipc_bc_j['source'] : '' ) ) { $n++; }
	}
	return $n;
} )();

// 5) /start help + unknown command + offset persistence.
$aipc_bc_stage( array(
	array( 'update_id' => 106, 'message' => array( 'chat' => array( 'id' => 12345 ), 'text' => '/start' ) ),
	array( 'update_id' => 107, 'message' => array( 'chat' => array( 'id' => 12345 ), 'text' => 'سلام' ) ),
) );
$aipc_bc_log_before5 = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();
$aipc_bc_replies5 = array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before5 );
$aipc_bc_help_reply = '';
$aipc_bc_unknown_reply = '';
foreach ( $aipc_bc_replies5 as $aipc_bc_r ) {
	if ( '12345' !== (string) $aipc_bc_r['chat_id'] || empty( $aipc_bc_r['text'] ) ) { continue; }
	if ( 0 === strpos( (string) $aipc_bc_r['text'], '🤖' ) ) { $aipc_bc_help_reply = (string) $aipc_bc_r['text']; }
	elseif ( '' === $aipc_bc_unknown_reply ) { $aipc_bc_unknown_reply = (string) $aipc_bc_r['text']; }
}
$aipc_bc_offset = (int) AIPC_Bale::all()['last_update_id'];
$aipc_bc_event_on = wp_get_scheduled_event( AIPC_Bale_Commands::POLL_HOOK );

// 6) Re-polling the same updates must be a no-op (offset semantics).
$aipc_bc_jobs_before6 = ( function () {
	$n = 0;
	foreach ( AIPC_Job_Store::since( 0 ) as $aipc_bc_j ) {
		if ( 'bale' === ( isset( $aipc_bc_j['source'] ) ? $aipc_bc_j['source'] : '' ) ) { $n++; }
	}
	return $n;
} )();
$aipc_bc_log_before6 = count( $aipc_bc_bale_log() );
AIPC_Bale_Commands::poll();
$aipc_bc_noop_replies = 0;
foreach ( array_slice( $aipc_bc_bale_log(), $aipc_bc_log_before6 ) as $aipc_bc_r ) {
	if ( 'sendMessage' === (string) $aipc_bc_r['method'] ) {
		$aipc_bc_noop_replies++;
	}
}
$aipc_bc_jobs_after6 = ( function () {
	$n = 0;
	foreach ( AIPC_Job_Store::since( 0 ) as $aipc_bc_j ) {
		if ( 'bale' === ( isset( $aipc_bc_j['source'] ) ? $aipc_bc_j['source'] : '' ) ) { $n++; }
	}
	return $n;
} )();

// 7) Disabling two-way removes the event; cleanup.
AIPC_Bale::save( wp_parse_args( array( 'two_way' => 0 ), AIPC_Bale::all() ) );
AIPC_Bale_Commands::maybe_schedule();
delete_option( 'aipc_mock_bale_updates' );

$out['bale_commands'] = array(
	'poll_event'     => false !== $aipc_bc_event_on,
	'interval'       => isset( wp_get_schedules()['aipc_bale_5min'] ) && 300 === (int) wp_get_schedules()['aipc_bale_5min']['interval'],
	'job_created'    => ! empty( $aipc_bc_job ) && 'موضوع تست فرمان بله' === $aipc_bc_job['topic'],
	'job_source'     => ! empty( $aipc_bc_job ) && 'bale' === $aipc_bc_job['source'],
	'job_is_draft'   => ! empty( $aipc_bc_job ) && 'draft' === ( isset( $aipc_bc_job['args']['publish_mode'] ) ? $aipc_bc_job['args']['publish_mode'] : '' ),
	'reply_new'      => false !== strpos( $aipc_bc_reply1, 'موضوع تست فرمان بله' ),
	'run_done'       => isset( $aipc_bc_state['status'] ) && 'done' === $aipc_bc_state['status'],
	'post_created'   => 'draft' === $aipc_bc_was_draft,
	'status_reply'   => '' !== $aipc_bc_status_reply,
	'latest_reply'   => false !== strpos( $aipc_bc_latest_reply, 'موضوع تست فرمان بله' ),
	'queue_reply'    => '' !== $aipc_bc_queue_reply,
	'published'      => 'publish' === $aipc_bc_published,
	'publish_reply'  => false !== strpos( $aipc_bc_publish_reply, '🚀' ),
	'publish_logged' => $aipc_bc_publish_logged,
	'stranger_ignored' => 0 === $aipc_bc_stranger_reply && $aipc_bc_bale_jobs_after === $aipc_bc_bale_jobs_before,
	'help_reply'     => false !== strpos( $aipc_bc_help_reply, '🤖' ),
	'unknown_reply'  => false !== strpos( $aipc_bc_unknown_reply, 'راهنما' ),
	'offset_saved'   => 107 === $aipc_bc_offset,
	'repoll_noop'    => 0 === $aipc_bc_noop_replies && $aipc_bc_jobs_after6 === $aipc_bc_jobs_before6,
	'event_removed'  => false === wp_get_scheduled_event( AIPC_Bale_Commands::POLL_HOOK ),
);

// Restore the pre-group Bale config (token/recipients/report intact).
AIPC_Bale::save( $aipc_bc_snapshot );

/* ------------------------------------------------------------------ *
 * v1.5.1/1.5.2 — Git self-updater (LAST: it replaces the plugin files)
 * ------------------------------------------------------------------ */
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$aipc_git_dir  = WP_PLUGIN_DIR . '/wp-ai-post-creator';
$aipc_git_main = $aipc_git_dir . '/wp-ai-post-creator.php';
$aipc_git_repo = 'ahmad75naraghi/wp-ai-post-creator';

// -- configuration defaults + sanitizing (no token stored yet) --
$aipc_git_cfg = AIPC_Updater::config();
$out['git_updater'] = array(
	'repo_default'     => $aipc_git_repo === $aipc_git_cfg['repo'],
	'branch_default'   => 'main' === $aipc_git_cfg['branch'],
	'no_token_default' => '' === $aipc_git_cfg['token'],
	'repo_sanitized'   => $aipc_git_repo === AIPC_Updater::sanitize_repo( ' nonsense!! ' ) && 'acme/my-repo.v2' === AIPC_Updater::sanitize_repo( 'acme/my-repo.v2' ),
	'branch_sanitized' => 'main' === AIPC_Updater::sanitize_branch( '../etc/passwd/../..' ) && 'feat/x.1_2-y' === AIPC_Updater::sanitize_branch( 'feat/x.1_2-y' ),
);

// -- remote version (anonymous): main / old / cache / missing branch --
$out['git_updater']['remote_main']    = '9.9.9' === AIPC_Updater::remote_version( 'main', true );
$out['git_updater']['remote_old']     = '0.0.1' === AIPC_Updater::remote_version( 'old', true );
$out['git_updater']['remote_cached']  = '9.9.9' === get_transient( 'aipc_git_v_' . md5( $aipc_git_repo . '|main' ) );
$out['git_updater']['remote_missing'] = is_wp_error( AIPC_Updater::remote_version( 'notfound', true ) );

// -- connection test: ok / repo missing / token rejected --
$aipc_test_ok  = AIPC_Updater::test_connection( $aipc_git_repo, 'main', 'ghp_e2e' );
$aipc_test_404 = AIPC_Updater::test_connection( 'acme/notfound', 'main', '' );
$aipc_test_bad = AIPC_Updater::test_connection( $aipc_git_repo, 'main', 'ghp_bad-token' );
$out['git_updater']['test_ok']        = is_array( $aipc_test_ok ) && '9.9.9' === $aipc_test_ok['version'];
$out['git_updater']['test_404']       = is_wp_error( $aipc_test_404 );
$out['git_updater']['test_bad_token'] = is_wp_error( $aipc_test_bad );

// -- corrupt package on the ANONYMOUS codeload path --
$aipc_res_bad = AIPC_Updater::run( 'bad', true );
$out['git_updater']['bad_package'] = is_wp_error( $aipc_res_bad );

// -- token storage: write-only, kept when the field stays empty --
AIPC_Updater::save_config( array( 'repo' => $aipc_git_repo, 'branch' => 'main', 'token' => 'ghp_e2e' ) );
$out['git_updater']['token_stored'] = 'ghp_e2e' === AIPC_Updater::config()['token'];
AIPC_Updater::save_config( array( 'repo' => $aipc_git_repo, 'branch' => 'main', 'token' => '' ) );
$out['git_updater']['token_kept'] = 'ghp_e2e' === AIPC_Updater::config()['token'];
$aipc_alloptions = wp_load_alloptions();
$out['git_updater']['autoload_off'] = ! isset( $aipc_alloptions['aipc_git'] );

// -- downgrade guard (token now stored; guard blocks before any download) --
$aipc_res_old = AIPC_Updater::run( 'old', false );
$aipc_guard_untouched = false !== strpos( (string) file_get_contents( $aipc_git_main ), "define( 'AIPC_VERSION', '" . AIPC_VERSION . "' )" );

file_put_contents( $aipc_git_dir . '/stale-test.php', '<?php // stale file that must disappear on update' );

// -- the real swap (token set → download via api.github.com) --
$aipc_res_ok = AIPC_Updater::run( 'main', false );

$aipc_data_new = get_plugin_data( $aipc_git_main );
$aipc_backup   = AIPC_Updater::last_backup();
$aipc_bak_ok   = false;
if ( '' !== $aipc_backup && is_readable( $aipc_backup . '/wp-ai-post-creator.php' ) ) {
	$aipc_bak_ok = false !== strpos( (string) file_get_contents( $aipc_backup . '/wp-ai-post-creator.php' ), "define( 'AIPC_VERSION', '" . AIPC_VERSION . "' )" );
}

// -- what actually hit the (mocked) GitHub API --
$aipc_raw_auth     = false;
$aipc_api_auth     = false;
$aipc_codeload     = false;
if ( file_exists( $log_file ) ) {
	foreach ( file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $aipc_line ) {
		$aipc_row = json_decode( $aipc_line, true );
		if ( ! $aipc_row || ! isset( $aipc_row['host'] ) ) {
			continue;
		}
		if ( 'git-raw' === $aipc_row['host'] && ! empty( $aipc_row['auth'] ) && 'ahmad75naraghi/wp-ai-post-creator' === $aipc_row['repo'] ) {
			$aipc_raw_auth = true;
		}
		if ( 'git-zip-api' === $aipc_row['host'] && ! empty( $aipc_row['auth'] ) ) {
			$aipc_api_auth = true;
		}
		if ( 'git-zip' === $aipc_row['host'] && 'bad' === $aipc_row['branch'] && empty( $aipc_row['auth'] ) ) {
			$aipc_codeload = true;
		}
	}
}

ob_start();
AIPC_Admin::render_update();
$aipc_update_html = ob_get_clean();

$out['git_updater'] += array(
	'guard_blocks'     => is_wp_error( $aipc_res_old ),
	'live_untouched'   => $aipc_guard_untouched, // captured BEFORE the successful swap
	'update_ok'        => is_array( $aipc_res_ok ) && ! empty( $aipc_res_ok['ok'] ),
	'new_version'      => isset( $aipc_data_new['Version'] ) ? $aipc_data_new['Version'] : null, // expect 9.9.9
	'marker'           => file_exists( $aipc_git_dir . '/updated-marker.txt' ),
	'stale_gone'       => ! file_exists( $aipc_git_dir . '/stale-test.php' ),
	'backup_ok'        => $aipc_bak_ok,
	'still_active'     => in_array( 'wp-ai-post-creator/wp-ai-post-creator.php', (array) get_option( 'active_plugins' ), true ),
	'workdir_clean'    => 0 === count( (array) glob( WP_CONTENT_DIR . '/aipc-git-tmp-*' ) ),
	'codeload_no_auth' => $aipc_codeload,
	'api_with_auth'    => $aipc_api_auth,
	'raw_auth'         => $aipc_raw_auth,
	'page_renders'     => false !== strpos( $aipc_update_html, 'aipc_git_update' ),
	'page_repo_field'  => false !== strpos( $aipc_update_html, 'aipc-git-repo' ),
	'page_token_field' => false !== strpos( $aipc_update_html, 'aipc-git-token' ),
	'page_test_button' => false !== strpos( $aipc_update_html, 'aipc_git_test' ),
	'handlers_bound'   => has_action( 'admin_post_aipc_git_update' ) && has_action( 'admin_post_aipc_git_check' ) && has_action( 'admin_post_aipc_git_test' ),
);

echo "\n===E2E_JSON===\n";
$aipc_json = json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
if ( false === $aipc_json ) {
	echo 'JSON_ERROR: ' . json_last_error_msg() . "\n";
	exit( 1 );
}
echo $aipc_json;
