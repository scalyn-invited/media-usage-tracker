<?php
/**
 * Tests for SafeDelete (verify gate + trash/restore flow).
 * Run: php tests/test-safe-delete.php
 */

namespace MediaUsageTracker\Storage {
	// Configurable fake storage.
	class UsageStorage {
		public $usage_count   = 0;
		public $history       = array();
		public $logged        = array();
		public $records       = array();
		public $removed       = array();
		public $cleared       = array();
		public $next_log_id   = 100;

		public function get_usage_count( $id ) { return $this->usage_count; }
		public function get_scan_history() { return $this->history; }
		public function clear_review_status( $id ) { $this->cleared[] = $id; }

		public function log_deletion( $data ) {
			$id = $this->next_log_id++;
			$this->logged[ $id ] = $data;
			return $id;
		}
		public function get_deletion_record( $id ) { return $this->records[ $id ] ?? null; }
		public function remove_deletion_record( $id ) { $this->removed[] = $id; }
	}
}

namespace {
	define( 'MUT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'ABSPATH', sys_get_temp_dir() . '/' );

	function absint( $v ) { return abs( (int) $v ); }

	// ---- WP stubs -----------------------------------------------------------
	$GLOBALS['__posts']   = array();   // id => post object
	$GLOBALS['__thumb_rows'] = 0;      // _thumbnail_id matches
	$GLOBALS['__content_rows'] = 0;    // content LIKE matches
	$GLOBALS['__url'] = array();       // id => url
	$GLOBALS['__files'] = array();     // path => exists bool

	function get_post( $id ) { return $GLOBALS['__posts'][ $id ] ?? null; }
	function wp_get_attachment_url( $id ) { return $GLOBALS['__url'][ $id ] ?? ''; }
	function get_attached_file( $id ) { return $GLOBALS['__files_for'][ $id ] ?? ''; }
	function get_the_title( $id ) { return 'Title ' . $id; }
	function get_post_mime_type( $id ) { return 'image/jpeg'; }
	function get_current_user_id() { return 7; }
	function wp_delete_attachment( $id, $force = false ) { $GLOBALS['__deleted'][] = $id; return true; }
	function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/mut-uploads' ); }
	function wp_mkdir_p( $dir ) { if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0777, true ); } return true; }
	function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
	function is_wp_error( $t ) { return false; }
	function wp_insert_attachment( $args, $file ) { return 555; }
	function wp_update_attachment_metadata( $id, $meta ) { return true; }
	function wp_generate_attachment_metadata( $id, $file ) { return array(); }

	// Minimal $wpdb whose reference counts come from globals.
	class FakeWpdb {
		public $posts = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public $_last_sql = '';
		public function esc_like( $s ) { return $s; }
		public function prepare( $sql, ...$args ) {
			$this->_last_sql = $sql;
			return $sql;
		}
		public function get_var( $sql ) {
			if ( strpos( $sql, '_thumbnail_id' ) !== false ) {
				return $GLOBALS['__thumb_rows'];
			}
			if ( strpos( $sql, 'post_content LIKE' ) !== false ) {
				return $GLOBALS['__content_rows'];
			}
			return 0;
		}
	}
	$GLOBALS['wpdb'] = new FakeWpdb();

	require MUT_PLUGIN_DIR . 'includes/class-safe-delete.php';

	use MediaUsageTracker\Core\SafeDelete;
	use MediaUsageTracker\Storage\UsageStorage;

	$pass = 0; $fail = 0;
	function check( $label, $cond, $got = null ) {
		global $pass, $fail;
		printf( "[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && $got !== null ) ? '  (got: ' . json_encode( $got ) . ')' : '' );
		$cond ? $pass++ : $fail++;
	}
	function fresh_history() {
		return array( (object) array( 'completed_at' => date( 'Y-m-d H:i:s' ) ) );
	}
	function make_attachment( $id ) {
		$GLOBALS['__posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'attachment' );
	}

	// =========================================================================
	// Gate: non-existent attachment is never safe
	// =========================================================================
	$GLOBALS['__posts'] = array();
	$s = new UsageStorage();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 999 );
	check( 'missing attachment: not safe', $r['safe'] === false );
	check( 'missing attachment: 1 blocking', $r['blocking'] === 1, $r['blocking'] );
	check( 'missing attachment: only exists check returned', count( $r['checks'] ) === 1, count( $r['checks'] ) );

	// =========================================================================
	// Gate: clean unused file IS safe
	// =========================================================================
	make_attachment( 10 );
	$GLOBALS['__thumb_rows']   = 0;
	$GLOBALS['__content_rows'] = 0;
	$s = new UsageStorage();
	$s->usage_count = 0;
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 10 );
	check( 'clean unused: safe', $r['safe'] === true, $r );
	check( 'clean unused: 0 blocking', $r['blocking'] === 0 );
	check( 'clean unused: 4 checks run', count( $r['checks'] ) === 4, count( $r['checks'] ) );

	// =========================================================================
	// Gate: scan usage > 0 blocks
	// =========================================================================
	make_attachment( 11 );
	$s = new UsageStorage();
	$s->usage_count = 3;
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 11 );
	check( 'scan usage>0: not safe', $r['safe'] === false );
	$scan_check = array_values( array_filter( $r['checks'], fn( $c ) => $c['key'] === 'scan_usage' ) )[0];
	check( 'scan usage>0: scan_usage check fails', $scan_check['status'] === 'fail' );

	// =========================================================================
	// Gate: LIVE reference blocks even when scan says unused
	// =========================================================================
	make_attachment( 12 );
	$GLOBALS['__content_rows'] = 1; // a post references it right now
	$s = new UsageStorage();
	$s->usage_count = 0;            // last scan thought it was unused
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 12 );
	check( 'live ref: not safe despite clean scan', $r['safe'] === false );
	$live_check = array_values( array_filter( $r['checks'], fn( $c ) => $c['key'] === 'live_refs' ) )[0];
	check( 'live ref: live_refs check fails', $live_check['status'] === 'fail' );
	$GLOBALS['__content_rows'] = 0; // reset

	// =========================================================================
	// Gate: featured-image usage blocks
	// =========================================================================
	make_attachment( 13 );
	$GLOBALS['__thumb_rows'] = 1;
	$s = new UsageStorage();
	$s->usage_count = 0;
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 13 );
	check( 'featured: not safe', $r['safe'] === false );
	$GLOBALS['__thumb_rows'] = 0;

	// =========================================================================
	// Advisory: stale scan warns but does NOT block
	// =========================================================================
	make_attachment( 14 );
	$s = new UsageStorage();
	$s->usage_count = 0;
	$s->history = array( (object) array( 'completed_at' => date( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 14 );
	check( 'stale scan: still safe (warn only)', $r['safe'] === true, $r );
	$fresh_check = array_values( array_filter( $r['checks'], fn( $c ) => $c['key'] === 'scan_fresh' ) )[0];
	check( 'stale scan: scan_fresh = warn', $fresh_check['status'] === 'warn' );
	check( 'stale scan: scan_fresh non-blocking', $fresh_check['blocking'] === false );

	// =========================================================================
	// Advisory: no history at all → warn, not block
	// =========================================================================
	make_attachment( 15 );
	$s = new UsageStorage();
	$s->usage_count = 0;
	$s->history = array();
	$sd = new SafeDelete( $s );
	$r = $sd->verify( 15 );
	check( 'no history: still safe', $r['safe'] === true );

	// =========================================================================
	// trash(): blocked when unsafe and not forced
	// =========================================================================
	make_attachment( 20 );
	$s = new UsageStorage();
	$s->usage_count = 2; // unsafe
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$res = $sd->trash( 20, false );
	check( 'trash blocked: success false', $res['success'] === false );
	check( 'trash blocked: nothing logged', count( $s->logged ) === 0 );
	check( 'trash blocked: attachment not deleted', empty( $GLOBALS['__deleted'] ) );

	// =========================================================================
	// trash(): proceeds and logs when safe (with a real temp file)
	// =========================================================================
	$GLOBALS['__deleted'] = array();
	make_attachment( 21 );
	$tmpfile = sys_get_temp_dir() . '/mut-test-source-' . uniqid() . '.jpg';
	file_put_contents( $tmpfile, 'pixels' );
	$GLOBALS['__files_for'] = array( 21 => $tmpfile );
	$GLOBALS['__url'][21]   = 'http://site.test/wp-content/uploads/2024/01/photo.jpg';
	$s = new UsageStorage();
	$s->usage_count = 0;
	$s->history = fresh_history();
	$sd = new SafeDelete( $s );
	$res = $sd->trash( 21, false );
	check( 'trash safe: success true', $res['success'] === true, $res );
	check( 'trash safe: log id returned', $res['log_id'] >= 100, $res['log_id'] );
	check( 'trash safe: deletion logged', count( $s->logged ) === 1 );
	check( 'trash safe: source file moved (gone from origin)', ! file_exists( $tmpfile ) );
	check( 'trash safe: attachment record removed', in_array( 21, $GLOBALS['__deleted'], true ) );
	check( 'trash safe: review status cleared', in_array( 21, $s->cleared, true ) );
	$logged = array_values( $s->logged )[0];
	check( 'trash safe: trash_path recorded', ! empty( $logged['trash_path'] ) && file_exists( $logged['trash_path'] ) );

	// =========================================================================
	// restore(): missing record fails gracefully
	// =========================================================================
	$s = new UsageStorage();
	$sd = new SafeDelete( $s );
	$res = $sd->restore( 999 );
	check( 'restore missing: success false', $res['success'] === false );

	// =========================================================================
	// restore(): moves file back + recreates attachment + clears log
	// =========================================================================
	$trash_file = sys_get_temp_dir() . '/mut-trash-' . uniqid() . '.jpg';
	file_put_contents( $trash_file, 'pixels' );
	$orig_dest  = sys_get_temp_dir() . '/mut-restore-' . uniqid() . '.jpg';
	$s = new UsageStorage();
	$s->records[5] = (object) array(
		'id'            => 5,
		'trash_path'    => $trash_file,
		'original_path' => $orig_dest,
		'title'         => 'Restored',
		'mime_type'     => 'image/jpeg',
	);
	$sd = new SafeDelete( $s );
	$res = $sd->restore( 5 );
	check( 'restore: success true', $res['success'] === true, $res );
	check( 'restore: attachment id returned', $res['attachment_id'] === 555 );
	check( 'restore: file back at original path', file_exists( $orig_dest ) );
	check( 'restore: trash file consumed', ! file_exists( $trash_file ) );
	check( 'restore: log record removed', in_array( 5, $s->removed, true ) );

	// cleanup
	@unlink( $orig_dest );
	if ( ! empty( $logged['trash_path'] ) ) { @unlink( $logged['trash_path'] ); }

	echo "\n";
	printf( "Result: %d passed, %d failed\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
