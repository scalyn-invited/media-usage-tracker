<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

class BulkReview {

    private $storage;

    public function __construct( UsageStorage $storage ) {
        $this->storage = $storage;
    }

    // -------------------------------------------------------------------------
    // AJAX handlers (hooked via admin_init in Plugin)
    // -------------------------------------------------------------------------

    public function handle_ajax_bulk_action() {
        check_ajax_referer( 'mut_bulk_review_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( $_POST['bulk_action'] ) : '';
        $ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
                    ? array_map( 'absint', $_POST['ids'] )
                    : array();

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No files selected.' );
        }

        if ( ! in_array( $action, array( 'flag', 'archive', 'clear' ), true ) ) {
            wp_send_json_error( 'Invalid action.' );
        }

        $count = 0;
        foreach ( $ids as $id ) {
            if ( ! get_post( $id ) ) {
                continue;
            }
            if ( $action === 'clear' ) {
                $this->storage->clear_review_status( $id );
            } else {
                $status = ( $action === 'flag' ) ? 'flagged' : 'archived';
                $this->storage->set_review_status( $id, $status );
            }
            $count++;
        }

        wp_send_json_success( array(
            'count'   => $count,
            'action'  => $action,
            'message' => $this->action_message( $action, $count ),
        ) );
    }

    public function handle_export() {
        $is_csv  = isset( $_GET['mut_bulk_export'] );
        $is_xlsx = isset( $_GET['mut_bulk_export_xlsx'] );

        if ( ! $is_csv && ! $is_xlsx ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        check_admin_referer( 'mut_bulk_export' );

        $status  = sanitize_key( $is_xlsx ? $_GET['mut_bulk_export_xlsx'] : $_GET['mut_bulk_export'] );
        $allowed = array( 'flagged', 'archived', 'all' );
        if ( ! in_array( $status, $allowed, true ) ) {
            wp_die( 'Invalid export type.' );
        }

        $items  = $this->storage->get_review_items( $status === 'all' ? '' : $status );
        $header = array( 'ID', 'File Name', 'Title', 'URL', 'Review Status', 'Flagged At' );

        $rows = array();
        foreach ( $items as $item ) {
            $file   = get_attached_file( $item->attachment_id );
            $rows[] = array(
                $item->attachment_id,
                $file ? basename( $file ) : '(missing)',
                get_the_title( $item->attachment_id ),
                wp_get_attachment_url( $item->attachment_id ),
                $item->status,
                $item->flagged_at,
            );
        }

        if ( $is_xlsx ) {
            $xls = new \MediaUsageTracker\Excel_Export( 'Bulk Review' );
            $xls->add_header_row( $header );
            foreach ( $rows as $row ) {
                $xls->add_row( $row );
            }
            $xls->send( 'mut-review-' . $status . '-' . gmdate( 'Y-m-d' ) . '.xlsx' );
        }

        // CSV
        $filename = 'mut-review-' . $status . '-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $header );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render() {
        $current_tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'all';
        $allowed_tabs = array( 'all', 'flagged', 'archived' );
        if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
            $current_tab = 'all';
        }

        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        $results  = $this->query_attachments( $current_tab, $paged, $per_page );
        $items    = $results['items'];
        $total    = $results['total'];
        $pages    = $total > 0 ? ceil( $total / $per_page ) : 1;

        $counts   = $this->storage->get_review_counts();

        $export_url = wp_nonce_url(
            add_query_arg( array(
                'page'            => 'mut-bulk-review',
                'mut_bulk_export' => $current_tab === 'all' ? 'all' : $current_tab,
            ), admin_url( 'admin.php' ) ),
            'mut_bulk_export'
        );

        $export_xlsx_url = wp_nonce_url(
            add_query_arg( array(
                'page'                 => 'mut-bulk-review',
                'mut_bulk_export_xlsx' => $current_tab === 'all' ? 'all' : $current_tab,
            ), admin_url( 'admin.php' ) ),
            'mut_bulk_export'
        );
        ?>
        <div class="wrap mut-bulk-review">
            <h1>📋 Bulk Review</h1>
            <p class="mut-bulk-intro">Select files and apply bulk actions. Deletion remains manual for safety.</p>

            <?php $this->render_tabs( $current_tab, $counts ); ?>

            <div id="mut-bulk-notice" class="mut-bulk-notice hidden"></div>

            <div class="mut-bulk-toolbar" id="mut-bulk-toolbar">
                <label class="mut-bulk-select-all-label">
                    <input type="checkbox" id="mut-select-all"> Select All
                </label>
                <span class="mut-bulk-selected-count" id="mut-selected-count">0 selected</span>
                <div class="mut-bulk-actions">
                    <button class="button mut-bulk-btn" data-action="flag" disabled>
                        🚩 Mark for Review
                    </button>
                    <button class="button mut-bulk-btn" data-action="archive" disabled>
                        📦 Archive Status
                    </button>
                    <button class="button mut-bulk-btn mut-bulk-clear" data-action="clear" disabled>
                        ✕ Clear Status
                    </button>
                </div>
                <div class="mut-bulk-toolbar-right">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button">
                        ⬇ Export List (CSV)
                    </a>
                    <a href="<?php echo esc_url( $export_xlsx_url ); ?>" class="button">
                        ⬇ Export List (Excel)
                    </a>
                    <?php
                    require_once MUT_PLUGIN_DIR . 'includes/class-pdf-exporter.php';
                    ?>
                    <a href="<?php echo esc_url( \MediaUsageTracker\Admin\PdfExporter::export_url( 'bulk_review' ) ); ?>" class="button" target="_blank">
                        ⬇ Export PDF
                    </a>
                </div>
            </div>

            <?php if ( ! empty( $items ) ) : ?>

                <div class="md:overflow-hidden md:rounded-lg md:border md:border-gray-200 md:bg-white md:shadow-sm">
                <table class="mut-bulk-table w-full block md:table text-sm text-left text-gray-700" id="mut-bulk-table">
                    <thead class="max-md:hidden md:table-header-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr class="md:table-row">
                            <th class="md:table-cell md:w-[4%] px-4 py-3"><input type="checkbox" id="mut-select-all-top" class="h-4 w-4 accent-indigo-600"></th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">Preview</th>
                            <th class="md:table-cell md:w-[28%] px-4 py-3">File / Title</th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">Type</th>
                            <th class="md:table-cell md:w-[10%] px-4 py-3">Status</th>
                            <th class="md:table-cell md:w-[12%] px-4 py-3">Review Status</th>
                            <th class="md:table-cell md:w-[14%] px-4 py-3">Upload Date</th>
                            <th class="md:table-cell md:w-[16%] px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="block md:table-row-group md:divide-y md:divide-gray-100">
                        <?php foreach ( $items as $item ) : ?>
                            <tr data-id="<?php echo esc_attr( $item['id'] ); ?>" class="mut-bulk-row <?php echo $item['review_status'] ? 'mut-row-' . esc_attr( $item['review_status'] ) : ''; ?> flex flex-wrap items-center gap-x-3 gap-y-2 md:table-row mb-3 last:mb-0 md:mb-0 rounded-lg md:rounded-none border md:border-0 border-gray-200 bg-white p-3 md:p-0 md:hover:bg-gray-50 md:even:bg-gray-50/60">
                                <td class="order-1 md:table-cell md:w-[4%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <input type="checkbox" class="mut-row-cb h-4 w-4 accent-indigo-600" value="<?php echo esc_attr( $item['id'] ); ?>">
                                </td>
                                <td class="order-2 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <?php
                                    $thumb = wp_get_attachment_image( $item['id'], array( 50, 50 ), true, array(
                                        'class' => 'h-10 w-10 rounded object-cover border border-gray-200',
                                    ) );
                                    echo $thumb ?: '<span class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-lg">📄</span>';
                                    ?>
                                </td>
                                <td class="order-3 flex-1 min-w-0 md:table-cell md:w-[28%] md:flex-none px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <strong>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $item['id'] ) ); ?>" class="text-gray-900 hover:text-indigo-600 no-underline">
                                            <?php echo esc_html( $item['filename'] ); ?>
                                        </a>
                                    </strong>
                                    <?php if ( $item['title'] && $item['title'] !== $item['filename'] ) : ?>
                                        <br><span class="text-xs text-gray-500"><?php echo esc_html( $item['title'] ); ?></span>
                                    <?php endif; ?>
                                    <br><span class="text-xs text-gray-400 md:hidden"><?php echo esc_html( $item['upload_date'] ); ?></span>
                                </td>
                                <td class="order-4 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
                                <td class="order-5 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600"><?php echo esc_html( $item['mime_label'] ); ?></span>
                                </td>
                                <td class="order-6 md:table-cell md:w-[10%] px-0 md:px-4 py-1 md:py-3 md:align-middle before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none">
                                    <?php if ( $item['usage_count'] > 0 ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $item['id'] ) ); ?>" class="no-underline" title="View usage locations">
                                            <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800">In Use</span>
                                        </a>
                                    <?php else : ?>
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">Unused</span>
                                    <?php endif; ?>
                                </td>
                                <td class="order-7 mut-review-status-cell md:table-cell md:w-[12%] px-0 md:px-4 py-1 md:py-3 md:align-middle before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none">
                                    <?php echo $this->render_review_badge( $item['review_status'] ); ?>
                                </td>
                                <td class="order-8 max-md:hidden md:table-cell md:w-[14%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500">
                                    <?php echo esc_html( $item['upload_date'] ); ?>
                                </td>
                                <td class="order-9 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
                                <td class="order-10 md:table-cell md:w-[16%] px-0 md:px-4 py-1 md:py-3 md:align-middle flex gap-1.5 md:whitespace-nowrap">
                                    <a href="<?php echo esc_url( admin_url( 'upload.php?item=' . $item['id'] ) ); ?>" class="button button-small" title="View in Media Library">View</a>
                                    <?php if ( $item['usage_count'] === 0 ) : ?>
                                        <button type="button" class="button button-small mut-delete-btn" data-id="<?php echo esc_attr( $item['id'] ); ?>" data-name="<?php echo esc_attr( $item['filename'] ); ?>" title="Safely delete this file">🗑️</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div class="mut-bulk-toolbar mut-bulk-toolbar--bottom">
                    <label class="mut-bulk-select-all-label">
                        <input type="checkbox" class="mut-select-all-bottom"> Select All
                    </label>
                    <span class="mut-bulk-selected-count">0 selected</span>
                    <div class="mut-bulk-actions">
                        <button class="button mut-bulk-btn" data-action="flag" disabled>
                            🚩 Mark for Review
                        </button>
                        <button class="button mut-bulk-btn" data-action="archive" disabled>
                            📦 Archive Status
                        </button>
                        <button class="button mut-bulk-btn mut-bulk-clear" data-action="clear" disabled>
                            ✕ Clear Status
                        </button>
                    </div>
                    <div class="mut-bulk-toolbar-right">
                        <a href="<?php echo esc_url( $export_url ); ?>" class="button">
                            ⬇ Export List (CSV)
                        </a>
                        <a href="<?php echo esc_url( $export_xlsx_url ); ?>" class="button">
                            ⬇ Export List (Excel)
                        </a>
                        <a href="<?php echo esc_url( \MediaUsageTracker\Admin\PdfExporter::export_url( 'bulk_review' ) ); ?>" class="button" target="_blank">
                            ⬇ Export PDF
                        </a>
                    </div>
                </div>

