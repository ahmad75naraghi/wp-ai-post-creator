<?php
/**
 * Mock OpenAI-compatible API for the AI Post Creator end-to-end test.
 *
 * Intercepts wp_remote_* calls to https://mock.invalid/v1/* (chat) and
 * https://images.invalid/v1/* (images) and returns scripted responses that
 * drive the agent pipeline deterministically. Every request is logged to
 * wp-content/mock-api-log.jsonl for assertions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aipc_mock_log( $entry ) {
	file_put_contents(
		WP_CONTENT_DIR . '/mock-api-log.jsonl',
		json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n",
		FILE_APPEND
	);
}

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	// Respect earlier (higher-priority) interceptions (e.g. failure injection).
	if ( null !== $preempt && false !== $preempt ) {
		return $preempt;
	}

	$host = (string) wp_parse_url( $url, PHP_URL_HOST );

	// ---- Bale Bot API mock ----
	if ( 'tapi.bale.ai' === $host ) {
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$method = '';
		if ( preg_match( '#/bot([^/]+)/([A-Za-z]+)#', $path, $m ) ) {
			$bale_token  = $m[1];
			$bale_method = $m[2];
		} else {
			$bale_token  = '';
			$bale_method = '';
		}
		$bale_body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : array();

		aipc_mock_log( array(
			'host'    => 'bale',
			'token'   => $bale_token,
			'method'  => $bale_method,
			'chat_id' => isset( $bale_body['chat_id'] ) ? $bale_body['chat_id'] : null,
			'photo'   => isset( $bale_body['photo'] ) ? $bale_body['photo'] : null,
			'caption' => isset( $bale_body['caption'] ) ? $bale_body['caption'] : null,
			'text'    => isset( $bale_body['text'] ) ? $bale_body['text'] : null,
		) );

		// Invalid tokens are rejected.
		if ( false !== strpos( $bale_token, 'bad' ) ) {
			return array(
				'body'     => json_encode( array( 'ok' => false, 'description' => 'mock bale: invalid token' ) ),
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
			);
		}

		if ( 'getUpdates' === $bale_method ) {
			return array(
				'body'     => json_encode( array(
					'ok'     => true,
					'result' => array(
						array(
							'update_id' => 1,
							'message'   => array(
								'message_id' => 1,
								'from'       => array( 'id' => 11, 'first_name' => 'Test' ),
								'chat'       => array( 'id' => 98765, 'first_name' => 'Test Person' ),
								'text'       => '/start',
							),
						),
					),
				) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		}

		return array(
			'body'     => json_encode( array( 'ok' => true, 'result' => array( 'message_id' => 42 ) ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	// ---- RSS feed of a research source site ----
	$aipc_path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( 'news.invalid' === $host && false !== strpos( $aipc_path, 'feed' ) ) {
		aipc_mock_log( array(
			'host'   => 'rss',
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'url'    => $url,
		) );
		$aipc_rss = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<rss version="2.0"><channel><title>اخبار آزمایشی باغبانی</title><link>https://news.invalid</link><description>فید آزمایشی</description>'
			. '<item><title>خبر آزمایشی: کشاورزی شهری در خانه رواج می‌یابد</title><link>https://news.invalid/item-1</link><description>گزارشی تازه درباره رشد سبزی‌کاری خانگی و بالکنی در شهرهای بزرگ.</description></item>'
			. '<item><title>خبر آزمایشی: خاک مناسب برای گلدان</title><link>https://news.invalid/item-2</link><description>راهنمای انتخاب خاک غنی با زهکشی مناسب برای کاشت خانگی.</description></item>'
			. '</channel></rss>';
		return array(
			'body'     => $aipc_rss,
			'headers'  => array( 'content-type' => 'application/rss+xml; charset=UTF-8' ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	// ---- Git self-updater mocks ----
	if ( 'raw.githubusercontent.com' === $host ) {
		$aipc_git_branch = 'main';
		if ( preg_match( '#/wp-ai-post-creator\.php$#', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			preg_match( '#raw\.githubusercontent\.com/[^/]+/[^/]+/([^/]+)/#', $url, $aipc_m );
			$aipc_git_branch = isset( $aipc_m[1] ) ? $aipc_m[1] : 'main';
		}
		$aipc_git_version = ( 'old' === $aipc_git_branch ) ? '0.0.1' : '9.9.9';
		aipc_mock_log( array(
			'host'    => 'git-raw',
			'branch'  => $aipc_git_branch,
			'version' => $aipc_git_version,
		) );
		return array(
			'body'     => "<?php\n/**\n * Plugin Name: AI Post Creator\n * Version: {$aipc_git_version}\n */\n",
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	if ( 'codeload.github.com' === $host ) {
		$aipc_git_branch = 'main';
		if ( preg_match( '#/zip/refs/heads/(.+)$#', (string) wp_parse_url( $url, PHP_URL_PATH ), $aipc_m ) ) {
			$aipc_git_branch = $aipc_m[1];
		}
		aipc_mock_log( array(
			'host'   => 'git-zip',
			'branch' => $aipc_git_branch,
		) );

		// 'bad' serves garbage; 'old' never reaches the download (version guard).
		if ( 'bad' === $aipc_git_branch ) {
			return array(
				'body'     => 'this is definitely not a zip archive',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		}

		// Build a zipball from the CURRENT live plugin folder with the version
		// bumped to 9.9.9, plus a marker file the assertions look for.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$aipc_work = WP_CONTENT_DIR . '/aipc-mock-git-' . time();
		$aipc_src  = WP_PLUGIN_DIR . '/wp-ai-post-creator';
		$aipc_dst  = $aipc_work . '/wp-ai-post-creator';

		$aipc_copy_dir = function ( $from, $to ) use ( &$aipc_copy_dir ) {
			wp_mkdir_p( $to );
			foreach ( scandir( $from ) as $f ) {
				if ( '.' === $f || '..' === $f || '.git' === $f || 'stale-test.php' === $f ) {
					continue;
				}
				if ( is_dir( $from . '/' . $f ) ) {
					$aipc_copy_dir( $from . '/' . $f, $to . '/' . $f );
				} else {
					copy( $from . '/' . $f, $to . '/' . $f );
				}
			}
		};
		$aipc_copy_dir( $aipc_src, $aipc_dst );

		$aipc_main = $aipc_dst . '/wp-ai-post-creator.php';
		file_put_contents( $aipc_main, str_replace( AIPC_VERSION, '9.9.9', file_get_contents( $aipc_main ) ) );
		file_put_contents( $aipc_dst . '/updated-marker.txt', 'updated-9.9.9' );

		$aipc_zipfile = $aipc_work . '.zip';
		$aipc_archive = new PclZip( $aipc_zipfile );
		$aipc_archive->create( $aipc_work, PCLZIP_OPT_REMOVE_PATH, $aipc_work );
		$aipc_zip_bytes = (string) file_get_contents( $aipc_zipfile );

		$aipc_rm_dir = function ( $dir ) use ( &$aipc_rm_dir ) {
			if ( ! is_dir( $dir ) ) {
				return;
			}
			foreach ( scandir( $dir ) as $f ) {
				if ( '.' !== $f && '..' !== $f ) {
					$path = $dir . '/' . $f;
					is_dir( $path ) ? $aipc_rm_dir( $path ) : unlink( $path );
				}
			}
			rmdir( $dir );
		};
		$aipc_rm_dir( $aipc_work );
		unlink( $aipc_zipfile );

		return array(
			'body'     => $aipc_zip_bytes,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	if ( ! in_array( $host, array( 'mock.invalid', 'images.invalid', 'flaky.invalid' ), true ) ) {
		return $preempt;
	}

	$method = isset( $args['method'] ) ? $args['method'] : 'POST';
	$body   = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
	$auth   = '';
	if ( isset( $args['headers']['Authorization'] ) ) {
		$auth = $args['headers']['Authorization'];
	} elseif ( isset( $args['headers']['authorization'] ) ) {
		$auth = $args['headers']['authorization'];
	}

	$prompt = '';
	if ( is_array( $body ) && ! empty( $body['messages'] ) ) {
		foreach ( $body['messages'] as $message ) {
			if ( isset( $message['role'] ) && 'user' === $message['role'] ) {
				$prompt = (string) $message['content'];
			}
		}
	}

	aipc_mock_log( array(
		'method'    => $method,
		'url'       => $url,
		'host'      => $host,
		'auth'      => $auth,
		'model'     => isset( $body['model'] ) ? $body['model'] : null,
		'prompt'    => mb_substr( $prompt, 0, 4000 ),
	) );

	$chat = function ( $content ) {
		return array(
			'body'     => json_encode( array(
				'id'      => 'chatcmpl-mock',
				'object'  => 'chat.completion',
				'choices' => array( array(
					'index'         => 0,
					'message'       => array( 'role' => 'assistant', 'content' => $content ),
					'finish_reason' => 'stop',
				) ),
				'usage'   => array( 'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_tokens' => 200 ),
				'model'   => 'mock-model',
			), JSON_UNESCAPED_UNICODE ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	};

	// GET /models
	if ( false !== strpos( $url, '/models' ) ) {
		return array(
			'body'     => json_encode( array(
				'object' => 'list',
				'data'   => array(
					array( 'id' => 'mock-mini' ),
					array( 'id' => 'mock-pro' ),
					array( 'id' => 'dall-e-3' ),
				),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	// POST /images/generations
	if ( false !== strpos( $url, '/images/generations' ) ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		return array(
			'body'     => json_encode( array(
				'created' => time(),
				'data'    => array( array( 'b64_json' => base64_encode( $png ) ) ),
			) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	// Always-failing provider used by the fallback-chain test.
	if ( 'flaky.invalid' === $host ) {
		return array(
			'body'     => json_encode( array( 'error' => array( 'message' => 'mock flaky provider is down' ) ) ),
			'response' => array( 'code' => 500, 'message' => 'Server Error' ),
		);
	}

	// POST /chat/completions — route by prompt content.
	$has = function ( $needle ) use ( $prompt ) {
		return false !== strpos( $prompt, $needle );
	};

	if ( $has( 'single word: OK' ) ) {
		return $chat( 'OK' );
	}

	if ( $has( 'REWRITE ANALYSIS' ) ) { // rw_analyze step
		$aipc_internal = array();
		if ( preg_match( '/- (.+?) — (http:\/\/localhost\/\?p=\d+)/u', $prompt, $aipc_m ) ) {
			$aipc_internal[] = array( 'title' => $aipc_m[1], 'url' => $aipc_m[2] );
		}
		return $chat( json_encode( array(
			'title'              => 'عنوان بازنویسی‌شدهٔ بهتر و سئوپسند',
			'primary_keyword'    => 'سبزی‌کاری در بالکن',
			'secondary_keywords' => array( 'کاشت سبزیجات', 'بالکن کوچک', 'خاک مناسب' ),
			'notes'              => "- عنوان تازه‌تر و دقیق‌تر بنویس\n- ساختار بخش‌ها را مرتب کن\n- لینک داخلی مرتبط را حفظ کن",
			'sections'           => array(
				array( 'heading' => 'بخش بازنویسی‌شده یک', 'brief' => 'پوشش کامل موضوع اول با نگاه تازه.' ),
				array( 'heading' => 'بخش بازنویسی‌شده دو', 'brief' => 'پوشش کامل موضوع دوم با نکات کاربردی.' ),
			),
			'internal_links'     => $aipc_internal,
		), JSON_UNESCAPED_UNICODE ) );
	}

	if ( $has( 'FULL REWRITE' ) ) { // rw_rewrite step
		$n = 2;
		if ( preg_match( '/exactly (\d+) main sections/', $prompt, $aipc_m ) ) {
			$n = (int) $aipc_m[1];
		}
		$aipc_internal_link = '';
		if ( preg_match( '/(http:\/\/localhost\/\?p=\d+)/', $prompt, $aipc_m ) ) {
			$aipc_internal_link = ' در همین بستر، <a href="' . $aipc_m[1] . '">مقالهٔ مرتبط</a> را هم ببینید.';
		}
		$html = '<p>مقدمهٔ کاملاً بازنویسی‌شده و تازه: این مقاله با نگاهی نو به سبزی‌کاری خانگی می‌پردازد و تمام نکات را ساده و کاربردی بازگو می‌کند. پیوند مرجع قدیمی همچنان معتبر است: <a href="https://example.com/old">منبع اصلی</a>.' . $aipc_internal_link . ' خواننده با چند دقیقه مطالعه می‌تواند کاشت خود را شروع کند و به نتیجهٔ مطلوب برسد.</p>';
		for ( $i = 1; $i <= $n; $i++ ) {
			$html .= '<h2>بخش بازنویسی‌شده ' . strval( $i ) . '</h2><p>محتوای تازه و اصیل بخش ' . strval( $i ) . ': تمام جملات این بخش کاملاً بازنویسی شده‌اند تا از هرگونه کلیشه و تکرار دور بماند و کلیدواژهٔ اصلی به‌طور طبیعی در متن بنشیند. نکات کاربردی گام‌به‌گام توضیح داده شده‌اند تا هر خواننده‌ای بتواند آن‌ها را انجام دهد و نتیجه بگیرد.</p>';
		}
		$html .= '<h2>نتیجه‌گیری بازنویسی‌شده</h2><p>جمع‌بندی تازه و الهام‌بخش: با رعایت نکات این راهنما، سبزی‌کاری در فضای کوچک ساده و لذت‌بخش می‌شود. تجربه‌های خود را با دیگران به اشتراک بگذارید و همین امروز اولین گلدان را بکارید.</p>';
		return $chat( $html );
	}

	if ( $has( '"toc_title"' ) ) { // plan step (category + topic from the site prompt)
		$aipc_title = 'راهنمای کامل سبزی‌کاری در بالکن';
		if ( preg_match( '/suggests this topic: "([^"]+)/u', $prompt, $aipc_m )
			|| preg_match( '/پیشنهاد داده: «([^»]+)/u', $prompt, $aipc_m ) ) {
			$aipc_title = trim( $aipc_m[1] );
		}
		return $chat( json_encode( array(
			'category'             => 'باغبانی',
			'title'                => $aipc_title,
			'title_options'        => array( 'سبزی‌کاری آپارتمانی از صفر تا برداشت' ),
			'topic_brief'          => 'راهنمای عملی کاشت سبزیجات در بالکن کوچک از انتخاب خاک تا برداشت.',
			'audience'             => 'ساکنان آپارتمان‌های کوچک و مبتدیان باغبانی',
			'intent'               => 'اطلاعاتی و آموزشی',
			'primary_keyword'      => 'سبزی‌کاری در بالکن',
			'secondary_keywords'   => array( 'کاشت سبزیجات', 'بالکن کوچک', 'خاک مناسب', 'آبیاری صحیح', 'نور کافی' ),
			'angle'                => 'تمرکز بر راه‌حل‌های کم‌جا و کم‌هزینه',
			'toc_title'            => 'فهرست مطالب',
		), JSON_UNESCAPED_UNICODE ) );
	}

	if ( $has( '"sections"' ) ) { // outline step
		$n = 3;
		if ( preg_match( '/exactly (\d+) main sections/', $prompt, $m ) ) {
			$n = (int) $m[1];
		}
		$sections = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$sections[] = array(
				'heading' => "بخش آزمایشی شماره {$i}",
				'brief'   => "در این بخش به موضوع {$i} پرداخته می‌شود و نکات کلیدی آن بررسی می‌گردد.",
			);
		}
		return $chat( json_encode( array( 'sections' => $sections ), JSON_UNESCAPED_UNICODE ) );
	}

	if ( $has( 'INTRODUCTION' ) ) {
		return $chat( '<p>مقدمه آزمایشی: در این مقاله با اصول سبزی‌کاری در فضای کوچک آشنا می‌شوید و یاد می‌گیرید چگونه با کمترین امکانات ممکن، سبزیجات تازه پرورش دهید و از محصول آن در آشپزخانه خود لذت ببرید. این راهنما به‌طور خاص برای مبتدیان طراحی شده است و همه مراحل را قدم‌به‌قدم و به زبان ساده توضیح می‌دهد تا هر کسی بتواند شروع کند.</p>' );
	}

	if ( $has( 'You are writing section' ) ) {
		$heading = 'بخش';
		if ( preg_match( '/SECTION HEADING: (.+)/', $prompt, $m ) ) {
			$heading = trim( $m[1] );
		}
		return $chat( '<p>محتوای آزمایشی برای ' . $heading . '. این متن متعدد جمله‌ای است تا شمارش کلمات و ساختار HTML در این مرحله به‌درستی بررسی شود و ذخیره‌سازی بدون مشکل انجام شود. در ادامه چند نکته کاربردی و مهم برای این بخش ارائه می‌شود که هر خواننده‌ای می‌تواند از آن‌ها استفاده کند و به نتیجه مطلوب برسد.</p><ul><li>نکته نخست در این بخش</li><li>نکته دوم در این بخش</li></ul>' );
	}

	if ( $has( 'COPYWRITING & SEO REVISION PASS' ) ) { // copywrite/revision step
		$n = 3;
		if ( preg_match( '/then exactly (\d+) main sections/', $prompt, $m ) ) {
			$n = (int) $m[1];
		}
		$html = '<p>مقدمه بازنویسی‌شده و بهبودیافته: این راهنمای بهبودیافته شما را گام‌به‌گام همراهی می‌کند تا با کمترین امکانات، سبزیجات تازه را در بالکن خود بکارید و از برداشت آن در آشپزخانه لذت ببرید. تمام نکات به‌صورت کاربردی و ساده بازنویسی شده‌اند تا هر مبتدی بتواند با اطمینان شروع کند و به نتیجه مطلوب برسد.</p>';
		for ( $i = 1; $i <= $n; $i++ ) {
			$html .= '<h2>بخش بهبودیافته ' . strval( $i ) . '</h2><p>محتوای بهبودیافته بخش ' . strval( $i ) . ' با تمرکز بر نکات کاربردی و عبارت‌پردازی اصیل. جملات کاملاً بازنویسی شده‌اند تا از تکرار و عبارات کلیشه‌ای پرهیز شود و کلیدواژه اصلی به‌طور طبیعی در متن به کار رود. این پاراگراف طول کافی برای تأیید شمارش کلمات را دارد و ساختار HTML آن استاندارد است و از نظر سئو و کپی‌رایتینگ بهینه شده است.</p>';
		}
		$html .= '<h2>نتیجه‌گیری</h2><p>جمع‌بندی نهایی بازنویسی‌شده همراه با دعوت به اقدام: تجربه‌های خود را در بخش دیدگاه‌ها با ما و سایر خوانندگان به اشتراک بگذارید و این راهنما را برای دوستان علاقه‌مند به باغبانی بفرستید تا آن‌ها هم از این نکات کاربردی بهره‌مند شوند.</p>';
		return $chat( $html );
	}

	if ( $has( 'CONCLUSION' ) ) {
		return $chat( '<h2>نتیجه‌گیری</h2><p>جمع‌بندی آزمایشی مقاله همراه با مرور نکات کلیدی هر بخش و تأکید بر اهمیت شروع کوچک و عملی. در پایان از خوانندگان عزیز دعوت می‌کنیم تجربه‌ها و پرسش‌های خود را در بخش دیدگاه‌ها با ما و سایر خوانندگان به اشتراک بگذارند و این مطلب را برای دوستان علاقه‌مند به باغبانی بفرستند.</p>' );
	}

	if ( $has( 'FAQ questions' ) ) { // custom prompt still contains the routing phrase
		return $chat( json_encode( array(
			'faq_heading' => 'پرسش‌های متداول',
			'items'       => array(
				array( 'q' => 'چه مقدار نور لازم است؟', 'a' => 'حداقل چهار ساعت نور مستقیم خورشید در روز.' ),
				array( 'q' => 'به چه خاکی نیاز دارم؟', 'a' => 'خاک کاشت غنی با زهکشی مناسب.' ),
				array( 'q' => 'چقدر جا لازم است؟', 'a' => 'یک متر مربع فضای بالکن کافی است.' ),
			),
		), JSON_UNESCAPED_UNICODE ) );
	}

	if ( $has( '"meta_title"' ) ) { // seo step
		return $chat( json_encode( array(
			'meta_title'       => 'سبزی‌کاری در بالکن | راهنمای کامل',
			'meta_description' => 'آموزش گام‌به‌گام سبزی‌کاری در بالکن برای مبتدیان با کمترین امکانات.',
			'slug'             => 'balcony-vegetable-gardening-guide',
			'excerpt'          => 'راهنمای کامل سبزی‌کاری در بالکن برای مبتدیان.',
			'tags'             => array( 'باغبانی', 'سبزیجات', 'بالکن', 'کشاورزی شهری', 'آپارتمان' ),
		), JSON_UNESCAPED_UNICODE ) );
	}

	if ( $has( 'image-generation prompt' ) ) {
		return $chat( json_encode( array( 'prompt' => 'A modern editorial illustration of a green balcony garden with fresh vegetables in terracotta pots, soft morning light, clean composition, high quality, no text' ), JSON_UNESCAPED_UNICODE ) );
	}

	return $chat( 'OK' );
}, 10, 3 );
