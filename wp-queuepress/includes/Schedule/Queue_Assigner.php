<?php
/**
 * Finds the next available publishing slot.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use DateTimeImmutable;
use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Locates the chronologically earliest free WordPress publishing slot.
 *
 * 1.1.0 design: this class is a pure slot-finder. It does NOT call
 * wp_update_post, does NOT set post_status, and does NOT lock anything.
 * The REST controller returns the found slot to the Gutenberg editor,
 * which applies it via editPost({ date }) — fully native WP scheduling.
 */
final class Queue_Assigner {
	/**
	 * Number of weeks to scan for the next available slot.
	 */
	private const SEARCH_WEEKS = 52;

	/**
	 * Post retrieval service.
	 *
	 * @var Post_Query
	 */
	private Post_Query $post_query;

	/**
	 * Slot availability service.
	 *
	 * @var Schedule_Calculator
	 */
	private Schedule_Calculator $schedule_calculator;

	/**
	 * Plugin preferences.
	 *
	 * @var Preferences
	 */
	private Preferences $preferences;

	/**
	 * Builds the queue assigner.
	 *
	 * @param Post_Query          $post_query Post retrieval service.
	 * @param Schedule_Calculator $schedule_calculator Slot availability service.
	 * @param Preferences         $preferences Plugin preferences.
	 */
	public function __construct(Post_Query $post_query, Schedule_Calculator $schedule_calculator, Preferences $preferences) {
		$this->post_query          = $post_query;
		$this->schedule_calculator = $schedule_calculator;
		$this->preferences         = $preferences;
	}

	/**
	 * Returns the next chronologically free slot strictly after the current time.
	 *
	 * Scans up to SEARCH_WEEKS weeks of configured slots, skips any that are
	 * already occupied by a future-scheduled or published post, and returns the
	 * first free slot. The provided post ID is excluded from the occupancy check
	 * so re-queueing does not block the post's own current date.
	 *
	 * @param int $post_id Post ID being queued.
	 * @return DateTimeImmutable|null Null when no free slot exists within the window.
	 */
	public function find_next_slot(int $post_id): ?DateTimeImmutable {
		$timezone   = wp_timezone();
		$now        = new DateTimeImmutable('now', $timezone);
		$week_start = $this->get_start_of_week($now);

		for ($week = 0; $week < self::SEARCH_WEEKS; $week++) {
			$start = $week_start->modify('+' . $week . ' weeks');
			$end   = $start->modify('+6 days')->setTime(23, 59, 59);
			$posts = $this->get_occupying_posts($start, $end, $post_id);
			$slots = $this->schedule_calculator->get_free_slots($start, $end, $posts);

			foreach ($slots as $slot) {
				$slot_time = new DateTimeImmutable($slot['date'] . ' ' . $slot['time'], $timezone);

				if ($slot_time > $now) {
					return $slot_time;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the start of the week containing the given date.
	 *
	 * Respects the week-start preference (Monday or Sunday).
	 *
	 * @param DateTimeImmutable $date Any date in the target week.
	 * @return DateTimeImmutable Midnight on the first day of that week.
	 */
	private function get_start_of_week(DateTimeImmutable $date): DateTimeImmutable {
		if ($this->preferences->starts_on_sunday()) {
			$modifier = '0' === $date->format('w') ? 'today' : 'last sunday';
		} else {
			$modifier = '1' === $date->format('N') ? 'today' : 'last monday';
		}

		return $date->modify($modifier)->setTime(0, 0, 0);
	}

	/**
	 * Returns posts that occupy slots in the given range, excluding the post
	 * being queued so it cannot collide with its own current date.
	 *
	 * Only future-scheduled and published posts count as occupying. Drafts
	 * that carry a queue date are not yet committed and do not block slots.
	 *
	 * @param DateTimeImmutable $start Range start (site timezone).
	 * @param DateTimeImmutable $end   Range end (site timezone).
	 * @param int               $post_id Post being queued.
	 * @return array<int,\WP_Post>
	 */
	private function get_occupying_posts(DateTimeImmutable $start, DateTimeImmutable $end, int $post_id): array {
		$posts = array_merge(
			$this->post_query->get_posts_between('future', $start, $end),
			$this->post_query->get_posts_between('publish', $start, $end)
		);

		return array_values(
			array_filter(
				$posts,
				static function (\WP_Post $post) use ($post_id): bool {
					return (int) $post->ID !== $post_id;
				}
			)
		);
	}
}
