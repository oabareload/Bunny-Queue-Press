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
                // slot contains 'date' and 'time'
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
                // No slots left within search window: record as conflict.
                $conflicts[] = array('post_id' => (int) $post->ID);
                continue;
            }

            $slot_dt = array_shift($available_slots);

            $old_date = (string) $post->post_date; // local site time string
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
            'version'    => '2.0.0',
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
