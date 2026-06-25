<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced in a post's post_content, via two methods:
 *   1. ID-pattern regex (Gutenberg/blocks/shortcodes)
 *   2. URL-based detection (resolves media URLs to attachment IDs)
 *
 * Behavior is identical to the original MediaScanner::scan_post_content().
 */
class ContentDetector implements MediaDetector {

	use CssUrlScanner;

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'content';
	}

	public function is_available() {
		return true;
	}

	public function detect( $post, $scan_id ) {
		$content = $post->post_content;

		// 1. ID-based patterns (Gutenberg, blocks, shortcodes)
		$patterns = array(
			'/wp-image-(\d+)/i',
			'/attachment_id["\']?\s*[:=]\s*["\']?(\d+)/i',
			'/"id":\s*(\d+)[,}]/i',
			'/data-id=["\'](\d+)["\']/i',
		);

		$found_ids = array();

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				$found_ids = array_merge( $found_ids, $matches[1] );
			}
		}

		// 2. URL-based detection - much more reliable for most sites
		global $wpdb;
		$upload_dir = wp_upload_dir();
		$upload_base = str_replace( home_url(), '', $upload_dir['baseurl'] );
		$upload_base = trim( $upload_base, '/' );

		// Find all media URLs in content
		$url_pattern = '#https?://[^"\']+?\.(jpg|jpeg|png|gif|webp|pdf|mp4)[\?"]?#i';
		if ( preg_match_all( $url_pattern, $content, $url_matches ) ) {
			foreach ( $url_matches[0] as $url ) {
				// Extract filename
				if ( preg_match( '#/([^/]+\.(?:jpg|jpeg|png|gif|webp|pdf|mp4))#i', $url, $filename_match ) ) {
					$filename = $filename_match[1];

					// Find attachment ID by filename or guid
					$attachment_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_type = 'attachment'
						 AND (guid LIKE %s OR post_name LIKE %s OR post_title LIKE %s)
						 LIMIT 1",
						'%' . $wpdb->esc_like( $filename ) . '%',
						'%' . $wpdb->esc_like( pathinfo($filename, PATHINFO_FILENAME) ) . '%',
						'%' . $wpdb->esc_like( pathinfo($filename, PATHINFO_FILENAME) ) . '%'
					) );

					if ( $attachment_id ) {
						$found_ids[] = $attachment_id;
					}
				}
			}
		}

		// CSS url() references (inline styles, custom CSS blocks)
		$this->scan_text_for_css_urls( $content, $found_ids );

		// Deduplicate and record content IDs
		$content_ids = array_unique( array_map( 'absint', $found_ids ) );
		foreach ( $content_ids as $id ) {
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				$context = substr( wp_trim_words( strip_tags( $content ), 30 ), 0, 200 );
				$this->storage->record_usage( array(
					'attachment_id' => $id,
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'usage_type'    => 'content',
					'context'       => $context,
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}
		}

		// Gallery shortcode: [gallery ids="1,2,3"] or [gallery include="1,2,3"]
		$gallery_ids = array();
		if ( has_shortcode( $content, 'gallery' ) ) {
			if ( preg_match_all( '/\[gallery[^\]]+(?:ids|include)=["\']?([\d,\s]+)["\']?/i', $content, $gmatches ) ) {
				foreach ( $gmatches[1] as $id_list ) {
					foreach ( array_filter( array_map( 'absint', explode( ',', $id_list ) ) ) as $gid ) {
						$gallery_ids[] = $gid;
					}
				}
			}
		}

		// Gutenberg wp:gallery block
		if ( strpos( $content, 'wp:gallery' ) !== false ) {
			if ( preg_match_all( '/<!--\s*wp:gallery[^-]*-->(.*?)<!--\s*\/wp:gallery\s*-->/s', $content, $block_matches ) ) {
				foreach ( $block_matches[1] as $block_content ) {
					if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $block_content, $bid_matches ) ) {
						foreach ( $bid_matches[1] as $bid ) {
							$gallery_ids[] = absint( $bid );
						}
					}
				}
			}
		}

		foreach ( array_unique( $gallery_ids ) as $id ) {
			if ( $id > 0 ) {
				$this->storage->record_usage( array(
					'attachment_id' => $id,
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'usage_type'    => 'gallery',
					'context'       => 'Gallery',
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}
		}

		// Video poster: [video poster="url"] shortcode
		$video_urls = array();
		if ( has_shortcode( $content, 'video' ) ) {
			if ( preg_match_all( '/\[video[^\]]+poster=["\']([^"\']+)["\']/', $content, $vmatches ) ) {
				foreach ( $vmatches[1] as $url ) {
					$video_urls[] = $url;
				}
			}
		}

		// Gutenberg video block: "poster":"url"
		if ( strpos( $content, 'wp:video' ) !== false ) {
			if ( preg_match_all( '/"poster"\s*:\s*"([^"]+)"/', $content, $vbmatches ) ) {
				foreach ( $vbmatches[1] as $url ) {
					$video_urls[] = $url;
				}
			}
		}

		foreach ( array_unique( $video_urls ) as $url ) {
			$id = absint( attachment_url_to_postid( $url ) );
			if ( $id > 0 ) {
				$this->storage->record_usage( array(
					'attachment_id' => $id,
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'usage_type'    => 'video_poster',
					'context'       => 'Video Poster',
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}
		}

		return $recorded;
	}
}
