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
- Draft → Schedule workflow without manual publish date management
- Autosave-safe scheduling behavior during queue assignment
- Compact editorial pipeline cards with full-card click targets
- Single-user focused workflow for small editorial teams or solo creators
- Minimal native admin styling with no JavaScript framework dependency

## Workflow

1. Configure reusable weekly publishing slots in **Calendar Settings**.
2. Draft content in Gutenberg.
3. Use the **Add to Queue** toggle to assign the next available slot.
4. Gutenberg updates the post date so the Publish button becomes Schedule.
5. Autosave is temporarily paused while queue mode is active to keep date changes safe.
6. Review drafts, scheduled posts, and published content in the **Pipeline** page.

## Admin Pages

- **Pipeline** — editorial overview of Drafts, Scheduled, and Published posts
- **Calendar Settings** — recurring weekly slot configuration with weekdays only
- **Settings** — plugin preferences and queue assignment controls

## Changelog

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
5. Use the Gutenberg editor Add to Queue toggle to schedule drafts safely.

## File structure

```text
Bunny-Queue-Press/
├── Bunny-Queue-Press.code-workspace
├── LICENSE
├── README.md
├── wp-queuepress/
│   ├── assets/
│   │   ├── css/
│   │   │   └── admin.css
│   │   └── js/
│   │       ├── calendar.js
│   │       └── editor.js
│   ├── includes/
│   │   ├── Admin/
│   │   │   ├── Admin_Menu.php
│   │   │   ├── Calendar_Page.php
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
│   │   │   ├── Schedule_Calculator.php
│   │   │   └── Slot_Repository.php
│   │   └── Settings/
│   │       └── Preferences.php
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

- This release focuses on visible admin workflow and UI stabilization.
- Internal prefixes, namespaces, text domains, and database keys remain unchanged.
