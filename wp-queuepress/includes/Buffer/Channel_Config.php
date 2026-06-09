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
 *     enabled, premium_account, post_style
 *
 *   Provider rules (internal constants, never stored, never editable):
 *     max_images, character limits, max_thread_posts
 *     Exposed read-only via limits_for() for UI display and Sprint 3 publishing.
 *
 * =========================================================================
 * User-configurable fields per service
 * =========================================================================
 *
 *   instagram : enabled
 *   twitter   : enabled, premium_account, post_style
 *   threads   : enabled, post_style
 *   (others)  : enabled only
 *
 * =========================================================================
 * post_style semantics (consumed by Sprint 3 publisher) — Twitter & Threads only
 * =========================================================================
 *
 *   'social_post' → Standard content-based publication.
 *                   The system assembles the caption from the post excerpt
 *                   (first element of the thread) and the full post content
 *                   (subsequent elements), automatically splitting into a
 *                   thread when the body exceeds the platform limit.
 *   'card_link'   → Traffic-oriented publication. Uses the post excerpt as
 *                   the body, the post URL as the link, the SEO title when
 *                   available, and the post thumbnail. Never generates a
 *                   thread. Text is always trimmed to fit within the
 *                   platform character limit.
 *
 * =========================================================================
 * Content & image source — internal system rules (NOT configurable)
 * =========================================================================
 *
 *   Content source:
 *     - Card Link             → excerpt
 *     - Social Post (all)     → excerpt + full_post
 *     - Twitter/Threads hilo  → element 0 = excerpt (+ permalink + hashtags);
 *                               elements 1..N = full_post (no permalink,
 *                               no hashtags, no title).
 *
 *   Image source:
 *     - Instagram SFW         → featured + gallery
 *     - Instagram NSFW        → featured only
 *     - Twitter               → featured + gallery (NSFW does NOT restrict)
 *     - Threads SFW           → featured + gallery
 *     - Threads NSFW          → forced to Card Link → featured only
 *
 *   These rules are enforced by the publishers, not by configuration.
 *   No UI toggle should ever expose content_source / image_source /
 *   NSFW handling to the user.
 *
 * =========================================================================
 * Legacy migration
 * =========================================================================
 *
 *   `content_source` and `image_source` were removed from the user-
 *   configurable schema. migrate_legacy_config() runs once on the first
 *   load with the new version (gated by a stored schema version flag) and
 *   strips those keys from the persisted option.
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
	 * Option key for the persisted schema version (used to gate migrations).
	 */
	public const SCHEMA_VERSION_OPTION = 'wp_queuepress_channel_config_schema_version';

	/**
	 * Current schema version. Bump whenever the persisted shape changes.
	 */
	public const CURRENT_SCHEMA_VERSION = 2;

	/**
	 * Valid values for the post_style field (Twitter and Threads only).
	 *
	 * 'social_post' → standard content-based publication; the system may auto-thread.
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
					'social_post' => __('Standard content-based publication. The system uses the post excerpt as an introduction and the full post content as the body, splitting it into a thread when needed.', 'wp-queuepress'),
					'card_link'   => __('Traffic-oriented publication. Uses the post excerpt as the body, the post URL as the link, the SEO title when available, and the post thumbnail. Never generates a thread.', 'wp-queuepress'),
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
				// Instagram has no user-configurable fields beyond enabled.
				// Content and image sources are determined by system rules.
				return array();

			case 'twitter':
				return array_merge(
					$premium_account_field,
					$post_style_field
				);

			case 'threads':
				return $post_style_field;

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
				// No user-configurable fields for Instagram.
				return $base;

			case 'twitter':
				return array_merge($base, array(
					'premium_account' => false,
					'post_style'      => 'social_post',
				));

			case 'threads':
				return array_merge($base, array(
					'post_style' => 'social_post',
				));

			default:
				return $base;
		}
	}

	/**
	 * Migrates the persisted option from an older schema to the current one.
	 *
	 * Currently performs one migration (schema v1 → v2):
	 *   - Strips `content_source` and `image_source` from every channel entry.
	 *     Both are now system rules, not user preferences.
	 *
	 * The migration is gated by the persisted schema version flag; it runs
	 * at most once per upgrade.
	 *
	 * @return bool True if a migration was executed, false if already up to date.
	 */
	public function migrate_legacy_config(): bool {
		$current = (int) get_option(self::SCHEMA_VERSION_OPTION, 1);

		if ($current >= self::CURRENT_SCHEMA_VERSION) {
			return false;
		}

		$all = $this->get_all();
		$changed = false;

		foreach ($all as $channel_id => $cfg) {
			if (! is_array($cfg)) {
				continue;
			}
			if (array_key_exists('content_source', $cfg) || array_key_exists('image_source', $cfg)) {
				unset($cfg['content_source'], $cfg['image_source']);
				$all[$channel_id] = $cfg;
				$changed = true;
			}
		}

		if ($changed) {
			update_option(self::OPTION_KEY, $all);
		}

		update_option(self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, false);

		return $changed;
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
