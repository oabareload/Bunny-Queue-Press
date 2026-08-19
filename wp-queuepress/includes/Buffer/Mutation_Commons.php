<?php
/**
 * Shared mutation builders and helpers for Buffer publishers.
 *
 * Contains image-based mutation builder used by Instagram and shared
 * helpers for link-card construction that Twitter/Threads builders can use.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Mutation_Commons {

    /**
     * Build a createPost mutation using an array of image URLs.
     * Preserves the exact output format Instagram_Publisher expects.
     *
     * @param string $channel_id
     * @param string $caption
     * @param string[] $asset_urls
     * @param string $mode Either 'addToQueue' or 'shareNow'. Any other value falls back to 'addToQueue'.
     * @return string
     */
    public static function build_create_post_mutation_from_image_urls(string $channel_id, string $caption, array $asset_urls, string $mode = 'addToQueue'): string {
        if (! in_array($mode, array('addToQueue', 'shareNow'), true)) {
            $mode = 'addToQueue';
        }

        $channel_json = wp_json_encode($channel_id);
        $caption_json = wp_json_encode($caption);

        $assets_gql = '';
        if (! empty($asset_urls)) {
            $asset_lines = array_map(
                static function (string $url): string {
                    $u = wp_json_encode(esc_url_raw($url));
                    return '      { image: { url: ' . $u . ' } }';
                },
                $asset_urls
            );
            $assets_gql = "\n    assets: [\n" . implode(",\n", $asset_lines) . "\n    ]";
        }

        return <<<GQL
		mutation {
		  createPost(input: {
		    channelId: {$channel_json}
		    mode: {$mode}
		    schedulingType: automatic
		    text: {$caption_json}
		    metadata: {
		      instagram: {
		        type: post
		        shouldShareToFeed: true
		      }
		    }{$assets_gql}
		  }) {
		    ... on PostActionSuccess {
		      post {
		        id
		        status
		      }
		    }
		    ... on MutationError {
		      message
		    }
		  }
		}
		GQL;
    }

    /**
     * Generic mutation builder that supports both image and link assets.
     * Used by Twitter/Threads specific builders.
     *
     * @param string $channel_id
     * @param string $caption
     * @param array  $assets
     * @param array  $meta Optional debug meta
     * @param string $mode Either 'addToQueue' or 'shareNow'. Any other value falls back to 'addToQueue'.
     * @return string
     */
    public static function build_create_post_mutation(string $channel_id, string $caption, array $assets, array $meta = [], string $service = '', string $mode = 'addToQueue'): string {
        if (! in_array($mode, array('addToQueue', 'shareNow'), true)) {
            $mode = 'addToQueue';
        }

        $channel_json = wp_json_encode($channel_id);
        $caption_json = wp_json_encode($caption);

        $asset_lines = array();

        foreach ($assets as $a) {
            if (is_string($a) && $a !== '') {
                $u = wp_json_encode(esc_url_raw($a));
                $asset_lines[] = '      { image: { url: ' . $u . ' } }';
                continue;
            }

            if (is_array($a)) {
                if (isset($a['image']) && is_array($a['image']) && ! empty($a['image']['url'])) {
                    $u = wp_json_encode(esc_url_raw((string) $a['image']['url']));
                    $asset_lines[] = '      { image: { url: ' . $u . ' } }';
                    continue;
                }

                if (isset($a['link']) && is_array($a['link'])) {
                    $link = $a['link'];
                    $url = wp_json_encode(esc_url_raw((string) ($link['url'] ?? '')));
                    $title = wp_json_encode(wp_strip_all_tags((string) ($link['title'] ?? '')));
                    $desc = wp_json_encode(wp_strip_all_tags((string) ($link['description'] ?? '')));
                    $thumb = wp_json_encode(esc_url_raw((string) ($link['thumbnailUrl'] ?? '')));

                    $asset_lines[] = '      { link: { url: ' . $url
                        . ' , title: ' . $title
                        . ' , description: ' . $desc
                        . ' , thumbnailUrl: ' . $thumb
                        . ' } }';

                    if (! empty($meta) && class_exists(Buffer_Debug::class)) {
                        Buffer_Debug::add_entry(array_merge(array(
                            'type' => 'link_card_mutation',
                            'timestamp' => gmdate('Y-m-d H:i:s'),
                            'channel_id' => $channel_id,
                            'final_link_object' => $a['link'],
                            'mutation_asset' => array('link' => $a['link']),
                        ), $meta));
                    }

                    continue;
                }
            }
        }

        $assets_gql = '';
        if (! empty($asset_lines)) {
            $assets_gql = "\n    assets: [\n" . implode(",\n", $asset_lines) . "\n    ]";
        }

        // Build metadata block based on service.
        $svc = strtolower((string) $service);
        $metadata_inner = '';

        $detected_post_style = (string) ($meta['detected_post_style'] ?? '');
        $thread_payload = $meta['thread'] ?? null;

        if ('twitter' === $svc) {
            // Include thread array only when explicitly provided for social_post.
            if ($detected_post_style === 'social_post' && is_array($thread_payload) && ! empty($thread_payload)) {
                $thread_lines = array();
                foreach ($thread_payload as $elem) {
                    $text_json = wp_json_encode((string) ($elem['text'] ?? ''));
                    $asset_lines_inner = array();
                    if (! empty($elem['assets']) && is_array($elem['assets'])) {
                        foreach ($elem['assets'] as $ai) {
                            $u = wp_json_encode(esc_url_raw((string) ($ai['image']['url'] ?? '')));
                            $asset_lines_inner[] = '                        { image: { url: ' . $u . ' } }';
                        }
                    }
                    $assets_block = '';
                    if (! empty($asset_lines_inner)) {
                        $assets_block = "\n                    assets: [\n" . implode("\n", $asset_lines_inner) . "\n                    ]";
                    }
                    $thread_lines[] = "                { text: {$text_json}{$assets_block} }";
                }

                $thread_gql = "thread: [\n" . implode("\n", $thread_lines) . "\n            ]";
            } else {
                $thread_gql = 'thread: null';
            }

            $metadata_inner = "twitter: { {$thread_gql} }";
        } elseif ('threads' === $svc) {
            // For threads, include linkAttachment if we have a link asset.
            $link_url = '';
            foreach ($assets as $a) {
                if (is_array($a) && isset($a['link']) && is_array($a['link']) && ! empty($a['link']['url'])) {
                    $link_url = esc_url_raw((string) $a['link']['url']);
                    break;
                }
            }

            $type_part = 'type: post';
            $link_part = '';
            $skip_link_attachment = ! empty($meta['no_link_attachment']);
            if (! empty($link_url) && ! $skip_link_attachment) {
                $link_json = wp_json_encode($link_url);
                $link_part = " linkAttachment: { url: {$link_json} }";
            }

            // Threads thread payload only when social_post and payload provided.
            if ($detected_post_style === 'social_post' && is_array($thread_payload) && ! empty($thread_payload)) {
                $thread_lines = array();
                foreach ($thread_payload as $elem) {
                    $text_json = wp_json_encode((string) ($elem['text'] ?? ''));
                    $asset_lines_inner = array();
                    if (! empty($elem['assets']) && is_array($elem['assets'])) {
                        foreach ($elem['assets'] as $ai) {
                            $u = wp_json_encode(esc_url_raw((string) ($ai['image']['url'] ?? '')));
                            $asset_lines_inner[] = '                        { image: { url: ' . $u . ' } }';
                        }
                    }
                    $assets_block = '';
                    if (! empty($asset_lines_inner)) {
                        $assets_block = "\n                    assets: [\n" . implode("\n", $asset_lines_inner) . "\n                    ]";
                    }
                    $thread_lines[] = "                { text: {$text_json}{$assets_block} }";
                }

                $thread_gql = "thread: [\n" . implode("\n", $thread_lines) . "\n            ]";
            } else {
                $thread_gql = 'thread: null';
            }

            $metadata_inner = "threads: { {$type_part} {$thread_gql}{$link_part} }";
        } elseif ('facebook' === $svc) {

            $link_url = '';

            foreach ($assets as $a) {
                if (
                    is_array($a)
                    && isset($a['link'])
                    && is_array($a['link'])
                    && ! empty($a['link']['url'])
                ) {
                    $link_url = esc_url_raw($a['link']['url']);
                    break;
                }
            }

            $type_part = 'type: post';
            $link_part = '';

            if (! empty($link_url)) {
                $link_json = wp_json_encode($link_url);
                $link_part = " linkAttachment: { url: {$link_json} }";
            }

            $metadata_inner = "facebook: { {$type_part} {$link_part} }";
        } elseif ('pinterest' === $svc) {
            $board_service_id = wp_json_encode(
                sanitize_text_field((string) ($meta['board_service_id'] ?? ''))
            );

            $title = wp_json_encode(
                sanitize_text_field((string) ($meta['title'] ?? ''))
            );

            $link_url = wp_json_encode(
                esc_url_raw((string) ($meta['url'] ?? ''))
            );

            $metadata_inner = "pinterest: {"
                . " boardServiceId: {$board_service_id}"
                . " title: {$title}"
                . " url: {$link_url}"
                . " }";
        } else {
            // Default to instagram metadata for backward compatibility.
            $metadata_inner = "instagram: { type: post shouldShareToFeed: true }";
        }

        $metadata_gql = "\n    metadata: {\n      {$metadata_inner}\n    }";

                return <<<GQL
mutation {
    createPost(input: {
        channelId: {$channel_json}
        mode: {$mode}
        schedulingType: automatic
        text: {$caption_json}
        {$metadata_gql}{$assets_gql}
    }) {
        ... on PostActionSuccess {
            post {
                id
                status
            }
        }
        ... on MutationError {
            message
        }
    }
}
GQL;
    }

    /**
     * Build a link asset object for link-card publishers (twitter/threads).
     * Shared logic extracted here so both publishers can reuse.
     *
     * @param WP_Post $post
     * @param array $cfg
     * @param array $channel_info
     * @return array|null ['link'=>..., 'debug'=>...] or null
     */
    public static function build_link_asset_from_post(WP_Post $post, array $cfg, array $channel_info): ?array {
        $service = strtolower((string) ($channel_info['service'] ?? ''));
        $post_style = (string) ($cfg['post_style'] ?? '');

        if (! in_array($service, array('twitter', 'threads', 'facebook'), true)) {
            return null;
        }

        if ('card_link' !== $post_style) {
            return null;
        }

        $url = Publisher_Commons::get_pretty_post_url($post);

        $title = '';
        $seo_keys = array('_yoast_wpseo_title', '_aioseo_title', 'rank_math_title');
        foreach ($seo_keys as $k) {
            $v = get_post_meta($post->ID, $k, true);
            if (! empty($v)) { $title = (string) $v; break; }
        }
        if ('' === $title) { $title = get_the_title($post) ?: ''; }
        $title = wp_strip_all_tags((string) $title);

        $description = '';
        $desc_keys = array('_yoast_wpseo_metadesc', '_aioseo_description', 'rank_math_description');
        foreach ($desc_keys as $k) {
            $v = get_post_meta($post->ID, $k, true);
            if (! empty($v)) { $description = (string) $v; break; }
        }
        if ('' === $description) {
            if (! empty($post->post_excerpt)) {
                $description = (string) $post->post_excerpt;
            } else {
                $description = wp_strip_all_tags($post->post_content);
                if (mb_strlen($description, 'UTF-8') > 240) {
                    $description = mb_substr($description, 0, 240, 'UTF-8');
                }
            }
        }

        $thumbnail = get_the_post_thumbnail_url($post->ID, 'full');
        $fallback_sources = array();

        if (empty($thumbnail)) {
            $og_keys = array('_yoast_wpseo_opengraph-image', '_aioseo_opengraph_image', 'og_image');
            foreach ($og_keys as $k) {
                $v = get_post_meta($post->ID, $k, true);
                if (! empty($v)) { $thumbnail = (string) $v; $fallback_sources[] = $k; break; }
            }
        }

        if (empty($thumbnail)) {
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m)) {
                $thumbnail = $m[1];
                $fallback_sources[] = 'content_first_img';
            }
        }

        if (empty($thumbnail)) { return null; }

        $thumbnail = esc_url_raw($thumbnail);

        $link_obj = array(
            'url' => $url,
            'title' => (string) $title,
            'description' => (string) $description,
            'thumbnailUrl' => (string) $thumbnail,
        );

        /*if (class_exists(Buffer_Debug::class)) {
            Buffer_Debug::add_entry(array(
                'type' => 'link_card_build',
                'post_id' => $post->ID,
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'channel_platform' => $service,
                'detected_post_style' => $post_style,
                'final_link_object' => $link_obj,
                'fallback_sources' => $fallback_sources,
            ));
        }*/

        return array('link' => $link_obj, 'debug' => array('fallback_sources' => $fallback_sources));
    }

}
