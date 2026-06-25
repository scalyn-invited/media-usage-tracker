<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Storage Optimization engine.
 *
 * Produces an ordered list of plain-English cleanup recommendations from data
 * that already exists in the plugin (usage table, file sizes on disk, MIME
 * types, and DuplicateDetector groups). Pure computation — no rendering.
 *
 * Results are cached in a transient and recomputed on demand or after a scan.
 */
class StorageOptimizer {

	const TRANSIENT_KEY = 'mut_storage_recommendations';
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/** Thresholds below which a recommendation is NOT surfaced (avoid noise). */
	const UNUSED_PCT_MIN     = 5;        // unused must be ≥5% of storage
	const DUP_BYTES_MIN      = 1048576;  // 1 MB of exact-dup waste
	const LARGE_FILE_BYTES   = 10485760; // 10 MB — "large" file threshold
	const SIMILAR_BYTES_MIN  = 1048576;  // 1 MB of similar-version waste

	const PRIORITY_HIGH   = 1;
	const PRIORITY_MEDIUM = 2;
	const PRIORITY_LOW    = 3;

	/** @var UsageStorage */
	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Full optimization report:
	 *   total_bytes        int
	 *   recoverable_bytes  int    (sum of recommendation savings, de-duplicated)
	 *   recoverable_pct    float
	 *   recommendations    array  (ordered by priority)
	 *   mime_breakdown     array  ([ label => [bytes, count] ], desc by bytes)
	 */
	public function get_report() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( $cached !== false ) {
			return $cached;
		}
		return $this->refresh();
	}

	public function refresh() {
		$total_bytes  = (int) $this->storage->get_storage_usage();
		$unused_bytes = (int) $this->storage->get_unused_storage_usage();

		$recommendations = array();

		$this->add_unused_recommendation( $recommendations, $total_bytes, $unused_bytes );
		$this->add_duplicate_recommendation( $recommendations );
		$this->add_large_unused_recommendation( $recommendations );
		$this->add_similar_recommendation( $recommendations );
		$this->add_mime_recommendation( $recommendations, $total_bytes );

		// Order by priority (High → Low), then by savings desc.
		usort( $recommendations, function ( $a, $b ) {
			if ( $a['priority'] !== $b['priority'] ) {
				return $a['priority'] <=> $b['priority'];
			}
			return $b['saving'] <=> $a['saving'];
		} );

		// "Recoverable" headline figure = unused bytes (the dominant, non-overlapping
		// number). Duplicate/large savings are subsets of unused, so we don't sum.
		$recoverable_bytes = $unused_bytes;
		$recoverable_pct   = $total_bytes > 0 ? round( $recoverable_bytes / $total_bytes * 100, 1 ) : 0.0;

		$report = array(
			'total_bytes'       => $total_bytes,
			'recoverable_bytes' => $recoverable_bytes,
			'recoverable_pct'   => $recoverable_pct,
			'recommendations'   => $recommendations,
			'mime_breakdown'    => $this->mime_breakdown(),
		);

		set_transient( self::TRANSIENT_KEY, $report, self::TRANSIENT_TTL );
		return $report;
	}

	public function bust_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	// -------------------------------------------------------------------------
	// Recommendation builders
	// -------------------------------------------------------------------------

	private function add_unused_recommendation( &$out, $total_bytes, $unused_bytes ) {
		if ( $total_bytes <= 0 ) {
			return;
		}
		$pct = round( $unused_bytes / $total_bytes * 100, 1 );
		if ( $pct < self::UNUSED_PCT_MIN ) {
			return;
		}
		$count = count( $this->storage->get_unused_attachments() );

		$out[] = $this->rec(
			'unused',
			self::PRIORITY_HIGH,
			sprintf( 'Unused media accounts for %s%% of your storage.', $pct ),
			sprintf(
				'%d %s totalling %s %s never been referenced in any content.',
				$count,
				$this->plural( $count, 'file', 'files' ),
				size_format( $unused_bytes, 1 ),
				$this->plural( $count, 'has', 'have' )
			),
			$unused_bytes,
			$total_bytes,
			'Review unused files',
			admin_url( 'admin.php?page=mut-cleanup' )
		);
	}

	private function add_duplicate_recommendation( &$out ) {
		$waste = $this->exact_duplicate_waste();
		if ( $waste['bytes'] < self::DUP_BYTES_MIN ) {
			return;
		}
		$out[] = $this->rec(
			'duplicates',
			self::PRIORITY_HIGH,
			sprintf( '%d duplicate %s wasting %s.',
				$waste['files'],
				$this->plural( $waste['files'], 'file is', 'files are' ),
				size_format( $waste['bytes'], 1 )
			),
			'Exact byte-for-byte copies exist. Removing the redundant copies recovers this space safely.',
			$waste['bytes'],
			(int) $this->storage->get_storage_usage(),
			'View duplicate analysis',
			admin_url( 'admin.php?page=mut-duplicates' )
		);
	}

	private function add_large_unused_recommendation( &$out ) {
		$large = $this->large_unused_files();
		if ( empty( $large['ids'] ) ) {
			return;
		}
		$out[] = $this->rec(
			'large_files',
			self::PRIORITY_MEDIUM,
			sprintf( '%d large unused %s over %s.',
				count( $large['ids'] ),
				$this->plural( count( $large['ids'] ), 'file', 'files' ),
				size_format( self::LARGE_FILE_BYTES, 0 )
			),
			sprintf( 'These oversized, unreferenced files alone account for %s. Prioritise them for the biggest quick wins.', size_format( $large['bytes'], 1 ) ),
			$large['bytes'],
			(int) $this->storage->get_storage_usage(),
			'Review unused files',
			admin_url( 'admin.php?page=mut-cleanup' )
		);
	}

	private function add_similar_recommendation( &$out ) {
		$waste = $this->similar_version_waste();
		if ( $waste['bytes'] < self::SIMILAR_BYTES_MIN ) {
			return;
		}
		$out[] = $this->rec(
			'similar',
			self::PRIORITY_LOW,
			sprintf( '%d near-duplicate %s detected.',
				$waste['groups'],
				$this->plural( $waste['groups'], 'group', 'groups' )
			),
			sprintf( 'Consolidating similar versions to a single canonical file could save roughly %s.', size_format( $waste['bytes'], 1 ) ),
			$waste['bytes'],
			(int) $this->storage->get_storage_usage(),
			'View duplicate analysis',
			admin_url( 'admin.php?page=mut-duplicates' )
		);
	}

	private function add_mime_recommendation( &$out, $total_bytes ) {
		if ( $total_bytes <= 0 ) {
			return;
		}
		$breakdown = $this->mime_breakdown();
		if ( empty( $breakdown ) ) {
			return;
		}
		// Surface the single dominant type if it disproportionately dominates storage.
		$top_label = array_key_first( $breakdown );
		$top       = $breakdown[ $top_label ];
		$pct_bytes = round( $top['bytes'] / $total_bytes * 100, 1 );

		$total_files = array_sum( array_map( fn( $t ) => $t['count'], $breakdown ) );
		$pct_files   = $total_files > 0 ? round( $top['count'] / $total_files * 100, 1 ) : 0;

		// Only flag when storage share noticeably outweighs file-count share.
		if ( $pct_bytes < 40 || $pct_bytes <= $pct_files * 1.5 ) {
			return;
		}

		$out[] = $this->rec(
			'mime_type',
			self::PRIORITY_LOW,
			sprintf( '%s account for %s%% of storage but only %s%% of files.', $top_label, $pct_bytes, $pct_files ),
			sprintf( 'A small number of %s dominate your library size. Compressing or offloading them yields outsized savings.', strtolower( $top_label ) ),
			0, // informational — no direct recoverable figure
			$total_bytes,
			'',
			''
		);
	}

	// -------------------------------------------------------------------------
	// Computations
	// -------------------------------------------------------------------------

	/** Bytes wasted by exact duplicates (all but one copy per group). */
	private function exact_duplicate_waste() {
		$groups = $this->duplicate_groups();
		$bytes  = 0;
		$files  = 0;
		foreach ( $groups as $g ) {
			if ( ( $g['type'] ?? '' ) !== 'exact' ) {
				continue;
			}
			$ids = $g['ids'];
			// Keep the first (canonical), count the rest as waste.
			$redundant = array_slice( $ids, 1 );
			foreach ( $redundant as $id ) {
				$bytes += $this->file_bytes( $id );
				$files++;
			}
		}
		return array( 'bytes' => $bytes, 'files' => $files );
	}

	/** Bytes saveable by consolidating similar groups (all but most-used file). */
	private function similar_version_waste() {
		$groups = $this->duplicate_groups();
		$bytes  = 0;
		$count  = 0;
		foreach ( $groups as $g ) {
			if ( ( $g['type'] ?? '' ) !== 'similar' ) {
				continue;
			}
			$ids = $g['ids'];
			// Keep the most-used; the rest are saveable.
			$keep = $this->most_used_id( $ids );
			foreach ( $ids as $id ) {
				if ( $id === $keep ) {
					continue;
				}
				$bytes += $this->file_bytes( $id );
			}
			$count++;
		}
		return array( 'bytes' => $bytes, 'groups' => $count );
	}

	/** Unused attachments larger than the large-file threshold. */
	private function large_unused_files() {
		$ids   = array();
		$bytes = 0;
		foreach ( $this->storage->get_unused_attachments() as $id ) {
			$b = $this->file_bytes( (int) $id );
			if ( $b >= self::LARGE_FILE_BYTES ) {
				$ids[]  = (int) $id;
				$bytes += $b;
			}
		}
		return array( 'ids' => $ids, 'bytes' => $bytes );
	}

	/** Storage grouped by friendly MIME category, sorted by bytes desc. */
	private function mime_breakdown() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT ID, post_mime_type FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		$out = array();
		foreach ( $rows as $row ) {
			$label = $this->mime_category( $row->post_mime_type );
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = array( 'bytes' => 0, 'count' => 0 );
			}
			$out[ $label ]['bytes'] += $this->file_bytes( (int) $row->ID );
			$out[ $label ]['count']++;
		}

		uasort( $out, fn( $a, $b ) => $b['bytes'] <=> $a['bytes'] );
		return $out;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function duplicate_groups() {
		require_once MUT_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
		$detector = new DuplicateDetector( $this->storage );
		return $detector->get_groups();
	}

	private function most_used_id( $ids ) {
		$best = $ids[0];
		$best_count = -1;
		foreach ( $ids as $id ) {
			$c = $this->storage->get_usage_count( $id );
			if ( $c > $best_count ) {
				$best_count = $c;
				$best = $id;
			}
		}
		return $best;
	}

	private function file_bytes( $id ) {
		$file = get_attached_file( (int) $id );
		return ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
	}

	private function mime_category( $mime ) {
		if ( strpos( $mime, 'image/' ) === 0 ) return 'Images';
		if ( strpos( $mime, 'video/' ) === 0 ) return 'Videos';
		if ( strpos( $mime, 'audio/' ) === 0 ) return 'Audio';
		if ( $mime === 'application/pdf' ) return 'Documents';
		if ( strpos( $mime, 'application/' ) === 0 ) return 'Documents';
		return 'Other';
	}

	private function rec( $category, $priority, $headline, $detail, $saving, $total_bytes, $action_label, $action_url ) {
		$share_pct = $total_bytes > 0 ? round( $saving / $total_bytes * 100, 1 ) : 0.0;
		return array(
			'category'     => $category,
			'priority'     => $priority,
			'headline'     => $headline,
			'detail'       => $detail,
			'saving'       => (int) $saving,
			'share_pct'    => $share_pct,
			'action_label' => $action_label,
			'action_url'   => $action_url,
		);
	}

	private function plural( $n, $singular, $plural ) {
		return ( (int) $n === 1 ) ? $singular : $plural;
	}
}
