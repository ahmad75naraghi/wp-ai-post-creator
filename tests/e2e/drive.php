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
 * REST: models + connection test
 * ------------------------------------------------------------------ */
rest_get_server();
wp_set_current_user( 1 );

$req  = new WP_REST_Request( 'GET', '/aipc/v1/models' );
$resp = rest_do_request( $req );
$out['rest_models'] = array(
	'status' => $resp->get_status(),
	'models' => $resp->get_data()['models'] ?? null,
);

$req  = new WP_REST_Request( 'POST', '/aipc/v1/test' );
$resp = rest_do_request( $req );
$out['rest_test'] = array(
	'status' => $resp->get_status(),
	'ok'     => ! empty( $resp->get_data()['ok'] ),
);

/* ------------------------------------------------------------------ *
 * REST: permission check — anonymous user must be rejected
 * ------------------------------------------------------------------ */
wp_set_current_user( 0 );
$req  = new WP_REST_Request( 'POST', '/aipc/v1/start' );
$req->set_param( 'topic', 'test' );
$resp = rest_do_request( $req );
$out['rest_permissions'] = array(
	'anonymous_status' => $resp->get_status(), // expect 401/403
);
wp_set_current_user( 1 );

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

$since = $state['since'] ?? 0;
$guard = 0;
while ( isset( $state['status'] ) && 'running' === $state['status'] && $guard++ < 50 ) {
	$req = new WP_REST_Request( 'POST', '/aipc/v1/step' );
	$req->set_param( 'job_id', $state['id'] );
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
		'seo_title'   => get_post_meta( $post_id, '_aipc_meta_title', true ),
		'seo_desc'    => get_post_meta( $post_id, '_aipc_meta_description', true ),
		'yoast_desc'  => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
		'rankmath'    => get_post_meta( $post_id, 'rank_math_title', true ),
		'rankmath_desc' => get_post_meta( $post_id, 'rank_math_description', true ),
		'focus_kw'    => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
		'has_schema'  => false !== strpos( (string) get_post_meta( $post_id, '_aipc_faq_schema', true ), 'FAQPage' ),
		'generated'   => (bool) get_post_meta( $post_id, '_aipc_generated', true ),
	);

	$out['terms'] = array(
		'tags'       => wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ),
		'categories' => wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) ),
	);

	$out['thumbnail'] = (int) get_post_thumbnail_id( $post_id );
}

/* ------------------------------------------------------------------ *
 * Settings sanitize
 * ------------------------------------------------------------------ */
$clean = AIPC_Settings::sanitize( array(
	'api_key'             => ' new-key ',
	'api_base_url'        => 'https://api.openai.com/v1/',
	'chat_model'          => 'gpt-4o',
	'temperature'         => '9',
	'max_tokens'          => '99999',
	'request_timeout'     => '5',
	'content_language'    => 'fa',
	'default_tone'        => 'professional',
	'default_length'      => 'medium',
	'site_prompt'         => "  توضیح سایت  ",
	'image_model'         => 'dall-e-3',
	'image_size'          => '1024x1024',
	'image_enabled'       => '1',
	'add_toc'             => '0',
	'add_faq'             => '1',
	'system_prompt_extra' => "line1\nline2",
	'delete_on_uninstall' => '0',
) );

$out['sanitize'] = array(
	'key_trimmed'     => 'new-key' === $clean['api_key'],
	'base_normalized' => 'https://api.openai.com/v1' === $clean['api_base_url'],
	'temp_clamped'    => 0.7 === $clean['temperature'],
	'tokens_clamped'  => 16000 === $clean['max_tokens'],
	'timeout_clamped' => 15 === $clean['request_timeout'],
	'toc_off'         => 0 === $clean['add_toc'],
	'site_prompt_trimmed' => 'توضیح سایت' === $clean['site_prompt'],
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
$out['provider_requests'] = array(
	'count'    => count( $requests ),
	'auth_ok'  => ! empty( $requests ) && 'Bearer sk-mock-key' === $requests[0]['auth'],
	'urls'     => array_values( array_unique( array_column( $requests, 'url' ) ) ),
	'models'   => array_values( array_unique( array_column( $requests, 'model' ) ) ),
);

/* ------------------------------------------------------------------ *
 * Cancel behavior on a second job
 * ------------------------------------------------------------------ */
$job = AIPC_Agent::instance()->create_job( 'cancel test topic', array( 'length' => 'short', 'language' => 'fa' ) );
AIPC_Agent::instance()->execute_step( $job['id'], 0 ); // plan
AIPC_Agent::instance()->cancel_job( $job['id'] );
$after = AIPC_Agent::instance()->get_job( $job['id'] );
$st    = AIPC_Agent::instance()->execute_step( $after['id'], 0 );
$out['cancel_flow'] = array(
	'status_after_cancel' => $after['status'],
	'status_after_step'   => $st['status'], // stays cancelled
);

/* ------------------------------------------------------------------ *
 * Failure + retry flow on a third job (provider returns 500 once)
 * ------------------------------------------------------------------ */
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( ! empty( $GLOBALS['aipc_fail_next'] ) && false !== strpos( $url, '/chat/completions' ) ) {
		return array(
			'body'     => json_encode( array( 'error' => array( 'message' => 'mock upstream error' ) ) ),
			'response' => array( 'code' => 500, 'message' => 'Server Error' ),
		);
	}
	return $preempt;
}, 5, 3 );

$GLOBALS['aipc_fail_next'] = true;

$job   = AIPC_Agent::instance()->create_job( 'failure flow topic', array( 'length' => 'short', 'language' => 'fa' ) );
$state = AIPC_Agent::instance()->execute_step( $job['id'], 0 );
$err   = $state;
if ( 'running' === $state['status'] ) {
	// the first call was plan (maybe not hit by the 500 filter due to ordering) — keep stepping
	$guard = 0;
	while ( 'running' === $state['status'] && $guard++ < 30 ) {
		$state = AIPC_Agent::instance()->execute_step( $job['id'], 0 );
	}
	$err = $state;
}
$out['failure_flow'] = array(
	'status'         => $err['status'],
	'error_message'  => $err['error']['message'] ?? null,
	'error_step'     => $err['error']['step'] ?? null,
);

if ( 'error' === $err['status'] ) {
	$GLOBALS['aipc_fail_next'] = false;
	$state = AIPC_Agent::instance()->retry_job( $job['id'] );
	$guard = 0;
	while ( 'running' === $state['status'] && $guard++ < 30 ) {
		$state = AIPC_Agent::instance()->execute_step( $job['id'], 0 );
	}
	$out['retry_flow'] = array(
		'status_after_retry' => $state['status'],
		'has_result'         => ! empty( $state['result']['post_id'] ),
	);
}

echo "\n===E2E_JSON===\n";
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
