<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Storage\UsageStorage;

/**
 * Quality Audit Detail page.
 *
 * Shows a paginated, sortable table of all files flagged by a single
 * quality check. Linked from the Quality Audit cards via:
 *   admin.php?page=mut-quality-detail&check=alt_text
 */
class QualityDetail {

	const PER_PAGE = 20;

	private $storage;

	public function __construct( UsageStorage $storage ) {
		$this->storage = $storage;
	}

	public function render() {
		require_once MUT_PLUGIN_DIR . 'includes/class-quality-auditor.php';

		$check_key = sanitize_key( $_GET['check'] ?? '' );
		$paged     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$back_url  = admin_url( 'admin.php?page=mut-quality' );

		if ( ! $check_key ) {
			echo '<div class="wrap"><p>Invalid check. <a href="' . esc_url( $back_url ) . '">Back to Quality Audit</a></p></div>';
			return;
		}

		$auditor = new QualityAuditor( $this->storage );
		$report  = $auditor->get_report();

		if ( ! isset( $report['checks'][ $check_key ] ) ) {
			echo '<div class="wrap"><p>Check not found. <a href="' . esc_url( $back_url ) . '">Back to Quality Audit</a></p></div>';
			return;
		}

		$check     = $report['checks'][ $check_key ];
		$all_ids   = $check['items'];

		$sev      = $check['severity'];
		$ai_ready = false;
		if ( in_array( $check_key, array( 'alt_text', 'caption' ), true ) ) {
			require_once MUT_PLUGIN_DIR . 'includes/class-alt-text-generator.php';
			$ai_ready = AltTextGenerator::is_configured();
		}

		// Support In Use / All filter chips for checks where it's meaningful.
		$chips_checks  = array( 'alt_text', 'caption', 'description' );
		$show_chips    = in_array( $check_key, $chips_checks, true );
		$filter        = 'inuse';
		$count_all     = count( $all_ids );
		$count_inuse   = 0;
		$all_counts    = array();
		if ( $show_chips ) {
			$all_counts  = $this->get_usage_counts( $all_ids );
			$count_inuse = count( array_filter( $all_ids, fn( $id ) => ( $all_counts[ (int) $id ] ?? 0 ) > 0 ) );
			$filter      = 'inuse';
			$all_ids     = array_values( array_filter( $all_ids, fn( $id ) => ( $all_counts[ (int) $id ] ?? 0 ) > 0 ) );
		}

		$total    = count( $all_ids );
		$pages    = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;
		$offset   = ( $paged - 1 ) * self::PER_PAGE;
		$page_ids = array_slice( $all_ids, $offset, self::PER_PAGE );

		// Batch-fetch usage counts for current page (reuse pre-fetched map if available).
		$usage_counts = $show_chips ? $all_counts : $this->get_usage_counts( $page_ids );
		?>
		<div class="wrap mut-quality-detail">

			<div style="margin-bottom:12px;">
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">← Back to Quality Audit</a>
			</div>

			<h1>
				<span class="mut-sev-badge mut-sev-badge-<?php echo esc_attr( $sev ); ?>" style="vertical-align:middle;margin-right:8px;">
					<?php echo esc_html( ucfirst( $sev ) ); ?>
				</span>
				<?php echo esc_html( $check['label'] ); ?>
				<span style="font-size:16px;font-weight:400;color:#787c82;margin-left:8px;"><?php echo number_format( $total ); ?> file(s)</span>
			</h1>
			<p class="description" style="margin-bottom:20px;"><?php echo esc_html( $check['description'] ); ?></p>

			<?php if ( $check_key === 'alt_text' || $check_key === 'caption' ) :
				$ai_label    = $check_key === 'caption' ? 'Generate Captions with AI' : 'Generate Alt Text with AI';
				$gen_btn_id  = $check_key === 'caption' ? 'mut-generate-caption' : 'mut-generate-alt-text';
				$review_id   = $check_key === 'caption' ? 'mut-caption-review-panel' : 'mut-alttext-review-panel';
				$list_id     = $check_key === 'caption' ? 'mut-caption-review-list' : 'mut-alttext-review-list';
				$save_all_id = $check_key === 'caption' ? 'mut-save-all-caption' : 'mut-save-all-alt';
				$cancel_id   = $check_key === 'caption' ? 'mut-cancel-caption-review' : 'mut-cancel-alt-review';
			?>
				<div style="height:1px;background:#dcdcde;margin:0 0 20px;"></div>
				<div style="margin-bottom:16px;">
					<?php if ( $ai_ready ) : ?>
						<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
							<button id="<?php echo esc_attr( $gen_btn_id ); ?>" class="button button-primary"
								data-ids="<?php echo esc_attr( wp_json_encode( $all_ids ) ); ?>"
								data-all-ids="<?php echo esc_attr( wp_json_encode( $all_ids ) ); ?>">
								✨ <?php echo esc_html( $ai_label ); ?> (<span id="mut-gen-count"><?php echo number_format( $total ); ?></span> file<?php echo $total !== 1 ? 's' : ''; ?>)
							</button>
							<label style="font-size:13px;color:#3c434a;cursor:pointer;">
								<input type="checkbox" id="mut-select-all-inuse" style="margin-right:4px;">
								Select all in use
							</label>
						</div>
						<span id="mut-ai-progress" class="mut-meta" style="margin-top:6px;display:none;"></span>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mut-settings' ) ); ?>" class="button">
							✨ <?php echo esc_html( $ai_label ); ?> — <em>API key required</em>
						</a>
					<?php endif; ?>
				</div>

				<div id="<?php echo esc_attr( $review_id ); ?>" style="display:none;margin-bottom:24px;">
					<h2>✨ AI-Generated <?php echo $check_key === 'caption' ? 'Captions' : 'Alt Text'; ?> — Review &amp; Save</h2>
					<p class="description">Edit any suggestion then click <strong>Save All</strong> or save individually.</p>
					<div id="<?php echo esc_attr( $list_id ); ?>"></div>
					<div class="mut-alttext-review-actions" style="margin-top:16px;">
						<button id="<?php echo esc_attr( $save_all_id ); ?>" class="button button-primary">Save All</button>
						<button id="<?php echo esc_attr( $cancel_id ); ?>" class="button" style="margin-left:8px;">Cancel</button>
						<span id="mut-save-progress" class="mut-meta" style="margin-left:10px;"></span>
					</div>
				</div>
				<div style="height:1px;background:#dcdcde;margin:20px 0;"></div>
			<?php endif; ?>


			<?php if ( empty( $all_ids ) ) : ?>
				<div class="mut-quality-allclear">
					<p>✓ No files with this issue.</p>
				</div>
			<?php else : ?>

				<div style="overflow-x:auto;">
				<table class="wp-list-table widefat striped mut-qd-table">
					<thead>
						<tr>
							<?php if ( in_array( $check_key, array( 'alt_text', 'caption' ), true ) && $ai_ready ) : ?>
								<th style="width:32px;"><input type="checkbox" id="mut-cb-header" title="Select all on this page"></th>
							<?php endif; ?>
							<th style="width:64px;">Thumb</th>
							<th>Filename</th>
							<th style="width:80px;">Type</th>
							<th style="width:110px;">Upload Date</th>
							<th style="width:90px;">File Size</th>
							<th style="width:110px;">Status</th>
							<?php if ( $check_key === 'alt_text' ) : ?>
								<th style="width:220px;">Current Alt Text</th>
							<?php endif; ?>
							<?php if ( $check_key === 'caption' ) : ?>
								<th style="width:220px;">Current Caption</th>
							<?php endif; ?>
							<?php if ( $check_key === 'alt_text' ) : ?>
								<th style="width:100px;">Decorative?</th>
							<?php endif; ?>
							<th style="width:80px;">Edit</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $page_ids as $id ) :
							$id        = (int) $id;
							$post      = get_post( $id );
							if ( ! $post ) continue;
							$file      = get_attached_file( $id );
							$name      = basename( $file ?: $post->post_title );
							$size      = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '—';
							$mime      = $post->post_mime_type;
							$date      = ( new \DateTime( $post->post_date, wp_timezone() ) )->format( 'M j, Y' );
							$edit_link = get_edit_post_link( $id );
							if ( $edit_link ) {
								$edit_link = add_query_arg( 'mut_ref', urlencode( $check_key ), $edit_link );
							}
							$media_url = admin_url( 'upload.php?item=' . $id );
							$thumb     = wp_get_attachment_image( $id, array( 48, 48 ), true, array(
								'style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px;display:block;',
							) );
							$type_label    = $this->mime_short( $mime );
							$alt_text      = $check_key === 'alt_text' ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : null;
							$is_decorative = $check_key === 'alt_text' ? (bool) get_post_meta( $id, '_mut_decorative', true ) : false;
							$caption       = $check_key === 'caption' ? (string) $post->post_excerpt : null;
							$usage_count   = $usage_counts[ $id ] ?? 0;
							$usage_url     = admin_url( 'admin.php?page=mut-usage-details&attachment_id=' . $id );
						?>
						<tr data-id="<?php echo esc_attr( $id ); ?>" data-inuse="<?php echo $usage_count > 0 ? '1' : '0'; ?>">
							<?php if ( in_array( $check_key, array( 'alt_text', 'caption' ), true ) && $ai_ready ) : ?>
								<td style="text-align:center;vertical-align:middle;">
									<input type="checkbox" class="mut-cb-row" value="<?php echo esc_attr( $id ); ?>">
								</td>
							<?php endif; ?>
							<td>
								<?php if ( $thumb ) : ?>
									<a href="<?php echo esc_url( $media_url ); ?>"><?php echo $thumb; ?></a>
								<?php else : ?>
									<span class="mut-file-icon"><?php echo esc_html( strtoupper( $type_label ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $media_url ); ?>" class="mut-filename-link">
									<strong><?php echo esc_html( $name ); ?></strong>
								</a><br>
								<span class="mut-meta"><?php echo esc_html( $post->post_title ); ?></span>
							</td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><?php echo esc_html( $date ); ?></td>
							<td><?php echo esc_html( $size ); ?></td>
							<td>
								<?php if ( $usage_count > 0 ) : ?>
									<a href="<?php echo esc_url( $usage_url ); ?>" style="text-decoration:none;" title="View usage locations">
										<span class="mut-status-badge mut-status-used">In Use</span>
									</a>
									<?php if ( $usage_count > 1 ) : ?>
										<br><span class="mut-meta" style="font-size:11px;"><?php echo $usage_count; ?> locations</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="mut-status-badge mut-status-unused">Unused</span>
								<?php endif; ?>
							</td>
							<?php if ( $check_key === 'alt_text' ) : ?>
								<td class="mut-qd-alttext-cell" data-id="<?php echo esc_attr( $id ); ?>">
									<?php if ( $alt_text !== '' ) : ?>
										<span class="mut-qd-alt-current" style="color:#787c82;font-style:italic;"><?php echo esc_html( $alt_text ); ?></span>
									<?php else : ?>
										<span class="mut-qd-alt-current" style="color:#d63638;">— missing —</span>
									<?php endif; ?>
									<?php if ( $ai_ready ) : ?>
										<div style="margin-top:6px;">
											<button class="button button-small mut-qd-generate-one"
												data-id="<?php echo esc_attr( $id ); ?>">✨ Generate</button>
										</div>
										<div class="mut-qd-inline-review" style="display:none;margin-top:6px;">
											<input type="text" class="mut-qd-alt-input widefat"
												data-id="<?php echo esc_attr( $id ); ?>"
												placeholder="AI suggestion..." />
											<div style="margin-top:4px;">
												<button class="button button-primary button-small mut-qd-save-one"
													data-id="<?php echo esc_attr( $id ); ?>">Save</button>
												<button class="button button-small mut-qd-cancel-one"
													style="margin-left:4px;">Cancel</button>
											</div>
										</div>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<?php if ( $check_key === 'caption' ) : ?>
								<td class="mut-qd-caption-cell" data-id="<?php echo esc_attr( $id ); ?>">
									<?php if ( $caption !== '' ) : ?>
										<span class="mut-qd-caption-current" style="color:#787c82;font-style:italic;"><?php echo esc_html( $caption ); ?></span>
									<?php else : ?>
										<span class="mut-qd-caption-current" style="color:#d63638;">— missing —</span>
									<?php endif; ?>
									<?php if ( $ai_ready ) : ?>
										<div style="margin-top:6px;">
											<button class="button button-small mut-qd-generate-caption-one"
												data-id="<?php echo esc_attr( $id ); ?>">✨ Generate</button>
										</div>
										<div class="mut-qd-caption-inline-review" style="display:none;margin-top:6px;">
											<input type="text" class="mut-qd-caption-input widefat"
												data-id="<?php echo esc_attr( $id ); ?>"
												placeholder="AI suggestion..." />
											<div style="margin-top:4px;">
												<button class="button button-primary button-small mut-qd-save-caption-one"
													data-id="<?php echo esc_attr( $id ); ?>">Save</button>
												<button class="button button-small mut-qd-cancel-caption-one"
													style="margin-left:4px;">Cancel</button>
											</div>
										</div>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<?php if ( $check_key === 'alt_text' ) : ?>
								<td>
									<button class="button button-small mut-mark-decorative"
										data-id="<?php echo esc_attr( $id ); ?>"
										data-decorative="<?php echo $is_decorative ? '1' : '0'; ?>">
										<?php echo $is_decorative ? 'Unmark' : 'Mark Decorative'; ?>
									</button>
								</td>
							<?php endif; ?>
							<td>
								<?php if ( $edit_link ) : ?>
									<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">Edit</a>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
t				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="mut-qd-pagination" style="margin-top:16px;">
						<?php
						$base_url = admin_url( 'admin.php?page=mut-quality-detail&check=' . $check_key . '&filter=' . $filter );
						for ( $p = 1; $p <= $pages; $p++ ) :
							$url = add_query_arg( 'paged', $p, $base_url );
							if ( $p === $paged ) :
								echo '<span style="display:inline-block;padding:4px 10px;margin:0 2px;background:#2271b1;color:#fff;border-radius:3px;">' . $p . '</span>';
							else :
								echo '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:4px 10px;margin:0 2px;border:1px solid #c3c4c7;border-radius:3px;text-decoration:none;">' . $p . '</a>';
							endif;
						endfor;
						?>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Batch-fetch distinct usage counts for a list of attachment IDs.
	 * Returns [ attachment_id => count ] for IDs found; missing IDs default to 0.
	 *
	 * @param  int[] $ids
	 * @return array<int,int>
	 */
	private function get_usage_counts( array $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$ids        = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT attachment_id, COUNT(DISTINCT post_id) AS cnt
			 FROM {$wpdb->prefix}mut_media_usage
			 WHERE attachment_id IN ($placeholders)
			 GROUP BY attachment_id",
			...$ids
		) );
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->attachment_id ] = (int) $row->cnt;
		}
		return $map;
	}

	private function mime_short( $mime ) {
		$map = array(
			'image/jpeg'      => 'JPEG',
			'image/png'       => 'PNG',
			'image/gif'       => 'GIF',
			'image/webp'      => 'WebP',
			'image/svg+xml'   => 'SVG',
			'image/bmp'       => 'BMP',
			'image/tiff'      => 'TIFF',
			'video/mp4'       => 'MP4',
			'audio/mpeg'      => 'MP3',
			'application/pdf' => 'PDF',
		);
		return $map[ strtolower( (string) $mime ) ] ?? strtoupper( explode( '/', (string) $mime )[1] ?? $mime );
	}
}
