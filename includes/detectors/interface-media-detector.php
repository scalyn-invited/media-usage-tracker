<?php
namespace MediaUsageTracker\Scanner;

/**
 * A MediaDetector inspects a single post for media references and records each
 * one it finds via UsageStorage. The scanner runs every available detector over
 * every post in a batch.
 *
 * This is the extension point for Phase 2 builder integrations (Elementor, Divi,
 * Beaver Builder, ACF): each becomes a detector that self-gates via
 * is_available() and records with its own usage_type.
 */
interface MediaDetector {

	/**
	 * Stable key for this detector. Also used as the usage_type stored on each
	 * recorded reference (e.g. 'content', 'featured_image', 'elementor').
	 */
	public function key();

	/**
	 * Whether this detector can run on the current site. Built-in detectors
	 * return true; builder detectors check that their plugin is active
	 * (e.g. class_exists('Elementor\\Plugin')).
	 */
	public function is_available();

	/**
	 * Inspect one post and record any media references found.
	 *
	 * @param \WP_Post $post
	 * @param int      $scan_id
	 * @return int Number of references recorded (for diagnostics).
	 */
	public function detect( $post, $scan_id );
}
