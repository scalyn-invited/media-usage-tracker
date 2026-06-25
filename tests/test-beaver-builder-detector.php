<?php
/**
 * Tests for BeaverBuilderDetector.
 * Run: php tests/test-beaver-builder-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'FL_BUILDER_VERSION', '2.6' );

	function absint( $v ) { return abs( (int) $v ); }

	$GLOBALS['__meta'] = array();
	function get_post_meta( $post_id, $key, $single = true ) {
		return $GLOBALS['__meta'][ $post_id ] ?? '';
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-beaver-builder-detector.php';

	use MediaUsageTracker\Scanner\BeaverBuilderDetector;
	use MediaUsageTracker\Scanner\MediaDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	function post( $id, $type = 'page' ) { return (object) array( 'ID' => $id, 'post_type' => $type ); }
	function rec_ids( $s ) { $ids = array_map( fn( $c ) => $c['attachment_id'], $s->calls ); sort( $ids ); return $ids; }

	// A BB node as WP would hand it back: object with ->settings (stdClass).
	function bb_node( $settings ) {
		$n = new stdClass();
		$n->settings = (object) $settings;
		return $n;
	}

	// =========================================================================
	// Interface + availability
	// =========================================================================
	$d = new BeaverBuilderDetector( new UsageStorage() );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: beaver_builder', $d->key() === 'beaver_builder' );
	check( 'available when FL_BUILDER_VERSION defined', $d->is_available() === true );

	// =========================================================================
	// Empty / invalid meta
	// =========================================================================
	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][1] = '';
	check( 'empty meta → 0', $d->detect( post( 1 ), 5 ) === 0 );

	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][2] = 'not-serialized';
	check( 'garbage string → 0', $d->detect( post( 2 ), 5 ) === 0 );

	// =========================================================================
	// Main path: array of objects with ->settings (WP auto-unserialized)
	// =========================================================================
	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][3] = array(
		bb_node( array( 'photo' => 401, 'photo_src' => 'http://s/a.jpg', 'caption' => 'x' ) ),
		bb_node( array( 'photos' => array( 402, 403 ) ) ),                 // gallery
		bb_node( array( 'bg_image' => '404' ) ),                          // row background, numeric string
		bb_node( array( 'video' => 405, 'fallback_photo' => 406 ) ),       // video module + poster
		bb_node( array( 'text' => 'no media here', 'color' => '#fff' ) ),  // non-media settings
		(object) array( 'type' => 'row' ),                                // node with no settings
	);
	$n = $d->detect( post( 3 ), 7 );
	check( 'main: 6 ids recorded', $n === 6, $n );
	check( 'main: ids 401-406', rec_ids( $s ) === array( 401, 402, 403, 404, 405, 406 ), rec_ids( $s ) );
	check( 'main: usage_type beaver_builder', $s->calls[0]['usage_type'] === 'beaver_builder' );
	check( 'main: context label', $s->calls[0]['context'] === 'Beaver Builder' );
	check( 'main: non-media settings ignored', ! in_array( 0, rec_ids( $s ), true ) );

	// =========================================================================
	// Defensive path: serialized STRING of plain arrays (no objects)
	// =========================================================================
	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][4] = serialize( array(
		array( 'settings' => array( 'photo' => 501 ) ),
		array( 'settings' => array( 'photos' => array( 502, 503 ) ) ),
	) );
	$n = $d->detect( post( 4 ), 7 );
	check( 'string-array path: ids 501,502,503', rec_ids( $s ) === array( 501, 502, 503 ), rec_ids( $s ) );

	// =========================================================================
	// Dedup across nodes
	// =========================================================================
	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][5] = array(
		bb_node( array( 'photo' => 600 ) ),
		bb_node( array( 'photos' => array( 600, 601 ) ) ),
	);
	$n = $d->detect( post( 5 ), 7 );
	check( 'dedup: 600 once + 601 → 2', $n === 2 && rec_ids( $s ) === array( 600, 601 ), rec_ids( $s ) );

	// =========================================================================
	// Zero / empty settings skipped
	// =========================================================================
	$s = new UsageStorage(); $d = new BeaverBuilderDetector( $s );
	$GLOBALS['__meta'][6] = array(
		bb_node( array( 'photo' => 0 ) ),
		bb_node( array( 'photos' => array() ) ),
	);
	check( 'zero/empty skipped', $d->detect( post( 6 ), 7 ) === 0 && count( $s->calls ) === 0 );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
