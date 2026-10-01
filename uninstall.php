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

// Scheduled events never outlive the plugin, whatever happens to the data.
// (epm_ping_podcast_index: the Podcast Index event's name in 1.3.0.)
foreach ( [ 'epm_sync_feed', 'epm_import_continue', 'epm_podcast_index_ping', 'epm_ping_podcast_index' ] as $epm_hook ) {
	wp_clear_scheduled_hook( $epm_hook );
}

// Temporary import files (parsed feed data) are never kept.
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

// Delete podcast settings, design options and internal state.
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
delete_transient( 'epm_feed_cache' );

// The plugin is not loaded here: register the Topics taxonomy so that
// wp_delete_post() removes each episode's topic relationships.
register_taxonomy( 'podcast_topic', 'podcast_episode' );

// Delete episode posts and their meta.
$epm_episodes = get_posts(
	[
		'post_type'      => 'podcast_episode',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $epm_episodes as $epm_episode_id ) {
	wp_delete_post( $epm_episode_id, true );
}

// Then the topics themselves.
$epm_terms = get_terms(
	[
		'taxonomy'   => 'podcast_topic',
		'hide_empty' => false,
		'fields'     => 'ids',
	]
);
if ( is_array( $epm_terms ) ) {
	foreach ( $epm_terms as $epm_term_id ) {
		wp_delete_term( (int) $epm_term_id, 'podcast_topic' );
	}
}
