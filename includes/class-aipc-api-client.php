<?php
/**
 * OpenAI-compatible HTTP client (chat completions, models, images).
 *
 * Works with OpenAI, OpenRouter, Groq, DeepSeek, Together, Ollama,
 * LM Studio and any other provider exposing an OpenAI-compatible REST API.
 *
 * @package wp-ai-post-creator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin, dependency-free client for OpenAI-compatible endpoints.
 *
 * An instance is bound to ONE connection (see AIPC_Connections): base URL,
 * API key, models and request parameters all come from that connection, so
 * different pipeline steps can talk to different providers.
 */
final class AIPC_API_Client {

	/**
	 * The connection this client talks to.
	 *
	 * @var array
	 */
	private $conn;

	/**
	 * Constructor.
	 *
	 * @param array|null $connection Connection data (falls back to the default connection).
	 */
	public function __construct( $connection = null ) {
		if ( ! is_array( $connection ) || empty( $connection ) ) {
			$connection = AIPC_Connections::get_default();
		}
		$defaults = array(
			'id'              => '',
			'name'            => '',
			'base_url'        => '',
			'api_key'         => '',
			'chat_model'      => 'gpt-4o-mini',
			'image_model'     => 'dall-e-3',
			'temperature'     => 0.7,
			'max_tokens'      => 4000,
			'request_timeout' => 120,
		);
		$this->conn = wp_parse_args( is_array( $connection ) ? $connection : array(), $defaults );
	}

	/**
	 * The connection name (for logs).
	 *
	 * @return string
	 */
	public function name() {
		return isset( $this->conn['name'] ) ? (string) $this->conn['name'] : '';
	}

	/**
	 * Whether the client has enough configuration to work.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->conn['base_url'] );
	}

	/**
	 * Normalized base URL.
	 *
	 * OpenAI-compatible providers serve the API under /v1 (OpenAI, OpenRouter,
	 * Groq, Ollama, LM Studio, OmniRoute …), so a bare host without any path
	 * gets /v1 appended automatically — entering http://localhost:20128 for a
	 * local gateway then "just works".
	 *
	 * @return string
	 */
	public function base_url() {
		$base = rtrim( trim( (string) $this->conn['base_url'] ), '/' );

		// Users often paste the full endpoint URL instead of the base
		// (…/v1/chat/completions → the request would become
		// …/v1/chat/completions/models). Strip well-known OpenAI endpoint
		// paths off the end until none is left.
		$endpoints = array( '/chat/completions', '/completions', '/responses', '/models', '/embeddings', '/images/generations' );
		do {
			$stripped = false;
			foreach ( $endpoints as $endpoint ) {
				$len = strlen( $endpoint );
				if ( strlen( $base ) > $len && substr( $base, -$len ) === $endpoint ) {
					$base     = rtrim( substr( $base, 0, -$len ), '/' );
					$stripped = true;
					break;
				}
			}
		} while ( $stripped );

		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		if ( '' !== $host && ( '' === $path || '/' === $path ) ) {
			$base .= '/v1';
		}
		return $base;
	}

	/**
	 * Perform an HTTP request against the provider.
	 *
	 * @param string      $path           API path, e.g. "/chat/completions".
	 * @param array|null  $body           JSON body (null = GET).
	 * @param int|null    $timeout        Optional timeout override.
	 * @param int         $attempt        Internal retry counter.
	 * @param bool        $retry_timeouts Whether a transport timeout earns one
	 *                                    automatic retry (image routes disable
	 *                                    this — a hanging gateway would double
	 *                                    the stall, 1.17.1).
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	private function request( $path, $body = null, $timeout = null, $attempt = 0, $retry_timeouts = true ) {
		$url  = $this->base_url() . $path;
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			// Attribution headers recommended by OpenRouter and friendly to
			// WAFs that distrust anonymous datacenter traffic.
			'HTTP-Referer' => home_url( '/' ),
			'X-Title'      => get_bloginfo( 'name' ),
		);
		// Local providers (Ollama, LM Studio) work without a key.
		if ( '' !== (string) $this->conn['api_key'] ) {
			$headers['Authorization'] = 'Bearer ' . $this->conn['api_key'];
		}

		/**
		 * Filter the headers sent with every AI API request.
		 *
		 * Useful for gateways that require extra headers (organization ids,
		 * proxy auth, custom WAF tokens …).
		 *
		 * @param array  $headers Request headers.
		 * @param string $url     Full request URL.
		 * @param array  $conn    Connection data (never the raw key).
		 */
		$headers = apply_filters( 'aipc_api_headers', $headers, $url, array_diff_key( $this->conn, array( 'api_key' => 1 ) ) );

