# Media Usage Tracker — Architecture

This document describes the plugin **as built**. It is the source of truth for
the spec; where an earlier planning doc claimed a technology that isn't actually
used, it is listed here under **Future Enhancements** rather than as a current
capability.

---

## Technical Architecture

### WordPress Technologies (in use)

- **Media Library API** — `get_attached_file()`, `wp_get_attachment_image()`,
  `wp_get_attachment_url()`, `wp_count_posts('attachment')`, attachment post types
- **WP_Query / `get_posts()`** — content enumeration during scanning
  (`includes/class-scanner.php`)
- **AJAX** (`admin-ajax.php`) — batched scanning, bulk review actions, and cache
  refresh. Handlers: `mut_start_scan`, `mut_process_batch`, `mut_bulk_action`,
  `mut_refresh_duplicates`, `mut_refresh_optimization`
- **`$wpdb` (custom tables)** — primary data access is hand-written SQL against
  three custom tables (see Database Structure)
- **dbDelta** — schema creation/migration on activation
  (`includes/class-activator.php`)
- **Transients API** — caches duplicate-detection and storage-optimization
  results (`mut_duplicate_groups`, `mut_storage_recommendations`), busted on
  scan completion
- **Options / Settings API** — plugin configuration

### Future Enhancements (planned, not yet implemented)

- **WP Cron** — scheduled automatic background scans (currently scans are
  user-triggered via AJAX batching)
- **REST API** — expose scan/results endpoints (currently uses legacy
  `admin-ajax.php`)
- **WP_List_Table** — refactor the custom result tables onto the native
  list-table class (currently hand-built `<table class="wp-list-table">` markup
  with manual pagination)

---

## Data Collection Sources

The scanner (`includes/class-scanner.php`) runs over `post`, `page`, and all
public custom post types, across `publish`, `draft`, `private`, and `future`
statuses.

### Scanned (in use)

- **`post_content`** — read per post and analysed by two methods:
  - **ID-pattern matching** — regex for `wp-image-(\d+)`, `attachment_id=…`,
    `"id":(\d+)`, `data-id="…"` (covers common Gutenberg image markup)
  - **URL-based detection** — regex matches media URLs
    (`.jpg/.jpeg/.png/.gif/.webp/.pdf/.mp4`) and resolves them to attachment IDs
    by `guid` / `post_name` / `post_title`. This is the most reliable method.
- **Featured Images** — `get_post_thumbnail_id()`, recorded with
  `usage_type = 'featured_image'`
- **Gutenberg Blocks** — caught **indirectly** via the ID-pattern and URL regex
  above. Note: this is regex over raw markup, **not** `parse_blocks()` block
  parsing.

### Future Enhancements (not yet implemented)

- **Elementor Content** — no `_elementor_data` postmeta handling yet
- **Custom Fields** — no `get_post_meta()` scanning yet
- **Gallery shortcode/block** — detected but handling is a stub
  (`// TODO` in `scan_post_content()`)

### Known limitation

ID-pattern matches are gated behind `wp_attachment_is_image()`, so a **PDF or
video** referenced purely by an ID pattern (e.g. `data-id`) is not recorded.
URL-based detection still catches PDFs/MP4s by file extension.

---

## Database Structure

The plugin uses a **normalized three-table design**, not a single flat
"scan results" store.

### `mut_media_usage` — one row per reference

The granular record: every place a media file is referenced becomes a row.

| Column          | Type            | Notes                                            |
|-----------------|-----------------|--------------------------------------------------|
| `id`            | bigint, PK      | Auto-increment                                   |
| `attachment_id` | bigint, indexed | The media file referenced                        |
| `post_id`       | bigint, indexed | The post/page/CPT containing the reference       |
| `post_type`     | varchar(20)     | Type of the referencing post                     |
| `usage_type`    | varchar(50)     | `content` or `featured_image`                    |
| `context`       | text            | ~200-char excerpt of where the reference appears |
| `scan_id`       | bigint, indexed | Links the row to a scan run                      |
| `created_at`    | datetime        | When the reference was recorded                  |

> **Usage Count is derived, not stored.** There is no count column; counts are
> computed on demand via `COUNT(DISTINCT post_id)` (`get_usage_count()`).

### `mut_scan_history` — one row per scan run

The per-run summary.

| Column              | Type        | Notes                          |
|---------------------|-------------|--------------------------------|
| `id`                | bigint, PK  | Auto-increment                 |
| `started_at`        | datetime    | Scan start                     |
| `completed_at`      | datetime    | Scan finish (nullable)         |
| `total_attachments` | int         | Library size at scan time      |
| `files_in_use`      | int         | Count with ≥1 reference        |
| `unused_files`      | int         | Count with no references       |
| `status`            | varchar(20) | `pending` / `running` / `completed` |
| `duration_seconds`  | int         | Scan duration                  |

### `mut_review_status` — one row per flagged attachment

Powers Bulk Review and cleanup flagging.

| Column          | Type                | Notes                    |
|-----------------|---------------------|--------------------------|
| `id`            | bigint, PK          | Auto-increment           |
| `attachment_id` | bigint, unique      | The flagged media file   |
| `status`        | varchar(20)         | `flagged` or `archived`  |
| `flagged_at`    | datetime            | When the status was set  |

---

## Derived / Cached Analyses

These are computed from the tables above and cached in transients (not stored as
tables):

- **Duplicate groups** (`mut_duplicate_groups`) — exact (MD5), similar (filename
  stem), and resize-variant (post_parent) detection
- **Storage recommendations** (`mut_storage_recommendations`) — unused %,
  duplicate waste, large unused files, MIME breakdown

Both caches are invalidated when a scan completes (`complete_scan()`).
