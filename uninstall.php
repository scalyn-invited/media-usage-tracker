<?php
// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Optional: clean up tables on uninstall (commented by default for safety)
// global $wpdb;
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mut_media_usage" );
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mut_scan_history" );
