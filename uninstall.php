<?php
/**
 * Lessclicks Uninstall
 *
 * Runs when the plugin is uninstalled.
 * Removes all plugin data from the database.
 *
 * @package Lessclicks
 * @since 1.0.0
 */

// Security check - only run when WordPress triggers uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options
delete_option( 'lessclicks_settings' );
delete_option( 'lessclicks_review_dismissed' );
delete_option( 'lessclicks_review_next' );

// Settings stored under the previous plugin name (Dashlytics).
delete_option( 'dashlytics_settings' );

// Delete legacy options from version 0.3 if present
delete_option( 'tokenauth' );
delete_option( 'apiurl' );
delete_option( 'siteidarl' );

// Remove capabilities
$lessclicks_role = get_role( 'administrator' );
if ( $lessclicks_role ) {
	$lessclicks_role->remove_cap( 'manage_lessclicks' );
}

// Delete transients if present
delete_transient( 'lessclicks_analytics_cache' );

// Multisite support
if ( is_multisite() ) {
	$lessclicks_blog_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $lessclicks_blog_ids as $lessclicks_blog_id ) {
		switch_to_blog( $lessclicks_blog_id );

		delete_option( 'lessclicks_settings' );
		delete_option( 'dashlytics_settings' );
		delete_option( 'tokenauth' );
		delete_option( 'apiurl' );
		delete_option( 'siteidarl' );

		restore_current_blog();
	}
}
