<?php
namespace MediaUsageTracker\Admin;

/**
 * AI-powered alt text generator.
 *
 * Supports multiple providers selectable from Settings.
 * Currently: Google Gemini, Anthropic Claude.
 */
class AltTextGenerator {

	const OPT_PROVIDER       = 'mut_ai_provider';
	const OPT_GEMINI_KEY     = 'mut_gemini_api_key';
	const OPT_ANTHROPIC_KEY  = 'mut_anthropic_api_key';
	const OPT_GROQ_KEY       = 'mut_groq_api_key';

	// Back-compat alias used by is_configured() callers and register_settings().
	const OPT_API_KEY        = 'mut_gemini_api_key';

	const GEMINI_ENDPOINT    = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
	const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const ANTHROPIC_MODEL    = 'claude-haiku-4-5-20251001';
	const GROQ_ENDPOINT      = 'https://api.groq.com/openai/v1/chat/completions';
	const GROQ_MODEL         = 'meta-llama/llama-4-scout-17b-16e-instruct';

	const PROVIDERS = array(
		'gemini'    => 'Google Gemini (Free tier available)',
		'anthropic' => 'Anthropic Claude',
		'groq'      => 'Groq (Free tier available)',
	);

	// -------------------------------------------------------------------------

	public static function get_provider() {
		return get_option( self::OPT_PROVIDER, 'gemini' );
	}

	public static function is_configured() {
		$provider = self::get_provider();
		$map = array(
			'anthropic' => self::OPT_ANTHROPIC_KEY,
			'groq'      => self::OPT_GROQ_KEY,
			'gemini'    => self::OPT_GEMINI_KEY,
		);
		$key_opt = $map[ $provider ] ?? self::OPT_GEMINI_KEY;
		return trim( (string) get_option( $key_opt, '' ) ) !== '';
	}

	// -------------------------------------------------------------------------

	public function generate( $attachment_id, $type = 'alt_text' ) {
		$provider = self::get_provider();
		$prompt   = $type === 'caption'
			? 'Write a short, engaging image caption suitable for display below a photo on a website. 1-2 sentences max, plain text, no hashtags, no quotes.'
			: 'Write a concise, descriptive alt text for this image in 10 words or fewer. Be specific about what is shown. Output only the alt text, no quotes, no punctuation at the end.';
		if ( $provider === 'anthropic' ) {
			return $this->generate_anthropic( $attachment_id, $prompt );
		}
		if ( $provider === 'groq' ) {
			return $this->generate_groq( $attachment_id, $prompt );
		}
		return $this->generate_gemini( $attachment_id, $prompt );
	}

	// -------------------------------------------------------------------------
	// Gemini
	// -------------------------------------------------------------------------

