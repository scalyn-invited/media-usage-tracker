<?php
namespace MediaUsageTracker\Quality;

/**
 * Flags attachments with no description (post_content).
 */
class DescriptionCheck implements QualityCheck {

	public function key() { return 'description'; }
	public function label() { return 'Missing Description'; }
	public function description() {
		return 'Descriptions aid internal organisation and can surface on attachment pages.';
	}
	public function severity() { return 'low'; }

	public function evaluate( array $attachment ) {
		// SVGs are decorative by nature; explicitly-empty alt text signals a decorative image.
		if ( ( $attachment['mime'] ?? '' ) === 'image/svg+xml' ) { return false; }
		// alt exists but is empty string = author deliberately marked as decorative.
		if ( ! empty( $attachment['alt_exists'] ) && ( $attachment['alt'] ?? 'x' ) === '' ) { return false; }
		if ( ! empty( $attachment['decorative'] ) ) { return false; }
		return trim( (string) ( $attachment['description'] ?? '' ) ) === '';
	}
}
