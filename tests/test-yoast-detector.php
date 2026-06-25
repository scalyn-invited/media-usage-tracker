<?php
/**
 * Tests for YoastDetector.
 * Run: php tests/test-yoast-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'WPSEO_VERSION', '21.0' );

	function absint( $v ) { return abs( (int) $v ); }

	$GLOBALS['__meta'] = array();
	function get_post_meta( $post_id, $key, $single = true ) {
		return $GLOBALS['__meta'][ $post_id ][ $key ] ?? '';
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-yoast-detector.php';

	use MediaUsageTracker\Scanner\YoastDetector;
	use MediaUsageTracker\Scanner\MediaDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	function post( $id, $type = 'post' ) {
		return (object) array( 'ID' => $id, 'post_type' => $type );
	}
	function rec_ids( $s ) { $ids = array_map( fn( $c ) => $c['attachment_id'], $s->calls ); sort( $ids ); return $ids; }

	// =========================================================================
	// Interface + availability
	// =========================================================================
	$d = new YoastDetector( new UsageStorage() );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: yoast', $d->key() === 'yoast' );
	check( 'available when WPSEO_VERSION defined', $d->is_available() === true );

	// =========================================================================
	// No meta → 0
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][1] = array();
	check( 'no meta → 0', $d->detect( post( 1 ), 5 ) === 0 && count( $s->calls ) === 0 );

	// =========================================================================
	// OG image only
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][2] = array( '_yoast_wpseo_opengraph-image-id' => '101' );
	$n = $d->detect( post( 2 ), 5 );
	check( 'og only: 1 recorded', $n === 1, $n );
	check( 'og only: id 101', $s->calls[0]['attachment_id'] === 101 );
	check( 'og only: usage_type yoast', $s->calls[0]['usage_type'] === 'yoast' );
	check( 'og only: context label', $s->calls[0]['context'] === 'Yoast SEO: OG Image' );

	// =========================================================================
	// Twitter image only
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][3] = array( '_yoast_wpseo_twitter-image-id' => '202' );
	$n = $d->detect( post( 3 ), 5 );
	check( 'twitter only: 1 recorded', $n === 1, $n );
	check( 'twitter only: id 202', $s->calls[0]['attachment_id'] === 202 );
	check( 'twitter only: context label', $s->calls[0]['context'] === 'Yoast SEO: Twitter Image' );

	// =========================================================================
	// Both OG + Twitter, different images → 2 records
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][4] = array(
		'_yoast_wpseo_opengraph-image-id' => '301',
		'_yoast_wpseo_twitter-image-id'   => '302',
	);
	$n = $d->detect( post( 4 ), 5 );
	check( 'both: 2 recorded', $n === 2, $n );
	check( 'both: ids 301+302', rec_ids( $s ) === array( 301, 302 ), rec_ids( $s ) );

	// =========================================================================
	// Dedup: same image for OG + Twitter → 1 record
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][5] = array(
		'_yoast_wpseo_opengraph-image-id' => '400',
		'_yoast_wpseo_twitter-image-id'   => '400',
	);
	$n = $d->detect( post( 5 ), 5 );
	check( 'dedup: same id once', $n === 1 && rec_ids( $s ) === array( 400 ), rec_ids( $s ) );

	// =========================================================================
	// Zero / empty value ignored
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][6] = array(
		'_yoast_wpseo_opengraph-image-id' => '0',
		'_yoast_wpseo_twitter-image-id'   => '',
	);
	check( 'zero/empty ignored', $d->detect( post( 6 ), 5 ) === 0 && count( $s->calls ) === 0 );

	// =========================================================================
	// Post ID + scan ID propagated
	// =========================================================================
	$s = new UsageStorage(); $d = new YoastDetector( $s );
	$GLOBALS['__meta'][7] = array( '_yoast_wpseo_opengraph-image-id' => '500' );
	$d->detect( post( 7, 'page' ), 99 );
	check( 'post_id propagated', $s->calls[0]['post_id'] === 7 );
	check( 'post_type propagated', $s->calls[0]['post_type'] === 'page' );
	check( 'scan_id propagated', $s->calls[0]['scan_id'] === 99 );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
