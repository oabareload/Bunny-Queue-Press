<?php
/**
 * Single source of truth for every supported publishing platform.
 *
 * Adding a new platform requires:
 *   1. Creating a Publisher class with publish_to_channel(int, string): array.
 *   2. Adding one entry to the $platforms array below.
 *
 * No other file (Buffer_Ajax, Channel_Config, Pipeline_Page, JS) should
 * hardcode a list of platforms. They all consume this registry.
 *
 * Design notes:
 *   - The registry is lazy: translations (__()) are resolved at first read
 *     time, which always happens after load_plugin_textdomain() has run
 *     (admin context).
 *   - The "limits" section is the unique source of platform constraint data.
 *     It carries both the numeric value (consumed by the publisher) and the
 *     human label (consumed by the UI). No parallel structure.
 *   - SVG icons live here so a new platform is registered end-to-end in one
 *     place.
 *   - The buffer service itself is intentionally NOT a platform here.
 *     Buffer is the transport layer; the platforms are the real destinations.
 *
 * @package QueuePostScheduler\Buffer
 */

declare(strict_types=1);

namespace QueuePostScheduler\Buffer;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Registry of publishing platforms supported by the plugin.
 */
final class Platform_Registry {

	/**
	 * Text domain used for translations.
	 */
	private const TEXT_DOMAIN = 'wp-queuepress';

	/**
	 * Lazy-loaded registry of platforms.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $platforms = null;

	// -------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------

	/**
	 * Returns the full registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if (null === self::$platforms) {
			self::$platforms = self::build();
		}
		return self::$platforms;
	}

	/**
	 * Returns the definition for a given slug, or null.
	 *
	 * @param string $slug
	 * @return array<string, mixed>|null
	 */
	public static function get(string $slug): ?array {
		return self::all()[$slug] ?? null;
	}

	/**
	 * Whether the given slug is registered.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function exists(string $slug): bool {
		return isset(self::all()[$slug]);
	}

	/**
	 * Fully-qualified class name of the publisher for a slug, or null.
	 *
	 * @param string $slug
	 * @return string|null
	 */
	public static function publisher_class(string $slug): ?string {
		$def = self::get($slug);
		return $def['publisher_class'] ?? null;
	}

	/**
	 * Returns the human label for a platform, already translated.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function label(string $slug): string {
		$def = self::get($slug);
		return $def['label'] ?? $slug;
	}

	/**
	 * Returns the SVG markup for a platform's icon.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function icon_svg(string $slug): string {
		$def = self::get($slug);
		if (! $def) { return ''; }
		return (string) ($def['icon_svg'] ?? '');
	}

	/**
	 * Returns a numeric limit value for a platform.
	 *
	 * Returns null if the platform or the limit is not defined.
	 *
	 * @param string $slug
	 * @param string $limit_key
	 * @return int|null
	 */
	public static function limit_value(string $slug, string $limit_key): ?int {
		$def = self::get($slug);
		if (! $def) { return null; }
		return $def['limits'][$limit_key]['value'] ?? null;
	}

	/**
	 * Returns a human label for a numeric limit.
	 *
	 * @param string $slug
	 * @param string $limit_key
	 * @return string|null
	 */
	public static function limit_label(string $slug, string $limit_key): ?string {
		$def = self::get($slug);
		if (! $def) { return null; }
		return $def['limits'][$limit_key]['label'] ?? null;
	}

	/**
	 * Returns the slugs of platforms that have at least one enabled channel
	 * in the given Channel_Config.
	 *
	 * @param Channel_Config $config
	 * @return string[]
	 */
	public static function active_slugs(Channel_Config $config): array {
		$slugs = array();
		foreach ($config->get_all() as $channel_cfg) {
			if (! is_array($channel_cfg)) { continue; }
			if (($channel_cfg['provider'] ?? '') !== 'buffer') { continue; }
			if (empty($channel_cfg['enabled'])) { continue; }
			$svc = (string) ($channel_cfg['service'] ?? '');
			if ($svc !== '' && self::exists($svc)) {
				$slugs[$svc] = true;
			}
		}
		return array_keys($slugs);
	}

	// -------------------------------------------------------------------
	// Registry definition — the single source of truth.
	// -------------------------------------------------------------------

