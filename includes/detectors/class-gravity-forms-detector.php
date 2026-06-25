<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by Gravity Forms.
 *
 * Scans all forms for image references in:
 *   - HTML fields (raw HTML content)
 *   - Image choice options on radio/checkbox/select fields
 *   - Confirmation messages (HTML)
 *   - Notification bodies (HTML)
 *
 * Records usage against post_id = form_id, post_type = 'gravityforms'.
 * Self-gates: only runs when Gravity Forms is active.
 */
class GravityFormsDetector implements MediaDetector {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'gravityforms';
	}

	public function is_available() {
		return class_exists( 'GFAPI' ) || function_exists( 'gravity_form' );
	}

	/**
	 * Called per-post by the scanner — we only run once on the first call
	 * (form ID 0 sentinel) then bail on subsequent posts to avoid re-scanning.
	 * Sitewide GF scan is triggered from complete_scan() instead via scan_all().
	 */
	public function detect( $post, $scan_id ) {
		return 0;
	}

	/**
	 * Scan all Gravity Forms. Called once per scan from MediaScanner::complete_scan().
	 */
	public function scan_all( $scan_id ) {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$forms    = \GFAPI::get_forms();
		$recorded = 0;

		foreach ( $forms as $form ) {
			$form_id = absint( $form['id'] );
			$found   = array(); // url/id => context label

			// 1. Fields
			foreach ( $form['fields'] ?? array() as $field ) {
				$field_label = $field->label ?? 'Field';

				// HTML fields — scan for image URLs in raw HTML
				if ( $field->type === 'html' ) {
					$html = $field->content ?? '';
					foreach ( $this->extract_urls_from_html( $html ) as $url ) {
						$found[ $url ] = 'Form: ' . $form['title'] . ' > HTML Field: ' . $field_label;
					}
				}

				// Image choices on radio/checkbox/select
				foreach ( $field->choices ?? array() as $choice ) {
					$img = $choice['image'] ?? '';
					if ( $img ) {
						$found[ $img ] = 'Form: ' . $form['title'] . ' > Choice Image: ' . $field_label;
					}
				}
			}

			// 2. Confirmations
			foreach ( $form['confirmations'] ?? array() as $confirmation ) {
				if ( ( $confirmation['type'] ?? '' ) === 'message' ) {
					$html = $confirmation['message'] ?? '';
					foreach ( $this->extract_urls_from_html( $html ) as $url ) {
						$found[ $url ] = 'Form: ' . $form['title'] . ' > Confirmation: ' . ( $confirmation['name'] ?? 'Default' );
					}
				}
			}

			// 3. Notifications
			foreach ( $form['notifications'] ?? array() as $notification ) {
				$html = $notification['message'] ?? '';
				foreach ( $this->extract_urls_from_html( $html ) as $url ) {
					$found[ $url ] = 'Form: ' . $form['title'] . ' > Notification: ' . ( $notification['name'] ?? 'Default' );
				}
			}

			// Resolve URLs to attachment IDs and record
			foreach ( $found as $url => $context ) {
				$id = absint( attachment_url_to_postid( $url ) );
				if ( $id > 0 ) {
					$this->storage->record_usage( array(
						'attachment_id' => $id,
						'post_id'       => $form_id,
						'post_type'     => 'gravityforms',
						'usage_type'    => 'gravityforms',
						'context'       => substr( $context, 0, 200 ),
						'scan_id'       => $scan_id,
					) );
					$recorded++;
				}
			}
		}

		return $recorded;
	}

	/**
	 * Extract image/media URLs from an HTML string.
	 *
	 * @return string[]
	 */
	private function extract_urls_from_html( $html ) {
		if ( empty( $html ) ) {
			return array();
		}

		$urls = array();

		// src="..." attributes (img, video poster, etc.)
		if ( preg_match_all( '/src=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp|svg|pdf|mp4))["\']?/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		// href="..." for linked images/files
		if ( preg_match_all( '/href=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp|pdf))["\']?/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}

		return array_unique( array_filter( $urls, function( $u ) {
			return strpos( $u, 'http' ) === 0;
		} ) );
	}
}
