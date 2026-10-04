<?php
/**
 * Plugin Name:       ODrive Connector
 * Plugin URI:        https://clab.my.id/circle
 * Description:       Connects your WordPress installation to an external ODrive instance for backup, restore, and media management.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Codelab
 * Author URI:        https://clab.my.id
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odrive-connector
 * Domain Path:       /languages
 *
 * @package ODriveConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'ODRIVE_VERSION',     '1.0.0' );
define( 'ODRIVE_PLUGIN_FILE', __FILE__ );
define( 'ODRIVE_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ODRIVE_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ODRIVE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload includes.
$odrive_includes = array(
	'includes/class-odrive-activity-log.php',
	'includes/class-odrive-auth.php',
	'includes/class-odrive-api-client.php',
	'includes/class-odrive-site-registration.php',
	'includes/class-odrive-health.php',
	'includes/class-odrive-backup.php',
	'includes/class-odrive-restore.php',
	'includes/class-odrive-media.php',
	'includes/class-odrive-scheduler.php',
	'includes/class-odrive-rest-api.php',
	'admin/class-odrive-admin.php',
	'includes/class-odrive-plugin.php',
);

foreach ( $odrive_includes as $file ) {
	require_once ODRIVE_PLUGIN_DIR . $file;
}

// Activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__,   array( 'ODrive_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ODrive_Plugin', 'deactivate' ) );

// Bootstrap.
function odrive_connector_init() {
	return ODrive_Plugin::instance();
}
add_action( 'plugins_loaded', 'odrive_connector_init' );
