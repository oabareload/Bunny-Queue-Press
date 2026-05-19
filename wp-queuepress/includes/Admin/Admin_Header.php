<?php
/**
 * Reusable admin header with navigation tabs.
 *
 * @package QueuePostScheduler\Admin
 */

declare(strict_types=1);

namespace QueuePostScheduler\Admin;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Renders the shared admin header and tab navigation for all plugin pages.
 */
final class Admin_Header {

	/**
	 * Tab definitions: slug => label.
	 *
	 * @var array<string,string>
	 */
	private static array $tabs = array(
		'qps-pipeline' => 'Pipeline',
		'qps-calendar' => 'Calendar Settings',
		'qps-settings' => 'Settings',
	);

	/**
	 * Renders the full header block.
	 *
	 * @param string $current_tab The slug of the currently active tab.
	 * @return void
	 */
	public static function render( string $current_tab ): void {
		$version = defined( 'WP_QUEUEPRESS_VERSION' ) ? WP_QUEUEPRESS_VERSION : '';
		?>
		<div class="qps-admin-header">
			<div class="qps-admin-header-inner">
				<span class="qps-logo-icon">🐰</span>
				<div class="qps-admin-title">
					<h1 class="qps-admin-plugin-name">Bunny Queue Press</h1>
				</div>
				<?php if ( $version ) : ?>
					<span class="qps-version-badge">v<?php echo esc_html( $version ); ?></span>
				<?php endif; ?>
			</div>
			<nav class="qps-admin-nav" aria-label="<?php esc_attr_e( 'Plugin navigation', 'wp-queuepress' ); ?>">
				<?php foreach ( self::$tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
						class="qps-nav-item<?php echo $slug === $current_tab ? ' qps-nav-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}
}
