<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * PDF Exporter — Phase 1 (browser print-to-PDF).
 *
 * Renders a clean standalone HTML page for three report types:
 *   full    — complete media library summary + file list
 *   unused  — unused files only + reclaimable storage
 *   quality — quality audit findings by severity
 *
 * Phase 2 upgrade path: replace output() with an mPDF call — data and
 * templates stay identical, only the last step changes.
 */
class PdfExporter {

	const PARAM_EXPORT = 'mut_export';
	const PARAM_REPORT = 'mut_report';

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Hook into admin_init — intercepts export requests before any output.
	 */
	public function handle_export() {
		if ( ! isset( $_GET[ self::PARAM_EXPORT ] ) || $_GET[ self::PARAM_EXPORT ] !== 'pdf' ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'mut_pdf_export' );

		$report = sanitize_key( $_GET[ self::PARAM_REPORT ] ?? 'full' );
		$this->output( $report );
		exit;
	}

	/**
	 * Generate the export URL for a given report type.
	 */
	public static function export_url( $report ) {
		return wp_nonce_url(
			add_query_arg( array(
				self::PARAM_EXPORT => 'pdf',
				self::PARAM_REPORT => $report,
			), admin_url( 'admin.php' ) ),
			'mut_pdf_export'
		);
	}

	// =========================================================================
	// Output
	// =========================================================================

	private function output( $report ) {
		$site_name = get_bloginfo( 'name' );
		$generated = ( new \DateTime( 'now', wp_timezone() ) )->format( 'F j, Y g:i A' );
		$title     = $this->report_title( $report );

		$body = $this->render_body( $report );

		// Phase 2 hook: replace everything below with mPDF::WriteHTML($html) + Output().
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
		echo '<title>' . esc_html( $title . ' — ' . $site_name ) . '</title>';
		echo '<style>' . $this->print_css() . '</style>';
		echo '</head><body>';
		echo '<div class="mut-pdf-header">';
		echo '<div class="mut-pdf-site">' . esc_html( $site_name ) . '</div>';
		echo '<div class="mut-pdf-title">' . esc_html( $title ) . '</div>';
		echo '<div class="mut-pdf-meta">Generated: ' . esc_html( $generated ) . '</div>';
		echo '</div>';
		echo $body;
		echo '<div class="mut-pdf-footer">Media Usage Tracker &mdash; ' . esc_html( $site_name ) . ' &mdash; ' . esc_html( $generated ) . '</div>';
		echo '<script>window.onload=function(){window.print();}</script>';
		echo '</body></html>';
	}

	// =========================================================================
	// Report bodies
	// =========================================================================

	private function render_body( $report ) {
		switch ( $report ) {
			case 'unused':        return $this->body_unused();
			case 'quality':       return $this->body_quality();
			case 'bulk_review':   return $this->body_bulk_review();
			case 'scan_history':  return $this->body_scan_history();
			default:              return $this->body_full();
		}
	}

	private function body_full() {
		global $wpdb;

		$total     = $this->storage->get_total_media_count();
		$in_use    = $this->storage->get_files_in_use_count();
		$unused    = $total - $in_use;
		$bytes     = $this->storage->get_storage_usage();
		$last_scan = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}mut_scan_history ORDER BY started_at DESC LIMIT 1" );

