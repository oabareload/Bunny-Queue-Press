<?php
/**
 * Per-channel publishing configuration repository.
 *
 * Single source of truth for:
 *   - User-configurable publishing preferences (stored, editable via UI).
 *   - Provider rules / platform limits (constants, never stored, never editable).
 *   - Field definitions consumed by Buffer_Page to render the UI dynamically.
 *     Buffer_Page must not duplicate any label, description, or limit value.
 *
 * =========================================================================
 * Design principle — two distinct data domains
 * =========================================================================
 *
 *   User preferences (stored in wp_options, editable via UI):
 *     enabled, content_source, image_source, premium_account, post_style
 *
 *   Provider rules (internal constants, never stored, never editable):
 *     max_images, character limits, max_thread_posts
 *     Exposed read-only via limits_for() for UI display and Sprint 3 publishing.
 *
 * =========================================================================
 * User-configurable fields per service
 * =========================================================================
 *
 *   instagram : enabled, content_source, image_source
 *   twitter   : enabled, content_source, premium_account, post_style
 *   threads   : enabled, content_source, post_style
 *   (others)  : enabled only
 *
 * =========================================================================
 * content_source semantics (consumed by Sprint 3 publisher)
 * =========================================================================
 *
 *   'excerpt'   → Publish using the post excerpt as the body text.
 *                 Produces a short, single publication.
 *   'full_post' → Publish using the full post content.
 *                 Sprint 3 will decide whether it fits in a single post
 *                 or requires automatic thread splitting based on platform limits.
 *
 * =========================================================================
 * post_style semantics (consumed by Sprint 3 publisher) — Twitter & Threads only
 * =========================================================================
 *
 *   'social_post' → Standard content-based publication.
 *                   When content_source is 'full_post', Sprint 3 may automatically
 *                   split the content into a thread if it exceeds the platform limit.
 *   'card_link'   → Traffic-oriented publication. Uses title + reduced text + URL +
 *                   thumbnail. Never generates a thread. Text is always trimmed to
 *                   fit within the platform character limit.
 *
 * =========================================================================
 * image_source semantics (Instagram only)
 * =========================================================================
 *
 *   'featured_only'         → Publish only the post's featured image.
 *   'featured_plus_gallery' → Publish the featured image first, then gallery images
 *                             in order, respecting the platform max_images limit.
 *
 * =========================================================================
 * NSFW rules — FUTURE (not yet implemented, not yet configurable)
 * =========================================================================
 *
 *   Instagram:
 *     If the post carries an NSFW tag, ignore gallery images regardless of
 *     image_source setting. Publish only the featured image.
 *
 *   Threads:
 *     Same as Instagram — NSFW posts always use featured image only.
 *
 *   Twitter (X):
 *     No automatic restriction on NSFW content. Publish according to normal rules.
 *
 *   Implementation note: these rules will be enforced in Sprint 3 by the publisher,
 *   not by configuration. No UI toggle should ever expose NSFW handling to the user.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Reads and writes per-channel configuration stored in wp_options.
 */
final class Channel_Config {

	/**
	 * WordPress option key for all channel configurations.
	 */
	public const OPTION_KEY = 'wp_queuepress_channel_config';

	/**
	 * Valid values for the content_source field.
	 */
	private const CONTENT_SOURCE_OPTIONS = array(
		'excerpt',
		'full_post',
	);

	/**
	 * Valid values for the image_source field (Instagram only).
	 */
	private const IMAGE_SOURCE_OPTIONS = array(
		'featured_only',
		'featured_plus_gallery',
	);

	/**
	 * Valid values for the post_style field (Twitter and Threads only).
	 *
	 * 'social_post' → standard content-based publication; Sprint 3 may auto-thread.
	 * 'card_link'   → traffic-oriented; never threads; always trims to platform limit.
	 */
	private const POST_STYLE_OPTIONS = array(
		'social_post',
		'card_link',
	);

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns all stored channel configurations, indexed by Channel ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all(): array {
		$stored = get_option(self::OPTION_KEY, array());

		return is_array($stored) ? $stored : array();
	}

	/**
	 * Returns the configuration for a single channel, merged with service defaults.
	 *
	 * Records missing new fields (e.g. post_style added after initial save)
	 * automatically receive their correct default via the merge with defaults_for().
	 *
	 * @param string $channel_id Buffer Channel ID.
	 * @param string $service    Buffer service slug (e.g. 'instagram').
	 * @return array<string, mixed>
	 */
	public function get(string $channel_id, string $service): array {
		$all    = $this->get_all();
		$stored = isset($all[$channel_id]) && is_array($all[$channel_id]) ? $all[$channel_id] : array();

		return array_merge($this->defaults_for($service), $stored);
	}

