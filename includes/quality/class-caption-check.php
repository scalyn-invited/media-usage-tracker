<?php
namespace MediaUsageTracker\Quality;

/**
 * Flags attachments with no caption (post_excerpt).
 */
class CaptionCheck implements QualityCheck {

	public function key() { return 'caption'; }
	public function label() { return 'Missing Caption'; }
	public function description() {
		return 'Captions provide context shown alongside media in many themes and galleries.';
	}
	public function severity() { return 'low'; }

	public function evaluate( array $attachment ) {
		if ( ( $attachment['mime'] ?? '' ) === 'image/svg+xml' ) { return false; }
		return trim( (string) ( $attachment['caption'] ?? '' ) ) === '';
	}
}
