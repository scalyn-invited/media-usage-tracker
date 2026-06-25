<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Beaver Builder.
 *
 * Beaver Builder stores its layout in the _fl_builder_data postmeta as a
 * serialized array of node objects (rows / columns / modules). Each node has a
 * ->settings object; media modules expose attachment references there, e.g.:
 *   - photo        => attachment ID            (Photo module)
 *   - photo_src    => URL                       (companion to photo)
 *   - photos       => array of IDs              (Gallery module)
 *   - bg_image / bg_photo => attachment ID      (row/column background)
 *   - video / fallback_photo => attachment ID   (Video module)
 *
 * Rather than enumerate every module's setting names (which vary by version),
 * we walk every node's settings and harvest:
 *   - any key whose value is a bare attachment ID for a known ID-bearing key,
 *   - any *_src URL,
 *   - any array of numeric IDs (galleries).
 *
 * Self-gates: only runs when Beaver Builder is active.
 *
 * Note: unserializing third-party objects is version-sensitive; this is the
 * most fragile detector and is guarded defensively.
 */
class BeaverBuilderDetector implements MediaDetector {

	use CssUrlScanner;

	const META_KEY = '_fl_builder_data';

	/** Settings keys whose value is a single attachment ID. */
	const ID_KEYS = array( 'photo', 'bg_image', 'bg_photo', 'video', 'fallback_photo', 'image', 'audio' );

	/** Settings keys whose value is an array of attachment IDs. */
	const IDS_KEYS = array( 'photos', 'gallery', 'images' );

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'beaver_builder';
	}

	public function is_available() {
		return class_exists( 'FLBuilder' ) || defined( 'FL_BUILDER_VERSION' );
	}

	public function detect( $post, $scan_id ) {
		$raw = get_post_meta( $post->ID, self::META_KEY, true );
		if ( empty( $raw ) ) {
			return 0;
		}

		// _fl_builder_data is stored already-unserialized by get_post_meta when
		// it was saved as an array; if it's still a serialized string, decode it
		// defensively (no class instantiation).
		$data = $raw;
		if ( is_string( $raw ) ) {
			$data = @unserialize( $raw, array( 'allowed_classes' => false ) );
		}
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$found_ids = array();
		foreach ( $data as $node ) {
			$settings = $this->node_settings( $node );
			if ( $settings === null ) {
				continue;
			}
			$this->harvest_settings( $settings, $found_ids );
		}

		// CSS url() references in custom CSS fields within node settings
		$raw_str = is_string( $raw ) ? $raw : serialize( $raw );
		$this->scan_text_for_css_urls( $raw_str, $found_ids );

		$found_ids = array_unique( array_filter( $found_ids ) );

		$recorded = 0;
		foreach ( $found_ids as $id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'beaver_builder',
				'context'       => 'Beaver Builder',
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Get a node's settings as an associative array, whether the node is a
	 * stdClass (allowed_classes=false turns objects into __PHP_Incomplete_Class
	 * or stdClass) or already an array.
	 *
	 * @return array|null
	 */
	private function node_settings( $node ) {
		if ( is_object( $node ) && isset( $node->settings ) ) {
			return (array) $node->settings;
		}
		if ( is_array( $node ) && isset( $node['settings'] ) ) {
			return (array) $node['settings'];
		}
		return null;
	}

	/**
	 * Harvest attachment IDs from one settings array.
	 *
	 * @param array $settings
	 * @param int[] $ids       Accumulator (by reference).
	 */
	private function harvest_settings( $settings, &$ids ) {
		foreach ( $settings as $key => $value ) {
			// Single-ID keys.
			if ( in_array( $key, self::ID_KEYS, true ) && is_numeric( $value ) ) {
				$id = absint( $value );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
				continue;
			}

			// Array-of-IDs keys (galleries).
			if ( in_array( $key, self::IDS_KEYS, true ) && is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_numeric( $item ) ) {
						$id = absint( $item );
						if ( $id > 0 ) {
							$ids[] = $id;
						}
					}
				}
				continue;
			}
		}
	}
}
