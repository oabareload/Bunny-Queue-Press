<?php
/**
 * AJAX handlers for calendar slot management.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

use DateTimeImmutable;
use QueuePostScheduler\Schedule\Post_Query;
use QueuePostScheduler\Schedule\Schedule_Calculator;
use QueuePostScheduler\Schedule\Slot_Repository;
use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Saves slot changes from the calendar screen.
 */
final class Slot_Ajax {
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
	 * Builds the AJAX controller.
	 *
	 * @param Slot_Repository    $slot_repository Slot persistence service.
	 * @param Post_Query         $post_query Post retrieval service.
	 * @param Schedule_Calculator $schedule_calculator Slot availability service.
	 * @param Preferences         $preferences Plugin preferences.
	 */
	public function __construct(
		Slot_Repository $slot_repository,
		Post_Query $post_query,
		Schedule_Calculator $schedule_calculator,
		Preferences $preferences
	) {
		$this->slot_repository     = $slot_repository;
		$this->post_query          = $post_query;
		$this->schedule_calculator = $schedule_calculator;
		$this->preferences         = $preferences;
	}

	/**
	 * Registers AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('wp_ajax_wp_queuepress_add_slot', array($this, 'add_slot'));
		add_action('wp_ajax_wp_queuepress_delete_slot', array($this, 'delete_slot'));
	}

	/**
	 * Adds a slot and returns updated availability for affected days.
	 *
	 * @return void
	 */
	public function add_slot(): void {
		$this->verify_request();

		$day    = isset($_POST['day']) ? sanitize_key(wp_unslash($_POST['day'])) : '';
		$time   = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
		$scope  = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'day';
		$result = $this->slot_repository->add_slot($day, $time, $scope);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		wp_send_json_success(
			array(
				'message' => __('Slot added successfully.', 'wp-queuepress'),
				'days'    => $this->get_day_payloads($result),
			)
		);
	}

	/**
	 * Deletes a slot and returns updated availability for the affected day.
	 *
	 * @return void
	 */
	public function delete_slot(): void {
		$this->verify_request();

		$day    = isset($_POST['day']) ? sanitize_key(wp_unslash($_POST['day'])) : '';
		$time   = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
		$result = $this->slot_repository->delete_slot($day, $time);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		wp_send_json_success(
			array(
				'message' => __('Slot deleted successfully.', 'wp-queuepress'),
				'days'    => $this->get_day_payloads($result),
			)
		);
	}

	/**
	 * Verifies nonce and permissions for slot changes.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		check_ajax_referer('wp_queuepress_slots', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to manage slots.', 'wp-queuepress')), 403);
		}
	}

	/**
	 * Builds updated slot HTML payloads for affected days.
	 *
	 * @param array<int,string> $day_keys Affected day keys.
	 * @return array<string,array<string,string>>
	 */
	private function get_day_payloads(array $day_keys): array {
		$availability = $this->get_current_week_availability();
		$payload      = array();

		foreach ($day_keys as $day_key) {
			$day_date           = $this->get_date_for_day($day_key);
			$payload[$day_key] = array(
				'html' => Calendar_Page::render_slot_list_html($availability[$day_date] ?? array(), $day_key),
			);
		}

		return $payload;
	}

	/**
	 * Gets availability for the currently displayed week.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function get_current_week_availability(): array {
		$timezone   = wp_timezone();
		$week_start = $this->get_requested_week_start($timezone);
		$week_end   = $week_start->modify('+6 days')->setTime(23, 59, 59);
		$posts      = array_merge(
			$this->post_query->get_posts_between('future', $week_start, $week_end),
			$this->post_query->get_posts_between('publish', $week_start, $week_end)
		);

		return $this->schedule_calculator->get_week_availability($week_start, $week_end, $posts);
	}

	/**
	 * Gets the displayed date for a weekday key.
	 *
	 * @param string $day_key Weekday key.
	 * @return string
	 */
	private function get_date_for_day(string $day_key): string {
		$week_start = $this->get_requested_week_start(wp_timezone());
		$days       = $this->get_ordered_weekdays();
		$offset     = array_search($day_key, $days, true);

		if (false === $offset) {
			return $week_start->format('Y-m-d');
		}

		return $week_start->modify('+' . $offset . ' days')->format('Y-m-d');
	}

	/**
	 * Reads the displayed week from AJAX payload.
	 *
	 * @param \DateTimeZone $timezone Site timezone.
	 * @return DateTimeImmutable
	 */
	private function get_requested_week_start(\DateTimeZone $timezone): DateTimeImmutable {
		$requested_week = isset($_POST['week_start']) ? sanitize_text_field(wp_unslash($_POST['week_start'])) : '';

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_week)) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $requested_week, $timezone);

			if ($date instanceof DateTimeImmutable) {
				return $this->get_start_of_week($date);
			}
		}

		return $this->get_start_of_week(new DateTimeImmutable('now', $timezone));
	}

	/**
	 * Returns weekday keys in the configured calendar order.
	 *
	 * @return array<int,string>
	 */
	private function get_ordered_weekdays(): array {
		$days = array_keys($this->slot_repository->get_weekdays());

		if ($this->preferences->starts_on_sunday()) {
			array_unshift($days, (string) array_pop($days));
		}

		return $days;
	}

	/**
	 * Applies the configured week-start preference.
	 *
	 * @param DateTimeImmutable $date Date inside the displayed week.
	 * @return DateTimeImmutable
	 */
	private function get_start_of_week(DateTimeImmutable $date): DateTimeImmutable {
		if ($this->preferences->starts_on_sunday()) {
			// If already Sunday, use today; otherwise get last Sunday.
			$modifier = '0' === $date->format('w') ? 'today' : 'last sunday';
		} else {
			// If already Monday, use today; otherwise get last Monday.
			$modifier = '1' === $date->format('N') ? 'today' : 'last monday';
		}

		return $date->modify($modifier)->setTime(0, 0, 0);
	}
}
