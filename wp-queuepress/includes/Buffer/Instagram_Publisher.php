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
 *   - Build the caption (extract/clean hashtags, assemble, enforce limit).
 *   - Build the asset list (image_source, NSFW override, dedup, limit).
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
 * post_style architecture note (Sprint 3.1):
 *   $cfg is passed in full to build_caption(). Instagram does not expose
 *   post_style in its fields_for(), so $cfg['post_style'] will be absent
 *   for Instagram channels. When X/Threads publishers are added in 3.1,
 *   build_caption() can branch on $cfg['post_style'] without any signature
 *   change — the architecture already supports it.
 *
 * NSFW rule (fixed, not configurable):
 *   If the post has a tag with slug 'nsfw', image_source is forced to
 *   'featured_only' regardless of the channel configuration.
 *   See: Channel_Config docblock — NSFW rules section.
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
		$asset_urls = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, self::MAX_IMAGES);
		if (empty($asset_urls)) {
			return array(
				'success' => false,
				'message' => __('No valid images found. Add a featured image before publishing to Instagram.', 'wp-queuepress'),
			);
		}

		// 5. Build caption.
		$caption = Publisher_Commons::build_caption($post, $cfg, self::CHARACTER_LIMIT);

		// 6. Build and execute mutation.
		$mutation = $this->build_mutation($channel_id, $caption, $asset_urls);
		$response = $this->client->mutate($mutation);

		// 7. Normalize response.
		return $this->normalize_response($response, $channel_id);
	}

	/**
	 * Publishes a WordPress post to a specific Instagram channel ID.
	 *
	 * This mirrors `publish()` but explicitly targets the given channel id
	 * and returns the normalized result including the `channel_id` and
	 * `service` keys so callers can persist per-channel records.
	 *
	 * @param int $post_id
	 * @param string $channel_id
	 * @return array
	 */
	public function publish_to_channel(int $post_id, string $channel_id): array {
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

		$caption = Publisher_Commons::build_caption($post, $cfg, self::CHARACTER_LIMIT);
		$mutation = $this->build_mutation($channel_id, $caption, $asset_urls);
		$response = $this->client->mutate($mutation);

		// Normalize like publish() but include channel_id and service.
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
	 * The channel_id is the primary key — not the service.
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
	// NSFW detection
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the post has a tag with slug 'nsfw' (case-insensitive).
	 *
	 * This is a fixed platform rule, not a user preference.
	 * See NSFW rules in Channel_Config docblock.
	 *
	 * @param WP_Post $post The post to check.
	 * @return bool
	 */
	/**
	 * Builds the ordered, deduplicated list of image URLs for the publication.
	 *
	 * Rules:
	 *   - If NSFW: always use featured_only regardless of cfg['image_source'].
	 *   - featured_only: [featured_image_url]
	 *   - featured_plus_gallery: [featured, ...gallery], featured always first.
	 *   - Deduplicate by URL preserving order (first occurrence wins).
	 *   - Maximum MAX_IMAGES images.
	 *   - If no valid images remain: return empty array (caller must error out).
	 *
	 * @param WP_Post              $post    The post.
	 * @param array<string, mixed> $cfg     Channel configuration.
	 * @param bool                 $is_nsfw Whether NSFW override applies.
	 * @return string[] Ordered, deduplicated image URLs.
	 */
	/**
	 * Builds the final caption for the Instagram publication.
	 *
	 * Process:
	 *   1. Extract raw content according to content_source.
	 *   2. Strip hashtags from content body (keep word, remove '#' prefix).
	 *      Extracted hashtags are collected for reuse at the end.
	 *   3. Build the hashtag block (guarantee #BunnyChase, complement to MIN_HASHTAGS).
	 *   4. Calculate fixed space (title + permalink + hashtags + separators).
	 *   5. Truncate only the content part to fit within CHARACTER_LIMIT.
	 *   6. Assemble: title \n\n content \n\n permalink \n\n hashtags.
	 *
	 * post_style is available in $cfg for Sprint 3.1 (card_link support).
	 * In Sprint 3.0 Instagram does not expose post_style; $cfg['post_style']
	 * will be absent and this method simply builds a standard social post.
	 *
	 * @param WP_Post              $post The post.
	 * @param array<string, mixed> $cfg  Channel configuration.
	 * @return string Final caption, never exceeding CHARACTER_LIMIT chars.
	 */
	/**
	 * Extracts hashtags from raw text and removes their '#' prefix in the body.
	 *
	 * For each '#word' found:
	 *   - The word is stored in the extracted collection.
	 *   - The '#' is removed from the text; the word remains in place.
	 *
	 * Example:
	 *   Input:  "Review of #Ghostbusters and #Gozer"
	 *   Output: ["Review of Ghostbusters and Gozer", ["Ghostbusters", "Gozer"]]
	 *
	 * @param string $text Raw content text.
	 * @return array{0: string, 1: string[]} [cleaned_text, extracted_hashtag_words]
	 */
	/**
	 * Builds the final hashtag block string.
	 *
	 * Rules (in order):
	 *   1. Start from extracted hashtags (deduplicated, case-preserved).
	 *   2. Guarantee REQUIRED_HASHTAG is present (case-insensitive check).
	 *   3. If total < MIN_HASHTAGS: complement with WordPress post tags.
	 *      WP tags are normalized: lowercased, spaces removed.
	 *      Already-present hashtags (case-insensitive) are not duplicated.
	 *   4. Never remove hashtags already in the extracted collection.
	 *
	 * @param WP_Post  $post              The post.
	 * @param string[] $extracted_hashtags Words (without '#') extracted from content.
	 * @return string Hashtag block, e.g. "#Ghostbusters #Gozer #BunnyChase"
	 */
	// -------------------------------------------------------------------------
	// Mutation building
	// -------------------------------------------------------------------------

	/**
	 * Builds the createPost GraphQL mutation string.
	 *
	 * Uses the exact format validated in n8n. Fixed values for Sprint 3.0:
	 *   mode:                          addToQueue
	 *   schedulingType:                automatic
	 *   metadata.instagram.type:       post
	 *   metadata.instagram.shouldShareToFeed: true
	 *
	 * @param string   $channel_id  Buffer channel ID.
	 * @param string   $caption     Final caption text.
	 * @param string[] $asset_urls  Ordered image URLs.
	 * @return string GraphQL mutation string.
	 */
	private function build_mutation(string $channel_id, string $caption, array $asset_urls): string {
		// Delegate image-based mutation construction to shared commons.
		return Mutation_Commons::build_create_post_mutation_from_image_urls($channel_id, $caption, $asset_urls);
	}

	// -------------------------------------------------------------------------
	// Response normalization
	// -------------------------------------------------------------------------

	/**
	 * Normalizes the raw Buffer API response into a standard result array.
	 *
	 * PostActionSuccess → success=true with post_id, status, channel_id.
	 * MutationError     → success=false with the exact Buffer message, unmodified.
	 * Unexpected        → success=false with a generic message.
	 *
	 * @param array<string, mixed> $response   Full decoded response from Buffer_Client::mutate().
	 * @param string               $channel_id The channel ID used for the publication.
	 * @return array{success: bool, post_id?: string, status?: string, channel_id?: string, message?: string}
	 */
	private function normalize_response(array $response, string $channel_id): array {
		return Publisher_Commons::normalize_response($response, $channel_id);
	}
}