	/**
	 * Sanitizes raw POST input and persists the configuration for one channel.
	 *
	 * Receives the full channel form submission. Only user-configurable fields
	 * are accepted. Provider rules (limits_for) are never read from POST data.
	 *
	 * @param string               $channel_id Buffer Channel ID.
	 * @param string               $service    Buffer service slug.
	 * @param array<string, mixed> $raw        Raw POST data for this channel.
	 * @return bool True on success.
	 */
	public function save(string $channel_id, string $service, array $raw): bool {
		if (empty($channel_id) || empty($service)) {
			return false;
		}

		$sanitized        = $this->sanitize($service, $raw);
		$all              = $this->get_all();
		$all[$channel_id] = $sanitized;

		return (bool) update_option(self::OPTION_KEY, $all);
	}

	/**
	 * Returns the user-configurable field definitions for the given service.
	 *
	 * This is the single source of truth for the UI. Buffer_Page must render
	 * labels, descriptions, options, and help text exclusively from this method.
	 * No field text may be duplicated in Buffer_Page or any other template.
	 *
	 * Each field definition is an array with the following keys:
	 *   'type'        (string)              'select' | 'checkbox'
	 *   'label'       (string)              Translatable field label.
	 *   'description' (string)              Translatable help text shown below the field.
	 *   'options'     (array, select only)  value => label pairs.
	 *   'option_descriptions' (array, optional) value => translatable description per option.
	 *
	 * Platform limits (max_images, character limits) are NOT included here.
	 * Use limits_for() for those — Buffer_Page renders them from there.
	 *
	 * @param string $service Buffer service slug.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields_for(string $service): array {
		$content_source_field = array(
			'content_source' => array(
				'type'        => 'select',
				'label'       => __('Content source', 'wp-queuepress'),
				'description' => __('Determines which part of the post is used as the publication body.', 'wp-queuepress'),
				'options'     => array(
					'excerpt'   => __('Excerpt', 'wp-queuepress'),
					'full_post' => __('Full post', 'wp-queuepress'),
				),
				'option_descriptions' => array(
					'excerpt'   => __('Publishes using the post excerpt. Always produces a single, short publication.', 'wp-queuepress'),
					'full_post' => __('Publishes the full post content. The system will split it automatically if needed.', 'wp-queuepress'),
				),
			),
		);

		$image_source_field = array(
			'image_source' => array(
				'type'        => 'select',
				'label'       => __('Image source', 'wp-queuepress'),
				'description' => __('Controls which images are attached to the publication.', 'wp-queuepress'),
				'options'     => array(
					'featured_only'         => __('Featured only', 'wp-queuepress'),
					'featured_plus_gallery' => __('Featured + gallery', 'wp-queuepress'),
				),
				'option_descriptions' => array(
					'featured_only'         => __('Publishes only the featured image.', 'wp-queuepress'),
					'featured_plus_gallery' => __('Publishes the featured image first, followed by gallery images in order, up to the platform image limit.', 'wp-queuepress'),
				),
			),
		);

		$post_style_field = array(
			'post_style' => array(
				'type'        => 'select',
				'label'       => __('Post style', 'wp-queuepress'),
				'description' => __('Controls how the content is formatted when published.', 'wp-queuepress'),
				'options'     => array(
					'social_post' => __('Social post', 'wp-queuepress'),
					'card_link'   => __('Card link', 'wp-queuepress'),
				),
				'option_descriptions' => array(
					'social_post' => __('Standard content-based publication. If the content is long, it may be automatically split into a thread.', 'wp-queuepress'),
					'card_link'   => __('Traffic-oriented publication using title, short text, URL, and thumbnail. Never generates a thread. Text is trimmed to fit platform limits.', 'wp-queuepress'),
				),
			),
		);

		$premium_account_field = array(
			'premium_account' => array(
				'type'        => 'checkbox',
				'label'       => __('Premium account', 'wp-queuepress'),
				'description' => __('Enable if you have a Premium X/Twitter subscription. Increases the character limit to 25,000.', 'wp-queuepress'),
			),
		);

		switch ($service) {
			case 'instagram':
				// post_style is NOT available for Instagram.
				// Instagram publications are always content-based; style is
				// determined by image_source and platform constraints.
				return array_merge(
					$content_source_field,
					$image_source_field
				);

			case 'twitter':
				return array_merge(
					$content_source_field,
					$premium_account_field,
					$post_style_field
				);

			case 'threads':
				return array_merge(
					$content_source_field,
					$post_style_field
				);

			default:
				return array();
		}
	}

	/**
	 * Returns the platform limits for the given service.
	 *
	 * This is the single source of truth for all platform constraint values.
	 * Buffer_Page must render the "Platform limits" section exclusively from
	 * this method. No limit value may be hardcoded in any template or UI file.
	 *
	 * Each limit entry is an array with the following keys:
	 *   'label' (string) Translatable human-readable label for the UI.
	 *   'value' (int)    The numeric limit value.
	 *
	 * Used by:
	 *   - Buffer_Page: render as read-only informational list.
	 *   - Sprint 3 publisher: enforce constraints during content preparation.
	 *
	 * @param string $service Buffer service slug.
	 * @return array<string, array{label: string, value: int}>
	 */
	public function limits_for(string $service): array {
		switch ($service) {
			case 'instagram':
				return array(
					'max_images'      => array(
						'label' => __('Maximum images', 'wp-queuepress'),
						'value' => 10,
					),
					'character_limit' => array(
						'label' => __('Maximum characters', 'wp-queuepress'),
						'value' => 2196,
					),
				);

			case 'twitter':
				return array(
					'max_images'              => array(
						'label' => __('Maximum images', 'wp-queuepress'),
						'value' => 4,
					),
					'max_thread_posts'        => array(
						'label' => __('Maximum thread posts', 'wp-queuepress'),
						'value' => 25,
					),
					'character_limit'         => array(
						'label' => __('Standard characters', 'wp-queuepress'),
						'value' => 280,
					),
					'character_limit_premium' => array(
						'label' => __('Premium characters', 'wp-queuepress'),
						'value' => 25000,
					),
				);

			case 'threads':
				return array(
					'max_images'      => array(
						'label' => __('Maximum images', 'wp-queuepress'),
						'value' => 20,
					),
					'character_limit' => array(
						'label' => __('Maximum characters', 'wp-queuepress'),
						'value' => 500,
					),
				);

			default:
				return array();
		}
	}

