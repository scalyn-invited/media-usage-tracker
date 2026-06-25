<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

class MediaScanner {

    private $storage;
    private $batch_size = 20; // Process in batches to avoid timeouts

    /** @var MediaDetector[] Ordered registry of detectors run over each post. */
    private $detectors = array();

    public function __construct( UsageStorage $storage ) {
        $this->storage = $storage;
        $this->load_detectors();
    }

    /**
     * Build the detector registry. Built-in detectors (content, featured image)
     * are always present and run in this exact order to preserve legacy scan
     * behavior. Phase 2 builder integrations (Elementor, Divi, Beaver Builder,
     * ACF) can register via the 'mut_media_detectors' filter.
     */
    private function load_detectors() {
        require_once MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/trait-css-url-scanner.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-content-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-featured-image-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-divi-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-acf-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-elementor-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-wpbakery-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-beaver-builder-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-yoast-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-jetengine-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-jetpopup-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-gravity-forms-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-wpdatatables-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-woocommerce-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-avada-detector.php';
        require_once MUT_PLUGIN_DIR . 'includes/detectors/class-astra-detector.php';

        $detectors = array(
            new ContentDetector( $this->storage ),
            new FeaturedImageDetector( $this->storage ),
            new DiviDetector( $this->storage ),
            new AcfDetector( $this->storage ),
            new ElementorDetector( $this->storage ),
            new WpBakeryDetector( $this->storage ),
            new BeaverBuilderDetector( $this->storage ),
            new YoastDetector( $this->storage ),
            new JetEngineDetector( $this->storage ),
            new JetPopupDetector( $this->storage ),
            new GravityFormsDetector( $this->storage ),
            new WpDataTablesDetector( $this->storage ),
            new WooCommerceDetector( $this->storage ),
            new AvadaDetector( $this->storage ),
            new AstraDetector( $this->storage ),
        );

        if ( function_exists( 'apply_filters' ) ) {
            $detectors = apply_filters( 'mut_media_detectors', $detectors, $this->storage );
        }

        // Keep only valid detector instances.
        $this->detectors = array_values( array_filter( $detectors, function ( $d ) {
            return $d instanceof MediaDetector;
        } ) );
    }

    /**
     * Expose the active detector registry (for diagnostics / tests).
     *
     * @return MediaDetector[]
     */
    public function get_detectors() {
        return $this->detectors;
    }

    /**
     * Start a full scan via AJAX batching
     */
    public function start_scan() {
        // Wipe ALL previous usage rows first so each scan starts clean
        $this->storage->clear_previous_usage();
        $scan_id = $this->storage->start_scan();
        return array(
            'scan_id'    => $scan_id,
            'batch_size' => $this->batch_size,
            'message'    => 'Scan started. Processing in batches...',
        );
    }

    /**
     * Process a batch of posts
     */
    public function process_batch( $scan_id, $offset = 0 ) {
        $start_time = microtime( true );

        // Get posts, pages, and public CPTs
        $post_types = $this->get_scan_post_types();

        $posts = get_posts( array(
            'post_type'      => $post_types,
            'posts_per_page' => $this->batch_size,
            'offset'         => absint( $offset ),
            'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
        ) );

        $processed = 0;
        $usages_found = 0;

        foreach ( $posts as $post ) {
            foreach ( $this->detectors as $detector ) {
                if ( $detector->is_available() ) {
                    $detector->detect( $post, $scan_id );
                }
            }
            $processed++;

            // Count usages for this post (approximate)
            if ( get_post_thumbnail_id( $post->ID ) ) {
                $usages_found++;
            }
        }

        // Log for debugging
        error_log( sprintf( 'Media Usage Tracker: Processed %d posts, found ~%d usages', $processed, $usages_found ) );

        $duration = round( microtime( true ) - $start_time, 2 );

        // Check if more batches needed
        $has_more = ( count( $posts ) === $this->batch_size );

        // Complete scan if no more batches
        if ( ! $has_more ) {
            $this->complete_scan( $scan_id );
            error_log('Media Usage Tracker: Scan completed for scan_id ' . $scan_id);
        }

        return array(
            'processed'    => $processed,
            'usages_found' => $usages_found,
            'offset'       => $offset + $this->batch_size,
            'has_more'     => $has_more,
            'duration'     => $duration,
            'total_posts'  => count( $posts ),
        );
    }

