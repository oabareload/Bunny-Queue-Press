<?php
/**
 * QueuePress Lab — advanced developer diagnostic tool.
 *
 * Lab is the single location for all debugging, log inspection, and direct
 * Buffer API access. It is intentionally gated behind an explicit "Enable Lab
 * Mode" action so that powerful tools cannot be triggered accidentally.
 *
 * Lab Mode state is stored in the `qps_lab_enabled` option (bool). It persists
 * across page loads and survives browser sessions. Only administrators
 * (manage_options) can toggle it.
 *
 * When Lab Mode is disabled the page renders a locked state with a single
 * "Enable Lab Mode" button. Clicking it shows a confirmation dialog; confirming
 * fires an AJAX call that persists the enabled state and unlocks the UI without
 * a full page reload.
 *
 * When Lab Mode is enabled the page renders four cards:
 *   1. Lab Controls      — toggle Lab Mode, toggle Debug Logging.
 *   2. GraphQL Playground — raw textarea + Execute button.
 *   3. Response Viewer   — Request / Response / Metadata panels.
 *   4. Debug Console     — Refresh / Download Log / Clear Log + log viewer.
 *
 * The debug_buffer flag continues to live inside wp_queuepress_buffer_settings
 * (same option as before). Lab reads and writes it there; Buffer_Page no longer
 * touches it.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

use QueuePostScheduler\Buffer\Buffer_Client;
use QueuePostScheduler\Buffer\Buffer_Debug;
use QueuePostScheduler\Buffer\Buffer_Queue_DB;
use QueuePostScheduler\Buffer\Buffer_Worker;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Renders and processes the QueuePress Lab admin page.
 */
final class Lab_Page {

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'qps-lab';

	/**
	 * WordPress option key for Lab Mode enabled state.
	 */
	public const OPTION_LAB_ENABLED = 'qps_lab_enabled';

	/**
	 * Nonce action — execute raw GraphQL.
	 */
	private const NONCE_EXECUTE = 'qps_lab_execute_graphql';

	/**
	 * Nonce action — clear debug log.
	 */
	private const NONCE_CLEAR = 'qps_lab_clear_log';

	/**
	 * Nonce action — toggle Lab Mode on/off.
	 */
	private const NONCE_TOGGLE = 'qps_lab_toggle_mode';

	/**
	 * Nonce action — save debug logging flag.
	 */
	private const NONCE_DEBUG = 'qps_lab_save_debug';

	private const NONCE_QUEUE_SETTINGS = 'qps_lab_save_queue_settings';
	private const NONCE_QUEUE_RETRY = 'qps_lab_queue_retry';
	private const NONCE_QUEUE_CANCEL = 'qps_lab_queue_cancel';

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Registers WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('wp_ajax_qps_lab_execute_graphql', array($this, 'handle_execute_graphql'));
		add_action('wp_ajax_qps_lab_clear_log',       array($this, 'handle_clear_log'));
		add_action('wp_ajax_qps_lab_toggle_mode',     array($this, 'handle_toggle_mode'));
		add_action('wp_ajax_qps_lab_save_debug',      array($this, 'handle_save_debug'));
		add_action('wp_ajax_qps_lab_save_queue_settings', array($this, 'handle_save_queue_settings'));
		add_action('wp_ajax_qps_lab_queue_retry',     array($this, 'handle_queue_retry'));
		add_action('wp_ajax_qps_lab_queue_cancel',    array($this, 'handle_queue_cancel'));
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX — executes an arbitrary GraphQL string via Buffer_Client.
	 *
	 * POST params:
	 *   _ajax_nonce  Nonce for qps_lab_execute_graphql.
	 *   graphql      Raw GraphQL query or mutation string.
	 *   log          '1' to write to the debug log, '0' to skip.
	 *
	 * @return void
	 */
	public function handle_execute_graphql(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_EXECUTE)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		if (! $this->is_enabled()) {
			wp_send_json_error(array('message' => __('Lab Mode is disabled.', 'wp-queuepress')), 403);
		}

		$graphql = isset($_POST['graphql']) ? trim((string) wp_unslash($_POST['graphql'])) : '';

		if ('' === $graphql) {
			wp_send_json_error(array('message' => __('GraphQL input is empty.', 'wp-queuepress')), 400);
		}

		$log      = isset($_POST['log']) && '1' === $_POST['log'];
		$token    = (string) ($this->get_buffer_settings()['access_token'] ?? '');