                <?php $this->render_pagination( $current_tab, $paged, $pages ); ?>

            <?php else : ?>
                <div class="mut-no-results">
                    <p>
                        <?php if ( $current_tab === 'all' ) : ?>
                            No media files found in the library.
                        <?php else : ?>
                            No files with status <strong><?php echo esc_html( $current_tab ); ?></strong> yet.
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-bulk-review' ) ); ?>">View all files</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php $this->render_delete_modal(); ?>
        </div>
        <?php
    }

    /**
     * Safe Delete confirmation modal. Hidden until a 🗑️ button is clicked;
     * populated live with the verify() gate results before deletion is allowed.
     */
    private function render_delete_modal() {
        ?>
        <div id="mut-delete-modal" class="mut-modal-overlay hidden" aria-hidden="true">
            <div class="mut-modal" role="dialog" aria-modal="true" aria-labelledby="mut-modal-title">
                <div class="mut-modal-header">
                    <h2 id="mut-modal-title">🛡️ Safe Delete Check</h2>
                    <button type="button" class="mut-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="mut-modal-body">
                    <p class="mut-modal-file">
                        Reviewing: <strong id="mut-modal-filename"></strong>
                    </p>
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

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------

    private function render_tabs( $current, $counts ) {
        $tabs = array(
            'all'      => 'All Files',
            'flagged'  => '🚩 Flagged',
            'archived' => '📦 Archived',
        );
        echo '<nav class="mut-bulk-tabs nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $count_badge = '';
            if ( $slug === 'flagged' && ! empty( $counts['flagged'] ) ) {
                $count_badge = ' <span class="mut-tab-count mut-tab-count--flagged">' . number_format( $counts['flagged'] ) . '</span>';
            } elseif ( $slug === 'archived' && ! empty( $counts['archived'] ) ) {
                $count_badge = ' <span class="mut-tab-count mut-tab-count--archived">' . number_format( $counts['archived'] ) . '</span>';
            }
            $url   = add_query_arg( array( 'page' => 'mut-bulk-review', 'tab' => $slug ), admin_url( 'admin.php' ) );
            $class = $slug === $current ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . $count_badge . '</a>';
        }
        echo '</nav>';
    }

    // -------------------------------------------------------------------------
    // Review badge
    // -------------------------------------------------------------------------

    private function render_review_badge( $status ) {
        if ( ! $status ) {
            return '<span class="mut-review-badge mut-review-none">—</span>';
        }
        $map = array(
            'flagged'  => array( 'class' => 'mut-review-flagged',  'label' => '🚩 Flagged' ),
            'archived' => array( 'class' => 'mut-review-archived', 'label' => '📦 Archived' ),
        );
        $def = $map[ $status ] ?? array( 'class' => '', 'label' => ucfirst( $status ) );
        return '<span class="mut-review-badge ' . esc_attr( $def['class'] ) . '">' . esc_html( $def['label'] ) . '</span>';
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    private function query_attachments( $tab, $paged, $per_page ) {
        global $wpdb;

        $offset     = ( $paged - 1 ) * $per_page;
        $review_tbl = $wpdb->prefix . 'mut_review_status';
        $usage_tbl  = $wpdb->prefix . 'mut_media_usage';

        $where = array( "p.post_type = 'attachment'", "p.post_status = 'inherit'" );

        if ( $tab === 'flagged' ) {
            $where[] = "r.status = 'flagged'";
        } elseif ( $tab === 'archived' ) {
            $where[] = "r.status = 'archived'";
        }

        $join      = "LEFT JOIN {$review_tbl} r ON p.ID = r.attachment_id";
        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             {$join}
             {$where_sql}"
        );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.guid, p.post_date, p.post_mime_type,
                    r.status AS review_status,
                    COUNT(DISTINCT u.post_id) AS usage_count
             FROM {$wpdb->posts} p
             {$join}
             LEFT JOIN {$usage_tbl} u ON p.ID = u.attachment_id
             {$where_sql}
             GROUP BY p.ID
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        $items = array();
        foreach ( $rows as $row ) {
            $file    = get_attached_file( $row->ID );
            $items[] = array(
                'id'            => (int) $row->ID,
                'title'         => $row->post_title,
                'filename'      => $file ? basename( $file ) : basename( $row->guid ),
                'mime_label'    => $this->mime_label( $row->post_mime_type ),
                'usage_count'   => (int) $row->usage_count,
                'review_status' => $row->review_status ?: '',
                'upload_date'   => get_the_date( 'M j, Y', $row->ID ),
            );
        }

        return array( 'total' => $total, 'items' => $items );
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    private function render_pagination( $tab, $current_page, $total_pages ) {
        if ( $total_pages <= 1 ) {
            return;
        }
        $base_url = add_query_arg( array( 'page' => 'mut-bulk-review', 'tab' => $tab ), admin_url( 'admin.php' ) );
        echo '<div class="mut-pagination">';
        if ( $current_page > 1 ) {
            echo '<a href="' . esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ) . '" class="button">‹ Prev</a>';
        }
        for ( $i = max( 1, $current_page - 2 ); $i <= min( $total_pages, $current_page + 2 ); $i++ ) {
            if ( $i === $current_page ) {
                echo '<span class="button button-primary mut-page-current">' . $i . '</span>';
            } else {
                echo '<a href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '" class="button">' . $i . '</a>';
            }
        }
        if ( $current_page < $total_pages ) {
            echo '<a href="' . esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ) . '" class="button">Next ›</a>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function action_message( $action, $count ) {
        $labels = array(
            'flag'    => $count . ' file' . ( $count !== 1 ? 's' : '' ) . ' marked for review.',
            'archive' => $count . ' file' . ( $count !== 1 ? 's' : '' ) . ' archived.',
            'clear'   => $count . ' file' . ( $count !== 1 ? 's' : '' ) . ' status cleared.',
        );
        return $labels[ $action ] ?? 'Done.';
    }

    private function mime_label( $mime ) {
        $map = array(
            'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF',
            'image/webp' => 'WebP', 'image/svg+xml' => 'SVG',
            'application/pdf' => 'PDF',
            'video/mp4' => 'MP4', 'video/quicktime' => 'MOV', 'video/webm' => 'WebM',
            'audio/mpeg' => 'MP3', 'audio/wav' => 'WAV',
        );
        return $map[ $mime ] ?? strtoupper( substr( strrchr( $mime, '/' ), 1 ) );
    }
}
