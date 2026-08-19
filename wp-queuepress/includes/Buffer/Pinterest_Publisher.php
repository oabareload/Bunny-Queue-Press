<?php
/**
 * Pinterest publisher.
 *
 * Builds and executes a Pinterest createPost mutation via Buffer.
 *
 * Behavior:
 *   - Publishes the post using its configured Pinterest board.
 *   - Uses the post title as the Pin title.
 *   - Uses the post permalink as the Pin destination URL.
 *   - Images are built using the common publisher asset builder.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
	exit;
}

final class Pinterest_Publisher {

	private Buffer_Client $client;
	private Channel_Config $config;

	public function __construct(Buffer_Client $client, Channel_Config $config) {
		$this->client = $client;
		$this->config = $config;
	}

	/**
	 * Low-level publish wrapper.
	 *
	 * @param string $channel_id
	 * @param string $caption
	 * @param array  $assets
	 * @param array  $meta
	 * @return array
	 */
	public function publish(string $channel_id, string $caption, array $assets, array $meta = []): array {
		$featured = null;

		if (empty($assets)) {
			$post_id = isset($meta['post_id']) ? (int) $meta['post_id'] : 0;

			if ($post_id > 0) {
				$featured = get_the_post_thumbnail_url($post_id, 'full');
			}
		} else {
			$has_featured = false;

			foreach ($assets as $asset) {
				if (is_string($asset) && strpos($asset, 'http') === 0) {
					$has_featured = true;
					break;
				}

				if (
					is_array($asset)
					&& ! empty($asset['image'])
					&& ! empty($asset['image']['url'])
				) {
					$has_featured = true;
					break;
				}
			}

			if (! $has_featured) {
				$post_id = isset($meta['post_id']) ? (int) $meta['post_id'] : 0;

				if ($post_id > 0) {
					$featured = get_the_post_thumbnail_url($post_id, 'full');
				}
			}
		}

		if (! empty($featured)) {
			array_unshift($assets, $featured);
		}

		$mutation = Mutation_Commons::build_create_post_mutation(
			$channel_id,
			$caption,
			$assets,
			$meta,
			'pinterest'
		);

		$response = $this->client->mutate($mutation);

		$create_post = $response['data']['createPost'] ?? null;

		if (isset($create_post['post']['id'])) {
			return array(
				'success'    => true,
				'post_id'    => (string) $create_post['post']['id'],
				'status'     => (string) ($create_post['post']['status'] ?? ''),
				'channel_id' => $channel_id,
			);
		}

		if (isset($create_post['message'])) {
			return array(
				'success' => false,
				'message' => (string) $create_post['message'],
			);
		}

		return array(
			'success' => false,
			'message' => __('Unexpected response from Buffer. Please try again.', 'wp-queuepress'),
		);
	}

	/**
	 * Publish a post to a specific Pinterest channel.
	 *
	 * @param int    $post_id
	 * @param string $channel_id
	 * @param string $share_mode Either 'addToQueue' or 'shareNow'. Only forwarded to the mutation builder.
	 * @return array
	 */
	public function publish_to_channel(int $post_id, string $channel_id, string $share_mode = 'addToQueue'): array {
		$cfg = $this->config->get($channel_id, 'pinterest');

		$post = get_post($post_id);

		if (! ($post instanceof WP_Post)) {
			return array(
				'success' => false,
				'message' => __('Post not found.', 'wp-queuepress'),
			);
		}

		$board_service_id = isset($cfg['board_service_id'])
			? trim((string) $cfg['board_service_id'])
			: '';

		if ('' === $board_service_id) {
			return array(
				'success' => false,
				'message' => __('Pinterest board is not configured for this channel.', 'wp-queuepress'),
			);
		}

		$featured_image = get_the_post_thumbnail_url($post->ID, 'full');
		if (empty($featured_image)) {
			return array(
				'success' => false,
				'message' => __('Pinterest posts require a featured image.', 'wp-queuepress'),
			);
		}

		$service_limits = $this->config->limits_for('pinterest');

		$limit_entry = $service_limits['character_limit'] ?? array('value' => 500);

		$limit = is_array($limit_entry) && isset($limit_entry['value'])
			? (int) $limit_entry['value']
			: (int) $limit_entry;

		$mutation = $this->build_social_post_mutation(
			$post,
			$cfg,
			$channel_id,
			$limit,
			$service_limits,
			$board_service_id,
			$featured_image,
			$share_mode
		);

		$response = $this->client->mutate($mutation);

		$normalized = Publisher_Commons::normalize_response(
			$response,
			$channel_id
		);

		$normalized['service']    = 'pinterest';
		$normalized['channel_id'] = $channel_id;

		return $normalized;
	}

	/**
	 * Build a Pinterest Pin mutation.
	 *
	 * Pinterest uses:
	 *   - boardServiceId: configured Pinterest board.
	 *   - title: WordPress post title.
	 *   - url: WordPress post permalink.
	 *
	 * @param WP_Post $post
	 * @param array   $cfg
	 * @param string  $channel_id
	 * @param int     $limit
	 * @param array   $service_limits
	 * @param string  $board_service_id
	 * @return string
	 */
	private function build_social_post_mutation(
		WP_Post $post,
		array $cfg,
		string $channel_id,
		int $limit,
		array $service_limits,
		string $board_service_id,
		string $featured_image,
		string $share_mode = 'addToQueue'
	): string {
		$caption = Publisher_Commons::build_caption(
			$post,
			$cfg,
			$limit,
			array(
				'force_source'      => 'excerpt',
				'include_permalink' => false,
				'margin'            => 0.10,
			)
		);

		$images = array(
			array(
				'image' => array(
					'url' => $featured_image,
				),
			),
		);

		$title = html_entity_decode(
			(string) get_the_title($post),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		$url = Publisher_Commons::get_pretty_post_url($post);

		$meta = array(
			'detected_post_style' => 'social_post',
			'board_service_id'    => $board_service_id,
			'title'               => $title,
			'url'                 => $url,
		);

		return Mutation_Commons::build_create_post_mutation(
			$channel_id,
			$caption,
			$images,
			$meta,
			'pinterest',
			$share_mode
		);
	}
}