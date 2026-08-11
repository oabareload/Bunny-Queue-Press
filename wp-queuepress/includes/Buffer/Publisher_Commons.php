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

    /**
     * Get the pretty/friendly URL for a post.
     *
     * For published posts, uses get_permalink().
     * For unpublished posts (draft, future, pending, etc.), reconstructs the URL
     * using get_sample_permalink() to generate a preview-friendly URL.
     *
     * @param WP_Post $post
     * @return string
     */
    public static function get_pretty_post_url(WP_Post $post): string {
        if ('publish' === $post->post_status) {
            return (string) get_permalink($post);
        }

        $sample_permalink = get_sample_permalink($post->ID);
        $base_structure = $sample_permalink[0];
        $post_slug = $sample_permalink[1];

        return str_replace('%postname%', $post_slug, $base_structure);
    }

    /**
     * Build the ordered, deduplicated list of image URLs for the publication.
     *
     * Image source is a fixed system rule, not a user preference:
     *   - NSFW (and not $allow_gallery_on_nsfw) → featured_only
     *   - Otherwise                              → featured_plus_gallery
     *
     * Rules:
     *   - featured_only: [featured_image_url]
     *   - featured_plus_gallery: [featured, ...gallery], featured always first.
     *   - Deduplicate by URL preserving order (first occurrence wins).
     *   - Maximum $max_images images.
     *   - If no valid images remain: return empty array (caller must error out).
     *
     * @param WP_Post $post    The post.
     * @param array   $cfg     Channel configuration (no longer reads image_source).
     * @param bool    $is_nsfw Whether NSFW override applies.
     * @param int     $max_images Maximum number of images to return.
     * @param bool    $allow_gallery_on_nsfw When true, NSFW posts still include gallery.
     * @return string[] Ordered, deduplicated image URLs.
     */
    public static function build_assets(WP_Post $post, array $cfg, bool $is_nsfw, int $max_images, bool $allow_gallery_on_nsfw = false): array {
        $effective_source = ($is_nsfw && ! $allow_gallery_on_nsfw) ? 'featured_only' : 'featured_plus_gallery';

        $urls = array();
        $featured = get_the_post_thumbnail_url($post->ID, 'full');
        if (! empty($featured)) {
            $urls[] = $featured;
        }

        if ('featured_plus_gallery' === $effective_source) {
            $gallery = self::get_gallery_image_urls($post);
            foreach ($gallery as $u) { $urls[] = $u; }
        }

        $found_urls = $urls;

        $seen = array();
        $unique = array();
        foreach ($urls as $u) {
            if (! isset($seen[$u])) {
                $seen[$u] = true;
                $unique[] = $u;
            }
        }

        $result = array_slice($unique, 0, $max_images);

        if (class_exists(Buffer_Debug::class)) {
            Buffer_Debug::add_entry(array(
                'type' => 'assets_filter',
                'post_id' => $post->ID,
                'effective_source' => $effective_source,
                'is_nsfw' => $is_nsfw,
                'allow_gallery_on_nsfw' => $allow_gallery_on_nsfw,
                'found_count' => count($found_urls),
                'returned_count' => count($result),
                'found' => array_values($found_urls),
                'returned' => array_values($result),
            ));
        }

        return $result;
    }

    public static function get_gallery_image_urls(WP_Post $post): array {
        $urls = array();

        $raw_content = is_string($post->post_content) ? $post->post_content : '';

        if (function_exists('parse_blocks') && has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);

            $diagnostic_found = array();
            $diagnostic_block_names = array();

            $walker = static function (array $blocks) use (&$walker, &$urls, &$diagnostic_found, &$diagnostic_block_names) {
                foreach ($blocks as $block) {
                    $name = $block['blockName'] ?? '';

                    if ($name !== '') { $diagnostic_block_names[] = $name; }

                    if ('core/gallery' === $name) {
                        $ids = $block['attrs']['ids'] ?? array();
                        if (is_string($ids)) {
                            $ids = array_filter(array_map('intval', explode(',', $ids)));
                        }
                        if (is_array($ids) && ! empty($ids)) {
                            foreach ($ids as $id) {
                                $url = wp_get_attachment_url((int) $id);
                                if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                            }
                        }

                        if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                            foreach ($block['innerBlocks'] as $inner) {
                                if (($inner['blockName'] ?? '') === 'core/image') {
                                    $id = (int) ($inner['attrs']['id'] ?? 0);
                                    if ($id > 0) {
                                        $url = wp_get_attachment_url($id);
                                        if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                                    }
                                    $img_url = $inner['attrs']['url'] ?? '';
                                    if (! empty($img_url) && is_string($img_url)) {
                                        $urls[] = esc_url_raw($img_url); $diagnostic_found[] = esc_url_raw($img_url);
                                    }
                                }
                            }
                        }
                    }

                    if (
                        strpos($name, 'bunny/') === 0 ||
                        strpos($name, 'core/gallery') === 0 ||
                        stripos($name, 'gallery') !== false
                    ) {
                        $attrs = $block['attrs'] ?? array();
                        if (! empty($attrs['imageData']) && is_array($attrs['imageData'])) {
                            foreach ($attrs['imageData'] as $img) {
                                if (is_array($img) && ! empty($img['url'])) {
                                    $urls[] = esc_url_raw($img['url']); $diagnostic_found[] = esc_url_raw($img['url']);
                                }
                            }
                        }
                        if (! empty($attrs['imageUrl']) && is_string($attrs['imageUrl'])) {
                            $urls[] = esc_url_raw($attrs['imageUrl']); $diagnostic_found[] = esc_url_raw($attrs['imageUrl']);
                        }
                        if (! empty($attrs['imageId']) && is_numeric($attrs['imageId'])) {
                            $id = (int) $attrs['imageId'];
                            $url = wp_get_attachment_url($id);
                            if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                        }
                        if (! empty($attrs['ids']) && is_array($attrs['ids'])) {
                            foreach ($attrs['ids'] as $id) {
                                $url = wp_get_attachment_url((int) $id);
                                if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                            }
                        }
                        if (! empty($attrs['images']) && is_array($attrs['images'])) {
                            foreach ($attrs['images'] as $img) {
                                if (is_array($img) && ! empty($img['url'])) {
                                    $urls[] = esc_url_raw($img['url']); $diagnostic_found[] = esc_url_raw($img['url']);
                                } elseif (is_numeric($img)) {
                                    $url = wp_get_attachment_url((int) $img);
                                    if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                                } elseif (is_string($img)) {
                                    $urls[] = esc_url_raw($img); $diagnostic_found[] = esc_url_raw($img);
                                }
                            }
                        }
                        if (! empty($attrs['url']) && is_string($attrs['url'])) {
                            $urls[] = esc_url_raw($attrs['url']); $diagnostic_found[] = esc_url_raw($attrs['url']);
                        }
                    }

                    if (($block['blockName'] ?? '') === 'core/image') {
                        $id = (int) ($block['attrs']['id'] ?? 0);
                        if ($id > 0) {
                            $url = wp_get_attachment_url($id);
                            if ($url) { $urls[] = $url; $diagnostic_found[] = $url; }
                        }
                        $img_url = $block['attrs']['url'] ?? '';
                        if (! empty($img_url) && is_string($img_url)) {
                            $urls[] = esc_url_raw($img_url); $diagnostic_found[] = esc_url_raw($img_url);
                        }
                    }

                    if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                        $walker($block['innerBlocks']);
                    }
                }
            };

            $walker($blocks);

            $seen = array();
            $unique = array();
            foreach ($urls as $u) {
                if (! isset($seen[$u])) { $seen[$u] = true; $unique[] = $u; }
            }

            if (class_exists(Buffer_Debug::class)) {
                Buffer_Debug::add_entry(array(
                    'type' => 'gallery_extract',
                    'post_id' => $post->ID,
                    'mode' => 'blocks',
                    'raw_excerpt' => mb_substr($raw_content, 0, 5000),
                    'block_count' => count($blocks),
                    'block_names' => array_values(array_unique($diagnostic_block_names)),
                    'found_count' => count($diagnostic_found),
                    'found' => array_values($diagnostic_found),
                    'unique_count' => count($unique),
                    'unique' => $unique,
                ));
            }

            return $unique;
        }

        if (preg_match('/\[gallery[^\]]*ids=["\']?([\d,]+)["\']?/i', $post->post_content, $matches)) {
            $ids = array_filter(array_map('intval', explode(',', $matches[1])));
            $found = array();
            foreach ($ids as $id) {
                $url = wp_get_attachment_url($id);
                if ($url) { $urls[] = $url; $found[] = $url; }
            }

            if (class_exists(Buffer_Debug::class)) {
                Buffer_Debug::add_entry(array(
                    'type' => 'gallery_extract',
                    'post_id' => $post->ID,
                    'mode' => 'shortcode',
                    'raw_excerpt' => mb_substr($raw_content, 0, 2000),
                    'ids' => $ids,
                    'found_count' => count($found),
                    'found' => $found,
                ));
            }
        } else {
            if (class_exists(Buffer_Debug::class)) {
                Buffer_Debug::add_entry(array(
                    'type' => 'gallery_extract',
                    'post_id' => $post->ID,
                    'mode' => 'none',
                    'raw_excerpt' => mb_substr($raw_content, 0, 2000),
                    'found_count' => 0,
                    'found' => array(),
                ));
            }
        }

        return $urls;
    }

    /**
     * Build a caption honoring channel config and options.
     *
     * Content source is a fixed system rule, not a user preference. When
     * 'force_source' is not provided, the content source defaults to
     * 'full_post'.
     *
     * Options:
     *  - 'include_permalink' => bool (default true)
     *  - 'force_source'      => 'excerpt'|'full_post'|null (default null → 'full_post')
     *  - 'post_style'        => string|null
     *  - 'margin'            => float (default 0.10)
     *  - 'prepend_content'   => string (optional) Raw text to prepend to the
     *                           body content before the smart-truncate step.
     *                           Used by Instagram Social Post to combine
     *                           excerpt + full_post into a single block whose
     *                           final length is still capped by the platform
     *                           character limit.
     *
     * @param WP_Post $post
     * @param array<string,mixed> $cfg
     * @param int $character_limit
     * @param array<string,mixed> $options
     * @return string
     */
    public static function build_caption(WP_Post $post, array $cfg, int $character_limit, array $options = array()): string {
        $include_permalink = isset($options['include_permalink']) ? (bool) $options['include_permalink'] : true;
        $include_title     = ! isset($options['include_title']) || (bool) $options['include_title'];  // default true
        $force_source = $options['force_source'] ?? null;
        $post_style = $options['post_style'] ?? null;
        $margin = isset($options['margin']) ? (float) $options['margin'] : 0.10;

        if (in_array($force_source, array('excerpt', 'full_post'), true)) {
            $content_source = $force_source;
        } else {
            $content_source = 'full_post';
        }

        // Decode HTML entities in the title (e.g. &#8211; → –, &#8217; → ').
        $title = html_entity_decode((string) get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $permalink = self::get_pretty_post_url($post);

        $raw_content = '';
        if ('excerpt' === $content_source) {
            // force_source='excerpt' must always use post_excerpt, regardless
            // of whether the post has Gutenberg blocks. Fall back to the body
            // (stripped) only if no excerpt is available.
            $excerpt_src = ! empty($post->post_excerpt)
                ? (string) $post->post_excerpt
                : (string) $post->post_content;
            $raw_content = self::strip_tags_preserve_breaks($excerpt_src);
        } elseif (function_exists('parse_blocks') && has_blocks($post->post_content)) {
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
                        $text = self::strip_tags_preserve_breaks($inner);
                        if ($text !== '') { $parts[] = $text; }
                    }
                    $attrs = $block['attrs'] ?? array();
                    if (! empty($attrs['title']) && is_string($attrs['title'])) {
                        $tt = self::strip_tags_preserve_breaks($attrs['title']);
                        if ($tt !== '') { $parts[] = $tt; }
                    }
                    if (! empty($attrs['content'])) {
                        if (is_string($attrs['content'])) {
                            $ct = self::strip_tags_preserve_breaks($attrs['content']);
                            if ($ct !== '') { $parts[] = $ct; }
                        } elseif (is_array($attrs['content'])) {
                            $extract = null;
                            $extract = static function ($value) use (&$parts, &$extract) {
                                if (is_string($value)) {
                                    $text = Publisher_Commons::strip_tags_preserve_breaks($value);
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
                $raw_content = self::strip_tags_preserve_breaks($post->post_content);
            } elseif (! empty($post->post_excerpt)) {
                $raw_content = (string) $post->post_excerpt;
            } else {
                $raw_content = self::strip_tags_preserve_breaks($post->post_content);
            }
        }

        $raw_content = str_replace(array("\r\n", "\r"), "\n", trim((string) $raw_content));
        $raw_content = (string) preg_replace('/\n{3,}/u', "\n\n", $raw_content);

        // Optional prepend: lets callers (Instagram Social Post) attach a
        // pre-built block (e.g. excerpt) ahead of the body content before
        // smart-truncate runs. System rule, not a user preference.
        //
        // The prepended text is run through strip_tags_preserve_breaks() so
        // any HTML residual or HTML-encoded entities (e.g. &#8211;, &)
        // are decoded/cleaned before being concatenated. Without this, callers
        // passing pre-built excerpt/full_post blocks would leak entities or
        // <p>/<br> tags into the final caption.
        $prepend = isset($options['prepend_content']) ? (string) $options['prepend_content'] : '';
        if ($prepend !== '') {
            $prepend = self::strip_tags_preserve_breaks($prepend);
            $prepend = str_replace(array("\r\n", "\r"), "\n", trim($prepend));
            $prepend = (string) preg_replace('/\n{3,}/u', "\n\n", $prepend);
            $raw_content = $prepend . "\n\n" . $raw_content;
        }

        // Hashtags: the system no longer extracts, rebuilds or appends a
        // hashtags block. They stay exactly where the author wrote them in
        // the content. If smart_truncate cuts a hashtag, that's accepted.
        $clean_content = $raw_content;

        $effective_limit = $character_limit;
        if ($character_limit !== PHP_INT_MAX) {
            $effective_limit = max(1, (int) floor($character_limit * (1 - $margin)));
        }

        // Calculate fixed length: title (if included) + (maybe permalink) + separators.
        $separator = "\n\n";
        $fixed_length = 0;
        if ($include_title && $title !== '') {
            $fixed_length += mb_strlen($title, 'UTF-8');
        }
        if ($include_permalink) {
            $fixed_length += mb_strlen($separator, 'UTF-8') + mb_strlen($permalink, 'UTF-8');
        }
        $content_separator_len = mb_strlen($separator, 'UTF-8');
        $available = $effective_limit - $fixed_length - $content_separator_len;

        if ($available <= 0) {
            $content_part = '';
        } elseif (mb_strlen($clean_content, 'UTF-8') <= $available) {
            $content_part = $clean_content;
        } else {
            $content_part = self::smart_truncate($clean_content, $available);
        }

        $assembled = array();
        if ($include_title && $title !== '') { $assembled[] = $title; }
        if ($content_part !== '') { $assembled[] = $content_part; }
        if ($include_permalink) { $assembled[] = $permalink; }

        return implode("\n\n", array_filter($assembled, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Truncates $text to at most $limit UTF-8 characters without cutting mid-word.
     *
     * Search priority (scanning backwards from position $limit):
     *   1. Last "\n" within a lookahead window of 20% of $limit (min 30 chars).
     *   2. Last "." within the same window.
     *   3. Last " " (space) within the same window.
     *   4. Hard cut at $limit (only when no delimiter found in window).
     *
     * @param string $text
     * @param int    $limit
     * @return string
     */
    private static function smart_truncate(string $text, int $limit): string {
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $limit) { return $text; }

        $candidate = mb_substr($text, 0, $limit, 'UTF-8');

        $window = max(30, (int) floor($limit * 0.20));
        $scan_from = max(0, $limit - $window);

        $nl_pos = mb_strrpos($candidate, "\n", 0, 'UTF-8');
        if ($nl_pos !== false && $nl_pos >= $scan_from) {
            return rtrim(mb_substr($text, 0, $nl_pos, 'UTF-8'));
        }

        $dot_pos = mb_strrpos($candidate, '.', 0, 'UTF-8');
        if ($dot_pos !== false && $dot_pos >= $scan_from) {
            return mb_substr($text, 0, $dot_pos + 1, 'UTF-8');
        }

        $space_pos = mb_strrpos($candidate, ' ', 0, 'UTF-8');
        if ($space_pos !== false && $space_pos >= $scan_from) {
            return rtrim(mb_substr($text, 0, $space_pos, 'UTF-8'));
        }

        return $candidate;
    }

    /**
     * Strips HTML tags from a string while preserving line break semantics.
     *
     * Converts <br> and <br/> to \n before stripping, so manual line breaks
     * inside paragraphs or custom block attributes are not lost.
     * Also inserts \n after block-level closing tags so that adjacent
     * block elements (e.g. <p>A</p><p>B</p>) produce separate lines
     * instead of running together.
     *
     * @param string $html Raw HTML string.
     * @return string Plain text with newlines preserved.
     */
    private static function strip_tags_preserve_breaks(string $html): string {
        $text = preg_replace('/<br\s*\/?>/iu', "\n", $html);
        $text = preg_replace('/<\/(p|div|li|h[1-6]|blockquote|pre)>/iu', "</$1>\n", (string) $text);
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
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
     * Splits a long caption into chunks not exceeding $limit characters.
     *
     * Strategy:
     *   1. Split on paragraph breaks (\n\n) first.
     *   2. If a paragraph fits, accumulate into the current chunk.
     *   3. If a paragraph exceeds $limit, split on single newlines, then on spaces.
     *   4. Words are never split mid-word. A single word longer than $limit
     *      is force-split as a last resort.
     *
     * @param string $text  Caption text (may contain \n and \n\n).
     * @param int    $limit Maximum UTF-8 characters per chunk.
     * @return string[]
     */
    public static function split_caption_into_chunks(string $text, int $limit): array {
        $text = trim((string) $text);
        if ($text === '') { return array(); }
        if ($limit <= 0)  { return array($text); }

        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        $chunks  = array();
        $current = '';

        $paragraphs = preg_split('/\n{2,}/u', $text);

        foreach ($paragraphs as $paragraph) {
            $para_len = mb_strlen($paragraph, 'UTF-8');

            if ($para_len <= $limit) {
                $candidate = $current === '' ? $paragraph : ($current . "\n\n" . $paragraph);
                if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                    $current = $candidate;
                } else {
                    if ($current !== '') { $chunks[] = $current; }
                    $current = $paragraph;
                }
            } else {
                if ($current !== '') { $chunks[] = $current; $current = ''; }

                $lines = explode("\n", $paragraph);
                foreach ($lines as $line) {
                    $line_len = mb_strlen($line, 'UTF-8');

                    if ($line_len <= $limit) {
                        $candidate = $current === '' ? $line : ($current . "\n" . $line);
                        if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                            $current = $candidate;
                        } else {
                            if ($current !== '') { $chunks[] = $current; }
                            $current = $line;
                        }
                    } else {
                        if ($current !== '') { $chunks[] = $current; $current = ''; }

                        $words = explode(' ', $line);
                        foreach ($words as $word) {
                            if ($word === '') { continue; }
                            $word_len  = mb_strlen($word, 'UTF-8');
                            $candidate = $current === '' ? $word : ($current . ' ' . $word);

                            if (mb_strlen($candidate, 'UTF-8') <= $limit) {
                                $current = $candidate;
                            } else {
                                if ($current !== '') { $chunks[] = $current; }
                                if ($word_len <= $limit) {
                                    $current = $word;
                                } else {
                                    $start = 0;
                                    while ($start < $word_len) {
                                        $chunks[] = mb_substr($word, $start, $limit, 'UTF-8');
                                        $start += $limit;
                                    }
                                    $current = '';
                                }
                            }
                        }
                    }
                }
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

        for ($i = 0; $i < $num_chunks; $i++) {
            $img = $images[$i % $total] ?? null;
            if ($img !== null) { $result[$i][] = $img; }
        }

        return $result;
    }

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
	public static function save_channel_record(int $post_id, array $result): void {
		$channel_id = (string) ($result['channel_id'] ?? '');
		if (empty($channel_id)) {
			return;
		}

		// The meta key is identical to Buffer_Ajax::META_KEY
		$meta_key = '_queuepress_buffer_channels';
		$channels = get_post_meta($post_id, $meta_key, true);
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

		update_post_meta($post_id, $meta_key, $channels);
	}
}
