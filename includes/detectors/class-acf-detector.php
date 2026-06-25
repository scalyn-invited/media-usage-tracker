<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Advanced Custom Fields (ACF).
 *
 * Walks top-level fields AND nested sub-fields inside repeaters,
 * flexible content layouts, and groups recursively.
 */
class AcfDetector implements MediaDetector {

	/** ACF field types that reference attachments. */
	const MEDIA_TYPES = array( 'image', 'file', 'gallery' );

	/** ACF field types that contain sub-fields to recurse into. */
	const CONTAINER_TYPES = array( 'repeater', 'flexible_content', 'group' );

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'acf';
	}

	public function is_available() {
		return function_exists( 'get_field_objects' );
	}

	public function detect( $post, $scan_id ) {
		$fields = get_field_objects( $post->ID, false );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return 0;
		}

		$found = array(); // attachment_id => context label
		$this->walk_fields( $fields, $found );

		$recorded = 0;
		foreach ( $found as $id => $label ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'acf',
				'context'       => 'ACF: ' . substr( (string) $label, 0, 180 ),
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Recursively walk ACF fields, collecting attachment IDs into $found.
	 * Handles repeaters, flexible content layouts, and groups.
	 *
	 * @param array $fields  Array of ACF field objects (each has 'type', 'value', 'sub_fields').
	 * @param array &$found  Map of attachment_id => context label (populated in place).
	 * @param string $prefix Label prefix for nested fields (e.g. "Team Members > Photo").
	 */
	private function walk_fields( array $fields, array &$found, $prefix = '' ) {
		foreach ( $fields as $field ) {
			$type  = $field['type'] ?? '';
			$label = $field['label'] !== '' ? $field['label'] : ( $field['name'] ?? 'ACF field' );
			$path  = $prefix ? $prefix . ' > ' . $label : $label;

			if ( in_array( $type, self::MEDIA_TYPES, true ) ) {
				foreach ( $this->extract_ids( $field['value'] ?? null ) as $id ) {
					if ( ! isset( $found[ $id ] ) ) {
						$found[ $id ] = $path;
					}
				}
				continue;
			}

			if ( ! in_array( $type, self::CONTAINER_TYPES, true ) ) {
				continue;
			}

			// Repeater: value is an array of rows, each row is an array of sub-field objects.
			if ( $type === 'repeater' && is_array( $field['value'] ?? null ) ) {
				foreach ( $field['value'] as $row ) {
					if ( is_array( $row ) ) {
						// Each row item may be a field object (with 'type') or a key=>value pair.
						// ACF with format=false gives key=>value pairs; resolve sub_fields for structure.
						$sub_fields = $field['sub_fields'] ?? array();
						$this->walk_repeater_row( $row, $sub_fields, $found, $path );
					}
				}
				continue;
			}

			// Flexible content: value is an array of layouts, each with a 'acf_fc_layout' key.
			if ( $type === 'flexible_content' && is_array( $field['value'] ?? null ) ) {
				$layouts = array();
				foreach ( $field['layouts'] ?? array() as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				foreach ( $field['value'] as $row ) {
					$layout_name = $row['acf_fc_layout'] ?? '';
					$layout_subs = $layouts[ $layout_name ]['sub_fields'] ?? array();
					$this->walk_repeater_row( $row, $layout_subs, $found, $path . ' > ' . $layout_name );
				}
				continue;
			}

			// Group: value is a single associative array of sub-field key=>value.
			if ( $type === 'group' && is_array( $field['value'] ?? null ) ) {
				$sub_fields = $field['sub_fields'] ?? array();
				$this->walk_repeater_row( $field['value'], $sub_fields, $found, $path );
			}
		}
	}

	/**
	 * Walk one repeater/group row: merge sub-field definitions with row values,
	 * then recurse via walk_fields().
	 */
	private function walk_repeater_row( array $row, array $sub_fields, array &$found, $prefix ) {
		// Build a keyed map of sub-field definitions.
		$sub_map = array();
		foreach ( $sub_fields as $sf ) {
			$sub_map[ $sf['name'] ] = $sf;
		}

		// Merge row values into sub-field definitions so walk_fields() can process them.
		$hydrated = array();
		foreach ( $row as $key => $value ) {
			if ( $key === 'acf_fc_layout' ) {
				continue;
			}
			if ( isset( $sub_map[ $key ] ) ) {
				$sf          = $sub_map[ $key ];
				$sf['value'] = $value;
				$hydrated[]  = $sf;
			} elseif ( is_numeric( $value ) || is_array( $value ) ) {
				// Sub-field definition not found — still try to extract IDs defensively.
				foreach ( $this->extract_ids( $value ) as $id ) {
					if ( ! isset( $found[ $id ] ) ) {
						$found[ $id ] = $prefix;
					}
				}
			}
		}

		if ( ! empty( $hydrated ) ) {
			$this->walk_fields( $hydrated, $found, $prefix );
		}
	}

	/**
	 * Pull attachment IDs out of a raw ACF field value, which may be:
	 *   - an int / numeric string         (image, file)
	 *   - an array of ints                (gallery)
	 *   - an array with an 'ID'/'id' key  (defensive: partially-formatted value)
	 *   - an array of any of the above    (gallery of mixed shapes)
	 *
	 * @return int[]
	 */
	private function extract_ids( $value ) {
		$ids = array();

		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			return $ids;
		}

		if ( is_array( $value ) ) {
			// Associative value carrying its own ID (defensive).
			if ( isset( $value['ID'] ) || isset( $value['id'] ) ) {
				$id = absint( $value['ID'] ?? $value['id'] );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
				return $ids;
			}
			// Otherwise treat as a list and recurse.
			foreach ( $value as $item ) {
				$ids = array_merge( $ids, $this->extract_ids( $item ) );
			}
		}

		return $ids;
	}
}
