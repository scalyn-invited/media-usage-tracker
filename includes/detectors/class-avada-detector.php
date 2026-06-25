<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Avada / Fusion Builder.
 *
 * Avada stores media in three places:
 *
 * 1. post_content shortcodes — [fusion_imageframe src="URL"], [fusion_gallery
 *    ids="1,2,3"], [fusion_image_before_after before_image="URL" ...], etc.
 *    We extract attachment IDs from `ids` attributes and resolve URLs from
 *    `src`, `image`, `before_image`, `after_image`, `backgroundimage` attrs.
 *
 * 2. _fusion postmeta — Fusion's per-post settings (page header background,
 *    slider images, etc.) stored as a serialized array.
 *
 * 3. fusion_options / theme_mods (global) — sitewide header/logo/footer images.
 *    Handled in scan_all() rather than per-post.
 *
 * Self-gates: only runs when Avada / Fusion Builder is active.
 */
class AvadaDetector implements MediaDetector {

	use CssUrlScanner;

	private $storage;

	/** Fusion shortcode attributes that hold a single attachment URL. */
	const URL_ATTRS = array(
		'src', 'image', 'before_image', 'after_image',
		'backgroundimage', 'video_mp4', 'video_webm', 'video_ogv',
	);

	/** Fusion shortcode attributes that hold a comma-separated list of IDs. */
	const IDS_ATTRS = array( 'ids', 'gallery_ids' );

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'avada';
	}

	public function is_available() {
		return class_exists( 'FusionBuilder' ) || defined( 'FUSION_BUILDER_VERSION' )
			|| defined( 'AVADA_VERSION' ) || class_exists( 'Avada' );
	}

	public function detect( $post, $scan_id ) {
		$found = array(); // attachment_id → context label

		// ── 1. Shortcodes in post_content ────────────────────────────────────
		$content = $post->post_content ?? '';
		if ( $content !== '' ) {
			$this->extract_from_shortcodes( $content, $found );
		}

		// ── 2. _fusion postmeta ──────────────────────────────────────────────
		$fusion_meta = get_post_meta( $post->ID, '_fusion', true );
		if ( ! empty( $fusion_meta ) ) {
			$meta = is_string( $fusion_meta ) ? maybe_unserialize( $fusion_meta ) : $fusion_meta;
			if ( is_array( $meta ) ) {
				$this->extract_from_meta( $meta, $found );
			}
		}

		// CSS url() references in custom CSS within shortcodes
		$css_ids = array();
		$this->scan_text_for_css_urls( $content, $css_ids );
		foreach ( $css_ids as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = 'Custom CSS';
			}
		}

		$recorded = 0;
		foreach ( $found as $id => $label ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => $post->ID,
				'post_type'     => $post->post_type,
				'usage_type'    => 'avada',
				'context'       => 'Avada: ' . substr( (string) $label, 0, 180 ),
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Scan sitewide Avada/Fusion theme options for media references.
	 * Called once per scan by MediaScanner::scan_global_detectors().
	 */
	public function scan_all( $scan_id ) {
		if ( ! $this->is_available() ) {
			return;
		}

		$found = array();

		// Avada stores global options in 'fusion_options' or theme_mods.
		$options = get_option( 'fusion_options', array() );
		if ( empty( $options ) ) {
			$options = get_theme_mods();
		}

		if ( is_array( $options ) ) {
			$this->extract_from_meta( $options, $found );
		}

		foreach ( $found as $id => $label ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => 0,
				'post_type'     => 'sitewide',
				'usage_type'    => 'avada',
				'context'       => 'Avada Global: ' . substr( (string) $label, 0, 180 ),
				'scan_id'       => $scan_id,
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse Fusion Builder shortcodes in content and collect attachment IDs.
	 *
	 * @param string $content
	 * @param array  $found   attachment_id → label accumulator (by reference)
	 */
	private function extract_from_shortcodes( $content, &$found ) {
		// Match any [fusion_* ...] shortcode and capture its attribute string.
		if ( ! preg_match_all( '/\[fusion_\w+([^\]]*)\]/i', $content, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $attr_string ) {
			// ── IDs attributes (comma-separated attachment IDs) ───────────────
			foreach ( self::IDS_ATTRS as $attr ) {
				if ( preg_match( '/' . preg_quote( $attr, '/' ) . '\s*=\s*["\']([^"\']+)["\']/', $attr_string, $m ) ) {
					foreach ( explode( ',', $m[1] ) as $raw_id ) {
						$id = absint( trim( $raw_id ) );
						if ( $id > 0 && ! isset( $found[ $id ] ) ) {
							$found[ $id ] = 'Fusion shortcode ids';
						}
					}
				}
			}

			// ── URL attributes ────────────────────────────────────────────────
			foreach ( self::URL_ATTRS as $attr ) {
				if ( preg_match( '/' . preg_quote( $attr, '/' ) . '\s*=\s*["\']([^"\']+)["\']/', $attr_string, $m ) ) {
					$url = trim( $m[1] );
					if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
						$id = absint( attachment_url_to_postid( $url ) );
						if ( $id === 0 ) {
							$unscaled = preg_replace( '/-scaled(\.\w+)$/', '$1', $url );
							if ( $unscaled !== $url ) {
								$id = absint( attachment_url_to_postid( $unscaled ) );
							}
						}
						if ( $id > 0 && ! isset( $found[ $id ] ) ) {
							$found[ $id ] = 'Fusion shortcode ' . $attr;
						}
					}
				}
			}
		}
	}

	/**
	 * Walk a key→value options/meta array and collect attachment IDs.
	 * Handles numeric IDs, URL strings, and nested arrays.
	 *
	 * @param array $meta
	 * @param array $found  accumulator (by reference)
	 */
	private function extract_from_meta( array $meta, &$found ) {
		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->extract_from_meta( $value, $found );
				continue;
			}

			if ( is_numeric( $value ) ) {
				$id = absint( $value );
				if ( $id > 0 && get_post_type( $id ) === 'attachment' && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = (string) $key;
				}
				continue;
			}

			if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$id = absint( attachment_url_to_postid( $value ) );
				if ( $id === 0 ) {
					$unscaled = preg_replace( '/-scaled(\.\w+)$/', '$1', $value );
					if ( $unscaled !== $value ) {
						$id = absint( attachment_url_to_postid( $unscaled ) );
					}
				}
				if ( $id > 0 && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = (string) $key;
				}
			}
		}
	}
}
