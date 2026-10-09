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
	private Queue_Assigner $queue_assigner;

	public function __construct(Post_Query $post_query, Queue_Rebuilder $queue_rebuilder, Queue_Assigner $queue_assigner) {
		$this->post_query      = $post_query;
		$this->queue_rebuilder = $queue_rebuilder;
		$this->queue_assigner  = $queue_assigner;
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

	/** Promotes expired Fills and uses one unexpired Fill only to cover a real Media gap. */
	public function run(): void {
		$this->schedule_next_run();

		$timezone = wp_timezone();
		$today    = new DateTimeImmutable('today', $timezone);
		$start    = new DateTimeImmutable('1970-01-01 00:00:00', $timezone);
		$end      = $today->modify('+' . (52 * 7 - 1) . ' days')->setTime(23, 59, 59);
		$fills    = $this->post_query->get_posts_between('future', $start, $end, 'fill', -1);
		$unexpired = array();
		usort(
			$fills,
			static function (\WP_Post $a, \WP_Post $b): int {
				$date_order = strcmp($a->post_date, $b->post_date);
				return 0 !== $date_order ? $date_order : ((int) $a->ID <=> (int) $b->ID);
			}
		);

		foreach ($fills as $post) {
			$fill_date = new DateTimeImmutable($post->post_date, $timezone);
			if ($fill_date->format('Y-m-d') <= $today->format('Y-m-d')) {
				$this->promote_fill((int) $post->ID, null);
			} else {
				$unexpired[] = $post;
			}
		}

		if (empty($unexpired)) {
			return;
		}

		$gap = $this->queue_rebuilder->find_first_media_gap();
		if (null === $gap) {
			return;
		}

		$this->promote_fill((int) $unexpired[0]->ID, $gap);
	}

	/** Assigns a Fill to a valid Media slot, then removes its Fill classification. */
	private function promote_fill(int $post_id, ?DateTimeImmutable $target): bool {
		clean_post_cache($post_id);
		$post = get_post($post_id);
		if (! $post instanceof \WP_Post || 'future' !== $post->post_status || 'fill' !== get_post_meta($post_id, '_wp_queuepress_relevance', true)) {
			return false;
		}

		$target = $target ?: $this->queue_assigner->find_next_slot($post_id, 'media');
		if (! $target instanceof DateTimeImmutable) {
			return false;
		}

		$result = $this->queue_rebuilder->apply_plan(
			array(
				array(
					'post_id'  => $post_id,
					'old_date' => (string) $post->post_date,
					'new_date' => $target->format(DATE_ATOM),
				),
			)
		);

		if (empty($result['applied'])) {
			return false;
		}

		update_post_meta($post_id, '_wp_queuepress_relevance', 'media');
		return true;
	}
}
