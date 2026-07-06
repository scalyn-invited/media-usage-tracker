<?php
namespace MediaUsageTracker\Admin;

use MediaUsageTracker\Core\Scheduler;
use MediaUsageTracker\Core\Notifier;
use MediaUsageTracker\Core\RealtimeScanner;
use MediaUsageTracker\Admin\AltTextGenerator;

class Settings {

	public function render() {
		// Handle manual "Check for Updates" click (separate from the settings form/nonce).
		if ( isset( $_POST['mut_check_update'] ) && check_admin_referer( 'mut_check_update_action', 'mut_check_update_nonce' ) ) {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Checked GitHub for updates.', 'media-usage-tracker' ) . ' ';

			$plugin_file    = plugin_basename( MUT_PLUGIN_FILE );
			$update_plugins = get_site_transient( 'update_plugins' );

			if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
				$new_version = $update_plugins->response[ $plugin_file ]->new_version;
				printf(
					/* translators: %s = new version number */
					esc_html__( 'A new version (%s) is available — go to the Plugins page to update.', 'media-usage-tracker' ),
					esc_html( $new_version )
				);
			} else {
				esc_html_e( 'You already have the latest version.', 'media-usage-tracker' );
			}

			echo '</p></div>';
		}

		$enabled   = (bool) get_option( Scheduler::OPT_ON, false );
		$frequency = get_option( Scheduler::OPT_FREQ, 'weekly' );
		$next_run  = Scheduler::next_run();
		$last_run  = get_option( 'mut_last_scheduled_scan', null );

