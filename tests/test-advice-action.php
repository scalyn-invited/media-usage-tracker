<?php
/**
 * Tests the advisor → review-action mapping in CleanupSuggestions::render_advice_action.
 * Run: php tests/test-advice-action.php
 */

namespace MediaUsageTracker\Storage {
	class UsageStorage {
		public $counts = array(); public $statuses = array(); public $review = array();
		public function get_usage_count( $id ) { return $this->counts[ $id ] ?? 0; }
		public function get_usage_post_statuses( $id ) { return $this->statuses[ $id ] ?? array(); }
		public function get_review_status( $id ) { return $this->review[ $id ] ?? ''; }
	}
}

namespace {
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }

	require MUT_PLUGIN_DIR . 'includes/class-cleanup-suggestions.php';
	use MediaUsageTracker\Admin\CleanupSuggestions;
	use MediaUsageTracker\Storage\UsageStorage;

	$cs  = new CleanupSuggestions( new UsageStorage() );
	$ref = new \ReflectionMethod( $cs, 'render_advice_action' );
	$ref->setAccessible( true );
	$call = fn( $id, $advice, $status ) => $ref->invoke( $cs, $id, $advice, $status );

	$pass = 0; $fail = 0;
	function check( $label, $cond ) {
		global $pass, $fail;
		printf( "[%s] %s\n", $cond ? 'PASS' : 'FAIL', $label );
		$cond ? $pass++ : $fail++;
	}

	// archive verdict, no status → archive button
	$h = $call( 7, array( 'action' => 'archive' ), '' );
	check( 'archive -> bulk_action archive', strpos( $h, 'data-action="archive"' ) !== false );
	check( 'archive -> archive button label', strpos( $h, 'Flag for archive' ) !== false );

	// review verdict → flag button
	$h = $call( 8, array( 'action' => 'review' ), '' );
	check( 'review -> bulk_action flag', strpos( $h, 'data-action="flag"' ) !== false );

	// monitor verdict → flag button (still actionable)
	$h = $call( 9, array( 'action' => 'monitor' ), '' );
	check( 'monitor -> bulk_action flag', strpos( $h, 'data-action="flag"' ) !== false );

	// keep verdict → no button
	$h = $call( 10, array( 'action' => 'keep' ), '' );
	check( 'keep -> no action button', trim( $h ) === '' );

	// already archived → status + undo, no act button
	$h = $call( 11, array( 'action' => 'archive' ), 'archived' );
	check( 'archived status shows Archived', strpos( $h, '📦 Archived' ) !== false );
	check( 'archived status shows undo (clear)', strpos( $h, 'data-action="clear"' ) !== false );
	check( 'archived status hides act button', strpos( $h, 'mut-advice-act"' ) === false );

	// already flagged → flagged badge
	$h = $call( 12, array( 'action' => 'review' ), 'flagged' );
	check( 'flagged status shows Flagged', strpos( $h, '🚩 Flagged' ) !== false );

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
