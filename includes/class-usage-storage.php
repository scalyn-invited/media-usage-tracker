<?php
namespace MediaUsageTracker\Storage;

class UsageStorage {

    private $usage_table;
    private $history_table;

    public function __construct() {
        global $wpdb;
        $this->usage_table   = $wpdb->prefix . 'mut_media_usage';
        $this->history_table = $wpdb->prefix . 'mut_scan_history';
    }

    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->usage_table} (
            id bigint(20) unsigned NOT NULL auto_increment,
            attachment_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(20) NOT NULL,
            usage_type varchar(50) NOT NULL,
            context text NOT NULL,
            scan_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY post_id (post_id),
            KEY scan_id (scan_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->history_table} (
            id bigint(20) unsigned NOT NULL auto_increment,
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            total_attachments int NOT NULL DEFAULT 0,
            files_in_use int NOT NULL DEFAULT 0,
            unused_files int NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            duration_seconds int NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );
    }

    /**
     * Record a single usage entry.
     * Skips insert if (attachment_id, post_id, usage_type, scan_id) already exists —
     * prevents the ID-regex and URL-regex scanner passes from double-inserting
     * the same image+post combination.
     */
    public function record_usage( $data ) {
        global $wpdb;

        $defaults = array(
            'attachment_id' => 0,
            'post_id'       => 0,
            'post_type'     => '',
            'usage_type'    => 'content',
            'context'       => '',
            'scan_id'       => 0,
        );

        $data = wp_parse_args( $data, $defaults );

        // Duplicate guard — skip if already recorded for this exact combo this scan
        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->usage_table}
             WHERE attachment_id = %d AND post_id = %d AND usage_type = %s AND scan_id = %d
             LIMIT 1",
            absint( $data['attachment_id'] ),
            absint( $data['post_id'] ),
            sanitize_text_field( $data['usage_type'] ),
            absint( $data['scan_id'] )
        ) );

        if ( $already ) {
            return;
        }

        $wpdb->insert(
            $this->usage_table,
            array(
                'attachment_id' => absint( $data['attachment_id'] ),
                'post_id'       => absint( $data['post_id'] ),
                'post_type'     => sanitize_key( $data['post_type'] ),
                'usage_type'    => sanitize_text_field( $data['usage_type'] ),
                'context'       => wp_kses_post( $data['context'] ),
                'scan_id'       => absint( $data['scan_id'] ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%d' )
        );
    }

    /**
     * Get usage details for a specific attachment
     */
    public function get_usage_for_attachment( $attachment_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->usage_table} WHERE attachment_id = %d ORDER BY created_at DESC",
            absint( $attachment_id )
        ) );
    }

    /**
     * Get total media count
     */
    public function get_total_media_count() {
        return (int) wp_count_posts( 'attachment' )->inherit;
    }

    /**
     * Get all unused attachments (no usage records)
     */
    public function get_unused_attachments() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$this->usage_table} u ON p.ID = u.attachment_id
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND u.attachment_id IS NULL"
        );
    }

    /**
     * Get total storage used by all attachments (in bytes).
     * Iterates over attachment IDs and sums file sizes on disk.
     */
    public function get_storage_usage() {
        global $wpdb;

        $attachment_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'"
        );

        $total_bytes = 0;
        foreach ( $attachment_ids as $id ) {
            $file = get_attached_file( (int) $id );
            if ( $file && file_exists( $file ) ) {
                $total_bytes += (int) filesize( $file );
            }
        }

        return $total_bytes;
    }

    /**
     * Get total storage used by unused attachments (in bytes).
     */
    public function get_unused_storage_usage() {
        $unused_ids  = $this->get_unused_attachments();
        $total_bytes = 0;
        foreach ( $unused_ids as $id ) {
            $file = get_attached_file( (int) $id );
            if ( $file && file_exists( $file ) ) {
                $total_bytes += (int) filesize( $file );
            }
        }
        return $total_bytes;
    }

    /**
     * Get count of files that have at least one usage
     */
    public function get_files_in_use_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$this->usage_table}" );
    }

    /**
     * Get scan history
     */
    public function get_scan_history() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->history_table} ORDER BY started_at DESC" );
    }

    /**
     * Start a new scan record
     */
    public function start_scan() {
        global $wpdb;
        $wpdb->insert( $this->history_table, array(
            'started_at' => current_time( 'mysql' ),
            'status'     => 'running'
        ) );
        return $wpdb->insert_id;
    }

    public function get_scan_started_at( $scan_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT started_at FROM {$this->history_table} WHERE id = %d",
            absint( $scan_id )
        ) );
    }

    /**
     * Complete a scan record
     */
    public function complete_scan( $scan_id, $stats ) {
        global $wpdb;

        $wpdb->update(
            $this->history_table,
            array(
                'completed_at'    => current_time( 'mysql' ),
                'status'          => 'completed',
                'total_attachments' => absint( $stats['total_attachments'] ?? 0 ),
                'files_in_use'    => absint( $stats['files_in_use'] ?? 0 ),
                'unused_files'    => absint( $stats['unused_files'] ?? 0 ),
                'duration_seconds'=> absint( $stats['duration_seconds'] ?? 0 ),
            ),
            array( 'id' => absint( $scan_id ) ),
            array( '%s', '%s', '%d', '%d', '%d', '%d' ),
            array( '%d' )
        );

        // A completed scan invalidates derived analyses (duplicates / storage
        // optimization). Drop their caches so the next page-load recomputes.
        delete_transient( 'mut_duplicate_groups' );
        delete_transient( 'mut_storage_recommendations' );
        delete_transient( 'mut_quality_audit' );
    }

    /**
     * Clear ALL usage data before a new scan.
     * Always TRUNCATEs — the old conditional was broken: passing $scan_id made it
     * DELETE only the brand-new scan's own (empty) rows, leaving all previous
     * scan rows intact and causing counts to stack up on every scan run.
     */
    public function clear_previous_usage() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->usage_table}" );
    }

    /**
     * Get the distinct post statuses of every post that references an attachment.
     * Used by the AI Cleanup Advisor to tell "live on the site" apart from
     * "only linked in drafts". Returns e.g. array( 'publish', 'draft' ).
     */
    public function get_usage_post_statuses( $attachment_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.post_status
             FROM {$this->usage_table} u
             INNER JOIN {$wpdb->posts} p ON p.ID = u.post_id
             WHERE u.attachment_id = %d",
            absint( $attachment_id )
        ) );
    }

    /**
     * Get usage count for a single attachment
     */
    public function get_usage_count( $attachment_id ) {
        global $wpdb;
        // DISTINCT post_id = how many posts use this image, not how many rows exist
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$this->usage_table} WHERE attachment_id = %d",
            absint( $attachment_id )
        ) );
    }

    /**
     * Get all usages for an attachment
     */
    public function get_usages_for_attachment( $attachment_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->usage_table} WHERE attachment_id = %d ORDER BY created_at DESC",
            absint( $attachment_id )
        ) );
    }

    // =========================================================================
    // Review Status — mut_review_status table
    // =========================================================================

    /**
     * Set or update review status for an attachment.
     * Status: 'flagged' | 'archived'
     */
    public function set_review_status( $attachment_id, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_review_status';
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d LIMIT 1",
            absint( $attachment_id )
        ) );
        if ( $existing ) {
            $wpdb->update(
                $table,
                array( 'status' => sanitize_key( $status ), 'flagged_at' => current_time( 'mysql' ) ),
                array( 'attachment_id' => absint( $attachment_id ) ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'attachment_id' => absint( $attachment_id ),
                    'status'        => sanitize_key( $status ),
                    'flagged_at'    => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Get the current review status for a single attachment, or '' if none.
     */
    public function get_review_status( $attachment_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_review_status';
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE attachment_id = %d LIMIT 1",
            absint( $attachment_id )
        ) );
    }

    /**
     * Remove review status for an attachment.
     */
    public function clear_review_status( $attachment_id ) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'mut_review_status',
            array( 'attachment_id' => absint( $attachment_id ) ),
            array( '%d' )
        );
    }

    /**
     * Get review items, optionally filtered by status.
     */
    public function get_review_items( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_review_status';
        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY flagged_at DESC",
                sanitize_key( $status )
            ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY flagged_at DESC" );
    }

    // =========================================================================
    // Deletion Log — mut_deletion_log table (Safe Delete Workflow)
    // =========================================================================

    /**
     * Record a safe-deletion. Returns the new log row id.
     */
    public function log_deletion( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_deletion_log';

        $defaults = array(
            'attachment_id' => 0,
            'file_name'     => '',
            'title'         => '',
            'mime_type'     => '',
            'original_path' => '',
            'trash_path'    => '',
            'file_size'     => 0,
            'deleted_by'    => 0,
        );
        $data = wp_parse_args( $data, $defaults );

        $wpdb->insert(
            $table,
            array(
                'attachment_id' => absint( $data['attachment_id'] ),
                'file_name'     => sanitize_text_field( $data['file_name'] ),
                'title'         => sanitize_text_field( $data['title'] ),
                'mime_type'     => sanitize_text_field( $data['mime_type'] ),
                'original_path' => $data['original_path'],
                'trash_path'    => $data['trash_path'],
                'file_size'     => absint( $data['file_size'] ),
                'deleted_by'    => absint( $data['deleted_by'] ),
                'deleted_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get all deletion-log records, newest first.
     */
    public function get_deletion_log() {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_deletion_log';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY deleted_at DESC" );
    }

    /**
     * Get a single deletion-log record by id.
     */
    public function get_deletion_record( $log_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'mut_deletion_log';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            absint( $log_id )
        ) );
    }

    /**
     * Remove a deletion-log record (after a restore or purge).
     */
    public function remove_deletion_record( $log_id ) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'mut_deletion_log',
            array( 'id' => absint( $log_id ) ),
            array( '%d' )
        );
    }

    /**
     * Get counts of each review status.
     */
    public function get_review_counts() {
        global $wpdb;
        $table   = $wpdb->prefix . 'mut_review_status';
        $rows    = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status" );
        $counts  = array( 'flagged' => 0, 'archived' => 0 );
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }
        return $counts;
    }

}