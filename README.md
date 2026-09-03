# Bunny Queue Press

Bunny Queue Press is a lightweight editorial scheduling plugin for WordPress. It provides a compact Pipeline overview, recurring weekly slot configuration in Calendar Settings, and an Add to Queue workflow that keeps Gutenberg autosave-safe while assigning future schedule dates. Since 2.0.0 it also integrates with Buffer for social media publishing to Instagram, X/Twitter, and Threads.

## Plugin metadata

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

