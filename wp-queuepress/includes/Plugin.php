<?php
/**
 * Coordinates plugin services.
 *
 * @package QueuePostScheduler
 */

declare(strict_types=1);

namespace QueuePostScheduler;

use QueuePostScheduler\Admin\Admin_Menu;
use QueuePostScheduler\Admin\Calendar_Page;
use QueuePostScheduler\Admin\Pipeline_Page;
use QueuePostScheduler\Admin\Slot_Ajax;
use QueuePostScheduler\Admin\Settings_Page;
use QueuePostScheduler\Editor\Editor_Assets;
use QueuePostScheduler\Schedule\Post_Query;
use QueuePostScheduler\Rest\Queue_Controller;
use QueuePostScheduler\Schedule\Queue_Assigner;
use QueuePostScheduler\Schedule\Queue_Commit_Handler;
use QueuePostScheduler\Schedule\Queue_Rebuilder;
use QueuePostScheduler\Schedule\Schedule_Calculator;
use QueuePostScheduler\Schedule\Slot_Repository;
use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Main plugin container.
 *
 * This class wires the small set of services used by the MVP. It deliberately
 * avoids a full dependency injection framework to keep the plugin lightweight.
 */
final class Plugin {
	/**
	 * Shared plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->load_textdomain();
		add_action('init', array($this, 'register_queue_meta'));

		$slot_repository     = new Slot_Repository();
		$preferences         = new Preferences();
		$post_query          = new Post_Query();
		$schedule_calculator = new Schedule_Calculator($slot_repository, $post_query);
		$queue_assigner      = new Queue_Assigner($post_query, $schedule_calculator, $preferences);
		$queue_rebuilder     = new Queue_Rebuilder($slot_repository, $post_query, $schedule_calculator, $preferences);

		$settings_page = new Settings_Page($slot_repository, $preferences);
		$calendar_page = new Calendar_Page($slot_repository, $schedule_calculator, $preferences);
		$pipeline_page = new Pipeline_Page($post_query, $preferences);
		$admin_menu    = new Admin_Menu($settings_page, $calendar_page, $pipeline_page);
		$slot_ajax     = new Slot_Ajax($slot_repository, $post_query, $schedule_calculator, $preferences);
		$editor_assets = new Editor_Assets();
		$queue_api     = new Queue_Controller($queue_assigner, $queue_rebuilder);
		$queue_commit  = new Queue_Commit_Handler($queue_assigner, $queue_rebuilder, $preferences);

		$settings_page->register();
		$calendar_page->register();
		$admin_menu->register();
		$slot_ajax->register();
		$editor_assets->register();
		$queue_api->register();
		$queue_commit->register();
	}

	/**
	 * Registers editor-visible queue intent metadata.
	 *
	 * @return void
	 */
	public function register_queue_meta(): void {
		register_post_meta(
			'post',
			'_wp_queuepress_queue_mode',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => static function (): bool {
					return current_user_can('edit_posts');
				},
			)
		);
	}

	/**
	 * Prevents direct construction outside the singleton accessor.
	 */
	private function __construct() {}

	/**
	 * Loads plugin translation files from the languages directory.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-queuepress',
			false,
			dirname(plugin_basename(WP_QUEUEPRESS_PLUGIN_FILE)) . '/languages'
		);
	}
}
