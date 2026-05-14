<?php
/**
 * Global plugin preferences.
 *
 * @package QueuePostScheduler\Settings
 */

declare(strict_types=1);

namespace QueuePostScheduler\Settings;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Stores and normalizes lightweight admin preferences.
 */
final class Preferences {
	/**
	 * WordPress option name for plugin preferences.
	 */
	public const OPTION_NAME = 'wp_queuepress_settings';

	/**
	 * Returns normalized preferences.
	 *
	 * @return array<string,string|bool>
	 */
	public function get(): array {
		$stored = get_option(self::OPTION_NAME, array());

		return $this->sanitize(is_array($stored) ? $stored : array());
	}

	/**
	 * Sanitizes submitted preferences.
	 *
	 * @param mixed $raw_settings Raw option value.
	 * @return array<string,string|bool>
	 */
	public function sanitize($raw_settings): array {
		$raw_settings = is_array($raw_settings) ? $raw_settings : array();

		return array(
			'week_start'  => in_array($raw_settings['week_start'] ?? '', array('sunday', 'monday'), true) ? $raw_settings['week_start'] : 'monday',
			'time_format' => in_array($raw_settings['time_format'] ?? '', array('12', '24'), true) ? $raw_settings['time_format'] : '12',
			'date_format' => in_array($raw_settings['date_format'] ?? '', array('F j, Y', 'j F Y', 'Y-m-d'), true) ? $raw_settings['date_format'] : 'F j, Y',
			'pause_queue' => ! empty($raw_settings['pause_queue']),
		);
	}

	/**
	 * Returns the preferred date format.
	 *
	 * @return string
	 */
	public function get_date_format(): string {
		$settings = $this->get();

		return (string) $settings['date_format'];
	}

	/**
	 * Returns the preferred time format.
	 *
	 * @return string
	 */
	public function get_time_format(): string {
		$settings = $this->get();

		return '24' === $settings['time_format'] ? 'H:i' : 'g:i A';
	}

	/**
	 * Returns whether the calendar should start on Sunday.
	 *
	 * @return bool
	 */
	public function starts_on_sunday(): bool {
		$settings = $this->get();

		return 'sunday' === $settings['week_start'];
	}

	/**
	 * Returns whether queue assignment is paused.
	 *
	 * @return bool
	 */
	public function is_queue_paused(): bool {
		$settings = $this->get();

		return ! empty($settings['pause_queue']);
	}
}
