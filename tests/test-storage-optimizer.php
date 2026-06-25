<?php
/**
 * Standalone tests for StorageOptimizer.
 * Run: php tests/test-storage-optimizer.php
 */

namespace MediaUsageTracker\Storage {
	// Fake storage we can fully control.
	class UsageStorage {
		public $total_bytes = 0;
		public $unused_bytes = 0;
		public $unused_ids = array();
		public $counts = array();
		public function get_storage_usage() { return $this->total_bytes; }
		public function get_unused_storage_usage() { return $this->unused_bytes; }
		public function get_unused_attachments() { return $this->unused_ids; }
		public function get_usage_count( $id ) { return $this->counts[ $id ] ?? 0; }
	}
}

namespace MediaUsageTracker\Admin {
	// Stub DuplicateDetector so the optimizer's group logic is deterministic.
	class DuplicateDetector {
		public static $groups = array();
		public function __construct( $s ) {}
		public function get_groups() { return self::$groups; }
	}
}

namespace {
	define( 'HOUR_IN_SECONDS', 3600 );

	// Point MUT_PLUGIN_DIR at a temp dir holding an EMPTY detector stub, so the
	// optimizer's internal require_once does not redeclare our in-test stub class.
	$__repo = dirname( __DIR__ ) . '/';
	$__tmp  = sys_get_temp_dir() . '/mut_opt_' . uniqid() . '/';
	@mkdir( $__tmp . 'includes', 0777, true );
	file_put_contents( $__tmp . 'includes/class-duplicate-detector.php', "<?php\n// stub\n" );
	define( 'MUT_PLUGIN_DIR', $__tmp );

	$GLOBALS['__files'] = array();
	$GLOBALS['__transients'] = array();
	$GLOBALS['__mime_rows'] = array();

