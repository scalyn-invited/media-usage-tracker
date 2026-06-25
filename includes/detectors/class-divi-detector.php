<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by the Divi Builder.
 *
 * Supports two storage formats:
 *
 * Divi 4 and earlier: [et_pb_*] shortcodes in post_content.
 *   - gallery_ids="12,15,20"  (explicit IDs)
 *   - src="URL", image="URL"  (URLs resolved to IDs)
 *
 * Divi 5+: Gutenberg block comments with JSON payloads.
 *   - <!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"...","id":"19"}}}}} /-->
 *   - <!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<img class=\"wp-image-11\" ...>"}}}} /-->
 *
 * Self-gates: only runs when Divi (theme or builder plugin) is active.
 */
class DiviDetector implements MediaDetector {

	use CssUrlScanner;

	/** Divi 4 shortcode attributes that hold a media URL. */
	const URL_ATTRS = array(
		'src', 'image_url', 'background_image', 'logo', 'image',
		'header_image', 'url', 'video_webm', 'video_mp4', 'src_webp',
	);

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'divi';
	}

	public function is_available() {
		return defined( 'ET_BUILDER_VERSION' )
			|| defined( 'ET_BUILDER_PLUGIN_VERSION' )
			|| function_exists( 'et_setup_theme' );
	}

	public function detect( $post, $scan_id ) {
		$content = (string) $post->post_content;
		if ( $content === '' ) {
			return 0;
		}

		$found_ids = array();

		// ── Divi 5: Gutenberg block comments ────────────────────────────────────
		if ( strpos( $content, 'wp:divi/' ) !== false ) {
			$this->extract_from_divi5_blocks( $content, $found_ids );
		}

		// ── Divi 4: [et_pb_*] shortcodes ────────────────────────────────────────
		if ( strpos( $content, 'et_pb_' ) !== false ) {
			$this->extract_from_shortcodes( $content, $found_ids );
		}

		// CSS url() references in custom CSS fields
		$this->scan_text_for_css_urls( $content, $found_ids );

		$found_ids = array_unique( array_filter( $found_ids ) );

		$recorded = 0;
		foreach ( $found_ids as $id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'divi',
				'context'       => 'Divi Builder',
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Extract attachment IDs from Divi 5 Gutenberg block JSON payloads.
	 */
	private function extract_from_divi5_blocks( $content, &$found_ids ) {
		// Match all <!-- wp:divi/... JSON ... /--> and <!-- wp:divi/... JSON ... --> blocks.
		if ( ! preg_match_all( '/<!--\s*wp:divi\/\S+\s+(\{.*?\})\s*(?:\/)?-->/s', $content, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $json_str ) {
			$data = json_decode( $json_str, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$this->walk_divi5_json( $data, $found_ids );
		}
	}

	/**
	 * Recursively walk a Divi 5 JSON structure collecting attachment IDs.
	 */
	private function walk_divi5_json( $node, &$found_ids ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			// Explicit attachment ID fields.
			if ( $key === 'id' && is_numeric( $value ) ) {
				$id = absint( $value );
				if ( $id > 0 && get_post_type( $id ) === 'attachment' ) {
					$found_ids[] = $id;
				}
				continue;
			}

			// URL fields — resolve to attachment ID.
			if ( $key === 'src' && is_string( $value ) && strpos( $value, 'http' ) === 0 ) {
				$id = $this->url_to_id( $value );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
				continue;
			}

			// HTML content inside text/code modules — extract wp-image-{ID} classes and src URLs.
			if ( $key === 'value' && is_string( $value ) && strpos( $value, '<img' ) !== false ) {
				$this->extract_from_html( $value, $found_ids );
				continue;
			}

			if ( is_array( $value ) ) {
				$this->walk_divi5_json( $value, $found_ids );
			}
		}
	}

	/**
	 * Extract attachment IDs from HTML string (wp-image-{ID} class or src URLs).
	 */
	private function extract_from_html( $html, &$found_ids ) {
		// wp-image-{ID} class — most reliable.
		if ( preg_match_all( '/wp-image-(\d+)/', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
			}
		}

		// Fallback: src URLs.
		if ( preg_match_all( '/src=["\']([^"\']+)["\']/', $html, $m ) ) {
			foreach ( $m[1] as $url ) {
				if ( strpos( $url, 'http' ) !== 0 ) {
					continue;
				}
				$id = $this->url_to_id( $url );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
			}
		}
	}

	/**
	 * Extract attachment IDs from Divi 4 [et_pb_*] shortcodes.
	 */
	private function extract_from_shortcodes( $content, &$found_ids ) {
		// Gallery modules — explicit comma-separated IDs.
		if ( preg_match_all( '/gallery_ids="([^"]+)"/i', $content, $gm ) ) {
			foreach ( $gm[1] as $list ) {
				foreach ( preg_split( '/\s*,\s*/', $list ) as $raw_id ) {
					$id = absint( $raw_id );
					if ( $id > 0 ) {
						$found_ids[] = $id;
					}
				}
			}
		}

		// Numeric *_id attributes.
		if ( preg_match_all( '/\b(?:image_id|logo_id|src_id)="(\d+)"/i', $content, $im ) ) {
			foreach ( $im[1] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
			}
		}

		// URL-bearing attributes.
		$attr_alt = implode( '|', array_map( 'preg_quote', self::URL_ATTRS ) );
		if ( preg_match_all( '/\b(?:' . $attr_alt . ')="([^"]+)"/i', $content, $um ) ) {
			foreach ( $um[1] as $url ) {
				$url = trim( $url );
				if ( $url === '' ) {
					continue;
				}
				$id = $this->url_to_id( $url );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
			}
		}
	}

	/**
	 * Resolve a URL to an attachment ID, with -scaled suffix fallback.
	 */
	private function url_to_id( $url ) {
		$id = absint( attachment_url_to_postid( $url ) );
		if ( $id === 0 ) {
			$unscaled = preg_replace( '/-scaled(\.\w+)$/', '$1', $url );
			if ( $unscaled !== $url ) {
				$id = absint( attachment_url_to_postid( $unscaled ) );
			}
		}
		return $id;
	}
}
