<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

class CleanupSuggestions {

	// Days threshold: uploads newer than this get "Recently Uploaded"
	const RECENT_DAYS = 30;

	// Days threshold: uploads older than this AND unused → "Likely Unused"
	const OLD_DAYS = 90;

	private $storage;
	private $advisor;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
		require_once MUT_PLUGIN_DIR . 'includes/class-cleanup-advisor.php';
		$this->advisor = new CleanupAdvisor( $storage );
	}

	public function render() {
		$has_scan = $this->has_scan_data();
		?>
		<div class="wrap mut-cleanup">
			<h1>⚠️ Unused Files
				<?php
				require_once MUT_PLUGIN_DIR . 'includes/class-pdf-exporter.php';
				?>
				<a href="<?php echo esc_url( \MediaUsageTracker\Admin\PdfExporter::export_url( 'unused' ) ); ?>" class="button" style="margin-left:12px;" target="_blank">⬇ Export PDF</a>
			</h1>
			<div id="mut-cs-notice" class="hidden" style="margin-bottom:12px;"></div>
			<p class="mut-cleanup-intro">
				Based on your last scan, files are sorted into categories to help you safely free up space.
				<strong>Always back up before deleting.</strong>
			</p>

			<?php if ( ! $has_scan ) : ?>
				<div class="notice notice-warning inline">
					<p>No scan data found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=media-usage-tracker' ) ); ?>">Run a scan first</a> to generate cleanup suggestions.</p>
				</div>
			<?php else : ?>
				<?php $this->render_summary_bar(); ?>
				<?php $this->render_category( 'likely_unused',     'Unused',             'These files are not referenced anywhere and were uploaded over ' . self::OLD_DAYS . ' days ago. Safe candidates for deletion after review.' ); ?>
				<?php $this->render_category( 'recently_uploaded', 'Recently Uploaded',  'Uploaded in the last ' . self::RECENT_DAYS . ' days and not yet in use. Give these time before acting on them.' ); ?>
				<?php $this->render_category( 'safe_to_review',    'Review Recommended', 'Not currently in use, but uploaded between ' . self::RECENT_DAYS . '–' . self::OLD_DAYS . ' days ago. Verify before deleting.' ); ?>
			<?php endif; ?>
		</div>

		<?php $this->render_delete_modal(); ?>
		<?php $this->render_bulk_delete_modal(); ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Summary bar
	// -------------------------------------------------------------------------

	private function render_summary_bar() {
		$counts = $this->get_category_counts();
		$total  = array_sum( $counts );
		?>
		<div class="mut-cleanup-summary">
			<a href="#mut-section-likely_unused" class="mut-cs-pill mut-cs-likely_unused" style="text-decoration:none;color:inherit;">
				<span class="mut-cs-count"><?php echo number_format( $counts['likely_unused'] ); ?></span>
				<span class="mut-cs-label">Unused</span>
			</a>
			<a href="#mut-section-recently_uploaded" class="mut-cs-pill mut-cs-recently_uploaded" style="text-decoration:none;color:inherit;">
				<span class="mut-cs-count"><?php echo number_format( $counts['recently_uploaded'] ); ?></span>
				<span class="mut-cs-label">Recently Uploaded</span>
			</a>
			<a href="#mut-section-safe_to_review" class="mut-cs-pill mut-cs-safe_to_review" style="text-decoration:none;color:inherit;">
				<span class="mut-cs-count"><?php echo number_format( $counts['safe_to_review'] ); ?></span>
				<span class="mut-cs-label">Review Recommended</span>
			</a>
			<div class="mut-cs-pill mut-cs-total">
				<span class="mut-cs-count"><?php echo number_format( $total ); ?></span>
				<span class="mut-cs-label">Total Files</span>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Render a collapsible category section
	// -------------------------------------------------------------------------

	private function render_category( $category, $heading, $description ) {
		$items = $this->get_attachments_by_category( $category );
		$count = count( $items );
		$open  = in_array( $category, array( 'likely_unused', 'safe_to_review' ), true );
		?>
		<div id="mut-section-<?php echo esc_attr( $category ); ?>" class="mut-cs-section mut-cs-section--<?php echo esc_attr( $category ); ?>">
			<button
				class="mut-cs-toggle <?php echo $open ? 'open' : ''; ?>"
				aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
				data-target="mut-cs-body-<?php echo esc_attr( $category ); ?>"
			>
				<span class="mut-cs-toggle-heading"><?php echo esc_html( $heading ); ?></span>
				<span class="mut-cs-badge"><?php echo number_format( $count ); ?></span>
				<span class="mut-cs-chevron">▾</span>
			</button>
			<p class="mut-cs-desc"><?php echo esc_html( $description ); ?></p>

			<div id="mut-cs-body-<?php echo esc_attr( $category ); ?>" class="mut-cs-body <?php echo $open ? '' : 'hidden'; ?>">
				<?php if ( empty( $items ) ) : ?>
					<p class="mut-cs-empty">No files in this category.</p>
				<?php else : ?>
					<?php if ( $count > 0 ) : ?>
						<div class="mut-cs-bulk-bar mut-cs-bulk-bar--top">
							<button type="button"
								class="button button-primary mut-advice-bulk-act"
								data-target="mut-cs-body-<?php echo esc_attr( $category ); ?>"
								data-default-action="<?php echo $category === 'likely_unused' ? 'archive' : 'flag'; ?>">
								<?php echo $category === 'likely_unused' ? '📦 Archive all candidates' : '🚩 Flag all'; ?>
							</button>
							<button type="button"
								class="button button-link-delete mut-cs-bulk-delete"
								data-category="<?php echo esc_attr( $category ); ?>"
								disabled>
								🗑️ Delete Selected (<span class="mut-cs-sel-count" data-category="<?php echo esc_attr( $category ); ?>">0</span>)
							</button>
							<a href="<?php echo esc_url( $this->get_export_url( $category ) ); ?>" class="button">
								⬇ Export <?php echo esc_html( $count ); ?> files as CSV
							</a>
							<a href="<?php echo esc_url( $this->get_export_xlsx_url( $category ) ); ?>" class="button">
								⬇ Export <?php echo esc_html( $count ); ?> files as Excel
							</a>
						</div>
					<?php endif; ?>

					<div style="overflow-x:auto;">
					<table class="wp-list-table widefat striped mut-cs-table" id="mut-cs-table-<?php echo esc_attr( $category ); ?>">
						<thead>
							<tr>
								<th style="width:36px;"><input type="checkbox" class="mut-cs-select-all" data-category="<?php echo esc_attr( $category ); ?>" title="Select all"></th>
								<th style="width:70px;">Thumbnail</th>
								<th>Filename</th>
								<th style="width:140px;">Upload Date</th>
								<th style="width:100px;">File Size</th>
								<th style="width:80px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<tr data-id="<?php echo esc_attr( $item['id'] ); ?>" data-category="<?php echo esc_attr( $category ); ?>">
									<td class="mut-ud-cb-col"><input type="checkbox" class="mut-cs-select-row" data-category="<?php echo esc_attr( $category ); ?>" value="<?php echo esc_attr( $item['id'] ); ?>" data-name="<?php echo esc_attr( $item['filename'] ); ?>"></td>
									<td class="mut-td-thumb">
										<?php
										$thumb = wp_get_attachment_image( $item['id'], array( 55, 55 ), true, array(
											'style' => 'width:55px;height:55px;object-fit:cover;border-radius:4px;display:block;',
										) );
										echo $thumb ?: '<span class="mut-no-thumb"></span>';
										?>
									</td>
									<td class="mut-td-filename">
										<strong><?php echo esc_html( $item['filename'] ); ?></strong><br>
										<span class="mut-meta"><?php echo esc_html( $item['title'] ); ?></span>
									</td>
									<td class="mut-td-date"><?php echo esc_html( $item['modified_date'] ); ?></td>
									<td class="mut-td-size"><?php echo esc_html( $item['filesize'] ); ?></td>
									<td class="mut-td-actions mut-actions-cell">
										<button type="button" class="button button-small mut-delete-btn mut-act-desk"
											data-id="<?php echo esc_attr( $item['id'] ); ?>"
											data-name="<?php echo esc_attr( $item['filename'] ); ?>"
											title="Safely delete this file">🗑️</button>
										<div class="mut-mob-actions">
											<button type="button" class="mut-mob-btn mut-mob-btn-del mut-delete-btn"
												data-id="<?php echo esc_attr( $item['id'] ); ?>"
												data-name="<?php echo esc_attr( $item['filename'] ); ?>">Delete</button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
t				</div>

					<?php if ( $count > 0 ) : ?>
						<div class="mut-cs-bulk-bar">
							<button type="button"
								class="button button-primary mut-advice-bulk-act"
								data-target="mut-cs-body-<?php echo esc_attr( $category ); ?>"
								data-default-action="<?php echo $category === 'likely_unused' ? 'archive' : 'flag'; ?>">
								<?php echo $category === 'likely_unused' ? '📦 Archive all candidates' : '🚩 Flag all'; ?>
							</button>
							<button type="button"
								class="button button-link-delete mut-cs-bulk-delete"
								data-category="<?php echo esc_attr( $category ); ?>"
								disabled>
								🗑️ Delete Selected (<span class="mut-cs-sel-count" data-category="<?php echo esc_attr( $category ); ?>">0</span>)
							</button>
							<a href="<?php echo esc_url( $this->get_export_url( $category ) ); ?>" class="button">
								⬇ Export <?php echo esc_html( $count ); ?> files as CSV
							</a>
							<a href="<?php echo esc_url( $this->get_export_xlsx_url( $category ) ); ?>" class="button">
								⬇ Export <?php echo esc_html( $count ); ?> files as Excel
							</a>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data: classify all attachments
	// -------------------------------------------------------------------------

	/**
	 * Returns IDs → category map for all attachments.
	 */
	private function classify_all() {
		global $wpdb;

		$now         = current_time( 'timestamp' );
		$recent_secs = self::RECENT_DAYS * DAY_IN_SECONDS;
		$old_secs    = self::OLD_DAYS    * DAY_IN_SECONDS;

		// Fetch all attachments with their upload date
		$attachments = $wpdb->get_results(
			"SELECT ID, post_date FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'
			 ORDER BY post_date DESC"
		);

		// IDs that have at least one usage record
		$used_ids = $wpdb->get_col(
			"SELECT DISTINCT attachment_id FROM {$wpdb->prefix}mut_media_usage"
		);
		$used_set = array_flip( array_map( 'intval', $used_ids ) );

		$map = array();
		foreach ( $attachments as $att ) {
			$id  = (int) $att->ID;
			$age = $now - strtotime( $att->post_date );

			if ( isset( $used_set[ $id ] ) ) {
				$map[ $id ] = 'in_use';
			} elseif ( $age <= $recent_secs ) {
				$map[ $id ] = 'recently_uploaded';
			} elseif ( $age <= $old_secs ) {
				$map[ $id ] = 'safe_to_review';
			} else {
				$map[ $id ] = 'likely_unused';
			}
		}

		return $map;
	}

	/**
	 * Returns counts per category without building full attachment data.
	 */
	private function get_category_counts() {
		$map    = $this->classify_all();
		$counts = array(
			'likely_unused'     => 0,
			'safe_to_review'    => 0,
			'recently_uploaded' => 0,
		);
		foreach ( $map as $category ) {
			if ( isset( $counts[ $category ] ) ) {
				$counts[ $category ]++;
			}
		}
		return $counts;
	}

	/**
	 * Returns enriched attachment rows for one category.
	 */
	private function get_attachments_by_category( $category ) {
		$map  = $this->classify_all();
		$ids  = array_keys( array_filter( $map, fn( $c ) => $c === $category ) );

		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$file     = get_attached_file( $id );
			$has_file = $file && file_exists( $file );

			$upload_ts = strtotime( $post->post_date );

			$rows[] = array(
				'id'            => $id,
				'title'         => $post->post_title,
				'filename'      => $file ? basename( $file ) : '(missing)',
				'modified_ts'   => $upload_ts,
				'modified_date' => date_i18n( 'M j, Y', $upload_ts ),
				'filesize'      => $has_file ? size_format( filesize( $file ) ) : '—',
			);
		}

		// Surface the stalest files first (oldest last-modified date).
		usort( $rows, fn( $a, $b ) => $a['modified_ts'] <=> $b['modified_ts'] );

		return $rows;
	}

	// -------------------------------------------------------------------------
	// CSV Export
	// -------------------------------------------------------------------------

	public function handle_export() {
		$is_csv  = isset( $_GET['mut_cleanup_export'] );
		$is_xlsx = isset( $_GET['mut_cleanup_export_xlsx'] );

		if ( ! $is_csv && ! $is_xlsx ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$category = sanitize_key( $is_xlsx ? $_GET['mut_cleanup_export_xlsx'] : $_GET['mut_cleanup_export'] );
		$allowed  = array( 'likely_unused', 'safe_to_review', 'recently_uploaded' );
		if ( ! in_array( $category, $allowed, true ) ) {
			wp_die( 'Invalid category.' );
		}

		$items  = $this->get_attachments_by_category( $category );
		$header = array( 'ID', 'Title', 'Filename', 'URL', 'File Size', 'Upload Date' );

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				$item['id'],
				$item['title'],
				$item['filename'],
				wp_get_attachment_url( $item['id'] ),
				$item['filesize'],
				$item['modified_date'],
			);
		}

		if ( $is_xlsx ) {
			$xls = new \MediaUsageTracker\Excel_Export( 'Cleanup Suggestions' );
			$xls->add_header_row( $header );
			foreach ( $rows as $row ) {
				$xls->add_row( $row );
			}
			$xls->send( 'mut-' . $category . '-' . date( 'Y-m-d' ) . '.xlsx' );
		}

		// CSV
		$filename = 'mut-' . $category . '-' . date( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Map an advisor action key to a short human badge label. */
	private function action_label( $action ) {
		$labels = array(
			'archive' => 'Archive Candidate',
			'review'  => 'Review',
			'monitor' => 'Monitor',
			'keep'    => 'Keep',
		);
		return $labels[ $action ] ?? ucfirst( $action );
	}

	/**
	 * Render the one-click review action for a row's advisor cell.
	 * Maps the advisor verdict to a review status the existing mut_bulk_action
	 * AJAX endpoint already understands:
	 *   archive → 'archive' (📦 Archived)   review/monitor → 'flag' (🚩 Flagged)
	 * If a status is already set, show it instead with an undo control.
	 */
	private function render_advice_action( $id, $advice, $review_status ) {
		ob_start();

		// Already actioned → show the standing status + clear control.
		if ( $review_status ) {
			$label = $review_status === 'archived' ? '📦 Archived' : '🚩 Flagged';
			?>
			<span class="mut-advice-action mut-advice-action--done">
				<span class="mut-advice-status mut-advice-status--<?php echo esc_attr( $review_status ); ?>"><?php echo esc_html( $label ); ?></span>
				<button type="button" class="button-link mut-advice-clear" data-id="<?php echo esc_attr( $id ); ?>" data-action="clear">undo</button>
			</span>
			<?php
			return ob_get_clean();
		}

		// "keep" needs no action.
		if ( $advice['action'] === 'keep' ) {
			return ob_get_clean();
		}

		$is_archive = ( $advice['action'] === 'archive' );
		$bulk       = $is_archive ? 'archive' : 'flag';
		$btn_label  = $is_archive ? '📦 Flag for archive' : '🚩 Flag';
		?>
		<button type="button"
			class="button button-small mut-advice-act <?php echo $is_archive ? 'mut-advice-act--archive' : 'mut-advice-act--review'; ?>"
			data-id="<?php echo esc_attr( $id ); ?>"
			data-action="<?php echo esc_attr( $bulk ); ?>">
			<?php echo esc_html( $btn_label ); ?>
		</button>
		<?php
		return ob_get_clean();
	}

	private function render_delete_modal() {
		?>
		<div id="mut-delete-modal" class="mut-modal-overlay hidden" aria-hidden="true">
			<div class="mut-modal" role="dialog" aria-modal="true" aria-labelledby="mut-modal-title">
				<div class="mut-modal-header">
					<h2 id="mut-modal-title">🛡️ Safe Delete Check</h2>
					<button type="button" class="mut-modal-close" aria-label="Close">&times;</button>
				</div>
				<div class="mut-modal-body">
					<p class="mut-modal-file">Reviewing: <strong id="mut-modal-filename"></strong></p>
					<div id="mut-modal-checks" class="mut-modal-checks">
						<p class="mut-modal-loading">Running safety checks…</p>
					</div>
					<p id="mut-modal-verdict" class="mut-modal-verdict hidden"></p>
				</div>
				<div class="mut-modal-footer">
					<label id="mut-modal-force-wrap" class="mut-modal-force hidden">
						<input type="checkbox" id="mut-modal-force">
						Override safety checks and delete anyway
					</label>
					<div class="mut-modal-actions">
						<button type="button" class="button mut-modal-cancel">Cancel</button>
						<button type="button" class="button button-primary mut-modal-confirm" disabled>Move to Trash</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_bulk_delete_modal() {
		?>
		<div id="mut-cs-bulk-modal" class="mut-modal-overlay hidden" aria-hidden="true">
			<div class="mut-modal" role="dialog" aria-modal="true" style="max-width:600px;">
				<div class="mut-modal-header">
					<h2>🛡️ Bulk Safe Delete</h2>
					<button type="button" class="mut-cs-bulk-modal-close mut-modal-close" aria-label="Close">&times;</button>
				</div>
				<div class="mut-modal-body">
					<p id="mut-cs-bulk-status" style="margin-bottom:12px;"></p>
					<div id="mut-cs-bulk-list" style="max-height:320px;overflow-y:auto;"></div>
				</div>
				<div class="mut-modal-footer">
					<div class="mut-modal-actions">
						<button type="button" class="button mut-cs-bulk-modal-close">Cancel</button>
						<button type="button" class="button button-primary" id="mut-cs-bulk-confirm" disabled>Delete Safe Files</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function has_scan_data() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mut_scan_history WHERE status = 'completed'" ) > 0;
	}

	private function get_export_url( $category ) {
		return add_query_arg( array(
			'page'               => 'mut-cleanup',
			'mut_cleanup_export' => $category,
		), admin_url( 'admin.php' ) );
	}

	private function get_export_xlsx_url( $category ) {
		return add_query_arg( array(
			'page'                    => 'mut-cleanup',
			'mut_cleanup_export_xlsx' => $category,
		), admin_url( 'admin.php' ) );
	}
}