		$email_on  = (bool) get_option( Notifier::OPT_ON, false );
		$email_to  = get_option( Notifier::OPT_TO, '' );
		if ( ! $email_to ) {
			$email_to = get_option( 'admin_email', '' );
		}
		?>
		<div class="wrap">
			<h1>⚙️ <?php esc_html_e( 'Media Usage Tracker Settings', 'media-usage-tracker' ); ?></h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'media-usage-tracker' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'mut_settings' ); ?>

				<?php $feedback_email = get_option( 'mut_feedback_email', '' ); ?>
				<h2><?php esc_html_e( 'General', 'media-usage-tracker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mut_feedback_email"><?php esc_html_e( 'Feedback Email', 'media-usage-tracker' ); ?></label></th>
						<td>
							<input type="email" id="mut_feedback_email" class="regular-text" name="mut_feedback_email" value="<?php echo esc_attr( $feedback_email ); ?>" placeholder="support@trusteddigitalagency.com" />
							<p class="description"><?php esc_html_e( 'Email address for the Send Feedback button on the FAQ page. Defaults to support@trusteddigitalagency.com if left blank.', 'media-usage-tracker' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Scheduled Scans', 'media-usage-tracker' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Automatically scan your media library on a set schedule using WP Cron.', 'media-usage-tracker' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Scheduled Scans', 'media-usage-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Scheduler::OPT_ON ); ?>" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Run scans automatically', 'media-usage-tracker' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mut_freq"><?php esc_html_e( 'Scan Frequency', 'media-usage-tracker' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( Scheduler::OPT_FREQ ); ?>" id="mut_freq">
								<?php foreach ( Scheduler::intervals() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $frequency, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schedule Status', 'media-usage-tracker' ); ?></th>
						<td>
							<?php if ( $enabled && $next_run ) : ?>
								<span class="mut-sched-status mut-sched-active">
									<?php esc_html_e( 'Active', 'media-usage-tracker' ); ?>
								</span>
								&nbsp;
								<?php
								printf(
									/* translators: %s = human-readable date/time */
									esc_html__( 'Next scan: %s', 'media-usage-tracker' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) )
								);
								?>
							<?php elseif ( $enabled ) : ?>
								<span class="mut-sched-status mut-sched-pending">
									<?php esc_html_e( 'Pending — save settings to activate', 'media-usage-tracker' ); ?>
								</span>
							<?php else : ?>
								<span class="mut-sched-status mut-sched-inactive">
									<?php esc_html_e( 'Inactive', 'media-usage-tracker' ); ?>
								</span>
							<?php endif; ?>

							<?php if ( $last_run ) : ?>
								<p class="description">
									<?php
									printf(
										esc_html__( 'Last scheduled scan: %s', 'media-usage-tracker' ),
										esc_html( ( new \DateTime( $last_run, wp_timezone() ) )->format( 'M j, Y g:i A' ) )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Instant Updates', 'media-usage-tracker' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Keep media usage current automatically as you edit — no need to re-run a scan after every change. Covers regular pages/posts (including Elementor, ACF, Divi, WPBakery, Beaver Builder, and similar builders) and Gravity Forms. Changes are grouped and processed a few seconds later in the background, so this never affects visitors browsing the site.', 'media-usage-tracker' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Instant Updates', 'media-usage-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RealtimeScanner::OPT_ON ); ?>" value="1" <?php checked( RealtimeScanner::is_enabled() ); ?> />
								<?php esc_html_e( 'Rescan a page/form automatically right after it\'s saved or deleted', 'media-usage-tracker' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email Summary', 'media-usage-tracker' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Get a digest emailed to you each time a scheduled scan finishes.', 'media-usage-tracker' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email After Scheduled Scan', 'media-usage-tracker' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Notifier::OPT_ON ); ?>" value="1" <?php checked( $email_on ); ?> />
								<?php esc_html_e( 'Send me a summary email', 'media-usage-tracker' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only sent for automatic scheduled scans, not manual ones.', 'media-usage-tracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mut_email_to"><?php esc_html_e( 'Recipient', 'media-usage-tracker' ); ?></label></th>
						<td>
							<input type="email" id="mut_email_to" class="regular-text" name="<?php echo esc_attr( Notifier::OPT_TO ); ?>" value="<?php echo esc_attr( $email_to ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Defaults to the site admin email if left blank.', 'media-usage-tracker' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'AI Alt Text Generation', 'media-usage-tracker' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Automatically generate descriptive alt text for images using AI. Select a provider and enter the matching API key.', 'media-usage-tracker' ); ?>
				</p>

				<?php
				$active_provider  = AltTextGenerator::get_provider();
				$gemini_key       = get_option( AltTextGenerator::OPT_GEMINI_KEY, '' );
				$anthropic_key    = get_option( AltTextGenerator::OPT_ANTHROPIC_KEY, '' );
				$groq_key         = get_option( AltTextGenerator::OPT_GROQ_KEY, '' );
				$is_configured    = AltTextGenerator::is_configured();
				?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mut_ai_provider"><?php esc_html_e( 'AI Provider', 'media-usage-tracker' ); ?></label></th>
						<td>
							<select id="mut_ai_provider" name="<?php echo esc_attr( AltTextGenerator::OPT_PROVIDER ); ?>">
								<option value="gemini"    <?php selected( $active_provider, 'gemini' ); ?>>Google Gemini (Free tier available)</option>
								<option value="anthropic" <?php selected( $active_provider, 'anthropic' ); ?>>Anthropic Claude</option>
								<option value="groq"      <?php selected( $active_provider, 'groq' ); ?>>Groq (Free tier available)</option>
							</select>
							<p class="description" style="margin-top:4px;">
								<span id="mut-provider-status"></span>
							</p>
						</td>
					</tr>

					<!-- Google Gemini key -->
					<tr id="mut-row-gemini" <?php echo $active_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label for="mut_gemini_api_key"><?php esc_html_e( 'Google Gemini API Key', 'media-usage-tracker' ); ?></label></th>
						<td>
							<span style="position:relative;display:inline-block;max-width:100%;">
								<input
									type="password"
									id="mut_gemini_api_key"
									class="regular-text"
									style="padding-right:34px;max-width:100%;box-sizing:border-box;"
									name="<?php echo esc_attr( AltTextGenerator::OPT_GEMINI_KEY ); ?>"
									value="<?php echo esc_attr( $gemini_key ); ?>"
									placeholder="AIza..."
									autocomplete="off"
								/>
								<button type="button" class="button-link mut-toggle-key" data-target="mut_gemini_api_key"
									style="position:absolute;right:8px;top:50%;transform:translateY(-50%);line-height:1;">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text">Show API key</span>
								</button>
							</span>
							<p class="description">
								<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Get a free Gemini API key →</a>
							</p>
						</td>
					</tr>

					<!-- Anthropic Claude key -->
					<tr id="mut-row-anthropic" <?php echo $active_provider !== 'anthropic' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label for="mut_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'media-usage-tracker' ); ?></label></th>
						<td>
							<span style="position:relative;display:inline-block;max-width:100%;">
								<input
									type="password"
									id="mut_anthropic_api_key"
									class="regular-text"
									style="padding-right:34px;max-width:100%;box-sizing:border-box;"
									name="<?php echo esc_attr( AltTextGenerator::OPT_ANTHROPIC_KEY ); ?>"
									value="<?php echo esc_attr( $anthropic_key ); ?>"
									placeholder="sk-ant-..."
									autocomplete="off"
								/>
								<button type="button" class="button-link mut-toggle-key" data-target="mut_anthropic_api_key"
									style="position:absolute;right:8px;top:50%;transform:translateY(-50%);line-height:1;">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text">Show API key</span>
								</button>
							</span>
							<p class="description">
								<a href="https://console.anthropic.com/" target="_blank" rel="noopener">Get an Anthropic API key →</a>
							</p>
						</td>
					</tr>

					<!-- Groq key -->
					<tr id="mut-row-groq" <?php echo $active_provider !== 'groq' ? 'style="display:none;"' : ''; ?>>
						<th scope="row"><label for="mut_groq_api_key"><?php esc_html_e( 'Groq API Key', 'media-usage-tracker' ); ?></label></th>
						<td>
							<span style="position:relative;display:inline-block;max-width:100%;">
								<input
									type="password"
									id="mut_groq_api_key"
									class="regular-text"
									style="padding-right:34px;max-width:100%;box-sizing:border-box;"
									name="<?php echo esc_attr( AltTextGenerator::OPT_GROQ_KEY ); ?>"
									value="<?php echo esc_attr( $groq_key ); ?>"
									placeholder="gsk_..."
									autocomplete="off"
								/>
								<button type="button" class="button-link mut-toggle-key" data-target="mut_groq_api_key"
									style="position:absolute;right:8px;top:50%;transform:translateY(-50%);line-height:1;">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<span class="screen-reader-text">Show API key</span>
								</button>
							</span>
							<p class="description">
								<a href="https://console.groq.com/keys" target="_blank" rel="noopener">Get a free Groq API key →</a>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Plugin Updates', 'media-usage-tracker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Current Version', 'media-usage-tracker' ); ?></th>
					<td>
						<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
							<span><?php echo esc_html( MUT_VERSION ); ?></span>
							<form method="post" style="display:inline;margin:0;">
								<?php wp_nonce_field( 'mut_check_update_action', 'mut_check_update_nonce' ); ?>
								<button type="submit" name="mut_check_update" class="button button-secondary">
									<?php esc_html_e( 'Check for Updates', 'media-usage-tracker' ); ?>
								</button>
							</form>
						</div>
						<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Checks the GitHub repository for a newer release.', 'media-usage-tracker' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<script>
		jQuery(document).ready(function($){
			// Show/hide key toggle
			$(document).on('click', '.mut-toggle-key', function(){
				var $btn  = $(this);
				var $f    = $('#' + $btn.data('target'));
				var show  = $f.attr('type') === 'password';
				$f.attr('type', show ? 'text' : 'password');
				$btn.find('.dashicons').toggleClass('dashicons-visibility', !show).toggleClass('dashicons-hidden', show);
				$btn.find('.screen-reader-text').text(show ? 'Hide API key' : 'Show API key');
			});

			// Per-provider key state passed from PHP
			var mutProviderKeys = {
				gemini:    <?php echo trim( $gemini_key )    !== '' ? 'true' : 'false'; ?>,
				anthropic: <?php echo trim( $anthropic_key ) !== '' ? 'true' : 'false'; ?>,
				groq:      <?php echo trim( $groq_key )      !== '' ? 'true' : 'false'; ?>
			};
			var mutProviderLabels = {
				gemini:    'Google Gemini',
				anthropic: 'Anthropic Claude',
				groq:      'Groq'
			};

			function mutUpdateProviderStatus( val ) {
				var label       = mutProviderLabels[ val ] || val;
				var hasKey      = mutProviderKeys[ val ] || false;
				var $status     = $('#mut-provider-status');
				if ( hasKey ) {
					$status.html('<span style="color:#00a32a;">&#10003; ' + label + ' API key is configured. AI alt text generation is ready.</span>');
				} else {
					$status.html('<span style="color:#d63638;">&#10007; No API key set for ' + label + '.</span>');
				}
			}

			// Provider dropdown — show matching key row, hide others, update status
			$('#mut_ai_provider').on('change', function(){
				var val = $(this).val();
				$('#mut-row-gemini').toggle(val === 'gemini');
				$('#mut-row-anthropic').toggle(val === 'anthropic');
				$('#mut-row-groq').toggle(val === 'groq');
				mutUpdateProviderStatus( val );
			});

			// Init on page load
			mutUpdateProviderStatus( $('#mut_ai_provider').val() );
		});
		</script>
		<?php
	}

	public function register_settings() {
		register_setting( 'mut_settings', Scheduler::OPT_ON,   array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'mut_settings', Scheduler::OPT_FREQ, array( 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'mut_settings', RealtimeScanner::OPT_ON, array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'mut_settings', Notifier::OPT_ON,         array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'mut_settings', Notifier::OPT_TO,         array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( 'mut_settings', AltTextGenerator::OPT_PROVIDER,     array( 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'mut_settings', AltTextGenerator::OPT_GEMINI_KEY,   array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'mut_settings', AltTextGenerator::OPT_ANTHROPIC_KEY, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'mut_settings', AltTextGenerator::OPT_GROQ_KEY,      array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'mut_settings', 'mut_feedback_email',                  array( 'sanitize_callback' => 'sanitize_email' ) );
	}
}
