# Bunny Queue Press

A lightweight editorial scheduling plugin for WordPress that allows creators and publishers to configure reusable weekly publishing slots, visualize scheduled and published content in a clean calendar interface, and identify free publishing spaces quickly.

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

- WordPress 6.0 or newer.
- PHP 7.4 or newer.

## MVP scope

- Admin menu with Calendar and Settings pages.
- Weekly recurring publishing slot settings stored in a WordPress option.
- Retrieval of scheduled posts with `future` status.
- Retrieval of published posts with `publish` status.
- Utility methods for occupied and free slot calculation.
- Gutenberg editor action for queueing a post into the next free publishing slot.
- Visual weekly slot management directly in the Calendar screen.
- Native WordPress admin UI with a small dependency-free stylesheet.

## File structure

```text
wp-queuepress/
├── assets/
│   └── css/
│       └── admin.css
├── includes/
│   ├── Admin/
│   │   ├── Admin_Menu.php
│   │   ├── Calendar_Page.php
│   │   └── Settings_Page.php
│   ├── Schedule/
│   │   ├── Post_Query.php
│   │   ├── Schedule_Calculator.php
│   │   └── Slot_Repository.php
│   └── Plugin.php
├── languages/
│   ├── wp-queuepress.pot
│   └── wp-queuepress-es_ES.po
├── README.md
├── uninstall.php
└── wp-queuepress.php
```

## Architecture decisions

- The plugin uses WordPress hooks, options, Settings API, `WP_Query`, admin menus, and native escaping/sanitization helpers.
- PHP namespaces isolate the plugin code without adding dependencies.
- A small plugin-owned autoloader replaces Composer to keep installation simple.
- Slot storage is centralized in `Slot_Repository`.
- Post retrieval is centralized in `Post_Query`.
- Slot availability logic is centralized in `Schedule_Calculator` so future queue automation can use the same rules as the admin UI.
- The UI is intentionally server-rendered and uses no JavaScript framework.

## Implementation steps

1. Copy `wp-queuepress` into `wp-content/plugins/`.
2. Activate **Bunny Queue Press** from the WordPress Plugins screen.
3. Open **Bunny Queue Press > Calendar**.
4. Use the global **Add Slot** panel to add publishing slots with the native time picker.
5. Choose whether the slot applies only to that day, weekdays, weekends, or every day.
6. Open **Bunny Queue Press > Settings** to choose display preferences, pause queue assignment, export configuration, or reset slots.
7. Review configured slots, future posts, published posts, and free slot indicators.

## Localization

- English strings remain the default fallback.
- Translation files are prepared in `languages/`.
- Spanish localization is started in `languages/wp-queuepress-es_ES.po`.
- All user-facing plugin strings use the `wp-queuepress` text domain.

## Future extensibility notes

- Add a queue item post type or custom table only when queue automation requires it.
- Reuse `Schedule_Calculator::get_free_slots()` for automatic assignment.
- Add nonce-protected form actions for manual queue operations.
- Keep drag-and-drop, AJAX, analytics, and notifications separate from the core scheduling services.
- Consider filters for post types, slot matching tolerance, and user capabilities after the MVP behavior is stable.
