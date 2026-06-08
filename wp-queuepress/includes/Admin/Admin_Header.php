<?php
/**
 * Reusable admin header with navigation tabs.
 *
 * Uses the shared Bunny Admin UI system (.bunny-* classes, bunny-admin.css).
 * Each plugin keeps its own copy of this helper — no cross-plugin dependency.
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
		'qps-buffer'   => 'Buffer',
	);

	/**
	 * Renders the full header block.
	 *
	 * @param string $current_tab The slug of the currently active tab.
	 * @param string $page_label  Optional subtitle shown below the plugin name.
	 * @return void
	 */
	public static function render( string $current_tab, string $page_label = '' ): void {
		$version = defined( 'WP_QUEUEPRESS_VERSION' ) ? WP_QUEUEPRESS_VERSION : '';
		$label   = $page_label ?: ( self::$tabs[ $current_tab ] ?? '' );
		?>
		<div class="bunny-header">
			<div class="bunny-header-inner">
				<span class="bunny-logo">🐰</span>
				<div class="bunny-title-stack">
					<h1 class="bunny-plugin-name"><?php esc_html_e( 'Bunny Queue Press', 'wp-queuepress' ); ?></h1>
					<?php if ( $label ) : ?>
						<span class="bunny-page-subtitle"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $version ) : ?>
					<span class="bunny-version-badge">v<?php echo esc_html( $version ); ?></span>
				<?php endif; ?>
			</div>
			<nav class="bunny-nav" aria-label="<?php esc_attr_e( 'Plugin navigation', 'wp-queuepress' ); ?>">
				<?php foreach ( self::$tabs as $slug => $tab_label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
						class="bunny-nav-item<?php echo $slug === $current_tab ? ' bunny-nav-active' : ''; ?>"
					>
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
		<?php
	}
}
