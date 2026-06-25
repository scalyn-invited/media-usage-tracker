<?php
namespace MediaUsageTracker\Admin;

/**
 * Image Replace — swap a media file with a new upload while keeping the same
 * attachment ID, filename, and all references intact.
 *
 * Adds a "Replace Image" metabox on the Edit Media screen and handles the
 * file swap via AJAX.
 */
class ImageReplace {

	public function __construct() {
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_metabox' ) );
		add_action( 'wp_ajax_mut_replace_image', array( $this, 'handle_replace' ) );
	}

	public function add_metabox( $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			return;
		}

		wp_enqueue_media();

		add_meta_box(
			'mut-replace-image',
			'🔄 Replace Image',
			array( $this, 'render_metabox' ),
			'attachment',
			'side',
			'high'
		);
	}

	public function render_metabox( $post ) {
		$file = get_attached_file( $post->ID );
		$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
		$nonce = wp_create_nonce( 'mut_replace_image_' . $post->ID );
		?>
		<div id="mut-replace-wrap">
			<p style="margin:0 0 10px;font-size:13px;color:#646970;">
				Replace the current image. The filename and attachment ID stay the same so all references continue to work.
			</p>
			<p style="margin:0 0 10px;font-size:13px;">
				<strong>Current size:</strong> <?php echo esc_html( size_format( $size ) ); ?>
			</p>

			<div style="display:flex;gap:0;margin-bottom:12px;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;">
				<button type="button" class="mut-rep-tab active" data-tab="local"
					style="flex:1;padding:7px 0;font-size:12px;font-weight:600;cursor:pointer;border:none;background:#f0f0f1;color:#1d2327;text-align:center;">
					📁 From Computer
				</button>
				<button type="button" class="mut-rep-tab" data-tab="library"
					style="flex:1;padding:7px 0;font-size:12px;font-weight:600;cursor:pointer;border:none;background:#fff;color:#787c82;text-align:center;border-left:1px solid #c3c4c7;">
					🖼 From Media Library
				</button>
			</div>

			<!-- Tab: Local upload -->
			<div id="mut-rep-local" class="mut-rep-panel">
				<input type="file" id="mut-replace-file" accept="image/*" style="width:100%;margin-bottom:10px;">
			</div>

			<!-- Tab: Media Library picker -->
			<div id="mut-rep-library" class="mut-rep-panel" style="display:none;">
				<div id="mut-rep-lib-preview" style="display:none;margin-bottom:10px;padding:10px;background:#f6f7f7;border-radius:4px;text-align:center;">
					<img id="mut-rep-lib-thumb" src="" style="max-width:100%;max-height:120px;border-radius:3px;">
					<div id="mut-rep-lib-name" style="font-size:12px;color:#646970;margin-top:6px;"></div>
					<div id="mut-rep-lib-size" style="font-size:11px;color:#787c82;"></div>
				</div>
				<button type="button" id="mut-rep-lib-btn" class="button" style="width:100%;">
					Choose from Media Library
				</button>
				<input type="hidden" id="mut-rep-lib-id" value="">
			</div>

			<button type="button" id="mut-replace-btn" class="button button-primary" style="width:100%;margin-top:8px;" disabled>
				Replace Image
			</button>
			<div id="mut-replace-status" style="margin-top:10px;font-size:12px;display:none;"></div>
		</div>

		<script>
		(function($){
			var postId    = <?php echo (int) $post->ID; ?>;
			var nonce     = '<?php echo $nonce; ?>';
			var mode      = 'local';
			var libId     = 0;

			var fileInput = document.getElementById('mut-replace-file');
			var btn       = document.getElementById('mut-replace-btn');
			var status    = document.getElementById('mut-replace-status');

			// Tab switching
			$('.mut-rep-tab').on('click', function(){
				$('.mut-rep-tab').removeClass('active').css({background:'#fff',color:'#787c82'});
				$(this).addClass('active').css({background:'#f0f0f1',color:'#1d2327'});
				mode = $(this).data('tab');
				$('#mut-rep-local').toggle(mode === 'local');
				$('#mut-rep-library').toggle(mode === 'library');
				status.style.display = 'none';
				updateBtn();
			});

			function updateBtn(){
				if (mode === 'local') {
					btn.disabled = !fileInput.files.length;
				} else {
					btn.disabled = !libId;
				}
			}

			fileInput.addEventListener('change', function(){
				status.style.display = 'none';
				updateBtn();
			});

			// Media Library picker
			var frame;
			$('#mut-rep-lib-btn').on('click', function(e){
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({
					title: 'Select Replacement Image',
					library: { type: 'image' },
					button: { text: 'Use This Image' },
					multiple: false
				});
				frame.on('select', function(){
					var att = frame.state().get('selection').first().toJSON();
					if (att.id === postId) {
						status.style.display = 'block';
						status.style.color = '#d63638';
						status.textContent = 'Cannot replace an image with itself.';
						return;
					}
					libId = att.id;
					$('#mut-rep-lib-id').val(att.id);
					$('#mut-rep-lib-thumb').attr('src', att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url);
					$('#mut-rep-lib-name').text(att.filename);
					$('#mut-rep-lib-size').text(att.filesizeHumanReadable || '');
					$('#mut-rep-lib-preview').show();
					status.style.display = 'none';
					updateBtn();
				});
				frame.open();
			});

			// Replace action
			btn.addEventListener('click', function(){
				btn.disabled = true;
				btn.textContent = 'Replacing...';
				status.style.display = 'block';
				status.style.color = '#2271b1';
				status.textContent = 'Replacing image...';

				var fd = new FormData();
				fd.append('action', 'mut_replace_image');
				fd.append('attachment_id', postId);
				fd.append('_wpnonce', nonce);

				if (mode === 'local') {
					var file = fileInput.files[0];
					if (!file || !file.type.match(/^image\//)) {
						status.style.color = '#d63638';
						status.textContent = 'Please select a valid image file.';
						btn.disabled = false;
						btn.textContent = 'Replace Image';
						return;
					}
					fd.append('file', file);
					fd.append('source', 'local');
				} else {
					if (!libId) return;
					fd.append('source_id', libId);
					fd.append('source', 'library');
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: fd,
					processData: false,
					contentType: false,
					success: function(res){
						if (res.success) {
							status.style.color = '#00a32a';
							status.innerHTML = '✓ Image replaced. New size: <strong>' + res.data.new_size + '</strong>';
							setTimeout(function(){ location.reload(); }, 1500);
						} else {
							status.style.color = '#d63638';
							status.textContent = '✗ ' + (res.data || 'Replace failed.');
							btn.disabled = false;
							btn.textContent = 'Replace Image';
						}
					},
					error: function(){
						status.style.color = '#d63638';
						status.textContent = '✗ Server error. Please try again.';
						btn.disabled = false;
						btn.textContent = 'Replace Image';
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	public function handle_replace() {
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$source        = sanitize_key( $_POST['source'] ?? 'local' );

		if ( ! $attachment_id || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'mut_replace_image_' . $attachment_id ) ) {
			wp_send_json_error( 'Invalid request.' );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			wp_send_json_error( 'Invalid attachment.' );
		}

		$old_file = get_attached_file( $attachment_id );
		if ( ! $old_file || ! file_exists( $old_file ) ) {
			wp_send_json_error( 'Original file not found.' );
		}

		$old_dir      = dirname( $old_file );
		$old_basename = basename( $old_file );
		$old_ext      = pathinfo( $old_basename, PATHINFO_EXTENSION );
		$old_name     = pathinfo( $old_basename, PATHINFO_FILENAME );

		// Determine the source file and its extension
		if ( $source === 'library' ) {
			$source_id = absint( $_POST['source_id'] ?? 0 );
			if ( ! $source_id || $source_id === $attachment_id ) {
				wp_send_json_error( 'Invalid source attachment.' );
			}
			$source_file = get_attached_file( $source_id );
			if ( ! $source_file || ! file_exists( $source_file ) ) {
				wp_send_json_error( 'Source file not found.' );
			}
			$source_mime = get_post_mime_type( $source_id );
			if ( ! $source_mime || strpos( $source_mime, 'image/' ) !== 0 ) {
				wp_send_json_error( 'Source is not a valid image.' );
			}
			$new_ext = pathinfo( $source_file, PATHINFO_EXTENSION );
		} else {
			if ( empty( $_FILES['file'] ) || $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
				wp_send_json_error( 'No file uploaded or upload error.' );
			}
			$tmp_file  = $_FILES['file']['tmp_name'];
			$file_type = wp_check_filetype_and_ext( $tmp_file, $_FILES['file']['name'] );
			if ( ! $file_type['type'] || strpos( $file_type['type'], 'image/' ) !== 0 ) {
				wp_send_json_error( 'Uploaded file is not a valid image.' );
			}
			$new_ext = $file_type['ext'] ?: pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION );
		}

		// Delete old thumbnails before replacing
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $old_meta['sizes'] ) ) {
			foreach ( $old_meta['sizes'] as $size_data ) {
				$thumb_path = $old_dir . '/' . $size_data['file'];
				if ( file_exists( $thumb_path ) ) {
					@unlink( $thumb_path );
				}
			}
		}
		$scaled_path = $old_dir . '/' . $old_name . '-scaled.' . $old_ext;
		if ( file_exists( $scaled_path ) ) {
			@unlink( $scaled_path );
		}

		// Keep original filename
		$new_file = $old_dir . '/' . $old_name . '.' . $new_ext;

		if ( strtolower( $old_ext ) !== strtolower( $new_ext ) ) {
			@unlink( $old_file );
		}

		// Copy or move the replacement file
		if ( $source === 'library' ) {
			if ( ! copy( $source_file, $new_file ) ) {
				wp_send_json_error( 'Failed to copy source file.' );
			}
		} else {
			if ( ! move_uploaded_file( $tmp_file, $new_file ) ) {
				wp_send_json_error( 'Failed to move uploaded file.' );
			}
		}

		// Set proper permissions
		chmod( $new_file, 0644 );

		// Update attachment metadata
		update_attached_file( $attachment_id, $new_file );

		// Update post mime type if extension changed
		if ( strtolower( $old_ext ) !== strtolower( $new_ext ) ) {
			$new_mime = $source === 'library' ? $source_mime : ( $file_type['type'] ?? '' );
			if ( $new_mime ) {
				wp_update_post( array(
					'ID'             => $attachment_id,
					'post_mime_type' => $new_mime,
				) );
			}
		}

		// Regenerate thumbnails and metadata
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		$new_size = filesize( $new_file );

		wp_send_json_success( array(
			'new_size'     => size_format( $new_size ),
			'new_filename' => basename( $new_file ),
		) );
	}
}
