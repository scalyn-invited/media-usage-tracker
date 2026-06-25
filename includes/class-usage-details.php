<?php
namespace MediaUsageTracker\Admin;

class UsageDetails {
    private $storage;

    public function __construct( $storage ) {
        $this->storage = $storage;
    }

    public function render() {
        $attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;

        if ( $attachment_id ) {
            $this->render_single( $attachment_id );
        } else {
            $this->render_list();
        }
    }

    // -------------------------------------------------------------------------
    // List view — ALL media files
    // -------------------------------------------------------------------------

    private function render_list() {
        global $wpdb;

        // Get ALL attachments, not just those with usage records
        $attachments = $wpdb->get_results(
            "SELECT ID, post_title, post_mime_type, post_date
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
             ORDER BY post_date DESC"
        );

        // Build a set of attachment IDs that have usage records
        $used_ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$wpdb->prefix}mut_media_usage" );
        $used_ids = array_flip( array_map( 'intval', $used_ids ) );

        $count_all    = count( $attachments );
        $count_used   = count( $used_ids );
        $count_unused = $count_all - $count_used;
        ?>
        <div class="wrap mut-usage-details">
            <h1>Media Usage</h1>
            <p id="mut-ud-found" class="mut-ud-found-text"><?php echo number_format( $count_all ); ?> media files found.</p>

            <div class="mut-ud-stats-bar">
                <div class="mut-ud-stat">
                    <div class="mut-ud-stat-num mut-ud-stat-blue"><?php echo number_format( $count_all ); ?></div>
                    <div class="mut-ud-stat-label">Total</div>
                </div>
                <div class="mut-ud-stat">
                    <div class="mut-ud-stat-num mut-ud-stat-green"><?php echo number_format( $count_used ); ?></div>
                    <div class="mut-ud-stat-label">In use</div>
                </div>
                <div class="mut-ud-stat">
                    <div class="mut-ud-stat-num mut-ud-stat-red"><?php echo number_format( $count_unused ); ?></div>
                    <div class="mut-ud-stat-label">Unused</div>
                </div>
            </div>

            <div class="mut-quick-chips" style="margin-bottom:16px;">
                <button type="button" class="mut-quick-chip mut-ud-pill active" data-filter="">
                    All <span class="mut-chip-count"><?php echo number_format( $count_all ); ?></span>
                </button>
                <button type="button" class="mut-quick-chip mut-ud-pill" data-filter="used">
                    In Use <span class="mut-chip-count"><?php echo number_format( $count_used ); ?></span>
                </button>
                <button type="button" class="mut-quick-chip mut-ud-pill" data-filter="unused">
                    Unused <span class="mut-chip-count"><?php echo number_format( $count_unused ); ?></span>
                </button>
            </div>

            <?php if ( empty( $attachments ) ) : ?>
                <p>No media files found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=media-usage-tracker' ) ); ?>">Run a scan first.</a></p>
            <?php else : ?>

