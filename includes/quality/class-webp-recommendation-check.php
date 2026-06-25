<?php
namespace MediaUsageTracker\Quality;

/**
 * Recommends WebP for JPEG/PNG images above a size threshold. Advisory only —
 * this flags candidates; it does NOT convert (conversion is a separate, riskier
 * future feature involving image libraries and reference rewriting).
 */
class WebPRecommendationCheck implements QualityCheck {

	const MIN_BYTES = 204800; // 200 KB — below this, WebP gains are marginal

	const CONVERTIBLE = array( 'image/jpeg', 'image/png' );

	public function key() { return 'webp'; }
	public function label() { return 'WebP Recommendations'; }
	public function description() {
		return 'These JPEG/PNG files are large enough to benefit from WebP conversion — typically 25–35% smaller with no visible quality loss. Use your hosting\'s image optimization tools or a WordPress image optimization plugin to convert them.';
	}
	public function severity() { return 'low'; }

	public function evaluate( array $attachment ) {
		$mime = (string) ( $attachment['mime'] ?? '' );
		if ( ! in_array( $mime, self::CONVERTIBLE, true ) ) {
			return false;
		}
		return (int) ( $attachment['bytes'] ?? 0 ) >= self::MIN_BYTES;
	}
}
