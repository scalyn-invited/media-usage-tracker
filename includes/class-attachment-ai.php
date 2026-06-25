<?php
namespace MediaUsageTracker\Admin;

/**
 * Injects "Generate with AI" buttons into the native WordPress Edit Media page
 * via a meta box (post.php) and via attachment_fields_to_edit (media modal).
 */
class AttachmentAI {

	public function register() {
		// Meta box for standalone Edit Media page (post.php?post=ID&action=edit)
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// Media modal / media library grid view
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_ai_buttons' ), 10, 2 );

		// JS + CSS on Edit Media page and upload page
		add_action( 'admin_footer', array( $this, 'maybe_enqueue' ) );
	}

	public function add_meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! AltTextGenerator::is_configured() ) {
			return;
		}
		// Only show for image attachments that are not SVG.
		$post = get_post();
		if ( ! $post || strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return;
		}
		if ( $post->post_mime_type === 'image/svg+xml' ) {
			return;
		}
		add_meta_box(
			'mut-attachment-ai',
			'✨ Generate with AI',
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			echo '<p style="color:#787c82;font-size:12px;">AI generation is available for images only.</p>';
			return;
		}
		$nonce = wp_create_nonce( 'mut_scan_nonce' );
		$id    = (int) $post->ID;
		?>
		<p style="margin:0 0 8px;">
			<button type="button" class="button button-primary button-small mut-att-gen-btn" style="width:100%;"
				data-id="<?php echo $id; ?>"
				data-type="alt_text"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-target="#attachment_alt">
				✨ Generate Alt Text
			</button>
			<span class="mut-att-gen-msg" style="display:none;font-size:11px;margin-top:4px;display:block;"></span>
		</p>
		<p style="margin:0;">
			<button type="button" class="button button-small mut-att-gen-btn" style="width:100%;"
				data-id="<?php echo $id; ?>"
				data-type="caption"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-target="#excerpt, #attachment_caption">
				✨ Generate Caption
			</button>
			<span class="mut-att-gen-msg" style="display:none;font-size:11px;margin-top:4px;display:block;"></span>
		</p>
		<p style="margin:8px 0 0;font-size:11px;color:#787c82;">Generated text fills the field automatically. Click <strong>Update</strong> to save.</p>
		<?php
	}

	/**
	 * Media modal: append buttons after alt text and caption fields.
	 */
	public function add_ai_buttons( $form_fields, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $form_fields;
		}
		if ( strpos( (string) $post->post_mime_type, 'image/' ) !== 0 ) {
			return $form_fields;
		}
		if ( ! AltTextGenerator::is_configured() ) {
			return $form_fields;
		}

		$nonce = wp_create_nonce( 'mut_scan_nonce' );
		$id    = (int) $post->ID;

		if ( isset( $form_fields['image_alt'] ) ) {
			$form_fields['image_alt']['html'] .=
				'<button type="button" class="button button-small mut-att-gen-btn"
					data-id="' . $id . '" data-type="alt_text" data-nonce="' . esc_attr( $nonce ) . '"
					data-target="input[name=\'attachments[' . $id . '][image_alt]\']"
					style="margin-top:6px;">✨ Generate with AI</button>
				<span class="mut-att-gen-msg" style="font-size:11px;color:#646970;margin-top:4px;display:block;"></span>';
		}

		if ( isset( $form_fields['post_excerpt'] ) ) {
			$form_fields['post_excerpt']['html'] .=
				'<button type="button" class="button button-small mut-att-gen-btn"
					data-id="' . $id . '" data-type="caption" data-nonce="' . esc_attr( $nonce ) . '"
					data-target="textarea[name=\'attachments[' . $id . '][post_excerpt]\']"
					style="margin-top:6px;">✨ Generate Caption with AI</button>
				<span class="mut-att-gen-msg" style="font-size:11px;color:#646970;margin-top:4px;display:block;"></span>';
		}

		return $form_fields;
	}

	public function maybe_enqueue() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// Only on attachment edit page, upload page, or post editor (media modal)
		if ( ! in_array( $screen->base, array( 'post', 'upload' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		(function ($) {
			$(document).on('click', '.mut-att-gen-btn', function () {
				var $btn    = $(this);
				var id      = $btn.data('id');
				var type    = $btn.data('type');
				var nonce   = $btn.data('nonce');
				var target  = $btn.data('target');
				var $msg    = $btn.next('.mut-att-gen-msg');
				var origTxt = $btn.text();

				$btn.prop('disabled', true).text('Generating…');
				$msg.text('').hide();

				$.post(ajaxurl, {
					action: 'mut_generate_alt_text',
					nonce:  nonce,
					id:     id,
					type:   type
				}, function (res) {
					$btn.prop('disabled', false).text(origTxt);
					if (res.success && res.data) {
						var text = res.data.text || res.data.alt_text || '';
						// Fill target field
						if (target) {
							$(target).val(text).trigger('change');
						} else {
							// Fallback: try all known field IDs for this page type
							if (type === 'alt_text') {
								$('#attachment_alt, #_wp_attachment_image_alt').val(text).trigger('change');
							} else {
								$('#excerpt, #attachment_caption').val(text).trigger('change');
							}
						}
						$msg.text('✓ Generated — click Update to save.').css('color', '#1a7a3a').show();
					} else {
						$msg.text('Error: ' + (res.data || 'Generation failed.')).css('color', '#d63638').show();
					}
				}).fail(function () {
					$btn.prop('disabled', false).text(origTxt);
					$msg.text('Request failed. Please try again.').css('color', '#d63638').show();
				});
			});
		}(jQuery));
		</script>
		<?php
	}
}
