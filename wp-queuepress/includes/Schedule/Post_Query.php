<?php
/**
 * Post retrieval helpers.
 *
 * @package QueuePostScheduler\Schedule
 */

declare(strict_types=1);

namespace QueuePostScheduler\Schedule;

use DateTimeImmutable;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Retrieves scheduled and published posts for calendar views.
 */
final class Post_Query {
	/**
	 * Retrieves posts for a status within a local date range.
	 *
	 * @param string            $status Post status, such as future or publish.
	 * @param DateTimeImmutable $start Start date in the site timezone.
	 * @param DateTimeImmutable $end End date in the site timezone.
	 * @return array<int,\WP_Post>
	 */
	public function get_posts_between(string $status, DateTimeImmutable $start, DateTimeImmutable $end): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => $status,
				'posts_per_page'         => 100,
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'after'     => $start->format('Y-m-d H:i:s'),
						'before'    => $end->format('Y-m-d H:i:s'),
						'inclusive' => true,
						'column'    => 'post_date',
					),
				),
			)
		);

		return $query->posts;
	}
}
