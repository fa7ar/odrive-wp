<?php
/**
 * Main admin page wrapper with tab navigation.
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Allowed tabs.
$allowed_tabs = array( 'connection', 'backup', 'activity', 'settings' );
$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'connection';
}

// Get the admin instance from the plugin.
$odrive_admin = ODrive_Plugin::instance()->admin;
?>
<div class="wrap odrive-wrap">
	<h1 class="odrive-page-title">
		<span class="odrive-logo-text">ODrive</span>
		<span class="odrive-version">v<?php echo esc_html( ODRIVE_VERSION ); ?></span>
	</h1>

	<?php settings_errors( 'odrive_messages' ); ?>

	<nav class="odrive-tabs nav-tab-wrapper" aria-label="<?php esc_attr_e( 'ODrive sections', 'odrive-connector' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=odrive-connector&tab=connection' ) ); ?>"
		   class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>"
		   aria-current="<?php echo 'connection' === $active_tab ? 'page' : 'false'; ?>">
			<?php esc_html_e( 'Connection', 'odrive-connector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=odrive-connector&tab=backup' ) ); ?>"
		   class="nav-tab <?php echo 'backup' === $active_tab ? 'nav-tab-active' : ''; ?>"
		   aria-current="<?php echo 'backup' === $active_tab ? 'page' : 'false'; ?>">
			<?php esc_html_e( 'Backup', 'odrive-connector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=odrive-connector&tab=activity' ) ); ?>"
		   class="nav-tab <?php echo 'activity' === $active_tab ? 'nav-tab-active' : ''; ?>"
		   aria-current="<?php echo 'activity' === $active_tab ? 'page' : 'false'; ?>">
			<?php esc_html_e( 'Activity', 'odrive-connector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=odrive-connector&tab=settings' ) ); ?>"
		   class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"
		   aria-current="<?php echo 'settings' === $active_tab ? 'page' : 'false'; ?>">
			<?php esc_html_e( 'Settings', 'odrive-connector' ); ?>
		</a>
	</nav>

	<div class="odrive-tab-content">
		<?php
		switch ( $active_tab ) {
			case 'connection':
				require_once ODRIVE_PLUGIN_DIR . 'admin/views/tab-connection.php';
				break;
			case 'backup':
				require_once ODRIVE_PLUGIN_DIR . 'admin/views/tab-backup.php';
				break;
			case 'activity':
				require_once ODRIVE_PLUGIN_DIR . 'admin/views/tab-activity.php';
				break;
			case 'settings':
				require_once ODRIVE_PLUGIN_DIR . 'admin/views/tab-settings.php';
				break;
		}
		?>
	</div>
</div>
