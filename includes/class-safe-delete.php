<?php
namespace MediaUsageTracker\Core;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Safe Delete Workflow.
 *
 * Deletion of media is irreversible in stock WordPress (attachments have no
 * trash). This workflow adds three layers of safety:
 *
 *   1. verify()  — a multi-gate pre-flight check that re-confirms, LIVE, that
 *                  the file is genuinely unused before deletion is allowed.
 *                  This guards against stale scan data (a file used by a post
 *                  created after the last scan).
 *   2. trash()   — instead of unlinking the file, it is moved to a private
 *                  uploads/mut-trash/ directory and the attachment record is
 *                  removed. Every deletion is written to mut_deletion_log.
 *   3. restore() — re-creates the attachment from a trash-log record.
 *
 * The verify() gate logic is pure and side-effect free so it can be unit
 * tested independently of WordPress.
 */
class SafeDelete {

	const TRASH_DIR = 'mut-trash';

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	// -------------------------------------------------------------------------
	// Verification gate (pure logic — testable)
	// -------------------------------------------------------------------------

	/**
	 * Run all safety checks for an attachment.
	 *
	 * @param int $attachment_id
	 * @return array {
	 *   @type bool   $safe        True only if no blocking check failed.
	 *   @type int    $blocking    Count of failed blocking checks.
	 *   @type array  $checks      List of [ key, label, status, blocking, detail ].
	 * }
	 */
	public function verify( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$checks        = array();

		// Gate 1: the attachment must actually exist.
		$is_attachment = $this->is_valid_attachment( $attachment_id );
		$checks[]      = array(
			'key'      => 'exists',
			'label'    => 'File exists in Media Library',
			'status'   => $is_attachment ? 'pass' : 'fail',
			'blocking' => true,
			'detail'   => $is_attachment ? '' : 'Attachment not found.',
		);

		// If it doesn't even exist, stop here — the rest is meaningless.
		if ( ! $is_attachment ) {
			return $this->summarize( $checks );
		}

		// Gate 2: no usage records from the last scan.
		$scan_usage = $this->storage->get_usage_count( $attachment_id );
		$checks[]   = array(
			'key'      => 'scan_usage',
			'label'    => 'No usage found in last scan',
			'status'   => $scan_usage === 0 ? 'pass' : 'fail',
			'blocking' => true,
			'detail'   => $scan_usage > 0 ? sprintf( 'Used in %d place(s) per last scan.', $scan_usage ) : '',
		);

		// Gate 3: LIVE re-check of post content / featured images right now.
		// Catches usage added since the last scan ran.
		$live = $this->count_live_references( $attachment_id );
		$checks[] = array(
			'key'      => 'live_refs',
			'label'    => 'No live references found right now',
			'status'   => $live === 0 ? 'pass' : 'fail',
			'blocking' => true,
			'detail'   => $live > 0 ? sprintf( 'Found %d live reference(s) in current content.', $live ) : '',
		);

		// Gate 4 (advisory, non-blocking): scan freshness.
		$stale    = $this->scan_is_stale();
		$checks[] = array(
			'key'      => 'scan_fresh',
			'label'    => 'Scan data is recent',
			'status'   => $stale ? 'warn' : 'pass',
			'blocking' => false,
			'detail'   => $stale ? 'Last scan is over 30 days old — consider re-scanning.' : '',
		);

		return $this->summarize( $checks );
	}

	/**
	 * Reduce a check list to a pass/fail summary.
	 */
	private function summarize( $checks ) {
		$blocking = 0;
		foreach ( $checks as $c ) {
			if ( $c['blocking'] && $c['status'] === 'fail' ) {
				$blocking++;
			}
		}
		return array(
			'safe'     => $blocking === 0,
			'blocking' => $blocking,
			'checks'   => $checks,
		);
	}

	/**
	 * Count live references to an attachment in current post content and as a
	 * featured image. This is a focused, on-demand check (not a full scan).
	 *
	 * @return int
	 */
	public function count_live_references( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return 0;
		}

