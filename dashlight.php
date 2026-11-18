<?php
/**
 * Plugin Name:       DashLight
 * Plugin URI:        https://github.com/ckreativedigital/DashLight
 * Description:       A plain-English status light on your dashboard: domain and security certificate renewal dates, waiting updates, and recent backups, with reminder emails before anything expires.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            CKreative Digital
 * Author URI:        https://ckreativedigital.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dashlight
 *
 * @package DashLight
 */

defined( 'ABSPATH' ) || exit;

define( 'DASHLIGHT_VERSION', '1.0.0' );
define( 'DASHLIGHT_FILE', __FILE__ );
define( 'DASHLIGHT_DIR', plugin_dir_path( __FILE__ ) );
define( 'DASHLIGHT_URL', plugin_dir_url( __FILE__ ) );

require_once DASHLIGHT_DIR . 'includes/class-dashlight-settings.php';
require_once DASHLIGHT_DIR . 'includes/class-dashlight-checks.php';
require_once DASHLIGHT_DIR . 'includes/class-dashlight-reminders.php';
require_once DASHLIGHT_DIR . 'includes/class-dashlight-widget.php';

register_activation_hook( __FILE__, array( 'DashLight_Reminders', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DashLight_Reminders', 'deactivate' ) );

add_action( 'plugins_loaded', 'dashlight_init' );

/**
 * Hooks everything up.
 */
function dashlight_init() {
	DashLight_Reminders::init();

	if ( is_admin() ) {
		DashLight_Settings::init();
		DashLight_Widget::init();
	}
}
