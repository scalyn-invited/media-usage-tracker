<?php
/**
 * Tests for DiviDetector.
 * Run: php tests/test-divi-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'ET_BUILDER_VERSION', '4.0' ); // make Divi "active" for these tests

	function absint( $v ) { return abs( (int) $v ); }

	// Resolve a URL to an attachment id via a lookup table keyed by filename.
	$GLOBALS['__url_lookup'] = array();
	function attachment_url_to_postid( $url ) {
		foreach ( $GLOBALS['__url_lookup'] as $needle => $id ) {
			if ( strpos( $url, $needle ) !== false ) return $id;
		}
		return 0;
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-divi-detector.php';

	use MediaUsageTracker\Scanner\DiviDetector;
	use MediaUsageTracker\Scanner\MediaDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	function post( $id, $content, $type = 'page' ) {
		return (object) array( 'ID' => $id, 'post_content' => $content, 'post_type' => $type );
	}
	function recorded_ids( $s ) { $ids = array_map( fn( $c ) => $c['attachment_id'], $s->calls ); sort( $ids ); return $ids; }

	// =========================================================================
	// Availability gate
	// =========================================================================
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: divi', $d->key() === 'divi' );
	check( 'available when ET_BUILDER_VERSION defined', $d->is_available() === true );

	// =========================================================================
	// Non-Divi content → nothing
	// =========================================================================
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	$n = $d->detect( post( 1, '<p>plain gutenberg wp-image-99</p>' ), 5 );
	check( 'non-divi content ignored', $n === 0 && count( $s->calls ) === 0 );

	// =========================================================================
	// Gallery ids
	// =========================================================================
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	$content = '[et_pb_gallery gallery_ids="12,15,20" /]';
	$n = $d->detect( post( 2, $content ), 5 );
	check( 'gallery: 3 recorded', $n === 3, $n );
	check( 'gallery: ids 12,15,20', recorded_ids( $s ) === array( 12, 15, 20 ), recorded_ids( $s ) );
	check( 'gallery: usage_type divi', $s->calls[0]['usage_type'] === 'divi' );
	check( 'gallery: context label', $s->calls[0]['context'] === 'Divi Builder' );

	// =========================================================================
	// Numeric image_id
	// =========================================================================
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	$n = $d->detect( post( 3, '[et_pb_image image_id="77" src="x"]' ), 5 );
	check( 'image_id 77 recorded', in_array( 77, recorded_ids( $s ), true ), recorded_ids( $s ) );

	// =========================================================================
	// URL attributes resolved (incl. non-image: PDF & video)
	// =========================================================================
	$GLOBALS['__url_lookup'] = array(
		'hero.jpg'    => 101,
		'bg.png'      => 102,
		'promo.mp4'   => 103,  // video — must still be recorded
		'brochure.pdf'=> 104,  // pdf — must still be recorded
	);
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	$content =
		'[et_pb_image src="http://site.test/wp-content/uploads/hero.jpg"]' .
		'[et_pb_section background_image="http://site.test/wp-content/uploads/bg.png"]' .
		'[et_pb_video src="http://site.test/wp-content/uploads/promo.mp4"]' .
		'[et_pb_button url="http://site.test/wp-content/uploads/brochure.pdf"]';
	$n = $d->detect( post( 4, $content ), 5 );
	check( 'url: 4 distinct resolved', $n === 4, $n );
	check( 'url: includes video 103 (non-image)', in_array( 103, recorded_ids( $s ), true ) );
	check( 'url: includes pdf 104 (non-image)', in_array( 104, recorded_ids( $s ), true ) );

	// =========================================================================
	// Dedup: same attachment via gallery + url
	// =========================================================================
	$GLOBALS['__url_lookup'] = array( 'shared.jpg' => 200 );
	$s = new UsageStorage();
	$d = new DiviDetector( $s );
	$content = '[et_pb_gallery gallery_ids="200" /][et_pb_image src="http://site.test/uploads/shared.jpg"]';
	$n = $d->detect( post( 5, $content ), 5 );
	check( 'dedup: recorded once', $n === 1 && count( $s->calls ) === 1, $n );
	check( 'dedup: id 200', $s->calls[0]['attachment_id'] === 200 );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
