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
 */
final class AIPC_API_Client {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Optional settings override (tests).
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings ? $settings : AIPC_Settings::all();
	}

	/**
	 * Whether the client has enough configuration to work.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->settings['api_key'] ) && ! empty( $this->settings['api_base_url'] );
	}

	/**
	 * Normalized base URL (adds /v1 for a bare api.openai.com host).
	 *
	 * @return string
	 */
	public function base_url() {
		$base = rtrim( trim( (string) $this->settings['api_base_url'] ), '/' );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		if ( 'api.openai.com' === $host && ( '' === $path || '/' === $path ) ) {
			$base .= '/v1';
		}
		return $base;
	}

	/**
	 * Perform an HTTP request against the provider.
	 *
	 * @param string      $path    API path, e.g. "/chat/completions".
	 * @param array|null  $body    JSON body (null = GET).
	 * @param int|null    $timeout Optional timeout override.
	 * @param int         $attempt Internal retry counter.
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	private function request( $path, $body = null, $timeout = null, $attempt = 0 ) {
		$url  = $this->base_url() . $path;
		$args = array(
			'method'  => null === $body ? 'GET' : 'POST',
			'timeout' => $timeout ? (int) $timeout : max( 15, (int) $this->settings['request_timeout'] ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->settings['api_key'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'user-agent' => 'wp-ai-post-creator/' . AIPC_VERSION . ' (WordPress)',
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( 0 === $attempt && false !== stripos( $message, 'timed out' ) ) {
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
		return mb_substr( trim( wp_strip_all_tags( $raw ) ), 0, 300 );
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
			'model'    => ! empty( $opts['model'] ) ? $opts['model'] : $this->settings['chat_model'],
			'messages' => array_values( $messages ),
		);

		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = (float) $opts['temperature'];
		} else {
			$body['temperature'] = (float) $this->settings['temperature'];
		}

		$max_tokens = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : (int) $this->settings['max_tokens'];
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
			);
		}

		return array(
			'data'  => $data,
			'usage' => isset( $res['usage'] ) ? $res['usage'] : array(),
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

		$model = ! empty( $opts['model'] ) ? $opts['model'] : $this->settings['image_model'];
		$size  = ! empty( $opts['size'] ) ? $opts['size'] : $this->settings['image_size'];

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

		$json = $this->request( '/images/generations', $body );

		if ( is_wp_error( $json ) ) {
			$msg = $json->get_error_message();
			// Compatibility retries for providers with different parameter rules.
			if ( false !== stripos( $msg, 'response_format' ) ) {
				unset( $body['response_format'] );
				$json = $this->request( '/images/generations', $body );
			} elseif ( false !== stripos( $msg, 'size' ) && isset( $body['size'] ) ) {
				unset( $body['size'] );
				$json = $this->request( '/images/generations', $body );
			}
			if ( is_wp_error( $json ) ) {
				return $json;
			}
		}

		$item = isset( $json['data'][0] ) && is_array( $json['data'][0] ) ? $json['data'][0] : array();

		if ( ! empty( $item['b64_json'] ) ) {
			$bits = base64_decode( (string) $item['b64_json'] );
			if ( $bits ) {
				return array( 'bits' => $bits );
			}
		}

		if ( ! empty( $item['url'] ) ) {
			return array( 'url' => (string) $item['url'] );
		}

		return new WP_Error( 'aipc_image', __( 'The provider returned no image data.', 'wp-ai-post-creator' ) );
	}

	/**
	 * Download an image from a URL (for providers returning URLs).
	 *
	 * @param string $url Image URL.
	 * @return string|WP_Error Raw bytes.
	 */
	public function download( $url ) {
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
				return $models; // The /models error is more informative.
			}
			return array( 'ok' => true, 'models' => null, 'chat' => true );
		}
		return array( 'ok' => true, 'models' => $models );
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
