<?php
/**
 * Calendar page scaffold.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

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
	 * Plugin preferences.
	 *
	 * @var Preferences
	 */
	private Preferences $preferences;

	/**
	 * Builds the calendar page controller.
	 *
	 * @param Slot_Repository    $slot_repository Slot persistence service.
	 * @param Preferences         $preferences Plugin preferences.
	 */
	public function __construct(
		Slot_Repository $slot_repository,
		Preferences $preferences
	) {
		$this->slot_repository     = $slot_repository;
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

	$weekdays = $this->slot_repository->get_weekdays();
	if ($this->preferences->starts_on_sunday()) {
		$sunday = array('sunday' => $weekdays['sunday']);
		unset($weekdays['sunday']);
		$weekdays = $sunday + $weekdays;
	}
	$immediate_slots  = $this->slot_repository->get_weekly_slots('immediate');
	$media_slots      = $this->slot_repository->get_weekly_slots('media');
	?>
	<div class="wrap bunny-wrap">
		<?php Admin_Header::render( 'qps-calendar' ); ?>
		<div class="bunny-page-content">
		<p class="description">
			<?php echo esc_html__('Configure recurring weekly publishing slots and review your schedule template.', 'wp-queuepress'); ?>
		</p>
		<?php if ($this->has_matching_calendar_slots()) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html__('Some Immediate and Media slots share a day and time. They remain independent and can schedule two different posts.', 'wp-queuepress'); ?></p></div>
		<?php endif; ?>
		<div class="qps-calendar-legend" aria-label="<?php echo esc_attr__('Calendar slot types', 'wp-queuepress'); ?>">
			<span class="qps-calendar-legend__item qps-calendar-legend__item--immediate"><?php echo esc_html__('Immediate', 'wp-queuepress'); ?></span>
			<span class="qps-calendar-legend__item qps-calendar-legend__item--media"><?php echo esc_html__('Media', 'wp-queuepress'); ?></span>
		</div>

		<?php $this->render_global_slot_controls(); ?>
		<section class="qps-pipeline-rebuild-tools" aria-label="<?php echo esc_attr__('Queue rebuild tools', 'wp-queuepress'); ?>">
			<h2><?php echo esc_html__('Rebuild Scheduled Queues', 'wp-queuepress'); ?></h2>
			<div class="qps-calendar-rebuild">
				<h3><?php echo esc_html__('Immediate', 'wp-queuepress'); ?></h3>
				<div class="qps-rebuild-notice" data-calendar="immediate"></div>
			</div>
			<div class="qps-calendar-rebuild">
				<h3><?php echo esc_html__('Media', 'wp-queuepress'); ?></h3>
				<div class="qps-rebuild-notice" data-calendar="media"></div>
			</div>
		</section>

		<div class="qps-save-controls">
			<button class="button button-primary qps-save-weekly-slots" disabled><?php echo esc_html__('Save Changes', 'wp-queuepress'); ?></button>
			<div class="qps-save-status" aria-live="polite"></div>
		</div>

		<div class="qps-calendar-grid">
			<?php foreach ($weekdays as $day_key => $day_label) : ?>
			<?php
			$day_slots = $this->get_combined_slots_for_day($day_key, $immediate_slots, $media_slots);
			?>
			<section class="qps-day-column">
			<header class="qps-day-header">
				<h2><?php echo esc_html($day_label); ?></h2>
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
		</div><!-- .qps-page-content -->
	</div>
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
					<span><?php echo esc_html__('Calendar', 'wp-queuepress'); ?></span>
					<select class="qps-slot-calendar">
						<option value="immediate"><?php echo esc_html__('Immediate', 'wp-queuepress'); ?></option>
						<option value="media"><?php echo esc_html__('Media', 'wp-queuepress'); ?></option>
					</select>
				</label>
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
				<input class="qps-slot-time" type="time" min="05:00" step="60" />
				</label>
				<p class="description"><?php echo esc_html__('Daily maintenance runs at 04:00 site time. Publishing slots must be at or after 05:00.', 'wp-queuepress'); ?></p>
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

	/** Checks for same-day/time slots across the independent calendars. */
	private function has_matching_calendar_slots(): bool {
		$immediate = $this->slot_repository->get_weekly_slots('immediate');
		$media     = $this->slot_repository->get_weekly_slots('media');
		foreach ($immediate as $day => $times) {
			if (! empty(array_intersect($times, $media[$day] ?? array()))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Renders only the configured slots for one day in both calendars.
	 *
	 * @param array<int,array<string,mixed>> $slots Slots for one day.
	 * @return void
	 */
	private function render_slots(array $slots, string $day_key): void {
		echo self::render_slot_list_html($slots, $day_key);
	}

	/** Combines independent configured slots without querying posts or occupancy. */
	private function get_combined_slots_for_day(string $day_key, array $immediate_slots, array $media_slots): array {
		$combined = array();
		foreach (array('immediate' => $immediate_slots, 'media' => $media_slots) as $calendar => $calendar_slots) {
			foreach ($calendar_slots[$day_key] ?? array() as $time) {
				$combined[] = array('time' => $time, 'calendar' => $calendar);
			}
		}
		usort($combined, static function (array $left, array $right): int {
			return ($left['time'] <=> $right['time']) ?: ($left['calendar'] <=> $right['calendar']);
		});
		return $combined;
	}

	/**
	 * Returns slot list markup used by both page render and AJAX refreshes.
	 *
	 * @param array<int,array{time:string,calendar:string}> $slots Configured slots.
	 * @param string                         $day_key Weekday key.
	 * @return string
	 */
	public static function render_slot_list_html(array $slots, string $day_key): string {
		ob_start();

		if (empty($slots)) {
			return (string) ob_get_clean();
		}

		echo '<ul class="qps-slot-list">';

		foreach ($slots as $slot) {
			$time     = (string) $slot['time'];
			$calendar = (string) $slot['calendar'];
			$class    = 'media' === $calendar ? 'qps-slot--media' : 'qps-slot--immediate';
			echo '<li class="qps-slot ' . esc_attr($class) . '" data-time="' . esc_attr($time) . '" data-calendar="' . esc_attr($calendar) . '">';
			echo '<div class="qps-slot-main">';
			echo '<time>' . esc_html($time) . '</time>';
			echo '<span class="qps-badge">' . esc_html('media' === $calendar ? __('Media', 'wp-queuepress') : __('Immediate', 'wp-queuepress')) . '</span>';
			echo '</div>';
			echo '<button type="button" class="button-link-delete qps-slot-delete" data-day="' . esc_attr($day_key) . '" data-calendar="' . esc_attr($calendar) . '" data-time="' . esc_attr($time) . '">';
			echo esc_html__('Delete', 'wp-queuepress');
			echo '</button>';
			echo '</li>';
		}

		echo '</ul>';

		return (string) ob_get_clean();
	}

}
