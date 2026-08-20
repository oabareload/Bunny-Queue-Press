# Bunny Queue Press

Bunny Queue Press is a lightweight editorial scheduling plugin for WordPress. It provides a compact Pipeline overview, recurring weekly slot configuration in Calendar Settings, and an Add to Queue workflow that keeps Gutenberg autosave-safe while assigning future schedule dates. Since 2.0.0 it also integrates with Buffer for social media publishing to Instagram, X/Twitter, and Threads.

## Plugin metadata

- Plugin Name: Bunny Queue Press
- Plugin URI: https://bunnychase.net/bunny-queue-press/
- Author: BunnyChase
- Author URI: https://bunnychase.net/
- Text Domain: wp-queuepress
- Domain Path: /languages
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer

## Features

- Pipeline overview page for drafts, scheduled posts, and recently published content
- Calendar Settings for weekly recurring slot configuration
- Add to Queue workflow from the Gutenberg editor
- Add First workflow that rebuilds the entire queue placing the new post first
- Draft → Schedule workflow without manual publish date management
- Autosave-safe scheduling behavior during queue assignment
- Confirmation modal for Add First showing all posts affected before committing
- Compact editorial pipeline cards with full-card click targets
- Single-user focused workflow for small editorial teams or solo creators
- Minimal native admin styling with no JavaScript framework dependency
- Buffer integration for publishing to Instagram, X/Twitter, and Threads
- Per-platform resend from the Pipeline without re-publishing to all channels
- Delete Buffer publications directly from the Pipeline action menu
- Future queue reordering via position swap: Move Down action and Drag & Drop
- Move Down moves a scheduled post one position lower by swapping dates with the next post
- Drag & Drop on the Future column performs a date swap between the dragged card and the drop target
- No index rebuilding or multi-post shifting during reorder operations
- Buffer Queue for resilient background publishing with automatic retry on image errors

## Workflow

1. Configure reusable weekly publishing slots in **Calendar Settings**.
2. Draft content in Gutenberg.
3. Select **Add to Queue** or **Add First** in the QueuePress panel.
4. Gutenberg updates the post date so the Publish button becomes Schedule.
5. Autosave is temporarily paused while queue mode is active to keep date changes safe.
6. For Add First: click "Review & Confirm Queue Rebuild" in the pre-publish panel to preview all affected posts before saving.
7. Review drafts, scheduled posts, and published content in the **Pipeline** page.
8. Use the per-card action menu (⋮) to send to Buffer, resend to a specific platform, or delete all Buffer publications for a post.

## Admin Pages

- Pipeline — editorial overview of Drafts, Scheduled, and Published posts
- Calendar Settings — recurring weekly slot configuration with weekdays only
- Settings — plugin preferences and queue assignment controls
- Buffer Settings — Buffer connection and channel configuration
- Lab — GraphQL playground, debug console, Buffer diagnostics, and developer tools

---

## Architecture

### Platform_Registry — single source of truth

Since 2.2.0, all platform definitions live exclusively in `Platform_Registry` (`includes/Buffer/Platform_Registry.php`). No other file declares platform labels, icons, publisher classes, field definitions, limits, defaults, or post styles. Every consumer reads from the Registry.

**What the Registry owns:**

| Concern | Method |
|---|---|
| Human-readable label | `Platform_Registry::label(string $slug): string` |
| SVG icon markup | `Platform_Registry::icon_svg(string $slug): string` |
| Publisher class name | `Platform_Registry::publisher_class(string $slug): ?string` |
| Character and image limits | `Platform_Registry::limit_value()` / `limit_label()` |
| Default field values | Returned via `Platform_Registry::get($slug)['extra_defaults']` |
| Supported post styles | Returned via `Platform_Registry::get($slug)['supported_post_styles']` |
| All registered platforms | `Platform_Registry::all(): array` |

**Consumers and how they read from the Registry:**

- `Channel_Config` — delegates `fields_for()`, `limits_for()`, `defaults_for()`, and `sanitize()` entirely to the Registry. Contains zero platform-specific switches.
- `Buffer_Ajax` — resolves publisher classes via `Platform_Registry::publisher_class($service)`. Validates service slugs via `Platform_Registry::exists($service)`.
- `Pipeline_Page` — renders the platform strip by iterating `Platform_Registry::all()`. The loop is fully dynamic; no platform name appears in the render logic.
- `Buffer_Page` — generates per-platform field UI by iterating channel definitions produced from Registry-driven `Channel_Config::fields_for()`.

### How to add a new platform

Adding a platform requires changes to exactly one file: `Platform_Registry.php`.

Inside `Platform_Registry::build()`, add an entry to the returned array:

```php
'myplatform' => array(
    'label'               => 'My Platform',
    'publisher_class'     => Twitter_Publisher::class, // reuse or create a publisher
    'icon_svg'            => '<svg ...>...</svg>',
    'limits'              => array(
        array('label' => 'Character limit', 'value' => 280),
        array('label' => 'Max images',      'value' => 4),
    ),
    'extra_defaults'      => array(
        'post_style' => 'social_post',
    ),
    'supported_post_styles' => array('social_post', 'card_link'),
    'default_post_style'    => 'social_post',
),
```

