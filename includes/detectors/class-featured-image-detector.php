<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects a post's featured image (post thumbnail).
 * Behavior is identical to the original MediaScanner::scan_featured_image().
 */
class FeaturedImageDetector implements MediaDetector {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'featured_image';
	}

	public function is_available() {
		return true;
	}

	public function detect( $post, $scan_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $thumbnail_id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'featured_image',
				'context'       => 'Featured Image',
				'scan_id'       => $scan_id,
			) );
			return 1;
		}
		return 0;
	}
}
