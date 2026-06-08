<?php
/**
 * Admin menu registration.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Adds the plugin pages to the WordPress admin menu.
 */
final class Admin_Menu {
	/**
	 * Settings page controller.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Calendar page controller.
	 *
	 * @var Calendar_Page
	 */
	private Calendar_Page $calendar_page;

	/**
	 * Pipeline page controller.
	 *
	 * @var Pipeline_Page
	 */
	private Pipeline_Page $pipeline_page;

	/**
	 * Buffer page controller.
	 *
	 * @var Buffer_Page
	 */
	private Buffer_Page $buffer_page;

	/**
	 * Builds the admin menu controller.
	 *
	 * @param Settings_Page  $settings_page  Settings page controller.
	 * @param Calendar_Page  $calendar_page  Calendar page controller.
	 * @param Pipeline_Page  $pipeline_page  Pipeline page controller.
	 * @param Buffer_Page    $buffer_page    Buffer page controller.
	 */
	public function __construct(Settings_Page $settings_page, Calendar_Page $calendar_page, Pipeline_Page $pipeline_page, Buffer_Page $buffer_page) {
		$this->settings_page  = $settings_page;
		$this->calendar_page  = $calendar_page;
		$this->pipeline_page = $pipeline_page;
		$this->buffer_page   = $buffer_page;
	}

	/**
	 * Registers admin menu hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('admin_menu', array($this, 'add_pages'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	/**
	 * Adds top-level and submenu admin pages.
	 *
	 * @return void
	 */
	public function add_pages(): void {
		add_menu_page(
			__('Bunny Queue Press', 'wp-queuepress'),
			__('Bunny Queue Press', 'wp-queuepress'),
			'manage_options',
			'qps-pipeline',
			array($this->pipeline_page, 'render'),
			'dashicons-calendar-alt',
			58
		);

		add_submenu_page(
			'qps-pipeline',
			__('Pipeline', 'wp-queuepress'),
			__('Pipeline', 'wp-queuepress'),
			'manage_options',
			'qps-pipeline',
			array($this->pipeline_page, 'render')
		);

		add_submenu_page(
			'qps-pipeline',
			__('Calendar Settings', 'wp-queuepress'),
			__('Calendar Settings', 'wp-queuepress'),
			'manage_options',
			'qps-calendar',
			array($this->calendar_page, 'render')
		);

		add_submenu_page(
			'qps-pipeline',
			__('Settings', 'wp-queuepress'),
			__('Settings', 'wp-queuepress'),
			'manage_options',
			'qps-settings',
			array($this->settings_page, 'render')
		);

		add_submenu_page(
			'qps-pipeline',
			__('Buffer', 'wp-queuepress'),
			__('Buffer', 'wp-queuepress'),
			'manage_options',
			'qps-buffer',
			array($this->buffer_page, 'render')
		);
	}

	/**
	 * Enqueues shared admin CSS only on this plugin's screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets(string $hook_suffix): void {
		$current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$allowed_hooks = array(
			'toplevel_page_qps-pipeline',
			'qps-pipeline_page_qps-pipeline',
			'qps-pipeline_page_qps-calendar',
			'qps-pipeline_page_qps-settings',
			'qps-pipeline_page_qps-buffer',
		);

		if (! in_array($hook_suffix, $allowed_hooks, true) && ! in_array($current_page, array('qps-pipeline', 'qps-calendar', 'qps-settings', 'qps-buffer'), true)) {
			return;
		}

		// Shared Bunny Admin UI base (header, tabs, nav).
		wp_enqueue_style(
			'bunny-admin',
			WP_QUEUEPRESS_PLUGIN_URL . 'assets/css/bunny-admin.css',
			array(),
			WP_QUEUEPRESS_VERSION
		);

		// Keep the stylesheet scoped to the plugin's admin pages.
		wp_enqueue_style(
			'wp-queuepress-admin',
			WP_QUEUEPRESS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WP_QUEUEPRESS_VERSION
		);

		if ('qps-calendar' !== $current_page && 'qps-pipeline_page_qps-calendar' !== $hook_suffix) {
			if ('qps-buffer' === $current_page || 'qps-pipeline_page_qps-buffer' === $hook_suffix) {
				wp_enqueue_script(
					'wp-queuepress-buffer-admin',
					WP_QUEUEPRESS_PLUGIN_URL . 'assets/js/buffer-admin.js',
					array(),
					WP_QUEUEPRESS_VERSION,
					true
				);
				wp_localize_script(
					'wp-queuepress-buffer-admin',
					'qpsBufferAdmin',
					array(
						'ajaxUrl' => admin_url('admin-ajax.php'),
						'i18n'    => array(
							'saving'   => __('Saving…', 'wp-queuepress'),
							'saved'    => __('Saved', 'wp-queuepress'),
							'error'    => __('Error saving', 'wp-queuepress'),
							'session'  => __('Session expired. Please reload.', 'wp-queuepress'),
							'enabled'  => __('Enabled', 'wp-queuepress'),
							'disabled' => __('Disabled', 'wp-queuepress'),
						),
					)
				);
			}

			if ('qps-pipeline' === $current_page || 'toplevel_page_qps-pipeline' === $hook_suffix) {
				wp_enqueue_script(
					'wp-queuepress-pipeline-buffer',
					WP_QUEUEPRESS_PLUGIN_URL . 'assets/js/pipeline-buffer.js',
					array(),
					WP_QUEUEPRESS_VERSION,
					true
				);
				wp_localize_script(
					'wp-queuepress-pipeline-buffer',
					'qpsPipelineBuffer',
					array(
						'ajaxUrl' => admin_url('admin-ajax.php'),
						'i18n'    => array(
							'sending'      => __('Sending…', 'wp-queuepress'),
							'sent'         => __('Sent', 'wp-queuepress'),
							'error'        => __('Error sending to Buffer.', 'wp-queuepress'),
							'networkError' => __('Network error. Please try again.', 'wp-queuepress'),
							'sentOn'       => __('Sent to Buffer on', 'wp-queuepress'),
						),
					)
				);
			}

			return;
		}

		wp_enqueue_script(
			'wp-queuepress-calendar',
			WP_QUEUEPRESS_PLUGIN_URL . 'assets/js/calendar.js',
			array(),
			WP_QUEUEPRESS_VERSION,
			true
		);

		wp_localize_script(
			'wp-queuepress-calendar',
			'wpQueuePressSlots',
			array(
				'ajaxUrl'     => admin_url('admin-ajax.php'),
				'nonce'       => wp_create_nonce('wp_queuepress_slots'),
				'pipelineUrl' => admin_url('admin.php?page=qps-pipeline'),
				'messages'    => array(
					'invalidTime' => __('Invalid time.', 'wp-queuepress'),
					'saving'      => __('Saving...', 'wp-queuepress'),
					'deleteText'  => __('Delete', 'wp-queuepress'),
					'emptyText'   => __('No configured slots.', 'wp-queuepress'),
				),
			)
		);
	}
}
