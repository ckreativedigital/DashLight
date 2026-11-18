<?php
/**
 * Removes everything DashLights stored when the plugin is deleted.
 *
 * @package DashLights
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'dashlights_settings' );
delete_option( 'dashlights_data' );
delete_transient( 'dashlights_rdap_bootstrap' );
wp_clear_scheduled_hook( 'dashlights_daily' );
