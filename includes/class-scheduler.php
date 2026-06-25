<?php
namespace MediaUsageTracker\Core;

use MediaUsageTracker\Storage\UsageStorage;
use MediaUsageTracker\Scanner\MediaScanner;

/**
 * Handles automatic scheduled scans via WP Cron.
 *
 * Schedule is driven by the mut_settings option:
 *   mut_scheduled_scan_enabled  => '1' or '0'
 *   mut_scheduled_scan_frequency => 'daily' | 'weekly' | 'monthly'
 *
 * Hook: 'mut_scheduled_scan'
 */
class Scheduler {

	const HOOK     = 'mut_scheduled_scan';
	const OPT_ON   = 'mut_scheduled_scan_enabled';
	const OPT_FREQ = 'mut_scheduled_scan_frequency';

	/**
	 * Register all WP hooks needed by the scheduler.
	 * Called once from Plugin::init().
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( 'update_option_' . self::OPT_ON,   array( __CLASS__, 'on_settings_change' ) );
		add_action( 'update_option_' . self::OPT_FREQ, array( __CLASS__, 'on_settings_change' ) );
	}

	/**
	 * Schedule the cron event based on current settings.
	 * Safe to call on activation or settings save — clears any existing event first.
	 */
	public static function schedule() {
		self::unschedule();

		if ( ! self::is_enabled() ) {
			return;
		}

		$frequency = self::get_frequency();
		if ( ! in_array( $frequency, array_keys( self::intervals() ), true ) ) {
			$frequency = 'weekly';
		}

		wp_schedule_event( time(), $frequency, self::HOOK );
	}

	/**
	 * Remove any scheduled cron event for this hook.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Fired when either settings option changes.
	 */
	public static function on_settings_change() {
		self::schedule();
	}

	/**
	 * Run a full scan synchronously (called by WP Cron).
	 * No AJAX batching — cron runs the whole thing in one PHP process.
	 */
	public static function run_scan() {
		require_once MUT_PLUGIN_DIR . 'includes/class-usage-storage.php';
		require_once MUT_PLUGIN_DIR . 'includes/class-scanner.php';

		$storage = new UsageStorage();
		$scanner = new MediaScanner( $storage );

		$result  = $scanner->start_scan();
		$scan_id = $result['scan_id'];
		$offset  = 0;

		do {
			$batch  = $scanner->process_batch( $scan_id, $offset );
			$offset = $batch['offset'];
		} while ( ! empty( $batch['has_more'] ) );

		update_option( 'mut_last_scheduled_scan', current_time( 'mysql' ) );

		do_action( 'mut_scheduled_scan_complete', $scan_id );
	}

	/**
	 * Register the 'monthly' interval with WP Cron (WP only ships daily/weekly).
	 */
	public static function add_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once a month', 'media-usage-tracker' ),
			);
		}
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function is_enabled() {
		return (bool) get_option( self::OPT_ON, false );
	}

	public static function get_frequency() {
		return get_option( self::OPT_FREQ, 'weekly' );
	}

	public static function next_run() {
		$ts = wp_next_scheduled( self::HOOK );
		return $ts ? $ts : null;
	}

	/** Human-readable labels for each supported frequency. */
	public static function intervals() {
		return array(
			'daily'   => __( 'Daily', 'media-usage-tracker' ),
			'weekly'  => __( 'Weekly', 'media-usage-tracker' ),
			'monthly' => __( 'Monthly', 'media-usage-tracker' ),
		);
	}
}
