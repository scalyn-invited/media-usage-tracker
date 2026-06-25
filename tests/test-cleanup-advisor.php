<?php
/**
 * Standalone test harness for CleanupAdvisor.
 * Run: php tests/test-cleanup-advisor.php
 */

namespace MediaUsageTracker\Storage {
	// Fake storage satisfying the advisor's type hint.
	class UsageStorage {
		public $counts   = array();
		public $statuses = array();
		public function get_usage_count( $id ) { return $this->counts[ $id ] ?? 0; }
		public function get_usage_post_statuses( $id ) { return $this->statuses[ $id ] ?? array(); }
	}
}

namespace {
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	$GLOBALS['__posts'] = array();
	$GLOBALS['__files'] = array();

	function get_post( $id ) { return $GLOBALS['__posts'][ $id ] ?? null; }
	function current_time( $type ) { return time(); }
	function get_attached_file( $id ) { return $GLOBALS['__files'][ $id ] ?? false; }
	function size_format( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$i = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) { $bytes /= 1024; $i++; }
		return round( $bytes, 1 ) . ' ' . $units[ $i ];
	}

	require MUT_PLUGIN_DIR . 'includes/class-cleanup-advisor.php';

	use MediaUsageTracker\Admin\CleanupAdvisor;
	use MediaUsageTracker\Storage\UsageStorage;

	$now     = time();
	$months  = fn( $m ) => $now - ( $m * 30 * DAY_IN_SECONDS );
	$storage = new UsageStorage();
	$advisor = new CleanupAdvisor( $storage );

	$big = tempnam( sys_get_temp_dir(), 'mut' );
	file_put_contents( $big, str_repeat( '0', 3 * 1024 * 1024 ) );

	$pass = 0; $fail = 0;
	function check( $label, $got, $expected ) {
		global $pass, $fail;
		$ok = ( $got === $expected );
		printf( "[%s] %s (expected=%s got=%s)\n", $ok ? 'PASS' : 'FAIL', $label, $expected, $got );
		$ok ? $pass++ : $fail++;
	}

	function scenario( $id, $post_date_ts, $file = false ) {
		$GLOBALS['__posts'][ $id ] = (object) array( 'post_date' => date( 'Y-m-d H:i:s', $post_date_ts ) );
		$GLOBALS['__files'][ $id ] = $file;
	}

	// 1. Unused, 18 months old → archive.
	scenario( 1, $months( 18 ) );
	$a = $advisor->get_advice( 1, $now );
	check( 'unused 18mo -> archive', $a['action'], 'archive' );
	check( 'message mentions 18 months', strpos( $a['message'], '18 months' ) !== false ? 'yes' : 'no', 'yes' );

	// 2. Unused, 24 months old, LARGE file → archive + frees space.
	scenario( 2, $months( 24 ), $big );
	$a = $advisor->get_advice( 2, $now );
	check( 'unused large -> archive', $a['action'], 'archive' );
	check( 'mentions frees space', strpos( $a['suggestion'], 'frees' ) !== false ? 'yes' : 'no', 'yes' );
	check( 'humanized to years', strpos( $a['message'], '2 years' ) !== false ? 'yes' : 'no', 'yes' );

	// 3. Unused, 4 months old → review.
	scenario( 3, $months( 4 ) );
	$a = $advisor->get_advice( 3, $now );
	check( 'unused 4mo -> review', $a['action'], 'review' );

	// 4. Unused, brand new → monitor.
	scenario( 4, $months( 0 ) );
	$a = $advisor->get_advice( 4, $now );
	check( 'unused new -> monitor', $a['action'], 'monitor' );

	// 5. Used in published content → keep.
	$storage->counts[5]   = 3;
	$storage->statuses[5] = array( 'publish', 'draft' );
	scenario( 5, $months( 20 ) );
	$a = $advisor->get_advice( 5, $now );
	check( 'published use -> keep', $a['action'], 'keep' );

	// 6. Used only in drafts → review.
	$storage->counts[6]   = 1;
	$storage->statuses[6] = array( 'draft' );
	scenario( 6, $months( 20 ) );
	$a = $advisor->get_advice( 6, $now );
	check( 'draft-only use -> review', $a['action'], 'review' );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
