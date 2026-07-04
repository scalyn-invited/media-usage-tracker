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
            <h1>🗂️ Media Usage</h1>
            <p id="mut-ud-found" class="mut-ud-found-text"><?php echo number_format( $count_all ); ?> media files found.</p>

            <div class="mut-ud-stats-bar">
                <button type="button" class="mut-ud-stat active" data-filter="">
                    <div class="mut-ud-stat-num mut-ud-stat-blue"><?php echo number_format( $count_all ); ?></div>
                    <div class="mut-ud-stat-label">Total</div>
                </button>
                <button type="button" class="mut-ud-stat" data-filter="used">
                    <div class="mut-ud-stat-num mut-ud-stat-green"><?php echo number_format( $count_used ); ?></div>
                    <div class="mut-ud-stat-label">In use</div>
                </button>
                <button type="button" class="mut-ud-stat" data-filter="unused">
                    <div class="mut-ud-stat-num mut-ud-stat-red"><?php echo number_format( $count_unused ); ?></div>
                    <div class="mut-ud-stat-label">Unused</div>
                </button>
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

                <div class="md:overflow-hidden md:rounded-lg md:border md:border-gray-200 md:bg-white md:shadow-sm">
                <table class="mut-list-table w-full block md:table text-sm text-left text-gray-700" id="mut-ud-table">
                    <thead class="max-md:hidden md:table-header-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr class="md:table-row">
                            <th style="display:none;" id="mut-ud-cb-th" class="mut-ud-cb-col md:table-cell md:w-[4%] px-4 py-3">
                                <input type="checkbox" id="mut-ud-select-all-th" class="h-4 w-4 accent-indigo-600">
                            </th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">Thumbnail</th>
                            <th class="md:table-cell md:w-[28%] px-4 py-3">Filename</th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">Media Type</th>
                            <th class="md:table-cell md:w-[12%] px-4 py-3">Upload Date</th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">File Size</th>
                            <th class="md:table-cell md:w-[8%] px-4 py-3">Usage Count</th>
                            <th class="md:table-cell md:w-[10%] px-4 py-3">Status</th>
                            <th class="md:table-cell md:w-[14%] px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="block md:table-row-group md:divide-y md:divide-gray-100">
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
                                'class' => 'h-10 w-10 rounded object-cover border border-gray-200',
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
                            <tr data-id="<?php echo esc_attr( $id ); ?>" data-inuse="<?php echo $in_use ? '1' : '0'; ?>"
                                class="flex flex-wrap items-center gap-x-3 gap-y-2 md:table-row mb-3 last:mb-0 md:mb-0 rounded-lg md:rounded-none border md:border-0 border-gray-200 bg-white p-3 md:p-0 md:hover:bg-gray-50 md:even:bg-gray-50/60">
                                <td class="mut-ud-cb-col order-1 md:table-cell md:w-[4%] px-0 md:px-4 py-1 md:py-3 md:align-middle" style="display:none;">
                                    <input type="checkbox" class="mut-ud-cb h-4 w-4 accent-indigo-600" value="<?php echo esc_attr( $id ); ?>">
                                </td>
                                <td class="order-2 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <?php if ( $show_thumb ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo $thumb; ?></a>
                                    <?php else : ?>
                                        <span class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 text-[10px] font-semibold text-gray-500"><?php echo esc_html( strtoupper( $type_class ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="order-3 flex-1 min-w-0 md:table-cell md:w-[28%] md:flex-none px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="text-gray-900 hover:text-indigo-600 no-underline">
                                        <strong><?php echo esc_html( $filename ); ?></strong>
                                    </a>
                                    <br><span class="text-xs text-gray-500"><?php echo esc_html( $att->post_title ); ?></span>
                                    <br><span class="text-xs text-gray-400 md:hidden"><?php echo esc_html( $upload_date ); ?></span>
                                </td>
                                <td class="order-4 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
                                <td class="order-5 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                        <?php echo esc_html( $type_label ); ?>
                                    </span>
                                </td>
                                <td class="order-6 max-md:hidden md:table-cell md:w-[12%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500"><?php echo esc_html( $upload_date ); ?></td>
                                <td class="order-7 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500 before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none"><?php echo esc_html( $this->get_filesize( $id ) ); ?></td>
                                <td class="order-8 md:table-cell md:w-[8%] px-0 md:px-4 py-1 md:py-3 md:align-middle before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none">
                                    <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-bold text-white <?php echo $count > 0 ? 'bg-emerald-600' : 'bg-gray-400'; ?>">
                                        <?php echo $count; ?>
                                    </span>
                                </td>
                                <td class="order-9 md:table-cell md:w-[10%] px-0 md:px-4 py-1 md:py-3 md:align-middle before:content-['·'] before:mr-3 before:text-gray-300 md:before:content-none">
                                    <?php if ( $in_use ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>" class="no-underline" title="View usage locations">
                                            <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800">In Use</span>
                                        </a>
                                    <?php else : ?>
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">Unused</span>
                                    <?php endif; ?>
                                </td>
                                <td class="order-10 basis-full w-0 h-0 p-0 md:hidden" aria-hidden="true"></td>
                                <td class="order-11 md:table-cell md:w-[14%] px-0 md:px-4 py-1 md:py-3 md:align-middle flex gap-1.5 md:whitespace-nowrap">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small" title="View Usage">👁 View</a>
                                    <a href="<?php echo esc_url( $review_url ); ?>" class="button button-small" title="Review">🚩 Review</a>
                                    <?php if ( ! $in_use ) : ?>
                                        <button type="button" class="button button-small mut-delete-btn mut-ud-del-btn"
                                            data-id="<?php echo esc_attr( $id ); ?>"
                                            data-name="<?php echo esc_attr( $filename ); ?>"
                                            title="Delete this file">🗑️</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div class="mut-bulk-toolbar" id="mut-ud-bulk-bar-bottom" style="display:none;margin-top:8px;border-top:1px solid #c3c4c7;border-bottom:1px solid #c3c4c7;">
                    <label style="font-weight:600;cursor:pointer;">
                        <input type="checkbox" id="mut-ud-select-all-bottom"> Select All
                    </label>
                    <span id="mut-ud-count-bottom" style="margin-left:12px;color:#787c82;">0 selected</span>
                    <button type="button" id="mut-ud-bulk-trash-bottom" class="button" disabled style="margin-left:12px;">🗑️ Move to Trash</button>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->render_delete_modal(); ?>

        <script>
        (function($){
            var $table    = $('#mut-ud-table');
            var $bar      = $('#mut-ud-bulk-bar, #mut-ud-bulk-bar-bottom');
            var $found    = $('#mut-ud-found');
            var $trashBtn = $('#mut-ud-bulk-trash, #mut-ud-bulk-trash-bottom');
            var $count    = $('#mut-ud-count, #mut-ud-count-bottom');
            var SELECT_ALL = '#mut-ud-select-all, #mut-ud-select-all-th, #mut-ud-select-all-bottom';
            var currentFilter = '';

            function applyFilter(filter) {
                currentFilter = filter;
                $('.mut-ud-pill').removeClass('active');
                $('.mut-ud-pill[data-filter="' + filter + '"]').addClass('active');
                $('.mut-ud-stat').removeClass('active');
                $('.mut-ud-stat[data-filter="' + filter + '"]').addClass('active');

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

            // --- Stat card filtering (tablet/mobile summary bar) ---
            $('.mut-ud-stat').on('click', function(){
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
        /* Type badges (still used by the single-attachment detail view) */
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

        .mut-meta { font-size: 12px; color: #787c82; }

        /* Stats bar — hidden on desktop */
        .mut-ud-stats-bar { display: none; }

        /* Bulk-select checkboxes are a desktop-only feature */
        @media screen and (max-width: 1100px) {
            .mut-ud-cb-col { display: none !important; }
        }

        /* ── TABLET + MOBILE: show stats bar ──────────────────── */
        @media screen and (max-width: 1100px) {
            .mut-ud-stats-bar {
                display: flex; gap: 8px; margin-bottom: 16px;
            }
            .mut-ud-stat {
                flex: 1; background: #f6f7f7; border-radius: 8px;
                border: 2px solid transparent;
                padding: 10px; text-align: center;
                font: inherit; cursor: pointer;
                -webkit-appearance: none; appearance: none;
            }
            .mut-ud-stat:hover { background: #edeeee; }
            .mut-ud-stat:active { background: #e4e5e5; }
            .mut-ud-stat.active { border-color: #2271b1; background: #f0f6ff; }
            .mut-ud-stat-num { font-size: 20px; font-weight: 700; line-height: 1.2; }
            .mut-ud-stat-blue { color: #2271b1; }
            .mut-ud-stat-green { color: #00a32a; }
            .mut-ud-stat-red { color: #d63638; }
            .mut-ud-stat-label { font-size: 11px; color: #787c82; margin-top: 2px; }
            .mut-ud-found-text { display: none; }
        }

        /* ── MOBILE (< 782px) ──────────────────────────────────── */
        @media screen and (max-width: 782px) {
            .mut-quick-chips { overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; }
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
                    <table class="mut-meta-table border-collapse mb-4">
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">File Name</th><td class="py-1.5 align-top text-sm text-gray-900"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs"><?php echo esc_html( $filename ); ?></code></td></tr>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">Media Type</th><td class="py-1.5 align-top text-sm text-gray-900">
                            <span class="mut-type-badge mut-type-<?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( $type_label ); ?></span>
                        </td></tr>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">Upload Date</th><td class="py-1.5 align-top text-sm text-gray-900"><?php echo esc_html( $upload_date ); ?></td></tr>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">File Size</th><td class="py-1.5 align-top text-sm text-gray-900"><?php echo esc_html( $filesize ); ?></td></tr>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">Usage Count</th><td class="py-1.5 align-top text-sm text-gray-900"><strong><?php echo count( $unique_posts ); ?> post(s)</strong></td></tr>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">Status</th><td class="py-1.5 align-top text-sm text-gray-900">
                            <?php if ( $in_use ) : ?>
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800">In Use</span>
                            <?php else : ?>
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">Unused</span>
                            <?php endif; ?>
                        </td></tr>
                        <?php if ( $is_image ) : ?>
                        <tr><th class="py-1.5 pr-4 text-left align-top text-sm font-semibold text-gray-500 whitespace-nowrap">Alt Text</th><td class="py-1.5 align-top text-sm text-gray-900">
                            <?php if ( $alt_text ) : ?>
                                <span class="text-gray-900"><?php echo esc_html( $alt_text ); ?></span>
                            <?php else : ?>
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide bg-gray-200 text-gray-600">Not Set</span>
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
                <div class="md:overflow-hidden md:rounded-lg md:border md:border-gray-200 md:bg-white md:shadow-sm">
                <table class="w-full block md:table text-sm text-left text-gray-700">
                    <thead class="max-md:hidden md:table-header-group bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr class="md:table-row">
                            <th class="md:table-cell md:w-[35%] px-4 py-3">Post / Page Title</th>
                            <th class="md:table-cell md:w-[12%] px-4 py-3">Content Type</th>
                            <th class="md:table-cell md:w-[18%] px-4 py-3">Usage Type</th>
                            <th class="md:table-cell md:w-[25%] px-4 py-3">Context</th>
                            <th class="md:table-cell md:w-[10%] px-4 py-3">Edit</th>
                        </tr>
                    </thead>
                    <tbody class="block md:table-row-group md:divide-y md:divide-gray-100">
                        <?php
                        foreach ( $usages as $usage ) :
                            $post        = get_post( $usage->post_id );
                            $title       = $post ? $post->post_title : '(Deleted)';
                            $post_status = $post ? $post->post_status : '';
                            $edit_link   = get_edit_post_link( $usage->post_id );

                            // Status labels to show next to title (skip 'publish' — that's the default)
                            $status_labels = array(
                                'draft'   => array( 'label' => 'Draft',   'classes' => 'bg-amber-100 text-amber-800' ),
                                'pending' => array( 'label' => 'Pending', 'classes' => 'bg-blue-100 text-blue-800' ),
                                'private' => array( 'label' => 'Private', 'classes' => 'bg-gray-100 text-gray-600' ),
                                'future'  => array( 'label' => 'Scheduled', 'classes' => 'bg-purple-100 text-purple-800' ),
                                'trash'   => array( 'label' => 'Trash',   'classes' => 'bg-red-100 text-red-800' ),
                            );
                            $status_info = $status_labels[ $post_status ] ?? null;
                        ?>
                            <tr class="block md:table-row mb-3 last:mb-0 md:mb-0 rounded-lg md:rounded-none border md:border-0 border-gray-200 bg-white p-3 md:p-0 md:hover:bg-gray-50 md:even:bg-gray-50/60">
                                <td class="block md:table-cell md:w-[35%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>" class="text-gray-900 hover:text-indigo-600 no-underline"><?php echo esc_html( $title ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $title ); ?>
                                    <?php endif; ?>
                                    <?php if ( $status_info ) : ?>
                                        <span class="ml-1.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold <?php echo esc_attr( $status_info['classes'] ); ?>">
                                            <?php echo esc_html( $status_info['label'] ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="block md:table-cell md:w-[12%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 md:hidden">Content Type: </span><?php echo esc_html( ucfirst( $usage->post_type ) ); ?>
                                </td>
                                <td class="block md:table-cell md:w-[18%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <span class="inline-block rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                        <?php echo esc_html( $this->format_usage_type( $usage->usage_type ) ); ?>
                                    </span>
                                </td>
                                <td class="block md:table-cell md:w-[25%] px-0 md:px-4 py-1 md:py-3 md:align-middle text-xs text-gray-500"><?php echo esc_html( wp_trim_words( $usage->context, 15 ) ); ?></td>
                                <td class="block md:table-cell md:w-[10%] px-0 md:px-4 py-1 md:py-3 md:align-middle">
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">Edit</a>
                                    <?php else : ?>
                                        <span class="text-xs text-gray-400">—</span>
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
