<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by JetEngine (Crocoblock).
 *
 * JetEngine stores field values in standard WordPress postmeta. Each meta key
 * corresponds to a field registered in a JetEngine meta box. The field
 * definition (type, name, label) lives in the option `jet_engine_meta_boxes`.
 *
 * Strategy:
 *   1. Load all JetEngine meta box definitions once and collect field names
 *      whose type is 'media' or 'gallery'.
 *   2. For each post, read only those postmeta keys.
 *   3. Values can be:
 *        - A single attachment ID (int or numeric string)          → media field
 *        - A JSON array of attachment IDs                          → gallery field
 *        - A serialized/JSON array of objects with an 'id' key     → gallery field (older JE format)
 *
 * Self-gates: only runs when JetEngine is active.
 */
class JetEngineDetector implements MediaDetector {

	private $storage;

	/** @var array<string,string>|null  field_name → label cache, null = not loaded yet */
	private static $media_fields = null;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'jetengine';
	}

	public function is_available() {
		return class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' );
	}

	public function detect( $post, $scan_id ) {
		$fields = $this->get_media_fields();
		$found  = array(); // attachment_id → label

		if ( ! empty( $fields ) ) {
			// Primary path: scan only known media/gallery field keys.
			foreach ( $fields as $field_name => $label ) {
				$raw = get_post_meta( $post->ID, $field_name, true );
				if ( empty( $raw ) ) {
					continue;
				}
				foreach ( $this->extract_ids( $raw ) as $id ) {
					if ( ! isset( $found[ $id ] ) ) {
						$found[ $id ] = $label;
					}
				}
			}
		} else {
			// Fallback: JetEngine field definitions not discoverable (e.g. stored
			// in a format we haven't seen yet). Walk ALL postmeta values that look
			// like attachment IDs and are not standard WP internal keys.
			$all_meta = get_post_meta( $post->ID );
			if ( is_array( $all_meta ) ) {
				foreach ( $all_meta as $key => $values ) {
					// Skip WP core and common plugin internal keys.
					if ( strpos( $key, '_' ) === 0 ) {
						continue;
					}
					$raw = maybe_unserialize( $values[0] ?? '' );
					foreach ( $this->extract_ids( $raw ) as $id ) {
						if ( ! isset( $found[ $id ] ) && get_post_type( $id ) === 'attachment' ) {
							$found[ $id ] = $key;
						}
					}
				}
			}
		}

		$recorded = 0;

		foreach ( $found as $id => $label ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'jetengine',
				'context'       => 'JetEngine: ' . substr( (string) $label, 0, 180 ),
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/** JetEngine field types that hold attachment references. */
	const MEDIA_TYPES = array( 'media', 'gallery', 'image' );

	/**
	 * Build a map of field_name → label for all JetEngine media/gallery fields.
	 * Cached in a static property so we only query once per request.
	 *
	 * JetEngine stores meta box definitions in two ways depending on version:
	 *   A) As posts of the 'jet-engine' CPT, with fields JSON in '_fields' postmeta
	 *      (current/default for JetEngine 2.x+).
	 *   B) As a flat array in the 'jet_engine_meta_boxes' wp_option
	 *      (legacy / some configurations).
	 * We try both and merge results.
	 *
	 * @return array<string,string>
	 */
	private function get_media_fields() {
		if ( self::$media_fields !== null ) {
			return self::$media_fields;
		}

		self::$media_fields = array();

		// --- Source A: jet-engine CPT (primary, JetEngine 2.x+) ---
		$cpt_posts = get_posts( array(
			'post_type'      => 'jet-engine',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		) );

		// JetEngine uses different meta keys depending on what the CPT post represents
		// (post type definition, meta box, taxonomy, etc.) and the plugin version.
		$field_meta_keys = array( 'meta_fields', '_fields', '_meta_fields', 'fields' );

		foreach ( (array) $cpt_posts as $cpt_id ) {
			foreach ( $field_meta_keys as $meta_key ) {
				$raw = get_post_meta( $cpt_id, $meta_key, true );
				if ( empty( $raw ) ) {
					continue;
				}
				$fields = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
				if ( is_array( $fields ) ) {
					$this->collect_from_fields( $fields );
				}
			}
		}

		// --- Source B: legacy wp_option fallback ---
		$meta_boxes = get_option( 'jet_engine_meta_boxes', array() );
		foreach ( (array) $meta_boxes as $box ) {
			$fields = $box['meta_fields'] ?? $box['fields'] ?? array();
			$this->collect_from_fields( (array) $fields );
		}

		return self::$media_fields;
	}

	/**
	 * Walk a fields array and add media/gallery entries to self::$media_fields.
	 *
	 * @param array $fields
	 */
	private function collect_from_fields( array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = $field['type'] ?? '';
			if ( ! in_array( $type, self::MEDIA_TYPES, true ) ) {
				continue;
			}
			$name  = $field['name'] ?? '';
			$label = $field['title'] ?? $field['label'] ?? $name;
			if ( $name !== '' ) {
				self::$media_fields[ $name ] = $label !== '' ? $label : $name;
			}
		}
	}

	/**
	 * Extract attachment IDs from a raw postmeta value.
	 *
	 * JetEngine media fields can store values in several formats depending on the
	 * field's "value format" setting:
	 *   - Numeric scalar / string        → attachment ID
	 *   - URL string                     → resolve via attachment_url_to_postid()
	 *   - JSON array of scalars          → gallery of IDs
	 *   - JSON array of {id,url} objects → gallery (image widget format)
	 *   - Serialized PHP array           → unserializes to one of the above
	 *
	 * @return int[]
	 */
	private function extract_ids( $raw ) {
		$ids = array();

		// Numeric scalar — single media field storing an ID.
		if ( is_numeric( $raw ) ) {
			$id = absint( $raw );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			return $ids;
		}

		// Plain URL string — resolve to attachment ID.
		if ( is_string( $raw ) && filter_var( $raw, FILTER_VALIDATE_URL ) ) {
			$id = attachment_url_to_postid( $raw );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			return $ids;
		}

		// Try JSON decode.
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} elseif ( is_serialized( $raw ) ) {
				$raw = maybe_unserialize( $raw );
			}
		}

		if ( ! is_array( $raw ) ) {
			return $ids;
		}

		foreach ( $raw as $item ) {
			if ( is_numeric( $item ) ) {
				$id = absint( $item );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			} elseif ( is_string( $item ) && filter_var( $item, FILTER_VALIDATE_URL ) ) {
				// Gallery storing URLs instead of IDs.
				$id = attachment_url_to_postid( $item );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			} elseif ( is_array( $item ) ) {
				// {id: 123, url: "..."} shape — prefer id, fall back to url lookup.
				if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
					$id = absint( $item['id'] );
				} elseif ( isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) {
					$id = absint( $item['ID'] );
				} elseif ( isset( $item['url'] ) && filter_var( $item['url'], FILTER_VALIDATE_URL ) ) {
					$id = attachment_url_to_postid( $item['url'] );
				} else {
					$id = 0;
				}
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}
}
