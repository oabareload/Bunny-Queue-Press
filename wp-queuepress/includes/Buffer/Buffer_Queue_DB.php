<?php
/**
 * Buffer Queue Database layer.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles database operations for the Buffer queue.
 */
final class Buffer_Queue_DB {

	/**
	 * Returns the name of the queue table.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'qps_buffer_queue';
	}

	/**
	 * Creates the table structure.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			network varchar(50) NOT NULL,
			channel_id varchar(100) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			last_error text,
			share_mode varchar(20) NOT NULL DEFAULT 'addToQueue',
			PRIMARY KEY  (id),
			KEY post_network_status (post_id, network, status),
			KEY status_id (status, id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * Adds a new job to the queue.
	 *
	 * @param int    $post_id
	 * @param string $network
	 * @param string $channel_id
	 * @param int    $attempts
	 * @param string $last_error
	 * @param string $share_mode Either 'addToQueue' or 'shareNow'. Invalid values fall back to 'addToQueue'.
	 * @return int|false The inserted ID or false on failure.
	 */
	public function add_job(int $post_id, string $network, string $channel_id, int $attempts = 0, string $last_error = '', string $share_mode = 'addToQueue') {
		global $wpdb;
		if (! in_array($share_mode, array('addToQueue', 'shareNow'), true)) {
			$share_mode = 'addToQueue';
		}
		$now = current_time('mysql', true);
		$result = $wpdb->insert(
			self::get_table_name(),
			array(
				'post_id'    => $post_id,
				'network'    => $network,
				'channel_id' => $channel_id,
				'status'     => 'pending',
				'created_at' => $now,
				'attempts'   => $attempts,
				'last_error' => $last_error,
				'share_mode' => $share_mode,
			),
			array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
		);
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Gets a list of active jobs for a given post and network.
	 * Active jobs are only those that are pending or processing. A previous
	 * 'failed', 'cancelled', or 'sent' job must never block a new send attempt
	 * — duplicate protection depends exclusively on a job currently being
	 * pending or processing, never on send history.
	 *
	 * @param int    $post_id
	 * @param string $network
	 * @return array
	 */
	public function get_active_jobs(int $post_id, string $network): array {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND network = %s AND status IN ('pending', 'processing')",
				$post_id,
				$network
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Atomically selects and locks one eligible pending job for processing (FIFO).
	 *
	 * @return array|null The job row or null if no job is eligible.
	 */
	public function get_and_lock_next_job(): ?array {
		global $wpdb;
		$table = self::get_table_name();

		// First, find the next eligible job (FIFO)
		$job = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);

		if (! $job) {
			return null;
		}

		// Try to atomically update it to processing
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing' WHERE id = %d AND status = %s",
				$job['id'],
				$job['status']
			)
		);

		if ($result) {
			$job['status'] = 'processing';
			return $job;
		}

		return null;
	}

	/**
	 * Resets jobs stuck in processing to failed.
	 *
	 * @return void
	 */
	public function reset_processing_jobs(): void {
		global $wpdb;
		$table = self::get_table_name();
		$wpdb->query("UPDATE {$table} SET status = 'failed' WHERE status = 'processing'");
	}

	/**
	 * Updates the status and error of a job.
	 *
	 * @param int    $job_id
	 * @param string $status
	 * @param string $last_error
	 * @return bool
	 */
	public function update_job(int $job_id, string $status, string $last_error = ''): bool {
		global $wpdb;
		$table = self::get_table_name();

		$data = array(
			'status' => $status,
		);
		$format = array('%s');

		if ($status === 'sent' || $status === 'failed') {
			// Increment attempts
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $job_id));
		}

		if ($last_error !== '') {
			$data['last_error'] = $last_error;
			$format[] = '%s';
		}

		return (bool) $wpdb->update($table, $data, array('id' => $job_id), $format, array('%d'));
	}
	
	/**
	 * Updates the share_mode of a job, but only while it is still 'pending'.
	 *
	 * Only 'addToQueue' and 'shareNow' are accepted; any other value is
	 * rejected outright (returns false) rather than silently normalized,
	 * since this path is reached from user input via AJAX.
	 *
	 * @param int    $job_id
	 * @param string $share_mode
	 * @return bool True if the row was updated, false otherwise (invalid value or job no longer pending/not found).
	 */
	public function update_share_mode(int $job_id, string $share_mode): bool {
		if (! in_array($share_mode, array('addToQueue', 'shareNow'), true)) {
			return false;
		}

		global $wpdb;
		$table = self::get_table_name();

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET share_mode = %s WHERE id = %d AND status = 'pending'",
				$share_mode,
				$job_id
			)
		);

		return (bool) $result;
	}

	/**
	 * Deletes a job from the database.
	 *
	 * @param int $job_id
	 * @return bool
	 */
	public function delete_job(int $job_id): bool {
		global $wpdb;
		$table = self::get_table_name();
		return (bool) $wpdb->delete($table, array('id' => $job_id), array('%d'));
	}

	/**
	 * Fetches all jobs for Lab UI.
	 *
	 * @return array
	 */
	public function get_all_jobs(): array {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A) ?: array();
	}

	/**
	 * Fetches a single page of jobs for the Lab UI, most recent first.
	 *
	 * @param int $page     1-indexed page number.
	 * @param int $per_page Rows per page.
	 * @return array
	 */
	public function get_paginated_jobs(int $page, int $per_page): array {
		global $wpdb;
		$table = self::get_table_name();

		$page     = max(1, $page);
		$per_page = max(1, $per_page);
		$offset   = ($page - 1) * $per_page;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Counts the total number of jobs in the queue table (all statuses).
	 *
	 * @return int
	 */
	public function count_jobs(): int {
		global $wpdb;
		$table = self::get_table_name();
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	}
	
	/**
	 * Gets a single job by ID.
	 *
	 * @param int $job_id
	 * @return array|null
	 */
	public function get_job(int $job_id): ?array {
		global $wpdb;
		$table = self::get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $job_id), ARRAY_A);
	}

	/**
	 * Builds a post_id:network → status map for every job currently 'pending'
	 * or 'processing', restricted to the given post IDs.
	 *
	 * Intended for Pipeline rendering: called ONCE per batch of posts (not per
	 * post, not per platform) so a page with many cards issues a single query
	 * instead of one per post_id+network combination.
	 *
	 * @param array<int,int> $post_ids Post IDs to check. Empty input short-circuits
	 *                                  without querying the database.
	 * @return array<string,string> Map of "{post_id}:{network}" => 'pending'|'processing'.
	 */
	public function get_active_status_map(array $post_ids): array {
		$post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids), static function (int $id): bool {
			return $id > 0;
		})));

		if (empty($post_ids)) {
			return array();
		}

		global $wpdb;
		$table = self::get_table_name();
		$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, network, status FROM {$table} WHERE status IN ('pending', 'processing') AND post_id IN ({$placeholders})",
				...$post_ids
			),
			ARRAY_A
		) ?: array();

		$map = array();
		foreach ($rows as $row) {
			$key = ((int) $row['post_id']) . ':' . (string) $row['network'];
			$map[$key] = (string) $row['status'];
		}

		return $map;
	}
}
