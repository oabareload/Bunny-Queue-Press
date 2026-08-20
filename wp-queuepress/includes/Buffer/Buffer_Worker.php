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
	 * WordPress option key storing the configurable auto-retry error rules.
	 *
	 * Each rule is an array: array('text' => string, 'match' => 'contains'|'exact').
	 * When empty, no error is auto-retried and all failures go straight to 'failed'.
	 */
	public const OPTION_RETRY_RULES = 'qps_buffer_retry_rules';

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

		$share_mode = (string) ($job['share_mode'] ?? 'addToQueue');
		if (! in_array($share_mode, array('addToQueue', 'shareNow'), true)) {
			$share_mode = 'addToQueue';
		}

		/** @var object $publisher */
		$publisher = new $publisher_class($client, $config);
		$res = $publisher->publish_to_channel($post_id, $channel_id, $share_mode);
		
		if (! empty($res['success'])) {
			$db->update_job((int) $job['id'], 'sent');
			
			// Save to meta so it shows in the UI correctly.
			Publisher_Commons::save_channel_record($post_id, $res);
		} else {
			$error_msg = (string) ($res['message'] ?? 'Unknown error');

			if ($this->is_auto_retryable($error_msg)) {
				// Retryable: FIFO strategy (delete and reinsert at the end of the queue)
				$attempts = (int) ($job['attempts'] ?? 0) + 1;
				
				$db->delete_job((int) $job['id']);
				$db->add_job($post_id, $service, $channel_id, $attempts, $error_msg, $share_mode);
			} else {
				// Failed
				$db->update_job((int) $job['id'], 'failed', $error_msg);
			}
		}
	}

	/**
	 * Determines whether a Buffer error message matches any configured
	 * auto-retry rule.
	 *
	 * If no rules are configured, this always returns false: with an empty
	 * rule set there is no automatic retry and every error ends as 'failed'.
	 * This does not affect the manual "Retry now" action in Lab, which calls
	 * process_single_job() directly regardless of these rules.
	 *
	 * @param string $error_msg
	 * @return bool
	 */
	private function is_auto_retryable(string $error_msg): bool {
		$rules = get_option(self::OPTION_RETRY_RULES, array());
		if (! is_array($rules) || empty($rules)) {
			return false;
		}

		foreach ($rules as $rule) {
			if (! is_array($rule)) {
				continue;
			}

			$text = (string) ($rule['text'] ?? '');
			$match = (string) ($rule['match'] ?? '');

			if ($text === '') {
				continue;
			}

			if ($match === 'exact' && $error_msg === $text) {
				return true;
			}

			if ($match === 'contains' && strpos($error_msg, $text) !== false) {
				return true;
			}
		}

		return false;
	}
}