After adding the entry:
- The platform appears automatically in the Buffer Settings UI (fields rendered from `Channel_Config::fields_for()`).
- The platform icon appears in the Pipeline strip as soon as a channel with that service slug is synced.
- `Buffer_Ajax` will route publish requests to the declared publisher class without any code change.
- No modifications are needed in `Channel_Config`, `Buffer_Ajax`, or `Pipeline_Page`.

### What was eliminated in 2.2.0

Prior to 2.2.0, platform identity was distributed across multiple files:

- `Channel_Config` contained `switch ($service)` blocks inside `fields_for()`, `limits_for()`, `defaults_for()`, and `sanitize()`. Each new platform required edits to all four methods.
- `Buffer_Ajax::publish_to_services()` contained a manual `switch` that mapped service slugs to publisher class names.
- `Pipeline_Page` contained a static list of platform slugs for icon rendering.

All of these were replaced by delegation to `Platform_Registry`. There are now zero `switch ($service)` statements and zero hardcoded platform lists outside the Registry.

---

## Pipeline

### Per-platform resend

Each platform icon in the Pipeline strip is clickable whenever the platform has at least one enabled Buffer channel configured, regardless of whether a Buffer record was previously saved for that post. Clicking a platform icon sends the post to Buffer for that specific service only, without re-publishing to all connected channels.

The icon label adapts based on whether a confirmed record exists:
- **Send to X** — no prior record for that platform.
- **Re-send to X** — a confirmed Buffer record already exists.

The AJAX action `qps_send_to_buffer_service` accepts `post_id` and `service`, resolves the correct publisher from `Platform_Registry`, executes the publish, and updates the strip state in-place.

### Delete Buffer publications

The action menu (⋮) on each pipeline card includes a **Delete Buffer Posts** option. Clicking it sends a confirmed delete request to the AJAX action `qps_delete_buffer_posts`, which calls `Buffer_Client::delete_post()` for each stored `post_id`, removes the persisted meta records, and resets the platform strip to the idle state.

### Visual platform states

Each icon in the strip reflects the real state of the persisted Buffer record:

| State class | Meaning |
|---|---|
| `qps-platform--idle` | No Buffer record exists for this platform |
| `qps-platform--pending` | Buffer returned a pending/processing status |
| `qps-platform--scheduled` | Post is queued in Buffer but not yet published |
| `qps-platform--success` | Post was successfully sent to Buffer |
| `qps-platform--error` | Buffer returned an error or failure status |

State is derived exclusively from the persisted `_queuepress_buffer_channels` meta key. No guessing based on request success alone.

### Dynamic platform strip

The strip rendered beneath each card thumbnail is generated entirely from `Platform_Registry::all()`. The PHP template contains no platform names — only a `foreach` over the registry. Adding a new platform to the Registry causes it to appear automatically in every pipeline card.

Clickability is determined server-side at render time based on whether `Channel_Config` reports at least one enabled channel for the platform slug. No client-side DOM promotion of `<span>` elements to `<button>` elements occurs — the correct element type is always emitted from PHP.

---

## Publishers

### social_post

Produces content-first social posts by assembling the caption from the post excerpt and body. When the combined text exceeds the platform character limit, the content is automatically split into a thread (Twitter and Threads) or truncated (Instagram). Images are distributed across thread elements up to the per-platform image limit.

Caption construction order:
- Thread element 0: excerpt + permalink
- Thread element 1: post title + first body chunk
- Thread elements 2…N: remaining body chunks

### card_link

Produces a traffic-oriented post using the post excerpt as the body text, the post URL as an attached link, and the post thumbnail as the preview image. Never generates a thread. Text is always trimmed to fit within the platform character limit. An SEO title is used when available.

### Twitter

Supports both `social_post` and `card_link`. In `social_post` mode, long content is split into a numbered thread using `split_caption_into_chunks()`. Images are distributed across thread elements. Premium accounts can use longer threads (`max_thread_posts` limit). The `premium_account` flag is configurable per channel.

### Threads

Supports both `social_post` and `card_link`. Follows the same thread model as Twitter in `social_post` mode. NSFW posts are automatically forced to `card_link` mode — this rule is enforced by `Threads_Publisher` and is not user-configurable. In `card_link` mode only the featured image is used.

### Instagram

Uses traditional single-post publication. Supports gallery images (featured image + ACF gallery) in SFW posts. NSFW posts use the featured image only — this rule is enforced by `Instagram_Publisher` and is not user-configurable. Does not support thread mode or `card_link`.

---

## Configuration

### Dynamic field generation

Buffer Settings fields for each platform are generated from `Channel_Config::fields_for($service)`, which reads field definitions from `Platform_Registry`. The UI contains no hardcoded field lists. Adding a field to a platform definition in the Registry causes it to appear in the settings form automatically.

### premium_account

