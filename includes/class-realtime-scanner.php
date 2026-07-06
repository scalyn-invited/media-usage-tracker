<?php
namespace MediaUsageTracker\Core;

use MediaUsageTracker\Storage\UsageStorage;
use MediaUsageTracker\Scanner\MediaScanner;

/**
 * Keeps media usage data current automatically, in the background, instead
 * of waiting for the next manual or scheduled full scan.
 *
 * Most detectors (content, Elementor, ACF, Divi, WPBakery, Beaver Builder,
 * Avada, Astra, WooCommerce, JetEngine, JetPopup, Yoast, featured image) are
 * all scoped to a single wp_posts row, so one `save_post` hook covers every
 * one of them at once — whichever builder wrote to that post, re-detecting
 * the post alone picks it up.
 *
 * A couple of detectors aren't tied to a post at all and need their own
 * trigger: Gravity Forms has its own save/delete actions, wired up below.
 * wpDataTables doesn't expose an equivalent hook to build against, so it's
 * intentionally left out here — that one source stays on the periodic
 * scheduled/manual scan.
 *
 * To avoid hammering the database when many things change in quick
 * succession (bulk edits, imports), individual changes are queued and
 * processed together in one deferred background pass a few seconds later,
 * rather than rescanning immediately on every single save.
 *
 * Everything here only runs when *you* (or another editor) save something
 * in wp-admin — a visitor loading the site never triggers any of it.
 */
class RealtimeScanner {

	const OPT_ON        = 'mut_realtime_updates_enabled';
	const QUEUE_OPTION  = 'mut_realtime_pending_queue';
	const PROCESS_HOOK  = 'mut_process_realtime_queue';
	const DEBOUNCE_SECS = 20;

	/** Sentinel queue entry standing in for "re-run the Gravity Forms detector". */
	const GLOBAL_GRAVITYFORMS = '__global_gravityforms';

	/**
	 * Register all WP hooks needed. Called once from Plugin::init(), same
	 * pattern as Scheduler::register().
	 */
	public static function register() {
		// The processor itself is always registered so a previously-queued
		// job can still run even if the setting gets switched off in between.
		add_action( self::PROCESS_HOOK, array( __CLASS__, 'process_queue' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		// Gravity Forms — these simply never fire if Gravity Forms isn't installed.
		add_action( 'gform_after_save_form', array( __CLASS__, 'on_gravityforms_change' ) );
		add_action( 'gform_after_delete_form', array( __CLASS__, 'on_gravityforms_change' ) );
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPT_ON, false );
	}

	/**
	 * Clear any pending deferred processing run. Called on plugin
	 * deactivation, same pattern as Scheduler::unschedule().
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::PROCESS_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::PROCESS_HOOK );
		}
		delete_option( self::QUEUE_OPTION );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks — queue the change and schedule the debounced pass.
	// Deletions clean up immediately since there's nothing to (re)scan.
	// -------------------------------------------------------------------------

	public static function on_save_post( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = $post ?: get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Only post types that are actually publicly viewable are worth
		// tracking media usage for.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		self::queue( absint( $post_id ) );
	}

	public static function on_delete_post( $post_id ) {
		require_once MUT_PLUGIN_DIR . 'includes/class-usage-storage.php';
		( new UsageStorage() )->clear_usage_for_post( absint( $post_id ) );
	}

	public static function on_delete_attachment( $attachment_id ) {
		require_once MUT_PLUGIN_DIR . 'includes/class-usage-storage.php';
		( new UsageStorage() )->clear_usage_for_attachment( absint( $attachment_id ) );
	}

	public static function on_gravityforms_change() {
		self::queue( self::GLOBAL_GRAVITYFORMS );
	}

	// -------------------------------------------------------------------------
	// Queue + debounce
	// -------------------------------------------------------------------------

	/**
	 * Add an item (post ID, or a "__global_*" sentinel) to the pending queue
	 * and make sure exactly one deferred processing run is scheduled.
	 */
	private static function queue( $item ) {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		if ( ! in_array( $item, $queue, true ) ) {
			$queue[] = $item;
			update_option( self::QUEUE_OPTION, $queue, false );
		}

		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE_SECS, self::PROCESS_HOOK );
		}
	}

	/**
	 * Runs once, a few seconds after the first queued change — processes
	 * everything queued up since then (however many items that is) in a
	 * single background pass.
	 */
	public static function process_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}
		delete_option( self::QUEUE_OPTION );

		require_once MUT_PLUGIN_DIR . 'includes/class-usage-storage.php';
		require_once MUT_PLUGIN_DIR . 'includes/class-scanner.php';
		$storage = new UsageStorage();
		$scanner = new MediaScanner( $storage );

		foreach ( $queue as $item ) {
			if ( $item === self::GLOBAL_GRAVITYFORMS ) {
				$scanner->rescan_global_detector( 'gravityforms' );
			} elseif ( is_numeric( $item ) ) {
				$scanner->rescan_post( (int) $item );
			}
		}
	}
}
