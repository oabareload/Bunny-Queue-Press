<?php
/**
 * Buffer integration admin page.
 *
 * Manages the Buffer settings screen: connection card, channel sidebar,
 * per-channel preference forms with AJAX autosave, and read-only platform limits.
 *
 * Layout:
 *   1. Connection card — token, status, workspace, sync actions, profile summary.
 *   2. Two-column layout below: vertical profile sidebar + active channel panel.
 *
 * AJAX autosave:
 *   Each channel form submits via fetch() on any field change.
 *   Handler: wp_ajax_qps_buffer_autosave_channel (full form, same Channel_Config::save()).
 *   The classic POST handler (handle_save_channel_config) is retained as JS-off fallback.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

use QueuePostScheduler\Buffer\Buffer_Client;
use QueuePostScheduler\Buffer\Channel_Config;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Renders and processes the Buffer settings admin page.
 */
final class Buffer_Page {

	/**
	 * WordPress option key for Buffer connection settings.
	 */
	public const OPTION_SETTINGS = 'wp_queuepress_buffer_settings';

	/**
	 * WordPress option key for synchronized Buffer channels.
	 */
	public const OPTION_CHANNELS = 'wp_queuepress_buffer_channels';

	/**
	 * Admin page slug.
	 */
	public const PAGE_SLUG = 'qps-buffer';

