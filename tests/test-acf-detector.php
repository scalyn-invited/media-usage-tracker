<?php
/**
 * Tests for AcfDetector.
 * Run: php tests/test-acf-detector.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	function absint( $v ) { return abs( (int) $v ); }

	// Stub ACF API: return whatever fixture the test set for a given post id.
	$GLOBALS['__acf_fields'] = array(); // post_id => fields array
	function get_field_objects( $post_id, $format = true ) {
		return $GLOBALS['__acf_fields'][ $post_id ] ?? array();
	}

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-acf-detector.php';

	use MediaUsageTracker\Scanner\AcfDetector;
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

	// =========================================================================
	// Interface + availability
	// =========================================================================
	$d = new AcfDetector( new UsageStorage() );
	check( 'iface: is MediaDetector', $d instanceof MediaDetector );
	check( 'key: acf', $d->key() === 'acf' );
	check( 'available when get_field_objects exists', $d->is_available() === true );

	// =========================================================================
	// No fields → nothing
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][1] = array();
	check( 'no fields → 0', $d->detect( post( 1 ), 5 ) === 0 && count( $s->calls ) === 0 );

	// =========================================================================
	// Image field (raw value = ID), File field (PDF), ignore text field
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][2] = array(
		'hero'    => array( 'type' => 'image', 'label' => 'Hero', 'value' => 41 ),
		'spec'    => array( 'type' => 'file',  'label' => 'Spec Sheet', 'value' => '42' ), // numeric string, PDF
		'heading' => array( 'type' => 'text',  'label' => 'Heading', 'value' => 'About us' ),
	);
	$n = $d->detect( post( 2 ), 5 );
	check( 'image+file recorded, text ignored', $n === 2, $n );
	check( 'ids 41 & 42', rec_ids( $s ) === array( 41, 42 ), rec_ids( $s ) );
	check( 'usage_type acf', $s->calls[0]['usage_type'] === 'acf' );
	check( 'context carries field label', strpos( $s->calls[0]['context'], 'ACF: ' ) === 0 );

	// =========================================================================
	// Gallery field (raw value = array of IDs)
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][3] = array(
		'shots' => array( 'type' => 'gallery', 'label' => 'Shots', 'value' => array( 7, 8, 9 ) ),
	);
	$n = $d->detect( post( 3 ), 5 );
	check( 'gallery: 3 recorded', $n === 3, $n );
	check( 'gallery: ids 7,8,9', rec_ids( $s ) === array( 7, 8, 9 ), rec_ids( $s ) );

	// =========================================================================
	// Defensive: partially-formatted value carrying its own ID key
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][4] = array(
		'pic' => array( 'type' => 'image', 'label' => 'Pic', 'value' => array( 'ID' => 55, 'url' => 'x' ) ),
	);
	$n = $d->detect( post( 4 ), 5 );
	check( 'assoc value ID key resolved to 55', rec_ids( $s ) === array( 55 ), rec_ids( $s ) );

	// =========================================================================
	// Dedup across fields
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][5] = array(
		'a' => array( 'type' => 'image',   'label' => 'A', 'value' => 60 ),
		'b' => array( 'type' => 'gallery', 'label' => 'B', 'value' => array( 60, 61 ) ),
	);
	$n = $d->detect( post( 5 ), 5 );
	check( 'dedup: 60 once + 61 → 2 records', $n === 2 && rec_ids( $s ) === array( 60, 61 ), rec_ids( $s ) );

	// =========================================================================
	// Empty/zero values skipped
	// =========================================================================
	$s = new UsageStorage();
	$d = new AcfDetector( $s );
	$GLOBALS['__acf_fields'][6] = array(
		'empty1' => array( 'type' => 'image',   'label' => 'E1', 'value' => 0 ),
		'empty2' => array( 'type' => 'image',   'label' => 'E2', 'value' => '' ),
		'empty3' => array( 'type' => 'gallery', 'label' => 'E3', 'value' => array() ),
	);
	check( 'empty values skipped', $d->detect( post( 6 ), 5 ) === 0 && count( $s->calls ) === 0 );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
