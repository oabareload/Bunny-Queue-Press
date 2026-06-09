<?php
/**
 * Pipeline page UI.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

use DateTimeImmutable;
use DateTimeZone;
use QueuePostScheduler\Buffer\Buffer_Ajax;
use QueuePostScheduler\Schedule\Post_Query;
use QueuePostScheduler\Settings\Preferences;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Renders the editorial pipeline overview.
 */
final class Pipeline_Page {

	/**
	 * Post retrieval service.
	 *
	 * @var Post_Query
	 */
	private Post_Query $post_query;

	/**
	 * Plugin preferences.
	 *
	 * @var Preferences
	 */
	private Preferences $preferences;

	/**
	 * Builds the pipeline page controller.
	 *
	 * @param Post_Query  $post_query  Post retrieval service.
	 * @param Preferences $preferences Plugin preferences.
	 */
	public function __construct(Post_Query $post_query, Preferences $preferences) {
		$this->post_query  = $post_query;
		$this->preferences = $preferences;
	}

	/**
	 * Registers pipeline page hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Reserved for future pipeline-specific hooks.
	}

	/**
	 * Renders the pipeline overview page.
	 *
	 * @return void
	 */
	public function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		$timezone       = wp_timezone();
		$now            = new DateTimeImmutable('now', $timezone);
		$future_window  = (clone $now)->modify('+30 days');
		$published_from = (clone $now)->modify('-30 days');

