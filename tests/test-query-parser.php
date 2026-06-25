<?php
/**
 * Standalone tests for QueryParser. WP-independent.
 * Run: php tests/test-query-parser.php
 */

namespace MediaUsageTracker\Admin {
	// no WP needed
}

namespace {
	require dirname( __DIR__ ) . '/includes/class-query-parser.php';
	use MediaUsageTracker\Admin\QueryParser;

	$p = new QueryParser();
	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? "  (got: " . json_encode( $got ) . ")" : '' );
		$cond ? $pass++ : $fail++;
	}

	// =========================================================================
	// Example 1: "Show large unused images"
	// =========================================================================
	$r = $p->parse( 'Show large unused images' );
	$f = $r['filters'];
	check( 'ex1: usage_status unused', $f['usage_status'] === 'unused', $f );
	check( 'ex1: media_type image',    $f['media_type'] === 'image', $f );
	check( 'ex1: size large',          $f['size'] === 'large', $f );
	check( 'ex1: no leftover search',  $f['s'] === '', $f );

	// =========================================================================
	// Example 2: "Find unused PDFs"
	// =========================================================================
	$r = $p->parse( 'Find unused PDFs' );
	$f = $r['filters'];
	check( 'ex2: usage_status unused',         $f['usage_status'] === 'unused', $f );
	check( 'ex2: media_type application/pdf',  $f['media_type'] === 'application/pdf', $f );
	check( 'ex2: no leftover',                 $f['s'] === '', $f );

	// =========================================================================
	// Example 3: "Show images not used in the last year"
	// =========================================================================
	$r = $p->parse( 'Show images not used in the last year' );
	$f = $r['filters'];
	check( 'ex3: usage_status unused',     $f['usage_status'] === 'unused', $f );
	check( 'ex3: media_type image',        $f['media_type'] === 'image', $f );
	check( 'ex3: older_than_days 365',     $f['older_than_days'] === 365, $f );
	check( 'ex3: date_range NOT set',      $f['date_range'] === '', $f );
	check( 'ex3: no leftover',             $f['s'] === '', $f );

	// =========================================================================
	// "not used in the last 6 months"
	// =========================================================================
	$r = $p->parse( 'pdfs not used in the last 6 months' );
	$f = $r['filters'];
	check( 'older: 6 months = 180 days', $f['older_than_days'] === 180, $f );
	check( 'older: media pdf',           $f['media_type'] === 'application/pdf', $f );

	// =========================================================================
	// Plain date range: "images uploaded in the last 30 days"
	// =========================================================================
	$r = $p->parse( 'images uploaded in the last 30 days' );
	$f = $r['filters'];
	check( 'range: date_range 30days', $f['date_range'] === '30days', $f );
	check( 'range: older_than not set', $f['older_than_days'] === 0, $f );

	// "last week" → 7days
	$r = $p->parse( 'videos from the last week' );
	check( 'range: last week = 7days', $r['filters']['date_range'] === '7days', $r['filters'] );

	// "today"
	$r = $p->parse( 'images uploaded today' );
	check( 'range: today', $r['filters']['date_range'] === 'today', $r['filters'] );

	// =========================================================================
	// Explicit size threshold
	// =========================================================================
	$r = $p->parse( 'unused images over 5 MB' );
	$f = $r['filters'];
	check( 'size: over 5MB = 5242880 bytes', $f['min_size_bytes'] === 5242880, $f );
	check( 'size: large not also set',       $f['size'] === '', $f );

	$r = $p->parse( 'videos bigger than 2gb' );
	check( 'size: 2gb = 2147483648', $r['filters']['min_size_bytes'] === 2147483648, $r['filters'] );

	// "small"
	$r = $p->parse( 'small unused images' );
	check( 'size: small', $r['filters']['size'] === 'small', $r['filters'] );

	// =========================================================================
	// Synonyms
	// =========================================================================
	check( 'syn: photos = image',     $p->parse('orphaned photos')['filters']['media_type'] === 'image' );
	check( 'syn: orphaned = unused',  $p->parse('orphaned photos')['filters']['usage_status'] === 'unused' );
	check( 'syn: docs = pdf',         $p->parse('big docs')['filters']['media_type'] === 'application/pdf' );
	check( 'syn: movies = video',     $p->parse('unused movies')['filters']['media_type'] === 'video' );
	check( 'syn: mp3 = audio',        $p->parse('unused mp3s')['filters']['media_type'] === 'audio' );
	check( 'syn: in use = used',      $p->parse('images in use')['filters']['usage_status'] === 'used' );

	// =========================================================================
	// Leftover free-text: "unused images named hero"
	// =========================================================================
	$r = $p->parse( 'unused images hero-banner' );
	check( 'leftover: keeps hero-banner as search', $r['filters']['s'] === 'hero-banner', $r['filters'] );
	check( 'leftover: still parses unused', $r['filters']['usage_status'] === 'unused' );
	check( 'leftover: still parses image',  $r['filters']['media_type'] === 'image' );

	// Stopwords stripped, nothing left
	$r = $p->parse( 'show me all the unused files' );
	check( 'stopwords: nothing leftover', $r['filters']['s'] === '', $r['filters'] );

	// =========================================================================
	// Interpretation string is populated
	// =========================================================================
	$r = $p->parse( 'Show large unused images' );
	check( 'interp: non-empty', $r['interpreted'] !== '' );
	check( 'interp: contains Unused', strpos( $r['interpreted'], 'Unused' ) !== false, $r['interpreted'] );
	check( 'interp: contains Large',  strpos( $r['interpreted'], 'Large' ) !== false, $r['interpreted'] );

	// Empty query
	$r = $p->parse( '' );
	check( 'empty: no filters set', $r['filters']['usage_status'] === '' && $r['filters']['s'] === '' );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