		$args = array(
			'method'  => null === $body ? 'GET' : 'POST',
			'timeout' => $timeout ? (int) $timeout : max( 15, (int) $this->conn['request_timeout'] ),
			'headers' => $headers,
			'user-agent' => 'wp-ai-post-creator/' . AIPC_VERSION . ' (WordPress)',
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$t0       = microtime( true );
		$response = wp_remote_request( $url, $args );

		// Full API trace (v1.11.0) — everything that went out and came
		// back, once per attempt, with secrets redacted by AIPC_Trace.
		if ( AIPC_Trace::enabled() ) {
			$trace_raw  = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$trace_json = '' !== $trace_raw ? json_decode( $trace_raw, true ) : null;
			AIPC_Trace::log( 'api_call', array(
				'connection' => isset( $this->conn['name'] ) ? (string) $this->conn['name'] : '',
				'method'     => $args['method'],
				'url'        => $url,
				'attempt'    => $attempt + 1,
				'ms'         => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
				'request'    => null === $body ? null : $body,
				'status'     => is_wp_error( $response ) ? 'transport-error' : (int) wp_remote_retrieve_response_code( $response ),
				'response'   => is_wp_error( $response ) ? $response->get_error_message() : ( is_array( $trace_json ) ? $trace_json : mb_substr( $trace_raw, 0, 4000 ) ),
			) );
		}

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( 0 === $attempt && $retry_timeouts && false !== stripos( $message, 'timed out' ) ) {
				return $this->request_after_delay( $path, $body, $timeout, $attempt, $message );
			}
			return new WP_Error( 'aipc_http', sprintf(
				/* translators: %s: cURL/HTTP error message. */
				__( 'Could not reach the AI provider: %s', 'wp-ai-post-creator' ),
				$message
			) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->error_message( $json, $raw );
			// One retry on rate-limit / transient server errors.
			if ( 0 === $attempt && ( 429 === $code || $code >= 500 ) ) {
				return $this->request_after_delay( $path, $body, $timeout, $attempt, $message );
			}
			return new WP_Error( 'aipc_http', sprintf(
				/* translators: 1: HTTP status code, 2: provider error message. */
				__( 'API error %1$d: %2$s', 'wp-ai-post-creator' ),
				$code,
				$message
			) );
		}

		if ( ! is_array( $json ) ) {
			if ( self::looks_like_html( $raw ) ) {
				return new WP_Error( 'aipc_http', __( 'The address returned a web page (HTML) instead of an API response — it looks like a website URL, not an API base URL. OpenAI-compatible endpoints almost always end in /v1.', 'wp-ai-post-creator' ) );
			}
			return new WP_Error( 'aipc_http', __( 'The provider returned an invalid (non-JSON) response.', 'wp-ai-post-creator' ) );
		}

		return $json;
	}

	/**
	 * Retry helper with a short delay.
	 *
	 * @param string     $path    API path.
	 * @param array|null $body    JSON body.
	 * @param int|null   $timeout Timeout.
	 * @param int        $attempt Attempt number.
	 * @param string     $reason  Previous failure reason (for logging context).
	 * @return array|WP_Error
	 */
	private function request_after_delay( $path, $body, $timeout, $attempt, $reason = '' ) {
		unset( $reason );
		sleep( 2 );
		return $this->request( $path, $body, $timeout, $attempt + 1 );
	}

	/**
	 * Extract a readable error message from a provider response.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $raw  Raw body.
	 * @return string
	 */
	private function error_message( $json, $raw ) {
		if ( is_array( $json ) ) {
			if ( ! empty( $json['error']['message'] ) ) {
				return sanitize_text_field( (string) $json['error']['message'] );
			}
			if ( ! empty( $json['message'] ) ) {
				return sanitize_text_field( (string) $json['message'] );
			}
			if ( ! empty( $json['error'] ) && is_string( $json['error'] ) ) {
				return sanitize_text_field( $json['error'] );
			}
		}
		if ( self::looks_like_html( $raw ) ) {
			return __( 'the address returned a web page (HTML), not an API response — check that the base URL is the API endpoint (it usually ends in /v1)', 'wp-ai-post-creator' );
		}
		return mb_substr( trim( wp_strip_all_tags( $raw ) ), 0, 300 );
	}

