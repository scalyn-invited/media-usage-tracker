<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Yoast SEO.
 *
 * Yoast stores social/OG image attachment IDs in postmeta:
 *   - _yoast_wpseo_opengraph-image-id   (OG / Facebook image)
 *   - _yoast_wpseo_twitter-image-id     (Twitter card image)
 *
 * Self-gates: only runs when Yoast SEO is active.
 */
class YoastDetector implements MediaDetector {

	const META_KEYS = array(
		'_yoast_wpseo_opengraph-image-id' => 'Yoast SEO: OG Image',
		'_yoast_wpseo_twitter-image-id'   => 'Yoast SEO: Twitter Image',
	);

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'yoast';
	}

	public function is_available() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' );
	}

	public function detect( $post, $scan_id ) {
		$found = array();

		foreach ( self::META_KEYS as $meta_key => $label ) {
			$raw = get_post_meta( $post->ID, $meta_key, true );
			$id  = absint( $raw );
			if ( $id > 0 && ! isset( $found[ $id ] ) ) {
				$found[ $id ] = $label;
			}
		}

		foreach ( $found as $attachment_id => $context ) {
			$this->storage->record_usage( array(
				'attachment_id' => $attachment_id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'yoast',
				'context'       => $context,
				'scan_id'       => $scan_id,
			) );
		}

		return count( $found );
	}
}
