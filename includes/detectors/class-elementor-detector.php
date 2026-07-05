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
 * While walking, we also track the nearest ancestor widget's `widgetType`
 * (e.g. "image-carousel", "slides", "image") so each recorded usage's context
 * says *which section/widget* the image lives in, rather than just
 * "Elementor" — this powers the Media by Page grouping.
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

		// attachment_id => owning widgetType (first widget it's found in wins).
		$id_widgets = array();
		$this->collect_media_ids( $data, $id_widgets, '' );
		$this->collect_css_media_ids( $data, $id_widgets, '' );

		$recorded = 0;
		foreach ( $id_widgets as $id => $widget_type ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'elementor',
				'context'       => $this->widget_label( $widget_type ),
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Recursively walk the Elementor tree, collecting attachment IDs from any
	 * media-control node (numeric `id` + a `url`/`source`), tagged with the
	 * nearest ancestor widget's type.
	 *
	 * @param mixed    $node
	 * @param int[]    $id_widgets   Accumulator: attachment_id => widgetType (by reference).
	 * @param string   $widget_type  Nearest ancestor widgetType seen so far.
	 */
	private function collect_media_ids( $node, &$id_widgets, $widget_type ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ) {
			$widget_type = $node['widgetType'];
		}

		// A media control: numeric id paired with a url/source.
		if ( isset( $node['id'] ) && is_numeric( $node['id'] )
			&& ( isset( $node['url'] ) || isset( $node['source'] ) ) ) {
			$id = absint( $node['id'] );
			if ( $id > 0 && ! isset( $id_widgets[ $id ] ) ) {
				$id_widgets[ $id ] = $widget_type;
			}
			// Fall through: galleries can nest, so keep walking children too.
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_media_ids( $value, $id_widgets, $widget_type );
			}
		}
	}

	/**
	 * Walk the Elementor tree looking for custom_css settings that contain
	 * url() references to wp-content/uploads, and resolve them to attachment
	 * IDs, tagged with the nearest ancestor widget's type.
	 */
	private function collect_css_media_ids( $node, &$id_widgets, $widget_type ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ) {
			$widget_type = $node['widgetType'];
		}

		if ( isset( $node['settings']['custom_css'] ) && is_string( $node['settings']['custom_css'] ) ) {
			$css_ids = array();
			$this->extract_css_urls( $node['settings']['custom_css'], $css_ids );
			foreach ( $css_ids as $cid ) {
				$cid = absint( $cid );
				if ( $cid > 0 && ! isset( $id_widgets[ $cid ] ) ) {
					$id_widgets[ $cid ] = $widget_type;
				}
			}
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$this->collect_css_media_ids( $child, $id_widgets, $widget_type );
			}
		}

		if ( ! isset( $node['elements'] ) ) {
			foreach ( $node as $value ) {
				if ( is_array( $value ) ) {
					$this->collect_css_media_ids( $value, $id_widgets, $widget_type );
				}
			}
		}
	}

	/**
	 * Turn an Elementor widgetType key into a human-readable label. Unknown
	 * (e.g. third-party addon) widget types are humanized generically rather
	 * than dropped, so every widget still gets its own sensible group.
	 */
	private function widget_label( $widget_type ) {
		if ( $widget_type === '' ) {
			return 'Elementor';
		}

		$known = array(
			'image'                     => 'Single Image',
			'image-box'                 => 'Image Box',
			'image-carousel'            => 'Image Carousel',
			'image-gallery'             => 'Gallery',
			'gallery'                   => 'Gallery',
			'slides'                    => 'Slides',
			'media-carousel'            => 'Media Carousel',
			'icon-box'                  => 'Icon Box',
			'video'                     => 'Video',
			'testimonial-carousel'      => 'Testimonial Carousel',
			'logo-grid'                 => 'Logo Grid',
			'theme-post-featured-image' => 'Featured Image (Theme Builder)',
		);
		if ( isset( $known[ $widget_type ] ) ) {
			return $known[ $widget_type ] . ' widget';
		}

		$label = ucwords( str_replace( array( '-', '_' ), ' ', $widget_type ) );
		return $label . ' widget';
	}

}
