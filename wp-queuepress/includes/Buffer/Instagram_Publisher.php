<?php
/**
 * Instagram publisher.
 *
 * Responsible for building and executing a single Instagram publication
 * to Buffer. This class is the only place where Instagram-specific
 * publishing logic lives.
 *
 * Responsibilities:
 *   - Select the active Instagram channel from Channel_Config.
 *   - Build the caption (excerpt + full post + permalink + hashtags,
 *     capped at the Instagram character limit).
 *   - Build the asset list (NSFW → featured only, otherwise → featured + gallery).
 *   - Build the GraphQL createPost mutation.
 *   - Execute the mutation via Buffer_Client.
 *   - Return a normalized result array.
 *
 * This class does NOT:
 *   - Register hooks.
 *   - Render HTML.
 *   - Save post meta.
 *   - Know about the WordPress admin.
 *
 * Content & image source rules (fixed system rules, not user preferences):
 *   - Content: excerpt + full_post (combined and capped at the platform limit).
 *   - Images:  NSFW → featured only; otherwise → featured + gallery.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Builds and sends an Instagram publication to Buffer.
 */
final class Instagram_Publisher {

	/**
	 * Instagram platform character limit.
	 * Source: Channel_Config::limits_for('instagram')['character_limit']['value']
	 */
	private const CHARACTER_LIMIT = 2196;

	/**
	 * Instagram platform maximum images.
	 * Source: Channel_Config::limits_for('instagram')['max_images']['value']
	 */
	private const MAX_IMAGES = 10;

	/**
	 * Hashtag always guaranteed to be present in the caption.
	 */
	private const REQUIRED_HASHTAG = 'BunnyChase';

	/**
	 * Minimum number of hashtags to include in the caption.
	 */
	private const MIN_HASHTAGS = 5;

	/**
	 * Buffer GraphQL client.
	 *
	 * @var Buffer_Client
	 */
	private Buffer_Client $client;

	/**
	 * Channel configuration repository.
	 *
	 * @var Channel_Config
	 */
	private Channel_Config $config;

	/**
	 * Constructs the publisher.
	 *
	 * @param Buffer_Client  $client Buffer GraphQL client.
	 * @param Channel_Config $config Channel configuration repository.
	 */
	public function __construct(Buffer_Client $client, Channel_Config $config) {
		$this->client = $client;
		$this->config = $config;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Publishes a WordPress post to Instagram via Buffer.
	 *
	 * Orchestrates the full flow: channel selection, asset building,
	 * caption building, mutation execution, response normalization.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array{
	 *   success: bool,
	 *   post_id?: string,
	 *   status?: string,
	 *   channel_id?: string,
	 *   message?: string
	 * }
	 */
	public function publish(int $post_id): array {
		// 1. Select channel.
		$channel = $this->get_instagram_channel();
		if (null === $channel) {
			return array(
				'success' => false,
				'message' => __('No Instagram channel enabled. Please configure one in Buffer settings.', 'wp-queuepress'),
			);
		}

		$channel_id = $channel['channel_id'];
		$cfg        = $this->config->get($channel_id, 'instagram');

		// 2. Get post.
		$post = get_post($post_id);
		if (! ($post instanceof WP_Post)) {
			return array(
				'success' => false,
				'message' => __('Post not found.', 'wp-queuepress'),
			);
		}

		// 3. Determine NSFW.
		$is_nsfw = Publisher_Commons::is_nsfw($post);

		// 4. Build assets — must have at least one image.
		//    System rule: NSFW → featured only, otherwise featured + gallery.
		$asset_urls = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, self::MAX_IMAGES);
		if (empty($asset_urls)) {
			return array(
				'success' => false,
				'message' => __('No valid images found. Add a featured image before publishing to Instagram.', 'wp-queuepress'),
			);
		}

		// 5. Build caption (excerpt + full_post + permalink + hashtags).
		$caption = $this->build_combined_caption($post);

		// 6. Build and execute mutation.
		$mutation = $this->build_mutation($channel_id, $caption, $asset_urls);
		$response = $this->client->mutate($mutation);

		// 7. Normalize response.
		return $this->normalize_response($response, $channel_id);
	}

	/**
	 * Publishes a WordPress post to a specific Instagram channel ID.
	 *
	 * @param int $post_id
	 * @param string $channel_id
	 * @param string $share_mode Either 'addToQueue' or 'shareNow'. Only forwarded to the mutation builder.
	 * @return array
	 */
	public function publish_to_channel(int $post_id, string $channel_id, string $share_mode = 'addToQueue'): array {
		$cfg = $this->config->get($channel_id, 'instagram');

		$post = get_post($post_id);
		if (! ($post instanceof WP_Post)) {
			return array(
				'success' => false,
				'message' => __('Post not found.', 'wp-queuepress'),
			);
		}

		$is_nsfw = Publisher_Commons::is_nsfw($post);
		$asset_urls = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, self::MAX_IMAGES);
		if (empty($asset_urls)) {
			return array(
				'success' => false,
				'message' => __('No valid images found. Add a featured image before publishing to Instagram.', 'wp-queuepress'),
			);
		}

