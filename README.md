# Bunny Queue Press

Bunny Queue Press is a lightweight editorial scheduling plugin for WordPress. Version 2.7.7 presents recurring Immediate and Media slots without week navigation, offers per-calendar rebuild previews on the Calendar screen, and shows editorial Drafts, Future, and Published posts in separate Pipeline columns. Fill posts remain eligible for their configurable 15- or 30-day expiration horizon; daily maintenance at 04:00 uses eligible Fill posts, ordered by expiration, only to cover free Media slots on the current local day. Fill posts and the maintenance process never assign publications to tomorrow or later dates. Relevance badges use consistent Immediate, Media, and Fill colors; posts without valid relevance remain unclassified. Buffer jobs use `shareNow` for Immediate posts and `addToQueue` for Media and Fill posts. WordPress remains responsible for publishing scheduled posts. Links to BunnyChase sent to Buffer are tagged automatically with UTM parameters for each social platform.

## Plugin metadata

## Installation

1. Copy the `wp-queuepress` folder into `wp-content/plugins/`.
2. Activate **Bunny Queue Press** from the WordPress Plugins screen.
3. Open **Bunny Queue Press > Pipeline** to review editorial status.
4. Open **Bunny Queue Press > Calendar** to configure Immediate and Media slots together in one weekly view. Slots before 05:00 in the WordPress site timezone are not allowed; daily Fill maintenance runs at 04:00.
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
    │   │   ├── Media_Fill_Maintenance.php
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

## Automatic UTM tagging

Since 2.7.7, QueuePress adds UTM parameters to BunnyChase links before sending the content to Buffer, so Google Analytics 4 can identify the traffic coming from each social network.

| Platform  | `utm_source` | `utm_medium` |
| --------- | ------------ | ------------ |
| X         | `x`          | `social`     |
| Facebook  | `facebook`   | `social`     |
| Pinterest | `pinterest`  | `social`     |
| Threads   | `threads`    | `social`     |
| Instagram | `instagram`  | `social`     |

`utm_campaign` is the WordPress post slug, normalized to lowercase letters, digits, `-`, and `_`.

Example: `https://bunnychase.net/figura-anime/` sent to Facebook becomes `https://bunnychase.net/figura-anime/?utm_source=facebook&utm_medium=social&utm_campaign=figura-anime`.

### Rules

- Only links on `bunnychase.net` and its subdomains are tagged. External links, media files (`/wp-content/`, images, PDFs), and WordPress system paths are never modified.
- Tagging applies only to the copy of the link prepared for the social network. The original post URL and the article content stored in WordPress are never changed.
- It covers the post permalink (captions, link cards, Pinterest pin URL, truncated-thread notice) and BunnyChase links written in the post content.
- Existing query parameters and `#fragments` are preserved.
- Existing `utm_source`, `utm_medium`, and `utm_campaign` values are replaced with those of the target platform, so they are never duplicated. Other parameters such as `utm_content` or `utm_term` are kept.
- The logic is centralized in `Publisher_Commons::tag_url()`; each platform's `utm_source` is defined in `Platform_Registry`.

### Buffer settings

QueuePress does not change Buffer settings. If a Buffer channel has its own Google Analytics campaign tracking enabled, Buffer may add or override UTM parameters; keep it disabled for BunnyChase channels and verify the final link with a test post.

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

While a job exists in `pending` or `processing` status for a given `post_id + network` combination, the pipeline blocks creating a duplicate job (shown as the blue "queued/processing" state on the platform icon). A `failed`, `cancelled`, or `sent` job never blocks a new send — the icon (green, gray, or red) remains clickable for any post with status Published, and clicking it starts a new attempt for that network.

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

