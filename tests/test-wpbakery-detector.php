<?php
/**
 * Tests for WpBakeryDetector.
 * Run: php tests/test-wpbakery-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'WPB_VC_VERSION', '6.0' );

	function absint( $v ) { return abs( (int) $v ); }

	$GLOBALS['__url_lookup'] = array();
	function attachment_url_to_postid( $url ) {
		foreach ( $GLOBALS['__url_lookup'] as $needle => $id ) {
			if ( strpos( $url, $needle ) !== false ) return $id;
		}
		return 0;
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-wpbakery-detector.php';

	use MediaUsageTracker\Scanner\WpBakeryDetector;
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
	function rec_ids( $s ) { $ids = array_map( fn( $c ) => $c['attachment_id'], $s->calls ); sort( $ids ); return $ids; }

	// =========================================================================
	// Interface + availability
	// =========================================================================
	$d = new WpBakeryDetector( new UsageStorage() );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: wpbakery', $d->key() === 'wpbakery' );
	check( 'available when WPB_VC_VERSION defined', $d->is_available() === true );

	// =========================================================================
	// Non-WPBakery content ignored
	// =========================================================================
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	check( 'non-vc content ignored', $d->detect( post( 1, '<p>plain wp-image-9</p>' ), 5 ) === 0 );

	// =========================================================================
	// Single image attribute
	// =========================================================================
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	$n = $d->detect( post( 2, '[vc_single_image image="123" img_size="large"]' ), 5 );
	check( 'single: image="123" recorded', $n === 1 && rec_ids( $s ) === array( 123 ), rec_ids( $s ) );
	check( 'single: usage_type wpbakery', $s->calls[0]['usage_type'] === 'wpbakery' );
	check( 'single: context WPBakery', $s->calls[0]['context'] === 'WPBakery' );

	// =========================================================================
	// Gallery images list
	// =========================================================================
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	$n = $d->detect( post( 3, '[vc_gallery type="flexslider" images="11,12,13" /]' ), 5 );
	check( 'gallery: 3 recorded', $n === 3, $n );
	check( 'gallery: ids 11,12,13', rec_ids( $s ) === array( 11, 12, 13 ), rec_ids( $s ) );

	// =========================================================================
	// URL attribute resolved (incl non-image)
	// =========================================================================
	$GLOBALS['__url_lookup'] = array( 'banner.png' => 50, 'doc.pdf' => 51 );
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	$content =
		'[vc_row background_image="http://s/wp-content/uploads/banner.png"]' .
		'[vc_btn link="url:http%3A%2F%2Fexternal" source="http://s/uploads/doc.pdf"]';
	$n = $d->detect( post( 4, $content ), 5 );
	check( 'url: banner 50 + pdf 51 resolved', rec_ids( $s ) === array( 50, 51 ), rec_ids( $s ) );

	// =========================================================================
	// Non-URL source value ignored (source="external_link")
	// =========================================================================
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	$n = $d->detect( post( 5, '[vc_single_image source="external_link" image="77"]' ), 5 );
	check( 'non-url source ignored, image kept', rec_ids( $s ) === array( 77 ), rec_ids( $s ) );

	// =========================================================================
	// Dedup: same id via image + gallery
	// =========================================================================
	$s = new UsageStorage(); $d = new WpBakeryDetector( $s );
	$n = $d->detect( post( 6, '[vc_single_image image="90"][vc_gallery images="90,91"]' ), 5 );
	check( 'dedup: 90 once + 91 → 2', $n === 2 && rec_ids( $s ) === array( 90, 91 ), rec_ids( $s ) );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
