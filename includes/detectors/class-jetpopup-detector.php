<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by JetPopup (Crocoblock).
 *
 * JetPopup stores two distinct sources of media:
 *
 * 1. Elementor widget content inside the popup post (`_elementor_data`).
 *    The existing ElementorDetector already handles this when it processes
 *    the popup's own post, so we do NOT re-scan it here.
 *
 * 2. JetPopup attachment conditions stored in `_jet_popup_settings` postmeta
 *    on REGULAR posts/pages. This meta describes which popups fire on a page
 *    AND can carry a background image set on the popup trigger/overlay. These
 *    are stored as a JSON structure with `background_image` objects.
 *
 * 3. Background image set directly on the popup post (`_jet_popup_settings`
 *    on posts of type `jet-popup`) — e.g. overlay background, close-button
 *    custom icon, etc.
 *
 * Self-gates: only runs when JetPopup is active.
 */
class JetPopupDetector implements MediaDetector {

	/** Postmeta key used by JetPopup for per-popup settings. */
	const SETTINGS_KEY = '_jet_popup_settings';

	/** JetPopup's own post type. */
	const POST_TYPE = 'jet-popup';

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'jetpopup';
	}

	public function is_available() {
		return class_exists( 'Jet_Popup' ) || function_exists( 'jet_popup' );
	}

	public function detect( $post, $scan_id ) {
		$raw = get_post_meta( $post->ID, self::SETTINGS_KEY, true );
		if ( empty( $raw ) ) {
			return 0;
		}

		$settings = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $settings ) ) {
			// Some versions store it slashed.
			if ( is_string( $raw ) ) {
				$settings = json_decode( stripslashes( $raw ), true );
			}
		}
		if ( ! is_array( $settings ) ) {
			return 0;
		}

		$ids = array();
		$this->collect_image_ids( $settings, $ids );
		$ids = array_unique( array_filter( $ids ) );

		$recorded = 0;
		foreach ( $ids as $id ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'jetpopup',
				'context'       => 'JetPopup: Background / Overlay Image',
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Recursively walk a JetPopup settings array collecting attachment IDs.
	 *
	 * JetPopup uses the same Elementor-style media control shape:
	 *   {"id": 123, "url": "https://..."}
	 *
	 * It also stores standalone numeric values for icon/image fields.
	 *
	 * @param mixed $node
	 * @param int[] $ids  Accumulator (by reference).
	 */
	private function collect_image_ids( $node, &$ids ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		// Elementor-style media control: numeric id + url.
		if ( isset( $node['id'] ) && is_numeric( $node['id'] )
			&& isset( $node['url'] ) && filter_var( $node['url'], FILTER_VALIDATE_URL ) ) {
			$id = absint( $node['id'] );
			if ( $id > 0 ) {
				$ids[] = $id;
				return; // No need to recurse into this leaf node.
			}
		}

		// Keys that directly hold a numeric attachment ID (e.g. close_icon_image).
		$direct_id_keys = array( 'attachment_id', 'image_id', 'icon_id' );
		foreach ( $direct_id_keys as $key ) {
			if ( isset( $node[ $key ] ) && is_numeric( $node[ $key ] ) ) {
				$id = absint( $node[ $key ] );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		// Recurse into child arrays.
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_image_ids( $value, $ids );
			}
		}
	}
}
