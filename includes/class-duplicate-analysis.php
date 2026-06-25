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

			<div style="overflow-x:auto;">
			<table class="wp-list-table widefat striped mut-dup-table">
				<thead>
					<tr>
						<th style="width:64px;">Thumb</th>
						<th>Filename</th>
						<th style="width:110px;">Status</th>
						<th style="width:100px;">File Size</th>
						<th style="width:140px;">Upload Date</th>
						<th style="width:200px;">Action</th>
					</tr>
				</thead>
				<tbody>
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
			'style' => 'width:52px;height:52px;object-fit:cover;border-radius:4px;display:block;',
		) );
		?>
		<tr data-id="<?php echo esc_attr( $id ); ?>">
			<td><?php echo $thumb ?: '<span class="mut-no-thumb"></span>'; ?></td>
			<td>
				<strong><?php echo esc_html( $filename ); ?></strong><br>
				<span class="mut-meta"><?php echo esc_html( $post->post_title ); ?></span>
			</td>
			<td>
				<?php if ( $usage > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $id ) ); ?>" style="text-decoration:none;" title="View usage locations">
						<span class="mut-status-badge mut-status-used">In Use</span>
					</a>
					<?php if ( $usage > 1 ) : ?>
						<br><span class="mut-meta" style="font-size:11px;"><?php echo $usage; ?> locations</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="mut-status-badge mut-status-unused">Unused</span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $filesize ); ?></td>
			<td><span class="mut-meta"><?php echo esc_html( $uploaded ); ?></span></td>
			<td class="mut-dup-row-action">
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
