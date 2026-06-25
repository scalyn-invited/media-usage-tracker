<?php
namespace MediaUsageTracker\Core;

class Activator {

    const DB_VERSION_OPTION = 'mut_db_version';

    public static function activate() {
        // Create database tables
        self::create_database_tables();

        // Record the schema version so upgrades can detect changes.
        update_option( self::DB_VERSION_OPTION, MUT_VERSION );

        // Set default options
        add_option( 'mut_last_scan', null );
        add_option( 'mut_settings', array(
            'scan_posts' => true,
            'scan_pages' => true,
            'scan_cpts'  => true,
        ) );

        // Wire up scheduled scan if previously enabled (e.g. reactivation).
        require_once MUT_PLUGIN_DIR . 'includes/class-scheduler.php';
        Scheduler::schedule();

        flush_rewrite_rules();
    }

    /**
     * Run on every load (cheaply) to keep the schema current without requiring
     * a manual deactivate/reactivate. dbDelta only applies diffs, so this is a
     * no-op once the stored DB version matches MUT_VERSION.
     */
    public static function maybe_upgrade() {
        $installed = get_option( self::DB_VERSION_OPTION );
        if ( $installed === MUT_VERSION ) {
            return; // Schema already current — fast path, no DB work.
        }

        self::create_database_tables();
        update_option( self::DB_VERSION_OPTION, MUT_VERSION );
    }

    public static function create_database_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mut_media_usage (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            post_type VARCHAR(20) NOT NULL,
            usage_type VARCHAR(50) NOT NULL,
            context TEXT,
            scan_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY post_id (post_id),
            KEY scan_id (scan_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mut_scan_history (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            total_attachments INT UNSIGNED DEFAULT 0,
            files_in_use INT UNSIGNED DEFAULT 0,
            unused_files INT UNSIGNED DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            duration_seconds INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mut_review_status (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'flagged',
            flagged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_id (attachment_id)
        ) $charset_collate;";

        $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mut_deletion_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            title TEXT,
            mime_type VARCHAR(100),
            original_path TEXT,
            trash_path TEXT,
            file_size BIGINT(20) UNSIGNED DEFAULT 0,
            deleted_by BIGINT(20) UNSIGNED DEFAULT 0,
            deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
    }
}
