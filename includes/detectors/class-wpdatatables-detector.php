<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by wpDataTables.
 *
 * wpDataTables stores table metadata in {prefix}wpdatatable and column
 * definitions in {prefix}wpdatatable_columns. For "manual" tables the row
 * data lives in {prefix}wpdatatable_{id}.
 *
 * We scan image-type columns in manual tables for attachment URLs and resolve
 * them to attachment IDs. Runs once per scan (not per post).
 *
 * Self-gates: only runs when wpDataTables is active.
 */
class WpDataTablesDetector implements MediaDetector {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'wpdatatables';
	}

	public function is_available() {
		return function_exists( 'wdt_get_all_tables' )
			|| defined( 'WDT_ROOT_PATH' )
			|| class_exists( 'WPDataTable' );
	}

	public function detect( $post, $scan_id ) {
		return 0; // Handled globally via scan_all()
	}

	/**
	 * Scan all wpDataTables manual tables for image/link columns.
	 * Called once per scan from MediaScanner::scan_global_detectors().
	 */
	public function scan_all( $scan_id ) {
		if ( ! $this->is_available() ) {
			return 0;
		}

		global $wpdb;

		// Fetch all manual tables (table_type = 'manual').
		$tables = $wpdb->get_results(
			"SELECT id, title FROM {$wpdb->prefix}wpdatatable WHERE table_type = 'manual'"
		);

		if ( empty( $tables ) ) {
			return 0;
		}

		$recorded = 0;

		foreach ( $tables as $table ) {
			$table_id    = absint( $table->id );
			$table_title = $table->title;
			$data_table  = $wpdb->prefix . 'wpdatatable_' . $table_id;

			// Get columns of type 'image' or 'link' for this table.
			$image_cols = $wpdb->get_results( $wpdb->prepare(
				"SELECT orig_header, display_header
				 FROM {$wpdb->prefix}wpdatatable_columns
				 WHERE table_id = %d
				   AND column_type IN ('image', 'link')",
				$table_id
			) );

			if ( empty( $image_cols ) ) {
				continue;
			}

			// Check the data table exists before querying it.
			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$data_table
			) );

			if ( ! $exists ) {
				continue;
			}

			// Build column list to SELECT.
			$col_names = array_map( function( $c ) use ( $wpdb ) {
				return '`' . esc_sql( $c->orig_header ) . '`';
			}, $image_cols );

			$col_labels = array();
			foreach ( $image_cols as $c ) {
				$col_labels[ $c->orig_header ] = $c->display_header ?: $c->orig_header;
			}

			$select = implode( ', ', $col_names );
			$rows   = $wpdb->get_results( "SELECT {$select} FROM `{$data_table}`", ARRAY_A );

			foreach ( $rows as $row ) {
				foreach ( $row as $col => $value ) {
					if ( empty( $value ) || strpos( $value, 'http' ) !== 0 ) {
						continue;
					}

					// Value may be a URL directly or wrapped in <img src="...">
					$urls = array( $value );
					if ( preg_match( '/src=["\']([^"\']+)["\']/', $value, $m ) ) {
						$urls[] = $m[1];
					}

					foreach ( $urls as $url ) {
						$id = absint( attachment_url_to_postid( $url ) );
						if ( $id > 0 ) {
							$context = 'wpDataTable: ' . $table_title . ' > ' . ( $col_labels[ $col ] ?? $col );
							$this->storage->record_usage( array(
								'attachment_id' => $id,
								'post_id'       => $table_id,
								'post_type'     => 'wpdatatables',
								'usage_type'    => 'wpdatatables',
								'context'       => substr( $context, 0, 200 ),
								'scan_id'       => $scan_id,
							) );
							$recorded++;
							break; // One record per cell is enough.
						}
					}
				}
			}
		}

		return $recorded;
	}
}