Available for X/Twitter channels. When enabled, the Twitter publisher allows longer threads by reading the `max_thread_posts` limit from the Registry rather than applying the standard limit. Stored as a boolean in `_queuepress_buffer_channels` keyed by `channel_id`.

### post_style

Available for X/Twitter and Threads channels. Controls whether the publisher uses `social_post` (content-first, thread-capable) or `card_link` (excerpt + URL + thumbnail) for each channel. The default value per platform is defined in `Platform_Registry::get($slug)['default_post_style']`. Stored per channel in `_queuepress_buffer_channels`.

The `post_style` UI field is rendered only for platforms that declare `supported_post_styles` with more than one option. Instagram does not expose this field.

---

## Migrations

### Schema version 2

Introduced in 2.2.0. The `_queuepress_buffer_channels` configuration schema was cleaned to remove fields that were previously user-configurable but have since been reclassified as internal system rules enforced by publishers:

- `content_source` — removed. Content assembly strategy is now determined by `post_style` and platform-specific publisher logic.
- `image_source` — removed. Image selection rules (featured-only for NSFW, gallery for SFW) are now enforced exclusively by each publisher.

These fields should never appear in the settings UI. Their removal prevents user confusion and ensures the system behaves consistently regardless of what older configuration rows contain.

### Automatic legacy cleanup

`Channel_Config::migrate_legacy_config()` is called once on plugin load. It reads the stored schema version from the `wp_queuepress_channel_config_schema_version` option. If the stored version is less than `2`, it iterates all channel configurations stored in `_queuepress_buffer_channels`, strips `content_source` and `image_source` from each record, and updates the stored option to `2`.

On subsequent requests the guard condition (`$current >= CURRENT_SCHEMA_VERSION`) returns immediately after a single cached `get_option()` call. There is no database write after the first migration.

---

## Changelog

### 2.6.11

**Added**
- Buffer Queue's automatic retry is now configurable from Lab → **Buffer Retry Error Rules**, instead of matching one hardcoded error string. Each rule matches a Buffer error message by `contains` or `exact` text; a match auto-retries the job (same FIFO delete/reinsert behavior as before, still with no retry limit). With zero rules configured, no error is auto-retried and every failure ends as `failed`. Manual **Retry now** is unaffected by these rules. Rules are stored in a single WordPress option (`qps_buffer_retry_rules`), not a new table, and are added/removed instantly via AJAX with duplicate and empty-text validation.
- **Buffer Queue Jobs** in Lab is now paginated (20 jobs per page) using standard WordPress pagination links, so the query is limited via `LIMIT`/`OFFSET` instead of loading up to 100 rows unconditionally. Columns and actions (Retry now, Cancel/Remove, Share now) are unchanged.

### 2.6.10

**Added**
- Added a per-job **Share now** switch to Buffer Queue Jobs in Lab, letting a `pending` job be marked to publish immediately (`shareNow`) instead of the default `addToQueue` behavior. The choice is saved instantly via AJAX and is scoped to that individual job only — there is no global setting.

**Fixed**
- Fixed the `qps_buffer_queue_db_version` migration guard not re-running on existing installations, which meant the new `share_mode` column would never be added to an already-created `qps_buffer_queue` table. The guard now targets version `1.1`, forcing exactly one additional `dbDelta()` pass on upgrade. This is non-destructive: no jobs are deleted, the table is not recreated, and existing jobs receive `share_mode = 'addToQueue'` via the column's `DEFAULT`.

### 2.6.6

**Added**
- Added an optional Buffer Queue flow in Lab for deferred Buffer publishing with enable/disable controls and configurable worker intervals.
- Added an admin worker status block showing Buffer Queue enabled state, worker scheduled state, interval, last run, and next run.
- Added a Buffer Queue Jobs list in Lab with job status, retry, cancel, and created-at timestamps.
- Added a dedicated queue database table and background worker (`qps_buffer_queue_worker`) to process one FIFO job per cron execution.
- Added timezone-correct presentation for queue timestamps, converting UTC-stored `created_at`, last run, and next run values into the WordPress configured timezone.

### 2.4.2

**Fixed**

- Fixed Move Down failing silently when the next post belongs to a different day group. Card navigation now uses a flat `querySelectorAll` list across all day groups instead of `nextElementSibling`, which cannot cross `<ul>` boundaries.
- Fixed a time-comparison inconsistency in `apply_plan()` that caused valid future dates to be rejected as past dates during a swap. The comparison now uses `DateTimeImmutable` objects in UTC throughout, eliminating ambiguity from `strtotime()` and PHP's `date.timezone` INI setting.
- Fixed drag-and-drop `dragleave` highlight flickering when the cursor passed over child elements inside a card. The handler now uses `card.contains(e.relatedTarget)` to distinguish genuine card exits from internal child transitions.
- Fixed stale `dragSourceId` state when a second `drop` event fired before the async swap response completed. The source ID is now cleared synchronously on `drop` before the fetch call.

**Added**

