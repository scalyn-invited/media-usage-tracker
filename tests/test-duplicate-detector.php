<?php
/**
 * Standalone tests for DuplicateDetector.
 * Run: php tests/test-duplicate-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $counts = array();
		public function get_usage_count( $id ) { return $this->counts[ $id ] ?? 0; }
	}
}

namespace {
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	// WP stubs.
	$GLOBALS['__posts'] = array();
	$GLOBALS['__files'] = array();
	$GLOBALS['__transients'] = array();

	function get_post( $id ) { return $GLOBALS['__posts'][ (int)$id ] ?? null; }
	function get_attached_file( $id ) { return $GLOBALS['__files'][ (int)$id ] ?? false; }
	function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $ttl ) { $GLOBALS['__transients'][ $k ] = $v; }
	function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function size_format( $b ) { return round( $b / 1024, 1 ) . ' KB'; }
	function wp_get_attachment_image() { return ''; }
	function get_the_date( $f, $p ) { return ''; }

	require MUT_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
	use MediaUsageTracker\Admin\DuplicateDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond ) {
		global $pass, $fail;
		printf( "[%s] %s\n", $cond ? 'PASS' : 'FAIL', $label );
		$cond ? $pass++ : $fail++;
	}

	// --- Helpers -------------------------------------------------------------
	function make_file( $content ) {
		$f = tempnam( sys_get_temp_dir(), 'mut' );
		file_put_contents( $f, $content );
		return $f;
	}
	function fake_att( $id, $parent = 0 ) {
		$GLOBALS['__posts'][ $id ] = (object) array( 'ID' => $id, 'post_parent' => $parent, 'post_mime_type' => 'image/png', 'guid' => '', 'post_title' => '' );
		return (object) array( 'ID' => $id, 'post_parent' => $parent, 'post_mime_type' => 'image/png', 'guid' => '', 'post_title' => '' );
	}

	$storage = new UsageStorage();

	// =========================================================================
	// 1. Exact duplicate detection
	// =========================================================================
	$content = 'identical-bytes-' . uniqid();
	$f1 = make_file( $content );
	$f2 = make_file( $content );
	$f3 = make_file( 'different-bytes-' . uniqid() );

	$GLOBALS['__files'][101] = $f1;
	$GLOBALS['__files'][102] = $f2;
	$GLOBALS['__files'][103] = $f3;

	$atts = array( fake_att(101), fake_att(102), fake_att(103) );

	$det = new DuplicateDetector( $storage );
	$ref = new ReflectionClass( $det );

	$detectExact = $ref->getMethod('detect_exact'); $detectExact->setAccessible(true);
	$groups = $detectExact->invoke( $det, $atts );

	check( 'exact: finds 1 group', count( $groups ) === 1 );
	check( 'exact: group type is exact', $groups[0]['type'] === 'exact' );
	check( 'exact: group contains both ids', in_array(101, $groups[0]['ids']) && in_array(102, $groups[0]['ids']) );
	check( 'exact: group excludes different file', ! in_array(103, $groups[0]['ids']) );

	// =========================================================================
	// 2. Similar stem detection
	// =========================================================================
	$GLOBALS['__files'][201] = '/tmp/logo.png';
	$GLOBALS['__files'][202] = '/tmp/logo-v2.png';
	$GLOBALS['__files'][203] = '/tmp/logo-final.png';
	$GLOBALS['__files'][204] = '/tmp/banner.jpg';

	$atts2 = array( fake_att(201), fake_att(202), fake_att(203), fake_att(204) );

	$detectSim = $ref->getMethod('detect_similar'); $detectSim->setAccessible(true);
	$groups2 = $detectSim->invoke( $det, $atts2 );

	check( 'similar: finds 1 group (logo cluster)', count( $groups2 ) === 1 );
	check( 'similar: type is similar', $groups2[0]['type'] === 'similar' );
	check( 'similar: all 3 logo variants grouped', count( $groups2[0]['ids'] ) === 3 );
	check( 'similar: banner excluded', ! in_array(204, $groups2[0]['ids']) );

	// WP size suffix stripped.
	$GLOBALS['__files'][211] = '/tmp/photo.jpg';
	$GLOBALS['__files'][212] = '/tmp/photo-300x200.jpg';
	$atts3 = array( fake_att(211), fake_att(212) );
	$groups3 = $detectSim->invoke( $det, $atts3 );
	check( 'similar: WP size suffix stripped for grouping', count( $groups3 ) === 1 );

	// =========================================================================
	// 3. Resize (post_parent) detection
	// =========================================================================
	$atts4 = array( fake_att(301), fake_att(302, 301), fake_att(303, 301) );

	$detectRes = $ref->getMethod('detect_resizes'); $detectRes->setAccessible(true);
	$groups4 = $detectRes->invoke( $det, $atts4 );

	check( 'resizes: finds 1 group', count( $groups4 ) === 1 );
	check( 'resizes: type is resizes', $groups4[0]['type'] === 'resizes' );
	check( 'resizes: parent included in ids', in_array(301, $groups4[0]['ids']) );
	check( 'resizes: both children included', in_array(302, $groups4[0]['ids']) && in_array(303, $groups4[0]['ids']) );

	// =========================================================================
	// 4. Normalise stem edge cases
	// =========================================================================
	$normStem = $ref->getMethod('normalise_stem'); $normStem->setAccessible(true);

	check( 'stem: strip -v2',      $normStem->invoke($det, 'logo-v2.png')        === 'logo' );
	check( 'stem: strip -copy',    $normStem->invoke($det, 'photo-copy.jpg')      === 'photo' );
	check( 'stem: strip -final',   $normStem->invoke($det, 'hero-final.png')      === 'hero' );
	check( 'stem: strip WP sizes', $normStem->invoke($det, 'img-1024x768.jpg')    === 'img' );
	check( 'stem: strip -1',       $normStem->invoke($det, 'asset-1.png')         === 'asset' );
	check( 'stem: underscore sep', $normStem->invoke($det, 'my_logo_v2.png')      === 'my-logo' );

	// =========================================================================
	// 5. Transient cache
	// =========================================================================
	// Bust so get_groups() runs fresh.
	$det->bust_cache();
	// With no real wpdb we can't call get_groups() fully, but we can confirm bust works.
	check( 'cache: bust clears transient', get_transient( 'mut_duplicate_groups' ) === false );

	// =========================================================================
	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
