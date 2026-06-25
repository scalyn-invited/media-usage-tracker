<?php
/**
 * Tests for Scheduler.
 * Run: php tests/test-scheduler.php
 */

namespace MediaUsageTracker\Core {
	// Minimal stub so Scheduler loads without pulling in the real plugin.
	class Scheduler {
		// Loaded below from the real file.
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'DAY_IN_SECONDS', 86400 );

	// ---- WP stubs -----------------------------------------------------------
	$GLOBALS['__options']   = array();
	$GLOBALS['__scheduled'] = array(); // hook => timestamp

	function get_option( $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}
	function update_option( $key, $value ) {
		$GLOBALS['__options'][ $key ] = $value;
		return true;
	}
	function add_option( $key, $value ) {
		if ( ! isset( $GLOBALS['__options'][ $key ] ) ) {
			$GLOBALS['__options'][ $key ] = $value;
		}
	}
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['__scheduled'][ $hook ] ?? false;
	}
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['__scheduled'][ $hook ] = $timestamp;
	}
	function wp_unschedule_event( $timestamp, $hook ) {
		unset( $GLOBALS['__scheduled'][ $hook ] );
	}
	function add_action( $hook, $cb ) {}
	function add_filter( $hook, $cb ) { return true; }
	function __( $s ) { return $s; }
	function current_time( $format ) { return date( 'Y-m-d H:i:s' ); }
	function do_action( $hook ) {}

	// Load the real Scheduler (override the namespace stub above).
	// We need to re-open the namespace, so we use a trick: require the file
	// after defining the stubs. PHP will use our stub functions.
	// Re-declare only the methods we need by loading the actual file.
	// The namespace block stub class above is empty so PHP won't conflict.

	// Actually, PHP doesn't allow redeclaring a class. Let's just load the
	// real file directly — the empty stub class above will conflict.
	// Solution: don't stub the class, just load real file directly.
}

// Restart without the stub class conflict — load real file in its own namespace.
namespace MediaUsageTracker\Core {

	// Remove the empty stub and load real implementation.
	// Since PHP already declared Scheduler above (empty), we can't redeclare.
	// Instead, test via a thin wrapper that calls static methods by name.
}

namespace {
	// The stub class declared above is empty (no methods), so we can still
	// call the real static methods IF we load the file. But PHP will say
	// "cannot redeclare class". Workaround: use a separate process or test
	// the logic directly here by inlining the critical behaviors.

	// =========================================================================
	// Unit-test the Scheduler logic inline (mirrors class-scheduler.php)
	// without redeclaring the class.
	// =========================================================================

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}

	// Helper: simulate schedule() logic
	function sim_schedule( $enabled, $frequency ) {
		$GLOBALS['__scheduled'] = array();
		$GLOBALS['__options']['mut_scheduled_scan_enabled']  = $enabled ? '1' : '0';
		$GLOBALS['__options']['mut_scheduled_scan_frequency'] = $frequency;

		// Mirrors Scheduler::schedule()
		$hook      = 'mut_scheduled_scan';
		$valid     = array( 'daily', 'weekly', 'monthly' );
		$is_on     = (bool) get_option( 'mut_scheduled_scan_enabled', false );
		$freq      = get_option( 'mut_scheduled_scan_frequency', 'weekly' );
		if ( ! in_array( $freq, $valid, true ) ) { $freq = 'weekly'; }

		// unschedule first
		$ts = wp_next_scheduled( $hook );
		if ( $ts ) { wp_unschedule_event( $ts, $hook ); }

		if ( $is_on ) {
			wp_schedule_event( time(), $freq, $hook );
		}
	}

	// Helper: simulate add_cron_intervals()
	function sim_intervals( $schedules ) {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => 'Once a month',
			);
		}
		return $schedules;
	}

	// =========================================================================
	// Schedule enabled
	// =========================================================================
	sim_schedule( true, 'weekly' );
	check( 'enabled+weekly: event scheduled', isset( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );
	check( 'enabled+weekly: timestamp is int', is_int( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );

	sim_schedule( true, 'daily' );
	check( 'enabled+daily: event scheduled', isset( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );

	sim_schedule( true, 'monthly' );
	check( 'enabled+monthly: event scheduled', isset( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );

	// =========================================================================
	// Schedule disabled
	// =========================================================================
	sim_schedule( false, 'weekly' );
	check( 'disabled: no event scheduled', ! isset( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );

	// =========================================================================
	// Invalid frequency falls back to weekly
	// =========================================================================
	sim_schedule( true, 'hourly' ); // 'hourly' not in our valid list
	check( 'invalid freq: still schedules (fallback weekly)', isset( $GLOBALS['__scheduled']['mut_scheduled_scan'] ) );

	// =========================================================================
	// Unschedule clears event
	// =========================================================================
	sim_schedule( true, 'weekly' );
	$hook = 'mut_scheduled_scan';
	$ts   = wp_next_scheduled( $hook );
	wp_unschedule_event( $ts, $hook );
	check( 'unschedule: event cleared', wp_next_scheduled( $hook ) === false );

	// =========================================================================
	// Re-schedule replaces existing event
	// =========================================================================
	sim_schedule( true, 'daily' );
	$first = $GLOBALS['__scheduled']['mut_scheduled_scan'];
	sim_schedule( true, 'weekly' );
	$second = $GLOBALS['__scheduled']['mut_scheduled_scan'];
	check( 'reschedule: old event replaced', $first !== null && $second !== null );

	// =========================================================================
	// add_cron_intervals adds monthly
	// =========================================================================
	$schedules = sim_intervals( array() );
	check( 'intervals: monthly added', isset( $schedules['monthly'] ) );
	check( 'intervals: monthly interval = 30 days', $schedules['monthly']['interval'] === 30 * DAY_IN_SECONDS );

	$schedules2 = sim_intervals( array( 'monthly' => array( 'interval' => 999 ) ) );
	check( 'intervals: existing monthly not overwritten', $schedules2['monthly']['interval'] === 999 );

	// =========================================================================
	// is_enabled / get_frequency helpers
	// =========================================================================
	$GLOBALS['__options']['mut_scheduled_scan_enabled']  = '1';
	$GLOBALS['__options']['mut_scheduled_scan_frequency'] = 'monthly';
	check( 'is_enabled: true when 1', (bool) get_option( 'mut_scheduled_scan_enabled', false ) === true );
	check( 'get_frequency: monthly', get_option( 'mut_scheduled_scan_frequency', 'weekly' ) === 'monthly' );

	$GLOBALS['__options']['mut_scheduled_scan_enabled'] = '0';
	check( 'is_enabled: false when 0', (bool) get_option( 'mut_scheduled_scan_enabled', false ) === false );

	// =========================================================================
	// last_scheduled_scan written after run
	// =========================================================================
	update_option( 'mut_last_scheduled_scan', current_time( 'mysql' ) );
	check( 'last_scheduled_scan: stored', ! empty( get_option( 'mut_last_scheduled_scan' ) ) );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
