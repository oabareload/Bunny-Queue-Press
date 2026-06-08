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
		// Fetch published posts with newest first (post_date DESC) for the Published column.
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

		$published_posts = $published_query->posts;
		$draft_groups    = $this->group_posts_by_day($future_drafts, $timezone);
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
	 * Each card has an action menu (⋮) with a "Send to Buffer" option.
	 * The ⋮ button is always active — sending to Buffer again simply
	 * overwrites the previous record for that channel.
	 *
	 * @param array<int,\WP_Post> $posts Posts to render.
	 * @param DateTimeZone        $timezone Site timezone.
	 * @param bool                $show_time Whether to show post time.
	 * @return void
	 */
	private function render_post_cards(array $posts, DateTimeZone $timezone, bool $show_time): void {
		foreach ($posts as $post) {
			$post_time    = new DateTimeImmutable($post->post_date, $timezone);
			$edit_url     = get_edit_post_link($post->ID);
			$title        = get_the_title($post) ?: __('(no title)', 'wp-queuepress');
			$status       = $this->get_post_status_label($post->post_status);
			$thumbnail    = get_the_post_thumbnail_url($post, array(120, 120));
			$buffer_entry = $this->get_buffer_channel_entry($post->ID);
			?>
			<li class="qps-card">
				<div class="qps-card-inner">
					<?php if ($thumbnail) : ?>
						<div class="qps-card-image">
							<img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr($title); ?>" />
						</div>
					<?php endif; ?>
					<div class="qps-card-body">
						<div class="qps-card-header-row">
							<?php if ($edit_url) : ?>
								<a class="qps-card-title-link" href="<?php echo esc_url($edit_url); ?>">
									<?php echo esc_html($title); ?>
								</a>
							<?php else : ?>
								<span class="qps-card-title-link"><?php echo esc_html($title); ?></span>
							<?php endif; ?>
							<div class="qps-card-actions">
								<?php if ($buffer_entry) : ?>
									<span class="qps-buffer-indicator" title="<?php echo esc_attr(
										sprintf(
											/* translators: %s: sent datetime */
											__('Sent to Buffer on %s', 'wp-queuepress'),
											$buffer_entry['sent_at']
										)
									); ?>">&#10003;</span>
								<?php endif; ?>
								<div class="qps-action-menu">
									<button
										type="button"
										class="qps-action-menu-toggle"
										aria-label="<?php esc_attr_e('Post actions', 'wp-queuepress'); ?>"
										aria-expanded="false"
									>&#8942;</button>
									<ul class="qps-action-menu-list" role="menu" hidden>
										<li role="none">
											<button
												type="button"
												role="menuitem"
												class="qps-send-to-buffer"
												data-post-id="<?php echo esc_attr((string) $post->ID); ?>"
												data-nonce="<?php echo esc_attr(wp_create_nonce(Buffer_Ajax::nonce_action($post->ID))); ?>"
											><?php esc_html_e('Send to Buffer', 'wp-queuepress'); ?></button>
										</li>
									</ul>
								</div><!-- .qps-action-menu -->
							</div><!-- .qps-card-actions -->
						</div><!-- .qps-card-header-row -->
						<div class="qps-card-meta">
							<?php if ($show_time) : ?>
								<time><?php echo esc_html(wp_date($this->preferences->get_time_format(), $post_time->getTimestamp())); ?></time>
							<?php endif; ?>
							<span class="qps-badge <?php echo esc_attr($status['class']); ?>"><?php echo esc_html($status['label']); ?></span>
						</div><!-- .qps-card-meta -->
					</div><!-- .qps-card-body -->
				</div><!-- .qps-card-inner -->
				<!-- Inline feedback (populated by JS) -->
				<div class="qps-card-feedback" aria-live="polite"></div>
			</li>
			<?php
		}
	}

	/**
	 * Returns the most recent Buffer channel entry for a post, or null.
	 *
	 * Reads _queuepress_buffer_channels and returns the first entry found.
	 * In Sprint 3.0 only one channel (Instagram) is used. In Sprint 3.1+
	 * this can be extended to return entries per channel_id.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, mixed>|null
	 */
	private function get_buffer_channel_entry(int $post_id): ?array {
		$channels = get_post_meta($post_id, Buffer_Ajax::META_KEY, true);

		if (! is_array($channels) || empty($channels)) {
			return null;
		}

		// Return the first entry — keyed by channel_id.
		$entry = reset($channels);

		return is_array($entry) ? $entry : null;
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
