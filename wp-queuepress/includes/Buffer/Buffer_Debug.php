<?php
/**
 * Buffer debug logger.
 *
 * Provides a centralized, WP-based log store for Buffer requests and responses.
 * Logs are persisted in the option `_queuepress_buffer_debug_log` and trimmed
 * to the most recent 100 entries. Logging is no-op unless the Buffer settings
 * include `debug_buffer` = true.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

if (! defined('ABSPATH')) {
    exit;
}

final class Buffer_Debug {

    private const OPTION_KEY = '_queuepress_buffer_debug_log';
    private const SETTINGS_OPTION = 'wp_queuepress_buffer_settings';
    private const MAX_ENTRIES = 100;

    /**
     * Returns whether debug logging is enabled in Buffer settings.
     */
    public static function enabled(): bool {
        $settings = get_option(self::SETTINGS_OPTION, array());
        if (! is_array($settings)) {
            return false;
        }
        return ! empty($settings['debug_buffer']);
    }

    /**
     * Adds an entry to the debug log. No-op when debug is disabled.
     *
     * Entry shape (recommended):
     *  - timestamp: string (Y-m-d H:i:s)
     *  - endpoint: string
     *  - mutation|query: string
     *  - request_body: string
     *  - request_headers: array
     *  - http_status: int|null
     *  - response_headers: array
     *  - response_body: string
     *  - wp_error: array|null
     *
     * @param array<string,mixed> $entry
     * @return void
     */
    public static function add_entry(array $entry): void {
        if (! self::enabled()) {
            return;
        }

        $key = self::OPTION_KEY;
        $logs = get_option($key, array());
        if (! is_array($logs)) {
            $logs = array();
        }

        // Normalize timestamp.
        if (empty($entry['timestamp'])) {
            $entry['timestamp'] = gmdate('Y-m-d H:i:s');
        }

        array_unshift($logs, $entry);

        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_ENTRIES);
        }

        update_option($key, $logs);
    }

    /**
     * Returns the stored debug log entries (most recent first).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_entries(): array {
        $key = self::OPTION_KEY;
        $logs = get_option($key, array());
        return is_array($logs) ? $logs : array();
    }

    /**
     * Clears the debug log.
     *
     * @return void
     */
    public static function clear(): void {
        update_option(self::OPTION_KEY, array());
    }
}
