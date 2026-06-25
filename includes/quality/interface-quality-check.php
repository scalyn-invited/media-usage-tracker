<?php
namespace MediaUsageTracker\Quality;

/**
 * One media-quality audit rule.
 *
 * Mirrors the swappable MediaDetector pattern: each check is a small,
 * self-contained class that the QualityAuditor runs over every attachment.
 * Adding a future audit = drop in one class implementing this interface and
 * register it — the engine never changes.
 *
 * evaluate() receives a normalized attachment context (built once by the
 * auditor) so checks never touch WordPress directly and stay trivially
 * testable:
 *
 *   [
 *     'id'          => int,
 *     'mime'        => string,   // e.g. 'image/jpeg'
 *     'alt'         => string,   // _wp_attachment_image_alt
 *     'caption'     => string,   // post_excerpt
 *     'description' => string,   // post_content
 *     'title'       => string,   // post_title
 *     'bytes'       => int,      // file size on disk
 *   ]
 */
interface QualityCheck {

	/** Machine key, e.g. 'alt_text'. */
	public function key();

	/** Short human label, e.g. 'Missing Alt Text'. */
	public function label();

	/** One-line explanation of the issue and why it matters. */
	public function description();

	/** Severity: 'high' | 'medium' | 'low'. */
	public function severity();

	/**
	 * Does this attachment FAIL the check?
	 *
	 * @param array $attachment Normalized context (see interface docblock).
	 * @return bool True if the attachment has this quality issue.
	 */
	public function evaluate( array $attachment );
}
