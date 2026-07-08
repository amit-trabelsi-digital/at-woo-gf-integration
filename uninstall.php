<?php
/**
 * Uninstall AT - WooCommerce Gravity Forms Integration
 *
 * @package ATWooGFIntegration
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if user has permission to uninstall
if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

/**
 * Clean up plugin data
 */
function at_woo_gf_integration_uninstall() {
	global $wpdb;

	// Remove all product meta data (event fields + waitlist storage)
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_woo_gf_form_id', '_gravity_form_id', '_event_date', '_event_end_date', '_event_location', '_max_attendees', '_event_type', '_event_waitlist_entries', 'enable_waitlist')" );

	// Remove all Gravity Forms entry meta
	if ( class_exists( 'GFAPI' ) ) {
		$table_name = $wpdb->prefix . 'gf_entry_meta';
		$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $found === $table_name ) {
			$wpdb->query( "DELETE FROM {$table_name} WHERE meta_key = 'woo_gf_product_id'" );
		}
	}

	// Remove plugin options (cookie-consent module).
	delete_option( 'at_woo_gf_cookie_settings' );
	delete_option( 'at_woo_gf_cookie_detected' );

	// Delete transients (update checker + all registration-count caches).
	delete_transient( 'at_woo_gf_integration_update_info' );
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_woo_gf_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_woo_gf_' ) . '%'
	) );

	// Clear any cached data
	wp_cache_flush();
}

// Only run uninstall if not a multisite or if on the main site
if ( ! is_multisite() ) {
	at_woo_gf_integration_uninstall();
} else {
	// For multisite, run on each site
	$sites = get_sites();
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		at_woo_gf_integration_uninstall();
		restore_current_blog();
	}
} 