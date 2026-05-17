<?php
/**
 * Plugin Name: Bunny Queue Press
 * Plugin URI: https://bunnychase.net/bunny-queue-press/
 * Description: A lightweight editorial scheduling plugin for WordPress that allows creators and publishers to configure reusable weekly publishing slots, visualize scheduled and published content in a clean calendar interface, and identify free publishing spaces quickly.
 * Version: 1.2.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: BunnyChase
 * Author URI: https://bunnychase.net/
 * License: GPL-2.0-or-later
 * Text Domain: wp-queuepress
 * Domain Path: /languages
 *
 * @package QueuePostScheduler
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('WP_QUEUEPRESS_VERSION', '1.2.2');
define('WP_QUEUEPRESS_PLUGIN_FILE', __FILE__);
define('WP_QUEUEPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_QUEUEPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Loads plugin classes from the includes directory.
 *
 * The autoloader intentionally supports only this plugin namespace so it stays
 * predictable and does not interfere with other WordPress plugins.
 *
 * @param string $class_name Fully qualified class name.
 */
spl_autoload_register(
	static function (string $class_name): void {
		$prefix = 'QueuePostScheduler\\';

		if (0 !== strpos($class_name, $prefix)) {
			return;
		}

		$relative_class = substr($class_name, strlen($prefix));
		$relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class);
		$file_path      = WP_QUEUEPRESS_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative_path . '.php';

		if (is_readable($file_path)) {
			require_once $file_path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\QueuePostScheduler\Plugin::instance()->register();
	}
);
