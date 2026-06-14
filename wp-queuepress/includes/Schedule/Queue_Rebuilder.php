<?php
/**
 * Deterministic queue rebuilder (compute-only planner).
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use DateTimeImmutable;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Queue_Rebuilder {
    private Slot_Repository $slot_repository;
    private Post_Query $post_query;
    private Schedule_Calculator $schedule_calculator;
    private \QueuePostScheduler\Settings\Preferences $preferences;

    private const SEARCH_WEEKS = 52;

    public function __construct(Slot_Repository $slot_repository, Post_Query $post_query, Schedule_Calculator $schedule_calculator, \QueuePostScheduler\Settings\Preferences $preferences) {
        $this->slot_repository     = $slot_repository;
        $this->post_query          = $post_query;
        $this->schedule_calculator = $schedule_calculator;
        $this->preferences         = $preferences;
    }

    /**
     * Compute a deterministic rebuild plan for all scheduled (future) posts.
     * Does not modify posts — compute-only. Persists plan to option `qps_pending_rebuild`.
     *
     * @return array Plan structure.
     */
    public function compute_and_persist_plan(): array {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);

        // Query all future posts deterministically: post_date ASC, ID ASC.
        $query = new \WP_Query(
            array(
                'post_type'           => 'post',
                'post_status'         => 'future',
                'posts_per_page'      => -1,
                'orderby'             => array('date' => 'ASC', 'ID' => 'ASC'),
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $posts = $query->posts;

        // Prepare in-memory occupied posts list. Start with a harmless sentinel
        // post so Schedule_Calculator does not re-query real posts when an
        // empty array is passed. The sentinel date is far in the past.
        $occupied_posts = array((object) array('post_date' => '1970-01-01 00:00:00', 'ID' => 0));

        // Collect available slots across SEARCH_WEEKS weeks.
        $available_slots = array();
        $week_start = $this->get_start_of_week($now);

        for ($week = 0; $week < self::SEARCH_WEEKS; $week++) {
            $start = $week_start->modify('+' . $week . ' weeks');
            $end = $start->modify('+6 days')->setTime(23, 59, 59);

            $free = $this->schedule_calculator->get_free_slots($start, $end, $occupied_posts);

            foreach ($free as $slot) {
                $slot_dt = new DateTimeImmutable($slot['date'] . ' ' . $slot['time'], $timezone);
                if ($slot_dt > $now) {
                    $available_slots[] = $slot_dt;
                }
            }
        }

        $plan_items = array();
        $conflicts = array();

        // Assign slots in order to scheduled posts.
        foreach ($posts as $post) {
            /** @var WP_Post $post */
            if (empty($available_slots)) {
                $conflicts[] = array('post_id' => (int) $post->ID);
                continue;
            }

            $slot_dt = array_shift($available_slots);

            $old_date = (string) $post->post_date;
            $new_date = $slot_dt->format(DATE_ATOM);

            $plan_items[] = array(
                'post_id'  => (int) $post->ID,
                'old_date' => $old_date,
                'new_date' => $new_date,
            );

            // Reserve slot by adding a synthetic occupied post so subsequent
            // weeks see it as occupied.
            $occupied_posts[] = (object) array('post_date' => $slot_dt->format('Y-m-d H:i:s'), 'ID' => (int) $post->ID);
        }

        $plan = array(
            'version'    => '2.1.1',
            'created_at' => (new DateTimeImmutable('now', wp_timezone()))->format(DATE_ATOM),
            'total'      => count($plan_items),
            'pointer'    => 0,
            'plan'       => $plan_items,
            'conflicts'  => $conflicts,
        );

        update_option('qps_pending_rebuild', $plan);

        return $plan;
    }

    /**
     * Computes a preview of the Add First rebuild WITHOUT requiring the post
     * to be already `future`. Safe to call from the REST preview endpoint
     * while the post is still a draft.
     *
     * The new post is placed first using the first available slot;
     * existing scheduled posts follow in their current relative order.
     * Nothing is written to the DB.
     *
     * @param int $post_id The post being queued (still draft at this point).
     * @return array<int,array<string,mixed>> Plan items: post_id, old_date, new_date.
     */
    public function compute_add_first_preview(int $post_id): array {
        $new_post = get_post($post_id);

        if (! $new_post instanceof WP_Post) {
            return array();
        }

        // Query all existing scheduled posts, excluding the new one (it is
        // still a draft, but exclude by ID defensively).
        $query = new \WP_Query(
            array(
                'post_type'              => $new_post->post_type,
                'post_status'            => 'future',
                'posts_per_page'         => -1,
                'orderby'                => array('date' => 'ASC', 'ID' => 'ASC'),
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $scheduled_posts = array_values(
            array_filter(
                $query->posts,
                static function (WP_Post $post) use ($post_id): bool {
                    return (int) $post->ID !== $post_id;
                }
            )
        );

        // Total slots needed: 1 (new post) + all existing scheduled posts.
        $total = 1 + count($scheduled_posts);
        $slots = $this->get_future_slots_for_plan($total);

        if (count($slots) < $total) {
            return array();
        }

        $plan = array();

        // Slot 0 -> new post.
        $slot_dt = array_shift($slots);
        $plan[]  = array(
            'post_id'  => (int) $new_post->ID,
            'old_date' => (string) $new_post->post_date, // draft date, for reference
            'new_date' => $slot_dt->format(DATE_ATOM),
        );

        // Remaining slots -> existing scheduled posts in relative order.
        foreach ($scheduled_posts as $post) {
            $slot_dt = array_shift($slots);
            $plan[]  = array(
                'post_id'  => (int) $post->ID,
                'old_date' => (string) $post->post_date,
                'new_date' => $slot_dt->format(DATE_ATOM),
            );
        }

        return $plan;
    }

    /**
     * Computes a swap plan between two future posts.
     *
     * Exchanges the post_date of the two posts. The result is a 2-item plan
     * in the exact shape consumed by apply_plan(): no row is added or removed
     * from the queue, only two timestamps are permuted.
     *
     * The plan is compute-only; nothing is written to the database. No call
     * to wp_update_post is made here.
     *
     * Validation (short-circuits on first failure, returns []):
     *  - Both IDs must be > 0.
     *  - IDs must be different.
     *  - Both posts must exist.
     *  - Both must be in 'future' status.
     *  - Both must share the same post_type.
     *  - Both post_date values must be different (defensive).
     *
     * @param int $post_a_id First post ID.
     * @param int $post_b_id Second post ID.
     * @return array<int,array<string,mixed>> Plan items in apply_plan() shape, or [] on rejection.
     */
    public function compute_swap_plan(int $post_a_id, int $post_b_id): array {
        if ($post_a_id <= 0 || $post_b_id <= 0) {
            return array();
        }

        if ($post_a_id === $post_b_id) {
            return array();
        }

        clean_post_cache($post_a_id);
        clean_post_cache($post_b_id);

        $post_a = get_post($post_a_id);
        $post_b = get_post($post_b_id);

        if (! $post_a instanceof WP_Post || ! $post_b instanceof WP_Post) {
            return array();
        }

        if ('future' !== $post_a->post_status || 'future' !== $post_b->post_status) {
            return array();
        }

        if ($post_a->post_type !== $post_b->post_type) {
            return array();
        }

        if ((string) $post_a->post_date === (string) $post_b->post_date) {
            return array();
        }

        $a_old = (string) $post_a->post_date;
        $b_old = (string) $post_b->post_date;

        return array(
            array(
                'post_id'  => (int) $post_a->ID,
                'old_date' => $a_old,
                'new_date' => $b_old,
            ),
            array(
                'post_id'  => (int) $post_b->ID,
                'old_date' => $b_old,
                'new_date' => $a_old,
            ),
        );
    }

    /**
     * Computes an Add First plan with the new post inserted at the front.
     *
     * Runs AFTER the new post is already `future` in the DB (called from
     * Queue_Commit_Handler::commit_queue_mode). Reads fresh post_date values
     * via clean_post_cache so old_date matches actual DB state.
     *
     * The current scheduled posts keep their existing relative order.
     * The returned plan is compute-only and safe to pass directly to apply_plan().
     *
     * @param int $post_id Newly scheduled post ID.
     * @return array<int,array<string,mixed>> Rebuild plan items.
     */
    public function compute_add_first_plan(int $post_id): array {
        // Flush object cache so we read the actual current DB state.
        clean_post_cache($post_id);
        $new_post = get_post($post_id);

        if (! $new_post instanceof WP_Post) {
            return array();
        }

        // Query ALL currently scheduled posts of the same type.
        $query = new \WP_Query(
            array(
                'post_type'              => $new_post->post_type,
                'post_status'            => 'future',
                'posts_per_page'         => -1,
                'orderby'                => array('date' => 'ASC', 'ID' => 'ASC'),
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        // All currently scheduled posts, excluding the new post.
        $scheduled_posts = array_values(
            array_filter(
                $query->posts,
                static function (WP_Post $post) use ($post_id): bool {
                    return (int) $post->ID !== $post_id;
                }
            )
        );

        // New post goes first; existing scheduled posts follow in relative order.
        $queue_posts = array_merge(array($new_post), $scheduled_posts);

        // Get exactly as many unique, non-overlapping future slots as we need.
        $slots = $this->get_future_slots_for_plan(count($queue_posts));

        if (count($slots) < count($queue_posts)) {
            return array();
        }

        $plan = array();

        foreach ($queue_posts as $post) {
            // Re-read post_date fresh so old_date is always accurate.
            clean_post_cache((int) $post->ID);
            $fresh      = get_post((int) $post->ID);
            $old_date   = $fresh instanceof WP_Post ? (string) $fresh->post_date : (string) $post->post_date;

            $slot_dt = array_shift($slots);

            $plan[] = array(
                'post_id'  => (int) $post->ID,
                'old_date' => $old_date,
                'new_date' => $slot_dt->format(DATE_ATOM),
            );
        }

        return $plan;
    }

    /**
     * Applies precomputed post-date changes without recalculating the plan.
     *
     * Eligible post statuses: future, draft, pending, private.
     * Published posts are never touched.
     *
     * @param array<int,array<string,mixed>> $plan Rebuild plan items.
     * @return array<string,mixed> Application summary.
     */
    public function apply_plan(array $plan): array {
        $results = array('total' => count($plan), 'applied' => 0, 'conflicts' => array());

        foreach ($plan as $item) {
            $post_id  = isset($item['post_id']) ? (int) $item['post_id'] : 0;
            $old_date = isset($item['old_date']) ? (string) $item['old_date'] : '';
            $new_date = isset($item['new_date']) ? (string) $item['new_date'] : '';

            if ($post_id <= 0) {
                $results['conflicts'][] = array('post_id' => $post_id, 'message' => 'Invalid post id');
                continue;
            }

            $post = get_post($post_id);
            if (! $post instanceof WP_Post) {
                $results['conflicts'][] = array('post_id' => $post_id, 'message' => 'Post not found');
                continue;
            }

            // Only touch posts that are not yet published.
            if (! in_array($post->post_status, array('future', 'draft', 'pending', 'private'), true)) {
                $results['conflicts'][] = array(
                    'post_id' => $post_id,
                    'message' => 'Post status not eligible for queue move: ' . $post->post_status,
                );
                continue;
            }

            if ($old_date && $post->post_date !== $old_date) {
                // Log the discrepancy but continue — old_date may differ legitimately
                // when the same post appears earlier in the plan and was already updated.
                $results['conflicts'][] = array(
                    'post_id'           => $post_id,
                    'message'           => 'Current post_date differs from plan old_date (non-blocking)',
                    'post_date'         => $post->post_date,
                    'expected_old_date' => $old_date,
                );
            }

            // Parse new_date as a site-timezone datetime. The value arriving
            // here is always a local post_date string (no UTC offset marker)
            // or a DATE_ATOM string from a plan item. Both cases are handled
            // by creating the DateTimeImmutable in the site timezone explicitly
            // so PHP never guesses the timezone.
            try {
                $tz             = wp_timezone();
                $dt             = new DateTimeImmutable($new_date, $tz);
                // Normalise to site timezone to get the canonical local string.
                $dt             = $dt->setTimezone($tz);
                $new_date_local = $dt->format('Y-m-d H:i:s');
                // GMT equivalent — built from the same DateTimeImmutable so
                // no string-parsing ambiguity is introduced.
                $dt_utc         = $dt->setTimezone(new \DateTimeZone('UTC'));
                $new_date_gmt   = $dt_utc->format('Y-m-d H:i:s');
            } catch (\Throwable $ex) {
                $results['conflicts'][] = array('post_id' => $post_id, 'message' => 'Invalid new_date format', 'new_date' => $new_date);
                continue;
            }

            // Safety gate: re-read the post from the DB immediately before writing
            // to catch any status change that occurred between plan computation and
            // now (e.g. another process published it, or a swap of a near-term post
            // whose date slipped into the past).
            clean_post_cache($post_id);
            $fresh_post = get_post($post_id);
            if (! $fresh_post instanceof WP_Post || 'future' !== $fresh_post->post_status) {
                $results['conflicts'][] = array(
                    'post_id' => $post_id,
                    'message' => 'Post is no longer future at write time — skipped to prevent accidental publication.',
                    'status'  => $fresh_post instanceof WP_Post ? $fresh_post->post_status : 'missing',
                );
                continue;
            }

            // Guard: new_date must be strictly in the future. Compare two
            // DateTimeImmutable objects both in UTC so no timezone offset can
            // skew the result. $dt_utc was built from the same $dt above;
            // $now_utc is constructed fresh to minimise the window between
            // plan computation and this write-time check.
            $now_utc = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($dt_utc <= $now_utc) {
                $results['conflicts'][] = array(
                    'post_id'      => $post_id,
                    'message'      => 'new_date is not in the future — skipped to prevent accidental publication.',
                    'new_date_gmt' => $new_date_gmt,
                    'now_gmt'      => $now_utc->format('Y-m-d H:i:s'),
                );
                continue;
            }

            $update = wp_update_post(
                array(
                    'ID'            => $post_id,
                    'post_date'     => $new_date_local,
                    'post_date_gmt' => $new_date_gmt,
                ),
                true
            );

            if (is_wp_error($update)) {
                $results['conflicts'][] = array('post_id' => $post_id, 'message' => $update->get_error_message());
                continue;
            }

            $results['applied']++;
        }

        return $results;
    }

    /**
     * Returns $limit unique future slots, reserving each one as it is assigned
     * so no two slots ever collide (correct method for full-queue plans).
     *
     * @param int $limit Number of slots needed.
     * @return array<int,DateTimeImmutable>
     */
    private function get_future_slots_for_plan(int $limit): array {
        $timezone       = wp_timezone();
        $now            = new DateTimeImmutable('now', $timezone);
        $week_start     = $this->get_start_of_week($now);
        $occupied_posts = array((object) array('post_date' => '1970-01-01 00:00:00', 'ID' => 0));
        $slots          = array();

        for ($week = 0; $week < self::SEARCH_WEEKS && count($slots) < $limit; $week++) {
            $start = $week_start->modify('+' . $week . ' weeks');
            $end   = $start->modify('+6 days')->setTime(23, 59, 59);
            $free  = $this->schedule_calculator->get_free_slots($start, $end, $occupied_posts);

            foreach ($free as $slot) {
                $slot_dt = new DateTimeImmutable($slot['date'] . ' ' . $slot['time'], $timezone);

                if ($slot_dt <= $now) {
                    continue;
                }

                $slots[] = $slot_dt;

                // Mark this slot as occupied immediately so the next iteration
                // sees it and never returns the same datetime again.
                $occupied_posts[] = (object) array(
                    'post_date' => $slot_dt->format('Y-m-d H:i:s'),
                    'ID'        => count($slots), // synthetic unique ID
                );

                if (count($slots) >= $limit) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * Returns the next configured future slots in deterministic order.
     * NOTE: Does NOT reserve slots between iterations — use get_future_slots_for_plan()
     * when building multi-post plans to avoid duplicate slots.
     *
     * @param int $limit Number of slots needed.
     * @return array<int,DateTimeImmutable>
     */
    private function get_future_slots(int $limit): array {
        $timezone       = wp_timezone();
        $now            = new DateTimeImmutable('now', $timezone);
        $week_start     = $this->get_start_of_week($now);
        $occupied_posts = array((object) array('post_date' => '1970-01-01 00:00:00', 'ID' => 0));
        $slots          = array();

        for ($week = 0; $week < self::SEARCH_WEEKS && count($slots) < $limit; $week++) {
            $start = $week_start->modify('+' . $week . ' weeks');
            $end   = $start->modify('+6 days')->setTime(23, 59, 59);
            $free  = $this->schedule_calculator->get_free_slots($start, $end, $occupied_posts);

            foreach ($free as $slot) {
                $slot_dt = new DateTimeImmutable($slot['date'] . ' ' . $slot['time'], $timezone);

                if ($slot_dt > $now) {
                    $slots[] = $slot_dt;
                }

                if (count($slots) >= $limit) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * Returns the start of week respecting preferences.
     *
     * @param DateTimeImmutable $date
     * @return DateTimeImmutable
     */
    private function get_start_of_week(DateTimeImmutable $date): DateTimeImmutable {
        if ($this->preferences->starts_on_sunday()) {
            $modifier = '0' === $date->format('w') ? 'today' : 'last sunday';
        } else {
            $modifier = '1' === $date->format('N') ? 'today' : 'last monday';
        }

        return $date->modify($modifier)->setTime(0, 0, 0);
    }
}