	/**
	 * Whether a response body looks like an HTML document (a website,
	 * not an API endpoint).
	 *
	 * @param string $raw Raw response body.
	 * @return bool
	 */
	private static function looks_like_html( $raw ) {
		$head = strtolower( ltrim( substr( (string) $raw, 0, 300 ) ) );
		return 0 === strpos( $head, '<!doctype' ) || 0 === strpos( $head, '<html' ) || false !== strpos( $head, '<head' );
	}

	/**
	 * Run a chat completion.
	 *
	 * @param array $messages Chat messages.
	 * @param array $opts     {model, temperature, max_tokens, json}.
	 * @return array|WP_Error {content, usage, model}
	 */
	public function chat( $messages, $opts = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aipc_config', __( 'API key or endpoint is not configured. Open Settings and connect a provider first.', 'wp-ai-post-creator' ) );
		}

		$body = array(
			'model'    => ! empty( $opts['model'] ) ? $opts['model'] : $this->conn['chat_model'],
			'messages' => array_values( $messages ),
		);

		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = (float) $opts['temperature'];
		} else {
			$body['temperature'] = (float) $this->conn['temperature'];
		}

		$max_tokens = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : (int) $this->conn['max_tokens'];
		if ( $max_tokens > 0 ) {
			$body['max_tokens'] = $max_tokens;
		}

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$json = $this->request( '/chat/completions', $body );

		if ( is_wp_error( $json ) ) {
			// Some providers reject response_format — retry once without it.
			if ( ! empty( $opts['json'] ) && false !== stripos( $json->get_error_message(), 'response_format' ) ) {
				$opts['json'] = false;
				return $this->chat( $messages, $opts );
			}
			return $json;
		}

		$content = '';
		if ( isset( $json['choices'][0]['message']['content'] ) ) {
			$content = (string) $json['choices'][0]['message']['content'];
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error( 'aipc_empty', __( 'The model returned an empty response. Try again or lower the max tokens setting.', 'wp-ai-post-creator' ) );
		}

		return array(
			'content' => $content,
			'usage'   => isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array(),
			'model'   => isset( $json['model'] ) ? (string) $json['model'] : $body['model'],
		);
	}

	/**
	 * Chat completion that must return JSON.
	 *
	 * @param array $messages Chat messages.
	 * @param array $opts     Options passed to chat().
	 * @return array|WP_Error {data: array, usage: array}
	 */
	public function chat_json( $messages, $opts = array() ) {
		$opts['json'] = true;
		$res = $this->chat( $messages, $opts );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data = self::extract_json( $res['content'] );

		if ( null === $data ) {
			// One corrective retry.
			$retry          = $messages;
			$retry[]        = array( 'role' => 'assistant', 'content' => $res['content'] );
			$retry[]        = array(
				'role'    => 'user',
				'content' => 'That was not valid JSON. Respond again with ONLY the JSON object — no markdown fences, no explanations.',
			);

			$res2 = $this->chat( $retry, $opts );
			if ( is_wp_error( $res2 ) ) {
				return $res2;
			}
			$data = self::extract_json( $res2['content'] );
			if ( null === $data ) {
				return new WP_Error( 'aipc_json', __( 'The model did not return valid JSON. Try again, or switch to a stronger model.', 'wp-ai-post-creator' ) );
			}
			return array(
				'data'  => $data,
				'usage' => isset( $res2['usage'] ) ? $res2['usage'] : array(),
				'model' => isset( $res2['model'] ) ? $res2['model'] : '',
			);
		}

		return array(
			'data'  => $data,
			'usage' => isset( $res['usage'] ) ? $res['usage'] : array(),
			'model' => isset( $res['model'] ) ? $res['model'] : '',
		);
	}

	/**
	 * List available model ids.
	 *
	 * @return array|WP_Error
	 */
	public function models() {
		$json = $this->request( '/models', null, 20 );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$ids = array();
		if ( ! empty( $json['data'] ) && is_array( $json['data'] ) ) {
			foreach ( $json['data'] as $model ) {
				if ( ! empty( $model['id'] ) ) {
					$ids[] = (string) $model['id'];
				}
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Generate an image. Returns raw bytes or a URL.
	 *
	 * @param string $prompt Image prompt.
	 * @param array  $opts   {model, size}.
	 * @return array|WP_Error {bits: string} or {url: string}
	 */
	public function image( $prompt, $opts = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aipc_config', __( 'API key or endpoint is not configured.', 'wp-ai-post-creator' ) );
		}

		$model     = ! empty( $opts['model'] ) ? $opts['model'] : $this->conn['image_model'];
		$force_b64 = isset( $this->conn['image_format'] ) && 'b64' === $this->conn['image_format'];
		$route     = isset( $this->conn['image_api'] ) ? $this->conn['image_api'] : 'auto';
		if ( ! in_array( $route, array( 'auto', 'images', 'chat' ), true ) ) {
			$route = 'auto';
		}

		// Gemini-style gateways (OpenRouter, Antigravity …) generate images
		// through chat completions, not /images/generations.
		if ( 'chat' === $route ) {
			return $this->image_via_chat( $prompt, $model, $force_b64 );
		}

		$result = $this->image_via_endpoint( $prompt, $model, $force_b64, $opts );

		// Auto-rescue (1.18.0): the gateway rejected the configured image
		// model ("Invalid image model …") — discover a model that actually
		// exists in its /models list and retry once. Cached for a day.
		if ( is_wp_error( $result ) && self::looks_like_bad_model( $result->get_error_message() ) ) {
			$alt = $this->discover_image_model( $model );
			if ( '' !== $alt && $alt !== $model ) {
				$retry = $this->image_via_endpoint( $prompt, $alt, $force_b64, $opts );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
		}

		// Automatic fallback: when the images endpoint cannot deliver
		// (missing route, "no credentials for provider", refused link …),
		// try the chat-completions image route before giving up.
		if ( 'auto' === $route && is_wp_error( $result ) ) {
			// Circuit breaker (1.17.1): when this gateway's chat-image route
			// recently hung until the timeout, don't hang on it again on
			// every retry — fail fast for 15 minutes instead.
			$cb_key = 'aipc_imgchat_to_' . md5( $this->base_url() . '|' . $model );
			if ( get_transient( $cb_key ) ) {
				return $result;
			}
			$via_chat = $this->image_via_chat( $prompt, $model, $force_b64 );
			if ( ! is_wp_error( $via_chat ) ) {
				return $via_chat;
			}
			if ( false !== stripos( $via_chat->get_error_message(), 'timed out' ) ) {
				set_transient( $cb_key, 1, 15 * MINUTE_IN_SECONDS );
			}
			return $result; // The endpoint error is usually the more informative one.
		}
		return $result;
	}

	/**
	 * Does this provider error mean "that image model does not exist here"?
	 *
	 * @param string $msg Error message.
	 * @return bool
	 */
	public static function looks_like_bad_model( $msg ) {
		return (bool) preg_match( '/invalid (image )?model|model[^.]*not (found|exist|available|supported)|unknown model|no such model|does not exist/i', (string) $msg );
	}

	/**
	 * Ask the gateway's /models list for an image-capable model (1.18.0).
	 *
	 * @param string $bad_model The configured model the gateway rejected.
	 * @return string Alternative model id, or '' when none was found.
	 */
	private function discover_image_model( $bad_model ) {
		$key    = 'aipc_img_model_' . md5( $this->base_url() );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached === $bad_model ? '' : $cached;
		}

		$json = $this->request( '/models', null, 30, 0, false );
		if ( is_wp_error( $json ) || empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return '';
		}
		$ids = array();
		foreach ( $json['data'] as $m ) {
			if ( isset( $m['id'] ) && is_string( $m['id'] ) ) {
				$ids[] = $m['id'];
			}
		}
		$alt = self::pick_image_model( $ids, $bad_model );
		if ( '' !== $alt ) {
			set_transient( $key, $alt, DAY_IN_SECONDS );
		}
		return $alt;
	}

	/**
	 * Pick the most likely image model from a /models id list.
	 *
	 * @param string[] $ids     Model ids.
	 * @param string   $exclude Id to skip (the one that just failed).
	 * @return string Model id, or '' when nothing looks image-capable.
	 */
	public static function pick_image_model( $ids, $exclude = '' ) {
		$patterns = array(
			'/gpt-image/i',
			'/dall-?e/i',
			'/flux/i',
			'/imagen/i',
			'/stable-?diffusion|sdxl|sd3/i',
			'/image/i',
		);
		foreach ( $patterns as $pattern ) {
			foreach ( (array) $ids as $id ) {
				$id = (string) $id;
				if ( '' === $id || $id === $exclude ) {
					continue;
				}
				if ( preg_match( $pattern, $id ) && ! preg_match( '/embed|whisper|tts|audio|moderation/i', $id ) ) {
					return $id;
				}
			}
		}
		return '';
	}

	/**
	 * Timeout for image-generation calls: the connection timeout, capped.
	 *
	 * Image routes must not inherit a huge chat timeout (users set 600 s for
	 * slow text models) — a hanging image gateway would pin the job runner
	 * for that long on every attempt (1.17.1).
	 *
	 * @return int Seconds.
	 */
	private function image_timeout() {
		/**
		 * Filter the maximum timeout for image-generation requests.
		 *
		 * @param int   $cap  Cap in seconds (default 180).
		 * @param array $conn Connection data (never the raw key).
		 */
		$cap = (int) apply_filters( 'aipc_image_timeout', 180, array_diff_key( $this->conn, array( 'api_key' => 1 ) ) );
		return max( 15, min( max( 15, $cap ), max( 15, (int) $this->conn['request_timeout'] ) ) );
	}

	/**
	 * Generate an image via the classic /images/generations endpoint.
	 *
	 * @param string $prompt    Image prompt.
	 * @param string $model     Image model.
	 * @param bool   $force_b64 Refuse link-only responses.
	 * @param array  $opts      {size} overrides.
	 * @return array|WP_Error {bits} or {url}
	 */
	private function image_via_endpoint( $prompt, $model, $force_b64, $opts = array() ) {
		$size = ! empty( $opts['size'] ) ? $opts['size'] : AIPC_Settings::get( 'image_size' );

		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
		);
		if ( $size && 'auto' !== $size ) {
			$body['size'] = $size;
		}
		// gpt-image-* always returns b64 and rejects response_format.
		if ( 0 !== strpos( $model, 'gpt-image' ) ) {
			$body['response_format'] = 'b64_json';
		}

		$json = $this->request( '/images/generations', $body, $this->image_timeout(), 0, false );

		if ( is_wp_error( $json ) ) {
			$msg = $json->get_error_message();
			// Compatibility retries for providers with different parameter rules.
			if ( false !== stripos( $msg, 'response_format' ) ) {
				unset( $body['response_format'] );
				$json = $this->request( '/images/generations', $body, $this->image_timeout(), 0, false );
			} elseif ( false !== stripos( $msg, 'size' ) && isset( $body['size'] ) ) {
				unset( $body['size'] );
				$json = $this->request( '/images/generations', $body, $this->image_timeout(), 0, false );
			}
			if ( is_wp_error( $json ) ) {
				return $json;
			}
		}

		$item = isset( $json['data'][0] ) && is_array( $json['data'][0] ) ? $json['data'][0] : array();

		if ( ! empty( $item['b64_json'] ) ) {
			$bits = self::decode_b64_image( $item['b64_json'] );
			if ( false !== $bits ) {
				return array( 'bits' => $bits );
			}
		}

		// Some gateways put a data: URI into the url field — that is still
		// base64 payload we can decode locally.
		if ( ! empty( $item['url'] ) && 0 === strpos( (string) $item['url'], 'data:' ) ) {
			$bits = self::decode_b64_image( $item['url'] );
			if ( false !== $bits ) {
				return array( 'bits' => $bits );
			}
		}

		if ( ! empty( $item['url'] ) ) {
			if ( $force_b64 ) {
				return new WP_Error( 'aipc_image', __( 'Base64 mode: the provider returned a link instead of base64 image data — failing over to the next image connection.', 'wp-ai-post-creator' ) );
			}
			return array( 'url' => (string) $item['url'] );
		}

		return new WP_Error( 'aipc_image', __( 'The provider returned no image data.', 'wp-ai-post-creator' ) );
	}

	/**
	 * Generate an image through chat completions (Gemini/OpenRouter style).
	 *
	 * Image-capable chat models (gemini-2.5-flash-image, …) return the
	 * picture as a base64 data: URI inside the assistant message — either
	 * in message.images[].image_url.url, in multimodal content parts, or
	 * directly in the content string. That payload is decoded locally, so
	 * this route is deterministic by design.
	 *
	 * @param string $prompt    Image prompt.
	 * @param string $model     Image model.
	 * @param bool   $force_b64 Refuse link-only responses.
	 * @return array|WP_Error {bits} or {url}
	 */
	private function image_via_chat( $prompt, $model, $force_b64 ) {
		$body = array(
			'model'      => $model,
			'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			'modalities' => array( 'image', 'text' ),
		);

		$json = $this->request( '/chat/completions', $body, $this->image_timeout(), 0, false );
		if ( is_wp_error( $json ) ) {
			// Some providers reject the modalities parameter — retry without.
			if ( false !== stripos( $json->get_error_message(), 'modalities' ) ) {
				unset( $body['modalities'] );
				$json = $this->request( '/chat/completions', $body, $this->image_timeout(), 0, false );
			}
			if ( is_wp_error( $json ) ) {
				return $json;
			}
		}

		$message = isset( $json['choices'][0]['message'] ) && is_array( $json['choices'][0]['message'] ) ? $json['choices'][0]['message'] : array();

		$candidates = array();
		if ( ! empty( $message['images'] ) && is_array( $message['images'] ) ) {
			foreach ( $message['images'] as $img ) {
				if ( isset( $img['image_url']['url'] ) ) {
					$candidates[] = (string) $img['image_url']['url'];
				} elseif ( isset( $img['url'] ) ) {
					$candidates[] = (string) $img['url'];
				} elseif ( is_string( $img ) ) {
					$candidates[] = $img;
				}
			}
		}
		if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
			foreach ( $message['content'] as $part ) {
				if ( isset( $part['image_url']['url'] ) ) {
					$candidates[] = (string) $part['image_url']['url'];
				} elseif ( isset( $part['inline_data']['data'] ) ) {
					$candidates[] = (string) $part['inline_data']['data']; // Gemini inlineData.
				}
			}
		} elseif ( isset( $message['content'] ) && is_string( $message['content'] ) && 0 === strpos( trim( $message['content'] ), 'data:image' ) ) {
			$candidates[] = trim( $message['content'] );
		}

		$link = '';
		foreach ( $candidates as $candidate ) {
			if ( 0 === strpos( $candidate, 'http' ) ) {
				if ( '' === $link ) {
					$link = $candidate;
				}
				continue;
			}
			$bits = self::decode_b64_image( $candidate );
			if ( false !== $bits ) {
				return array( 'bits' => $bits );
			}
		}

		if ( '' !== $link ) {
			if ( $force_b64 ) {
				return new WP_Error( 'aipc_image', __( 'Base64 mode: the provider returned a link instead of base64 image data — failing over to the next image connection.', 'wp-ai-post-creator' ) );
			}
			return array( 'url' => $link );
		}

		return new WP_Error( 'aipc_image', __( 'The chat route returned no image — the model may not support image output.', 'wp-ai-post-creator' ) );
	}

	/**
	 * Decode a base64 image payload defensively.
	 *
	 * Accepts plain base64, data: URIs ("data:image/png;base64,…") and
	 * payloads with embedded whitespace/newlines. Strict decoding first,
	 * lenient as a fallback.
	 *
	 * @param string $data Raw payload.
	 * @return string|false Binary image bytes, or false when not decodable.
	 */
	public static function decode_b64_image( $data ) {
		$data = trim( (string) $data );
		if ( '' === $data ) {
			return false;
		}
		if ( 0 === strpos( $data, 'data:' ) ) {
			$comma = strpos( $data, ',' );
			if ( false === $comma ) {
				return false;
			}
			$data = substr( $data, $comma + 1 );
		}
		$clean = preg_replace( '/\s+/', '', $data );
		$bits  = base64_decode( $clean, true );
		if ( false === $bits || '' === $bits ) {
			$bits = base64_decode( $clean );
		}
		return ( is_string( $bits ) && '' !== $bits ) ? $bits : false;
	}

	/**
	 * Download an image from a URL (for providers returning URLs).
	 *
	 * @param string $url Image URL.
	 * @return string|WP_Error Raw bytes.
	 */
	public function download( $url ) {
		if ( AIPC_Trace::enabled() ) {
			AIPC_Trace::log( 'download', array(
				'connection' => isset( $this->conn['name'] ) ? (string) $this->conn['name'] : '',
				'url'        => (string) $url,
			) );
		}
		// SSRF guard: providers can return arbitrary URLs.
		if ( ! AIPC_Network::is_safe_url( $url ) ) {
			return new WP_Error( 'aipc_image', __( 'The image URL was rejected by the outbound network guard.', 'wp-ai-post-creator' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'user-agent'  => 'wp-ai-post-creator/' . AIPC_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$bits = wp_remote_retrieve_body( $response );
		if ( '' === $bits ) {
			return new WP_Error( 'aipc_image', __( 'Could not download the generated image.', 'wp-ai-post-creator' ) );
		}
		return $bits;
	}

	/**
	 * Quick connection test used by the settings screen.
	 *
	 * @return array|WP_Error {ok, models}
	 */
	public function test() {
		$models = $this->models();
		if ( is_wp_error( $models ) ) {
			// Some compatible providers don't implement /models — fall back to a tiny chat call.
			$res = $this->chat(
				array( array( 'role' => 'user', 'content' => 'Reply with the single word: OK' ) ),
				array( 'max_tokens' => 10, 'temperature' => 0 )
			);
			if ( is_wp_error( $res ) ) {
				// Common mistake: the base URL misses its /v1 suffix
				// (e.g. https://openrouter.ai/api). Probe the variant once.
				$fixed = $this->probe_v1_variant();
				if ( is_array( $fixed ) ) {
					return $fixed;
				}
				return $models; // The /models error is more informative.
			}
			return $this->with_fixed_base( array( 'ok' => true, 'models' => null, 'chat' => true ) );
		}
		return $this->with_fixed_base( array( 'ok' => true, 'models' => $models ) );
	}

	/**
	 * Add 'fixed_base_url' to a successful test result when normalization
	 * changed what the user typed (pasted endpoint path, missing /v1 …),
	 * so the settings screen can correct the field.
	 *
	 * @param array $out Successful test result.
	 * @return array
	 */
	private function with_fixed_base( $out ) {
		$raw        = rtrim( trim( (string) $this->conn['base_url'] ), '/' );
		$normalized = $this->base_url();
		if ( '' !== $raw && $raw !== $normalized ) {
			$out['fixed_base_url'] = $normalized;
		}
		return $out;
	}

	/**
	 * Probe the same connection with "/v1" appended to the base URL.
	 *
	 * @return array|null Test result with 'fixed_base_url' when the variant works.
	 */
	private function probe_v1_variant() {
		$base = $this->base_url();
		if ( '' === $base || '/v1' === substr( $base, -3 ) ) {
			return null;
		}
		$alt             = $this->conn;
		$alt['base_url'] = $base . '/v1';
		$probe           = new self( $alt );
		$models          = $probe->models();
		if ( is_wp_error( $models ) ) {
			return null;
		}
		return array( 'ok' => true, 'models' => $models, 'fixed_base_url' => $probe->base_url() );
	}

	/**
	 * Robustly extract the first JSON object from a model response.
	 *
	 * @param string $raw Model output (may contain fences or chatter).
	 * @return array|null
	 */
	public static function extract_json( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$s = trim( $raw );

		// Prefer fenced ```json blocks.
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/s', $s, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			$s = $m[1];
		}

		$first = strpos( $s, '{' );
		if ( false === $first ) {
			$decoded = json_decode( $s, true ); // Maybe a top-level array.
			return is_array( $decoded ) ? $decoded : null;
		}

		// Scan for a balanced object, honoring strings/escapes.
		$depth   = 0;
		$in_str  = false;
		$esc     = false;
		$len     = strlen( $s );
		for ( $i = $first; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( $in_str ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( '\\' === $c ) {
					$esc = true;
				} elseif ( '"' === $c ) {
					$in_str = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in_str = true;
			} elseif ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					$cand    = substr( $s, $first, $i - $first + 1 );
					$decoded = json_decode( $cand, true );
					if ( is_array( $decoded ) ) {
						return $decoded;
					}
				}
			}
		}

		$decoded = json_decode( $s, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
