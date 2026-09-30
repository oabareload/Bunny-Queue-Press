<?php
/**
 * Daily maintenance for the media calendar and expired Fill posts.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use DateTimeImmutable;

if (! defined('ABSPATH')) {
	exit;
}

/** Runs a small once-daily check without publishing posts directly. */
final class Media_Fill_Maintenance {
	private const HOOK = 'wp_queuepress_daily_media_fill_maintenance';
	// Keep one hour between maintenance and the earliest permitted slot (05:00).
	private const HOUR = 4;

	private Post_Query $post_query;
	private Queue_Rebuilder $queue_rebuilder;

	public function __construct(Post_Query $post_query, Queue_Rebuilder $queue_rebuilder) {
		$this->post_query      = $post_query;
		$this->queue_rebuilder = $queue_rebuilder;
	}

	/** Registers the daily event and its callback. */
	public function register(): void {
		add_action('init', array($this, 'schedule_next_run'));
		add_action(self::HOOK, array($this, 'run'));
	}

	/** Schedules the next 04:00 in the WordPress site timezone. */
	public function schedule_next_run(): void {
		if (wp_next_scheduled(self::HOOK)) {
			return;
		}

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable('now', $timezone);
		$next     = $now->setTime(self::HOUR, 0, 0);
		if ($next <= $now) {
			$next = $next->modify('+1 day');
		}

		wp_schedule_single_event($next->getTimestamp(), self::HOOK);
	}

	/** Promotes Fills dated today or earlier, then rebuilds only the media calendar. */
	public function run(): void {
		$this->schedule_next_run();

		$timezone = wp_timezone();
		$today    = new DateTimeImmutable('today', $timezone);
		$end      = $today->setTime(23, 59, 59);
		$fills    = $this->post_query->get_posts_between('future', new DateTimeImmutable('1970-01-01 00:00:00', $timezone), $end, 'fill', -1);

		if (empty($fills)) {
			return;
		}

		foreach ($fills as $post) {
			update_post_meta((int) $post->ID, '_wp_queuepress_relevance', 'media');
		}

		$plan = $this->queue_rebuilder->compute_and_persist_plan('media');
		$this->queue_rebuilder->apply_plan($plan['plan'] ?? array());
		delete_option('qps_pending_rebuild_media');
	}
}
