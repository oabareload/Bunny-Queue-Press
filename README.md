# Bunny Queue Press

Bunny Queue Press is a lightweight editorial scheduling plugin for WordPress. It provides a compact Pipeline overview, recurring weekly slot configuration in Calendar Settings, and an Add to Queue workflow that keeps Gutenberg autosave-safe while assigning future schedule dates.

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

## Workflow

1. Configure reusable weekly publishing slots in **Calendar Settings**.
2. Draft content in Gutenberg.
3. Select **Add to Queue** or **Add First** in the QueuePress panel.
4. Gutenberg updates the post date so the Publish button becomes Schedule.
5. Autosave is temporarily paused while queue mode is active to keep date changes safe.
6. For Add First: click "Review & Confirm Queue Rebuild" in the pre-publish panel to preview all affected posts before saving.
7. Review drafts, scheduled posts, and published content in the **Pipeline** page.

## Admin Pages

- **Pipeline** — editorial overview of Drafts, Scheduled, and Published posts
- **Calendar Settings** — recurring weekly slot configuration with weekdays only
- **Settings** — plugin preferences and queue assignment controls

## Changelog
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

## Installation

1. Copy the `wp-queuepress` folder into `wp-content/plugins/`.
2. Activate **Bunny Queue Press** from the WordPress Plugins screen.
3. Open **Bunny Queue Press > Pipeline** to review editorial status.
4. Open **Bunny Queue Press > Calendar Settings** to configure weekly recurring slots.
5. Use the Gutenberg editor QueuePress panel to schedule drafts safely.

## File structure

```text
Bunny-Queue-Press/
├── LICENSE
├── README.md
├── wp-queuepress/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin.css
│   │   │   ├── bunny-admin.css
│   │   │   └── editor.css
│   │   └── js/
│   │       ├── calendar.js
│   │       └── editor.js
│   ├── includes/
│   │   ├── Admin/
│   │   │   ├── Admin_Header.php
│   │   │   ├── Admin_Menu.php
│   │   │   ├── Calendar_Page.php
│   │   │   ├── Pipeline_Page.php
│   │   │   ├── Settings_Page.php
│   │   │   └── Slot_Ajax.php
│   │   ├── Editor/
│   │   │   └── Editor_Assets.php
│   │   ├── Plugin.php
│   │   ├── Rest/
│   │   │   └── Queue_Controller.php
│   │   ├── Schedule/
│   │   │   ├── Post_Query.php
│   │   │   ├── Queue_Assigner.php
│   │   │   ├── Queue_Commit_Handler.php
│   │   │   ├── Queue_Rebuilder.php
│   │   │   ├── Schedule_Calculator.php
│   │   │   └── Slot_Repository.php
│   │   └── Settings/
│   │       └── Preferences.php
│   │   └── Buffer/
│   │       ├── Buffer_Ajax.php
│   │       ├── Buffer_Client.php
│   │       ├── Buffer_Debug.php
│   │       ├── Channel_Config.php
│   │       ├── Instagram_Publisher.php
│   │       ├── Mutation_Commons.php
│   │       ├── Publisher_Commons.php
│   │       ├── Threads_Publisher.php
│   │       └── Twitter_Publisher.php
│   ├── languages/
│   │   ├── wp-queuepress-es_ES-wp-queuepress-editor.json
│   │   ├── wp-queuepress-es_ES.mo
│   │   ├── wp-queuepress-es_ES.po
│   │   └── wp-queuepress.pot
│   ├── uninstall.php
│   └── wp-queuepress.php
```

## Localization

- English is the default fallback language.
- Translation files are prepared in `languages/`.
- All user-facing strings use the `wp-queuepress` text domain.

## Notes

- No background workers, cron jobs, or batch processors are used. All queue operations are synchronous and triggered by the user's explicit save action in Gutenberg.
- The Add First rebuild is compute-only at preview time; changes are only written to the database after the user confirms in the modal.
- Internal prefixes, namespaces, text domains, and database keys remain unchanged from prior releases.