	/**
	 * Nonce action used by the AJAX autosave endpoint.
	 */
	private const AJAX_NONCE_ACTION = 'qps_buffer_autosave_channel';

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('admin_post_qps_buffer_save_token',          array($this, 'handle_save_token'));
		add_action('admin_post_qps_buffer_test_connection',     array($this, 'handle_test_connection'));
		add_action('admin_post_qps_buffer_refresh_profiles',    array($this, 'handle_refresh_profiles'));
		add_action('admin_post_qps_buffer_disconnect',          array($this, 'handle_disconnect'));
		add_action('admin_post_qps_buffer_save_channel_config', array($this, 'handle_save_channel_config'));
		add_action('wp_ajax_qps_buffer_autosave_channel',       array($this, 'handle_ajax_autosave_channel'));
		add_action('admin_post_qps_buffer_clear_debug_log',      array($this, 'handle_clear_debug_log'));
	}

	// -------------------------------------------------------------------------
	// Action handlers — connection
	// -------------------------------------------------------------------------

	/**
	 * Saves the Buffer access token and optionally the organization selection.
	 *
	 * @return void
	 */
	public function handle_save_token(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_save_token');

		$settings                    = $this->get_settings();
		$token                       = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
		$organization_id             = isset($_POST['organization_id']) ? sanitize_text_field(wp_unslash($_POST['organization_id'])) : '';
		$debug_flag                  = isset($_POST['debug_buffer']) ? (bool) wp_unslash($_POST['debug_buffer']) : false;

		$settings['access_token']    = $token;
		$settings['organization_id'] = $organization_id;
		$settings['debug_buffer']    = $debug_flag;

		update_option(self::OPTION_SETTINGS, $settings);

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'saved'), admin_url('admin.php')));
		exit;
	}

	/**
	 * Tests the Buffer connection using the stored token.
	 *
	 * @return void
	 */
	public function handle_test_connection(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_test_connection');

		$settings = $this->get_settings();
		$client   = new Buffer_Client($settings['access_token']);

		if ($client->test_connection()) {
			$settings['last_sync'] = gmdate('Y-m-d H:i:s');
			$settings['connected'] = true;
			update_option(self::OPTION_SETTINGS, $settings);
			$notice = 'connected';
		} else {
			$settings['connected'] = false;
			update_option(self::OPTION_SETTINGS, $settings);
			$notice = 'conn_failed';
		}

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => $notice), admin_url('admin.php')));
		exit;
	}

	/**
	 * Synchronizes organizations and channels from Buffer and saves locally.
	 *
	 * @return void
	 */
	public function handle_refresh_profiles(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_refresh_profiles');

		$settings = $this->get_settings();
		$client   = new Buffer_Client($settings['access_token']);

		$organization_id = $settings['organization_id'];

		if (empty($organization_id)) {
			$organizations   = $client->get_organizations();
			$organization_id = ! empty($organizations[0]['id']) ? $organizations[0]['id'] : '';
			if (! empty($organization_id)) {
				$settings['organization_id'] = $organization_id;
			}
		}

		if (empty($organization_id)) {
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'no_org'), admin_url('admin.php')));
			exit;
		}

		$raw_channels = $client->get_channels($organization_id);

		if (empty($raw_channels)) {
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'no_channels'), admin_url('admin.php')));
			exit;
		}

		$channels = array_map(
			static function (array $ch): array {
				return array(
					'id'              => sanitize_text_field($ch['id'] ?? ''),
					'name'            => sanitize_text_field($ch['name'] ?? ''),
					'display_name'    => sanitize_text_field($ch['displayName'] ?? ''),
					'service'         => sanitize_text_field($ch['service'] ?? ''),
					'avatar'          => esc_url_raw($ch['avatar'] ?? ''),
					'is_queue_paused' => ! empty($ch['isQueuePaused']),
				);
			},
			$raw_channels
		);

		$settings['last_sync'] = gmdate('Y-m-d H:i:s');
		$settings['connected'] = true;
		update_option(self::OPTION_SETTINGS, $settings);
		update_option(self::OPTION_CHANNELS, $channels);

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'synced'), admin_url('admin.php')));
		exit;
	}

	/**
	 * Clears all stored Buffer credentials and synchronized channel data.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_disconnect');

		update_option(self::OPTION_SETTINGS, $this->default_settings());
		update_option(self::OPTION_CHANNELS, array());

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'disconnected'), admin_url('admin.php')));
		exit;
	}

	// -------------------------------------------------------------------------
	// Action handlers — channel config
	// -------------------------------------------------------------------------

	/**
	 * Saves a channel configuration via classic form POST (JS-off fallback).
	 *
	 * Receives the full channel form submission and delegates to Channel_Config::save().
	 *
	 * @return void
	 */
	public function handle_save_channel_config(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_save_channel_config');

		$channel_id = isset($_POST['channel_id']) ? sanitize_text_field(wp_unslash($_POST['channel_id'])) : '';
		$service    = isset($_POST['service']) ? sanitize_key(wp_unslash($_POST['service'])) : '';

		if (empty($channel_id) || empty($service)) {
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'ch_save_failed'), admin_url('admin.php')));
			exit;
		}

		$raw    = isset($_POST['ch']) && is_array($_POST['ch']) ? wp_unslash($_POST['ch']) : array();
		$config = new Channel_Config();
		$config->save($channel_id, $service, $raw);

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'qps_notice' => 'ch_saved'), admin_url('admin.php')));
		exit;
	}

	/**
	 * AJAX handler — autosave a full channel configuration form.
	 *
	 * Receives the full serialized form for one channel via fetch().
	 * Delegates sanitization and persistence to Channel_Config::save().
	 * Returns JSON: { success: true } or { success: false, message: string }.
	 *
	 * Security: nonce verified + manage_options capability check.
	 *
	 * @return void
	 */
	public function handle_ajax_autosave_channel(): void {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'wp-queuepress')), 403);
		}

		$nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
		if (! wp_verify_nonce($nonce, self::AJAX_NONCE_ACTION)) {
			wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'wp-queuepress')), 403);
		}

		$channel_id = isset($_POST['channel_id']) ? sanitize_text_field(wp_unslash($_POST['channel_id'])) : '';
		$service    = isset($_POST['service']) ? sanitize_key(wp_unslash($_POST['service'])) : '';

		if (empty($channel_id) || empty($service)) {
			wp_send_json_error(array('message' => __('Channel ID or service missing.', 'wp-queuepress')), 400);
		}

		$raw    = isset($_POST['ch']) && is_array($_POST['ch']) ? wp_unslash($_POST['ch']) : array();
		$config = new Channel_Config();
		$saved  = $config->save($channel_id, $service, $raw);

		if ($saved) {
			wp_send_json_success();
		} else {
			wp_send_json_error(array('message' => __('Could not save channel settings.', 'wp-queuepress')), 500);
		}
	}

	/**
	 * Clears the Buffer debug log (admin action).
	 *
	 * @return void
	 */
	public function handle_clear_debug_log(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		check_admin_referer('qps_buffer_clear_debug_log');

		\QueuePostScheduler\Buffer\Buffer_Debug::clear();

		wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'view_debug' => '1'), admin_url('admin.php')));
		exit;
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Buffer admin page.
	 *
	 * Layout:
	 *   1. Connection card (always visible).
	 *   2. Two-column profile area (only when channels are synced):
	 *      left  → vertical profile sidebar
	 *      right → active channel panel
	 *
	 * @return void
	 */
	public function render(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-queuepress'));
		}

		$settings  = $this->get_settings();
		$channels  = $this->get_channels();
		$connected = ! empty($settings['connected']);
		$has_token = ! empty($settings['access_token']);
		$notice    = isset($_GET['qps_notice']) ? sanitize_key(wp_unslash($_GET['qps_notice'])) : '';
		?>
		<div class="wrap bunny-wrap">
			<?php Admin_Header::render(self::PAGE_SLUG); ?>
			<div class="bunny-page-content">

				<?php $this->render_notice($notice); ?>

				<?php $this->render_connection_card($settings, $channels, $connected, $has_token); ?>

				<?php if (! empty($channels)) : ?>
					<?php $this->render_profiles_layout($channels); ?>
				<?php endif; ?>

			</div><!-- .bunny-page-content -->
		</div><!-- .wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Render — connection card
	// -------------------------------------------------------------------------

	/**
	 * Renders the consolidated Buffer connection card.
	 *
	 * Contains: status indicator, last sync, token form, workspace summary,
	 * profile count, and action buttons (Test, Refresh, Disconnect).
	 *
	 * @param array<string, mixed>             $settings  Buffer connection settings.
	 * @param array<int, array<string, mixed>> $channels  Synchronized channels.
	 * @param bool                             $connected Whether connection is active.
	 * @param bool                             $has_token Whether a token is stored.
	 * @return void
	 */
	private function render_connection_card(array $settings, array $channels, bool $connected, bool $has_token): void {
		$service_names = array_unique(array_map(static function ($ch) {
			return ucfirst($ch['service']);
		}, $channels));
		?>
		<div class="qps-connection-card">
			<!-- Card header: status + last sync -->
			<div class="qps-connection-card-header">
				<div class="qps-connection-status <?php echo $connected ? 'qps-status--connected' : 'qps-status--disconnected'; ?>">
					<span class="qps-status-dot"></span>
					<span class="qps-status-label">
						<?php echo $connected
							? esc_html__('Connected to Buffer', 'wp-queuepress')
							: esc_html__('Not connected', 'wp-queuepress');
						?>
					</span>
				</div>
				<?php if (! empty($settings['last_sync'])) : ?>
					<span class="qps-connection-sync">
						<?php echo esc_html(
							sprintf(
								/* translators: %s: datetime string */
								__('Last sync: %s', 'wp-queuepress'),
								$settings['last_sync']
							)
						); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- Card body -->
			<div class="qps-connection-card-body">
				<!-- Token form -->
				<div class="qps-connection-token">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="qps_buffer_save_token" />
						<?php wp_nonce_field('qps_buffer_save_token'); ?>
						<div class="qps-token-row">
							<label for="qps-access-token" class="qps-token-label">
								<?php esc_html_e('Access Token', 'wp-queuepress'); ?>
							</label>
							<input
								type="password"
								id="qps-access-token"
								name="access_token"
								value="<?php echo esc_attr($settings['access_token']); ?>"
								class="qps-token-input"
								autocomplete="new-password"
								placeholder="buf_…"
							/>
							<label style="margin-left:12px; display:inline-flex; align-items:center; gap:8px;">
								<input type="checkbox" name="debug_buffer" value="1" <?php checked(! empty($settings['debug_buffer'])); ?> />
								<span><?php esc_html_e('Buffer Debug Mode', 'wp-queuepress'); ?></span>
							</label>
							<?php submit_button(__('Save Token', 'wp-queuepress'), 'secondary small', 'submit', false); ?>
						</div>
					</form>
				</div>

				<!-- Profile summary (only when synced) -->
				<?php if (! empty($channels)) : ?>
				<div class="qps-connection-summary">
					<?php if (! empty($settings['organization_id'])) : ?>
						<span class="qps-summary-item">
							<span class="qps-summary-label"><?php esc_html_e('Workspace', 'wp-queuepress'); ?></span>
							<code><?php echo esc_html($settings['organization_id']); ?></code>
						</span>
					<?php endif; ?>
					<span class="qps-summary-item">
						<span class="qps-summary-label"><?php esc_html_e('Profiles', 'wp-queuepress'); ?></span>
						<span class="qps-summary-value">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of profiles */
									_n('%d synced', '%d synced', count($channels), 'wp-queuepress'),
									count($channels)
								)
							);
							?>
							<?php if (! empty($service_names)) : ?>
								<span class="qps-summary-networks">
									<?php echo esc_html('(' . implode(', ', $service_names) . ')'); ?>
								</span>
							<?php endif; ?>
						</span>
					</span>
				</div>
				<?php endif; ?>

				<!-- Action buttons -->
				<?php if ($has_token) : ?>
				<div class="qps-connection-actions">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<input type="hidden" name="action" value="qps_buffer_test_connection" />
						<?php wp_nonce_field('qps_buffer_test_connection'); ?>
						<?php submit_button(__('Test Connection', 'wp-queuepress'), 'secondary small', 'submit', false); ?>
					</form>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<input type="hidden" name="action" value="qps_buffer_refresh_profiles" />
						<?php wp_nonce_field('qps_buffer_refresh_profiles'); ?>
						<?php submit_button(__('Refresh Profiles', 'wp-queuepress'), 'secondary small', 'submit', false); ?>
					</form>

					<?php if ($connected) : ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
						onsubmit="return confirm('<?php echo esc_js(__('Disconnect Buffer and remove all synchronized profiles?', 'wp-queuepress')); ?>');">
						<input type="hidden" name="action" value="qps_buffer_disconnect" />
						<?php wp_nonce_field('qps_buffer_disconnect'); ?>
						<?php submit_button(__('Disconnect', 'wp-queuepress'), 'delete small', 'submit', false); ?>
					</form>
					<a class="button secondary small" href="<?php echo esc_url(add_query_arg('view_debug', '1', admin_url('admin.php?page=' . self::PAGE_SLUG))); ?>" style="margin-left:8px;">
						<?php esc_html_e('View Debug Log', 'wp-queuepress'); ?>
					</a>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline; margin-left:8px;">
						<input type="hidden" name="action" value="qps_buffer_clear_debug_log" />
						<?php wp_nonce_field('qps_buffer_clear_debug_log'); ?>
						<?php submit_button(__('Clear Debug Log', 'wp-queuepress'), 'secondary small', 'submit', false); ?>
					</form>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div><!-- .qps-connection-card-body -->
			<?php if (! empty($_GET['view_debug']) && current_user_can('manage_options')) :
				$entries = \QueuePostScheduler\Buffer\Buffer_Debug::get_entries();
			?>
			<div class="qps-debug-log" style="padding:12px; border-top:1px solid #eee; background:#fff;">
				<h3><?php esc_html_e('Buffer Debug Log', 'wp-queuepress'); ?></h3>
				<?php if (empty($entries)) : ?>
					<p><?php esc_html_e('No debug entries found.', 'wp-queuepress'); ?></p>
				<?php else : ?>
					<?php foreach ($entries as $entry) : ?>
						<div style="margin:8px 0; padding:8px; border:1px solid #f0f0f1; background:#fafafa;">
							<strong><?php echo esc_html($entry['timestamp'] ?? ''); ?></strong>
							<pre style="white-space:pre-wrap; font-size:12px;"><?php echo esc_html(print_r($entry, true)); ?></pre>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div><!-- .qps-connection-card -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Render — profiles layout
	// -------------------------------------------------------------------------

	/**
	 * Renders the two-column profiles layout: sidebar + panel area.
	 *
	 * @param array<int, array<string, mixed>> $channels Synchronized channels.
	 * @return void
	 */
	private function render_profiles_layout(array $channels): void {
		$config = new Channel_Config();
		?>
		<div class="qps-profiles-layout">

			<!-- Vertical sidebar -->
			<nav class="qps-profiles-sidebar" role="navigation" aria-label="<?php esc_attr_e('Profiles', 'wp-queuepress'); ?>">
				<?php foreach ($channels as $index => $channel) :
					$channel_id = $channel['id'];
					$service    = $channel['service'];
					$cfg        = $config->get($channel_id, $service);
					$is_enabled = ! empty($cfg['enabled']);
					$panel_id   = 'qps-panel-' . sanitize_html_class($channel_id);
				?>
				<button
					type="button"
					class="qps-sidebar-item<?php echo (0 === $index) ? ' qps-sidebar-item--active' : ''; ?>"
					data-panel="<?php echo esc_attr($panel_id); ?>"
					aria-selected="<?php echo (0 === $index) ? 'true' : 'false'; ?>"
				>
					<?php if (! empty($channel['avatar'])) : ?>
						<img
							src="<?php echo esc_url($channel['avatar']); ?>"
							alt=""
							width="28"
							height="28"
							class="qps-sidebar-avatar"
						/>
					<?php else : ?>
						<span class="qps-sidebar-avatar qps-sidebar-avatar--placeholder" aria-hidden="true"></span>
					<?php endif; ?>

					<span class="qps-sidebar-info">
						<span class="qps-sidebar-name"><?php echo esc_html($channel['display_name'] ?: $channel['name']); ?></span>
						<span class="qps-sidebar-service"><?php echo esc_html(ucfirst($service)); ?></span>
					</span>

					<span class="qps-sidebar-status <?php echo $is_enabled ? 'qps-sidebar-status--on' : 'qps-sidebar-status--off'; ?>"
						aria-label="<?php echo $is_enabled ? esc_attr__('Enabled', 'wp-queuepress') : esc_attr__('Disabled', 'wp-queuepress'); ?>">
					</span>
				</button>
				<?php endforeach; ?>
			</nav><!-- .qps-profiles-sidebar -->

			<!-- Panel area -->
			<div class="qps-profiles-panels">
				<?php foreach ($channels as $index => $channel) :
					$channel_id = $channel['id'];
					$service    = $channel['service'];
					$cfg        = $config->get($channel_id, $service);
					$fields     = $config->fields_for($service);
					$limits     = $config->limits_for($service);
					$panel_id   = 'qps-panel-' . sanitize_html_class($channel_id);
				?>
				<div
					id="<?php echo esc_attr($panel_id); ?>"
					class="qps-channel-panel<?php echo (0 === $index) ? ' qps-channel-panel--active' : ''; ?>"
					role="region"
					aria-label="<?php echo esc_attr($channel['display_name'] ?: $channel['name']); ?>"
				>
					<!-- Panel meta -->
					<div class="qps-panel-meta">
						<?php if (! empty($channel['avatar'])) : ?>
							<img src="<?php echo esc_url($channel['avatar']); ?>" alt="" width="40" height="40" class="qps-panel-avatar" />
						<?php endif; ?>
						<div class="qps-panel-meta-info">
							<strong class="qps-panel-name"><?php echo esc_html($channel['display_name'] ?: $channel['name']); ?></strong>
							<span class="qps-panel-meta-row">
								<span class="qps-service-badge qps-service-<?php echo esc_attr($service); ?>"><?php echo esc_html(ucfirst($service)); ?></span>
								<code class="qps-panel-channel-id"><?php echo esc_html($channel_id); ?></code>
							</span>
						</div>
						<!-- Autosave feedback -->
						<span class="qps-autosave-status" data-panel="<?php echo esc_attr($panel_id); ?>" aria-live="polite"></span>
					</div>

					<!-- Channel form — autosaved via AJAX, classic POST as fallback -->
					<form
						method="post"
						action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
						class="qps-channel-form"
						data-channel-id="<?php echo esc_attr($channel_id); ?>"
						data-service="<?php echo esc_attr($service); ?>"
						data-panel="<?php echo esc_attr($panel_id); ?>"
					>
						<input type="hidden" name="action" value="qps_buffer_save_channel_config" />
						<input type="hidden" name="channel_id" value="<?php echo esc_attr($channel_id); ?>" />
						<input type="hidden" name="service" value="<?php echo esc_attr($service); ?>" />
						<?php wp_nonce_field('qps_buffer_save_channel_config'); ?>

						<!-- AJAX nonce (separate action, not exposed in classic POST) -->
						<input type="hidden" class="qps-ajax-nonce" value="<?php echo esc_attr(wp_create_nonce(self::AJAX_NONCE_ACTION)); ?>" />

						<!-- Enabled toggle -->
						<div class="qps-enabled-row">
							<label class="qps-toggle" for="qps-enabled-<?php echo esc_attr(sanitize_html_class($channel_id)); ?>">
								<input
									type="checkbox"
									id="qps-enabled-<?php echo esc_attr(sanitize_html_class($channel_id)); ?>"
									name="ch[enabled]"
									value="1"
									<?php checked(! empty($cfg['enabled'])); ?>
								/>
								<span class="qps-toggle-track"><span class="qps-toggle-thumb"></span></span>
								<span class="qps-toggle-label"><?php esc_html_e('Enabled', 'wp-queuepress'); ?></span>
							</label>
						</div>

						<!-- Configurable preferences -->
						<?php if (! empty($fields)) : ?>
						<div class="qps-channel-fields">
							<?php foreach ($fields as $field_key => $field_def) : ?>
								<?php $this->render_config_field($field_key, $field_def, $cfg); ?>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<!-- Platform limits (read-only, rendered dynamically from Channel_Config::limits_for()) -->
						<?php if (! empty($limits)) : ?>
						<div class="qps-channel-limits">
						<p class="qps-limits-label"><?php esc_html_e('Platform limits', 'wp-queuepress'); ?></p>
						<ul class="qps-limits-list">
						<?php foreach ($limits as $limit) : ?>
						<li>
						<span class="qps-limit-label"><?php echo esc_html($limit['label']); ?>:</span>
						<span class="qps-limit-value"><?php echo esc_html(number_format_i18n((int) $limit['value'])); ?></span>
						</li>
						<?php endforeach; ?>
						</ul>
						</div>
						<?php endif; ?>

						<!-- JS-off fallback submit -->
						<div class="qps-form-fallback">
							<?php submit_button(__('Save', 'wp-queuepress'), 'secondary small', 'submit', false); ?>
						</div>

						<!-- Placeholder: future Draft · Queue · Sent columns -->
						<div class="qps-publications-placeholder" aria-hidden="true"></div>

					</form>
				</div><!-- .qps-channel-panel -->
				<?php endforeach; ?>
			</div><!-- .qps-profiles-panels -->

		</div><!-- .qps-profiles-layout -->
		<?php
	}

	/**
	 * Renders a single configuration field inside a channel form.
	 *
	 * All display text — label, description, option labels, option descriptions —
	 * is read exclusively from the field definition returned by
	 * Channel_Config::fields_for(). Nothing is hardcoded here.
	 *
	 * Supported field types: 'select', 'checkbox'.
	 * Field names use the single-save convention: ch[FIELD_KEY].
	 *
	 * @param string               $field_key Field identifier.
	 * @param array<string, mixed> $field_def Field definition from Channel_Config::fields_for().
	 * @param array<string, mixed> $cfg       Current saved config for this channel.
	 * @return void
	 */
	private function render_config_field(string $field_key, array $field_def, array $cfg): void {
		$input_name          = 'ch[' . esc_attr($field_key) . ']';
		$input_id            = 'qps-ch-' . sanitize_html_class($field_key);
		$current             = $cfg[$field_key] ?? '';
		$description         = $field_def['description'] ?? '';
		$option_descriptions = $field_def['option_descriptions'] ?? array();
		?>
		<div class="qps-config-field">
			<label for="<?php echo esc_attr($input_id); ?>">
				<?php echo esc_html($field_def['label']); ?>
			</label>

			<?php if ('select' === $field_def['type']) : ?>
				<select
					id="<?php echo esc_attr($input_id); ?>"
					name="<?php echo esc_attr($input_name); ?>"
					class="qps-select"
				>
					<?php foreach ($field_def['options'] as $val => $label) : ?>
						<option value="<?php echo esc_attr($val); ?>" <?php selected($current, $val); ?>>
							<?php echo esc_html($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if (! empty($option_descriptions[$current])) : ?>
					<p class="qps-field-option-description">
						<?php echo esc_html($option_descriptions[$current]); ?>
					</p>
				<?php endif; ?>

			<?php elseif ('checkbox' === $field_def['type']) : ?>
				<input
					type="checkbox"
					id="<?php echo esc_attr($input_id); ?>"
					name="<?php echo esc_attr($input_name); ?>"
					value="1"
					<?php checked(! empty($current)); ?>
				/>
			<?php endif; ?>

			<?php if (! empty($description)) : ?>
				<p class="qps-field-description"><?php echo esc_html($description); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the stored Buffer settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$stored = get_option(self::OPTION_SETTINGS, array());

		return array_merge($this->default_settings(), is_array($stored) ? $stored : array());
	}

	/**
	 * Returns the stored Buffer channels list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_channels(): array {
		$stored = get_option(self::OPTION_CHANNELS, array());

		return is_array($stored) ? $stored : array();
	}

	/**
	 * Returns the default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	private function default_settings(): array {
		return array(
			'connected'       => false,
			'access_token'    => '',
			'organization_id' => '',
			'last_sync'       => '',
		);
	}

	/**
	 * Renders a dismissible admin notice based on the redirect notice code.
	 *
	 * @param string $notice Notice code from redirect query arg.
	 * @return void
	 */
	private function render_notice(string $notice): void {
		if (empty($notice)) {
			return;
		}

		$messages = array(
			'saved'          => array('type' => 'success', 'msg' => __('Settings saved.', 'wp-queuepress')),
			'connected'      => array('type' => 'success', 'msg' => __('Connection successful! Buffer API responded correctly.', 'wp-queuepress')),
			'synced'         => array('type' => 'success', 'msg' => __('Profiles synchronized successfully.', 'wp-queuepress')),
			'disconnected'   => array('type' => 'success', 'msg' => __('Buffer disconnected. All credentials and profiles removed.', 'wp-queuepress')),
			'ch_saved'       => array('type' => 'success', 'msg' => __('Channel settings saved.', 'wp-queuepress')),
			'conn_failed'    => array('type' => 'error',   'msg' => __('Connection failed. Please verify your access token.', 'wp-queuepress')),
			'no_org'         => array('type' => 'error',   'msg' => __('Could not determine a Buffer organization. Please check your token.', 'wp-queuepress')),
			'ch_save_failed' => array('type' => 'error',   'msg' => __('Could not save channel settings. Channel ID or service missing.', 'wp-queuepress')),
			'no_channels'    => array('type' => 'warning', 'msg' => __('No channels found for this organization.', 'wp-queuepress')),
		);

		if (! isset($messages[$notice])) {
			return;
		}

		$type = esc_attr($messages[$notice]['type']);
		$msg  = esc_html($messages[$notice]['msg']);

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$type,
			$msg
		);
	}
}