- Added **Move Up** action to the Pipeline action menu for scheduled posts. Swaps the current post with the previous post in the Scheduled queue. Hidden on the first scheduled post.
- Added **Move Top** action to the Pipeline action menu for scheduled posts. Swaps the current post with the first post in the Scheduled queue. Hidden on the first scheduled post.
- Added a visual drag handle (six-dot icon, `cursor: grab`) in the top-left corner of each draggable card. Appears on hover and during drag. Cursor changes to `grabbing` during active drag.
- Added drop-target highlight (purple outline) on cards during drag-over to make the swap target visually unambiguous.
- Added a **Copy** button per log entry in the Lab Debug Console. Uses the native Clipboard API with an `execCommand` fallback. Copies the full JSON content of that entry to the clipboard and shows a transient `✓ Copied` confirmation.
- Added auto-publish to Buffer when a scheduled post transitions from `future` to `publish` (i.e. WordPress publishes it automatically at its scheduled time). Only fires if no prior Buffer publication attempt has been recorded for the post. If any record exists under `_queuepress_buffer_channels`, the transition is skipped entirely — no partial retries.

### 2.4.1

**Fixed**

- Fixed Move Down failing silently when the next post belongs to a different day group. Navigation now operates on a flat DOM list of all scheduled cards across all day groups, eliminating the `<ul>` boundary limitation.
- Fixed potential accidental publication of posts during queue reordering. `apply_plan()` now validates that the resolved `new_date` is strictly in the future (GMT) before calling `wp_update_post()`. If the date has already passed, the item is skipped and recorded as a conflict.
- Fixed `apply_plan()` re-reading the post status from the database immediately before writing. If the post is no longer `future` at write time (e.g. published by another process between plan computation and execution), the update is skipped entirely.
- Fixed drag-and-drop highlight flickering when the cursor moved over child elements inside a card. The `dragleave` handler now uses `card.contains(e.relatedTarget)` to distinguish genuine card exits from internal child transitions.
- Fixed stale `dragSourceId` state if a second drop event fired before the async swap response completed. The source ID is now cleared synchronously on `drop` before the fetch call.

**Added**

- Added **Move Up** action to the Pipeline action menu for scheduled posts. Swaps the current post with the previous post in the Scheduled queue. Hidden on the first scheduled post.
- Added **Move Top** action to the Pipeline action menu for scheduled posts. Swaps the current post with the first post in the Scheduled queue. Hidden on the first scheduled post.
- Added a visual drag handle (six-dot icon) in the top-left corner of each draggable card. The handle appears on hover and during drag. Cursor changes to `grab` on hover and `grabbing` during drag.
- Added drop target highlight (purple outline) on cards during drag-over to make the swap target visually unambiguous.

### 2.4.0

**New**

- Added `Queue_Rebuilder::compute_swap_plan()` — a backend primitive that computes a two-item plan swapping the `post_date` values of two `future` posts without touching any other posts in the queue.
- Added REST endpoint `POST /wp-queuepress/v1/posts/swap` accepting `{ source, target }` post IDs. Calls `compute_swap_plan()` and `apply_plan()`. Protected by `can_swap_posts()` permission callback requiring `edit_post` and `publish_posts` capability on both posts.
- Added **Move Down** action to the Pipeline action menu (⋮) for scheduled posts. Swaps the current post with the next post in the Future column by calling the swap endpoint. Hidden on the last scheduled post.
- Added **Drag & Drop** reordering on the Future column. Dragging a card and dropping it onto another card triggers a swap between those two posts — identical in behavior and backend call to Move Down. No index math, no multi-post shifting.
- Added `draggable="true"` and `qps-card--draggable` markup to scheduled cards. HTML5 native drag-and-drop API is used; no external library dependency introduced.
- Added `arrow-down` SVG icon to the Pipeline icon set for the Move Down action.

**Behavior**

- Swap is strictly a two-post date exchange. No other posts in the queue are affected.
- Move Down and Drag & Drop are functionally identical — both resolve to `POST /posts/swap { source, target }`.
- The Pipeline reloads after a successful swap so group headers and card order reflect the authoritative server state.
- Move Down is hidden on the last card in the Future column. No affordance is shown when there is no next post to swap with.
- Drag & Drop is restricted to the Future column only. Draft and Published columns are not draggable.
- Buffer records, channel configuration, Add First, and Add to Queue behavior are not affected.

**Architecture**

- `Queue_Rebuilder::compute_swap_plan(int $source, int $target): array` — validates both posts are `future`, reads their current `post_date` values, and returns a two-item plan with dates exchanged.
- `Queue_Controller::post_swap()` — REST handler. Delegates entirely to `compute_swap_plan()` + `apply_plan()`.
- `Pipeline_Page` computes `$swap_flags` (first/last position in flattened ordered queue) server-side. Cards receive `draggable` attribute and Move Down button based on these flags — no client-side position computation.
- `pipeline-buffer.js` — `callSwap()`, `initMoveDown()`, and `initDragDrop()` are additive. No existing handler was modified.

### 2.3.0

**New**

