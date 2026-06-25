<?php
namespace MediaUsageTracker\Scanner;

/**
 * Shared trait for extracting media URLs from CSS text (custom_css fields, inline styles, etc.).
 * Any detector can use this to find background-image url() references in CSS strings.
 */
trait CssUrlScanner {

	/**
	 * Extract url() references from CSS text and resolve to attachment IDs.
	 *
	 * @param string $css  Raw CSS text.
	 * @param int[]  $ids  Accumulator (by reference).
	 */
	protected function extract_css_urls( $css, &$ids ) {
		if ( preg_match_all( '/url\(\s*["\']?(https?:\/\/[^"\')\s]+)["\']?\s*\)/i', $css, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				if ( strpos( $url, 'wp-content/uploads' ) === false ) {
					continue;
				}
				$id = absint( attachment_url_to_postid( $url ) );
				if ( $id === 0 ) {
					$unscaled = preg_replace( '/-scaled(\.\w+)$/', '$1', $url );
					if ( $unscaled !== $url ) {
						$id = absint( attachment_url_to_postid( $unscaled ) );
					}
				}
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
	}

	/**
	 * Scan a raw string (post_content, serialized meta, JSON blob) for any
	 * CSS url() references pointing to wp-content/uploads.
	 *
	 * @param string $text  Any text that might contain CSS.
	 * @param int[]  $ids   Accumulator (by reference).
	 */
	protected function scan_text_for_css_urls( $text, &$ids ) {
		if ( strpos( $text, 'url(' ) !== false && strpos( $text, 'wp-content/uploads' ) !== false ) {
			$this->extract_css_urls( $text, $ids );
		}
	}
}
