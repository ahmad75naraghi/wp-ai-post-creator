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
	'image_step'    => AIPC_Steps::get( 'image' )['connection'] === $connections[1]['id'],
	'prompt_marker' => false !== strpos( AIPC_Steps::prompt_for( 'faq' ), 'FAQ-QUESTIONS-CUSTOM' ),
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
	'placeholder'  => __( 'Default (%s)', 'wp-ai-post-creator' ) === 'پیش‌فرض (%s)',
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
);

ob_start();
AIPC_Admin::render_prompts();
$html = ob_get_clean();
$out['admin_pages']['prompts'] = array(
	'rendered'        => false !== strpos( $html, 'aipc-step-card' ),
	'shows_custom'    => false !== strpos( $html, 'FAQ-QUESTIONS-CUSTOM' ),
	'shows_image_sel' => false !== strpos( $html, 'steps[image]' ),
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
		$aipc_cron_job = $aipc_j;
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
);

echo "\n===E2E_JSON===\n";
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
