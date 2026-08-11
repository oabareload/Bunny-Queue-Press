<?php
/**
 * Buffer Worker for the background queue.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use QueuePostScheduler\Admin\Buffer_Page;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles background processing of the Buffer Queue via WP-Cron.
 */
final class Buffer_Worker {

	/**
	 * Hook name for the WP-Cron event.
	 */
	public const CRON_HOOK = 'qps_buffer_queue_worker';

	/**
	 * Registers the worker hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(self::CRON_HOOK, array($this, 'process_queue'));
	}

	/**
	 * Processes exactly one job from the queue.
	 *
	 * @return void
	 */
	public function process_queue(): void {
		// Record the last run timestamp so the admin UI can display it.
		// Stored as MySQL datetime (UTC) string.
		update_option('qps_buffer_queue_last_run', current_time('mysql', true));

		$db = new Buffer_Queue_DB();
		
		// 1. Reset abandoned processing jobs
		$db->reset_processing_jobs();
		
		// 2. Fetch and lock ONE job
		$job = $db->get_and_lock_next_job();
		if (! $job) {
			return; // No eligible jobs
		}
		
		// 3. Process the job
		$this->process_single_job($job, $db);
	}
	
	/**
	 * Processes a single job directly (used by worker and manual retry).
	 *
	 * @param array           $job
	 * @param Buffer_Queue_DB $db
	 * @return void
	 */
	public function process_single_job(array $job, Buffer_Queue_DB $db): void {
		$settings = get_option(Buffer_Page::OPTION_SETTINGS, array());
		$token    = ! empty($settings['access_token']) ? (string) $settings['access_token'] : '';
		if ($token === '') {
			$db->update_job((int) $job['id'], 'failed', 'Buffer access token is not configured.');
			return;
		}
		
		$service = $job['network'];
		$channel_id = $job['channel_id'];
		$post_id = (int) $job['post_id'];
		
		$publisher_class = Platform_Registry::publisher_class($service);
		if ($publisher_class === null) {
			$db->update_job((int) $job['id'], 'failed', 'Unsupported channel service: ' . $service);
			return;
		}
		
		$client = new Buffer_Client($token);
		$config = new Channel_Config();
		
		/** @var object $publisher */
		$publisher = new $publisher_class($client, $config);
		$res = $publisher->publish_to_channel($post_id, $channel_id);
		
		if (! empty($res['success'])) {
			$db->update_job((int) $job['id'], 'sent');
			
			// Save to meta so it shows in the UI correctly.
			Publisher_Commons::save_channel_record($post_id, $res);
		} else {
			$error_msg = (string) ($res['message'] ?? 'Unknown error');
			
			// Check for exact retryable error
			if (strpos($error_msg, 'Image could not be read from its URL.') !== false) {
				// Retryable: FIFO strategy (delete and reinsert at the end of the queue)
				$attempts = (int) ($job['attempts'] ?? 0) + 1;
				
				$db->delete_job((int) $job['id']);
				$db->add_job($post_id, $service, $channel_id, $attempts, $error_msg);
			} else {
				// Failed
				$db->update_job((int) $job['id'], 'failed', $error_msg);
			}
		}
	}
}