	/**
	 * Returns the default user-configurable settings for the given service.
	 *
	 * Only preferences are included. Provider rules are never stored.
	 * Acts as the merge base in get() — records missing new fields receive
	 * their correct default automatically without a migration.
	 *
	 * @param string $service Buffer service slug.
	 * @return array<string, mixed>
	 */
	public function defaults_for(string $service): array {
		$base = array(
			'provider' => 'buffer',
			'service'  => $service,
			'enabled'  => false,
		);

		switch ($service) {
			case 'instagram':
				// No post_style for Instagram.
				return array_merge($base, array(
					'content_source' => 'excerpt',
					'image_source'   => 'featured_only',
				));

			case 'twitter':
				return array_merge($base, array(
					'content_source'  => 'excerpt',
					'premium_account' => false,
					'post_style'      => 'social_post',
				));

			case 'threads':
				return array_merge($base, array(
					'content_source' => 'excerpt',
					'post_style'     => 'social_post',
				));

			default:
				return $base;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitizes raw POST data for the given service.
	 *
	 * Receives the full channel form. Only user-configurable fields are read
	 * and persisted. Provider rules are never present in the output.
	 *
	 * @param string               $service Buffer service slug.
	 * @param array<string, mixed> $raw     Raw input array (full form submission).
	 * @return array<string, mixed>
	 */
	private function sanitize(string $service, array $raw): array {
		$defaults = $this->defaults_for($service);
		$out      = array(
			'provider' => 'buffer',
			'service'  => sanitize_key($service),
			'enabled'  => ! empty($raw['enabled']),
		);

		// content_source — all supported services.
		if (isset($defaults['content_source'])) {
			$val                   = isset($raw['content_source']) ? sanitize_key($raw['content_source']) : '';
			$out['content_source'] = in_array($val, self::CONTENT_SOURCE_OPTIONS, true)
				? $val
				: $defaults['content_source'];
		}

		// image_source — Instagram only.
		if (isset($defaults['image_source'])) {
			$val               = isset($raw['image_source']) ? sanitize_key($raw['image_source']) : '';
			$out['image_source'] = in_array($val, self::IMAGE_SOURCE_OPTIONS, true)
				? $val
				: $defaults['image_source'];
		}

		// premium_account — Twitter only.
		if (isset($defaults['premium_account'])) {
			$out['premium_account'] = ! empty($raw['premium_account']);
		}

		// post_style — Twitter and Threads only (not Instagram).
		if (isset($defaults['post_style'])) {
			$val             = isset($raw['post_style']) ? sanitize_key($raw['post_style']) : '';
			$out['post_style'] = in_array($val, self::POST_STYLE_OPTIONS, true)
				? $val
				: $defaults['post_style'];
		}

		return $out;
	}
}
