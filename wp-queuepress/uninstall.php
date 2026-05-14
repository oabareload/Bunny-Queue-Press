<?php
/**
 * Uninstall cleanup for WP QueuePress.
 *
 * @package QueuePostScheduler
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove plugin settings when WordPress runs the uninstall routine.
delete_option('qps_weekly_slots');
delete_option('wp_queuepress_settings');
delete_option('wp_queuepress_queue_lock');
