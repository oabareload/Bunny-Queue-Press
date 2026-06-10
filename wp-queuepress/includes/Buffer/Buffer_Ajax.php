<?php
/**
 * Buffer AJAX handler.
 *
 * Registers and processes WordPress AJAX actions related to Buffer publishing.
 * Lives in the Buffer domain because it belongs to Buffer's responsibility,
 * not to the admin UI layer.
 *
 * Responsibilities:
 *   - Register wp_ajax_* hooks for: full publish, per-service resend, delete.
 *   - Validate nonce, user capability and (where applicable) post status.
 *   - Look up the publisher for each channel via Platform_Registry.
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
	 * Nonce action prefix for the full "Send to Buffer" action.
	 * Full action: qps_send_to_buffer_{post_id}.
	 */
	private const NONCE_SEND_PREFIX = 'qps_send_to_buffer_';

	/**
	 * Nonce action prefix for the per-service resend action.
	 * Full action: qps_send_to_buffer_service_{post_id}_{service}.
	 */
	private const NONCE_SERVICE_PREFIX = 'qps_send_to_buffer_service_';

	/**
	 * Nonce action prefix for the delete action.
	 * Full action: qps_delete_buffer_posts_{post_id}.
	 */
	private const NONCE_DELETE_PREFIX = 'qps_delete_buffer_posts_';

	/**
	 * Post statuses that are allowed to be sent to Buffer for the first time.
	 *
	 * Server-side enforcement of the "no drafts" rule. The Pipeline UI also
	 * disables the action visually, but the check is duplicated here so a
	 * crafted request cannot bypass it.
	 */
	private const PUBLISHABLE_STATUSES = array('publish', 'future');

	/**
	 * Registers WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('wp_ajax_qps_send_to_buffer',         array($this, 'handle_send_to_buffer'));
		add_action('wp_ajax_qps_send_to_buffer_service', array($this, 'handle_send_to_buffer_service'));
		add_action('wp_ajax_qps_delete_buffer_posts',    array($this, 'handle_delete_buffer_posts'));
	}

	// -------------------------------------------------------------------------
	// Nonce helpers (public so the Pipeline can generate matching nonces)
	// -------------------------------------------------------------------------

	/**
	 * Nonce action for the full "Send to Buffer" action.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function nonce_action(int $post_id): string {
		return self::NONCE_SEND_PREFIX . $post_id;
	}

	/**
	 * Nonce action for the per-service resend action.
	 *
	 * @param int    $post_id
	 * @param string $service
	 * @return string
	 */
	public static function nonce_action_service(int $post_id, string $service): string {
		return self::NONCE_SERVICE_PREFIX . $post_id . '_' . sanitize_key($service);
	}

	/**
	 * Nonce action for the delete action.
	 *
	 * @param int $post_id
	 * @return string
	 */
	public static function nonce_action_delete(int $post_id): string {
		return self::NONCE_DELETE_PREFIX . $post_id;
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles the qps_send_to_buffer AJAX action (full multi-platform publish).
	 *
	 * Flow:
	 *   1. Verify nonce.
	 *   2. Verify user capability for the specific post.
	 *   3. Verify post status is publish or future.
	 *   4. Load Buffer settings and dispatch publish to all enabled channels.
	 *   5. Persist per-channel results to post meta.
	 *   6. Return JSON.
	 *
	 * @return void
	 */
	public function handle_send_to_buffer(): void {
		$post_id = (int) ($_POST['post_id'] ?? 0);
		if ($post_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid post ID.', 'wp-queuepress')), 400);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::nonce_action($post_id))) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		if (! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('You do not have permission to publish this post.', 'wp-queuepress')), 403);
		}

		$status = $this->get_post_status_or_error($post_id);
		if (is_wp_error($status)) {
			wp_send_json_error(array('message' => $status->get_error_message()), 400);
		}

		$results = $this->publish_to_services($post_id, array_keys(Platform_Registry::all()));
		if (isset($results['__error'])) {
			wp_send_json_error(array('message' => $results['__error']));
		}

		$this->send_results_response($results);
	}

	/**
	 * Handles the qps_send_to_buffer_service AJAX action (single-platform resend).
	 *
	 * Re-uses the same publish_to_services helper but scopes it to a single
	 * service. This endpoint is NOT blocked by post_status — the user is
	 * explicitly asking to re-send an already-published post.
	 *
	 * @return void
	 */
	public function handle_send_to_buffer_service(): void {
		$post_id = (int) ($_POST['post_id'] ?? 0);
		$service = isset($_POST['service']) ? sanitize_key((string) wp_unslash($_POST['service'])) : '';

		if ($post_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid post ID.', 'wp-queuepress')), 400);
		}
		if ($service === '' || ! Platform_Registry::exists($service)) {
			wp_send_json_error(array('message' => __('Invalid service.', 'wp-queuepress')), 400);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::nonce_action_service($post_id, $service))) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		if (! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('You do not have permission to publish this post.', 'wp-queuepress')), 403);
		}

		$results = $this->publish_to_services($post_id, array($service));
		if (isset($results['__error'])) {
			wp_send_json_error(array('message' => $results['__error']));
		}

		$this->send_results_response($results);
	}

	/**
	 * Handles the qps_delete_buffer_posts AJAX action.
	 *
	 * Reads the stored post_ids from post meta, calls Buffer's deletePost
	 * mutation for each one and then clears the meta entry. Never touches
	 * the WordPress post, its images or its content.
	 *
	 * @return void
	 */
	public function handle_delete_buffer_posts(): void {
		$post_id = (int) ($_POST['post_id'] ?? 0);
		if ($post_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid post ID.', 'wp-queuepress')), 400);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::nonce_action_delete($post_id))) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		if (! current_user_can('edit_post', $post_id)) {
			wp_send_json_error(array('message' => __('You do not have permission to modify this post.', 'wp-queuepress')), 403);
		}

		$settings = get_option(Buffer_Page::OPTION_SETTINGS, array());
		$token    = ! empty($settings['access_token']) ? (string) $settings['access_token'] : '';
		if ($token === '') {
			wp_send_json_error(array('message' => __('Buffer access token is not configured.', 'wp-queuepress')));
		}

		$stored = get_post_meta($post_id, self::META_KEY, true);
		if (! is_array($stored) || empty($stored)) {
			wp_send_json_success(array(
				'results' => array(),
				'message' => __('No Buffer publications to delete.', 'wp-queuepress'),
			));
		}

		$client  = new Buffer_Client($token);
		$results = array();
		foreach ($stored as $channel_id => $entry) {
			if (! is_array($entry)) { continue; }
			$buffer_post_id = (string) ($entry['post_id'] ?? '');
			$service        = (string) ($entry['service'] ?? '');
			if ($buffer_post_id === '') {
				continue;
			}
			$res = $client->delete_post($buffer_post_id);
			$res['channel_id'] = (string) $channel_id;
			$res['service']    = $service;
			$results[(string) $channel_id] = $res;
		}

		// Clear the local record regardless of individual outcomes. The user
		// asked to remove the association; downstream the strip resets visually.
		delete_post_meta($post_id, self::META_KEY);

		$messages = array();
		foreach ($results as $cid => $r) {
			$svc = isset($r['service']) ? ucfirst((string) $r['service']) : (string) $cid;
			$messages[] = $svc . ': ' . (! empty($r['success']) ? 'OK' : ($r['message'] ?? 'Error'));
		}
		$message = ! empty($messages) ? implode(' · ', $messages) : __('Done.', 'wp-queuepress');

		wp_send_json_success(array(
			'results' => $results,
			'message' => $message,
		));
	}

	// -------------------------------------------------------------------------
	// Core publishing helper
	// -------------------------------------------------------------------------

	/**
	 * Publishes a post to the given list of service slugs.
	 *
	 * Iterates every channel in the config, filters by provider/enabled and
	 * service whitelist, and dispatches to the publisher class obtained from
	 * the Platform_Registry. Persists per-channel results when Buffer returned
	 * a post_id (so failed attempts do not overwrite existing records).
	 *
	 * @param int      $post_id       WordPress post ID.
	 * @param string[] $service_slugs Service slugs to publish to.
	 * @return array<string, array<string, mixed>> Map of channel_id => result.
	 *         The special key '__error' is set if the Buffer token is missing.
	 */
	private function publish_to_services(int $post_id, array $service_slugs): array {
		$settings = get_option(Buffer_Page::OPTION_SETTINGS, array());
		$token    = ! empty($settings['access_token']) ? (string) $settings['access_token'] : '';
		if ($token === '') {
			return array('__error' => __('Buffer access token is not configured.', 'wp-queuepress'));
		}

		$client       = new Buffer_Client($token);
		$config       = new Channel_Config();
		$all_channels = $config->get_all();
		$allowed      = array_flip(array_map('strval', $service_slugs));
		$results      = array();

		foreach ($all_channels as $channel_id => $channel_cfg) {
			if (! is_array($channel_cfg)) { continue; }
			if (($channel_cfg['provider'] ?? '') !== 'buffer') { continue; }
			if (empty($channel_cfg['enabled'])) { continue; }

			$service = (string) ($channel_cfg['service'] ?? '');
			if ($service === '' || ! isset($allowed[$service])) { continue; }

			$publisher_class = Platform_Registry::publisher_class($service);
			if ($publisher_class === null) {
				$results[(string) $channel_id] = array(
					'success'    => false,
					'message'    => __('Unsupported channel service.', 'wp-queuepress'),
					'channel_id' => (string) $channel_id,
					'service'    => $service,
				);
				continue;
			}

			/** @var object $publisher */
			$publisher = new $publisher_class($client, $config);
			$res = $publisher->publish_to_channel($post_id, (string) $channel_id);

			// Persist only when Buffer returned a usable post_id, so we never
			// overwrite a previous successful record with a failed attempt.
			if (isset($res['channel_id']) && ! empty($res['channel_id']) && ! empty($res['post_id'])) {
				$this->save_channel_record($post_id, $res);
			}

			$results[(string) $channel_id] = $res;
		}

		return $results;
	}

	/**
	 * Builds and sends the standard success JSON response for a publish batch.
	 *
	 * @param array<string, array<string, mixed>> $results
	 * @return void
	 */
	private function send_results_response(array $results): void {
		$messages = array();
		foreach ($results as $cid => $r) {
			$svc = isset($r['service']) ? ucfirst((string) $r['service']) : (string) $cid;
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

	/**
	 * Validates the post status for a publish action.
	 *
	 * Returns the status string on success, or a WP_Error describing why
	 * the post cannot be published to Buffer.
	 *
	 * @param int $post_id
	 * @return string|\WP_Error
	 */
	private function get_post_status_or_error(int $post_id) {
		$post = get_post($post_id);
		if (! ($post instanceof \WP_Post)) {
			return new \WP_Error('qps_post_not_found', __('Post not found.', 'wp-queuepress'));
		}
		if (! in_array($post->post_status, self::PUBLISHABLE_STATUSES, true)) {
			return new \WP_Error(
				'qps_post_not_publishable',
				__('Only published or scheduled posts can be sent to Buffer.', 'wp-queuepress')
			);
		}
		return $post->post_status;
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

		// Keyed by channel_id — the primary key. Do not key by service.
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