		$caption = $this->build_combined_caption($post);
		$mutation = $this->build_mutation($channel_id, $caption, $asset_urls, $share_mode);
		$response = $this->client->mutate($mutation);

		$normalized = $this->normalize_response($response, $channel_id);
		$normalized['service'] = 'instagram';
		$normalized['channel_id'] = $channel_id;

		return $normalized;
	}

	// -------------------------------------------------------------------------
	// Channel selection
	// -------------------------------------------------------------------------

	/**
	 * Returns the first enabled Instagram channel configuration.
	 *
	 * Iterates all stored channel configurations and returns the first entry
	 * where provider='buffer', service='instagram', enabled=true.
	 *
	 * @return array{channel_id: string, cfg: array<string, mixed>}|null
	 */
	private function get_instagram_channel(): ?array {
		$all = $this->config->get_all();

		foreach ($all as $channel_id => $cfg) {
			if (
				isset($cfg['provider'], $cfg['service'], $cfg['enabled']) &&
				'buffer'    === $cfg['provider'] &&
				'instagram' === $cfg['service'] &&
				true        === (bool) $cfg['enabled']
			) {
				return array(
					'channel_id' => $channel_id,
					'cfg'        => $cfg,
				);
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Caption building (system rule: excerpt + full_post + permalink + hashtags)
	// -------------------------------------------------------------------------

	/**
	 * Build the Instagram caption as: excerpt + full_post + permalink + hashtags.
	 *
	 * The combined body is then passed through Publisher_Commons::build_caption
	 * with prepend_content so the smart-truncate step operates over the
	 * combined block (preserving paragraph/word boundaries) and the resulting
	 * length is capped at the Instagram character limit.
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	private function build_combined_caption(WP_Post $post): string {
		// Build the excerpt and the full post as pure body content (no title,
		// no permalink, no appended hashtags). Hashtags inside the content are
		// preserved exactly where the author wrote them.
		$excerpt = Publisher_Commons::build_caption(
			$post,
			array(),
			PHP_INT_MAX,
			array(
				'force_source'      => 'excerpt',
				'include_title'     => false,
				'include_permalink' => false,
			)
		);

		$full_post = Publisher_Commons::build_caption(
			$post,
			array(),
			PHP_INT_MAX,
			array(
				'force_source'      => 'full_post',
				'include_title'     => false,
				'include_permalink' => false,
			)
		);

		// Decoded title for the middle slot between excerpt and full_post.
		$title = html_entity_decode((string) get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Instagram final order: excerpt + title + full_post.
		$prepend = trim((string) $excerpt) . "\n\n" . trim($title) . "\n\n" . trim((string) $full_post);

		// Final caption: include_permalink=true appends the permalink at the end.
		// No hashtags block is added; whatever the author wrote stays in place.
		return Publisher_Commons::build_caption(
			$post,
			array(),
			self::CHARACTER_LIMIT,
			array(
				'include_title'     => false,
				'include_permalink' => true,
				'margin'            => 0.10,
				'prepend_content'   => $prepend,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Mutation building
	// -------------------------------------------------------------------------

	/**
	 * Builds the createPost GraphQL mutation string.
	 *
	 * Fixed values:
	 *   mode:                          addToQueue
	 *   schedulingType:                automatic
	 *   metadata.instagram.type:       post
	 *   metadata.instagram.shouldShareToFeed: true
	 *
	 * @param string   $channel_id  Buffer channel ID.
	 * @param string   $caption     Final caption text.
	 * @param string[] $asset_urls  Ordered image URLs.
	 * @param string   $share_mode  Either 'addToQueue' or 'shareNow'. Only forwarded to the mutation builder.
	 * @return string GraphQL mutation string.
	 */
	private function build_mutation(string $channel_id, string $caption, array $asset_urls, string $share_mode = 'addToQueue'): string {
		return Mutation_Commons::build_create_post_mutation_from_image_urls($channel_id, $caption, $asset_urls, $share_mode);
	}

	// -------------------------------------------------------------------------
	// Response normalization
	// -------------------------------------------------------------------------

	/**
	 * Normalizes the raw Buffer API response into a standard result array.
	 *
	 * @param array<string, mixed> $response
	 * @param string               $channel_id
	 * @return array{success: bool, post_id?: string, status?: string, channel_id?: string, message?: string}
	 */
	private function normalize_response(array $response, string $channel_id): array {
		return Publisher_Commons::normalize_response($response, $channel_id);
	}
}
