<?php
/**
 * Removes everything Honk stored when the plugin is deleted.
 *
 * @package Honk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes Honk's options, scheduled tasks and user meta for the current site.
 *
 * @return void
 */
function honk_uninstall_site() {
	global $wpdb;

	$jobs = get_option( 'honk_jobs', array() );
	if ( is_array( $jobs ) ) {
		foreach ( array_keys( $jobs ) as $job_id ) {
			delete_option( 'honk_job_' . $job_id );
		}
	}
	// Jobs the index lost track of (honk_job_<32 hex>).
	$like = $wpdb->esc_like( 'honk_job_' ) . '%';
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time clean-up of the plugin's own options.
	foreach ( (array) $rows as $name ) {
		delete_option( $name );
	}

	foreach ( array( 'honk_settings', 'honk_site_id', 'honk_log', 'honk_jobs', 'honk_recent_keys', 'honk_login_failures', 'honk_health_state', 'honk_stock_state', 'honk_heartbeat_last', 'honk_heartbeat_scheduled' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'honk_cron_checked' );
	$transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_honk_server_config_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time clean-up of the plugin's own transients.
	foreach ( (array) $transients as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}

	foreach ( array( 'honk_daily', 'honk_hourly', 'honk_daily_summary', 'honk_login_burst_check', 'honk_heartbeat', 'honk_deliver' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'honk' );
	}

	// The "new order reported" flag on orders, in both order storages (posts and HPOS tables).
	delete_metadata( 'post', 0, '_honk_new_order_sent', '', true );
	$table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time clean-up.
		$wpdb->delete( $table, array( 'meta_key' => '_honk_new_order_sent' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time clean-up of the plugin's own order meta (HPOS table).
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $honk_site_id ) {
		switch_to_blog( $honk_site_id );
		honk_uninstall_site();
		restore_current_blog();
	}
} else {
	honk_uninstall_site();
}

// Remembered sign-in devices (hashes) of administrators; user meta is shared by all sites.
delete_metadata( 'user', 0, 'honk_known_devices', '', true );
