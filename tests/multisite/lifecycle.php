<?php
/** LIFE-N2: run ONLY on a fresh marked disposable multisite network. */
if ( ! defined( 'ABSPATH' ) || ! is_multisite() || '1' !== getenv( 'EPM_TEST_SITE' ) || ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) {
	throw new RuntimeException( 'Requires an explicitly authorized disposable multisite test network.' );
}
require_once __DIR__ . '/../integration/lib.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$entry = WP_PLUGIN_DIR . '/elementor-podcast-manager/elementor-podcast-manager.php';
wp_register_plugin_realpath( $entry );
require_once $entry;
$t = new EPM_Test_Runner();
$primary = get_current_blog_id();
$second = (int) wpmu_create_blog( 'network.example.test', '/second-' . wp_generate_uuid4() . '/', 'Disposable second site', 1 );
$sites = [ $primary, $second ];
$attachments = [];
$t->assert( $second > 0, 'the disposable network has a second site' );
$plugin = plugin_basename( EPM_FILE );
$activation = activate_plugin( $plugin, '', true, false );
$t->assert( ! is_wp_error( $activation ), 'network activation succeeds: ' . ( is_wp_error( $activation ) ? $activation->get_error_message() : 'ok' ) );
foreach ( $sites as $site ) {
	switch_to_blog( $site );
	try {
		$t->same( EPM_VERSION, get_option( 'epm_version' ), "network activation initializes site $site" );
		update_option( 'epm_hosting', [ 'mode' => 'self' ] );
		update_option( 'epm_podcast_guid', 'keep-guid-' . $site );
		update_option( 'rewrite_rules', [ 'podcast/feed/?$' => 'index.php?epm_feed=1' ] );
		wp_schedule_single_event( time() + 600, 'epm_sync_feed' );
		wp_schedule_single_event( time() + 600, 'epm_upgrade_step' );
	} finally { restore_current_blog(); }
}
$third = (int) wpmu_create_blog( 'network.example.test', '/third-' . wp_generate_uuid4() . '/', 'Disposable new site', 1 );
$sites[] = $third;
switch_to_blog( $third );
$t->same( EPM_VERSION, get_option( 'epm_version' ), 'new sites initialize a network-active plugin' );
restore_current_blog();
deactivate_plugins( $plugin, false, true );
foreach ( $sites as $site ) {
	switch_to_blog( $site );
	try {
		$t->same( false, wp_next_scheduled( 'epm_upgrade_step' ), "network deactivation clears site $site upgrade work" );
		$t->same( false, wp_next_scheduled( 'epm_sync_feed' ), "network deactivation clears site $site schedules" );
		$t->same( false, get_option( 'rewrite_rules', false ), "network deactivation invalidates site $site routes" );
		EPM\EpisodePostType::register();
		foreach ( [ 'publish', 'trash', 'auto-draft' ] as $status ) {
			$id = wp_insert_post( [ 'post_type' => 'podcast_episode', 'post_status' => $status, 'post_title' => 'Disposable ' . $status, 'meta_input' => [ '_epm_guid' => "site-$site-$status" ] ] );
			wp_set_object_terms( $id, 'Disposable topic', 'podcast_topic' );
		}
		$attachments[ $site ] = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Keep my media' ] );
		update_option( 'epm_podcast_settings', [ 'title' => 'Keep this show' ] );
		update_option( 'epm_podcast_guid', 'keep-guid-' . $site );
		update_option( 'rewrite_rules', [ 'podcast/feed/?$' => 'index.php?epm_feed=1' ] );
		wp_schedule_single_event( time() + 600, 'epm_import_continue' );
	} finally { restore_current_blog(); }
}
define( 'WP_UNINSTALL_PLUGIN', $plugin );
include dirname( __DIR__, 2 ) . '/uninstall.php';
foreach ( $sites as $site ) {
	switch_to_blog( $site );
	try {
		$t->same( 'keep-guid-' . $site, get_option( 'epm_podcast_guid' ), "keep mode preserves site $site podcast GUID" );
		$t->same( 'Keep this show', get_option( 'epm_podcast_settings' )['title'], "keep mode preserves site $site settings" );
		$t->same( false, wp_next_scheduled( 'epm_import_continue' ), "keep-mode uninstall clears site $site schedules" );
		$t->same( false, get_option( 'rewrite_rules', false ), "keep-mode uninstall invalidates site $site routes" );
	} finally { restore_current_blog(); }
}
add_filter( 'epm_delete_data_on_uninstall', '__return_true' );
include dirname( __DIR__, 2 ) . '/uninstall.php';
remove_filter( 'epm_delete_data_on_uninstall', '__return_true' );
foreach ( $sites as $site ) {
	switch_to_blog( $site );
	try {
		global $wpdb;
		$t->same( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'podcast_episode'" ), "delete mode removes all episode statuses on site $site" );
		$t->same( false, get_option( 'epm_podcast_settings', false ), "delete mode removes site $site settings" );
		$t->same( false, get_option( 'epm_podcast_guid', false ), "delete mode removes site $site podcast GUID" );
		$t->assert( null !== get_post( $attachments[ $site ] ), "delete mode keeps Media Library items on site $site" );
		wp_delete_attachment( $attachments[ $site ], true );
		$t->same( [], get_terms( [ 'taxonomy' => 'podcast_topic', 'hide_empty' => false, 'fields' => 'ids' ] ), "delete mode removes site $site topics" );
	} finally { restore_current_blog(); }
}
$t->same( $primary, get_current_blog_id(), 'network operations restore the calling site' );
$t->finish();