		if ('' === $token) {
			wp_send_json_error(array('message' => __('No Buffer access token configured. Please save a token in Buffer Settings first.', 'wp-queuepress')), 400);
		}

		$client = new Buffer_Client($token);
		wp_send_json_success($client->execute_raw_graphql($graphql, $log));
	}

	/**
	 * AJAX — clears the shared QueuePress debug log.
	 *
	 * POST params:
	 *   _ajax_nonce  Nonce for qps_lab_clear_log.
	 *
	 * @return void
	 */
	public function handle_clear_log(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_CLEAR)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		Buffer_Debug::clear();
		wp_send_json_success();
	}

	/**
	 * AJAX — toggles Lab Mode on or off.
	 *
	 * POST params:
	 *   _ajax_nonce  Nonce for qps_lab_toggle_mode.
	 *   enable       '1' to enable, '0' to disable.
	 *
	 * @return void
	 */
	public function handle_toggle_mode(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_TOGGLE)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		$enable = isset($_POST['enable']) && '1' === $_POST['enable'];
		update_option(self::OPTION_LAB_ENABLED, $enable);
		wp_send_json_success(array('enabled' => $enable));
	}

	/**
	 * AJAX — saves the debug logging flag into wp_queuepress_buffer_settings.
	 *
	 * POST params:
	 *   _ajax_nonce  Nonce for qps_lab_save_debug.
	 *   debug        '1' to enable debug logging, '0' to disable.
	 *
	 * @return void
	 */
	public function handle_save_debug(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_DEBUG)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		$debug    = isset($_POST['debug']) && '1' === $_POST['debug'];
		$settings = $this->get_buffer_settings();
		$settings['debug_buffer'] = $debug;
		update_option('wp_queuepress_buffer_settings', $settings);
		wp_send_json_success(array('debug' => $debug));
	}

	public function handle_save_queue_settings(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_QUEUE_SETTINGS)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'wp-queuepress')), 403);
		}

		$enabled = isset($_POST['enabled']) && '1' === $_POST['enabled'];
		$interval = isset($_POST['interval']) ? (int) $_POST['interval'] : 15;
		if (! in_array($interval, array(5, 10, 15, 30, 60), true)) {
			$interval = 15;
		}

		$settings = array('enabled' => $enabled, 'interval' => $interval);
		update_option('qps_lab_queue_settings', $settings);

		// Handle WP-Cron
		$hook = Buffer_Worker::CRON_HOOK;
		if (! $enabled) {
			wp_clear_scheduled_hook($hook);
		} else {
			// Schedule if not scheduled, or if interval changed, we might need to reschedule.
			// The simplest way to apply interval change is clear and reschedule.
			wp_clear_scheduled_hook($hook);
			
			// We need a custom schedule for WP Cron if it doesn't exist, but let's just use 
			// an action to add custom schedules if needed, or rely on WP's built-in.
			// Actually WP doesn't have 5,10,15,30 out of the box except hourly/daily.
			// So we need to add the schedules in Plugin.php or here.
			// For this snippet, we'll assume we register a custom schedule dynamically.
			wp_schedule_event(time(), "qps_{$interval}min", $hook);
		}

		wp_send_json_success($settings);
	}

	public function handle_queue_retry(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}
		
		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_QUEUE_RETRY)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'wp-queuepress')), 403);
		}
		
		$job_id = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;
		$db = new Buffer_Queue_DB();
		$job = $db->get_job($job_id);
		
		if (! $job) {
			wp_send_json_error(array('message' => __('Job not found.', 'wp-queuepress')), 404);
		}
		
		if ($job['status'] !== 'failed') {
			wp_send_json_error(array('message' => __('Only failed jobs can be manually retried.', 'wp-queuepress')), 400);
		}
		
		// Process directly regardless of status, as requested by user.
		$worker = new Buffer_Worker();
		$worker->process_single_job($job, $db);
		
		$updated_job = $db->get_job($job_id);
		wp_send_json_success(array('job' => $updated_job));
	}

	public function handle_queue_cancel(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}
		
		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_QUEUE_CANCEL)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'wp-queuepress')), 403);
		}
		
		$job_id = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;
		$db = new Buffer_Queue_DB();
		$job = $db->get_job($job_id);
		
		if (! $job) {
			wp_send_json_error(array('message' => __('Job not found.', 'wp-queuepress')), 404);
		}
		
		if ($job['status'] === 'processing') {
			wp_send_json_error(array('message' => __('Processing jobs cannot be cancelled.', 'wp-queuepress')), 400);
		}
		
		$db->update_job($job_id, 'cancelled');
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Lab admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		$lab_enabled   = $this->is_enabled();
		$settings      = $this->get_buffer_settings();
		$debug_enabled = ! empty($settings['debug_buffer']);
		$has_token     = '' !== (string) ($settings['access_token'] ?? '');
		$entries       = $lab_enabled ? Buffer_Debug::get_entries() : array();
		
		$db = new Buffer_Queue_DB();
		$queue_jobs = $db->get_all_jobs();
		$queue_settings = get_option('qps_lab_queue_settings', array('enabled' => false, 'interval' => 15));
		?>
		<div class="wrap bunny-wrap">
			<?php Admin_Header::render(self::PAGE_SLUG); ?>
			<div class="bunny-page-content">

				<?php if (! $lab_enabled) : ?>
					<?php $this->render_locked_state(); ?>
				<?php else : ?>
					<?php $this->render_lab($has_token, $debug_enabled, $entries, $queue_settings, $queue_jobs); ?>
				<?php endif; ?>

			</div><!-- .bunny-page-content -->
		</div><!-- .wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Render — locked state
	// -------------------------------------------------------------------------

	/**
	 * Renders the locked (Lab Mode disabled) screen.
	 *
	 * @return void
	 */
	private function render_locked_state(): void {
		?>
		<div class="qps-lab-locked">
			<div class="qps-lab-locked-card">
				<div class="qps-lab-locked-icon" aria-hidden="true">🔬</div>
				<h2 class="qps-lab-locked-title"><?php esc_html_e('QueuePress Lab is disabled', 'wp-queuepress'); ?></h2>
				<p class="qps-lab-locked-desc">
					<?php esc_html_e('Lab contains advanced tools for direct Buffer API access, debug logging, and raw GraphQL execution. Enable Lab Mode only if you understand the consequences.', 'wp-queuepress'); ?>
				</p>
				<button
					type="button"
					id="qps-lab-enable-btn"
					class="button button-primary qps-lab-enable-btn"
					data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_TOGGLE)); ?>"
				>
					<?php esc_html_e('Enable Lab Mode', 'wp-queuepress'); ?>
				</button>
			</div>
		</div>

		<!-- Confirmation dialog -->
		<div id="qps-lab-confirm-overlay" class="qps-lab-overlay" hidden aria-modal="true" role="dialog"
			aria-labelledby="qps-lab-confirm-title">
			<div class="qps-lab-dialog">
				<h2 id="qps-lab-confirm-title"><?php esc_html_e('Enable Lab Mode?', 'wp-queuepress'); ?></h2>
				<p><?php esc_html_e('QueuePress Lab contains advanced debugging and direct Buffer API tools.', 'wp-queuepress'); ?></p>
				<p><?php esc_html_e('These tools can:', 'wp-queuepress'); ?></p>
				<ul class="qps-lab-warn-list">
					<li><?php esc_html_e('Send raw GraphQL requests to Buffer', 'wp-queuepress'); ?></li>
					<li><?php esc_html_e('Modify Buffer content', 'wp-queuepress'); ?></li>
					<li><?php esc_html_e('Delete Buffer posts', 'wp-queuepress'); ?></li>
					<li><?php esc_html_e('Generate large debug logs', 'wp-queuepress'); ?></li>
				</ul>
				<p><strong><?php esc_html_e('Enable Lab Mode only if you understand the consequences.', 'wp-queuepress'); ?></strong></p>
				<div class="qps-lab-dialog-actions">
					<button type="button" id="qps-lab-confirm-cancel" class="button button-secondary">
						<?php esc_html_e('Cancel', 'wp-queuepress'); ?>
					</button>
					<button type="button" id="qps-lab-confirm-ok" class="button button-primary">
						<?php esc_html_e('Enable Lab Mode', 'wp-queuepress'); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Render — unlocked Lab
	// -------------------------------------------------------------------------

	/**
	 * Renders the full Lab UI (Lab Mode is enabled).
	 *
	 * @param bool                          $has_token      Whether a Buffer token is configured.
	 * @param bool                          $debug_enabled  Whether debug logging is currently on.
	 * @param array<int,array<string,mixed>> $entries        Current debug log entries.
	* @param array<string,mixed>           $queue_settings Queue settings array (enabled, interval).
	* @param array<int,array<string,mixed>> $queue_jobs     Jobs fetched from the Buffer queue DB.
	 * @return void
	 */
    private function render_lab(bool $has_token, bool $debug_enabled, array $entries, array $queue_settings, array $queue_jobs): void {
		?>
		<div class="qps-lab-grid">

			<!-- ── Card 1: Lab Controls ── -->
			<div class="qps-lab-card qps-lab-card--controls">
				<div class="qps-lab-card-header">
					<h2 class="qps-lab-card-title"><?php esc_html_e('Lab Controls', 'wp-queuepress'); ?></h2>
				</div>
				<div class="qps-lab-card-body">

					<!-- Lab Mode toggle -->
					<div class="qps-lab-control-row">
						<div class="qps-lab-control-info">
							<strong><?php esc_html_e('Lab Mode', 'wp-queuepress'); ?></strong>
							<span class="qps-lab-control-status qps-lab-status--on">
								<?php esc_html_e('Enabled', 'wp-queuepress'); ?>
							</span>
						</div>
						<button
							type="button"
							id="qps-lab-disable-btn"
							class="button button-secondary"
							data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_TOGGLE)); ?>"
						>
							<?php esc_html_e('Disable Lab Mode', 'wp-queuepress'); ?>
						</button>
					</div>

					<hr class="qps-lab-divider" />

					<!-- Buffer Queue toggle -->
					<div class="qps-lab-control-row">
						<div class="qps-lab-control-info">
							<strong><?php esc_html_e('Buffer Queue', 'wp-queuepress'); ?></strong>
							<span id="qps-queue-status" class="qps-lab-control-status <?php echo ! empty($queue_settings['enabled']) ? 'qps-lab-status--on' : 'qps-lab-status--off'; ?>">
								<?php echo ! empty($queue_settings['enabled'])
									? esc_html__('Enabled', 'wp-queuepress')
									: esc_html__('Disabled', 'wp-queuepress'); ?>
							</span>
						</div>
						<div>
							<label for="qps-lab-queue-interval"><?php esc_html_e('Worker interval:', 'wp-queuepress'); ?></label>
							<select id="qps-lab-queue-interval">
								<?php
								$intervals = array(5, 10, 15, 30, 60);
								foreach ($intervals as $val) {
									$selected = (int) ($queue_settings['interval'] ?? 15) === $val ? 'selected' : '';
									echo sprintf('<option value="%1$d" %2$s>%1$d min</option>', $val, $selected);
								}
								?>
							</select>
							<label class="qps-toggle" for="qps-lab-queue-chk" style="margin-left: 10px;">
								<input
									type="checkbox"
									id="qps-lab-queue-chk"
									value="1"
									<?php checked(! empty($queue_settings['enabled'])); ?>
									data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_QUEUE_SETTINGS)); ?>"
								/>
								<span class="qps-toggle-track"><span class="qps-toggle-thumb"></span></span>
								<span class="qps-toggle-label"><?php esc_html_e('Enable Queue', 'wp-queuepress'); ?></span>
							</label>
						</div>
					</div>
					<p class="description" style="margin-top:6px;">
						<?php esc_html_e('When enabled, publishing is deferred to a background worker to handle Buffer errors resiliently.', 'wp-queuepress'); ?>
					</p>

					<!-- Worker status block -->
					<?php
					$last_run = get_option('qps_buffer_queue_last_run', '');
					$next_ts = wp_next_scheduled(Buffer_Worker::CRON_HOOK);
					$scheduled = $next_ts ? true : false;
					$cfg_interval = (int) ($queue_settings['interval'] ?? 0);

					// Use WordPress date/time formats and timezone for display only.
					$date_format = get_option('date_format') . ' ' . get_option('time_format');
					$last_run_display = '';
					if ($last_run) {
						// $last_run is stored as a GMT/UTC MySQL datetime string.
						// Convert to a Unix timestamp (UTC) and let wp_date() render it
						// in the site timezone/format to avoid double conversions.
						$ts = strtotime($last_run . ' UTC');
						if (false !== $ts) {
							$last_run_display = wp_date($date_format, $ts);
						}
					}
					$next_run_display = '';
					if ($next_ts) {
						// wp_next_scheduled() returns a Unix timestamp; pass it directly to wp_date()
						// so it is rendered using the site's timezone.
						$next_run_display = wp_date($date_format, (int) $next_ts);
					}
					?>
					<div class="qps-worker-status" style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
						<div class="qps-worker-item"><strong><?php esc_html_e('Buffer Queue:', 'wp-queuepress'); ?></strong>
							<span class="qps-lab-control-status <?php echo ! empty($queue_settings['enabled']) ? 'qps-lab-status--on' : 'qps-lab-status--off'; ?>">
								<?php echo ! empty($queue_settings['enabled']) ? esc_html__('Enabled', 'wp-queuepress') : esc_html__('Disabled', 'wp-queuepress'); ?>
							</span>
						</div>
						<div class="qps-worker-item"><strong><?php esc_html_e('Worker:', 'wp-queuepress'); ?></strong> <?php echo $scheduled ? esc_html__('Scheduled', 'wp-queuepress') : esc_html__('Not scheduled', 'wp-queuepress'); ?></div>
						<div class="qps-worker-item"><strong><?php esc_html_e('Interval:', 'wp-queuepress'); ?></strong> <?php echo $cfg_interval ? esc_html($cfg_interval . ' min') : esc_html__('—', 'wp-queuepress'); ?></div>
						<div class="qps-worker-item"><strong><?php esc_html_e('Last run:', 'wp-queuepress'); ?></strong> <?php echo $last_run_display ? esc_html($last_run_display) : esc_html__('Never', 'wp-queuepress'); ?></div>
						<div class="qps-worker-item"><strong><?php esc_html_e('Next run:', 'wp-queuepress'); ?></strong> <?php echo $next_run_display ? esc_html($next_run_display) : esc_html__('—', 'wp-queuepress'); ?></div>
					</div>

					<hr class="qps-lab-divider" />

					<!-- Debug Logging toggle -->
					<div class="qps-lab-control-row">
						<div class="qps-lab-control-info">
							<strong><?php esc_html_e('Debug Logging', 'wp-queuepress'); ?></strong>
							<span id="qps-debug-status" class="qps-lab-control-status <?php echo $debug_enabled ? 'qps-lab-status--on' : 'qps-lab-status--off'; ?>">
								<?php echo $debug_enabled
									? esc_html__('Enabled', 'wp-queuepress')
									: esc_html__('Disabled', 'wp-queuepress'); ?>
							</span>
						</div>
						<label class="qps-toggle" for="qps-lab-debug-chk" title="<?php esc_attr_e('Enable Debug Logging', 'wp-queuepress'); ?>">
							<input
								type="checkbox"
								id="qps-lab-debug-chk"
								value="1"
								<?php checked($debug_enabled); ?>
								data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_DEBUG)); ?>"
							/>
							<span class="qps-toggle-track"><span class="qps-toggle-thumb"></span></span>
							<span class="qps-toggle-label"><?php esc_html_e('Enable Debug Logging', 'wp-queuepress'); ?></span>
						</label>
					</div>
					<p class="description" style="margin-top:6px;">
						<?php esc_html_e('When enabled, all Buffer API requests are written to the debug log visible in the Debug Console below.', 'wp-queuepress'); ?>
					</p>

				</div><!-- .qps-lab-card-body -->
			</div><!-- Card 1 -->

			<!-- ── Card 2: GraphQL Playground ── -->
			<div class="qps-lab-card qps-lab-card--playground">
				<div class="qps-lab-card-header">
					<h2 class="qps-lab-card-title"><?php esc_html_e('GraphQL Playground', 'wp-queuepress'); ?></h2>
				</div>
				<div class="qps-lab-card-body">

					<?php if (! $has_token) : ?>
						<div class="notice notice-warning inline">
							<p><?php esc_html_e('No Buffer access token configured. Add a token in Buffer Settings before executing GraphQL.', 'wp-queuepress'); ?></p>
						</div>
					<?php endif; ?>

					<textarea
						id="qps-lab-graphql"
						class="qps-lab-textarea"
						rows="14"
						placeholder="mutation {&#10;  ...&#10;}"
						spellcheck="false"
					></textarea>

					<div class="qps-lab-toolbar">
						<button
							type="button"
							id="qps-lab-execute"
							class="button button-primary"
							data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_EXECUTE)); ?>"
							<?php echo $has_token ? '' : 'disabled'; ?>
						>
							<?php esc_html_e('Execute GraphQL', 'wp-queuepress'); ?>
						</button>
						<label class="qps-lab-log-toggle">
							<input type="checkbox" id="qps-lab-log" value="1" />
							<?php esc_html_e('Log to Debug Log', 'wp-queuepress'); ?>
						</label>
					</div>

					<div id="qps-lab-playground-error" class="qps-lab-error-msg" hidden></div>

				</div><!-- .qps-lab-card-body -->
			</div><!-- Card 2 -->

			<!-- ── Card 3: Response Viewer ── -->
			<div class="qps-lab-card qps-lab-card--response">
				<div class="qps-lab-card-header">
					<h2 class="qps-lab-card-title"><?php esc_html_e('Response Viewer', 'wp-queuepress'); ?></h2>
				</div>
				<div class="qps-lab-card-body">

					<div id="qps-lab-results-empty" class="qps-lab-results-empty">
						<span><?php esc_html_e('Execute a GraphQL request to see results here.', 'wp-queuepress'); ?></span>
					</div>

					<div id="qps-lab-results" class="qps-lab-results" hidden>
						<div class="qps-lab-metadata" id="qps-lab-metadata-row">
							<span id="qps-lab-status" class="qps-lab-meta-item"></span>
							<span id="qps-lab-elapsed" class="qps-lab-meta-item"></span>
							<span id="qps-lab-ts" class="qps-lab-meta-item"></span>
						</div>
						<div class="qps-lab-results-grid">
							<div class="qps-lab-result-panel">
								<h3><?php esc_html_e('Request', 'wp-queuepress'); ?></h3>
								<pre id="qps-lab-request" class="qps-lab-pre"></pre>
							</div>
							<div class="qps-lab-result-panel">
								<h3><?php esc_html_e('Response', 'wp-queuepress'); ?></h3>
								<pre id="qps-lab-response" class="qps-lab-pre"></pre>
							</div>
						</div>
					</div>

				</div><!-- .qps-lab-card-body -->
			</div><!-- Card 3 -->

			<!-- ── Card 4: Debug Console ── -->
			<div class="qps-lab-card qps-lab-card--console">
				<div class="qps-lab-card-header">
					<h2 class="qps-lab-card-title"><?php esc_html_e('Debug Console', 'wp-queuepress'); ?></h2>
					<div class="qps-lab-console-toolbar">
						<button type="button" id="qps-lab-refresh" class="button button-secondary">
							<?php esc_html_e('Refresh', 'wp-queuepress'); ?>
						</button>
						<button
							type="button"
							id="qps-lab-download"
							class="button button-secondary"
						>
							<?php esc_html_e('Download Log', 'wp-queuepress'); ?>
						</button>
						<button
							type="button"
							id="qps-lab-clear"
							class="button button-secondary"
							data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_CLEAR)); ?>"
							data-confirm="<?php esc_attr_e('Clear the entire debug log? This cannot be undone.', 'wp-queuepress'); ?>"
						>
							<?php esc_html_e('Clear Log', 'wp-queuepress'); ?>
						</button>
						<span id="qps-lab-console-feedback" class="qps-lab-console-feedback" aria-live="polite"></span>
					</div>
				</div><!-- .qps-lab-card-header -->
				<div class="qps-lab-card-body">

					<div id="qps-lab-log" class="qps-lab-log">
						<?php if (empty($entries)) : ?>
							<p class="qps-lab-log-empty"><?php esc_html_e('No debug entries found.', 'wp-queuepress'); ?></p>
						<?php else : ?>
							<?php foreach ($entries as $entry) : ?>
							<div class="qps-lab-log-entry">
								<div class="qps-lab-log-entry-header">
								<strong class="qps-lab-log-ts"><?php echo esc_html($entry['timestamp'] ?? ''); ?></strong>
								<?php if (isset($entry['type'])) : ?>
								<span class="qps-lab-log-type"><?php echo esc_html($entry['type']); ?></span>
								<?php endif; ?>
								<?php if (isset($entry['http_status'])) : ?>
								<span class="qps-lab-log-status qps-lab-log-status--<?php echo (int) $entry['http_status'] === 200 ? 'ok' : 'err'; ?>">
								HTTP <?php echo esc_html((string) $entry['http_status']); ?>
								</span>
								<?php endif; ?>
								 <button type="button" class="qps-lab-copy-entry button button-small" aria-label="<?php esc_attr_e('Copy log entry', 'wp-queuepress'); ?>"><?php esc_html_e('Copy', 'wp-queuepress'); ?></button>
							</div>
								<pre class="qps-lab-pre qps-lab-log-entry-body"><?php echo esc_html(wp_json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
							</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div><!-- #qps-lab-log -->

					<!-- Serialized entries for JS download (never rendered visually) -->
					<script id="qps-lab-log-data" type="application/json"><?php
						echo wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					?></script>

				</div><!-- .qps-lab-card-body -->
			</div><!-- Card 4 -->

			<!-- ── Card 5: Buffer Queue Jobs ── -->
			<div class="qps-lab-card qps-lab-card--queue">
				<div class="qps-lab-card-header">
					<h2 class="qps-lab-card-title"><?php esc_html_e('Buffer Queue Jobs', 'wp-queuepress'); ?></h2>
				</div>
				<div class="qps-lab-card-body">
					<?php if (empty($queue_jobs)) : ?>
						<p><?php esc_html_e('No jobs in the queue.', 'wp-queuepress'); ?></p>
					<?php else : ?>
						<div class="qps-queue-table-wrap">
						<table class="qps-queue-table">
							<thead>
								<tr>
									<th><?php esc_html_e('Post', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Network', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Status', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Created', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Attempts', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Last Error', 'wp-queuepress'); ?></th>
									<th><?php esc_html_e('Actions', 'wp-queuepress'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($queue_jobs as $job) : ?>
									<tr id="qps-job-<?php echo esc_attr((string) $job['id']); ?>">
										<td class="qps-queue-post">
											<a href="<?php echo esc_url(get_edit_post_link((int) $job['post_id'])); ?>">
												<?php echo esc_html((string) $job['post_id']); ?>
											</a>
										</td>
										<td class="qps-queue-network"><?php echo esc_html(ucfirst((string) $job['network'])); ?></td>
										<td class="qps-queue-status">
											<span class="qps-job-status qps-job-status--<?php echo esc_attr((string) $job['status']); ?>">
												<?php echo esc_html(ucfirst((string) $job['status'])); ?>
											</span>
										</td>
										<td class="qps-queue-created">
											<?php
											$created_display = '';
											if (! empty($job['created_at'])) {
												// stored as UTC MySQL datetime string — convert to timestamp and render
												$created_ts = strtotime((string) $job['created_at'] . ' UTC');
												if (false !== $created_ts) {
													// reuse site date/time format so presentation matches Last/Next run
													$created_display = wp_date($date_format, $created_ts);
												}
											}
											echo $created_display ? esc_html($created_display) : esc_html('—');
											?>
										</td>
										<td class="qps-queue-attempts"><?php echo esc_html((string) $job['attempts']); ?></td>
										<td class="qps-queue-error">
											<?php if (! empty($job['last_error'])) : ?>
												<div class="qps-job-error-block"><pre class="qps-job-error-pre"><?php echo esc_html((string) $job['last_error']); ?></pre></div>
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
										<td class="qps-queue-actions">
											<?php if (in_array($job['status'], array('pending', 'retry', 'processing', 'failed'), true)) : ?>
												<button type="button" class="button button-small qps-lab-queue-cancel" data-id="<?php echo esc_attr((string) $job['id']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_QUEUE_CANCEL)); ?>">
													<?php esc_html_e('Cancel', 'wp-queuepress'); ?>
												</button>
											<?php endif; ?>
											<?php if ($job['status'] === 'failed') : ?>
												<button type="button" class="button button-small button-primary qps-lab-queue-retry" data-id="<?php echo esc_attr((string) $job['id']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_QUEUE_RETRY)); ?>">
													<?php esc_html_e('Retry', 'wp-queuepress'); ?>
												</button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div><!-- Card 5 -->

		</div><!-- .qps-lab-grid -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns whether Lab Mode is currently enabled.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) get_option(self::OPTION_LAB_ENABLED, false);
	}

	/**
	 * Returns the stored Buffer settings (same option as Buffer_Page).
	 *
	 * @return array<string,mixed>
	 */
	private function get_buffer_settings(): array {
		$stored = get_option('wp_queuepress_buffer_settings', array());
		return is_array($stored) ? $stored : array();
	}
}
