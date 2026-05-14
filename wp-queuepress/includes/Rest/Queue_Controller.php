<?php
/**
 * REST endpoint for next-slot lookup.
 *
 * @package QueuePostScheduler\Rest
 */

declare(strict_types=1);

namespace QueuePostScheduler\Rest;

use QueuePostScheduler\Schedule\Queue_Assigner;

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
	 * Builds the controller.
	 *
	 * @param Queue_Assigner $queue_assigner Slot finder service.
	 */
	public function __construct(Queue_Assigner $queue_assigner) {
		$this->queue_assigner = $queue_assigner;
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
		$slot    = $this->queue_assigner->find_next_slot($post_id);

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
			)
		);
	}
}
