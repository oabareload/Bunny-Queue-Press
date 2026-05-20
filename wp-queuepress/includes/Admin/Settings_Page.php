<?php
/**
 * Weekly slot settings page.
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
 * Renders and stores weekly publishing slot configuration.
 */
final class Settings_Page {
	/**
	 * Option group used by the WordPress Settings API.
	 */
	private const OPTION_GROUP = 'qps_settings';

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
	 * Builds the settings page.
	 *
	 * @param Slot_Repository $slot_repository Slot persistence service.
	 * @param Preferences     $preferences Plugin preferences.
	 */
	public function __construct(Slot_Repository $slot_repository, Preferences $preferences) {
		$this->slot_repository = $slot_repository;
		$this->preferences     = $preferences;
	}

	/**
	 * Registers settings.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_wp_queuepress_export_config', array($this, 'export_configuration'));
		add_action('admin_post_wp_queuepress_reset_slots', array($this, 'reset_slots'));
	}

	/**
	 * Registers the weekly slots option with sanitization.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Preferences::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this->preferences, 'sanitize'),
				'default'           => array(),
			)
		);
	}

	/**
	 * Exports slot configuration and preferences as JSON.
	 *
	 * @return void
	 */
	public function export_configuration(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('wp_queuepress_export_config');

		$payload = array(
			'plugin'   => 'wp-queuepress',
			'version'  => WP_QUEUEPRESS_VERSION,
			'settings' => $this->preferences->get(),
			'slots'    => $this->slot_repository->get_weekly_slots(),
		);

		nocache_headers();
		header('Content-Type: application/json; charset=' . get_option('blog_charset'));
		header('Content-Disposition: attachment; filename=wp-queuepress-config.json');
		echo wp_json_encode($payload, JSON_PRETTY_PRINT);
		exit;
	}

	/**
	 * Removes all configured publishing slots.
	 *
	 * @return void
	 */
	public function reset_slots(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('wp_queuepress_reset_slots');
		update_option(Slot_Repository::OPTION_NAME, array());

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => 'qps-settings',
					'wp_queuepress_reset'   => '1',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		$settings = $this->preferences->get();
		?>
		<div class="wrap bunny-wrap">
			<?php Admin_Header::render( 'qps-settings' ); ?>
			<div class="bunny-page-content">

			<?php if (isset($_GET['settings-updated'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Settings saved.', 'wp-queuepress'); ?></p></div>
			<?php endif; ?>

			<?php if (isset($_GET['wp_queuepress_reset'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Slot configuration reset.', 'wp-queuepress'); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_GROUP); ?>
				<div class="qps-settings-grid">
					<section class="qps-settings-panel">
						<h2><?php echo esc_html__('Calendar Preferences', 'wp-queuepress'); ?></h2>
					<p><?php echo esc_html__('Control how dates and times are displayed inside Bunny Queue Press.', 'wp-queuepress'); ?></p>

						<label class="qps-field">
							<span><?php echo esc_html__('Week Starts On', 'wp-queuepress'); ?></span>
							<select name="<?php echo esc_attr(Preferences::OPTION_NAME); ?>[week_start]">
								<option value="sunday" <?php selected($settings['week_start'], 'sunday'); ?>><?php echo esc_html__('Sunday', 'wp-queuepress'); ?></option>
								<option value="monday" <?php selected($settings['week_start'], 'monday'); ?>><?php echo esc_html__('Monday', 'wp-queuepress'); ?></option>
							</select>
						</label>

						<label class="qps-field">
							<span><?php echo esc_html__('Time Format', 'wp-queuepress'); ?></span>
							<select name="<?php echo esc_attr(Preferences::OPTION_NAME); ?>[time_format]">
								<option value="12" <?php selected($settings['time_format'], '12'); ?>><?php echo esc_html__('12-hour', 'wp-queuepress'); ?></option>
								<option value="24" <?php selected($settings['time_format'], '24'); ?>><?php echo esc_html__('24-hour', 'wp-queuepress'); ?></option>
							</select>
						</label>

						<label class="qps-field">
							<span><?php echo esc_html__('Date Format', 'wp-queuepress'); ?></span>
							<select name="<?php echo esc_attr(Preferences::OPTION_NAME); ?>[date_format]">
								<?php foreach (array('F j, Y', 'j F Y', 'Y-m-d') as $format) : ?>
									<option value="<?php echo esc_attr($format); ?>" <?php selected($settings['date_format'], $format); ?>>
										<?php echo esc_html(wp_date($format)); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</section>

					<section class="qps-settings-panel">
						<h2><?php echo esc_html__('Queue Behavior', 'wp-queuepress'); ?></h2>
						<label class="qps-toggle-field">
							<input type="checkbox" name="<?php echo esc_attr(Preferences::OPTION_NAME); ?>[pause_queue]" value="1" <?php checked(! empty($settings['pause_queue'])); ?> />
							<span>
								<strong><?php echo esc_html__('Pause automatic queue scheduling', 'wp-queuepress'); ?></strong>
								<small><?php echo esc_html__('When paused, editor queue actions will not assign new publishing slots. Existing scheduled posts are not changed.', 'wp-queuepress'); ?></small>
							</span>
						</label>
					</section>
				</div>

				<?php submit_button(__('Save Settings', 'wp-queuepress')); ?>
			</form>

			<div class="qps-settings-grid">
				<section class="qps-settings-panel">
					<h2><?php echo esc_html__('Export Configuration', 'wp-queuepress'); ?></h2>
					<p><?php echo esc_html__('Download current slot configuration and plugin preferences as a JSON file.', 'wp-queuepress'); ?></p>
					<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_queuepress_export_config'), 'wp_queuepress_export_config')); ?>">
						<?php echo esc_html__('Export JSON', 'wp-queuepress'); ?>
					</a>
				</section>

				<section class="qps-settings-panel qps-settings-panel--danger">
					<h2><?php echo esc_html__('Reset Slot Configuration', 'wp-queuepress'); ?></h2>
					<p><?php echo esc_html__('Remove all configured publishing slots. Scheduled and published posts are not changed.', 'wp-queuepress'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remove all configured publishing slots?', 'wp-queuepress')); ?>');">
						<input type="hidden" name="action" value="wp_queuepress_reset_slots" />
						<?php wp_nonce_field('wp_queuepress_reset_slots'); ?>
						<?php submit_button(__('Reset Slots', 'wp-queuepress'), 'delete', 'submit', false); ?>
					</form>
				</section>
			</div>
			</div><!-- .qps-page-content -->
		</div>
		<?php
	}
}
