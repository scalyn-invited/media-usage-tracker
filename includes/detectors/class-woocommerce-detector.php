<?php
namespace MediaUsageTracker\Scanner;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Detects media referenced by WooCommerce.
 *
 * Covers:
 *   - Product gallery images (_product_image_gallery postmeta — comma-separated IDs)
 *   - Product variation images (_thumbnail_id on variation posts)
 *   - Product category images (thumbnail_id term meta on product_cat)
 *
 * Note: featured product images (_thumbnail_id on products) are already
 * caught by FeaturedImageDetector and are not duplicated here.
 *
 * Self-gates: only runs when WooCommerce is active.
 */
class WooCommerceDetector implements MediaDetector {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function key() {
		return 'woocommerce';
	}

	public function is_available() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Per-post detection: product gallery + variation images.
	 */
	public function detect( $post, $scan_id ) {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$recorded = 0;

		// Product featured image + gallery images
		if ( $post->post_type === 'product' ) {
			// Featured/main product image
			$thumb_id = absint( get_post_meta( $post->ID, '_thumbnail_id', true ) );
			if ( $thumb_id > 0 ) {
				$this->storage->record_usage( array(
					'attachment_id' => $thumb_id,
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'usage_type'    => 'woocommerce',
					'context'       => 'WooCommerce: Product Image',
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}

			// Gallery images: _product_image_gallery = "1,2,3"
			$gallery = get_post_meta( $post->ID, '_product_image_gallery', true );
			if ( ! empty( $gallery ) ) {
				foreach ( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) as $id ) {
					$this->storage->record_usage( array(
						'attachment_id' => $id,
						'post_id'       => $post->ID,
						'post_type'     => $post->post_type,
						'usage_type'    => 'woocommerce',
						'context'       => 'WooCommerce: Product Gallery',
						'scan_id'       => $scan_id,
					) );
					$recorded++;
				}
			}
		}

		// Variation images: each variation is post_type=product_variation with _thumbnail_id
		if ( $post->post_type === 'product_variation' ) {
			$id = absint( get_post_meta( $post->ID, '_thumbnail_id', true ) );
			if ( $id > 0 ) {
				$this->storage->record_usage( array(
					'attachment_id' => $id,
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'usage_type'    => 'woocommerce',
					'context'       => 'WooCommerce: Variation Image',
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}
		}

		return $recorded;
	}

	/**
	 * Scan product category images — runs once per scan.
	 */
	public function scan_all( $scan_id ) {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$recorded = 0;

		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'ids',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		foreach ( $terms as $term_id ) {
			$id = absint( get_term_meta( $term_id, 'thumbnail_id', true ) );
			if ( $id > 0 ) {
				$term = get_term( $term_id, 'product_cat' );
				$this->storage->record_usage( array(
					'attachment_id' => $id,
					'post_id'       => $term_id,
					'post_type'     => 'product_cat',
					'usage_type'    => 'woocommerce',
					'context'       => 'WooCommerce: Category Image (' . ( $term->name ?? $term_id ) . ')',
					'scan_id'       => $scan_id,
				) );
				$recorded++;
			}
		}

		return $recorded;
	}
}
