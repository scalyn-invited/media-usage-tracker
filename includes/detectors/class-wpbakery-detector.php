<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by WPBakery Page Builder (formerly Visual Composer).
 *
 * WPBakery stores its layout as [vc_*] shortcodes inside post_content. Media is
 * referenced via:
 *   - image="123"            single attachment ID (vc_single_image, etc.)
 *   - images="1,2,3"         comma-separated IDs (vc_gallery, image carousels)
 *   - *_image_id="123"       background / various ID attributes
 *   - source/url="…url…"     URLs resolved to attachment IDs
 *
 * Records non-image attachments too.
 *
 * Self-gates: only runs when WPBakery is active.
 */
class WpBakeryDetector implements MediaDetector {

	use CssUrlScanner;

	/** Attributes carrying a single numeric attachment ID. */
	const ID_ATTRS = array( 'image', 'image_id', 'source_image', 'bg_image_id', 'thumbnail_id', 'overlay_image' );

	/** Attributes carrying a comma-separated list of attachment IDs. */
	const IDS_ATTRS = array( 'images', 'include', 'image_ids', 'gallery' );

	/** Attributes carrying a media URL. */
	const URL_ATTRS = array( 'source', 'url', 'image_url', 'background_image' );

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'wpbakery';
	}

	public function is_available() {
		return defined( 'WPB_VC_VERSION' ) || defined( 'VC_VERSION' ) || function_exists( 'vc_map' );
	}

	public function detect( $post, $scan_id ) {
		$content = (string) $post->post_content;

		// Quick bail-out: no WPBakery shortcodes present.
		if ( strpos( $content, '[vc_' ) === false ) {
			return 0;
		}

		$found_ids = array();

		// 1. Single-ID attributes:  image="123"
		$id_alt = implode( '|', array_map( 'preg_quote', self::ID_ATTRS ) );
		if ( preg_match_all( '/\b(?:' . $id_alt . ')="(\d+)"/i', $content, $sm ) ) {
			foreach ( $sm[1] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$found_ids[] = $id;
				}
			}
		}

		// 2. Multi-ID attributes:  images="1,2,3"
		$ids_alt = implode( '|', array_map( 'preg_quote', self::IDS_ATTRS ) );
		if ( preg_match_all( '/\b(?:' . $ids_alt . ')="([0-9,\s]+)"/i', $content, $mm ) ) {
			foreach ( $mm[1] as $list ) {
				foreach ( preg_split( '/\s*,\s*/', trim( $list ) ) as $id ) {
					$id = absint( $id );
					if ( $id > 0 ) {
						$found_ids[] = $id;
					}
				}
			}
		}

		// 3. URL attributes → resolve to attachment IDs.
		$url_alt = implode( '|', array_map( 'preg_quote', self::URL_ATTRS ) );
		if ( preg_match_all( '/\b(?:' . $url_alt . ')="([^"]+)"/i', $content, $um ) ) {
			foreach ( $um[1] as $url ) {
				$url = trim( $url );
				// Skip the values that are obviously not URLs (e.g. source="external_link").
				if ( $url === '' || strpos( $url, 'http' ) !== 0 ) {
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
					$found_ids[] = $id;
				}
			}
		}

		// CSS url() references (e.g. css="" attribute with background images)
		$this->scan_text_for_css_urls( $content, $found_ids );

		$found_ids = array_unique( $found_ids );

		$recorded = 0;
		foreach ( $found_ids as $id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'wpbakery',
				'context'       => 'WPBakery',
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}
}
