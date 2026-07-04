<?php
namespace MediaUsageTracker\Admin;

class Dashboard {
    private $storage;

    public function __construct( $storage ) {
        $this->storage = $storage;
    }

    public function render() {
        $total_media  = $this->storage ? $this->storage->get_total_media_count() : 0;
        $files_in_use = $this->storage ? $this->storage->get_files_in_use_count() : 0;
        $unused_files = 0;
        $storage_bytes = 0;
        $last_scan    = $this->storage ? $this->get_last_scan_info() : null;
        $dup_groups   = 0;
        $recoverable  = 0;
        if ( $this->storage ) {
            require_once MUT_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
            $detector   = new \MediaUsageTracker\Admin\DuplicateDetector( $this->storage );
            $dup_groups = count( $detector->get_groups() );

            require_once MUT_PLUGIN_DIR . 'includes/class-storage-optimizer.php';
            $optimizer   = new \MediaUsageTracker\Admin\StorageOptimizer( $this->storage );
            $report      = $optimizer->get_report();
            $recoverable = $report['recoverable_bytes'];
        }

        if ( $this->storage ) {
            $unused_ids   = $this->storage->get_unused_attachments();
            $unused_files = count( $unused_ids );
            $storage_bytes = $this->storage->get_storage_usage();
        }

        $storage_label = $this->format_bytes( $storage_bytes );
        ?>
        <div class="wrap mut-dashboard">
            <h1>📊 Media Usage Tracker Dashboard</h1>

            <!-- Quick Scan Button -->
            <div class="mut-actions">
                <button id="mut-start-scan" class="button button-primary button-large">🔄 Start New Scan</button>
<span id="mut-progress" style="display:none; margin-left:15px; font-weight:bold; color:#2271b1;"></span>
            </div>

            <!-- Summary Cards -->
            <div class="mut-stats-grid">

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details' ) ); ?>" class="mut-stat-card mut-card-total" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">🗂️</div>
                    <div class="mut-card-body">
                        <h3>Total Media Files</h3>
                        <div class="mut-stat-number" data-stat="total_media"><?php echo number_format( $total_media ); ?></div>
                        <p>Attachments in library →</p>
                    </div>
                </a>

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details#used' ) ); ?>" class="mut-stat-card mut-card-inuse" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">✅</div>
                    <div class="mut-card-body">
                        <h3>Files In Use</h3>
                        <div class="mut-stat-number" data-stat="files_in_use"><?php echo number_format( $files_in_use ); ?></div>
                        <p>Referenced in content →</p>
                    </div>
                </a>

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-cleanup' ) ); ?>" class="mut-stat-card mut-card-unused" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">⚠️</div>
                    <div class="mut-card-body">
                        <h3>Unused Files</h3>
                        <div class="mut-stat-number" data-stat="unused_files"><?php echo number_format( $unused_files ); ?></div>
                        <p>Ready for review →</p>
                    </div>
                </a>

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-optimize' ) ); ?>" class="mut-stat-card mut-card-storage" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">💾</div>
                    <div class="mut-card-body">
                        <h3>Storage Consumed</h3>
                        <div class="mut-stat-number" data-stat="storage"><?php echo esc_html( $storage_label ); ?></div>
                        <p>Total media library size →</p>
                    </div>
                </a>

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-duplicates' ) ); ?>" class="mut-stat-card mut-card-duplicates" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">🔁</div>
                    <div class="mut-card-body">
                        <h3>Duplicate Groups</h3>
                        <div class="mut-stat-number" data-stat="dup_groups"><?php echo number_format( $dup_groups ); ?></div>
                        <p>View duplicate analysis →</p>
                    </div>
                </a>

                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-optimize' ) ); ?>" class="mut-stat-card mut-card-recoverable" style="text-decoration:none;color:inherit;">
                    <div class="mut-card-icon">⚡</div>
                    <div class="mut-card-body">
                        <h3>Recoverable Space</h3>
                        <div class="mut-stat-number" data-stat="recoverable"><?php echo esc_html( $this->format_bytes( $recoverable ) ); ?></div>
                        <p>View optimization →</p>
                    </div>
                </a>

            </div>

            <!-- Recent Scan Information -->
            <div class="mut-recent-scan">
                <h2>Recent Scan Information</h2>
                <?php if ( $last_scan ) : ?>
                <div class="mut-scan-info-grid">

                    <div class="mut-scan-info-item">
                        <span class="mut-scan-label">Date Scanned</span>
                        <span class="mut-scan-value" data-scan="ago" title="<?php
                            $dt = new \DateTime( $last_scan->started_at, wp_timezone() );
                            echo esc_attr( $dt->format( 'M j, Y g:i A' ) );
                        ?>"><?php echo esc_html( human_time_diff( strtotime( $last_scan->started_at ), current_time( 'timestamp' ) ) . ' ago' ); ?></span>
                    </div>

                    <div class="mut-scan-info-item">
                        <span class="mut-scan-label">Status</span>
                        <span class="mut-scan-value">
                            <span class="mut-scan-badge mut-scan-badge-<?php echo esc_attr( $last_scan->status ); ?>" data-scan="status">
                                <?php echo esc_html( ucfirst( $last_scan->status ) ); ?>
                            </span>
                        </span>
                    </div>

                    <div class="mut-scan-info-item">
                        <span class="mut-scan-label">Total Scanned</span>
                        <span class="mut-scan-value" data-scan="total_attachments"><?php echo number_format( $last_scan->total_attachments ); ?> files</span>
                    </div>

                    <div class="mut-scan-info-item">
                        <span class="mut-scan-label">Files In Use</span>
                        <span class="mut-scan-value" data-scan="files_in_use"><?php echo number_format( $last_scan->files_in_use ); ?></span>
                    </div>

                    <div class="mut-scan-info-item">
                        <span class="mut-scan-label">Unused Files</span>
                        <span class="mut-scan-value" data-scan="unused_files"><?php echo number_format( $last_scan->unused_files ); ?></span>
                    </div>


                </div>
                <?php else : ?>
                <p class="mut-no-scan">No scan has been run yet. Click <strong>Start New Scan</strong> above to begin.</p>
                <?php endif; ?>
            </div>

            <!-- Quick Links -->
            <div class="mut-quick-links">
                <span class="mut-quick-links-label">Quick Links</span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details' ) ); ?>" class="mut-quick-link">🗂️ Media Usage</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-media-by-page' ) ); ?>" class="mut-quick-link">📄 Media by Page</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-search' ) ); ?>" class="mut-quick-link">🔍 Search &amp; Filter</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-cleanup' ) ); ?>" class="mut-quick-link">🧹 Cleanup Suggestions</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-bulk-review' ) ); ?>" class="mut-quick-link">🚩 Bulk Review</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-duplicates' ) ); ?>" class="mut-quick-link">🔁 Duplicate Analysis</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-optimize' ) ); ?>" class="mut-quick-link">💾 Storage Optimization</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-quality' ) ); ?>" class="mut-quick-link">✨ Quality Audit</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-trash' ) ); ?>" class="mut-quick-link">🗑️ Trash</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-reports' ) ); ?>" class="mut-quick-link">📈 Reports</a>
            </div>

        </div>

        <style>
        /* ── Layout ── */
        .mut-dashboard { max-width: 1200px; }
        .mut-actions { margin: 20px 0 30px; display: flex; align-items: center; gap: 16px; }

        /* ── Summary Cards ── */
        .mut-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 36px;
        }
        .mut-stat-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px 22px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 16px;
            border-left: 4px solid #c3c4c7;
            transition: box-shadow 0.15s, transform 0.15s;
            cursor: pointer;
        }
        .mut-stat-card:hover {
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .mut-card-total  { border-left-color: #2271b1; }
        .mut-card-inuse  { border-left-color: #00a32a; }
        .mut-card-unused { border-left-color: #d63638; }
        .mut-card-storage{ border-left-color: #8c5fce; }

        .mut-card-icon { font-size: 2em; line-height: 1; }
        .mut-card-body h3 {
            margin: 0 0 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #646970;
        }
        .mut-stat-number {
            font-size: 2em;
            font-weight: 700;
            line-height: 1.1;
            color: #1d2327;
            margin-bottom: 2px;
        }
        .mut-card-unused .mut-stat-number { color: #d63638; }
        .mut-card-storage .mut-stat-number { color: #8c5fce; }
        .mut-card-inuse .mut-stat-number  { color: #00a32a; }
        .mut-card-total .mut-stat-number  { color: #2271b1; }
        .mut-card-body p { margin: 0; font-size: 12px; color: #787c82; }

        /* ── Recent Scan ── */
        .mut-recent-scan {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 24px 28px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .mut-recent-scan h2 {
            margin: 0 0 20px;
            font-size: 15px;
            font-weight: 600;
            color: #1d2327;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 12px;
        }
        .mut-scan-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
        }
        .mut-scan-info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .mut-scan-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #787c82;
        }
        .mut-scan-value {
            font-size: 15px;
            font-weight: 600;
            color: #1d2327;
        }
        .mut-scan-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .mut-scan-badge-completed { background: #edfaef; color: #00641a; border: 1px solid #a3e6ad; }
        .mut-scan-badge-running   { background: #e8f0fd; color: #1b4da3; border: 1px solid #a0bcf0; }
        .mut-scan-badge-pending   { background: #fef9e7; color: #8a6400; border: 1px solid #f0d97a; }
        .mut-no-scan { color: #787c82; font-style: italic; margin: 0; }

        /* ── Quick Links ── */
        .mut-quick-links {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .mut-quick-links-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #787c82;
            margin-right: 4px;
        }
        .mut-quick-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 20px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            color: #1d2327;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .mut-quick-link:hover {
            background: #f0f6fc;
            border-color: #2271b1;
            color: #2271b1;
        }
        </style>
        <?php
    }

    private function format_bytes( $bytes ) {
        if ( $bytes >= 1073741824 ) return number_format( $bytes / 1073741824, 2 ) . ' GB';
        if ( $bytes >= 1048576 )   return number_format( $bytes / 1048576, 2 )    . ' MB';
        if ( $bytes >= 1024 )      return number_format( $bytes / 1024, 1 )       . ' KB';
        return $bytes . ' B';
    }

    private function get_last_scan_info() {
        global $wpdb;
        return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}mut_scan_history ORDER BY started_at DESC LIMIT 1" );
    }
}
