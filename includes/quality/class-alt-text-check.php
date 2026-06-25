<?php
namespace MediaUsageTracker\Quality;

/**
 * Flags images missing alt text — the highest-value check (accessibility/WCAG
 * + SEO), not just tidiness. Applies to images only.
 */
class AltTextCheck implements QualityCheck {

	public function key() { return 'alt_text'; }
	public function label() { return 'Missing Alt Text'; }
	public function description() {
		return 'Images without alt text are inaccessible to screen readers and lose SEO value.';
	}
	public function severity() { return 'high'; }

	public function evaluate( array $attachment ) {
		if ( strpos( $attachment['mime'] ?? '', 'image/' ) !== 0 ) {
			return false;
		}
		if ( ( $attachment['mime'] ?? '' ) === 'image/svg+xml' ) {
			return false; // SVGs are decorative icons — no alt text needed
		}
		if ( ! empty( $attachment['decorative'] ) ) {
			return false; // user explicitly marked this as decorative
		}
		return trim( (string) ( $attachment['alt'] ?? '' ) ) === '';
	}
}
