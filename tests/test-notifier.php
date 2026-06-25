<?php
/**
 * Tests for Notifier (scheduled-scan email summary).
 * Run: php tests/test-notifier.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $total = 0;
		public $in_use = 0;
		public $unused_bytes = 0;
		public function get_total_media_count() { return $this->total; }
		public function get_files_in_use_count() { return $this->in_use; }
		public function get_unused_storage_usage() { return $this->unused_bytes; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	// ---- WP stubs -----------------------------------------------------------
	$GLOBALS['__options'] = array();
	$GLOBALS['__sent']    = array();

	function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }
	function current_time( $fmt ) { return '2026-06-16 09:00:00'; }
	function get_bloginfo( $k ) { return 'Acme Co'; }
	function admin_url( $p ) { return 'http://site.test/wp-admin/' . $p; }
	function esc_html( $s ) { return $s; }
	function esc_attr( $s ) { return $s; }
	function esc_url( $s ) { return $s; }
	function size_format( $bytes, $dec = 0 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0; $bytes = (float) $bytes;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) { $bytes /= 1024; $i++; }
		return round( $bytes, $dec ) . ' ' . $units[ $i ];
	}
	function wp_mail( $to, $subject, $body, $headers = array() ) {
		$GLOBALS['__sent'][] = compact( 'to', 'subject', 'body', 'headers' );
		return true;
	}
	function add_action( $h, $cb ) {}

	require MUT_PLUGIN_DIR . 'includes/class-notifier.php';

	use MediaUsageTracker\Core\Notifier;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}

	// =========================================================================
	// build_summary: math
	// =========================================================================
	$s = new UsageStorage();
	$s->total = 100; $s->in_use = 70; $s->unused_bytes = 5 * 1024 * 1024;
	$n = new Notifier( $s );
	$sum = $n->build_summary();
	check( 'summary: total 100', $sum['total'] === 100 );
	check( 'summary: in_use 70', $sum['in_use'] === 70 );
	check( 'summary: unused 30', $sum['unused'] === 30, $sum['unused'] );
	check( 'summary: unused_pct 30', $sum['unused_pct'] === 30, $sum['unused_pct'] );
	check( 'summary: reclaimable bytes', $sum['reclaimable'] === 5 * 1024 * 1024 );

	// in_use > total guard (shouldn't go negative)
	$s2 = new UsageStorage();
	$s2->total = 10; $s2->in_use = 15;
	$n2 = new Notifier( $s2 );
	$sum2 = $n2->build_summary();
	check( 'summary: unused never negative', $sum2['unused'] === 0, $sum2['unused'] );

	// zero total → 0% not div-by-zero
	$s3 = new UsageStorage();
	$n3 = new Notifier( $s3 );
	$sum3 = $n3->build_summary();
	check( 'summary: zero total → 0 pct', $sum3['unused_pct'] === 0 );

	// =========================================================================
	// build_subject
	// =========================================================================
	check( 'subject: mentions unused count', strpos( $n->build_subject( $sum ), '30 unused files' ) !== false, $n->build_subject( $sum ) );
	check( 'subject: site name in brackets', strpos( $n->build_subject( $sum ), '[Acme Co]' ) !== false );

	$clean = array( 'total' => 50, 'in_use' => 50, 'unused' => 0, 'unused_pct' => 0, 'reclaimable' => 0, 'scanned_at' => 'x' );
	check( 'subject: clean = no unused', strpos( $n->build_subject( $clean ), 'no unused files' ) !== false, $n->build_subject( $clean ) );

	// singular
	$one = array( 'total' => 50, 'in_use' => 49, 'unused' => 1, 'unused_pct' => 2, 'reclaimable' => 1024, 'scanned_at' => 'x' );
	check( 'subject: singular file (no s)', strpos( $n->build_subject( $one ), '1 unused file ' ) !== false, $n->build_subject( $one ) );

	// =========================================================================
	// render_email
	// =========================================================================
	$html = $n->render_email( $sum );
	check( 'email: contains heading', strpos( $html, 'Media Usage Scan Summary' ) !== false );
	check( 'email: shows total', strpos( $html, '100' ) !== false );
	check( 'email: shows reclaimable 5 MB', strpos( $html, '5 MB' ) !== false || strpos( $html, '5.0 MB' ) !== false, $html );
	check( 'email: has cleanup CTA when unused', strpos( $html, 'Review Unused Files' ) !== false );

	$cleanHtml = $n->render_email( $clean );
	check( 'email: clean state message', strpos( $cleanHtml, 'every media file is in use' ) !== false );
	check( 'email: no CTA when clean', strpos( $cleanHtml, 'Review Unused Files' ) === false );

	// =========================================================================
	// is_enabled / recipient
	// =========================================================================
	$GLOBALS['__options']['mut_scan_email_enabled'] = '0';
	check( 'is_enabled: false when 0', $n->is_enabled() === false );
	$GLOBALS['__options']['mut_scan_email_enabled'] = '1';
	check( 'is_enabled: true when 1', $n->is_enabled() === true );

	$GLOBALS['__options']['mut_scan_email_recipient'] = '';
	$GLOBALS['__options']['admin_email'] = 'boss@acme.test';
	check( 'recipient: falls back to admin_email', $n->recipient() === 'boss@acme.test' );

	$GLOBALS['__options']['mut_scan_email_recipient'] = 'me@acme.test';
	check( 'recipient: explicit wins', $n->recipient() === 'me@acme.test' );

	// =========================================================================
	// on_scan_complete: respects enabled flag + actually sends
	// =========================================================================
	$GLOBALS['__sent'] = array();
	$GLOBALS['__options']['mut_scan_email_enabled'] = '0';
	$n->on_scan_complete( 1 );
	check( 'on_complete: nothing sent when disabled', count( $GLOBALS['__sent'] ) === 0 );

	$GLOBALS['__options']['mut_scan_email_enabled'] = '1';
	$n->on_scan_complete( 1 );
	check( 'on_complete: one email sent when enabled', count( $GLOBALS['__sent'] ) === 1 );
	check( 'on_complete: sent to recipient', $GLOBALS['__sent'][0]['to'] === 'me@acme.test' );
	check( 'on_complete: html content-type header', in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['__sent'][0]['headers'], true ) );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
