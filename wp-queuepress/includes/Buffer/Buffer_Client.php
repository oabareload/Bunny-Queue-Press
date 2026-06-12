<?php
/**
 * Buffer GraphQL API client.
 *
 * Handles all communication with the official Buffer GraphQL endpoint.
 * Uses Bearer token authentication (manual token configured by the user).
 *
 * Designed to be extended later with publishing methods (posts, threads,
 * carousels, etc.) without modifying the core transport layer.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Sends authenticated GraphQL queries to the Buffer API.
 */
final class Buffer_Client {

	/**
	 * Buffer GraphQL endpoint.
	 */
	private const API_URL = 'https://api.buffer.com';

	/**
	 * Bearer access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * Last request/response captured for debugging.
	 *
	 * @var array<string,mixed>
	 */
	private array $last_request = array();

	/**
	 * Constructs the client with the given Bearer token.
	 *
	 * @param string $access_token Buffer API access token.
	 */
	public function __construct(string $access_token) {
		$this->access_token = $access_token;
	}

	/**
	 * Tests the connection by fetching the account organizations.
	 *
	 * Returns true if the API responds with a valid account object.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		$result = $this->get_organizations();

		return ! empty($result);
	}

	/**
	 * Fetches all organizations linked to the authenticated account.
	 *
	 * @return array<int, array{id: string, name: string, ownerEmail: string}>
	 */
	public function get_organizations(): array {
		$query = '
			query GetOrganizations {
				account {
					organizations {
						id
						name
						ownerEmail
					}
				}
			}
		';

		$data = $this->query($query);

		if (empty($data['data']['account']['organizations'])) {
			return array();
		}

		return (array) $data['data']['account']['organizations'];
	}

	/**
	 * Fetches all channels (social profiles) for the given organization.
	 *
	 * @param string $organization_id Buffer organization ID.
	 * @return array<int, array{id: string, name: string, displayName: string, service: string, avatar: string, isQueuePaused: bool}>
	 */
	public function get_channels(string $organization_id): array {
		$query = '
			query GetChannels {
				channels(input: {
					organizationId: "' . esc_js($organization_id) . '"
				}) {
					id
					name
					displayName
					service
					avatar
					isQueuePaused
				}
			}
		';

		$data = $this->query($query);

		if (empty($data['data']['channels'])) {
			return array();
		}

		return (array) $data['data']['channels'];
	}

