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

	// Remove all product meta data
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_woo_gf_form_id', '_gravity_form_id', '_event_date', '_event_end_date', '_event_location', '_max_attendees', '_event_type')" );

	// Remove all Gravity Forms entry meta
	if ( class_exists( 'GFAPI' ) ) {
		$table_name = $wpdb->prefix . 'gf_entry_meta';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name ) {
			$wpdb->query( "DELETE FROM {$table_name} WHERE meta_key = 'woo_gf_product_id'" );
		}
	}

	// Delete transients
	delete_transient( 'at_woo_gf_integration_update_info' );

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