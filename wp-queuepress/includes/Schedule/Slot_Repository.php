<?php
/**
 * Weekly slot storage and sanitization.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles storage and retrieval of recurring weekly publishing slots.
 */
final class Slot_Repository {
	/**
	 * WordPress option name for weekly slot configuration.
	 */
	public const OPTION_NAME = 'qps_weekly_slots';
	public const MEDIA_OPTION_NAME = 'wp_queuepress_media_slots';
	public const MINIMUM_SLOT_TIME = '05:00';

	/**
	 * Returns supported weekdays in storage order.
	 *
	 * @return array<string,string>
	 */
	public function get_weekdays(): array {
		return array(
			'monday'    => __('Monday', 'wp-queuepress'),
			'tuesday'   => __('Tuesday', 'wp-queuepress'),
			'wednesday' => __('Wednesday', 'wp-queuepress'),
			'thursday'  => __('Thursday', 'wp-queuepress'),
			'friday'    => __('Friday', 'wp-queuepress'),
			'saturday'  => __('Saturday', 'wp-queuepress'),
			'sunday'    => __('Sunday', 'wp-queuepress'),
		);
	}

	/**
	 * Retrieves normalized weekly slots from the WordPress options table.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function get_weekly_slots(string $calendar = 'immediate'): array {
		$option_name  = 'media' === $calendar ? self::MEDIA_OPTION_NAME : self::OPTION_NAME;
		$stored_slots = get_option($option_name, array());

		return $this->sanitize_slots(is_array($stored_slots) ? $stored_slots : array());
	}

	/**
	 * Sanitizes submitted slot configuration.
	 *
	 * Users enter comma-separated HH:MM values. This method normalizes those
	 * strings into sorted arrays and rejects invalid times.
	 *
	 * @param array|string $raw_slots Raw option value from the settings form.
	 * @return array<string,array<int,string>>
	 */
	public function sanitize_slots($raw_slots): array {
		$sanitized = array();
		$weekdays  = array_keys($this->get_weekdays());

		foreach ($weekdays as $weekday) {
			$day_value = '';

			if (is_array($raw_slots) && isset($raw_slots[$weekday])) {
				// Stored options are arrays, while form submissions are comma-separated strings.
				$day_value = is_array($raw_slots[$weekday])
					? implode(',', array_map('strval', $raw_slots[$weekday]))
					: sanitize_text_field(wp_unslash((string) $raw_slots[$weekday]));
			}

			$sanitized[$weekday] = $this->sanitize_day_slots($day_value);
		}

		return $sanitized;
	}

	/**
	 * Adds a slot to one or more weekdays.
	 *
	 * @param string $day_key Weekday key used as the source day.
	 * @param string $time Slot time in HH:MM format.
	 * @param string $scope Target scope.
	 * @return array<int,string>|\WP_Error
	 */
	public function add_slot(string $day_key, string $time, string $scope, string $calendar = 'immediate') {
		$day_key = sanitize_key($day_key);
		$scope   = sanitize_key($scope);
		$time    = sanitize_text_field(wp_unslash($time));
		$days    = $this->get_scope_days($day_key, $scope);

		if (empty($days)) {
			return new \WP_Error('wp_queuepress_invalid_day', __('Invalid day.', 'wp-queuepress'));
		}

		if (! $this->is_valid_time($time)) {
			return new \WP_Error('wp_queuepress_invalid_time', __('Invalid time. Slots must be at or after 05:00.', 'wp-queuepress'));
		}

		$calendar = 'media' === $calendar ? 'media' : 'immediate';
		$slots    = $this->get_weekly_slots($calendar);
		$weekdays = $this->get_weekdays();

		foreach ($days as $day) {
			if (in_array($time, $slots[$day] ?? array(), true)) {
				return new \WP_Error(
					'wp_queuepress_duplicate_slot',
					sprintf(
						/* translators: 1: Weekday name, 2: slot time. */
						__('%1$s %2$s already exists.', 'wp-queuepress'),
						$weekdays[$day] ?? $day,
						$time
					)
				);
			}
		}

		foreach ($days as $day) {
			$slots[$day][] = $time;
			sort($slots[$day], SORT_STRING);
		}

		update_option('media' === $calendar ? self::MEDIA_OPTION_NAME : self::OPTION_NAME, $slots);

		return $days;
	}

	/**
	 * Removes a slot from one weekday.
	 *
	 * @param string $day_key Weekday key.
	 * @param string $time Slot time in HH:MM format.
	 * @return array<int,string>|\WP_Error
	 */
	public function delete_slot(string $day_key, string $time, string $calendar = 'immediate') {
		$day_key = sanitize_key($day_key);
		$time    = sanitize_text_field(wp_unslash($time));

		if (! array_key_exists($day_key, $this->get_weekdays())) {
			return new \WP_Error('wp_queuepress_invalid_day', __('Invalid day.', 'wp-queuepress'));
		}

		if (! $this->is_valid_time_format($time)) {
			return new \WP_Error('wp_queuepress_invalid_time', __('Invalid time. Slots must be at or after 05:00.', 'wp-queuepress'));
		}

		$calendar        = 'media' === $calendar ? 'media' : 'immediate';
		$slots           = $this->get_weekly_slots($calendar);
		$slots[$day_key] = array_values(
			array_filter(
				$slots[$day_key] ?? array(),
				static function (string $slot_time) use ($time): bool {
					return $slot_time !== $time;
				}
			)
		);

		update_option('media' === $calendar ? self::MEDIA_OPTION_NAME : self::OPTION_NAME, $slots);

		return array($day_key);
	}

	/**
	 * Checks if a submitted time is a valid 24-hour slot value.
	 *
	 * @param string $time Slot time.
	 * @return bool
	 */
	public function is_valid_time(string $time): bool {
		return $this->is_valid_time_format($time) && $time >= self::MINIMUM_SLOT_TIME;
	}

	/** Checks time syntax without applying the current minimum-slot policy. */
	public function is_valid_time_format(string $time): bool {
		return 1 === preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
	}

	/**
	 * Sanitizes a single day's comma-separated slot list.
	 *
	 * @param string $day_value Comma-separated HH:MM values.
	 * @return array<int,string>
	 */
	private function sanitize_day_slots(string $day_value): array {
		if ('' === trim($day_value)) {
			return array();
		}

		$raw_times = array_map('trim', explode(',', $day_value));
		$times     = array();

		foreach ($raw_times as $raw_time) {
			// Accept only 24-hour clock values. Invalid input is ignored.
			// Preserve legacy values so the admin can see and remove them safely.
			if (! $this->is_valid_time_format($raw_time)) {
				continue;
			}

			$times[] = $raw_time;
		}

		$times = array_values(array_unique($times));
		sort($times, SORT_STRING);

		return $times;
	}

	/**
	 * Resolves an add-slot scope into concrete weekday keys.
	 *
	 * @param string $day_key Source day key.
	 * @param string $scope Target scope.
	 * @return array<int,string>
	 */
	private function get_scope_days(string $day_key, string $scope): array {
		if (! array_key_exists($day_key, $this->get_weekdays())) {
			return array();
		}

		if ('weekdays' === $scope) {
			return array('monday', 'tuesday', 'wednesday', 'thursday', 'friday');
		}

		if ('weekends' === $scope) {
			return array('saturday', 'sunday');
		}

		if ('everyday' === $scope) {
			return array_keys($this->get_weekdays());
		}

		return array($day_key);
	}
}
