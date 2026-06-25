<?php
/**
 * Tests for ElementorDetector.
 * Run: php tests/test-elementor-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'ELEMENTOR_VERSION', '3.0' );

	function absint( $v ) { return abs( (int) $v ); }

	$GLOBALS['__meta'] = array(); // post_id => raw _elementor_data string
	function get_post_meta( $post_id, $key, $single = true ) {
		return $GLOBALS['__meta'][ $post_id ] ?? '';
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-elementor-detector.php';

	use MediaUsageTracker\Scanner\ElementorDetector;
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

	// A realistic Elementor tree: section (with bg image) → column → widgets.
	$tree = array(
		array(
			'id'       => 'a1b2c3',          // element hash — non-numeric, must be ignored
			'elType'   => 'section',
			'settings' => array(
				'background_image' => array( 'id' => 102, 'url' => 'http://s/bg.jpg' ),
			),
			'elements' => array(
				array(
					'id'       => 'd4e5f6',
					'elType'   => 'column',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => '99aa11',
							'elType'     => 'widget',
							'widgetType' => 'image',
							'settings'   => array(
								'image' => array( 'id' => 101, 'url' => 'http://s/hero.jpg' ),
							),
						),
						array(
							'id'         => 'bb22cc',
							'elType'     => 'widget',
							'widgetType' => 'image-gallery',
							'settings'   => array(
								'gallery' => array(
									array( 'id' => 103, 'url' => 'http://s/g1.jpg' ),
									array( 'id' => 104, 'url' => 'http://s/g2.jpg' ),
								),
							),
						),
						array(
							'id'         => 'cc33dd',
							'elType'     => 'widget',
							'widgetType' => 'video',
							'settings'   => array(
								// self-hosted video: non-image attachment, has source
								'hosted_url' => array( 'id' => 106, 'url' => 'http://s/promo.mp4', 'source' => 'library' ),
							),
						),
						array(
							'id'         => '12345',   // numeric-looking hash but NO url → must be ignored
							'elType'     => 'widget',
							'widgetType' => 'spacer',
							'settings'   => array( 'space' => array( 'unit' => 'px', 'size' => 50 ) ),
						),
					),
				),
			),
		),
	);

	// =========================================================================
	// Interface + availability
	// =========================================================================
	$d = new ElementorDetector( new UsageStorage() );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: elementor', $d->key() === 'elementor' );
	check( 'available when ELEMENTOR_VERSION defined', $d->is_available() === true );

	// =========================================================================
	// Empty / invalid meta
	// =========================================================================
	$s = new UsageStorage(); $d = new ElementorDetector( $s );
	$GLOBALS['__meta'][1] = '';
	check( 'empty meta → 0', $d->detect( post( 1 ), 5 ) === 0 );

	$s = new UsageStorage(); $d = new ElementorDetector( $s );
	$GLOBALS['__meta'][2] = 'not-json{';
	check( 'invalid json → 0', $d->detect( post( 2 ), 5 ) === 0 );

	// =========================================================================
	// Full tree
	// =========================================================================
	$s = new UsageStorage(); $d = new ElementorDetector( $s );
	$GLOBALS['__meta'][3] = json_encode( $tree );
	$n = $d->detect( post( 3 ), 7 );

	check( 'tree: 5 media ids collected', $n === 5, $n );
	check( 'tree: ids 101,102,103,104,106', rec_ids( $s ) === array( 101, 102, 103, 104, 106 ), rec_ids( $s ) );
	check( 'tree: element hashes ignored (no 0/garbage)', ! in_array( 0, rec_ids( $s ), true ) );
	check( 'tree: numeric-id-without-url ignored (no 12345)', ! in_array( 12345, rec_ids( $s ), true ) );
	check( 'tree: video (non-image) 106 captured via source', in_array( 106, rec_ids( $s ), true ) );
	check( 'tree: usage_type elementor', $s->calls[0]['usage_type'] === 'elementor' );
	check( 'tree: context Elementor', $s->calls[0]['context'] === 'Elementor' );

	// =========================================================================
	// Slashed JSON retry path
	// =========================================================================
	$s = new UsageStorage(); $d = new ElementorDetector( $s );
	$GLOBALS['__meta'][4] = addslashes( json_encode( array(
		array( 'id' => 'x1', 'settings' => array( 'image' => array( 'id' => 201, 'url' => 'http://s/a.jpg' ) ) ),
	) ) );
	$n = $d->detect( post( 4 ), 7 );
	check( 'slashed json: recovered + recorded 201', rec_ids( $s ) === array( 201 ), rec_ids( $s ) );

	// =========================================================================
	// Dedup: same id used twice
	// =========================================================================
	$s = new UsageStorage(); $d = new ElementorDetector( $s );
	$GLOBALS['__meta'][5] = json_encode( array(
		array( 'id' => 'x', 'settings' => array( 'image' => array( 'id' => 300, 'url' => 'u' ) ),
			'elements' => array(
				array( 'id' => 'y', 'settings' => array( 'background_image' => array( 'id' => 300, 'url' => 'u' ) ) ),
			),
		),
	) );
	$n = $d->detect( post( 5 ), 7 );
	check( 'dedup: id 300 once', $n === 1 && rec_ids( $s ) === array( 300 ), $n );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
