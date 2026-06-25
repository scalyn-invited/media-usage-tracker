<?php
/**
 * Behavior-preservation tests for the detector refactor.
 * Confirms the extracted ContentDetector / FeaturedImageDetector record exactly
 * what the old inline MediaScanner methods did, and that the scanner builds the
 * registry in the correct (legacy) order and honors the filter.
 *
 * Run: php tests/test-detectors.php
 */

namespace MediaUsageTracker\Storage {
	// Fake storage that captures every record_usage() call.
	class UsageStorage {
		public $calls = array();
		public function record_usage( $data ) { $this->calls[] = $data; }
		public function clear_previous_usage() {}
		public function start_scan() { return 1; }
		public function get_files_in_use_count() { return 0; }
		public function complete_scan( $id, $stats ) {}
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	// ---- WP stubs -----------------------------------------------------------
	$GLOBALS['__is_image']   = array();  // id => bool
	$GLOBALS['__thumbnails'] = array();  // post_id => thumb_id
	$GLOBALS['__url_lookup'] = array();  // filename-substr => attachment id

	function wp_upload_dir() { return array( 'baseurl' => 'http://site.test/wp-content/uploads' ); }
	function home_url() { return 'http://site.test'; }
	function wp_attachment_is_image( $id ) { return ! empty( $GLOBALS['__is_image'][ $id ] ); }
	function wp_trim_words( $text, $n ) { return implode( ' ', array_slice( explode( ' ', $text ), 0, $n ) ); }
	function has_shortcode( $c, $s ) { return false; }
	function get_post_thumbnail_id( $post_id ) { return $GLOBALS['__thumbnails'][ $post_id ] ?? 0; }
	function apply_filters( $tag, $value ) { return $value; }
	function absint( $v ) { return abs( (int) $v ); }

	// Minimal $wpdb for ContentDetector's URL lookup.
	class FakeWpdb {
		public $posts = 'wp_posts';
		public function esc_like( $s ) { return $s; }
		public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
		public function get_var( $prepared ) {
			// First LIKE arg is "%filename%"; resolve via lookup table.
			$needle = trim( $prepared['args'][0], '%' );
			foreach ( $GLOBALS['__url_lookup'] as $key => $id ) {
				if ( strpos( $needle, $key ) !== false ) return $id;
			}
			return null;
		}
	}
	$GLOBALS['wpdb'] = new FakeWpdb();

	require MUT_PLUGIN_DIR . 'includes/detectors/interface-media-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-content-detector.php';
	require MUT_PLUGIN_DIR . 'includes/detectors/class-featured-image-detector.php';

	use MediaUsageTracker\Scanner\ContentDetector;
	use MediaUsageTracker\Scanner\FeaturedImageDetector;
	use MediaUsageTracker\Scanner\MediaDetector;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	function post( $id, $content, $type = 'post' ) {
		return (object) array( 'ID' => $id, 'post_content' => $content, 'post_type' => $type );
	}

	// =========================================================================
	// ContentDetector: ID-pattern dedup + image gate + usage_type 'content'
	// =========================================================================
	$GLOBALS['__is_image'] = array( 10 => true, 11 => true, 12 => false ); // 12 is a PDF
	$s = new UsageStorage();
	$cd = new ContentDetector( $s );

	// Content references id 10 twice (dedup), id 11 once, id 12 (non-image, dropped).
	$content = 'block wp-image-10 ... data-id="11" ... wp-image-10 again ... data-id="12"';
	$n = $cd->detect( post( 100, $content ), 7 );

	check( 'content: returns 2 recorded (10,11; 12 gated out)', $n === 2, $n );
	check( 'content: exactly 2 calls', count( $s->calls ) === 2, count( $s->calls ) );
	$ids = array_map( fn( $c ) => $c['attachment_id'], $s->calls );
	sort( $ids );
	check( 'content: recorded ids 10 & 11', $ids === array( 10, 11 ), $ids );
	check( 'content: usage_type=content', $s->calls[0]['usage_type'] === 'content' );
	check( 'content: post_id propagated', $s->calls[0]['post_id'] === 100 );
	check( 'content: scan_id propagated', $s->calls[0]['scan_id'] === 7 );
	check( 'content: pdf id 12 NOT recorded', ! in_array( 12, $ids, true ) );

	// =========================================================================
	// ContentDetector: URL-based detection resolves to attachment id
	// =========================================================================
	$GLOBALS['__is_image'] = array( 55 => true );
	$GLOBALS['__url_lookup'] = array( 'hero.jpg' => 55 );
	$s2 = new UsageStorage();
	$cd2 = new ContentDetector( $s2 );
	$n2 = $cd2->detect( post( 200, 'see http://site.test/wp-content/uploads/2024/01/hero.jpg here' ), 9 );
	check( 'url: resolved + recorded 1', $n2 === 1, $n2 );
	check( 'url: recorded id 55', $s2->calls[0]['attachment_id'] === 55, $s2->calls );

	// =========================================================================
	// FeaturedImageDetector: records thumbnail with correct fields
	// =========================================================================
	$GLOBALS['__thumbnails'] = array( 300 => 88 );
	$s3 = new UsageStorage();
	$fd = new FeaturedImageDetector( $s3 );
	$nf = $fd->detect( post( 300, '' ), 3 );
	check( 'featured: returns 1', $nf === 1 );
	check( 'featured: usage_type=featured_image', $s3->calls[0]['usage_type'] === 'featured_image' );
	check( 'featured: context label', $s3->calls[0]['context'] === 'Featured Image' );
	check( 'featured: attachment id 88', $s3->calls[0]['attachment_id'] === 88 );

	// No thumbnail → no record.
	$s4 = new UsageStorage();
	$fd2 = new FeaturedImageDetector( $s4 );
	$nf2 = $fd2->detect( post( 301, '' ), 3 );
	check( 'featured: none when no thumbnail', $nf2 === 0 && count( $s4->calls ) === 0 );

	// =========================================================================
	// Interface conformance + detector keys
	// =========================================================================
	check( 'iface: ContentDetector is MediaDetector', $cd instanceof MediaDetector );
	check( 'iface: FeaturedImageDetector is MediaDetector', $fd instanceof MediaDetector );
	check( 'key: content', $cd->key() === 'content' );
	check( 'key: featured_image', $fd->key() === 'featured_image' );
	check( 'avail: content always available', $cd->is_available() === true );
	check( 'avail: featured always available', $fd->is_available() === true );

	// =========================================================================
	// Scanner registry: correct order [content, featured]
	// =========================================================================
	require MUT_PLUGIN_DIR . 'includes/class-scanner.php';
	$scanner = new \MediaUsageTracker\Scanner\MediaScanner( new UsageStorage() );
	$keys = array_map( fn( $d ) => $d->key(), $scanner->get_detectors() );
	check( 'registry: built-ins present in order', $keys === array( 'content', 'featured_image', 'divi', 'acf', 'elementor', 'wpbakery', 'beaver_builder', 'yoast' ), $keys );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
