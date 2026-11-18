<?php
/**
 * Removes everything DashLight stored when the plugin is deleted.
 *
 * @package DashLight
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'dashlight_settings' );
delete_option( 'dashlight_data' );
delete_transient( 'dashlight_rdap_bootstrap' );
wp_clear_scheduled_hook( 'dashlight_daily' );