		$count = 0;

		// Featured image usage.
		$as_thumb = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
			$attachment_id
		) );
		$count += $as_thumb;

		// Content references: by URL and by Gutenberg/classic ID markers.
		$url = wp_get_attachment_url( $attachment_id );
		$like_terms = array();
		if ( $url ) {
			$like_terms[] = '%' . $wpdb->esc_like( $url ) . '%';
		}
		$like_terms[] = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';
		$like_terms[] = '%' . $wpdb->esc_like( 'wp:image {"id":' . $attachment_id ) . '%';

		$clauses = array();
		$params  = array();
		foreach ( $like_terms as $term ) {
			$clauses[] = 'post_content LIKE %s';
			$params[]  = $term;
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts}
		        WHERE post_status IN ('publish','draft','private','future','pending')
		          AND ( " . implode( ' OR ', $clauses ) . ' )';

		$content_refs = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		$count += $content_refs;

		return $count;
	}

	// -------------------------------------------------------------------------
	// Trash / restore
	// -------------------------------------------------------------------------

	/**
	 * Safely "delete" an attachment: verify, move its files to the trash dir,
	 * log it, then remove the attachment record. Returns a result array.
	 *
	 * @param int  $attachment_id
	 * @param bool $force  Bypass the verify() gate (explicit override only).
	 * @return array [ success(bool), message(string), log_id(int|null) ]
	 */
	public function trash( $attachment_id, $force = false ) {
		$attachment_id = absint( $attachment_id );

		$result = $this->verify( $attachment_id );
		if ( ! $result['safe'] && ! $force ) {
			return array(
				'success' => false,
				'message' => sprintf( '%d safety check(s) failed. Deletion blocked.', $result['blocking'] ),
				'log_id'  => null,
			);
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'success' => false,
				'message' => 'Source file not found on disk.',
				'log_id'  => null,
			);
		}

		$trash_path = $this->move_to_trash_dir( $file, $attachment_id );
		if ( ! $trash_path ) {
			return array(
				'success' => false,
				'message' => 'Could not move file to trash. Deletion aborted.',
				'log_id'  => null,
			);
		}

		$log_id = $this->storage->log_deletion( array(
			'attachment_id' => $attachment_id,
			'file_name'     => basename( $file ),
			'original_path' => $file,
			'trash_path'    => $trash_path,
			'file_size'     => (int) ( @filesize( $trash_path ) ?: 0 ),
			'title'         => get_the_title( $attachment_id ),
			'mime_type'     => get_post_mime_type( $attachment_id ),
			'deleted_by'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
		) );

		// Remove the attachment record (files already relocated, so pass true to
		// clean up any leftover metadata / sized images WP knows about).
		wp_delete_attachment( $attachment_id, true );

		// Clean up any review status for the now-gone attachment.
		$this->storage->clear_review_status( $attachment_id );

		return array(
			'success' => true,
			'message' => 'File moved to trash. You can restore it within the retention window.',
			'log_id'  => $log_id,
		);
	}

	/**
	 * Restore a previously trashed file from its deletion-log record.
	 *
	 * @param int $log_id
	 * @return array [ success(bool), message(string), attachment_id(int|null) ]
	 */
	public function restore( $log_id ) {
		$record = $this->storage->get_deletion_record( absint( $log_id ) );
		if ( ! $record ) {
			return array( 'success' => false, 'message' => 'Deletion record not found.', 'attachment_id' => null );
		}

		if ( empty( $record->trash_path ) || ! file_exists( $record->trash_path ) ) {
			return array( 'success' => false, 'message' => 'Trashed file is no longer available.', 'attachment_id' => null );
		}

		// Move the file back to its original location.
		$dest = $record->original_path;
		wp_mkdir_p( dirname( $dest ) );
		if ( ! @rename( $record->trash_path, $dest ) ) {
			return array( 'success' => false, 'message' => 'Could not restore file to its original location.', 'attachment_id' => null );
		}

		// Re-create the attachment post.
		$attachment_id = wp_insert_attachment( array(
			'post_title'     => $record->title,
			'post_mime_type' => $record->mime_type,
			'post_status'    => 'inherit',
		), $dest );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return array( 'success' => false, 'message' => 'File restored but attachment record could not be created.', 'attachment_id' => null );
		}

		// Regenerate metadata / sized images.
		$image_helpers = ABSPATH . 'wp-admin/includes/image.php';
		if ( file_exists( $image_helpers ) ) {
			require_once $image_helpers;
		}
		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $dest ) );
		}

		$this->storage->remove_deletion_record( $record->id );

		return array(
			'success'       => true,
			'message'       => 'File restored to the Media Library.',
			'attachment_id' => (int) $attachment_id,
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Move a file into the private trash directory. Returns the new path or ''.
	 */
	private function move_to_trash_dir( $file, $attachment_id ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::TRASH_DIR;
		wp_mkdir_p( $dir );

		// Drop a .htaccess + index to keep the trash dir private.
		$this->protect_dir( $dir );

		// Prefix with id + timestamp to avoid collisions.
		$dest = trailingslashit( $dir ) . $attachment_id . '-' . time() . '-' . basename( $file );

		return @rename( $file, $dest ) ? $dest : '';
	}

	/**
	 * Write deny-all guards into the trash directory (once).
	 */
	private function protect_dir( $dir ) {
		$ht = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "Deny from all\n" );
		}
		$idx = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, "<?php // Silence is golden.\n" );
		}
	}

	private function is_valid_attachment( $attachment_id ) {
		if ( ! $attachment_id ) {
			return false;
		}
		$post = get_post( $attachment_id );
		return $post && $post->post_type === 'attachment';
	}

	/**
	 * Is the most recent completed scan older than 30 days (or absent)?
	 */
	private function scan_is_stale() {
		$history = $this->storage->get_scan_history();
		if ( empty( $history ) ) {
			return true;
		}
		$latest = $history[0];
		$when   = ! empty( $latest->completed_at ) ? strtotime( $latest->completed_at ) : 0;
		if ( ! $when ) {
			return true;
		}
		return ( time() - $when ) > 30 * DAY_IN_SECONDS;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function handle_verify() {
		check_ajax_referer( 'mut_bulk_review_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( 'Missing attachment ID.' );
		}
		wp_send_json_success( $this->verify( $id ) );
	}

	public function handle_delete() {
		check_ajax_referer( 'mut_bulk_review_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$force = ! empty( $_POST['force'] );
		if ( ! $id ) {
			wp_send_json_error( 'Missing attachment ID.' );
		}
		$result = $this->trash( $id, $force );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result['message'] );
	}

	public function handle_restore() {
		check_ajax_referer( 'mut_bulk_review_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		if ( ! $log_id ) {
			wp_send_json_error( 'Missing log ID.' );
		}
		$result = $this->restore( $log_id );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result['message'] );
	}

	public function handle_permanently_delete() {
		check_ajax_referer( 'mut_bulk_review_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$log_ids = isset( $_POST['log_ids'] ) ? array_map( 'absint', (array) $_POST['log_ids'] ) : array();
		if ( empty( $log_ids ) ) {
			wp_send_json_error( 'No items specified.' );
		}
		$deleted = 0;
		$failed  = 0;
		foreach ( $log_ids as $log_id ) {
			$result = $this->permanently_delete( $log_id );
			$result['success'] ? $deleted++ : $failed++;
		}
		wp_send_json_success( array( 'deleted' => $deleted, 'failed' => $failed ) );
	}

	public function permanently_delete( $log_id ) {
		$record = $this->storage->get_deletion_record( absint( $log_id ) );
		if ( ! $record ) {
			return array( 'success' => false, 'message' => 'Deletion record not found.' );
		}
		if ( ! empty( $record->trash_path ) && file_exists( $record->trash_path ) ) {
			@unlink( $record->trash_path );
		}
		$this->storage->remove_deletion_record( $record->id );
		return array( 'success' => true );
	}
}
