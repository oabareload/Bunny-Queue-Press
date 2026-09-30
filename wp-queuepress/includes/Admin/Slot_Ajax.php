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
use QueuePostScheduler\Schedule\Queue_Rebuilder;
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
		add_action('wp_ajax_wp_queuepress_save_weekly_slots', array($this, 'save_weekly_slots'));
		add_action('wp_ajax_wp_queuepress_preview_rebuild', array($this, 'preview_rebuild'));
		add_action('wp_ajax_wp_queuepress_apply_rebuild', array($this, 'apply_rebuild'));
	}

	/**
	 * Computes a deterministic rebuild plan (preview) and persists it.
	 *
	 * @return void
	 */
	public function preview_rebuild(): void {
		$this->verify_request();

		// Compose a rebuilder and compute plan.
		$rebuilder = new \QueuePostScheduler\Schedule\Queue_Rebuilder(
			$this->slot_repository,
			$this->post_query,
			$this->schedule_calculator,
			$this->preferences
		);

		try {
			$calendar = isset($_POST['calendar']) && 'media' === sanitize_key(wp_unslash($_POST['calendar'])) ? 'media' : 'immediate';
			$plan = $rebuilder->compute_and_persist_plan($calendar);
			// Enrich preview items with title and localized display strings.
			$preview_raw = array_slice($plan['plan'], 0, 50);
			$preview = array();
			$timezone = wp_timezone();
			$date_fmt = $this->preferences->get_date_format();
			$time_fmt = $this->preferences->get_time_format();

			foreach ($preview_raw as $item) {
				$post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
				$post = get_post($post_id);
				$title = $post instanceof \WP_Post ? get_the_title($post) : sprintf(__('Post #%d', 'wp-queuepress'), $post_id);

				// Old date is stored as local WP date string; parse in site timezone.
				try {
					$old_dt = new \DateTimeImmutable($item['old_date'], $timezone);
					$old_display = wp_date($date_fmt, $old_dt->getTimestamp()) . ' — ' . wp_date($time_fmt, $old_dt->getTimestamp());
				} catch (\Throwable $ex) {
					$old_display = $item['old_date'];
				}

				// New date is stored as ISO string; ensure it's represented in site timezone.
				try {
					$new_dt = new \DateTimeImmutable($item['new_date']);
					$new_dt = $new_dt->setTimezone($timezone);
					$new_display = wp_date($date_fmt, $new_dt->getTimestamp()) . ' — ' . wp_date($time_fmt, $new_dt->getTimestamp());
				} catch (\Throwable $ex) {
					$new_display = $item['new_date'];
				}

				$preview[] = array(
					'post_id' => $post_id,
					'post_title' => $title,
					'old_date' => $old_display,
					'new_date' => $new_display,
				);
			}

			wp_send_json_success(array(
				'total' => $plan['total'],
				'preview' => $preview,
				'conflicts' => $plan['conflicts'],
			));
		} catch (\Throwable $e) {
			wp_send_json_error(array('message' => $e->getMessage()), 500);
		}
	}

	/**
	 * Applies the previously computed plan for the selected calendar.
	 *
	 * Iterates the plan sequentially and updates scheduled posts to the precomputed new dates.
	 * Continues on errors and returns a list of conflicts.
	 *
	 * @return void
	 */
	public function apply_rebuild(): void {
		$this->verify_request();

		$calendar = isset($_POST['calendar']) && 'media' === sanitize_key(wp_unslash($_POST['calendar'])) ? 'media' : 'immediate';
		$pending = get_option('qps_pending_rebuild_' . $calendar, null);
		if (empty($pending) || ! is_array($pending) || empty($pending['plan']) || ! is_array($pending['plan'])) {
			wp_send_json_error(array('message' => __('No pending rebuild plan found.', 'wp-queuepress')), 400);
		}

		$rebuilder = new Queue_Rebuilder(
			$this->slot_repository,
			$this->post_query,
			$this->schedule_calculator,
			$this->preferences
		);
		$results   = $rebuilder->apply_plan($pending['plan']);

		// Remove pending plan after attempting the application.
		delete_option('qps_pending_rebuild_' . $calendar);

		wp_send_json_success($results);
	}

	/**
	 * Saves the full weekly slots structure in one request.
	 *
	 * @return void
	 */
	public function save_weekly_slots(): void {
		$this->verify_request();

		$calendar = isset($_POST['calendar']) && 'media' === sanitize_key(wp_unslash($_POST['calendar'])) ? 'media' : 'immediate';
		$raw = isset($_POST['slots']) ? wp_unslash($_POST['slots']) : '';
		if (! $raw) {
			wp_send_json_error(array('message' => __('Missing slots payload.', 'wp-queuepress')), 400);
		}

		$data = json_decode($raw, true);
		if (! is_array($data)) {
			wp_send_json_error(array('message' => __('Invalid slots payload.', 'wp-queuepress')), 400);
		}

		// Accept the combined calendar payload; legacy single-calendar payloads remain supported.
		$weekdays = array_keys($this->slot_repository->get_weekdays());
		$combined_payload = isset($data['immediate'], $data['media']);
		$sanitized = array();
		foreach ($combined_payload ? array('immediate', 'media') : array($calendar) as $target_calendar) {
			$calendar_data = $combined_payload ? $data[$target_calendar] : $data;
			if (! is_array($calendar_data)) {
				wp_send_json_error(array('message' => __('Invalid slots payload.', 'wp-queuepress')), 400);
			}
			$sanitized[$target_calendar] = array();
			foreach ($weekdays as $weekday) {
				$sanitized[$target_calendar][$weekday] = array();
				$times = isset($calendar_data[$weekday]) && is_array($calendar_data[$weekday]) ? $calendar_data[$weekday] : array();
				foreach ($times as $time) {
					$time = sanitize_text_field((string) $time);
					if (! $this->slot_repository->is_valid_time_format($time)) {
						wp_send_json_error(array('message' => __('Invalid time format.', 'wp-queuepress')), 400);
					}
					if (! $this->slot_repository->is_valid_time($time)) {
						wp_send_json_error(array('message' => __('Publishing slots must be at or after 05:00 (WordPress site timezone).', 'wp-queuepress')), 400);
					}
					$sanitized[$target_calendar][$weekday][] = $time;
				}
				$sanitized[$target_calendar][$weekday] = array_values(array_unique($sanitized[$target_calendar][$weekday]));
				sort($sanitized[$target_calendar][$weekday], SORT_STRING);
			}
		}

		foreach ($sanitized as $target_calendar => $slots) {
			update_option('media' === $target_calendar ? Slot_Repository::MEDIA_OPTION_NAME : Slot_Repository::OPTION_NAME, $slots);
		}

		// Refresh only configured slot markup; post occupancy is not part of this screen.
		$days = $this->get_day_payloads($weekdays);

		wp_send_json_success(
			array(
				'message' => __('Slots saved.', 'wp-queuepress'),
				'days'    => $days,
			)
		);
	}

	/**
	 * Adds a slot and returns configured-slot markup for affected days.
	 *
	 * @return void
	 */
	public function add_slot(): void {
		$this->verify_request();

		$calendar = isset($_POST['calendar']) && 'media' === sanitize_key(wp_unslash($_POST['calendar'])) ? 'media' : 'immediate';
		$day    = isset($_POST['day']) ? sanitize_key(wp_unslash($_POST['day'])) : '';
		$time   = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
		$scope  = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'day';
		$result = $this->slot_repository->add_slot($day, $time, $scope, $calendar);

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
	 * Deletes a slot and returns configured-slot markup for the affected day.
	 *
	 * @return void
	 */
	public function delete_slot(): void {
		$this->verify_request();

		$calendar = isset($_POST['calendar']) && 'media' === sanitize_key(wp_unslash($_POST['calendar'])) ? 'media' : 'immediate';
		$day    = isset($_POST['day']) ? sanitize_key(wp_unslash($_POST['day'])) : '';
		$time   = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
		$result = $this->slot_repository->delete_slot($day, $time, $calendar);

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
		$calendar_slots = array(
			'immediate' => $this->slot_repository->get_weekly_slots('immediate'),
			'media'     => $this->slot_repository->get_weekly_slots('media'),
		);
		$payload = array();

		foreach ($day_keys as $day_key) {
			$slots = array();
			foreach ($calendar_slots as $calendar => $days) {
				foreach ($days[$day_key] ?? array() as $time) {
					$slots[] = array('time' => $time, 'calendar' => $calendar);
				}
			}
			usort($slots, static function (array $left, array $right): int {
				return ($left['time'] <=> $right['time']) ?: ($left['calendar'] <=> $right['calendar']);
			});
			$payload[$day_key] = array('html' => Calendar_Page::render_slot_list_html($slots, $day_key));
		}

		return $payload;
	}

}
