<?php
/**
 * Plugin Name:       DashLights
 * Plugin URI:        https://github.com/ckreativedigital/DashLights
 * Description:       Plain-English status lights on your dashboard: domain and security certificate renewal dates, waiting updates, and recent backups, with reminder emails before anything expires.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            CKreative Digital
 * Author URI:        https://ckreativedigital.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dashlights
 *
 * @package DashLights
 */

defined( 'ABSPATH' ) || exit;

define( 'DASHLIGHTS_VERSION', '1.0.0' );
define( 'DASHLIGHTS_FILE', __FILE__ );
define( 'DASHLIGHTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DASHLIGHTS_URL', plugin_dir_url( __FILE__ ) );

require_once DASHLIGHTS_DIR . 'includes/class-dashlights-settings.php';
require_once DASHLIGHTS_DIR . 'includes/class-dashlights-checks.php';
require_once DASHLIGHTS_DIR . 'includes/class-dashlights-reminders.php';
require_once DASHLIGHTS_DIR . 'includes/class-dashlights-widget.php';

register_activation_hook( __FILE__, array( 'DashLights_Reminders', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DashLights_Reminders', 'deactivate' ) );

add_action( 'plugins_loaded', 'dashlights_init' );

/**
 * Hooks everything up.
 */
function dashlights_init() {
	DashLights_Reminders::init();

	if ( is_admin() ) {
		DashLights_Settings::init();
		DashLights_Widget::init();
	}
}
