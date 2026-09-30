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
delete_transient( 'epm_feed_cache' );

// Delete episode posts and their meta.
$episodes = get_posts(
	[
		'post_type'      => 'podcast_episode',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $episodes as $episode_id ) {
	wp_delete_post( $episode_id, true );
}
