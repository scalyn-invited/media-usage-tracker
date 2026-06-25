<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by the Astra theme.
 *
 * Astra stores its customizer settings in theme mods ('theme_mods_astra').
 * Image references include:
 *   - custom_logo          WordPress core theme mod — attachment ID
 *   - Site logo, retina logo, mobile logo (Astra Pro) — attachment IDs
 *   - Background images in header, footer, sections — URLs or IDs
 *
 * Runs once per scan via scan_all() since these are sitewide settings.
 * Self-gates: only runs when Astra theme is active.
 */
class AstraDetector implements MediaDetector {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'astra';
	}

	public function is_available() {
		return defined( 'ASTRA_THEME_VERSION' ) || defined( 'ASTRA_THEME_SETTINGS' ) || get_template() === 'astra';
	}

	/**
	 * Per-post detection — not used for Astra (sitewide theme settings only).
	 */
	public function detect( $post, $scan_id ) {
		return 0;
	}

	/**
	 * Scan Astra theme mods for media references. Called once per scan.
	 */
	public function scan_all( $scan_id ) {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$recorded = 0;
		$found    = array(); // attachment_id => context label

		// 1. WordPress core custom_logo (theme mod, attachment ID).
		$logo_id = absint( get_theme_mod( 'custom_logo', 0 ) );
		if ( $logo_id > 0 ) {
			$found[ $logo_id ] = 'Astra: Site Logo';
		}

		// 2. Walk all Astra theme mods for image IDs and URLs.
		$mods = get_theme_mods();
		if ( is_array( $mods ) ) {
			$this->walk_mods( $mods, $found );
		}

		foreach ( $found as $id => $context ) {
			$this->storage->record_usage( array(
				'attachment_id' => $id,
				'post_id'       => 0,
				'post_type'     => 'sitewide',
				'usage_type'    => 'astra',
				'context'       => substr( (string) $context, 0, 200 ),
				'scan_id'       => $scan_id,
			) );
			$recorded++;
		}

		return $recorded;
	}

	/**
	 * Recursively walk theme mods array collecting attachment IDs.
	 */
	private function walk_mods( array $mods, &$found ) {
		// Known Astra keys that store attachment IDs directly.
		$id_keys = array(
			'astra-header-responsive-logo',
			'astra-header-logo',
			'astra-retina-logo',
			'astra-mobile-header-logo',
			'astra-mobile-header-retina-logo',
			'site-logo',
			'logo',
		);

		foreach ( $mods as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->walk_mods( $value, $found );
				continue;
			}

			// Numeric value matching a known ID key.
			if ( in_array( $key, $id_keys, true ) && is_numeric( $value ) ) {
				$id = absint( $value );
				if ( $id > 0 && get_post_type( $id ) === 'attachment' && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = 'Astra: ' . $key;
				}
				continue;
			}

			// Any key ending in -image, -logo, -bg, -background that holds a numeric ID.
			if ( is_numeric( $value ) && preg_match( '/[-_](?:image|logo|bg|background|icon)(?:_id)?$/i', (string) $key ) ) {
				$id = absint( $value );
				if ( $id > 0 && get_post_type( $id ) === 'attachment' && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = 'Astra: ' . $key;
				}
				continue;
			}

			// URL string — resolve to attachment ID.
			if ( is_string( $value ) && strpos( $value, 'http' ) === 0 && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$id = absint( attachment_url_to_postid( $value ) );
				if ( $id === 0 ) {
					$unscaled = preg_replace( '/-scaled(\.\w+)$/', '$1', $value );
					if ( $unscaled !== $value ) {
						$id = absint( attachment_url_to_postid( $unscaled ) );
					}
				}
				if ( $id > 0 && ! isset( $found[ $id ] ) ) {
					$found[ $id ] = 'Astra: ' . $key;
				}
			}
		}
	}
}
