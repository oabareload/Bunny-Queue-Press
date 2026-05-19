<?php
/**
 * REST endpoint for next-slot lookup.
 *
 * @package QueuePostScheduler\Rest
 */

declare(strict_types=1);

namespace QueuePostScheduler\Rest;

use QueuePostScheduler\Schedule\Queue_Assigner;
use QueuePostScheduler\Schedule\Queue_Rebuilder;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles editor requests for the next free publishing slot.
 *
 * 1.1.0: this endpoint is read-only from a scheduling perspective.
 * It returns a { date, time } payload. The Gutenberg editor applies
 * that date via editPost({ date }) — no wp_update_post here.
 */
final class Queue_Controller {
	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'wp-queuepress/v1';

	/**
	 * Queue assignment service.
	 *
	 * @var Queue_Assigner
	 */
	private Queue_Assigner $queue_assigner;

	/**
	 * Queue rebuilder service (used for add-first preview).
	 *
	 * @var Queue_Rebuilder
	 */
	private Queue_Rebuilder $queue_rebuilder;

	/**
	 * Builds the controller.
	 *
	 * @param Queue_Assigner  $queue_assigner  Slot finder service.
	 * @param Queue_Rebuilder $queue_rebuilder Queue rebuilder service.
	 */
	public function __construct(Queue_Assigner $queue_assigner, Queue_Rebuilder $queue_rebuilder) {
		$this->queue_assigner  = $queue_assigner;
		$this->queue_rebuilder = $queue_rebuilder;
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Registers the next-slot route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/next-slot',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_next_slot'),
				'permission_callback' => array($this, 'can_queue_post'),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'mode' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'add_to_queue',
						'sanitize_callback' => 'sanitize_key',
						'enum'              => array('add_to_queue', 'add_first'),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/add-first-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_add_first_preview'),
				'permission_callback' => array($this, 'can_queue_post'),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/queue-mode',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_queue_mode'),
				'permission_callback' => array($this, 'can_queue_post'),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Verifies that the current user can edit and publish the requested post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function can_queue_post(\WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$post    = get_post($post_id);

		if (! $post instanceof \WP_Post || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return new \WP_Error(
				'wp_queuepress_invalid_post',
				__('This post cannot be queued for publishing.', 'wp-queuepress'),
				array('status' => 400)
			);
		}

		$post_type = get_post_type_object($post->post_type);

		return current_user_can('edit_post', $post_id)
			&& $post_type
			&& ! empty($post_type->cap->publish_posts)
			&& current_user_can($post_type->cap->publish_posts);
	}

	/**
	 * Returns the next available slot for the requested post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_next_slot(\WP_REST_Request $request) {
		$post_id = (int) $request['id'];
		$mode    = (string) $request['mode'];
		$slot    = 'add_first' === $mode
			? $this->queue_assigner->find_first_queue_slot()
			: $this->queue_assigner->find_next_slot($post_id);

		if (null === $slot) {
			return new \WP_Error(
				'wp_queuepress_no_slots',
				__('No free publishing slots are currently available.', 'wp-queuepress'),
				array('status' => 404)
			);
		}

		return rest_ensure_response(
			array(
				'date' => $slot->format('Y-m-d'),
				'time' => $slot->format('H:i'),
				'iso'  => $slot->format('Y-m-d\TH:i:s'),
				'mode' => $mode,
			)
		);
	}

	/**
	 * Returns a preview of the full queue rebuild that add_first would produce.
	 *
	 * The new post (id) is placed first; all existing scheduled posts follow
	 * in their current relative order with new assigned dates.
	 * This is compute-only — nothing is saved.
	 *
	 * Response shape:
	 * {
	 *   new_post: { id, title, new_date, new_date_label },
	 *   affected:  [ { id, title, old_date_label, new_date_label }, ... ]
	 * }
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_add_first_preview(\WP_REST_Request $request) {
		$post_id  = (int) $request['id'];
		$timezone = wp_timezone();

		// compute_add_first_plan requires the post to already be `future`.
		// At preview time the post is still a draft, so we simulate: build
		// the plan as if the new post occupies the very first available slot,
		// then list the existing scheduled posts shifted after it.
		$plan = $this->queue_rebuilder->compute_add_first_preview($post_id);

		if (empty($plan)) {
			return new \WP_Error(
				'wp_queuepress_no_slots',
				__('No free publishing slots are currently available.', 'wp-queuepress'),
				array('status' => 404)
			);
		}

		$format_label = static function (string $date_str) use ($timezone): string {
			try {
				$dt = new \DateTimeImmutable($date_str, $timezone);
				return $dt->format('D, M j Y \a\t g:i A');
			} catch (\Throwable $e) {
				return $date_str;
			}
		};

		$new_item    = array_shift($plan);
		$new_post    = get_post($new_item['post_id']);
		$new_post_dt = new \DateTimeImmutable($new_item['new_date'], $timezone);

		$affected = array();
		foreach ($plan as $item) {
			$p = get_post($item['post_id']);
			if (! $p instanceof \WP_Post) { continue; }
			$affected[] = array(
				'id'            => $item['post_id'],
				'title'         => get_the_title($p),
				'old_date_label'=> $format_label($item['old_date']),
				'new_date_label'=> $format_label($item['new_date']),
			);
		}

		return rest_ensure_response(
			array(
				'new_post' => array(
					'id'            => $new_item['post_id'],
					'title'         => $new_post instanceof \WP_Post ? get_the_title($new_post) : '',
					'new_date'      => $new_post_dt->format('Y-m-d\TH:i:s'),
					'new_date_label'=> $format_label($new_item['new_date']),
				),
				'affected' => $affected,
			)
		);
	}

	/**
	 * Deletes queue intent metadata for the requested post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function delete_queue_mode(\WP_REST_Request $request): \WP_REST_Response {
		delete_post_meta((int) $request['id'], '_wp_queuepress_queue_mode');

		return rest_ensure_response(array('deleted' => true));
	}
}
