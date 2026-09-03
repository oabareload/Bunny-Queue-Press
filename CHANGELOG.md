# Changelog

### 2.6.14

**Fixed**
- Fixed a regression in the Pipeline where a platform icon that had already been sent successfully (green) became permanently non-clickable, with no way to resend that post to that network manually. Sending is now allowed for any post with status Published, in any per-platform state (not sent, sent, or error), and is blocked only while an active job for that post and network is pending or processing in Buffer Queue — never because of prior send history. This is enforced both in the Pipeline UI and in the request that processes the send.

### 2.6.13

**Fixed**
- Fixed an already-published post occasionally being converted to a scheduled (future) post on save, and re-entering the Queue flow. The editor's Queue mode panel now only shows and applies to draft posts; scheduled and published posts can no longer be affected by it, even when saving from a stale editor session.

### 2.6.12

**Improved**
- Improved Buffer Queue's visual status in the Pipeline: a platform icon now shows a distinct queued state while its job is pending or being processed by Buffer Queue, instead of appearing the same as "not sent". Sent and queued icons are no longer clickable, preventing a duplicate send from being triggered for the same post and platform while one is already in progress.

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
- Fixed stale `dragSourceId` state if a second `drop` event fired before the async swap response completed. The source ID is now cleared synchronously on `drop` before the fetch call.

**Added**

- Added **Move Up** action to the Pipeline action menu for scheduled posts. Swaps the current post with the previous post in the Scheduled queue. Hidden on the first scheduled post.
- Added **Move Top** action to the Pipeline action menu for scheduled posts. Swaps the current post with the first post in the Scheduled queue. Hidden on the first scheduled post.
- Added a visual drag handle (six-dot icon) in the top-left corner of each draggable card. The handle appears on hover and during drag. Cursor changes to `grab` on hover and `grabbing` during active drag.
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