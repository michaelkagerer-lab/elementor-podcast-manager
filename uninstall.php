<?php
/**
 * Uninstall routine.
 *
 * Only deletes data when the site owner explicitly opts in via the
 * EPM_DELETE_DATA constant or the epm_delete_data_on_uninstall filter.
 * Episodes, settings, artwork and audio are preserved by default.
 *
 * @package EPM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin is not loaded during uninstall; load only the site iterator.
require_once __DIR__ . '/includes/Lifecycle.php';
$epm_uninstall_site = static function (): void {
	// Rebuild without the plugin on the next request, even when keeping its data.
	delete_option( 'rewrite_rules' );

	// Scheduled events never outlive the plugin, whatever happens to the data.
	// (epm_ping_podcast_index: the Podcast Index event's name in 1.3.0.)
	foreach ( [ 'epm_sync_feed', 'epm_import_continue', 'epm_import_cleanup', 'epm_podcast_index_ping', 'epm_ping_podcast_index', 'epm_upgrade_step' ] as $epm_hook ) {
		wp_clear_scheduled_hook( $epm_hook );
	}

	// Parsed feed data of an import and the cached feed (rows in the options
	// table) are never kept.
	global $wpdb;
	foreach ( [ 'epm_import_chunk_', 'epm_feed_chunk_' ] as $epm_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $epm_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	delete_option( 'epm_feed_cache' );
	delete_transient( 'epm_feed_cache' );
	delete_option( 'epm_upgrade_state' );
	delete_option( 'epm_upgrade_lock' );

	// 1.3.0 kept it as files in uploads/epm-import/.
	$epm_uploads = wp_upload_dir( null, false );
	$epm_import  = trailingslashit( (string) $epm_uploads['basedir'] ) . 'epm-import';
	if ( empty( $epm_uploads['error'] ) && is_dir( $epm_import ) ) {
		foreach ( (array) scandir( $epm_import ) as $epm_file ) {
			if ( is_file( $epm_import . '/' . $epm_file ) ) {
				wp_delete_file( $epm_import . '/' . $epm_file );
			}
		}
		@rmdir( $epm_import ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	delete_option( 'epm_import_job' );
	delete_option( 'epm_import_lock' );
	delete_option( 'epm_activation_redirect' );

	$delete = defined( 'EPM_DELETE_DATA' ) && EPM_DELETE_DATA;

	$delete = apply_filters( 'epm_delete_data_on_uninstall', $delete );

	if ( ! $delete ) {
		return;
	}


	// The plugin is not loaded here: register the Topics taxonomy so that
	// wp_delete_post() removes each episode's topic relationships.
	register_taxonomy( 'podcast_topic', 'podcast_episode' );

	// Explicit deletion uses bounded SQL batches. Keep media files and
	// attachments; remove episode revisions, comments and relationships.
	require_once __DIR__ . '/includes/Uninstall.php';
	EPM\Uninstall::episodes();

	// Then the topics themselves.
	$epm_terms = get_terms(
		[
			'taxonomy'   => 'podcast_topic',
			'hide_empty' => false,
			'fields'     => 'ids',
		]
	);
	if ( is_wp_error( $epm_terms ) ) {
		throw new RuntimeException( __( 'Podcast topic cleanup failed; settings were retained.', 'elementor-podcast-manager' ) );
	}
	if ( is_array( $epm_terms ) ) {
		foreach ( $epm_terms as $epm_term_id ) {
			$epm_deleted = wp_delete_term( (int) $epm_term_id, 'podcast_topic' );
			if ( is_wp_error( $epm_deleted ) || false === $epm_deleted ) {
				throw new RuntimeException( __( 'Podcast topic cleanup failed; settings were retained.', 'elementor-podcast-manager' ) );
			}
		}
	}
	// Delete settings only after episode and topic cleanup completes.
	delete_option( 'epm_podcast_settings' );
	delete_option( 'epm_design_settings' );
	delete_option( 'epm_version' );
	delete_option( 'epm_guids_migrated' );
	delete_option( 'epm_podcast_guid' );
	delete_option( 'epm_hosting' );
	delete_option( 'epm_sync_state' );
	delete_option( 'epm_setup' );
	delete_option( 'epm_distribution' );
	delete_option( 'epm_feed_build' );
	delete_option( 'epm_feed_address' );
	delete_option( 'epm_removed_guid_rows' );

};
// Removing plugin files affects every network, including sites where the
// plugin was previously deactivated but still has stored podcast data.
EPM\Lifecycle::each_site( $epm_uninstall_site, true, true );