                <div class="mut-bulk-toolbar" id="mut-ud-bulk-bar" style="display:none;margin-bottom:0;border-top:1px solid #c3c4c7;border-bottom:1px solid #c3c4c7;">
                    <label style="font-weight:600;cursor:pointer;">
                        <input type="checkbox" id="mut-ud-select-all"> Select All
                    </label>
                    <span id="mut-ud-count" style="margin-left:12px;color:#787c82;">0 selected</span>
                    <button type="button" id="mut-ud-bulk-trash" class="button" disabled style="margin-left:12px;">🗑️ Move to Trash</button>
                </div>

                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped mut-list-table" id="mut-ud-table">
                    <thead>
                        <tr>
                            <th style="width:32px;display:none;" id="mut-ud-cb-th" class="mut-ud-cb-col">
                                <input type="checkbox" id="mut-ud-select-all-th">
                            </th>
                            <th class="mut-col-thumb">Thumbnail</th>
                            <th>Filename</th>
                            <th class="mut-col-type">Media Type</th>
                            <th class="mut-col-date">Upload Date</th>
                            <th class="mut-col-size">File Size</th>
                            <th class="mut-col-count">Usage Count</th>
                            <th class="mut-col-status">Status</th>
                            <th class="mut-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $attachments as $att ) :
                            $id          = absint( $att->ID );
                            $in_use      = isset( $used_ids[ $id ] );
                            $count       = $in_use ? $this->storage->get_usage_count( $id ) : 0;
                            $detail_url  = admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $id );
                            $review_url  = admin_url( 'admin.php?page=mut-bulk-review' );
                            $export_url  = add_query_arg( array(
                                'page'        => 'mut-reports',
                                'export_xlsx' => 'attachment_' . $id,
                            ), admin_url( 'admin.php' ) );
                            $thumb       = wp_get_attachment_image( $id, array( 56, 56 ), true, array(
                                'style' => 'width:56px;height:56px;object-fit:cover;border-radius:4px;display:block;'
                            ) );
                            $filename    = basename( get_attached_file( $id ) ) ?: $att->post_title;
                            $upload_date = get_the_date( 'M j, Y', $id );
                            $mime        = $att->post_mime_type;
                            $type_label  = $this->mime_label( $mime );
                            $type_class  = $this->mime_class( $mime );
                            $file_path   = get_attached_file( $id );
                            $is_image    = strpos( (string) $mime, 'image/' ) === 0;
                            // Only trust the generated <img> when the underlying file is
                            // present. A missing image file otherwise renders as an empty
                            // broken-image box; fall back to the labelled type icon instead.
                            $show_thumb  = $thumb && ( ! $is_image || ( $file_path && file_exists( $file_path ) ) );
                        ?>
                            <tr data-id="<?php echo esc_attr( $id ); ?>" data-inuse="<?php echo $in_use ? '1' : '0'; ?>">
                                <td class="mut-ud-cb-col" style="display:none;text-align:center;vertical-align:middle;">
                                    <input type="checkbox" class="mut-ud-cb" value="<?php echo esc_attr( $id ); ?>">
                                </td>
                                <td class="mut-td-thumb">
                                    <?php if ( $show_thumb ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo $thumb; ?></a>
                                    <?php else : ?>
                                        <span class="mut-file-icon mut-icon-<?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( strtoupper( $type_class ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mut-td-filename">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="mut-filename-link">
                                        <strong><?php echo esc_html( $filename ); ?></strong>
                                    </a><br>
                                    <span class="mut-meta"><?php echo esc_html( $att->post_title ); ?></span>
                                </td>
                                <td class="mut-td-type">
                                    <span class="mut-type-badge mut-type-<?php echo esc_attr( $type_class ); ?>">
                                        <?php echo esc_html( $type_label ); ?>
                                    </span>
                                </td>
                                <td class="mut-td-date"><?php echo esc_html( $upload_date ); ?></td>
                                <td class="mut-td-size"><?php echo esc_html( $this->get_filesize( $id ) ); ?></td>
                                <td class="mut-td-count mut-cell-count">
                                    <span class="mut-count <?php echo $count > 0 ? 'mut-count-used' : 'mut-count-zero'; ?>">
                                        <?php echo $count; ?>
                                    </span>
                                </td>
                                <td class="mut-td-status">
                                    <?php if ( $in_use ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>" style="text-decoration:none;" title="View usage locations">
                                            <span class="mut-status-badge mut-status-inuse">In Use</span>
                                        </a>
                                    <?php else : ?>
                                        <span class="mut-status-badge mut-status-unused">Unused</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mut-td-actions mut-actions-cell">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small mut-act-desk" title="View Usage">👁 View</a>
                                    <a href="<?php echo esc_url( $review_url ); ?>" class="button button-small mut-act-desk" title="Review">🚩 Review</a>
                                    <?php if ( ! $in_use ) : ?>
                                        <button type="button" class="button button-small mut-delete-btn mut-ud-del-btn mut-act-desk"
                                            data-id="<?php echo esc_attr( $id ); ?>"
                                            data-name="<?php echo esc_attr( $filename ); ?>"
                                            title="Delete this file">🗑️</button>
                                    <?php endif; ?>
                                    <div class="mut-mob-actions">
                                        <a href="<?php echo esc_url( $detail_url ); ?>" class="mut-mob-btn">View usage</a>
                                        <a href="<?php echo esc_url( $review_url ); ?>" class="mut-mob-btn">Review</a>
                                        <?php if ( ! $in_use ) : ?>
                                            <button type="button" class="mut-mob-btn mut-mob-btn-del mut-delete-btn mut-ud-del-btn"
                                                data-id="<?php echo esc_attr( $id ); ?>"
                                                data-name="<?php echo esc_attr( $filename ); ?>">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->render_delete_modal(); ?>

        <script>
        (function($){
            var $table    = $('#mut-ud-table');
            var $bar      = $('#mut-ud-bulk-bar');
            var $found    = $('#mut-ud-found');
            var $trashBtn = $('#mut-ud-bulk-trash');
            var $count    = $('#mut-ud-count');
            var SELECT_ALL = '#mut-ud-select-all, #mut-ud-select-all-th';
            var currentFilter = '';

            function applyFilter(filter) {
                currentFilter = filter;
                $('.mut-ud-pill').removeClass('active');
                $('.mut-ud-pill[data-filter="' + filter + '"]').addClass('active');

                var visible = 0;
                $table.find('tbody tr').each(function(){
                    var inuse = $(this).data('inuse');
                    var show = filter === '' ||
                               (filter === 'used'   && inuse == 1) ||
                               (filter === 'unused' && inuse == 0);
                    $(this).toggle(show);
                    if (show) visible++;
                });

                $found.text(visible.toLocaleString() + ' media files found.');

                var isUnused = filter === 'unused';
                $bar.toggle(isUnused);
                $('.mut-ud-cb-col').toggle(isUnused);
                if (!isUnused) {
                    $table.find('.mut-ud-cb').prop('checked', false);
                    updateBulkBar();
                }
            }

            // Auto-apply filter from URL hash on page load
            var hash = window.location.hash.replace('#', '');
            if (hash === 'used' || hash === 'unused') {
                applyFilter(hash);
            }

            // --- Pill filtering ---
            $('.mut-ud-pill').on('click', function(){
                applyFilter($(this).data('filter'));
            });

            // --- Bulk checkboxes ---
            function getChecked() { return $table.find('.mut-ud-cb:checked'); }

            function updateBulkBar() {
                var n = getChecked().length;
                $count.text(n + ' selected');
                $trashBtn.prop('disabled', n === 0);
                var total = $table.find('.mut-ud-cb:visible').length;
                $(SELECT_ALL).prop('checked', total > 0 && n === total);
            }

            $(document).on('change', SELECT_ALL, function(){
                var checked = $(this).prop('checked');
                $table.find('tbody tr:visible .mut-ud-cb').prop('checked', checked);
                $(SELECT_ALL).prop('checked', checked);
                updateBulkBar();
            });

            $table.on('change', '.mut-ud-cb', updateBulkBar);

            // --- Bulk trash ---
            $trashBtn.on('click', function(){
                var ids = getChecked().map(function(){ return $(this).val(); }).get();
                if (!ids.length) return;
                if (!confirm('Move ' + ids.length + ' file(s) to Trash?')) return;

                $trashBtn.prop('disabled', true).text('Moving…');
                var remaining = ids.length;

                ids.forEach(function(id){
                    $.post(mutAjax.ajax_url, {
                        action: 'mut_safe_delete',
                        nonce:  mutAjax.bulk_nonce,
                        id:     id,
                        force:  0
                    }, function(res){
                        if (res.success) {
                            $table.find('tr[data-id="' + id + '"]').fadeOut(300, function(){ $(this).remove(); });
                        }
                        remaining--;
                        if (remaining === 0) {
                            $trashBtn.text('🗑️ Move to Trash');
                            updateBulkBar();
                        }
                    });
                });
            });
            // Tablet: tap row to toggle actions on touch devices
            if ('ontouchstart' in window) {
                $table.on('click', 'tbody tr', function(e) {
                    if ($(e.target).closest('a, button, input').length) return;
                    var w = window.innerWidth;
                    if (w >= 783 && w <= 1100) {
                        $table.find('tbody tr').not(this).removeClass('mut-tab-active');
                        $(this).toggleClass('mut-tab-active');
                    }
                });
            }
        })(jQuery);
        </script>

        <style>
        .mut-list-table { min-width: 900px; table-layout: auto; }
        .mut-col-thumb { width: 72px; }
        .mut-col-type { width: 100px; }
        .mut-col-date { width: 120px; }
        .mut-col-size { width: 80px; }
        .mut-col-count { width: 100px; }
        .mut-col-status { width: 90px; }
        .mut-col-actions { width: 200px; }
        .mut-file-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px; height: 56px;
            border-radius: 4px;
            background: #f0f0f1;
            font-size: 10px;
            font-weight: 700;
            color: #646970;
            letter-spacing: .04em;
        }

        /* Type badges */
        .mut-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .mut-type-image  { background: #e8f5e9; color: #1b5e20; }
        .mut-type-video  { background: #e3f2fd; color: #0d47a1; }
        .mut-type-audio  { background: #fce4ec; color: #880e4f; }
        .mut-type-pdf    { background: #fff3e0; color: #e65100; }
        .mut-type-doc    { background: #ede7f6; color: #4527a0; }
        .mut-type-other  { background: #e2e8f0; color: #334155; }

        /* Status badges — match shared mut-admin.css definition exactly */
        .mut-status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: none;
        }
        .mut-status-inuse  { background: #d1f0d1; color: #1a5a1a; }
        .mut-status-unused { background: #e8e8e8; color: #666; }

        .mut-actions-cell { white-space: nowrap; }
        .mut-actions-cell .button { margin-right: 3px; }
        .mut-filename-link { text-decoration: none; color: #1d2327; }
        .mut-filename-link:hover { color: #2271b1; }
        .mut-meta { font-size: 12px; color: #787c82; }

        /* Stats bar — hidden on desktop */
        .mut-ud-stats-bar { display: none; }
        .mut-mob-actions { display: none; }

        /* ── TABLET + MOBILE: show stats bar ──────────────────── */
        @media screen and (max-width: 1100px) {
            .mut-ud-stats-bar {
                display: flex; gap: 8px; margin-bottom: 16px;
            }
            .mut-ud-stat {
                flex: 1; background: #f6f7f7; border-radius: 8px;
                padding: 10px; text-align: center;
            }
            .mut-ud-stat-num { font-size: 20px; font-weight: 700; line-height: 1.2; }
            .mut-ud-stat-blue { color: #2271b1; }
            .mut-ud-stat-green { color: #00a32a; }
            .mut-ud-stat-red { color: #d63638; }
            .mut-ud-stat-label { font-size: 11px; color: #787c82; margin-top: 2px; }
            .mut-ud-found-text { display: none; }
        }

        /* ── TABLET card-rows (783px – 1100px) ─────────────────── */
        @media screen and (min-width: 783px) and (max-width: 1100px) {
            .mut-list-table { min-width: 0; border: none; box-shadow: none; }
            .mut-list-table thead { display: none; }
            .mut-list-table tbody tr {
                display: flex; flex-wrap: nowrap; align-items: center;
                background: #fff; border: 1px solid #e0e0e0;
                border-radius: 12px; padding: 14px 16px; margin-bottom: 8px;
                position: relative; overflow: hidden;
                transition: background 0.15s;
            }
            .mut-list-table tbody tr:hover { background: #f9f9f9; }
            .mut-list-table tbody tr td { border: none; padding: 0; }

            /* Clean row: thumb + filename + status */
            .mut-td-thumb {
                order: 1; width: 44px; flex-shrink: 0; margin-right: 16px;
            }
            .mut-td-thumb img { width: 44px !important; height: 44px !important; border-radius: 6px !important; }
            .mut-td-filename {
                order: 2; flex: 1; min-width: 0;
            }
            .mut-td-filename .mut-filename-link strong {
                display: block; white-space: nowrap; overflow: hidden;
                text-overflow: ellipsis; font-size: 14px;
            }
            .mut-td-status {
                order: 3; flex-shrink: 0;
                display: inline-flex !important; align-items: center;
                margin-left: 12px;
            }

            /* Hide all metadata */
            .mut-td-type   { display: none !important; }
            .mut-td-size   { display: none !important; }
            .mut-td-count  { display: none !important; }
            .mut-td-date   { display: none !important; }

            /* Actions: hidden by default, slide in on hover */
            .mut-td-actions {
                order: 4; position: absolute; right: 0; top: 0; bottom: 0;
                display: flex !important; align-items: center; gap: 2px;
                padding: 0 8px 0 24px;
                background: linear-gradient(to right, transparent, #f9f9f9 20px);
                opacity: 0; transition: opacity 0.15s;
                white-space: nowrap;
            }
            .mut-list-table tbody tr:hover .mut-td-actions,
            .mut-list-table tbody tr.mut-tab-active .mut-td-actions {
                opacity: 1;
            }
            .mut-act-desk {
                display: inline-flex !important; align-items: center; justify-content: center;
                min-width: 36px; height: 36px; padding: 0 8px; border-radius: 6px;
                border: 1px solid #dcdcde; background: #fff; color: #1d2327;
                cursor: pointer; font-size: 13px; transition: background 0.1s;
            }
            .mut-act-desk:hover { background: #f0f0f1; }
            .mut-act-desk[title="Delete this file"] { border-color: #f0b8b8; color: #d63638; }
            .mut-act-desk[title="Delete this file"]:hover { background: #fef1f1; }
            .mut-mob-actions { display: none !important; }
            .mut-ud-cb-col { display: none !important; }
        }

        /* ── MOBILE cards (< 782px) ────────────────────────────── */
        @media screen and (max-width: 782px) {
            .mut-quick-chips { overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
            .mut-list-table { min-width: 0; border: none; box-shadow: none; }
            .mut-list-table thead { display: none; }
            .mut-list-table tbody tr {
                display: flex; flex-direction: column;
                background: #fff; border: 1px solid #e0e0e0;
                border-radius: 12px; padding: 16px; margin-bottom: 12px;
            }
            .mut-list-table tbody tr td { border: none; padding: 0; }

            .mut-td-thumb {
                order: 1; text-align: center; margin-bottom: 12px;
            }
            .mut-td-thumb img { width: 80px !important; height: 80px !important; border-radius: 8px !important; }
            .mut-td-thumb .mut-file-icon { width: 80px; height: 80px; font-size: 14px; margin: 0 auto; }
            .mut-td-filename { order: 2; margin-bottom: 12px; }
            .mut-td-filename .mut-filename-link strong {
                font-size: 15px; white-space: normal; word-break: break-word;
            }
            .mut-td-type   { order: 3; }
            .mut-td-size   { order: 4; }
            .mut-td-date   { order: 5; }
            .mut-td-count  { order: 6; }
            .mut-td-status { order: 7; }
            .mut-td-type, .mut-td-size, .mut-td-date,
            .mut-td-count, .mut-td-status {
                display: flex !important; align-items: center;
                font-size: 13px; gap: 8px; margin-bottom: 6px;
                background: none !important; padding: 0 !important;
                border-radius: 0 !important; color: #1d2327;
            }
            .mut-td-type::before   { content: 'Type';   color: #787c82; min-width: 55px; flex-shrink: 0; font-size: 12px; }
            .mut-td-size::before   { content: 'Size';   color: #787c82; min-width: 55px; flex-shrink: 0; font-size: 12px; }
            .mut-td-date::before   { content: 'Date';   color: #787c82; min-width: 55px; flex-shrink: 0; font-size: 12px; }
            .mut-td-count::before  { content: 'Uses';   color: #787c82; min-width: 55px; flex-shrink: 0; font-size: 12px; }
            .mut-td-status::before { content: 'Status'; color: #787c82; min-width: 55px; flex-shrink: 0; font-size: 12px; }

            .mut-td-actions {
                order: 20; margin-top: 12px; padding-top: 12px;
                border-top: 1px solid #f0f0f1;
            }
            .mut-act-desk { display: none !important; }
            .mut-mob-actions {
                display: flex !important; flex-direction: column; gap: 0;
            }
            .mut-mob-actions {
                border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden;
                display: flex !important; flex-direction: column;
            }
            .mut-mob-btn {
                display: block; width: 100%; padding: 12px 10px;
                min-height: 44px; box-sizing: border-box;
                border: none; border-bottom: 1px solid #e8e8e8;
                background: #fff; color: #1d2327;
                font-size: 13px; font-weight: 600; line-height: 20px;
                text-decoration: none; text-align: center; cursor: pointer;
            }
            .mut-mob-btn:last-child { border-bottom: none; }
            .mut-mob-btn:hover { background: #f6f7f7; }
            .mut-mob-btn-del { color: #d63638; }
            .mut-mob-btn-del:hover { background: #fef1f1; }
            .mut-ud-cb-col { display: none !important; }
            .mut-bulk-toolbar { flex-wrap: wrap; gap: 8px; }
        }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Single attachment detail view
    // -------------------------------------------------------------------------

    private function render_single( $attachment_id ) {
        $attachment = get_post( $attachment_id );
        if ( ! $attachment ) {
            echo '<div class="wrap"><h1>Error</h1><p>Media not found.</p></div>';
            return;
        }

        $usages      = $this->storage->get_usages_for_attachment( $attachment_id );
        $filename    = basename( get_attached_file( $attachment_id ) );
        $upload_date = get_the_date( 'M j, Y g:i A', $attachment );
        $filesize    = $this->get_filesize( $attachment_id );
        $edit_url    = get_edit_post_link( $attachment_id );
        $media_url   = admin_url( 'upload.php?item=' . $attachment_id );
        $mime        = $attachment->post_mime_type;
        $type_label  = $this->mime_label( $mime );
        $type_class  = $this->mime_class( $mime );

        $usages       = $this->deduplicate_usages( $usages );
        $unique_posts = array_unique( array_column( $usages, 'post_id' ) );
        $in_use       = ! empty( $usages );
        $alt_text     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        $is_image     = strpos( (string) $mime, 'image/' ) === 0;
        ?>
        <div class="wrap mut-usage-details">

            <div class="mut-detail-back">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-usage-details' ) ); ?>" class="button">← Back to Media Usage</a>
            </div>

            <div class="mut-detail-header">
                <div class="mut-detail-thumb">
                    <?php echo wp_get_attachment_image( $attachment_id, 'medium' ); ?>
                </div>
                <div class="mut-detail-meta">
                    <h1><?php echo esc_html( $attachment->post_title ); ?></h1>
                    <table class="mut-meta-table">
                        <tr><th>File Name</th><td><code><?php echo esc_html( $filename ); ?></code></td></tr>
                        <tr><th>Media Type</th><td>
                            <span class="mut-type-badge mut-type-<?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( $type_label ); ?></span>
                        </td></tr>
                        <tr><th>Upload Date</th><td><?php echo esc_html( $upload_date ); ?></td></tr>
                        <tr><th>File Size</th><td><?php echo esc_html( $filesize ); ?></td></tr>
                        <tr><th>Usage Count</th><td><strong><?php echo count( $unique_posts ); ?> post(s)</strong></td></tr>
                        <tr><th>Status</th><td>
                            <?php
                            $badge_base   = 'display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;border:none;';
                            $badge_inuse  = $badge_base . 'background:#d1f0d1;color:#1a5a1a;';
                            $badge_unused = $badge_base . 'background:#e8e8e8;color:#666;';
                            if ( $in_use ) : ?>
                                <span style="<?php echo esc_attr( $badge_inuse ); ?>">In Use</span>
                            <?php else : ?>
                                <span style="<?php echo esc_attr( $badge_unused ); ?>">Unused</span>
                            <?php endif; ?>
                        </td></tr>
                        <?php if ( $is_image ) : ?>
                        <tr><th>Alt Text</th><td>
                            <?php if ( $alt_text ) : ?>
                                <span style="color:#1d2327;"><?php echo esc_html( $alt_text ); ?></span>
                            <?php else : ?>
                                <span style="<?php echo esc_attr( $badge_unused ); ?>">Not Set</span>
                            <?php endif; ?>
                        </td></tr>
                        <?php endif; ?>
                    </table>
                    <div class="mut-detail-actions">
                        <a href="<?php echo esc_url( $media_url ); ?>" class="button">View in Media Library</a>
                        <?php if ( $edit_url ) : ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button">Edit Media</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-bulk-review' ) ); ?>" class="button">🚩 Review</a>
                        <a href="<?php echo esc_url( add_query_arg( array( 'export' => 'all' ), admin_url( 'admin.php?page=mut-reports' ) ) ); ?>" class="button">⬇ Export</a>
                    </div>
                </div>
            </div>

            <?php if ( empty( $usages ) ) : ?>
                <div class="mut-no-usage">
                    <p>⚠️ This media file has no recorded usage — it may be unused. <a href="<?php echo esc_url( admin_url( 'admin.php?page=media-usage-tracker' ) ); ?>">Run a scan</a> to verify.</p>
                </div>
            <?php else : ?>
                <h2>Found in <?php echo count( $unique_posts ); ?> location(s)</h2>
                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped mut-locations-table">
                    <thead>
                        <tr>
                            <th>Post / Page Title</th>
                            <th style="width:120px;">Content Type</th>
                            <th style="width:150px;">Usage Type</th>
                            <th style="width:200px;">Context</th>
                            <th style="width:100px;">Edit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ( $usages as $usage ) :
                            $post        = get_post( $usage->post_id );
                            $title       = $post ? $post->post_title : '(Deleted)';
                            $post_status = $post ? $post->post_status : '';
                            $edit_link   = get_edit_post_link( $usage->post_id );

                            // Status labels to show next to title (skip 'publish' — that's the default)
                            $status_labels = array(
                                'draft'   => array( 'label' => 'Draft',   'color' => '#dba617', 'bg' => '#fef9e7' ),
                                'pending' => array( 'label' => 'Pending', 'color' => '#2271b1', 'bg' => '#f0f6ff' ),
                                'private' => array( 'label' => 'Private', 'color' => '#787c82', 'bg' => '#f6f7f7' ),
                                'future'  => array( 'label' => 'Scheduled','color'=> '#8b5cf6', 'bg' => '#f5f0ff' ),
                                'trash'   => array( 'label' => 'Trash',   'color' => '#d63638', 'bg' => '#fde8e8' ),
                            );
                            $status_info = $status_labels[ $post_status ] ?? null;
                        ?>
                            <tr>
                                <td>
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $title ); ?>
                                    <?php endif; ?>
                                    <?php if ( $status_info ) : ?>
                                        <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo esc_attr( $status_info['bg'] ); ?>;color:<?php echo esc_attr( $status_info['color'] ); ?>;">
                                            <?php echo esc_html( $status_info['label'] ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( ucfirst( $usage->post_type ) ); ?></td>
                                <td>
                                    <span class="mut-usage-type mut-type-<?php echo esc_attr( $usage->usage_type ); ?>">
                                        <?php echo esc_html( $this->format_usage_type( $usage->usage_type ) ); ?>
                                    </span>
                                </td>
                                <td class="mut-context"><?php echo esc_html( wp_trim_words( $usage->context, 15 ) ); ?></td>
                                <td>
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">Edit</a>
                                    <?php else : ?>
                                        <span class="mut-meta">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Priority order for usage types — lower number = higher priority.
     * When the same image appears in the same post via multiple detectors,
     * only the highest-priority record is kept for display.
     */
    private function usage_type_priority() {
        return array(
            'woocommerce'    => 1,
            'featured_image' => 2,
            'acf'            => 3,
            'jetengine'      => 4,
            'elementor'      => 5,
            'divi'           => 6,
            'wpbakery'       => 7,
            'avada'          => 8,
            'beaver_builder' => 9,
            'yoast'          => 10,
            'jetpopup'       => 11,
            'content'        => 12,
            'gallery'        => 12,
            'video_poster'   => 13,
            'sitewide'       => 14,
            'gravityforms'   => 15,
            'astra'          => 16,
            'wpdatatables'   => 17,
        );
    }

    /**
     * Collapse multiple usage rows for the same post down to one per post,
     * keeping the record with the highest-priority usage type.
     *
     * @param  object[] $usages  Raw rows from storage.
     * @return object[]
     */
    private function deduplicate_usages( array $usages ) {
        $priority = $this->usage_type_priority();
        $best     = array(); // post_id → winning usage row

        foreach ( $usages as $usage ) {
            $pid  = (int) $usage->post_id;
            $rank = $priority[ $usage->usage_type ] ?? 99;

            if ( ! isset( $best[ $pid ] ) ) {
                $best[ $pid ] = array( 'row' => $usage, 'rank' => $rank );
            } elseif ( $rank < $best[ $pid ]['rank'] ) {
                $best[ $pid ] = array( 'row' => $usage, 'rank' => $rank );
            }
        }

        return array_values( array_column( $best, 'row' ) );
    }

    private function get_filesize( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( $file && file_exists( $file ) ) {
            return size_format( filesize( $file ) );
        }
        return '—';
    }

    private function mime_label( $mime ) {
        if ( strpos( $mime, 'image/' ) === 0 )       return 'Image';
        if ( strpos( $mime, 'video/' ) === 0 )       return 'Video';
        if ( strpos( $mime, 'audio/' ) === 0 )       return 'Audio';
        if ( $mime === 'application/pdf' )            return 'PDF';
        if ( strpos( $mime, 'word' ) !== false )      return 'Word Doc';
        if ( strpos( $mime, 'spreadsheet' ) !== false || strpos( $mime, 'excel' ) !== false ) return 'Spreadsheet';
        return ucwords( str_replace( array( 'application/', '/' ), array( '', ' ' ), $mime ) );
    }

    private function mime_class( $mime ) {
        if ( strpos( $mime, 'image/' ) === 0 )       return 'image';
        if ( strpos( $mime, 'video/' ) === 0 )       return 'video';
        if ( strpos( $mime, 'audio/' ) === 0 )       return 'audio';
        if ( $mime === 'application/pdf' )            return 'pdf';
        if ( strpos( $mime, 'word' ) !== false || strpos( $mime, 'spreadsheet' ) !== false ) return 'doc';
        return 'other';
    }

    private function format_usage_type( $type ) {
        $labels = array(
            'featured_image' => 'Featured Image',
            'content'        => 'In Content',
            'gallery'        => 'Gallery',
            'acf'            => 'ACF',
            'elementor'      => 'Elementor',
            'divi'           => 'Divi',
            'wpbakery'       => 'WPBakery',
            'beaver_builder' => 'Beaver Builder',
            'yoast'          => 'Yoast SEO',
            'jetengine'      => 'JetEngine',
            'jetpopup'       => 'JetPopup',
            'gallery'        => 'Gallery',
            'video_poster'   => 'Video Poster',
            'sitewide'       => 'Sitewide Setting',
            'gravityforms'   => 'Gravity Forms',
            'wpdatatables'   => 'wpDataTables',
            'woocommerce'    => 'WooCommerce',
        );
        return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
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
}
