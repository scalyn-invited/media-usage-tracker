<?php
namespace MediaUsageTracker\Core;

use MediaUsageTracker\Admin\CleanupSuggestions;
use MediaUsageTracker\Admin\Dashboard;
use MediaUsageTracker\Admin\MediaLibrary;
use MediaUsageTracker\Admin\Reports;
use MediaUsageTracker\Admin\Settings;
use MediaUsageTracker\Admin\MediaSearch;
use MediaUsageTracker\Admin\BulkReview;
use MediaUsageTracker\Admin\DuplicateAnalysis;
use MediaUsageTracker\Admin\StorageOptimization;
use MediaUsageTracker\Admin\QualityAudit;
use MediaUsageTracker\Admin\QualityDetail;
use MediaUsageTracker\Admin\AltTextGenerator;
use MediaUsageTracker\Admin\PdfExporter;
use MediaUsageTracker\Admin\TrashBin;
use MediaUsageTracker\Core\Scheduler;
use MediaUsageTracker\Core\SafeDelete;
use MediaUsageTracker\Core\Notifier;
use MediaUsageTracker\Core\RealtimeScanner;

class Plugin {

    private $scanner;
    private $storage;

    public function __construct() {
        $this->load_dependencies();
    }

    private function load_dependencies() {
        require_once MUT_PLUGIN_DIR . 'includes/class-usage-storage.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-scanner.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-dashboard.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-reports.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-media-library.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-settings.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-cleanup-suggestions.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-media-search.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-bulk-review.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-duplicate-analysis.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-storage-optimization.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-quality-audit.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-quality-detail.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-scheduler.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-realtime-scanner.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-safe-delete.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-trash-bin.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-media-by-page.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-notifier.php';
        require_once MUT_PLUGIN_DIR . 'includes/class-attachment-ai.php';

        $this->storage = new \MediaUsageTracker\Storage\UsageStorage();
        $this->scanner = new \MediaUsageTracker\Scanner\MediaScanner( $this->storage );
    }

