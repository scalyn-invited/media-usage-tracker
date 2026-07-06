<?php
namespace MediaUsageTracker\Core;

class Deactivator {

    public static function deactivate() {
        require_once MUT_PLUGIN_DIR . 'includes/class-scheduler.php';
        Scheduler::unschedule();

        require_once MUT_PLUGIN_DIR . 'includes/class-realtime-scanner.php';
        RealtimeScanner::unschedule();

        flush_rewrite_rules();
    }
}
