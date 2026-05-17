<?php
/**
 * Calendar page scaffold.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

use DateTimeImmutable;
use DateTimeZone;
use QueuePostScheduler\Schedule\Schedule_Calculator;
use QueuePostScheduler\Schedule\Slot_Repository;
use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Renders a lightweight calendar-like overview using native admin markup.
 */
final class Calendar_Page {
	/**
	 * Slot persistence service.
	 *
	 * @var Slot_Repository
	 */
	private Slot_Repository $slot_repository;

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
	 * Builds the calendar page controller.
	 *
	 * @param Slot_Repository    $slot_repository Slot persistence service.
	 * @param Schedule_Calculator $schedule_calculator Slot availability service.
	 * @param Preferences         $preferences Plugin preferences.
	 */
	public function __construct(
		Slot_Repository $slot_repository,
		Schedule_Calculator $schedule_calculator,
		Preferences $preferences
	) {
		$this->slot_repository     = $slot_repository;
		$this->schedule_calculator = $schedule_calculator;
		$this->preferences         = $preferences;
	}

	/**
	 * Registers calendar page hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Reserved for future calendar hooks.
	}

	/**
	* Renders the calendar overview page.
	*
	* @return void
	*/
	public function render(): void {
	if (! current_user_can('manage_options')) {
	wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
	}

	$timezone     = wp_timezone();
	$week_start   = $this->get_requested_week_start($timezone);
	$week_end     = $week_start->modify('+6 days')->setTime(23, 59, 59);
	$availability = $this->schedule_calculator->get_week_availability($week_start, $week_end, array());
	$days         = $this->build_week_days($week_start);
	?>
	<div class="wrap qps-wrap" data-wp-queuepress-week="<?php echo esc_attr($week_start->format('Y-m-d')); ?>">
		<h1><?php echo esc_html__('Calendar Settings', 'wp-queuepress'); ?></h1>
		<p class="description">
			<?php echo esc_html__('Configure recurring weekly publishing slots and review your schedule template.', 'wp-queuepress'); ?>
		</p>

		<?php $this->render_global_slot_controls(); ?>

		<div class="qps-calendar-grid">
			<?php foreach ($days as $day) : ?>
			<?php
			$day_key = strtolower($day->format('l'));

			if (! in_array($day_key, array('monday', 'tuesday', 'wednesday', 'thursday', 'friday'), true)) {
				continue;
			}

			$day_date  = $day->format('Y-m-d');
			$day_slots = $availability[$day_date] ?? array();
			?>
			<section class="qps-day-column">
			<header class="qps-day-header">
				<h2><?php echo esc_html($this->slot_repository->get_weekdays()[$day_key] ?? $day->format('l')); ?></h2>
			</header>

			<div class="qps-day-section">
				<h3><?php echo esc_html__('Configured Slots', 'wp-queuepress'); ?></h3>
				<div class="qps-slot-manager" data-day="<?php echo esc_attr($day_key); ?>">
					<div class="qps-slot-list-wrap">
						<?php $this->render_slots($day_slots, $day_key); ?>
					</div>
					<div class="qps-slot-message" aria-live="polite"></div>
				</div>
			</div>
			</section>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	}

