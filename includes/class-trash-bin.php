<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Trash Bin admin page.
 *
 * Lists every file removed via the Safe Delete Workflow, with one-click
 * Restore. Proves to the user that "delete" in this plugin is recoverable —
 * the core credibility win of Safe Delete.
 */
class TrashBin {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function render() {
		$items = $this->storage->get_deletion_log();
		$total_bytes = 0;
		foreach ( $items as $item ) {
			$total_bytes += (int) $item->file_size;
		}
		?>
		<div class="wrap mut-trash">
			<h1>🗑️ Trash</h1>
			<p class="mut-trash-intro">
				Files removed via Safe Delete are moved here, not permanently erased.
				Restore any item to return it to your Media Library.
			</p>

			<div id="mut-trash-notice" class="mut-bulk-notice hidden"></div>

			<?php if ( empty( $items ) ) : ?>
				<div class="mut-no-results">
					<p>Trash is empty. Nothing has been safely deleted yet.</p>
				</div>
			<?php else : ?>
				<div class="mut-trash-summary">
					<span><strong><?php echo count( $items ); ?></strong> file<?php echo count( $items ) !== 1 ? 's' : ''; ?> in trash</span>
					<span><strong><?php echo esc_html( size_format( $total_bytes ) ); ?></strong> reclaimable</span>
				</div>

					<!-- Bulk toolbar -->
				<div class="mut-trash-bulk-bar" id="mut-trash-bulk-bar" style="display:none;">
					<span id="mut-trash-sel-count">0</span> selected
					<button type="button" class="button button-small mut-trash-bulk-restore" style="margin-left:10px;">↩ Restore Selected</button>
					<button type="button" class="button button-small mut-trash-bulk-delete" style="margin-left:6px;color:#b32d2e;border-color:#b32d2e;">🗑 Delete Permanently</button>
				</div>

				<div style="overflow-x:auto;">
				<table class="wp-list-table widefat striped mut-trash-table">
					<thead>
						<tr>
							<th style="width:36px;"><input type="checkbox" id="mut-trash-cb-all" title="Select all"></th>
							<th>File</th>
							<th style="width:100px;">Type</th>
							<th style="width:90px;">Size</th>
							<th style="width:160px;">Deleted</th>
							<th style="width:120px;">Status</th>
							<th style="width:160px;">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) :
							$available = ! empty( $item->trash_path ) && file_exists( $item->trash_path );
							?>
							<tr data-log-id="<?php echo esc_attr( $item->id ); ?>">
								<td><input type="checkbox" class="mut-trash-cb-row" value="<?php echo esc_attr( $item->id ); ?>"></td>
								<td>
									<strong><?php echo esc_html( $item->file_name ); ?></strong>
									<?php if ( $item->title && $item->title !== $item->file_name ) : ?>
										<br><span class="mut-meta"><?php echo esc_html( $item->title ); ?></span>
									<?php endif; ?>
								</td>
								<td><span class="mut-cs-mime"><?php echo esc_html( $this->mime_label( $item->mime_type ) ); ?></span></td>
								<td><span class="mut-meta"><?php echo esc_html( size_format( (int) $item->file_size ) ); ?></span></td>
								<td><span class="mut-meta"><?php echo esc_html( $this->format_date( $item->deleted_at ) ); ?></span></td>
								<td>
									<?php if ( $available ) : ?>
										<span class="mut-status-badge mut-status-restorable">Restorable</span>
									<?php else : ?>
										<span class="mut-status-badge mut-status-unused">File gone</span>
									<?php endif; ?>
								</td>
								<td style="white-space:nowrap;">
									<?php if ( $available ) : ?>
										<button type="button" class="button button-small mut-restore-btn" data-log-id="<?php echo esc_attr( $item->id ); ?>">
											↩ Restore
										</button>
									<?php else : ?>
										<span class="mut-meta">—</span>
									<?php endif; ?>
									<button type="button" class="button button-small mut-perm-delete-btn"
										data-log-id="<?php echo esc_attr( $item->id ); ?>"
										style="color:#b32d2e;border-color:#b32d2e;margin-left:4px;"
										title="Permanently delete this file">
										🗑 Delete
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<div class="mut-trash-bulk-bar mut-trash-bulk-bar--bottom">
					<span class="mut-trash-sel-count-sync">0</span> selected
					<button type="button" class="button button-small mut-trash-bulk-restore" style="margin-left:10px;">↩ Restore Selected</button>
					<button type="button" class="button button-small mut-trash-bulk-delete" style="margin-left:6px;color:#b32d2e;border-color:#b32d2e;">🗑 Delete Permanently</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function mime_label( $mime ) {
		$map = array(
			'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF',
			'image/webp' => 'WebP', 'image/svg+xml' => 'SVG',
			'application/pdf' => 'PDF',
			'video/mp4' => 'MP4', 'video/quicktime' => 'MOV', 'video/webm' => 'WebM',
			'audio/mpeg' => 'MP3', 'audio/wav' => 'WAV',
		);
		return $map[ $mime ] ?? ( $mime ? strtoupper( substr( strrchr( $mime, '/' ), 1 ) ) : '—' );
	}

	private function format_date( $mysql_date ) {
		$ts = strtotime( $mysql_date );
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $mysql_date;
	}
}
