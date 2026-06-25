<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Elementor.
 *
 * Elementor stores its layout as a JSON tree in the _elementor_data postmeta.
 * Every media control serializes as an object carrying a numeric attachment id
 * plus a url, e.g.  {"id":123,"url":"…"}  — for single images, and arrays of
 * those for galleries / carousels / background overlays.
 *
 * Element nodes themselves also have an "id", but it's an alphanumeric hash
 * (e.g. "1a2b3c4"), never numeric. So we walk the whole tree and collect any
 * node whose `id` is numeric AND which carries a `url`/`source`. This captures
 * media across all widget types without enumerating them.
 *
 * Records non-image attachments too (background video, file links).
 *
 * Self-gates: only runs when Elementor is active.
 */
class ElementorDetector implements MediaDetector {

	use CssUrlScanner;

	const META_KEY = '_elementor_data';

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'elementor';
	}

	public function is_available() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public function detect( $post, $scan_id ) {
		$raw = get_post_meta( $post->ID, self::META_KEY, true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return 0;
		}

		$data = json_decode( $raw, true );
		if ( null === $data ) {
			// Some stores keep the JSON slashed; retry after stripping.
			$data = json_decode( stripslashes( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$ids = array();
		$this->collect_media_ids( $data, $ids );
		$this->collect_css_media_ids( $data, $ids );

		$ids = array_unique( array_filter( $ids ) );

		$recorded = 0;
		foreach ( $ids as $id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'elementor',
				'context'       => 'Elementor',
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Recursively walk the Elementor tree, collecting attachment IDs from any
	 * media-control node (numeric `id` + a `url`/`source`).
	 *
	 * @param mixed $node
	 * @param int[] $ids  Accumulator (by reference).
	 */
	private function collect_media_ids( $node, &$ids ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		// A media control: numeric id paired with a url/source.
		if ( isset( $node['id'] ) && is_numeric( $node['id'] )
			&& ( isset( $node['url'] ) || isset( $node['source'] ) ) ) {
			$id = absint( $node['id'] );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			// Fall through: galleries can nest, so keep walking children too.
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_media_ids( $value, $ids );
			}
		}
	}

	/**
	 * Walk the Elementor tree looking for custom_css settings that contain
	 * url() references to wp-content/uploads, and resolve them to attachment IDs.
	 */
	private function collect_css_media_ids( $node, &$ids ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['settings']['custom_css'] ) && is_string( $node['settings']['custom_css'] ) ) {
			$this->extract_css_urls( $node['settings']['custom_css'], $ids );
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$this->collect_css_media_ids( $child, $ids );
			}
		}

		if ( ! isset( $node['elements'] ) ) {
			foreach ( $node as $value ) {
				if ( is_array( $value ) ) {
					$this->collect_css_media_ids( $value, $ids );
				}
			}
		}
	}

}
