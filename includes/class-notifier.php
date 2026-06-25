<?php
namespace MediaUsageTracker\Core;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Email summary after a scheduled scan completes.
 *
 * Hooks 'mut_scheduled_scan_complete' (fired by Scheduler::run_scan) and emails
 * the admin a digest: totals, unused files, reclaimable space, and a link back
 * to the dashboard.
 *
 * Driven by options:
 *   mut_scan_email_enabled    => '1' | '0'
 *   mut_scan_email_recipient  => email address (defaults to admin_email)
 *
 * The summary-building logic (build_summary / render_email) is pure so it can
 * be unit tested without sending real mail.
 */
class Notifier {

	const OPT_ON   = 'mut_scan_email_enabled';
	const OPT_TO   = 'mut_scan_email_recipient';

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Wire the completion hook. Called once from Plugin::init().
	 */
	public static function register( UsageStorage $storage ) {
		$instance = new self( $storage );
		add_action( 'mut_scheduled_scan_complete', array( $instance, 'on_scan_complete' ) );
	}

	/**
	 * Fired when a scheduled scan finishes.
	 */
	public function on_scan_complete( $scan_id = 0 ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$summary = $this->build_summary();
		$to      = $this->recipient();
		$subject = $this->build_subject( $summary );
		$body    = $this->render_email( $summary );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $to, $subject, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// Summary data (pure — testable)
	// -------------------------------------------------------------------------

	/**
	 * Assemble the numbers shown in the email.
	 *
	 * @return array
	 */
	public function build_summary() {
		$total    = (int) $this->storage->get_total_media_count();
		$in_use   = (int) $this->storage->get_files_in_use_count();
		$unused   = max( 0, $total - $in_use );
		$reclaim  = (int) $this->storage->get_unused_storage_usage();
		$pct      = $total > 0 ? (int) round( ( $unused / $total ) * 100 ) : 0;

		return array(
			'total'        => $total,
			'in_use'       => $in_use,
			'unused'       => $unused,
			'unused_pct'   => $pct,
			'reclaimable'  => $reclaim,
			'scanned_at'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Build the subject line from the summary.
	 */
	public function build_subject( $summary ) {
		$site = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'Your site';
		if ( $summary['unused'] > 0 ) {
			return sprintf(
				'[%s] Media scan: %d unused file%s found',
				$site,
				$summary['unused'],
				$summary['unused'] === 1 ? '' : 's'
			);
		}
		return sprintf( '[%s] Media scan complete — no unused files', $site );
	}

	/**
	 * Render the HTML email body.
	 *
	 * @return string
	 */
	public function render_email( $summary ) {
		$dashboard_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=media-usage-tracker' ) : '#';
		$cleanup_url   = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=mut-cleanup' ) : '#';
		$reclaim_human = $this->size_format( $summary['reclaimable'] );

		ob_start();
		?>
		<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#1d2327;">
			<h2 style="font-size:18px;margin:0 0 4px;">📊 Media Usage Scan Summary</h2>
			<p style="color:#787c82;font-size:13px;margin:0 0 20px;">
				Automated scan completed <?php echo esc_html( $summary['scanned_at'] ); ?>
			</p>

			<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
				<?php
				echo $this->stat_row( 'Total media files', number_format( $summary['total'] ) );
				echo $this->stat_row( 'In use', number_format( $summary['in_use'] ) );
				echo $this->stat_row(
					'Unused',
					number_format( $summary['unused'] ) . ' (' . $summary['unused_pct'] . '%)',
					$summary['unused'] > 0 ? '#b32d2e' : '#1a7a3a'
				);
				echo $this->stat_row( 'Reclaimable space', $reclaim_human, '#b26a00' );
				?>
			</table>

			<?php if ( $summary['unused'] > 0 ) : ?>
				<p style="font-size:14px;">
					You have <strong><?php echo number_format( $summary['unused'] ); ?></strong>
					potentially unused file<?php echo $summary['unused'] === 1 ? '' : 's'; ?>
					taking up <strong><?php echo esc_html( $reclaim_human ); ?></strong>.
				</p>
				<p style="margin:20px 0;">
					<a href="<?php echo esc_url( $cleanup_url ); ?>"
					   style="background:#2271b1;color:#fff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;display:inline-block;">
						Review Unused Files →
					</a>
				</p>
			<?php else : ?>
				<p style="font-size:14px;color:#1a7a3a;">
					✓ Great news — every media file is in use. Nothing to clean up.
				</p>
			<?php endif; ?>

			<p style="font-size:12px;color:#787c82;margin-top:24px;border-top:1px solid #e2e4e7;padding-top:12px;">
				<a href="<?php echo esc_url( $dashboard_url ); ?>" style="color:#2271b1;">Open dashboard</a>
				&middot; Sent by Media Usage Tracker. Manage email settings under
				Media Usage → Settings.
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	private function stat_row( $label, $value, $color = '#1d2327' ) {
		return sprintf(
			'<tr><td style="padding:8px 0;border-bottom:1px solid #f0f0f1;font-size:14px;color:#50575e;">%s</td>'
			. '<td style="padding:8px 0;border-bottom:1px solid #f0f0f1;font-size:14px;font-weight:600;text-align:right;color:%s;">%s</td></tr>',
			esc_html( $label ),
			esc_attr( $color ),
			esc_html( $value )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function is_enabled() {
		return (bool) get_option( self::OPT_ON, false );
	}

	public function recipient() {
		$to = get_option( self::OPT_TO, '' );
		if ( ! $to && function_exists( 'get_option' ) ) {
			$to = get_option( 'admin_email', '' );
		}
		return $to;
	}

	/**
	 * size_format() shim so this is testable without WP loaded.
	 */
	private function size_format( $bytes ) {
		if ( function_exists( 'size_format' ) ) {
			$out = size_format( $bytes, 1 );
			if ( $out ) {
				return $out;
			}
		}
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0;
		$bytes = (float) $bytes;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, 1 ) . ' ' . $units[ $i ];
	}
}
