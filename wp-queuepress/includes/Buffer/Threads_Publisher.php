<?php
/**
 * Threads publisher wrapper that uses commons to build mutations.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Threads_Publisher {

    private Buffer_Client $client;
    private Channel_Config $config;

    public function __construct(Buffer_Client $client, Channel_Config $config) {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * Build and execute a Threads createPost mutation. $assets may contain
     * image URLs or prebuilt ['link'=>...] objects from Commons::build_link_asset_from_post().
     *
     * @param string $channel_id
     * @param string $caption
     * @param array $assets
     * @param array $meta
     * @return array
     */
    public function publish(string $channel_id, string $caption, array $assets, array $meta = []): array {
        $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, $meta, 'threads');
        $response = $this->client->mutate($mutation);

        $create_post = $response['data']['createPost'] ?? null;
        if (isset($create_post['post']['id'])) {
            return array(
                'success' => true,
                'post_id' => (string) $create_post['post']['id'],
                'status'  => (string) ($create_post['post']['status'] ?? ''),
                'channel_id' => $channel_id,
            );
        }
        if (isset($create_post['message'])) {
            return array('success' => false, 'message' => (string) $create_post['message']);
        }
        return array('success' => false, 'message' => __('Unexpected response from Buffer. Please try again.', 'wp-queuepress'));
    }

    /**
     * Publish a post to a specific Threads channel.
     *
     * @param int $post_id
     * @param string $channel_id
     * @return array
     */
    public function publish_to_channel(int $post_id, string $channel_id): array {
        $cfg = $this->config->get($channel_id, 'threads');

        $post = get_post($post_id);
        if (! ($post instanceof WP_Post)) {
            return array('success' => false, 'message' => __('Post not found.', 'wp-queuepress'));
        }

        $limit_entry = $this->config->limits_for('threads')['character_limit'] ?? array('value' => 500);
        $limit = is_array($limit_entry) && isset($limit_entry['value']) ? (int) $limit_entry['value'] : (int) $limit_entry;

        $post_style_cfg = isset($cfg['post_style']) ? (string) $cfg['post_style'] : '';

        $is_nsfw = Publisher_Commons::is_nsfw($post);

        // If Threads and NSFW, force card_link regardless of configured post_style.
        $effective_post_style = $post_style_cfg;
        if ($is_nsfw && $post_style_cfg === 'social_post') {
            $effective_post_style = 'card_link';
        }

        // Determine link asset (may be null). Pass an overridden cfg to ensure
        // build_link_asset_from_post sees card_link when forced.
        $cfg_for_link = $cfg;
        $cfg_for_link['post_style'] = $effective_post_style;
        $link_asset = Mutation_Commons::build_link_asset_from_post($post, $cfg_for_link, array_merge($cfg, array('service' => 'threads')));

        if ($effective_post_style === 'card_link') {
            $options = array('force_source' => 'excerpt', 'include_permalink' => false, 'post_style' => 'card_link');
            $caption = Publisher_Commons::build_caption($post, $cfg, $limit, $options);

            if ($link_asset !== null) {
                $assets = array($link_asset);
                $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, array('detected_post_style' => $post_style_cfg), 'threads');
            } else {
                $featured = get_the_post_thumbnail_url($post->ID, 'full');
                $assets = array(); if (! empty($featured)) { $assets[] = $featured; }
                $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, array(), 'threads');
            }
        } else {
            // social_post flow: build full caption first
            $caption_options = array('include_permalink' => true);
            $full_caption = Publisher_Commons::build_caption($post, $cfg, PHP_INT_MAX, $caption_options);

            // Gather images (Threads should filter NSFW via build_assets).
            // Compute generous upper bound: max_images * 25 (hard fallback of 25 thread posts).
            $service_limits = $this->config->limits_for('threads');
            $max_images_entry = $service_limits['max_images'] ?? array('value' => 20);
            $max_images_value = is_array($max_images_entry) && isset($max_images_entry['value']) ? (int) $max_images_entry['value'] : (int) $max_images_entry;
            $max_total_images = max(1, $max_images_value * 25);
            $images = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, $max_total_images);

            $service_limits = $this->config->limits_for('threads');
            $max_per_element = isset($service_limits['images_per_element']) ? (int) $service_limits['images_per_element'] : (isset($service_limits['images_per_post']) ? (int) $service_limits['images_per_post'] : 10);

            $thread_payload = Publisher_Commons::build_thread_payload($post, $cfg, 'threads', array(
                'caption' => $full_caption,
                'images' => $images,
                'limit' => $limit,
                'max_per_element' => $max_per_element,
                'nsfw' => $is_nsfw,
            ));

            if (is_array($thread_payload) && ! empty($thread_payload)) {
                $first = $thread_payload[0]['text'] ?? '';
                $meta = array('detected_post_style' => 'social_post', 'thread' => $thread_payload);
                $assets = array();
                $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $first, $assets, $meta, 'threads');
            } else {
                // Fallback single post behavior
                $caption = Publisher_Commons::build_caption($post, $cfg, $limit, array('include_permalink' => true));
                if ($link_asset !== null) {
                    $assets = array($link_asset);
                    $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, array('detected_post_style' => $post_style_cfg), 'threads');
                } else {
                    $featured = get_the_post_thumbnail_url($post->ID, 'full');
                    $assets = array(); if (! empty($featured)) { $assets[] = $featured; }
                    $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, array(), 'threads');
                }
            }
        }

        $response = $this->client->mutate($mutation);
        $normalized = Publisher_Commons::normalize_response($response, $channel_id);
        $normalized['service'] = 'threads';
        $normalized['channel_id'] = $channel_id;

        return $normalized;
    }

}
