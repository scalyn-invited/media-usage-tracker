<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;
use MediaUsageTracker\Admin\AltTextGenerator;

/**
 * Media Quality Audit admin page.
 *
 * Renders the QualityAuditor report: a severity-ordered set of cards, each an
 * audit category with its flagged-file count and a sample of affected files.
 */
class QualityAudit {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function handle_refresh() {
		check_ajax_referer( 'mut_scan_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
		require_once MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';
		$auditor = new QualityAuditor( $this->storage );
		$auditor->refresh();
		wp_send_json_success();
	}

	public function render() {
		require_once MUT_PLUGIN_DIR . 'includes/class-alt-text-generator.php';
		require_once MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';
		$auditor      = new QualityAuditor( $this->storage );
		$report       = $auditor->get_report();
		$alt_ids      = $report['checks']['alt_text']['items'] ?? array();
		$ai_ready     = AltTextGenerator::is_configured();
		$settings_url = admin_url( 'admin.php?page=mut-settings' );
		?>
		<div class="wrap mut-quality">
			<h1>✅ Media Quality Audit
				<button id="mut-quality-refresh" class="button" style="margin-left:12px;">↻ Re-run Audit</button>
				<a href="<?php echo esc_url( \MediaUsageTracker\Admin\PdfExporter::export_url( 'quality' ) ); ?>" class="button" style="margin-left:8px;" target="_blank">⬇ Export PDF</a>
				<span id="mut-quality-refresh-msg" class="mut-meta" style="margin-left:8px;"></span>
			</h1>
			<p class="mut-quality-intro">
				Quality checks across your media library: accessibility, metadata completeness,
				file size, and format. Findings are advisory — nothing is changed automatically.
			</p>

			<div class="mut-quality-statbar">
				<div class="mut-quality-stat">
					<span class="mut-quality-stat-num"><?php echo number_format( $report['total'] ); ?></span>
					<span class="mut-quality-stat-label">In-use files audited</span>
				</div>
				<div class="mut-quality-stat">
					<span class="mut-quality-stat-num"><?php echo number_format( $report['total_issues'] ); ?></span>
					<span class="mut-quality-stat-label">Issues found</span>
				</div>
				<div class="mut-quality-stat">
					<span class="mut-quality-stat-num mut-quality-clean"><?php echo number_format( $report['clean'] ); ?></span>
					<span class="mut-quality-stat-label">Clean files</span>
				</div>
			</div>

			<?php if ( $report['total'] === 0 ) : ?>
				<div class="mut-no-results"><p>No media files to audit yet. Run a scan first.</p></div>
			<?php elseif ( $report['total_issues'] === 0 ) : ?>
				<div class="mut-quality-allclear">
					<p>✓ No quality issues found. Your media library is in great shape.</p>
				</div>
			<?php else : ?>
				<div class="mut-quality-cards">
					<?php foreach ( $report['checks'] as $check ) :
						if ( $check['count'] === 0 ) {
							continue;
						}
						?>
						<div class="mut-quality-card mut-sev-<?php echo esc_attr( $check['severity'] ); ?>">
							<?php
							$detail_url = admin_url( 'admin.php?page=mut-quality-detail&check=' . urlencode( $check['key'] ) );
							?>
							<div class="mut-quality-card-head">
								<span class="mut-sev-badge mut-sev-badge-<?php echo esc_attr( $check['severity'] ); ?>">
									<?php echo esc_html( ucfirst( $check['severity'] ) ); ?>
								</span>
								<h3><?php echo esc_html( $check['label'] ); ?></h3>
								<a href="<?php echo esc_url( $detail_url ); ?>" class="mut-quality-count" title="View all files">
									<?php echo number_format( $check['count'] ); ?>
								</a>
							</div>
							<p class="mut-quality-card-desc"><?php echo esc_html( $check['description'] ); ?></p>

							<?php if ( in_array( $check['key'], array( 'alt_text', 'caption' ), true ) && ! empty( $check['items'] ) ) :
								$is_caption  = $check['key'] === 'caption';
								$btn_id      = $is_caption ? 'mut-generate-caption' : 'mut-generate-alt-text';
								$btn_label   = $is_caption ? '✨ Generate Captions with AI' : '✨ Generate Alt Text with AI';
								$btn_label_r = $is_caption ? '✨ Generate Captions with AI — <em>API key required</em>' : '✨ Generate Alt Text with AI — <em>API key required</em>';
							?>
								<div class="mut-ai-alttext-bar">
									<?php if ( $ai_ready ) : ?>
										<button id="<?php echo esc_attr( $btn_id ); ?>" class="button button-primary"
											data-ids="<?php echo esc_attr( wp_json_encode( $check['items'] ) ); ?>"
											data-all-ids="<?php echo esc_attr( wp_json_encode( $check['items'] ) ); ?>">
											<?php echo $btn_label; ?>
										</button>
										<span id="mut-ai-progress" class="mut-meta" style="margin-left:10px;display:none;"></span>
									<?php else : ?>
										<a href="<?php echo esc_url( $settings_url ); ?>" class="button">
											<?php echo $btn_label_r; ?>
										</a>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<div class="mut-quality-samples">
								<?php
								$sample = array_slice( $check['items'], 0, 6 );
								foreach ( $sample as $id ) :
									$img = wp_get_attachment_image( $id, array( 44, 44 ), true, array(
										'style' => 'width:44px;height:44px;object-fit:cover;border-radius:4px;',
									) );
									$link = admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $id );
									?>
									<a href="<?php echo esc_url( $link ); ?>" class="mut-quality-thumb" title="<?php echo esc_attr( get_the_title( $id ) ); ?>">
										<?php echo $img ?: '<span class="mut-no-thumb"></span>'; ?>
									</a>
								<?php endforeach; ?>
								<?php if ( $check['count'] > count( $sample ) ) : ?>
									<a href="<?php echo esc_url( $detail_url ); ?>" class="mut-quality-more">
										+<?php echo number_format( $check['count'] - count( $sample ) ); ?> more
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- AI Alt Text Review Panel -->
				<div id="mut-alttext-review-panel" style="display:none;">
					<h2>✨ AI-Generated Alt Text — Review &amp; Save</h2>
					<p class="description">Review each suggestion below. Edit any text, then click <strong>Save All</strong> or save individually.</p>
					<div id="mut-alttext-review-list"></div>
					<div class="mut-alttext-review-actions" style="margin-top:16px;">
						<button id="mut-save-all-alt" class="button button-primary">Save All</button>
						<button id="mut-cancel-alt-review" class="button" style="margin-left:8px;">Cancel</button>
						<span id="mut-save-progress" class="mut-meta" style="margin-left:10px;"></span>
					</div>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}
}
