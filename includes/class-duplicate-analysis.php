<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Duplicate Analysis admin page.
 * Renders the three group types produced by DuplicateDetector.
 */
class DuplicateAnalysis {

	/** @var UsageStorage */
	private $storage;

	/** @var DuplicateDetector */
	private $detector;

	public function __construct( UsageStorage $storage ) {
		$this->storage  = $storage;
		require_once MUT_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
		$this->detector = new DuplicateDetector( $storage );
	}

	// -------------------------------------------------------------------------
	// AJAX: refresh / bust cache
	// -------------------------------------------------------------------------

	public function handle_refresh() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$groups = $this->detector->refresh();
		wp_send_json_success( array( 'count' => count( $groups ) ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render() {
		$groups = $this->detector->get_groups();

		$by_type = array(
			'exact'   => array(),
			'similar' => array(),
			'resizes' => array(),
		);
		foreach ( $groups as $g ) {
			$by_type[ $g['type'] ][] = $g;
		}

		$total = count( $groups );
		?>
		<div class="wrap mut-duplicate">
			<div id="mut-dup-notice" class="hidden" style="margin-bottom:12px;"></div>
			<h1>🔁 Duplicate Analysis</h1>
			<p class="mut-dup-intro">
				Identifies exact copies, similar-named versions, and auto-generated resizes across your media library.
				<strong>Always verify before deleting.</strong>
			</p>

			<div class="mut-dup-toolbar">
				<button type="button" id="mut-dup-refresh" class="button">
					↻ Refresh Analysis
				</button>
				<span id="mut-dup-refresh-msg" class="mut-dup-refresh-msg"></span>
				<span class="mut-dup-total">
					<?php echo $total > 0
						? number_format( $total ) . ' duplicate ' . ( $total === 1 ? 'group' : 'groups' ) . ' found'
						: 'No duplicate groups detected'; ?>
				</span>
			</div>

			<?php if ( $total === 0 ) : ?>
				<div class="notice notice-success inline mut-dup-notice">
					<p>✅ No duplicate or similar assets were found in your media library.</p>
				</div>
			<?php else : ?>

				<?php $this->render_section(
					'exact',
					'🔴 Exact Duplicates',
					'Byte-for-byte identical files. Safe to remove all but one, after updating references.',
					$by_type['exact']
				); ?>

				<?php $this->render_section(
					'similar',
					'🟡 Similar Versions',
					'Files with the same base name but different version markers (e.g. logo-v2, logo-final). Consolidate to the most-used copy.',
					$by_type['similar']
				); ?>

				<?php $this->render_section(
					'resizes',
					'🔵 Auto-Generated Resizes',
					'WordPress-created size variants sharing a common parent. Do not delete the originals.',
					$by_type['resizes']
				); ?>

			<?php endif; ?>
		</div>
		<?php $this->render_delete_modal(); ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Section renderer
	// -------------------------------------------------------------------------

	private function render_section( $type, $heading, $description, $groups ) {
		$count = count( $groups );
		$open  = $count > 0 && $type !== 'resizes';
		?>
		<div class="mut-dup-section mut-dup-section--<?php echo esc_attr( $type ); ?>">
			<button
				class="mut-cs-toggle <?php echo $open ? 'open' : ''; ?>"
				aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
				data-target="mut-dup-body-<?php echo esc_attr( $type ); ?>">
				<span class="mut-cs-toggle-heading"><?php echo esc_html( $heading ); ?></span>
				<span class="mut-cs-badge"><?php echo number_format( $count ); ?></span>
				<span class="mut-cs-chevron">▾</span>
			</button>
			<p class="mut-cs-desc"><?php echo esc_html( $description ); ?></p>

			<div id="mut-dup-body-<?php echo esc_attr( $type ); ?>" class="mut-cs-body <?php echo $open ? '' : 'hidden'; ?>">
				<?php if ( empty( $groups ) ) : ?>
					<p class="mut-cs-empty">No <?php echo esc_html( $type ); ?> duplicates found.</p>
				<?php else : ?>
					<?php foreach ( $groups as $index => $group ) : ?>
						<?php $this->render_group( $group, $type, $index ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Group card
	// -------------------------------------------------------------------------

	private function render_group( $group, $type, $index ) {
		?>
		<div class="mut-dup-group mut-dup-group--<?php echo esc_attr( $type ); ?>">
			<div class="mut-dup-group-header">
				<span class="mut-dup-group-label"><?php echo esc_html( $group['label'] ); ?></span>
				<span class="mut-dup-group-reason" title="<?php echo esc_attr( $group['reason'] ); ?>">ℹ</span>
			</div>

			<div class="mut-dup-recommendation">
				💡 <?php echo esc_html( $group['recommendation'] ); ?>
			</div>

			<div class="md:overflow-hidden md:rounded-lg md:border md:border-gray-200 md:bg-white md:shadow-sm">
			<table class="mut-dup-table w-full block md:table text-sm text-left text-gray-700">
				<thead class="max-md:hidden md:table-header-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
					<tr class="md:table-row">
						<th class="md:table-cell md:w-[10%] px-4 py-3">Thumb</th>
						<th class="md:table-cell md:w-[30%] px-4 py-3">Filename</th>
						<th class="md:table-cell md:w-[16%] px-4 py-3">Status</th>
						<th class="md:table-cell md:w-[12%] px-4 py-3">File Size</th>
						<th class="md:table-cell md:w-[16%] px-4 py-3">Upload Date</th>
						<th class="md:table-cell md:w-[16%] px-4 py-3">Action</th>
					</tr>
				</thead>
				<tbody class="block md:table-row-group md:divide-y md:divide-gray-100">
					<?php foreach ( $group['ids'] as $id ) : ?>
						<?php $this->render_group_row( $id, $type ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private function render_group_row( $id, $type ) {
		$post  = get_post( $id );
		if ( ! $post ) return;

		$file     = get_attached_file( $id );
		$has_file = $file && file_exists( $file );
		$filename = $file ? basename( $file ) : '(missing)';
		$filesize = $has_file ? size_format( filesize( $file ) ) : '—';
		$usage    = $this->storage->get_usage_count( $id );
		$status   = $this->storage->get_review_status( $id );
		$uploaded = get_the_date( 'M j, Y', $post );

		$thumb = wp_get_attachment_image( $id, array( 52, 52 ), true, array(
			'class' => 'h-10 w-10 rounded object-cover border border-gray-200',
		) );
		?>
		<tr data-id="<?php echo esc_attr( $id ); ?>"
			class="flex flex-wrap items-center gap-x-3 gap-y-2 md:table-row mb-3 last:mb-0 md:mb-0 rounded-lg md:rounded-none border md:border-0 border-gray-200 bg-white p-3 md:p-0 md:hover:bg-gray-50 md:even:bg-gray-50/60">
			<td class="order-1 md:table-cell md:w-[10%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
				<?php echo $thumb ?: '<span class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-lg">📄</span>'; ?>
			</td>
			<td class="order-2 flex-1 min-w-0 md:table-cell md:w-[30%] md:flex-none px-0 md:px-4 py-1 md:py-3 md:align-middle">
				<strong class="font-medium text-gray-900"><?php echo esc_html( $filename ); ?></strong>
				<br><span class="text-xs text-gray-500"><?php echo esc_html( $post->post_title ); ?></span>
			</td>
			<td class="order-3 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
			<td class="order-4 md:table-cell md:w-[16%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
				<?php if ( $usage > 0 ) : ?>
					<span class="inline-block">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $id ) ); ?>" class="no-underline" title="View usage locations">
							<span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800">In Use</span>
						</a>
						<?php if ( $usage > 1 ) : ?>
							<span class="block text-[11px] text-gray-500"><?php echo $usage; ?> locations</span>
						<?php endif; ?>
					</span>
				<?php else : ?>
					<span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">Unused</span>
				<?php endif; ?>
			</td>
			<td class="order-5 md:table-cell md:w-[12%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500 before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none"><?php echo esc_html( $filesize ); ?></td>
			<td class="order-6 md:table-cell md:w-[16%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500 before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none"><?php echo esc_html( $uploaded ); ?></td>
			<td class="order-7 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
			<td class="order-8 mut-dup-row-action md:table-cell md:w-[16%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
				<?php echo $this->render_row_action( $id, $type, $usage, $status ); // phpcs:ignore ?>
			</td>
		</tr>
		<?php
	}

	// -------------------------------------------------------------------------
	// Per-row action button
	// -------------------------------------------------------------------------

	private function render_row_action( $id, $type, $usage, $review_status ) {
		// Already actioned.
		if ( $review_status ) {
			$label = $review_status === 'archived' ? '📦 Archived' : '🚩 Flagged';
			$class = 'mut-advice-status--' . esc_attr( $review_status );
			return '<span class="mut-advice-action mut-advice-action--done">'
				. '<span class="mut-advice-status ' . $class . '">' . esc_html( $label ) . '</span>'
				. ' <button type="button" class="button-link mut-advice-clear" data-id="' . esc_attr( $id ) . '" data-action="clear">undo</button>'
				. '</span>';
		}

		// Resizes: originals should never be flagged; offer flag only for children.
		if ( $type === 'resizes' ) {
			if ( $usage > 0 ) {
				return '<span class="mut-meta">Original</span>';
			}
			return '<button type="button" class="button button-small mut-advice-act mut-advice-act--review" '
				. 'data-id="' . esc_attr( $id ) . '" data-action="flag">🚩 Flag</button>';
		}

		// Exact / similar: unused files get "Flag for archive" + delete; used get "Flag".
		if ( $usage === 0 ) {
			$filename = basename( get_attached_file( $id ) ?: '' );
			return '<span style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;">'
				. '<button type="button" class="button button-small mut-advice-act mut-advice-act--archive" '
				. 'data-id="' . esc_attr( $id ) . '" data-action="archive">📦 Flag for archive</button>'
				. '<button type="button" class="button button-small mut-delete-btn" '
				. 'data-id="' . esc_attr( $id ) . '" data-name="' . esc_attr( $filename ) . '" '
				. 'title="Safely delete this file">🗑️</button>'
				. '</span>';
		}

		return '<button type="button" class="button button-small mut-advice-act mut-advice-act--review" '
			. 'data-id="' . esc_attr( $id ) . '" data-action="flag">🚩 Flag</button>';
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
}