	private function generate_gemini( $attachment_id, $prompt = null ) {
		if ( $prompt === null ) {
			$prompt = 'Write a concise, descriptive alt text for this image in 10 words or fewer. Be specific about what is shown. Output only the alt text, no quotes, no punctuation at the end.';
		}
		$api_key = trim( (string) get_option( self::OPT_GEMINI_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Google Gemini API key not configured.' );
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return array( 'ok' => false, 'error' => 'Attachment URL not found.' );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( strpos( (string) $mime, 'image/' ) !== 0 ) {
			return array( 'ok' => false, 'error' => 'Not an image.' );
		}

		$img_response = wp_remote_get( $image_url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $img_response ) ) {
			return array( 'ok' => false, 'error' => 'Could not fetch image: ' . $img_response->get_error_message() );
		}
		$img_body = wp_remote_retrieve_body( $img_response );
		if ( $img_body === '' ) {
			return array( 'ok' => false, 'error' => 'Empty image response.' );
		}

		$body = wp_json_encode( array(
			'contents' => array(
				array(
					'parts' => array(
						array(
							'inline_data' => array(
								'mime_type' => $this->normalize_mime( $mime ),
								'data'      => base64_encode( $img_body ),
							),
						),
						array(
							'text' => $prompt,
						),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 60,
				'temperature'     => 0.2,
			),
		) );

		$endpoint = add_query_arg( 'key', $api_key, self::GEMINI_ENDPOINT );
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? 'Gemini API error ' . $code;
			return array( 'ok' => false, 'error' => $msg );
		}

		$text = trim( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' );
		if ( $text === '' ) {
			return array( 'ok' => false, 'error' => 'Empty response from Gemini.' );
		}

		return array( 'ok' => true, 'text' => $text );
	}

	// -------------------------------------------------------------------------
	// Anthropic Claude
	// -------------------------------------------------------------------------

	private function generate_anthropic( $attachment_id, $prompt = null ) {
		if ( $prompt === null ) {
			$prompt = 'Write a concise, descriptive alt text for this image in 10 words or fewer. Be specific about what is shown. Output only the alt text, no quotes, no punctuation at the end.';
		}
		$api_key = trim( (string) get_option( self::OPT_ANTHROPIC_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Anthropic API key not configured.' );
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return array( 'ok' => false, 'error' => 'Attachment URL not found.' );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( strpos( (string) $mime, 'image/' ) !== 0 ) {
			return array( 'ok' => false, 'error' => 'Not an image.' );
		}

		$media_type = $this->normalize_mime_anthropic( $mime );
		if ( ! $media_type ) {
			return array( 'ok' => false, 'error' => 'Unsupported image type for Anthropic: ' . $mime );
		}

		$body = wp_json_encode( array(
			'model'      => self::ANTHROPIC_MODEL,
			'max_tokens' => 150,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type' => 'url',
								'url'  => $image_url,
							),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		) );

		$response = wp_remote_post( self::ANTHROPIC_ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body' => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? 'Anthropic API error ' . $code;
			return array( 'ok' => false, 'error' => $msg );
		}

		$text = trim( $data['content'][0]['text'] ?? '' );
		if ( $text === '' ) {
			return array( 'ok' => false, 'error' => 'Empty response from Anthropic.' );
		}

		return array( 'ok' => true, 'text' => $text );
	}

	// -------------------------------------------------------------------------
	// Groq
	// -------------------------------------------------------------------------

	private function generate_groq( $attachment_id, $prompt = null ) {
		if ( $prompt === null ) {
			$prompt = 'Write a concise, descriptive alt text for this image in 10 words or fewer. Be specific about what is shown. Output only the alt text, no quotes, no punctuation at the end.';
		}
		$api_key = trim( (string) get_option( self::OPT_GROQ_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Groq API key not configured.' );
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return array( 'ok' => false, 'error' => 'Attachment URL not found.' );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( strpos( (string) $mime, 'image/' ) !== 0 ) {
			return array( 'ok' => false, 'error' => 'Not an image.' );
		}

		// Groq can't reach localhost URLs — fetch locally and send as base64 data URI.
		$img_response = wp_remote_get( $image_url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $img_response ) ) {
			return array( 'ok' => false, 'error' => 'Could not fetch image: ' . $img_response->get_error_message() );
		}
		$img_body = wp_remote_retrieve_body( $img_response );
		if ( $img_body === '' ) {
			return array( 'ok' => false, 'error' => 'Empty image response.' );
		}
		$data_uri = 'data:' . $this->normalize_mime( $mime ) . ';base64,' . base64_encode( $img_body );

		$body = wp_json_encode( array(
			'model'      => self::GROQ_MODEL,
			'max_tokens' => 100,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => $data_uri ),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		) );

		$response = wp_remote_post( self::GROQ_ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? 'Groq API error ' . $code;
			return array( 'ok' => false, 'error' => $msg );
		}

		$text = trim( $data['choices'][0]['message']['content'] ?? '' );
		if ( $text === '' ) {
			return array( 'ok' => false, 'error' => 'Empty response from Groq.' );
		}

		return array( 'ok' => true, 'text' => $text );
	}

	// -------------------------------------------------------------------------
	// Save / AJAX handlers
	// -------------------------------------------------------------------------

	public function save( $attachment_id, $alt_text ) {
		$alt_text = sanitize_text_field( $alt_text );
		if ( $alt_text === '' || ! get_post( $attachment_id ) ) {
			return false;
		}
		return (bool) update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	}

	public function handle_generate() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$attachment_id = absint( $_POST['attachment_id'] ?? $_POST['id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}
		$type   = in_array( $_POST['type'] ?? '', array( 'alt_text', 'caption' ), true ) ? $_POST['type'] : 'alt_text';
		$result = $this->generate( $attachment_id, $type );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'id' => $attachment_id, 'alt_text' => $result['text'], 'text' => $result['text'] ) );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	public function handle_nl_search() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		if ( $query === '' ) {
			wp_send_json_error( 'Empty query.' );
		}

		$system = 'You are a media library filter assistant for a WordPress plugin. Parse the user\'s natural language search query into filter parameters. Return ONLY a valid JSON object with these exact keys:
- "usage_status": one of "used", "unused", or "" (empty = all)
- "media_type": one of "image", "application/pdf", "video", "audio", or "" (empty = all)
- "date_range": one of "today", "7days", "30days", "90days", "1year", or "" (empty = any date)
- "size": one of "large" (files ≥ 2MB), "small" (files < 100KB), or "" (empty = any size)
- "s": a filename/title keyword string, or "" (empty = no keyword search)
- "source": the plugin/theme key if the user mentions a specific plugin or theme, or "" (empty = all sources). Valid values: "acf", "elementor", "woocommerce", "yoast", "divi", "wpbakery", "beaver_builder", "avada", "jetengine", "jetpopup", "gravityforms", "astra". Examples: "acf images" → "acf", "used by elementor" → "elementor", "gravity forms" → "gravityforms", "beaver builder" → "beaver_builder".
Return only the JSON object, no explanation, no markdown, no extra text.';

		$user_prompt = 'Parse this search query into filter parameters: ' . $query;

		$provider = self::get_provider();
		$result   = null;

		if ( $provider === 'groq' ) {
			$result = $this->call_text_groq( $system, $user_prompt );
		} elseif ( $provider === 'anthropic' ) {
			$result = $this->call_text_anthropic( $system, $user_prompt );
		} else {
			$result = $this->call_text_gemini( $system, $user_prompt );
		}

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] );
		}

		$raw    = trim( $result['text'] );
		$raw    = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw    = preg_replace( '/\s*```$/', '', $raw );
		$params = json_decode( $raw, true );

		if ( ! is_array( $params ) ) {
			wp_send_json_error( 'AI returned unparseable response: ' . $raw );
		}

		$allowed_status     = array( 'used', 'unused', '' );
		$allowed_type       = array( 'image', 'application/pdf', 'video', 'audio', '' );
		$allowed_date_range = array( 'today', '7days', '30days', '90days', '1year', '' );
		$allowed_size       = array( 'large', 'small', '' );
		$allowed_source     = array( 'acf', 'elementor', 'woocommerce', 'yoast', 'divi', 'wpbakery', 'beaver_builder', 'avada', 'jetengine', 'jetpopup', 'gravityforms', 'astra', '' );

		$filters = array(
			'usage_status' => in_array( $params['usage_status'] ?? '', $allowed_status, true )     ? ( $params['usage_status'] ?? '' ) : '',
			'media_type'   => in_array( $params['media_type']   ?? '', $allowed_type, true )        ? ( $params['media_type']   ?? '' ) : '',
			'date_range'   => in_array( $params['date_range']   ?? '', $allowed_date_range, true )  ? ( $params['date_range']   ?? '' ) : '',
			'size'         => in_array( $params['size']         ?? '', $allowed_size, true )         ? ( $params['size']         ?? '' ) : '',
			's'            => sanitize_text_field( $params['s'] ?? '' ),
			'source'       => in_array( $params['source']       ?? '', $allowed_source, true )       ? ( $params['source']       ?? '' ) : '',
		);

		wp_send_json_success( $filters );
	}