- Added a dedicated **Lab** page for advanced Buffer diagnostics and testing.
- Added a GraphQL Playground capable of executing raw GraphQL queries and mutations directly against the configured Buffer account.
- Added a Debug Console with refresh, download, and clear log actions.
- Added a dedicated Lab Mode gate (`qps_lab_enabled`) requiring explicit user confirmation before enabling advanced tooling.
- Added `Buffer_Client::execute_raw_graphql()` for direct GraphQL execution using the configured Buffer credentials.

**Improved**

- Moved all Buffer debugging and diagnostic tooling out of Buffer Settings into the dedicated Lab page.
- Debug Logging is now managed entirely from Lab.
- Added Lab Mode status indicators and developer-oriented diagnostics.
- Added a dedicated admin navigation tab for Lab.

**Removed**

- Removed the Debug Logging checkbox from Buffer Settings.
- Removed View Debug Log and Clear Debug Log actions from Buffer Settings.
- Removed the inline debug log viewer from Buffer Settings.
- Removed the legacy `admin_post_qps_buffer_clear_debug_log` workflow.

**Architecture**

- Buffer Settings is now focused exclusively on Buffer connection and channel configuration.
- Lab centralizes all developer tooling, diagnostics, GraphQL testing, and debug log management.
- Debug logging continues using the existing `debug_buffer` setting inside `wp_queuepress_buffer_settings`; no migration is required.

### 2.2.1

**Fixed**

- Fixed Buffer `deletePost` GraphQL mutation compatibility. The mutation now uses inline fragments (`... on DeletePostSuccess { post { id } }` / `... on MutationError { message }`) matching the union return type in Buffer's schema. The prior `{ id }` selection on the root payload never resolved.
- Fixed deletion of Buffer posts from the Pipeline UI as a direct consequence of the above.
- Fixed platform actions becoming unavailable when Buffer returned an error despite receiving the publication. Platform icons are now always clickable when at least one enabled channel exists, so a failed or timed-out send never permanently locks the user out of retrying.
- Fixed platform icon `qps-platform--clickable` being removed from the strip after a successful delete. Icons correctly retain their clickable state after a delete, since clickability depends on channel configuration, not on record existence.

**Improved**

- Platform icon clickability is now driven by enabled channel configuration (`Channel_Config` / `Platform_Registry`) rather than by the existence of a saved Buffer record. A platform is clickable as long as at least one enabled channel exists for that service.
- Platform icons now show **Send to X** (no prior record) or **Re-send to X** (confirmed record exists), making the intended action unambiguous.
- Removed obsolete span-to-button DOM promotion logic from `pipeline-buffer.js`. Clickable state is emitted server-side as a `<button>` element; no client-side element replacement is performed.
- Improved consistency between `Platform_Registry`, `Pipeline_Page`, and `Buffer_Ajax`.

**Architecture**

- `Platform_Registry` remains the single source of truth for labels, icons, publisher classes, limits, defaults, and supported post styles.
- `Channel_Config` delegates all platform-specific behavior to `Platform_Registry`.
- `Buffer_Ajax` resolves publishers dynamically through `Platform_Registry`.
- Pipeline platform strip is fully registry-driven; clickability is determined at render time from `Platform_Registry::active_slugs()`.

### 2.2.0

**Platform_Registry — unified platform architecture**

- Introduced `Platform_Registry` as the single source of truth for all platform definitions. Labels, icons, publisher classes, field definitions, limits, defaults, and post styles are now declared once and consumed everywhere.
- Removed all `switch ($service)` blocks from `Channel_Config`. The four methods `fields_for()`, `limits_for()`, `defaults_for()`, and `sanitize()` now delegate entirely to the Registry.
- `Buffer_Ajax::publish_to_services()` now resolves publisher classes via `Platform_Registry::publisher_class()` instead of a manual switch.
- `Pipeline_Page` platform strip is now fully dynamic, driven by `Platform_Registry::all()`. No platform names appear in the render loop.
- Added `Buffer_Client::delete_post()` for deleting individual Buffer publications by their Buffer post ID.

**Pipeline improvements**

- Per-platform resend: clicking a platform icon in the strip sends the post to Buffer for that service only (`qps_send_to_buffer_service` AJAX action).
- Delete Buffer Posts: action menu option removes all Buffer records for a post and resets the strip to idle.
- Unified platform state resolver — states `pending`, `queued`, `processing`, `scheduled`, `added_to_queue`, `sent`, `published`, `success`, `error`, and `failed` are all mapped to visual modifiers consistently.
- Platform strip generated dynamically from `Platform_Registry::all()`.

**Publishers**

- `Twitter_Publisher` and `Threads_Publisher` support both `social_post` and `card_link` post styles.
- `Instagram_Publisher` maintains traditional single-post publication.
- NSFW rules enforced by publishers, not by configuration.

**Configuration**

- `post_style` field rendered only for platforms that declare multiple supported styles.
- `premium_account` field available for Twitter channels.
- All field definitions sourced from `Platform_Registry` via `Channel_Config`.

**Migrations**

