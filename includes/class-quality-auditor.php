<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;
use MediaUsageTracker\Quality\QualityCheck;

/**
 * Media Quality Audit engine.
 *
 * Runs a registry of QualityCheck rules over every attachment and aggregates
 * the findings. Mirrors the StorageOptimizer / DuplicateDetector blueprint:
 * pure computation, cached in a transient, recomputed on demand or after a scan.
 *
 * The checks registry is filterable so third parties can add audits:
 *   add_filter( 'mut_quality_checks', fn( $checks ) => [...$checks, new MyCheck()] );
 */
class QualityAuditor {

	const TRANSIENT_KEY = 'mut_quality_audit';
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/** Severity ordering for sorting / display. */
	const SEVERITY_RANK = array( 'high' => 1, 'medium' => 2, 'low' => 3 );

	/** @var UsageStorage */
	private $storage;

	/** @var QualityCheck[] */
	private $checks = array();

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
		$this->load_checks();
	}

	private function load_checks() {
		$dir = MUT_PLUGIN_DIR . 'includes/quality/';
		require_once $dir . 'interface-quality-check.php';
		require_once $dir . 'class-alt-text-check.php';
		require_once $dir . 'class-caption-check.php';
		require_once $dir . 'class-description-check.php';
		require_once $dir . 'class-oversized-image-check.php';
		require_once $dir . 'class-unsupported-format-check.php';
		require_once $dir . 'class-webp-recommendation-check.php';

		$checks = array(
			new \MediaUsageTracker\Quality\AltTextCheck(),
			new \MediaUsageTracker\Quality\OversizedImageCheck(),
			new \MediaUsageTracker\Quality\UnsupportedFormatCheck(),
			new \MediaUsageTracker\Quality\WebPRecommendationCheck(),
			new \MediaUsageTracker\Quality\CaptionCheck(),
			new \MediaUsageTracker\Quality\DescriptionCheck(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$checks = apply_filters( 'mut_quality_checks', $checks, $this->storage );
		}

		$this->checks = array_values( array_filter( $checks, function ( $c ) {
			return $c instanceof QualityCheck;
		} ) );
	}

	/** @return QualityCheck[] */
	public function get_checks() {
		return $this->checks;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	public function get_report() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( $cached !== false ) {
			return $cached;
		}
		return $this->refresh();
	}

	public function refresh() {
		$report = $this->run_checks( $this->get_attachment_contexts() );
		set_transient( self::TRANSIENT_KEY, $report, self::TRANSIENT_TTL );
		return $report;
	}

	public function bust_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	// -------------------------------------------------------------------------
	// Pure aggregation (testable without WordPress)
	// -------------------------------------------------------------------------

	/**
	 * Run every check over a list of attachment contexts and aggregate.
	 *
	 * @param array[] $attachments Normalized contexts (see QualityCheck docblock).
	 * @return array {
	 *   @type int   $total           Attachments audited.
	 *   @type int   $total_issues    Total flagged (attachment × check) findings.
	 *   @type int   $clean           Attachments with zero issues.
	 *   @type array $checks          Per-check result keyed by check key.
	 *   @type array $severity_counts [ high => n, medium => n, low => n ].
	 * }
	 */
	public function run_checks( array $attachments ) {
		$results = array();
		foreach ( $this->checks as $check ) {
			$results[ $check->key() ] = array(
				'key'         => $check->key(),
				'label'       => $check->label(),
				'description' => $check->description(),
				'severity'    => $check->severity(),
				'count'       => 0,
				'items'       => array(),
			);
		}

		$total_issues      = 0;
		$flagged_ids       = array();
		$flagged_inuse_ids = array();
		$inuse_total       = 0;
		$severity_count    = array( 'high' => 0, 'medium' => 0, 'low' => 0 );

		// Checks where the detail page only shows in-use files — card count must match.
		$inuse_only_checks = array( 'alt_text', 'caption', 'description' );

		foreach ( $attachments as $att ) {
			$in_use = ! empty( $att['in_use'] );
			if ( $in_use ) {
				$inuse_total++;
			}
			foreach ( $this->checks as $check ) {
				if ( $check->evaluate( $att ) ) {
					$key = $check->key();
					if ( in_array( $key, $inuse_only_checks, true ) && ! $in_use ) {
						continue;
					}
					$results[ $key ]['count']++;
					$results[ $key ]['items'][] = (int) ( $att['id'] ?? 0 );
					$flagged_ids[ (int) ( $att['id'] ?? 0 ) ] = true;
					if ( $in_use ) {
						$total_issues++;
						$flagged_inuse_ids[ (int) ( $att['id'] ?? 0 ) ] = true;
					}
					$sev = $check->severity();
					if ( isset( $severity_count[ $sev ] ) ) {
						$severity_count[ $sev ]++;
					}
				}
			}
		}

		// Order checks: by severity, then by count desc.
		uasort( $results, function ( $a, $b ) {
			$ra = self::SEVERITY_RANK[ $a['severity'] ] ?? 9;
			$rb = self::SEVERITY_RANK[ $b['severity'] ] ?? 9;
			if ( $ra !== $rb ) {
				return $ra <=> $rb;
			}
			return $b['count'] <=> $a['count'];
		} );

		return array(
			'total'           => $inuse_total,
			'total_issues'    => $total_issues,
			'clean'           => max( 0, $inuse_total - count( $flagged_inuse_ids ) ),
			'checks'          => $results,
			'severity_counts' => $severity_count,
		);
	}

	// -------------------------------------------------------------------------
	// Attachment context builder
	// -------------------------------------------------------------------------

	/**
	 * Build the normalized context array for every attachment.
	 *
	 * @return array[]
	 */
	private function get_attachment_contexts() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_excerpt, post_content, post_mime_type
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// Build usage count map so each context knows if it is in use.
		$used_ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$wpdb->prefix}mut_media_usage" );
		$used_map = array_flip( array_map( 'intval', $used_ids ) );

		$contexts = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row->ID;
			$file = get_attached_file( $id );
			$contexts[] = array(
				'id'          => $id,
				'mime'        => (string) $row->post_mime_type,
				'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'alt_exists'  => metadata_exists( 'post', $id, '_wp_attachment_image_alt' ),
				'caption'     => (string) $row->post_excerpt,
				'description' => (string) $row->post_content,
				'title'       => (string) $row->post_title,
				'bytes'       => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
				'decorative'  => (bool) get_post_meta( $id, '_mut_decorative', true ),
				'in_use'      => isset( $used_map[ $id ] ),
			);
		}

		return $contexts;
	}
}