	/**
	 * Executes a GraphQL mutation against the Buffer API.
	 *
	 * Unlike query(), this method returns the full decoded response body
	 * including the 'data' key without filtering GraphQL-level errors.
	 * This is necessary for mutations where errors arrive inside 'data'
	 * (e.g. MutationError) rather than in the top-level 'errors' array.
	 *
	 * @param string $mutation GraphQL mutation string.
	 * @return array<string, mixed> Full decoded response body, or empty array on transport error.
	 */
	public function mutate(string $mutation): array {
		if (empty($this->access_token)) {
			return array();
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
		);

		$body = wp_json_encode(array('query' => $mutation));

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 15,
			)
		);

		$wp_error = is_wp_error($response) ? $response : null;
		$status = null;
		$response_headers = array();
		$raw = '';

		if ($wp_error instanceof \WP_Error) {
			// nothing else to extract
		} else {
			$status = wp_remote_retrieve_response_code($response);
			$raw = wp_remote_retrieve_body($response);
			$rh = wp_remote_retrieve_headers($response);
			// Ensure headers are an array for logging.
			$response_headers = is_array($rh) ? $rh : (array) $rh;
		}

		// Capture last request for immediate inspection.
		$this->last_request = array(
			'timestamp'        => gmdate('Y-m-d H:i:s'),
			'endpoint'         => self::API_URL,
			'mutation'         => $mutation,
			'request_body'     => $body,
			'request_headers'  => $headers,
			'http_status'      => $status,
			'response_headers' => $response_headers,
			'response_body'    => $raw,
			'wp_error'         => $wp_error instanceof \WP_Error ? self::wp_error_to_array($wp_error) : null,
		);

		// Optionally persist to Buffer debug log if enabled.
		if (class_exists(__NAMESPACE__ . '\\Buffer_Debug')) {
			// Use Buffer_Debug::add_entry but guard in case the class is absent.
			\QueuePostScheduler\Buffer\Buffer_Debug::add_entry($this->last_request);
		}

		if ($wp_error instanceof \WP_Error) {
			return array();
		}

		if (200 !== (int) $status) {
			return array();
		}

		if (empty($raw)) {
			return array();
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Executes a GraphQL query against the Buffer API.
	 *
	 * All requests use Bearer authentication. HTTP errors and GraphQL-level
	 * errors are normalized into a WP_Error and logged; on failure, an empty
	 * array is returned so callers do not need to check for WP_Error.
	 *
	 * @param string               $query     GraphQL query string.
	 * @param array<string, mixed> $variables Optional GraphQL variables.
	 * @return array<string, mixed> Decoded response body, or empty array on error.
	 */
	private function query(string $query, array $variables = array()): array {
		if (empty($this->access_token)) {
			return array();
		}

		$body = array('query' => $query);

		if (! empty($variables)) {
			$body['variables'] = $variables;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
		);

		$raw_body = wp_json_encode($body);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => $headers,
				'body'    => $raw_body,
				'timeout' => 15,
			)
		);

		$wp_error = is_wp_error($response) ? $response : null;
		$status = null;
		$response_headers = array();
		$raw = '';

		if ($wp_error instanceof \WP_Error) {
			// leave values as initialized
		} else {
			$status = wp_remote_retrieve_response_code($response);
			$raw = wp_remote_retrieve_body($response);
			$rh = wp_remote_retrieve_headers($response);
			$response_headers = is_array($rh) ? $rh : (array) $rh;
		}

		// Capture last request details (query path)
		$this->last_request = array(
			'timestamp'        => gmdate('Y-m-d H:i:s'),
			'endpoint'         => self::API_URL,
			'query'            => $query,
			'variables'        => $variables,
			'request_body'     => $raw_body,
			'request_headers'  => $headers,
			'http_status'      => $status,
			'response_headers' => $response_headers,
			'response_body'    => $raw,
			'wp_error'         => $wp_error instanceof \WP_Error ? self::wp_error_to_array($wp_error) : null,
		);

		if (class_exists(__NAMESPACE__ . '\\Buffer_Debug')) {
			\QueuePostScheduler\Buffer\Buffer_Debug::add_entry($this->last_request);
		}

		if ($wp_error instanceof \WP_Error) {
			return array();
		}

		if (200 !== (int) $status) {
			return array();
		}

		if (empty($raw)) {
			return array();
		}

		$decoded = json_decode($raw, true);

		if (! is_array($decoded)) {
			return array();
		}

		// Surface GraphQL-level errors.
		if (! empty($decoded['errors'])) {
			return array();
		}

		return $decoded;
	}

	/**
	 * Deletes a previously created Buffer post by its remote id.
	 *
	 * Calls the official deletePost mutation. Returns a normalized result:
	 *   - On success: ['success' => true, 'post_id' => $id]
	 *   - On failure: ['success' => false, 'message' => <buffer message>]
	 *
	 * @param string $post_id Buffer post id returned by createPost.
	 * @return array<string, mixed>
	 */
	public function delete_post(string $post_id): array {
		if ($post_id === '') {
			return array(
				'success' => false,
				'message' => __('Missing Buffer post id.', 'wp-queuepress'),
			);
		}
		$mutation = sprintf(
			'mutation { deletePost(input: { id: "%s" }) { ... on DeletePostSuccess { id } ... on VoidMutationError { message } } }',
			esc_js($post_id)
		);
		$response = $this->mutate($mutation);

		$delete_post = $response['data']['deletePost'] ?? null;
		// Success path: DeletePostSuccess resolves to { id }.
		if (is_array($delete_post) && isset($delete_post['id']) && (string) $delete_post['id'] !== '') {
			return array(
				'success' => true,
				'post_id' => (string) $delete_post['id'],
			);
		}
		// Error path: VoidMutationError resolves to { message }.
		if (is_array($delete_post) && isset($delete_post['message'])) {
			return array(
				'success' => false,
				'message' => (string) $delete_post['message'],
			);
		}
		return array(
			'success' => false,
			'message' => __('Unexpected response from Buffer. Please try again.', 'wp-queuepress'),
		);
	}

	/**
	 * Executes an arbitrary GraphQL string (query or mutation) against the Buffer API.
	 *
	 * Reuses the same credentials, headers, timeout, and debug-logging
	 * infrastructure as mutate(). Intended for the Lab playground — callers
	 * should not duplicate transport logic.
	 *
	 * Returns a structured result:
	 *   'http_status' => int|null   — HTTP response code, null on transport error.
	 *   'body'        => array|null — Decoded JSON body, null when unavailable.
	 *   'elapsed_ms'  => int        — Wall-clock time in milliseconds.
	 *   'timestamp'   => string     — UTC datetime of the request.
	 *   'error'       => string|null — WP_Error message, null on success.
	 *
	 * @param string $graphql GraphQL query or mutation string.
	 * @param bool   $log     When true, honours the Buffer_Debug gate. When false, skips logging.
	 * @return array<string,mixed>
	 */
	public function execute_raw_graphql(string $graphql, bool $log = true): array {
		$timestamp = gmdate('Y-m-d H:i:s');
		$start     = microtime(true);

		if (empty($this->access_token)) {
			return array(
				'http_status' => null,
				'body'        => null,
				'elapsed_ms'  => 0,
				'timestamp'   => $timestamp,
				'error'       => __('No Buffer access token configured.', 'wp-queuepress'),
			);
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->access_token,
			'Content-Type'  => 'application/json',
		);

		$body = wp_json_encode(array('query' => $graphql));

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 15,
			)
		);

		$elapsed_ms = (int) round((microtime(true) - $start) * 1000);

		$wp_error         = is_wp_error($response) ? $response : null;
		$status           = null;
		$raw              = '';
		$response_headers = array();

		if (! ($wp_error instanceof \WP_Error)) {
			$status           = wp_remote_retrieve_response_code($response);
			$raw              = wp_remote_retrieve_body($response);
			$rh               = wp_remote_retrieve_headers($response);
			$response_headers = is_array($rh) ? $rh : (array) $rh;
		}

		$log_entry = array(
			'type'             => 'lab_raw_graphql',
			'timestamp'        => $timestamp,
			'endpoint'         => self::API_URL,
			'graphql'          => $graphql,
			'request_body'     => $body,
			'request_headers'  => $headers,
			'http_status'      => $status,
			'response_headers' => $response_headers,
			'response_body'    => $raw,
			'elapsed_ms'       => $elapsed_ms,
			'wp_error'         => $wp_error instanceof \WP_Error ? self::wp_error_to_array($wp_error) : null,
		);

		if ($log && class_exists(__NAMESPACE__ . '\\Buffer_Debug')) {
			\QueuePostScheduler\Buffer\Buffer_Debug::add_entry($log_entry);
		}

		$decoded = null;
		if (! empty($raw)) {
			$decoded = json_decode($raw, true);
			if (! is_array($decoded)) {
				$decoded = null;
			}
		}

		return array(
			'http_status' => $status !== null ? (int) $status : null,
			'body'        => $decoded,
			'elapsed_ms'  => $elapsed_ms,
			'timestamp'   => $timestamp,
			'error'       => $wp_error instanceof \WP_Error ? $wp_error->get_error_message() : null,
		);
	}

	/**
	 * Returns the last captured request/response details.
	 *
	 * @return array<string,mixed>
	 */
	public function get_last_request(): array {
		return $this->last_request;
	}

	/**
	 * Helper to convert a WP_Error into an array for logging.
	 *
	 * @param \WP_Error $err
	 * @return array<string,mixed>
	 */
	private static function wp_error_to_array(\WP_Error $err): array {
		return array(
			'code'    => $err->get_error_code(),
			'message' => $err->get_error_message(),
			'data'    => $err->get_error_data(),
		);
	}
}
