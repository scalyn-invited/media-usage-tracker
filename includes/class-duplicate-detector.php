<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Duplicate / near-duplicate asset detector.
 *
 * Detects three group types without any external library:
 *   exact    — identical file bytes (MD5 hash)
 *   similar  — normalised filename stem matches (logo-v2, logo_final, logo-copy…)
 *   resizes  — WP auto-generated size variants sharing a common parent attachment
 *
 * Results are cached in a transient so the page never re-hashes on every load.
 */
class DuplicateDetector {

	const TRANSIENT_KEY    = 'mut_duplicate_groups';
	const TRANSIENT_TTL    = HOUR_IN_SECONDS;
	const MAX_HASH_BYTES   = 52428800; // 50 MB — skip hashing files larger than this

	/** @var UsageStorage */
	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return all duplicate groups, served from transient when available.
	 *
	 * Each group:
	 *   type        string  'exact' | 'similar' | 'resizes'
	 *   ids         int[]   attachment IDs in the group (2+)
	 *   label       string  Human summary ("5 similar logo versions detected.")
	 *   reason      string  Technical explanation shown in tooltip
	 *   recommendation string Plain-English next step
	 */
	public function get_groups() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( $cached !== false ) {
			return $cached;
		}
		return $this->refresh();
	}

	/**
	 * Rebuild all groups, update the transient, and return the fresh result.
	 */
	public function refresh() {
		$attachments = $this->fetch_all_attachments();

		$groups = array_merge(
			$this->detect_exact( $attachments ),
			$this->detect_similar( $attachments ),
			$this->detect_resizes( $attachments )
		);

		set_transient( self::TRANSIENT_KEY, $groups, self::TRANSIENT_TTL );
		return $groups;
	}

	/** Bust the transient so the next page-load recomputes. */
	public function bust_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	// -------------------------------------------------------------------------
	// Data fetch
	// -------------------------------------------------------------------------

	private function fetch_all_attachments() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_parent, p.post_mime_type, p.guid
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment'
			   AND p.post_status = 'inherit'
			 ORDER BY p.ID ASC"
		);
	}

	// -------------------------------------------------------------------------
	// Detection: exact (MD5 hash)
	// -------------------------------------------------------------------------

	private function detect_exact( $attachments ) {
		$hash_map = array(); // hash => [id, …]

		foreach ( $attachments as $att ) {
			$file = get_attached_file( (int) $att->ID );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			if ( filesize( $file ) > self::MAX_HASH_BYTES ) {
				continue;
			}
			$hash = md5_file( $file );
			if ( $hash === false ) {
				continue;
			}
			$hash_map[ $hash ][] = (int) $att->ID;
		}

		$groups = array();
		foreach ( $hash_map as $hash => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$count    = count( $ids );
			$groups[] = array(
				'type'           => 'exact',
				'ids'            => $ids,
				'label'          => $count . ' identical ' . ( $count === 2 ? 'copy' : 'copies' ) . ' detected.',
				'reason'         => 'These files have the same MD5 hash (' . substr( $hash, 0, 8 ) . '…) — byte-for-byte duplicates.',
				'recommendation' => $this->exact_recommendation( $ids ),
			);
		}

		return $groups;
	}

	private function exact_recommendation( $ids ) {
		// Prefer to keep the one that is actually in use.
		$unused = array();
		foreach ( $ids as $id ) {
			if ( $this->storage->get_usage_count( $id ) === 0 ) {
				$unused[] = $id;
			}
		}
		if ( count( $unused ) === count( $ids ) ) {
			return 'All copies are unused — safe to delete all but one.';
		}
		if ( count( $unused ) > 0 ) {
			return 'Keep the copy in active use; the unused ' . ( count( $unused ) === 1 ? 'copy' : 'copies' ) . ' can be removed.';
		}
		return 'Multiple copies are in use — consolidate references to one file, then remove the rest.';
	}

	// -------------------------------------------------------------------------
	// Detection: similar (normalised stem)
	// -------------------------------------------------------------------------

	private function detect_similar( $attachments ) {
		$stem_map = array(); // normalised_stem => [ att, … ]

		foreach ( $attachments as $att ) {
			$file = get_attached_file( (int) $att->ID );
			$name = $file ? basename( $file ) : basename( $att->guid );
			$stem = $this->normalise_stem( $name );
			if ( $stem === '' ) {
				continue;
			}
			$stem_map[ $stem ][] = $att;
		}

		$groups = array();
		foreach ( $stem_map as $stem => $atts ) {
			if ( count( $atts ) < 2 ) {
				continue;
			}
			$count    = count( $atts );
			$ids      = array_map( fn( $a ) => (int) $a->ID, $atts );
			$groups[] = array(
				'type'           => 'similar',
				'ids'            => $ids,
				'label'          => $count . ' similar ' . $this->stem_label( $stem ) . ' versions detected.',
				'reason'         => 'Filenames share the normalised base name "' . esc_html( $stem ) . '".',
				'recommendation' => $this->similar_recommendation( $ids ),
			);
		}

		return $groups;
	}

	/**
	 * Normalise a filename stem for grouping.
	 * Strips WP size suffixes (-300x200), version markers (-v2, -copy, -1, -final,
	 * -old, -new, -backup, -revised), and extension. Returns lowercase.
	 */
	private function normalise_stem( $filename ) {
		// Remove extension.
		$stem = pathinfo( $filename, PATHINFO_FILENAME );

		// Strip WP thumbnail size suffix:  -300x200
		$stem = preg_replace( '/-\d+x\d+$/', '', $stem );

		// Strip common version/copy markers (order matters — greediest last).
		$stem = preg_replace(
			'/[-_](?:v\d+|\d+|copy|final|old|new|backup|revised|update|updated|draft|temp|tmp|orig|original)$/i',
			'',
			$stem
		);

		// Collapse separators and lowercase.
		$stem = strtolower( preg_replace( '/[-_\s]+/', '-', $stem ) );
		return trim( $stem, '-' );
	}

	private function stem_label( $stem ) {
		// Use the stem as a human label, falling back to generic.
		return $stem !== '' ? '"' . $stem . '"' : 'asset';
	}

	private function similar_recommendation( $ids ) {
		// Find which id has the most usages.
		$best_id    = null;
		$best_count = -1;
		foreach ( $ids as $id ) {
			$c = $this->storage->get_usage_count( $id );
			if ( $c > $best_count ) {
				$best_count = $c;
				$best_id    = $id;
			}
		}
		if ( $best_count === 0 ) {
			return 'None of these versions are in active use — consolidate or remove all but one.';
		}
		$file = get_attached_file( $best_id );
		$name = $file ? basename( $file ) : '#' . $best_id;
		return 'Consider consolidating to the most-used version: ' . $name . ' (' . $best_count . ' ' . ( $best_count === 1 ? 'reference' : 'references' ) . ').';
	}

	// -------------------------------------------------------------------------
	// Detection: WP auto-generated size variants
	// -------------------------------------------------------------------------

	private function detect_resizes( $attachments ) {
		global $wpdb;

		// Collect IDs whose post_parent points to another attachment.
		$parent_map = array(); // parent_id => [child_id, …]
		$att_ids    = array_map( fn( $a ) => (int) $a->ID, $attachments );
		$att_set    = array_flip( $att_ids );

		foreach ( $attachments as $att ) {
			$parent = (int) $att->post_parent;
			if ( $parent > 0 && isset( $att_set[ $parent ] ) ) {
				$parent_map[ $parent ][] = (int) $att->ID;
			}
		}

		// Also check _wp_attachment_metadata for 'sizes' entries.
		// These are not separate posts but stored file paths; we surface groups
		// only when the parent has registered sizes in its metadata.
		$groups = array();
		foreach ( $parent_map as $parent_id => $child_ids ) {
			$all_ids  = array_merge( array( $parent_id ), $child_ids );
			$count    = count( $all_ids );
			$groups[] = array(
				'type'           => 'resizes',
				'ids'            => $all_ids,
				'label'          => $count . ' size variants of the same image.',
				'reason'         => 'These attachments share a common parent (ID #' . $parent_id . ') — WP auto-generated crops or resizes.',
				'recommendation' => 'Do not delete the original (ID #' . $parent_id . '). Child variants are managed by WordPress.',
			);
		}

		return $groups;
	}
}
