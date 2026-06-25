<?php
namespace MediaUsageTracker\Admin;

class Reports {
    private $storage;

    public function __construct( $storage ) {
        $this->storage = $storage;
    }

    public function render() {
        ?>
        <div class="wrap">
            <h1>Reports &amp; Scan History</h1>

            <h2>Usage Report</h2>
            <?php $this->render_usage_report(); ?>

            <h2>Scan History</h2>
            <?php $this->render_scan_history(); ?>

            <h2>Export Options</h2>
            <p>
                <a href="<?php echo esc_url(add_query_arg('export', 'unused')); ?>" class="button button-primary">⬇ Export Unused Media (CSV)</a>
                <a href="<?php echo esc_url(add_query_arg('export', 'all')); ?>" class="button">⬇ Export All Usage Data (CSV)</a>
                &nbsp;&nbsp;
                <a href="<?php echo esc_url(add_query_arg('export_xlsx', 'unused')); ?>" class="button button-primary">⬇ Export Unused Media (Excel)</a>
                <a href="<?php echo esc_url(add_query_arg('export_xlsx', 'all')); ?>" class="button">⬇ Export All Usage Data (Excel)</a>
                &nbsp;&nbsp;
                <?php
                require_once MUT_PLUGIN_DIR . 'includes/class-pdf-exporter.php';
                ?>
                <a href="<?php echo esc_url( \MediaUsageTracker\Admin\PdfExporter::export_url( 'scan_history' ) ); ?>" class="button" target="_blank">⬇ Export Scan History (PDF)</a>
            </p>
        </div>

        <style>
        .mut-report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 16px 0 28px;
        }
        .mut-report-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 8px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .mut-report-card h3 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #50575e;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .mut-report-card .mut-report-number {
            font-size: 2.2em;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .mut-report-card p {
            margin: 0;
            font-size: 12px;
            color: #787c82;
        }
        .mut-report-card.unused .mut-report-number { color: #d63638; }
        .mut-report-card.active .mut-report-number { color: #00a32a; }
        .mut-report-card.storage .mut-report-number { color: #2271b1; }
        .mut-report-note {
            margin: 0 0 16px;
            color: #787c82;
            font-style: italic;
            font-size: 13px;
        }
        </style>
        <?php
    }

    /**
     * Render the Usage Report summary cards.
     */
    private function render_usage_report() {
        if ( ! $this->storage ) {
            echo '<p>Storage not available.</p>';
            return;
        }

        $total_media   = $this->storage->get_total_media_count();
        $active_files  = $this->storage->get_files_in_use_count();
        $unused_ids    = $this->storage->get_unused_attachments();
        $unused_files  = count( $unused_ids );
        $total_storage = $this->storage->get_storage_usage();
        $unused_storage = $this->storage->get_unused_storage_usage();

        $has_scan = (bool) $this->storage->get_scan_history();
        ?>

        <?php if ( ! $has_scan ) : ?>
            <p class="mut-report-note">ℹ️ No scan has been run yet. Run a scan from the Dashboard to see accurate active/unused counts.</p>
        <?php endif; ?>

        <div class="mut-report-grid">

            <div class="mut-report-card total">
                <h3>Total Media Files</h3>
                <div class="mut-report-number"><?php echo number_format( $total_media ); ?></div>
                <p>Attachments in the library</p>
            </div>

            <div class="mut-report-card active">
                <h3>Active Files</h3>
                <div class="mut-report-number"><?php echo number_format( $active_files ); ?></div>
                <p>Referenced in posts or pages</p>
            </div>

            <div class="mut-report-card unused">
                <h3>Unused Files</h3>
                <div class="mut-report-number"><?php echo number_format( $unused_files ); ?></div>
                <p>Not found in any content</p>
            </div>

            <div class="mut-report-card storage">
                <h3>Storage Usage</h3>
                <div class="mut-report-number"><?php echo esc_html( $this->format_bytes( $total_storage ) ); ?></div>
                <p><?php echo esc_html( $this->format_bytes( $unused_storage ) ); ?> used by unused files</p>
            </div>

        </div>
        <?php
    }

    /**
     * Format bytes to a human-readable string (KB / MB / GB).
     */
    private function format_bytes( $bytes ) {
        if ( $bytes >= 1073741824 ) {
            return number_format( $bytes / 1073741824, 2 ) . ' GB';
        } elseif ( $bytes >= 1048576 ) {
            return number_format( $bytes / 1048576, 2 ) . ' MB';
        } elseif ( $bytes >= 1024 ) {
            return number_format( $bytes / 1024, 1 ) . ' KB';
        }
        return $bytes . ' B';
    }

    private function render_scan_history() {
        global $wpdb;
        $history = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mut_scan_history ORDER BY started_at DESC LIMIT 20");

        if (empty($history)) {
            echo '<p>No scans yet. Run a scan from the Dashboard.</p>';
            return;
        }

        ?>
        <div style="overflow-x:auto;">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Total Media</th>
                    <th>In Use</th>
                    <th>Unused</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $scan) : ?>
                    <tr>
                        <td><?php echo esc_html( ( new \DateTime( $scan->started_at, wp_timezone() ) )->format( 'Y-m-d H:i:s' ) ); ?></td>
                        <td><span class="status-<?php echo esc_attr($scan->status); ?>"><?php echo esc_html($scan->status); ?></span></td>
                        <td><?php echo esc_html($scan->total_attachments); ?></td>
                        <td><?php echo esc_html($scan->files_in_use); ?></td>
                        <td><?php echo esc_html($scan->unused_files); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    public function handle_csv_export() {
        if (!isset($_GET['export'])) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;
        $export_type = sanitize_text_field($_GET['export']);

        if ($export_type === 'unused') {
            $unused = $this->storage->get_unused_attachments();
            $filename = 'unused-media-' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            fputcsv($output, ['Attachment ID', 'Title', 'URL', 'File Size']);

            foreach ($unused as $att) {
                $file = get_attached_file($att->ID);
                fputcsv($output, [
                    $att->ID,
                    $att->post_title,
                    wp_get_attachment_url($att->ID),
                    $file ? filesize($file) : 0
                ]);
            }
            exit;
        }

        // All usage export
        $usages = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mut_media_usage ORDER BY created_at DESC");
        $filename = 'media-usage-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Attachment ID', 'Post ID', 'Post Type', 'Usage Type', 'Context', 'Date']);

        foreach ($usages as $u) {
            fputcsv($output, [$u->attachment_id, $u->post_id, $u->post_type, $u->usage_type, $u->context, $u->created_at]);
        }
        exit;
    }

