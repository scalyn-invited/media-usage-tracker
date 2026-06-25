<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Storage Optimization admin page.
 * Renders the report produced by StorageOptimizer as priority-ordered cards
 * plus a CSS-only MIME-type breakdown chart.
 */
class StorageOptimization {

	/** @var UsageStorage */
	private $storage;

	/** @var StorageOptimizer */
	private $optimizer;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
		require_once MUT_PLUGIN_DIR . 'includes/class-storage-optimizer.php';
		$this->optimizer = new StorageOptimizer( $storage );
	}

	// -------------------------------------------------------------------------
	// AJAX: refresh
	// -------------------------------------------------------------------------

	public function handle_refresh() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		$report = $this->optimizer->refresh();
		wp_send_json_success( array( 'count' => count( $report['recommendations'] ) ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render() {
		$report = $this->optimizer->get_report();
		?>
		<div class="wrap mut-optimize">
			<h1>⚡ Storage Optimization</h1>
			<p class="mut-opt-intro">
				Data-driven cleanup priorities based on your latest scan. Recommendations are ranked by recoverable impact.
				<strong>Always back up before deleting.</strong>
			</p>

			<div class="mut-opt-toolbar">
				<button type="button" id="mut-opt-refresh" class="button">↻ Recalculate</button>
				<span id="mut-opt-refresh-msg" class="mut-opt-refresh-msg"></span>
			</div>

			<?php $this->render_stat_bar( $report ); ?>

			<h2 class="mut-opt-section-title">Cleanup Priorities</h2>
			<?php if ( empty( $report['recommendations'] ) ) : ?>
				<div class="notice notice-success inline mut-opt-clean">
					<p>✅ Your media library is well-optimized — no significant cleanup opportunities detected.</p>
				</div>
			<?php else : ?>
				<div class="mut-opt-recs">
					<?php foreach ( $report['recommendations'] as $rec ) : ?>
						<?php $this->render_recommendation( $rec ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h2 class="mut-opt-section-title">Storage by File Type</h2>
			<?php $this->render_mime_chart( $report ); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Stat bar
	// -------------------------------------------------------------------------

	private function render_stat_bar( $report ) {
		?>
		<div class="mut-opt-statbar">
			<div class="mut-opt-stat">
				<span class="mut-opt-stat-num"><?php echo esc_html( size_format( $report['total_bytes'], 1 ) ); ?></span>
				<span class="mut-opt-stat-label">Total Storage</span>
			</div>
			<div class="mut-opt-stat mut-opt-stat--recoverable">
				<span class="mut-opt-stat-num"><?php echo esc_html( size_format( $report['recoverable_bytes'], 1 ) ); ?></span>
				<span class="mut-opt-stat-label">Recoverable</span>
			</div>
			<div class="mut-opt-stat mut-opt-stat--pct">
				<span class="mut-opt-stat-num"><?php echo esc_html( $report['recoverable_pct'] ); ?>%</span>
				<span class="mut-opt-stat-label">Of Library</span>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Recommendation card
	// -------------------------------------------------------------------------

	private function render_recommendation( $rec ) {
		$pmeta = $this->priority_meta( $rec['priority'] );
		?>
		<div class="mut-opt-rec mut-opt-rec--<?php echo esc_attr( $rec['category'] ); ?>">
			<div class="mut-opt-rec-main">
				<div class="mut-opt-rec-head">
					<span class="mut-opt-priority mut-opt-priority--<?php echo esc_attr( $pmeta['key'] ); ?>">
						<?php echo esc_html( $pmeta['label'] ); ?>
					</span>
					<span class="mut-opt-rec-headline"><?php echo esc_html( $rec['headline'] ); ?></span>
				</div>
				<p class="mut-opt-rec-detail"><?php echo esc_html( $rec['detail'] ); ?></p>

				<?php if ( $rec['share_pct'] > 0 ) : ?>
					<div class="mut-opt-bar" title="<?php echo esc_attr( $rec['share_pct'] ); ?>% of total storage">
						<div class="mut-opt-bar-fill" style="width:<?php echo esc_attr( min( 100, $rec['share_pct'] ) ); ?>%;"></div>
					</div>
				<?php endif; ?>
			</div>

			<div class="mut-opt-rec-side">
				<?php if ( $rec['saving'] > 0 ) : ?>
					<span class="mut-opt-saving"><?php echo esc_html( size_format( $rec['saving'], 1 ) ); ?></span>
					<span class="mut-opt-saving-label">recoverable</span>
				<?php endif; ?>
				<?php if ( $rec['action_url'] && $rec['action_label'] ) : ?>
					<a href="<?php echo esc_url( $rec['action_url'] ); ?>" class="button button-small mut-opt-action">
						<?php echo esc_html( $rec['action_label'] ); ?> →
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function priority_meta( $priority ) {
		switch ( (int) $priority ) {
			case 1:  return array( 'key' => 'high',   'label' => '🔴 High' );
			case 2:  return array( 'key' => 'medium', 'label' => '🟡 Medium' );
			default: return array( 'key' => 'low',    'label' => '🔵 Low' );
		}
	}

	// -------------------------------------------------------------------------
	// MIME breakdown chart (pure CSS)
	// -------------------------------------------------------------------------

	private function render_mime_chart( $report ) {
		$breakdown = $report['mime_breakdown'];
		$total     = $report['total_bytes'];

		if ( empty( $breakdown ) || $total <= 0 ) {
			echo '<p class="mut-opt-empty">No media files to analyse.</p>';
			return;
		}

		$colors = array(
			'Images'    => '#2271b1',
			'Videos'    => '#d63638',
			'Audio'     => '#8c5fce',
			'Documents' => '#dba617',
			'Other'     => '#787c82',
		);
		?>
		<div class="mut-opt-mime">
			<?php foreach ( $breakdown as $label => $data ) : ?>
				<?php $pct = round( $data['bytes'] / $total * 100, 1 ); ?>
				<div class="mut-opt-mime-row">
					<span class="mut-opt-mime-label"><?php echo esc_html( $label ); ?></span>
					<div class="mut-opt-mime-track">
						<div class="mut-opt-mime-fill"
							style="width:<?php echo esc_attr( max( 1, $pct ) ); ?>%;background:<?php echo esc_attr( $colors[ $label ] ?? '#787c82' ); ?>;">
						</div>
					</div>
					<span class="mut-opt-mime-val">
						<?php echo esc_html( size_format( $data['bytes'], 1 ) ); ?>
						(<?php echo esc_html( $pct ); ?>%, <?php echo number_format( $data['count'] ); ?> <?php echo $data['count'] === 1 ? 'file' : 'files'; ?>)
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