- Schema version bumped to 2.
- `Channel_Config::migrate_legacy_config()` strips `content_source` and `image_source` from all stored channel records on first load after upgrade.
- Migration runs at most once; subsequent requests exit the guard in O(1) via a cached `wp_options` read.

### 2.1.1

**Twitter & Threads reliability fixes**

- Fixed thread generation where the title was prepended after chunking, potentially producing thread items that exceeded platform character limits.
- Thread chunking now operates on the complete content including the title, so `split_caption_into_chunks()` has full visibility of the space required from the start.
- Added a final validation pass that re-splits any oversized chunk as a safety mechanism, covering edge cases not caught by the primary chunking logic.

**Pipeline status improvements**

- Buffer states `scheduled`, `queued`, `added_to_queue`, and `success` are now displayed as successful submissions in the platform status strip.
- Platform indicators in the Pipeline now better reflect the real publication submission state returned by Buffer.
- Removed the `--scheduled` yellow state; all confirmed submission states are green.

**Stability**

- Improved consistency between Twitter and Threads publishing flows.
- Production fixes based on real publishing failures.
- Confirmed Instagram publisher does not use chunking and requires no changes.

### 2.1.0

- **Pipeline UI Refresh:**
  - Moved the per-post action menu from the card header onto the post thumbnail (top-right overlay).
  - Replaced the legacy Unicode ✓ indicator with a per-platform status strip (Buffer, X/Twitter, Threads, Instagram) rendered as inline SVG icons with `fill="currentColor"`.
  - Added per-platform state modifiers (`qps-platform--idle | --success | --scheduled | --pending | --error`) with dedicated tooltips (platform name, state, sent-at timestamp).
  - Introduced a placeholder state for posts without a featured image so the card keeps a consistent 140×140 footprint and the action menu remains usable.
  - Tightened thumbnail and strip dimensions (miniature 140×140, icon 14×14, gap 1px, strip capped at ~55% of the image width) to make the strip a discreet indicator rather than a dominant element.
  - Derived platform state exclusively from the real persisted Buffer record (`post_id` + `sent_at`), preventing "Not Sent" tooltip mismatches when legacy or empty meta rows exist.
  - Cleaned up legacy selectors (`.qps-buffer-indicator`, `.qps-action-menu*`) and the `get_buffer_channel_entry()` helper, which were superseded by the new `resolve_platform_state()` resolver.

- **Content publishing improvements:**
  - Titles now pass through `html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')` so entities like `&#8211;` and `&#8217;` render as real Unicode characters.
  - The system no longer extracts, rebuilds, or appends a hashtags block. Hashtags stay exactly where the author wrote them in the content; truncation is accepted as a natural consequence of the platform limit.
  - Instagram Social Post caption now follows the exact order: excerpt + title + full post + permalink (no rebuilt hashtag block).
  - Twitter and Threads Social Post threads use the new model: element 0 = excerpt + permalink, element 1 = title + first body chunk, elements 2..N = remaining body chunks (no duplicated title, no duplicated hashtags, no duplicated permalink).
  - `force_source => 'excerpt'` now always reads `post_excerpt` regardless of whether the post uses Gutenberg blocks.
  - The `prepend_content` path runs through `strip_tags_preserve_breaks()` so pre-built blocks no longer leak raw HTML or HTML entities into the final caption.
  - `build_thread_payload()` was removed because it has no remaining callers; `split_caption_into_chunks()` and `distribute_images_across_chunks()` remain as reusable primitives.

### 2.0.0

- **Major release — Full Buffer integration:**
  - Added complete Buffer integration including authentication, per-channel configuration, and publishing pipeline.
  - Implemented `Buffer_Client` transport with safe GraphQL JSON encoding and debug logging stored in WordPress option `_queuepress_buffer_debug_log`.
  - Added `Publisher_Commons`, `Mutation_Commons`, and service-specific publishers (`Instagram_Publisher`, `Twitter_Publisher`, `Threads_Publisher`) to centralize caption, asset, hashtag, and mutation construction logic.
  - Implemented `social_post` and `card_link` post styles with correct permalink/hashtag handling and per-service character limits.
  - Automatic thread splitting for long content with image distribution across thread elements and NSFW handling (Threads fall back to `card_link`).
  - AJAX orchestration updated (`Buffer_Ajax`) to publish to enabled Buffer channels and persist per-channel records.
  - Added debug logging utilities (`Buffer_Debug`) to capture request/response and aid troubleshooting.
  - Many internal improvements: safer GraphQL escaping, block-aware caption extraction using `parse_blocks()`, and consolidated publisher behavior.

### 1.4.3

- **Fixed queue status transition and Gutenberg state sync issues:**
  - Added controlled metadata cleanup on the `future` → `draft` status transition to remove `_wp_queuepress_queue_mode` and clean post object cache immediately.
  - Resolved post-requeuing inconsistencies by executing `clean_post_cache()` before fetching posts in the enqueuing pipeline (`Queue_Commit_Handler::commit_add_to_queue`).
  - Implemented automatic initial slot computation in the Gutenberg component (`editor.js`) when mounting a draft that has an active pre-existing queue mode, avoiding date mismatch when scheduling.

