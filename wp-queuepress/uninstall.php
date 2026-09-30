<?php
/**
 * Uninstall cleanup for Bunny Queue Press.
 *
 * @package QueuePostScheduler
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove plugin settings when WordPress runs the uninstall routine.
delete_option('qps_weekly_slots');
delete_option('wp_queuepress_media_slots');
delete_option('wp_queuepress_settings');
delete_option('wp_queuepress_queue_lock');
wp_clear_scheduled_hook('wp_queuepress_daily_media_fill_maintenance');
delete_option('qps_pending_rebuild_immediate');
delete_option('qps_pending_rebuild_media');
delete_option('qps_pending_rebuild');
