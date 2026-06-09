<?php
/**
 * Threads publisher.
 *
 * Builds and executes a Threads createPost mutation via Buffer.
 *
 * Behavior (system rules, not user preferences):
 *   - post_style = 'social_post' →
 *       caption = excerpt + permalink + hashtags (first element of the thread)
 *       thread  = [intro, ...chunks_of_full_post]
 *                 chunks have NO permalink, NO hashtags, NO title.
 *       text top-level = intro (same as first element of the thread).
 *       images: featured + gallery (SFW only).
 *   - post_style = 'card_link' →
 *       caption = excerpt (no permalink, since the link asset carries the URL)
 *       assets  = [link asset] (link carries its own thumbnail).
 *       images: featured only (the card carries the thumbnail).
 *
 * NSFW (system rule, not configurable):
 *   - If NSFW, social_post is forced to card_link.
 *   - Card Link images are effectively featured-only (the link asset is the
 *     only image that reaches Buffer; no top-level gallery is attached).
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
     * Low-level publish wrapper. Used by callers that already have assets
     * and a caption built (e.g. a custom orchestration flow).
     *
     * @param string $channel_id
     * @param string $caption
     * @param array $assets
     * @param array $meta
     * @return array
     */
    public function publish(string $channel_id, string $caption, array $assets, array $meta = []): array {
        $detected_post_style = (string) ($meta['detected_post_style'] ?? '');
        if ($detected_post_style !== 'card_link') {
            $featured = null;
            if (empty($assets)) {
                $post_id = isset($meta['post_id']) ? (int) $meta['post_id'] : 0;
                if ($post_id > 0) { $featured = get_the_post_thumbnail_url($post_id, 'full'); }
            } else {
                $has_featured = false;
                foreach ($assets as $a) {
                    if (is_string($a) && strpos($a, 'http') === 0) { $has_featured = true; break; }
                    if (is_array($a) && ! empty($a['image']) && ! empty($a['image']['url'])) { $has_featured = true; break; }
                }
                if (! $has_featured) {
                    $post_id = isset($meta['post_id']) ? (int) $meta['post_id'] : 0;
                    if ($post_id > 0) { $featured = get_the_post_thumbnail_url($post_id, 'full'); }
                }
            }
            if (! empty($featured)) { array_unshift($assets, $featured); }
        }

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

        $service_limits = $this->config->limits_for('threads');

        $limit_entry = $service_limits['character_limit'] ?? array('value' => 500);
        $limit = is_array($limit_entry) && isset($limit_entry['value']) ? (int) $limit_entry['value'] : (int) $limit_entry;

        $post_style_cfg = isset($cfg['post_style']) ? (string) $cfg['post_style'] : '';

        $is_nsfw = Publisher_Commons::is_nsfw($post);

        // System rule: Threads NSFW forces social_post → card_link.
        $effective_post_style = $post_style_cfg;
        if ($is_nsfw && 'social_post' === $post_style_cfg) {
            $effective_post_style = 'card_link';
        }

        // Compute the link asset. Pass an overridden cfg to ensure
        // build_link_asset_from_post sees card_link when forced.
        $cfg_for_link = $cfg;
        $cfg_for_link['post_style'] = $effective_post_style;
        $channel_info = array_merge($cfg, array('service' => 'threads'));
        $link_asset = Mutation_Commons::build_link_asset_from_post($post, $cfg_for_link, $channel_info);

        if ('card_link' === $effective_post_style) {
            $mutation = $this->build_card_link_mutation($post, $cfg, $channel_id, $limit, $link_asset);
        } else {
            $mutation = $this->build_social_post_mutation($post, $cfg, $channel_id, $limit, $is_nsfw, $service_limits, $link_asset);
        }

        $response = $this->client->mutate($mutation);
        $normalized = Publisher_Commons::normalize_response($response, $channel_id);
        $normalized['service'] = 'threads';
        $normalized['channel_id'] = $channel_id;

        return $normalized;
    }

    /**
     * Build a Card Link mutation: excerpt body + link asset.
     *
     * The link asset carries its own thumbnail, so no extra top-level images
     * are attached (Card Link → featured only).
     *
     * @return string
     */
    private function build_card_link_mutation(WP_Post $post, array $cfg, string $channel_id, int $limit, ?array $link_asset): string {
        $caption = Publisher_Commons::build_caption(
            $post,
            $cfg,
            $limit,
            array(
                'force_source'      => 'excerpt',
                'include_permalink' => false,
                'post_style'        => 'card_link',
            )
        );

        if ($link_asset !== null) {
            $assets = array($link_asset);
            return Mutation_Commons::build_create_post_mutation(
                $channel_id,
                $caption,
                $assets,
                array('detected_post_style' => 'card_link'),
                'threads'
            );
        }

        // Fallback: no link asset (missing title/thumbnail); send featured as image.
        $featured = get_the_post_thumbnail_url($post->ID, 'full');
        $assets = array();
        if (! empty($featured)) { $assets[] = $featured; }

        return Mutation_Commons::build_create_post_mutation(
            $channel_id,
            $caption,
            $assets,
            array(),
            'threads'
        );
    }

    /**
     * Build a Social Post mutation with the new thread model:
     *   element 0 = excerpt + permalink + hashtags
     *   element 1..N = full_post chunks (no permalink, no hashtags, no title)
     *
     * If the full_post body fits in the limit, the thread is a single element.
     *
     * @return string
     */
    private function build_social_post_mutation(WP_Post $post, array $cfg, string $channel_id, int $limit, bool $is_nsfw, array $service_limits, ?array $link_asset): string {
        // 1. Intro element: excerpt + permalink. No title. No appended hashtags.
        $intro = Publisher_Commons::build_caption(
            $post,
            $cfg,
            $limit,
            array(
                'force_source'      => 'excerpt',
                'include_title'     => false,
                'include_permalink' => true,
                'margin'            => 0.10,
            )
        );

        // 2. Body: full_post pure content (no title, no permalink).
        // Prepend the decoded title to the body text BEFORE chunking so
        // split_caption_into_chunks has visibility of the full space required
        // and the first chunk never exceeds the limit after the title is added.
        $full_post_text = Publisher_Commons::build_caption(
            $post,
            $cfg,
            PHP_INT_MAX,
            array(
                'force_source'      => 'full_post',
                'include_title'     => false,
                'include_permalink' => false,
            )
        );

        $title = html_entity_decode((string) get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $full_post_with_title = trim($title) . "\n\n" . $full_post_text;

        $effective_limit = max(1, (int) floor($limit * (1 - 0.10)));
        $body_chunks = Publisher_Commons::split_caption_into_chunks($full_post_with_title, $effective_limit);

        // 3. Assemble thread.
        $thread_texts = array_merge(array($intro), $body_chunks);

        // 4. Guardia final: re-split any element that still exceeds the limit.
        $validated = array();
        foreach ($thread_texts as $element) {
            if (mb_strlen($element, 'UTF-8') <= $effective_limit) {
                $validated[] = $element;
            } else {
                foreach (Publisher_Commons::split_caption_into_chunks($element, $effective_limit) as $sub) {
                    $validated[] = $sub;
                }
            }
        }
        $thread_texts = $validated;

        // 4. Images. Threads NSFW is already forced to card_link before this point,
        //    so we never reach this branch in NSFW. Default allow_gallery_on_nsfw=false
        //    keeps the rule future-proof if a caller ever lets NSFW slip through.
        $max_images_entry = $service_limits['max_images'] ?? array('value' => 20);
        $max_images_value = is_array($max_images_entry) && isset($max_images_entry['value']) ? (int) $max_images_entry['value'] : (int) $max_images_entry;
        $max_total_images = max(1, $max_images_value * 25);
        $images = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, $max_total_images);

        $max_per_element = isset($service_limits['images_per_element'])
            ? (int) $service_limits['images_per_element']
            : (isset($service_limits['images_per_post']) ? (int) $service_limits['images_per_post'] : 10);

        // 5. Distribute images.
        $distributed = Publisher_Commons::distribute_images_across_chunks($images, count($thread_texts), $max_per_element);

        // 6. Build the thread payload.
        $thread_payload = array();
        foreach ($thread_texts as $i => $text) {
            $assets_for_element = array();
            foreach ($distributed[$i] ?? array() as $url) {
                $assets_for_element[] = array('image' => array('url' => $url));
            }
            $thread_payload[] = array('text' => $text, 'assets' => $assets_for_element);
        }

        // 7. Top-level text is the intro.
        $meta = array(
            'detected_post_style' => 'social_post',
            'thread'              => $thread_payload,
        );

        // 8. Top-level assets = featured image.
        $top_assets = array();
        $featured = get_the_post_thumbnail_url($post->ID, 'full');
        if (! empty($featured)) { $top_assets[] = $featured; }

        return Mutation_Commons::build_create_post_mutation($channel_id, $intro, $top_assets, $meta, 'threads');
    }
}