### 1.4.2

- **Fixed self-collision scheduling bug:** added a sentinel post guard to prevent `Schedule_Calculator` from executing its database fallback query when scheduling a post in an otherwise empty week. This ensures the post is correctly scheduled at its previewed slot (e.g., 10:00 AM) rather than shifted to subsequent slots (e.g., 8:00 PM) due to colliding with its own newly-saved database status.

### 1.4.1

- **Bunny Admin UI system — homologation:** migrated all shared admin UI classes from the `qps-*` prefix to the unified `bunny-*` system. Header, nav, tabs, wrappers, badges, and spacing now use `.bunny-*` classes and `--bunny-*` CSS custom properties consistent across all Bunny plugins.
- **New `bunny-admin.css`:** introduced a dedicated, plugin-agnostic stylesheet containing only shared admin chrome (sticky header, tab navigation, version badge, page-content wrapper, responsive breakpoints). Plugin-specific styles remain in `admin.css`.
- **Sticky admin header:** the shared header is now `position: sticky; top: 32px` so it remains visible while scrolling long pages like Calendar Settings.
- **Page subtitle:** `Admin_Header::render()` now accepts an optional `$page_label` argument and renders a `.bunny-page-subtitle` below the plugin name, showing the current section name.
- **Shadow and transition tokens:** `--bunny-shadow`, `--bunny-shadow-hover`, and `--bunny-transition` added to the shared variable set, sourced from Bunny Affiliate Manager's visual system.
- **`bunny-admin.css` enqueued separately:** declared as a WordPress style dependency before `admin.css` to allow future plugins to load the base independently.
- **No functional changes:** all plugin logic, routing, REST endpoints, AJAX handlers, and WordPress hooks are unchanged.

### 1.4.0

- **Shared admin header:** introduced `Admin_Header`, a reusable PHP helper that renders a consistent header block — plugin logo, name, and version badge — across all admin pages without duplicating markup.
- **Persistent tab navigation:** all admin pages (Pipeline, Calendar Settings, Settings) now share a horizontal tab bar rendered by `Admin_Header`. The active tab is detected by the page slug passed at render time; no `$_GET` parsing is required.
- **Modernized admin UI styles:** added a dedicated CSS section in `admin.css` using scoped `--qps-*` custom properties. Tab underline accent color is `#6c47ff`. Includes soft borders, consistent spacing, and a version pill badge in the header.
- **No logic or routing changes:** all existing callbacks, REST endpoints, AJAX handlers, and WordPress hooks remain untouched. Only layout and presentation were modified.

### 1.3.0

- **Add First queue rebuild:** when a post is scheduled with Add First mode, the plugin now recalculates and reassigns dates for all existing scheduled posts, not just the new one. The new post takes the first available slot and all other scheduled posts shift forward in their current relative order, with no duplicate slots.
- **Add First confirmation modal:** clicking Schedule with Add First mode no longer commits immediately. A pre-publish panel appears with a "Review & Confirm Queue Rebuild" button. Clicking it fetches the full proposed rebuild from the server and displays a confirmation modal showing the new post's slot and a table of every affected scheduled post with its current and new publication date. The save only proceeds after the user explicitly confirms.
- **None mode reverts to Draft correctly:** selecting None now clears the tentative future date from the Gutenberg editor and resets the post status to Draft before removing the queue meta. The post no longer remains in Scheduled/Future state after deselecting a queue mode.
- **New REST endpoint `GET /add-first-preview`:** returns the complete proposed Add First rebuild plan — new post slot plus all affected posts with formatted date labels — without modifying any data. Used by the confirmation modal.
- **Gutenberg deprecation warnings resolved:** `PluginDocumentSettingPanel` and `PluginPrePublishPanel` now resolve from `wp.editor` (correct since WP 6.6) with a safe fallback to `wp.editPost` for older installs.

### 1.2.2

- Added deterministic Rebuild Preview: compute-only planner persists a proposed plan to `qps_pending_rebuild` and returns a human-friendly preview in the admin UI.
- Added Apply Rebuild execution (single-request): an admin action that reads the persisted plan and applies scheduled date updates sequentially using the precomputed dates. The operation is synchronous (single AJAX request), continues on individual failures, collects conflicts, and deletes the pending plan after the attempt.
- Improved Apply Rebuild UX: kept the preview modal open during apply, added a professional spinner and progress bar, in-modal result summary with conflict list, and retry/close actions. No background workers or batching were introduced in this release.
- Calendar Settings: removed editorial badges such as "Empty Slot" and "Programmed" — the calendar is now strictly a configuration view showing weekly recurring slots only.
- Fixed several client-state issues: transactional staged editor for Calendar Settings, robust save behavior, and correct published-post ordering in the Pipeline.

### 1.2.1

- Added a dedicated Pipeline page for editorial workflow visibility
- Separated Calendar Settings into weekly recurring slot configuration
- Simplified Add to Queue queue assignment behavior
- Improved autosave-safe queue flow in the editor
- Redesigned compact editorial pipeline cards
- Fixed admin UI asset routing and page styling

