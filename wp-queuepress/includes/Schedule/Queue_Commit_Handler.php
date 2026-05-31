<?php
/**
 * Commits editor queue intent when WordPress schedules a post.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Applies QueuePress queue modes at the native WordPress schedule transition.
 */
final class Queue_Commit_Handler {
	/**
	 * Queue mode meta key.
	 */
	private const META_KEY = '_wp_queuepress_queue_mode';

	/**
	 * Re-entrancy guard for wp_update_post calls made while applying plans.
	 *
	 * @var bool
	 */
	private bool $is_applying = false;

	/**
	 * Slot finder for single-post queue commits.
	 *
	 * @var Queue_Assigner
	 */
	private Queue_Assigner $queue_assigner;

	/**
	 * Queue planner and plan applier.
	 *
	 * @var Queue_Rebuilder
	 */
	private Queue_Rebuilder $queue_rebuilder;

	/**
	 * Plugin preferences.
	 *
	 * @var Preferences
	 */
	private Preferences $preferences;

	/**
	 * Builds the commit handler.
	 *
	 * @param Queue_Assigner  $queue_assigner Slot finder service.
	 * @param Queue_Rebuilder $queue_rebuilder Queue planner service.
	 * @param Preferences     $preferences Plugin preferences.
	 */
	public function __construct(Queue_Assigner $queue_assigner, Queue_Rebuilder $queue_rebuilder, Preferences $preferences) {
		$this->queue_assigner  = $queue_assigner;
		$this->queue_rebuilder = $queue_rebuilder;
		$this->preferences     = $preferences;
	}

	/**
	 * Registers the native schedule transition hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('transition_post_status', array($this, 'commit_queue_mode'), 10, 3);
	}

	/**
	 * Applies the queued mode only when a post becomes scheduled.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function commit_queue_mode(string $new_status, string $old_status, \WP_Post $post): void {
		if ($this->is_applying) {
			return;
		}

		// Handle future -> draft transition: perform a controlled cleanup to prevent obsolete state persistence.
		if ('future' === $old_status) {
			if ('draft' === $new_status) {
				delete_post_meta((int) $post->ID, self::META_KEY);
				clean_post_cache((int) $post->ID);
			}
			return;
		}

		if ('future' !== $new_status) {
			return;
		}

		if (! $this->is_supported_post($post) || $this->preferences->is_queue_paused()) {
			return;
		}

		$post_id = (int) $post->ID;
		$mode    = (string) get_post_meta($post_id, self::META_KEY, true);

		if (! in_array($mode, array('add_to_queue', 'add_first'), true)) {
			return;
		}

		// Clear intent before applying date changes so nested transitions cannot re-run it.
		delete_post_meta($post_id, self::META_KEY);

		$this->is_applying = true;

		try {
			if ('add_first' === $mode) {
				$plan = $this->queue_rebuilder->compute_add_first_plan($post_id);
				error_log('[QueuePress] add_first plan for post ' . $post_id . ': ' . wp_json_encode($plan));
				$result = $this->queue_rebuilder->apply_plan($plan);
				error_log('[QueuePress] apply_plan result: ' . wp_json_encode($result));
			} else {
				$this->commit_add_to_queue($post_id);
			}
		} finally {
			$this->is_applying = false;
		}
	}

	/**
	 * Assigns only the current post to the next free slot.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function commit_add_to_queue(int $post_id): void {
		$slot = $this->queue_assigner->find_next_slot($post_id);

		if (null === $slot) {
			return;
		}

		clean_post_cache($post_id);
		$post = get_post($post_id);
		if (! $post instanceof \WP_Post) {
			return;
		}

		$this->queue_rebuilder->apply_plan(
			array(
				array(
					'post_id'  => $post_id,
					'old_date' => (string) $post->post_date,
					'new_date' => $slot->format(DATE_ATOM),
				),
			)
		);
	}

	/**
	 * Checks whether QueuePress should manage this post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_supported_post(\WP_Post $post): bool {
		$post_id   = (int) $post->ID;
		$post_type = get_post_type_object($post->post_type);

		return 'post' === $post->post_type
			&& ! wp_is_post_autosave($post_id)
			&& ! wp_is_post_revision($post_id)
			&& current_user_can('edit_post', $post_id)
			&& $post_type
			&& ! empty($post_type->cap->publish_posts)
			&& current_user_can($post_type->cap->publish_posts);
	}
}
