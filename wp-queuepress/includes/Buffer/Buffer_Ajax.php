<?php
/**
 * Buffer AJAX handler.
 *
 * Registers and processes WordPress AJAX actions related to Buffer publishing.
 * Lives in the Buffer domain because it belongs to Buffer's responsibility,
 * not to the admin UI layer.
 *
 * Responsibilities:
 *   - Register wp_ajax_* hooks.
 *   - Validate nonce and user capability.
 *   - Instantiate publishers and dispatch publish requests.
 *   - Persist results to post meta (_queuepress_buffer_channels).
 *   - Return JSON responses to the browser.
 *
 * Persistence model (_queuepress_buffer_channels):
 *   Keyed by channel_id (the primary key). Multiple channels and multiple
 *   providers can coexist for the same post without interference.
 *
 *   Structure:
 *   [
 *     'CHANNEL_ID' => [
 *       'provider' => 'buffer',
 *       'service'  => 'instagram',
 *       'post_id'  => '...',
 *       'status'   => 'scheduled',
 *       'sent_at'  => 'Y-m-d H:i:s',
 *     ],
 *     ...
 *   ]
 *
 *   Reading: always use channel_id as the primary key.
 *   Do NOT filter by service when reading — multiple Instagram channels
 *   may exist in Sprint 3.1+.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use QueuePostScheduler\Admin\Buffer_Page;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles AJAX requests for Buffer publishing actions.
 */
final class Buffer_Ajax {

	/**
	 * Post meta key for all Buffer channel publication records.
	 */
	public const META_KEY = '_queuepress_buffer_channels';

	/**
	 * Nonce action prefix. Full action: qps_send_to_buffer_{post_id}.
	 */
	private const NONCE_PREFIX = 'qps_send_to_buffer_';

	/**
	 * Registers WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('wp_ajax_qps_send_to_buffer', array($this, 'handle_send_to_buffer'));
	}

	// -------------------------------------------------------------------------
	// Nonce helper (public so Pipeline_Page can generate matching nonces)
	// -------------------------------------------------------------------------

	/**
	 * Returns the nonce action string for a given post ID.
	 *
	 * Used by Pipeline_Page to generate the data-nonce attribute,
	 * and by the handler to verify it.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return string Nonce action string.
	 */
	public static function nonce_action(int $post_id): string {
		return self::NONCE_PREFIX . $post_id;
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Handles the qps_send_to_buffer AJAX action.
	 *
	 * Flow:
	 *   1. Verify nonce.
	 *   2. Verify user capability for the specific post.
	 *   3. Load Buffer settings and instantiate publisher.
	 *   4. Execute publication.
	 *   5. Persist result to post meta.
	 *   6. Return JSON.
	 *
	 * @return void
	 */
	public function handle_send_to_buffer(): void {
		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

		if ($post_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid post ID.', 'wp-queuepress')), 400);
		}

		// Verify nonce specific to this post.
		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::nonce_action($post_id))) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		// Verify capability for this specific post.
		if (! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('You do not have permission to publish this post.', 'wp-queuepress')), 403);
		}

		// Load Buffer settings.
		$settings = get_option(Buffer_Page::OPTION_SETTINGS, array());
		$token    = ! empty($settings['access_token']) ? (string) $settings['access_token'] : '';

		if (empty($token)) {
			wp_send_json_error(array('message' => __('Buffer access token is not configured.', 'wp-queuepress')));
		}

		// Instantiate common services.
		$client = new Buffer_Client($token);
		$config = new Channel_Config();

		// Collect per-channel results.
		$all_channels = $config->get_all();
		$post = get_post($post_id);
		$results = array();

		foreach ($all_channels as $channel_id => $channel_cfg) {
			if (! is_array($channel_cfg)) { continue; }
			if (! isset($channel_cfg['provider']) || $channel_cfg['provider'] !== 'buffer') { continue; }
			if (empty($channel_cfg['enabled'])) { continue; }

			$service = isset($channel_cfg['service']) ? (string) $channel_cfg['service'] : '';

			switch ($service) {
				case 'instagram':
					$publisher = new Instagram_Publisher($client, $config);
					$res = $publisher->publish_to_channel($post_id, $channel_id);
					break;

				case 'twitter':
					$publisher = new Twitter_Publisher($client, $config);
					$res = $publisher->publish_to_channel($post_id, $channel_id);
					break;

				case 'threads':
					$publisher = new Threads_Publisher($client, $config);
					$res = $publisher->publish_to_channel($post_id, $channel_id);
					break;

				default:
					$res = array('success' => false, 'message' => __('Unsupported channel service.', 'wp-queuepress'), 'channel_id' => $channel_id, 'service' => $service);
			}

			// Persist per-channel result only when Buffer returned a post_id.
			// This prevents overwriting existing records with failed attempts.
			if (isset($res['channel_id']) && ! empty($res['channel_id']) && ! empty($res['post_id'])) {
				$this->save_channel_record($post_id, $res);
			}

			$results[$channel_id] = $res;
		}

		// Build a user-friendly message per channel for the UI.
		$messages = array();
		foreach ($results as $cid => $r) {
			$svc = isset($r['service']) ? ucfirst($r['service']) : $cid;
			if (! empty($r['success'])) {
				$messages[] = $svc . ': OK';
			} else {
				$messages[] = $svc . ': ' . ($r['message'] ?? 'Error');
			}
		}

		wp_send_json_success(array(
			'results' => $results,
			'message' => implode(' · ', $messages),
			'sent_at' => gmdate('Y-m-d H:i:s'),
		));
	}

	// -------------------------------------------------------------------------
	// Persistence
	// -------------------------------------------------------------------------

	/**
	 * Saves or overwrites the Buffer publication record for a single channel.
	 *
	 * Reads the existing _queuepress_buffer_channels array, updates only the
	 * entry for the channel used in this publication, and writes it back.
	 * All other channel entries remain untouched.
	 *
	 * @param int                  $post_id WordPress post ID.
	 * @param array<string, mixed> $result  Normalized result from the publisher.
	 * @return void
	 */
	private function save_channel_record(int $post_id, array $result): void {
		$channel_id = (string) ($result['channel_id'] ?? '');
		if (empty($channel_id)) {
			return;
		}

		$channels = get_post_meta($post_id, self::META_KEY, true);
		if (! is_array($channels)) {
			$channels = array();
		}

		// Keyed by channel_id — the primary key.
		// Do NOT key by service: multiple channels of the same service may exist in 3.1+.
		$channels[$channel_id] = array(
			'provider' => 'buffer',
			'service'  => (string) ($result['service'] ?? 'buffer'),
			'post_id'  => (string) ($result['post_id'] ?? ''),
			'status'   => (string) ($result['status'] ?? ''),
			'sent_at'  => gmdate('Y-m-d H:i:s'),
		);

		update_post_meta($post_id, self::META_KEY, $channels);
	}
}
