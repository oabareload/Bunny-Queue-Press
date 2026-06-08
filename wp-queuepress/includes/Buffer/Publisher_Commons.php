<?php
/**
 * Publisher common helpers.
 *
 * Shared utilities used by service-specific publishers to prepare captions,
 * assets and normalize Buffer responses.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Publisher_Commons {

    public static function is_nsfw(WP_Post $post): bool {
        $tags = get_the_tags($post->ID);
        if (empty($tags) || ! is_array($tags)) {
            return false;
        }
        foreach ($tags as $tag) {
            if ('nsfw' === strtolower((string) $tag->slug)) {
                return true;
            }
        }
        return false;
    }

    public static function build_assets(WP_Post $post, array $cfg, bool $is_nsfw, int $max_images): array {
        $effective_source = $is_nsfw ? 'featured_only' : (string) ($cfg['image_source'] ?? 'featured_only');

        $urls = array();
        $featured = get_the_post_thumbnail_url($post->ID, 'full');
        if (! empty($featured)) {
            $urls[] = $featured;
        }

        if ('featured_plus_gallery' === $effective_source) {
            $gallery = self::get_gallery_image_urls($post);
            foreach ($gallery as $u) { $urls[] = $u; }
        }

        // Deduplicate preserving order.
        $seen = array();
        $unique = array();
        foreach ($urls as $u) {
            if (! isset($seen[$u])) {
                $seen[$u] = true;
                $unique[] = $u;
            }
        }

        return array_slice($unique, 0, $max_images);
    }

    public static function get_gallery_image_urls(WP_Post $post): array {
        $urls = array();

        if (function_exists('parse_blocks') && has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);

            $walker = static function (array $blocks) use (&$walker, &$urls) {
                foreach ($blocks as $block) {
                    $name = $block['blockName'] ?? '';

                    if ('core/gallery' === $name) {
                        $ids = $block['attrs']['ids'] ?? array();
                        if (is_string($ids)) {
                            $ids = array_filter(array_map('intval', explode(',', $ids)));
                        }
                        if (is_array($ids) && ! empty($ids)) {
                            foreach ($ids as $id) {
                                $url = wp_get_attachment_url((int) $id);
                                if ($url) { $urls[] = $url; }
                            }
                        }

                        if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                            foreach ($block['innerBlocks'] as $inner) {
                                if (($inner['blockName'] ?? '') === 'core/image') {
                                    $id = (int) ($inner['attrs']['id'] ?? 0);
                                    if ($id > 0) {
                                        $url = wp_get_attachment_url($id);
                                        if ($url) { $urls[] = $url; }
                                    }
                                    $img_url = $inner['attrs']['url'] ?? '';
                                    if (! empty($img_url) && is_string($img_url)) {
                                        $urls[] = esc_url_raw($img_url);
                                    }
                                }
                            }
                        }
                    }

                    if (strpos($name, 'wp:bunny/') === 0 || stripos($name, 'gallery') !== false) {
                        $attrs = $block['attrs'] ?? array();
                        if (! empty($attrs['imageData']) && is_array($attrs['imageData'])) {
                            foreach ($attrs['imageData'] as $img) {
                                if (is_array($img) && ! empty($img['url'])) {
                                    $urls[] = esc_url_raw($img['url']);
                                }
                            }
                        }
                        if (! empty($attrs['imageUrl']) && is_string($attrs['imageUrl'])) {
                            $urls[] = esc_url_raw($attrs['imageUrl']);
                        }
                        if (! empty($attrs['imageId']) && is_numeric($attrs['imageId'])) {
                            $id = (int) $attrs['imageId'];
                            $url = wp_get_attachment_url($id);
                            if ($url) { $urls[] = $url; }
                        }
                        if (! empty($attrs['ids']) && is_array($attrs['ids'])) {
                            foreach ($attrs['ids'] as $id) {
                                $url = wp_get_attachment_url((int) $id);
                                if ($url) { $urls[] = $url; }
                            }
                        }
                        if (! empty($attrs['images']) && is_array($attrs['images'])) {
                            foreach ($attrs['images'] as $img) {
                                if (is_array($img) && ! empty($img['url'])) {
                                    $urls[] = esc_url_raw($img['url']);
                                } elseif (is_numeric($img)) {
                                    $url = wp_get_attachment_url((int) $img);
                                    if ($url) { $urls[] = $url; }
                                } elseif (is_string($img)) {
                                    $urls[] = esc_url_raw($img);
                                }
                            }
                        }
                        if (! empty($attrs['url']) && is_string($attrs['url'])) {
                            $urls[] = esc_url_raw($attrs['url']);
                        }
                    }

                    if (($block['blockName'] ?? '') === 'core/image') {
                        $id = (int) ($block['attrs']['id'] ?? 0);
                        if ($id > 0) {
                            $url = wp_get_attachment_url($id);
                            if ($url) { $urls[] = $url; }
                        }
                        $img_url = $block['attrs']['url'] ?? '';
                        if (! empty($img_url) && is_string($img_url)) {
                            $urls[] = esc_url_raw($img_url);
                        }
                    }

                    if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                        $walker($block['innerBlocks']);
                    }
                }
            };

            $walker($blocks);

            // Deduplicate preserving order
            $seen = array();
            $unique = array();
            foreach ($urls as $u) {
                if (! isset($seen[$u])) { $seen[$u] = true; $unique[] = $u; }
            }

            return $unique;
        }

        // Fallback: gallery shortcode
        if (preg_match('/\[gallery[^\]]*ids=["\']?([\d,]+)["\']?/i', $post->post_content, $matches)) {
            $ids = array_filter(array_map('intval', explode(',', $matches[1])));
            foreach ($ids as $id) {
                $url = wp_get_attachment_url($id);
                if ($url) { $urls[] = $url; }
            }
        }

        return $urls;
    }

    /**
     * Build a caption honoring channel config and options.
     *
     * Options:
     *  - 'include_permalink' => bool (default true)
     *  - 'force_source' => 'excerpt'|'full_post'|null (default null)
     *  - 'post_style' => string|null
     *
     * @param WP_Post $post
     * @param array<string,mixed> $cfg
     * @param int $character_limit
     * @param array<string,mixed> $options
     * @return string
     */
    public static function build_caption(WP_Post $post, array $cfg, int $character_limit, array $options = array()): string {
        $include_permalink = isset($options['include_permalink']) ? (bool) $options['include_permalink'] : true;
        $force_source = $options['force_source'] ?? null;
        $post_style = $options['post_style'] ?? null;

        // Determine content source: forced option wins, otherwise channel config.
        $content_source = null;
        if (in_array($force_source, array('excerpt', 'full_post'), true)) {
            $content_source = $force_source;
        } else {
            $content_source = (string) ($cfg['content_source'] ?? 'excerpt');
        }

        $title = get_the_title($post);
        $permalink = (string) get_permalink($post);

        $raw_content = '';
        if (function_exists('parse_blocks') && has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);
            $parts = array();
            $walker = static function (array $blocks) use (&$walker, &$parts) {
                foreach ($blocks as $block) {
                    $name = $block['blockName'] ?? '';
                    if ('core/gallery' === $name || stripos($name, 'gallery') !== false) {
                        if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) { $walker($block['innerBlocks']); }
                        continue;
                    }
                    $inner = $block['innerHTML'] ?? '';
                    if (! empty($inner) && is_string($inner)) {
                        $text = trim(wp_strip_all_tags($inner));
                        if ($text !== '') { $parts[] = $text; }
                    }
                    $attrs = $block['attrs'] ?? array();
                    if (! empty($attrs['title']) && is_string($attrs['title'])) {
                        $tt = trim(wp_strip_all_tags($attrs['title']));
                        if ($tt !== '') { $parts[] = $tt; }
                    }
                    if (! empty($attrs['content'])) {
                        if (is_string($attrs['content'])) {
                            $ct = trim(wp_strip_all_tags($attrs['content']));
                            if ($ct !== '') { $parts[] = $ct; }
                        } elseif (is_array($attrs['content'])) {
                            $extract = null;
                            $extract = static function ($value) use (&$parts, &$extract) {
                                if (is_string($value)) {
                                    $text = trim(wp_strip_all_tags($value));
                                    if ($text !== '') { $parts[] = $text; }
                                    return;
                                }
                                if (is_array($value)) { foreach ($value as $v) { $extract($v); } }
                            };
                            $extract($attrs['content']);
                        }
                    }
                    if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                        $walker($block['innerBlocks']);
                    }
                }
            };
            $walker($blocks);
            $raw_content = implode("\n\n", $parts);
        } else {
            if ($content_source === 'full_post') {
                $raw_content = wp_strip_all_tags($post->post_content);
            } elseif (! empty($post->post_excerpt)) {
                $raw_content = (string) $post->post_excerpt;
            } else {
                $raw_content = wp_strip_all_tags($post->post_content);
            }
        }

        $raw_content = trim((string) $raw_content);

        list($clean_content, $extracted_hashtags) = self::extract_and_clean_hashtags($raw_content);
        $hashtag_block = self::build_hashtags($post, $extracted_hashtags, 'BunnyChase', 5);

        // Calculate fixed length: title + (maybe permalink) + hashtags + separators
        $fixed_length = mb_strlen($title, 'UTF-8') + mb_strlen($hashtag_block, 'UTF-8') + 4; // base separators
        if ($include_permalink) {
            $fixed_length += mb_strlen($permalink, 'UTF-8') + 2; // if permalink included add separator
        }
        $available = $character_limit - $fixed_length;

        if ($available <= 0) { $content_part = ''; }
        elseif (mb_strlen($clean_content, 'UTF-8') > $available) { $content_part = mb_substr($clean_content, 0, $available, 'UTF-8'); }
        else { $content_part = $clean_content; }

        $parts = array($title);
        if ($content_part !== '') { $parts[] = $content_part; }
        if ($include_permalink) { $parts[] = $permalink; }
        if ($hashtag_block !== '') { $parts[] = $hashtag_block; }
        $parts = array_filter($parts);
        return implode("\n\n", $parts);
    }

    public static function extract_and_clean_hashtags(string $text): array {
        $extracted = array();
        $cleaned = preg_replace_callback('/#(\w+)/u', static function (array $matches) use (&$extracted): string {
            $extracted[] = $matches[1];
            return $matches[1];
        }, $text);
        return array((string) $cleaned, $extracted);
    }

    public static function build_hashtags(WP_Post $post, array $extracted_hashtags, string $required, int $min_hashtags): string {
        $hashtags = array();
        $hashtags_lower = array();
        foreach ($extracted_hashtags as $word) {
            $lower = strtolower($word);
            if (! in_array($lower, $hashtags_lower, true)) { $hashtags[] = $word; $hashtags_lower[] = $lower; }
        }
        $required_lower = strtolower($required);
        if (! in_array($required_lower, $hashtags_lower, true)) { $hashtags[] = $required; $hashtags_lower[] = $required_lower; }

        if (count($hashtags) < $min_hashtags) {
            $wp_tags = get_the_tags($post->ID);
            if (! empty($wp_tags) && is_array($wp_tags)) {
                foreach ($wp_tags as $tag) {
                    if (count($hashtags) >= $min_hashtags) { break; }
                    $normalized = strtolower(str_replace(' ', '', (string) $tag->slug));
                    if (! empty($normalized) && ! in_array($normalized, $hashtags_lower, true)) {
                        $hashtags[] = $normalized;
                        $hashtags_lower[] = $normalized;
                    }
                }
            }
        }

        return implode(' ', array_map(static fn (string $h): string => '#' . $h, $hashtags));
    }

    public static function normalize_response(array $response, string $channel_id): array {
        $create_post = $response['data']['createPost'] ?? null;
        if (! is_array($create_post)) {
            return array('success' => false, 'message' => __('Unexpected response from Buffer. Please try again.', 'wp-queuepress'));
        }
        if (isset($create_post['post']['id'])) {
            return array('success' => true, 'post_id' => (string) $create_post['post']['id'], 'status' => (string) ($create_post['post']['status'] ?? ''), 'channel_id' => $channel_id);
        }
        if (isset($create_post['message'])) {
            return array('success' => false, 'message' => (string) $create_post['message']);
        }
        return array('success' => false, 'message' => __('Unexpected response from Buffer. Please try again.', 'wp-queuepress'));
    }

    /**
     * Split a long caption into chunks not exceeding $limit characters.
     * Preserves words (doesn't cut mid-word) and returns array of strings.
     *
     * @param string $text
     * @param int $limit
     * @return string[]
     */
    public static function split_caption_into_chunks(string $text, int $limit): array {
        $text = trim((string) $text);
        if ($text === '') { return array(); }
        if ($limit <= 0) { return array($text); }

        $words = preg_split('/\s+/u', $text);
        $chunks = array();
        $current = '';

        foreach ($words as $w) {
            $append = $current === '' ? $w : (' ' . $w);
            if (mb_strlen($current . $append, 'UTF-8') > $limit) {
                if ($current !== '') { $chunks[] = $current; }
                // If single word longer than limit, force-split it.
                if (mb_strlen($w, 'UTF-8') > $limit) {
                    $start = 0;
                    $len = mb_strlen($w, 'UTF-8');
                    while ($start < $len) {
                        $chunks[] = mb_substr($w, $start, $limit, 'UTF-8');
                        $start += $limit;
                    }
                    $current = '';
                } else {
                    $current = $w;
                }
            } else {
                $current .= $append;
            }
        }
        if ($current !== '') { $chunks[] = $current; }
        return $chunks;
    }

    /**
     * Distribute images across $num_chunks following rules:
     * - Preserve order
     * - If images >= chunks: distribute as evenly as possible
     * - If images < chunks: assign one image per chunk cycling through images
     * - Cap images per chunk to $max_per_chunk
     *
     * Returns array with $num_chunks elements, each an array of image URLs.
     *
     * @param string[] $images
     * @param int $num_chunks
     * @param int $max_per_chunk
     * @return array<int,array<string>>
     */
    public static function distribute_images_across_chunks(array $images, int $num_chunks, int $max_per_chunk): array {
        $num_chunks = max(1, (int) $num_chunks);
        $max_per_chunk = max(1, (int) $max_per_chunk);
        $total = count($images);
        $result = array_fill(0, $num_chunks, array());
        if ($total === 0) { return $result; }

        if ($total >= $num_chunks) {
            $base = intdiv($total, $num_chunks);
            $rem = $total % $num_chunks;
            $idx = 0;
            for ($i = 0; $i < $num_chunks; $i++) {
                $count = $base + ($i < $rem ? 1 : 0);
                $count = min($count, $max_per_chunk);
                for ($j = 0; $j < $count && $idx < $total; $j++, $idx++) {
                    $result[$i][] = $images[$idx];
                }
            }
            return $result;
        }

        // total < num_chunks: assign one image per chunk cycling through images
        for ($i = 0; $i < $num_chunks; $i++) {
            $img = $images[$i % $total] ?? null;
            if ($img !== null) { $result[$i][] = $img; }
        }

        return $result;
    }

    /**
     * Build a thread payload array from a post when caption exceeds limit.
     * Returns null if no thread should be created (e.g., NSFW for threads,
     * or caption does not exceed the given limit).
     *
     * Options:
     *  - 'caption' => string (required) full caption (with link/hashtags)
     *  - 'images' => array of image URLs (optional)
     *  - 'limit' => int character limit per element
     *  - 'max_per_element' => int max images per element
     *  - 'nsfw' => bool
     *
     * @param WP_Post $post
     * @param array $cfg
     * @param string $service 'twitter'|'threads'
     * @param array $options
     * @return array|null
     */
    public static function build_thread_payload(WP_Post $post, array $cfg, string $service, array $options = array(), float $margin = 0.05): ?array {
        $caption = (string) ($options['caption'] ?? '');
        $images = is_array($options['images']) ? $options['images'] : array();
        $limit = isset($options['limit']) ? (int) $options['limit'] : 280;
        $max_per_element = isset($options['max_per_element']) ? (int) $options['max_per_element'] : 4;
        $nsfw = ! empty($options['nsfw']);

        $svc = strtolower((string) $service);

        if ($svc === 'threads' && $nsfw) {
            // Threads must not create social_post thread when NSFW.
            return null;
        }

        if (mb_strlen($caption, 'UTF-8') <= $limit) { return null; }

        $effective_limit = max(1, (int) floor($limit * (1 - $margin)));

        $parts = self::split_caption_into_chunks($caption, $effective_limit);
        if (empty($parts)) { return null; }

        $distributed = self::distribute_images_across_chunks($images, count($parts), $max_per_element);

        $thread = array();
        foreach ($parts as $i => $text) {
            $assets = array();
            foreach ($distributed[$i] ?? array() as $url) {
                $assets[] = array('image' => array('url' => $url));
            }
            $thread[] = array('text' => $text, 'assets' => $assets);
        }

        return $thread;
    }

}
