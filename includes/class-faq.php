<?php
namespace MediaUsageTracker\Admin;

class FAQ {

    public function render() {
        $sections = $this->get_faq_data();
        ?>
        <div class="wrap mut-faq">
            <h1>❓ FAQ</h1>
            <p class="mut-faq-intro">Common questions about Media Usage Tracker — how it works, what it does, and what to do when something looks off.</p>

            <div class="mut-faq-layout">

                <!-- Sidebar nav -->
                <nav class="mut-faq-nav">
                    <?php foreach ( $sections as $section ) : ?>
                        <a href="#<?php echo esc_attr( $section['id'] ); ?>" class="mut-faq-nav-item">
                            <span class="mut-faq-nav-icon"><?php echo $section['icon']; ?></span>
                            <?php echo esc_html( $section['title'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- FAQ content -->
                <div class="mut-faq-content">

                    <div class="mut-faq-feedback-bar">
                        <span class="mut-faq-feedback-icon">🤝</span>
                        <div class="mut-faq-feedback-text">
                            <strong>Help us improve compatibility.</strong>
                            MUT has been tested against the most popular plugins and themes, but the WordPress ecosystem is huge. If you find a plugin where images aren't being detected, or a usage type that looks wrong, we'd love to hear about it.
                        </div>
                        <?php $mut_fb_email = get_option( 'mut_feedback_email', '' ) ?: 'support@trusteddigitalagency.com'; ?>
                        <a href="mailto:<?php echo esc_attr( $mut_fb_email ); ?>" class="mut-faq-feedback-btn">📧 Send Feedback</a>
                    </div>
                    <?php foreach ( $sections as $section ) : ?>
                        <div class="mut-faq-section" id="<?php echo esc_attr( $section['id'] ); ?>">
                            <h2 class="mut-faq-section-title">
                                <?php echo $section['icon']; ?> <?php echo esc_html( $section['title'] ); ?>
                            </h2>
                            <?php foreach ( $section['items'] as $item ) : ?>
                                <div class="mut-faq-item">
                                    <button type="button" class="mut-faq-question" aria-expanded="false">
                                        <span><?php echo esc_html( $item['q'] ); ?></span>
                                        <span class="mut-faq-chevron">▾</span>
                                    </button>
                                    <div class="mut-faq-answer">
                                        <div class="mut-faq-answer-inner">
                                            <?php echo wp_kses_post( $item['a'] ); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <style>
        .mut-faq { max-width: 1100px; }
        .mut-faq-intro { color: #646970; margin-bottom: 28px; font-size: 14px; }

        .mut-faq-layout {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 32px;
            align-items: start;
        }

        /* Sidebar */
        .mut-faq-nav {
            position: sticky;
            top: 32px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .mut-faq-nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            color: #1d2327;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.12s;
        }
        .mut-faq-nav-item:hover { background: #f0f0f1; color: #2271b1; }
        .mut-faq-nav-item.active { background: #e8f0fd; color: #2271b1; font-weight: 600; }
        .mut-faq-nav-icon { font-size: 15px; line-height: 1; }

        /* Sections */
        .mut-faq-section { margin-bottom: 40px; }
        .mut-faq-section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1d2327;
            margin: 0 0 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f1;
        }

        /* Items */
        .mut-faq-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 8px;
            overflow: hidden;
        }
        .mut-faq-question {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            background: #fff;
            border: none;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
            transition: background 0.12s;
        }
        .mut-faq-question:hover { background: #f8f9fa; }
        .mut-faq-question[aria-expanded="true"] { background: #f0f6ff; color: #2271b1; }
        .mut-faq-chevron {
            flex-shrink: 0;
            font-size: 16px;
            transition: transform 0.2s;
            color: #787c82;
        }
        .mut-faq-question[aria-expanded="true"] .mut-faq-chevron { transform: rotate(180deg); color: #2271b1; }

        .mut-faq-answer {
            display: none;
            border-top: 1px solid #e0e0e0;
        }
        .mut-faq-answer.open { display: block; }
        .mut-faq-answer-inner {
            padding: 14px 18px;
            font-size: 13px;
            line-height: 1.7;
            color: #3c434a;
        }
        .mut-faq-answer-inner p { margin: 0 0 10px; }
        .mut-faq-answer-inner p:last-child { margin-bottom: 0; }
        .mut-faq-answer-inner ul { margin: 8px 0 10px 18px; }
        .mut-faq-answer-inner li { margin-bottom: 4px; }
        .mut-faq-answer-inner strong { color: #1d2327; }
        .mut-faq-answer-inner code {
            background: #f0f0f1;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 12px;
        }
        .mut-faq-tip {
            background: #f0f6ff;
            border-left: 3px solid #2271b1;
            padding: 8px 12px;
            border-radius: 0 4px 4px 0;
            margin-top: 10px;
            font-size: 12px;
            color: #2271b1;
        }
        .mut-faq-warn {
            background: #fef9e7;
            border-left: 3px solid #dba617;
            padding: 8px 12px;
            border-radius: 0 4px 4px 0;
            margin-top: 10px;
            font-size: 12px;
            color: #8a6400;
        }

        /* Feedback bar */
        .mut-faq-feedback-bar {
            display: flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #f0f6ff 0%, #fef9e7 100%);
            border: 1px solid #d0e2ff;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 32px;
        }
        .mut-faq-feedback-icon { font-size: 24px; flex-shrink: 0; }
        .mut-faq-feedback-text {
            flex: 1;
            font-size: 13px;
            color: #3c434a;
            line-height: 1.6;
        }
        .mut-faq-feedback-text strong { color: #1d2327; display: block; margin-bottom: 2px; }
        .mut-faq-feedback-btn {
            flex-shrink: 0;
            background: #2271b1;
            color: #fff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .mut-faq-feedback-btn:hover { background: #135e96; color: #fff; }
        </style>

        <script>
        (function ($) {
            // Accordion toggle
            $(document).on('click', '.mut-faq-question', function () {
                var $q      = $(this);
                var $answer = $q.next('.mut-faq-answer');
                var open    = $q.attr('aria-expanded') === 'true';
                $q.attr('aria-expanded', !open);
                $answer.toggleClass('open', !open);
            });

            // Sidebar active state on scroll
            var $navItems = $('.mut-faq-nav-item');
            var $sections = $('.mut-faq-section');

            function updateNav() {
                var scrollTop = $(window).scrollTop() + 80;
                var current = '';
                $sections.each(function () {
                    if ($(this).offset().top <= scrollTop) {
                        current = $(this).attr('id');
                    }
                });
                $navItems.removeClass('active').filter('[href="#' + current + '"]').addClass('active');
            }

            $(window).on('scroll', updateNav);
            updateNav();

            // Smooth scroll on nav click
            $navItems.on('click', function (e) {
                e.preventDefault();
                var target = $($(this).attr('href'));
                if (target.length) {
                    $('html, body').animate({ scrollTop: target.offset().top - 40 }, 300);
                }
            });
        }(jQuery));
        </script>
        <?php
    }

    private function get_faq_data() {
        return array(

            array(
                'id'    => 'faq-scanning',
                'icon'  => '🔍',
                'title' => 'Scanning',
                'items' => array(
                    array(
                        'q' => 'What does a scan actually do?',
                        'a' => '<p>A scan goes through every post, page, and custom post type in your site and records which media files are referenced — in content, featured images, page builder fields, ACF fields, and more. Results are stored in the plugin\'s own database table so every other page in MUT can query them instantly without slowing down your front-end.</p>',
                    ),
                    array(
                        'q' => 'How long does a scan take?',
                        'a' => '<p>It depends on how many posts and media files your site has. A small site (under 500 posts) usually finishes in under a minute. Larger sites with thousands of posts and many active page builders may take several minutes.</p><div class="mut-faq-tip">💡 You can leave the page while a scan runs — it processes in the background via AJAX.</div>',
                    ),
                    array(
                        'q' => 'How often should I scan?',
                        'a' => '<p>Scan whenever you\'ve made significant content changes — added new pages, deleted posts, or uploaded a batch of new media. For most sites, once a week or once a month is enough. The dashboard shows how long ago the last scan ran so you always know if the data is fresh.</p>',
                    ),
                    array(
                        'q' => 'Does scanning affect site performance?',
                        'a' => '<p>No. Scans run entirely in the WordPress admin via AJAX and never touch your front-end. Visitors won\'t notice anything. The scan does query the database, so avoid running it during a high-traffic period on very large sites just to be safe.</p>',
                    ),
                    array(
                        'q' => 'Does MUT slow down my website?',
                        'a' => '<p>No. MUT is an admin-only plugin — it does not load any CSS, JavaScript, or PHP on your site\'s front-end. Visitors will never see or experience any impact. The plugin only runs when you are logged in to the WordPress admin panel.</p>',
                    ),
                    array(
                        'q' => 'Which page builders and plugins does MUT detect?',
                        'a' => '<p>MUT includes detectors for:</p><ul><li><strong>Elementor</strong> — images across all widget types: single image, gallery, carousel, background, slider, and more</li><li><strong>JetEngine</strong> — image fields on custom post types</li><li><strong>JetPopup</strong> — static background/overlay images on popups</li><li><strong>ACF (Advanced Custom Fields)</strong> — image field types, shows field name in context</li><li><strong>Divi</strong> — supports both Divi 4 (shortcodes) and Divi 5 (block JSON format)</li><li><strong>WPBakery Page Builder</strong> — single image and gallery modules</li><li><strong>Beaver Builder</strong> — photo and gallery modules</li><li><strong>Avada / Fusion Builder</strong> — Fusion shortcode image elements</li><li><strong>WooCommerce</strong> — product images, gallery, variation images, category images</li><li><strong>Yoast SEO</strong> — OG (Open Graph) images</li><li><strong>Gravity Forms</strong> — images embedded in HTML fields, confirmation messages, notifications</li><li><strong>Astra</strong> — site logo and theme customizer images</li><li><strong>wpDataTables</strong> — image columns in manual tables</li></ul><p>Only detectors for plugins/themes that are actually installed and active run during a scan.</p><div class="mut-faq-tip">💡 JetPopup only detects <em>static</em> images set directly on the popup. Dynamic images pulled from JetEngine fields at runtime are tracked under JetEngine instead.</div>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-compatibility',
                'icon'  => '🔌',
                'title' => 'Compatibility',
                'items' => array(
                    array(
                        'q' => 'Does MUT work with Divi 5?',
                        'a' => '<p>Yes. Divi 5 changed its storage format from shortcodes (<code>[et_pb_*]</code>) to Gutenberg block JSON comments. MUT detects both formats automatically — no configuration needed.</p>',
                    ),
                    array(
                        'q' => 'I use JetPopup and the "Referenced By: JetPopup" filter shows no results. Is it broken?',
                        'a' => '<p>Not necessarily. JetPopup only records <em>static</em> images set directly on the popup itself (overlay backgrounds, close button icons). If your popup uses dynamic images pulled from a JetEngine field at runtime, those are tracked under <strong>JetEngine</strong> instead — the image belongs to the source post, not the popup. That means it will still show as "In Use" and won\'t be accidentally deleted.</p>',
                    ),
                    array(
                        'q' => 'Does MUT work with Astra Pro?',
                        'a' => '<p>Yes. The Astra detector works with both the free Astra theme and Astra Pro. It scans the WordPress Customizer theme mods for logos, background images, and other media set through Astra\'s settings.</p>',
                    ),
                    array(
                        'q' => 'Can MUT detect images used in dynamic/conditional content?',
                        'a' => '<p>No — this is a fundamental limitation of static scanning. If an image URL is constructed at runtime (e.g. via JavaScript, PHP conditionals, or a dynamic field resolved from a database), MUT cannot know about it until the page is actually rendered. MUT scans the <em>saved content</em> in the database, not the rendered HTML.</p><div class="mut-faq-warn">⚠️ If you use heavily dynamic templates, always double-check before bulk-deleting unused files.</div>',
                    ),
                    array(
                        'q' => 'What PHP version is required?',
                        'a' => '<p>MUT requires <strong>PHP 8.1 or higher</strong>. If your hosting is on an older PHP version, the plugin will not activate. Contact your hosting provider to upgrade — PHP 8.1+ is recommended by WordPress itself for performance and security.</p>',
                    ),
                    array(
                        'q' => 'What WordPress version does MUT require?',
                        'a' => '<p>MUT requires <strong>WordPress 6.0 or higher</strong>. It has been tested and confirmed working on the latest WordPress releases. We recommend keeping WordPress up to date for the best experience.</p>',
                    ),
                    array(
                        'q' => 'Are there any compatibility issues with other plugins?',
                        'a' => '<p>No. MUT is designed to be completely non-intrusive. It is a <strong>read-only</strong> plugin — it scans and reports but never modifies other plugins\' data or settings. Key design principles:</p><ul><li>It only <em>reads</em> data from other plugins (Elementor, ACF, WooCommerce, etc.) — never writes to them</li><li>Detectors only activate when the relevant plugin is installed and active</li><li>It uses its own database table and does not touch other plugin tables</li><li>No frontend output — no CSS or JavaScript on the public site</li><li>No hooks that override or conflict with other plugins\' behaviour</li></ul><p>MUT has been tested alongside all 13 supported plugins running simultaneously with no conflicts.</p>',
                    ),
                    array(
                        'q' => 'Does MUT work with custom post types?',
                        'a' => '<p>Yes. MUT scans all registered post types — pages, posts, products, portfolio items, team members, and any custom post type created by JetEngine, ACF, or other plugins. If a media file is referenced in the content or metadata of any post type, MUT will detect it.</p>',
                    ),
                    array(
                        'q' => 'What happens if I deactivate MUT?',
                        'a' => '<p>Nothing breaks. MUT does not modify your content, media files, or other plugins\' data. When deactivated:</p><ul><li>Your media library remains exactly as it was</li><li>All scan data is preserved in the database — if you reactivate later, your history is still there</li><li>No frontend or admin side effects</li></ul><p>Deactivating MUT is completely safe and reversible.</p>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-unused',
                'icon'  => '📂',
                'title' => 'Unused Files',
                'items' => array(
                    array(
                        'q' => 'A file shows as "Unused" but I know it\'s being used. Why?',
                        'a' => '<p>This usually means the file is referenced in a way the last scan didn\'t pick up. Common reasons:</p><ul><li>The scan data is stale — a new post was created after the last scan</li><li>The file is used by a plugin MUT doesn\'t have a detector for yet</li><li>The file URL is loaded dynamically via JavaScript (MUT scans saved post content, not runtime JS)</li></ul><p>Run a fresh scan, then check again. If it still shows unused, the safe delete modal will run a live re-check before allowing deletion.</p>',
                    ),
                    array(
                        'q' => 'Is it safe to delete unused files?',
                        'a' => '<p>MUT\'s Safe Delete workflow runs multiple checks before allowing deletion:</p><ul><li>Confirms no usage records from the last scan</li><li>Runs a live re-check of current post content right at deletion time</li><li>Warns if scan data is over 30 days old</li></ul><p>Files are moved to the MUT Trash (not permanently deleted) so you can restore them if something breaks.</p><div class="mut-faq-warn">⚠️ Always check the safe delete results before confirming. If any check fails, the delete is blocked by default.</div>',
                    ),
                    array(
                        'q' => 'What\'s the difference between "Unused Files" and "Cleanup Suggestions"?',
                        'a' => '<p><strong>Unused Files</strong> is a flat list of every file with zero usage records — useful for browsing and searching.</p><p><strong>Cleanup Suggestions</strong> groups them by age: Recently Uploaded (give them time), Review Recommended (30–90 days old), and Likely Unused (90+ days old, safe to act on). It\'s a more guided workflow for bulk cleanup.</p>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-delete',
                'icon'  => '🗑️',
                'title' => 'Deleting & Trash',
                'items' => array(
                    array(
                        'q' => 'What happens when I delete a file in MUT?',
                        'a' => '<p>The file is moved to a private <code>mut-trash/</code> folder inside your uploads directory — it is <strong>not</strong> permanently deleted. The attachment record is removed from WordPress, usage records are cleaned up, and a log entry is created so you can restore the file later.</p>',
                    ),
                    array(
                        'q' => 'How do I restore a deleted file?',
                        'a' => '<p>Go to <strong>Media Usage → Trash</strong>. Every deleted file is listed there. Click <strong>↩ Restore</strong> on any item to move it back to the Media Library. The attachment record is re-created and image metadata is regenerated automatically.</p>',
                    ),
                    array(
                        'q' => 'What does "Delete Permanently" do?',
                        'a' => '<p>It removes the file from the <code>mut-trash/</code> folder and deletes the log record. This action cannot be undone — the file is gone for good. Only use it when you are certain the file is no longer needed.</p><div class="mut-faq-warn">⚠️ Permanent deletion is irreversible. Use Restore first if you have any doubt.</div>',
                    ),
                    array(
                        'q' => 'Do I need to re-scan after deleting files?',
                        'a' => '<p>No. When a file is deleted, its WordPress attachment record and usage data are removed immediately. Dashboard counts (Total Media, Unused Files, Storage) reflect the change on the next page load. The only thing that stays "frozen" is the scan history snapshot — that\'s a record of what was found at scan time, which is expected behaviour.</p>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-quality',
                'icon'  => '✅',
                'title' => 'Quality Audit',
                'items' => array(
                    array(
                        'q' => 'What does the Quality Audit check?',
                        'a' => '<p>It runs the following checks across your media library:</p><ul><li><strong>Missing Alt Text</strong> — images without an alt attribute (SVGs excluded)</li><li><strong>Missing Caption</strong> — images with no caption (SVGs excluded)</li><li><strong>Missing Description</strong> — attachments with no description field filled in (SVGs and decorative images excluded)</li><li><strong>WebP Conversion</strong> — JPEG/PNG files that could benefit from converting to WebP for smaller file sizes</li><li><strong>Oversized Images</strong> — images that exceed recommended dimensions</li><li><strong>Duplicate Files</strong> — exact or similar copies in the library</li></ul>',
                    ),
                    array(
                        'q' => 'Why are SVGs excluded from caption and description checks?',
                        'a' => '<p>SVGs are typically icons, logos, or vector graphics — they\'re rarely displayed in a context where a caption or long description is meaningful. Excluding them reduces noise so the audit highlights issues that actually matter for accessibility and SEO.</p>',
                    ),
                    array(
                        'q' => 'What is a "decorative" image and why is it excluded?',
                        'a' => '<p>A decorative image is one where the alt text field exists but is intentionally set to empty. This is a valid accessibility pattern — it signals to screen readers that the image is purely visual and should be ignored. MUT detects this and excludes those images from the Missing Alt Text results.</p>',
                    ),
                    array(
                        'q' => 'Can I generate alt text and captions automatically?',
                        'a' => '<p>Yes. If you have an AI provider configured in <strong>Settings</strong> (Gemini, Claude, or Groq), a <strong>✨ Generate with AI</strong> button appears on the native WordPress Edit Media page and in the media modal. It fills the field automatically — just click <strong>Update</strong> to save.</p><div class="mut-faq-tip">💡 You can also bulk-generate alt text for all missing files from the Quality Audit page.</div>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-search',
                'icon'  => '🔎',
                'title' => 'Search & Filter',
                'items' => array(
                    array(
                        'q' => 'How does the AI natural language search work?',
                        'a' => '<p>Type a plain-English description of what you\'re looking for — e.g. <em>"large unused images from last year"</em> or <em>"PDFs uploaded this month"</em> — and the AI translates it into filters automatically. It detects usage status, media type, date range, and file size from your query.</p><div class="mut-faq-tip">💡 AI search requires a provider to be configured in Settings. It doesn\'t search by filename — use the text search box for that.</div>',
                    ),
                    array(
                        'q' => 'What is the "Referenced By" filter?',
                        'a' => '<p>It lets you filter media files to only those referenced by a specific plugin — for example, show only files used by Elementor, or only files used by JetEngine. The dropdown only appears if MUT has detected that the relevant plugin is installed and active on your site.</p>',
                    ),
                    array(
                        'q' => 'Why do I see delete buttons only when filtering to Unused?',
                        'a' => '<p>Delete actions are only shown when the Unused filter is active because that\'s the only context where acting on files makes sense. Showing delete buttons on every row for all files would be too easy to misuse. The bulk checkboxes follow the same logic.</p>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-ai',
                'icon'  => '✨',
                'title' => 'AI Features',
                'items' => array(
                    array(
                        'q' => 'Which AI providers are supported?',
                        'a' => '<p>MUT supports three providers:</p><ul><li><strong>Google Gemini</strong> — vision-capable, good for image analysis</li><li><strong>Anthropic Claude</strong> — strong at descriptive alt text</li><li><strong>Groq</strong> — fast, cost-effective option</li></ul><p>Configure your preferred provider and API key in <strong>Settings</strong>.</p>',
                    ),
                    array(
                        'q' => 'Does generating alt text save automatically?',
                        'a' => '<p>No. The generated text is filled into the field but you must click <strong>Update</strong> (on the Edit Media page) to save it to the database. This gives you a chance to review and edit the suggestion first.</p>',
                    ),
                    array(
                        'q' => 'Does AI search store or send my media files anywhere?',
                        'a' => '<p>The natural language search only sends your text query to the AI provider — no images or file data leave your server. Image analysis (alt text generation) sends the image to the configured provider\'s API. Review the privacy policy of your chosen provider if this is a concern.</p>',
                    ),
                ),
            ),

            array(
                'id'    => 'faq-reports',
                'icon'  => '📊',
                'title' => 'Reports & Export',
                'items' => array(
                    array(
                        'q' => 'What can I export?',
                        'a' => '<p>MUT supports several export formats across different pages:</p><ul><li><strong>CSV / Excel</strong> — from Cleanup Suggestions and Bulk Review</li><li><strong>PDF</strong> — full library report from the Dashboard, bulk review list from Bulk Review, and scan history from Reports</li></ul>',
                    ),
                    array(
                        'q' => 'What does the scan history show?',
                        'a' => '<p>The Reports page lists every scan that has been run — when it started, how many files were scanned, how many were in use, and how many were unused. It\'s a historical record useful for tracking library growth or cleanup progress over time.</p>',
                    ),
                ),
            ),

        );
    }
}