	function get_attached_file( $id ) { return $GLOBALS['__files'][ (int)$id ] ?? false; }
	function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $ttl ) { $GLOBALS['__transients'][ $k ] = $v; }
	function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); }
	function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; }
	function size_format( $bytes, $dec = 0 ) {
		$u = array( 'B','KB','MB','GB' ); $i = 0;
		while ( $bytes >= 1024 && $i < 3 ) { $bytes /= 1024; $i++; }
		return round( $bytes, $dec ) . ' ' . $u[ $i ];
	}

	// Minimal $wpdb for mime_breakdown().
	class FakeWpdb {
		public $posts = 'wp_posts';
		public function get_results( $sql ) { return $GLOBALS['__mime_rows']; }
	}
	$GLOBALS['wpdb'] = new FakeWpdb();

	require $__repo . 'includes/class-storage-optimizer.php';
	use MediaUsageTracker\Admin\StorageOptimizer;
	use MediaUsageTracker\Admin\DuplicateDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond ) {
		global $pass, $fail;
		printf( "[%s] %s\n", $cond ? 'PASS' : 'FAIL', $label );
		$cond ? $pass++ : $fail++;
	}
	function find_rec( $report, $cat ) {
		foreach ( $report['recommendations'] as $r ) {
			if ( $r['category'] === $cat ) return $r;
		}
		return null;
	}
	function mk_file( $bytes ) {
		$f = tempnam( sys_get_temp_dir(), 'mut' );
		file_put_contents( $f, str_repeat( '0', $bytes ) );
		return $f;
	}

	// =========================================================================
	// Scenario 1: 35% unused → high-priority unused recommendation
	// =========================================================================
	$s = new UsageStorage();
	$s->total_bytes  = 1000000000; // 1 GB
	$s->unused_bytes = 350000000;  // 350 MB → 35%
	$s->unused_ids   = array( 1, 2, 3 );
	DuplicateDetector::$groups = array();

	$opt = new StorageOptimizer( $s );
	$opt->bust_cache();
	$report = $opt->refresh();

	check( 'unused: recoverable_pct = 35', $report['recoverable_pct'] === 35.0 );
	$u = find_rec( $report, 'unused' );
	check( 'unused: recommendation present', $u !== null );
	check( 'unused: headline mentions 35%', $u && strpos( $u['headline'], '35%' ) !== false );
	check( 'unused: priority high', $u && $u['priority'] === StorageOptimizer::PRIORITY_HIGH );
	check( 'unused: saving equals unused bytes', $u && $u['saving'] === 350000000 );

	// =========================================================================
	// Scenario 2: below 5% unused → NO unused recommendation
	// =========================================================================
	$s2 = new UsageStorage();
	$s2->total_bytes  = 1000000000;
	$s2->unused_bytes = 20000000; // 2%
	$s2->unused_ids   = array( 9 );
	$opt2 = new StorageOptimizer( $s2 );
	$opt2->bust_cache();
	$r2 = $opt2->refresh();
	check( 'clean lib: no unused recommendation', find_rec( $r2, 'unused' ) === null );

	// =========================================================================
	// Scenario 3: exact duplicates → duplicate waste recommendation
	// =========================================================================
	$dupFile = mk_file( 5 * 1024 * 1024 ); // 5 MB each
	$GLOBALS['__files'][20] = $dupFile;
	$GLOBALS['__files'][21] = $dupFile;
	$GLOBALS['__files'][22] = $dupFile;

	$s3 = new UsageStorage();
	$s3->total_bytes  = 100 * 1024 * 1024;
	$s3->unused_bytes = 0;
	DuplicateDetector::$groups = array(
		array( 'type' => 'exact', 'ids' => array( 20, 21, 22 ) ),
	);
	$opt3 = new StorageOptimizer( $s3 );
	$opt3->bust_cache();
	$r3 = $opt3->refresh();
	$d = find_rec( $r3, 'duplicates' );
	check( 'dup: recommendation present', $d !== null );
	// 2 redundant copies × 5 MB = 10 MB waste
	check( 'dup: waste = 2 redundant copies', $d && $d['saving'] === 10 * 1024 * 1024 );
	check( 'dup: headline counts 2 files', $d && strpos( $d['headline'], '2 duplicate' ) !== false );

	// =========================================================================
	// Scenario 4: large unused files → medium-priority recommendation
	// =========================================================================
	$big = mk_file( 15 * 1024 * 1024 ); // 15 MB > 10 MB threshold
	$small = mk_file( 1 * 1024 * 1024 );
	$GLOBALS['__files'][30] = $big;
	$GLOBALS['__files'][31] = $small;

	$s4 = new UsageStorage();
	$s4->total_bytes  = 200 * 1024 * 1024;
	$s4->unused_bytes = 16 * 1024 * 1024;
	$s4->unused_ids   = array( 30, 31 );
	DuplicateDetector::$groups = array();
	$opt4 = new StorageOptimizer( $s4 );
	$opt4->bust_cache();
	$r4 = $opt4->refresh();
	$lf = find_rec( $r4, 'large_files' );
	check( 'large: recommendation present', $lf !== null );
	check( 'large: only the 15MB file counted', $lf && $lf['saving'] === 15 * 1024 * 1024 );
	check( 'large: priority medium', $lf && $lf['priority'] === StorageOptimizer::PRIORITY_MEDIUM );

	// =========================================================================
	// Scenario 5: similar versions → low-priority consolidation tip
	// =========================================================================
	$v1 = mk_file( 2 * 1024 * 1024 );
	$v2 = mk_file( 2 * 1024 * 1024 );
	$GLOBALS['__files'][40] = $v1;
	$GLOBALS['__files'][41] = $v2;
	$s5 = new UsageStorage();
	$s5->total_bytes  = 50 * 1024 * 1024;
	$s5->unused_bytes = 0;
	$s5->counts = array( 40 => 5, 41 => 0 ); // 40 is most-used → keep it
	DuplicateDetector::$groups = array(
		array( 'type' => 'similar', 'ids' => array( 40, 41 ) ),
	);
	$opt5 = new StorageOptimizer( $s5 );
	$opt5->bust_cache();
	$r5 = $opt5->refresh();
	$sim = find_rec( $r5, 'similar' );
	check( 'similar: recommendation present', $sim !== null );
	check( 'similar: saves the non-canonical 2MB copy', $sim && $sim['saving'] === 2 * 1024 * 1024 );
	check( 'similar: priority low', $sim && $sim['priority'] === StorageOptimizer::PRIORITY_LOW );

	// =========================================================================
	// Scenario 6: MIME breakdown sorted by bytes desc
	// =========================================================================
	$vid = mk_file( 40 * 1024 * 1024 );
	$img = mk_file( 1 * 1024 * 1024 );
	$GLOBALS['__files'][50] = $vid;
	$GLOBALS['__files'][51] = $img;
	$GLOBALS['__mime_rows'] = array(
		(object) array( 'ID' => 51, 'post_mime_type' => 'image/png' ),
		(object) array( 'ID' => 50, 'post_mime_type' => 'video/mp4' ),
	);
	$s6 = new UsageStorage();
	$s6->total_bytes  = 41 * 1024 * 1024;
	$s6->unused_bytes = 0;
	DuplicateDetector::$groups = array();
	$opt6 = new StorageOptimizer( $s6 );
	$opt6->bust_cache();
	$r6 = $opt6->refresh();
	$labels = array_keys( $r6['mime_breakdown'] );
	check( 'mime: Videos sorted first (largest)', $labels[0] === 'Videos' );
	check( 'mime: video recommendation present', find_rec( $r6, 'mime_type' ) !== null );

	// =========================================================================
	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