		$future_drafts = $this->filter_drafts_by_future_date(
			$this->post_query->get_posts_between('draft', $now, $future_window),
			$now
		);
		$normal_drafts = $this->filter_drafts_by_past_date(
			$this->post_query->get_posts_between('draft', $published_from, $now),
			$now
		);
		$scheduled_posts = $this->post_query->get_posts_between('future', $now, $future_window);
		$published_query = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'after'     => $published_from->format('Y-m-d H:i:s'),
						'before'    => $now->format('Y-m-d H:i:s'),
						'inclusive' => true,
						'column'    => 'post_date',
					),
				),
			)
		);

		$published_posts  = $published_query->posts;
		$draft_groups     = $this->group_posts_by_day($future_drafts, $timezone);
		$scheduled_groups = $this->group_posts_by_day($scheduled_posts, $timezone);
		$published_groups = $this->group_posts_by_day($published_posts, $timezone);
		?>
		<div class="wrap bunny-wrap">
			<?php Admin_Header::render( 'qps-pipeline' ); ?>
			<div class="bunny-page-content">
			<p class="description">
				<?php echo esc_html__('A lightweight editorial overview of drafts, scheduled posts, and recently published content.', 'wp-queuepress'); ?>
			</p>

			<div class="qps-pipeline-grid">
				<section class="qps-pipeline-column">
					<header class="qps-pipeline-column-header">
						<h2><?php echo esc_html__('Drafts', 'wp-queuepress'); ?></h2>
						<p><?php echo esc_html__('Future-dated drafts first, followed by unscheduled drafts.', 'wp-queuepress'); ?></p>
					</header>
					<div class="qps-pipeline-column-body">
						<?php $this->render_draft_groups($draft_groups, $normal_drafts, $timezone); ?>
					</div>
				</section>

				<section class="qps-pipeline-column">
					<header class="qps-pipeline-column-header">
						<h2><?php echo esc_html__('Scheduled', 'wp-queuepress'); ?></h2>
						<p><?php echo esc_html__('Future scheduled posts for the coming weeks.', 'wp-queuepress'); ?></p>
					</header>
					<div class="qps-pipeline-column-body">
						<?php $this->render_grouped_post_column($scheduled_groups, $timezone, __('No scheduled posts found.', 'wp-queuepress')); ?>
					</div>
				</section>

				<section class="qps-pipeline-column">
					<header class="qps-pipeline-column-header">
						<h2><?php echo esc_html__('Published', 'wp-queuepress'); ?></h2>
						<p><?php echo esc_html__('Recently published posts from the last 30 days.', 'wp-queuepress'); ?></p>
					</header>
					<div class="qps-pipeline-column-body">
						<?php $this->render_grouped_post_column($published_groups, $timezone, __('No recently published posts found.', 'wp-queuepress')); ?>
					</div>
				</section>
			</div>
			</div><!-- .qps-page-content -->
		</div>
		<?php
	}

	/**
	 * Renders draft groups with future drafts first and unscheduled drafts below.
	 *
	 * @param array<string,array<int,\WP_Post>> $future_groups Draft groups by day.
	 * @param array<int,\WP_Post>               $normal_drafts Normal unscheduled drafts.
	 * @param DateTimeZone                      $timezone      Site timezone.
	 * @return void
	 */
	private function render_draft_groups(array $future_groups, array $normal_drafts, DateTimeZone $timezone): void {
		if (empty($future_groups) && empty($normal_drafts)) {
			$this->render_empty_state(__('No drafts found.', 'wp-queuepress'));
			return;
		}

		foreach ($future_groups as $day_label => $posts) {
			?>
			<div class="qps-pipeline-group">
				<div class="qps-pipeline-group-header">
					<strong><?php echo esc_html($day_label); ?></strong>
					<span><?php echo esc_html(sprintf('%s %s', count($posts), _n('draft', 'drafts', count($posts), 'wp-queuepress'))); ?></span>
				</div>
				<ul class="qps-card-list">
					<?php $this->render_post_cards($posts, $timezone, true); ?>
				</ul>
			</div>
			<?php
		}

		if (! empty($normal_drafts)) {
			?>
			<div class="qps-pipeline-group">
				<div class="qps-pipeline-group-header">
					<strong><?php echo esc_html__('Unscheduled', 'wp-queuepress'); ?></strong>
					<span><?php echo esc_html(sprintf('%s %s', count($normal_drafts), _n('draft', 'drafts', count($normal_drafts), 'wp-queuepress'))); ?></span>
				</div>
				<ul class="qps-card-list">
					<?php $this->render_post_cards($normal_drafts, $timezone, false); ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * Renders a grouped post column.
	 *
	 * @param array<string,array<int,\WP_Post>> $groups Grouped posts by day.
	 * @param DateTimeZone                      $timezone Site timezone.
	 * @param string                            $empty_message Empty state message.
	 * @return void
	 */
	private function render_grouped_post_column(array $groups, DateTimeZone $timezone, string $empty_message): void {
		if (empty($groups)) {
			$this->render_empty_state($empty_message);
			return;
		}

		foreach ($groups as $day_label => $posts) {
			?>
			<div class="qps-pipeline-group">
				<div class="qps-pipeline-group-header">
					<strong><?php echo esc_html($day_label); ?></strong>
					<span><?php echo esc_html(sprintf('%s %s', count($posts), _n('item', 'items', count($posts), 'wp-queuepress'))); ?></span>
				</div>
				<ul class="qps-card-list">
					<?php $this->render_post_cards($posts, $timezone, true); ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * Renders a set of compact horizontal post cards.
	 *
	 * Each card has:
	 *   - An action menu (⋮) overlaid on the post thumbnail (top-right).
	 *   - A platform status strip (bottom-left of the image) showing the state
	 *     of Buffer / Twitter / Threads / Instagram for the post.
	 *   - A feedback row below the card (populated by JS) for ephemeral messages.
	 *
	 * @param array<int,\WP_Post> $posts Posts to render.
	 * @param DateTimeZone        $timezone Site timezone.
	 * @param bool                $show_time Whether to show post time.
	 * @return void
	 */
	private function render_post_cards(array $posts, DateTimeZone $timezone, bool $show_time): void {
		foreach ($posts as $post) {
			$post_time     = new DateTimeImmutable($post->post_date, $timezone);
			$edit_url      = get_edit_post_link($post->ID);
			$title         = get_the_title($post) ?: __('(no title)', 'wp-queuepress');
			$status        = $this->get_post_status_label($post->post_status);
			$thumbnail     = get_the_post_thumbnail_url($post, array(180, 180));
			$channels_meta = get_post_meta($post->ID, Buffer_Ajax::META_KEY, true);
			if (! is_array($channels_meta)) { $channels_meta = array(); }

			// Build a per-service lookup: [service_slug => entry]
			$by_service = array();
			foreach ($channels_meta as $cid => $entry) {
				if (! is_array($entry)) { continue; }
				$svc = isset($entry['service']) ? (string) $entry['service'] : '';
				if ($svc !== '') { $by_service[$svc] = $entry; }
			}

			// Whether the post actually has a real Buffer publication record.
			// An entry is only "real" if it has both a buffer post_id AND a sent_at.
			// This filters out legacy/empty/test rows that may exist from old
			// versions of the plugin or incomplete migrations.
			$has_real_record = static function (array $entry): bool {
				$post_id = isset($entry['post_id']) ? (string) $entry['post_id'] : '';
				$sent_at = isset($entry['sent_at']) ? (string) $entry['sent_at'] : '';
				return ($post_id !== '' && $sent_at !== '');
			};
			?>
			<li class="qps-card">
				<div class="qps-card-inner">
					<div class="qps-card-image<?php echo $thumbnail ? '' : ' qps-card-image--placeholder'; ?>">
						<?php if ($thumbnail) : ?>
							<img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($title); ?>" />
						<?php else : ?>
							<span class="qps-card-image-placeholder" aria-hidden="true"><?php echo $this->render_icon('image'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php endif; ?>

						<!-- Action menu overlay (top-right of the image) -->
						<div class="qps-image-menu">
							<button
								type="button"
								class="qps-image-menu-toggle"
								aria-label="<?php esc_attr_e('Post actions', 'wp-queuepress'); ?>"
								aria-haspopup="true"
								aria-expanded="false"
							><?php echo $this->render_icon('more'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup, controlled output. ?></button>
							<ul class="qps-image-menu-list" role="menu" hidden>
								<li role="none">
									<button
										type="button"
										role="menuitem"
										class="qps-send-to-buffer"
										data-post-id="<?php echo esc_attr((string) $post->ID); ?>"
										data-nonce="<?php echo esc_attr(wp_create_nonce(Buffer_Ajax::nonce_action($post->ID))); ?>"
									>
										<?php echo $this->render_icon('buffer'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<span><?php esc_html_e('Send to Buffer', 'wp-queuepress'); ?></span>
									</button>
								</li>
							</ul>
						</div><!-- .qps-image-menu -->

						<!-- Platform status strip (bottom-left of the image) -->
						<div class="qps-platform-strip" aria-label="<?php esc_attr_e('Per-platform status', 'wp-queuepress'); ?>">
							<?php
							$platforms = array(
								'buffer'    => 'buffer',
								'twitter'   => 'x',
								'threads'   => 'threads',
								'instagram' => 'instagram',
							);
							$platform_labels = array(
								'buffer'    => __('Buffer', 'wp-queuepress'),
								'twitter'   => __('X / Twitter', 'wp-queuepress'),
								'threads'   => __('Threads', 'wp-queuepress'),
								'instagram' => __('Instagram', 'wp-queuepress'),
							);
							foreach ($platforms as $service_slug => $icon_key) :
								$entry = isset($by_service[$service_slug]) ? $by_service[$service_slug] : null;
								$state_modifier = 'qps-platform--idle';
								$state_label    = __('Not sent', 'wp-queuepress');
								$tooltip_date   = '';
								if (is_array($entry) && $has_real_record($entry)) {
									$resolved = $this->resolve_platform_state($entry);
									$state_modifier = $resolved['modifier'];
									$state_label    = $resolved['label'];
									$tooltip_date   = $resolved['date'];
								}
								$tooltip = $platform_labels[$service_slug] . "\n" . $state_label;
								if ($tooltip_date !== '') {
									$tooltip .= "\n" . $tooltip_date;
								}
								?>
								<span
									class="qps-platform <?php echo esc_attr($state_modifier); ?>"
									data-service="<?php echo esc_attr($service_slug); ?>"
									title="<?php echo esc_attr($tooltip); ?>"
								><?php echo $this->render_icon($icon_key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php endforeach; ?>
						</div><!-- .qps-platform-strip -->
					</div>
					<div class="qps-card-body">
						<div class="qps-card-header-row">
							<?php if ($edit_url) : ?>
								<a class="qps-card-title-link" href="<?php echo esc_url($edit_url); ?>">
									<?php echo esc_html($title); ?>
								</a>
							<?php else : ?>
								<span class="qps-card-title-link"><?php echo esc_html($title); ?></span>
							<?php endif; ?>
						</div><!-- .qps-card-header-row -->
						<div class="qps-card-meta">
							<?php if ($show_time) : ?>
								<time><?php echo esc_html(wp_date($this->preferences->get_time_format(), $post_time->getTimestamp())); ?></time>
							<?php endif; ?>
							<span class="qps-badge <?php echo esc_attr($status['class']); ?>"><?php echo esc_html($status['label']); ?></span>
						</div><!-- .qps-card-meta -->
					</div><!-- .qps-card-body -->
				</div><!-- .qps-card-inner -->
				<!-- Inline feedback (populated by JS, ephemeral messages only) -->
				<div class="qps-card-feedback" aria-live="polite"></div>
			</li>
			<?php
		}
	}

	/**
	 * Renders an inline SVG icon by key.
	 *
	 * All icons use fill="currentColor" so callers can control color via CSS.
	 * Recognized keys: more, buffer, x, threads, instagram.
	 *
	 * @param string $key Icon key.
	 * @return string SVG markup.
	 */
	private function render_icon(string $key): string {
		switch ($key) {
			case 'more':
				return '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<circle cx="12" cy="5"  r="1.8" fill="currentColor"/>'
					. '<circle cx="12" cy="12" r="1.8" fill="currentColor"/>'
					. '<circle cx="12" cy="19" r="1.8" fill="currentColor"/>'
					. '</svg>';

			case 'buffer':
				return '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="2"/>'
					. '<text x="12" y="16" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="currentColor">b</text>'
					. '</svg>';

			case 'x':
				return '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<path fill="currentColor" d="M17.53 3H20.5l-6.49 7.42L21.75 21h-6.06l-4.75-6.21L5.5 21H2.52l6.95-7.95L2.25 3h6.21l4.29 5.67L17.53 3zm-1.06 16.5h1.64L7.62 4.4H5.86L16.47 19.5z"/>'
					. '</svg>';

			case 'threads':
				return '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M16 8c-1.5-2-4-2.5-6-1.5-2 1-3 3-3 5.5s1 4.5 3 5.5 4.5.5 6-1.5c1-1.4 1-3.2 0-4.5-1-1.4-3-2-5-1.5"/>'
					. '<circle cx="12" cy="12" r="1.3" fill="currentColor"/>'
					. '</svg>';

			case 'instagram':
				return '<svg class="qps-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/>'
					. '<circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/>'
					. '<circle cx="17.5" cy="6.5" r="1.1" fill="currentColor"/>'
					. '</svg>';

			case 'image':
				// Placeholder icon for posts without a featured image.
				return '<svg class="qps-icon qps-icon--lg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
					. '<rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/>'
					. '<circle cx="9" cy="10" r="1.5" fill="currentColor"/>'
					. '<path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" d="M4 18l5-5 4 4 3-3 4 4"/>'
					. '</svg>';

			default:
				return '';
		}
	}

	/**
	 * Resolves the platform state from a Buffer channel entry.
	 *
	 * Applies the precedence hierarchy:
	 *   success > scheduled > pending > error > idle
	 *
	 * The state is derived only from the data actually stored in the
	 * entry (`status`, `post_id`, `sent_at`). Entries without both a
	 * `post_id` and a `sent_at` are treated as "not sent" upstream and
	 * never reach this function (the caller filters them out).
	 *
	 * @param array<string, mixed> $entry One Buffer channel entry.
	 * @return array{modifier: string, label: string, date: string}
	 */
	private function resolve_platform_state(array $entry): array {
		$raw_status = isset($entry['status']) ? strtolower(trim((string) $entry['status'])) : '';
		$sent_at    = isset($entry['sent_at']) ? (string) $entry['sent_at'] : '';

		// Pending states (queue / processing).
		if (in_array($raw_status, array('pending', 'queued', 'processing', 'in_progress'), true)) {
			return array(
				'modifier' => 'qps-platform--pending',
				'label'    => __('Pending', 'wp-queuepress'),
				'date'     => $sent_at,
			);
		}

		// Error states.
		if (in_array($raw_status, array('error', 'failed', 'failure', 'cancelled', 'canceled'), true)) {
			return array(
				'modifier' => 'qps-platform--error',
				'label'    => __('Error', 'wp-queuepress'),
				'date'     => $sent_at,
			);
		}

		// Scheduled / queued for future publication.
		if (in_array($raw_status, array('scheduled', 'queue', 'add_to_queue', 'added_to_queue'), true)) {
			return array(
				'modifier' => 'qps-platform--scheduled',
				'label'    => __('Scheduled', 'wp-queuepress'),
				'date'     => $sent_at,
			);
		}

		// Success states.
		if (in_array($raw_status, array('sent', 'published', 'added', 'success', 'ok', 'live'), true)) {
			return array(
				'modifier' => 'qps-platform--success',
				'label'    => __('Published', 'wp-queuepress'),
				'date'     => $sent_at,
			);
		}

		// Unknown / legacy status: if we have a real record (post_id + sent_at),
		// show as success by default; otherwise idle.
		return array(
			'modifier' => 'qps-platform--success',
			'label'    => __('Published', 'wp-queuepress'),
			'date'     => $sent_at,
		);
	}

	/**
	 * Groups posts by their local calendar day.
	 *
	 * @param array<int,\WP_Post> $posts Posts to group.
	 * @param DateTimeZone        $timezone Site timezone.
	 * @return array<string,array<int,\WP_Post>>
	 */
	private function group_posts_by_day(array $posts, DateTimeZone $timezone): array {
		$grouped = array();

		foreach ($posts as $post) {
			$post_time = new DateTimeImmutable($post->post_date, $timezone);
			$day_label = wp_date($this->preferences->get_date_format(), $post_time->getTimestamp());

			if (! isset($grouped[$day_label])) {
				$grouped[$day_label] = array();
			}

			$grouped[$day_label][] = $post;
		}

		return $grouped;
	}

	/**
	 * Filters drafts whose post_date is in the future.
	 *
	 * @param array<int,\WP_Post> $drafts Draft posts.
	 * @param DateTimeImmutable    $now Current site time.
	 * @return array<int,\WP_Post>
	 */
	private function filter_drafts_by_future_date(array $drafts, DateTimeImmutable $now): array {
		return array_values(
			array_filter(
				$drafts,
				static function (\WP_Post $post) use ($now): bool {
					$post_time = new DateTimeImmutable($post->post_date, wp_timezone());
					return $post_time > $now;
				}
			)
		);
	}

	/**
	 * Filters drafts whose post_date is at or before the current time.
	 *
	 * @param array<int,\WP_Post> $drafts Draft posts.
	 * @param DateTimeImmutable    $now Current site time.
	 * @return array<int,\WP_Post>
	 */
	private function filter_drafts_by_past_date(array $drafts, DateTimeImmutable $now): array {
		return array_values(
			array_filter(
				$drafts,
				static function (\WP_Post $post) use ($now): bool {
					$post_time = new DateTimeImmutable($post->post_date, wp_timezone());
					return $post_time <= $now;
				}
			)
		);
	}

	/**
	 * Returns a badge label for the post status.
	 *
	 * @param string $status WordPress post status.
	 * @return array{label:string,class:string}
	 */
	private function get_post_status_label(string $status): array {
		if ('future' === $status) {
			return array(
				'label' => __('Scheduled', 'wp-queuepress'),
				'class' => 'is-scheduled',
			);
		}

		if ('publish' === $status) {
			return array(
				'label' => __('Published', 'wp-queuepress'),
				'class' => 'is-published',
			);
		}

		return array(
			'label' => __('Draft', 'wp-queuepress'),
			'class' => 'is-draft',
		);
	}

	/**
	 * Renders a compact empty state.
	 *
	 * @param string $message Empty state message.
	 * @return void
	 */
	private function render_empty_state(string $message): void {
		echo '<div class="qps-empty">';
		echo '<span>' . esc_html($message) . '</span>';
		echo '</div>';
	}
}
