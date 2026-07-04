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
			<p class="text-gray-600 max-w-2xl mb-4">
				Files removed via Safe Delete are moved here, not permanently erased.
				Restore any item to return it to your Media Library.
			</p>

			<div id="mut-trash-notice" class="hidden mb-3"></div>

			<?php if ( empty( $items ) ) : ?>
				<div class="bg-white border border-gray-300 rounded-md p-10 text-center text-gray-500">
					<p>Trash is empty. Nothing has been safely deleted yet.</p>
				</div>
			<?php else : ?>
				<div class="flex gap-6 text-sm text-gray-600 mb-4">
					<span><strong class="text-gray-900"><?php echo count( $items ); ?></strong> file<?php echo count( $items ) !== 1 ? 's' : ''; ?> in trash</span>
					<span><strong class="text-gray-900"><?php echo esc_html( size_format( $total_bytes ) ); ?></strong> reclaimable</span>
				</div>

				<!-- Bulk toolbar -->
				<div class="flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm mb-2" id="mut-trash-bulk-bar" style="display:none;">
					<span id="mut-trash-sel-count">0</span> selected
					<button type="button" class="button button-small mut-trash-bulk-restore ml-2">↩ Restore Selected</button>
					<button type="button" class="button button-small mut-trash-bulk-delete text-[#b32d2e] border-[#b32d2e]">🗑 Delete Permanently</button>
				</div>

				<div class="md:overflow-hidden md:rounded-lg md:border md:border-gray-200 md:bg-white md:shadow-sm">
				<table class="mut-trash-table w-full block md:table text-sm text-left text-gray-700">
					<thead class="max-md:hidden md:table-header-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
						<tr class="md:table-row">
							<th class="md:table-cell w-9 px-4 py-3"><input type="checkbox" id="mut-trash-cb-all" class="h-4 w-4 accent-indigo-600" title="Select all"></th>
							<th class="md:table-cell w-16 px-4 py-3"><span class="sr-only">Preview</span></th>
							<th class="md:table-cell md:w-64 px-4 py-3">File</th>
							<th class="md:table-cell w-24 px-4 py-3">Type</th>
							<th class="md:table-cell w-20 px-4 py-3">Size</th>
							<th class="md:table-cell w-40 px-4 py-3">Deleted</th>
							<th class="md:table-cell w-28 px-4 py-3">Status</th>
							<th class="md:table-cell w-40 px-4 py-3">Actions</th>
						</tr>
					</thead>
					<tbody class="block md:table-row-group md:divide-y md:divide-gray-100">
						<?php foreach ( $items as $item ) :
							$available = ! empty( $item->trash_path ) && file_exists( $item->trash_path );
							?>
							<tr data-log-id="<?php echo esc_attr( $item->id ); ?>"
								class="flex flex-wrap items-center gap-x-3 gap-y-2 md:table-row mb-3 last:mb-0 md:mb-0 rounded-lg md:rounded-none border md:border-0 border-gray-200 bg-white p-3 md:p-0 md:hover:bg-gray-50 md:even:bg-gray-50/60">
								<td class="order-1 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<input type="checkbox" class="mut-trash-cb-row h-4 w-4 accent-indigo-600" value="<?php echo esc_attr( $item->id ); ?>">
								</td>
								<td class="order-2 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<?php $thumb = $this->thumbnail_data_uri( $item, $available ); ?>
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_attr( $thumb ); ?>" alt="" class="h-10 w-16 rounded object-cover border border-gray-200">
									<?php else : ?>
										<span class="flex h-10 w-16 items-center justify-center rounded bg-gray-100 text-lg"><?php echo esc_html( $this->thumbnail_icon( $item->mime_type ) ); ?></span>
									<?php endif; ?>
								</td>
								<td class="order-3 flex-1 min-w-0 md:table-cell md:w-64 md:flex-none px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<span class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 md:hidden">File</span>
									<strong class="font-medium text-gray-900"><?php echo esc_html( $item->file_name ); ?></strong>
									<?php if ( $item->title && $item->title !== $item->file_name ) : ?>
										<br><span class="text-xs text-gray-500"><?php echo esc_html( $item->title ); ?></span>
									<?php endif; ?>
								</td>
								<td class="order-4 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
								<td class="order-5 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600"><?php echo esc_html( $this->mime_label( $item->mime_type ) ); ?></span>
								</td>
								<td class="order-6 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<span class="text-xs text-gray-500"><?php echo esc_html( size_format( (int) $item->file_size ) ); ?></span>
								</td>
								<td class="order-7 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<span class="text-xs text-gray-500"><?php echo esc_html( $this->format_date( $item->deleted_at ) ); ?></span>
								</td>
								<td class="order-8 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
								<td class="order-9 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle">
									<?php if ( $available ) : ?>
										<span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800">Restorable</span>
									<?php else : ?>
										<span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">File gone</span>
									<?php endif; ?>
								</td>
								<td class="order-10 flex flex-wrap gap-1.5 md:table-cell px-0 md:px-4 py-1 md:py-3 md:align-middle md:whitespace-nowrap">
									<?php if ( $available ) : ?>
										<button type="button" class="button button-small mut-restore-btn" data-log-id="<?php echo esc_attr( $item->id ); ?>">
											↩ Restore
										</button>
									<?php else : ?>
										<span class="text-xs text-gray-400">—</span>
									<?php endif; ?>
									<button type="button" class="button button-small mut-perm-delete-btn text-[#b32d2e] border-[#b32d2e]"
										data-log-id="<?php echo esc_attr( $item->id ); ?>"
										title="Permanently delete this file">
										🗑 Delete
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot class="max-md:hidden md:table-footer-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
						<tr class="md:table-row">
							<th class="md:table-cell w-9 px-4 py-3"></th>
							<th class="md:table-cell w-16 px-4 py-3"><span class="sr-only">Preview</span></th>
							<th class="md:table-cell md:w-64 px-4 py-3">File</th>
							<th class="md:table-cell w-24 px-4 py-3">Type</th>
							<th class="md:table-cell w-20 px-4 py-3">Size</th>
							<th class="md:table-cell w-40 px-4 py-3">Deleted</th>
							<th class="md:table-cell w-28 px-4 py-3">Status</th>
							<th class="md:table-cell w-40 px-4 py-3">Actions</th>
						</tr>
					</tfoot>
				</table>
				</div>

				<div class="mut-trash-bulk-bar--bottom flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm mt-2">
					<span class="mut-trash-sel-count-sync">0</span> selected
					<button type="button" class="button button-small mut-trash-bulk-restore ml-2">↩ Restore Selected</button>
					<button type="button" class="button button-small mut-trash-bulk-delete text-[#b32d2e] border-[#b32d2e]">🗑 Delete Permanently</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Inline the trashed file as a data URI for a small preview thumbnail.
	 * The attachment post (and its generated thumbnail sizes) is already
	 * deleted by this point, and the trash directory is .htaccess-locked,
	 * so there is no attachment ID or public URL to hand to wp_get_attachment_image().
	 * Returns null (caller falls back to an icon) for non-images, missing
	 * files, or files above the inline size cap.
	 */
	private function thumbnail_data_uri( $item, $available ) {
		if ( ! $available || strpos( (string) $item->mime_type, 'image/' ) !== 0 ) {
			return null;
		}
		if ( (int) $item->file_size > 2 * MB_IN_BYTES ) {
			return null;
		}
		$contents = @file_get_contents( $item->trash_path );
		if ( false === $contents ) {
			return null;
		}
		return 'data:' . $item->mime_type . ';base64,' . base64_encode( $contents );
	}

	private function thumbnail_icon( $mime ) {
		$mime = (string) $mime;
		if ( strpos( $mime, 'video/' ) === 0 ) {
			return '🎬';
		}
		if ( strpos( $mime, 'audio/' ) === 0 ) {
			return '🎵';
		}
		if ( 'application/pdf' === $mime ) {
			return '📄';
		}
		if ( strpos( $mime, 'image/' ) === 0 ) {
			return '🖼️';
		}
		return '📁';
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
