<?php
/**
 * Gutenberg editor asset loading.
 *
 * @package QueuePostScheduler\Editor
 */

declare(strict_types=1);

namespace QueuePostScheduler\Editor;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Enqueues the lightweight editor action script.
 */
final class Editor_Assets {
	/**
	 * Registers editor asset hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('enqueue_block_editor_assets', array($this, 'enqueue'));
	}

	/**
	 * Enqueues the queue action script and styles for the post editor.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = get_current_screen();

		if (! $screen || 'post' !== $screen->post_type || 'post' !== $screen->base) {
			return;
		}

		wp_enqueue_script(
			'wp-queuepress-editor',
			WP_QUEUEPRESS_PLUGIN_URL . 'assets/js/editor.js',
			array('wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins'),
			WP_QUEUEPRESS_VERSION,
			true
		);

		wp_enqueue_style(
			'wp-queuepress-editor',
			WP_QUEUEPRESS_PLUGIN_URL . 'assets/css/editor.css',
			array(),
			WP_QUEUEPRESS_VERSION
		);

		wp_set_script_translations('wp-queuepress-editor', 'wp-queuepress', WP_QUEUEPRESS_PLUGIN_DIR . 'languages');
	}
}