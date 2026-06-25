<?php
/**
 * Tests for JetEngine and JetPopup detectors.
 * Run: php tests/test-jet-detectors.php
 */

namespace MediaUsageTracker\Scanner {
	interface MediaDetector {
		public function key();
		public function is_available();
		public function detect( $post, $scan_id );
	}
}

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $recorded = array();
		public function record_usage( $data ) {
			$this->recorded[] = $data;
		}
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	// WordPress stubs
	function get_posts( $args = array() ) { return array(); }
	function get_post_type( $id ) { return 'attachment'; }
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $_fake_postmeta;
		if ( $key === '' ) {
			// Return all meta as arrays of values (WP format).
			$all = $_fake_postmeta[ $post_id ] ?? array();
			return array_map( fn( $v ) => array( $v ), $all );
		}
		$val = $_fake_postmeta[ $post_id ][ $key ] ?? '';
		return $single ? $val : ( $val !== '' ? array( $val ) : array() );
	}
	function get_option( $key, $default = array() ) {
		global $_fake_options;
		return $_fake_options[ $key ] ?? $default;
	}
	function absint( $v ) { return abs( (int) $v ); }
	function is_serialized( $v ) { return is_string( $v ) && @unserialize( $v ) !== false && $v !== 'b:0;'; }
	function maybe_unserialize( $v ) { return is_serialized( $v ) ? unserialize( $v ) : $v; }


	// Fake post object helper
	function make_post( $id, $type = 'post' ) {
		$p = new stdClass();
		$p->ID        = $id;
		$p->post_type = $type;
		return $p;
	}

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label,
			( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/class-jetengine-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-jetpopup-detector.php';

	use MediaUsageTracker\Scanner\JetEngineDetector;
	use MediaUsageTracker\Scanner\JetPopupDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	// =========================================================================
	// JetEngineDetector
	// =========================================================================
	echo "=== JetEngineDetector ===\n";

	// Register a fake meta box with media + gallery fields
	$_fake_options['jet_engine_meta_boxes'] = array(
		array(
			'meta_fields' => array(
				array( 'type' => 'media',   'name' => 'hero_image',    'title' => 'Hero Image' ),
				array( 'type' => 'gallery', 'name' => 'photo_gallery', 'title' => 'Photo Gallery' ),
				array( 'type' => 'text',    'name' => 'headline',      'title' => 'Headline' ),
			),
		),
	);

	// Post 10: single media field (numeric string)
	$_fake_postmeta[10]['hero_image']    = '42';
	$_fake_postmeta[10]['photo_gallery'] = '';

	// Post 11: gallery as JSON array of IDs
	$_fake_postmeta[11]['hero_image']    = '';
	$_fake_postmeta[11]['photo_gallery'] = json_encode( array( 55, 56, 57 ) );

	// Post 12: gallery as JSON array of {id,url} objects
	$_fake_postmeta[12]['hero_image']    = '';
	$_fake_postmeta[12]['photo_gallery'] = json_encode( array(
		array( 'id' => 88, 'url' => 'https://example.com/img.jpg' ),
		array( 'id' => 89, 'url' => 'https://example.com/img2.jpg' ),
	) );

	// Post 13: both fields, same ID → deduplication
	$_fake_postmeta[13]['hero_image']    = '99';
	$_fake_postmeta[13]['photo_gallery'] = json_encode( array( 99, 100 ) );

	// Post 14: no media fields set
	$_fake_postmeta[14]['hero_image']    = '';
	$_fake_postmeta[14]['photo_gallery'] = '';

	$storage = new UsageStorage();
	$det     = new JetEngineDetector( $storage );

	check( 'jetengine: key', $det->key() === 'jetengine' );

	$storage->recorded = array();
	$n = $det->detect( make_post( 10 ), 1 );
	check( 'jetengine: single media field → 1 record', $n === 1, $n );
	check( 'jetengine: correct attachment_id', $storage->recorded[0]['attachment_id'] === 42 );
	check( 'jetengine: context label', strpos( $storage->recorded[0]['context'], 'Hero Image' ) !== false );

	$storage->recorded = array();
	$n = $det->detect( make_post( 11 ), 1 );
	check( 'jetengine: gallery JSON array → 3 records', $n === 3, $n );
	$recorded_ids = array_column( $storage->recorded, 'attachment_id' );
	check( 'jetengine: gallery IDs correct', $recorded_ids === array( 55, 56, 57 ), $recorded_ids );

	$storage->recorded = array();
	$n = $det->detect( make_post( 12 ), 1 );
	check( 'jetengine: gallery {id,url} objects → 2 records', $n === 2, $n );
	$recorded_ids = array_column( $storage->recorded, 'attachment_id' );
	check( 'jetengine: gallery object IDs correct', $recorded_ids === array( 88, 89 ), $recorded_ids );

	$storage->recorded = array();
	$n = $det->detect( make_post( 13 ), 1 );
	check( 'jetengine: deduplication across fields → 2 unique IDs', $n === 2, $n );
	$recorded_ids = array_column( $storage->recorded, 'attachment_id' );
	check( 'jetengine: dedup IDs are 99 and 100', in_array( 99, array_column( $storage->recorded, 'attachment_id' ) ) && in_array( 100, array_column( $storage->recorded, 'attachment_id' ) ) );

	$storage->recorded = array();
	$n = $det->detect( make_post( 14 ), 1 );
	check( 'jetengine: no fields → 0 records', $n === 0, $n );

	// usage_type must be 'jetengine'
	$storage->recorded = array();
	$det->detect( make_post( 10 ), 1 );
	check( 'jetengine: usage_type = jetengine', $storage->recorded[0]['usage_type'] === 'jetengine' );

	// No meta boxes registered → fallback scans public postmeta → still finds the attachment
	$_fake_options['jet_engine_meta_boxes'] = array();
	// Reset static cache via reflection
	$ref  = new ReflectionClass( JetEngineDetector::class );
	$prop = $ref->getProperty( 'media_fields' );
	$prop->setAccessible( true );
	$prop->setValue( null, null );
	$s2   = new UsageStorage();
	$det2 = new JetEngineDetector( $s2 );
	$prop->setValue( null, null );
	$n = $det2->detect( make_post( 10 ), 1 );
	check( 'jetengine: no meta boxes → fallback finds attachment via postmeta', $n === 1, $n );

	// =========================================================================
	// JetPopupDetector
	// =========================================================================
	echo "\n=== JetPopupDetector ===\n";

	// Reset postmeta
	$_fake_postmeta = array();

	// Post 20: popup post with background image (Elementor-style {id,url})
	$_fake_postmeta[20]['_jet_popup_settings'] = json_encode( array(
		'background_image' => array( 'id' => 201, 'url' => 'https://example.com/bg.jpg' ),
	) );

	// Post 21: close icon as direct attachment_id key
	$_fake_postmeta[21]['_jet_popup_settings'] = json_encode( array(
		'close_icon' => array( 'attachment_id' => 202 ),
	) );

	// Post 22: multiple images nested in settings
	$_fake_postmeta[22]['_jet_popup_settings'] = json_encode( array(
		'background_image' => array( 'id' => 301, 'url' => 'https://example.com/a.jpg' ),
		'overlay'          => array(
			'bg' => array( 'id' => 302, 'url' => 'https://example.com/b.jpg' ),
		),
	) );

	// Post 23: slashed JSON (some WP installs)
	$_fake_postmeta[23]['_jet_popup_settings'] = addslashes( json_encode( array(
		'background_image' => array( 'id' => 401, 'url' => 'https://example.com/c.jpg' ),
	) ) );

	// Post 24: no popup settings
	$_fake_postmeta[24]['_jet_popup_settings'] = '';

	$storage = new UsageStorage();
	$pop     = new JetPopupDetector( $storage );

	check( 'jetpopup: key', $pop->key() === 'jetpopup' );

	$storage->recorded = array();
	$n = $pop->detect( make_post( 20, 'jet-popup' ), 1 );
	check( 'jetpopup: background image → 1 record', $n === 1, $n );
	check( 'jetpopup: correct attachment_id', $storage->recorded[0]['attachment_id'] === 201 );
	check( 'jetpopup: usage_type = jetpopup', $storage->recorded[0]['usage_type'] === 'jetpopup' );
	check( 'jetpopup: context mentions JetPopup', strpos( $storage->recorded[0]['context'], 'JetPopup' ) !== false );

	$storage->recorded = array();
	$n = $pop->detect( make_post( 21, 'jet-popup' ), 1 );
	check( 'jetpopup: direct attachment_id key → 1 record', $n === 1, $n );
	check( 'jetpopup: close icon ID correct', $storage->recorded[0]['attachment_id'] === 202 );

	$storage->recorded = array();
	$n = $pop->detect( make_post( 22, 'jet-popup' ), 1 );
	check( 'jetpopup: nested images → 2 records', $n === 2, $n );
	$recorded_ids = array_column( $storage->recorded, 'attachment_id' );
	check( 'jetpopup: nested IDs correct', in_array( 301, $recorded_ids ) && in_array( 302, $recorded_ids ), $recorded_ids );

	$storage->recorded = array();
	$n = $pop->detect( make_post( 23, 'jet-popup' ), 1 );
	check( 'jetpopup: slashed JSON decoded → 1 record', $n === 1, $n );
	check( 'jetpopup: slashed ID correct', $storage->recorded[0]['attachment_id'] === 401 );

	$storage->recorded = array();
	$n = $pop->detect( make_post( 24, 'jet-popup' ), 1 );
	check( 'jetpopup: empty settings → 0 records', $n === 0, $n );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
