<?php
namespace MediaUsageTracker\Quality;

/**
 * Flags attachments whose MIME type is outside a modern, web-friendly
 * allowlist (e.g. BMP, TIFF, legacy formats). Advisory — these often render
 * poorly or bloat storage versus modern equivalents.
 */
class UnsupportedFormatCheck implements QualityCheck {

	/** Modern, web-appropriate types we consider "supported". */
	const SUPPORTED = array(
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif',
		'video/mp4', 'video/webm',
		'audio/mpeg', 'audio/wav', 'audio/ogg',
		'application/pdf',
	);

	public function key() { return 'unsupported_format'; }
	public function label() { return 'Unsupported Formats'; }
	public function description() {
		return 'Legacy or uncommon formats (BMP, TIFF, etc.) may not display well in browsers and bloat storage.';
	}
	public function severity() { return 'medium'; }

	public function evaluate( array $attachment ) {
		$mime = (string) ( $attachment['mime'] ?? '' );
		if ( $mime === '' ) {
			return false; // unknown — don't false-flag
		}
		return ! in_array( $mime, self::SUPPORTED, true );
	}
}
