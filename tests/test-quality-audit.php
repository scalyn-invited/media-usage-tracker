<?php
/**
 * Tests for the Media Quality Audit module (checks + auditor aggregation).
 * Run: php tests/test-quality-audit.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );

	function apply_filters( $tag, $value ) { return $value; }
	function get_transient( $k ) { return false; }
	function set_transient( $k, $v, $t ) { return true; }
	function delete_transient( $k ) { return true; }

	$dir = MUT_PLUGIN_DIR . 'includes/quality/';
	require $dir . 'interface-quality-check.php';
	require $dir . 'class-alt-text-check.php';
	require $dir . 'class-caption-check.php';
	require $dir . 'class-description-check.php';
	require $dir . 'class-oversized-image-check.php';
	require $dir . 'class-unsupported-format-check.php';
	require $dir . 'class-webp-recommendation-check.php';

	use MediaUsageTracker\Quality\AltTextCheck;
	use MediaUsageTracker\Quality\CaptionCheck;
	use MediaUsageTracker\Quality\DescriptionCheck;
	use MediaUsageTracker\Quality\OversizedImageCheck;
	use MediaUsageTracker\Quality\UnsupportedFormatCheck;
	use MediaUsageTracker\Quality\WebPRecommendationCheck;
	use MediaUsageTracker\Quality\QualityCheck;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	// Build a context with overrides.
	function ctx( $over = array() ) {
		return array_merge( array(
			'id' => 1, 'mime' => 'image/jpeg', 'alt' => 'x', 'caption' => 'x',
			'description' => 'x', 'title' => 'x', 'bytes' => 1000,
		), $over );
	}

	// =========================================================================
	// AltTextCheck
	// =========================================================================
	$c = new AltTextCheck();
	check( 'alt: interface', $c instanceof QualityCheck );
	check( 'alt: severity high', $c->severity() === 'high' );
	check( 'alt: image w/o alt fails', $c->evaluate( ctx( array( 'alt' => '' ) ) ) === true );
	check( 'alt: image w/ alt passes', $c->evaluate( ctx( array( 'alt' => 'A cat' ) ) ) === false );
	check( 'alt: whitespace-only alt fails', $c->evaluate( ctx( array( 'alt' => '   ' ) ) ) === true );
	check( 'alt: non-image ignored', $c->evaluate( ctx( array( 'mime' => 'application/pdf', 'alt' => '' ) ) ) === false );

	// =========================================================================
	// CaptionCheck / DescriptionCheck
	// =========================================================================
	$cap = new CaptionCheck();
	check( 'caption: empty fails', $cap->evaluate( ctx( array( 'caption' => '' ) ) ) === true );
	check( 'caption: present passes', $cap->evaluate( ctx( array( 'caption' => 'Hi' ) ) ) === false );
	check( 'caption: applies to non-images too', $cap->evaluate( ctx( array( 'mime' => 'application/pdf', 'caption' => '' ) ) ) === true );

	$desc = new DescriptionCheck();
	check( 'desc: empty fails', $desc->evaluate( ctx( array( 'description' => '' ) ) ) === true );
	check( 'desc: present passes', $desc->evaluate( ctx( array( 'description' => 'Hi' ) ) ) === false );

	// =========================================================================
	// OversizedImageCheck (>1 MB)
	// =========================================================================
	$ov = new OversizedImageCheck();
	check( 'oversized: 2MB image fails', $ov->evaluate( ctx( array( 'bytes' => 2 * 1024 * 1024 ) ) ) === true );
	check( 'oversized: exactly 1MB passes (not >)', $ov->evaluate( ctx( array( 'bytes' => 1048576 ) ) ) === false );
	check( 'oversized: 1.5MB image fails', $ov->evaluate( ctx( array( 'bytes' => 1572864 ) ) ) === true );
	check( 'oversized: small image passes', $ov->evaluate( ctx( array( 'bytes' => 50000 ) ) ) === false );
	check( 'oversized: big VIDEO ignored', $ov->evaluate( ctx( array( 'mime' => 'video/mp4', 'bytes' => 50 * 1024 * 1024 ) ) ) === false );

	// =========================================================================
	// UnsupportedFormatCheck
	// =========================================================================
	$uf = new UnsupportedFormatCheck();
	check( 'format: jpeg supported', $uf->evaluate( ctx( array( 'mime' => 'image/jpeg' ) ) ) === false );
	check( 'format: webp supported', $uf->evaluate( ctx( array( 'mime' => 'image/webp' ) ) ) === false );
	check( 'format: bmp unsupported fails', $uf->evaluate( ctx( array( 'mime' => 'image/bmp' ) ) ) === true );
	check( 'format: tiff unsupported fails', $uf->evaluate( ctx( array( 'mime' => 'image/tiff' ) ) ) === true );
	check( 'format: empty mime not flagged', $uf->evaluate( ctx( array( 'mime' => '' ) ) ) === false );

	// =========================================================================
	// WebPRecommendationCheck
	// =========================================================================
	$wp = new WebPRecommendationCheck();
	check( 'webp: 300KB jpeg recommended', $wp->evaluate( ctx( array( 'mime' => 'image/jpeg', 'bytes' => 307200 ) ) ) === true );
	check( 'webp: 300KB png recommended', $wp->evaluate( ctx( array( 'mime' => 'image/png', 'bytes' => 307200 ) ) ) === true );
	check( 'webp: small jpeg not recommended', $wp->evaluate( ctx( array( 'mime' => 'image/jpeg', 'bytes' => 50000 ) ) ) === false );
	check( 'webp: already webp ignored', $wp->evaluate( ctx( array( 'mime' => 'image/webp', 'bytes' => 500000 ) ) ) === false );
	check( 'webp: gif ignored', $wp->evaluate( ctx( array( 'mime' => 'image/gif', 'bytes' => 500000 ) ) ) === false );

	// =========================================================================
	// QualityAuditor::run_checks aggregation
	// =========================================================================
	// Load auditor — it requires its own files; provide minimal admin_url stub.
	function admin_url( $p = '' ) { return 'http://t/' . $p; }
	require MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';
	use MediaUsageTracker\Admin\QualityAuditor;
	use MediaUsageTracker\Storage\UsageStorage;

	$auditor = new QualityAuditor( new UsageStorage() );
	check( 'auditor: 6 checks registered', count( $auditor->get_checks() ) === 6, count( $auditor->get_checks() ) );

	$attachments = array(
		// Perfect image — no issues.
		ctx( array( 'id' => 1 ) ),
		// Image missing alt + oversized + webp candidate (3 issues).
		ctx( array( 'id' => 2, 'alt' => '', 'bytes' => 2 * 1024 * 1024, 'mime' => 'image/jpeg' ) ),
		// BMP missing caption (unsupported + caption).
		ctx( array( 'id' => 3, 'mime' => 'image/bmp', 'caption' => '' ) ),
	);

	$report = $auditor->run_checks( $attachments );
	check( 'report: total 3', $report['total'] === 3 );
	check( 'report: alt_text count 1', $report['checks']['alt_text']['count'] === 1 );
	check( 'report: oversized count 1', $report['checks']['oversized']['count'] === 1 );
	check( 'report: webp count 1', $report['checks']['webp']['count'] === 1 );
	check( 'report: unsupported count 1', $report['checks']['unsupported_format']['count'] === 1 );
	check( 'report: caption count 1', $report['checks']['caption']['count'] === 1 );
	check( 'report: alt items has id 2', $report['checks']['alt_text']['items'] === array( 2 ) );
	check( 'report: clean files = 1 (only id 1)', $report['clean'] === 1, $report['clean'] );
	// id 2 trips 3 checks (alt+oversized+webp); id 3 trips 2 (unsupported+caption) = 5.
	check( 'report: total_issues = 5', $report['total_issues'] === 5, $report['total_issues'] );
	check( 'report: severity high counted', $report['severity_counts']['high'] === 1, $report['severity_counts'] );

	// First card should be the high-severity alt_text (severity ordering).
	$first_key = array_key_first( $report['checks'] );
	check( 'report: high-severity check ordered first', $first_key === 'alt_text', $first_key );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
