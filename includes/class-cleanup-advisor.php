<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * AI Cleanup Advisor
 *
 * Produces human-readable, plain-English advice about whether a media file is a
 * safe candidate for cleanup. The reasoning is rule-based (no external API call),
 * but the public interface — get_advice() returning a message + suggestion +
 * confidence — is deliberately shaped so the rule engine can later be swapped for
 * a real LLM call without touching callers.
 *
 * Example output:
 *   message    => "This image has not been referenced in any content for 18 months."
 *   suggestion => "Safe candidate for archival review."
 *   action     => 'archive' | 'review' | 'monitor' | 'keep'
 *   confidence => 0.0 – 1.0
 */
class CleanupAdvisor {

	/** Files unused and older than this (months) are strong archival candidates. */
	const ARCHIVE_MONTHS = 18;

	/** Files unused and older than this (months) warrant a manual review. */
	const REVIEW_MONTHS = 3;

	/** A file larger than this (bytes) is worth calling out for the space it frees. */
	const LARGE_FILE_BYTES = 2097152; // 2 MB

	/** @var UsageStorage */
	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Generate advice for a single attachment.
	 *
	 * @param int      $attachment_id
	 * @param int|null $now Unix timestamp to evaluate against (defaults to now). Injectable for testing.
	 * @return array{message:string,suggestion:string,action:string,confidence:float}
	 */
	public function get_advice( $attachment_id, $now = null ) {
		$attachment_id = (int) $attachment_id;
		$now           = $now ?? current_time( 'timestamp' );

		$facts = $this->gather_facts( $attachment_id, $now );

		return $this->reason( $facts );
	}

	/**
	 * Collect the raw signals the rule engine reasons over.
	 *
	 * @return array
	 */
	private function gather_facts( $attachment_id, $now ) {
		$post        = get_post( $attachment_id );
		$upload_ts   = $post ? strtotime( $post->post_date ) : $now;
		$age_months  = $this->months_between( $upload_ts, $now );

		$usage_count = (int) $this->storage->get_usage_count( $attachment_id );
		$statuses    = $usage_count > 0
			? (array) $this->storage->get_usage_post_statuses( $attachment_id )
			: array();

		// Is the file referenced anywhere that is actually live (published)?
		$has_published_use = in_array( 'publish', $statuses, true );

		$file     = get_attached_file( $attachment_id );
		$has_file = $file && file_exists( $file );
		$bytes    = $has_file ? (int) filesize( $file ) : 0;

		return array(
			'age_months'        => $age_months,
			'usage_count'       => $usage_count,
			'statuses'          => $statuses,
			'has_published_use' => $has_published_use,
			'bytes'             => $bytes,
			'is_large'          => $bytes >= self::LARGE_FILE_BYTES,
			'has_file'          => $has_file,
		);
	}

	/**
	 * Turn facts into advice. Ordered most-confident → least.
	 *
	 * @return array{message:string,suggestion:string,action:string,confidence:float}
	 */
	private function reason( array $f ) {
		$age   = $f['age_months'];
		$human = $this->humanize_months( $age );

		// 1. Referenced in published content → keep.
		if ( $f['has_published_use'] ) {
			return $this->advice(
				sprintf( 'In active use across %d published %s.', $f['usage_count'], $this->plural( $f['usage_count'], 'location', 'locations' ) ),
				'Keep — this file is live on your site.',
				'keep',
				0.95
			);
		}

		// 2. Referenced, but only in unpublished content (drafts/private/future).
		if ( $f['usage_count'] > 0 ) {
			return $this->advice(
				sprintf( 'Referenced only in unpublished content (%s).', implode( ', ', array_unique( $f['statuses'] ) ) ),
				'Review — not visible to visitors, but still linked. Confirm before removing.',
				'review',
				0.6
			);
		}

		// From here on the file is referenced nowhere.

		// 3. Unused and old → strong archival candidate.
		if ( $age >= self::ARCHIVE_MONTHS ) {
			$suffix = $f['is_large']
				? sprintf( ' Archiving it frees %s.', size_format( $f['bytes'] ) )
				: '';
			return $this->advice(
				sprintf( 'This file has not been referenced in any content for %s.', $human ),
				'Safe candidate for archival review.' . $suffix,
				'archive',
				0.9
			);
		}

		// 4. Unused for a meaningful but shorter window → review.
		if ( $age >= self::REVIEW_MONTHS ) {
			return $this->advice(
				sprintf( 'Unused and uploaded %s ago.', $human ),
				'Review recommended — verify it is genuinely unneeded before deleting.',
				'review',
				0.65
			);
		}

		// 5. Recently uploaded, not yet used → give it time.
		return $this->advice(
			sprintf( 'Uploaded %s ago and not yet referenced.', $human ),
			'Monitor — recently added files are often used soon after upload.',
			'monitor',
			0.5
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function advice( $message, $suggestion, $action, $confidence ) {
		return array(
			'message'    => $message,
			'suggestion' => $suggestion,
			'action'     => $action,
			'confidence' => (float) $confidence,
		);
	}

	/** Whole months between two timestamps (floored, never negative). */
	private function months_between( $from_ts, $to_ts ) {
		if ( $to_ts <= $from_ts ) {
			return 0;
		}
		return (int) floor( ( $to_ts - $from_ts ) / ( 30 * DAY_IN_SECONDS ) );
	}

	/** "18 months", "1 year and 2 months", "20 days". */
	private function humanize_months( $months ) {
		if ( $months < 1 ) {
			return 'less than a month';
		}
		// Keep the natural "18 months" phrasing up to two years before switching
		// to "N years" — reads better for archival-age files.
		if ( $months < 24 ) {
			return $months . ' ' . $this->plural( $months, 'month', 'months' );
		}
		$years     = intdiv( $months, 12 );
		$remainder = $months % 12;
		$out       = $years . ' ' . $this->plural( $years, 'year', 'years' );
		if ( $remainder > 0 ) {
			$out .= ' and ' . $remainder . ' ' . $this->plural( $remainder, 'month', 'months' );
		}
		return $out;
	}

	private function plural( $n, $singular, $plural ) {
		return ( (int) $n === 1 ) ? $singular : $plural;
	}
}
