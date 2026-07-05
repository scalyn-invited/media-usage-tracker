<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

class MediaByPage {

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function render() {
		global $wpdb;
		$table = $wpdb->prefix . 'mut_media_usage';

		$search = sanitize_text_field( $_GET['s'] ?? '' );

		$rows = $wpdb->get_results(
			"SELECT u.post_id, u.post_type, COUNT( DISTINCT u.attachment_id ) AS media_count,
			        GROUP_CONCAT( DISTINCT u.usage_type ORDER BY u.usage_type SEPARATOR ',' ) AS sources
			 FROM {$table} u
			 WHERE u.post_id > 0
			 GROUP BY u.post_id, u.post_type
			 ORDER BY media_count DESC"
		);

		$front_page_id = ( get_option( 'show_on_front' ) === 'page' ) ? (int) get_option( 'page_on_front' ) : 0;

		$filtered = array();
		foreach ( $rows as $row ) {
			$post = get_post( $row->post_id );
			if ( ! $post || $post->post_type !== 'page' ) {
				continue;
			}
			$row->title     = $post->post_title ?: '(no title)';
			$row->edit_link = get_edit_post_link( $row->post_id, 'raw' );
			$row->view_link = get_permalink( $row->post_id );
			$row->modified  = get_the_modified_date( 'M j, Y', $row->post_id );
			$row->post_type_label = 'Page';
			$row->status    = $post->post_status;
			$row->is_front_page = ( $front_page_id > 0 && (int) $row->post_id === $front_page_id );

			if ( $search !== '' && stripos( $row->title, $search ) === false ) {
				continue;
			}

			$filtered[] = $row;
		}
		?>
		<div class="wrap mut-mbp">
			<h1><i class="dashicons dashicons-layout" style="font-size:22px;vertical-align:middle;margin-right:6px;"></i> Media by Page</h1>
			<p class="description">Browse your pages to see which media files are used on each one.</p>

			<form method="get" style="margin:20px 0 16px;display:flex;gap:8px;">
				<input type="hidden" name="page" value="mut-media-by-page">
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search pages..." class="regular-text" style="flex:1;max-width:400px;">
				<button type="submit" class="button">Search</button>
				<?php if ( $search !== '' ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-media-by-page' ) ); ?>" class="button">Clear</a>
				<?php endif; ?>
			</form>

			<p style="color:#787c82;margin-bottom:16px;"><?php echo count( $filtered ); ?> page(s) with media files.</p>

			<?php if ( empty( $filtered ) ) : ?>
				<div class="mut-no-results"><p>No pages found. Run a scan first if you haven't already.</p></div>
			<?php else : ?>
				<?php foreach ( $filtered as $row ) : ?>
					<div class="mut-mbp-row" data-post-id="<?php echo esc_attr( $row->post_id ); ?>">
						<div class="mut-mbp-row-header" onclick="mutMbpToggle(this)">
							<span class="dashicons <?php echo esc_attr( $this->post_type_icon( $row->post_type ) ); ?> mut-mbp-row-icon"></span>
							<div class="mut-mbp-row-title">
								<?php echo esc_html( $row->title ); ?>
								<span class="mut-mbp-row-type"><?php echo esc_html( $row->post_type_label ); ?></span>
								<?php if ( $row->is_front_page ) : ?>
									<span class="mut-mbp-status-badge mut-mbp-status-front">Front Page</span>
								<?php endif; ?>
								<?php if ( $row->status !== 'publish' ) : ?>
									<span class="mut-mbp-status-badge mut-mbp-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span>
								<?php endif; ?>
							</div>
							<div class="mut-mbp-row-meta">
								<span>🖼 <?php echo (int) $row->media_count; ?> image<?php echo $row->media_count > 1 ? 's' : ''; ?></span>
							</div>
							<div class="mut-mbp-sources">
								<?php foreach ( $this->format_sources( $row->sources ) as $src ) : ?>
									<span class="mut-mbp-source-badge mut-mbp-src-<?php echo esc_attr( $src['class'] ); ?>"><?php echo esc_html( $src['label'] ); ?></span>
								<?php endforeach; ?>
							</div>
							<span class="dashicons dashicons-arrow-down-alt2 mut-mbp-chevron"></span>
						</div>
						<div class="mut-mbp-detail" style="display:none;">
							<div class="mut-mbp-loading" style="padding:20px;color:#787c82;">Loading...</div>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<style>
		.mut-mbp-pill {
			display: inline-flex; align-items: center; gap: 6px;
			padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
			text-decoration: none; color: #3c434a;
			border: 1px solid #c3c4c7; background: #fff; transition: all 0.15s;
		}
		.mut-mbp-pill:hover { border-color: #2271b1; color: #2271b1; }
		.mut-mbp-pill.active { background: #2271b1; color: #fff; border-color: #2271b1; }
		.mut-mbp-pill-count {
			background: rgba(0,0,0,0.08); padding: 1px 8px; border-radius: 10px; font-size: 12px;
		}
		.mut-mbp-pill.active .mut-mbp-pill-count { background: rgba(255,255,255,0.25); }

		.mut-mbp-row {
			border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 10px;
			background: #fff; overflow: hidden;
		}
		.mut-mbp-row-header {
			display: flex; align-items: center; gap: 14px; padding: 14px 18px;
			cursor: pointer; transition: background 0.15s;
		}
		.mut-mbp-row-header:hover { background: #f6f7f7; }
		.mut-mbp-row-icon { color: #787c82; font-size: 18px; }
		.mut-mbp-row-title { flex: 1; font-size: 15px; font-weight: 600; color: #1d2327; }
		.mut-mbp-row-type { font-weight: 400; color: #787c82; font-size: 13px; margin-left: 6px; }
		.mut-mbp-row-meta { font-size: 13px; color: #787c82; }
		.mut-mbp-sources { display: flex; gap: 6px; flex-wrap: wrap; }
		.mut-mbp-source-badge {
			font-size: 11px; font-weight: 600; padding: 2px 10px;
			border-radius: 10px; white-space: nowrap;
		}
		.mut-mbp-src-elementor  { background: #e3f2fd; color: #0d47a1; }
		.mut-mbp-src-acf        { background: #fce4ec; color: #880e4f; }
		.mut-mbp-src-woocommerce{ background: #e8f5e9; color: #1b5e20; }
		.mut-mbp-src-content    { background: #f3e5f5; color: #4a148c; }
		.mut-mbp-src-divi       { background: #ede7f6; color: #4527a0; }
		.mut-mbp-src-yoast      { background: #fff3e0; color: #e65100; }
		.mut-mbp-src-featured   { background: #e0f2f1; color: #004d40; }
		.mut-mbp-src-default    { background: #f5f5f5; color: #616161; }
		.mut-mbp-chevron { color: #787c82; transition: transform 0.2s; }
		.mut-mbp-row.open .mut-mbp-chevron { transform: rotate(180deg); }

		.mut-mbp-detail { border-top: 1px solid #e0e0e0; padding: 18px; }
		.mut-mbp-grid {
			display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
			gap: 12px; margin-bottom: 14px;
		}
		.mut-mbp-thumb {
			border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden;
			background: #fafafa; text-align: center;
		}
		.mut-mbp-thumb img {
			width: 100%; height: 80px; object-fit: cover; display: block;
			background: #f0f0f1;
		}
		.mut-mbp-thumb-info { padding: 6px 8px; }
		.mut-mbp-thumb-name {
			font-size: 11px; color: #1d2327; font-weight: 600;
			overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
		}
		.mut-mbp-thumb-source {
			font-size: 10px; color: #2271b1; background: #e6f1fb;
			padding: 1px 6px; border-radius: 4px; display: inline-block; margin-top: 3px;
		}
		.mut-mbp-group-header {
			display: flex; align-items: center; justify-content: space-between; gap: 8px;
			margin: 16px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #e0e0e0;
			font-size: 12px; font-weight: 600; color: #3c434a;
			text-transform: uppercase; letter-spacing: .03em;
		}
		.mut-mbp-group-header:first-child { margin-top: 0; }
		.mut-mbp-group-count {
			font-weight: 400; text-transform: none; letter-spacing: normal;
			color: #787c82; font-size: 11px;
		}
		.mut-mbp-detail-footer {
			display: flex; gap: 20px; font-size: 13px; color: #787c82;
			padding-top: 12px; border-top: 1px solid #f0f0f0; flex-wrap: wrap;
		}
		.mut-mbp-detail-footer a { color: #2271b1; text-decoration: none; margin-left: auto; }
		.mut-mbp-detail-footer a:hover { text-decoration: underline; }
		.mut-mbp-status-badge {
			display: inline-block; padding: 2px 8px; border-radius: 10px;
			font-size: 11px; font-weight: 600; margin-left: 6px; vertical-align: middle;
		}
		.mut-mbp-status-draft { background: #fef3cd; color: #856404; }
		.mut-mbp-status-pending { background: #e8f0fd; color: #1b4da3; }
		.mut-mbp-status-private { background: #f0e8f6; color: #4527a0; }
		.mut-mbp-status-trash { background: #fde8e8; color: #a32d2d; }
		.mut-mbp-status-front { background: #d7f5e0; color: #1a7a3a; }
		.mut-mbp-jet-notice {
			background: #fef8ee; border: 1px solid #f0d97a; border-radius: 6px;
			padding: 10px 14px; font-size: 13px; color: #6e5300; margin-bottom: 14px;
			line-height: 1.5;
		}
		</style>

		<script>
		function mutMbpToggle(header) {
			var row    = header.closest('.mut-mbp-row');
			var detail = row.querySelector('.mut-mbp-detail');
			var isOpen = row.classList.contains('open');

			if (isOpen) {
				row.classList.remove('open');
				detail.style.display = 'none';
				return;
			}

			row.classList.add('open');
			detail.style.display = 'block';

			if (detail.dataset.loaded) return;
			detail.dataset.loaded = '1';

			var postId = row.dataset.postId;
			jQuery.post(ajaxurl, {
				action: 'mut_media_by_page',
				post_id: postId,
				_wpnonce: '<?php echo wp_create_nonce( 'mut_media_by_page' ); ?>'
			}, function(res) {
				if (!res.success) {
					detail.innerHTML = '<p style="color:#d63638;">Failed to load.</p>';
					return;
				}

				var items = res.data.items;
				var dominantSource = res.data.dominant_source || '';

				// Bucket items by their group label (e.g. "Image Carousel
				// widget"), preserving first-seen order.
				var groupOrder = [];
				var groupMap   = {};
				for (var i = 0; i < items.length; i++) {
					var it = items[i];
					var g  = it.group || it.source || 'Other';
					if (!groupMap[g]) {
						groupMap[g] = [];
						groupOrder.push(g);
					}
					groupMap[g].push(it);
				}

				function renderThumb(it) {
					var out = '<div class="mut-mbp-thumb" onclick="mutMbpLightbox(\'' + it.full.replace(/'/g, "\\'") + '\', \'' + it.filename.replace(/'/g, "\\'") + '\')" style="cursor:pointer;" title="Click to preview">';
					out += '<img src="' + it.thumb + '" alt="" loading="lazy">';
					out += '<div class="mut-mbp-thumb-info">';
					out += '<div class="mut-mbp-thumb-name" title="' + it.filename + '">' + it.filename + '</div>';
					// Only call out the source when it differs from the page's
					// dominant one (already shown once in the row header).
					if (it.source !== dominantSource) {
						out += '<span class="mut-mbp-thumb-source">' + it.source + '</span>';
					}
					out += '</div></div>';
					return out;
				}

				var html = '';
				// A single group reads the same as before (no extra heading
				// noise for a page that's just one kind of content).
				var showGroupHeadings = groupOrder.length > 1;
				for (var gi = 0; gi < groupOrder.length; gi++) {
					var groupName  = groupOrder[gi];
					var groupItems = groupMap[groupName];
					if (showGroupHeadings) {
						html += '<div class="mut-mbp-group-header"><span>' + groupName + '</span>';
						html += '<span class="mut-mbp-group-count">' + groupItems.length + ' image' + (groupItems.length !== 1 ? 's' : '') + '</span></div>';
					}
					html += '<div class="mut-mbp-grid">';
					for (var j = 0; j < groupItems.length; j++) {
						html += renderThumb(groupItems[j]);
					}
					html += '</div>';
				}
				var dynItems = res.data.dynamic_items || [];
				if (dynItems.length > 0) {
					html += '<div class="mut-mbp-dynamic-section">';
					html += '<div class="mut-mbp-dynamic-header">';
					html += '<span class="dashicons dashicons-update" style="color:#2271b1;margin-right:6px;font-size:16px;vertical-align:middle;"></span>';
					html += '<strong>Dynamic content (loaded via listings)</strong>';
					html += '<span class="mut-mbp-dynamic-count">' + dynItems.length + ' image' + (dynItems.length !== 1 ? 's' : '') + '</span>';
					html += '</div>';
					html += '<div class="mut-mbp-grid">';
					for (var d = 0; d < dynItems.length; d++) {
						var di = dynItems[d];
						html += '<div class="mut-mbp-thumb" onclick="mutMbpLightbox(\'' + di.full.replace(/'/g, "\\'") + '\', \'' + di.filename.replace(/'/g, "\\'") + '\')" style="cursor:pointer;" title="Click to preview">';
						html += '<img src="' + di.thumb + '" alt="" loading="lazy">';
						html += '<div class="mut-mbp-thumb-info">';
						html += '<div class="mut-mbp-thumb-name" title="' + di.filename + '">' + di.filename + '</div>';
						html += '<span class="mut-mbp-thumb-source mut-mbp-src-jet">' + di.source + '</span>';
						if (di.from) { html += '<span class="mut-mbp-thumb-source" style="font-size:10px;background:#f0f0f1;color:#646970;">from ' + di.from + '</span>'; }
						html += '</div></div>';
					}
					html += '</div></div>';
				} else if (res.data.has_jet_listing) {
					html += '<div class="mut-mbp-jet-notice">';
					html += '<span class="dashicons dashicons-info" style="color:#2271b1;margin-right:6px;font-size:16px;vertical-align:middle;"></span>';
					html += 'This page uses JetEngine dynamic content but no dynamic images were found.';
					html += '</div>';
				}
				html += '<div class="mut-mbp-detail-footer">';
				html += '<span>📅 Modified: ' + res.data.modified + '</span>';
				html += '<span>💾 Total: ' + res.data.total_size + '</span>';
				if (res.data.edit_link) {
					html += '<a href="' + res.data.edit_link + '" target="_blank">✏️ Edit page</a>';
				}
				html += '</div>';
				detail.innerHTML = html;
			});
		}
		function mutMbpLightbox(url, name) {
			var overlay = document.getElementById('mut-mbp-lightbox');
			if (!overlay) {
				overlay = document.createElement('div');
				overlay.id = 'mut-mbp-lightbox';
				overlay.innerHTML = '<div class="mut-mbp-lb-backdrop"></div><div class="mut-mbp-lb-content"><button class="mut-mbp-lb-close">&times;</button><img class="mut-mbp-lb-img" src="" alt=""><div class="mut-mbp-lb-name"></div></div>';
				document.body.appendChild(overlay);
				overlay.querySelector('.mut-mbp-lb-backdrop').addEventListener('click', function(){ overlay.style.display = 'none'; });
				overlay.querySelector('.mut-mbp-lb-close').addEventListener('click', function(){ overlay.style.display = 'none'; });
				document.addEventListener('keydown', function(e){ if (e.key === 'Escape') overlay.style.display = 'none'; });
			}
			overlay.querySelector('.mut-mbp-lb-img').src = url;
			overlay.querySelector('.mut-mbp-lb-name').textContent = name;
			overlay.style.display = 'flex';
		}
		</script>

		<style>
		#mut-mbp-lightbox {
			display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
			z-index: 999999; align-items: center; justify-content: center;
		}
		.mut-mbp-lb-backdrop {
			position: absolute; top: 0; left: 0; width: 100%; height: 100%;
			background: rgba(0,0,0,0.8);
		}
		.mut-mbp-lb-content {
			position: relative; z-index: 1; max-width: 90vw; max-height: 90vh; text-align: center;
		}
		.mut-mbp-lb-img {
			max-width: 90vw; max-height: 80vh; object-fit: contain;
			border-radius: 4px; background: #fff;
		}
		.mut-mbp-lb-name {
			color: #fff; font-size: 13px; margin-top: 10px; opacity: 0.8;
		}
		.mut-mbp-lb-close {
			position: absolute; top: 8px; right: 8px; width: 36px; height: 36px;
			border-radius: 50%; border: none; background: #fff; color: #1d2327;
			font-size: 22px; cursor: pointer; line-height: 36px; text-align: center;
			box-shadow: 0 2px 8px rgba(0,0,0,0.3);
		}
		.mut-mbp-lb-close:hover { background: #f0f0f0; }
		.mut-mbp-thumb:hover img { opacity: 0.8; transition: opacity 0.15s; }
		</style>
		<?php
	}

	public function ajax_handler() {
		check_ajax_referer( 'mut_media_by_page' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mut_media_usage';

		$usages = $wpdb->get_results( $wpdb->prepare(
			"SELECT attachment_id, usage_type, context FROM {$table} WHERE post_id = %d",
			$post_id
		) );

		$source_labels = array(
			'content'        => 'WordPress',
			'elementor'      => 'Elementor',
			'acf'            => 'ACF',
			'woocommerce'    => 'WooCommerce',
			'divi'           => 'Divi',
			'wpbakery'       => 'WPBakery',
			'beaver_builder' => 'Beaver Builder',
			'avada'          => 'Avada',
			'yoast'          => 'Yoast SEO',
			'jetengine'      => 'JetEngine',
			'jetpopup'       => 'JetPopup',
			'gravity_forms'  => 'Gravity Forms',
			'astra'          => 'Astra',
			'featured_image' => 'Featured Image',
			'gallery'        => 'Gallery',
		);

		// Generic sources that should be replaced by a more specific one.
		$generic_sources = array( 'content', 'gallery', 'featured_image' );

		// Build best source per attachment: prefer specific detectors over generic.
		// Carries the recorded `context` along too — for Elementor that's now
		// the owning widget's label (e.g. "Image Carousel widget"), which is
		// what we group thumbnails by below.
		$best_source = array();
		foreach ( $usages as $u ) {
			$aid  = (int) $u->attachment_id;
			$type = $u->usage_type;
			if ( ! isset( $best_source[ $aid ] ) || in_array( $best_source[ $aid ]['type'], $generic_sources, true ) ) {
				$best_source[ $aid ] = array( 'type' => $type, 'context' => $u->context );
			}
		}

		$items      = array();
		$total_bytes = 0;
		$seen       = array();

		foreach ( $best_source as $aid => $info ) {
			if ( isset( $seen[ $aid ] ) ) {
				continue;
			}
			$seen[ $aid ] = true;

			$source_type = $info['type'];
			$context     = $info['context'];

			$file  = get_attached_file( $aid );
			$thumb = wp_get_attachment_image_url( $aid, 'thumbnail' );
			if ( ! $thumb ) {
				$thumb = wp_get_attachment_image_url( $aid, 'full' );
			}
			if ( ! $thumb ) {
				$thumb = includes_url( 'images/media/default.png' );
			}

			$bytes = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
			$total_bytes += $bytes;

			$full_url = wp_get_attachment_url( $aid ) ?: $thumb;

			$source_label = $source_labels[ $source_type ] ?? ucfirst( $source_type );

			// Group thumbnails by the Elementor widget they live in (e.g.
			// "Image Carousel widget") when we have one; everything else
			// (ACF, plain WordPress content, etc.) groups by its source.
			$group = ( $source_type === 'elementor' && ! empty( $context ) && $context !== 'Elementor' )
				? $context
				: $source_label;

			$items[] = array(
				'id'       => $aid,
				'filename' => basename( $file ?: get_the_title( $aid ) ),
				'thumb'    => $thumb,
				'full'     => $full_url,
				'source'   => $source_label,
				'group'    => $group,
				'size'     => size_format( $bytes ),
			);
		}

		$post = get_post( $post_id );

		// The page's dominant source (e.g. "Elementor" for an Elementor-built
		// page) is already shown once in the row header, so per-image badges
		// only need to call out images that come from somewhere *different*.
		$source_type_counts = array_count_values( wp_list_pluck( $best_source, 'type' ) );
		arsort( $source_type_counts );
		$dominant_type  = key( $source_type_counts ) ?: '';
		$dominant_source = $source_labels[ $dominant_type ] ?? ( $dominant_type !== '' ? ucfirst( $dominant_type ) : '' );

		// Cross-reference JetEngine dynamic listings
		$dynamic_items   = array();
		$has_jet_listing = false;
		$elementor_data  = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $elementor_data ) && (
			strpos( $elementor_data, 'jet-listing-grid' ) !== false ||
			strpos( $elementor_data, 'jet-engine' ) !== false ||
			strpos( $elementor_data, 'jetengine' ) !== false
		) ) {
			$has_jet_listing = true;
			$dynamic_items   = $this->resolve_jet_dynamic_images( $elementor_data, $table, $seen );
		}

		wp_send_json_success( array(
			'items'           => $items,
			'dynamic_items'   => $dynamic_items,
			'modified'        => get_the_modified_date( 'M j, Y', $post_id ),
			'total_size'      => size_format( $total_bytes ),
			'edit_link'       => get_edit_post_link( $post_id, 'raw' ) ?: '',
			'has_jet_listing' => $has_jet_listing,
			'dominant_source' => $dominant_source,
		) );
	}

	private function resolve_jet_dynamic_images( $elementor_data, $table, &$seen ) {
		global $wpdb;

		$data = json_decode( $elementor_data, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( stripslashes( $elementor_data ), true );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}

		$listing_ids = array();
		$this->find_jet_listings( $data, $listing_ids );

		if ( empty( $listing_ids ) ) {
			return array();
		}

		$post_types = array();
		foreach ( $listing_ids as $lid ) {
			$lid = absint( $lid );
			if ( ! $lid ) continue;
			$listing_data = get_post_meta( $lid, '_listing_data', true );
			if ( is_array( $listing_data ) && ! empty( $listing_data['post_type'] ) ) {
				$post_types[] = $listing_data['post_type'];
				continue;
			}
			$page_settings = get_post_meta( $lid, '_elementor_page_settings', true );
			if ( is_array( $page_settings ) && ! empty( $page_settings['listing_post_type'] ) ) {
				$post_types[] = $page_settings['listing_post_type'];
			}
		}

		$post_types = array_unique( array_filter( $post_types ) );
		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT u.attachment_id, u.post_id, u.usage_type
			 FROM {$table} u WHERE u.post_type IN ({$placeholders}) ORDER BY u.post_id",
			...$post_types
		) );

		$dynamic = array();
		foreach ( $rows as $r ) {
			$aid = (int) $r->attachment_id;
			if ( isset( $seen[ $aid ] ) ) continue;
			$seen[ $aid ] = true;

			$file  = get_attached_file( $aid );
			$thumb = wp_get_attachment_image_url( $aid, 'thumbnail' );
			if ( ! $thumb ) $thumb = wp_get_attachment_image_url( $aid, 'full' );
			if ( ! $thumb ) $thumb = includes_url( 'images/media/default.png' );

			$source_labels = array(
				'jetengine'      => 'JetEngine',
				'acf'            => 'ACF',
				'content'        => 'WordPress',
				'featured_image' => 'Featured Image',
				'elementor'      => 'Elementor',
				'woocommerce'    => 'WooCommerce',
				'yoast'          => 'Yoast SEO',
				'gravity_forms'  => 'Gravity Forms',
			);
			$src_label = $source_labels[ $r->usage_type ] ?? ucfirst( $r->usage_type );

			$dynamic[] = array(
				'id'       => $aid,
				'filename' => basename( $file ?: get_the_title( $aid ) ),
				'thumb'    => $thumb,
				'full'     => wp_get_attachment_url( $aid ) ?: $thumb,
				'source'   => $src_label,
				'from'     => get_the_title( $r->post_id ),
			);
		}
		return $dynamic;
	}

	private function find_jet_listings( $node, &$listing_ids ) {
		if ( ! is_array( $node ) ) return;

		if ( isset( $node['widgetType'] ) && strpos( $node['widgetType'], 'jet-listing-grid' ) !== false ) {
			foreach ( array( 'lisitng_id', 'listin_id', 'listing_id' ) as $key ) {
				if ( ! empty( $node['settings'][ $key ] ) ) {
					$listing_ids[] = $node['settings'][ $key ];
				}
			}
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				$this->find_jet_listings( $child, $listing_ids );
			}
		}

		if ( isset( $node[0] ) ) {
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$this->find_jet_listings( $child, $listing_ids );
				}
			}
		}
	}

	private function post_type_icon( $type ) {
		$icons = array(
			'page'    => 'dashicons-admin-page',
			'post'    => 'dashicons-admin-post',
			'product' => 'dashicons-cart',
		);
		return $icons[ $type ] ?? 'dashicons-media-default';
	}

	private function format_sources( $sources_csv ) {
		$labels = array(
			'content'        => array( 'label' => 'WordPress',      'class' => 'content' ),
			'elementor'      => array( 'label' => 'Elementor',      'class' => 'elementor' ),
			'acf'            => array( 'label' => 'ACF',            'class' => 'acf' ),
			'woocommerce'    => array( 'label' => 'WooCommerce',    'class' => 'woocommerce' ),
			'divi'           => array( 'label' => 'Divi',           'class' => 'divi' ),
			'wpbakery'       => array( 'label' => 'WPBakery',       'class' => 'default' ),
			'beaver_builder' => array( 'label' => 'Beaver Builder', 'class' => 'default' ),
			'avada'          => array( 'label' => 'Avada',          'class' => 'default' ),
			'yoast'          => array( 'label' => 'Yoast SEO',      'class' => 'yoast' ),
			'jetengine'      => array( 'label' => 'JetEngine',      'class' => 'default' ),
			'jetpopup'       => array( 'label' => 'JetPopup',       'class' => 'default' ),
			'gravity_forms'  => array( 'label' => 'Gravity Forms',  'class' => 'default' ),
			'featured_image' => array( 'label' => 'Featured Image', 'class' => 'featured' ),
			'gallery'        => array( 'label' => 'Gallery',        'class' => 'content' ),
			'astra'          => array( 'label' => 'Astra',          'class' => 'default' ),
		);

		$out = array();
		foreach ( explode( ',', $sources_csv ) as $key ) {
			$key = trim( $key );
			if ( $key !== '' && isset( $labels[ $key ] ) ) {
				$out[ $key ] = $labels[ $key ];
			}
		}
		return $out;
	}
}