	private function call_text_gemini( $system, $user_prompt ) {
		$api_key = trim( (string) get_option( self::OPT_GEMINI_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Gemini API key not configured.' );
		}
		$body = wp_json_encode( array(
			'contents'         => array( array( 'parts' => array( array( 'text' => $system . "\n\n" . $user_prompt ) ) ) ),
			'generationConfig' => array( 'maxOutputTokens' => 120, 'temperature' => 0 ),
		) );
		$response = wp_remote_post( add_query_arg( 'key', $api_key, self::GEMINI_ENDPOINT ), array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'error' => $response->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) { return array( 'ok' => false, 'error' => $data['error']['message'] ?? 'Gemini error ' . $code ); }
		return array( 'ok' => true, 'text' => trim( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' ) );
	}

	private function call_text_anthropic( $system, $user_prompt ) {
		$api_key = trim( (string) get_option( self::OPT_ANTHROPIC_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Anthropic API key not configured.' );
		}
		$body = wp_json_encode( array(
			'model'      => self::ANTHROPIC_MODEL,
			'max_tokens' => 120,
			'system'     => $system,
			'messages'   => array( array( 'role' => 'user', 'content' => $user_prompt ) ),
		) );
		$response = wp_remote_post( self::ANTHROPIC_ENDPOINT, array(
			'timeout' => 20,
			'headers' => array( 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'error' => $response->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) { return array( 'ok' => false, 'error' => $data['error']['message'] ?? 'Anthropic error ' . $code ); }
		return array( 'ok' => true, 'text' => trim( $data['content'][0]['text'] ?? '' ) );
	}

	private function call_text_groq( $system, $user_prompt ) {
		$api_key = trim( (string) get_option( self::OPT_GROQ_KEY, '' ) );
		if ( $api_key === '' ) {
			return array( 'ok' => false, 'error' => 'Groq API key not configured.' );
		}
		$body = wp_json_encode( array(
			'model'      => self::GROQ_MODEL,
			'max_tokens' => 120,
			'messages'   => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user',   'content' => $user_prompt ),
			),
		) );
		$response = wp_remote_post( self::GROQ_ENDPOINT, array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) { return array( 'ok' => false, 'error' => $response->get_error_message() ); }
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) { return array( 'ok' => false, 'error' => $data['error']['message'] ?? 'Groq error ' . $code ); }
		return array( 'ok' => true, 'text' => trim( $data['choices'][0]['message']['content'] ?? '' ) );
	}

	public function handle_generate_caption() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}
		$result = $this->generate( $attachment_id, 'caption' );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'id' => $attachment_id, 'text' => $result['text'] ) );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	public function save_caption( $attachment_id, $caption ) {
		$caption = sanitize_textarea_field( $caption );
		if ( $caption === '' || ! get_post( $attachment_id ) ) {
			return false;
		}
		return (bool) wp_update_post( array( 'ID' => $attachment_id, 'post_excerpt' => $caption ) );
	}

	public function handle_save_caption() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$caption       = sanitize_textarea_field( wp_unslash( $_POST['caption'] ?? '' ) );
		if ( ! $attachment_id || $caption === '' ) {
			wp_send_json_error( 'Missing attachment ID or caption.' );
		}
		$saved = $this->save_caption( $attachment_id, $caption );
		if ( $saved ) {
			wp_send_json_success( array( 'id' => $attachment_id ) );
		} else {
			wp_send_json_error( 'Failed to save.' );
		}
	}