    /**
     * Get post types to scan
     */
    private function get_scan_post_types() {
        $post_types = array( 'post', 'page' );
        $cpts = get_post_types( array( 'public' => true, '_builtin' => false ), 'names' );
        $all  = array_merge( $post_types, $cpts );
        // Include WooCommerce variations (non-public post type).
        if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
            $all[] = 'product_variation';
        }
        return array_unique( $all );
    }

    /**
     * Get total media count
     */
    public function get_total_media_count() {
        return (int) wp_count_posts( 'attachment' )->inherit;
    }

    /**
     * Finalize scan
     */
    private function complete_scan( $scan_id ) {
        $this->scan_sitewide_settings( $scan_id );
        $this->scan_global_detectors( $scan_id );

        $total_media  = $this->get_total_media_count();
        $files_in_use = $this->storage->get_files_in_use_count();
        $unused       = $total_media - $files_in_use;

        // Calculate duration from the stored started_at timestamp.
        $started_at = $this->storage->get_scan_started_at( $scan_id );
        $duration   = $started_at ? max( 1, time() - strtotime( $started_at ) ) : 0;

        $this->storage->complete_scan( $scan_id, array(
            'total_attachments' => $total_media,
            'files_in_use'      => $files_in_use,
            'unused_files'      => $unused,
            'duration_seconds'  => $duration,
        ));
    }

    /**
     * Run detectors that operate globally (not per-post) — e.g. Gravity Forms.
     */
    private function scan_global_detectors( $scan_id ) {
        foreach ( $this->detectors as $detector ) {
            if ( method_exists( $detector, 'scan_all' ) && $detector->is_available() ) {
                $detector->scan_all( $scan_id );
            }
        }
    }

    /**
     * Scan sitewide settings (run once per scan, not per post).
     * Records usage against post ID 0 with post_type 'sitewide'.
     */
    private function scan_sitewide_settings( $scan_id ) {
        $found = array();

        // WordPress site logo (Customizer → Site Identity)
        $site_logo_id = absint( get_theme_mod( 'custom_logo' ) );
        if ( $site_logo_id > 0 ) {
            $found[ $site_logo_id ] = 'Site Logo';
        }

        // WordPress site icon (favicon)
        $site_icon_id = absint( get_option( 'site_icon' ) );
        if ( $site_icon_id > 0 ) {
            $found[ $site_icon_id ] = 'Site Icon';
        }

        // Yoast SEO sitewide social images (wpseo_social option)
        if ( defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' ) ) {
            $wpseo_social = get_option( 'wpseo_social', array() );

            $social_fields = array(
                'og_default_image_id' => 'Yoast: Default OG Image',
                'twitter_site_image_id' => 'Yoast: Default Twitter Image',
            );
            foreach ( $social_fields as $key => $label ) {
                $id = absint( $wpseo_social[ $key ] ?? 0 );
                if ( $id > 0 ) {
                    $found[ $id ] = $label;
                }
            }

            // Yoast also stores the URL alongside the ID — resolve URL as fallback
            $url_fields = array(
                'og_default_image'     => 'Yoast: Default OG Image',
                'twitter_site_image'   => 'Yoast: Default Twitter Image',
            );
            foreach ( $url_fields as $key => $label ) {
                $url = $wpseo_social[ $key ] ?? '';
                if ( $url && strpos( $url, 'http' ) === 0 ) {
                    $id = absint( attachment_url_to_postid( $url ) );
                    if ( $id > 0 && ! isset( $found[ $id ] ) ) {
                        $found[ $id ] = $label;
                    }
                }
            }
        }

        foreach ( $found as $attachment_id => $context ) {
            $this->storage->record_usage( array(
                'attachment_id' => $attachment_id,
                'post_id'       => 0,
                'post_type'     => 'sitewide',
                'usage_type'    => 'sitewide',
                'context'       => $context,
                'scan_id'       => $scan_id,
            ) );
        }
    }
}