	/**
	 * Builds the registry.
	 *
	 * Translations are resolved here, at first read. By that time WordPress
	 * has loaded the textdomain (we are in admin context, called from a
	 * page render or from an AJAX handler dispatched by wp_ajax_*).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function build(): array {
		return array(

			'twitter' => array(
				'label'           => __('X / Twitter', self::TEXT_DOMAIN),
				'icon_svg'        => '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<path fill="currentColor" d="M17.53 3H20.5l-6.49 7.42L21.75 21h-6.06l-4.75-6.21L5.5 21H2.52l6.95-7.95L2.25 3h6.21l4.29 5.67L17.53 3zm-1.06 16.5h1.64L7.62 4.4H5.86L16.47 19.5z"/>'
					. '</svg>',
				'publisher_class' => __NAMESPACE__ . '\\Twitter_Publisher',

				'extra_field_keys'       => array('premium_account', 'post_style'),
				'extra_defaults'         => array(
					'premium_account' => false,
					'post_style'      => 'social_post',
				),
				'supported_post_styles'  => array('social_post', 'card_link'),
				'default_post_style'     => 'social_post',

				// The unique source of platform constraint data.
				// UI reads ['label']; publishers read ['value'].
				'limits' => array(
					'max_images'              => array(
						'label' => __('Maximum images', self::TEXT_DOMAIN),
						'value' => 4,
					),
					'max_thread_posts'        => array(
						'label' => __('Maximum thread posts', self::TEXT_DOMAIN),
						'value' => 25,
					),
					'character_limit'         => array(
						'label' => __('Standard characters', self::TEXT_DOMAIN),
						'value' => 280,
					),
					'character_limit_premium' => array(
						'label' => __('Premium characters', self::TEXT_DOMAIN),
						'value' => 25000,
					),
				),
			),

			'threads' => array(
				'label'           => __('Threads', self::TEXT_DOMAIN),
				'icon_svg'        => '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M16 8c-1.5-2-4-2.5-6-1.5-2 1-3 3-3 5.5s1 4.5 3 5.5 4.5.5 6-1.5c1-1.4 1-3.2 0-4.5-1-1.4-3-2-5-1.5"/>'
					. '<circle cx="12" cy="12" r="1.3" fill="currentColor"/>'
					. '</svg>',
				'publisher_class' => __NAMESPACE__ . '\\Threads_Publisher',

				'extra_field_keys'       => array('post_style'),
				'extra_defaults'         => array(
					'post_style' => 'social_post',
				),
				'supported_post_styles'  => array('social_post', 'card_link'),
				'default_post_style'     => 'social_post',

				'limits' => array(
					'max_images'      => array(
						'label' => __('Maximum images', self::TEXT_DOMAIN),
						'value' => 20,
					),
					'max_thread_posts'        => array(
						'label' => __('Maximum thread posts', self::TEXT_DOMAIN),
						'value' => 25,
					),
					'character_limit' => array(
						'label' => __('Maximum characters', self::TEXT_DOMAIN),
						'value' => 500,
					),
				),
			),

			'facebook' => array(
				'label'           => __('Facebook', self::TEXT_DOMAIN),
				'icon_svg' => '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<path fill="currentColor" d="M13.5 22v-8h2.7l.4-3h-3.1V9.1c0-.9.3-1.5 1.6-1.5H17V4.9c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4V11H8v3h2.8v8z"/>'
					. '</svg>',
				'publisher_class' => __NAMESPACE__ . '\\Facebook_Publisher',

				'extra_field_keys'       => array('post_style'),
				'extra_defaults'         => array(
					'post_style' => 'social_post',
				),
				'supported_post_styles'  => array('social_post', 'card_link'),
				'default_post_style'     => 'social_post',

				'limits' => array(
					'max_images'      => array(
						'label' => __('Maximum images', self::TEXT_DOMAIN),
						'value' => 10,
					),
					'character_limit' => array(
						'label' => __('Maximum characters', self::TEXT_DOMAIN),
						'value' => 5000,
					),
				),
			),

			'instagram' => array(
				'label'           => __('Instagram', self::TEXT_DOMAIN),
				'icon_svg'        => '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>'
					. '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/>'
					. '<circle cx="17.5" cy="6.5" r="1.1" fill="currentColor"/>'
					. '</svg>',
				'publisher_class' => __NAMESPACE__ . '\\Instagram_Publisher',

				'extra_field_keys'       => array(),
				'extra_defaults'         => array(),
				'supported_post_styles'  => array(),
				'default_post_style'     => '',

				'limits' => array(
					'max_images'      => array(
						'label' => __('Maximum images', self::TEXT_DOMAIN),
						'value' => 10,
					),
					'character_limit' => array(
						'label' => __('Maximum characters', self::TEXT_DOMAIN),
						'value' => 2196,
					),
				),
			),
		);
	}
}