	public function handle_save() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$alt_text      = sanitize_text_field( wp_unslash( $_POST['alt_text'] ?? '' ) );
		if ( ! $attachment_id || $alt_text === '' ) {
			wp_send_json_error( 'Missing attachment ID or alt text.' );
		}
		$saved = $this->save( $attachment_id, $alt_text );
		if ( $saved ) {
			wp_send_json_success( array( 'id' => $attachment_id ) );
		} else {
			wp_send_json_error( 'Failed to save.' );
		}
	}

	public function handle_mark_decorative() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$decorative    = ! empty( $_POST['decorative'] ) && $_POST['decorative'] !== '0';
		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			wp_send_json_error( 'Invalid attachment.' );
		}
		if ( $decorative ) {
			update_post_meta( $attachment_id, '_mut_decorative', 1 );
		} else {
			delete_post_meta( $attachment_id, '_mut_decorative' );
		}
		delete_transient( 'mut_quality_audit' );
		wp_send_json_success( array( 'id' => $attachment_id, 'decorative' => $decorative ) );
	}

	// -------------------------------------------------------------------------

	private function normalize_mime( $mime ) {
		$map = array(
			'image/jpeg' => 'image/jpeg',
			'image/jpg'  => 'image/jpeg',
			'image/png'  => 'image/png',
			'image/gif'  => 'image/gif',
			'image/webp' => 'image/webp',
			'image/bmp'  => 'image/bmp',
			'image/tiff' => 'image/tiff',
		);
		return $map[ strtolower( (string) $mime ) ] ?? 'image/jpeg';
	}

	private function normalize_mime_anthropic( $mime ) {
		$map = array(
			'image/jpeg' => 'image/jpeg',
			'image/jpg'  => 'image/jpeg',
			'image/png'  => 'image/png',
			'image/gif'  => 'image/gif',
			'image/webp' => 'image/webp',
		);
		return $map[ strtolower( (string) $mime ) ] ?? null;
	}
}
