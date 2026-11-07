<?php
/**
 * Twitter publisher.
 *
 * Builds and executes a Twitter createPost mutation via Buffer.
 *
 * Behavior (system rules, not user preferences):
 *   - post_style = 'social_post' →
 *       caption = excerpt + permalink + hashtags (first element of the thread)
 *       thread  = [intro, ...chunks_of_full_post]
 *                 chunks have NO permalink, NO hashtags, NO title.
 *       text top-level = intro (same as first element of the thread).
 *   - post_style = 'card_link' →
 *       caption = excerpt (no permalink, since the link asset carries the URL)
 *       assets  = [link asset] (or featured-only fallback)
 *
 *   - post_style = 'excerpt_thread' →
 *       thread  = full excerpt split on paragraph/sentence/word boundaries using
 *                 X's weighted count. The post URL (UTM + Nano URLs) is appended
 *                 only to the last element. The first element carries one image;
 *                 the rest are spread evenly from the second element on, up to
 *                 four per element, and no element is added just for images.
 *       The thread always has at least two elements: an excerpt that fits in
 *       the first gets the link in the second; with one continuation, the link
 *       goes in a third; with three or more text elements it joins the last.
 *
 * NSFW:
 *   Twitter does NOT restrict gallery images on NSFW (allow_gallery_on_nsfw=true).
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Twitter_Publisher {

    /**
     * Weight X gives to any URL, whatever its real length.
     */
    private const X_URL_WEIGHT = 23;

    /**
     * Separator between the excerpt text and the link in the last post.
     */
    private const X_LINK_SEPARATOR = "\n\n";

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

        $mutation = Mutation_Commons::build_create_post_mutation($channel_id, $caption, $assets, $meta, 'twitter');
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
     * Publish a post to a specific Twitter channel.
     *
     * @param int $post_id
     * @param string $channel_id
     * @param string $share_mode Either 'addToQueue' or 'shareNow'. Only forwarded to the mutation builder.
     * @return array
     */
    public function publish_to_channel(int $post_id, string $channel_id, string $share_mode = 'addToQueue'): array {
        $cfg = $this->config->get($channel_id, 'twitter');

        $post = get_post($post_id);
        if (! ($post instanceof WP_Post)) {
            return array('success' => false, 'message' => __('Post not found.', 'wp-queuepress'));
        }

        $service_limits = $this->config->limits_for('twitter');

        $limit_entry = $service_limits['character_limit'] ?? array('value' => 280);
        $limit = is_array($limit_entry) && isset($limit_entry['value']) ? (int) $limit_entry['value'] : (int) $limit_entry;
        if (! empty($cfg['premium_account']) && isset($service_limits['character_limit_premium'])) {
            $limit = (int) $service_limits['character_limit_premium']['value'];
        }

        $post_style_cfg = isset($cfg['post_style']) ? (string) $cfg['post_style'] : '';

        $is_nsfw = Publisher_Commons::is_nsfw($post);

        if ($post_style_cfg === 'card_link') {
            $mutation = $this->build_card_link_mutation($post, $cfg, $channel_id, $limit, $share_mode);
        } elseif ($post_style_cfg === 'excerpt_thread') {
            $built = $this->build_excerpt_thread_mutation($post, $cfg, $channel_id, $limit, $service_limits, $share_mode);
            if (! isset($built['mutation'])) {
                return array(
                    'success'    => false,
                    'message'    => (string) ($built['message'] ?? __('Could not build the Excerpt thread.', 'wp-queuepress')),
                    'service'    => 'twitter',
                    'channel_id' => $channel_id,
                );
            }
            $mutation = $built['mutation'];
        } else {
            $mutation = $this->build_social_post_mutation($post, $cfg, $channel_id, $limit, $is_nsfw, $service_limits, null, $share_mode);
        }

        $response = $this->client->mutate($mutation);
        $normalized = Publisher_Commons::normalize_response($response, $channel_id);
        $normalized['service'] = 'twitter';
        $normalized['channel_id'] = $channel_id;

        return $normalized;
    }

    /**
     * Build a Card Link mutation: excerpt body + link asset.
     *
     * @return string
     */
    private function build_card_link_mutation(WP_Post $post, array $cfg, string $channel_id, int $limit, string $share_mode = 'addToQueue'): string {
        $caption = Publisher_Commons::build_caption(
            $post,
            $cfg,
            $limit,
            array(
                'force_source'      => 'excerpt',
                'include_permalink' => true,
                'post_style'        => 'card_link',
                'platform'          => 'twitter',
            )
        );

        $featured = get_the_post_thumbnail_url($post->ID, 'full');
        $assets = array();
        if (! empty($featured)) { $assets[] = $featured; }

        return Mutation_Commons::build_create_post_mutation(
            $channel_id,
            $caption,
            $assets,
            array(),
            'twitter',
            $share_mode
        );
    }

    /**
     * Build a Social Post mutation with the new thread model:
     *   element 0 = excerpt + permalink + hashtags
     *   element 1..N = full_post chunks (no permalink, no hashtags, no title)
     *
     * If the full_post body fits in the limit, the thread is a single element.
     * Twitter does NOT restrict gallery images on NSFW (allow_gallery_on_nsfw=true).
     *
     * @return string
     */
    private function build_social_post_mutation(WP_Post $post, array $cfg, string $channel_id, int $limit, bool $is_nsfw, array $service_limits, ?array $link_asset, string $share_mode = 'addToQueue'): string {
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
                'platform'          => 'twitter',
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
                'platform'          => 'twitter',
            )
        );

        $title = html_entity_decode((string) get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $full_post_with_title = trim($title) . "\n\n" . $full_post_text;

        $effective_limit = max(1, (int) floor($limit * (1 - 0.10)));
        $body_chunks = Publisher_Commons::split_caption_into_chunks($full_post_with_title, $effective_limit);

        // 3. Assemble thread: intro first, then body chunks.
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

        // 5. Gather images for the thread. Twitter does NOT restrict on NSFW.
        $max_images_entry = $service_limits['max_images'] ?? array('value' => 4);
        $max_thread_posts_entry = $service_limits['max_thread_posts'] ?? array('value' => 25);
        $max_images_value = is_array($max_images_entry) && isset($max_images_entry['value']) ? (int) $max_images_entry['value'] : (int) $max_images_entry;
        $max_thread_posts_value = is_array($max_thread_posts_entry) && isset($max_thread_posts_entry['value']) ? (int) $max_thread_posts_entry['value'] : (int) $max_thread_posts_entry;
        $max_total_images = max(1, $max_images_value * $max_thread_posts_value);
        $images = Publisher_Commons::build_assets($post, $cfg, $is_nsfw, $max_total_images, true);
        $thread_truncated = false;
        $featured = get_the_post_thumbnail_url($post->ID, 'full');

        // 6. Top-level assets = featured image (consistent with prior behavior).
        $top_assets = array();
        if (! empty($featured)) { $top_assets[] = $featured; }

        $max_per_element = isset($service_limits['images_per_element'])
            ? (int) $service_limits['images_per_element']
            : (isset($service_limits['images_per_post']) ? (int) $service_limits['images_per_post'] : 4);

        // 7. Si el hilo excede el límite de Buffer,
        // reemplazar el último elemento por un mensaje de continuación.
        if (count($thread_texts) > $max_thread_posts_value) {

            $thread_truncated = true;

            // Conservamos un espacio para el mensaje final.
            $thread_texts = array_slice(
                $thread_texts,
                0,
                $max_thread_posts_value - 1
            );

            $thread_texts[] = sprintf(
                "🐰✨ Llegamos al límite del hilo.\n\nPuedes leer el resto del artículo aquí:\n%s",
                Publisher_Commons::short_post_url((string) get_permalink($post), $post, 'twitter')
            );
        }

        // 8. Distribute images across thread elements.
        $distributed = Publisher_Commons::distribute_images_across_chunks($images, count($thread_texts), $max_per_element);

        // 9. Build the thread payload.
        $thread_payload = array();
        foreach ($thread_texts as $i => $text) {
            
            $assets_for_element = array();

            // Si es el último tweet del hilo truncado,
            // ignoramos la distribución normal y usamos la imagen destacada.
            if (
                $thread_truncated &&
                $i === count($thread_texts) - 1 &&
                ! empty($featured)
            ) {
                $assets_for_element[] = array(
                    'image' => array(
                        'url' => $featured,
                    ),
                );
            } else {

                foreach ($distributed[$i] ?? array() as $url) {
                    $assets_for_element[] = array(
                        'image' => array(
                            'url' => $url,
                        ),
                    );
                }
            }

            $thread_payload[] = array(
                'text'   => $text,
                'assets' => $assets_for_element,
            );
        }

        // 10. Top-level text is the intro (per the rule: caption = first element of the thread).
        $meta = array(
            'detected_post_style' => 'social_post',
            'thread'              => $thread_payload,
        );

        return Mutation_Commons::build_create_post_mutation($channel_id, $intro, $top_assets, $meta, 'twitter', $share_mode);
    }

    /**
     * Build an Excerpt Thread mutation: the full excerpt, split into a thread
     * when it does not fit in one post, with the post link only at the end of
     * the last post.
     *
     * Returns array('mutation' => string) on success or array('message' => string)
     * when the post cannot be published in this format. Nothing is ever
     * truncated or rewritten to make it fit.
     *
     * @return array{mutation?: string, message?: string}
     */
    private function build_excerpt_thread_mutation(WP_Post $post, array $cfg, string $channel_id, int $limit, array $service_limits, string $share_mode = 'addToQueue'): array {
        $no_excerpt = array('message' => __('This post has no excerpt, so it cannot be published with the Excerpt thread format.', 'wp-queuepress'));

        // build_caption() would fall back to the post body when the excerpt is
        // empty; here that would thread the whole article, so stop first.
        if ('' === trim(wp_strip_all_tags((string) $post->post_excerpt))) {
            return $no_excerpt;
        }

        $excerpt = Publisher_Commons::build_caption(
            $post,
            $cfg,
            PHP_INT_MAX,
            array(
                'force_source'      => 'excerpt',
                'include_title'     => false,
                'include_permalink' => false,
                'platform'          => 'twitter',
            )
        );
        if ('' === $excerpt) {
            return $no_excerpt;
        }

        // UTM-tagged permalink, shortened through Nano URLs when available.
        $link    = Publisher_Commons::short_post_url(Publisher_Commons::get_pretty_post_url($post), $post, 'twitter');
        $reserve = self::x_weighted_length(self::X_LINK_SEPARATOR . $link);
        if ($limit <= $reserve) {
            return array('message' => __('The character limit is too small to fit the post link.', 'wp-queuepress'));
        }

        $max_posts       = max(2, (int) ($service_limits['max_thread_posts']['value'] ?? 25));
        $max_per_element = max(1, (int) ($service_limits['max_images']['value'] ?? 4));

        // The text alone decides how many posts there are (never the images). The
        // split is lossless: the pieces concatenate back to the exact excerpt. The
        // link goes only in the last post, and there are always at least two.
        $suffix = self::X_LINK_SEPARATOR . $link;
        if (self::x_weighted_length($excerpt) <= $limit) {
            // The whole excerpt fits in the first post: the link goes in the second.
            $tweets = array($excerpt, $link);
        } else {
            $tweets = self::x_split_text($excerpt, '', $limit);
            if (2 === count($tweets)) {
                // A single continuation: the link goes in a third post.
                $tweets[] = $link;
            } else {
                // Three or more text posts: the link joins the last one, and its
                // space is reserved before that post is calculated.
                $tweets = self::x_split_text($excerpt, $suffix, $limit);
            }
        }
        if (empty($tweets)) {
            return array('message' => __('Could not split the excerpt within the character limit.', 'wp-queuepress'));
        }

        $count = count($tweets);
        if ($count > $max_posts) {
            return array('message' => sprintf(
                /* translators: 1: posts needed, 2: maximum thread posts */
                __('The excerpt needs %1$d posts but the thread limit is %2$d. Nothing was published.', 'wp-queuepress'),
                $count,
                $max_posts
            ));
        }

        foreach ($tweets as $text) {
            if (self::x_weighted_length($text) > $limit) {
                return array('message' => __('Could not split the excerpt within the character limit.', 'wp-queuepress'));
            }
        }

        // Images: same selection and order as the other formats (featured first,
        // then gallery, deduplicated). The first post carries a single image (the
        // main one, used once) and the rest are spread evenly from the second post
        // on, up to the per-post maximum. No post is added just for images, so
        // anything beyond the thread's capacity is left out.
        $images = Publisher_Commons::build_assets($post, $cfg, Publisher_Commons::is_nsfw($post), 1 + $max_per_element * ($count - 1), true);
        $main   = array_slice($images, 0, 1);
        $rest   = array_slice($images, 1);

        $distributed = array_merge(
            array($main),
            Publisher_Commons::distribute_images_across_chunks($rest, $count - 1, $max_per_element, false)
        );

        $thread_payload = array();
        foreach ($tweets as $i => $text) {
            $assets_for_element = array();
            foreach ($distributed[$i] ?? array() as $url) {
                $assets_for_element[] = array('image' => array('url' => $url));
            }
            $thread_payload[] = array('text' => $text, 'assets' => $assets_for_element);
        }

        // Every image goes inside its own thread element and nothing is sent as a
        // top-level asset, so no image can be published twice.

        return array('mutation' => Mutation_Commons::build_create_post_mutation(
            $channel_id,
            $tweets[0],
            array(),
            array('detected_post_style' => 'excerpt_thread', 'thread' => $thread_payload),
            'twitter',
            $share_mode
        ));
    }

    /**
     * Length of a text as X counts it: URLs (with scheme, or bare domains with
     * a common TLD) weigh 23 each; characters outside X's weight-1 ranges
     * (Latin, general punctuation) weigh 2, including emoji and CJK.
     * Deliberately conservative: it may overcount, never undercount.
     */
    private static function x_weighted_length(string $text): int {
        if ('' === $text) { return 0; }

        $weight = 0;
        $rest   = (string) preg_replace_callback(
            '~https?://[^\s<>"]+|\b(?:[a-z0-9][a-z0-9-]*\.)+(?:com|net|org|io|co|es|info|app|dev|me|tv|ly|gg|xyz|online|site|blog|news|mx|ar|cl|jp|us|uk|de|fr)\b(?:/[^\s<>"]*)?~iu',
            static function (array $m) use (&$weight): string {
                $url   = $m[0];
                $trail = '';
                // Sentence punctuation glued to a URL is not part of it.
                if (preg_match('~[.,;:!?)\]}\'"\x{2026}]+$~u', $url, $t)) {
                    $trail = $t[0];
                }
                $weight += self::X_URL_WEIGHT;
                return $trail;
            },
            $text
        );

        foreach (preg_split('//u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $ch) {
            $cp = (int) mb_ord($ch, 'UTF-8');
            $weight += ($cp <= 4351 || ($cp >= 8192 && $cp <= 8205) || ($cp >= 8208 && $cp <= 8223) || ($cp >= 8242 && $cp <= 8247)) ? 1 : 2;
        }

        return $weight;
    }

    /**
     * Splits the excerpt into posts without losing a single character: the
     * pieces, concatenated in order, give back $text exactly. The last post also
     * carries $suffix (separator + link), whose space is reserved. Breaks go at
     * the latest natural point that fits (paragraph, line, sentence end, any
     * whitespace) and only when none exists, mid-word. Whitespace is never
     * dropped or rewritten; it stays where it was in the text.
     *
     * @return string[] Empty array when the text cannot be split within $limit.
     */
    private static function x_split_text(string $text, string $suffix, int $limit): array {
        $chunks  = array();
        $reserve = self::x_weighted_length($suffix);

        while (true) {
            if (self::x_weighted_length($text . $suffix) <= $limit) {
                $chunks[] = $text . $suffix;
                return $chunks;
            }

            // If the rest fits but not together with the link, cut earlier so the
            // last post keeps some text and the link.
            $cut = self::x_weighted_length($text) <= $limit ? $limit - $reserve : $limit;
            $at  = $cut > 0 ? self::x_find_break($text, $cut) : 0;
            if ($at < 1 || $at >= mb_strlen($text)) {
                return array();
            }

            $chunks[] = mb_substr($text, 0, $at);
            $text     = mb_substr($text, $at);
        }
    }

    /**
     * Position (in characters) where to cut $text so the first part weighs at
     * most $limit. Prefers, in order: end of a paragraph, end of a line, end of a
     * sentence, any whitespace; otherwise the longest prefix that fits. The cut
     * is always placed after the whitespace so no character is lost.
     */
    private static function x_find_break(string $text, int $limit): int {
        $low  = 1;
        $high = mb_strlen($text);
        $max  = 0;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if (self::x_weighted_length(mb_substr($text, 0, $mid)) <= $limit) {
                $max = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($max < 1) { return 0; }

        $chars = preg_split('//u', mb_substr($text, 0, $max), -1, PREG_SPLIT_NO_EMPTY) ?: array();

        // The first post must contain something other than whitespace.
        $lead = 0;
        while ($lead < $max && 1 === preg_match('/^\s$/u', $chars[$lead])) { $lead++; }

        $best = array();
        for ($b = $max; $b > $lead; $b--) {
            if (1 !== preg_match('/^\s$/u', $chars[$b - 1])) { continue; }

            $j        = $b - 1;
            $newlines = 0;
            while ($j >= $lead && 1 === preg_match('/^\s$/u', $chars[$j])) {
                if ("\n" === $chars[$j]) { $newlines++; }
                $j--;
            }

            if ($newlines >= 2) {
                $rank = 1;
            } elseif (1 === $newlines) {
                $rank = 2;
            } elseif (1 === preg_match('/^[.!?\x{2026}]$/u', $chars[$j])) {
                $rank = 3;
            } else {
                $rank = 4;
            }
            if (! isset($best[$rank])) { $best[$rank] = $b; }
        }

        if (empty($best)) { return $max; }
        ksort($best);

        return (int) reset($best);
    }
}
