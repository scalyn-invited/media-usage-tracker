<?php
namespace MediaUsageTracker\Admin;

class MediaLibrary {
    private $storage;

    public function __construct( $storage ) {
        $this->storage = $storage;
        add_filter( 'manage_media_columns', array( $this, 'add_usage_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_usage_column' ), 10, 2 );
        add_action( 'restrict_manage_posts', array( $this, 'add_status_filter' ) );
        add_action( 'pre_get_posts', array( $this, 'apply_status_filter' ) );
    }

    public function add_usage_column( $columns ) {
        $columns['mut_usage'] = __( 'Status', 'media-usage-tracker' );
        return $columns;
    }

    public function render_usage_column( $column, $attachment_id ) {
        if ( $column !== 'mut_usage' ) {
            return;
        }

        $count = $this->storage->get_usage_count( $attachment_id );
        $filter = sanitize_key( $_GET['mut_status'] ?? '' );

        $badge_base   = 'display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;border:none;';
        $badge_used   = $badge_base . 'background:#d1f0d1;color:#1a5a1a;';
        $badge_unused = $badge_base . 'background:#e8e8e8;color:#666;';

        if ( $count > 0 ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $attachment_id ) ) . '" style="text-decoration:none;" title="View usage locations">'
               . '<span style="' . $badge_used . '">In Use</span>'
               . '</a>';
            if ( $count > 1 ) {
                echo '<br><span style="color:#777;font-size:11px;">' . esc_html( $count ) . ' locations</span>';
            }
        } else {
            echo '<span style="' . $badge_unused . '">Unused</span>';
        }

        // Show Replace link when "Over 1MB" filter is active
        if ( $filter === 'over1mb' ) {
            $file = get_attached_file( $attachment_id );
            $size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
            if ( $size > 1048576 ) {
                $size_label = size_format( $size );
                $edit_url   = admin_url( 'post.php?post=' . $attachment_id . '&action=edit' );
                echo '<br><span style="color:#d63638;font-size:11px;font-weight:600;">' . esc_html( $size_label ) . '</span>';
                echo ' <a href="' . esc_url( $edit_url ) . '" style="font-size:11px;color:#2271b1;text-decoration:none;font-weight:600;" title="Replace this image with a smaller version">🔄 Replace</a>';
            }
        }
    }

    /**
     * Add custom filter dropdown to Media Library list view.
     */
    public function add_status_filter( $post_type ) {
        if ( $post_type !== 'attachment' ) {
            return;
        }

        $current = sanitize_key( $_GET['mut_status'] ?? '' );
        ?>
        <select name="mut_status" style="float:none;margin-left:4px;">
            <option value="">All Status</option>
            <option value="inuse" <?php selected( $current, 'inuse' ); ?>>In Use</option>
            <option value="unused" <?php selected( $current, 'unused' ); ?>>Unused</option>
            <option value="over1mb" <?php selected( $current, 'over1mb' ); ?>>Over 1MB</option>
        </select>
        <?php
    }

    /**
     * Filter the Media Library query based on the selected status.
     */
    public function apply_status_filter( $query ) {
        global $pagenow;

        if ( $pagenow !== 'upload.php' || ! $query->is_main_query() ) {
            return;
        }

        $filter = sanitize_key( $_GET['mut_status'] ?? '' );
        if ( $filter === '' ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mut_media_usage';

        if ( $filter === 'inuse' ) {
            $used_ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" );
            if ( ! empty( $used_ids ) ) {
                $query->set( 'post__in', array_map( 'intval', $used_ids ) );
            } else {
                $query->set( 'post__in', array( 0 ) );
            }
        } elseif ( $filter === 'unused' ) {
            $used_ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" );
            if ( ! empty( $used_ids ) ) {
                $query->set( 'post__not_in', array_map( 'intval', $used_ids ) );
            }
        } elseif ( $filter === 'over1mb' ) {
            // Get attachment IDs where file size > 1MB
            $all_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
            );
            $big_ids = array();
            foreach ( $all_ids as $aid ) {
                $file = get_attached_file( $aid );
                if ( $file && file_exists( $file ) && filesize( $file ) > 1048576 ) {
                    $big_ids[] = (int) $aid;
                }
            }
            if ( ! empty( $big_ids ) ) {
                $query->set( 'post__in', $big_ids );
            } else {
                $query->set( 'post__in', array( 0 ) );
            }
        }
    }
}