	/**
	 * Gets the selected week start from the query string.
	 *
	 * @param DateTimeZone $timezone Site timezone.
	 * @return DateTimeImmutable
	 */
	private function get_requested_week_start(DateTimeZone $timezone): DateTimeImmutable {
		$requested_week = isset($_GET['qps_week']) ? sanitize_text_field(wp_unslash($_GET['qps_week'])) : '';

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_week)) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $requested_week, $timezone);

			if ($date instanceof DateTimeImmutable) {
				return $this->get_start_of_week($date);
			}
		}

		return $this->get_start_of_week(new DateTimeImmutable('now', $timezone));
	}

	/**
	 * Builds the seven days displayed by the calendar.
	 *
	 * @param DateTimeImmutable $week_start First day of the week.
	 * @return DateTimeImmutable[]
	 */
	private function build_week_days(DateTimeImmutable $week_start): array {
		$days = array();

		for ($i = 0; $i < 7; $i++) {
			$days[] = $week_start->modify('+' . $i . ' days');
		}

		return $days;
	}

	/**
	 * Renders previous, current, and next week links.
	 *
	 * @param DateTimeImmutable $week_start Current week start.
	 * @return void
	 */
	private function render_week_navigation(DateTimeImmutable $week_start): void {
		$previous_week = $week_start->modify('-7 days')->format('Y-m-d');
		$next_week     = $week_start->modify('+7 days')->format('Y-m-d');
		$current_week  = $this->get_start_of_week(new DateTimeImmutable('now', wp_timezone()))->format('Y-m-d');
		?>
		<nav class="qps-week-nav" aria-label="<?php echo esc_attr__('Calendar week navigation', 'wp-queuepress'); ?>">
			<div class="qps-week-nav__label">
				<span><?php echo esc_html__('Week of', 'wp-queuepress'); ?></span>
				<strong><?php echo esc_html(wp_date($this->preferences->get_date_format(), $week_start->getTimestamp())); ?></strong>
			</div>
			<div class="qps-week-nav__actions">
				<a class="button" href="<?php echo esc_url(add_query_arg('qps_week', $previous_week)); ?>">
					<?php echo esc_html__('Previous Week', 'wp-queuepress'); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('qps_week', $current_week)); ?>">
					<?php echo esc_html__('Current Week', 'wp-queuepress'); ?>
				</a>
				<a class="button" href="<?php echo esc_url(add_query_arg('qps_week', $next_week)); ?>">
					<?php echo esc_html__('Next Week', 'wp-queuepress'); ?>
				</a>
			</div>
		</nav>
		<?php
	}

	/**
	 * Renders the single global add-slot panel.
	 *
	 * @return void
	 */
	private function render_global_slot_controls(): void {
		$weekdays = $this->slot_repository->get_weekdays();
		?>
		<section class="qps-global-slot-manager qps-slot-manager">
			<div class="qps-global-slot-header">
				<div>
					<h2><?php echo esc_html__('Slot Management', 'wp-queuepress'); ?></h2>
					<p><?php echo esc_html__('Add reusable publishing slots without editing each day separately.', 'wp-queuepress'); ?></p>
				</div>
				<button type="button" class="button button-primary qps-add-slot-toggle">
					<?php echo esc_html__('Add Slot', 'wp-queuepress'); ?>
				</button>
			</div>
			<div class="qps-slot-form" hidden>
				<label>
					<span><?php echo esc_html__('Schedule For', 'wp-queuepress'); ?></span>
					<select class="qps-slot-schedule-for">
						<option value="specific-day"><?php echo esc_html__('Specific Day', 'wp-queuepress'); ?></option>
						<option value="weekdays"><?php echo esc_html__('Weekdays', 'wp-queuepress'); ?></option>
						<option value="weekends"><?php echo esc_html__('Weekends', 'wp-queuepress'); ?></option>
						<option value="everyday"><?php echo esc_html__('Every Day', 'wp-queuepress'); ?></option>
					</select>
				</label>
				<label class="qps-specific-day-wrap">
					<span><?php echo esc_html__('Day', 'wp-queuepress'); ?></span>
					<select class="qps-slot-day">
						<?php foreach ($weekdays as $day_key => $day_label) : ?>
							<option value="<?php echo esc_attr($day_key); ?>"><?php echo esc_html($day_label); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php echo esc_html__('Time', 'wp-queuepress'); ?></span>
					<input class="qps-slot-time" type="time" step="60" />
				</label>
				<div class="qps-slot-form-actions">
					<button type="button" class="button button-primary qps-slot-save">
						<?php echo esc_html__('Add Slot', 'wp-queuepress'); ?>
					</button>
					<button type="button" class="button-link qps-slot-cancel">
						<?php echo esc_html__('Cancel', 'wp-queuepress'); ?>
					</button>
				</div>
				<div class="qps-slot-message" aria-live="polite"></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders configured slots and their availability.
	 *
	 * @param array<int,array<string,mixed>> $slots Slots for one day.
	 * @return void
	 */
	private function render_slots(array $slots, string $day_key): void {
		echo self::render_slot_list_html($slots, $day_key);
	}

	/**
	 * Returns slot list markup used by both page render and AJAX refreshes.
	 *
	 * @param array<int,array<string,mixed>> $slots Slots for one day.
	 * @param string                         $day_key Weekday key.
	 * @return string
	 */
	public static function render_slot_list_html(array $slots, string $day_key): string {
		ob_start();

		if (empty($slots)) {
			self::render_empty_state_html(__('No configured slots.', 'wp-queuepress'), __('Empty Slot', 'wp-queuepress'));
			return (string) ob_get_clean();
		}

		echo '<ul class="qps-slot-list">';

		foreach ($slots as $slot) {
			$time         = (string) $slot['time'];
			$status_class = ! empty($slot['occupied']) ? 'is-occupied' : 'is-free';
			$status_text  = ! empty($slot['occupied'])
				? __('Scheduled', 'wp-queuepress')
				: __('Empty Slot', 'wp-queuepress');

			echo '<li class="qps-slot ' . esc_attr($status_class) . '" data-time="' . esc_attr($time) . '">';
			echo '<div class="qps-slot-main">';
			echo '<time>' . esc_html($time) . '</time>';
			echo '<span class="qps-badge ' . esc_attr(! empty($slot['occupied']) ? 'is-scheduled' : 'is-empty') . '">' . esc_html($status_text) . '</span>';
			echo '</div>';
			echo '<button type="button" class="button-link-delete qps-slot-delete" data-day="' . esc_attr($day_key) . '" data-time="' . esc_attr($time) . '">';
			echo esc_html__('Delete', 'wp-queuepress');
			echo '</button>';
			echo '</li>';
		}

		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Renders a consistent empty state with a compact status badge.
	 *
	 * @param string $message Empty state message.
	 * @param string $badge Badge text.
	 * @return void
	 */
	private function render_empty_state(string $message, string $badge): void {
		self::render_empty_state_html($message, $badge);
	}

	/**
	 * Prints empty state markup shared by static slot rendering.
	 *
	 * @param string $message Empty state message.
	 * @param string $badge Badge text.
	 * @return void
	 */
	private static function render_empty_state_html(string $message, string $badge): void {
		echo '<div class="qps-empty">';
		echo '<span>' . esc_html($message) . '</span>';
		echo '<span class="qps-badge is-empty">' . esc_html($badge) . '</span>';
		echo '</div>';
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