    public function handle_excel_export() {
        if ( ! isset( $_GET['export_xlsx'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;
        $export_type = sanitize_text_field( $_GET['export_xlsx'] );

        if ( $export_type === 'unused' ) {
            $unused = $this->storage->get_unused_attachments();
            $xls    = new \MediaUsageTracker\Excel_Export( 'Unused Media' );
            $xls->add_header_row( array( 'Attachment ID', 'Title', 'URL', 'File Size' ) );
            foreach ( $unused as $att ) {
                $file = get_attached_file( $att->ID );
                $xls->add_row( array(
                    $att->ID,
                    $att->post_title,
                    wp_get_attachment_url( $att->ID ),
                    $file ? filesize( $file ) : 0,
                ) );
            }
            $xls->send( 'unused-media-' . date( 'Y-m-d' ) . '.xlsx' );
        }

        // All usage export
        $usages = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mut_media_usage ORDER BY created_at DESC" );
        $xls    = new \MediaUsageTracker\Excel_Export( 'Media Usage' );
        $xls->add_header_row( array( 'Attachment ID', 'Post ID', 'Post Type', 'Usage Type', 'Context', 'Date' ) );
        foreach ( $usages as $u ) {
            $xls->add_row( array( $u->attachment_id, $u->post_id, $u->post_type, $u->usage_type, $u->context, $u->created_at ) );
        }
        $xls->send( 'media-usage-' . date( 'Y-m-d' ) . '.xlsx' );
    }
}