    public function run() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {
        // Register the monthly cron interval and the scheduled scan hook.
        add_filter( 'cron_schedules', array( 'MediaUsageTracker\Core\Scheduler', 'add_cron_intervals' ) );
        Scheduler::register();

        // Keep media usage current automatically as content changes, instead
        // of waiting for the next manual/scheduled scan.
        RealtimeScanner::register();

        // Email summary after a scheduled scan completes (runs during cron too).
        Notifier::register( $this->storage );

        // Admin hooks
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_upload_warn' ) );
            add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment_oversize_flag' ), 10, 2 );
            add_action( 'add_attachment', array( $this, 'flag_oversized_upload' ) );
            add_action( 'wp_ajax_mut_get_upload_warning', array( $this, 'ajax_get_upload_warning' ) );

            // AJAX handlers for scanning
            add_action( 'wp_ajax_mut_start_scan', array( $this, 'ajax_start_scan' ) );
            add_action( 'wp_ajax_mut_process_batch', array( $this, 'ajax_process_batch' ) );

            // CSV export for cleanup suggestions (fires before headers are sent)
            $cleanup = new CleanupSuggestions( $this->storage );
            add_action( 'admin_init', array( $cleanup, 'handle_export' ) );

            // Bulk review AJAX + export
            $bulk_review = new BulkReview( $this->storage );
            add_action( 'wp_ajax_mut_bulk_action', array( $bulk_review, 'handle_ajax_bulk_action' ) );
            add_action( 'admin_init', array( $bulk_review, 'handle_export' ) );

            // Duplicate analysis AJAX (refresh cache)
            $duplicate = new DuplicateAnalysis( $this->storage );
            add_action( 'wp_ajax_mut_refresh_duplicates', array( $duplicate, 'handle_refresh' ) );

            // Storage optimization AJAX (recalculate)
            $optimize = new StorageOptimization( $this->storage );
            add_action( 'wp_ajax_mut_refresh_optimization', array( $optimize, 'handle_refresh' ) );

            // Quality Audit AJAX (re-run)
            $quality = new QualityAudit( $this->storage );
            add_action( 'wp_ajax_mut_refresh_quality', array( $quality, 'handle_refresh' ) );

            // AI Alt Text Generator AJAX (generate + save)
            require_once MUT_PLUGIN_DIR . 'includes/class-alt-text-generator.php';
            $alt_gen = new AltTextGenerator();
            add_action( 'wp_ajax_mut_generate_alt_text',   array( $alt_gen, 'handle_generate' ) );
            add_action( 'wp_ajax_mut_save_alt_text',       array( $alt_gen, 'handle_save' ) );
            add_action( 'wp_ajax_mut_mark_decorative',     array( $alt_gen, 'handle_mark_decorative' ) );
            add_action( 'wp_ajax_mut_generate_caption',    array( $alt_gen, 'handle_generate_caption' ) );
            add_action( 'wp_ajax_mut_save_caption',        array( $alt_gen, 'handle_save_caption' ) );
            add_action( 'wp_ajax_mut_nl_search',           array( $alt_gen, 'handle_nl_search' ) );

            // Media by Page AJAX
            $mbp = new \MediaUsageTracker\Admin\MediaByPage( $this->storage );
            add_action( 'wp_ajax_mut_media_by_page', array( $mbp, 'ajax_handler' ) );

            // Safe Delete Workflow AJAX (verify / delete / restore)
            $safe_delete = new SafeDelete( $this->storage );
            add_action( 'wp_ajax_mut_verify_delete',  array( $safe_delete, 'handle_verify' ) );
            add_action( 'wp_ajax_mut_safe_delete',    array( $safe_delete, 'handle_delete' ) );
            add_action( 'wp_ajax_mut_restore_delete',     array( $safe_delete, 'handle_restore' ) );
            add_action( 'wp_ajax_mut_permanently_delete', array( $safe_delete, 'handle_permanently_delete' ) );

            add_action( 'wp_ajax_mut_get_dashboard_stats', array( $this, 'ajax_get_dashboard_stats' ) );

            // Reports export (must fire before headers are sent)
            $reports = new Reports( $this->storage );
            add_action( 'admin_init', array( $reports, 'handle_csv_export' ) );
            add_action( 'admin_init', array( $reports, 'handle_excel_export' ) );

            // PDF export (must fire before headers are sent)
            require_once MUT_PLUGIN_DIR . 'includes/class-pdf-exporter.php';
            $pdf = new PdfExporter( $this->storage );
            add_action( 'admin_init', array( $pdf, 'handle_export' ) );

            // Back-link on media edit screen when referred from Quality detail page.
            add_action( 'edit_form_top', array( $this, 'maybe_render_quality_back_link' ) );

            // Initialize admin classes
            new Dashboard( $this->storage );
            new MediaLibrary( $this->storage );
            $settings = new Settings();
            add_action( 'admin_init', array( $settings, 'register_settings' ) );

            $attachment_ai = new \MediaUsageTracker\Admin\AttachmentAI();
            $attachment_ai->register();
        }
    }

    /**
     * Register activation and deactivation hooks (must be called early)
     */
    public static function register_hooks() {
        // Register hooks from main plugin file
        register_activation_hook( MUT_PLUGIN_FILE, array( 'MediaUsageTracker\Core\Activator', 'activate' ) );
        register_deactivation_hook( MUT_PLUGIN_FILE, array( 'MediaUsageTracker\Core\Deactivator', 'deactivate' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Media Usage Tracker',
            'Media Usage',
            'manage_options',
            'media-usage-tracker',
            array( $this, 'dashboard_page' ),
            'dashicons-media-archive',
            80
        );

        add_submenu_page(
            'media-usage-tracker',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'media-usage-tracker',
            array( $this, 'dashboard_page' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Media Usage',
            'Media Usage',
            'manage_options',
            'mut-usage-details',
            array( new \MediaUsageTracker\Admin\UsageDetails( $this->storage ?? null ), 'render' )
        );

        $media_by_page = new \MediaUsageTracker\Admin\MediaByPage( $this->storage );
        add_submenu_page(
            'media-usage-tracker',
            'Media by Page',
            'Media by Page',
            'manage_options',
            'mut-media-by-page',
            array( $media_by_page, 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Search & Filter',
            '— Search & Filter',
            'manage_options',
            'mut-search',
            array( new MediaSearch( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Bulk Review',
            '— Bulk Review',
            'manage_options',
            'mut-bulk-review',
            array( new BulkReview( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Unused Files',
            'Unused Files',
            'manage_options',
            'mut-cleanup',
            array( new CleanupSuggestions( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Duplicate Analysis',
            'Duplicate Analysis',
            'manage_options',
            'mut-duplicates',
            array( new DuplicateAnalysis( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Storage Optimization',
            'Storage Optimization',
            'manage_options',
            'mut-optimize',
            array( new StorageOptimization( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Quality Audit',
            'Quality Audit',
            'manage_options',
            'mut-quality',
            array( new QualityAudit( $this->storage ), 'render' )
        );

        // Hidden detail page — not shown in nav, accessible via URL.
        add_submenu_page(
            null,
            'Quality Check Detail',
            '',
            'manage_options',
            'mut-quality-detail',
            array( new QualityDetail( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Trash',
            'Trash',
            'manage_options',
            'mut-trash',
            array( new TrashBin( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Reports',
            'Reports',
            'manage_options',
            'mut-reports',
            array( new Reports( $this->storage ), 'render' )
        );

        add_submenu_page(
            'media-usage-tracker',
            'Settings',
            'Settings',
            'manage_options',
            'mut-settings',
            array( new Settings(), 'render' )
        );

        require_once MUT_PLUGIN_DIR . 'includes/class-faq.php';
        add_submenu_page(
            'media-usage-tracker',
            'FAQ',
            '❓ FAQ',
            'manage_options',
            'mut-faq',
            array( new \MediaUsageTracker\Admin\FAQ(), 'render' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'media-usage-tracker' ) === false && strpos( $hook, 'mut-' ) === false ) {
            return;
        }
         wp_enqueue_style(
        'mut-tailwind',
        MUT_PLUGIN_URL . 'assets/css/tailwind.css',
        array(),
        file_exists( MUT_PLUGIN_DIR . 'assets/css/tailwind.css' ) ? filemtime( MUT_PLUGIN_DIR . 'assets/css/tailwind.css' ) : MUT_VERSION
    );
        wp_enqueue_style( 'mut-admin', MUT_PLUGIN_URL . 'assets/css/mut-admin.css', array(), file_exists( MUT_PLUGIN_DIR . 'assets/css/mut-admin.css' ) ? filemtime( MUT_PLUGIN_DIR . 'assets/css/mut-admin.css' ) : MUT_VERSION );
        wp_enqueue_script( 'mut-admin', MUT_PLUGIN_URL . 'assets/js/mut-admin.js', array( 'jquery' ), file_exists( MUT_PLUGIN_DIR . 'assets/js/mut-admin.js' ) ? filemtime( MUT_PLUGIN_DIR . 'assets/js/mut-admin.js' ) : MUT_VERSION, true );

        wp_localize_script( 'mut-admin', 'mutAjax', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'mut_scan_nonce' ),
            'bulk_nonce' => wp_create_nonce( 'mut_bulk_review_nonce' ),
            'i18n'       => array(
                'scanning' => __( 'Scanning...', 'media-usage-tracker' ),
                'complete' => __( 'Scan Complete!', 'media-usage-tracker' ),
            ),
        ) );

        // Pass thumbnail URLs + titles for the AI alt text review panel.
        if ( strpos( $hook, 'mut-quality' ) !== false ) {
            require_once MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';
            $auditor = new \MediaUsageTracker\Admin\QualityAuditor( $this->storage );
            $report  = $auditor->get_report();
            $ids     = $report['checks']['alt_text']['items'] ?? array();
            $thumbs  = array();
            $titles  = array();
            foreach ( $ids as $id ) {
                $src           = wp_get_attachment_image_src( $id, array( 60, 60 ) );
                $thumbs[ $id ] = $src ? $src[0] : '';
                $titles[ $id ] = get_the_title( $id );
            }
            wp_localize_script( 'mut-admin', 'mutAltText', array(
                'thumbs' => $thumbs,
                'titles' => $titles,
            ) );
        }
    }

    /**
     * Enqueue the upload size warning script on media library + post editor pages.
     */
    public function enqueue_upload_warn( $hook ) {
        $allowed = array( 'upload.php', 'media-new.php', 'post.php', 'post-new.php' );
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_enqueue_script(
            'mut-upload-warn',
            MUT_PLUGIN_URL . 'assets/js/mut-upload-warn.js',
            array( 'jquery', 'wp-plupload' ),
            MUT_VERSION,
            true
        );
        wp_localize_script( 'mut-upload-warn', 'mutUploadWarn', array(
            'threshold' => 1048576,
            'editBase'  => admin_url( 'post.php' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'mut_scan_nonce' ),
        ) );
    }

    /**
     * Store a transient when an oversized image is uploaded (fires server-side during XHR).
     */
    public function flag_oversized_upload( $attachment_id ) {
        $mime = get_post_mime_type( $attachment_id );
        if ( strpos( (string) $mime, 'image/' ) !== 0 ) { return; }
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) { return; }
        $bytes = filesize( $file );
        if ( $bytes <= 1048576 ) { return; }
        set_transient( 'mut_upload_warn_' . get_current_user_id(), array(
            'id'    => $attachment_id,
            'bytes' => $bytes,
            'name'  => basename( $file ),
        ), 120 );
    }

    /**
     * AJAX: return pending upload warning for the current user, then clear it.
     */
    public function ajax_get_upload_warning() {
        check_ajax_referer( 'mut_scan_nonce', 'nonce' );
        $key  = 'mut_upload_warn_' . get_current_user_id();
        $warn = get_transient( $key );
        if ( $warn ) {
            delete_transient( $key );
            wp_send_json_success( $warn );
        } else {
            wp_send_json_success( null );
        }
    }

    /**
     * Add oversized flag to attachment JS data so the client can warn immediately.
     */
    public function prepare_attachment_oversize_flag( $response, $attachment ) {
        $file = get_attached_file( $attachment->ID );
        if ( $file && file_exists( $file ) ) {
            $bytes = filesize( $file );
            if ( $bytes > 1048576 ) {
                $response['mutOversized']  = true;
                $response['mutFilesizeMb'] = round( $bytes / 1048576, 1 );
            }
        }
        return $response;
    }

    /**
     * AJAX: Return fresh dashboard stats for in-place update after scan.
     */
    public function ajax_get_dashboard_stats() {
        check_ajax_referer( 'mut_scan_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $total_media   = $this->storage ? $this->storage->get_total_media_count() : 0;
        $files_in_use  = $this->storage ? $this->storage->get_files_in_use_count() : 0;
        $unused_files  = 0;
        $storage_bytes = 0;
        $dup_groups    = 0;
        $recoverable   = 0;

        if ( $this->storage ) {
            $unused_ids    = $this->storage->get_unused_attachments();
            $unused_files  = count( $unused_ids );
            $storage_bytes = $this->storage->get_storage_usage();

            require_once MUT_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
            $detector   = new \MediaUsageTracker\Admin\DuplicateDetector( $this->storage );
            $dup_groups = count( $detector->get_groups() );

            require_once MUT_PLUGIN_DIR . 'includes/class-storage-optimizer.php';
            $optimizer   = new \MediaUsageTracker\Admin\StorageOptimizer( $this->storage );
            $report      = $optimizer->get_report();
            $recoverable = $report['recoverable_bytes'];
        }

        // Format bytes helper
        $fmt = function( $bytes ) {
            if ( $bytes >= 1073741824 ) return number_format( $bytes / 1073741824, 2 ) . ' GB';
            if ( $bytes >= 1048576 )   return number_format( $bytes / 1048576, 2 )    . ' MB';
            if ( $bytes >= 1024 )      return number_format( $bytes / 1024, 1 )       . ' KB';
            return $bytes . ' B';
        };

        // Last scan info
        global $wpdb;
        $last_scan  = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}mut_scan_history ORDER BY started_at DESC LIMIT 1" );
        $scan_data  = null;
        if ( $last_scan ) {
            $dt        = new \DateTime( $last_scan->started_at, wp_timezone() );
            $scan_data = array(
                'ago'               => human_time_diff( strtotime( $last_scan->started_at ), current_time( 'timestamp' ) ) . ' ago',
                'exact'             => $dt->format( 'M j, Y g:i A' ),
                'status'            => $last_scan->status,
                'status_label'      => ucfirst( $last_scan->status ),
                'total_attachments' => number_format( $last_scan->total_attachments ),
                'files_in_use'      => number_format( $last_scan->files_in_use ),
                'unused_files'      => number_format( $last_scan->unused_files ),
            );
        }

        wp_send_json_success( array(
            'total_media'  => number_format( $total_media ),
            'files_in_use' => number_format( $files_in_use ),
            'unused_files' => number_format( $unused_files ),
            'storage'      => $fmt( $storage_bytes ),
            'dup_groups'   => number_format( $dup_groups ),
            'recoverable'  => $fmt( $recoverable ),
            'last_scan'    => $scan_data,
        ) );
    }

    /**
     * AJAX: Start new scan
     */
    public function ajax_start_scan() {
        check_ajax_referer( 'mut_scan_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $result = $this->scanner->start_scan();
        wp_send_json_success( $result );
    }

    /**
     * AJAX: Process scan batch
     */
    public function ajax_process_batch() {
        check_ajax_referer( 'mut_scan_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
        $offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

        if ( ! $scan_id ) {
            wp_send_json_error( 'Invalid scan ID.' );
        }

        $result = $this->scanner->process_batch( $scan_id, $offset );
        wp_send_json_success( $result );
    }

    /**
     * Show a back link on the media edit screen when referred from a Quality detail page.
     */
    public function maybe_render_quality_back_link( $post ) {
        if ( $post->post_type !== 'attachment' ) {
            return;
        }
        $ref = sanitize_key( $_GET['mut_ref'] ?? '' );
        if ( ! $ref ) {
            return;
        }

        $check_labels = array(
            'alt_text'          => 'Missing Alt Text',
            'oversized'         => 'Oversized Images',
            'unsupported_format'=> 'Unsupported Formats',
            'webp'              => 'WebP Recommendations',
            'caption'           => 'Missing Caption',
            'description'       => 'Missing Description',
        );

        $label    = $check_labels[ $ref ] ?? ucwords( str_replace( '_', ' ', $ref ) );
        $back_url = admin_url( 'admin.php?page=mut-quality-detail&check=' . urlencode( $ref ) );

        echo '<div style="margin:12px 0 4px;">';
        echo '<a href="' . esc_url( $back_url ) . '" class="button" style="display:inline-flex;align-items:center;gap:6px;">';
        echo '← Back to <strong style="margin-left:3px;">' . esc_html( $label ) . '</strong>';
        echo '</a>';
        echo '</div>';
    }

    /**
     * Dashboard page callback (prevents duplicate rendering)
     */
    public function dashboard_page() {
        $dashboard = new Dashboard( $this->storage );
        $dashboard->render();
    }
}
