<?php
/**
 * Slot availability calculations.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use DateTimeImmutable;
use DateTimeZone;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Compares configured weekly slots against posts already occupying those times.
 */
final class Schedule_Calculator {
	/**
	 * Slot persistence service.
	 *
	 * @var Slot_Repository
	 */
	private Slot_Repository $slot_repository;

	/**
	 * Post retrieval service.
	 *
	 * @var Post_Query
	 */
	private Post_Query $post_query;

	/**
	 * Builds the calculator.
	 *
	 * @param Slot_Repository $slot_repository Slot persistence service.
	 * @param Post_Query      $post_query Post retrieval service.
	 */
	public function __construct(Slot_Repository $slot_repository, Post_Query $post_query) {
		$this->slot_repository = $slot_repository;
		$this->post_query      = $post_query;
	}

	/**
	 * Calculates occupied and free slots for the selected week.
	 *
	 * @param DateTimeImmutable $week_start Start of week in the site timezone.
	 * @param DateTimeImmutable $week_end End of week in the site timezone.
	 * @param array<int,\WP_Post> $posts Optional post list when the caller already queried it.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function get_week_availability(DateTimeImmutable $week_start, DateTimeImmutable $week_end, array $posts = array()): array {
		$timezone     = wp_timezone();
		$weekly_slots = $this->slot_repository->get_weekly_slots();

		if (empty($posts)) {
			$posts = array_merge(
				$this->post_query->get_posts_between('future', $week_start, $week_end),
				$this->post_query->get_posts_between('publish', $week_start, $week_end)
			);
		}

		$occupied_times = $this->build_occupied_time_index($posts, $timezone);
		$availability   = array();

		for ($i = 0; $i < 7; $i++) {
			$day      = $week_start->modify('+' . $i . ' days');
			$day_key  = strtolower($day->format('l'));
			$day_date = $day->format('Y-m-d');

			$availability[$day_date] = array_map(
				static function (string $time) use ($day_date, $occupied_times): array {
					$slot_key = $day_date . ' ' . $time;

					return array(
						'date'     => $day_date,
						'time'     => $time,
						'occupied' => isset($occupied_times[$slot_key]),
						'post_id'  => $occupied_times[$slot_key] ?? null,
					);
				},
				$weekly_slots[$day_key] ?? array()
			);
		}

		return $availability;
	}

	/**
	 * Returns only free slots for the selected week.
	 *
	 * This method is unused by the current UI but provides a simple internal API
	 * for future queue assignment logic.
	 *
	 * @param DateTimeImmutable $week_start Start of week in the site timezone.
	 * @param DateTimeImmutable $week_end End of week in the site timezone.
	 * @param array<int,\WP_Post> $posts Optional post list when the caller already queried it.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_free_slots(DateTimeImmutable $week_start, DateTimeImmutable $week_end, array $posts = array()): array {
		$availability = $this->get_week_availability($week_start, $week_end, $posts);
		$free_slots   = array();

		foreach ($availability as $day_slots) {
			foreach ($day_slots as $slot) {
				if (empty($slot['occupied'])) {
					$free_slots[] = $slot;
				}
			}
		}

		return $free_slots;
	}

	/**
	 * Builds a lookup table keyed by local date and time.
	 *
	 * @param array<int,\WP_Post> $posts Posts that may occupy configured slots.
	 * @param DateTimeZone       $timezone Site timezone.
	 * @return array<string,int>
	 */
	private function build_occupied_time_index(array $posts, DateTimeZone $timezone): array {
		$occupied = array();

		foreach ($posts as $post) {
			// WordPress stores post_date in local site time, which matches slot configuration.
			$post_time = new DateTimeImmutable($post->post_date, $timezone);
			$key       = $post_time->format('Y-m-d H:i');

			$occupied[$key] = (int) $post->ID;
		}

		return $occupied;
	}
}
