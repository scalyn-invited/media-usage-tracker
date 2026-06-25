<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

class MediaSearch {

    private $storage;

    /** Size thresholds for the "large"/"small" filters. */
    const LARGE_BYTES = 2097152;  // 2 MB
    const SMALL_BYTES = 102400;   // 100 KB

    /** Cap on rows examined when a size filter forces per-file stat()'ing. */
    const SIZE_SCAN_CAP = 5000;

    public function __construct( UsageStorage $storage ) {
        $this->storage = $storage;
    }

    public function render() {
        $filters       = $this->get_filters();
        $results       = $this->query_attachments( $filters );
        $total         = $results['total'];
        $items         = $results['items'];
        $per_page      = 20;
        $page          = max( 1, absint( $filters['paged'] ) );
        $pages         = $total > 0 ? ceil( $total / $per_page ) : 1;
        $status_counts = $this->query_status_counts( $filters );
        ?>
        <div class="wrap mut-search">
            <h1>🔍 Search &amp; Filter Media</h1>

            <!-- AI Natural Language Search -->
            <div class="mut-nl-search-bar" id="mut-nl-bar">
                <div class="mut-nl-inner">
                    <span class="mut-nl-icon">✨</span>
                    <input type="text" id="mut-nl-input" class="mut-nl-input"
                        placeholder="e.g. &quot;show large unused images from last year&quot; or &quot;find PDFs uploaded this month&quot;"
                        autocomplete="off" />
                    <button id="mut-nl-submit" class="button button-primary mut-nl-btn">Search with AI</button>
                </div>
                <div id="mut-nl-status" class="mut-nl-status" style="display:none;"></div>
            </div>

            <div class="mut-quick-chips">
                <span class="mut-quick-chips-label">Quick filters:</span>
                <?php
                $chips = array(
                    'Unused Images'    => array( 'usage_status' => 'unused', 'media_type' => 'image' ),
                    'Unused PDFs'      => array( 'usage_status' => 'unused', 'media_type' => 'application/pdf' ),
                    'Large Files'      => array( 'size' => 'large' ),
                    'Large & Unused'   => array( 'usage_status' => 'unused', 'size' => 'large' ),
                    'Small Files'      => array( 'size' => 'small' ),
                    'Uploaded This Week' => array( 'date_range' => '7days' ),
                    'Uploaded This Month' => array( 'date_range' => '30days' ),
                );
                foreach ( $chips as $label => $params ) {
                    $url = add_query_arg( array_merge( array( 'page' => 'mut-search' ), $params ), admin_url( 'admin.php' ) );
                    echo '<a href="' . esc_url( $url ) . '" class="mut-quick-chip">' . esc_html( $label ) . '</a>';
                }
                ?>
            </div>

            <form method="get" action="" class="mut-search-form">
                <input type="hidden" name="page" value="mut-search">

                <div class="mut-search-bar">
                    <input
                        type="text"
                        name="s"
                        value="<?php echo esc_attr( $filters['s'] ); ?>"
                        placeholder="Search by filename or title…"
                        class="mut-search-input"
                    >
                    <button type="submit" class="button button-primary">Search</button>
                    <?php if ( $this->has_active_filters( $filters ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-search' ) ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>

                <div class="mut-filter-row">

                    <label class="mut-filter-label">
                        Usage Status
                        <select name="usage_status" class="mut-filter-select">
                            <option value=""       <?php selected( $filters['usage_status'], '' ); ?>>All Files</option>
                            <option value="used"   <?php selected( $filters['usage_status'], 'used' ); ?>>Used</option>
                            <option value="unused" <?php selected( $filters['usage_status'], 'unused' ); ?>>Unused</option>
                        </select>
                    </label>

                    <label class="mut-filter-label">
                        Media Type
                        <select name="media_type" class="mut-filter-select">
                            <option value=""      <?php selected( $filters['media_type'], '' ); ?>>All Types</option>
                            <option value="image" <?php selected( $filters['media_type'], 'image' ); ?>>Images</option>
                            <option value="application/pdf" <?php selected( $filters['media_type'], 'application/pdf' ); ?>>PDFs</option>
                            <option value="video" <?php selected( $filters['media_type'], 'video' ); ?>>Videos</option>
                            <option value="audio" <?php selected( $filters['media_type'], 'audio' ); ?>>Audio</option>
                        </select>
                    </label>

                    <label class="mut-filter-label">
                        Upload Date
                        <select name="date_range" class="mut-filter-select">
                            <option value=""        <?php selected( $filters['date_range'], '' ); ?>>Any Date</option>
                            <option value="today"   <?php selected( $filters['date_range'], 'today' ); ?>>Today</option>
                            <option value="7days"   <?php selected( $filters['date_range'], '7days' ); ?>>Last 7 Days</option>
                            <option value="30days"  <?php selected( $filters['date_range'], '30days' ); ?>>Last 30 Days</option>
                            <option value="90days"  <?php selected( $filters['date_range'], '90days' ); ?>>Last 90 Days</option>
                            <option value="1year"   <?php selected( $filters['date_range'], '1year' ); ?>>Last Year</option>
                        </select>
                    </label>

                    <label class="mut-filter-label">
                        Size
                        <select name="size" class="mut-filter-select">
                            <option value=""      <?php selected( $filters['size'], '' ); ?>>Any Size</option>
                            <option value="large" <?php selected( $filters['size'], 'large' ); ?>>Large (&ge; <?php echo esc_html( size_format( self::LARGE_BYTES ) ); ?>)</option>
                            <option value="small" <?php selected( $filters['size'], 'small' ); ?>>Small (&lt; <?php echo esc_html( size_format( self::SMALL_BYTES ) ); ?>)</option>
                        </select>
                    </label>

                    <?php
                    $source_plugins = $this->get_source_plugins();
                    if ( ! empty( $source_plugins ) ) : ?>
                    <label class="mut-filter-label">
                        Referenced By
                        <select name="source" class="mut-filter-select">
                            <option value="" <?php selected( $filters['source'], '' ); ?>>Any Plugin</option>
                            <?php foreach ( $source_plugins as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['source'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>

                    <button type="submit" class="button mut-filter-apply">Apply Filters</button>
                </div>

            </form>

            <?php $this->render_active_filter_badges( $filters ); ?>

            <?php
            $status_pill_base = add_query_arg(
                array_diff_key( array_filter( array(
                    'page'       => 'mut-search',
                    's'          => $filters['s'],
                    'media_type' => $filters['media_type'],
                    'date_range' => $filters['date_range'],
                    'size'       => $filters['size'],
                    'source'     => $filters['source'],
                ) ), array_flip( array() ) ),
                admin_url( 'admin.php' )
            );
            $cur_status = $filters['usage_status'];
            ?>
            <div class="mut-quick-chips" style="margin-bottom:12px;">
                <a href="<?php echo esc_url( add_query_arg( 'usage_status', '', $status_pill_base ) ); ?>"
                   class="mut-quick-chip <?php echo $cur_status === '' ? 'active' : ''; ?>">
                    All <span class="mut-chip-count"><?php echo number_format( $status_counts['all'] ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'usage_status', 'used', $status_pill_base ) ); ?>"
                   class="mut-quick-chip <?php echo $cur_status === 'used' ? 'active' : ''; ?>">
                    In Use <span class="mut-chip-count"><?php echo number_format( $status_counts['used'] ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'usage_status', 'unused', $status_pill_base ) ); ?>"
                   class="mut-quick-chip <?php echo $cur_status === 'unused' ? 'active' : ''; ?>">
                    Unused <span class="mut-chip-count"><?php echo number_format( $status_counts['unused'] ); ?></span>
                </a>
            </div>

            <?php $is_unused_filter = ( $filters['usage_status'] === 'unused' ); ?>

            <?php if ( $is_unused_filter && $total > 0 ) : ?>
            <div class="mut-search-bulk-bar" id="mut-search-bulk-bar" style="display:none;">
                <span class="mut-bulk-selected-count">0 selected</span>
                <button type="button" class="button mut-search-bulk-trash" disabled>🗑 Move to Trash</button>
                <button type="button" class="button-link mut-search-bulk-deselect" style="margin-left:8px;">Deselect all</button>
            </div>
            <?php endif; ?>

            <div class="mut-search-results-header">
                <span class="mut-results-count">
                    <?php
                    if ( $total === 0 ) {
                        echo 'No files found.';
                        if ( ( $filters['source'] ?? '' ) === 'jetpopup' ) {
                            echo ' <span class="mut-source-hint">JetPopup dynamic images are tracked under JetEngine.</span>';
                        }
                    } elseif ( $total === 1 ) {
                        echo '1 file found.';
                    } else {
                        echo number_format( $total ) . ' files found.';
                    }
                    ?>
                </span>
                <?php if ( $total > 0 ) : ?>
                    <span class="mut-pagination-info">
                        Page <?php echo $page; ?> of <?php echo $pages; ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $items ) ) : ?>

                <div style="overflow-x:auto;">
                <table class="wp-list-table widefat striped mut-search-table">
                    <thead>
                        <tr>
                            <?php if ( $is_unused_filter ) : ?>
                            <th class="mut-col-cb" style="width:36px;">
                                <input type="checkbox" id="mut-search-cb-all" title="Select all">
                            </th>
                            <?php endif; ?>
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
                        <?php foreach ( $items as $item ) : ?>
                            <?php
                            $detail_url = admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $item['id'] );
                            $count      = $item['usage_count'];
                            $filename   = esc_attr( $item['filename'] );
                            ?>
                            <tr data-id="<?php echo esc_attr( $item['id'] ); ?>">
                                <?php if ( $is_unused_filter ) : ?>
                                <td class="mut-col-cb">
                                    <input type="checkbox" class="mut-search-cb-row" value="<?php echo esc_attr( $item['id'] ); ?>">
                                </td>
                                <?php endif; ?>
                                <td class="mut-td-thumb">
                                    <?php
                                    $thumb = wp_get_attachment_image( $item['id'], array( 56, 56 ), true, array(
                                        'style' => 'width:56px;height:56px;object-fit:cover;border-radius:4px;display:block;',
                                    ) );
                                    echo $thumb ?: '<span class="mut-file-icon">' . esc_html( $item['mime_label'] ) . '</span>';
                                    ?>
                                </td>
                                <td class="mut-td-filename">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="mut-filename-link">
                                        <strong><?php echo esc_html( $item['filename'] ); ?></strong>
                                    </a>
                                    <?php if ( $item['title'] && $item['title'] !== $item['filename'] ) : ?>
                                        <br><span class="mut-meta"><?php echo esc_html( $item['title'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mut-td-type">
                                    <span class="mut-type-badge mut-type-<?php echo esc_attr( strtolower( $item['mime_label'] ) ); ?>">
                                        <?php echo esc_html( $item['mime_label'] ); ?>
                                    </span>
                                </td>
                                <td class="mut-td-date"><?php echo esc_html( $item['upload_date'] ); ?></td>
                                <td class="mut-td-size"><?php echo esc_html( $item['filesize'] ); ?></td>
                                <td class="mut-td-count mut-cell-count">
                                    <span class="mut-count <?php echo $count > 0 ? 'mut-count-used' : 'mut-count-zero'; ?>">
                                        <?php echo $count; ?>
                                    </span>
                                </td>
                                <td class="mut-td-status">
                                    <?php if ( $count > 0 ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>" style="text-decoration:none;" title="View usage locations">
                                            <span class="mut-status-badge mut-status-used">In Use</span>
                                        </a>
                                    <?php else : ?>
                                        <span class="mut-status-badge mut-status-unused">Unused</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mut-td-actions mut-actions-cell">
                                    <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small mut-act-desk" title="View Usage">👁 View</a>
                                    <?php if ( $is_unused_filter ) : ?>
                                    <button type="button" class="button button-small mut-delete-btn mut-act-desk"
                                        data-id="<?php echo esc_attr( $item['id'] ); ?>"
                                        data-name="<?php echo $filename; ?>"
                                        title="Move to Trash" style="color:#d63638;">🗑️</button>
                                    <?php endif; ?>
                                    <div class="mut-mob-actions">
                                        <a href="<?php echo esc_url( $detail_url ); ?>" class="mut-mob-btn">View usage</a>
                                        <?php if ( $is_unused_filter ) : ?>
                                        <button type="button" class="mut-mob-btn mut-mob-btn-del mut-delete-btn"
                                            data-id="<?php echo esc_attr( $item['id'] ); ?>"
                                            data-name="<?php echo $filename; ?>">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php $this->render_pagination( $filters, $page, $pages ); ?>

            <?php elseif ( $this->has_active_filters( $filters ) ) : ?>
                <div class="mut-no-results">
                    <p>No media files match your search criteria. <a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-search' ) ); ?>">Clear all filters</a> to start over.</p>
                </div>
            <?php else : ?>
                <div class="mut-no-results">
                    <p>No media files found in the library.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->render_delete_modal(); ?>

        <style>
        .mut-search-bulk-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f0f6ff;
            border: 1px solid #b3d1f7;
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        .mut-bulk-selected-count { font-weight: 600; color: #2271b1; min-width: 80px; }
        .mut-col-cb { width: 36px !important; }
        </style>

        <script>
        (function ($) {
            var $bar    = $('#mut-search-bulk-bar');
            var $allCb  = $('#mut-search-cb-all');
            var $count  = $('.mut-bulk-selected-count');
            var $bulkBtn = $('.mut-search-bulk-trash');

            function updateBar() {
                var n = $('.mut-search-cb-row:checked').length;
                $count.text(n + ' selected');
                $bulkBtn.prop('disabled', n === 0);
                if (n > 0) { $bar.show(); } else { $bar.hide(); }
            }

            $allCb.on('change', function () {
                $('.mut-search-cb-row').prop('checked', this.checked);
                updateBar();
            });

            $(document).on('change', '.mut-search-cb-row', function () {
                var total = $('.mut-search-cb-row').length;
                var checked = $('.mut-search-cb-row:checked').length;
                $allCb.prop('checked', total === checked).prop('indeterminate', checked > 0 && checked < total);
                updateBar();
            });

            $('.mut-search-bulk-deselect').on('click', function () {
                $('.mut-search-cb-row, #mut-search-cb-all').prop('checked', false);
                updateBar();
            });

            // Bulk move to trash
            $bulkBtn.on('click', function () {
                var ids = $('.mut-search-cb-row:checked').map(function () { return $(this).val(); }).get();
                if (!ids.length) return;
                if (!confirm('Move ' + ids.length + ' file(s) to trash?')) return;
                $bulkBtn.prop('disabled', true).text('Moving…');
                var done = 0, failed = 0;
                var nonce = typeof mutAdmin !== 'undefined' ? mutAdmin.nonce : '';
                ids.forEach(function (id) {
                    $.post(ajaxurl, { action: 'mut_safe_delete', id: id, force: 1, nonce: nonce }, function (res) {
                        if (res.success) { done++; $('tr[data-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); }); }
                        else { failed++; }
                        if (done + failed === ids.length) {
                            $bulkBtn.text('🗑 Move to Trash');
                            updateBar();
                            if (failed) alert(failed + ' file(s) could not be deleted.');
                        }
                    });
                });
            });
        }(jQuery));
        </script>
        <?php
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

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    /**
     * Map usage_type values → human labels for the "Referenced by" dropdown.
     * Only plugins whose detector is_available() are shown.
     */
    private function get_source_plugins() {
        $all = array(
            'elementor'   => array( 'label' => 'Elementor',     'check' => function() { return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' ); } ),
            'jetengine'   => array( 'label' => 'JetEngine',     'check' => function() { return class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' ); } ),
            'acf'         => array( 'label' => 'ACF',           'check' => function() { return function_exists( 'get_field_objects' ); } ),
            'divi'        => array( 'label' => 'Divi',          'check' => function() { return defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' ); } ),
            'woocommerce' => array( 'label' => 'WooCommerce',   'check' => function() { return class_exists( 'WooCommerce' ) || function_exists( 'WC' ); } ),
            'yoast'       => array( 'label' => 'Yoast SEO',     'check' => function() { return defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' ); } ),
            'wpbakery'    => array( 'label' => 'WPBakery',      'check' => function() { return class_exists( 'Vc_Manager' ) || function_exists( 'vc_map' ); } ),
            'beaver_builder' => array( 'label' => 'Beaver Builder', 'check' => function() { return class_exists( 'FLBuilder' ) || class_exists( 'FL_Builder' ); } ),
            'jetpopup'    => array( 'label' => 'JetPopup',       'check' => function() { return class_exists( 'Jet_Popup' ) || function_exists( 'jet_popup' ); } ),
            'gravityforms'=> array( 'label' => 'Gravity Forms',  'check' => function() { return class_exists( 'GFAPI' ) || function_exists( 'gravity_form' ); } ),
            'astra'       => array( 'label' => 'Astra',          'check' => function() { return defined( 'ASTRA_THEME_VERSION' ) || get_template() === 'astra'; } ),
            'avada'       => array( 'label' => 'Avada',          'check' => function() { return class_exists( 'FusionBuilder' ) || defined( 'FUSION_BUILDER_VERSION' ) || defined( 'AVADA_VERSION' ) || class_exists( 'Avada' ); } ),
        );

        $active = array();
        foreach ( $all as $key => $def ) {
            if ( call_user_func( $def['check'] ) ) {
                $active[ $key ] = $def['label'];
            }
        }
        return $active;
    }

    private function get_filters() {
        $filters = array(
            's'            => isset( $_GET['s'] )            ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'usage_status' => isset( $_GET['usage_status'] ) ? sanitize_key( $_GET['usage_status'] ) : '',
            'media_type'   => isset( $_GET['media_type'] )   ? sanitize_text_field( $_GET['media_type'] ) : '',
            'date_range'   => isset( $_GET['date_range'] )   ? sanitize_key( $_GET['date_range'] ) : '',
            'size'         => isset( $_GET['size'] )         ? sanitize_key( $_GET['size'] ) : '',
            'source'       => isset( $_GET['source'] )       ? sanitize_key( $_GET['source'] ) : '',
            'paged'        => isset( $_GET['paged'] )        ? absint( $_GET['paged'] ) : 1,
        );

        return $filters;
    }

    private function has_active_filters( $filters ) {
        return $filters['s'] !== '' || $filters['usage_status'] !== '' || $filters['media_type'] !== ''
            || $filters['date_range'] !== '' || $filters['size'] !== '' || $filters['source'] !== '';
    }

    private function render_active_filter_badges( $filters ) {
        $source_plugins = $this->get_source_plugins();
        $labels = array(
            's'            => $filters['s'] ? 'Search: "' . esc_html( $filters['s'] ) . '"' : '',
            'usage_status' => $filters['usage_status'] ? ucfirst( $filters['usage_status'] ) . ' files' : '',
            'media_type'   => $filters['media_type'] ? $this->media_type_label( $filters['media_type'] ) : '',
            'date_range'   => $filters['date_range'] ? $this->date_range_label( $filters['date_range'] ) : '',
            'size'         => $filters['size'] ? 'Size: ' . ucfirst( $filters['size'] ) : '',
            'source'       => $filters['source'] ? 'Used by: ' . esc_html( $source_plugins[ $filters['source'] ] ?? ucfirst( $filters['source'] ) ) : '',
        );

        $active = array_filter( $labels );
        if ( empty( $active ) ) {
            return;
        }

        echo '<div class="mut-active-filters">';
        echo '<span class="mut-active-filters-label">Active filters:</span>';
        foreach ( $active as $key => $label ) {
            $remove_url = remove_query_arg( array( $key, 'paged' ) );
            echo '<span class="mut-filter-badge">' . esc_html( $label ) . ' <a href="' . esc_url( $remove_url ) . '" class="mut-filter-badge-remove" title="Remove filter">×</a></span>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    private function query_status_counts( $filters ) {
        global $wpdb;

        $where  = array( "p.post_type = 'attachment'", "p.post_status = 'inherit'" );
        $params = array();

        if ( $filters['s'] !== '' ) {
            $like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
            $where[] = '( p.post_title LIKE %s OR p.guid LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }
        if ( $filters['media_type'] !== '' ) {
            if ( $filters['media_type'] === 'application/pdf' ) {
                $where[]  = 'p.post_mime_type = %s';
                $params[] = 'application/pdf';
            } else {
                $where[]  = 'p.post_mime_type LIKE %s';
                $params[] = $filters['media_type'] . '/%';
            }
        }
        if ( $filters['date_range'] !== '' ) {
            $days_map = array( 'today' => 1, '7days' => 7, '30days' => 30, '90days' => 90, '1year' => 365 );
            if ( isset( $days_map[ $filters['date_range'] ] ) ) {
                $where[]  = 'p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)';
                $params[] = $days_map[ $filters['date_range'] ];
            }
        }

        $source_join = '';
        if ( $filters['source'] !== '' ) {
            $allowed_sources = array_keys( $this->get_source_plugins() );
            if ( in_array( $filters['source'], $allowed_sources, true ) ) {
                $source_join = $wpdb->prepare(
                    "INNER JOIN {$wpdb->prefix}mut_media_usage su ON p.ID = su.attachment_id AND su.usage_type = %s",
                    $filters['source']
                );
            }
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );
        $usage_join = "LEFT JOIN {$wpdb->prefix}mut_media_usage u ON p.ID = u.attachment_id";

        $sql = "SELECT SUM(usage_count > 0) AS used_count, SUM(usage_count = 0) AS unused_count, COUNT(*) AS total_count
                FROM (
                    SELECT p.ID, COUNT(DISTINCT u.post_id) AS usage_count
                    FROM {$wpdb->posts} p
                    {$usage_join}
                    {$source_join}
                    {$where_sql}
                    GROUP BY p.ID
                ) sub";

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row( $sql );
        }

        return array(
            'all'    => (int) ( $row->total_count  ?? 0 ),
            'used'   => (int) ( $row->used_count   ?? 0 ),
            'unused' => (int) ( $row->unused_count ?? 0 ),
        );
    }

    private function query_attachments( $filters ) {
        global $wpdb;

        $per_page = 20;
        $page     = max( 1, absint( $filters['paged'] ) );
        $offset   = ( $page - 1 ) * $per_page;

        // Base WHERE clauses
        $where  = array( "p.post_type = 'attachment'", "p.post_status = 'inherit'" );
        $params = array();

        // Search: filename (guid) or title
        if ( $filters['s'] !== '' ) {
            $like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
            $where[] = '( p.post_title LIKE %s OR p.guid LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        // Media type
        if ( $filters['media_type'] !== '' ) {
            if ( $filters['media_type'] === 'application/pdf' ) {
                $where[]  = 'p.post_mime_type = %s';
                $params[] = 'application/pdf';
            } else {
                $where[]  = 'p.post_mime_type LIKE %s';
                $params[] = $filters['media_type'] . '/%';
            }
        }

        // Date range
        if ( $filters['date_range'] !== '' ) {
            $days_map = array(
                'today'  => 1,
                '7days'  => 7,
                '30days' => 30,
                '90days' => 90,
                '1year'  => 365,
            );
            if ( isset( $days_map[ $filters['date_range'] ] ) ) {
                $where[]  = 'p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)';
                $params[] = $days_map[ $filters['date_range'] ];
            }
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        // Usage / source JOIN
        $usage_join   = "LEFT JOIN {$wpdb->prefix}mut_media_usage u ON p.ID = u.attachment_id";
        $usage_having = '';

        // Source (plugin) filter — restrict join to rows with matching usage_type
        $source_join = '';
        if ( $filters['source'] !== '' ) {
            $allowed_sources = array_keys( $this->get_source_plugins() );
            if ( in_array( $filters['source'], $allowed_sources, true ) ) {
                $source_join  = $wpdb->prepare(
                    "INNER JOIN {$wpdb->prefix}mut_media_usage su ON p.ID = su.attachment_id AND su.usage_type = %s",
                    $filters['source']
                );
            }
        }

        if ( $filters['usage_status'] === 'used' ) {
            $usage_having = 'HAVING usage_count > 0';
        } elseif ( $filters['usage_status'] === 'unused' ) {
            $usage_having = 'HAVING usage_count = 0';
        }

        $size_active = $filters['size'] !== '';

        if ( ! $size_active ) {
            // ---- Fast path: size not involved → paginate in SQL -------------
            $count_sql = "
                SELECT COUNT(*) FROM (
                    SELECT p.ID, COUNT(DISTINCT u.post_id) AS usage_count
                    FROM {$wpdb->posts} p
                    {$usage_join}
                    {$source_join}
                    {$where_sql}
                    GROUP BY p.ID
                    {$usage_having}
                ) AS sub
            ";

            $total = $params
                ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                : (int) $wpdb->get_var( $count_sql );

            $data_sql = "
                SELECT p.ID, p.post_title, p.guid, p.post_date, p.post_mime_type,
                       COUNT(DISTINCT u.post_id) AS usage_count
                FROM {$wpdb->posts} p
                {$usage_join}
                {$source_join}
                {$where_sql}
                GROUP BY p.ID
                {$usage_having}
                ORDER BY p.post_date DESC
                LIMIT %d OFFSET %d
            ";

            $data_params = array_merge( $params, array( $per_page, $offset ) );
            $rows        = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ) );
        } else {
            // ---- Size path: file size lives on disk, not the DB -------------
            // Fetch matching rows (bounded), stat each, filter by size, then
            // paginate the filtered set in PHP. Bounded by SIZE_SCAN_CAP so a
            // huge library can't run away.
            $scan_sql = "
                SELECT p.ID, p.post_title, p.guid, p.post_date, p.post_mime_type,
                       COUNT(DISTINCT u.post_id) AS usage_count
                FROM {$wpdb->posts} p
                {$usage_join}
                {$source_join}
                {$where_sql}
                GROUP BY p.ID
                {$usage_having}
                ORDER BY p.post_date DESC
                LIMIT %d
            ";

            $scan_params = array_merge( $params, array( self::SIZE_SCAN_CAP ) );
            $candidates  = $wpdb->get_results( $wpdb->prepare( $scan_sql, $scan_params ) );

            $matched = array();
            foreach ( $candidates as $row ) {
                $file  = get_attached_file( $row->ID );
                $bytes = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
                if ( ! $this->size_matches( $bytes, $filters ) ) {
                    continue;
                }
                $row->_bytes = $bytes;
                $matched[]   = $row;
            }

            $total = count( $matched );
            $rows  = array_slice( $matched, $offset, $per_page );
        }

        $items = array();
        foreach ( $rows as $row ) {
            $file       = get_attached_file( $row->ID );
            $items[]    = array(
                'id'          => (int) $row->ID,
                'title'       => $row->post_title,
                'filename'    => $file ? basename( $file ) : basename( $row->guid ),
                'mime_label'  => $this->mime_label( $row->post_mime_type ),
                'usage_count' => (int) $row->usage_count,
                'upload_date' => get_the_date( 'M j, Y', $row->ID ),
                'filesize'    => ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—',
            );
        }

        return array( 'total' => $total, 'items' => $items );
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    private function render_pagination( $filters, $current_page, $total_pages ) {
        if ( $total_pages <= 1 ) {
            return;
        }

        $base_url = remove_query_arg( 'paged' );
        echo '<div class="mut-pagination">';

        if ( $current_page > 1 ) {
            echo '<a href="' . esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ) . '" class="button">‹ Prev</a>';
        }

        $start = max( 1, $current_page - 2 );
        $end   = min( $total_pages, $current_page + 2 );

        if ( $start > 1 ) {
            echo '<a href="' . esc_url( add_query_arg( 'paged', 1, $base_url ) ) . '" class="button">1</a>';
            if ( $start > 2 ) echo '<span class="mut-pagination-ellipsis">…</span>';
        }

        for ( $i = $start; $i <= $end; $i++ ) {
            if ( $i === $current_page ) {
                echo '<span class="button button-primary mut-page-current">' . $i . '</span>';
            } else {
                echo '<a href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '" class="button">' . $i . '</a>';
            }
        }

        if ( $end < $total_pages ) {
            if ( $end < $total_pages - 1 ) echo '<span class="mut-pagination-ellipsis">…</span>';
            echo '<a href="' . esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ) . '" class="button">' . $total_pages . '</a>';
        }

        if ( $current_page < $total_pages ) {
            echo '<a href="' . esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ) . '" class="button">Next ›</a>';
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Does a file's byte size satisfy the active size filters?
     * Honors both the categorical large/small filter and an explicit
     * min_size_bytes threshold (both may be present).
     */
    private function size_matches( $bytes, $filters ) {
        if ( $filters['size'] === 'large' && $bytes < self::LARGE_BYTES ) {
            return false;
        }
        if ( $filters['size'] === 'small' && $bytes >= self::SMALL_BYTES ) {
            return false;
        }
        return true;
    }

    private function mime_label( $mime ) {
        $map = array(
            'image/jpeg'      => 'JPEG',
            'image/png'       => 'PNG',
            'image/gif'       => 'GIF',
            'image/webp'      => 'WebP',
            'image/svg+xml'   => 'SVG',
            'application/pdf' => 'PDF',
            'video/mp4'       => 'MP4',
            'video/quicktime' => 'MOV',
            'video/webm'      => 'WebM',
            'audio/mpeg'      => 'MP3',
            'audio/wav'       => 'WAV',
        );
        return $map[ $mime ] ?? strtoupper( substr( strrchr( $mime, '/' ), 1 ) );
    }

    private function media_type_label( $type ) {
        $map = array(
            'image'           => 'Images',
            'application/pdf' => 'PDFs',
            'video'           => 'Videos',
            'audio'           => 'Audio',
        );
        return $map[ $type ] ?? ucfirst( $type );
    }

    private function date_range_label( $range ) {
        $map = array(
            'today'  => 'Upload Date: Today',
            '7days'  => 'Upload Date: Last 7 Days',
            '30days' => 'Upload Date: Last 30 Days',
            '90days' => 'Upload Date: Last 90 Days',
            '1year'  => 'Upload Date: Last Year',
        );
        return $map[ $range ] ?? '';
    }
}
