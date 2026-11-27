<?php
/**
 * Uninstall cleanup for iWorks.ie Search & Replace.
 *
 * Removes the undo table and every iwiesr_* option on every site.
 *
 * @package IWorksIE\SearchReplace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Delete this plugin's options on the current site.
 *
 * @return void
 */
function iwiesr_uninstall_site() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'iwiesr_' ) . '%',
			$wpdb->esc_like( '_transient_iwiesr_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_iwiesr_' ) . '%'
		)
	);
	foreach ( (array) $names as $name ) {
		delete_option( (string) $name );
	}
}

if ( is_multisite() ) {
	$iwiesr_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $iwiesr_sites as $iwiesr_site_id ) {
		switch_to_blog( (int) $iwiesr_site_id );
		iwiesr_uninstall_site();
		restore_current_blog();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$iwiesr_site_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'iwiesr_' ) . '%'
		)
	);
	foreach ( (array) $iwiesr_site_options as $iwiesr_site_option ) {
		delete_site_option( (string) $iwiesr_site_option );
	}
} else {
	iwiesr_uninstall_site();
	delete_site_option( 'iwiesr_db_version' );
}

$iwiesr_table = $wpdb->base_prefix . 'iwiesr_backup';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS `{$iwiesr_table}`" );