---

## Installation

1. Copy the `wp-queuepress` folder into `wp-content/plugins/`.
2. Activate **Bunny Queue Press** from the WordPress Plugins screen.
3. Open **Bunny Queue Press > Pipeline** to review editorial status.
4. Open **Bunny Queue Press > Calendar Settings** to configure weekly recurring slots.
5. Use the Gutenberg editor QueuePress panel to schedule drafts safely.
6. Open **Bunny Queue Press > Buffer Settings** to connect your Buffer account and configure per-channel publishing options.

## File structure

```text
Bunny-Queue-Press/
├── LICENSE
├── README.md
└── wp-queuepress/
    ├── assets/
    │   ├── css/
    │   │   ├── admin.css
    │   │   ├── bunny-admin.css
    │   │   └── editor.css
    │   └── js/
    │       ├── buffer-admin.js
    │       ├── calendar.js
    │       ├── editor.js
    │       ├── lab.js
    │       └── pipeline-buffer.js
    ├── includes/
    │   ├── Admin/
    │   │   ├── Admin_Header.php
    │   │   ├── Admin_Menu.php
    │   │   ├── Buffer_Page.php
    │   │   ├── Calendar_Page.php
    │   │   ├── Lab_Page.php
    │   │   ├── Pipeline_Page.php
    │   │   ├── Settings_Page.php
    │   │   └── Slot_Ajax.php
    │   ├── Buffer/
    │   │   ├── Buffer_Ajax.php
    │   │   ├── Buffer_Client.php
    │   │   ├── Buffer_Debug.php
    │   │   ├── Channel_Config.php
    │   │   ├── Instagram_Publisher.php
    │   │   ├── Mutation_Commons.php
    │   │   ├── Platform_Registry.php
    │   │   ├── Publisher_Commons.php
    │   │   ├── Threads_Publisher.php
    │   │   └── Twitter_Publisher.php
    │   ├── Editor/
    │   │   └── Editor_Assets.php
    │   ├── Rest/
    │   │   └── Queue_Controller.php
    │   ├── Schedule/
    │   │   ├── Post_Query.php
    │   │   ├── Queue_Assigner.php
    │   │   ├── Queue_Commit_Handler.php
    │   │   ├── Queue_Rebuilder.php
    │   │   ├── Schedule_Calculator.php
    │   │   └── Slot_Repository.php
    │   ├── Settings/
    │   │   └── Preferences.php
    │   └── Plugin.php
    ├── languages/
    │   ├── wp-queuepress-es_ES-wp-queuepress-editor.json
    │   ├── wp-queuepress-es_ES.mo
    │   ├── wp-queuepress-es_ES.po
    │   └── wp-queuepress.pot
    ├── uninstall.php
    └── wp-queuepress.php
```

## Localization

- English is the default fallback language.
- Translation files are prepared in `languages/`.
- All user-facing strings use the `wp-queuepress` text domain.

---

## Buffer Queue

Since 2.6.0, QueuePress includes an optional Buffer Queue that defers Buffer publications to a background WP-Cron worker. The queue is disabled by default and can be enabled from **Lab > Buffer Queue**.

### How it works

- **Queue OFF** (default): Publishing is synchronous. The pipeline calls the publisher directly and sends to Buffer immediately.
- **Queue ON**: The pipeline creates a `pending` job in the database and returns immediately. A global WP-Cron worker processes exactly one job per execution.

### FIFO processing

The worker always selects the first eligible `pending` job by ascending ID (`ORDER BY id ASC LIMIT 1`). There are no priority levels or timestamp-based ordering.

### Automatic retry

When Buffer returns the exact error `"Image could not be read from its URL."`, the worker deletes the current job and reinserts it as a new `pending` record at the end of the queue, preserving `post_id`, `network`, `channel_id`, `attempts`, and `last_error`. There is no maximum retry limit.

Any other error marks the job as `failed` without automatic retry.

### Pipeline blocking

While a job exists in `pending`, `processing`, or `failed` status for a given `post_id + network` combination, the pipeline blocks creating a duplicate job. The administrator must use **Retry now** or **Cancel** from Lab before re-sending.

### Manual actions (Lab)

- **Retry now**: Available only for `failed` jobs. Processes the job immediately during the AJAX request using the same publisher flow as the worker.
- **Cancel / Remove**: Available for all statuses except `processing`. Removes the job and releases the pipeline block.

### Abandoned jobs

If a job is found in `processing` status at the start of a worker execution (from a previous crash), it is marked as `failed`.

### Worker configuration

- Single global WP-Cron hook: `qps_buffer_queue_worker`
- Configurable interval from Lab: 5, 10, 15, 30, or 60 minutes
- Disabled by default; no cron event is scheduled until the queue is enabled
- `Last run`, `Next run`, and job `Created` timestamps are rendered using the WordPress-configured timezone.

### Database

- Table: `{$wpdb->prefix}qps_buffer_queue`
- Created automatically via `dbDelta()` on first admin load
- Non-destructive: existing data is never dropped during updates