		$attachments = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			 ORDER BY p.post_date DESC"
		);

		$used_ids = array_flip( array_map( 'intval',
			$wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$wpdb->prefix}mut_media_usage" )
		) );

		ob_start();
		?>
		<div class="mut-pdf-summary-grid">
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $total ); ?></div>
				<div class="mut-pdf-stat-label">Total Files</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $in_use ); ?></div>
				<div class="mut-pdf-stat-label">In Use</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $unused ); ?></div>
				<div class="mut-pdf-stat-label">Unused</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo esc_html( size_format( $bytes ) ); ?></div>
				<div class="mut-pdf-stat-label">Total Storage</div>
			</div>
		</div>

		<?php if ( $last_scan ) : ?>
		<p class="mut-pdf-scan-note">
			Last scan: <?php echo esc_html( ( new \DateTime( $last_scan->started_at, wp_timezone() ) )->format( 'M j, Y g:i A' ) ); ?>
			&mdash; <?php echo number_format( (int) $last_scan->total_attachments ); ?> files scanned.
		</p>
		<?php endif; ?>

		<table class="mut-pdf-table">
			<thead>
				<tr>
					<th>#</th>
					<th>Filename</th>
					<th>Type</th>
					<th>Upload Date</th>
					<th>File Size</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<?php $i = 1; foreach ( $attachments as $att ) :
					$id     = (int) $att->ID;
					$file   = get_attached_file( $id );
					$size   = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—';
					$status = isset( $used_ids[ $id ] ) ? 'In Use' : 'Unused';
					$name   = basename( $file ?: $att->post_title );
					$date   = ( new \DateTime( $att->post_date, wp_timezone() ) )->format( 'M j, Y' );
				?>
				<tr class="<?php echo $status === 'Unused' ? 'mut-pdf-row-unused' : ''; ?>">
					<td><?php echo $i++; ?></td>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo esc_html( $this->mime_short( $att->post_mime_type ) ); ?></td>
					<td><?php echo esc_html( $date ); ?></td>
					<td><?php echo esc_html( $size ); ?></td>
					<td class="<?php echo $status === 'Unused' ? 'mut-pdf-unused' : 'mut-pdf-inuse'; ?>">
						<?php echo esc_html( $status ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	private function body_unused() {
		$unused_ids    = $this->storage->get_unused_attachments();
		$reclaimable   = $this->storage->get_unused_storage_usage();

		ob_start();
		?>
		<div class="mut-pdf-summary-grid">
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( count( $unused_ids ) ); ?></div>
				<div class="mut-pdf-stat-label">Unused Files</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo esc_html( size_format( $reclaimable ) ); ?></div>
				<div class="mut-pdf-stat-label">Reclaimable Storage</div>
			</div>
		</div>

		<?php if ( empty( $unused_ids ) ) : ?>
			<p style="text-align:center;padding:32px 0;color:#1a7a3a;font-weight:600;">
				✓ No unused files found. Your media library is clean.
			</p>
		<?php else : ?>
		<table class="mut-pdf-table">
			<thead>
				<tr>
					<th>#</th>
					<th>Filename</th>
					<th>Type</th>
					<th>Upload Date</th>
					<th>File Size</th>
				</tr>
			</thead>
			<tbody>
				<?php $i = 1; foreach ( $unused_ids as $id ) :
					$id   = (int) $id;
					$post = get_post( $id );
					if ( ! $post ) continue;
					$file = get_attached_file( $id );
					$size = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—';
					$name = basename( $file ?: $post->post_title );
					$date = ( new \DateTime( $post->post_date, wp_timezone() ) )->format( 'M j, Y' );
				?>
				<tr>
					<td><?php echo $i++; ?></td>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo esc_html( $this->mime_short( $post->post_mime_type ) ); ?></td>
					<td><?php echo esc_html( $date ); ?></td>
					<td><?php echo esc_html( $size ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private function body_quality() {
		require_once MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';
		$auditor = new QualityAuditor( $this->storage );
		$report  = $auditor->get_report();

		$sev_labels = array(
			'high'   => 'High',
			'medium' => 'Medium',
			'low'    => 'Low',
		);

		ob_start();
		?>
		<div class="mut-pdf-summary-grid">
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $report['total'] ); ?></div>
				<div class="mut-pdf-stat-label">Files Audited</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $report['total_issues'] ); ?></div>
				<div class="mut-pdf-stat-label">Issues Found</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $report['clean'] ); ?></div>
				<div class="mut-pdf-stat-label">Clean Files</div>
			</div>
			<?php foreach ( array( 'high', 'medium', 'low' ) as $sev ) : ?>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num mut-pdf-sev-<?php echo $sev; ?>"><?php echo number_format( $report['severity_counts'][ $sev ] ?? 0 ); ?></div>
				<div class="mut-pdf-stat-label"><?php echo $sev_labels[ $sev ]; ?> Issues</div>
			</div>
			<?php endforeach; ?>
		</div>

		<?php foreach ( $report['checks'] as $check ) :
			if ( $check['count'] === 0 ) continue;
			$sev = $check['severity'];
		?>
		<div class="mut-pdf-quality-section mut-pdf-sev-border-<?php echo esc_attr( $sev ); ?>">
			<div class="mut-pdf-quality-head">
				<span class="mut-pdf-sev-tag mut-pdf-sev-tag-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( strtoupper( $sev ) ); ?></span>
				<strong><?php echo esc_html( $check['label'] ); ?></strong>
				<span class="mut-pdf-quality-count"><?php echo number_format( $check['count'] ); ?> file(s)</span>
			</div>
			<p class="mut-pdf-quality-desc"><?php echo esc_html( $check['description'] ); ?></p>

			<?php if ( ! empty( $check['items'] ) ) :
				$sample = array_slice( $check['items'], 0, 20 );
			?>
			<table class="mut-pdf-table mut-pdf-table-sm">
				<thead>
					<tr><th>#</th><th>Filename</th><th>Type</th><th>File Size</th></tr>
				</thead>
				<tbody>
					<?php $i = 1; foreach ( $sample as $id ) :
						$file = get_attached_file( $id );
						$name = basename( $file ?: ( get_the_title( $id ) ?: 'ID ' . $id ) );
						$mime = get_post_mime_type( $id );
						$size = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—';
					?>
					<tr>
						<td><?php echo $i++; ?></td>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( $this->mime_short( $mime ) ); ?></td>
						<td><?php echo esc_html( $size ); ?></td>
					</tr>
					<?php endforeach; ?>
					<?php if ( $check['count'] > 20 ) : ?>
					<tr><td colspan="4" style="color:#787c82;font-style:italic;">
						… and <?php echo number_format( $check['count'] - 20 ); ?> more files not shown.
					</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function body_bulk_review() {
		$counts   = $this->storage->get_review_counts();
		$flagged  = $this->storage->get_review_items( 'flagged' );
		$archived = $this->storage->get_review_items( 'archived' );
		$all      = array_merge( $flagged, $archived );

		ob_start();
		?>
		<div class="mut-pdf-summary-grid">
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( count( $all ) ); ?></div>
				<div class="mut-pdf-stat-label">Total Reviewed</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num mut-pdf-sev-high"><?php echo number_format( $counts['flagged'] ?? 0 ); ?></div>
				<div class="mut-pdf-stat-label">Flagged for Review</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num mut-pdf-sev-low"><?php echo number_format( $counts['archived'] ?? 0 ); ?></div>
				<div class="mut-pdf-stat-label">Archived</div>
			</div>
		</div>

		<?php if ( empty( $all ) ) : ?>
			<p style="text-align:center;padding:32px 0;color:#787c82;">No files have been flagged or archived yet.</p>
		<?php else :
			foreach ( array(
				'flagged'  => array( 'label' => 'Flagged for Review', 'items' => $flagged, 'cls' => 'high' ),
				'archived' => array( 'label' => 'Archived',           'items' => $archived, 'cls' => 'low' ),
			) as $group ) :
				if ( empty( $group['items'] ) ) continue;
			?>
			<div class="mut-pdf-quality-section mut-pdf-sev-border-<?php echo esc_attr( $group['cls'] ); ?>" style="margin-bottom:20px;">
				<div class="mut-pdf-quality-head">
					<span class="mut-pdf-sev-tag mut-pdf-sev-tag-<?php echo esc_attr( $group['cls'] ); ?>">
						<?php echo esc_html( strtoupper( $group['cls'] ) ); ?>
					</span>
					<strong><?php echo esc_html( $group['label'] ); ?></strong>
					<span class="mut-pdf-quality-count"><?php echo number_format( count( $group['items'] ) ); ?> file(s)</span>
				</div>

				<table class="mut-pdf-table" style="margin-top:10px;">
					<thead>
						<tr>
							<th>#</th>
							<th>Filename</th>
							<th>Type</th>
							<th>Upload Date</th>
							<th>File Size</th>
							<th>Flagged On</th>
						</tr>
					</thead>
					<tbody>
						<?php $i = 1; foreach ( $group['items'] as $row ) :
							$id      = (int) $row->attachment_id;
							$post    = get_post( $id );
							if ( ! $post ) continue;
							$file    = get_attached_file( $id );
							$name    = basename( $file ?: $post->post_title );
							$size    = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—';
							$mime    = $this->mime_short( $post->post_mime_type );
							$upload  = ( new \DateTime( $post->post_date, wp_timezone() ) )->format( 'M j, Y' );
							$flagged = isset( $row->flagged_at )
								? ( new \DateTime( $row->flagged_at, wp_timezone() ) )->format( 'M j, Y' )
								: '—';
						?>
						<tr>
							<td><?php echo $i++; ?></td>
							<td><?php echo esc_html( $name ); ?></td>
							<td><?php echo esc_html( $mime ); ?></td>
							<td><?php echo esc_html( $upload ); ?></td>
							<td><?php echo esc_html( $size ); ?></td>
							<td><?php echo esc_html( $flagged ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private function body_scan_history() {
		global $wpdb;

		$total     = $this->storage->get_total_media_count();
		$in_use    = $this->storage->get_files_in_use_count();
		$unused    = $total - $in_use;
		$bytes     = $this->storage->get_storage_usage();

		$scans = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}mut_scan_history ORDER BY started_at DESC"
		);

		ob_start();
		?>
		<div class="mut-pdf-summary-grid">
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( $total ); ?></div>
				<div class="mut-pdf-stat-label">Total Media Files</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num" style="color:#1a7a3a;"><?php echo number_format( $in_use ); ?></div>
				<div class="mut-pdf-stat-label">Active Files</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num mut-pdf-sev-high"><?php echo number_format( $unused ); ?></div>
				<div class="mut-pdf-stat-label">Unused Files</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo esc_html( size_format( $bytes ) ); ?></div>
				<div class="mut-pdf-stat-label">Total Storage</div>
			</div>
			<div class="mut-pdf-stat">
				<div class="mut-pdf-stat-num"><?php echo number_format( count( $scans ) ); ?></div>
				<div class="mut-pdf-stat-label">Total Scans Run</div>
			</div>
		</div>

		<?php if ( empty( $scans ) ) : ?>
			<p style="text-align:center;padding:32px 0;color:#787c82;">No scans have been run yet.</p>
		<?php else : ?>
		<table class="mut-pdf-table">
			<thead>
				<tr>
					<th>#</th>
					<th>Date &amp; Time</th>
					<th>Status</th>
					<th>Total Scanned</th>
					<th>In Use</th>
					<th>Unused</th>
				</tr>
			</thead>
			<tbody>
				<?php $i = 1; foreach ( $scans as $scan ) :
					$dt = new \DateTime( $scan->started_at, wp_timezone() );
				?>
				<tr>
					<td><?php echo $i++; ?></td>
					<td><?php echo esc_html( $dt->format( 'M j, Y g:i A' ) ); ?></td>
					<td style="color:<?php echo $scan->status === 'completed' ? '#1a7a3a' : '#b32d2e'; ?>;font-weight:600;">
						<?php echo esc_html( ucfirst( $scan->status ) ); ?>
					</td>
					<td><?php echo number_format( (int) $scan->total_attachments ); ?></td>
					<td style="color:#1a7a3a;"><?php echo number_format( (int) $scan->files_in_use ); ?></td>
					<td style="color:#b32d2e;"><?php echo number_format( (int) $scan->unused_files ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	private function report_title( $report ) {
		$titles = array(
			'full'         => 'Full Media Report',
			'unused'       => 'Unused Files Report',
			'quality'      => 'Quality Audit Report',
			'bulk_review'  => 'Bulk Review Report',
			'scan_history' => 'Scan History Report',
		);
		return $titles[ $report ] ?? 'Media Report';
	}

	private function mime_short( $mime ) {
		$map = array(
			'image/jpeg'      => 'JPEG',
			'image/png'       => 'PNG',
			'image/gif'       => 'GIF',
			'image/webp'      => 'WebP',
			'image/svg+xml'   => 'SVG',
			'video/mp4'       => 'MP4',
			'audio/mpeg'      => 'MP3',
			'application/pdf' => 'PDF',
		);
		return $map[ strtolower( (string) $mime ) ] ?? strtoupper( explode( '/', (string) $mime )[1] ?? $mime );
	}

	// =========================================================================
	// Print CSS — Phase 2: feed this same string to mPDF::WriteHTML()
	// =========================================================================

	private function print_css() {
		return '
		* { box-sizing: border-box; margin: 0; padding: 0; }
		body { font-family: Arial, sans-serif; font-size: 12px; color: #1d2327; background: #fff; padding: 24px 32px; }

		.mut-pdf-header { border-bottom: 2px solid #2271b1; padding-bottom: 12px; margin-bottom: 20px; }
		.mut-pdf-site   { font-size: 11px; color: #787c82; text-transform: uppercase; letter-spacing: .06em; }
		.mut-pdf-title  { font-size: 22px; font-weight: 700; color: #1d2327; margin: 4px 0 2px; }
		.mut-pdf-meta   { font-size: 11px; color: #787c82; }

		.mut-pdf-summary-grid {
			display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;
		}
		.mut-pdf-stat {
			flex: 1; min-width: 100px; border: 1px solid #e2e4e7;
			border-radius: 6px; padding: 12px 16px; text-align: center;
		}
		.mut-pdf-stat-num   { font-size: 24px; font-weight: 700; color: #2271b1; }
		.mut-pdf-stat-label { font-size: 11px; color: #787c82; margin-top: 2px; }

		.mut-pdf-sev-high   { color: #d63638 !important; }
		.mut-pdf-sev-medium { color: #8a6d0b !important; }
		.mut-pdf-sev-low    { color: #2c5d8f !important; }

		.mut-pdf-scan-note { font-size: 11px; color: #787c82; margin-bottom: 14px; }

		.mut-pdf-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
		.mut-pdf-table th {
			background: #f6f7f7; border: 1px solid #e2e4e7;
			padding: 6px 8px; text-align: left; font-size: 11px;
			text-transform: uppercase; letter-spacing: .04em; color: #50575e;
		}
		.mut-pdf-table td { border: 1px solid #e2e4e7; padding: 5px 8px; font-size: 11px; }
		.mut-pdf-table tr:nth-child(even) td { background: #f9f9f9; }
		.mut-pdf-table-sm td, .mut-pdf-table-sm th { padding: 4px 6px; font-size: 10px; }

		.mut-pdf-unused { color: #b32d2e; font-weight: 600; }
		.mut-pdf-inuse  { color: #1a7a3a; font-weight: 600; }
		.mut-pdf-row-unused td { background: #fdf2f2 !important; }

		.mut-pdf-quality-section {
			border: 1px solid #e2e4e7; border-radius: 6px;
			padding: 14px 16px; margin-bottom: 16px;
			page-break-inside: avoid;
		}
		.mut-pdf-sev-border-high   { border-left: 4px solid #d63638; }
		.mut-pdf-sev-border-medium { border-left: 4px solid #dba617; }
		.mut-pdf-sev-border-low    { border-left: 4px solid #2c5d8f; }

		.mut-pdf-quality-head {
			display: flex; align-items: center; gap: 10px; margin-bottom: 6px;
		}
		.mut-pdf-sev-tag {
			font-size: 10px; font-weight: 700; padding: 2px 6px;
			border-radius: 3px; letter-spacing: .04em;
		}
		.mut-pdf-sev-tag-high   { background: #fcebec; color: #b32d2e; }
		.mut-pdf-sev-tag-medium { background: #fcf3d7; color: #8a6d0b; }
		.mut-pdf-sev-tag-low    { background: #e8f0fa; color: #2c5d8f; }
		.mut-pdf-quality-count  { margin-left: auto; font-size: 11px; color: #787c82; }
		.mut-pdf-quality-desc   { font-size: 11px; color: #50575e; margin: 4px 0 10px; }

		.mut-pdf-footer {
			margin-top: 32px; padding-top: 10px;
			border-top: 1px solid #e2e4e7;
			font-size: 10px; color: #a0a5aa; text-align: center;
		}

		@media print {
			body { padding: 0; }
			@page { margin: 18mm 14mm; }
			.mut-pdf-quality-section { page-break-inside: avoid; }
			tr { page-break-inside: avoid; }
		}
		';
	}
}
