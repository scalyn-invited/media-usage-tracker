=== Media Usage Tracker ===
Contributors: YajAce
Tags: media, unused, cleanup, audit
Requires at least: 6.8
Tested up to: 6.8
Stable tag: 1.2.3
License: GPLv2 or later

Identifies where every media file is used across your site, flags unused/duplicate/low-quality files, and helps clean it all up — with AI-assisted alt text along the way.

== Description ==

Media Usage Tracker scans your posts, pages, and custom post types (plus a wide range of page builders and plugins) to build a full picture of where each image or file is actually used — so you can safely find and remove what isn't, without guessing.

**Where things are used**

* Media by Page — browse any page and see every image on it, grouped by the widget/section it came from
* Media Usage / Search & Filter — look up any file and see every location that references it
* Detects usage inside content, Elementor, ACF, Divi, WPBakery, Beaver Builder, Avada, Astra, WooCommerce, JetEngine, JetPopup, Yoast SEO, Gravity Forms, and wpDataTables

**Cleanup tools**

* Unused Files — everything with zero detected usage, ready to review or delete
* Duplicate Analysis — find identical/near-identical files wasting storage
* Storage Optimization — surface your biggest space wins
* Bulk Review — flag, archive, or clear files in bulk
* Trash — a safety net before anything is permanently deleted

**Quality Audit**

* Flags missing alt text, missing captions, oversized images, unsupported formats, and WebP opportunities
* AI-generated alt text and captions (OpenAI/Anthropic/Groq — bring your own API key), reviewed before saving
* Mark an image as decorative to exclude it from alt text checks

**Keeping data current**

* Scheduled scans on a cron interval, with an optional email summary
* Instant Updates — rescans a page or form automatically right after it's saved or deleted, instead of waiting for the next scan

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/media-usage-tracker`, or install through the WordPress Plugins screen.
2. Activate the plugin.
3. Go to Media Usage → Reports and run your first scan.

== Changelog ==

= 1.2.3 =
* Fixed: the Mark Decorative "Unmark" button no longer looks faded/disabled — only the alt text value itself dims when an image is marked decorative.
* Updated readme with a full feature list and changelog.

= 1.2.2 =
* Added AI Generate alt text and Mark Decorative controls to the Media Usage detail page.

= 1.2.1 =
* Fixed: Gravity Forms images now show up on pages that embed the form via `[gravityform]`, instead of only under the form itself.

= 1.2.0 =
* Added Instant Updates — automatic background rescans on save/delete, instead of waiting for the next manual or scheduled scan.
* Fixed a false "(Deleted)" label on Gravity Forms/wpDataTables usage rows.

= 1.1.1 =
* Made Quality Audit cards clickable.

= 1.1.0 =
* Added a "Check for Updates" button to Settings, plus a GitHub-based update checker.

= 1.0.0 =
* Initial release.
