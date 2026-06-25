<?php
/**
 * JetEngine debug snippet — paste into Code Snippets or functions.php temporarily.
 * Hooks into admin_init so it runs once when you visit the WP admin.
 * Output goes to wp-content/debug.log (WP_DEBUG_LOG must be true).
 *
 * REMOVE after debugging.
 */
add_action( 'admin_init', function () {
    // Only run once for admin users.
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( get_transient( 'mut_je_debug_done' ) ) return;
    set_transient( 'mut_je_debug_done', 1, 60 );

    // 1. What does the jet-engine CPT look like?
    $je_posts = get_posts( array(
        'post_type'      => 'jet-engine',
        'posts_per_page' => 5,
        'post_status'    => 'any',
    ) );
    error_log( '=== MUT JetEngine Debug ===' );
    error_log( 'jet-engine CPT posts found: ' . count( $je_posts ) );
    foreach ( $je_posts as $p ) {
        error_log( '  Post ID ' . $p->ID . ' | title: ' . $p->post_title . ' | status: ' . $p->post_status );
        $fields_raw = get_post_meta( $p->ID, '_fields', true );
        error_log( '  _fields meta: ' . substr( print_r( $fields_raw, true ), 0, 500 ) );
        // Also dump ALL meta keys for this jet-engine post
        $all = get_post_meta( $p->ID );
        error_log( '  All meta keys: ' . implode( ', ', array_keys( $all ) ) );
    }

    // 2. What is the jet_engine_meta_boxes option?
    $opt = get_option( 'jet_engine_meta_boxes' );
    error_log( 'jet_engine_meta_boxes option: ' . substr( print_r( $opt, true ), 0, 500 ) );

    // 3. Pick the first "Our Team" (or any JetEngine CPT) post and dump its meta.
    $team_posts = get_posts( array(
        'post_type'      => 'any',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'exclude'        => array_merge(
            wp_list_pluck( get_posts( array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => 100 ) ), 'ID' ),
            wp_list_pluck( get_posts( array( 'post_type' => 'page', 'fields' => 'ids', 'posts_per_page' => 100 ) ), 'ID' )
        ),
    ) );
    if ( $team_posts ) {
        $tp = $team_posts[0];
        error_log( 'Sample CPT post: ID=' . $tp->ID . ' type=' . $tp->post_type . ' title=' . $tp->post_title );
        $meta = get_post_meta( $tp->ID );
        foreach ( $meta as $key => $vals ) {
            error_log( '  meta[' . $key . '] = ' . substr( print_r( $vals[0] ?? '', true ), 0, 200 ) );
        }
    }

    error_log( '=== End MUT JetEngine Debug ===' );
} );
