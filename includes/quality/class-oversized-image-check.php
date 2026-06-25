<?php
namespace MediaUsageTracker\Quality;

/**
 * Flags images larger than 1 MB — candidates for compression/resizing.
 * Applies to images only (a 5 MB video is expected; a 5 MB JPEG is not).
 */
class OversizedImageCheck implements QualityCheck {

	const THRESHOLD_BYTES = 1048576; // 1 MB

	public function key() { return 'oversized'; }
	public function label() { return 'Oversized Images (>1 MB)'; }
	public function description() {
		return 'Large image files slow page loads. Compressing or resizing them improves performance.';
	}
	public function severity() { return 'medium'; }

	public function evaluate( array $attachment ) {
		if ( strpos( $attachment['mime'] ?? '', 'image/' ) !== 0 ) {
			return false;
		}
		return (int) ( $attachment['bytes'] ?? 0 ) > self::THRESHOLD_BYTES;
	}
}
